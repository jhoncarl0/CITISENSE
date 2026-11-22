<?php
/*
 * CitiSense admin — "reply to a specific message / comment".
 *
 * The message_replies table links a reply to the message or comment it
 * answers, without changing the tables the mobile app already uses:
 *   itemType / parentType   'report_message'       report_messages.id
 *                           'resident_message'     resident_messages.messageID
 *                           'announcement_comment' announcement_comments.commentID
 * Rows are removed together with their reply; a reply whose parent was deleted
 * keeps its row, so the page can say "Original message was deleted".
 */

if (!function_exists("csReplyParents")) {

    /** Message key ("r12" / "d5") to [type, id], or null. */
    function csMessageKeyParts(string $key): ?array
    {
        if (!preg_match('/^([rd])(\d{1,10})$/', $key, $match)) {
            return null;
        }
        return [$match[1] === "r" ? "report_message" : "resident_message", (int) $match[2]];
    }

    /** [type, id] to message key ("r12" / "d5"). */
    function csMessageKey(string $type, int $id): string
    {
        return ($type === "report_message" ? "r" : "d") . $id;
    }

    /**
     * Parents of the given items: ["type:id" => ["type" => ..., "id" => ...]].
     * $items is a list of [type, id].
     */
    function csReplyParents(mysqli $conn, array $items): array
    {
        $byType = [];
        foreach ($items as [$type, $id]) {
            $byType[$type][] = (int) $id;
        }

        $parents = [];
        foreach ($byType as $type => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
                $marks = implode(",", array_fill(0, count($chunk), "?"));
                $stmt = $conn->prepare(
                    "SELECT itemID, parentType, parentID FROM message_replies
                     WHERE itemType = ? AND itemID IN ($marks)"
                );
                $stmt->bind_param("s" . str_repeat("i", count($chunk)), $type, ...$chunk);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                    $parents[$type . ":" . $row["itemID"]] = [
                        "type" => (string) $row["parentType"],
                        "id" => (int) $row["parentID"],
                    ];
                }
                $stmt->close();
            }
        }

        return $parents;
    }

    function csSaveReplyLink(mysqli $conn, string $itemType, int $itemID, string $parentType, int $parentID): void
    {
        $stmt = $conn->prepare(
            "INSERT INTO message_replies (itemType, itemID, parentType, parentID) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("sisi", $itemType, $itemID, $parentType, $parentID);
        $stmt->execute();
        $stmt->close();
    }

    /** Forget that this item was a reply (call when the item itself is deleted). */
    function csDeleteReplyLink(mysqli $conn, string $itemType, int $itemID): void
    {
        $stmt = $conn->prepare("DELETE FROM message_replies WHERE itemType = ? AND itemID = ?");
        $stmt->bind_param("si", $itemType, $itemID);
        $stmt->execute();
        $stmt->close();
    }

    /** Short one-line preview of a message for the "replying to" quote. */
    function csReplyPreview(string $text, int $width = 90): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/u', " ", $text)), 0, $width, "…");
    }
}
