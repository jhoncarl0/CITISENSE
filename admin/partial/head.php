<?php
/*
 * CitiSense admin — shared document <head>.
 *
 * Set before including:
 *   $pageTitle   string   e.g. "Reports"
 *   $pageStyles  string[] page stylesheets in ../css/ (optional)
 *   $pageHead    string   extra raw tags, e.g. library CSS (optional)
 */

require_once __DIR__ . "/helpers.php";

$pageTitle = $pageTitle ?? "Admin";
$pageStyles = $pageStyles ?? [];
$pageHead = $pageHead ?? "";
$csAssetVersion = "2.1";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= csEscape($pageTitle) ?> · CitiSense Admin</title>

    <link rel="icon" type="image/png" href="../css/images/favicon.png">

    <!-- Roboto: the same typeface as the CitiSense mobile app -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <?= $pageHead ?>

    <link rel="stylesheet" href="../css/citisense.css?v=<?= $csAssetVersion ?>">
    <?php foreach ($pageStyles as $style): ?>
        <link rel="stylesheet" href="../css/<?= csEscape($style) ?>?v=<?= $csAssetVersion ?>">
    <?php endforeach; ?>
</head>
