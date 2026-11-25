<?php

session_start();


// ==========================================================
// ADMIN SESSION CHECK
// ==========================================================

if (!isset($_SESSION["admin_id"], $_SESSION["barangay_id"])) {
    header("Location: login.php");
    exit;
}


// ==========================================================
// DATABASE CONNECTION
// ==========================================================

require_once __DIR__ . "/../config/database.php";


// ==========================================================
// LOGGED-IN ADMIN BARANGAY
// ==========================================================

$barangayID = $_SESSION["barangay_id"];


// ==========================================================
// GET BARANGAY NAME
// ==========================================================

$barangayName = "Barangay";

$stmt = $conn->prepare("
    SELECT barangayName
    FROM barangay
    WHERE barangayID = ?
    LIMIT 1
");

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $barangay = $result->fetch_assoc();

    $barangayName = $barangay["barangayName"];
}

$stmt->close();


// ==========================================================
// GET AVAILABLE CATEGORIES
// ==========================================================

$categories = [];

$stmt = $conn->prepare("
    SELECT
        c.categoryID,
        c.categoryName

    FROM categories c

    LEFT JOIN reports r
        ON r.categoryID = c.categoryID
        AND r.barangayID = ?

    WHERE c.categoryName <> 'Blocked Drainage'

    GROUP BY
        c.categoryID,
        c.categoryName

    ORDER BY
        c.categoryID ASC
");

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $categories[] = [
        "categoryID"   => $row["categoryID"],
        "categoryName" => $row["categoryName"]
    ];

}

$stmt->close();


// ==========================================================
// GET STATUSES
// ==========================================================
//
// Your database uses the stats table.
// No report status is hardcoded here.
// The statuses displayed come from the database.
//
// ==========================================================

$statuses = [];

$stmt = $conn->prepare("
    SELECT
        statusID,
        statusName

    FROM stats

    ORDER BY statusID ASC
");

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $statuses[] = $row;
}

$stmt->close();


// ==========================================================
// GET AVAILABLE DATE RANGE
// ==========================================================

// Report dates are stored in UTC; group and filter by Philippine calendar day.
$csLocalReported = csSqlLocal("r.dateReported");
$csLocalReportedBare = csSqlLocal("dateReported");

$databaseStartDate = "";
$databaseEndDate = "";

$stmt = $conn->prepare("
    SELECT
        MIN(DATE($csLocalReportedBare)) AS startDate,
        MAX(DATE($csLocalReportedBare)) AS endDate

    FROM reports

    WHERE barangayID = ?
");

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $databaseStartDate = $row["startDate"] ?? "";
    $databaseEndDate = $row["endDate"] ?? "";
}

$stmt->close();


// ==========================================================
// FILTER VALUES
// ==========================================================

$startDate = trim($_GET["start_date"] ?? "");
$endDate = trim($_GET["end_date"] ?? "");
$selectedCategory = trim($_GET["categoryID"] ?? "");
$selectedStatus = trim($_GET["statusID"] ?? "");


// ==========================================================
// DEFAULT DATE RANGE
// ==========================================================
//
// If no date filter was selected,
// use the actual earliest and latest
// report dates from the database.
//
// ==========================================================

if ($startDate === "" && $databaseStartDate !== "") {
    $startDate = $databaseStartDate;
}

if ($endDate === "" && $databaseEndDate !== "") {
    $endDate = $databaseEndDate;
}


// ==========================================================
// BUILD REPORT FILTER
// ==========================================================

$where = [
    "r.barangayID = ?"
];

$types = "s";
$params = [
    $barangayID
];


// DATE FILTER

if ($startDate !== "") {

    $where[] = "DATE($csLocalReported) >= ?";

    $types .= "s";

    $params[] = $startDate;
}


if ($endDate !== "") {

    $where[] = "DATE($csLocalReported) <= ?";

    $types .= "s";

    $params[] = $endDate;
}


// CATEGORY FILTER

