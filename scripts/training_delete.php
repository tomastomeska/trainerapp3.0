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

// Podporuje single-delete (session_id) i bulk-delete (session_ids[])
$sessionIds = [];
if (!empty($_POST['session_ids']) && is_array($_POST['session_ids'])) {
    foreach ($_POST['session_ids'] as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $sessionIds[] = $id;
        }
    }
}
if ($sessionId > 0) {
    $sessionIds[] = $sessionId;
}
$sessionIds = array_values(array_unique($sessionIds));

if (empty($sessionIds)) {
    flash('warning', 'Nevybral(a) jste žádný trénink ke smazání.');
    redirect(BASE_URL . '/dashboard.php');
}

$placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
$params = array_merge($sessionIds, [$coachId]);

$stmt = $pdo->prepare(
    "SELECT ts.id
     FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
     WHERE ts.id IN ($placeholders)
       AND a.coach_id = ?
       AND ts.deleted_by_coach_at IS NULL"
);
$stmt->execute($params);
$foundIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));

if (empty($foundIds)) {
    flash('danger', 'Trénink nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$updatePlaceholders = implode(',', array_fill(0, count($foundIds), '?'));
$updateParams = array_merge([$coachId], $foundIds);
$pdo->prepare("UPDATE training_sessions SET deleted_by_coach_at = NOW(), deleted_by_coach_id = ? WHERE id IN ($updatePlaceholders)")
    ->execute($updateParams);

if (count($foundIds) === 1) {
    flash('success', 'Trénink byl přesunut do smazaných.');
} else {
    flash('success', 'Tréninky byly přesunuty do smazaných: ' . count($foundIds) . '×.');
}

if ($redirectTo !== '' && strpos($redirectTo, BASE_URL . '/') === 0) {
    redirect($redirectTo);
}

redirect(BASE_URL . '/dashboard.php');
