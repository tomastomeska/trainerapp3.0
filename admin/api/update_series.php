<?php
// admin/api/update_series.php – AJAX endpoint pro úpravu série
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
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data']);
    exit;
}

if (!verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný požadavek']);
    exit;
}

$coachId = getCurrentCoachId();
$seriesId = (int)($input['series_id'] ?? 0);
$weight = (float)($input['weight'] ?? 0);
$equipmentWeight = (float)($input['equipment_weight'] ?? 0);
$reps = (int)($input['reps'] ?? 0);
$assist = (int)($input['assistance_reps'] ?? 0);
$durationSeconds = (int)($input['duration_seconds'] ?? 0);
$pdo = getDB();

$stmt = $pdo->prepare(
    'SELECT ss.id, e.is_timed
     FROM session_series ss
     JOIN training_sessions ts ON ts.id = ss.session_id
     JOIN athletes a ON a.id = ts.athlete_id
     JOIN exercises e ON e.id = ss.exercise_id
     WHERE ss.id = ? AND a.coach_id = ?
       AND ts.completed_at IS NULL
       AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$seriesId, $coachId]);
$series = $stmt->fetch();
if (!$series) {
    echo json_encode(['success' => false, 'error' => 'Série nenalezena']);
    exit;
}

$isTimed = (int)($series['is_timed'] ?? 0) === 1;
if ($isTimed) {
    if ($durationSeconds <= 0) {
        echo json_encode(['success' => false, 'error' => 'Zadejte čas série']);
        exit;
    }
    $reps = 0;
    $assist = 0;
} else {
    $durationSeconds = null;
}

$stmtUpdate = $pdo->prepare(
    'UPDATE session_series
     SET weight = ?, equipment_weight = ?, reps = ?, assistance_reps = ?, duration_seconds = ?
     WHERE id = ?'
);
$stmtUpdate->execute([
    $weight,
    $equipmentWeight > 0 ? $equipmentWeight : null,
    $reps,
    $assist,
    $durationSeconds,
    $seriesId,
]);

echo json_encode(['success' => true]);