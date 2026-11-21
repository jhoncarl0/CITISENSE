<?php
/*
 * CitiSense — record an administrator's decision about possible duplicates.
 *
 * POST JSON:
 *   { "action": "not_duplicate", "reportID": "REP018", "otherIDs": ["REP020", "REP021"] }
 *       Remembers that REP018 is NOT the same problem as the listed reports,
 *       so these pairs are no longer suggested.
 *   { "action": "duplicate", "reportID": "REP024", "otherIDs": ["REP013"] }
 *       Logs that REP024 was closed as a duplicate of REP013 (the report
 *       itself is rejected separately with reject_report.php /
 *       update_report_status.php).
 *
 * Only reports of the administrator's own barangay are accepted.
 */

session_start();

header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function reviewResponse(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    reviewResponse(405, ["success" => false, "message" => "Invalid request method."]);
}

$adminID = trim((string) ($_SESSION["admin_id"] ?? ""));
$barangayID = trim((string) ($_SESSION["barangay_id"] ?? ""));

if ($adminID === "" || $barangayID === "") {
    reviewResponse(401, ["success" => false, "message" => "Your session has expired. Please log in again."]);
}

$input = json_decode(file_get_contents("php://input"), true);

$action = (string) ($input["action"] ?? "");
$reportID = trim((string) ($input["reportID"] ?? ""));
$otherIDs = array_values(array_unique(array_filter(
    array_map(static fn($id) => trim((string) $id), (array) ($input["otherIDs"] ?? [])),
    static fn($id) => $id !== "" && strlen($id) <= 50
)));

if (!in_array($action, ["not_duplicate", "duplicate"], true) || $reportID === "" || !$otherIDs || count($otherIDs) > 50) {
    reviewResponse(400, ["success" => false, "message" => "Invalid request data."]);
}

try {
    require_once __DIR__ . "/../config/database.php";
    require_once __DIR__ . "/partial/schema.php";
    require_once __DIR__ . "/partial/duplicates.php";

    if (!csEnsureSchema($conn)) {
        reviewResponse(500, ["success" => false, "message" => "The database update is needed before decisions can be saved."]);
    }

    /* Every report must belong to this barangay. */
    $ids = array_merge([$reportID], $otherIDs);
    $marks = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $conn->prepare("SELECT reportID FROM reports WHERE barangayID = ? AND reportID IN ($marks)");
    $types = "s" . str_repeat("s", count($ids));
    $params = [$barangayID, ...$ids];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $found = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), "reportID");
    $stmt->close();

    if (count(array_unique($found)) !== count($ids)) {
        reviewResponse(404, ["success" => false, "message" => "One of the reports was not found in your barangay."]);
    }

    $now = date("Y-m-d H:i:s");
    $conn->begin_transaction();

    foreach ($otherIDs as $otherID) {
        $key = csPairKey($reportID, $otherID);
        [$a, $b] = explode("|", $key);

        $stmt = $conn->prepare("DELETE FROM duplicate_reviews WHERE pairKey = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO duplicate_reviews (pairKey, barangayID, reportA, reportB, decision, decidedBy, decidedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sssssss", $key, $barangayID, $a, $b, $action, $adminID, $now);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();

    reviewResponse(200, [
        "success" => true,
        "message" => $action === "not_duplicate"
            ? "Got it. Report " . $reportID . " will no longer be suggested as a duplicate of " . implode(", ", $otherIDs) . "."
            : "Decision saved.",
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }
    error_log("Duplicate review failed: " . $error->getMessage());
    reviewResponse(500, ["success" => false, "message" => "The decision could not be saved. Please try again."]);
}
