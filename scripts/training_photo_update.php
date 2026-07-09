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
    if (!empty($session['training_photo'])) {
        deleteTrainingSessionPhotosByFilename($sessionId, (string)$session['training_photo']);
    }
    $pdo->prepare('UPDATE training_sessions SET training_photo = NULL WHERE id = ?')
        ->execute([$sessionId]);
    flash('success', 'Fotografie byla odstraněna.');
    redirect($backUrl);
}

// ── Smazání fotky z galerie ────────────────────────────────────────────────
if ($action === 'delete_gallery') {
    $photoId = intParam($_POST, 'photo_id');
    if ($photoId <= 0) {
        flash('danger', 'Fotka galerie nebyla nalezena.');
        redirect($backUrl);
    }

    $stmtPhoto = $pdo->prepare(
        'SELECT tsp.*
         FROM training_session_photos tsp
         JOIN training_sessions ts ON ts.id = tsp.session_id
         JOIN athletes a ON a.id = ts.athlete_id
         WHERE tsp.id = ? AND tsp.session_id = ? AND a.coach_id = ?'
    );
    $stmtPhoto->execute([$photoId, $sessionId, $coachId]);
    $photoRow = $stmtPhoto->fetch();

    if (!$photoRow) {
        flash('danger', 'Fotka galerie nebyla nalezena.');
        redirect($backUrl);
    }

    deleteUploadedPhoto((string)$photoRow['filename'], 'trainings');
    $pdo->prepare('DELETE FROM training_session_photos WHERE id = ?')->execute([$photoId]);

    if (!empty($session['training_photo']) && (string)$session['training_photo'] === (string)$photoRow['filename']) {
        $pdo->prepare('UPDATE training_sessions SET training_photo = NULL WHERE id = ?')->execute([$sessionId]);
    }

    flash('success', 'Fotka byla odstraněna z galerie.');
    redirect($backUrl);
}

// ── Změna fotografie ─────────────────────────────────────────────────────────
if ($action === 'update') {
    try {
        $photos = saveTrainingPhotosFromInput('training_photo', 'trainings');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
        redirect($backUrl);
    }

    $newPhoto = $photos[0] ?? null;
    if (!$newPhoto) {
        flash('warning', 'Nebyla vybrána žádná fotografie.');
        redirect($backUrl);
    }

    // Smaž starou fotku, ulož novou
    deleteUploadedPhoto($session['training_photo'], 'trainings');
    if (!empty($session['training_photo'])) {
        deleteTrainingSessionPhotosByFilename($sessionId, (string)$session['training_photo']);
    }
    $pdo->prepare('UPDATE training_sessions SET training_photo = ? WHERE id = ?')
        ->execute([$newPhoto, $sessionId]);
    addTrainingSessionPhotos($sessionId, [$newPhoto]);
    flash('success', 'Fotografie byla aktualizována.');
    redirect($backUrl);
}

// ── Přidání více fotek do galerie ──────────────────────────────────────────
if ($action === 'add_gallery') {
    $newPhotos = [];
    try {
        $newPhotos = saveTrainingPhotosFromInput('training_photos', 'trainings');
    } catch (RuntimeException $e) {
        flash('danger', $e->getMessage());
        redirect($backUrl);
    }

    if (empty($newPhotos)) {
        flash('warning', 'Nebyla vybrána žádná fotografie.');
        redirect($backUrl);
    }

    addTrainingSessionPhotos($sessionId, $newPhotos);

    if (empty($session['training_photo'])) {
        $pdo->prepare('UPDATE training_sessions SET training_photo = ? WHERE id = ?')
            ->execute([$newPhotos[0], $sessionId]);
    }

    flash('success', 'Galerie byla rozšířena o ' . count($newPhotos) . ' fotek.');
    redirect($backUrl);
}

flash('danger', 'Neznámá akce.');
redirect($backUrl);
