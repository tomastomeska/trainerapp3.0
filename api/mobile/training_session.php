<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();

$action = (string)($_REQUEST['action'] ?? 'get');

if ($action === 'open') {
    try {
    $stmt = $pdo->prepare(
        'SELECT ts.id, ts.athlete_id, ts.started_at, ws.name AS set_name,
                a.first_name, a.last_name
         FROM training_sessions ts
         JOIN athletes a ON a.id = ts.athlete_id
         LEFT JOIN workout_sets ws ON ws.id = ts.workout_set_id
         WHERE a.coach_id = ?
           AND ts.completed_at IS NULL
           AND ts.deleted_by_coach_at IS NULL
         ORDER BY ts.started_at DESC, ts.id DESC'
    );
    $stmt->execute([$coach['id']]);
    $sessions = [];
    foreach ($stmt->fetchAll() as $row) {
        $sessions[] = [
            'id' => (int)$row['id'],
            'athlete_id' => (int)$row['athlete_id'],
            'athlete_name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
            'workout_set_name' => (string)($row['set_name'] ?? 'Trénink'),
            'started_at' => (string)($row['started_at'] ?? ''),
        ];
    }
    mobileJson(['success' => true, 'sessions' => $sessions]);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Rozpracované tréninky se nepodařilo načíst.'], 500);
    }
}

if ($action === 'get') {
    $sessionId = (int)($_GET['id'] ?? 0);
    if ($sessionId <= 0) mobileJson(['success' => false, 'error' => 'Chybí ID tréninku.'], 422);

    $stmt = $pdo->prepare(
        'SELECT ts.*, a.first_name, a.last_name, ws.name AS set_name
         FROM training_sessions ts
         JOIN athletes a ON a.id = ts.athlete_id
         LEFT JOIN workout_sets ws ON ws.id = ts.workout_set_id
         WHERE ts.id = ? AND a.coach_id = ? AND ts.deleted_by_coach_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$sessionId, $coach['id']]);
    $session = $stmt->fetch();
    if (!$session) mobileJson(['success' => false, 'error' => 'Trénink nebyl nalezen.'], 404);

    $stmtEx = $pdo->prepare(
        'SELECT tse.exercise_id, tse.exercise_order, tse.exercise_name, tse.sport_type, tse.is_timed
         FROM training_session_exercises tse
         WHERE tse.session_id = ?
         ORDER BY tse.exercise_order ASC'
    );
    $stmtEx->execute([$sessionId]);
    $exercises = $stmtEx->fetchAll();

    $stmtSeries = $pdo->prepare(
        'SELECT ss.*
         FROM session_series ss
         WHERE ss.session_id = ?
         ORDER BY ss.exercise_id ASC, ss.series_order ASC'
    );
    $stmtSeries->execute([$sessionId]);
    $allSeries = $stmtSeries->fetchAll();

    $seriesByEx = [];
    foreach ($allSeries as $s) {
        $exId = (int)$s['exercise_id'];
        if (!isset($seriesByEx[$exId])) $seriesByEx[$exId] = [];
        $seriesByEx[$exId][] = [
            'id' => (int)$s['id'],
            'series_order' => (int)$s['series_order'],
            'weight' => (float)$s['weight'],
            'equipment_weight' => (float)($s['equipment_weight'] ?? 0),
            'reps' => (int)$s['reps'],
            'assistance_reps' => (int)($s['assistance_reps'] ?? 0),
            'duration_seconds' => $s['duration_seconds'] !== null ? (int)$s['duration_seconds'] : null,
        ];
    }

    $exerciseItems = [];
    foreach ($exercises as $ex) {
        $exId = (int)$ex['exercise_id'];
        $previousSeries = [];
        $previousLabel = '';
        if (function_exists('getLastCompletedSeriesForExercise')) {
            try {
                $lastCompleted = getLastCompletedSeriesForExercise((int)$session['athlete_id'], $exId, $sessionId);
                if (is_array($lastCompleted)) {
                    $completedAt = (string)($lastCompleted['session']['completed_at'] ?? '');
                    $setName = (string)($lastCompleted['session']['set_name'] ?? '');
                    $previousLabel = trim($completedAt . ($setName !== '' ? ' · ' . $setName : ''));
                    foreach ($lastCompleted['series'] as $prev) {
                        $previousSeries[] = [
                            'id' => (int)($prev['id'] ?? 0),
                            'series_order' => (int)($prev['series_order'] ?? 0),
                            'weight' => (float)($prev['weight'] ?? 0) + (float)($prev['equipment_weight'] ?? 0),
                            'reps' => (int)($prev['reps'] ?? 0),
                        ];
                    }
                }
            } catch (Throwable $e) {
            }
        }
        $exerciseItems[] = [
            'exercise_id' => $exId,
            'exercise_order' => (int)$ex['exercise_order'],
            'exercise_name' => (string)$ex['exercise_name'],
            'sport_type' => (string)($ex['sport_type'] ?? 'standard'),
            'is_timed' => (bool)((int)($ex['is_timed'] ?? 0)),
            'series' => $seriesByEx[$exId] ?? [],
            'previous_series' => $previousSeries,
            'previous_label' => $previousLabel,
        ];
    }

    $athleteWeightKg = null;
    $athleteWeightAt = '';
    try {
        $weightStmt = $pdo->prepare(
            'SELECT weight_kg, measured_at FROM athlete_weight_logs WHERE athlete_id = ? ORDER BY measured_at DESC, id DESC LIMIT 1'
        );
        $weightStmt->execute([(int)$session['athlete_id']]);
        $weightRow = $weightStmt->fetch();
        if ($weightRow) {
            $athleteWeightKg = (float)$weightRow['weight_kg'];
            $athleteWeightAt = (string)($weightRow['measured_at'] ?? '');
        }
    } catch (Throwable $e) {
    }

    mobileJson([
        'success' => true,
        'session' => [
            'id' => (int)$session['id'],
            'athlete_id' => (int)$session['athlete_id'],
            'athlete_name' => trim((string)$session['first_name'] . ' ' . (string)$session['last_name']),
            'workout_set_name' => (string)($session['set_name'] ?? 'Trénink'),
            'started_at' => (string)$session['started_at'],
            'completed_at' => (string)($session['completed_at'] ?? ''),
            'athlete_weight_kg' => $athleteWeightKg,
            'athlete_weight_at' => $athleteWeightAt,
            'location' => (string)($session['location'] ?? ''),
            'notes' => (string)($session['notes'] ?? ''),
        ],
        'exercises' => $exerciseItems,
    ]);
}

