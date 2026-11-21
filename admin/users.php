<?php

session_start();

require_once __DIR__ . "/../config/database.php";


/*
|--------------------------------------------------------------------------
| ADMIN ACCESS
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit;
}


$barangayID = $_SESSION["barangay_id"] ?? "";

if ($barangayID === "") {
    die("Barangay ID is missing from the session.");
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
|
| The search works for:
| - User ID
| - First Name
| - Last Name
|
*/

$search = trim($_GET["search"] ?? "");


/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$limit = 8;

$page = isset($_GET["page"])
    ? max(1, (int) $_GET["page"])
    : 1;


/*
|--------------------------------------------------------------------------
| COUNT RESIDENTS
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*) AS total
    FROM users
    WHERE barangayID = ?
      AND role = 'resident'
";

$countParams = [$barangayID];
$countTypes = "s";


/*
|--------------------------------------------------------------------------
| SEARCH FILTER FOR COUNT
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $searchPattern = "%" . $search . "%";

    $countSql .= "
        AND (
            CAST(userID AS CHAR) LIKE ?
            OR firstName LIKE ?
            OR lastName LIKE ?
        )
    ";

    $countParams[] = $searchPattern;
    $countParams[] = $searchPattern;
    $countParams[] = $searchPattern;

    $countTypes .= "sss";
}


/*
|--------------------------------------------------------------------------
| EXECUTE COUNT
|--------------------------------------------------------------------------
*/

$countStmt = $conn->prepare($countSql);

if (!$countStmt) {
    die("Count query failed: " . $conn->error);
}

$countStmt->bind_param(
    $countTypes,
    ...$countParams
);

$countStmt->execute();

$countResult = $countStmt->get_result();

$countRow = $countResult->fetch_assoc();

$totalUsers = (int) ($countRow["total"] ?? 0);

$countStmt->close();


/*
|--------------------------------------------------------------------------
| TOTAL PAGES
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil($totalUsers / $limit)
);


/*
|--------------------------------------------------------------------------
| FIX PAGE IF OUT OF RANGE
|--------------------------------------------------------------------------
*/

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;


/*
|--------------------------------------------------------------------------
| GET RESIDENTS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        userID,
        firstName,
        lastName,
        email,
        contactNo,
        profilePic,
        dateJoined
    FROM users
    WHERE barangayID = ?
      AND role = 'resident'
";


$params = [$barangayID];
$types = "s";


/*
|--------------------------------------------------------------------------
| SEARCH FILTER
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $searchPattern = "%" . $search . "%";

    $sql .= "
        AND (
            CAST(userID AS CHAR) LIKE ?
            OR firstName LIKE ?
            OR lastName LIKE ?
        )
    ";

    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;

    $types .= "sss";
}


/*
|--------------------------------------------------------------------------
| SORT + PAGINATION
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY dateJoined DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$types .= "ii";


/*
|--------------------------------------------------------------------------
| EXECUTE USER QUERY
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("User query preparation failed: " . $conn->error);
}


$stmt->bind_param(
    $types,
    ...$params
);


$stmt->execute();


$result = $stmt->get_result();


/*
|--------------------------------------------------------------------------
| STORE USERS
|--------------------------------------------------------------------------
*/

$users = [];

while ($row = $result->fetch_assoc()) {

    $users[] = $row;

}


$stmt->close();

require_once __DIR__ . "/partial/helpers.php";

$showingFrom = $totalUsers > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $limit, $totalUsers);

$pageTitle = "Residents";
$pageStyles = ["users.css"];
include __DIR__ . "/partial/head.php";
?>
<body>

<?php include __DIR__ . "/partial/sidebar.php"; ?>

