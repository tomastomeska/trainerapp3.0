<?php
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
if (!$input || !verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný požadavek']);
    exit;
}

$coachId = getCurrentCoachId();
$sessionId = (int)($input['session_id'] ?? 0);
$exerciseIds = $input['exercise_ids'] ?? [];

if ($sessionId <= 0 || !is_array($exerciseIds) || empty($exerciseIds)) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data pro řazení']);
    exit;
}

$exerciseIds = array_map(static fn($value) => (int)$value, $exerciseIds);
$exerciseIds = array_values($exerciseIds);

if (count($exerciseIds) !== count(array_unique($exerciseIds))) {
    echo json_encode(['success' => false, 'error' => 'Neplatné pořadí cviků']);
    exit;
}

$pdo = getDB();

try {
    $pdo->beginTransaction();

    $stmtSession = $pdo->prepare(
        'SELECT ts.id
         FROM training_sessions ts
         JOIN athletes a ON a.id = ts.athlete_id
         WHERE ts.id = ? AND a.coach_id = ?
           AND ts.completed_at IS NULL
           AND ts.deleted_by_coach_at IS NULL'
    );
    $stmtSession->execute([$sessionId, $coachId]);
    if (!$stmtSession->fetch()) {
        throw new RuntimeException('Trénink nenalezen nebo je už ukončen');
    }

    $stmtCurrent = $pdo->prepare(
        'SELECT exercise_id
         FROM training_session_exercises
         WHERE session_id = ?
         ORDER BY exercise_order ASC'
    );
    $stmtCurrent->execute([$sessionId]);
    $currentIds = array_map(static fn($row) => (int)$row['exercise_id'], $stmtCurrent->fetchAll());

    if (count($currentIds) !== count($exerciseIds)) {
        throw new RuntimeException('Počet cviků neodpovídá aktuálnímu tréninku');
    }

    $currentSorted = $currentIds;
    $newSorted = $exerciseIds;
    sort($currentSorted);
    sort($newSorted);
    if ($currentSorted !== $newSorted) {
        throw new RuntimeException('Neplatná množina cviků pro přeuspořádání');
    }

    $stmtUpdate = $pdo->prepare(
        'UPDATE training_session_exercises
         SET exercise_order = ?
         WHERE session_id = ? AND exercise_id = ?'
    );

    foreach ($exerciseIds as $index => $exerciseId) {
        $stmtUpdate->execute([$index + 1, $sessionId, $exerciseId]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
