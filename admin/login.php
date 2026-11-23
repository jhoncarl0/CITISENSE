<?php

session_start();

if (isset($_SESSION["admin_id"])) {
    header("Location: dashboard.php");
    exit;
}

require_once __DIR__ . "/partial/helpers.php";

$loginErrors = [
    "empty" => "Please enter your email and password.",
    "invalid" => "The email or password is incorrect. Please check and try again.",
    "notadmin" => "This account does not have admin access. Residents should use the CitiSense mobile app.",
];

$errorKey = (string) ($_GET["error"] ?? "");
$errorMessage = $loginErrors[$errorKey] ?? "";

$pageTitle = "Log In";
$pageStyles = ["login.css"];
include __DIR__ . "/partial/head.php";
?>
<body class="login-body<?= $errorMessage !== "" ? " has-login-error" : "" ?>">

<main class="login-page">

    <?php include __DIR__ . "/partial/login_brand.php"; ?>

    <!-- Login form -->
    <section class="login-panel">
        <div class="login-card">
            <div class="login-mascot-small" data-mascot-stage>
                <div class="login-mascot-float">
                    <img class="login-mascot" src="../css/images/mascot-login.png" alt="CitiSense mascot giving a thumbs up" data-mascot>
                </div>
                <span class="login-mascot-shadow"></span>
            </div>

            <h1>Welcome back to <span>CitiSense</span>, Admin!</h1>
            <p class="login-intro">Log in to manage and respond to community reports.</p>

            <?php if (($_GET["reset"] ?? "") === "1"): ?>
                <div class="cs-alert cs-alert-success login-success" role="status">
                    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                    <div class="cs-alert-body">Your password was changed. You can now log in with your new password.</div>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="cs-alert cs-alert-error" role="alert">
                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                    <div class="cs-alert-body"><?= csEscape($errorMessage) ?></div>
                </div>

                <script>
                    // Remove ?error= so a refresh does not show the message again.
                    const loginUrl = new URL(window.location.href);
                    loginUrl.searchParams.delete("error");
                    window.history.replaceState({}, document.title, loginUrl.pathname);
                </script>
            <?php endif; ?>

            <form action="../api/auth/login.php" method="POST" class="login-form">

                <div class="cs-field">
                    <label class="cs-label" for="email">Email</label>
                    <div class="cs-input-icon">
                        <i class="bi bi-envelope" aria-hidden="true"></i>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="cs-input"
                            placeholder="Enter your email"
                            autocomplete="username"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="cs-field">
                    <label class="cs-label" for="password">Password</label>
                    <div class="cs-input-icon login-password">
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="cs-input"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <button
                            type="button"
                            class="login-toggle-password"
                            id="togglePassword"
                            aria-controls="password"
                            aria-pressed="false"
                        >
                            <i class="bi bi-eye" aria-hidden="true"></i>
                            <span>Show</span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="cs-btn cs-btn-primary cs-btn-block login-submit">
                    Log In
                </button>
            </form>

            <p class="login-help">
                <a href="forgot_password.php"><i class="bi bi-key" aria-hidden="true"></i> Forgot your password?</a>
            </p>
        </div>
    </section>
</main>

<script>
(function () {
    const password = document.getElementById("password");
    const toggle = document.getElementById("togglePassword");

    toggle.addEventListener("click", function () {
        const show = password.type === "password";
        password.type = show ? "text" : "password";
        toggle.setAttribute("aria-pressed", show ? "true" : "false");
        toggle.querySelector("i").className = "bi " + (show ? "bi-eye-slash" : "bi-eye");
        toggle.querySelector("span").textContent = show ? "Hide" : "Show";
        password.focus();
    });

    // Clear the form when returning with the Back button after logging out.
    window.addEventListener("pageshow", function () {
        document.getElementById("email").value = "";
        password.value = "";
        resetSubmit();
    });

    /* ---------- Mascot animation ----------
       Small, calm reactions only. Everything is skipped when the user
       has asked the system for reduced motion. */
    const form = document.querySelector(".login-form");
    const submit = form.querySelector(".login-submit");
    const submitLabel = submit.innerHTML;
    const mascots = Array.prototype.slice.call(document.querySelectorAll("[data-mascot]"));
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    function react(name, duration) {
        if (reduceMotion) return;
        mascots.forEach(function (img) {
            img.classList.remove("is-hop", "is-shake", "is-nod");
            void img.offsetWidth; // restart the animation
            img.classList.add(name);
            window.setTimeout(function () { img.classList.remove(name); }, duration);
        });
    }

    function setMood(name, on) {
        document.querySelectorAll("[data-mascot-stage]").forEach(function (stage) {
            stage.classList.toggle(name, on);
        });
    }

    function resetSubmit() {
        submit.disabled = false;
        submit.innerHTML = submitLabel;
        setMood("is-busy", false);
    }

    // The mascot politely looks away while the password is typed.
    password.addEventListener("focus", function () { setMood("is-shy", true); });
    password.addEventListener("blur", function () { setMood("is-shy", false); });

    // A small nod once the email looks complete.
    const email = document.getElementById("email");
    let nodded = false;
    email.addEventListener("input", function () {
        const valid = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim());
        if (valid && !nodded) react("is-nod", 700);
        nodded = valid;
    });

    // Hop on submit and show that the login is in progress.
    form.addEventListener("submit", function () {
        if (!email.value.trim() || !password.value) return; // browser validation shows the message
        react("is-hop", 650);
        setMood("is-busy", true);
        window.setTimeout(function () {
            submit.disabled = true;
            submit.innerHTML = '<span class="cs-spinner login-spinner" aria-hidden="true"></span> Logging in…';
        }, 0);
    });

    // A short shake when the login failed.
    if (document.body.classList.contains("has-login-error")) {
        window.setTimeout(function () { react("is-shake", 650); }, 650);
    }

    // The big badge follows the cursor with a very light tilt (mouse only).
    const stage = document.querySelector(".login-brand [data-mascot-stage]");
    const brand = document.querySelector(".login-brand");
    if (!reduceMotion && stage && brand && window.matchMedia("(pointer: fine)").matches) {
        let frame = 0;
        brand.addEventListener("mousemove", function (event) {
            if (frame) return;
            frame = window.requestAnimationFrame(function () {
                frame = 0;
                const box = stage.getBoundingClientRect();
                const dx = (event.clientX - (box.left + box.width / 2)) / brand.clientWidth;
                const dy = (event.clientY - (box.top + box.height / 2)) / brand.clientHeight;
                stage.style.setProperty("--tilt-y", (Math.max(-1, Math.min(1, dx * 2)) * 7).toFixed(2) + "deg");
                stage.style.setProperty("--tilt-x", (Math.max(-1, Math.min(1, dy * 2)) * -6).toFixed(2) + "deg");
            });
        });
        brand.addEventListener("mouseleave", function () {
            stage.style.setProperty("--tilt-x", "0deg");
            stage.style.setProperty("--tilt-y", "0deg");
        });
    }
})();
</script>

</body>
</html>