if ($action === 'save_series' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = mobileInput();
    $sessionId = (int)($input['session_id'] ?? 0);
    $exerciseId = (int)($input['exercise_id'] ?? 0);
    $weight = (float)($input['weight'] ?? 0);
    $reps = (int)($input['reps'] ?? 0);
    $durationSeconds = isset($input['duration_seconds']) ? (int)$input['duration_seconds'] : null;

    if ($sessionId <= 0 || $exerciseId <= 0) {
        mobileJson(['success' => false, 'error' => 'Chybí ID tréninku nebo cviku.'], 422);
    }

    $stmt = $pdo->prepare(
        'SELECT ts.id FROM training_sessions ts
         JOIN athletes a ON ts.athlete_id = a.id
         WHERE ts.id = ? AND a.coach_id = ? AND ts.deleted_by_coach_at IS NULL'
    );
    $stmt->execute([$sessionId, $coach['id']]);
    if (!$stmt->fetch()) mobileJson(['success' => false, 'error' => 'Trénink nebyl nalezen.'], 404);

    try {
        $stmtOrder = $pdo->prepare('SELECT COALESCE(MAX(series_order), 0) + 1 FROM session_series WHERE session_id = ? AND exercise_id = ?');
        $stmtOrder->execute([$sessionId, $exerciseId]);
        $nextOrder = (int)$stmtOrder->fetchColumn();

        $ins = $pdo->prepare('INSERT INTO session_series (session_id, exercise_id, series_order, weight, reps, duration_seconds) VALUES (?, ?, ?, ?, ?, ?)');
        $ins->execute([$sessionId, $exerciseId, $nextOrder, $weight, $reps, $durationSeconds]);
        $seriesId = (int)$pdo->lastInsertId();

        mobileJson(['success' => true, 'series_id' => $seriesId, 'series_order' => $nextOrder]);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Chyba uložení série: ' . $e->getMessage()], 500);
    }
}

