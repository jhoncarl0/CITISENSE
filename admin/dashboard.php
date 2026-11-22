<?php

session_start();

// ==========================================
// CHECK IF ADMIN IS LOGGED IN
// ==========================================

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/partial/helpers.php";

$barangayID = (string) ($_SESSION["barangay_id"] ?? "");

// ==========================================
// GET LOGGED-IN ADMIN'S BARANGAY
// ==========================================

$stmt = $conn->prepare(
    "SELECT barangayName
     FROM barangay
     WHERE barangayID = ?
     LIMIT 1"
);
$stmt->bind_param("s", $barangayID);
$stmt->execute();
$result = $stmt->get_result();
$barangayName = $result->num_rows === 1
    ? $result->fetch_assoc()["barangayName"]
    : "Unknown Barangay";
$stmt->close();

// ==========================================
// REPORT STATISTICS
// ==========================================

$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS totalReports,
        SUM(CASE WHEN s.statusName = 'Pending' THEN 1 ELSE 0 END) AS pendingReports,
        SUM(CASE WHEN s.statusName = 'In Progress' THEN 1 ELSE 0 END) AS inProgressReports,
        SUM(CASE WHEN s.statusName = 'Resolved' THEN 1 ELSE 0 END) AS resolvedReports,
        SUM(CASE WHEN s.statusName = 'Overdue' THEN 1 ELSE 0 END) AS overdueReports
     FROM reports r
     LEFT JOIN stats s ON r.statusID = s.statusID
     WHERE r.barangayID = ?"
);
$stmt->bind_param("s", $barangayID);
$stmt->execute();
$statistics = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalReports = (int) ($statistics["totalReports"] ?? 0);
$pendingReports = (int) ($statistics["pendingReports"] ?? 0);
$inProgressReports = (int) ($statistics["inProgressReports"] ?? 0);
$resolvedReports = (int) ($statistics["resolvedReports"] ?? 0);
$overdueReports = (int) ($statistics["overdueReports"] ?? 0);
$needsAttention = $pendingReports + $overdueReports;

// ==========================================
// REPORTS BY CATEGORY
// ==========================================

$stmt = $conn->prepare(
    "SELECT c.categoryID, c.categoryName, COUNT(r.reportID) AS reportCount
     FROM categories c
     LEFT JOIN reports r
        ON c.categoryID = r.categoryID
        AND r.barangayID = ?
     WHERE c.categoryName <> 'Blocked Drainage'
     GROUP BY c.categoryID, c.categoryName
     ORDER BY reportCount DESC"
);
$stmt->bind_param("s", $barangayID);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ==========================================
// LATEST REPORTS
// ==========================================

$stmt = $conn->prepare(
    "SELECT r.reportID, r.dateReported, c.categoryName, s.statusName, l.address
     FROM reports r
     INNER JOIN categories c ON c.categoryID = r.categoryID
     INNER JOIN stats s ON s.statusID = r.statusID
     LEFT JOIN location l ON l.locationID = r.locationID
     WHERE r.barangayID = ?
     ORDER BY r.dateReported DESC, r.reportID DESC
     LIMIT 5"
);
$stmt->bind_param("s", $barangayID);
$stmt->execute();
$latestReports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ==========================================
// HEATMAP DATA (same report data as heatmap.php)
// ==========================================

$stmt = $conn->prepare(
    "SELECT r.reportID, r.locationID, l.latitude, l.longitude, l.address
     FROM reports r
     INNER JOIN location l ON r.locationID = l.locationID
     WHERE r.barangayID = ?
       AND l.latitude IS NOT NULL
       AND l.longitude IS NOT NULL
     ORDER BY r.dateReported DESC"
);
$stmt->bind_param("s", $barangayID);
$stmt->execute();
$heatmapResult = $stmt->get_result();

$heatmapData = [];
while ($row = $heatmapResult->fetch_assoc()) {
    $heatmapData[] = [
        "reportID" => $row["reportID"],
        "locationID" => $row["locationID"],
        "latitude" => (float) $row["latitude"],
        "longitude" => (float) $row["longitude"],
        "address" => $row["address"],
    ];
}
$stmt->close();

// ==========================================
// GREETING
// ==========================================

$hour = (int) date("G");
$greeting = $hour < 12 ? "Good morning" : ($hour < 18 ? "Good afternoon" : "Good evening");
$adminFirstName = trim(explode(" ", (string) ($_SESSION["admin_name"] ?? ""))[0] ?? "") ?: "Admin";

