<?php
// api/delete_series.php – AJAX endpoint pro smazání série
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

$input    = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný požadavek']);
    exit;
}

$seriesId = (int)($input['series_id'] ?? 0);
$coachId  = getCurrentCoachId();
$pdo      = getDB();

// Ověření vlastnictví
$stmt = $pdo->prepare(
    'SELECT ss.id FROM session_series ss
     JOIN training_sessions ts ON ss.session_id = ts.id
     JOIN athletes a ON ts.athlete_id = a.id
    WHERE ss.id = ? AND a.coach_id = ?
      AND ts.completed_at IS NULL
      AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$seriesId, $coachId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Série nenalezena nebo trénink dokončen']);
    exit;
}

$pdo->prepare('DELETE FROM session_series WHERE id = ?')->execute([$seriesId]);

echo json_encode(['success' => true]);
