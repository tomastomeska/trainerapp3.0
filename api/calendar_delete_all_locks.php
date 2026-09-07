<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Neplatný požadavek']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný bezpečnostní token']);
    exit;
}

$stmt = getDB()->prepare('DELETE FROM coach_calendar_locks WHERE coach_id = ?');
$stmt->execute([(int)getCurrentCoachId()]);

echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);