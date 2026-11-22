<?php
/*
 * CitiSense admin — Database Check (admins only).
 *
 * Runs every query used by the November 2026 features against the real
 * database and shows the exact error message for anything that fails, so a
 * problem can be fixed quickly. It only creates the new tables (the same as
 * the normal pages do) and changes no data.
 *
 * Open: http://localhost/CITISENSE/admin/db_check.php (log in first)
 */
session_start();

if (empty($_SESSION["admin_id"]) || empty($_SESSION["barangay_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/partial/helpers.php";
require_once __DIR__ . "/partial/schema.php";
require_once __DIR__ . "/partial/uploads.php";
require_once __DIR__ . "/partial/duplicates.php";
require_once __DIR__ . "/partial/messages.php";
require_once __DIR__ . "/partial/resident_messages.php";

$barangayID = (string) $_SESSION["barangay_id"];
$checks = [];

/** Run one check; $fn returns a short detail text or throws. */
function dbCheck(array &$checks, string $label, callable $fn): void
{
    try {
        $detail = $fn();
        $checks[] = ["ok" => true, "label" => $label, "detail" => (string) $detail];
    } catch (Throwable $error) {
        $checks[] = ["ok" => false, "label" => $label, "detail" => $error->getMessage()];
    }
}

/* --- Server ------------------------------------------------------------ */
dbCheck($checks, "PHP version", function () {
    return PHP_VERSION;
});

dbCheck($checks, "Database server", function () use ($conn) {
    $row = $conn->query("SELECT VERSION() AS v, DATABASE() AS d, @@collation_connection AS c")->fetch_assoc();
    return $row["v"] . " · database `" . $row["d"] . "` · connection collation " . $row["c"];
});

dbCheck($checks, "GD picture library (resizing pictures, photo comparison)", function () {
    if (!function_exists("imagecreatefromjpeg") || !function_exists("imagecreatefrompng")) {
        throw new RuntimeException("GD is not enabled. In XAMPP, open php.ini, remove the ; before extension=gd, then restart Apache.");
    }
    return "enabled";
});

dbCheck($checks, "fileinfo (checking uploaded files)", function () {
    if (!class_exists("finfo")) {
        throw new RuntimeException("fileinfo is not enabled. In php.ini, remove the ; before extension=fileinfo, then restart Apache.");
    }
    return "enabled";
});

/* --- New tables -------------------------------------------------------- */
foreach (csSchemaStatements() as $sql) {
    preg_match('/`(\w+)`/', $sql, $match);
    $table = $match[1] ?? "table";
    dbCheck($checks, "Create table `$table` (if missing)", function () use ($conn, $sql) {
        $conn->query($sql);
        return "ready";
    });
}

dbCheck($checks, "\"Rejected\" status in `stats`", function () use ($conn) {
    return "statusID " . csRejectedStatusID($conn);
});

dbCheck($checks, "Table collations", function () use ($conn) {
    $result = $conn->query(
        "SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('reports','users','announcements','report_messages','announcement_comments',
                              'announcement_images','message_reads','resident_messages','duplicate_reviews','report_image_hashes')
         ORDER BY TABLE_NAME"
    );
    $parts = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $parts[] = $row["TABLE_NAME"] . ": " . $row["TABLE_COLLATION"];
    }
    return implode(" · ", $parts);
});

/* --- Feature queries --------------------------------------------------- */
dbCheck($checks, "Messages: list of conversations (one per resident)", function () use ($conn, $barangayID) {
    $threads = csResidentThreads($conn, $barangayID);
    return count($threads) . " conversation(s)";
});

dbCheck($checks, "Messages: unread count (sidebar badge)", function () use ($conn, $barangayID) {
    return csUnreadResidentCount($conn, $barangayID) . " resident(s) with new messages";
});

dbCheck($checks, "Messages: open a resident conversation", function () use ($conn, $barangayID) {
    $threads = csResidentThreads($conn, $barangayID);
    if (!$threads) {
        return "no conversations yet";
    }
    $conversation = csLoadResidentConversation($conn, $barangayID, $threads[0]["userID"]);
    return $threads[0]["name"] . " · " . count($conversation["messages"] ?? []) . " message(s), "
        . count($conversation["reports"] ?? []) . " report(s)";
});

dbCheck($checks, "Messages: open an announcement conversation", function () use ($conn, $barangayID) {
    $stmt = $conn->prepare("SELECT announceID FROM announcements WHERE barangayID = ? ORDER BY datePosted DESC LIMIT 1");
    $stmt->bind_param("s", $barangayID);
    $stmt->execute();
    $id = (string) ($stmt->get_result()->fetch_assoc()["announceID"] ?? "");
    $stmt->close();
    if ($id === "") {
        return "no announcements yet";
    }
    $thread = csLoadThread($conn, $barangayID, "announcement", $id);
    return "announcement $id · " . count($thread["messages"] ?? []) . " comment(s)";
});

dbCheck($checks, "Announcements: comment counts", function () use ($conn, $barangayID) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM announcement_comments c
         INNER JOIN announcements a ON a.announceID = c.announceID
         LEFT JOIN message_reads mr
            ON mr.threadType = 'announcement'
           AND mr.threadID COLLATE utf8mb4_unicode_ci = CAST(c.announceID AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
           AND mr.barangayID = ?
         WHERE a.barangayID = ?"
    );
    $stmt->bind_param("ss", $barangayID, $barangayID);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()["total"];
    $stmt->close();
    return "$total comment(s)";
});

