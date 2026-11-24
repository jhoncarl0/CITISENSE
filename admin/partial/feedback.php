<?php
/*
 * CitiSense admin — residents' feedback on resolved reports.
 *
 * The CitiSense app lets a resident answer, once a report is Resolved:
 * "Is it fixed?" (yes / no), a rating from 1 to 5 and an optional comment.
 * It saves this in report_feedback (one row per report, updated when the
 * resident changes it; created by the mobile API). When the answer is
 * "not fixed", the app also adds a message to the report's conversation.
 *
 * Here the admin site only reads that table:
 *   - csFeedbackList()          the Resident Feedback page
 *   - csFeedbackForReports()    the feedback shown inside a report's View
 *   - csUnreadFeedbackCount()   the menu badge (new or changed feedback)
 * "Seen" is remembered per barangay in message_reads
 * (threadType 'feedback', threadID 'all', lastReadAt = newest feedback seen).
 *
 * If report_feedback does not exist yet (no feedback was ever sent), every
 * function simply returns nothing.
 */

if (!function_exists("csFeedbackList")) {

    /** Collation-safe join (report_feedback may use the server's default collation). */
    function csFeedbackJoin(): string
    {
        return "INNER JOIN reports r ON r.reportID = f.reportID COLLATE utf8mb4_unicode_ci";
    }

    /** Feedback of this barangay, newest first. */
    function csFeedbackList(mysqli $conn, string $barangayID, string $filter = "all"): array
    {
        try {
            $where = "r.barangayID = ?";
            if ($filter === "not_fixed") {
                $where .= " AND f.is_fixed = 0";
            } elseif ($filter === "fixed") {
                $where .= " AND f.is_fixed = 1";
            }

            $stmt = $conn->prepare(
                "SELECT f.reportID, f.is_fixed, f.rating, f.comment, f.created_at, f.updated_at,
                        r.title, r.statusID, c.categoryName, s.statusName, l.address,
                        u.firstName, u.lastName, u.userID
                 FROM report_feedback f
                 " . csFeedbackJoin() . "
                 INNER JOIN categories c ON c.categoryID = r.categoryID
                 INNER JOIN stats s ON s.statusID = r.statusID
                 LEFT JOIN location l ON l.locationID = r.locationID
                 LEFT JOIN users u ON u.userID = f.userID COLLATE utf8mb4_unicode_ci
                 WHERE $where
                 ORDER BY f.updated_at DESC, f.id DESC
                 LIMIT 500"
            );
            $stmt->bind_param("s", $barangayID);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return array_map("csFormatFeedback", $rows);
        } catch (Throwable $error) {
            return [];
        }
    }

    /** Feedback for these reports: [reportID => feedback]. */
    function csFeedbackForReports(mysqli $conn, string $barangayID, array $reportIDs): array
    {
        $reportIDs = array_values(array_unique(array_map("strval", $reportIDs)));
        if (!$reportIDs) {
            return [];
        }

        try {
            $marks = implode(",", array_fill(0, count($reportIDs), "?"));
            $stmt = $conn->prepare(
                "SELECT f.reportID, f.is_fixed, f.rating, f.comment, f.created_at, f.updated_at
                 FROM report_feedback f
                 " . csFeedbackJoin() . "
                 WHERE r.barangayID = ? AND r.reportID IN ($marks)"
            );
            $types = "s" . str_repeat("s", count($reportIDs));
            $params = [$barangayID, ...$reportIDs];
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $found = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $found[(string) $row["reportID"]] = csFormatFeedback($row);
            }
            $stmt->close();
            return $found;
        } catch (Throwable $error) {
            return [];
        }
    }

    function csFormatFeedback(array $row): array
    {
        $name = trim(($row["firstName"] ?? "") . " " . ($row["lastName"] ?? ""));
        return [
            "reportID" => (string) $row["reportID"],
            "fixed" => (int) $row["is_fixed"] === 1,
            "rating" => max(1, min(5, (int) $row["rating"])),
            "comment" => trim((string) ($row["comment"] ?? "")),
            "at" => (string) ($row["updated_at"] ?: $row["created_at"]),
            "atLabel" => csDate((string) ($row["updated_at"] ?: $row["created_at"]), "M j, Y · g:i A"),
            "changed" => (string) $row["updated_at"] !== "" && (string) $row["updated_at"] !== (string) $row["created_at"],
            "title" => (string) ($row["title"] ?? ""),
            "category" => (string) ($row["categoryName"] ?? ""),
            "status" => (string) ($row["statusName"] ?? ""),
            "address" => (string) ($row["address"] ?? ""),
            "resident" => $name,
            "userID" => (string) ($row["userID"] ?? ""),
        ];
    }

    /** When the barangay last looked at the feedback page ("" = never). */
    function csFeedbackSeenAt(mysqli $conn, string $barangayID): string
    {
        try {
            $stmt = $conn->prepare(
                "SELECT lastReadAt FROM message_reads WHERE threadType = 'feedback' AND threadID = 'all' AND barangayID = ? LIMIT 1"
            );
            $stmt->bind_param("s", $barangayID);
            $stmt->execute();
            $seen = (string) ($stmt->get_result()->fetch_assoc()["lastReadAt"] ?? "");
            $stmt->close();
            return $seen;
        } catch (Throwable $error) {
            return "";
        }
    }

    /** Feedback that is new or was changed since the barangay last opened the page. */
    function csUnreadFeedbackCount(mysqli $conn, string $barangayID): int
    {
        try {
            $seen = csFeedbackSeenAt($conn, $barangayID);
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM report_feedback f " . csFeedbackJoin() . "
                 WHERE r.barangayID = ? AND f.updated_at > ?"
            );
            $since = $seen !== "" ? $seen : "1970-01-01 00:00:00";
            $stmt->bind_param("ss", $barangayID, $since);
            $stmt->execute();
            $count = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);
            $stmt->close();
            return $count;
        } catch (Throwable $error) {
            return 0;
        }
    }

    /** Remember that everything up to the newest feedback has been seen. */
    function csMarkFeedbackSeen(mysqli $conn, string $barangayID, array $feedback): void
    {
        if (!$feedback) {
            return;
        }
        $latest = max(array_column($feedback, "at"));
        try {
            $stmt = $conn->prepare("DELETE FROM message_reads WHERE threadType = 'feedback' AND threadID = 'all' AND barangayID = ?");
            $stmt->bind_param("s", $barangayID);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("INSERT INTO message_reads (threadType, threadID, barangayID, lastReadAt) VALUES ('feedback', 'all', ?, ?)");
            $stmt->bind_param("ss", $barangayID, $latest);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $error) {
            error_log("CitiSense feedback: could not remember what was seen: " . $error->getMessage());
        }
    }

    /** "★★★★☆" for a rating. */
    function csStars(int $rating): string
    {
        $rating = max(0, min(5, $rating));
        return str_repeat("★", $rating) . str_repeat("☆", 5 - $rating);
    }
}