if ($action === 'add_exercise' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = mobileInput();
    $sessionId = (int)($input['session_id'] ?? 0);
    $exerciseId = (int)($input['exercise_id'] ?? 0);

    if ($sessionId <= 0 || $exerciseId <= 0) {
        mobileJson(['success' => false, 'error' => 'Chybí ID tréninku nebo cviku.'], 422);
    }

    $stmt = $pdo->prepare(
        'SELECT ts.id FROM training_sessions ts
         JOIN athletes a ON ts.athlete_id = a.id
         WHERE ts.id = ? AND a.coach_id = ? AND ts.deleted_by_coach_at IS NULL'
    );
    $stmt->execute([$sessionId, $coach['id']]);
    if (!$stmt->fetch()) mobileJson(['success' => false, 'error' => 'Trénink nebyl nalezen.'], 404);

    $exStmt = $pdo->prepare('SELECT id, name, sport_type FROM exercises WHERE id = ? LIMIT 1');
    $exStmt->execute([$exerciseId]);
    $ex = $exStmt->fetch();
    if (!$ex) mobileJson(['success' => false, 'error' => 'Cvik nebyl nalezen.'], 404);

    try {
        $stmtOrder = $pdo->prepare('SELECT COALESCE(MAX(exercise_order), 0) + 1 FROM training_session_exercises WHERE session_id = ?');
        $stmtOrder->execute([$sessionId]);
        $nextOrder = (int)$stmtOrder->fetchColumn();

        $hasIsTimed = false;
        try {
            $chk2 = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'is_timed'");
            if ($chk2 && $chk2->fetch()) $hasIsTimed = true;
        } catch (Throwable $e) {}

        if ($hasIsTimed) {
            $ins = $pdo->prepare('INSERT INTO training_session_exercises (session_id, exercise_id, exercise_order, exercise_name, sport_type, is_timed) SELECT ?, ?, ?, name, sport_type, is_timed FROM exercises WHERE id = ?');
            $ins->execute([$sessionId, $exerciseId, $nextOrder, $exerciseId]);
        } else {
            $ins = $pdo->prepare('INSERT INTO training_session_exercises (session_id, exercise_id, exercise_order, exercise_name, sport_type) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$sessionId, $exerciseId, $nextOrder, $ex['name'], $ex['sport_type']]);
        }
        mobileJson(['success' => true]);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Chyba přidání cviku: ' . $e->getMessage()], 500);
    }
}

if ($action === 'delete_series' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = mobileInput();
    $seriesId = (int)($input['series_id'] ?? 0);
    if ($seriesId <= 0) mobileJson(['success' => false, 'error' => 'Chybí ID série.'], 422);

    try {
        $del = $pdo->prepare(
            'DELETE ss FROM session_series ss
             JOIN training_sessions ts ON ts.id = ss.session_id
             JOIN athletes a ON a.id = ts.athlete_id
             WHERE ss.id = ? AND a.coach_id = ?'
        );
        $del->execute([$seriesId, $coach['id']]);
        mobileJson(['success' => true]);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Chyba mazání série: ' . $e->getMessage()], 500);
    }
}

if ($action === 'complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = mobileInput();
    $sessionId = (int)($input['session_id'] ?? 0);
    $location = trim((string)($input['location'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));

    if ($sessionId <= 0) mobileJson(['success' => false, 'error' => 'Chybí ID tréninku.'], 422);

    try {
        $stmt = $pdo->prepare(
            'UPDATE training_sessions ts
             JOIN athletes a ON a.id = ts.athlete_id
             SET ts.completed_at = NOW(), ts.location = ?, ts.notes = ?
             WHERE ts.id = ? AND a.coach_id = ? AND ts.completed_at IS NULL'
        );
        $stmt->execute([$location ?: null, $notes ?: null, $sessionId, $coach['id']]);

        mobileJson(['success' => true]);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Chyba při ukončování tréninku: ' . $e->getMessage()], 500);
    }
}
