<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function respondJson(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isLoggedIn()) {
    respondJson(['success' => false, 'error' => 'Nepřihlášen'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['success' => false, 'error' => 'Neplatná metoda'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    respondJson(['success' => false, 'error' => 'Neplatný požadavek'], 400);
}

$coachId = getCurrentCoachId();
$sessionId = (int)($input['session_id'] ?? 0);
$exerciseIds = $input['exercise_ids'] ?? [];

if ($sessionId <= 0 || !is_array($exerciseIds) || empty($exerciseIds)) {
    respondJson(['success' => false, 'error' => 'Neplatná data pro řazení'], 400);
}

$exerciseIds = array_map(static fn($value) => (int)$value, $exerciseIds);
$exerciseIds = array_values($exerciseIds);

if (count($exerciseIds) !== count(array_unique($exerciseIds))) {
    respondJson(['success' => false, 'error' => 'Neplatné pořadí cviků'], 400);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/** @var PDO|null $pdo */
$pdo = null;
try {
    $pdo = getDB();
} catch (Throwable $e) {
    if (function_exists('appLogDbIssue')) {
        appLogDbIssue('admin/api/reorder_session_exercises.php', $e);
    }
    respondJson(['success' => false, 'error' => 'Dočasný problém serveru. Zkuste to prosím znovu.'], 503);
}

if (!$pdo instanceof PDO) {
    respondJson(['success' => false, 'error' => 'Dočasný problém serveru. Zkuste to prosím znovu.'], 503);
}

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
    respondJson(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respondJson(['success' => false, 'error' => $e->getMessage()], 400);
}
