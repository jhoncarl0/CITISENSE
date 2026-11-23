<?php
/*
 * CitiSense admin — password rules (one place for every password form).
 *
 * Used by: Settings > Change password, and Forgot password > New password.
 * The same rules are shown and checked in the browser by js/password-rules.js,
 * but the server always checks again here: the browser check is only a help.
 *
 * Rules for an administrator password:
 *   - 10 to 64 characters
 *   - only standard keyboard characters: A–Z, a–z, 0–9 and these symbols
 *       ! " # $ % & ' ( ) * + , - . / : ; < = > ? @ [ \ ] ^ _ ` { | } ~
 *     (no spaces, emojis, accented or other special Unicode characters)
 *   - at least one uppercase letter, one lowercase letter, one number
 *     and one symbol
 *   - must not contain the first part of the account's email address
 *
 * Passwords are stored only as password_hash() hashes (bcrypt) and checked
 * with password_verify(). 64 characters of plain ASCII stay under bcrypt's
 * 72-byte limit, so the whole password always counts.
 */

if (!defined("CS_PASSWORD_MIN")) {
    define("CS_PASSWORD_MIN", 10);
    define("CS_PASSWORD_MAX", 64);
}

if (!function_exists("csPasswordProblems")) {

    /**
     * Plain-language problems with a new password ([] when it is fine).
     * $email: the account's email, so the password cannot contain its name part.
     */
    function csPasswordProblems($password, string $email = ""): array
    {
        if (!is_string($password) || $password === "") {
            return ["Please enter a new password."];
        }

        $problems = [];

        // Any byte outside printable ASCII: spaces, emojis, accents, other Unicode.
        if (!preg_match('/^[\x21-\x7E]+$/', $password)) {
            $problems[] = "Use only letters (A–Z), numbers and keyboard symbols. Spaces, emojis and special characters such as ñ or é are not allowed.";
        }

        $length = strlen($password);
        if ($length < CS_PASSWORD_MIN) {
            $problems[] = "Use at least " . CS_PASSWORD_MIN . " characters.";
        } elseif ($length > CS_PASSWORD_MAX) {
            $problems[] = "Use at most " . CS_PASSWORD_MAX . " characters.";
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $problems[] = "Add at least one uppercase letter (A–Z).";
        }
        if (!preg_match('/[a-z]/', $password)) {
            $problems[] = "Add at least one lowercase letter (a–z).";
        }
        if (!preg_match('/[0-9]/', $password)) {
            $problems[] = "Add at least one number (0–9).";
        }
        if (!preg_match('/[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]/', $password)) {
            $problems[] = "Add at least one symbol, for example ! @ # \$ % or *.";
        }

        $namePart = strtolower((string) strstr($email, "@", true));
        if (strlen($namePart) >= 3 && str_contains(strtolower($password), $namePart)) {
            $problems[] = "Do not use your email name in your password.";
        }

        return $problems;
    }
}
