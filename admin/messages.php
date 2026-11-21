<?php
session_start();

if (empty($_SESSION["admin_id"]) || empty($_SESSION["barangay_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/partial/helpers.php";
require_once __DIR__ . "/partial/schema.php";
require_once __DIR__ . "/partial/messages.php";

$barangayID = (string) $_SESSION["barangay_id"];
$schemaReady = csEnsureSchema($conn);

$filter = in_array($_GET["filter"] ?? "", ["report", "announcement"], true) ? $_GET["filter"] : "all";
$search = mb_substr(trim((string) ($_GET["search"] ?? "")), 0, 80);
$type = in_array($_GET["type"] ?? "", ["report", "announcement"], true) ? $_GET["type"] : "";
$threadID = mb_substr(trim((string) ($_GET["id"] ?? "")), 0, 50);

$threads = $schemaReady ? csMessageThreads($conn, $barangayID, $filter, $search) : [];
$thread = ($schemaReady && $type !== "" && $threadID !== "") ? csLoadThread($conn, $barangayID, $type, $threadID) : null;

if ($thread) {
    csMarkThreadRead($conn, $barangayID, $type, $thread["id"]);
    foreach ($threads as &$item) {
        if ($item["type"] === $type && $item["id"] === $thread["id"]) {
            $item["unread"] = 0;
        }
    }
    unset($item);
}

$unreadTotal = count(array_filter($threads, static fn($t) => $t["unread"] > 0));
$listQuery = ["filter" => $filter !== "all" ? $filter : "", "search" => $search];

$pageTitle = "Messages";
$pageStyles = ["messages.css"];
include __DIR__ . "/partial/head.php";
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">

    <header class="cs-page-header">
        <div>
            <h1>Messages</h1>
            <p>Read what residents say about their reports and your announcements, and reply to them.</p>
        </div>
        <?php if ($unreadTotal > 0): ?>
            <span class="msg-unread-summary"><i class="bi bi-envelope-exclamation" aria-hidden="true"></i> <?= $unreadTotal ?> <?= $unreadTotal === 1 ? "conversation needs" : "conversations need" ?> a look</span>
        <?php endif; ?>
    </header>

    <?php if (!$schemaReady): ?>
        <?= csSchemaNotice() ?>
    <?php endif; ?>

    <section class="cs-card msg-layout<?= $thread ? " has-thread" : "" ?>">

        <!-- CONVERSATION LIST -->
        <aside class="msg-sidebar" aria-label="Conversations">
            <nav class="msg-filters" aria-label="Show conversations">
                <?php foreach (["all" => "All", "report" => "Reports", "announcement" => "Announcements"] as $key => $label): ?>
                    <a href="<?= csEscape(csUrl("messages.php", ["filter" => $key !== "all" ? $key : "", "search" => $search])) ?>"
                       class="msg-filter<?= $filter === $key ? " is-active" : "" ?>"<?= $filter === $key ? ' aria-current="page"' : "" ?>><?= $label ?></a>
                <?php endforeach; ?>
            </nav>

            <form class="msg-search" method="GET" action="messages.php" role="search">
                <?php if ($filter !== "all"): ?><input type="hidden" name="filter" value="<?= csEscape($filter) ?>"><?php endif; ?>
                <label class="cs-sr-only" for="msgSearch">Search conversations</label>
                <div class="cs-input-icon">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="msgSearch" name="search" class="cs-input" placeholder="Search by name, report ID or title" value="<?= csEscape($search) ?>">
                </div>
            </form>

            <?php if ($threads): ?>
                <ul class="msg-thread-list">
                    <?php foreach ($threads as $item): ?>
                        <?php $active = $thread && $item["type"] === $thread["type"] && $item["id"] === $thread["id"]; ?>
                        <li>
                            <a class="msg-thread<?= $active ? " is-active" : "" ?><?= $item["unread"] > 0 ? " is-unread" : "" ?>"
                               href="<?= csEscape(csUrl("messages.php", array_merge($listQuery, ["type" => $item["type"], "id" => $item["id"]]))) ?>"
                               <?= $active ? 'aria-current="true"' : "" ?>>
                                <span class="msg-thread-icon is-<?= $item["type"] ?>">
                                    <i class="bi <?= $item["type"] === "report" ? "bi-clipboard-check" : "bi-megaphone" ?>" aria-hidden="true"></i>
                                </span>
                                <span class="msg-thread-text">
                                    <span class="msg-thread-top">
                                        <strong><?= csEscape($item["title"]) ?></strong>
                                        <time><?= csEscape(csTimeAgo($item["lastAt"])) ?></time>
                                    </span>
                                    <span class="msg-thread-sub"><?= csEscape($item["subtitle"]) ?></span>
                                    <span class="msg-thread-last">
                                        <?= $item["lastSender"] === "barangay" ? "You: " : "" ?><?= csEscape(mb_strimwidth($item["lastMessage"], 0, 90, "…")) ?>
                                    </span>
                                </span>
                                <?php if ($item["unread"] > 0): ?>
                                    <span class="msg-unread-count" aria-label="<?= $item["unread"] ?> new"><?= $item["unread"] ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="cs-empty msg-empty-list">
                    <span class="cs-empty-icon"><i class="bi bi-chat-dots" aria-hidden="true"></i></span>
                    <h3><?= $search !== "" ? "No conversations found" : "No messages yet" ?></h3>
                    <p><?= $search !== "" ? "Try another name or report ID." : "When residents send a message about a report or comment on an announcement in the CitiSense app, it will appear here." ?></p>
                </div>
            <?php endif; ?>
        </aside>

        <!-- CONVERSATION -->
        <div class="msg-main">
            <?php if ($thread): ?>
                <header class="msg-thread-header">
                    <a class="cs-btn cs-btn-ghost cs-btn-sm msg-back" href="<?= csEscape(csUrl("messages.php", $listQuery)) ?>">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> All conversations
                    </a>
                    <div class="msg-thread-heading">
                        <?php if ($thread["image"] !== ""): ?>
                            <img class="msg-thread-photo" src="<?= csEscape($thread["image"]) ?>" alt="">
                        <?php else: ?>
                            <span class="msg-thread-icon is-<?= $thread["type"] ?>"><i class="bi <?= $thread["type"] === "report" ? "bi-clipboard-check" : "bi-megaphone" ?>" aria-hidden="true"></i></span>
                        <?php endif; ?>
                        <div>
                            <h2><?= csEscape($thread["title"]) ?></h2>
                            <p><?= csEscape($thread["subtitle"]) ?></p>
                        </div>
                        <?php if ($thread["status"] !== ""): ?>
                            <?= csStatusBadge($thread["status"]) ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($thread["description"] !== ""): ?>
                        <details class="msg-context">
                            <summary><?= $thread["type"] === "report" ? "What the resident reported" : "Announcement text" ?></summary>
                            <p><?= nl2br(csEscape($thread["description"])) ?></p>
                        </details>
                    <?php endif; ?>
                    <div class="msg-thread-links">
                        <?php if ($thread["type"] === "report"): ?>
                            <a href="<?= csEscape(csUrl("reports.php", ["tab" => "all", "search" => $thread["id"]])) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open this report</a>
                        <?php else: ?>
                            <a href="<?= csEscape(csUrl("announcements.php", ["search" => $thread["title"]])) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open this announcement</a>
                        <?php endif; ?>
                    </div>
                </header>

                <div class="msg-messages" id="msgMessages" tabindex="0" aria-label="Messages">
                    <?php if (!$thread["messages"]): ?>
                        <div class="msg-no-messages">
                            <i class="bi bi-chat-text" aria-hidden="true"></i>
                            <p><?= $thread["type"] === "report"
                                ? "No messages yet. You can send the resident a message about this report below."
                                : "No comments yet. Replies you write here will be shown under the announcement." ?></p>
                        </div>
                    <?php endif; ?>

                    <?php $lastDay = ""; ?>
                    <?php foreach ($thread["messages"] as $message): ?>
                        <?php $day = csDate($message["at"], "l, F j, Y"); ?>
                        <?php if ($day !== $lastDay): $lastDay = $day; ?>
                            <p class="msg-day"><span><?= csEscape($day) ?></span></p>
                        <?php endif; ?>
                        <div class="msg-bubble-row<?= $message["fromBarangay"] ? " is-mine" : "" ?><?= $message["hidden"] ? " is-hidden" : "" ?>">
                            <?php if (!$message["fromBarangay"]): ?>
                                <span class="cs-avatar msg-avatar" aria-hidden="true">
                                    <?php if ($message["avatar"] !== ""): ?>
                                        <img src="<?= csEscape($message["avatar"]) ?>" alt="">
                                    <?php else: ?>
                                        <?= csEscape(strtoupper(mb_substr($message["name"], 0, 1))) ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <div class="msg-bubble">
                                <span class="msg-sender">
                                    <?= csEscape($message["fromBarangay"] ? "Barangay (" . $message["name"] . ")" : $message["name"]) ?>
                                    <time><?= csEscape(csDate($message["at"], "g:i A")) ?></time>
                                </span>
                                <p><?= nl2br(csEscape($message["message"])) ?></p>
                                <?php if ($message["hidden"]): ?>
                                    <span class="msg-hidden-note"><i class="bi bi-eye-slash" aria-hidden="true"></i> Hidden from residents</span>
                                <?php endif; ?>
                                <?php if ($thread["type"] === "announcement" && !$message["fromBarangay"]): ?>
                                    <button type="button" class="msg-hide-btn" data-hide-comment="<?= $message["id"] ?>" data-hidden="<?= $message["hidden"] ? "0" : "1" ?>">
                                        <?= $message["hidden"] ? "Show again" : "Hide comment" ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form class="msg-reply" id="msgReplyForm" data-type="<?= csEscape($thread["type"]) ?>" data-id="<?= csEscape($thread["id"]) ?>">
                    <label class="cs-label" for="msgReply">
                        <?= $thread["type"] === "report" ? "Reply to the resident" : "Reply under this announcement" ?>
                    </label>
                    <div class="msg-reply-row">
                        <textarea id="msgReply" class="cs-textarea" rows="2" maxlength="2000"
                                  placeholder="Type your message… (Ctrl + Enter to send)" required></textarea>
                        <button type="submit" class="cs-btn cs-btn-primary" id="msgSend">
                            <i class="bi bi-send" aria-hidden="true"></i> Send
                        </button>
                    </div>
                    <span class="cs-hint">
                        <?= $thread["type"] === "report"
                            ? "The resident will see your reply in the CitiSense app and get a notification."
                            : "Your reply is shown to everyone who views this announcement in the app." ?>
                    </span>
                </form>
            <?php else: ?>
                <div class="msg-placeholder">
                    <img src="../css/images/mascot-bell.png" alt="" class="msg-placeholder-mascot">
                    <h2>Choose a conversation</h2>
                    <p>Select a conversation on the left to read the messages and reply.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<?php include __DIR__ . "/partial/scripts.php"; ?>
<script src="../js/messages.js?v=<?= $csAssetVersion ?>"></script>

</body>
</html>
