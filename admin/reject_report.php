<?php
/*
 * CitiSense — Reject a report.
 *
 * POST JSON: { "reportID": "REP001", "reason": "..." }
 *
 * Rules:
 *   - Only an administrator of the report's barangay may reject it.
 *   - Only Pending reports can be rejected, one report at a time.
 *   - A reason is required. It is saved in reporthistory.remarks and sent
 *     to the resident in a notification.
 *   - Rejected reports are left out of the Original/Duplicate cycles, so if
 *     the first report of an issue is rejected, the next report of that
 *     issue becomes the Original.
 *
 * The "Rejected" status is looked up by name in the stats table. If the
 * table has no such row yet, one is added (statusID STAT005, or the next
 * free STATnnn) — this adds a row of reference data, not a schema change.
 */

session_start();

header("Content-Type: application/json; charset=UTF-8");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function rejectResponse(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    rejectResponse(405, ["success" => false, "message" => "Invalid request method."]);
}

$adminID = trim((string) ($_SESSION["admin_id"] ?? ""));
$barangayID = trim((string) ($_SESSION["barangay_id"] ?? ""));

if ($adminID === "" || $barangayID === "") {
    rejectResponse(401, ["success" => false, "message" => "Your session has expired. Please log in again."]);
}

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    rejectResponse(400, ["success" => false, "message" => "Invalid request data."]);
}

$reportID = trim((string) ($input["reportID"] ?? ""));
$reason = trim((string) ($input["reason"] ?? ""));

if ($reportID === "" || strlen($reportID) > 50) {
    rejectResponse(400, ["success" => false, "message" => "Invalid report."]);
}

if (mb_strlen($reason) < 5) {
    rejectResponse(400, ["success" => false, "message" => "Please write a short reason so the resident knows why."]);
}

if (mb_strlen($reason) > 1000) {
    rejectResponse(400, ["success" => false, "message" => "The reason is too long. Please keep it under 1,000 characters."]);
}

$transactionStarted = false;

try {
    require_once __DIR__ . "/../config/database.php";

    /* The session must belong to an admin of this barangay. */
    $stmt = $conn->prepare(
        "SELECT userID FROM users
         WHERE userID = ? AND barangayID = ? AND LOWER(role) = 'admin'
         LIMIT 1"
    );
    $stmt->bind_param("ss", $adminID, $barangayID);
    $stmt->execute();
    $authorized = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$authorized) {
        rejectResponse(403, ["success" => false, "message" => "Administrator access denied."]);
    }

    $conn->begin_transaction();
    $transactionStarted = true;

    $stmt = $conn->prepare(
        "SELECT reportID, userID, statusID
         FROM reports
         WHERE reportID = ? AND barangayID = ?
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param("ss", $reportID, $barangayID);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$report) {
        throw new RuntimeException("Report not found in your barangay.");
    }

    if ((string) $report["statusID"] !== "STAT001") {
        throw new RuntimeException("Only Pending reports can be rejected.");
    }

    /* Find (or add) the Rejected status. */
    require_once __DIR__ . "/partial/schema.php";
    require_once __DIR__ . "/partial/duplicate_links.php";
    csEnsureSchema($conn);
    $rejectedID = csRejectedStatusID($conn);

    /* A linked duplicate always has its main report's status. */
    $linkedMainID = csLinkedMainOf($conn, $reportID);
    if ($linkedMainID !== "") {
        $conn->rollback();
        $transactionStarted = false;
        rejectResponse(409, [
            "success" => false,
            "message" => "Report " . $reportID . " is a duplicate of report " . $linkedMainID
                . " and always has the same status. Change report " . $linkedMainID . " instead.",
        ]);
    }

    $stmt = $conn->prepare(
        "UPDATE reports SET statusID = ? WHERE reportID = ? AND barangayID = ? AND statusID = 'STAT001'"
    );
    $stmt->bind_param("sss", $rejectedID, $reportID, $barangayID);
    $stmt->execute();
    $stmt->close();

    $historyID = "H" . bin2hex(random_bytes(16));
    $dateChanged = csDbNow();

    $stmt = $conn->prepare(
        "INSERT INTO reporthistory (historyID, reportID, userID, statusID, remarks, dateChanged)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssssss", $historyID, $reportID, $adminID, $rejectedID, $reason, $dateChanged);
    $stmt->execute();
    $stmt->close();

    $notifID = "N" . bin2hex(random_bytes(16));
    $residentID = (string) $report["userID"];
    $message = "Your report " . $reportID . " was not accepted. Reason: " . $reason;

    $stmt = $conn->prepare(
        "INSERT INTO notification (notifID, userID, reportID, message, isRead)
         VALUES (?, ?, ?, ?, 0)"
    );
    $stmt->bind_param("ssss", $notifID, $residentID, $reportID, $message);
    $stmt->execute();
    $stmt->close();

    // Its linked duplicates are rejected together with it.
    $linkedUpdated = csSyncLinkedDuplicates($conn, $barangayID, $reportID, $adminID, $reason);

    $conn->commit();
    $transactionStarted = false;

    rejectResponse(200, [
        "success" => true,
        "message" => "Report " . $reportID . " was rejected. The resident has been notified."
            . ($linkedUpdated ? " " . count($linkedUpdated) . " linked duplicate report(s) were rejected with it." : ""),
        "reportID" => $reportID,
        "statusID" => $rejectedID,
    ]);

} catch (Throwable $error) {
    if ($transactionStarted && isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log("Reject rollback failed: " . $rollbackError->getMessage());
        }
    }

    error_log("Report reject failed: " . $error->getMessage());

    $clientErrors = [
        "Report not found in your barangay.",
        "Only Pending reports can be rejected.",
    ];
    $isClientError = in_array($error->getMessage(), $clientErrors, true);

    rejectResponse($isClientError ? 409 : 500, [
        "success" => false,
        "message" => $isClientError
            ? $error->getMessage()
            : "Unable to reject the report. Please try again.",
    ]);
}
