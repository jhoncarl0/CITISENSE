<?php
/*
 * Scripts for partial/report_drawer.php (include after partial/scripts.php).
 * Pages that already load Leaflet themselves (Issue Map) set
 * $csLeafletLoaded = true first, so Leaflet is never loaded twice.
 */
?>
<?php if (empty($csLeafletLoaded)): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<script src="../js/reports.js?v=<?= $csAssetVersion ?>.2"></script>
