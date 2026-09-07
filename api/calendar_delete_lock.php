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
$lockSeriesAvailable = (bool)$pdo->query("SHOW COLUMNS FROM coach_calendar_locks LIKE 'series_id'")->fetch();
$owner = $pdo->prepare(
    'SELECT ' . ($lockSeriesAvailable ? 'series_id' : 'NULL AS series_id') . ', note, starts_at, ends_at
     FROM coach_calendar_locks WHERE id = ? AND coach_id = ?'
);
$owner->execute([$lockId, $coachId]);
$lock = $owner->fetch();
if (!$lock) {
    echo json_encode(['success' => false, 'error' => 'Uzamčení nenalezeno']);
    exit;
}

if ($deleteScope === 'series' && !empty($lock['series_id'])) {
    $del = $pdo->prepare('DELETE FROM coach_calendar_locks WHERE coach_id = ? AND series_id = ?');
    $del->execute([$coachId, $lock['series_id']]);
} elseif ($deleteScope === 'series' && !$lockSeriesAvailable) {
    $lockStart = new DateTime((string)$lock['starts_at']);
    $lockEnd = new DateTime((string)$lock['ends_at']);
    $legacyLocksStmt = $pdo->prepare(
        'SELECT id, note, starts_at, ends_at FROM coach_calendar_locks WHERE coach_id = ?'
    );
    $legacyLocksStmt->execute([$coachId]);
    $legacyIds = [];
    foreach ($legacyLocksStmt->fetchAll() as $legacyLock) {
        $candidateStart = new DateTime((string)$legacyLock['starts_at']);
        $candidateEnd = new DateTime((string)$legacyLock['ends_at']);
        if ((string)($legacyLock['note'] ?? '') === (string)($lock['note'] ?? '')
            && $candidateStart->format('N H:i:s') === $lockStart->format('N H:i:s')
            && ($candidateEnd->getTimestamp() - $candidateStart->getTimestamp()) === ($lockEnd->getTimestamp() - $lockStart->getTimestamp())) {
            $legacyIds[] = (int)$legacyLock['id'];
        }
    }
    if (!$legacyIds) {
        $legacyIds[] = $lockId;
    }
    $placeholders = implode(',', array_fill(0, count($legacyIds), '?'));
    $del = $pdo->prepare("DELETE FROM coach_calendar_locks WHERE coach_id = ? AND id IN ($placeholders)");
    $del->execute(array_merge([$coachId], $legacyIds));
} else {
    $del = $pdo->prepare('DELETE FROM coach_calendar_locks WHERE id = ? AND coach_id = ?');
    $del->execute([$lockId, $coachId]);
}

if ($del->rowCount() === 0) {
    echo json_encode(['success' => false, 'error' => 'Uzamčení nenalezeno']);
    exit;
}

echo json_encode(['success' => true]);
