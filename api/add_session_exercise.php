<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function respondJson(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isLoggedIn()) {
    respondJson(['success' => false, 'error' => 'Nepřihlášen'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['success' => false, 'error' => 'Neplatná metoda'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    respondJson(['success' => false, 'error' => 'Neplatný požadavek'], 400);
}

$coachId = getCurrentCoachId();
$sessionId = (int)($input['session_id'] ?? 0);
$exerciseId = (int)($input['exercise_id'] ?? 0);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/** @var PDO|null $pdo */
$pdo = null;
try {
    $pdo = getDB();
} catch (Throwable $e) {
    if (function_exists('appLogDbIssue')) {
        appLogDbIssue('api/add_session_exercise.php', $e);
    }
    respondJson(['success' => false, 'error' => 'Dočasný problém serveru. Zkuste to prosím znovu.'], 503);
}

if (!$pdo instanceof PDO) {
    respondJson(['success' => false, 'error' => 'Dočasný problém serveru. Zkuste to prosím znovu.'], 503);
}

$stmtSession = $pdo->prepare(
    'SELECT ts.id
     FROM training_sessions ts
     JOIN athletes a ON a.id = ts.athlete_id
     WHERE ts.id = ? AND a.coach_id = ?
       AND ts.deleted_by_coach_at IS NULL'
);
$stmtSession->execute([$sessionId, $coachId]);
if (!$stmtSession->fetch()) {
    respondJson(['success' => false, 'error' => 'Trénink nenalezen'], 404);
}

$stmtExercise = $pdo->prepare(
    'SELECT id, name, sport_type, is_timed
     FROM exercises
     WHERE id = ? AND (coach_id = ? OR is_global = 1)'
);
$stmtExercise->execute([$exerciseId, $coachId]);
$exercise = $stmtExercise->fetch();
if (!$exercise) {
    respondJson(['success' => false, 'error' => 'Cvik nenalezen'], 404);
}

$stmtExisting = $pdo->prepare(
    'SELECT id
     FROM training_session_exercises
     WHERE session_id = ? AND exercise_id = ?
     LIMIT 1'
);
$stmtExisting->execute([$sessionId, $exerciseId]);
if ($stmtExisting->fetch()) {
    respondJson(['success' => false, 'error' => 'Tento cvik už v tréninku je'], 409);
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

respondJson(['success' => true]);
