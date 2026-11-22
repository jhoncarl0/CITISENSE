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
 * Replies (like Facebook): message_replies links a reply to the comment it
 * answers. Replies are shown one level deep, under their top-level comment;
 * a reply to a reply also goes there and names the person it answers.
 *
 * "New" = a visible resident comment newer than both the barangay's last
 * visit to that announcement's comments (message_reads) and the barangay's
 * own latest comment there.
 */

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/replies.php";

if (!function_exists("annLoadComments")) {

    /**
     * Comments for the given announcements, oldest first.
     * Returns [announceID => ["comments" => [...flat...], "threads" => [top-level comments, each with "replies"],
     *                        "total" => int, "residents" => int, "unread" => int]].
     * total and residents count visible comments only; hidden ones are listed for the admin but not counted.
     */
    function annLoadComments(mysqli $conn, string $barangayID, array $announceIDs): array
    {
        $ids = array_values(array_unique(array_map("intval", $announceIDs)));
        $data = [];
        foreach ($ids as $id) {
            $data[$id] = ["comments" => [], "threads" => [], "total" => 0, "residents" => 0, "unread" => 0];
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

        /* Replies: which comment each one answers, grouped under its top-level comment. */
        $all = [];
        foreach ($data as $entry) {
            foreach ($entry["comments"] as $comment) {
                $all[] = ["announcement_comment", $comment["id"]];
            }
        }
        $parents = $all ? csReplyParents($conn, $all) : [];

        foreach ($data as &$entry) {
            $byID = [];
            foreach ($entry["comments"] as $index => $comment) {
                $byID[$comment["id"]] = $index;
            }

            foreach ($entry["comments"] as &$comment) {
                $parent = $parents["announcement_comment:" . $comment["id"]] ?? null;
                $comment["parentID"] = ($parent && isset($byID[$parent["id"]])) ? $parent["id"] : 0;
                $comment["parentMissing"] = $parent && !isset($byID[$parent["id"]]);
            }
            unset($comment);

            foreach ($entry["comments"] as &$comment) {
                $root = $comment["id"];
                $guard = 0;
                while (($up = $entry["comments"][$byID[$root]]["parentID"]) && $guard++ < 100) {
                    $root = $up;
                }
                $comment["rootID"] = $root;
                $comment["replyToName"] = ($comment["parentID"] && $comment["parentID"] !== $root)
                    ? $entry["comments"][$byID[$comment["parentID"]]]["name"]
                    : "";
            }
            unset($comment);

            $threads = [];
            foreach ($entry["comments"] as $comment) {
                if ($comment["rootID"] === $comment["id"]) {
                    $threads[$comment["id"]] = $comment + ["replies" => []];
                }
            }
            foreach ($entry["comments"] as $comment) {
                if ($comment["rootID"] !== $comment["id"]) {
                    $threads[$comment["rootID"]]["replies"][] = $comment;
                }
            }
            $entry["threads"] = array_values($threads);
        }
        unset($entry);

        return $data;
    }

    /** One comment (with its replies when it is a top-level comment), found by ID. */
    function annFindComment(array $entry, int $commentID): ?array
    {
        foreach ($entry["threads"] as $thread) {
            if ($thread["id"] === $commentID) {
                return $thread;
            }
            foreach ($thread["replies"] as $reply) {
                if ($reply["id"] === $commentID) {
                    return $reply;
                }
            }
        }
        return null;
    }

    /** IDs of all replies below a comment (replies, their replies, …). */
    function annReplyDescendants(array $entry, int $commentID): array
    {
        $children = [];
        foreach ($entry["comments"] as $comment) {
            if (!empty($comment["parentID"])) {
                $children[$comment["parentID"]][] = $comment["id"];
            }
        }

        $found = [];
        $queue = [$commentID];
        while ($queue) {
            $next = array_shift($queue);
            foreach ($children[$next] ?? [] as $child) {
                if (!in_array($child, $found, true)) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }
        return $found;
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

        $isTop = array_key_exists("replies", $comment);
        $classes .= $isTop ? "" : " is-reply";

        $html = '<li class="' . $classes . '" data-comment-id="' . $comment["id"] . '"'
            . ($isTop ? ' data-root-id="' . $comment["id"] . '"' : '')
            . (!empty($comment["parentID"]) ? ' data-parent-id="' . (int) $comment["parentID"] . '"' : '') . '>';
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
        if (!empty($comment["replyToName"])) {
            $html .= '<span class="ann-reply-to"><i class="bi bi-caret-right-fill" aria-hidden="true"></i>' . csEscape($comment["replyToName"]) . '</span>';
        } elseif (!empty($comment["parentMissing"])) {
            $html .= '<span class="ann-reply-to is-missing"><i class="bi bi-reply" aria-hidden="true"></i>Reply to a deleted comment</span>';
        }
        $html .= '<time datetime="' . csEscape($comment["at"]) . '" title="' . csEscape(csDate($comment["at"], "F j, Y · g:i A")) . '">'
            . csEscape(csTimeAgo($comment["at"])) . '</time>';
        $html .= '</div>';
        $html .= '<p>' . nl2br(csEscape($comment["message"])) . '</p>';

        if ($comment["hidden"]) {
            $html .= '<span class="ann-comment-hidden-note"><i class="bi bi-eye-slash" aria-hidden="true"></i> Hidden from residents</span>';
        }

        $html .= '<div class="ann-comment-actions">';
        $html .= '<button type="button" class="ann-comment-action" data-comment-reply="' . $comment["id"] . '"'
            . ' data-root="' . (int) ($comment["rootID"] ?? $comment["id"]) . '" data-name="' . csEscape($comment["name"]) . '">'
            . '<i class="bi bi-reply" aria-hidden="true"></i> Reply</button>';
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

        $html .= '</div>';

        if ($isTop) {
            $html .= '<ul class="ann-replies" data-replies-for="' . $comment["id"] . '">';
            foreach ($comment["replies"] as $reply) {
                $html .= annCommentHtml($reply);
            }
            $html .= '</ul>';
        }

        $html .= '</li>';

        return $html;
    }
}
