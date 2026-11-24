<?php
/*
 * CitiSense admin — navigation (sidebar on desktop, slide-out menu on
 * tablets/phones). Include right after <body>. Expects an active session
 * and, when available, the $conn database connection.
 */

require_once __DIR__ . "/helpers.php";

$navCurrentPage = basename($_SERVER["PHP_SELF"]);

/* Pages that belong to a navigation item but have their own file. */
$navAliases = [
    "reportStatusOverview.php" => "reports.php",
    "duplicate_report.php" => "reports.php",
];
$activePage = $navAliases[$navCurrentPage] ?? $navCurrentPage;

/*
 * Reports that still need action (Pending + Overdue) for this barangay,
 * shown next to "Reports" so urgent work is visible from every page.
 * The avatar is loaded from the same users table the Settings page edits.
 */
$navNeedsAction = 0;
$navProfilePic = "";
$navDuplicateGroups = 0;
$navUnreadMessages = 0;
$navUnreadAnnouncements = 0;
$navNewFeedback = 0;

if (
    isset($conn) &&
    $conn instanceof mysqli &&
    !empty($_SESSION["barangay_id"])
) {
    try {
        $navStmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM reports
             WHERE barangayID = ?
               AND statusID IN ('STAT001', 'STAT004')"
        );

        if ($navStmt) {
            $navBarangay = (string) $_SESSION["barangay_id"];
            $navStmt->bind_param("s", $navBarangay);
            $navStmt->execute();
            $navNeedsAction = (int) ($navStmt->get_result()->fetch_assoc()["total"] ?? 0);
            $navStmt->close();
        }

        if (!empty($_SESSION["admin_id"])) {
            $navStmt = $conn->prepare(
                "SELECT profilePic FROM users WHERE userID = ? LIMIT 1"
            );

            if ($navStmt) {
                $navAdmin = (string) $_SESSION["admin_id"];
                $navStmt->bind_param("s", $navAdmin);
                $navStmt->execute();
                $navProfilePic = trim((string) ($navStmt->get_result()->fetch_assoc()["profilePic"] ?? ""));
                $navStmt->close();
            }
        }
    } catch (Throwable $navError) {
        error_log("CitiSense sidebar: " . $navError->getMessage());
    }

    /* Counts for the new menu items. Uses cached photo fingerprints only, so
       it stays fast; missing tables (before the update) simply give 0. */
    try {
        require_once __DIR__ . "/duplicates.php";
        require_once __DIR__ . "/messages.php";
        require_once __DIR__ . "/resident_messages.php";
        require_once __DIR__ . "/feedback.php";

        $navBarangay = (string) $_SESSION["barangay_id"];
        $navDuplicateGroups = count(csGroupDuplicatePairs(csFindDuplicatePairs($conn, $navBarangay, false)));
        $navUnreadMessages = csUnreadResidentCount($conn, $navBarangay);
        $navUnreadAnnouncements = csUnreadThreadCount($conn, $navBarangay, "announcement");
        $navNewFeedback = csUnreadFeedbackCount($conn, $navBarangay);
    } catch (Throwable $navError) {
        error_log("CitiSense sidebar counts: " . $navError->getMessage());
    }
}

$navAdminName = trim((string) ($_SESSION["admin_name"] ?? "")) ?: "Administrator";
$navInitials = strtoupper(
    implode("", array_map(
        static fn($part) => mb_substr($part, 0, 1),
        array_slice(preg_split("/\s+/", $navAdminName), 0, 2)
    ))
);

$navSections = [
    "Overview" => [
        ["dashboard.php", "bi-house-door", "Dashboard"],
    ],
    "Reports" => [
        ["reports.php", "bi-clipboard-check", "Reports", $navNeedsAction],
        ["possible_duplicates.php", "bi-files", "Possible Duplicates", $navDuplicateGroups],
        ["feedback.php", "bi-chat-square-heart", "Resident Feedback", $navNewFeedback],
        ["heatmap.php", "bi-map", "Issue Map"],
        ["analytics.php", "bi-bar-chart-line", "Analytics"],
    ],
    "Community" => [
        ["announcements.php", "bi-megaphone", "Announcements", $navUnreadAnnouncements],
        ["messages.php", "bi-chat-dots", "Messages", $navUnreadMessages],
        ["users.php", "bi-people", "Residents"],
    ],
    "Account" => [
        ["settings.php", "bi-gear", "Settings"],
    ],
];
?>

