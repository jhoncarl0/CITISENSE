<?php
/*
 * CitiSense admin — Original / Duplicate report classification.
 *
 * Mirrors statusLoadCycles() in update_report_status.php so the Reports page
 * only offers status actions that the backend will accept:
 *   - Rejected reports are left out entirely.
 *   - Reports are grouped by barangay + category + location.
 *   - The earliest report of a cycle is the Original; later ones are
 *     Duplicates while the Original is open.
 *   - Once the Original is Resolved, a report submitted after its recorded
 *     resolution time (or, without history, after the Original's own
 *     submission time) starts a new cycle.
 *
 * Read-only: this never changes data.
 */

if (!function_exists("csClassifyReportCycles")) {

    /**
     * @return array<string, array{
     *     originalID: string,
     *     isOriginal: bool,
     *     cycleReportIDs: string[]
     * }>
     */
    function csClassifyReportCycles(mysqli $conn, string $barangayID): array
    {
        $stmt = $conn->prepare(
            "SELECT reportID, categoryID, locationID, statusID, dateReported
             FROM reports
             WHERE barangayID = ?
               AND statusID NOT IN (
                   SELECT rs.statusID FROM stats rs
                   WHERE LOWER(TRIM(rs.statusName)) = 'rejected'
               )
             ORDER BY dateReported ASC, reportID ASC"
        );

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param("s", $barangayID);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $resolvedAt = [];

        $stmt = $conn->prepare(
            "SELECT h.reportID, MIN(h.dateChanged) AS resolvedAt
             FROM reporthistory h
             INNER JOIN reports r ON r.reportID = h.reportID
             WHERE r.barangayID = ?
               AND h.statusID = 'STAT003'
             GROUP BY h.reportID"
        );

        if ($stmt) {
            $stmt->bind_param("s", $barangayID);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $resolvedAt[(string) $row["reportID"]] = (string) $row["resolvedAt"];
            }

            $stmt->close();
        }

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[$row["categoryID"] . "\0" . $row["locationID"]][] = $row;
        }

        $map = [];

        foreach ($buckets as $bucket) {
            $cycles = [];
            $current = null;

            foreach ($bucket as $report) {
                $startNew = $current === null;

                if ($current !== null) {
                    $original = $current[0];

                    if ((string) $original["statusID"] === "STAT003") {
                        $boundary = $resolvedAt[(string) $original["reportID"]] ?? "";

                        if ($boundary === "") {
                            $boundary = (string) $original["dateReported"];
                        }

                        if (strcmp((string) $report["dateReported"], $boundary) > 0) {
                            $startNew = true;
                        }
                    }
                }

                if ($startNew) {
                    if ($current !== null) {
                        $cycles[] = $current;
                    }
                    $current = [];
                }

                $current[] = $report;
            }

            if ($current !== null) {
                $cycles[] = $current;
            }

            foreach ($cycles as $cycle) {
                $originalID = (string) $cycle[0]["reportID"];
                $ids = array_map(static fn($r) => (string) $r["reportID"], $cycle);

                foreach ($ids as $id) {
                    $map[$id] = [
                        "originalID" => $originalID,
                        "isOriginal" => $id === $originalID,
                        "cycleReportIDs" => $ids,
                    ];
                }
            }
        }

        return $map;
    }
}
