<?php
session_start();

if (
    empty($_SESSION["admin_id"]) ||
    empty($_SESSION["barangay_id"])
) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/partial/helpers.php";
require_once __DIR__ . "/partial/report_cycles.php";
require_once __DIR__ . "/partial/schema.php";
require_once __DIR__ . "/partial/duplicates.php";
require_once __DIR__ . "/partial/duplicate_links.php";
require_once __DIR__ . "/partial/feedback.php";
require_once __DIR__ . "/partial/report_details.php";

$barangayID = (string) $_SESSION["barangay_id"];
$schemaReady = csEnsureSchema($conn);

function reportsPrepare(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Reports SQL prepare error: " . $conn->error);
        http_response_code(500);
        exit("Unable to load reports. Please check the server error log.");
    }

    return $stmt;
}

/* =========================================================
   STATUSES (looked up by name, so IDs are never guessed)
========================================================= */

$statusIDsByName = [];

$stmt = reportsPrepare($conn, "SELECT statusID, statusName FROM stats");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $statusIDsByName[strtolower(trim((string) $row["statusName"]))] = (string) $row["statusID"];
}

$stmt->close();

/* Tabs, in the order an administrator works through them. */
$tabs = [
    "pending" => ["label" => "New", "status" => "pending", "icon" => "bi-inbox"],
    "overdue" => ["label" => "Overdue", "status" => "overdue", "icon" => "bi-exclamation-triangle"],
    "in-progress" => ["label" => "In Progress", "status" => "in progress", "icon" => "bi-tools"],
    "resolved" => ["label" => "Resolved", "status" => "resolved", "icon" => "bi-check-circle"],
    "rejected" => ["label" => "Rejected", "status" => "rejected", "icon" => "bi-x-circle"],
    "all" => ["label" => "All", "status" => "", "icon" => "bi-list-ul"],
];

/* =========================================================
   INPUTS
========================================================= */

$tab = (string) ($_GET["tab"] ?? "");
$searchQuery = mb_substr(trim((string) ($_GET["search"] ?? "")), 0, 100);
$selectedCategory = trim((string) ($_GET["categoryID"] ?? ""));
$currentPage = max(1, (int) ($_GET["page"] ?? 1));
$startDate = trim((string) ($_GET["start_date"] ?? ""));
$endDate = trim((string) ($_GET["end_date"] ?? ""));
$sort = ($_GET["sort"] ?? "") === "oldest" ? "oldest" : "newest";

/* Keep only real YYYY-MM-DD dates; swap them if entered in reverse. */
$isValidDate = static fn(string $value): bool =>
    (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) &&
    checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));

if ($startDate !== "" && !$isValidDate($startDate)) {
    $startDate = "";
}

if ($endDate !== "" && !$isValidDate($endDate)) {
    $endDate = "";
}

