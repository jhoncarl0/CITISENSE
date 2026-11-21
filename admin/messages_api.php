<?php
/*
 * CitiSense — barangay replies to residents.
 *
 * POST JSON:
 *   { "action": "reply", "type": "report", "id": "REP024", "message": "..." }
 *       Adds a barangay message to report_messages and notifies the resident
 *       (notification table, like status updates).
 *   { "action": "reply", "type": "announcement", "id": "34", "message": "..." }
 *       Adds a barangay reply to announcement_comments.
 *   { "action": "hide", "commentID": 12, "hidden": true }
 *       Hides (or shows again) a resident's comment on an announcement.
 *
 * Only conversations of the administrator's own barangay are accepted.
 */

session_start();

header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function messagesResponse(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    messagesResponse(405, ["success" => false, "message" => "Invalid request method."]);
}

$adminID = trim((string) ($_SESSION["admin_id"] ?? ""));
$barangayID = trim((string) ($_SESSION["barangay_id"] ?? ""));

if ($adminID === "" || $barangayID === "") {
    messagesResponse(401, ["success" => false, "message" => "Your session has expired. Please log in again."]);
}

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    messagesResponse(400, ["success" => false, "message" => "Invalid request data."]);
}

$action = (string) ($input["action"] ?? "");

try {
    require_once __DIR__ . "/../config/database.php";
    require_once __DIR__ . "/partial/schema.php";

    if (!csEnsureSchema($conn)) {
        messagesResponse(500, ["success" => false, "message" => "The database update is needed before messages can be sent."]);
    }

    /* ------------------------------------------------------------ reply */
    if ($action === "reply") {
        $type = (string) ($input["type"] ?? "");
        $id = trim((string) ($input["id"] ?? ""));
        $message = trim((string) ($input["message"] ?? ""));

        if (!in_array($type, ["report", "announcement"], true) || $id === "" || strlen($id) > 50) {
            messagesResponse(400, ["success" => false, "message" => "Invalid conversation."]);
        }

        if ($message === "") {
            messagesResponse(400, ["success" => false, "message" => "Please type a message first."]);
        }

        if (mb_strlen($message) > 2000) {
            messagesResponse(400, ["success" => false, "message" => "The message is too long. Please keep it under 2,000 characters."]);
        }

        if ($type === "report") {
            $stmt = $conn->prepare("SELECT reportID, userID FROM reports WHERE reportID = ? AND barangayID = ? LIMIT 1");
            $stmt->bind_param("ss", $id, $barangayID);
            $stmt->execute();
            $report = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$report) {
                messagesResponse(404, ["success" => false, "message" => "Report not found in your barangay."]);
            }

            $conn->begin_transaction();

            $stmt = $conn->prepare(
                "INSERT INTO report_messages (reportID, senderType, senderID, message) VALUES (?, 'barangay', ?, ?)"
            );
            $stmt->bind_param("sss", $id, $adminID, $message);
            $stmt->execute();
            $stmt->close();

            $notifID = "N" . bin2hex(random_bytes(16));
            $preview = mb_strimwidth($message, 0, 120, "…");
            $notice = "The barangay replied about your report " . $id . ": " . $preview;
            $residentID = (string) $report["userID"];

            $stmt = $conn->prepare(
                "INSERT INTO notification (notifID, userID, reportID, message, isRead) VALUES (?, ?, ?, ?, 0)"
            );
            $stmt->bind_param("ssss", $notifID, $residentID, $id, $notice);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
        } else {
            $announceID = (int) $id;
            $stmt = $conn->prepare("SELECT announceID FROM announcements WHERE announceID = ? AND barangayID = ? LIMIT 1");
            $stmt->bind_param("is", $announceID, $barangayID);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$found) {
                messagesResponse(404, ["success" => false, "message" => "Announcement not found in your barangay."]);
            }

            $stmt = $conn->prepare(
                "INSERT INTO announcement_comments (announceID, senderType, senderID, message) VALUES (?, 'barangay', ?, ?)"
            );
            $stmt->bind_param("iss", $announceID, $adminID, $message);
            $stmt->execute();
            $stmt->close();
        }

        messagesResponse(200, ["success" => true, "message" => "Your reply was sent."]);
    }

    /* ------------------------------------------------------------- hide */
    if ($action === "hide") {
        $commentID = (int) ($input["commentID"] ?? 0);
        $hidden = !empty($input["hidden"]) ? 1 : 0;

        $stmt = $conn->prepare(
            "SELECT c.commentID FROM announcement_comments c
             INNER JOIN announcements a ON a.announceID = c.announceID
             WHERE c.commentID = ? AND a.barangayID = ? AND c.senderType = 'resident'
             LIMIT 1"
        );
        $stmt->bind_param("is", $commentID, $barangayID);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$found) {
            messagesResponse(404, ["success" => false, "message" => "Comment not found."]);
        }

        $stmt = $conn->prepare("UPDATE announcement_comments SET isHidden = ? WHERE commentID = ?");
        $stmt->bind_param("ii", $hidden, $commentID);
        $stmt->execute();
        $stmt->close();

        messagesResponse(200, [
            "success" => true,
            "message" => $hidden ? "The comment is now hidden from residents." : "The comment is visible again.",
        ]);
    }

    messagesResponse(400, ["success" => false, "message" => "Unknown action."]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }
    error_log("Messages API failed: " . $error->getMessage());
    messagesResponse(500, ["success" => false, "message" => "The message could not be sent. Please try again."]);
}
