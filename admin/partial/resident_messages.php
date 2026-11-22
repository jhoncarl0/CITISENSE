<?php
/*
 * CitiSense admin — Messages page: one conversation per resident.
 *
 * A resident's conversation combines:
 *   report_messages     messages about one of their reports (existing table,
 *                       also used by the mobile app); each keeps its report tag
 *   resident_messages   direct "general" messages, not about one report (new)
 * shown together, oldest first.
 *
 * Read state: message_reads with threadType 'resident' and threadID = userID.
 * A resident message counts as new when it is newer than the barangay's last
 * visit to that conversation, newer than any older per-report "read" record
 * (from before conversations were grouped by resident), and newer than the
 * barangay's own latest message to that resident.
 *
 * Collations: report_messages uses utf8mb4_general_ci while reports/users use
 * utf8mb4_unicode_ci, so joins between them name the collation explicitly.
 */

require_once __DIR__ . "/helpers.php";

if (!function_exists("csResidentThreads")) {

    /** All messages with this barangay, as rows: userID, reportID ('' for general), senderType, message, at, key. */
    function csResidentMessageRows(mysqli $conn, string $barangayID, string $userID = ""): array
    {
        $rows = [];

        $sql = "SELECT r.userID, m.reportID, m.senderType, m.senderID, m.message, m.created_at, m.id
                FROM report_messages m
                INNER JOIN reports r ON r.reportID = m.reportID COLLATE utf8mb4_unicode_ci
                WHERE r.barangayID = ?" . ($userID !== "" ? " AND r.userID = ?" : "");
        $stmt = $conn->prepare($sql);
        if ($userID !== "") {
            $stmt->bind_param("ss", $barangayID, $userID);
        } else {
            $stmt->bind_param("s", $barangayID);
        }
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $rows[] = [
                "userID" => (string) $row["userID"],
                "reportID" => (string) $row["reportID"],
                "senderType" => (string) $row["senderType"],
                "senderID" => (string) $row["senderID"],
                "message" => (string) $row["message"],
                "at" => (string) $row["created_at"],
                "key" => "r" . $row["id"],
                "order" => (int) $row["id"],
            ];
        }
        $stmt->close();

        $sql = "SELECT userID, senderType, senderID, message, created_at, messageID
                FROM resident_messages
                WHERE barangayID = ?" . ($userID !== "" ? " AND userID = ?" : "");
        $stmt = $conn->prepare($sql);
        if ($userID !== "") {
            $stmt->bind_param("ss", $barangayID, $userID);
        } else {
            $stmt->bind_param("s", $barangayID);
        }
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $rows[] = [
                "userID" => (string) $row["userID"],
                "reportID" => "",
                "senderType" => (string) $row["senderType"],
                "senderID" => (string) $row["senderID"],
                "message" => (string) $row["message"],
                "at" => (string) $row["created_at"],
                "key" => "d" . $row["messageID"],
                "order" => (int) $row["messageID"],
            ];
        }
        $stmt->close();

        usort($rows, static function ($a, $b) {
            return [$a["at"], $a["key"][0], $a["order"]] <=> [$b["at"], $b["key"][0], $b["order"]];
        });

        return $rows;
    }

    /** "Seen until" time per resident: [userID => 'Y-m-d H:i:s']. */
    function csResidentSeenUntil(mysqli $conn, string $barangayID, array $rows): array
    {
        $reads = ["resident" => [], "report" => []];
        $stmt = $conn->prepare(
            "SELECT threadType, threadID, lastReadAt FROM message_reads
             WHERE barangayID = ? AND threadType IN ('resident', 'report')"
        );
        $stmt->bind_param("s", $barangayID);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $reads[$row["threadType"]][(string) $row["threadID"]] = (string) $row["lastReadAt"];
        }
        $stmt->close();

        $seen = [];
        foreach ($rows as $row) {
            $user = $row["userID"];
            $candidates = [$seen[$user] ?? "", $reads["resident"][$user] ?? ""];
            if ($row["reportID"] !== "") {
                $candidates[] = $reads["report"][$row["reportID"]] ?? "";
            }
            if ($row["senderType"] === "barangay") {
                $candidates[] = $row["at"];
            }
            $seen[$user] = max($candidates);
        }

        return $seen;
    }

    /** Basic profile rows for these residents: [userID => row]. */
    function csResidentProfiles(mysqli $conn, array $userIDs): array
    {
        $userIDs = array_values(array_unique(array_filter($userIDs, "strlen")));
        if (!$userIDs) {
            return [];
        }

        $marks = implode(",", array_fill(0, count($userIDs), "?"));
        $stmt = $conn->prepare(
            "SELECT userID, firstName, lastName, email, contactNo, profilePic, dateJoined
             FROM users WHERE userID IN ($marks)"
        );
        $stmt->bind_param(str_repeat("s", count($userIDs)), ...$userIDs);
        $stmt->execute();
        $profiles = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $profiles[(string) $row["userID"]] = $row;
        }
        $stmt->close();

        return $profiles;
    }

    function csResidentName(?array $profile): string
    {
        $name = trim(($profile["firstName"] ?? "") . " " . ($profile["lastName"] ?? ""));
        return $name !== "" ? $name : "Resident";
    }

    /**
     * Conversations, newest activity first. Each: userID, name, avatar,
     * lastMessage, lastSender, lastAt, lastReport, total, unread.
     * $search matches the resident's name or email, or one of their report IDs.
     */
    function csResidentThreads(mysqli $conn, string $barangayID, string $search = ""): array
    {
        $rows = csResidentMessageRows($conn, $barangayID);
        $seen = csResidentSeenUntil($conn, $barangayID, $rows);

        $threads = [];
        $reportsByUser = [];
        foreach ($rows as $row) {
            $user = $row["userID"];
            if (!isset($threads[$user])) {
                $threads[$user] = ["userID" => $user, "total" => 0, "unread" => 0];
            }
            $threads[$user]["total"]++;
            $threads[$user]["lastMessage"] = $row["message"];
            $threads[$user]["lastSender"] = $row["senderType"];
            $threads[$user]["lastAt"] = $row["at"];
            $threads[$user]["lastReport"] = $row["reportID"];
            if ($row["senderType"] === "resident" && $row["at"] > ($seen[$user] ?? "")) {
                $threads[$user]["unread"]++;
            }
            if ($row["reportID"] !== "") {
                $reportsByUser[$user][$row["reportID"]] = true;
            }
        }

        $profiles = csResidentProfiles($conn, array_keys($threads));
        $needle = mb_strtolower($search);
        $result = [];

        foreach ($threads as $user => $thread) {
            $profile = $profiles[$user] ?? null;
            $thread["name"] = csResidentName($profile);
            $thread["avatar"] = csMediaUrl($profile["profilePic"] ?? "");

            if ($needle !== "") {
                $haystack = mb_strtolower($thread["name"] . " " . ($profile["email"] ?? "") . " " . implode(" ", array_keys($reportsByUser[$user] ?? [])));
                if (mb_strpos($haystack, $needle) === false) {
                    continue;
                }
            }

            $result[] = $thread;
        }

        usort($result, static fn($a, $b) => strcmp($b["lastAt"], $a["lastAt"]));

        return $result;
    }

    /** Residents of the barangay matching $search who have no conversation yet (to start one). */
    function csFindResidentsToMessage(mysqli $conn, string $barangayID, string $search, array $excludeIDs, int $limit = 8): array
    {
        if ($search === "") {
            return [];
        }

        $like = "%" . $search . "%";
        $stmt = $conn->prepare(
            "SELECT userID, firstName, lastName, email, profilePic
             FROM users
             WHERE barangayID = ? AND role = 'resident'
               AND (firstName LIKE ? OR lastName LIKE ? OR CONCAT(firstName, ' ', lastName) LIKE ? OR email LIKE ?)
             ORDER BY firstName, lastName
             LIMIT 40"
        );
        $stmt->bind_param("sssss", $barangayID, $like, $like, $like, $like);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $found = [];
        foreach ($rows as $row) {
            if (in_array((string) $row["userID"], $excludeIDs, true)) {
                continue;
            }
            $found[] = [
                "userID" => (string) $row["userID"],
                "name" => csResidentName($row),
                "email" => (string) ($row["email"] ?? ""),
                "avatar" => csMediaUrl($row["profilePic"] ?? ""),
            ];
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /**
     * One resident's conversation: profile, reports and messages (oldest first),
     * or null when the resident does not belong to this barangay.
     */
    function csLoadResidentConversation(mysqli $conn, string $barangayID, string $userID): ?array
    {
        $stmt = $conn->prepare(
            "SELECT u.userID, u.firstName, u.lastName, u.email, u.contactNo, u.profilePic, u.dateJoined
             FROM users u
             WHERE u.userID = ?
               AND (u.barangayID = ? OR EXISTS (SELECT 1 FROM reports r WHERE r.userID = u.userID AND r.barangayID = ?))
             LIMIT 1"
        );
        $stmt->bind_param("sss", $userID, $barangayID, $barangayID);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$profile) {
            return null;
        }

        $stmt = $conn->prepare(
            "SELECT r.reportID, r.dateReported, c.categoryName, s.statusName, l.address
             FROM reports r
             INNER JOIN categories c ON c.categoryID = r.categoryID
             INNER JOIN stats s ON s.statusID = r.statusID
             LEFT JOIN location l ON l.locationID = r.locationID
             WHERE r.userID = ? AND r.barangayID = ?
             ORDER BY r.dateReported DESC, r.reportID DESC"
        );
        $stmt->bind_param("ss", $userID, $barangayID);
        $stmt->execute();
        $reports = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $reports[(string) $row["reportID"]] = [
                "id" => (string) $row["reportID"],
                "category" => (string) $row["categoryName"],
                "status" => (string) $row["statusName"],
                "address" => (string) ($row["address"] ?? ""),
                "date" => (string) $row["dateReported"],
            ];
        }
        $stmt->close();

        $rows = csResidentMessageRows($conn, $barangayID, $userID);
        $seen = csResidentSeenUntil($conn, $barangayID, $rows)[$userID] ?? "";

        $senders = csResidentProfiles($conn, array_column($rows, "senderID"));
        $residentName = csResidentName($profile);
        $residentAvatar = csMediaUrl($profile["profilePic"] ?? "");

        $messages = [];
        foreach ($rows as $row) {
            $fromBarangay = $row["senderType"] === "barangay";
            $report = $reports[$row["reportID"]] ?? null;
            $messages[] = [
                "key" => $row["key"],
                "fromBarangay" => $fromBarangay,
                "name" => $fromBarangay ? csResidentName($senders[$row["senderID"]] ?? null) : $residentName,
                "avatar" => $fromBarangay ? "" : $residentAvatar,
                "message" => $row["message"],
                "at" => $row["at"],
                "reportID" => $row["reportID"],
                "reportLabel" => $row["reportID"] !== ""
                    ? "Report " . $row["reportID"] . ($report ? " · " . $report["category"] : "")
                    : "",
                "isNew" => !$fromBarangay && $row["at"] > $seen,
            ];
        }

        return [
            "userID" => (string) $profile["userID"],
            "name" => $residentName,
            "avatar" => $residentAvatar,
            "email" => (string) ($profile["email"] ?? ""),
            "contactNo" => (string) ($profile["contactNo"] ?? ""),
            "dateJoined" => (string) ($profile["dateJoined"] ?? ""),
            "reports" => array_values($reports),
            "messages" => $messages,
            "lastReport" => $rows ? (string) end($rows)["reportID"] : "",
        ];
    }

    /** Remember that the barangay has seen everything in this resident's conversation. */
    function csMarkResidentRead(mysqli $conn, string $barangayID, string $userID): void
    {
        $rows = csResidentMessageRows($conn, $barangayID, $userID);
        if (!$rows) {
            return;
        }

        /* Use the newest message's own timestamp, so database and PHP time zones never disagree. */
        $latest = end($rows)["at"];

        $stmt = $conn->prepare("DELETE FROM message_reads WHERE threadType = 'resident' AND threadID = ? AND barangayID = ?");
        $stmt->bind_param("ss", $userID, $barangayID);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO message_reads (threadType, threadID, barangayID, lastReadAt) VALUES ('resident', ?, ?, ?)");
        $stmt->bind_param("sss", $userID, $barangayID, $latest);
        $stmt->execute();
        $stmt->close();
    }

    /** Number of residents with new messages (for the menu badge). */
    function csUnreadResidentCount(mysqli $conn, string $barangayID): int
    {
        try {
            return count(array_filter(csResidentThreads($conn, $barangayID), static fn($t) => $t["unread"] > 0));
        } catch (Throwable $error) {
            return 0;
        }
    }

    /** The resident who sent this report, if the report belongs to this barangay. */
    function csReportOwner(mysqli $conn, string $barangayID, string $reportID): string
    {
        $stmt = $conn->prepare("SELECT userID FROM reports WHERE reportID = ? AND barangayID = ? LIMIT 1");
        $stmt->bind_param("ss", $reportID, $barangayID);
        $stmt->execute();
        $owner = (string) ($stmt->get_result()->fetch_assoc()["userID"] ?? "");
        $stmt->close();
        return $owner;
    }
}