if ($selectedCategory !== "") {

    $where[] = "r.categoryID = ?";

    $types .= "s";

    $params[] = $selectedCategory;
}


// STATUS FILTER

if ($selectedStatus !== "") {

    $where[] = "r.statusID = ?";

    $types .= "s";

    $params[] = $selectedStatus;
}


$whereSQL = implode(" AND ", $where);


// ==========================================================
// HELPER FOR BINDING DYNAMIC PARAMETERS
// ==========================================================

function bindDynamicParameters($stmt, $types, &$params)
{
    if ($types === "") {
        return;
    }

    $bindValues = [];

    $bindValues[] = $types;

    foreach ($params as $key => $value) {
        $bindValues[] = &$params[$key];
    }

    call_user_func_array(
        [$stmt, "bind_param"],
        $bindValues
    );
}


// ==========================================================
// SUMMARY TOTAL REPORTS
// ==========================================================
//
// COUNT(reportID) counts every submitted record.
// Duplicate submissions are therefore included.
//
// ==========================================================

$totalReports = 0;

$sql = "
    SELECT COUNT(r.reportID) AS totalReports

    FROM reports r

    WHERE $whereSQL
";

$stmt = $conn->prepare($sql);

bindDynamicParameters(
    $stmt,
    $types,
    $params
);

$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $totalReports =
        (int) ($row["totalReports"] ?? 0);
}

$stmt->close();


// ==========================================================
// SUMMARY STATUS COUNTS
// ==========================================================

$statusSummary = [];

$sql = "
    SELECT
        s.statusID,
        s.statusName,
        COUNT(r.reportID) AS reportCount

    FROM stats s

    LEFT JOIN reports r
        ON r.statusID = s.statusID
        AND r.barangayID = ?
";

$statusWhere = [];

$statusTypes = "s";

$statusParams = [
    $barangayID
];


// Date filters for summary

if ($startDate !== "") {

    $statusWhere[] =
        "DATE($csLocalReported) >= ?";

    $statusTypes .= "s";

    $statusParams[] = $startDate;
}

if ($endDate !== "") {

    $statusWhere[] =
        "DATE($csLocalReported) <= ?";

    $statusTypes .= "s";

    $statusParams[] = $endDate;
}

if ($selectedCategory !== "") {

    $statusWhere[] =
        "r.categoryID = ?";

    $statusTypes .= "s";

    $statusParams[] = $selectedCategory;
}

if ($selectedStatus !== "") {

    $statusWhere[] =
        "r.statusID = ?";

    $statusTypes .= "s";

    $statusParams[] = $selectedStatus;
}


if (count($statusWhere) > 0) {

    $sql .= "
        AND " . implode(
            " AND ",
            $statusWhere
        );
}

$sql .= "
    GROUP BY
        s.statusID,
        s.statusName

    ORDER BY s.statusID ASC
";


$stmt = $conn->prepare($sql);

bindDynamicParameters(
    $stmt,
    $statusTypes,
    $statusParams
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $statusSummary[] = [

        "statusID" =>
            $row["statusID"],

        "statusName" =>
            $row["statusName"],

        "reportCount" =>
            (int) $row["reportCount"]

    ];
}

$stmt->close();


// ==========================================================
// REPORTS BY CATEGORY
// ==========================================================

$categorySummary = [];

$sql = "
    SELECT
        c.categoryID,
        c.categoryName,
        COUNT(r.reportID) AS reportCount

    FROM categories c

    INNER JOIN reports r
        ON r.categoryID = c.categoryID

    WHERE $whereSQL

    GROUP BY
        c.categoryID,
        c.categoryName

    ORDER BY reportCount DESC
";

$stmt = $conn->prepare($sql);

bindDynamicParameters(
    $stmt,
    $types,
    $params
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $categorySummary[] = [

        "categoryID" =>
            $row["categoryID"],

        "categoryName" =>
            $row["categoryName"],

        "reportCount" =>
            (int) $row["reportCount"]

    ];
}

$stmt->close();


