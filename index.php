<?php
/*
 * CitiSense — root entry point.
 *
 * Opening the project folder (e.g. http://localhost/CITISENSE/) sends the
 * visitor to the right place:
 *   - logged-in administrators  -> admin/dashboard.php
 *   - everyone else             -> admin/login.php
 *
 * Only api/auth/login.php sets $_SESSION["admin_id"], and only after it has
 * verified the password and the "admin" role, so this check cannot be used
 * to skip authentication. Every admin page still performs its own session
 * check. Neither destination redirects back here, so there is no loop.
 */

/*
 * Hosting (e.g. Railway) sends every address that is not a real file to this
 * script. Only the site's own front page redirects; anything else (such as a
 * missing photo under uploads/) gets "404 Not Found". Otherwise a relative
 * redirect would be added to the missing address again and again
 * (/uploads/reports/admin/admin/.../dashboard.php).
 */
$base = rtrim(str_replace("\\", "/", dirname((string) ($_SERVER["SCRIPT_NAME"] ?? "/index.php"))), "/");
$path = (string) parse_url((string) ($_SERVER["REQUEST_URI"] ?? "/"), PHP_URL_PATH);

if (!in_array($path, [$base, $base . "/", $base . "/index.php"], true)) {
    http_response_code(404);
    header("Content-Type: text/plain; charset=UTF-8");
    exit("Not found.");
}

session_start();

$destination = !empty($_SESSION["admin_id"])
    ? "/admin/dashboard.php"
    : "/admin/login.php";

// Built from the site's base path, so it always points to the same place.
header("Location: " . $base . $destination);
exit;
