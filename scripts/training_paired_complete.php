<?php
// training_paired_complete.php – Dokončí všechny session v párovém tréninku
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    flash('danger', 'Neplatný požadavek.');
    redirect(BASE_URL . '/dashboard.php');
}

$coachId  = getCurrentCoachId();
$pairedId = intParam($_POST, 'paired_session_id');
$location = normalizeTrainingVenueName($_POST['location'] ?? '');
$pdo      = getDB();

// Načti všechny nedokončené session v tomto párovém tréninku
$stmt = $pdo->prepare(
    'SELECT ts.id, ts.athlete_id, a.first_name, a.last_name
     FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
     WHERE ts.paired_session_id = ? AND a.coach_id = ?
       AND ts.completed_at IS NULL
       AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$pairedId, $coachId]);
$sessions = $stmt->fetchAll();

if (empty($sessions)) {
    flash('danger', 'Párový trénink nenalezen nebo již dokončen.');
    redirect(BASE_URL . '/dashboard.php');
}

if ($location !== '') {
    rememberTrainingVenue($location, $coachId);
}

$now = date('Y-m-d H:i:s');

foreach ($sessions as $s) {
    $notes = trim($_POST['notes_' . $s['id']] ?? '');
    $pdo->prepare(
        'UPDATE training_sessions
         SET completed_at = ?, location = ?, notes = ?
         WHERE id = ?'
    )->execute([$now, $location ?: null, $notes ?: null, $s['id']]);
}

// Sestaví flash zprávu s názvy sportovců (bez HTML)
$names = [];
foreach ($sessions as $s) {
    $fullName = trim((string)$s['first_name'] . ' ' . (string)$s['last_name']);
    if ($fullName !== '') {
        $names[] = $fullName;
    }
}

$namesText = !empty($names) ? implode(' a ', $names) : 'sportovci';
flash('success', 'Párový trénink dokončen! Tréninky uloženy: ' . $namesText . ' 🎉');
redirect(BASE_URL . '/dashboard.php');
