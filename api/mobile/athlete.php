<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$athleteId = (int)($_GET['id'] ?? 0);
if ($athleteId <= 0) mobileJson(['success' => false, 'error' => 'Chybí ID sportovce.'], 422);

$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
$stmt->execute([$athleteId, $coach['id']]);
$athlete = $stmt->fetch();
if (!$athlete) mobileJson(['success' => false, 'error' => 'Sportovec nebyl nalezen.'], 404);

$trainingStmt = $pdo->prepare(
    'SELECT ts.id, ts.started_at, ts.completed_at, ts.workout_set_id,
            ws.name AS set_name,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.session_id = ts.id) AS total_series
     FROM training_sessions ts
     LEFT JOIN workout_sets ws ON ws.id = ts.workout_set_id
     WHERE ts.athlete_id = ? AND ts.deleted_by_coach_at IS NULL
     ORDER BY COALESCE(ts.started_at, ts.created_at) DESC, ts.id DESC
     LIMIT 20'
);
$trainingStmt->execute([$athleteId]);

$trainings = [];
foreach ($trainingStmt->fetchAll() as $row) {
    $trainings[] = [
        'id' => (int)$row['id'],
        'started_at' => $row['started_at'],
        'completed_at' => $row['completed_at'],
        'workout_set_id' => $row['workout_set_id'] !== null ? (int)$row['workout_set_id'] : null,
        'set_name' => (string)($row['set_name'] ?? ''),
        'total_series' => (int)$row['total_series'],
    ];
}

mobileJson([
    'success' => true,
    'athlete' => $athlete,
    'trainings' => $trainings,
]);
