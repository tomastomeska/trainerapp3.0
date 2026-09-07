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
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Unauthorized - neplatny secret token.');
    }
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    $pdo->exec(
        "ALTER TABLE admin_gallery_files
         MODIFY visibility ENUM('all_coaches','specific_coaches','all_athletes','specific_athletes','all_users') NOT NULL DEFAULT 'all_coaches'"
    );
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_gallery_file_athletes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id INT NOT NULL,
            athlete_id INT NOT NULL,
            UNIQUE KEY uq_admin_gallery_file_athlete (file_id, athlete_id),
            CONSTRAINT fk_admin_gallery_file_athletes_file
                FOREIGN KEY (file_id) REFERENCES admin_gallery_files(id) ON DELETE CASCADE,
            CONSTRAINT fk_admin_gallery_file_athletes_athlete
                FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    echo json_encode([
        'success' => true,
        'message' => 'Publika galerie administrátora byla rozšířena.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}