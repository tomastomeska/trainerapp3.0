-- Run once in phpMyAdmin after backing up the database.
-- Requires the previously installed push_notification_logs table.
-- No existing tables or records are changed.
CREATE TABLE IF NOT EXISTS `push_delivery_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `log_id` BIGINT UNSIGNED NOT NULL,
    `category` ENUM('messages','calendar','online_training','athlete','payments') NOT NULL,
    `body` VARCHAR(240) NOT NULL,
    `status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lock_token` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `locked_until` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_push_delivery_log` (`log_id`),
    KEY `idx_push_delivery_pending` (`status`, `available_at`, `id`),
    KEY `idx_push_delivery_locked` (`status`, `locked_until`, `id`),
    CONSTRAINT `fk_push_delivery_log`
        FOREIGN KEY (`log_id`) REFERENCES `push_notification_logs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
