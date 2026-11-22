<?php
/*
 * CitiSense admin — comments shown directly under each announcement.
 *
 * Comments live in announcement_comments (senderType 'resident' | 'barangay').
 * Residents write theirs in the CitiSense app; the barangay posts from the
 * Announcements page. Used by admin/announcements.php (first render) and
 * admin/announcement_comments_api.php (add / hide / delete / mark as read),
 * so a comment always looks the same however it got on the page.
 *
 * "New" = a visible resident comment newer than both the barangay's last
 * visit to that announcement's comments (message_reads) and the barangay's
 * own latest comment there.
 */

require_once __DIR__ . "/helpers.php";

if (!function_exists("annLoadComments")) {

    /**
     * Comments for the given announcements, oldest first.
     * Returns [announceID => ["comments" => [...], "total" => int, "residents" => int, "unread" => int]].
     * total and residents count visible comments only; hidden ones are listed for the admin but not counted.
     */
    function annLoadComments(mysqli $conn, string $barangayID, array $announceIDs): array
    {
        $ids = array_values(array_unique(array_map("intval", $announceIDs)));
        $data = [];
        foreach ($ids as $id) {
            $data[$id] = ["comments" => [], "total" => 0, "residents" => 0, "unread" => 0];
        }
        if (!$ids) {
            return $data;
        }

        $marks = implode(",", array_fill(0, count($ids), "?"));
        $stmt = $conn->prepare(
            "SELECT c.commentID, c.announceID, c.senderType, c.senderID, c.message, c.isHidden, c.created_at,
                    u.firstName, u.lastName, u.profilePic
             FROM announcement_comments c
             LEFT JOIN users u ON u.userID = c.senderID COLLATE utf8mb4_unicode_ci
             WHERE c.announceID IN ($marks)
             ORDER BY c.created_at ASC, c.commentID ASC"
        );
        $stmt->bind_param(str_repeat("i", count($ids)), ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $lastRead = [];
        $stmt = $conn->prepare(
            "SELECT threadID, lastReadAt FROM message_reads WHERE threadType = 'announcement' AND barangayID = ?"
        );
        $stmt->bind_param("s", $barangayID);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $lastRead[(int) $row["threadID"]] = (string) $row["lastReadAt"];
        }
        $stmt->close();

        $lastBarangay = [];
        foreach ($rows as $row) {
            if ($row["senderType"] === "barangay") {
                $lastBarangay[(int) $row["announceID"]] = (string) $row["created_at"];
            }
        }

        foreach ($rows as $row) {
            $id = (int) $row["announceID"];
            $comment = annFormatComment($row);
            $seenUntil = max($lastRead[$id] ?? "", $lastBarangay[$id] ?? "");
            $comment["isNew"] = !$comment["fromBarangay"] && !$comment["hidden"] && $comment["at"] > $seenUntil;

            $data[$id]["comments"][] = $comment;
            if (!$comment["hidden"]) {
                $data[$id]["total"]++;
                if (!$comment["fromBarangay"]) {
                    $data[$id]["residents"]++;
                }
            }
            if ($comment["isNew"]) {
                $data[$id]["unread"]++;
            }
        }

        return $data;
    }

    function annFormatComment(array $row): array
    {
        $fromBarangay = (string) $row["senderType"] === "barangay";
        $name = trim(($row["firstName"] ?? "") . " " . ($row["lastName"] ?? ""));

        return [
            "id" => (int) $row["commentID"],
            "announceID" => (int) $row["announceID"],
            "fromBarangay" => $fromBarangay,
            "name" => $name !== "" ? $name : ($fromBarangay ? "Barangay" : "Resident"),
            "avatar" => csMediaUrl($row["profilePic"] ?? ""),
            "message" => (string) $row["message"],
            "at" => (string) $row["created_at"],
            "hidden" => !empty($row["isHidden"]),
            "isNew" => false,
        ];
    }

    /** "No comments yet", "3 comments · 2 from residents", "1 comment · from the barangay" … */
    function annCommentSummary(int $total, int $residents): string
    {
        if ($total === 0) {
            return "No comments yet";
        }

        $text = $total . ($total === 1 ? " comment" : " comments");
        if ($residents === 0) {
            $text .= " · from the barangay";
        } elseif ($residents < $total) {
            $text .= " · " . $residents . " from residents";
        }

        return $text;
    }

    /** One comment as an <li>. */
    function annCommentHtml(array $comment): string
    {
        $classes = "ann-comment"
            . ($comment["fromBarangay"] ? " is-mine" : "")
            . ($comment["hidden"] ? " is-hidden" : "")
            . ($comment["isNew"] ? " is-new" : "");

        if ($comment["fromBarangay"]) {
            $avatar = '<i class="bi bi-megaphone-fill" aria-hidden="true"></i>';
        } elseif ($comment["avatar"] !== "") {
            $avatar = '<img src="' . csEscape($comment["avatar"]) . '" alt="">';
        } else {
            $avatar = csEscape(strtoupper(mb_substr($comment["name"], 0, 1)));
        }

        $html = '<li class="' . $classes . '" data-comment-id="' . $comment["id"] . '">';
        $html .= '<span class="cs-avatar ann-comment-avatar" aria-hidden="true">' . $avatar . '</span>';
        $html .= '<div class="ann-comment-body">';
        $html .= '<div class="ann-comment-head">';
        $html .= '<strong>' . csEscape($comment["name"]) . '</strong>';
        if ($comment["fromBarangay"]) {
            $html .= '<span class="ann-comment-tag">Barangay</span>';
        }
        if ($comment["isNew"]) {
            $html .= '<span class="ann-comment-tag is-new">New</span>';
        }
        $html .= '<time datetime="' . csEscape($comment["at"]) . '" title="' . csEscape(csDate($comment["at"], "F j, Y · g:i A")) . '">'
            . csEscape(csTimeAgo($comment["at"])) . '</time>';
        $html .= '</div>';
        $html .= '<p>' . nl2br(csEscape($comment["message"])) . '</p>';

        if ($comment["hidden"]) {
            $html .= '<span class="ann-comment-hidden-note"><i class="bi bi-eye-slash" aria-hidden="true"></i> Hidden from residents</span>';
        }

        $html .= '<div class="ann-comment-actions">';
        if ($comment["fromBarangay"]) {
            $html .= '<button type="button" class="ann-comment-action is-danger" data-comment-delete="' . $comment["id"] . '">'
                . '<i class="bi bi-trash" aria-hidden="true"></i> Delete</button>';
        } else {
            $html .= '<button type="button" class="ann-comment-action" data-comment-hide="' . $comment["id"] . '" data-hidden="' . ($comment["hidden"] ? "0" : "1") . '">'
                . ($comment["hidden"]
                    ? '<i class="bi bi-eye" aria-hidden="true"></i> Show again'
                    : '<i class="bi bi-eye-slash" aria-hidden="true"></i> Hide') . '</button>';
        }
        $html .= '</div>';

        $html .= '</div></li>';

        return $html;
    }
}
