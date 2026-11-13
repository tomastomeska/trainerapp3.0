<?php
$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    $secret = getCronSecret();
    $provided = (string)($_GET['secret'] ?? '');
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        exit('Unauthorized - neplatny secret token.');
    }
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS athlete_transformation_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            athlete_id INT NOT NULL,
            photo_path VARCHAR(255) NOT NULL,
            photo_mime_type VARCHAR(100) NOT NULL,
            measured_on DATE NOT NULL,
            age_years TINYINT UNSIGNED NULL,
            weight_kg DECIMAL(5,2) NULL,
            height_cm DECIMAL(5,2) NULL,
            chest_cm DECIMAL(5,2) NULL,
            waist_cm DECIMAL(5,2) NULL,
            hips_cm DECIMAL(5,2) NULL,
            left_arm_cm DECIMAL(5,2) NULL,
            right_arm_cm DECIMAL(5,2) NULL,
            left_thigh_cm DECIMAL(5,2) NULL,
            right_thigh_cm DECIMAL(5,2) NULL,
            left_calf_cm DECIMAL(5,2) NULL,
            right_calf_cm DECIMAL(5,2) NULL,
            body_fat_percent DECIMAL(5,2) NULL,
            note TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_transformation_athlete_date (athlete_id, measured_on, id),
            CONSTRAINT fk_transformation_entries_athlete
                FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS athlete_transformation_photos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entry_id INT NOT NULL,
            athlete_id INT NOT NULL,
            position TINYINT UNSIGNED NOT NULL,
            photo_path VARCHAR(255) NOT NULL,
            photo_mime_type VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_transformation_photo_slot (entry_id, position),
            CONSTRAINT fk_transformation_photos_entry
                FOREIGN KEY (entry_id) REFERENCES athlete_transformation_entries(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        INSERT IGNORE INTO athlete_transformation_photos (entry_id, athlete_id, position, photo_path, photo_mime_type)
        SELECT id, athlete_id, 1, photo_path, photo_mime_type FROM athlete_transformation_entries
        WHERE TRIM(photo_path) <> ''
    ");

    echo json_encode([
        'success' => true,
        'message' => 'Databáze proměny sportovce v čase je připravena.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