<a class="cs-skip-link" href="#main-content">Skip to main content</a>

<noscript>
    <div class="cs-noscript-warning">
        Some buttons need JavaScript. Please turn JavaScript on in your browser.
    </div>
</noscript>

<!-- Top bar: shown on tablets and phones only -->
<header class="cs-topbar">
    <a class="cs-brand" href="dashboard.php" aria-label="CitiSense home">
        <img src="../css/images/citisense-logo-sm.png" alt="">
        <span class="cs-brand-name">CitiSense</span>
    </a>

    <button
        type="button"
        class="cs-btn cs-btn-ghost cs-btn-sm"
        data-sidebar-toggle
        aria-controls="csSidebar"
        aria-expanded="false"
    >
        <i class="bi bi-list" aria-hidden="true"></i>
        Menu
    </button>
</header>

<div class="cs-sidebar-backdrop" data-sidebar-close></div>

<aside class="cs-sidebar" id="csSidebar" aria-label="Main navigation">

    <a class="cs-brand" href="dashboard.php">
        <img src="../css/images/citisense-logo-sm.png" alt="CitiSense logo">
        <span>
            <span class="cs-brand-name">CitiSense</span>
            <span class="cs-brand-sub">Barangay Admin</span>
        </span>
    </a>

    <nav class="cs-nav">
        <?php foreach ($navSections as $sectionLabel => $items): ?>
            <p class="cs-nav-label"><?= csEscape($sectionLabel) ?></p>

            <?php foreach ($items as $item): ?>
                <?php
                [$href, $icon, $label] = $item;
                $count = (int) ($item[3] ?? 0);
                $isActive = $activePage === $href;
                ?>
                <a
                    href="<?= csEscape($href) ?>"
                    class="cs-nav-item<?= $isActive ? " is-active" : "" ?>"
                    <?= $isActive ? 'aria-current="page"' : "" ?>
                >
                    <i class="bi <?= csEscape($icon) ?>" aria-hidden="true"></i>
                    <span><?= csEscape($label) ?></span>

                    <?php if ($count > 0): ?>
                        <?php
                        $countHint = [
                            "reports.php" => [$count . " reports need action (Pending or Overdue)", "need action"],
                            "possible_duplicates.php" => [$count . " groups of possible duplicates to review", "to review"],
                            "messages.php" => [$count . " residents with new messages", "unread"],
                            "announcements.php" => [$count . " announcements with new comments", "with new comments"],
                            "feedback.php" => [$count . " new or updated feedback from residents", "new"],
                        ][$href] ?? [(string) $count, ""];
                        ?>
                        <span class="cs-nav-count<?= $href === "possible_duplicates.php" ? " is-soft" : "" ?>" title="<?= csEscape($countHint[0]) ?>">
                            <?= $count ?>
                            <span class="cs-sr-only"><?= csEscape($countHint[1]) ?></span>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="cs-sidebar-footer">
        <div class="cs-account">
            <span class="cs-avatar" aria-hidden="true">
                <?php if ($navProfilePic !== ""): ?>
                    <img src="<?= csEscape(csMediaUrl($navProfilePic)) ?>" alt="">
                <?php else: ?>
                    <?= csEscape($navInitials) ?>
                <?php endif; ?>
            </span>
            <div>
                <span class="cs-account-name"><?= csEscape($navAdminName) ?></span>
                <span class="cs-account-role">Administrator</span>
            </div>
        </div>

        <a
            href="logout.php"
            class="cs-btn cs-btn-ghost cs-logout"
            data-confirm="You will need to enter your email and password again to come back."
            data-confirm-title="Log out of CitiSense?"
            data-confirm-button="Log Out"
            data-confirm-icon="bi-box-arrow-right"
        >
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            Log Out
        </a>
    </div>
</aside>
