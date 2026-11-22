<?php
/*
 * CitiSense — comments under announcements (Announcements page).
 *
 * POST JSON:
 *   { "action": "add",    "announceID": 34, "message": "...", "parentID": 12 }
 *                                                               barangay posts a comment, or (parentID)
 *                                                               a reply to comment 12
 *   { "action": "hide",   "commentID": 12, "hidden": true }      hide / show a resident's comment
 *   { "action": "delete", "commentID": 15 }                      delete one of the barangay's comments
 *                                                               (and the replies below it)
 *   { "action": "read",   "announceID": 34 }                     mark the comments as seen
 *
 * Replies with the updated comment HTML and the announcement's comment
 * summary, so the page can update without reloading. Only announcements of
 * the administrator's own barangay are accepted.
 */

session_start();

header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function commentsResponse(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    commentsResponse(405, ["success" => false, "message" => "Invalid request method."]);
}

$adminID = trim((string) ($_SESSION["admin_id"] ?? ""));
$barangayID = trim((string) ($_SESSION["barangay_id"] ?? ""));

if ($adminID === "" || $barangayID === "") {
    commentsResponse(401, ["success" => false, "message" => "Your session has expired. Please log in again."]);
}

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    commentsResponse(400, ["success" => false, "message" => "Invalid request data."]);
}

$action = (string) ($input["action"] ?? "");

