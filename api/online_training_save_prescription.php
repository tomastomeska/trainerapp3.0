<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function onlinePrescriptionJson(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    requireLogin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        onlinePrescriptionJson(['success' => false, 'error' => 'Neplatný požadavek.'], 400);
    }

    $coachId = (int)getCurrentCoachId();
    $trainingId = (int)($_POST['training_id'] ?? 0);
    $seriesId = (int)($_POST['series_id'] ?? 0);
    $pdo = getDB();

    $trainingStmt = $pdo->prepare("SELECT ot.id FROM online_trainings ot WHERE ot.id = ? AND ot.trainer_id = ? AND ot.status = 'created' LIMIT 1");
    $trainingStmt->execute([$trainingId, $coachId]);
    if (!$trainingStmt->fetch()) onlinePrescriptionJson(['success' => false, 'error' => 'Draft nebyl nalezen nebo už byl odeslán.'], 403);

    $seriesStmt = $pdo->prepare('SELECT s.id FROM online_training_series s JOIN online_training_exercises e ON e.id = s.online_training_exercise_id WHERE s.id = ? AND e.online_training_id = ? LIMIT 1');
    $seriesStmt->execute([$seriesId, $trainingId]);
    if (!$seriesStmt->fetch()) onlinePrescriptionJson(['success' => false, 'error' => 'Série nepatří k tomuto draftu.'], 403);

    $weight = ($_POST['weight'] ?? '') === '' ? null : (float)$_POST['weight'];
    $reps = ($_POST['reps'] ?? '') === '' ? null : (int)$_POST['reps'];
    $duration = ($_POST['duration'] ?? '') === '' ? null : (int)$_POST['duration'];
    $save = $pdo->prepare('UPDATE online_training_series SET prescribed_weight = ?, prescribed_reps = ?, prescribed_duration_seconds = ? WHERE id = ?');
    $save->execute([$weight, $reps, $duration, $seriesId]);

    $verify = $pdo->prepare('SELECT prescribed_weight, prescribed_reps, prescribed_duration_seconds FROM online_training_series WHERE id = ? LIMIT 1');
    $verify->execute([$seriesId]);
    $saved = $verify->fetch();
    if (!$saved) onlinePrescriptionJson(['success' => false, 'error' => 'Hodnoty se po uložení nepodařilo načíst.'], 500);

    onlinePrescriptionJson(['success' => true, 'weight' => $saved['prescribed_weight'], 'reps' => $saved['prescribed_reps'], 'duration' => $saved['prescribed_duration_seconds']]);
} catch (Throwable $e) {
    error_log('online_training_save_prescription: ' . $e->getMessage());
    onlinePrescriptionJson(['success' => false, 'error' => $e->getMessage()], 500);
}
