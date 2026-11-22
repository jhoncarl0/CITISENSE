<?php
/*
 * CitiSense admin — database additions (November 2026 update).
 *
 * Only NEW tables and one new status row are added. No existing table,
 * column or row is changed, so the mobile app keeps working as before.
 *
 *   announcement_images     pictures attached to announcements
 *   announcement_comments   residents' comments on announcements and
 *                           barangay replies (same shape as report_messages)
 *   message_reads           which conversations the barangay has read
 *   resident_messages       direct messages between the barangay and a resident
 *                           that are not about one report (Messages page)
 *   message_replies         which message / comment a reply answers
 *                           ("reply to a specific message", like Messenger)
 *   duplicate_reviews       admin decisions on "possible duplicate" pairs
 *   report_image_hashes     cached photo fingerprints for duplicate checks
 *   stats: 'Rejected'       new report status (STAT005, or the next free ID)
 *
 * csEnsureSchema() runs the statements below once per login session. They
 * all use CREATE TABLE IF NOT EXISTS / insert-if-missing, so running them
 * again is harmless. The same SQL is in database/citisense_update_2026_11.sql
 * for anyone who prefers to run it in phpMyAdmin.
 */

if (!defined("CS_SCHEMA_VERSION")) {
    define("CS_SCHEMA_VERSION", "2026-11-22b");
}

if (!function_exists("csEnsureSchema")) {

    function csSchemaStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `announcement_images` (
                `imageID` int(11) NOT NULL AUTO_INCREMENT,
                `announceID` int(11) NOT NULL,
                `imagePath` varchar(255) NOT NULL,
                `sortOrder` int(11) NOT NULL DEFAULT 0,
                `uploadedAt` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`imageID`),
                KEY `idx_announcement_images_announce` (`announceID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS `announcement_comments` (
                `commentID` int(11) NOT NULL AUTO_INCREMENT,
                `announceID` int(11) NOT NULL,
                `senderType` enum('resident','barangay') NOT NULL,
                `senderID` varchar(50) NOT NULL,
                `message` text NOT NULL,
                `isHidden` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`commentID`),
                KEY `idx_announcement_comments_announce` (`announceID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS `message_reads` (
                `threadType` varchar(20) NOT NULL,
                `threadID` varchar(50) NOT NULL,
                `barangayID` varchar(50) NOT NULL,
                `lastReadAt` datetime NOT NULL,
                PRIMARY KEY (`threadType`, `threadID`, `barangayID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS `resident_messages` (
                `messageID` int(11) NOT NULL AUTO_INCREMENT,
                `barangayID` varchar(50) NOT NULL,
                `userID` varchar(50) NOT NULL,
                `senderType` enum('resident','barangay') NOT NULL,
                `senderID` varchar(50) NOT NULL,
                `message` text NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`messageID`),
                KEY `idx_resident_messages_user` (`barangayID`, `userID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `message_replies` (
                `itemType` varchar(20) NOT NULL,
                `itemID` int(11) NOT NULL,
                `parentType` varchar(20) NOT NULL,
                `parentID` int(11) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`itemType`, `itemID`),
                KEY `idx_message_replies_parent` (`parentType`, `parentID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `duplicate_reviews` (
                `pairKey` varchar(110) NOT NULL,
                `barangayID` varchar(50) NOT NULL,
                `reportA` varchar(50) NOT NULL,
                `reportB` varchar(50) NOT NULL,
                `decision` varchar(20) NOT NULL,
                `decidedBy` varchar(50) NOT NULL,
                `decidedAt` datetime NOT NULL,
                PRIMARY KEY (`pairKey`),
                KEY `idx_duplicate_reviews_barangay` (`barangayID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS `report_image_hashes` (
                `imagePath` varchar(255) NOT NULL,
                `fileSize` int(11) NOT NULL,
                `fileTime` int(11) NOT NULL,
                `hash` varchar(16) NOT NULL,
                `computedAt` datetime NOT NULL,
                PRIMARY KEY (`imagePath`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        ];
    }

    /** ID of the "Rejected" status, adding the row if it is missing. */
    function csRejectedStatusID(mysqli $conn): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $stmt = $conn->prepare(
            "SELECT statusID FROM stats WHERE LOWER(TRIM(statusName)) = 'rejected' LIMIT 1"
        );
        $stmt->execute();
        $found = (string) ($stmt->get_result()->fetch_assoc()["statusID"] ?? "");
        $stmt->close();

        if ($found !== "") {
            return $cached = $found;
        }

        $stmt = $conn->prepare("SELECT statusID FROM stats");
        $stmt->execute();
        $existing = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), "statusID");
        $stmt->close();

        $number = 5;
        do {
            $newID = sprintf("STAT%03d", $number++);
        } while (in_array($newID, $existing, true));

        $stmt = $conn->prepare("INSERT INTO stats (statusID, statusName) VALUES (?, 'Rejected')");
        $stmt->bind_param("s", $newID);
        $stmt->execute();
        $stmt->close();

        return $cached = $newID;
    }

    /**
     * Create the new tables if needed. Returns true when the database is
     * ready; on failure (e.g. no CREATE permission) logs the reason and
     * returns false so pages can show a clear message.
     */
    function csEnsureSchema(mysqli $conn): bool
    {
        if (($_SESSION["cs_schema_version"] ?? "") === CS_SCHEMA_VERSION) {
            return true;
        }

        try {
            foreach (csSchemaStatements() as $sql) {
                $conn->query($sql);
            }

            csRejectedStatusID($conn);

            $_SESSION["cs_schema_version"] = CS_SCHEMA_VERSION;
            return true;
        } catch (Throwable $error) {
            error_log("CitiSense schema update failed: " . $error->getMessage());
            return false;
        }
    }

    /** Message shown when the tables could not be created automatically. */
    function csSchemaNotice(): string
    {
        return '<div class="cs-alert cs-alert-warning" role="alert">'
            . '<i class="bi bi-database-exclamation" aria-hidden="true"></i>'
            . '<div class="cs-alert-body"><strong class="cs-alert-title">A one-time database update is needed</strong>'
            . 'The new tables could not be created automatically. Please import '
            . '<code>database/citisense_update_2026_11.sql</code> in phpMyAdmin, then reload this page.</div></div>';
    }
}
