<?php
// training_photo_update.php – Změna nebo smazání fotografie dokončeného tréninku
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    flash('danger', 'Neplatný požadavek.');
    redirect(BASE_URL . '/dashboard.php');
}

$coachId   = getCurrentCoachId();
$sessionId = intParam($_POST, 'session_id');
$action    = $_POST['action'] ?? '';
$pdo       = getDB();

// Ověření vlastnictví session
$stmt = $pdo->prepare(
    'SELECT ts.id, ts.training_photo FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
    WHERE ts.id = ? AND a.coach_id = ?
      AND ts.completed_at IS NOT NULL
      AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Trénink nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$backUrl = BASE_URL . '/training_detail.php?id=' . $sessionId;

// ── Smazání fotografie ───────────────────────────────────────────────────────
if ($action === 'delete') {
    deleteUploadedPhoto($session['training_photo'], 'trainings');
    $pdo->prepare('UPDATE training_sessions SET training_photo = NULL WHERE id = ?')
        ->execute([$sessionId]);
    flash('success', 'Fotografie byla odstraněna.');
    redirect($backUrl);
}

// ── Změna fotografie ─────────────────────────────────────────────────────────
if ($action === 'update') {
    $uploadErr = (int)($_FILES['training_photo']['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadErr === UPLOAD_ERR_NO_FILE) {
        flash('warning', 'Nebyla vybrána žádná fotografie.');
        redirect($backUrl);
    }

    if ($uploadErr !== UPLOAD_ERR_OK) {
        flash('danger', 'Nahrávání selhalo. Zkuste to prosím znovu.');
        redirect($backUrl);
    }

    $maxPhotoSize = 8 * 1024 * 1024;
    if ((int)($_FILES['training_photo']['size'] ?? 0) > $maxPhotoSize) {
        flash('danger', 'Fotografie je příliš velká. Maximum je 8 MB.');
        redirect($backUrl);
    }

    $newPhoto = resizeAndSavePhoto('training_photo', 'trainings');
    if (!$newPhoto) {
        flash('danger', 'Podporujeme pouze obrázky JPG, PNG, GIF nebo WEBP.');
        redirect($backUrl);
    }

    // Smaž starou fotku, ulož novou
    deleteUploadedPhoto($session['training_photo'], 'trainings');
    $pdo->prepare('UPDATE training_sessions SET training_photo = ? WHERE id = ?')
        ->execute([$newPhoto, $sessionId]);
    flash('success', 'Fotografie byla aktualizována.');
    redirect($backUrl);
}

flash('danger', 'Neznámá akce.');
redirect($backUrl);
