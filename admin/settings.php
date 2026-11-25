
<?php
session_start();

if (
    !isset($_SESSION["admin_id"]) ||
    !isset($_SESSION["barangay_id"])
) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/partial/helpers.php";
require_once __DIR__ . "/partial/uploads.php";
require_once __DIR__ . "/partial/passwords.php";

$adminID = (string) $_SESSION["admin_id"];
$barangayID = (string) $_SESSION["barangay_id"];

if (empty($_SESSION["settings_csrf"])) {
    $_SESSION["settings_csrf"] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "";

/**
 * Escape output for HTML.
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/**
 * Load the logged-in administrator for the assigned barangay.
 */
function loadAdmin(
    mysqli $conn,
    string $adminID,
    string $barangayID
): ?array {
    $sql = "
        SELECT
            u.userID,
            u.firstName,
            u.lastName,
            u.email,
            u.contactNo,
            u.profilePic,
            u.dateJoined,
            b.barangayName
        FROM users AS u
        LEFT JOIN barangay AS b
            ON b.barangayID = u.barangayID
        WHERE u.userID = ?
          AND u.barangayID = ?
          AND u.role = 'admin'
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $adminID, $barangayID);
    $stmt->execute();

    $result = $stmt->get_result();
    $admin = $result->fetch_assoc() ?: null;

    $stmt->close();

    return $admin;
}

/**
 * Generate profile initials without relying on global variables.
 */
function getInitials(string $firstName, string $lastName): string
{
    $firstName = trim($firstName);
    $lastName = trim($lastName);

    $firstInitial = $firstName !== ""
        ? (function_exists("mb_substr")
            ? mb_substr($firstName, 0, 1, "UTF-8")
            : substr($firstName, 0, 1))
        : "";

    $lastInitial = $lastName !== ""
        ? (function_exists("mb_substr")
            ? mb_substr($lastName, 0, 1, "UTF-8")
            : substr($lastName, 0, 1))
        : "";

    $initials = $firstInitial . $lastInitial;

    if ($initials === "") {
        return "AD";
    }

    return function_exists("mb_strtoupper")
        ? mb_strtoupper($initials, "UTF-8")
        : strtoupper($initials);
}

