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
    $column = $pdo->query("SHOW COLUMNS FROM coach_calendar_events LIKE 'title_type'")->fetch();
    if (!$column) {
        $pdo->exec(
            "ALTER TABLE coach_calendar_events
             ADD COLUMN title_type ENUM('training','consultation','other','group_lesson') NOT NULL DEFAULT 'training'
             AFTER color_key"
        );
    }

    $pdo->exec("UPDATE coach_calendar_events SET title_type = 'consultation' WHERE custom_title = 'Konzultační hodina'");
    $pdo->exec("UPDATE coach_calendar_events SET title_type = 'other' WHERE custom_title = 'Jiné'");
    $pdo->exec("UPDATE coach_calendar_events SET title_type = 'group_lesson' WHERE custom_title = 'Skupinová lekce'");

    echo json_encode([
        'success' => true,
        'message' => 'Typ kalendářních událostí byl doplněn.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}