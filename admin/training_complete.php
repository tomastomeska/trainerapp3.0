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
$location  = trim($_POST['location'] ?? '');
$notes     = trim($_POST['notes']    ?? '');
$pdo       = getDB();

$trainingPhoto = null;
if (!empty($_FILES['training_photo']) && ($_FILES['training_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $uploadErr = (int)($_FILES['training_photo']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadErr !== UPLOAD_ERR_OK) {
        flash('danger', 'Fotografii se nepodařilo nahrát. Zkuste to prosím znovu.');
        redirect(BASE_URL . '/training_session.php?id=' . $sessionId);
    }

    $maxPhotoSize = 8 * 1024 * 1024;
    if ((int)($_FILES['training_photo']['size'] ?? 0) > $maxPhotoSize) {
        flash('danger', 'Fotografie je příliš velká. Maximum je 8 MB.');
        redirect(BASE_URL . '/training_session.php?id=' . $sessionId);
    }

    $trainingPhoto = resizeAndSavePhoto('training_photo', 'trainings');
    if (!$trainingPhoto) {
        flash('danger', 'Podporujeme pouze obrázky JPG, PNG, GIF nebo WEBP.');
        redirect(BASE_URL . '/training_session.php?id=' . $sessionId);
    }
}

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

try {
    $pdo->prepare(
        'UPDATE training_sessions
         SET completed_at = NOW(), location = ?, notes = ?, training_photo = ?
         WHERE id = ?'
    )->execute([$location ?: null, $notes ?: null, $trainingPhoto, $sessionId]);
} catch (Throwable $e) {
    deleteUploadedPhoto($trainingPhoto, 'trainings');
    throw $e;
}

flash('success', 'Trénink byl úspěšně uložen! 🎉');
redirect(BASE_URL . '/training_detail.php?id=' . $sessionId);
