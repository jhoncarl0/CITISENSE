<?php
/*
 * CitiSense admin — the data behind a report's details panel ("View").
 *
 * Used by the Reports page (for the reports on the current page) and by
 * report_details.php (JSON, so Possible Duplicates, Resident Feedback, the
 * Issue Map, the Dashboard and Messages can open a report's details in place
 * instead of sending the administrator to the Reports page).
 *
 * Duplicate relationships come only from the database:
 *   - "linked" rows in duplicate_reviews = duplicates the ADMIN confirmed
 *     (Possible Duplicates > Mark as Duplicate). Only these are shown as
 *     "Duplicate of report …".
 *   - the possible-duplicate suggestions are only suggestions, shown as
 *     "Possible duplicate" with a link to review them.
 *   - the older automatic grouping (same issue type at the same saved
 *     location, partial/report_cycles.php) is shown as "same place", never as
 *     a confirmed duplicate.
 *
 * Read-only: nothing here changes data.
 */

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/report_cycles.php";
require_once __DIR__ . "/duplicates.php";
require_once __DIR__ . "/duplicate_links.php";
require_once __DIR__ . "/feedback.php";

if (!function_exists("csReportSelectSQL")) {

    /** SELECT … FROM reports r with the joins every report view needs (add WHERE yourself). */
    function csReportSelectSQL(): string
    {
        return "
            SELECT
                r.reportID, r.userID, r.categoryID, r.locationID, r.statusID,
                r.title, r.description, r.dateReported, r.timeline,
                c.categoryName, s.statusName, l.address, l.latitude, l.longitude,
                u.firstName, u.lastName, u.email, u.contactNo
            FROM reports r
            INNER JOIN categories c ON c.categoryID = r.categoryID
            INNER JOIN stats s ON s.statusID = r.statusID
            LEFT JOIN location l ON l.locationID = r.locationID
            LEFT JOIN users u ON u.userID = r.userID
        ";
    }

    /**
     * Relationships between the reports of a barangay, computed once per request.
     * $withSuggestions: also run the possible-duplicate check (slower: photos).
     */
    function csReportContext(mysqli $conn, string $barangayID, bool $withSuggestions = true): array
    {
        $links = csDuplicateLinkMap($conn, $barangayID); // [duplicateID => mainID], confirmed only
        $linkedByMain = [];
        foreach ($links as $dupID => $mainID) {
            $linkedByMain[$mainID][] = (string) $dupID;
        }

        $possible = [];
        if ($withSuggestions) {
            try {
                foreach (csGroupDuplicatePairs(csFindDuplicatePairs($conn, $barangayID, true)) as $group) {
                    $mainID = (string) $group["main"]["reportID"];
                    foreach ($group["ids"] as $id) {
                        $possible[(string) $id] = [
                            "group" => (string) $group["id"],
                            "main" => $mainID,
                            "others" => array_values(array_diff($group["ids"], [$id])),
                        ];
                    }
                }
            } catch (Throwable $error) {
                error_log("CitiSense: possible duplicates could not be checked: " . $error->getMessage());
            }
        }

        return [
            "cycles" => csClassifyReportCycles($conn, $barangayID),
            "links" => $links,
            "linkedByMain" => $linkedByMain,
            "possible" => $possible,
        ];
    }

    /** Report rows (csReportSelectSQL) for these IDs of the barangay, keyed by ID. */
    function csLoadReportRows(mysqli $conn, string $barangayID, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map("strval", $ids), static fn($id) => $id !== "")));
        if (!$ids) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $marks = implode(",", array_fill(0, count($chunk), "?"));
            $stmt = $conn->prepare(csReportSelectSQL() . " WHERE r.barangayID = ? AND r.reportID IN ($marks)");
            $params = [$barangayID, ...$chunk];
            $stmt->bind_param(str_repeat("s", count($params)), ...$params);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $rows[(string) $row["reportID"]] = $row;
            }
            $stmt->close();
        }

        return $rows;
    }

    /**
     * Details-panel data for $ids, plus the reports they refer to (their main
     * report, their confirmed duplicates, the first report at the same place),
     * keyed by report ID. $rows: rows already loaded (optional).
     */
    function csReportDetailsPayload(mysqli $conn, string $barangayID, array $ids, ?array $context = null, array $rows = []): array
    {
        $context ??= csReportContext($conn, $barangayID, true);
        $cycles = $context["cycles"];
        $links = $context["links"];
        $linkedByMain = $context["linkedByMain"];
        $possible = $context["possible"];

        $ids = array_map("strval", $ids);
        $rows += csLoadReportRows($conn, $barangayID, array_diff($ids, array_keys($rows)));

        /* Related reports, so "Open report …" and the duplicates list work. */
        $related = [];
        foreach (array_keys($rows) as $id) {
            $id = (string) $id;
            $related[] = $cycles[$id]["originalID"] ?? $id;
            if (isset($links[$id])) {
                $related[] = $links[$id];
            }
            foreach ($linkedByMain[$id] ?? [] as $dupID) {
                $related[] = $dupID;
            }
        }
        $rows += csLoadReportRows($conn, $barangayID, array_diff($related, array_keys($rows)));

        if (!$rows) {
            return [];
        }

        $allIDs = array_map("strval", array_keys($rows));
        $marks = implode(",", array_fill(0, count($allIDs), "?"));
        $idTypes = str_repeat("s", count($allIDs));

        $images = [];
        $stmt = $conn->prepare("SELECT reportID, imagePath FROM reportimages WHERE reportID IN ($marks) ORDER BY uploadedAt ASC");
        $stmt->bind_param($idTypes, ...$allIDs);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            if (trim((string) $row["imagePath"]) !== "") {
                $images[(string) $row["reportID"]][] = csMediaUrl($row["imagePath"]);
            }
        }
        $stmt->close();

        $history = [];
        $stmt = $conn->prepare(
            "SELECT h.reportID, h.remarks, h.dateChanged, s.statusName, u.firstName, u.lastName
             FROM reporthistory h
             INNER JOIN reports r ON r.reportID = h.reportID
             INNER JOIN stats s ON s.statusID = h.statusID
             LEFT JOIN users u ON u.userID = h.userID
             WHERE r.barangayID = ? AND h.reportID IN ($marks)
             ORDER BY h.dateChanged ASC, h.historyID ASC"
        );
        $params = [$barangayID, ...$allIDs];
        $stmt->bind_param("s" . $idTypes, ...$params);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $history[(string) $row["reportID"]][] = [
                "raw" => (string) $row["dateChanged"],
                "status" => (string) $row["statusName"],
                "date" => csDate($row["dateChanged"], "M j, Y · g:i A"),
                "by" => trim(($row["firstName"] ?? "") . " " . ($row["lastName"] ?? "")),
                "remarks" => trim((string) ($row["remarks"] ?? "")),
            ];
        }
        $stmt->close();

        $feedback = csFeedbackForReports($conn, $barangayID, $allIDs);

        $payload = [];
        foreach ($rows as $id => $report) {
            $id = (string) $id;
            $cycle = $cycles[$id] ?? ["originalID" => $id, "isOriginal" => true, "cycleReportIDs" => [$id]];
            $originalID = (string) $cycle["originalID"];
            $statusID = (string) $report["statusID"];

            /*
             * The mobile app fills "timeline" with the submission time when a
             * report is created, so it is only a real target date once the
             * report was accepted and the date differs from the submission time.
             */
            $hasTargetDate = !empty($report["timeline"])
                && in_array($statusID, ["STAT002", "STAT004"], true)
                && strtotime((string) $report["timeline"]) !== strtotime((string) $report["dateReported"]);

            /* Earliest allowed expected completion day: when it was last accepted. */
            $acceptedAt = (string) $report["dateReported"];
            foreach ($history[$id] ?? [] as $entry) {
                if (strtolower(trim($entry["status"])) === "in progress") {
                    $acceptedAt = $entry["raw"];
                }
            }

            $linkedIDs = $linkedByMain[$id] ?? [];
            $linkedTo = $links[$id] ?? "";
            $suggestion = $possible[$id] ?? null;

            /*
             * Role of the report in a confirmed duplicate relationship:
             *   "main"            has confirmed duplicates and was accepted
             *   "suggested-main"  has confirmed duplicates, not accepted yet
             *   "duplicate"       confirmed duplicate of another report
             *   ""                none
             */
            $role = "";
            if ($linkedTo !== "") {
                $role = "duplicate";
            } elseif ($linkedIDs) {
                $role = $statusID === "STAT001" ? "suggested-main" : "main";
            }

            $payload[$id] = [
                "id" => $id,
                "category" => (string) $report["categoryName"],
                "title" => trim((string) ($report["title"] ?? "")),
                "description" => trim((string) ($report["description"] ?? "")),
                "address" => (string) ($report["address"] ?? ""),
                "lat" => is_numeric($report["latitude"] ?? null) ? (float) $report["latitude"] : null,
                "lng" => is_numeric($report["longitude"] ?? null) ? (float) $report["longitude"] : null,
                "date" => csDate($report["dateReported"], "F j, Y · g:i A"),
                "ago" => csTimeAgo($report["dateReported"]),
                "timeline" => $hasTargetDate ? csDate($report["timeline"], "F j, Y · g:i A") : "",
                "timelineInput" => $hasTargetDate ? csDate($report["timeline"], "Y-m-d\\TH:i") : "",
                "timelineMin" => csDate($acceptedAt, "Y-m-d\\T00:00"),
                "acceptedOn" => csDate($acceptedAt, "F j, Y"),
                "statusID" => $statusID,
                "status" => (string) $report["statusName"],
                "resident" => trim(($report["firstName"] ?? "") . " " . ($report["lastName"] ?? "")),
                "email" => (string) ($report["email"] ?? ""),
                "contact" => (string) ($report["contactNo"] ?? ""),
                "images" => $images[$id] ?? [],
                "history" => $history[$id] ?? [],
                "isOriginal" => (bool) $cycle["isOriginal"],
                "role" => $role,
                "linkedTo" => $linkedTo,
                "linkedCount" => count($linkedIDs),
                "linkedIDs" => $linkedIDs,
                "feedback" => $feedback[$id] ?? null,
                "originalID" => $originalID,
                "originalStatusID" => (string) ($rows[$originalID]["statusID"] ?? $statusID),
                "originalStatus" => (string) ($rows[$originalID]["statusName"] ?? $report["statusName"]),
                "cycleSize" => count($cycle["cycleReportIDs"]),
                "duplicateGroup" => $suggestion["group"] ?? "",
                "duplicateOthers" => $suggestion["others"] ?? [],
                "isSuggestedMain" => $suggestion !== null && $suggestion["main"] === $id,
            ];
        }

        return $payload;
    }

    /** JSON for a <script type="application/json"> block (safe inside HTML). */
    function csReportPayloadJSON(array $payload): string
    {
        return json_encode(
            (object) $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: "{}";
    }
}