if ($startDate !== "" && $endDate !== "" && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

/* Older links (e.g. from the Dashboard) use ?statusID=STAT00x. */
if ($tab === "" && !empty($_GET["statusID"])) {
    $legacyName = array_search((string) $_GET["statusID"], $statusIDsByName, true);
    foreach ($tabs as $key => $info) {
        if ($legacyName !== false && $info["status"] === $legacyName) {
            $tab = $key;
        }
    }
}

if (!isset($tabs[$tab])) {
    $tab = "pending";
}

/* A search looks through every status unless a tab was chosen. */
if (($searchQuery !== "" || $startDate !== "" || $endDate !== "") && !isset($_GET["tab"])) {
    $tab = "all";
}

/* =========================================================
   BARANGAY + CATEGORY OPTIONS
========================================================= */

$stmt = reportsPrepare($conn, "SELECT barangayName FROM barangay WHERE barangayID = ? LIMIT 1");
$stmt->bind_param("s", $barangayID);
$stmt->execute();
$barangayName = $stmt->get_result()->fetch_assoc()["barangayName"] ?? "Unknown Barangay";
$stmt->close();

$stmt = reportsPrepare(
    $conn,
    "SELECT categoryID, categoryName
     FROM categories
     WHERE categoryName <> 'Blocked Drainage'
     ORDER BY categoryName ASC"
);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($selectedCategory !== "" && !in_array($selectedCategory, array_column($categories, "categoryID"), true)) {
    $selectedCategory = "";
}

/* =========================================================
   FILTERS (search + type) — shared by the counts and list
========================================================= */

$conditions = ["r.barangayID = ?"];
$types = "s";
$params = [$barangayID];

if ($selectedCategory !== "") {
    $conditions[] = "r.categoryID = ?";
    $types .= "s";
    $params[] = $selectedCategory;
}

if ($startDate !== "") {
    // Days are Philippine calendar days; dates are stored in UTC.
    $conditions[] = "r.dateReported >= ?";
    $types .= "s";
    $params[] = csToDb($startDate . " 00:00:00");
}

if ($endDate !== "") {
    // Include the whole "To" day (Philippine time).
    $conditions[] = "r.dateReported < ?";
    $types .= "s";
    $params[] = csToDb(date("Y-m-d", strtotime($endDate . " +1 day")) . " 00:00:00");
}

/*
 * Confirmed duplicates (marked on Possible Duplicates) are not separate
 * entries: they are listed inside their main report's View. Searching for a
 * duplicate's ID therefore finds its main report.
 */
$linkMap = csDuplicateLinkMap($conn, $barangayID);

if ($searchQuery !== "") {
    $mainsOfMatches = [];
    foreach ($linkMap as $dupID => $mainID) {
        if (stripos((string) $dupID, $searchQuery) !== false) {
            $mainsOfMatches[(string) $mainID] = true;
        }
    }
    $mainsOfMatches = array_keys($mainsOfMatches);

    $conditions[] = "(
        EXISTS (SELECT 1 FROM categories sc WHERE sc.categoryID = r.categoryID AND sc.categoryName LIKE ?)
        OR EXISTS (SELECT 1 FROM location sl WHERE sl.locationID = r.locationID AND sl.address LIKE ?)
        OR EXISTS (SELECT 1 FROM users su WHERE su.userID = r.userID AND (su.firstName LIKE ? OR su.lastName LIKE ?))
        OR COALESCE(r.title, '') LIKE ?
        OR COALESCE(r.description, '') LIKE ?
        OR CAST(r.reportID AS CHAR) LIKE ?"
        . ($mainsOfMatches ? " OR r.reportID IN (" . implode(",", array_fill(0, count($mainsOfMatches), "?")) . ")" : "")
        . "
    )";

    $types .= "sssssss";
    for ($i = 0; $i < 7; $i++) {
        $params[] = "%" . $searchQuery . "%";
    }
    foreach ($mainsOfMatches as $mainID) {
        $types .= "s";
        $params[] = (string) $mainID;
    }
}

if ($linkMap) {
    $dupIDs = array_map("strval", array_keys($linkMap));
    $conditions[] = "r.reportID NOT IN (" . implode(",", array_fill(0, count($dupIDs), "?")) . ")";
    $types .= str_repeat("s", count($dupIDs));
    array_push($params, ...$dupIDs);
}

$whereSQL = implode(" AND ", $conditions);

/* =========================================================
   COUNT PER TAB
========================================================= */

$countsByStatusID = [];

$stmt = reportsPrepare(
    $conn,
    "SELECT r.statusID, COUNT(*) AS total FROM reports r WHERE $whereSQL GROUP BY r.statusID"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $countsByStatusID[(string) $row["statusID"]] = (int) $row["total"];
}

$stmt->close();

$tabCounts = [];

foreach ($tabs as $key => $info) {
    if ($info["status"] === "") {
        $tabCounts[$key] = array_sum($countsByStatusID);
    } else {
        $id = $statusIDsByName[$info["status"]] ?? null;
        $tabCounts[$key] = $id !== null ? ($countsByStatusID[$id] ?? 0) : 0;
    }
}

/* =========================================================
   REPORT LIST (10 per page, newest or oldest first)
========================================================= */

$listConditions = $conditions;
$listTypes = $types;
$listParams = $params;

if ($tabs[$tab]["status"] !== "") {
    $listConditions[] = "r.statusID = ?";
    $listTypes .= "s";
    $listParams[] = $statusIDsByName[$tabs[$tab]["status"]] ?? "__none__";
}

$listWhereSQL = implode(" AND ", $listConditions);
$totalReports = $tabCounts[$tab];

