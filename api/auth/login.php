<?php

session_start();

require_once __DIR__ . "/../../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../../admin/login.php");
    exit;
}

$email = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";

if ($email === "" || $password === "") {
    header("Location: ../../admin/login.php?error=empty");
    exit;
}

$sql = "SELECT userID, firstName, lastName, email, pass, barangayID, role
        FROM users
        WHERE email = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

$user = $result->num_rows === 1 ? $result->fetch_assoc() : null;
$stmt->close();

/*
 * Check the password first, also when the email is unknown (against a dummy
 * hash, so both cases take the same time). Only a correct password reveals
 * that an account is not an administrator account.
 */
$storedHash = (string) ($user["pass"] ?? '$2y$10$0123456789012345678901uK5Yy1eW7lC3r0oQyZ1Gk3n7aYw9mS2');
$passwordOk = password_verify($password, $storedHash);

if (!$user || !$passwordOk) {
    header("Location: ../../admin/login.php?error=invalid");
    exit;
}

if ($user["role"] !== "admin") {
    header("Location: ../../admin/login.php?error=notadmin");
    exit;
}

// New session ID after logging in (prevents session fixation).
session_regenerate_id(true);

$_SESSION["admin_id"] = $user["userID"];
$_SESSION["admin_name"] = $user["firstName"] . " " . $user["lastName"];
$_SESSION["admin_email"] = $user["email"];
$_SESSION["barangay_id"] = $user["barangayID"];

header("Location: ../../admin/dashboard.php");
exit;