$statTiles = [
    ["Total reports", $totalReports, "bi-file-earmark-text", "primary", "all"],
    ["Overdue", $overdueReports, "bi-exclamation-triangle-fill", "overdue", "STAT004"],
    ["Pending", $pendingReports, "bi-hourglass-split", "pending", "STAT001"],
    ["In Progress", $inProgressReports, "bi-tools", "in-progress", "STAT002"],
    ["Resolved", $resolvedReports, "bi-check-circle-fill", "resolved", "STAT003"],
];

$pageTitle = "Dashboard";
$pageStyles = ["dashboard.css"];
$pageHead = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">';
include __DIR__ . "/partial/head.php";
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">

    <!-- WELCOME -->
    <header class="dash-welcome">
        <div class="dash-welcome-text">
            <span class="cs-location-chip">
                <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                <?= csEscape($barangayName) ?>
            </span>
            <p class="dash-greeting"><?= csEscape($greeting) ?>,</p>
            <h1><?= csEscape($adminFirstName) ?>!</h1>
            <p class="dash-subtitle">Here is what is happening in your community today, <?= csEscape(date("l, F j, Y")) ?>.</p>
        </div>
        <img class="dash-mascot" src="../css/images/mascot-walk-camera.svg" alt="">
    </header>

    <!-- CALL TO ACTION -->
    <?php if ($needsAttention > 0): ?>
        <section class="dash-cta" aria-labelledby="ctaHeading">
            <span class="dash-cta-icon"><i class="bi bi-bell" aria-hidden="true"></i></span>
            <div class="dash-cta-text">
                <h2 id="ctaHeading">
                    <?= number_format($needsAttention) ?> <?= $needsAttention === 1 ? "report needs" : "reports need" ?> your attention
                </h2>
                <p>
                    <?php if ($overdueReports > 0): ?>
                        <?= $overdueReports ?> overdue and
                    <?php endif; ?>
                    
                    waiting for review.
                </p>
            </div>
            <a class="cs-btn dash-cta-button" href="<?= $overdueReports > 0 && $pendingReports === 0 ? "reports.php?tab=overdue" : "reports.php" ?>">
                Review Reports
                <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </section>
    <?php else: ?>
        <section class="cs-alert cs-alert-success">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <div class="cs-alert-body">
                <strong class="cs-alert-title">You are all caught up.</strong>
                There are no pending or overdue reports right now.
            </div>
        </section>
    <?php endif; ?>

    <!-- STATISTICS -->
    <section aria-labelledby="statsHeading">
        <h2 id="statsHeading" class="dash-section-title">Report summary</h2>
        <div class="cs-stat-grid dash-stats">
            <?php foreach ($statTiles as [$label, $value, $icon, $tone, $statusID]): ?>
                <a class="cs-stat" href="<?= csEscape($statusID === "all" ? "reports.php?tab=all" : csUrl("reports.php", ["statusID" => $statusID])) ?>">
                    <span class="cs-icon-circle cs-tone-<?= $tone ?>"><i class="bi <?= $icon ?>" aria-hidden="true"></i></span>
                    <span>
                        <span class="cs-stat-value"><?= number_format($value) ?></span>
                        <span class="cs-stat-label"><?= csEscape($label) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="dash-grid">

        <!-- LATEST REPORTS -->
        <div class="cs-card dash-latest">
            <div class="cs-card-header">
                <div>
                    <h2>Latest reports</h2>
                    <p>The 5 most recent reports from residents.</p>
                </div>
                <a class="cs-btn cs-btn-ghost cs-btn-sm" href="reports.php">View All Reports</a>
            </div>

            <?php if ($latestReports): ?>
                <ul class="dash-report-list">
                    <?php foreach ($latestReports as $report): ?>
                        <li>
                            <a href="<?= csEscape(csUrl("reports.php", ["search" => $report["reportID"]])) ?>">
                                <span class="cs-icon-circle"><i class="bi <?= csCategoryIcon($report["categoryName"]) ?>" aria-hidden="true"></i></span>
                                <span class="dash-report-text">
                                    <strong><?= csEscape($report["categoryName"]) ?></strong>
                                    <span><?= csEscape($report["address"] ?: "Location not provided") ?></span>
                                    <small><?= csEscape(csTimeAgo($report["dateReported"])) ?> · ID <?= csEscape($report["reportID"]) ?></small>
                                </span>
                                <?= csStatusBadge($report["statusName"]) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="cs-empty">
                    <span class="cs-empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span>
                    <h3>No reports yet</h3>
                    <p>Reports from residents will appear here.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="dash-side">
            <!-- REPORTS BY CATEGORY -->
            <div class="cs-card">
                <div class="cs-card-header">
                    <h2>Reports by type</h2>
                </div>
                <div class="cs-card-body">
                    <?php if ($categories): ?>
                        <ul class="dash-category-list">
                            <?php foreach ($categories as $category): ?>
                                <?php
                                $count = (int) $category["reportCount"];
                                $percentage = $totalReports > 0 ? round($count / $totalReports * 100) : 0;
                                ?>
                                <li>
                                    <div class="dash-category-row">
                                        <span><i class="bi <?= csCategoryIcon($category["categoryName"]) ?>" aria-hidden="true"></i> <?= csEscape($category["categoryName"]) ?></span>
                                        <strong><?= number_format($count) ?> <small>(<?= $percentage ?>%)</small></strong>
                                    </div>
                                    <div class="dash-bar" role="presentation"><span style="width: <?= $percentage ?>%"></span></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="cs-muted">No categories available.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- HEATMAP PREVIEW -->
            <div class="cs-card">
                <div class="cs-card-header">
                    <div>
                        <h2>Issue map</h2>
                        <p>Where reports are coming from.</p>
                    </div>
                    <a class="cs-btn cs-btn-ghost cs-btn-sm" href="heatmap.php">Open Full Map</a>
                </div>
                <div class="dash-map-wrap">
                    <div id="reportHeatmap" class="dash-map" role="img" aria-label="Map showing where reports were submitted"></div>
                    <div class="dash-map-legend" aria-hidden="true">
                        <span>Few reports</span>
                        <span class="dash-map-gradient"></span>
                        <span>Many reports</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<?php include __DIR__ . "/partial/scripts.php"; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script>
