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

session_start();

$destination = !empty($_SESSION["admin_id"])
    ? "admin/dashboard.php"
    : "admin/login.php";

header("Location: " . $destination);
exit;
