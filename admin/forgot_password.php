<?php
/*
 * CitiSense admin — Forgot password (email code).
 *
 * Step 1  email    -> a 6-digit code is emailed (only to admin accounts)
 * Step 2  code     -> the code is checked; a one-time reset key is kept in
 *                     the server session (never shown in the browser)
 * Step 3  password -> new password with the admin password rules
 *
 * See partial/password_reset.php for how this reuses the residents' recovery.
 * The messages never reveal whether an email is registered.
 */

session_start();

if (!empty($_SESSION["admin_id"])) {
    header("Location: dashboard.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/partial/helpers.php";
require_once __DIR__ . "/partial/passwords.php";
require_once __DIR__ . "/partial/password_reset.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const PWRESET_MAX_AGE = 15 * 60;   // the whole process, in seconds
const PWRESET_RESEND_WAIT = 60;    // between two code emails

if (empty($_SESSION["pwreset_csrf"])) {
    $_SESSION["pwreset_csrf"] = bin2hex(random_bytes(32));
}

$state = $_SESSION["pwreset"] ?? null;
if (!is_array($state) || (time() - (int) ($state["started"] ?? 0)) > PWRESET_MAX_AGE) {
    $state = ["step" => "email", "email" => "", "started" => time(), "lastSend" => 0, "token" => ""];
}

$message = "";
$messageType = "info";

/** Ask the API to email a code, but only to an administrator account. */
function pwresetSendCode(mysqli $conn, string $email): void
{
    $stmt = $conn->prepare("SELECT userID FROM users WHERE email = ? AND role = 'admin' LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $isAdmin = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$isAdmin) {
        return; // same message on the page, nothing is sent
    }

    $result = csApiPost("forgot_password.php", ["email" => $email]);
    if ($result["status"] !== 200 || empty($result["data"]["success"])) {
        // Logged for the developers only; the page shows the usual message.
        error_log(
            "Admin password reset: the API could not send the code (HTTP " . $result["status"] . "). "
            . ($result["data"]["message"] ?? $result["error"] ?? "")
        );
    }
}

$action = (string) ($_POST["action"] ?? "");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf = $_POST["csrf_token"] ?? "";

    if (!is_string($csrf) || !hash_equals($_SESSION["pwreset_csrf"], $csrf)) {
        $message = "This page has expired. Please try again.";
        $messageType = "error";
    } elseif ($action === "start_over") {
        $state = ["step" => "email", "email" => "", "started" => time(), "lastSend" => 0, "token" => ""];
    } elseif ($action === "request" || $action === "resend") {
        $email = $action === "resend"
            ? (string) $state["email"]
            : strtolower(trim((string) ($_POST["email"] ?? "")));

        $wait = PWRESET_RESEND_WAIT - (time() - (int) $state["lastSend"]);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $message = "Please enter a valid email address.";
            $messageType = "error";
        } elseif ($wait > 0) {
            $message = "Please wait " . $wait . " seconds before asking for another code.";
            $messageType = "error";
        } else {
            try {
                pwresetSendCode($conn, $email);
            } catch (Throwable $error) {
                error_log("Admin password reset (send): " . $error->getMessage());
            }

            $state = [
                "step" => "code",
                "email" => $email,
                "started" => $action === "resend" ? (int) $state["started"] : time(),
                "lastSend" => time(),
                "token" => "",
            ];
            $message = "If an administrator account uses this email, we sent a 6-digit code to it. "
                . "The code works for 5 minutes. Check your Spam folder if you do not see it.";
            $messageType = "success";
        }
    } elseif ($action === "verify" && $state["step"] === "code") {
        $code = preg_replace('/\D+/', "", (string) ($_POST["code"] ?? ""));

        if (strlen($code) !== 6) {
            $message = "Please enter the 6-digit code from the email.";
            $messageType = "error";
        } else {
            $result = csApiPost("verify_otp.php", ["email" => $state["email"], "otp" => $code]);
            $token = (string) ($result["data"]["resetToken"] ?? "");

            if ($result["status"] === 200 && !empty($result["data"]["success"]) && preg_match('/^[a-f0-9]{64}$/', $token)) {
                $state["step"] = "password";
                $state["token"] = $token;
                $message = "Code accepted. Now choose your new password.";
                $messageType = "success";
            } elseif ($result["status"] === 0) {
                error_log("Admin password reset: the API could not be reached. " . ($result["error"] ?? ""));
                $message = "We could not check the code right now. Please try again in a few minutes.";
                $messageType = "error";
            } else {
                $message = "The code is wrong or has expired. Check the newest email, or ask for a new code.";
                $messageType = "error";
            }
        }
    } elseif ($action === "reset" && $state["step"] === "password") {
        $password = $_POST["new_password"] ?? "";
        $confirm = $_POST["confirm_password"] ?? "";
        $problems = csPasswordProblems($password, (string) $state["email"]);

        if ($problems) {
            $message = "Your new password is not strong enough: " . implode(" ", $problems);
            $messageType = "error";
        } elseif (!is_string($confirm) || $password !== $confirm) {
            $message = "The two passwords do not match. Please type them again.";
            $messageType = "error";
        } else {
            $tokenHash = hash("sha256", (string) $state["token"]);
            $done = false;

            try {
                $conn->begin_transaction();

                // The reset key must be verified, unused and not expired.
                $stmt = $conn->prepare(
                    "SELECT id, userID FROM password_reset_otps
                     WHERE reset_token_hash = ?
                       AND purpose = 'password_reset'
                       AND verified_at IS NOT NULL
                       AND expires_at > NOW()
                     LIMIT 1
                     FOR UPDATE"
                );
                $stmt->bind_param("s", $tokenHash);
                $stmt->execute();
                $record = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $account = null;
                if ($record) {
                    $userID = (string) $record["userID"];
                    $stmt = $conn->prepare("SELECT userID, pass FROM users WHERE userID = ? AND email = ? AND role = 'admin' LIMIT 1");
                    $stmt->bind_param("ss", $userID, $state["email"]);
                    $stmt->execute();
                    $account = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }

                if (!$account) {
                    $conn->rollback();
                    $message = "This reset has expired. Please start again and ask for a new code.";
                    $messageType = "error";
                    $state = ["step" => "email", "email" => "", "started" => time(), "lastSend" => 0, "token" => ""];
                } elseif (password_verify($password, (string) $account["pass"])) {
                    $conn->rollback();
                    $message = "Please choose a password different from your current one.";
                    $messageType = "error";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $userID = (string) $account["userID"];

                    $stmt = $conn->prepare("UPDATE users SET pass = ? WHERE userID = ? AND role = 'admin'");
                    $stmt->bind_param("ss", $hash, $userID);
                    $stmt->execute();
                    $stmt->close();

                    // The code and the reset key can never be used again.
                    $stmt = $conn->prepare("DELETE FROM password_reset_otps WHERE userID = ?");
                    $stmt->bind_param("s", $userID);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    $done = true;
                }
            } catch (Throwable $error) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }
                error_log("Admin password reset (save): " . $error->getMessage());
                $message = "We could not save your new password. Please try again.";
                $messageType = "error";
            }

            if ($done) {
                unset($_SESSION["pwreset"], $_SESSION["pwreset_csrf"]);
                session_regenerate_id(true);
                header("Location: login.php?reset=1");
                exit;
            }
        }
    }
}

