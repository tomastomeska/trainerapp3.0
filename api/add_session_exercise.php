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
if (!$input || !verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný požadavek']);
    exit;
}

$coachId = getCurrentCoachId();
$sessionId = (int)($input['session_id'] ?? 0);
$exerciseId = (int)($input['exercise_id'] ?? 0);
$pdo = getDB();

$stmtSession = $pdo->prepare(
    'SELECT ts.id
     FROM training_sessions ts
     JOIN athletes a ON a.id = ts.athlete_id
     WHERE ts.id = ? AND a.coach_id = ?
       AND ts.deleted_by_coach_at IS NULL'
);
$stmtSession->execute([$sessionId, $coachId]);
if (!$stmtSession->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Trénink nenalezen']);
    exit;
}

$stmtExercise = $pdo->prepare(
    'SELECT id, name, sport_type, is_timed
     FROM exercises
     WHERE id = ? AND (coach_id = ? OR is_global = 1)'
);
$stmtExercise->execute([$exerciseId, $coachId]);
$exercise = $stmtExercise->fetch();
if (!$exercise) {
    echo json_encode(['success' => false, 'error' => 'Cvik nenalezen']);
    exit;
}

$stmtExisting = $pdo->prepare(
    'SELECT id
     FROM training_session_exercises
     WHERE session_id = ? AND exercise_id = ?
     LIMIT 1'
);
$stmtExisting->execute([$sessionId, $exerciseId]);
if ($stmtExisting->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Tento cvik už v tréninku je']);
    exit;
}

$stmtOrder = $pdo->prepare(
    'SELECT COALESCE(MAX(exercise_order), 0)
     FROM training_session_exercises
     WHERE session_id = ?'
);
$stmtOrder->execute([$sessionId]);
$nextOrder = (int)$stmtOrder->fetchColumn() + 1;

$stmtInsert = $pdo->prepare(
    'INSERT INTO training_session_exercises (session_id, exercise_id, exercise_order, exercise_name, sport_type, is_timed)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$stmtInsert->execute([
    $sessionId,
    $exerciseId,
    $nextOrder,
    $exercise['name'],
    $exercise['sport_type'] ?? 'standard',
    (int)($exercise['is_timed'] ?? 0),
]);

echo json_encode(['success' => true]);
