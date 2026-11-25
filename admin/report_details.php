<?php
/*
 * CitiSense admin — details of one report as JSON, for the details panel.
 *
 * GET report_details.php?id=REP001
 *   -> { success: true, id: "REP001", reports: { "REP001": {...}, ...related } }
 *
 * Lets every page open a report in place ("View Report") without leaving
 * the page. Only reports of the administrator's own barangay are returned.
 */
session_start();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

function reportDetailsResponse(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION["admin_id"]) || empty($_SESSION["barangay_id"])) {
    reportDetailsResponse(401, ["success" => false, "message" => "Your session has ended. Please log in again."]);
}

$reportID = trim((string) ($_GET["id"] ?? ""));
if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $reportID)) {
    reportDetailsResponse(400, ["success" => false, "message" => "Invalid report ID."]);
}

try {
    require_once __DIR__ . "/../config/database.php";
    require_once __DIR__ . "/partial/schema.php";
    require_once __DIR__ . "/partial/report_details.php";

    $barangayID = (string) $_SESSION["barangay_id"];
    csEnsureSchema($conn);

    $payload = csReportDetailsPayload($conn, $barangayID, [$reportID], csReportContext($conn, $barangayID, true));

    if (!isset($payload[$reportID])) {
        reportDetailsResponse(404, ["success" => false, "message" => "Report " . $reportID . " could not be found."]);
    }

    reportDetailsResponse(200, ["success" => true, "id" => $reportID, "reports" => $payload]);
} catch (Throwable $error) {
    error_log("CitiSense report details failed: " . $error->getMessage());
    reportDetailsResponse(500, ["success" => false, "message" => "The report could not be loaded. Please try again."]);
}