$_SESSION["pwreset"] = $state;
$step = $state["step"];

$pageTitle = "Forgot Password";
$pageStyles = ["login.css"];
include __DIR__ . "/partial/head.php";
?>
<body class="login-body">

<main class="login-page">

    <?php include __DIR__ . "/partial/login_brand.php"; ?>

    <section class="login-panel">
        <div class="login-card">
            <h1 class="login-title">Reset your <span>password</span></h1>

            <ol class="pwr-steps" aria-label="Steps">
                <?php foreach (["email" => "Email", "code" => "Code", "password" => "New password"] as $key => $label): ?>
                    <li class="<?= $step === $key ? "is-current" : "" ?>"<?= $step === $key ? ' aria-current="step"' : "" ?>><?= $label ?></li>
                <?php endforeach; ?>
            </ol>

            <?php if ($message !== ""): ?>
                <div class="cs-alert cs-alert-<?= $messageType === "error" ? "error" : "success" ?>" role="<?= $messageType === "error" ? "alert" : "status" ?>">
                    <i class="bi <?= $messageType === "error" ? "bi-exclamation-circle-fill" : "bi-check-circle-fill" ?>" aria-hidden="true"></i>
                    <div class="cs-alert-body"><?= csEscape($message) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($step === "email"): ?>
                <p class="login-intro">Enter the email of your administrator account. We will send you a 6-digit code.</p>
                <form method="POST" class="login-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= csEscape($_SESSION["pwreset_csrf"]) ?>">
                    <input type="hidden" name="action" value="request">
                    <div class="cs-field">
                        <label class="cs-label" for="email">Email</label>
                        <div class="cs-input-icon">
                            <i class="bi bi-envelope" aria-hidden="true"></i>
                            <input type="email" id="email" name="email" class="cs-input" maxlength="254"
                                   placeholder="Enter your email" autocomplete="username" required autofocus>
                        </div>
                    </div>
                    <button type="submit" class="cs-btn cs-btn-primary cs-btn-block" data-busy="Sending…">Send Code</button>
                </form>

            <?php elseif ($step === "code"): ?>
                <p class="login-intro">Enter the 6-digit code sent to <strong><?= csEscape($state["email"]) ?></strong>.</p>
                <form method="POST" class="login-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= csEscape($_SESSION["pwreset_csrf"]) ?>">
                    <input type="hidden" name="action" value="verify">
                    <div class="cs-field">
                        <label class="cs-label" for="code">6-digit code</label>
                        <input type="text" id="code" name="code" class="cs-input pwr-code" inputmode="numeric"
                               pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="••••••" required autofocus>
                    </div>
                    <button type="submit" class="cs-btn cs-btn-primary cs-btn-block" data-busy="Checking…">Check Code</button>
                </form>
                <div class="pwr-links">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csEscape($_SESSION["pwreset_csrf"]) ?>">
                        <input type="hidden" name="action" value="resend">
                        <button type="submit" class="pwr-link">Send a new code</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csEscape($_SESSION["pwreset_csrf"]) ?>">
                        <input type="hidden" name="action" value="start_over">
                        <button type="submit" class="pwr-link">Use another email</button>
                    </form>
                </div>

            <?php else: ?>
                <p class="login-intro">Choose a new password for <strong><?= csEscape($state["email"]) ?></strong>. Do this within a few minutes, before the code expires.</p>
                <form method="POST" class="login-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= csEscape($_SESSION["pwreset_csrf"]) ?>">
                    <input type="hidden" name="action" value="reset">
                    <div class="cs-field">
                        <label class="cs-label" for="newPassword">New password</label>
                        <input type="password" id="newPassword" name="new_password" class="cs-input"
                               minlength="<?= CS_PASSWORD_MIN ?>" maxlength="<?= CS_PASSWORD_MAX ?>" autocomplete="new-password" required autofocus
                               data-password-rules data-confirm="confirmPassword" data-email="<?= csEscape($state["email"]) ?>">
                    </div>
                    <div class="cs-field">
                        <label class="cs-label" for="confirmPassword">Type the new password again</label>
                        <input type="password" id="confirmPassword" name="confirm_password" class="cs-input"
                               maxlength="<?= CS_PASSWORD_MAX ?>" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="cs-btn cs-btn-primary cs-btn-block" data-busy="Saving…">Save New Password</button>
                </form>
            <?php endif; ?>

            <p class="login-help">
                <a href="login.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to log in</a>
            </p>
        </div>
    </section>
</main>

<script src="../js/password-rules.js?v=<?= $csAssetVersion ?>"></script>
<script>
// Avoid double clicks while the request is being sent.
document.querySelectorAll("form [data-busy]").forEach(function (button) {
    button.form.addEventListener("submit", function () {
        window.setTimeout(function () {
            button.disabled = true;
            button.textContent = button.dataset.busy;
        }, 0);
    });
});
</script>
</body>
</html>
