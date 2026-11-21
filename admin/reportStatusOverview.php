<?php
/**
 * CitiSense: Read-only Report Status Overview.
 *
 * Navigated to from the Reports page's Quick Actions > Manage Reports.
 * The eye-action drawer and original/duplicate management are NOT changed.
 *
 * GET parameters (from reports.php):
 *   start_date, end_date, categoryID, statusID
 *   statusID=""    => Default: Overdue (when present), In Progress, Resolved
 *   statusID="all" => All: Overdue, Pending, In Progress, Resolved
 *   other statusID => Only the selected status (STAT001-STAT004)
 */

session_start();

if (empty($_SESSION['admin_id']) || empty($_SESSION['barangay_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/partial/helpers.php';

function boardEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function boardDate($value, string $format = 'M j, Y g:i A'): string
{
    $stamp = $value ? strtotime((string) $value) : false;
    return $stamp === false ? '' : date($format, $stamp);
}

function boardValidDate(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
}

$barangayID = (string) $_SESSION['barangay_id'];
$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$categoryID = trim((string) ($_GET['categoryID'] ?? ''));
$selectedStatus = trim((string) ($_GET['statusID'] ?? ''));

if ($startDate !== '' && !boardValidDate($startDate)) {
    $startDate = '';
}
if ($endDate !== '' && !boardValidDate($endDate)) {
    $endDate = '';
}
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$statusLabels = [
    'STAT004' => 'Overdue',
    'STAT001' => 'Pending',
    'STAT002' => 'In Progress',
    'STAT003' => 'Resolved',
];

if (!in_array($selectedStatus, ['', 'all', ...array_keys($statusLabels)], true)) {
    $selectedStatus = '';
}

// Only the three categories still supported by the Reports page.
$categoryNames = ['Waste Accumulation', 'Potholes', 'Others'];

// Always preserve the original page's filtering when navigating back.
$backQuery = array_filter([
    'start_date' => $startDate,
    'end_date' => $endDate,
    'categoryID' => $categoryID,
    'statusID' => $selectedStatus,
], static fn($value) => $value !== '');
$backUrl = 'reports.php' . ($backQuery ? '?' . http_build_query($backQuery) : '');

// Retrieve barangay name to preserve the Reports-page context.
$barangayName = 'Your Barangay';
$stmt = $conn->prepare('SELECT barangayName FROM barangay WHERE barangayID = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('s', $barangayID);
    $stmt->execute();
    $barangayRow = $stmt->get_result()->fetch_assoc();
    $barangayName = (string) ($barangayRow['barangayName'] ?? $barangayName);
    $stmt->close();
}

// Base filters. Never include reports belonging to another barangay.

$where = [
    'r.barangayID = ?',
    "c.categoryName IN ('Waste Accumulation', 'Potholes', 'Others')",
    "r.statusID IN ('STAT004', 'STAT002', 'STAT003')"
];

$types = 's';
$params = [$barangayID];

// Only show reports from the selected category.
if ($categoryID !== '') {
    $where[] = 'r.categoryID = ?';
    $types .= 's';
    $params[] = $categoryID;
}


// Use the same actual statusID that the existing report workflow saves.
// Do not recalculate or fabricate overdue status from r.timeline here.
$sql = '
    SELECT
        r.reportID,
        r.categoryID,
        r.userID,
        r.statusID,
        r.title,
        r.description,
        r.dateReported,
        r.timeline,
        c.categoryName,
        s.statusName,
        l.address,
        u.firstName,
        u.lastName,
        u.email,
        u.contactNo,
        (
            SELECT ri.imagePath
            FROM reportImages ri
            WHERE ri.reportID = r.reportID
            ORDER BY ri.uploadedAt ASC
            LIMIT 1
        ) AS imagePath
    FROM reports r
    INNER JOIN categories c ON c.categoryID = r.categoryID
    INNER JOIN stats s ON s.statusID = r.statusID
    LEFT JOIN location l ON l.locationID = r.locationID
    LEFT JOIN users u ON u.userID = r.userID
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY r.dateReported DESC, r.reportID DESC
';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('CitiSense report status overview SQL error: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load reports.');
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$allMatchingReports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$grouped = array_fill_keys(array_keys($statusLabels), []);
$details = [];

foreach ($allMatchingReports as $report) {
    $statusID = (string) $report['statusID'];
    if (!isset($grouped[$statusID])) {
        continue;
    }

    $reportID = (string) $report['reportID'];
    $resident = trim((string) ($report['firstName'] ?? '') . ' ' . (string) ($report['lastName'] ?? ''));
    if ($resident === '') {
        $resident = 'Resident unavailable';
    }
    $report['residentName'] = $resident;
    $grouped[$statusID][] = $report;

    $details[$reportID] = [
        'reportID' => $reportID,
        'category' => $report['categoryName'],
        'location' => $report['address'] ?: 'Location unavailable',
        'description' => $report['description'] ?: 'No description provided.',
        'statusID' => $statusID,
        'status' => $report['statusName'],
        'dateReported' => boardDate($report['dateReported']),
        'timeline' => boardDate($report['timeline']),
        'resident' => $resident,
        'userID' => $report['userID'],
        'email' => $report['email'] ?: 'Not provided',
        'contact' => $report['contactNo'] ?: 'Not provided',
        'image' => csMediaUrl($report['imagePath'] ?? ''),
        'history' => [],
    ];
}

// Retrieve real reportHistory records, scoped to the admin's barangay.
// Only copy histories belonging to a report in this board.
if ($details) {
    $historySQL = '
        SELECT
            h.reportID,
            h.statusID,
            h.remarks,
            h.dateChanged,
            s.statusName,
            u.firstName,
            u.lastName
        FROM reportHistory h
        INNER JOIN reports r ON r.reportID = h.reportID
        INNER JOIN stats s ON s.statusID = h.statusID
        LEFT JOIN users u ON u.userID = h.userID
        WHERE r.barangayID = ?
        ORDER BY h.dateChanged ASC, h.historyID ASC
    ';

    $historyStmt = $conn->prepare($historySQL);
    if (!$historyStmt) {
        error_log('CitiSense report history SQL error: ' . $conn->error);
        http_response_code(500);
        exit('Unable to load report status history.');
    }
    $historyStmt->bind_param('s', $barangayID);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    while ($entry = $historyResult->fetch_assoc()) {
        $id = (string) $entry['reportID'];
        if (!isset($details[$id])) {
            continue;
        }
        $changedBy = trim((string) ($entry['firstName'] ?? '') . ' ' . (string) ($entry['lastName'] ?? ''));
        $details[$id]['history'][] = [
            'status' => $entry['statusName'],
            'dateChanged' => boardDate($entry['dateChanged']),
            'remarks' => $entry['remarks'] ?? '',
            'changedBy' => $changedBy,
        ];
    }
    $historyStmt->close();
}

/* ============================================
   REPORT STATUS COLUMN DISPLAY
============================================ */

$displayStatuses = [];

// Show Overdue first, only when reports exist.
if (!empty($grouped['STAT004'])) {
    $displayStatuses[] = 'STAT004';
}

// Always show In Progress and Resolved.
$displayStatuses[] = 'STAT002';
$displayStatuses[] = 'STAT003';

$jsonDetails = json_encode(
    $details,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
);
$boardCategoryName = '';
if ($categoryID !== '') {
    foreach ($allMatchingReports as $report) {
        if ((string) $report['categoryID'] === $categoryID) {
            $boardCategoryName = (string) $report['categoryName'];
            break;
        }
    }
    if ($boardCategoryName === '') {
        $catStmt = $conn->prepare('SELECT categoryName FROM categories WHERE categoryID = ? LIMIT 1');
        if ($catStmt) {
            $catStmt->bind_param('s', $categoryID);
            $catStmt->execute();
            $boardCategoryName = (string) ($catStmt->get_result()->fetch_assoc()['categoryName'] ?? '');
            $catStmt->close();
        }
    }
}

$columnHelp = [
    'STAT004' => 'Past the target date. Please follow up.',
    'STAT002' => 'Accepted and being worked on.',
    'STAT003' => 'Fixed and closed.',
];

$pageTitle = 'Track Progress';
$pageStyles = ['reportStatusOverview.css'];
include __DIR__ . '/partial/head.php';
?>
<body>
    <?php include __DIR__ . '/partial/sidebar.php'; ?>

    <main class="cs-main" id="main-content">
        <header class="cs-page-header">
            <div>
                <a class="cs-back-link" href="<?= boardEscape($backUrl) ?>">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                    Back to Reports
                </a>
                <h1>Track Progress<?= $boardCategoryName !== '' ? ': ' . boardEscape($boardCategoryName) : '' ?></h1>
                <p>Accepted reports grouped by their current status. Select a report to see its details and history.</p>
                <span class="cs-location-chip">
                    <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                    <?= boardEscape($barangayName) ?>
                </span>
            </div>
            <div class="board-total">
                <strong><?= count($allMatchingReports) ?></strong>
                <?= count($allMatchingReports) === 1 ? 'report' : 'reports' ?>
            </div>
        </header>

        <p class="cs-alert cs-alert-info board-note">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span class="cs-alert-body">New (Pending) reports are not shown here. To accept or reject them, go back to <strong>Reports</strong>.</span>
        </p>

        <section class="board-columns board-columns-<?= count($displayStatuses) ?>" aria-label="Reports grouped by current status">
            <?php foreach ($displayStatuses as $statusID): ?>
                <?php
                $items = $grouped[$statusID];
                $statusName = $statusLabels[$statusID];
                $slug = csStatusSlug($statusName);
                ?>
                <div class="board-column is-<?= boardEscape($slug) ?>">
                    <header class="board-column-header">
                        <div>
                            <h2>
                                <i class="bi <?= csStatusIcon($statusName) ?>" aria-hidden="true"></i>
                                <?= boardEscape($statusName) ?>
                            </h2>
                            <p><?= boardEscape($columnHelp[$statusID] ?? '') ?></p>
                        </div>
                        <span class="board-count" aria-label="<?= count($items) ?> reports"><?= count($items) ?></span>
                    </header>

                    <div class="board-stack">
                        <?php if (!$items): ?>
                            <p class="board-empty">No <?= boardEscape(strtolower($statusName)) ?> reports.</p>
                        <?php endif; ?>

                        <?php foreach ($items as $report): ?>
                            <button
                                type="button"
                                class="report-board-card"
                                data-report-id="<?= boardEscape($report['reportID']) ?>"
                            >
                                <span class="board-card-top">
                                    <strong><?= boardEscape($report['categoryName']) ?></strong>
                                    <span class="cs-subtle">ID <?= boardEscape($report['reportID']) ?></span>
                                </span>
                                <?php if (!empty($report['title'])): ?>
                                    <span class="board-card-title"><?= boardEscape($report['title']) ?></span>
                                <?php endif; ?>
                                <span class="board-card-line"><i class="bi bi-geo-alt" aria-hidden="true"></i><?= boardEscape($report['address'] ?: 'Location unavailable') ?></span>
                                <span class="board-card-line"><i class="bi bi-calendar3" aria-hidden="true"></i>Submitted <?= boardEscape(boardDate($report['dateReported'], 'M j, Y')) ?></span>
                                <?php if (!empty($report['timeline']) && $statusID !== 'STAT003'): ?>
                                    <span class="board-card-line"><i class="bi bi-flag" aria-hidden="true"></i>Target <?= boardEscape(boardDate($report['timeline'], 'M j, Y')) ?></span>
                                <?php endif; ?>
                                <span class="board-card-line"><i class="bi bi-person" aria-hidden="true"></i><?= boardEscape($report['residentName']) ?></span>
                                <span class="board-card-open">View details <i class="bi bi-chevron-right" aria-hidden="true"></i></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <?php include __DIR__ . '/partial/footer.php'; ?>
    </main>

    <!-- Read-only report details drawer -->
    <div class="report-board-drawer-overlay" id="reportBoardOverlay" hidden>
        <aside class="report-board-drawer" id="reportBoardDrawer" role="dialog" aria-modal="true"
               aria-labelledby="boardDrawerHeading" tabindex="-1">
            <div class="cs-drawer-header">
                <h2 id="boardDrawerHeading">Report details</h2>
                <button type="button" id="boardDrawerClose" class="cs-btn cs-btn-ghost cs-btn-sm">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                    Close
                </button>
            </div>

            <div class="board-drawer-body">
                <section class="board-detail-section" aria-labelledby="boardInfoHeading">
                    <h3 id="boardInfoHeading"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Report information</h3>
                    <dl class="board-detail-list">
                        <div><dt>Type of issue</dt><dd id="boardDetailCategory"></dd></div>
                        <div><dt>Report ID</dt><dd id="boardDetailReportID"></dd></div>
                        <div><dt>Status</dt><dd id="boardDetailStatus"></dd></div>
                        <div><dt>Location</dt><dd id="boardDetailLocation"></dd></div>
                        <div><dt>What the resident said</dt><dd id="boardDetailDescription" class="board-description"></dd></div>
                        <div><dt>Date submitted</dt><dd id="boardDetailDate"></dd></div>
                        <div id="boardDetailTimelineRow" hidden><dt>Target completion</dt><dd id="boardDetailTimeline"></dd></div>
                    </dl>
                </section>

                <section class="board-detail-section" aria-labelledby="boardResidentHeading">
                    <h3 id="boardResidentHeading"><i class="bi bi-person" aria-hidden="true"></i> Submitted by</h3>
                    <dl class="board-detail-list">
                        <div><dt>Name</dt><dd id="boardDetailResident"></dd></div>
                        <div><dt>Email</dt><dd id="boardDetailEmail"></dd></div>
                        <div><dt>Contact number</dt><dd id="boardDetailContact"></dd></div>
                    </dl>
                </section>

                <section class="board-detail-section" id="boardHistoryPanel" hidden aria-labelledby="boardHistoryHeading">
                    <h3 id="boardHistoryHeading"><i class="bi bi-clock-history" aria-hidden="true"></i> Status history</h3>
                    <div class="report-board-history-list" id="boardHistoryList"></div>
                </section>
            </div>
        </aside>
    </div>

    <script type="application/json" id="reportBoardPayload"><?= $jsonDetails ?: '{}' ?></script>
    <?php include __DIR__ . '/partial/scripts.php'; ?>
    <script src="../js/reportStatusOverview.js?v=<?= $csAssetVersion ?>"></script>
</body>
</html>
