<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/online_training.php';

requireAthleteLogin();
$trainingId = (int)($_POST['training_id'] ?? 0);
$athleteId = (int)getCurrentAthleteId();
$back = BASE_URL . '/online_training.php?id=' . $trainingId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    flash('danger', 'Neplatný požadavek na dokončení tréninku.');
    redirect($back);
}

try {
    $pdo = getDB();
    $trainingStmt = $pdo->prepare("SELECT id, trainer_id, started_at FROM online_trainings WHERE id = ? AND athlete_id = ? AND status = 'in_progress' LIMIT 1");
    $trainingStmt->execute([$trainingId, $athleteId]);
    $training = $trainingStmt->fetch();
    if (!$training || empty($training['started_at'])) throw new RuntimeException('Trénink není rozpracovaný nebo nebyl zahájen.');

    $duration = max(0, time() - (strtotime((string)$training['started_at']) ?: time()));
    $update = $pdo->prepare("UPDATE online_trainings SET status = 'completed', completed_at = NOW(), duration_seconds = ? WHERE id = ? AND athlete_id = ? AND status = 'in_progress'");
    $update->execute([$duration, $trainingId, $athleteId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Trénink se nepodařilo dokončit.');

    $verify = $pdo->prepare("SELECT status, completed_at FROM online_trainings WHERE id = ? AND athlete_id = ? LIMIT 1");
    $verify->execute([$trainingId, $athleteId]);
    $completed = $verify->fetch();
    if (!$completed || $completed['status'] !== 'completed' || empty($completed['completed_at'])) throw new RuntimeException('Dokončení se nepodařilo ověřit.');

    $coachTraining = onlineTrainingLoadForCoach($pdo, $trainingId, (int)$training['trainer_id']);
    if ($coachTraining) onlineTrainingNotifyCompleted($pdo, $coachTraining);
    flash('success', 'Online trénink byl dokončen.');
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}
redirect($back);
