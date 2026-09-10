<?php
$isCli = php_sapi_name() === 'cli';
if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/functions.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    $secret = getCronSecret();
    if (!hash_equals($secret, (string)($_GET['secret'] ?? ''))) {
        http_response_code(403);
        exit('Unauthorized - neplatny secret token.');
    }
}

header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = getDB();
    if (!$pdo->query("SHOW COLUMNS FROM workout_sets LIKE 'is_global'")->fetch()) {
        $pdo->exec('ALTER TABLE workout_sets ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER coach_id');
    }
    if (!$pdo->query("SHOW COLUMNS FROM workout_sets LIKE 'description'")->fetch()) {
        $pdo->exec('ALTER TABLE workout_sets ADD COLUMN description TEXT NULL AFTER name');
    }
    $pdo->exec('ALTER TABLE workout_sets MODIFY coach_id INT NULL');
    $pdo->exec('UPDATE workout_sets SET is_global = 0 WHERE is_global IS NULL');
    echo json_encode(['success' => true, 'message' => 'Migrace globálních tréninkových sad proběhla v pořádku.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}