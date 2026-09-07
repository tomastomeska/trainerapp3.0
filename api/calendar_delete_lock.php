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
$deleteScope = (string)($input['delete_scope'] ?? 'single');
$coachId = (int)getCurrentCoachId();

if ($lockId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Chybí ID uzamčení']);
    exit;
}

$pdo = getDB();
$owner = $pdo->prepare('SELECT series_id FROM coach_calendar_locks WHERE id = ? AND coach_id = ?');
$owner->execute([$lockId, $coachId]);
$lock = $owner->fetch();
if (!$lock) {
    echo json_encode(['success' => false, 'error' => 'Uzamčení nenalezeno']);
    exit;
}

if ($deleteScope === 'series' && !empty($lock['series_id'])) {
    $del = $pdo->prepare('DELETE FROM coach_calendar_locks WHERE coach_id = ? AND series_id = ?');
    $del->execute([$coachId, $lock['series_id']]);
} else {
    $del = $pdo->prepare('DELETE FROM coach_calendar_locks WHERE id = ? AND coach_id = ?');
    $del->execute([$lockId, $coachId]);
}

if ($del->rowCount() === 0) {
    echo json_encode(['success' => false, 'error' => 'Uzamčení nenalezeno']);
    exit;
}

echo json_encode(['success' => true]);
