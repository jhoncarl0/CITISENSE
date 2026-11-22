<?php
/*
 * CitiSense — barangay messages to residents (Messages page).
 *
 * POST JSON:
 *   { "action": "send", "userID": "USR001", "reportID": "REP024", "message": "..." }
 *       About a report: added to report_messages (the chat under that report
 *       in the app). reportID must be one of this resident's reports.
 *   { "action": "send", "userID": "USR001", "reportID": "", "message": "..." }
 *       General message: added to resident_messages.
 *   Both notify the resident (notification table, like status updates).
 *
 *   { "action": "reply", "type": "report", "id": "REP024", "message": "..." }
 *       Older form, still accepted: a message about that report.
 *
 * Comments on announcements are handled by announcement_comments_api.php.
 * Only residents and reports of the administrator's own barangay are accepted.
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

    require_once __DIR__ . "/partial/resident_messages.php";

    /* ------------------------------------------------------------- send */
    if ($action === "send" || $action === "reply") {
        if ($action === "reply") {
            // Older form: { type: "report", id: "REP024" }
            if ((string) ($input["type"] ?? "") !== "report") {
                messagesResponse(400, ["success" => false, "message" => "Invalid conversation."]);
            }
            $reportID = trim((string) ($input["id"] ?? ""));
            $userID = csReportOwner($conn, $barangayID, $reportID);
        } else {
            $userID = trim((string) ($input["userID"] ?? ""));
            $reportID = trim((string) ($input["reportID"] ?? ""));
        }
        $message = trim((string) ($input["message"] ?? ""));

        if ($userID === "" || strlen($userID) > 50 || strlen($reportID) > 50) {
            messagesResponse(400, ["success" => false, "message" => "Invalid conversation."]);
        }
        if ($message === "") {
            messagesResponse(400, ["success" => false, "message" => "Please type a message first."]);
        }
        if (mb_strlen($message) > 2000) {
            messagesResponse(400, ["success" => false, "message" => "The message is too long. Please keep it under 2,000 characters."]);
        }

        $resident = csLoadResidentConversation($conn, $barangayID, $userID);
        if (!$resident) {
            messagesResponse(404, ["success" => false, "message" => "Resident not found in your barangay."]);
        }
        if ($reportID !== "" && !in_array($reportID, array_column($resident["reports"], "id"), true)) {
            messagesResponse(404, ["success" => false, "message" => "That report does not belong to this resident."]);
        }

        $preview = mb_strimwidth($message, 0, 120, "…");
        $notifID = "N" . bin2hex(random_bytes(16));

        if ($reportID !== "") {
            $conn->begin_transaction();

            $stmt = $conn->prepare(
                "INSERT INTO report_messages (reportID, senderType, senderID, message) VALUES (?, 'barangay', ?, ?)"
            );
            $stmt->bind_param("sss", $reportID, $adminID, $message);
            $stmt->execute();
            $stmt->close();

            $notice = "The barangay replied about your report " . $reportID . ": " . $preview;
            $stmt = $conn->prepare(
                "INSERT INTO notification (notifID, userID, reportID, message, isRead) VALUES (?, ?, ?, ?, 0)"
            );
            $stmt->bind_param("ssss", $notifID, $userID, $reportID, $notice);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO resident_messages (barangayID, userID, senderType, senderID, message) VALUES (?, ?, 'barangay', ?, ?)"
            );
            $stmt->bind_param("ssss", $barangayID, $userID, $adminID, $message);
            $stmt->execute();
            $stmt->close();

            // The notification table may require a report; the message itself is saved either way.
            try {
                $notice = "New message from the barangay: " . $preview;
                $stmt = $conn->prepare(
                    "INSERT INTO notification (notifID, userID, reportID, message, isRead) VALUES (?, ?, NULL, ?, 0)"
                );
                $stmt->bind_param("sss", $notifID, $userID, $notice);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $notifyError) {
                error_log("Messages API: general message saved, notification skipped: " . $notifyError->getMessage());
            }
        }

        csMarkResidentRead($conn, $barangayID, $userID);

        messagesResponse(200, ["success" => true, "message" => "Your message was sent to " . $resident["name"] . "."]);
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