$perPage = 10;
$totalPages = max(1, (int) ceil($totalReports / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$reportSelectSQL = csReportSelectSQL();

$stmt = reportsPrepare(
    $conn,
    $reportSelectSQL . " WHERE $listWhereSQL ORDER BY " . ($sort === "oldest"
        ? "r.dateReported ASC, r.reportID ASC"
        : "r.dateReported DESC, r.reportID DESC") . " LIMIT ? OFFSET ?"
);
$listTypes .= "ii";
$listParams[] = $perPage;
$listParams[] = $offset;
$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$reportList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* =========================================================
   DETAILS FOR THIS PAGE (shared with report_details.php)
========================================================= */

$reportContext = csReportContext($conn, $barangayID, true);
$listRows = [];
foreach ($reportList as $report) {
    $listRows[(string) $report["reportID"]] = $report;
}
$payload = csReportDetailsPayload($conn, $barangayID, array_keys($listRows), $reportContext, $listRows);
$payloadJSON = csReportPayloadJSON($payload);

$baseQuery = [
    "tab" => $tab,
    "search" => $searchQuery,
    "categoryID" => $selectedCategory,
    "start_date" => $startDate,
    "end_date" => $endDate,
    "sort" => $sort === "oldest" ? "oldest" : "",
];
$hasFilters = $searchQuery !== "" || $selectedCategory !== "" || $startDate !== "" || $endDate !== "";

$emptyMessages = [
    "pending" => ["No new reports", "You are all caught up. New reports from residents will appear here."],
    "overdue" => ["No overdue reports", "Every accepted report is still on schedule."],
    "in-progress" => ["Nothing in progress", "Reports you accept will appear here until they are resolved."],
    "resolved" => ["No resolved reports yet", "Reports you mark as resolved will appear here."],
    "rejected" => ["No rejected reports", "Reports you reject will appear here."],
    "all" => ["No reports yet", "When residents send a report from the CitiSense app, it will appear here."],
];

$pageTitle = "Reports";
$pageStyles = ["reports.css"];
$pageHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">';
include __DIR__ . "/partial/head.php";
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">

    <header class="cs-page-header">
        <div>
            <h1>Reports</h1>
            <p>Accept or reject new reports from residents, then mark them as resolved when the problem is fixed.</p>
        </div>
        <div class="cs-page-actions">
            <button type="button" class="cs-btn cs-btn-ghost" data-modal-open="downloadReportModal">
                <i class="bi bi-download" aria-hidden="true"></i>
                Download Summary
            </button>
        </div>
    </header>

    <section class="cs-card rp-card">

        <!-- STATUS TABS -->
        <nav class="rp-tabs" aria-label="Show reports by status">
            <?php foreach ($tabs as $key => $info): ?>
                <a
                    href="<?= csEscape(csUrl("reports.php", array_merge($baseQuery, ["tab" => $key]))) ?>"
                    class="rp-tab rp-tab-<?= $key ?><?= $tab === $key ? " is-active" : "" ?>"
                    <?= $tab === $key ? 'aria-current="page"' : "" ?>
                >
                    <i class="bi <?= $info["icon"] ?>" aria-hidden="true"></i>
                    <?= csEscape($info["label"]) ?>
                    <span class="rp-tab-count"><?= number_format($tabCounts[$key]) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- SEARCH -->
        <form class="rp-search" method="GET" action="reports.php" role="search">
            <input type="hidden" name="tab" value="<?= csEscape($tab) ?>">

            <div class="cs-input-icon rp-search-input">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="cs-sr-only" for="reportSearch">Search reports</label>
                <input
                    type="search"
                    id="reportSearch"
                    name="search"
                    class="cs-input"
                    value="<?= csEscape($searchQuery) ?>"
                    placeholder="Search by street, name, problem or report ID"
                    maxlength="100"
                >
            </div>

            <label class="cs-sr-only" for="reportCategory">Type of issue</label>
            <select id="reportCategory" name="categoryID" class="cs-select rp-search-type">
                <option value="">All types of issue</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= csEscape($category["categoryID"]) ?>" <?= $selectedCategory === $category["categoryID"] ? "selected" : "" ?>>
                        <?= csEscape($category["categoryName"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="rp-search-row">
                <div class="cs-field">
                    <label class="cs-label" for="reportStart">From date</label>
                    <input type="date" id="reportStart" name="start_date" class="cs-input" value="<?= csEscape($startDate) ?>" max="<?= date("Y-m-d") ?>">
                </div>

                <div class="cs-field">
                    <label class="cs-label" for="reportEnd">To date</label>
                    <input type="date" id="reportEnd" name="end_date" class="cs-input" value="<?= csEscape($endDate) ?>" max="<?= date("Y-m-d") ?>">
                </div>

                <div class="cs-field">
                    <label class="cs-label" for="reportSort">Sort by</label>
                    <select id="reportSort" name="sort" class="cs-select">
                        <option value="newest" <?= $sort === "newest" ? "selected" : "" ?>>Newest to oldest</option>
                        <option value="oldest" <?= $sort === "oldest" ? "selected" : "" ?>>Oldest to newest</option>
                    </select>
                </div>

                <div class="rp-search-buttons">
                    <button type="submit" class="cs-btn cs-btn-primary">
                        <i class="bi bi-search" aria-hidden="true"></i> Search
                    </button>

                    <?php if ($hasFilters || $sort === "oldest"): ?>
                        <a href="<?= csEscape(csUrl("reports.php", ["tab" => $tab])) ?>" class="cs-btn cs-btn-ghost">Clear</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($startDate !== "" || $endDate !== ""): ?>
                <p class="rp-filter-note">
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                    Showing reports submitted
                    <?php if ($startDate !== "" && $endDate !== ""): ?>
                        from <strong><?= csEscape(csDate($startDate, "F j, Y")) ?></strong> to <strong><?= csEscape(csDate($endDate, "F j, Y")) ?></strong>
                    <?php elseif ($startDate !== ""): ?>
                        on or after <strong><?= csEscape(csDate($startDate, "F j, Y")) ?></strong>
                    <?php else: ?>
                        on or before <strong><?= csEscape(csDate($endDate, "F j, Y")) ?></strong>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </form>

        <!-- LIST -->
        <?php if ($reportList): ?>
            <ul class="rp-list">
                <?php foreach ($reportList as $report): ?>
                    <?php
                    $id = (string) $report["reportID"];
                    $info = $payload[$id];
                    $isPending = $report["statusID"] === "STAT001";
                    $isLinked = $info["linkedTo"] !== ""; // follows its main report: no own actions
                    $canAccept = !$isLinked && $isPending && ($info["isOriginal"] || $info["originalStatusID"] === "STAT001");
                    $canChange = !$isLinked && $info["isOriginal"] && in_array($report["statusID"], ["STAT002", "STAT004"], true);
                    $isRejected = strtolower(trim((string) $report["statusName"])) === "rejected";
                    ?>
                    <li class="rp-item is-<?= csEscape(csStatusSlug($report["statusName"])) ?>">
                        <button type="button" class="rp-thumb" data-view="<?= csEscape($id) ?>" aria-label="View report <?= csEscape($id) ?>">
                            <?php if ($info["images"]): ?>
                                <img src="<?= csEscape($info["images"][0]) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <i class="bi <?= csCategoryIcon($report["categoryName"]) ?>" aria-hidden="true"></i>
                            <?php endif; ?>
                        </button>

                        <div class="rp-main">
                            <div class="rp-title-row">
                                <h2><?= csEscape($info["title"] !== "" ? $info["title"] : $report["categoryName"]) ?></h2>
                                <?= csStatusBadge($report["statusName"]) ?>
                                <?php if ($info["role"] === "main"): ?>
                                    <span class="rp-role-tag is-main" title="Confirmed duplicates follow this report"><i class="bi bi-star-fill" aria-hidden="true"></i>Main report</span>
                                <?php elseif ($info["role"] === "suggested-main" || $info["isSuggestedMain"]): ?>
                                    <span class="rp-role-tag is-suggested" title="<?= $info["role"] === "suggested-main" ? "Becomes the main report when it is accepted" : "Suggested as the main report of possible duplicates" ?>"><i class="bi bi-star" aria-hidden="true"></i>Suggested main report</span>
                                <?php endif; ?>
                            </div>
                            <!-- One detail per line, easier to read. The location shows only in View. -->
                            <dl class="cs-facts">
                                <div>
                                    <dt>Report ID</dt>
                                    <dd><?= csEscape($id) ?></dd>
                                </div>
                                <div>
                                    <dt>Type of issue</dt>
                                    <dd><?= csEscape($report["categoryName"]) ?></dd>
                                </div>
                                <div>
                                    <dt>Submitted</dt>
                                    <dd><?= csEscape(csDate($report["dateReported"], "M j, Y · g:i A")) ?></dd>
                                </div>
                                <div>
                                    <dt>Reported by</dt>
                                    <dd><?= csEscape($info["resident"] !== "" ? $info["resident"] : "Resident") ?></dd>
                                </div>
                            </dl>
                            <?php if ($isLinked): /* only confirmed (admin-marked) duplicates say "Duplicate of" */ ?>
                                <p class="rp-line rp-repeat"><i class="bi bi-files" aria-hidden="true"></i>Duplicate of report <?= csEscape($info["linkedTo"]) ?> · follows its status</p>
                            <?php elseif (!$info["isOriginal"]): ?>
                                <p class="rp-line rp-repeat"><i class="bi bi-geo-alt" aria-hidden="true"></i>Same place and type of issue as report <?= csEscape($info["originalID"]) ?></p>
                            <?php elseif ($info["cycleSize"] > 1): ?>
                                <p class="rp-line rp-repeat"><i class="bi bi-people" aria-hidden="true"></i><?= $info["cycleSize"] - 1 ?> other <?= $info["cycleSize"] - 1 === 1 ? "report" : "reports" ?> at the same place</p>
                            <?php endif; ?>
                            <?php if (!empty($info["feedback"])): $fb = $info["feedback"]; ?>
                                <p class="rp-feedback-tag <?= $fb["fixed"] ? "is-yes" : "is-no" ?>">
                                    <i class="bi <?= $fb["fixed"] ? "bi-check-circle-fill" : "bi-exclamation-triangle-fill" ?>" aria-hidden="true"></i>
                                    Resident feedback: <?= $fb["fixed"] ? "fixed" : "not fixed" ?> · <?= $fb["rating"] ?>/5
                                </p>
                            <?php endif; ?>
                            <?php if ($info["linkedCount"] > 0): ?>
                                <button type="button" class="rp-line rp-linked-count" data-view="<?= csEscape($id) ?>">
                                    <i class="bi bi-files" aria-hidden="true"></i>
                                    <?= $info["linkedCount"] ?> confirmed duplicate <?= $info["linkedCount"] === 1 ? "report follows" : "reports follow" ?> this report · see them in View
                                </button>
                            <?php endif; ?>
                            <?php if ($info["duplicateGroup"] !== ""): /* a suggestion only, not a confirmed relationship */ ?>
                                <a class="rp-dup-tag" href="possible_duplicates.php?from=<?= csEscape(rawurlencode($id)) ?>#group-<?= csEscape($info["duplicateGroup"]) ?>">
                                    <i class="bi bi-files" aria-hidden="true"></i>
                                    <?php if ($info["isSuggestedMain"]): ?>
                                        Possible duplicates to review: <?= csEscape(implode(", ", array_slice($info["duplicateOthers"], 0, 3))) ?><?= count($info["duplicateOthers"]) > 3 ? " +" . (count($info["duplicateOthers"]) - 3) . " more" : "" ?>
                                    <?php else: ?>
                                        Possible duplicate of <?= csEscape(implode(", ", array_slice($info["duplicateOthers"], 0, 3))) ?><?= count($info["duplicateOthers"]) > 3 ? " +" . (count($info["duplicateOthers"]) - 3) . " more" : "" ?> · not confirmed
                                    <?php endif; ?>
                                    <span class="rp-dup-review">Review</span>
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="rp-actions">
                            <?php if ($canAccept): ?>
                                <button type="button" class="cs-btn cs-btn-primary cs-btn-sm" data-accept="<?= csEscape($id) ?>">
                                    <i class="bi bi-check-lg" aria-hidden="true"></i> Accept
                                </button>
                            <?php endif; ?>
                            <?php if ($canChange): ?>
                                <button type="button" class="cs-btn cs-btn-secondary cs-btn-sm" data-change-status="<?= csEscape($id) ?>">
                                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Change Status
                                </button>
                            <?php endif; ?>
                            <?php if ($isRejected && !$isLinked): ?>
                                <button type="button" class="cs-btn cs-btn-ghost cs-btn-sm" data-restore="<?= csEscape($id) ?>">
                                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore
                                </button>
                            <?php endif; ?>
                            <?php if ($isPending && !$isLinked): ?>
                                <button type="button" class="cs-btn cs-btn-danger-outline cs-btn-sm" data-reject="<?= csEscape($id) ?>">
                                    <i class="bi bi-x-lg" aria-hidden="true"></i> Reject
                                </button>
                            <?php endif; ?>
                            <button type="button" class="cs-btn cs-btn-ghost cs-btn-sm" data-view="<?= csEscape($id) ?>">
                                View
                            </button>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="cs-card-footer">
                <span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalReports) ?> of <?= number_format($totalReports) ?></span>
                <?php if ($totalPages > 1): ?>
                    <nav class="cs-pagination" aria-label="Pages">
                        <a class="cs-page-btn<?= $currentPage <= 1 ? " is-disabled" : "" ?>" href="<?= csEscape(csUrl("reports.php", array_merge($baseQuery, ["page" => $currentPage - 1]))) ?>">
                            <i class="bi bi-chevron-left" aria-hidden="true"></i> Previous
                        </a>
                        <span class="rp-page-label">Page <?= $currentPage ?> of <?= $totalPages ?></span>
                        <a class="cs-page-btn<?= $currentPage >= $totalPages ? " is-disabled" : "" ?>" href="<?= csEscape(csUrl("reports.php", array_merge($baseQuery, ["page" => $currentPage + 1]))) ?>">
                            Next <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    </nav>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="cs-empty">
                <span class="cs-empty-icon"><i class="bi <?= $tab === "pending" && !$hasFilters ? "bi-check2-circle" : "bi-inbox" ?>" aria-hidden="true"></i></span>
                <?php if ($hasFilters): ?>
                    <h3>No reports found</h3>
                    <p>Nothing matches your search here. Try another word, or look in the <strong>All</strong> tab.</p>
                    <a href="<?= csEscape(csUrl("reports.php", ["tab" => $tab])) ?>" class="cs-btn cs-btn-secondary">Clear Search</a>
                <?php else: ?>
                    <h3><?= csEscape($emptyMessages[$tab][0]) ?></h3>
                    <p><?= csEscape($emptyMessages[$tab][1]) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<?php include __DIR__ . "/partial/report_drawer.php"; ?>

<!-- DOWNLOAD SUMMARY -->
<div class="cs-modal" id="downloadReportModal" role="dialog" aria-modal="true" aria-labelledby="downloadTitle">
    <div class="cs-modal-dialog">
        <form id="downloadReportForm" action="export_reports.php" method="GET">
            <div class="cs-modal-header">
                <div>
                    <h2 id="downloadTitle">Download a summary</h2>
                    <p>Choose the time period and file type.</p>
                </div>
                <button type="button" class="cs-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="cs-modal-body">
                <div class="cs-field">
                    <label class="cs-label" for="reportPeriod">Time period</label>
                    <select id="reportPeriod" name="period" class="cs-select" required>
                        <option value="">Choose a period…</option>
                        <option value="day">Today</option>
                        <option value="3months">Last 3 months</option>
                        <option value="6months">Last 6 months</option>
                        <option value="year">Last 12 months</option>
                    </select>
                </div>
                <div class="cs-field">
                    <label class="cs-label" for="reportFormat">File type</label>
                    <select id="reportFormat" name="format" class="cs-select">
                        <option value="pdf">PDF (best for printing)</option>
                        <option value="docx">Word (can be edited)</option>
                        <option value="csv">CSV (for Excel)</option>
                    </select>
                </div>
            </div>
            <div class="cs-modal-footer">
                <button type="button" class="cs-btn cs-btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="cs-btn cs-btn-primary"><i class="bi bi-download" aria-hidden="true"></i> Download</button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="reportPayload"><?= $payloadJSON ?></script>

<?php include __DIR__ . "/partial/scripts.php"; ?>
<?php include __DIR__ . "/partial/report_drawer_scripts.php"; ?>

</body>
</html>
