<?php
// training_new.php – výběr sady a zahájení tréninku (přímý odkaz z dashboardu)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$athleteId = intParam($_GET, 'athlete_id');
$pdo       = getDB();

$stmt = $pdo->prepare('SELECT * FROM athletes WHERE id = ? AND coach_id = ?');
$stmt->execute([$athleteId, $coachId]);
$athlete = $stmt->fetch();

if (!$athlete) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

// Sady
$stmtSets = $pdo->prepare(
    'SELECT ws.*, COUNT(wse.id) AS exercise_count
     FROM workout_sets ws
     LEFT JOIN workout_set_exercises wse ON ws.id = wse.workout_set_id
     WHERE ws.coach_id = ?
     GROUP BY ws.id
     ORDER BY ws.name'
);
$stmtSets->execute([$coachId]);
$workoutSets = $stmtSets->fetchAll();

// Poslední trénink sportovce
$lastSession = getLastSession($athleteId);

redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