/**
 * Process submitted forms.
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION["settings_csrf"], $csrfToken)
    ) {
        $message = "Your session could not be verified. Please refresh and try again.";
        $messageType = "error";
    } else {
        $action = $_POST["action"] ?? "";

        /*
         * UPDATE PROFILE PICTURE
         */
        if ($action === "change_picture") {
            $admin = loadAdmin($conn, $adminID, $barangayID);

            if (!$admin) {
                http_response_code(403);
                exit("Unauthorized.");
            }

            if (!isset($_FILES["profile_picture"])) {
                $message = "Please select a picture to upload.";
                $messageType = "error";
            } elseif (
                $_FILES["profile_picture"]["error"] !== UPLOAD_ERR_OK
            ) {
                $message = "The image could not be uploaded. Please select it again.";
                $messageType = "error";
            } elseif (
                $_FILES["profile_picture"]["size"] > 3 * 1024 * 1024
            ) {
                $message = "The image must be 3 MB or smaller.";
                $messageType = "error";
            } else {
                $temporaryFile = $_FILES["profile_picture"]["tmp_name"];
                $imageInfo = @getimagesize($temporaryFile);

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($temporaryFile);

                $allowedTypes = [
                    "image/jpeg" => "jpg",
                    "image/png"  => "png",
                    "image/webp" => "webp"
                ];

                if (
                    $imageInfo === false ||
                    !isset($allowedTypes[$mimeType])
                ) {
                    $message = "Use a valid JPG, PNG, or WEBP image.";
                    $messageType = "error";
                } else {
                    // Saved in the site's uploads folder (uploads/profile_pictures/),
                    // like announcement pictures, so a hosting volume mounted on
                    // uploads (e.g. Railway /app/uploads) keeps it.
                    $uploadDirectory = csUploadDir("profile_pictures");

                    if (
                        !is_dir($uploadDirectory) &&
                        !mkdir($uploadDirectory, 0755, true) &&
                        !is_dir($uploadDirectory)
                    ) {
                        $message = "The upload folder could not be created.";
                        $messageType = "error";
                    } else {
                        $filename =
                            bin2hex(random_bytes(16)) .
                            "." .
                            $allowedTypes[$mimeType];

                        $destination = $uploadDirectory . $filename;

                        $databasePath =
                            "uploads/profile_pictures/" . $filename;

                        if (
                            !move_uploaded_file(
                                $temporaryFile,
                                $destination
                            )
                        ) {
                            $message = "The picture could not be saved.";
                            $messageType = "error";
                        } else {
                            $stmt = $conn->prepare("
                                UPDATE users
                                SET profilePic = ?
                                WHERE userID = ?
                                  AND barangayID = ?
                                  AND role = 'admin'
                            ");

                            $stmt->bind_param(
                                "sss",
                                $databasePath,
                                $adminID,
                                $barangayID
                            );

                            $updated = $stmt->execute()
                                && $stmt->affected_rows === 1;

                            $stmt->close();

                            if ($updated) {
                                $oldPicture =
                                    (string) ($admin["profilePic"] ?? "");

                                /*
                                 * Delete only an old file located inside
                                 * this page's profile-picture directory.
                                 */
                                if (
                                    str_starts_with(
                                        $oldPicture,
                                        "uploads/profile_pictures/"
                                    )
                                ) {
                                    $oldFile = dirname($uploadDirectory, 2) . "/" . $oldPicture;

                                    $realUploadDirectory =
                                        realpath($uploadDirectory);

                                    $realOldFile = realpath($oldFile);

                                    if (
                                        $realUploadDirectory !== false &&
                                        $realOldFile !== false &&
                                        str_starts_with(
                                            $realOldFile,
                                            $realUploadDirectory .
                                            DIRECTORY_SEPARATOR
                                        ) &&
                                        is_file($realOldFile)
                                    ) {
                                        @unlink($realOldFile);
                                    }
                                }

                                $message =
                                    "Profile picture updated successfully.";
                                $messageType = "success";
                            } else {
                                @unlink($destination);

                                $message =
                                    "Could not update the profile picture in the database.";
                                $messageType = "error";
                            }
                        }
                    }
                }
            }
        }

        /*
         * CHANGE PASSWORD
         */
        elseif ($action === "change_password") {
            $currentPassword =
                $_POST["current_password"] ?? "";

            $newPassword =
                $_POST["new_password"] ?? "";

            $confirmPassword =
                $_POST["confirm_password"] ?? "";

            if (
                !is_string($currentPassword) ||
                !is_string($newPassword) ||
                !is_string($confirmPassword)
            ) {
                $message = "Invalid password input.";
                $messageType = "error";
            } elseif (
                $currentPassword === "" ||
                $newPassword === "" ||
                $confirmPassword === ""
            ) {
                $message = "Please complete all password fields.";
                $messageType = "error";
            } elseif ($newPassword !== $confirmPassword) {
                $message = "The new passwords do not match.";
                $messageType = "error";
            } elseif (
                $passwordProblems = csPasswordProblems(
                    $newPassword,
                    (string) ($admin["email"] ?? "")
                )
            ) {
                // Same rules as the browser shows (partial/passwords.php).
                $message = "Your new password is not strong enough: "
                    . implode(" ", $passwordProblems);
                $messageType = "error";
            } else {
                $stmt = $conn->prepare("
                    SELECT pass
                    FROM users
                    WHERE userID = ?
                      AND barangayID = ?
                      AND role = 'admin'
                    LIMIT 1
                ");

                $stmt->bind_param("ss", $adminID, $barangayID);
                $stmt->execute();

                $result = $stmt->get_result();
                $account = $result->fetch_assoc();

                $stmt->close();

                if (
                    !$account ||
                    !password_verify(
                        $currentPassword,
                        $account["pass"]
                    )
                ) {
                    $message = "Your current password is incorrect.";
                    $messageType = "error";
                } elseif (
                    password_verify(
                        $newPassword,
                        $account["pass"]
                    )
                ) {
                    $message =
                        "Choose a password different from your current password.";
                    $messageType = "error";
                } else {
                    $newHash = password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $conn->prepare("
                        UPDATE users
                        SET pass = ?
                        WHERE userID = ?
                          AND barangayID = ?
                          AND role = 'admin'
                    ");

                    $stmt->bind_param(
                        "sss",
                        $newHash,
                        $adminID,
                        $barangayID
                    );

                    $updated = $stmt->execute()
                        && $stmt->affected_rows === 1;

                    $stmt->close();

                    if ($updated) {
                        // New session ID after a credential change.
                        session_regenerate_id(true);

                        $message = "Password changed successfully.";
                        $messageType = "success";
                    } else {
                        $message =
                            "Could not change your password. Please try again.";
                        $messageType = "error";
                    }
                }
            }
        } else {
            $message = "Invalid settings action.";
            $messageType = "error";
        }
    }
}

/*
 * Reload current admin information after form processing.
 */
$admin = loadAdmin($conn, $adminID, $barangayID);

if (!$admin) {
    http_response_code(403);
    exit("The admin account could not be found.");
}

$fullName = trim(
    (string) $admin["firstName"] .
    " " .
    (string) $admin["lastName"]
);

$initials = getInitials(
    (string) $admin["firstName"],
    (string) $admin["lastName"]
);

$profilePic = trim((string) ($admin["profilePic"] ?? ""));

$profilePicUrl = $profilePic !== ""
    ? csMediaUrl($profilePic)
    : "";

require_once __DIR__ . "/partial/helpers.php";
$dateJoined = csDate($admin["dateJoined"] ?? "", "F j, Y", "Not available");

$pageTitle = "Settings";
$pageStyles = ["settings.css"];
include __DIR__ . "/partial/head.php";
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">

    <header class="set-header">
        <div>
            <h1>Settings</h1>
            <p>Manage your account and keep your password safe.</p>
        </div>
        <img class="set-mascot" src="../css/images/mascot-settings.png" alt="">
    </header>

    <?php if ($message !== ""): ?>
        <div class="cs-alert cs-alert-<?= $messageType === "success" ? "success" : "error" ?>" role="<?= $messageType === "success" ? "status" : "alert" ?>">
            <i class="bi <?= $messageType === "success" ? "bi-check-circle-fill" : "bi-exclamation-circle-fill" ?>" aria-hidden="true"></i>
            <div class="cs-alert-body"><?= e($message) ?></div>
            <button type="button" class="cs-alert-close" data-dismiss-alert aria-label="Close message"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
    <?php endif; ?>

    <div class="set-grid">

        <!-- PROFILE -->
        <section class="cs-card set-profile" aria-labelledby="profileHeading">
            <div class="cs-card-body">
                <h2 id="profileHeading" class="cs-section-title">Profile picture</h2>

                <div class="set-profile-row">
                    <span class="set-avatar" id="avatarPreview">
                        <?php if ($profilePicUrl !== ""): ?>
                            <img src="<?= e($profilePicUrl) ?>" alt="Your profile picture" id="profilePreview">
                        <?php else: ?>
                            <span id="profileInitials"><?= e($initials) ?></span>
                        <?php endif; ?>
                    </span>

                    <div class="set-profile-info">
                        <h3><?= e($fullName) ?></h3>
                        <p><?= e($admin["email"]) ?></p>
                        <span class="cs-badge cs-badge-info no-dot">Barangay Administrator</span>
                    </div>
                </div>

                <form
                    id="pictureForm"
                    action="settings.php"
                    method="POST"
                    enctype="multipart/form-data"
                    class="set-picture-form"
                >
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["settings_csrf"]) ?>">
                    <input type="hidden" name="action" value="change_picture">
                    <input
                        type="file"
                        id="profilePictureInput"
                        name="profile_picture"
                        accept="image/jpeg,image/png,image/webp"
                        class="cs-sr-only"
                    >

                    <div class="set-picture-actions" id="pictureChoose">
                        <label for="profilePictureInput" class="cs-btn cs-btn-secondary">
                            <i class="bi bi-camera" aria-hidden="true"></i>
                            Choose a New Picture
                        </label>
                        <span class="cs-hint">JPG, PNG or WEBP. Up to 3 MB.</span>
                    </div>

                    <div class="set-picture-confirm" id="pictureConfirm" hidden>
                        <p><strong>Use this picture?</strong> Check the preview above.</p>
                        <div class="set-picture-buttons">
                            <button type="submit" class="cs-btn cs-btn-primary" id="savePictureButton">
                                <i class="bi bi-check-lg" aria-hidden="true"></i>
                                Save Picture
                            </button>
                            <button type="button" class="cs-btn cs-btn-ghost" id="cancelPictureButton">Cancel</button>
                        </div>
                    </div>

                    <p class="set-inline-error" id="pictureError" role="alert" hidden></p>
                </form>
            </div>
        </section>

        <!-- ACCOUNT INFORMATION -->
        <section class="cs-card" aria-labelledby="infoHeading">
            <div class="cs-card-body">
                <h2 id="infoHeading" class="cs-section-title">Account information</h2>
                <p class="cs-hint set-note">These details are managed by your system administrator.</p>

                <dl class="set-info">
                    <div><dt>Full name</dt><dd><?= e($fullName) ?></dd></div>
                    <div><dt>Email address</dt><dd><?= e($admin["email"]) ?></dd></div>
                    <div><dt>Contact number</dt><dd><?= e($admin["contactNo"] ?: "Not provided") ?></dd></div>
                    <div><dt>Assigned barangay</dt><dd><?= e($admin["barangayName"] ?? "Not assigned") ?></dd></div>
                    <div><dt>Admin ID</dt><dd><?= e($admin["userID"]) ?></dd></div>
                    <div><dt>Date joined</dt><dd><?= e($dateJoined) ?></dd></div>
                </dl>
            </div>
        </section>

        <!-- PASSWORD -->
        <section class="cs-card set-password" aria-labelledby="passwordHeading">
            <div class="cs-card-body">
                <h2 id="passwordHeading" class="cs-section-title">Change password</h2>
                <p class="cs-hint set-note">Use <?= CS_PASSWORD_MIN ?> to <?= CS_PASSWORD_MAX ?> characters with an uppercase letter, a lowercase letter, a number and a symbol. Spaces and emojis are not allowed.</p>

                <form id="passwordForm" action="settings.php" method="POST" autocomplete="off" class="set-password-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION["settings_csrf"]) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="set-password-grid">
                        <?php
                        $passwordFields = [
                            ["currentPassword", "current_password", "Current password", "current-password", ""],
                            ["newPassword", "new_password", "New password", "new-password", ""],
                            ["confirmPassword", "confirm_password", "Type the new password again", "new-password", ""],
                        ];
                        ?>
                        <?php foreach ($passwordFields as [$id, $name, $label, $autocomplete, $hint]): ?>
                            <div class="cs-field">
                                <label class="cs-label" for="<?= $id ?>"><?= $label ?></label>
                                <div class="set-password-input">
                                    <input
                                        id="<?= $id ?>"
                                        type="password"
                                        name="<?= $name ?>"
                                        class="cs-input"
                                        autocomplete="<?= $autocomplete ?>"
                                        <?= $name !== "current_password" ? 'minlength="' . CS_PASSWORD_MIN . '" maxlength="' . CS_PASSWORD_MAX . '"' : "" ?>
                                        <?= $name === "new_password" ? 'data-password-rules data-confirm="confirmPassword" data-email="' . e((string) ($admin["email"] ?? "")) . '"' : "" ?>
                                        required
                                    >
                                    <button type="button" class="set-show-password" data-toggle-password="<?= $id ?>" aria-pressed="false">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                        <span>Show</span>
                                    </button>
                                </div>
                                <?php if ($hint !== ""): ?>
                                    <span class="cs-hint"><?= $hint ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <p class="set-inline-error" id="passwordError" role="alert" hidden></p>

                    <div class="cs-form-actions">
                        <button class="cs-btn cs-btn-primary" type="submit">
                            <i class="bi bi-shield-lock" aria-hidden="true"></i>
                            Update Password
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<?php include __DIR__ . "/partial/scripts.php"; ?>
<script src="../js/password-rules.js?v=<?= $csAssetVersion ?? "2.0" ?>"></script>

