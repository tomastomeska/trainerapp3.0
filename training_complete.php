<?php
// training_complete.php – Dokončí trénink
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    flash('danger', 'Neplatný požadavek.');
    redirect(BASE_URL . '/dashboard.php');
}

$coachId   = getCurrentCoachId();
$sessionId = intParam($_POST, 'session_id');
$location  = normalizeTrainingVenueName($_POST['location'] ?? '');
$notes     = trim($_POST['notes']    ?? '');
$pdo       = getDB();

$uploadedPhotos = [];
try {
    // 1) rychlá fotka přímo z kamery (zachované chování)
    $uploadedPhotos = array_merge($uploadedPhotos, saveTrainingPhotosFromInput('training_photo', 'trainings'));
    // 2) výběr více fotek z galerie
    $uploadedPhotos = array_merge($uploadedPhotos, saveTrainingPhotosFromInput('training_photos', 'trainings'));
} catch (RuntimeException $e) {
    flash('danger', $e->getMessage());
    redirect(BASE_URL . '/training_session.php?id=' . $sessionId);
}

$trainingPhoto = $uploadedPhotos[0] ?? null;

// Ověření vlastnictví
$stmt = $pdo->prepare(
    'SELECT ts.id, ts.athlete_id FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
    WHERE ts.id = ? AND a.coach_id = ?
      AND ts.completed_at IS NULL
      AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Trénink nenalezen nebo již dokončen.');
    redirect(BASE_URL . '/dashboard.php');
}

if ($location !== '') {
    rememberTrainingVenue($location, $coachId);
}

try {
    $pdo->prepare(
        'UPDATE training_sessions
         SET completed_at = NOW(), location = ?, notes = ?, training_photo = ?
         WHERE id = ?'
    )->execute([$location ?: null, $notes ?: null, $trainingPhoto, $sessionId]);

    if (!empty($uploadedPhotos)) {
        addTrainingSessionPhotos($sessionId, $uploadedPhotos);
    }
} catch (Throwable $e) {
    foreach ($uploadedPhotos as $photo) {
        deleteUploadedPhoto($photo, 'trainings');
    }
    throw $e;
}

flash('success', 'Trénink byl úspěšně uložen! 🎉');
redirect(BASE_URL . '/training_detail.php?id=' . $sessionId);
