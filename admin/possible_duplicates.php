<?php
session_start();

if (empty($_SESSION["admin_id"]) || empty($_SESSION["barangay_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/partial/helpers.php";
require_once __DIR__ . "/partial/schema.php";
require_once __DIR__ . "/partial/duplicates.php";

$barangayID = (string) $_SESSION["barangay_id"];
$schemaReady = csEnsureSchema($conn);

$groups = csGroupDuplicatePairs(csFindDuplicatePairs($conn, $barangayID, true));
$totalReports = array_sum(array_map(static fn($g) => count($g["ids"]), $groups));

/* Data the page script needs (IDs, statuses) — never trusted by the server. */
$groupPayload = [];
foreach ($groups as $group) {
    $groupPayload[$group["id"]] = [
        "main" => $group["main"]["reportID"],
        "ids" => $group["ids"],
        "status" => array_column(array_merge([$group["main"]], $group["members"]), "statusID", "reportID"),
    ];
}

$pageTitle = "Possible Duplicates";
$pageStyles = ["duplicates.css"];
include __DIR__ . "/partial/head.php";

/** One report row inside a group. */
function dupReportRow(array $report, bool $isMain, string $groupID, string $mainID): void
{
    $image = csMediaUrl($report["imagePath"] ?? "");
    $match = $report["match"] ?? null;
    $isOpenForAction = in_array($report["statusID"], ["STAT001", "STAT002", "STAT004"], true);
    ?>
    <li class="dup-report<?= $isMain ? " is-main" : "" ?>" data-report="<?= csEscape($report["reportID"]) ?>">
        <a class="dup-photo" href="<?= $image !== "" ? csEscape($image) : "#" ?>" <?= $image !== "" ? 'target="_blank" rel="noopener" title="Open the full-size photo"' : 'aria-hidden="true" tabindex="-1"' ?>>
            <?php if ($image !== ""): ?>
                <img src="<?= csEscape($image) ?>" alt="Photo of report <?= csEscape($report["reportID"]) ?>" loading="lazy">
            <?php else: ?>
                <i class="bi <?= csCategoryIcon($report["categoryName"]) ?>" aria-hidden="true"></i>
            <?php endif; ?>
        </a>

        <div class="dup-info">
            <div class="dup-title-row">
                <?php if ($isMain): ?>
                    <span class="dup-main-tag"><i class="bi bi-star-fill" aria-hidden="true"></i> Suggested main report</span>
                <?php endif; ?>
                <h3><?= csEscape($report["categoryName"]) ?> <span class="cs-subtle">· ID <?= csEscape($report["reportID"]) ?></span></h3>
                <?= csStatusBadge($report["statusName"]) ?>
            </div>
            <p class="dup-line"><i class="bi bi-geo-alt" aria-hidden="true"></i><?= csEscape(($report["address"] ?? "") !== "" ? $report["address"] : "Location not provided") ?></p>
            <p class="dup-line dup-meta">
                <?= csEscape(csDate($report["dateReported"], "M j, Y · g:i A")) ?>
                <?php if ($report["resident"] !== ""): ?> · by <?= csEscape($report["resident"]) ?><?php endif; ?>
            </p>
            <?php if (trim((string) $report["description"]) !== ""): ?>
                <p class="dup-description">“<?= csEscape(mb_strimwidth(trim((string) $report["description"]), 0, 180, "…")) ?>”</p>
            <?php endif; ?>

            <?php if ($match): ?>
                <div class="dup-reasons" aria-label="Why this looks like a duplicate">
                    <span class="dup-confidence is-<?= csEscape(strtolower(str_replace(" ", "-", $match["confidence"]))) ?>">
                        <?= csEscape($match["confidence"]) ?> duplicate of <?= csEscape($match["other"]) ?>
                    </span>
                    <?php foreach ($match["reasons"] as $reason): ?>
                        <span class="dup-reason"><i class="bi bi-check2" aria-hidden="true"></i><?= csEscape($reason) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="dup-actions">
            <?php if ($isOpenForAction): ?>
                <button type="button" class="cs-btn cs-btn-danger-outline cs-btn-sm" data-mark-duplicate="<?= csEscape($report["reportID"]) ?>" data-group="<?= csEscape($groupID) ?>">
                    <i class="bi bi-files" aria-hidden="true"></i> Mark as Duplicate
                </button>
            <?php endif; ?>
            <?php if (!$isMain): ?>
                <button type="button" class="cs-btn cs-btn-ghost cs-btn-sm" data-not-duplicate="<?= csEscape($report["reportID"]) ?>" data-group="<?= csEscape($groupID) ?>">
                    <i class="bi bi-x-circle" aria-hidden="true"></i> Not a Duplicate
                </button>
            <?php endif; ?>
            <a class="cs-btn cs-btn-ghost cs-btn-sm" href="<?= csEscape(csUrl("reports.php", ["tab" => "all", "search" => $report["reportID"]])) ?>">
                View Report
            </a>
        </div>
    </li>
    <?php
}
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">

    <header class="cs-page-header">
        <div>
            <h1>Possible Duplicates</h1>
            <p>Reports that may be about the same problem. Review each group and decide. Nothing is rejected automatically.</p>
        </div>
        <?php if ($groups): ?>
            <div class="dup-summary">
                <strong><?= count($groups) ?></strong>
                <?= count($groups) === 1 ? "group" : "groups" ?> to review · <?= $totalReports ?> reports
            </div>
        <?php endif; ?>
    </header>

    <?php if (!$schemaReady): ?>
        <?= csSchemaNotice() ?>
    <?php endif; ?>

    <details class="cs-card dup-help">
        <summary>
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            How does CitiSense find possible duplicates?
        </summary>
        <div class="dup-help-body">
            <p>It compares reports that are still open (Pending, In Progress or Overdue) and looks at:</p>
            <ul>
                <li><strong>Location</strong> — map pins within about 60 metres of each other.</li>
                <li><strong>Type of issue</strong> — for example, both are Potholes.</li>
                <li><strong>Description</strong> — the same important words.</li>
                <li><strong>Photo</strong> — pictures that look almost the same.</li>
            </ul>
            <p><strong>Mark as Duplicate</strong> closes that report as Rejected with a friendly message telling the resident the problem was already reported.
               <strong>Not a Duplicate</strong> keeps the report and stops suggesting it in this group.</p>
        </div>
    </details>

    <?php if ($groups): ?>
        <section class="dup-groups" aria-label="Groups of possible duplicates">
            <?php foreach ($groups as $number => $group): ?>
                <article class="cs-card dup-group" id="group-<?= csEscape($group["id"]) ?>">
                    <header class="dup-group-header">
                        <div>
                            <h2>Possibly the same problem <span class="cs-subtle">· <?= count($group["ids"]) ?> reports</span></h2>
                            <p>Keep the main report and close the others as duplicates, or mark any report that is different as “Not a Duplicate”.</p>
                        </div>
                    </header>
                    <ul class="dup-list">
                        <?php dupReportRow($group["main"], true, $group["id"], $group["main"]["reportID"]); ?>
                        <?php foreach ($group["members"] as $member): ?>
                            <?php dupReportRow($member, false, $group["id"], $group["main"]["reportID"]); ?>
                        <?php endforeach; ?>
                    </ul>
                </article>
            <?php endforeach; ?>
        </section>
    <?php else: ?>
        <section class="cs-card cs-empty">
            <span class="cs-empty-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
            <h3>No possible duplicates right now</h3>
            <p>When two open reports look like the same problem, they will appear here for you to review.</p>
            <a href="reports.php" class="cs-btn cs-btn-secondary">Go to Reports</a>
        </section>
    <?php endif; ?>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<!-- MARK AS DUPLICATE -->
<div class="cs-modal" id="duplicateModal" role="dialog" aria-modal="true" aria-labelledby="duplicateTitle">
    <div class="cs-modal-dialog">
        <form id="duplicateForm">
            <div class="cs-modal-body">
                <span class="cs-confirm-icon is-danger"><i class="bi bi-files" aria-hidden="true"></i></span>
                <h2 id="duplicateTitle">Mark as duplicate?</h2>
                <p class="cs-muted" id="duplicateText"></p>

                <div class="cs-field">
                    <label class="cs-label" for="duplicateOf">Same problem as</label>
                    <select id="duplicateOf" class="cs-select"></select>
                </div>

                <div class="cs-field">
                    <label class="cs-label" for="duplicateReason">Message sent to the resident</label>
                    <textarea id="duplicateReason" class="cs-textarea" rows="3" maxlength="1000" required></textarea>
                    <span class="cs-hint">The report will be closed as Rejected with this message.</span>
                </div>
            </div>
            <div class="cs-modal-footer">
                <button type="button" class="cs-btn cs-btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="cs-btn cs-btn-danger" id="duplicateSubmit">
                    <i class="bi bi-files" aria-hidden="true"></i> Mark as Duplicate
                </button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="duplicateGroups"><?= json_encode($groupPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: "{}" ?></script>

<?php include __DIR__ . "/partial/scripts.php"; ?>
<script src="../js/duplicates.js?v=<?= $csAssetVersion ?>"></script>

</body>
</html>