<main class="cs-main" id="main-content">

    <header class="cs-page-header">
        <div>
            <h1>Residents</h1>
            <p>People in your barangay who signed up for the CitiSense app.</p>
        </div>
        <div class="users-total">
            <span class="cs-icon-circle"><i class="bi bi-people" aria-hidden="true"></i></span>
            <span>
                <strong><?= number_format($totalUsers) ?></strong>
                <?= $search !== "" ? "matching" : "registered" ?> <?= $totalUsers === 1 ? "resident" : "residents" ?>
            </span>
        </div>
    </header>

    <section class="cs-card">
        <form method="GET" action="users.php" class="users-search" role="search">
            <div class="cs-field">
                <label class="cs-label" for="userSearch">Search residents</label>
                <div class="users-search-row">
                    <div class="cs-input-icon">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input
                            type="search"
                            id="userSearch"
                            name="search"
                            class="cs-input"
                            placeholder="Type a first name, last name or user ID…"
                            value="<?= csEscape($search) ?>"
                            autocomplete="off"
                        >
                    </div>
                    <button type="submit" class="cs-btn cs-btn-primary">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        Search
                    </button>
                    <?php if ($search !== ""): ?>
                        <a href="users.php" class="cs-btn cs-btn-ghost">Clear</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <?php if ($users): ?>
            <div class="cs-table-wrap">
                <table class="cs-table cs-table-stack users-table">
                    <thead>
                        <tr>
                            <th scope="col">Resident</th>
                            <th scope="col">Email</th>
                            <th scope="col">Contact number</th>
                            <th scope="col">Date joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $profilePic = trim($user["profilePic"] ?? "");
                            $profileImageSrc = "";
                            if ($profilePic !== "") {
                                $profileImageSrc = csMediaUrl($profilePic);
                            }
                            $fullName = trim(($user["firstName"] ?? "") . " " . ($user["lastName"] ?? ""));
                            $initials = strtoupper(mb_substr($user["firstName"] ?? "?", 0, 1) . mb_substr($user["lastName"] ?? "", 0, 1));
                            ?>
                            <tr>
                                <td data-label="">
                                    <div class="user-cell">
                                        <span class="cs-avatar user-avatar">
                                            <?php if ($profileImageSrc !== ""): ?>
                                                <img src="<?= csEscape($profileImageSrc) ?>" alt="">
                                            <?php else: ?>
                                                <?= csEscape($initials) ?>
                                            <?php endif; ?>
                                        </span>
                                        <div>
                                            <span class="cs-cell-strong"><?= csEscape($fullName) ?></span>
                                            <span class="cs-cell-sub">ID <?= csEscape($user["userID"]) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Email">
                                    <?php if (!empty($user["email"])): ?>
                                        <a href="mailto:<?= csEscape($user["email"]) ?>" class="users-link"><?= csEscape($user["email"]) ?></a>
                                    <?php else: ?>
                                        <span class="cs-subtle">Not provided</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Contact number">
                                    <?= !empty($user["contactNo"]) ? csEscape($user["contactNo"]) : '<span class="cs-subtle">Not provided</span>' ?>
                                </td>
                                <td data-label="Date joined">
                                    <?= csEscape(csDate($user["dateJoined"], "M j, Y", "—")) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="cs-card-footer">
                <span>Showing <?= $showingFrom ?>–<?= $showingTo ?> of <?= number_format($totalUsers) ?> residents</span>

                <?php if ($totalPages > 1): ?>
                    <nav class="cs-pagination" aria-label="Resident pages">
                        <a
                            class="cs-page-btn<?= $page <= 1 ? " is-disabled" : "" ?>"
                            href="<?= csEscape(csUrl("users.php", ["page" => $page - 1, "search" => $search])) ?>"
                            <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : "" ?>
                        ><i class="bi bi-chevron-left" aria-hidden="true"></i> Previous</a>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a
                                class="cs-page-btn<?= $i === $page ? " is-active" : "" ?>"
                                href="<?= csEscape(csUrl("users.php", ["page" => $i, "search" => $search])) ?>"
                                aria-label="Page <?= $i ?>"
                                <?= $i === $page ? 'aria-current="page"' : "" ?>
                            ><?= $i ?></a>
                        <?php endfor; ?>

                        <a
                            class="cs-page-btn<?= $page >= $totalPages ? " is-disabled" : "" ?>"
                            href="<?= csEscape(csUrl("users.php", ["page" => $page + 1, "search" => $search])) ?>"
                            <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : "" ?>
                        >Next <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                    </nav>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="cs-empty">
                <span class="cs-empty-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                <h3>No residents found</h3>
                <?php if ($search !== ""): ?>
                    <p>No resident matches “<?= csEscape($search) ?>”. Check the spelling or try another name.</p>
                    <a href="users.php" class="cs-btn cs-btn-secondary">Show All Residents</a>
                <?php else: ?>
                    <p>No residents have signed up yet. They will appear here after they create an account in the CitiSense app.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php include __DIR__ . "/partial/footer.php"; ?>
</main>

<?php include __DIR__ . "/partial/scripts.php"; ?>

</body>
</html>
<?php
$conn->close();
?>
