<?php
/*
 * CitiSense admin — serves report/profile photos that the mobile app saved
 * outside this project folder (see csMediaRoots() in partial/helpers.php).
 *
 * Usage: media.php?p=uploads/reports/REP001.jpg
 * Only logged-in admins, only picture files, only inside the search folders.
 */
session_start();

if (empty($_SESSION["admin_id"])) {
    http_response_code(403);
    exit;
}
session_write_close(); // do not block other requests while sending the file

require_once __DIR__ . "/partial/helpers.php";

$allowed = [
    "jpg" => "image/jpeg",
    "jpeg" => "image/jpeg",
    "png" => "image/png",
    "gif" => "image/gif",
    "webp" => "image/webp",
];

$path = csCleanMediaPath($_GET["p"] ?? "");
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$file = ($path !== "" && isset($allowed[$extension])) ? csFindMediaFile($path) : "";

// The file must really be inside one of the search folders.
$real = $file !== "" ? str_replace("\\", "/", (string) realpath($file)) : "";
$inside = false;
foreach (csMediaRoots() as $root) {
    if ($real !== "" && str_starts_with($real, $root)) {
        $inside = true;
        break;
    }
}

if (!$inside) {
    http_response_code(404);
    exit;
}

$mime = $allowed[$extension];
if (class_exists("finfo")) {
    $detected = (new finfo(FILEINFO_MIME_TYPE))->file($real);
    if (!in_array($detected, $allowed, true)) {
        http_response_code(404);
        exit;
    }
    $mime = $detected;
}

$modified = filemtime($real) ?: time();
$etag = '"' . md5($real . "|" . filesize($real) . "|" . $modified) . '"';

header("Content-Type: " . $mime);
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, max-age=86400");
header("ETag: " . $etag);
header("Last-Modified: " . gmdate("D, d M Y H:i:s", $modified) . " GMT");

if (trim((string) ($_SERVER["HTTP_IF_NONE_MATCH"] ?? "")) === $etag) {
    http_response_code(304);
    exit;
}

header("Content-Length: " . filesize($real));
readfile($real);