try {
    require_once __DIR__ . "/../config/database.php";
    require_once __DIR__ . "/partial/schema.php";
    require_once __DIR__ . "/partial/messages.php";
    require_once __DIR__ . "/partial/announcement_comments.php";
    require_once __DIR__ . "/partial/replies.php";

    if (!csEnsureSchema($conn)) {
        commentsResponse(500, ["success" => false, "message" => "The database update is needed before comments can be used."]);
    }

    /** The announcement ID if it belongs to this barangay, else 0. */
    $ownAnnouncement = static function (int $announceID) use ($conn, $barangayID): int {
        $stmt = $conn->prepare("SELECT announceID FROM announcements WHERE announceID = ? AND barangayID = ? LIMIT 1");
        $stmt->bind_param("is", $announceID, $barangayID);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found ? (int) $found["announceID"] : 0;
    };

    /** The comment (with its announcement) if it belongs to this barangay, else null. */
    $ownComment = static function (int $commentID) use ($conn, $barangayID): ?array {
        $stmt = $conn->prepare(
            "SELECT c.commentID, c.announceID, c.senderType FROM announcement_comments c
             INNER JOIN announcements a ON a.announceID = c.announceID
             WHERE c.commentID = ? AND a.barangayID = ?
             LIMIT 1"
        );
        $stmt->bind_param("is", $commentID, $barangayID);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found ?: null;
    };

    /** Summary text and one comment's HTML for the response. */
    $respond = static function (int $announceID, string $message, ?int $commentID = null, array $extra = []) use ($conn, $barangayID): void {
        $data = annLoadComments($conn, $barangayID, [$announceID])[$announceID];
        $comment = $commentID !== null ? annFindComment($data, $commentID) : null;
        commentsResponse(200, [
            "success" => true,
            "message" => $message,
            "summary" => annCommentSummary($data["total"], $data["residents"]),
            "count" => count($data["comments"]),
            "html" => $comment ? annCommentHtml($comment) : "",
            "rootID" => $comment ? (int) $comment["rootID"] : 0,
        ] + $extra);
    };

    /* -------------------------------------------------------------- add */
    if ($action === "add") {
        $announceID = $ownAnnouncement((int) ($input["announceID"] ?? 0));
        $message = trim((string) ($input["message"] ?? ""));

        if (!$announceID) {
            commentsResponse(404, ["success" => false, "message" => "Announcement not found in your barangay."]);
        }
        if ($message === "") {
            commentsResponse(400, ["success" => false, "message" => "Please write a comment first."]);
        }
        if (mb_strlen($message) > 2000) {
            commentsResponse(400, ["success" => false, "message" => "The comment is too long. Please keep it under 2,000 characters."]);
        }

        $parent = null;
        $parentID = (int) ($input["parentID"] ?? 0);
        if ($parentID > 0) {
            $parent = $ownComment($parentID);
            if (!$parent || (int) $parent["announceID"] !== $announceID) {
                commentsResponse(404, ["success" => false, "message" => "The comment you are replying to was not found. It may have been deleted."]);
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO announcement_comments (announceID, senderType, senderID, message) VALUES (?, 'barangay', ?, ?)"
        );
        $stmt->bind_param("iss", $announceID, $adminID, $message);
        $stmt->execute();
        $commentID = (int) $conn->insert_id;
        $stmt->close();

        if ($parent) {
            csSaveReplyLink($conn, "announcement_comment", $commentID, "announcement_comment", (int) $parent["commentID"]);
        }

        // Posting counts as having read everything above it.
        csMarkThreadRead($conn, $barangayID, "announcement", (string) $announceID);

        $respond($announceID, $parent ? "Your reply was posted." : "Your comment was posted.", $commentID);
    }

    /* ------------------------------------------------------------- hide */
    if ($action === "hide") {
        $comment = $ownComment((int) ($input["commentID"] ?? 0));
        $hidden = !empty($input["hidden"]) ? 1 : 0;

        if (!$comment || $comment["senderType"] !== "resident") {
            commentsResponse(404, ["success" => false, "message" => "Comment not found."]);
        }

        $commentID = (int) $comment["commentID"];
        $stmt = $conn->prepare("UPDATE announcement_comments SET isHidden = ? WHERE commentID = ?");
        $stmt->bind_param("ii", $hidden, $commentID);
        $stmt->execute();
        $stmt->close();

        $respond(
            (int) $comment["announceID"],
            $hidden ? "The comment is now hidden from residents." : "The comment is visible again.",
            $commentID
        );
    }

    /* ----------------------------------------------------------- delete */
    if ($action === "delete") {
        $comment = $ownComment((int) ($input["commentID"] ?? 0));

        if (!$comment || $comment["senderType"] !== "barangay") {
            commentsResponse(404, ["success" => false, "message" => "Comment not found."]);
        }

        $commentID = (int) $comment["commentID"];
        $announceID = (int) $comment["announceID"];

        // Like Facebook: deleting a comment also removes the replies below it.
        $entry = annLoadComments($conn, $barangayID, [$announceID])[$announceID];
        $removed = array_merge([$commentID], annReplyDescendants($entry, $commentID));

        $conn->begin_transaction();
        $stmt = $conn->prepare("DELETE FROM announcement_comments WHERE commentID = ?");
        foreach ($removed as $id) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            csDeleteReplyLink($conn, "announcement_comment", $id);
        }
        $stmt->close();
        $conn->commit();

        $replies = count($removed) - 1;
        $respond(
            $announceID,
            $replies > 0 ? "Your comment and " . $replies . ($replies === 1 ? " reply were" : " replies were") . " deleted." : "Your comment was deleted.",
            null,
            ["removed" => $removed]
        );
    }

    /* ------------------------------------------------------------- read */
    if ($action === "read") {
        $announceID = $ownAnnouncement((int) ($input["announceID"] ?? 0));
        if (!$announceID) {
            commentsResponse(404, ["success" => false, "message" => "Announcement not found in your barangay."]);
        }

        csMarkThreadRead($conn, $barangayID, "announcement", (string) $announceID);
        commentsResponse(200, ["success" => true]);
    }

    commentsResponse(400, ["success" => false, "message" => "Unknown action."]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }
    error_log("Announcement comments API failed: " . $error->getMessage());
    commentsResponse(500, ["success" => false, "message" => "Something went wrong. Please try again."]);
}
