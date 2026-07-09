<?php
// training_delete.php - Soft-delete tréninku trenérem (obnovitelné v adminu)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    flash('danger', 'Neplatný požadavek.');
    redirect(BASE_URL . '/dashboard.php');
}

$coachId = getCurrentCoachId();
$sessionId = intParam($_POST, 'session_id');
$redirectTo = trim($_POST['redirect_to'] ?? '');
$pdo = getDB();

$stmt = $pdo->prepare(
    'SELECT ts.id
     FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
     WHERE ts.id = ?
       AND a.coach_id = ?
       AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Trénink nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$pdo->prepare('UPDATE training_sessions SET deleted_by_coach_at = NOW(), deleted_by_coach_id = ? WHERE id = ?')
    ->execute([$coachId, $sessionId]);

flash('success', 'Trénink byl přesunut do smazaných.');

if ($redirectTo !== '' && strpos($redirectTo, BASE_URL . '/') === 0) {
    redirect($redirectTo);
}

redirect(BASE_URL . '/dashboard.php');
