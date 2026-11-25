<?php
/*
 * CitiSense — one timezone rule for the whole admin website.
 *
 *   STORED  (database)  : CS_DB_TIMEZONE, UTC by default.
 *   SHOWN   (screens)   : CS_DISPLAY_TIMEZONE, Asia/Manila by default.
 *
 * - PHP's default timezone is the display timezone, so date("Y-m-d"),
 *   "today", greetings, etc. follow Philippine time.
 * - The MySQL session uses the storage timezone, so NOW(),
 *   CURRENT_TIMESTAMP and TIMESTAMP columns are read and written in it.
 * - Every date the admin site writes goes through csDbNow() / csToDb();
 *   every stored date it shows goes through csDate() / csTimeAgo()
 *   (admin/partial/helpers.php), which convert storage -> display.
 *
 * Environment variables (optional):
 *   CS_DB_TIMEZONE       timezone the stored dates are in. Keep "UTC" when the
 *                        mobile API also saves UTC (its default on Railway).
 *                        Use "Asia/Manila" only if the database holds
 *                        Philippine local times.
 *   CS_DISPLAY_TIMEZONE  timezone shown to administrators (Asia/Manila).
 *
 * Admin > Database Check (admin/db_check.php) shows the clocks side by side.
 */

if (!defined("CS_DB_TIMEZONE")) {
    $csTzValid = static function ($name, string $fallback): string {
        $name = trim((string) $name);
        return $name !== "" && in_array($name, timezone_identifiers_list(), true) ? $name : $fallback;
    };

    define("CS_DB_TIMEZONE", $csTzValid(getenv("CS_DB_TIMEZONE"), "UTC"));
    define("CS_DISPLAY_TIMEZONE", $csTzValid(getenv("CS_DISPLAY_TIMEZONE"), "Asia/Manila"));
    unset($csTzValid);

    date_default_timezone_set(CS_DISPLAY_TIMEZONE);
}

if (!function_exists("csDbTimezone")) {

    function csDbTimezone(): DateTimeZone
    {
        static $tz = null;
        return $tz ??= new DateTimeZone(CS_DB_TIMEZONE);
    }

    function csDisplayTimezone(): DateTimeZone
    {
        static $tz = null;
        return $tz ??= new DateTimeZone(CS_DISPLAY_TIMEZONE);
    }

    /** "+00:00" style offset of the storage timezone right now (for MySQL's time_zone). */
    function csDbOffset(): string
    {
        return (new DateTimeImmutable("now", csDbTimezone()))->format("P");
    }

    /** Current time as stored in the database ("Y-m-d H:i:s", storage timezone). */
    function csDbNow(): string
    {
        return (new DateTimeImmutable("now", csDbTimezone()))->format("Y-m-d H:i:s");
    }

    /**
     * A stored database date/time as a DateTimeImmutable in the display
     * timezone, or null when empty/invalid.
     */
    function csFromDb($value): ?DateTimeImmutable
    {
        $value = trim((string) ($value ?? ""));
        if ($value === "" || str_starts_with($value, "0000-00-00")) {
            return null;
        }
        try {
            // Values with their own offset ("…Z", "+08:00") keep it.
            return (new DateTimeImmutable($value, csDbTimezone()))->setTimezone(csDisplayTimezone());
        } catch (Exception $error) {
            return null;
        }
    }

    /**
     * A date/time typed by the administrator (display timezone, e.g. a
     * datetime-local or date input) converted to the storage format.
     */
    function csToDb($localValue): string
    {
        $value = trim((string) ($localValue ?? ""));
        if ($value === "") {
            return "";
        }
        try {
            return (new DateTimeImmutable(str_replace("T", " ", $value), csDisplayTimezone()))
                ->setTimezone(csDbTimezone())
                ->format("Y-m-d H:i:s");
        } catch (Exception $error) {
            return "";
        }
    }

    /**
     * SQL expression giving $column (a stored date/time) in the display
     * timezone, e.g. for DATE(...) grouping by Philippine calendar day.
     * Uses numeric offsets, so MySQL needs no timezone tables.
     */
    function csSqlLocal(string $column): string
    {
        $display = (new DateTimeImmutable("now", csDisplayTimezone()))->format("P");
        $stored = csDbOffset();
        return $display === $stored ? $column : "CONVERT_TZ(" . $column . ", '" . $stored . "', '" . $display . "')";
    }

    /** Use the storage timezone for this MySQL connection (NOW(), TIMESTAMP columns). */
    function csApplyDbTimezone(mysqli $conn): void
    {
        try {
            $conn->query("SET time_zone = '" . $conn->real_escape_string(csDbOffset()) . "'");
        } catch (Throwable $error) {
            error_log("CitiSense: could not set the MySQL time zone: " . $error->getMessage());
        }
    }
}
