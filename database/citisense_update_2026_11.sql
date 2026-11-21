-- ---------------------------------------------------------------------
-- CitiSense — admin website update (November 2026)
-- ---------------------------------------------------------------------
-- Safe to run on the existing `citisense` database (tested against the
-- citisense-4.sql export). It only ADDS tables and one status row;
-- nothing existing is changed or deleted, and running it twice is harmless.
--
-- You do not have to run this by hand: the admin website creates these
-- tables automatically the first time an administrator logs in. Use this
-- file if your MySQL user is not allowed to create tables from PHP.
-- ---------------------------------------------------------------------

-- Pictures attached to announcements (several per announcement).
-- imagePath is relative to the project folder, like reportimages.imagePath,
-- e.g. 'uploads/announcements/9f1c…e2.jpg'.
CREATE TABLE IF NOT EXISTS `announcement_images` (
  `imageID` int(11) NOT NULL AUTO_INCREMENT,
  `announceID` int(11) NOT NULL,
  `imagePath` varchar(255) NOT NULL,
  `sortOrder` int(11) NOT NULL DEFAULT 0,
  `uploadedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`imageID`),
  KEY `idx_announcement_images_announce` (`announceID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Comments on announcements. Same shape as the existing report_messages
-- table: residents write rows with senderType = 'resident' (from the mobile
-- app), barangay officials reply with senderType = 'barangay'.
CREATE TABLE IF NOT EXISTS `announcement_comments` (
  `commentID` int(11) NOT NULL AUTO_INCREMENT,
  `announceID` int(11) NOT NULL,
  `senderType` enum('resident','barangay') NOT NULL,
  `senderID` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `isHidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`commentID`),
  KEY `idx_announcement_comments_announce` (`announceID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Which conversations the barangay has already read (for "unread" counts).
-- threadType is 'report' (report_messages) or 'announcement'.
CREATE TABLE IF NOT EXISTS `message_reads` (
  `threadType` varchar(20) NOT NULL,
  `threadID` varchar(50) NOT NULL,
  `barangayID` varchar(50) NOT NULL,
  `lastReadAt` datetime NOT NULL,
  PRIMARY KEY (`threadType`,`threadID`,`barangayID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Admin decisions on possible duplicate reports ('not_duplicate' / 'duplicate').
CREATE TABLE IF NOT EXISTS `duplicate_reviews` (
  `pairKey` varchar(110) NOT NULL,
  `barangayID` varchar(50) NOT NULL,
  `reportA` varchar(50) NOT NULL,
  `reportB` varchar(50) NOT NULL,
  `decision` varchar(20) NOT NULL,
  `decidedBy` varchar(50) NOT NULL,
  `decidedAt` datetime NOT NULL,
  PRIMARY KEY (`pairKey`),
  KEY `idx_duplicate_reviews_barangay` (`barangayID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cached photo fingerprints used to spot similar report photos.
CREATE TABLE IF NOT EXISTS `report_image_hashes` (
  `imagePath` varchar(255) NOT NULL,
  `fileSize` int(11) NOT NULL,
  `fileTime` int(11) NOT NULL,
  `hash` varchar(16) NOT NULL,
  `computedAt` datetime NOT NULL,
  PRIMARY KEY (`imagePath`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- New report status. Skipped if a 'Rejected' status already exists.
INSERT INTO `stats` (`statusID`, `statusName`)
SELECT 'STAT005', 'Rejected' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `stats` WHERE LOWER(TRIM(`statusName`)) = 'rejected')
  AND NOT EXISTS (SELECT 1 FROM `stats` WHERE `statusID` = 'STAT005');
