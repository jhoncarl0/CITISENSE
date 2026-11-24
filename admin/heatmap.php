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
// GROUP NEARBY PLACES (within 60 metres)
// ==========================================
//
// Report locations within 60 m of each other are shown as ONE pin with
// the total number of reports and the count per category. A place joins
// a group when it is within 60 m of ANY place already in that group.
// Only the display is grouped: every report keeps its own record and
// coordinates in the database. Recalculated on every page load, so new
// or changed reports are always included, each counted exactly once.
// ==========================================

const HEATMAP_GROUP_METERS = 60;

function heatmapDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

/* One entry per saved location. */
$places = [];
foreach ($reports as $report) {
    $key = (string) $report["locationID"];
    if (!isset($places[$key])) {
        $places[$key] = [
            "address" => $report["address"] ?: "Unnamed location",
            "lat" => (float) $report["latitude"],
            "lng" => (float) $report["longitude"],
            "total" => 0,
            "open" => 0,
            "categories" => [],
        ];
    }
    $places[$key]["total"]++;
    if ($report["statusID"] !== "STAT003") {
        $places[$key]["open"]++;
    }
    $category = (string) ($report["categoryName"] ?: "Other");
    $places[$key]["categories"][$category] = ($places[$key]["categories"][$category] ?? 0) + 1;
}

/* Union-find: places within 60 m end up in the same group. */
$placeKeys = array_keys($places);
$parent = array_combine($placeKeys, $placeKeys) ?: [];
$findRoot = static function (string $key) use (&$parent): string {
    while ($parent[$key] !== $key) {
        $parent[$key] = $parent[$parent[$key]];
        $key = $parent[$key];
    }
    return $key;
};
$placeCount = count($placeKeys);
for ($i = 0; $i < $placeCount; $i++) {
    for ($j = $i + 1; $j < $placeCount; $j++) {
        $a = $places[$placeKeys[$i]];
        $b = $places[$placeKeys[$j]];
        if (heatmapDistanceMeters($a["lat"], $a["lng"], $b["lat"], $b["lng"]) <= HEATMAP_GROUP_METERS) {
            $parent[$findRoot($placeKeys[$i])] = $findRoot($placeKeys[$j]);
        }
    }
}

$groups = [];
foreach ($places as $key => $place) {
    $root = $findRoot((string) $key);
    if (!isset($groups[$root])) {
        $groups[$root] = ["places" => [], "total" => 0, "open" => 0, "categories" => [], "latSum" => 0.0, "lngSum" => 0.0];
    }
    $group = &$groups[$root];
    $group["places"][] = $place;
    $group["total"] += $place["total"];
    $group["open"] += $place["open"];
    $group["latSum"] += $place["lat"] * $place["total"];
    $group["lngSum"] += $place["lng"] * $place["total"];
    foreach ($place["categories"] as $category => $count) {
        $group["categories"][$category] = ($group["categories"][$category] ?? 0) + $count;
    }
    unset($group);
}

$mapGroups = [];
foreach ($groups as $group) {
    // Named after its place with the most reports.
    usort($group["places"], static fn($x, $y) => $y["total"] <=> $x["total"]);
    arsort($group["categories"]);
    $mapGroups[] = [
        "address" => $group["places"][0]["address"],
        "nearby" => count($group["places"]) - 1,
        "total" => $group["total"],
        "open" => $group["open"],
        "categories" => $group["categories"],
        "lat" => $group["latSum"] / max(1, $group["total"]),
        "lng" => $group["lngSum"] / max(1, $group["total"]),
    ];
}
usort($mapGroups, static fn($x, $y) => $y["total"] <=> $x["total"]);

$groupsJSON = json_encode(
    $mapGroups,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);

// ==========================================
// PLACES WITH THE MOST REPORTS (text summary of the map, same groups as the pins)
// ==========================================

$topPlaces = array_slice($mapGroups, 0, 6);

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
                                <?php if ($place["nearby"] > 0): ?>
                                    <span class="heatmap-nearby">and <?= $place["nearby"] ?> nearby <?= $place["nearby"] === 1 ? "place" : "places" ?> (within 60 m)</span>
                                <?php endif; ?>
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
            locationGroups[report.locationID] = {
                latitude, longitude, count: 0,
                address: report.address || "",
                categories: {}
            };
        }
        const group = locationGroups[report.locationID];
        group.count++;
        const category = report.categoryName || "Other";
        group.categories[category] = (group.categories[category] || 0) + 1;
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

    /* One pin per group of places within 60 m (grouped on the server, see
       HEATMAP_GROUP_METERS). The pin shows the total number of reports; hover
       or tap it for the count per category. Text is set with textContent, so
       addresses and category names are never treated as HTML. */
    const groups = <?= $groupsJSON ?: "[]" ?>;

    function groupTooltip(group) {
        const box = document.createElement("div");
        box.className = "hm-tip";

        const title = document.createElement("strong");
        title.className = "hm-tip-title";
        title.textContent = group.address;

        const total = document.createElement("span");
        total.className = "hm-tip-total";
        total.textContent = group.total + (group.total === 1 ? " report" : " reports")
            + (group.nearby > 0 ? " · " + (group.nearby + 1) + " places within 60 m" : "");

        const list = document.createElement("ul");
        Object.entries(group.categories).forEach(([name, count]) => {
            const item = document.createElement("li");
            const label = document.createElement("span");
            label.textContent = name;
            const value = document.createElement("b");
            value.textContent = count;
            item.append(label, value);
            list.appendChild(item);
        });

        box.append(title, total, list);
        return box;
    }

    groups.forEach((group) => {
        // Invisible hover area over the hotspot: nothing is drawn on the map
        // (no number); the tooltip shows the details. Screen readers still
        // get the text.
        const size = 44;
        const label = document.createElement("span");
        label.className = "cs-sr-only";
        label.textContent = group.address + ": " + group.total + (group.total === 1 ? " report" : " reports");
        const icon = L.divIcon({
            className: "hm-hotspot",
            html: label.outerHTML,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2]
        });

        L.marker([group.lat, group.lng], {
            icon,
            keyboard: true
        })
            .bindTooltip(groupTooltip(group), {
                direction: "top",
                offset: [0, -size / 2],
                opacity: 1,
                className: "hm-tip-wrap"
            })
            .on("click", function () { this.openTooltip(); })
            .addTo(map);
    });

    if (heatPoints.length > 0) {
        map.fitBounds(L.latLngBounds(heatPoints.map((p) => [p[0], p[1]])).pad(0.15));
    }
})();
</script>

</body>
</html>
