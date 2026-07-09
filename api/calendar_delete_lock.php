<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Neplatná metoda']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data']);
    exit;
}

if (!verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný CSRF token']);
    exit;
}

$lockId = (int)($input['lock_id'] ?? 0);
$coachId = (int)getCurrentCoachId();

if ($lockId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Chybí ID uzamčení']);
    exit;
}

$pdo = getDB();
$del = $pdo->prepare('DELETE FROM coach_calendar_locks WHERE id = ? AND coach_id = ?');
$del->execute([$lockId, $coachId]);

if ($del->rowCount() === 0) {
    echo json_encode(['success' => false, 'error' => 'Uzamčení nenalezeno']);
    exit;
}

echo json_encode(['success' => true]);