<script>
(() => {
    const pictureInput = document.getElementById("profilePictureInput");
    const avatar = document.getElementById("avatarPreview");
    const choose = document.getElementById("pictureChoose");
    const confirmBox = document.getElementById("pictureConfirm");
    const cancel = document.getElementById("cancelPictureButton");
    const save = document.getElementById("savePictureButton");
    const pictureError = document.getElementById("pictureError");
    const originalAvatar = avatar.innerHTML;
    let previewUrl = null;

    function resetPicture() {
        pictureInput.value = "";
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        avatar.innerHTML = originalAvatar;
        confirmBox.hidden = true;
        choose.hidden = false;
    }

    function showPictureError(message) {
        pictureError.textContent = message;
        pictureError.hidden = !message;
    }

    pictureInput.addEventListener("change", () => {
        const file = pictureInput.files[0];
        showPictureError("");
        if (!file) return;

        if (!["image/jpeg", "image/png", "image/webp"].includes(file.type)) {
            resetPicture();
            showPictureError("Please choose a JPG, PNG or WEBP picture.");
            return;
        }

        if (file.size > 3 * 1024 * 1024) {
            resetPicture();
            showPictureError("This picture is too large. Please choose one that is 3 MB or smaller.");
            return;
        }

        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = URL.createObjectURL(file);

        const img = document.createElement("img");
        img.src = previewUrl;
        img.alt = "Preview of your new profile picture";
        avatar.replaceChildren(img);

        choose.hidden = true;
        confirmBox.hidden = false;
        save.focus();
    });

    cancel.addEventListener("click", resetPicture);

    document.getElementById("pictureForm").addEventListener("submit", () => {
        save.disabled = true;
        save.textContent = "Uploading…";
    });

    // Show / hide password buttons
    document.querySelectorAll("[data-toggle-password]").forEach((button) => {
        button.addEventListener("click", () => {
            const input = document.getElementById(button.dataset.togglePassword);
            const show = input.type === "password";
            input.type = show ? "text" : "password";
            button.setAttribute("aria-pressed", show ? "true" : "false");
            button.querySelector("i").className = "bi " + (show ? "bi-eye-slash" : "bi-eye");
            button.querySelector("span").textContent = show ? "Hide" : "Show";
        });
    });

    const passwordForm = document.getElementById("passwordForm");
    const passwordError = document.getElementById("passwordError");

    passwordForm.addEventListener("submit", (event) => {
        const newPassword = document.getElementById("newPassword");
        const confirmPassword = document.getElementById("confirmPassword");

        if (newPassword.value !== confirmPassword.value) {
            event.preventDefault();
            passwordError.textContent = "The two new passwords do not match. Please type them again.";
            passwordError.hidden = false;
            confirmPassword.focus();
            return;
        }

        passwordError.hidden = true;
    });
})();
</script>
</body>
</html>
