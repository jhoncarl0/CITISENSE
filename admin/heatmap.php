<?php

session_start();


// ==========================================
// ADMIN SESSION CHECK
// ==========================================

if (!isset($_SESSION["admin_id"], $_SESSION["barangay_id"])) {

    header("Location: login.php");
    exit;

}


// ==========================================
// DATABASE CONNECTION
// ==========================================

require_once __DIR__ . "/../config/database.php";


// ==========================================
// GET LOGGED-IN ADMIN BARANGAY
// ==========================================

$barangayID = $_SESSION["barangay_id"];


// ==========================================
// GET BARANGAY NAME
// ==========================================

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


// ==========================================
// GET REPORT LOCATIONS
// ==========================================
//
// Each resident report is connected to:
//
// reports
//      ↓
// locationID
//      ↓
// location
//      ↓
// latitude / longitude
//
// Every report becomes a heatmap point.
//
// There are NO MAP PINS.
// ==========================================

$reports = [];

$sql = "
    SELECT
        r.reportID,
        r.categoryID,
        c.categoryName,
        r.statusID,
        s.statusName,
        r.locationID,
        l.latitude,
        l.longitude,
        l.address,
        r.dateReported

    FROM reports r

    INNER JOIN location l
        ON r.locationID = l.locationID

    INNER JOIN categories c
        ON r.categoryID = c.categoryID

    INNER JOIN stats s
        ON r.statusID = s.statusID

    WHERE r.barangayID = ?

    ORDER BY r.dateReported DESC
";

$stmt = $conn->prepare($sql);

$stmt->bind_param("s", $barangayID);

$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {

    if (
        $row["latitude"] === null ||
        $row["longitude"] === null
    ) {
        continue;
    }

    $reports[] = [

        "reportID" => $row["reportID"],

        "categoryID" => $row["categoryID"],

        "categoryName" => $row["categoryName"],

        "statusID" => $row["statusID"],

        "statusName" => $row["statusName"],

        "locationID" => $row["locationID"],

        "latitude" => (float) $row["latitude"],

        "longitude" => (float) $row["longitude"],

        "address" => $row["address"],

        "dateReported" => $row["dateReported"]

    ];

}

$stmt->close();


// ==========================================
// CONVERT REPORT DATA TO JSON
// ==========================================

$reportsJSON = json_encode(
    $reports,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);


// ==========================================
// PLACES WITH THE MOST REPORTS (text summary of the map)
// ==========================================

$placeCounts = [];

foreach ($reports as $report) {
    $key = (string) $report["locationID"];

    if (!isset($placeCounts[$key])) {
        $placeCounts[$key] = [
            "address" => $report["address"] ?: "Unnamed location",
            "total" => 0,
            "open" => 0,
        ];
    }

    $placeCounts[$key]["total"]++;

    if ($report["statusID"] !== "STAT003") {
        $placeCounts[$key]["open"]++;
    }
}

uasort($placeCounts, static fn($a, $b) => $b["total"] <=> $a["total"]);
$topPlaces = array_slice($placeCounts, 0, 6, true);

require_once __DIR__ . "/partial/helpers.php";

