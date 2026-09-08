<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/online_training.php';

header('Content-Type: application/json; charset=utf-8');

function onlineSeriesJson(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    requireAthleteLogin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        onlineSeriesJson(['success' => false, 'error' => 'Neplatný požadavek.'], 400);
    }

    $trainingId = (int)($_POST['training_id'] ?? 0);
    $seriesId = (int)($_POST['series_id'] ?? 0);
    $athleteId = (int)getCurrentAthleteId();
    $pdo = getDB();

    $trainingStmt = $pdo->prepare("SELECT id FROM online_trainings WHERE id = ? AND athlete_id = ? AND status = 'in_progress' LIMIT 1");
    $trainingStmt->execute([$trainingId, $athleteId]);
    if (!$trainingStmt->fetch()) onlineSeriesJson(['success' => false, 'error' => 'Trénink není rozpracovaný nebo k němu nemáte přístup.'], 403);

    $seriesStmt = $pdo->prepare('SELECT s.id FROM online_training_series s JOIN online_training_exercises e ON e.id = s.online_training_exercise_id WHERE s.id = ? AND e.online_training_id = ? LIMIT 1');
    $seriesStmt->execute([$seriesId, $trainingId]);
    if (!$seriesStmt->fetch()) onlineSeriesJson(['success' => false, 'error' => 'Série nepatří k tomuto online tréninku.'], 403);

    $weight = ($_POST['weight'] ?? '') === '' ? null : (float)$_POST['weight'];
    $reps = ($_POST['reps'] ?? '') === '' ? null : (int)$_POST['reps'];
    $duration = ($_POST['duration'] ?? '') === '' ? null : (int)$_POST['duration'];
    $note = trim((string)($_POST['note'] ?? '')) ?: null;

    $existingStmt = $pdo->prepare('SELECT id FROM online_training_results WHERE online_training_id = ? AND online_training_series_id = ? ORDER BY id DESC LIMIT 1');
    $existingStmt->execute([$trainingId, $seriesId]);
    $existing = $existingStmt->fetch();
    if ($existing) {
        $saveStmt = $pdo->prepare('UPDATE online_training_results SET actual_weight = ?, actual_reps = ?, actual_duration_seconds = ?, note = ?, updated_at = NOW() WHERE id = ?');
        $saveStmt->execute([$weight, $reps, $duration, $note, (int)$existing['id']]);
    } else {
        $saveStmt = $pdo->prepare('INSERT INTO online_training_results (online_training_id, online_training_series_id, actual_weight, actual_reps, actual_duration_seconds, note) VALUES (?, ?, ?, ?, ?, ?)');
        $saveStmt->execute([$trainingId, $seriesId, $weight, $reps, $duration, $note]);
    }

    $verifyStmt = $pdo->prepare('SELECT id, actual_weight, actual_reps, actual_duration_seconds FROM online_training_results WHERE online_training_id = ? AND online_training_series_id = ? ORDER BY id DESC LIMIT 1');
    $verifyStmt->execute([$trainingId, $seriesId]);
    $saved = $verifyStmt->fetch();
    if (!$saved) onlineSeriesJson(['success' => false, 'error' => 'Záznam se po uložení nepodařilo načíst.'], 500);

    onlineSeriesJson(['success' => true, 'result_id' => (int)$saved['id'], 'weight' => $saved['actual_weight'], 'reps' => $saved['actual_reps'], 'duration' => $saved['actual_duration_seconds']]);
} catch (Throwable $e) {
    error_log('online_training_save_series: ' . $e->getMessage());
    onlineSeriesJson(['success' => false, 'error' => $e->getMessage()], 500);
}
