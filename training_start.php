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
$stmt = $pdo->prepare('SELECT id, online_only FROM athletes WHERE id = ? AND coach_id = ?');
$stmt->execute([$athleteId, $coachId]);
$athlete = $stmt->fetch();
if (!$athlete) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}
if (!empty($athlete['online_only'])) {
    flash('danger', 'Sportovec je pouze ONLINE. Klasický trénink nelze zahájit.');
    redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
}

// Ověření sady (u archivace pustíme pouze aktivní)
$setCheckSql = 'SELECT id FROM workout_sets WHERE id = ? AND ' . ((workoutSetsHasColumn('is_global') && workoutSetsHasColumn('description')) ? '(coach_id = ? OR is_global = 1)' : 'coach_id = ?');
if (workoutSetArchivingEnabled()) {
    $setCheckSql .= ' AND is_active = 1';
}
$stmt = $pdo->prepare($setCheckSql);
$stmt->execute([$workoutSetId, $coachId]);
$workoutSet = $stmt->fetch();
if (!$workoutSet) {
    flash('danger', 'Sada nenalezena nebo je archivovaná.');
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
    flash('warning', 'Sportovec má stále neukončený trénink. Nejprve ho ukončete, teprve potom lze zahájit další.');
    // Přímý POST také vrátí trenéra k existujícímu tréninku (párovému nebo individuálnímu).
    if ($existing['paired_session_id']) {
        redirect(BASE_URL . '/training_paired_session.php?id=' . $existing['paired_session_id']);
    }
    redirect(BASE_URL . '/training_session.php?id=' . $existing['id']);
}

// Vytvoř novou session
$pdo->beginTransaction();
try {
$lock = $pdo->prepare('SELECT online_only FROM athletes WHERE id = ? AND coach_id = ? FOR UPDATE');
$lock->execute([$athleteId, $coachId]);
$lockedAthlete = $lock->fetch();
if (!$lockedAthlete || !empty($lockedAthlete['online_only'])) throw new RuntimeException('Sportovec je pouze ONLINE. Klasický trénink nelze zahájit.');
$activeCheck = $pdo->prepare('SELECT id FROM training_sessions WHERE athlete_id = ? AND completed_at IS NULL AND deleted_by_coach_at IS NULL LIMIT 1');
$activeCheck->execute([$athleteId]);
if ($activeCheck->fetch()) throw new RuntimeException('Sportovec má stále neukončený trénink.');
$stmt = $pdo->prepare(
    'INSERT INTO training_sessions (athlete_id, workout_set_id) VALUES (?, ?)'
);
$stmt->execute([$athleteId, $workoutSetId]);
$sessionId = (int)$pdo->lastInsertId();

// Ulož snapshot cviků v době startu tréninku.
$snapshotStmt = $pdo->prepare(
    'INSERT INTO training_session_exercises (session_id, exercise_id, exercise_order, exercise_name, sport_type, is_timed)
     SELECT ?, wse.exercise_id, wse.exercise_order, e.name, e.sport_type, e.is_timed
     FROM workout_set_exercises wse
     JOIN exercises e ON e.id = wse.exercise_id
     WHERE wse.workout_set_id = ?
     ORDER BY wse.exercise_order ASC'
);
$snapshotStmt->execute([$sessionId, $workoutSetId]);
$pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Training start: ' . $e->getMessage());
    flash('danger', 'Trénink se nepodařilo zahájit: ' . $e->getMessage());
    redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
}

redirect(BASE_URL . '/training_session.php?id=' . $sessionId);
