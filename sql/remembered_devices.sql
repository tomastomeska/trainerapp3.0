-- Back up the database and run once in phpMyAdmin before uploading the PHP files.
CREATE TABLE IF NOT EXISTS `remembered_devices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `selector` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `validator_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `credential_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `coach_id` INT NULL,
    `athlete_id` INT NULL,
    `device_name` VARCHAR(120) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_remembered_selector` (`selector`),
    KEY `idx_remembered_coach` (`coach_id`, `expires_at`),
    KEY `idx_remembered_athlete` (`athlete_id`, `expires_at`),
    CONSTRAINT `fk_remembered_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_remembered_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `athletes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
