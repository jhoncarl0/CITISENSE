<?php

session_start();


// ==========================================================
// ADMIN SESSION CHECK
// ==========================================================

if (
    !isset($_SESSION["admin_id"]) ||
    !isset($_SESSION["barangay_id"])
) {
    header("Location: login.php");
    exit;
}


// ==========================================================
// DATABASE CONNECTION
// ==========================================================

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/partial/helpers.php";
require_once __DIR__ . "/partial/schema.php";
require_once __DIR__ . "/partial/uploads.php";
require_once __DIR__ . "/partial/announcement_comments.php";

$schemaReady = csEnsureSchema($conn);

// ==========================================================
// SESSION DATA
// ==========================================================

$adminID = $_SESSION["admin_id"];
$barangayID = $_SESSION["barangay_id"];


// ==========================================================
// ANNOUNCEMENT TABLE
// ==========================================================

$announcementTable = "announcements";


// ==========================================================
// GET BARANGAY NAME
// ==========================================================

$barangayName = "Barangay";

$stmt = $conn->prepare("
    SELECT barangayName
    FROM barangay
    WHERE barangayID = ?
    LIMIT 1
");

$stmt->bind_param("s", $barangayID);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $barangay = $result->fetch_assoc();

    $barangayName = $barangay["barangayName"];
}

$stmt->close();


// ==========================================================
// CREATE ANNOUNCEMENT
// ==========================================================

/*
|--------------------------------------------------------------------------
| CREATE / UPDATE / DELETE  (with pictures)
|--------------------------------------------------------------------------
| Pictures are checked before anything is saved, so a bad file never leaves
| a half-saved announcement behind. Up to CS_ANNOUNCEMENT_MAX_IMAGES pictures
| per announcement.
*/

const CS_ANNOUNCEMENT_MAX_IMAGES = 6;

function annRedirect(string $query, string $error = ""): void
{
    if ($error !== "") {
        $_SESSION["ann_error"] = $error;
    }
    header("Location: announcements.php?" . $query);
    exit;
}