dbCheck($checks, "Announcements: pictures", function () use ($conn) {
    $total = (int) $conn->query("SELECT COUNT(*) AS n FROM announcement_images")->fetch_assoc()["n"];
    return "$total picture(s) saved";
});

dbCheck($checks, "Announcements: picture folder can be written", function () {
    $dir = csUploadDir("announcements");
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create $dir. Create the folder and allow the web server to write to it.");
    }
    $probe = $dir . ".write-test-" . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, "ok") === false) {
        throw new RuntimeException("Cannot write to $dir. Allow the web server to write to this folder.");
    }
    @unlink($probe);
    return $dir;
});

dbCheck($checks, "Report photos (files on this computer)", function () use ($conn, $barangayID) {
    $stmt = $conn->prepare(
        "SELECT ri.imagePath FROM reportimages ri INNER JOIN reports r ON r.reportID = ri.reportID WHERE r.barangayID = ?"
    );
    $stmt->bind_param("s", $barangayID);
    $stmt->execute();
    $paths = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), "imagePath");
    $stmt->close();
    $where = [];
    $missing = [];
    foreach ($paths as $path) {
        $file = csFindMediaFile((string) $path);
        if ($file === "") {
            $missing[] = (string) $path;
        } else {
            $folder = substr($file, 0, -strlen(csCleanMediaPath($path)));
            $where[$folder] = ($where[$folder] ?? 0) + 1;
        }
    }
    if (!$paths) {
        return "no report photos yet";
    }
    if ($missing) {
        throw new RuntimeException(
            count($missing) . " of " . count($paths) . " photo file(s) were not found on this computer (e.g. "
            . implode(", ", array_slice($missing, 0, 3)) . "). Searched: " . implode(" · ", csMediaRoots())
            . ". Copy the mobile API's uploads folder into one of these folders, add its folder to CS_MEDIA_ROOTS in"
            . " admin/partial/helpers.php, or set CS_MEDIA_BASE_URL if the photos are on another server."
        );
    }
    $parts = [];
    foreach ($where as $folder => $count) {
        $parts[] = "$count in $folder";
    }
    return "all " . count($paths) . " found: " . implode(" · ", $parts);
});

dbCheck($checks, "Possible duplicates", function () use ($conn, $barangayID) {
    $groups = csGroupDuplicatePairs(csFindDuplicatePairs($conn, $barangayID, true));
    return count($groups) . " group(s) to review";
});

$failed = count(array_filter($checks, static fn($c) => !$c["ok"]));

$pageTitle = "Database Check";
include __DIR__ . "/partial/head.php";
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">
        <header class="cs-page-header">
            <div>
                <h1>Database Check</h1>
                <p>Tests the new features against your database. No data is changed.</p>
            </div>
        </header>

        <?php if ($failed === 0): ?>
            <div class="cs-alert cs-alert-success" role="status">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                <div class="cs-alert-body"><strong class="cs-alert-title">Everything works</strong>All <?= count($checks) ?> checks passed.</div>
            </div>
        <?php else: ?>
            <div class="cs-alert cs-alert-error" role="alert">
                <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                <div class="cs-alert-body"><strong class="cs-alert-title"><?= $failed ?> check(s) failed</strong>The red rows show the exact error message. Please copy it when asking for help.</div>
            </div>
        <?php endif; ?>

        <section class="cs-card" style="margin-top:20px">
            <div class="cs-table-wrap">
                <table class="cs-table">
                    <thead><tr><th style="width:56px">Result</th><th>Check</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php foreach ($checks as $check): ?>
                        <tr>
                            <td>
                                <?php if ($check["ok"]): ?>
                                    <span class="cs-badge cs-badge-resolved"><i class="bi bi-check-lg" aria-hidden="true"></i> OK</span>
                                <?php else: ?>
                                    <span class="cs-badge cs-badge-overdue"><i class="bi bi-x-lg" aria-hidden="true"></i> Error</span>
                                <?php endif; ?>
                            </td>
                            <td><?= csEscape($check["label"]) ?></td>
                            <td style="word-break:break-word<?= $check["ok"] ? "" : ";color:#b91c1c;font-weight:600" ?>"><?= csEscape($check["detail"]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<?php include __DIR__ . "/partial/scripts.php"; ?>
</body>
</html>
