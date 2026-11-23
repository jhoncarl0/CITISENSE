<?php
session_start();

if (empty($_SESSION["admin_id"]) || empty($_SESSION["barangay_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/partial/helpers.php";
require_once __DIR__ . "/partial/schema.php";
require_once __DIR__ . "/partial/resident_messages.php";

$barangayID = (string) $_SESSION["barangay_id"];
$schemaReady = csEnsureSchema($conn);

/* Announcement comments live on the Announcements page; keep old links working. */
if (($_GET["type"] ?? "") === "announcement" || ($_GET["filter"] ?? "") === "announcement") {
    $announceID = (int) ($_GET["id"] ?? 0);
    header("Location: announcements.php" . ($announceID > 0 ? "#comments-" . $announceID : ""));
    exit;
}

/* Old per-report links (e.g. "Open Messages" on a report) open that resident's conversation. */
if (($_GET["type"] ?? "") === "report" && trim((string) ($_GET["id"] ?? "")) !== "") {
    $reportID = mb_substr(trim((string) $_GET["id"]), 0, 50);
    $owner = csReportOwner($conn, $barangayID, $reportID);
    header("Location: " . ($owner !== "" ? csUrl("messages.php", ["user" => $owner, "report" => $reportID]) : "messages.php"));
    exit;
}

/* One conversation per resident. */
$search = mb_substr(trim((string) ($_GET["search"] ?? "")), 0, 80);
$userID = mb_substr(trim((string) ($_GET["user"] ?? "")), 0, 50);
$preselectReport = mb_substr(trim((string) ($_GET["report"] ?? "")), 0, 50);

$threads = $schemaReady ? csResidentThreads($conn, $barangayID, $search) : [];
$others = $schemaReady ? csFindResidentsToMessage($conn, $barangayID, $search, array_column($threads, "userID")) : [];
$conversation = ($schemaReady && $userID !== "") ? csLoadResidentConversation($conn, $barangayID, $userID) : null;

if ($conversation) {
    csMarkResidentRead($conn, $barangayID, $conversation["userID"]);
    foreach ($threads as &$item) {
        if ($item["userID"] === $conversation["userID"]) {
            $item["unread"] = 0;
        }
    }
    unset($item);

    /* Which report a new message is about: the one asked for, else general. */
    /* Default: the report of the latest message, else the newest report, so the
       message reaches the resident's chat in the CitiSense app. "general" = the
       admin chose a general message. */
    $reportIDs = array_column($conversation["reports"], "id");
    if ($preselectReport === "general") {
        $preselectReport = "";
    } elseif (!in_array($preselectReport, $reportIDs, true)) {
        $preselectReport = in_array($conversation["lastReport"], $reportIDs, true)
            ? $conversation["lastReport"]
            : (string) ($reportIDs[0] ?? "");
    }
}

$unreadTotal = count(array_filter($threads, static fn($t) => $t["unread"] > 0));
$listQuery = ["search" => $search];

/* Small round avatar: photo or first letter. */
function msgAvatar(string $name, string $avatar, string $class = ""): string
{
    $inner = $avatar !== ""
        ? '<img src="' . csEscape($avatar) . '" alt="">'
        : csEscape(strtoupper(mb_substr($name, 0, 1)));
    return '<span class="cs-avatar ' . $class . '" aria-hidden="true">' . $inner . '</span>';
}

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
            <p>Talk with residents. Each resident has one conversation that includes the messages about all their reports.</p>
        </div>
        <?php if ($unreadTotal > 0): ?>
            <span class="msg-unread-summary"><i class="bi bi-envelope-exclamation" aria-hidden="true"></i> <?= $unreadTotal ?> <?= $unreadTotal === 1 ? "resident is" : "residents are" ?> waiting for a reply</span>
        <?php endif; ?>
    </header>

    <?php if (!$schemaReady): ?>
        <?= csSchemaNotice() ?>
    <?php endif; ?>

    <section class="cs-card msg-layout<?= $conversation ? " has-thread" : "" ?>">

        <!-- CONVERSATION LIST -->
        <aside class="msg-sidebar" aria-label="Conversations">
            <form class="msg-search" method="GET" action="messages.php" role="search">
                <label class="cs-sr-only" for="msgSearch">Search residents</label>
                <div class="cs-input-icon">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="msgSearch" name="search" class="cs-input" placeholder="Search a resident by name, email or report ID" value="<?= csEscape($search) ?>">
                </div>
                <span class="cs-hint msg-search-hint">To message someone new, search their name.</span>
            </form>

            <?php if ($threads || $others): ?>
                <ul class="msg-thread-list">
                    <?php foreach ($threads as $item): ?>
                        <?php $active = $conversation && $item["userID"] === $conversation["userID"]; ?>
                        <li>
                            <a class="msg-thread<?= $active ? " is-active" : "" ?><?= $item["unread"] > 0 ? " is-unread" : "" ?>"
                               href="<?= csEscape(csUrl("messages.php", array_merge($listQuery, ["user" => $item["userID"]]))) ?>"
                               <?= $active ? 'aria-current="true"' : "" ?>>
                                <?= msgAvatar($item["name"], $item["avatar"], "msg-thread-avatar") ?>
                                <span class="msg-thread-text">
                                    <span class="msg-thread-top">
                                        <strong><?= csEscape($item["name"]) ?></strong>
                                        <time><?= csEscape(csTimeAgo($item["lastAt"])) ?></time>
                                    </span>
                                    <span class="msg-thread-last">
                                        <?= $item["lastSender"] === "barangay" ? "You: " : "" ?><?= csEscape(mb_strimwidth($item["lastMessage"], 0, 90, "…")) ?>
                                    </span>
                                    <?php if ($item["lastReport"] !== ""): ?>
                                        <span class="msg-thread-sub"><i class="bi bi-clipboard-check" aria-hidden="true"></i> About report <?= csEscape($item["lastReport"]) ?></span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($item["unread"] > 0): ?>
                                    <span class="msg-unread-count" aria-label="<?= $item["unread"] ?> new"><?= $item["unread"] ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>

                    <?php if ($others): ?>
                        <li class="msg-list-heading">Start a new conversation</li>
                        <?php foreach ($others as $person): ?>
                            <?php $active = $conversation && $person["userID"] === $conversation["userID"]; ?>
                            <li>
                                <a class="msg-thread is-new-contact<?= $active ? " is-active" : "" ?>"
                                   href="<?= csEscape(csUrl("messages.php", array_merge($listQuery, ["user" => $person["userID"]]))) ?>">
                                    <?= msgAvatar($person["name"], $person["avatar"], "msg-thread-avatar") ?>
                                    <span class="msg-thread-text">
                                        <span class="msg-thread-top"><strong><?= csEscape($person["name"]) ?></strong></span>
                                        <span class="msg-thread-sub"><?= csEscape($person["email"] !== "" ? $person["email"] : "No messages yet") ?></span>
                                    </span>
                                    <i class="bi bi-pencil-square msg-new-icon" aria-hidden="true"></i>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            <?php else: ?>
                <div class="cs-empty msg-empty-list">
                    <span class="cs-empty-icon"><i class="bi bi-chat-dots" aria-hidden="true"></i></span>
                    <h3><?= $search !== "" ? "No residents found" : "No messages yet" ?></h3>
                    <p><?= $search !== ""
                        ? "No resident matches “" . csEscape($search) . "”. Try another name, email or report ID."
                        : "When residents send a message in the CitiSense app, it will appear here. To message a resident, search their name above or use the Message button on the Residents page." ?></p>
                </div>
            <?php endif; ?>
        </aside>

        <!-- CONVERSATION -->
        <div class="msg-main">
            <?php if ($conversation): ?>
                <header class="msg-thread-header">
                    <a class="cs-btn cs-btn-ghost cs-btn-sm msg-back" href="<?= csEscape(csUrl("messages.php", $listQuery)) ?>">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> All conversations
                    </a>
                    <div class="msg-thread-heading">
                        <?= msgAvatar($conversation["name"], $conversation["avatar"], "msg-profile-avatar") ?>
                        <div>
                            <h2><?= csEscape($conversation["name"]) ?></h2>
                            <p>
                                Resident
                                · <?= count($conversation["reports"]) ?> <?= count($conversation["reports"]) === 1 ? "report" : "reports" ?>
                                <?php if ($conversation["dateJoined"] !== ""): ?>
                                    · Joined <?= csEscape(csDate($conversation["dateJoined"], "F Y")) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <button type="button" class="cs-btn cs-btn-ghost cs-btn-sm" aria-expanded="false" aria-controls="msgProfile" data-profile-toggle>
                            <i class="bi bi-person-vcard" aria-hidden="true"></i> Profile
                        </button>
                    </div>

                </header>

                <div class="msg-body">
                    <!-- Resident profile -->
                    <aside class="msg-profile" id="msgProfile" aria-label="Profile of <?= csEscape($conversation["name"]) ?>" hidden>
                        <div class="msg-profile-head">
                            <h3>Profile</h3>
                            <button type="button" class="cs-btn cs-btn-ghost cs-btn-sm" data-profile-close aria-label="Close profile">
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                            </button>
                        </div>
                        <dl class="msg-profile-rows">
                            <div>
                                <dt><i class="bi bi-envelope" aria-hidden="true"></i> Email</dt>
                                <dd><?= $conversation["email"] !== ""
                                    ? '<a href="mailto:' . csEscape($conversation["email"]) . '">' . csEscape($conversation["email"]) . '</a>'
                                    : '<span class="cs-subtle">Not provided</span>' ?></dd>
                            </div>
                            <div>
                                <dt><i class="bi bi-telephone" aria-hidden="true"></i> Contact number</dt>
                                <dd><?= $conversation["contactNo"] !== ""
                                    ? '<a href="tel:' . csEscape(preg_replace("/[^0-9+]/", "", $conversation["contactNo"])) . '">' . csEscape($conversation["contactNo"]) . '</a>'
                                    : '<span class="cs-subtle">Not provided</span>' ?></dd>
                            </div>
                            <div>
                                <dt><i class="bi bi-calendar3" aria-hidden="true"></i> Member since</dt>
                                <dd><?= csEscape(csDate($conversation["dateJoined"], "F j, Y", "Unknown")) ?></dd>
                            </div>
                        </dl>

                        <h3 class="msg-profile-title">Reports (<?= count($conversation["reports"]) ?>)</h3>
                        <?php if ($conversation["reports"]): ?>
                            <ul class="msg-profile-reports">
                                <?php foreach ($conversation["reports"] as $report): ?>
                                    <li>
                                        <a href="<?= csEscape(csUrl("reports.php", ["tab" => "all", "search" => $report["id"], "open" => $report["id"]])) ?>">
                                            <span class="msg-report-icon"><i class="bi <?= csEscape(csCategoryIcon($report["category"])) ?>" aria-hidden="true"></i></span>
                                            <span class="msg-report-text">
                                                <strong><?= csEscape($report["id"]) ?> · <?= csEscape($report["category"]) ?></strong>
                                                <span><?= csEscape(csDate($report["date"], "M j, Y")) ?><?= $report["address"] !== "" ? " · " . csEscape($report["address"]) : "" ?></span>
                                            </span>
                                            <?= csStatusBadge($report["status"]) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="cs-subtle">This resident has not sent any reports yet.</p>
                        <?php endif; ?>
                    </aside>
                <div class="msg-messages" id="msgMessages" tabindex="0" aria-label="Messages">
                    <?php if (!$conversation["messages"]): ?>
                        <div class="msg-no-messages">
                            <i class="bi bi-chat-text" aria-hidden="true"></i>
                            <p>No messages with <?= csEscape($conversation["name"]) ?> yet. Write the first message below.</p>
                        </div>
                    <?php endif; ?>

                    <?php $lastDay = ""; ?>
                    <?php foreach ($conversation["messages"] as $message): ?>
                        <?php $day = csDate($message["at"], "l, F j, Y"); ?>
                        <?php if ($day !== $lastDay): $lastDay = $day; ?>
                            <p class="msg-day"><span><?= csEscape($day) ?></span></p>
                        <?php endif; ?>
                        <?php $senderLabel = $message["fromBarangay"] ? "Barangay (" . $message["name"] . ")" : $message["name"]; ?>
                        <div class="msg-bubble-row<?= $message["fromBarangay"] ? " is-mine" : "" ?><?= $message["isNew"] ? " is-new" : "" ?>"
                             id="msg-<?= csEscape($message["key"]) ?>"
                             data-key="<?= csEscape($message["key"]) ?>"
                             data-report="<?= csEscape($message["reportID"]) ?>"
                             data-name="<?= csEscape($senderLabel) ?>"
                             data-preview="<?= csEscape(csReplyPreview($message["message"])) ?>">
                            <?php if (!$message["fromBarangay"]): ?>
                                <?= msgAvatar($message["name"], $message["avatar"], "msg-avatar") ?>
                            <?php endif; ?>
                            <div class="msg-bubble-wrap">
                            <?php if ($message["replyTo"]): ?>
                                <?php if ($message["replyTo"]["missing"]): ?>
                                    <span class="msg-quote is-missing"><i class="bi bi-reply" aria-hidden="true"></i> Original message was deleted</span>
                                <?php else: ?>
                                    <a class="msg-quote" href="#msg-<?= csEscape($message["replyTo"]["key"]) ?>" data-jump="<?= csEscape($message["replyTo"]["key"]) ?>">
                                        <span class="msg-quote-who"><i class="bi bi-reply" aria-hidden="true"></i> Replying to <?= csEscape($message["replyTo"]["name"]) ?></span>
                                        <span class="msg-quote-text"><?= csEscape($message["replyTo"]["text"]) ?></span>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="msg-bubble">
                                <span class="msg-sender">
                                    <?= csEscape($message["fromBarangay"] ? "Barangay (" . $message["name"] . ")" : $message["name"]) ?>
                                    <time><?= csEscape(csDate($message["at"], "g:i A")) ?></time>
                                </span>
                                <?php if ($message["reportLabel"] !== ""): ?>
                                    <a class="msg-report-tag" href="<?= csEscape(csUrl("reports.php", ["tab" => "all", "search" => $message["reportID"], "open" => $message["reportID"]])) ?>">
                                        <i class="bi bi-clipboard-check" aria-hidden="true"></i> <?= csEscape($message["reportLabel"]) ?>
                                    </a>
                                <?php endif; ?>
                                <p><?= nl2br(csEscape($message["message"])) ?></p>
                            </div>
                            </div>
                            <div class="msg-actions">
                                <button type="button" class="msg-action" data-msg-reply title="Reply" aria-label="Reply to this message">
                                    <i class="bi bi-reply-fill" aria-hidden="true"></i><span>Reply</span>
                                </button>
                                <button type="button" class="msg-action is-danger" data-msg-delete title="Delete" aria-label="Delete this message">
                                    <i class="bi bi-trash" aria-hidden="true"></i><span>Delete</span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                </div>

                <form class="msg-reply" id="msgReplyForm" data-user="<?= csEscape($conversation["userID"]) ?>">
                    <div class="msg-reply-top">
                        <label class="cs-label" for="msgReply">Message <?= csEscape($conversation["name"]) ?></label>
                        <label class="msg-about">
                            <span>About</span>
                            <select id="msgAbout" class="cs-select">
                                <option value=""<?= $preselectReport === "" ? " selected" : "" ?>>General message (not shown in the app's chat yet)</option>
                                <?php foreach ($conversation["reports"] as $report): ?>
                                    <option value="<?= csEscape($report["id"]) ?>"<?= $preselectReport === $report["id"] ? " selected" : "" ?>>
                                        Report <?= csEscape($report["id"]) ?> · <?= csEscape($report["category"]) ?> (<?= csEscape($report["status"]) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="msg-replying" id="msgReplying" hidden>
                        <i class="bi bi-reply-fill" aria-hidden="true"></i>
                        <div>
                            <strong>Replying to <span data-replying-name></span></strong>
                            <span data-replying-text></span>
                        </div>
                        <button type="button" class="cs-btn cs-btn-ghost cs-btn-sm" data-replying-cancel aria-label="Cancel reply">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="msg-reply-row">
                        <textarea id="msgReply" class="cs-textarea" rows="2" maxlength="2000"
                                  placeholder="Type your message… (Ctrl + Enter to send)" required></textarea>
                        <button type="submit" class="cs-btn cs-btn-primary" id="msgSend">
                            <i class="bi bi-send" aria-hidden="true"></i> Send
                        </button>
                    </div>
                    <span class="cs-hint" id="msgAboutHint"
                          data-general="The resident only gets this as a notification: the CitiSense app does not show general messages in a chat yet, so they cannot reply to it. Choose a report to start a conversation they can answer."
                          data-report="The resident gets a notification and sees this message under that report in the CitiSense app."></span>
                </form>
            <?php else: ?>
                <div class="msg-placeholder">
                    <img src="../css/images/mascot-bell.png" alt="" class="msg-placeholder-mascot">
                    <h2>Choose a resident</h2>
                    <p>Select a conversation on the left to see the resident's messages and profile, and to reply.</p>
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
