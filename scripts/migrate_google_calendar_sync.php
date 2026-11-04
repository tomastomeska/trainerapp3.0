<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    ensureSchemaUpgrades($pdo);

    echo json_encode([
        'success' => true,
        'message' => 'Google Calendar sync schema migrace probehla v poradku.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
