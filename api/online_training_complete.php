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

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$committed = false;
try {
    $pdo = getDB();
    $pdo->beginTransaction();
    $trainingStmt = $pdo->prepare("SELECT id, trainer_id, started_at FROM online_trainings WHERE id = ? AND athlete_id = ? AND status = 'in_progress' LIMIT 1 FOR UPDATE");
    $trainingStmt->execute([$trainingId, $athleteId]);
    $training = $trainingStmt->fetch();
    if (!$training || empty($training['started_at'])) throw new RuntimeException('Trénink není rozpracovaný nebo nebyl zahájen.');

    onlineTrainingSaveAthleteResults($pdo, $trainingId, $athleteId, $_POST);
    $duration = max(0, time() - (strtotime((string)$training['started_at']) ?: time()));
    $update = $pdo->prepare("UPDATE online_trainings SET status = 'completed', completed_at = NOW(), duration_seconds = ? WHERE id = ? AND athlete_id = ? AND status = 'in_progress'");
    $update->execute([$duration, $trainingId, $athleteId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Trénink se nepodařilo dokončit.');

    $verify = $pdo->prepare("SELECT status, completed_at FROM online_trainings WHERE id = ? AND athlete_id = ? LIMIT 1");
    $verify->execute([$trainingId, $athleteId]);
    $completed = $verify->fetch();
    if (!$completed || $completed['status'] !== 'completed' || empty($completed['completed_at'])) throw new RuntimeException('Dokončení se nepodařilo ověřit.');
    $pdo->commit();
    $committed = true;

    $coachTraining = onlineTrainingLoadForCoach($pdo, $trainingId, (int)$training['trainer_id']);
    if ($coachTraining) onlineTrainingNotifyCompleted($pdo, $coachTraining);
    flash('success', 'Online trénink byl dokončen.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($committed) {
        error_log('online_training_complete notification: ' . $e->getMessage());
        flash('warning', 'Trénink byl dokončen, ale upozornění trenérovi se nepodařilo odeslat.');
    } elseif ($ajax) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        flash('danger', $e->getMessage());
    }
}
if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'redirect' => $back], JSON_UNESCAPED_UNICODE);
    exit;
}
redirect($back);
