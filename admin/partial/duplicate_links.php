<?php
/*
 * CitiSense admin — reports linked as duplicates of a main report.
 *
 * "Mark as Duplicate" (Possible Duplicates page) links a report to the
 * suggested main report instead of rejecting it. The link is stored in the
 * existing duplicate_reviews table:
 *     decision = 'linked', reportA = the duplicate, reportB = the main report
 * (pairKey stays the sorted "A|B" key, so there is one decision per pair).
 *
 * A linked duplicate always has the same status (and expected completion
 * date) as its main report:
 *   - when it is linked, it takes the main report's current status;
 *   - every status change of the main report (accept, resolve, reject, back
 *     to Pending, Overdue, new completion date) is copied to it by
 *     csSyncLinkedDuplicates(), inside the same database transaction, with a
 *     history entry and a notification for its resident (shown in the app);
 *   - its own status cannot be changed directly (the endpoints refuse).
 *
 * This works next to the older automatic grouping (same category + same
 * location, partial/report_cycles.php), which is unchanged.
 */

require_once __DIR__ . "/duplicates.php"; // csPairKey()

if (!function_exists("csLinkedMainOf")) {

    /** The main report this report is linked to, or "". */
    function csLinkedMainOf(mysqli $conn, string $reportID): string
    {
        $stmt = $conn->prepare(
            "SELECT reportB FROM duplicate_reviews WHERE decision = 'linked' AND reportA = ? LIMIT 1"
        );
        $stmt->bind_param("s", $reportID);
        $stmt->execute();
        $main = (string) ($stmt->get_result()->fetch_assoc()["reportB"] ?? "");
        $stmt->close();
        return $main;
    }

    /** Reports linked as duplicates of this main report. */
    function csLinkedDuplicatesOf(mysqli $conn, string $mainID): array
    {
        $stmt = $conn->prepare(
            "SELECT reportA FROM duplicate_reviews WHERE decision = 'linked' AND reportB = ? ORDER BY reportA"
        );
        $stmt->bind_param("s", $mainID);
        $stmt->execute();
        $ids = array_map("strval", array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), "reportA"));
        $stmt->close();
        return $ids;
    }

    /** All links of a barangay: [duplicateID => mainID]. Empty when the table is missing. */
    function csDuplicateLinkMap(mysqli $conn, string $barangayID): array
    {
        try {
            $stmt = $conn->prepare(
                "SELECT reportA, reportB FROM duplicate_reviews WHERE barangayID = ? AND decision = 'linked'"
            );
            $stmt->bind_param("s", $barangayID);
            $stmt->execute();
            $map = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $map[(string) $row["reportA"]] = (string) $row["reportB"];
            }
            $stmt->close();
            return $map;
        } catch (Throwable $error) {
            return [];
        }
    }

    /**
     * Link $duplicateID to $mainID (call inside a transaction).
     * A main report that is itself linked is followed to its own main, and
     * reports already linked to $duplicateID move to the new main, so links
     * never form chains. Returns the main report actually used.
     */
    function csLinkDuplicate(mysqli $conn, string $barangayID, string $duplicateID, string $mainID, string $adminID): string
    {
        $seen = [];
        while (($up = csLinkedMainOf($conn, $mainID)) !== "") {
            if ($up === $duplicateID || isset($seen[$up])) {
                // The chosen report already follows this one: linking would go in a circle.
                throw new DomainException(
                    "Report " . $mainID . " is already a duplicate of report " . $duplicateID
                    . ", so report " . $duplicateID . " cannot be its duplicate."
                );
            }
            $seen[$mainID] = true;
            $mainID = $up;
        }
        if ($mainID === $duplicateID) {
            throw new DomainException("A report cannot be a duplicate of itself.");
        }

        $now = date("Y-m-d H:i:s");
        $insert = $conn->prepare(
            "INSERT INTO duplicate_reviews (pairKey, barangayID, reportA, reportB, decision, decidedBy, decidedAt)
             VALUES (?, ?, ?, ?, 'linked', ?, ?)"
        );
        $delete = $conn->prepare("DELETE FROM duplicate_reviews WHERE pairKey = ?");
        $unlink = $conn->prepare("DELETE FROM duplicate_reviews WHERE decision = 'linked' AND reportA = ?");

        $link = static function (string $dup) use ($insert, $delete, $unlink, $barangayID, $mainID, $adminID, $now): void {
            $key = csPairKey($dup, $mainID);
            $unlink->bind_param("s", $dup);
            $unlink->execute();
            $delete->bind_param("s", $key);
            $delete->execute();
            $insert->bind_param("ssssss", $key, $barangayID, $dup, $mainID, $adminID, $now);
            $insert->execute();
        };

        foreach (csLinkedDuplicatesOf($conn, $duplicateID) as $child) {
            if ($child !== $mainID) {
                $link($child);
            }
        }
        $link($duplicateID);

        $insert->close();
        $delete->close();
        $unlink->close();

        return $mainID;
    }

    /**
     * Give every duplicate linked to $mainID the main report's status and
     * expected completion date (call inside the caller's transaction).
     * Each status change gets a history entry and a notification.
     * $remarks: optional text for the history (e.g. the admin's message).
     * $justLinked + $onlyIDs: right after linking, only those reports get
     * the "now follows report …" notice (even when the status is the same).
     * Returns the IDs of duplicates whose status changed.
     */
    function csSyncLinkedDuplicates(mysqli $conn, string $barangayID, string $mainID, string $adminID, ?string $remarks = null, bool $justLinked = false, ?array $onlyIDs = null): array
    {
        $duplicates = csLinkedDuplicatesOf($conn, $mainID);
        if ($onlyIDs !== null) {
            $duplicates = array_values(array_intersect($duplicates, $onlyIDs));
        }
        if (!$duplicates) {
            return [];
        }

        $stmt = $conn->prepare(
            "SELECT r.statusID, r.timeline, s.statusName
             FROM reports r INNER JOIN stats s ON s.statusID = r.statusID
             WHERE r.reportID = ? AND r.barangayID = ? LIMIT 1"
        );
        $stmt->bind_param("ss", $mainID, $barangayID);
        $stmt->execute();
        $main = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$main) {
            return [];
        }

        $statusID = (string) $main["statusID"];
        $statusName = (string) $main["statusName"];
        $timeline = $main["timeline"];

        $select = $conn->prepare(
            "SELECT userID, statusID, timeline FROM reports WHERE reportID = ? AND barangayID = ? LIMIT 1 FOR UPDATE"
        );
        $update = $conn->prepare("UPDATE reports SET statusID = ?, timeline = ? WHERE reportID = ? AND barangayID = ?");
        $history = $conn->prepare(
            "INSERT INTO reporthistory (historyID, reportID, userID, statusID, remarks, dateChanged) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $notify = $conn->prepare(
            "INSERT INTO notification (notifID, userID, reportID, message, isRead) VALUES (?, ?, ?, ?, 0)"
        );

        $changed = [];
        foreach ($duplicates as $dupID) {
            $select->bind_param("ss", $dupID, $barangayID);
            $select->execute();
            $dup = $select->get_result()->fetch_assoc();
            if (!$dup) {
                continue;
            }

            $statusChanges = (string) $dup["statusID"] !== $statusID;
            $timelineChanges = (string) ($dup["timeline"] ?? "") !== (string) ($timeline ?? "");

            if (!$statusChanges && !$timelineChanges && !$justLinked) {
                continue;
            }

            if ($statusChanges || $timelineChanges) {
                $update->bind_param("ssss", $statusID, $timeline, $dupID, $barangayID);
                $update->execute();
            }

            if (!$statusChanges && !$justLinked) {
                continue; // only the completion date moved: no extra notice
            }

            $note = "Same problem as report " . $mainID . ": follows its status."
                . ($remarks !== null && $remarks !== "" ? " " . $remarks : "");
            $historyID = "H" . bin2hex(random_bytes(16));
            $now = date("Y-m-d H:i:s");
            $history->bind_param("ssssss", $historyID, $dupID, $adminID, $statusID, $note, $now);
            $history->execute();

            $key = strtolower(trim($statusName));
            if ($justLinked) {
                $message = ($remarks !== null && $remarks !== ""
                        ? rtrim($remarks) . " "
                        : "Thank you! Your report " . $dupID . " is about the same problem as report " . $mainID . ". ")
                    . "Your report now follows the updates of report " . $mainID . " and is " . $statusName . ".";
            } elseif ($key === "rejected") {
                $message = "Your report " . $dupID . " was not accepted, together with report " . $mainID . " about the same problem.";
            } elseif ($key === "pending") {
                $message = "Your report " . $dupID . " is back to Pending and will be reviewed again.";
            } else {
                $message = "Your report " . $dupID . " is now " . $statusName . ".";
            }

            $notifID = "N" . bin2hex(random_bytes(16));
            $userID = (string) $dup["userID"];
            $notify->bind_param("ssss", $notifID, $userID, $dupID, $message);
            $notify->execute();

            if ($statusChanges) {
                $changed[] = $dupID;
            }
        }

        $select->close();
        $update->close();
        $history->close();
        $notify->close();

        return $changed;
    }
}
