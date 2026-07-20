<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    flash('danger', 'Neplatný požadavek.');
    redirect(BASE_URL . '/admin/athletes.php');
}

$athleteId = intParam($_POST, 'athlete_id');
$pdo       = getDB();

$stmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ?');
$stmt->execute([$athleteId]);
if (!$stmt->fetch()) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/admin/athletes.php');
}

// Smazat (cascade smaže i sessions a series)
$pdo->prepare('DELETE FROM athletes WHERE id = ?')
    ->execute([$athleteId]);

flash('success', 'Sportovec byl smazán.');
redirect(BASE_URL . '/admin/athletes.php');
