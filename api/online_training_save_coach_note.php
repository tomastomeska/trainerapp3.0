<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/online_training.php';

requireLogin();

$trainingId = (int)($_POST['training_id'] ?? 0);
$coachId = (int)getCurrentCoachId();
$back = BASE_URL . '/online_training.php?id=' . $trainingId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    flash('danger', 'Neplatný požadavek na uložení poznámky.');
    redirect($back);
}

try {
    $pdo = getDB();
    $trainingStmt = $pdo->prepare('SELECT id, athlete_id, sequence_number FROM online_trainings WHERE id = ? AND trainer_id = ? LIMIT 1');
    $trainingStmt->execute([$trainingId, $coachId]);
    $training = $trainingStmt->fetch();
    if (!$training) {
        throw new RuntimeException('Online trénink nebyl nalezen.');
    }

    $note = trim((string)($_POST['coach_note'] ?? ''));
    $saveStmt = $pdo->prepare('UPDATE online_trainings SET coach_note = ?, updated_at = NOW() WHERE id = ? AND trainer_id = ?');
    $saveStmt->execute([$note !== '' ? $note : null, $trainingId, $coachId]);

    $verifyStmt = $pdo->prepare('SELECT coach_note FROM online_trainings WHERE id = ? AND trainer_id = ? LIMIT 1');
    $verifyStmt->execute([$trainingId, $coachId]);
    $storedNote = $verifyStmt->fetchColumn();
    if ((string)($storedNote ?? '') !== $note) {
        throw new RuntimeException('Poznámku se nepodařilo ověřit po uložení.');
    }

    if ($note !== '') {
        $number = str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT);
        createAthleteNotification((int)$training['athlete_id'], 'Nová poznámka trenéra k online tréninku', 'Trenér přidal poznámku k online tréninku #' . $number . '. Otevřít: ' . $back);
    }

    flash('success', 'Poznámka trenéra byla uložena a zpřístupněna sportovci.');
} catch (Throwable $e) {
    error_log('online_training_save_coach_note: ' . $e->getMessage());
    flash('danger', $e->getMessage());
}

redirect($back);
