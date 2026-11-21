<?php
/*
 * Opening /admin/ directly shows the dashboard (or the login page when
 * not logged in) instead of a directory listing.
 */

session_start();

header("Location: " . (!empty($_SESSION["admin_id"]) ? "dashboard.php" : "login.php"));
exit;
