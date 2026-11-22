<?php
/*
 * CitiSense admin — conversations with residents.
 *
 * Two kinds of conversation ("threads"):
 *   report        messages about one report      (existing table report_messages)
 *                 -> shown on the Messages page
 *   announcement  comments on one announcement   (new table announcement_comments)
 *                 -> shown under each announcement on the Announcements page
 *                    (see partial/announcement_comments.php); used here for
 *                    the menu badge and unread tracking
 *
 * Both tables use senderType 'resident' | 'barangay'. The mobile app writes
 * the residents' messages; the admin website reads them and writes the
 * barangay's replies. message_reads remembers, per barangay, the newest
 * message already seen in each thread, so unread counts survive logouts and
 * are shared by all administrators of the barangay. A resident message that
 * is older than the barangay's latest reply also counts as read.
 *
 * Collations: report_messages (and the new tables) use utf8mb4_general_ci,
 * while reports/users use utf8mb4_unicode_ci. MariaDB refuses to compare
 * columns of different collations ("Illegal mix of collations"), so every
 * join between them names the collation explicitly.
 */

require_once __DIR__ . "/helpers.php";

if (!function_exists("csMessageThreads")) {

    /** Conversations, newest activity first. $type: "all" | "report" | "announcement". */
    function csMessageThreads(mysqli $conn, string $barangayID, string $type = "all", string $search = ""): array
    {
        $threads = [];
        $like = "%" . $search . "%";

        if ($type === "all" || $type === "report") {
            $sql = "
                SELECT agg.reportID AS threadID, agg.lastAt, agg.total, agg.unread,
                       c.categoryName, l.address, s.statusName, u.firstName, u.lastName,
                       (SELECT m2.message FROM report_messages m2 WHERE m2.reportID = agg.reportID
                        ORDER BY m2.created_at DESC, m2.id DESC LIMIT 1) AS lastMessage,
                       (SELECT m3.senderType FROM report_messages m3 WHERE m3.reportID = agg.reportID
                        ORDER BY m3.created_at DESC, m3.id DESC LIMIT 1) AS lastSender
                FROM (
                    SELECT m.reportID,
                           MAX(m.created_at) AS lastAt,
                           COUNT(*) AS total,
                           SUM(CASE WHEN m.senderType = 'resident'
                                     AND m.created_at > COALESCE(mr.lastReadAt, '1970-01-01 00:00:00')
                                     AND m.created_at > COALESCE((SELECT MAX(br.created_at) FROM report_messages br
                                                                  WHERE br.reportID = m.reportID AND br.senderType = 'barangay'),
                                                                 '1970-01-01 00:00:00')
                                    THEN 1 ELSE 0 END) AS unread
                    FROM report_messages m
                    LEFT JOIN message_reads mr
                        ON mr.threadType = 'report' AND mr.threadID COLLATE utf8mb4_unicode_ci = m.reportID COLLATE utf8mb4_unicode_ci AND mr.barangayID = ?
                    GROUP BY m.reportID
                ) agg
                INNER JOIN reports r ON r.reportID = agg.reportID COLLATE utf8mb4_unicode_ci AND r.barangayID = ?
                INNER JOIN categories c ON c.categoryID = r.categoryID
                INNER JOIN stats s ON s.statusID = r.statusID
                LEFT JOIN location l ON l.locationID = r.locationID
                LEFT JOIN users u ON u.userID = r.userID
            ";
            $types = "ss";
            $params = [$barangayID, $barangayID];

            if ($search !== "") {
                $sql .= " WHERE (r.reportID LIKE ? OR c.categoryName LIKE ? OR u.firstName LIKE ? OR u.lastName LIKE ? OR l.address LIKE ?)";
                $types .= "sssss";
                array_push($params, $like, $like, $like, $like, $like);
            }

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();

            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $resident = trim(($row["firstName"] ?? "") . " " . ($row["lastName"] ?? ""));
                $threads[] = [
                    "type" => "report",
                    "id" => (string) $row["threadID"],
                    "title" => "Report " . $row["threadID"] . " · " . $row["categoryName"],
                    "subtitle" => $resident !== "" ? $resident : "Resident",
                    "status" => (string) $row["statusName"],
                    "lastMessage" => (string) $row["lastMessage"],
                    "lastSender" => (string) $row["lastSender"],
                    "lastAt" => (string) $row["lastAt"],
                    "total" => (int) $row["total"],
                    "unread" => (int) $row["unread"],
                ];
            }
            $stmt->close();
        }

        if ($type === "all" || $type === "announcement") {
            $sql = "
                SELECT agg.announceID AS threadID, agg.lastAt, agg.total, agg.unread, agg.people, a.title,
                       (SELECT c2.message FROM announcement_comments c2 WHERE c2.announceID = agg.announceID AND c2.isHidden = 0
                        ORDER BY c2.created_at DESC, c2.commentID DESC LIMIT 1) AS lastMessage,
                       (SELECT c3.senderType FROM announcement_comments c3 WHERE c3.announceID = agg.announceID AND c3.isHidden = 0
                        ORDER BY c3.created_at DESC, c3.commentID DESC LIMIT 1) AS lastSender
                FROM (
                    SELECT c.announceID,
                           MAX(c.created_at) AS lastAt,
                           COUNT(*) AS total,
                           COUNT(DISTINCT CASE WHEN c.senderType = 'resident' THEN c.senderID END) AS people,
                           SUM(CASE WHEN c.senderType = 'resident'
                                     AND c.created_at > COALESCE(mr.lastReadAt, '1970-01-01 00:00:00')
                                     AND c.created_at > COALESCE((SELECT MAX(bc.created_at) FROM announcement_comments bc
                                                                  WHERE bc.announceID = c.announceID AND bc.senderType = 'barangay'),
                                                                 '1970-01-01 00:00:00')
                                    THEN 1 ELSE 0 END) AS unread
                    FROM announcement_comments c
                    LEFT JOIN message_reads mr
                        ON mr.threadType = 'announcement' AND mr.threadID COLLATE utf8mb4_unicode_ci = CAST(c.announceID AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AND mr.barangayID = ?
                    WHERE c.isHidden = 0
                    GROUP BY c.announceID
                ) agg
                INNER JOIN announcements a ON a.announceID = agg.announceID AND a.barangayID = ?
            ";
            $types = "ss";
            $params = [$barangayID, $barangayID];

            if ($search !== "") {
                $sql .= " WHERE (a.title LIKE ? OR a.content LIKE ?)";
                $types .= "ss";
                array_push($params, $like, $like);
            }

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();

            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $people = (int) $row["people"];
                $threads[] = [
                    "type" => "announcement",
                    "id" => (string) $row["threadID"],
                    "title" => (string) $row["title"],
                    "subtitle" => "Announcement · " . $people . ($people === 1 ? " resident" : " residents"),
                    "status" => "",
                    "lastMessage" => (string) $row["lastMessage"],
                    "lastSender" => (string) $row["lastSender"],
                    "lastAt" => (string) $row["lastAt"],
                    "total" => (int) $row["total"],
                    "unread" => (int) $row["unread"],
                ];
            }
            $stmt->close();
        }

        usort($threads, static fn($a, $b) => strcmp($b["lastAt"], $a["lastAt"]));

        return $threads;
    }

    /**
     * Number of conversations with unread resident messages (for the menu badges).
     * $type "report" = Messages, "announcement" = announcements with new comments.
     */
    function csUnreadThreadCount(mysqli $conn, string $barangayID, string $type = "report"): int
    {
        try {
            $count = 0;
            foreach (csMessageThreads($conn, $barangayID, $type) as $thread) {
                if ($thread["unread"] > 0) {
                    $count++;
                }
            }
            return $count;
        } catch (Throwable $error) {
            return 0;
        }
    }

    /**
     * One conversation: header details and messages (oldest first), or null
     * when it does not belong to this barangay.
     */
    function csLoadThread(mysqli $conn, string $barangayID, string $type, string $id): ?array
    {
        if ($type === "report") {
            $stmt = $conn->prepare(
                "SELECT r.reportID, r.userID, r.description, r.dateReported, c.categoryName, s.statusName, l.address,
                        u.firstName, u.lastName,
                        (SELECT ri.imagePath FROM reportimages ri WHERE ri.reportID = r.reportID ORDER BY ri.uploadedAt ASC LIMIT 1) AS imagePath
                 FROM reports r
                 INNER JOIN categories c ON c.categoryID = r.categoryID
                 INNER JOIN stats s ON s.statusID = r.statusID
                 LEFT JOIN location l ON l.locationID = r.locationID
                 LEFT JOIN users u ON u.userID = r.userID
                 WHERE r.reportID = ? AND r.barangayID = ?
                 LIMIT 1"
            );
            $stmt->bind_param("ss", $id, $barangayID);
            $stmt->execute();
            $report = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$report) {
                return null;
            }

            $stmt = $conn->prepare(
                "SELECT m.id, m.senderType, m.senderID, m.message, m.created_at, u.firstName, u.lastName, u.profilePic
                 FROM report_messages m
                 LEFT JOIN users u ON u.userID = m.senderID COLLATE utf8mb4_unicode_ci
                 WHERE m.reportID = ?
                 ORDER BY m.created_at ASC, m.id ASC"
            );
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $resident = trim(($report["firstName"] ?? "") . " " . ($report["lastName"] ?? ""));

            return [
                "type" => "report",
                "id" => (string) $report["reportID"],
                "title" => "Report " . $report["reportID"] . " · " . $report["categoryName"],
                "subtitle" => ($resident !== "" ? $resident : "Resident") . " · " . ($report["address"] ?: "Location not provided"),
                "status" => (string) $report["statusName"],
                "image" => csMediaUrl($report["imagePath"] ?? ""),
                "description" => trim((string) $report["description"]),
                "date" => (string) $report["dateReported"],
                "messages" => array_map("csFormatMessage", $rows),
            ];
        }

        if ($type === "announcement") {
            $announceID = (int) $id;
            $stmt = $conn->prepare(
                "SELECT announceID, title, content, datePosted FROM announcements WHERE announceID = ? AND barangayID = ? LIMIT 1"
            );
            $stmt->bind_param("is", $announceID, $barangayID);
            $stmt->execute();
            $announcement = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$announcement) {
                return null;
            }

            $stmt = $conn->prepare(
                "SELECT c.commentID AS id, c.senderType, c.senderID, c.message, c.created_at, c.isHidden,
                        u.firstName, u.lastName, u.profilePic
                 FROM announcement_comments c
                 LEFT JOIN users u ON u.userID = c.senderID COLLATE utf8mb4_unicode_ci
                 WHERE c.announceID = ?
                 ORDER BY c.created_at ASC, c.commentID ASC"
            );
            $stmt->bind_param("i", $announceID);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            return [
                "type" => "announcement",
                "id" => (string) $announcement["announceID"],
                "title" => (string) $announcement["title"],
                "subtitle" => "Announcement posted " . csDate($announcement["datePosted"], "F j, Y"),
                "status" => "",
                "image" => "",
                "description" => trim((string) $announcement["content"]),
                "date" => (string) $announcement["datePosted"],
                "messages" => array_map("csFormatMessage", $rows),
            ];
        }

        return null;
    }

    function csFormatMessage(array $row): array
    {
        $name = trim(($row["firstName"] ?? "") . " " . ($row["lastName"] ?? ""));

        return [
            "id" => (int) $row["id"],
            "fromBarangay" => (string) $row["senderType"] === "barangay",
            "senderID" => (string) $row["senderID"],
            "name" => $name !== "" ? $name : ((string) $row["senderType"] === "barangay" ? "Barangay" : "Resident"),
            "avatar" => csMediaUrl($row["profilePic"] ?? ""),
            "message" => (string) $row["message"],
            "at" => (string) $row["created_at"],
            "hidden" => !empty($row["isHidden"]),
        ];
    }

    /** Remember that the barangay has seen everything currently in a thread. */
    function csMarkThreadRead(mysqli $conn, string $barangayID, string $type, string $id): void
    {
        if ($type === "report") {
            $stmt = $conn->prepare("SELECT MAX(created_at) AS latest FROM report_messages WHERE reportID = ?");
            $stmt->bind_param("s", $id);
        } else {
            $announceID = (int) $id;
            $stmt = $conn->prepare("SELECT MAX(created_at) AS latest FROM announcement_comments WHERE announceID = ?");
            $stmt->bind_param("i", $announceID);
        }

        $stmt->execute();
        $latest = $stmt->get_result()->fetch_assoc()["latest"] ?? null;
        $stmt->close();

        if (!$latest) {
            return;
        }

        /* Use the newest message's own timestamp, so database and PHP time zones never disagree. */
        $stmt = $conn->prepare("DELETE FROM message_reads WHERE threadType = ? AND threadID = ? AND barangayID = ?");
        $stmt->bind_param("sss", $type, $id, $barangayID);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO message_reads (threadType, threadID, barangayID, lastReadAt) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $type, $id, $barangayID, $latest);
        $stmt->execute();
        $stmt->close();
    }
}
