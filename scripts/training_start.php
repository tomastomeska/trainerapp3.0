<?php
// training_start.php – Zahájí novou tréninkovou session
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    flash('danger', 'Neplatný požadavek.');
    redirect(BASE_URL . '/dashboard.php');
}

$coachId      = getCurrentCoachId();
$athleteId    = intParam($_POST, 'athlete_id');
$workoutSetId = intParam($_POST, 'workout_set_id');
$pdo          = getDB();

// Ověření sportovce
$stmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ?');
$stmt->execute([$athleteId, $coachId]);
if (!$stmt->fetch()) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

// Ověření sady
$stmt = $pdo->prepare('SELECT id FROM workout_sets WHERE id = ? AND coach_id = ?');
$stmt->execute([$workoutSetId, $coachId]);
$workoutSet = $stmt->fetch();
if (!$workoutSet) {
    flash('danger', 'Sada nenalezena.');
    redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
}

// Zkontroluj, zda neexistuje nedokončená session pro tohoto sportovce
$stmt = $pdo->prepare(
    'SELECT id, paired_session_id FROM training_sessions
    WHERE athlete_id = ?
      AND completed_at IS NULL
      AND deleted_by_coach_at IS NULL
     LIMIT 1'
);
$stmt->execute([$athleteId]);
$existing = $stmt->fetch();
if ($existing) {
    // Pokračuj v existující session (párové nebo individuální)
    if ($existing['paired_session_id']) {
        redirect(BASE_URL . '/training_paired_session.php?id=' . $existing['paired_session_id']);
    }
    redirect(BASE_URL . '/training_session.php?id=' . $existing['id']);
}

// Vytvoř novou session
$stmt = $pdo->prepare(
    'INSERT INTO training_sessions (athlete_id, workout_set_id) VALUES (?, ?)'
);
$stmt->execute([$athleteId, $workoutSetId]);
$sessionId = (int)$pdo->lastInsertId();

// Ulož snapshot cviků v době startu tréninku.
$snapshotStmt = $pdo->prepare(
    'INSERT INTO training_session_exercises (session_id, exercise_id, exercise_order, exercise_name, sport_type)
     SELECT ?, wse.exercise_id, wse.exercise_order, e.name, e.sport_type
     FROM workout_set_exercises wse
     JOIN exercises e ON e.id = wse.exercise_id
     WHERE wse.workout_set_id = ?
     ORDER BY wse.exercise_order ASC'
);
$snapshotStmt->execute([$sessionId, $workoutSetId]);

redirect(BASE_URL . '/training_session.php?id=' . $sessionId);
