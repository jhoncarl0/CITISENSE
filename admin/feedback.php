<?php
/*
 * CitiSense admin — Resident Feedback.
 * What residents answered in the app after their report was resolved:
 * is it fixed, a 1–5 rating and an optional comment (see partial/feedback.php).
 */
session_start();

if (empty($_SESSION["admin_id"]) || empty($_SESSION["barangay_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/partial/helpers.php";
require_once __DIR__ . "/partial/schema.php";
require_once __DIR__ . "/partial/feedback.php";

$barangayID = (string) $_SESSION["barangay_id"];
csEnsureSchema($conn);

$filter = in_array($_GET["filter"] ?? "", ["not_fixed", "fixed"], true) ? $_GET["filter"] : "all";

$all = csFeedbackList($conn, $barangayID);
$shown = $filter === "all" ? $all : array_values(array_filter(
    $all,
    static fn($f) => $filter === "fixed" ? $f["fixed"] : !$f["fixed"]
));

$seenAt = csFeedbackSeenAt($conn, $barangayID);
$notFixed = count(array_filter($all, static fn($f) => !$f["fixed"]));
$average = $all ? array_sum(array_column($all, "rating")) / count($all) : 0;

// Opening this page counts as seeing all feedback (clears the menu badge).
csMarkFeedbackSeen($conn, $barangayID, $all);

$pageTitle = "Resident Feedback";
$pageStyles = ["feedback.css"];
include __DIR__ . "/partial/head.php";
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">

    <header class="cs-page-header">
        <div>
            <h1>Resident Feedback</h1>
            <p>What residents said in the CitiSense app after their report was resolved: is the problem really fixed, and how satisfied they are.</p>
        </div>
    </header>

    <section class="cs-stat-grid fb-stats" aria-label="Summary">
        <?php foreach ([
            [number_format(count($all)), "Feedback received", "bi-chat-square-heart", "primary"],
            [$all ? number_format($average, 1) . " / 5" : "–", "Average rating", "bi-star-fill", "pending"],
            [number_format($notFixed), $notFixed === 1 ? "Says it is not fixed" : "Say it is not fixed", "bi-exclamation-triangle-fill", "overdue"],
        ] as [$value, $label, $icon, $tone]): ?>
            <div class="cs-stat">
                <span class="cs-icon-circle cs-tone-<?= $tone ?>"><i class="bi <?= $icon ?>" aria-hidden="true"></i></span>
                <span>
                    <span class="cs-stat-value"><?= csEscape($value) ?></span>
                    <span class="cs-stat-label"><?= csEscape($label) ?></span>
                </span>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="cs-card fb-card">
        <nav class="fb-filters" aria-label="Show feedback">
            <?php foreach (["all" => "All", "not_fixed" => "Not fixed", "fixed" => "Fixed"] as $key => $label): ?>
                <a href="<?= csEscape(csUrl("feedback.php", ["filter" => $key !== "all" ? $key : ""])) ?>"
                   class="fb-filter<?= $filter === $key ? " is-active" : "" ?>"<?= $filter === $key ? ' aria-current="page"' : "" ?>><?= $label ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if ($shown): ?>
            <ul class="fb-list">
                <?php foreach ($shown as $item): ?>
                    <?php $isNew = $seenAt === "" || $item["at"] > $seenAt; ?>
                    <li class="fb-item<?= $item["fixed"] ? "" : " is-not-fixed" ?>">
                        <div class="fb-head">
                            <h2><?= csEscape($item["title"] !== "" ? $item["title"] : $item["category"]) ?></h2>
                            <?php if ($isNew): ?><span class="fb-new"><?= $item["changed"] ? "Updated" : "New" ?></span><?php endif; ?>
                            <span class="fb-fixed <?= $item["fixed"] ? "is-yes" : "is-no" ?>">
                                <i class="bi <?= $item["fixed"] ? "bi-check-circle-fill" : "bi-x-circle-fill" ?>" aria-hidden="true"></i>
                                <?= $item["fixed"] ? "Fixed" : "Not fixed" ?>
                            </span>
                        </div>

                        <p class="fb-stars" aria-label="Rating <?= $item["rating"] ?> out of 5">
                            <span aria-hidden="true"><?= csStars($item["rating"]) ?></span>
                            <span class="fb-rating"><?= $item["rating"] ?>/5</span>
                        </p>

                        <?php if ($item["comment"] !== ""): ?>
                            <blockquote class="fb-comment">“<?= csEscape($item["comment"]) ?>”</blockquote>
                        <?php endif; ?>

                        <dl class="cs-facts">
                            <div><dt>Report ID</dt><dd><?= csEscape($item["reportID"]) ?> · <?= csEscape($item["category"]) ?></dd></div>
                            <div><dt>Resident</dt><dd><?= csEscape($item["resident"] !== "" ? $item["resident"] : "Resident") ?></dd></div>
                            <div><dt><?= $item["changed"] ? "Updated" : "Sent" ?></dt><dd><?= csEscape(csDate($item["at"], "M j, Y · g:i A")) ?></dd></div>
                            <div><dt>Report status</dt><dd><?= csStatusBadge($item["status"]) ?></dd></div>
                        </dl>

                        <div class="fb-actions">
                            <a class="cs-btn cs-btn-secondary cs-btn-sm" href="<?= csEscape(csUrl("reports.php", ["tab" => "all", "search" => $item["reportID"], "open" => $item["reportID"]])) ?>">
                                <i class="bi bi-clipboard-check" aria-hidden="true"></i> View Report
                            </a>
                            <?php if ($item["userID"] !== ""): ?>
                                <a class="cs-btn cs-btn-ghost cs-btn-sm" href="<?= csEscape(csUrl("messages.php", ["user" => $item["userID"], "report" => $item["reportID"]])) ?>">
                                    <i class="bi bi-chat-dots" aria-hidden="true"></i> Message Resident
                                </a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="cs-empty">
                <span class="cs-empty-icon"><i class="bi bi-chat-square-heart" aria-hidden="true"></i></span>
                <h3><?= $all ? "Nothing here" : "No feedback yet" ?></h3>
                <p><?= $all
                    ? "No feedback matches this filter."
                    : "When a report is resolved, its resident can tell you in the CitiSense app whether the problem is really fixed. Their answers will appear here." ?></p>
            </div>
        <?php endif; ?>
    </section>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<?php include __DIR__ . "/partial/scripts.php"; ?>
</body>
</html>