(function () {
    const mapElement = document.getElementById("reportHeatmap");
    const heatmapReports = <?= json_encode($heatmapData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    if (typeof L === "undefined") {
        mapElement.classList.add("is-unavailable");
        mapElement.textContent = "The map could not load. Please check your internet connection.";
        return;
    }

    let mapCenter = [10.2923404, 123.8890404];
    if (heatmapReports.length > 0) {
        mapCenter = [heatmapReports[0].latitude, heatmapReports[0].longitude];
    }

    const map = L.map(mapElement, {
        zoomControl: true,
        scrollWheelZoom: true,
        touchZoom: true,
        wheelPxPerZoomLevel: 120
    }).setView(mapCenter, 15);

    // Zooming over the map (mouse wheel, trackpad pinch, touch pinch)
    // zooms only the map, never the whole page.
    CitiSense.lockPageZoom(mapElement);


    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap contributors"
    }).addTo(map);

    // One database record = one submission; group by location.
    const locationGroups = {};
    heatmapReports.forEach((report) => {
        const latitude = Number(report.latitude);
        const longitude = Number(report.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

        const key = String(report.locationID);
        if (!locationGroups[key]) locationGroups[key] = { latitude, longitude, count: 0 };
        locationGroups[key].count++;
    });

    const heatPoints = Object.values(locationGroups)
        .map((location) => [location.latitude, location.longitude, Math.min(location.count, 4)]);

    if (heatPoints.length > 0 && typeof L.heatLayer === "function") {
        L.heatLayer(heatPoints, {
            radius: 50,
            blur: 20,
            maxZoom: 10,
            max: 4,
            minOpacity: 0.65,
            gradient: { 0.0: "#00c853", 0.25: "#ffff00", 0.5: "#ff9800", 0.75: "#ff5722", 1.0: "#ff0000" }
        }).addTo(map);
    }

    setTimeout(() => {
        map.invalidateSize();
        if (heatPoints.length > 1) {
            map.fitBounds(L.latLngBounds(heatPoints.map((p) => [p[0], p[1]])), { padding: [30, 30], maxZoom: 15 });
        } else if (heatPoints.length === 1) {
            map.setView([heatPoints[0][0], heatPoints[0][1]], 15);
        }
    }, 150);
})();
</script>

</body>
</html>