/** Image rows of one announcement in this barangay. */
function annImages(mysqli $conn, int $announceID): array
{
    $stmt = $conn->prepare(
        "SELECT imageID, imagePath FROM announcement_images
         WHERE announceID = ? ORDER BY sortOrder ASC, imageID ASC"
    );
    $stmt->bind_param("i", $announceID);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Save new pictures for an announcement; returns saved relative paths. */
function annSaveImages(mysqli $conn, int $announceID, array $files, int $startOrder): array
{
    $saved = [];

    try {
        foreach ($files as $index => $file) {
            $path = csSaveUploadedImage($file, "announcements");
            $saved[] = $path;
            $order = $startOrder + $index;

            $stmt = $conn->prepare(
                "INSERT INTO announcement_images (announceID, imagePath, sortOrder) VALUES (?, ?, ?)"
            );
            $stmt->bind_param("isi", $announceID, $path, $order);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $error) {
        foreach ($saved as $path) {
            csDeleteStoredImage($path, "announcements");
        }
        throw $error;
    }

    return $saved;
}

$requestAction = $_SERVER["REQUEST_METHOD"] === "POST" ? (string) ($_POST["action"] ?? "") : "";

/* A form bigger than PHP's post_max_size arrives with no fields at all. */
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    $requestAction === "" &&
    (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0
) {
    annRedirect("error=image", "The pictures are too large to upload together. Please choose fewer or smaller pictures.");
}

if (in_array($requestAction, ["create", "update"], true) && !$schemaReady) {
    annRedirect("error=image", "Pictures cannot be saved until the database update is done (see the message on this page).");
}

if ($requestAction === "create") {
    $title = trim($_POST["title"] ?? "");
    $content = trim($_POST["content"] ?? "");
    $files = csNormalizeFiles($_FILES["images"] ?? null);

    if ($title === "" || $content === "") {
        annRedirect("error=empty");
    }

    if (count($files) > CS_ANNOUNCEMENT_MAX_IMAGES) {
        annRedirect("error=image", "Please add at most " . CS_ANNOUNCEMENT_MAX_IMAGES . " pictures.");
    }

    try {
        foreach ($files as $file) {
            csValidateUploadedImage($file);
        }
    } catch (RuntimeException $error) {
        annRedirect("error=image", $error->getMessage());
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            INSERT INTO $announcementTable (userID, barangayID, title, content)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssss", $adminID, $barangayID, $title, $content);
        $stmt->execute();
        $announceID = (int) $conn->insert_id;
        $stmt->close();

        if ($files) {
            annSaveImages($conn, $announceID, $files, 0);
        }

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        error_log("Announcement create failed: " . $error->getMessage());
        annRedirect(
            "error=image",
            $error instanceof RuntimeException ? $error->getMessage() : "The announcement could not be saved. Please try again."
        );
    }

    annRedirect("success=created");
}

if ($requestAction === "update") {
    $announceID = (int) ($_POST["announceID"] ?? 0);
    $title = trim($_POST["title"] ?? "");
    $content = trim($_POST["content"] ?? "");
    $files = csNormalizeFiles($_FILES["images"] ?? null);
    $removeIDs = array_map("intval", (array) ($_POST["removeImages"] ?? []));

    if ($announceID <= 0 || $title === "" || $content === "") {
        annRedirect("error=empty");
    }

    /* The announcement must belong to this barangay. */
    $stmt = $conn->prepare("SELECT announceID FROM $announcementTable WHERE announceID = ? AND barangayID = ? LIMIT 1");
    $stmt->bind_param("is", $announceID, $barangayID);
    $stmt->execute();
    $owned = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$owned) {
        annRedirect("error=empty");
    }

    $existing = annImages($conn, $announceID);
    $keep = array_values(array_filter($existing, static fn($img) => !in_array((int) $img["imageID"], $removeIDs, true)));
    $remove = array_values(array_filter($existing, static fn($img) => in_array((int) $img["imageID"], $removeIDs, true)));

    if (count($keep) + count($files) > CS_ANNOUNCEMENT_MAX_IMAGES) {
        annRedirect("error=image", "An announcement can have at most " . CS_ANNOUNCEMENT_MAX_IMAGES . " pictures. Remove some first.");
    }

    try {
        foreach ($files as $file) {
            csValidateUploadedImage($file);
        }
    } catch (RuntimeException $error) {
        annRedirect("error=image", $error->getMessage());
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            UPDATE $announcementTable SET title = ?, content = ?
            WHERE announceID = ? AND barangayID = ?
        ");
        $stmt->bind_param("ssis", $title, $content, $announceID, $barangayID);
        $stmt->execute();
        $stmt->close();

        foreach ($remove as $img) {
            $imageID = (int) $img["imageID"];
            $stmt = $conn->prepare("DELETE FROM announcement_images WHERE imageID = ? AND announceID = ?");
            $stmt->bind_param("ii", $imageID, $announceID);
            $stmt->execute();
            $stmt->close();
        }

        if ($files) {
            annSaveImages($conn, $announceID, $files, count($existing) + 1);
        }

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        error_log("Announcement update failed: " . $error->getMessage());
        annRedirect(
            "error=image",
            $error instanceof RuntimeException ? $error->getMessage() : "Your changes could not be saved. Please try again."
        );
    }

    /* Remove files only after the database change succeeded. */
    foreach ($remove as $img) {
        csDeleteStoredImage($img["imagePath"], "announcements");
    }

    annRedirect("success=updated");
}

if ($requestAction === "delete") {
    $announceID = (int) ($_POST["announceID"] ?? 0);

    if ($announceID > 0) {
        $stmt = $conn->prepare("SELECT announceID FROM $announcementTable WHERE announceID = ? AND barangayID = ? LIMIT 1");
        $stmt->bind_param("is", $announceID, $barangayID);
        $stmt->execute();
        $owned = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($owned) {
            $images = $schemaReady ? annImages($conn, $announceID) : [];

            $conn->begin_transaction();

            try {
                $cleanup = ["DELETE FROM announcement_reads WHERE announceID = ?"];
                if ($schemaReady) {
                    $cleanup[] = "DELETE FROM announcement_images WHERE announceID = ?";
                    $cleanup[] = "DELETE FROM announcement_comments WHERE announceID = ?";
                }

                foreach ($cleanup as $sql) {
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $announceID);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $conn->prepare("DELETE FROM $announcementTable WHERE announceID = ? AND barangayID = ?");
                $stmt->bind_param("is", $announceID, $barangayID);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                error_log("Announcement delete failed: " . $error->getMessage());
                annRedirect("error=image", "The announcement could not be deleted. Please try again.");
            }

            foreach ($images as $img) {
                csDeleteStoredImage($img["imagePath"], "announcements");
            }
        }
    }

    annRedirect("success=deleted");
}

$search = trim($_GET["search"] ?? "");


// ==========================================================
// GET ANNOUNCEMENTS
// ==========================================================

$announcements = [];


if ($search !== "") {

    $searchValue = "%" . $search . "%";


    $stmt = $conn->prepare("
        SELECT
            a.announceID,
            a.userID,
            a.barangayID,
            a.title,
            a.content,
            a.datePosted,

            u.firstName,
            u.lastName

        FROM $announcementTable a

        LEFT JOIN users u
            ON a.userID = u.userID

        WHERE a.barangayID = ?

        AND (
            a.title LIKE ?
            OR a.content LIKE ?
        )

        ORDER BY a.datePosted DESC
    ");


    $stmt->bind_param(
        "sss",
        $barangayID,
        $searchValue,
        $searchValue
    );

} else {

    $stmt = $conn->prepare("
        SELECT
            a.announceID,
            a.userID,
            a.barangayID,
            a.title,
            a.content,
            a.datePosted,

            u.firstName,
            u.lastName

        FROM $announcementTable a

        LEFT JOIN users u
            ON a.userID = u.userID

        WHERE a.barangayID = ?

        ORDER BY a.datePosted DESC
    ");


    $stmt->bind_param(
        "s",
        $barangayID
    );
}


$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {

    $announcements[] = $row;
}


$stmt->close();


// ==========================================================
// UNREAD NOTIFICATIONS
// ==========================================================

$unreadNotifications = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS unreadNotifications

    FROM notification

    WHERE userID = ?

    AND isRead = FALSE
");

$stmt->bind_param(
    "s",
    $adminID
);

$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows === 1) {

    $notificationData = $result->fetch_assoc();

    $unreadNotifications =
        (int) $notificationData["unreadNotifications"];
}


$stmt->close();

require_once __DIR__ . "/partial/helpers.php";

/* Pictures and comments for the listed announcements. */
$imagesByAnnouncement = [];
$commentStats = [];

if ($schemaReady && $announcements) {
    $ids = array_map(static fn($a) => (int) $a["announceID"], $announcements);
    $marks = implode(",", array_fill(0, count($ids), "?"));
    $idTypes = str_repeat("i", count($ids));

    $stmt = $conn->prepare(
        "SELECT imageID, announceID, imagePath FROM announcement_images
         WHERE announceID IN ($marks) ORDER BY sortOrder ASC, imageID ASC"
    );
    $stmt->bind_param($idTypes, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $imagesByAnnouncement[(int) $row["announceID"]][] = [
            "id" => (int) $row["imageID"],
            "url" => csMediaUrl($row["imagePath"]),
        ];
    }
    $stmt->close();

    $commentStats = annLoadComments($conn, $barangayID, $ids);
}

$imageError = (string) ($_SESSION["ann_error"] ?? "");
unset($_SESSION["ann_error"]);

$successMessages = [
    "created" => "Your announcement was published. Residents can now see it in the CitiSense app.",
    "updated" => "Your changes to the announcement were saved.",
    "deleted" => "The announcement was deleted.",
];
$successMessage = $successMessages[(string) ($_GET["success"] ?? "")] ?? "";
$hasError = isset($_GET["error"]);

$pageTitle = "Announcements";
$pageStyles = ["announcements.css"];
include __DIR__ . "/partial/head.php";
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">

    <header class="ann-header">
        <div>
            <h1>Announcements</h1>
            <p>Share news and important updates with residents. Announcements appear on the Home screen of the CitiSense app.</p>
            <span class="cs-location-chip">
                <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                <?= csEscape($barangayName) ?>
            </span>
        </div>
        <img class="ann-mascot" src="../css/images/mascot-bell.png" alt="">
    </header>

    <?php if ($successMessage !== ""): ?>
        <div class="cs-alert cs-alert-success" role="status">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <div class="cs-alert-body"><?= csEscape($successMessage) ?></div>
            <button type="button" class="cs-alert-close" data-dismiss-alert aria-label="Close message"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
    <?php endif; ?>

    <?php if (!$schemaReady): ?>
        <?= csSchemaNotice() ?>
    <?php endif; ?>

    <?php if ($hasError): ?>
        <div class="cs-alert cs-alert-error" role="alert">
            <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
            <div class="cs-alert-body">
                <?= csEscape($imageError !== "" ? $imageError : "Please fill in both the title and the message before saving.") ?>
            </div>
            <button type="button" class="cs-alert-close" data-dismiss-alert aria-label="Close message"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== "" || $hasError): ?>
        <script>
            // Remove ?success= / ?error= so a refresh does not repeat the message.
            history.replaceState({}, document.title, location.pathname + (new URLSearchParams(location.search).get("search") ? "?search=" + encodeURIComponent(new URLSearchParams(location.search).get("search")) : ""));
        </script>
    <?php endif; ?>

    <!-- TOOLBAR -->
    <section class="cs-card ann-toolbar">
        <form method="GET" action="announcements.php" class="ann-search" role="search">
            <label class="cs-sr-only" for="annSearch">Search announcements</label>
            <div class="cs-input-icon">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input
                    type="search"
                    id="annSearch"
                    name="search"
                    class="cs-input"
                    placeholder="Search by title or message…"
                    value="<?= csEscape($search) ?>"
                >
            </div>
            <button type="submit" class="cs-btn cs-btn-ghost">Search</button>
            <?php if ($search !== ""): ?>
                <a href="announcements.php" class="cs-btn cs-btn-ghost">Clear</a>
            <?php endif; ?>
        </form>

        <button type="button" class="cs-btn cs-btn-primary" data-modal-open="createModal">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            New Announcement
        </button>
    </section>

    <!-- LIST -->
    <section class="ann-list" aria-label="Announcements">
        <?php if ($search !== ""): ?>
            <p class="cs-muted">
                <?= count($announcements) ?> <?= count($announcements) === 1 ? "result" : "results" ?> for “<?= csEscape($search) ?>”
            </p>
        <?php endif; ?>

        <?php if ($announcements): ?>
            <?php foreach ($announcements as $announcement): ?>
                <?php
                $authorName = trim(($announcement["firstName"] ?? "") . " " . ($announcement["lastName"] ?? "")) ?: "Admin";
                $announceID = (int) $announcement["announceID"];
                $images = $imagesByAnnouncement[$announceID] ?? [];
                $comments = $commentStats[$announceID] ?? ["comments" => [], "threads" => [], "total" => 0, "residents" => 0, "unread" => 0];
                ?>
                <article class="cs-card ann-card">
                    <span class="ann-icon"><i class="bi bi-megaphone" aria-hidden="true"></i></span>

                    <div class="ann-content">
                        <h2><?= csEscape($announcement["title"]) ?></h2>
                        <p class="ann-meta">
                            <span><i class="bi bi-calendar3" aria-hidden="true"></i> <?= csEscape(csDate($announcement["datePosted"], "F j, Y · g:i A", "No date")) ?></span>
                            <span><i class="bi bi-person" aria-hidden="true"></i> Posted by <?= csEscape($authorName) ?></span>
                        </p>
                        <p class="ann-text"><?= nl2br(csEscape($announcement["content"])) ?></p>

                        <?php if ($images): ?>
                            <div class="ann-gallery ann-gallery-<?= min(count($images), 3) ?>">
                                <?php foreach ($images as $index => $image): ?>
                                    <?php if ($index >= 3 && count($images) > 4) break; ?>
                                    <button
                                        type="button"
                                        class="ann-photo"
                                        data-lightbox="ann-<?= $announceID ?>"
                                        data-src="<?= csEscape($image["url"]) ?>"
                                        aria-label="View picture <?= $index + 1 ?> of <?= count($images) ?>"
                                    >
                                        <img src="<?= csEscape($image["url"]) ?>" alt="" loading="lazy">
                                        <?php if (count($images) > 4 && $index === 2): ?>
                                            <span class="ann-photo-more">+<?= count($images) - 3 ?> more</span>
                                        <?php endif; ?>
                                    </button>
                                <?php endforeach; ?>
                                <?php foreach (array_slice($images, count($images) > 4 ? 3 : PHP_INT_MAX) as $hidden): ?>
                                    <span hidden data-lightbox-extra="ann-<?= $announceID ?>" data-src="<?= csEscape($hidden["url"]) ?>"></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($schemaReady): ?>
                            <button
                                type="button"
                                class="ann-comments-toggle<?= $comments["unread"] > 0 ? " has-unread" : "" ?>"
                                aria-expanded="false"
                                aria-controls="comments-<?= $announceID ?>"
                                data-comments-toggle="<?= $announceID ?>"
                            >
                                <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                                <span data-comments-summary><?= csEscape(annCommentSummary($comments["total"], $comments["residents"])) ?></span>
                                <?php if ($comments["unread"] > 0): ?>
                                    <span class="cs-badge cs-badge-overdue no-dot" data-comments-new><?= $comments["unread"] ?> new</span>
                                <?php endif; ?>
                                <i class="bi bi-chevron-down ann-comments-chevron" aria-hidden="true"></i>
                            </button>

                            <div class="ann-comments" id="comments-<?= $announceID ?>" data-announce-id="<?= $announceID ?>" hidden>
                                <ul class="ann-comment-list" data-comment-list aria-label="Comments on <?= csEscape($announcement["title"]) ?>">
                                    <?php foreach ($comments["threads"] ?? [] as $comment): ?>
                                        <?= annCommentHtml($comment) ?>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="ann-comments-empty" data-comments-empty<?= $comments["comments"] ? " hidden" : "" ?>>
                                    No comments yet. Residents can comment in the CitiSense app, and you can write the first comment below.
                                </p>

                                <form class="ann-comment-form" data-comment-form>
                                    <label class="cs-sr-only" for="commentInput<?= $announceID ?>">Write a comment</label>
                                    <textarea
                                        id="commentInput<?= $announceID ?>"
                                        class="cs-textarea"
                                        rows="2"
                                        maxlength="2000"
                                        placeholder="Write a comment… (Ctrl + Enter to post)"
                                        required
                                    ></textarea>
                                    <div class="ann-comment-form-row">
                                        <span class="cs-hint">Everyone who views this announcement in the app will see your comment.</span>
                                        <button type="submit" class="cs-btn cs-btn-primary cs-btn-sm">
                                            <i class="bi bi-send" aria-hidden="true"></i> Post
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="ann-actions">
                        <button
                            type="button"
                            class="cs-btn cs-btn-ghost cs-btn-sm ann-edit-btn"
                            data-id="<?= $announceID ?>"
                            data-title="<?= csEscape($announcement["title"]) ?>"
                            data-content="<?= csEscape($announcement["content"]) ?>"
                            data-images="<?= csEscape(json_encode($images)) ?>"
                        >
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                            Edit
                        </button>

                        <form
                            method="POST"
                            action="announcements.php"
                            data-confirm="“<?= csEscape($announcement["title"]) ?>” will be removed for everyone, together with its pictures and comments. This cannot be undone."
                            data-confirm-title="Delete this announcement?"
                            data-confirm-button="Yes, Delete"
                            data-confirm-danger="true"
                            data-confirm-icon="bi-trash"
                        >
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="announceID" value="<?= $announceID ?>">
                            <button type="submit" class="cs-btn cs-btn-danger-outline cs-btn-sm">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                                Delete
                            </button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="cs-card cs-empty">
                <span class="cs-empty-icon"><i class="bi bi-megaphone" aria-hidden="true"></i></span>
                <?php if ($search !== ""): ?>
                    <h3>No announcements found</h3>
                    <p>No announcement matches “<?= csEscape($search) ?>”. Try another word.</p>
                    <a href="announcements.php" class="cs-btn cs-btn-secondary">Show All Announcements</a>
                <?php else: ?>
                    <h3>No announcements yet</h3>
                    <p>Create your first announcement to share updates with the community.</p>
                    <button type="button" class="cs-btn cs-btn-primary" data-modal-open="createModal">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        New Announcement
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<?php
/* Picture picker shared by the create and edit forms. */
function annPhotoPicker(string $id): void
{
    ?>
    <div class="cs-field ann-picker" data-photo-picker data-max="<?= CS_ANNOUNCEMENT_MAX_IMAGES ?>">
        <span class="cs-label">Pictures <span class="cs-subtle">(optional, up to <?= CS_ANNOUNCEMENT_MAX_IMAGES ?>)</span></span>

        <div class="ann-picker-grid" data-photo-list></div>

        <label class="ann-picker-drop" for="<?= $id ?>">
            <i class="bi bi-image" aria-hidden="true"></i>
            <span><strong>Add pictures</strong> or drag them here</span>
            <small>JPG, PNG or WEBP · up to 5 MB each</small>
        </label>
        <input
            type="file"
            id="<?= $id ?>"
            name="images[]"
            class="cs-sr-only"
            accept="image/jpeg,image/png,image/webp"
            multiple
            data-photo-input
        >
        <p class="ann-picker-error" data-photo-error role="alert" hidden></p>
    </div>
    <?php
}
?>

<!-- CREATE -->
<div class="cs-modal" id="createModal" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
    <div class="cs-modal-dialog">
        <form method="POST" action="announcements.php" enctype="multipart/form-data" data-announcement-form>
            <input type="hidden" name="action" value="create">
            <div class="cs-modal-header">
                <div>
                    <h2 id="createModalTitle">New announcement</h2>
                    <p>This will be shown to residents of <?= csEscape($barangayName) ?>.</p>
                </div>
                <button type="button" class="cs-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="cs-modal-body">
                <div class="cs-field">
                    <label class="cs-label" for="createTitle">Title</label>
                    <input type="text" id="createTitle" name="title" class="cs-input" maxlength="255" placeholder="Example: Garbage collection schedule" required>
                </div>
                <div class="cs-field">
                    <label class="cs-label" for="createContent">Message</label>
                    <textarea id="createContent" name="content" class="cs-textarea" rows="6" placeholder="Write the full announcement here…" required></textarea>
                </div>
                <?php if ($schemaReady) annPhotoPicker("createImages"); ?>
            </div>
            <div class="cs-modal-footer">
                <button type="button" class="cs-btn cs-btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="cs-btn cs-btn-primary">
                    <i class="bi bi-send" aria-hidden="true"></i>
                    Publish Announcement
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT -->
<div class="cs-modal" id="editModal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
    <div class="cs-modal-dialog">
        <form method="POST" action="announcements.php" enctype="multipart/form-data" data-announcement-form>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="announceID" id="editAnnouncementID">
            <div class="cs-modal-header">
                <div>
                    <h2 id="editModalTitle">Edit announcement</h2>
                    <p>Residents will see the updated version.</p>
                </div>
                <button type="button" class="cs-icon-btn" data-modal-close aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="cs-modal-body">
                <div class="cs-field">
                    <label class="cs-label" for="editTitle">Title</label>
                    <input type="text" id="editTitle" name="title" class="cs-input" maxlength="255" required>
                </div>
                <div class="cs-field">
                    <label class="cs-label" for="editContent">Message</label>
                    <textarea id="editContent" name="content" class="cs-textarea" rows="6" required></textarea>
                </div>
                <?php if ($schemaReady) annPhotoPicker("editImages"); ?>
            </div>
            <div class="cs-modal-footer">
                <button type="button" class="cs-btn cs-btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="cs-btn cs-btn-primary">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- PICTURE VIEWER -->
<div class="cs-modal ann-lightbox" id="annLightbox" role="dialog" aria-modal="true" aria-label="Picture viewer">
    <div class="ann-lightbox-inner">
        <button type="button" class="ann-lightbox-close cs-btn cs-btn-ghost cs-btn-sm" data-modal-close>
            <i class="bi bi-x-lg" aria-hidden="true"></i> Close
        </button>
        <button type="button" class="ann-lightbox-nav is-prev" data-lightbox-step="-1" aria-label="Previous picture"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
        <img id="annLightboxImage" src="" alt="Announcement picture">
        <button type="button" class="ann-lightbox-nav is-next" data-lightbox-step="1" aria-label="Next picture"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        <p class="ann-lightbox-count" id="annLightboxCount"></p>
    </div>
</div>

<?php include __DIR__ . "/partial/scripts.php"; ?>
<script src="../js/announcements.js?v=<?= $csAssetVersion ?>"></script>

</body>
</html>
