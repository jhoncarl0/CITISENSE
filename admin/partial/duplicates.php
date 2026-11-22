<?php
require_once __DIR__ . "/helpers.php";
/*
 * CitiSense admin — possible duplicate report detection.
 *
 * Read-only: this never changes a report. It suggests pairs of OPEN reports
 * (Pending, In Progress, Overdue) that may describe the same problem, so an
 * administrator can review them. Nothing is rejected automatically.
 *
 * Signals (points):
 *   Distance between the two map pins   up to 45   (<=15 m 45, <=30 m 35, <=60 m 20)
 *   Same type of issue                  20
 *   Similar description                 up to 15   (shared meaningful words)
 *   Similar photo                       up to 30   (64-bit difference hash)
 *
 * A pair is suggested at 55 points or more, and only when the pins are close
 * or the photos are nearly identical. Pairs that are already linked by the
 * existing Original/Duplicate grouping (same location record) and pairs an
 * administrator marked "Not a duplicate" are left out.
 */

require_once __DIR__ . "/helpers.php";

if (!defined("CS_DUPLICATE_MIN_SCORE")) {
    define("CS_DUPLICATE_MIN_SCORE", 55);
}

if (!function_exists("csFindDuplicatePairs")) {

    /** Distance in metres between two latitude/longitude points. */
    function csDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** Meaningful lowercase words of a description (English + common Cebuano/Tagalog fillers removed). */
    function csDescriptionWords(string $text): array
    {
        static $stop = [
            "the", "a", "an", "and", "or", "of", "to", "in", "on", "at", "is", "are", "was", "it", "this",
            "that", "there", "here", "for", "with", "please", "po", "ang", "sa", "ng", "na", "ug", "og",
            "nga", "kay", "mga", "si", "ni", "naa", "dili", "very", "so", "be", "has", "have", "our", "we",
        ];

        $text = strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', " ", $text) ?? "");
        $words = preg_split('/\s+/', trim($text)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stop, true)
        )));
    }

    /** 0..1 similarity of two descriptions (shared words / all words). */
    function csDescriptionSimilarity(string $a, string $b): float
    {
        $wa = csDescriptionWords($a);
        $wb = csDescriptionWords($b);

        if (count($wa) < 2 || count($wb) < 2) {
            return 0.0;
        }

        $shared = count(array_intersect($wa, $wb));
        $all = count(array_unique(array_merge($wa, $wb)));

        return $all > 0 ? $shared / $all : 0.0;
    }

    /** Absolute file path of a stored photo, or "" if it is not on this server. */
    function csLocalImageFile(string $relativePath): string
    {
        $relativePath = trim($relativePath);

        if ($relativePath === "" || preg_match('~^(https?:)?//|^data:~i', $relativePath) || str_contains($relativePath, "..")) {
            return "";
        }

        return csFindMediaFile($relativePath);
    }

    /** 64-bit difference hash (16 hex characters) of an image file, or null. */
    function csComputeImageHash(string $file): ?string
    {
        if (!function_exists("imagecreatefromstring")) {
            return null;
        }

        $image = @imagecreatefromstring((string) @file_get_contents($file));

        if ($image === false) {
            return null;
        }

        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));
        imagedestroy($image);

        $bits = "";
        for ($y = 0; $y < 8; $y++) {
            $previous = null;
            for ($x = 0; $x < 9; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $gray = (($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114;
                if ($previous !== null) {
                    $bits .= $gray > $previous ? "1" : "0";
                }
                $previous = $gray;
            }
        }
        imagedestroy($small);

        $hex = "";
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return $hex;
    }

    /**
     * Photo hash with a database cache (report_image_hashes). When
     * $computeMissing is false only cached hashes are returned (fast path
     * for counts shown on every page).
     */
    function csImageHash(mysqli $conn, string $relativePath, bool $computeMissing): ?string
    {
        $file = csLocalImageFile($relativePath);

        if ($file === "") {
            return null;
        }

        $size = (int) @filesize($file);
        $time = (int) @filemtime($file);

        try {
            $stmt = $conn->prepare("SELECT fileSize, fileTime, hash FROM report_image_hashes WHERE imagePath = ? LIMIT 1");
            $stmt->bind_param("s", $relativePath);
            $stmt->execute();
            $cached = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (Throwable $error) {
            $cached = null;
        }

        if ($cached && (int) $cached["fileSize"] === $size && (int) $cached["fileTime"] === $time) {
            return (string) $cached["hash"];
        }

        if (!$computeMissing) {
            return null;
        }

        $hash = csComputeImageHash($file);

        if ($hash === null) {
            return null;
        }

        try {
            $now = date("Y-m-d H:i:s");
            $stmt = $conn->prepare("DELETE FROM report_image_hashes WHERE imagePath = ?");
            $stmt->bind_param("s", $relativePath);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO report_image_hashes (imagePath, fileSize, fileTime, hash, computedAt) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("siiss", $relativePath, $size, $time, $hash, $now);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $error) {
            error_log("CitiSense image hash cache: " . $error->getMessage());
        }

        return $hash;
    }

    /** Number of differing bits between two 16-hex-character hashes. */
    function csHashDistance(string $a, string $b): int
    {
        $distance = 0;

        for ($i = 0; $i < 16; $i += 4) {
            $x = hexdec(substr($a, $i, 4)) ^ hexdec(substr($b, $i, 4));
            $distance += substr_count(decbin((int) $x), "1");
        }

        return $distance;
    }

    /** Stable key for a pair of report IDs. */
    function csPairKey(string $a, string $b): string
    {
        return strcmp($a, $b) <= 0 ? $a . "|" . $b : $b . "|" . $a;
    }

    /**
     * Possible duplicate pairs for a barangay, highest score first.
     *
     * Each pair: [
     *   "key", "score", "confidence", "reasons" => string[],
     *   "older" => report, "newer" => report, "distance" => float|null
     * ]
     * A report: reportID, categoryID, categoryName, statusID, statusName,
     * description, title, address, dateReported, imagePath, resident.
     */
    function csFindDuplicatePairs(mysqli $conn, string $barangayID, bool $computeMissingHashes = true): array
    {
        $stmt = $conn->prepare(
            "SELECT r.reportID, r.categoryID, r.statusID, r.locationID, r.title, r.description, r.dateReported,
                    c.categoryName, s.statusName, l.address, l.latitude, l.longitude,
                    u.firstName, u.lastName,
                    (SELECT ri.imagePath FROM reportimages ri WHERE ri.reportID = r.reportID
                     ORDER BY ri.uploadedAt ASC LIMIT 1) AS imagePath
             FROM reports r
             INNER JOIN categories c ON c.categoryID = r.categoryID
             INNER JOIN stats s ON s.statusID = r.statusID
             LEFT JOIN location l ON l.locationID = r.locationID
             LEFT JOIN users u ON u.userID = r.userID
             WHERE r.barangayID = ?
               AND r.statusID IN ('STAT001', 'STAT002', 'STAT004')
             ORDER BY r.dateReported ASC, r.reportID ASC
             LIMIT 500"
        );
        $stmt->bind_param("s", $barangayID);
        $stmt->execute();
        $reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($reports) < 2) {
            return [];
        }

        /* Pairs already reviewed as "not a duplicate". */
        $dismissed = [];
        try {
            $stmt = $conn->prepare(
                "SELECT pairKey FROM duplicate_reviews WHERE barangayID = ? AND decision = 'not_duplicate'"
            );
            $stmt->bind_param("s", $barangayID);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $dismissed[(string) $row["pairKey"]] = true;
            }
            $stmt->close();
        } catch (Throwable $error) {
            /* duplicate_reviews not created yet: nothing dismissed */
        }

        foreach ($reports as &$report) {
            $report["reportID"] = (string) $report["reportID"];
            $report["resident"] = trim(($report["firstName"] ?? "") . " " . ($report["lastName"] ?? ""));
            $report["hash"] = !empty($report["imagePath"])
                ? csImageHash($conn, (string) $report["imagePath"], $computeMissingHashes)
                : null;
            $report["hasPin"] = is_numeric($report["latitude"]) && is_numeric($report["longitude"])
                && (float) $report["latitude"] != 0.0;
        }
        unset($report);

        $pairs = [];
        $count = count($reports);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $older = $reports[$i];
                $newer = $reports[$j];

                /* Already grouped together by the existing system. */
                if ((string) $older["locationID"] === (string) $newer["locationID"] &&
                    (string) $older["categoryID"] === (string) $newer["categoryID"]) {
                    continue;
                }

                $key = csPairKey($older["reportID"], $newer["reportID"]);

                if (isset($dismissed[$key])) {
                    continue;
                }

                $score = 0;
                $reasons = [];
                $distance = null;
                $locationPoints = 0;
                $imagePoints = 0;

                if ($older["hasPin"] && $newer["hasPin"]) {
                    $distance = csDistanceMeters(
                        (float) $older["latitude"], (float) $older["longitude"],
                        (float) $newer["latitude"], (float) $newer["longitude"]
                    );

                    if ($distance <= 15) {
                        $locationPoints = 45;
                    } elseif ($distance <= 30) {
                        $locationPoints = 35;
                    } elseif ($distance <= 60) {
                        $locationPoints = 20;
                    }

                    if ($locationPoints > 0) {
                        $reasons[] = $distance < 1
                            ? "Same spot on the map"
                            : "Only " . (int) round($distance) . " m apart";
                    }
                }

                $score += $locationPoints;

                if ((string) $older["categoryID"] === (string) $newer["categoryID"]) {
                    $score += 20;
                    $reasons[] = "Same type of issue";
                }

                $similarity = csDescriptionSimilarity((string) $older["description"], (string) $newer["description"]);
                if ($similarity >= 0.5) {
                    $score += 15;
                    $reasons[] = "Very similar description";
                } elseif ($similarity >= 0.3) {
                    $score += 8;
                    $reasons[] = "Similar description";
                }

                if ($older["hash"] !== null && $newer["hash"] !== null) {
                    $bits = csHashDistance($older["hash"], $newer["hash"]);
                    if ($bits <= 6) {
                        $imagePoints = 30;
                        $reasons[] = "Photos look almost the same";
                    } elseif ($bits <= 12) {
                        $imagePoints = 15;
                        $reasons[] = "Photos look similar";
                    }
                }

                $score += $imagePoints;

                if ($score < CS_DUPLICATE_MIN_SCORE || ($locationPoints === 0 && $imagePoints < 30)) {
                    continue;
                }

                $pairs[] = [
                    "key" => $key,
                    "score" => min(100, $score),
                    "confidence" => $score >= 80 ? "Very likely" : ($score >= 65 ? "Likely" : "Possible"),
                    "reasons" => $reasons,
                    "distance" => $distance,
                    "older" => $older,
                    "newer" => $newer,
                ];
            }
        }

        usort($pairs, static fn($a, $b) => [$b["score"], $b["newer"]["dateReported"]] <=> [$a["score"], $a["newer"]["dateReported"]]);

        return $pairs;
    }

    /**
     * Group connected pairs into "possibly the same problem" groups.
     *
     * Each group: [
     *   "id"      => stable group id,
     *   "score"   => highest pair score,
     *   "main"    => the suggested report to keep (In Progress/Overdue first,
     *                otherwise the earliest),
     *   "members" => other reports, each with "match" => best pair info
     *                (reasons, confidence, distance) towards the main report,
     *   "ids"     => all report IDs in the group
     * ]
     */
    function csGroupDuplicatePairs(array $pairs): array
    {
        $parent = [];
        $find = function ($id) use (&$parent, &$find) {
            if (!isset($parent[$id])) {
                $parent[$id] = $id;
            }
            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };

        $reports = [];
        foreach ($pairs as $pair) {
            $a = $pair["older"]["reportID"];
            $b = $pair["newer"]["reportID"];
            $reports[$a] = $pair["older"];
            $reports[$b] = $pair["newer"];
            $parent[$find($a)] = $find($b);
        }

        $buckets = [];
        foreach (array_keys($reports) as $id) {
            $buckets[$find($id)][] = $id;
        }

        $groups = [];

        foreach ($buckets as $ids) {
            $members = array_map(static fn($id) => $reports[$id], $ids);

            usort($members, static function ($x, $y) {
                $active = static fn($r) => in_array($r["statusID"], ["STAT002", "STAT004"], true) ? 0 : 1;
                return [$active($x), $x["dateReported"], $x["reportID"]] <=> [$active($y), $y["dateReported"], $y["reportID"]];
            });

            $main = array_shift($members);
            $groupPairs = array_values(array_filter(
                $pairs,
                static fn($p) => in_array($p["older"]["reportID"], $ids, true) && in_array($p["newer"]["reportID"], $ids, true)
            ));

            foreach ($members as &$member) {
                $best = null;
                foreach ($groupPairs as $pair) {
                    $touches = in_array($member["reportID"], [$pair["older"]["reportID"], $pair["newer"]["reportID"]], true);
                    if (!$touches) {
                        continue;
                    }
                    $withMain = in_array($main["reportID"], [$pair["older"]["reportID"], $pair["newer"]["reportID"]], true);
                    $rank = [$withMain ? 1 : 0, $pair["score"]];
                    if ($best === null || $rank > $best["rank"]) {
                        $other = $pair["older"]["reportID"] === $member["reportID"] ? $pair["newer"]["reportID"] : $pair["older"]["reportID"];
                        $best = [
                            "rank" => $rank,
                            "other" => $other,
                            "score" => $pair["score"],
                            "confidence" => $pair["confidence"],
                            "reasons" => $pair["reasons"],
                        ];
                    }
                }
                $member["match"] = $best;
            }
            unset($member);

            usort($members, static fn($x, $y) => ($y["match"]["score"] ?? 0) <=> ($x["match"]["score"] ?? 0));

            sort($ids);
            $groups[] = [
                "id" => substr(md5(implode(",", $ids)), 0, 10),
                "score" => max(array_column($groupPairs, "score")),
                "main" => $main,
                "members" => $members,
                "ids" => $ids,
            ];
        }

        usort($groups, static fn($a, $b) => [$b["score"], count($b["ids"])] <=> [$a["score"], count($a["ids"])]);

        return $groups;
    }

    /** reportID => list of [otherID, confidence, reasons] for quick lookups. */
    function csDuplicateIndex(array $pairs): array
    {
        $index = [];

        foreach ($pairs as $pair) {
            $a = $pair["older"]["reportID"];
            $b = $pair["newer"]["reportID"];
            $index[$a][] = ["other" => $b, "confidence" => $pair["confidence"], "reasons" => $pair["reasons"], "key" => $pair["key"]];
            $index[$b][] = ["other" => $a, "confidence" => $pair["confidence"], "reasons" => $pair["reasons"], "key" => $pair["key"]];
        }

        return $index;
    }
}
