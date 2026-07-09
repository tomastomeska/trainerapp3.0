<?php
// api/save_series.php – AJAX endpoint pro uložení série
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Neplatná metoda']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data']);
    exit;
}

if (!verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný požadavek']);
    exit;
}

$coachId    = getCurrentCoachId();
$sessionId  = (int)($input['session_id']  ?? 0);
$exerciseId = (int)($input['exercise_id'] ?? 0);
$weight     = (float)($input['weight']    ?? 0);
$equipmentWeight = (float)($input['equipment_weight'] ?? 0);
$reps       = (int)($input['reps']        ?? 0);
$assist     = (int)($input['assistance_reps'] ?? 0);
$durationSeconds = (int)($input['duration_seconds'] ?? 0);

$pdo = getDB();

// Ověření, že session patří trenérovi (bez omezení completed_at – umožnění editace po ukončení)
$stmt = $pdo->prepare(
    'SELECT ts.id FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
    WHERE ts.id = ? AND a.coach_id = ?
      AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Trénink nenalezen']);
    exit;
}

// Ověření cviku
$stmt2 = $pdo->prepare('SELECT id, is_timed FROM exercises WHERE id = ? AND (coach_id = ? OR is_global = 1)');
$stmt2->execute([$exerciseId, $coachId]);
$exercise = $stmt2->fetch();
if (!$exercise) {
    echo json_encode(['success' => false, 'error' => 'Cvik nenalezen']);
    exit;
}

$isTimed = (int)($exercise['is_timed'] ?? 0) === 1;
if ($isTimed) {
    if ($durationSeconds <= 0) {
        echo json_encode(['success' => false, 'error' => 'Zadejte čas série']);
        exit;
    }
    $reps = 0;
    $assist = 0;
} else {
    $durationSeconds = null;
}

try {
    $stmtOrder = $pdo->prepare(
        'SELECT COALESCE(MAX(series_order), 0) + 1 AS next_order
         FROM session_series
         WHERE session_id = ? AND exercise_id = ?'
    );
    $stmtOrder->execute([$sessionId, $exerciseId]);
    $nextOrder = max(1, (int)($stmtOrder->fetch()['next_order'] ?? 1));

    $stmt3 = $pdo->prepare(
        'INSERT INTO session_series (session_id, exercise_id, series_order, weight, equipment_weight, reps, assistance_reps, duration_seconds)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt3->execute([$sessionId, $exerciseId, $nextOrder, $weight, $equipmentWeight ?: null, $reps, $assist, $durationSeconds]);
    $newId = (int)$pdo->lastInsertId();

    echo json_encode(['success' => true, 'id' => $newId]);
} catch (Throwable $e) {
    error_log('save_series failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Serverová chyba při ukládání série']);
}
