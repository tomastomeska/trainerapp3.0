<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    flash('danger', 'Neplatný požadavek.');
    redirect(BASE_URL . '/dashboard.php');
}

$coachId   = getCurrentCoachId();
$athleteId = intParam($_POST, 'athlete_id');
$pdo       = getDB();

$stmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ?');
$stmt->execute([$athleteId, $coachId]);
if (!$stmt->fetch()) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

// Smazat (cascade smaže i sessions a series)
$pdo->prepare('DELETE FROM athletes WHERE id = ? AND coach_id = ?')
    ->execute([$athleteId, $coachId]);

flash('success', 'Sportovec byl smazán.');
redirect(BASE_URL . '/dashboard.php');
