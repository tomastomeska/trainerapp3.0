<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') mobileJson(['success' => false, 'error' => 'Neplatná metoda.'], 405);

$input = mobileInput();
$athleteId = (int)($input['athlete_id'] ?? 0);
$workoutSetId = (int)($input['workout_set_id'] ?? 0);
if ($athleteId <= 0 || $workoutSetId <= 0) {
    mobileJson(['success' => false, 'error' => 'Vyberte sportovce a tréninkovou sadu.'], 422);
}

$pdo = getDB();

$athleteStmt = $pdo->prepare('SELECT id, first_name, last_name FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
$athleteStmt->execute([$athleteId, $coach['id']]);
$athlete = $athleteStmt->fetch();
if (!$athlete) mobileJson(['success' => false, 'error' => 'Sportovec nebyl nalezen.'], 404);

$setStmt = $pdo->prepare('SELECT id, name FROM workout_sets WHERE id = ? AND coach_id = ? LIMIT 1');
$setStmt->execute([$workoutSetId, $coach['id']]);
$set = $setStmt->fetch();
if (!$set) mobileJson(['success' => false, 'error' => 'Tréninková sada nebyla nalezena.'], 404);

$activeStmt = $pdo->prepare(
    'SELECT ts.id, ts.paired_session_id
     FROM training_sessions ts
     WHERE ts.athlete_id = ?
       AND ts.completed_at IS NULL
       AND ts.deleted_by_coach_at IS NULL
     ORDER BY ts.id DESC LIMIT 1'
);
$activeStmt->execute([$athleteId]);
$active = $activeStmt->fetch();
if ($active) {
    mobileJson([
        'success' => false,
        'error' => 'Sportovec už má rozpracovaný trénink.',
        'existing_session_id' => (int)$active['id'],
    ], 409);
}

$pdo->beginTransaction();
try {
    $insert = $pdo->prepare('INSERT INTO training_sessions (athlete_id, workout_set_id) VALUES (?, ?)');
    $insert->execute([$athleteId, $workoutSetId]);
    $sessionId = (int)$pdo->lastInsertId();

    $copy = $pdo->prepare(
        'INSERT INTO training_session_exercises
            (session_id, exercise_id, exercise_order, exercise_name, sport_type)
         SELECT ?, wse.exercise_id, wse.exercise_order, e.name, e.sport_type
         FROM workout_set_exercises wse
         JOIN exercises e ON e.id = wse.exercise_id
         WHERE wse.workout_set_id = ?
         ORDER BY wse.exercise_order ASC'
    );
    $copy->execute([$sessionId, $workoutSetId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    mobileJson(['success' => false, 'error' => 'Trénink se nepodařilo spustit.'], 500);
}

mobileJson([
    'success' => true,
    'session' => [
        'id' => $sessionId,
        'athlete_id' => $athleteId,
        'athlete_name' => trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']),
        'workout_set_id' => $workoutSetId,
        'workout_set_name' => (string)$set['name'],
        'started_at' => date('Y-m-d H:i:s'),
    ],
]);
