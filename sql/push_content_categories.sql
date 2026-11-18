-- Back up the database first. Check the existing category definitions before running.
-- Adds values only; existing preferences and queued deliveries are retained.
ALTER TABLE push_notification_settings
    MODIFY category ENUM('messages','calendar','online_training','athlete','payments',
                         'surveys','info_channel','gallery')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE push_delivery_queue
    MODIFY category ENUM('messages','calendar','online_training','athlete','payments',
                         'surveys','info_channel','gallery')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