// ==========================================================
// REPORTS OVER TIME
// ==========================================================
//
// Every reportID is counted.
// Duplicate submissions are included.
//
// ==========================================================

$dailyReports = [];

$sql = "
    SELECT
        DATE($csLocalReported) AS reportDate,
        s.statusID,
        s.statusName,
        COUNT(r.reportID) AS reportCount

    FROM reports r

    INNER JOIN stats s
        ON r.statusID = s.statusID

    WHERE $whereSQL

    GROUP BY
        DATE($csLocalReported),
        s.statusID,
        s.statusName

    ORDER BY
        reportDate ASC
";


$stmt = $conn->prepare($sql);

bindDynamicParameters(
    $stmt,
    $types,
    $params
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $date = $row["reportDate"];

    if (!isset($dailyReports[$date])) {

        $dailyReports[$date] = [];

    }

    $dailyReports[$date][
        $row["statusName"]
    ] = (int) $row["reportCount"];
}

$stmt->close();


// ==========================================================
// CREATE CHART DATA
// ==========================================================

$chartLabels = [];

$chartTotal = [];


// Build dates from actual database result

foreach ($dailyReports as $date => $statusData) {

    $chartLabels[] = $date;

    $dailyTotal = 0;

    foreach ($statusData as $count) {

        $dailyTotal += (int) $count;

    }

    $chartTotal[] = $dailyTotal;
}


// ==========================================================
// STATUS DATA FOR CHART
// ==========================================================

$chartStatusData = [];

foreach ($statuses as $status) {

    $statusName = $status["statusName"];

    $values = [];

    foreach ($dailyReports as $date => $statusData) {

        $values[] =
            (int) (
                $statusData[$statusName]
                ?? 0
            );
    }

    $chartStatusData[] = [

        "statusID" =>
            $status["statusID"],

        "statusName" =>
            $statusName,

        "values" =>
            $values

    ];
}


// ==========================================================
// NOTIFICATION COUNT
// ==========================================================

$unreadNotifications = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS unreadNotifications

    FROM notification

    WHERE userID = ?

    AND isRead = FALSE
");

$stmt->bind_param(
    "s",
    $_SESSION["admin_id"]
);

$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $unreadNotifications =
        (int) (
            $row["unreadNotifications"]
            ?? 0
        );
}

$stmt->close();


// ==========================================================
// JSON DATA
// ==========================================================