$pageTitle = "Issue Map";
$pageStyles = ["heatmap.css"];
$pageHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">';
include __DIR__ . "/partial/head.php";
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">

    <header class="cs-page-header">
        <div>
            <h1>Issue Map</h1>
            <p>See which parts of the barangay have the most reports. Red areas have many reports; green areas have few.</p>
            <span class="cs-location-chip">
                <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                <?= csEscape($barangayName) ?>
            </span>
        </div>
        <div class="cs-page-actions">
            <a class="cs-btn cs-btn-secondary" href="reports.php">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i>
                Go to Reports
            </a>
        </div>
    </header>

    <section class="heatmap-layout">
        <div class="cs-card heatmap-card">
            <div class="cs-card-header">
                <div>
                    <h2>Report heatmap</h2>
                    <p>Based on <?= number_format(count($reports)) ?> <?= count($reports) === 1 ? "report" : "reports" ?> with a map location. To zoom, use the <strong>+</strong> and <strong>−</strong> buttons, scroll on the map, or pinch with two fingers.</p>
                </div>
            </div>

            <div class="heatmap-map-wrap">
                <div id="heatmapMap" class="heatmap-map" role="img" aria-label="Heatmap of report locations. A list of places is shown beside the map."></div>
            </div>

            <div class="heatmap-legend">
                <span class="cs-label">How many reports</span>
                <ul>
                    <li><span class="legend-swatch very-low"></span>Very few</li>
                    <li><span class="legend-swatch low"></span>Few</li>
                    <li><span class="legend-swatch moderate"></span>Some</li>
                    <li><span class="legend-swatch high"></span>Many</li>
                </ul>
            </div>
        </div>

        <aside class="cs-card heatmap-places" aria-labelledby="placesHeading">
            <div class="cs-card-header">
                <div>
                    <h2 id="placesHeading">Places with the most reports</h2>
                    <p>Open = not yet resolved.</p>
                </div>
            </div>

            <?php if ($topPlaces): ?>
                <ol class="heatmap-place-list">
                    <?php $rank = 0; foreach ($topPlaces as $place): $rank++; ?>
                        <li>
                            <span class="heatmap-rank"><?= $rank ?></span>
                            <span class="heatmap-place-text">
                                <strong><?= csEscape($place["address"]) ?></strong>
                                <span>
                                    <?= $place["total"] ?> <?= $place["total"] === 1 ? "report" : "reports" ?>
                                    <?php if ($place["open"] > 0): ?>
                                        · <span class="heatmap-open"><?= $place["open"] ?> open</span>
                                    <?php else: ?>
                                        · all resolved
                                    <?php endif; ?>
                                </span>
                            </span>
                            <a class="cs-btn cs-btn-ghost cs-btn-sm" href="<?= csEscape(csUrl("reports.php", ["search" => $place["address"]])) ?>" aria-label="View reports at <?= csEscape($place["address"]) ?>">View</a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <div class="cs-empty">
                    <span class="cs-empty-icon"><i class="bi bi-map" aria-hidden="true"></i></span>
                    <h3>No locations yet</h3>
                    <p>Reports with a map location will appear here.</p>
                </div>
            <?php endif; ?>
        </aside>
    </section>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<?php include __DIR__ . "/partial/scripts.php"; ?>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script>
(function () {
    const reports = <?= $reportsJSON ?: "[]" ?>;
    const mapElement = document.getElementById("heatmapMap");

    if (typeof L === "undefined") {
        mapElement.classList.add("is-unavailable");
        mapElement.textContent = "The map could not load. Please check your internet connection. The list of places still shows where reports come from.";
        return;
    }

    // Default view: Cebu City, until report locations are known.
    const map = L.map(mapElement, {
        scrollWheelZoom: true,
        touchZoom: true,
        wheelPxPerZoomLevel: 120
    }).setView([10.2923404, 123.8890404], 14);

    // Zooming over the map (mouse wheel, trackpad pinch, touch pinch)
    // zooms only the map, never the whole page.
    CitiSense.lockPageZoom(mapElement);


    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    // Reports sharing a locationID are submissions for the same place; the
    // heat intensity is the number of submissions (capped at 4).
    const locationGroups = {};
    reports.forEach((report) => {
        const latitude = Number(report.latitude);
        const longitude = Number(report.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

        if (!locationGroups[report.locationID]) {
            locationGroups[report.locationID] = { latitude, longitude, count: 0 };
        }
        locationGroups[report.locationID].count++;
    });

    const heatPoints = Object.values(locationGroups)
        .map((location) => [location.latitude, location.longitude, Math.min(location.count, 4)]);

    if (typeof L.heatLayer === "function") {
        L.heatLayer(heatPoints, {
            radius: 40,
            blur: 30,
            maxZoom: 17,
            minOpacity: 0.35,
            max: 4,
            gradient: {
                0.0: "#00c853",   // Very few
                0.25: "#ffff00",  // Few
                0.5: "#ff9800",   // Some
                0.75: "#ff5722",
                1.0: "#ff0000"    // Many
            }
        }).addTo(map);
    }

    if (heatPoints.length > 0) {
        map.fitBounds(L.latLngBounds(heatPoints.map((p) => [p[0], p[1]])).pad(0.15));
    }
})();
</script>

</body>
</html>