$chartLabelsJSON =
    json_encode(
        $chartLabels,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

$chartTotalJSON =
    json_encode(
        $chartTotal,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

$chartStatusJSON =
    json_encode(
        $chartStatusData,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

$categoryChartJSON =
    json_encode(
        $categorySummary,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

require_once __DIR__ . "/partial/helpers.php";

/* Safe JSON for inline <script> (escapes <, >, &, quotes). */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;

$visibleStatusSummary = array_values(array_filter(
    $statusSummary,
    static fn($status) => strtolower(trim((string) $status["statusName"])) !== "rejected"
));

$isFiltered = ($selectedCategory !== "" || $selectedStatus !== "" ||
    ($startDate !== $databaseStartDate) || ($endDate !== $databaseEndDate));

$periodLabel = ($startDate !== "" && $endDate !== "")
    ? csDate($startDate) . " – " . csDate($endDate)
    : "All dates";

$pageTitle = "Analytics";
$pageStyles = ["analytics.css"];
include __DIR__ . "/partial/head.php";
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">

    <header class="cs-page-header">
        <div>
            <h1>Analytics</h1>
            <p>See how many reports come in over time and which problems are most common.</p>
            <span class="cs-location-chip">
                <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                <?= csEscape($barangayName) ?>
            </span>
        </div>
    </header>

    <!-- FILTERS -->
    <section class="cs-card">
        <form class="analytics-filters" method="GET" action="analytics.php">
            <div class="cs-field">
                <label class="cs-label" for="anStart">From date</label>
                <input type="date" id="anStart" name="start_date" class="cs-input" value="<?= csEscape($startDate) ?>">
            </div>
            <div class="cs-field">
                <label class="cs-label" for="anEnd">To date</label>
                <input type="date" id="anEnd" name="end_date" class="cs-input" value="<?= csEscape($endDate) ?>">
            </div>
            <div class="cs-field">
                <label class="cs-label" for="anCategory">Type of issue</label>
                <select id="anCategory" name="categoryID" class="cs-select">
                    <option value="">All types</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= csEscape($category["categoryID"]) ?>" <?= $selectedCategory === $category["categoryID"] ? "selected" : "" ?>>
                            <?= csEscape($category["categoryName"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cs-field">
                <label class="cs-label" for="anStatus">Status</label>
                <select id="anStatus" name="statusID" class="cs-select">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $status): ?>
                        <?php if (strtolower(trim($status["statusName"])) === "rejected") continue; ?>
                        <option value="<?= csEscape($status["statusID"]) ?>" <?= $selectedStatus === $status["statusID"] ? "selected" : "" ?>>
                            <?= csEscape($status["statusName"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="analytics-filter-actions">
                <button type="submit" class="cs-btn cs-btn-primary">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    Show Results
                </button>
                <?php if ($isFiltered): ?>
                    <a href="analytics.php" class="cs-btn cs-btn-ghost">Clear All</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <!-- SUMMARY -->
    <section aria-label="Summary">
        <div class="cs-stat-grid analytics-stats">
            <div class="cs-stat">
                <span class="cs-icon-circle cs-tone-primary"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
                <span>
                    <span class="cs-stat-value"><?= number_format($totalReports) ?></span>
                    <span class="cs-stat-label">Total reports</span>
                    <span class="cs-stat-hint"><?= csEscape($periodLabel) ?></span>
                </span>
            </div>
            <?php foreach ($visibleStatusSummary as $status): ?>
                <?php
                $share = $totalReports > 0 ? round($status["reportCount"] / $totalReports * 100) : 0;
                $slug = csStatusSlug($status["statusName"]);
                ?>
                <div class="cs-stat">
                    <span class="cs-icon-circle cs-tone-<?= csEscape($slug) ?>"><i class="bi <?= csStatusIcon($status["statusName"]) ?>" aria-hidden="true"></i></span>
                    <span>
                        <span class="cs-stat-value"><?= number_format($status["reportCount"]) ?></span>
                        <span class="cs-stat-label"><?= csEscape($status["statusName"]) ?></span>
                        <span class="cs-stat-hint"><?= $share ?>% of reports</span>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- CHARTS -->
    <section class="analytics-grid">
        <div class="cs-card">
            <div class="cs-card-header">
                <div>
                    <h2>Reports over time</h2>
                    <p>Number of reports submitted each day, by status.</p>
                </div>
            </div>
            <div class="cs-card-body">
                <?php if (count($chartLabels) > 0): ?>
                    <div class="analytics-chart">
                        <canvas id="reportsOverTimeChart" role="img" aria-label="Line chart of daily reports by status"></canvas>
                    </div>
                <?php else: ?>
                    <div class="cs-empty">
                        <span class="cs-empty-icon"><i class="bi bi-graph-up" aria-hidden="true"></i></span>
                        <h3>No report data for these filters</h3>
                        <p>Try a wider date range or clear the filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cs-card">
            <div class="cs-card-header">
                <div>
                    <h2>Reports by type of issue</h2>
                    <p>Which problems are reported most.</p>
                </div>
            </div>
            <div class="cs-card-body">
                <?php if (count($categorySummary) > 0): ?>
                    <div class="analytics-donut">
                        <canvas id="categoryChart" role="img" aria-label="Doughnut chart of reports by type of issue"></canvas>
                        <div class="analytics-donut-center">
                            <strong><?= number_format($totalReports) ?></strong>
                            <span>Total</span>
                        </div>
                    </div>

                    <!-- Text breakdown: readable even if the chart cannot load -->
                    <ul class="analytics-legend">
                        <?php foreach ($categorySummary as $index => $item): ?>
                            <?php $share = $totalReports > 0 ? round($item["reportCount"] / $totalReports * 100) : 0; ?>
                            <li>
                                <span class="analytics-swatch" data-color-index="<?= $index ?>"></span>
                                <span class="analytics-legend-name"><?= csEscape($item["categoryName"]) ?></span>
                                <strong><?= number_format($item["reportCount"]) ?></strong>
                                <span class="cs-subtle">(<?= $share ?>%)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="cs-empty">
                        <span class="cs-empty-icon"><i class="bi bi-pie-chart" aria-hidden="true"></i></span>
                        <h3>No category data</h3>
                        <p>Category totals will appear here once reports match your filters.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<?php include __DIR__ . "/partial/scripts.php"; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const chartLabels = <?= json_encode($chartLabels, $jsonFlags) ?: "[]" ?>;
    const chartTotal = <?= json_encode($chartTotal, $jsonFlags) ?: "[]" ?>;
    const chartStatusData = <?= json_encode($chartStatusData, $jsonFlags) ?: "[]" ?>;
    const categoryData = <?= json_encode($categorySummary, $jsonFlags) ?: "[]" ?>;

    // Same palette as the status badges and the mobile app.
    const STATUS_COLORS = {
        pending: "#d97706",
        "in progress": "#2563eb",
        resolved: "#16a34a",
        overdue: "#dc2626"
    };
    const CATEGORY_COLORS = ["#2563eb", "#f59e0b", "#16a34a", "#8b5cf6", "#0ea5e9", "#ec4899", "#64748b", "#14b8a6"];

    document.querySelectorAll(".analytics-swatch").forEach((swatch) => {
        swatch.style.background = CATEGORY_COLORS[Number(swatch.dataset.colorIndex) % CATEGORY_COLORS.length];
    });

    if (typeof Chart === "undefined") {
        document.querySelectorAll(".analytics-chart, .analytics-donut").forEach((box) => {
            box.classList.add("is-unavailable");
            box.textContent = "The chart could not load. Please check your internet connection.";
        });
        return;
    }

    Chart.defaults.font.family = "Roboto, system-ui, sans-serif";
    Chart.defaults.font.size = 14;
    Chart.defaults.color = "#4b5a73";

    const lineCanvas = document.getElementById("reportsOverTimeChart");
    if (lineCanvas) {
        const datasets = [{
            label: "All reports",
            data: chartTotal,
            borderColor: "#0f1e46",
            backgroundColor: "#0f1e46",
            borderWidth: 3,
            tension: 0.3,
            pointRadius: 3
        }];

        chartStatusData
            .filter((status) => status.statusName.toLowerCase() !== "rejected")
            .forEach((status) => {
                const color = STATUS_COLORS[status.statusName.toLowerCase()] || "#64748b";
                datasets.push({
                    label: status.statusName,
                    data: status.values,
                    borderColor: color,
                    backgroundColor: color,
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3
                });
            });

        new Chart(lineCanvas, {
            type: "line",
            data: { labels: chartLabels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: "index", intersect: false },
                plugins: {
                    legend: { position: "bottom", labels: { usePointStyle: true, padding: 18, font: { size: 15 } } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "#eef2f7" } }
                }
            }
        });
    }

    const donutCanvas = document.getElementById("categoryChart");
    if (donutCanvas && categoryData.length > 0) {
        new Chart(donutCanvas, {
            type: "doughnut",
            data: {
                labels: categoryData.map((item) => item.categoryName),
                datasets: [{
                    data: categoryData.map((item) => item.reportCount),
                    backgroundColor: categoryData.map((item, i) => CATEGORY_COLORS[i % CATEGORY_COLORS.length]),
                    borderWidth: 3,
                    borderColor: "#ffffff"
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "66%",
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (context) => " " + context.raw + " reports" } }
                }
            }
        });
    }
})();
</script>

</body>
</html>
