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
$exerciseId = (int)($input['exercise_id'] ?? 0);
$action = (string)($input['action'] ?? '');
$newExerciseId = (int)($input['new_exercise_id'] ?? 0);

if (!in_array($action, ['remove', 'replace'], true)) {
    echo json_encode(['success' => false, 'error' => 'Neplatná akce']);
    exit;
}

if ($sessionId <= 0 || $exerciseId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data']);
    exit;
}

if ($action === 'replace' && $newExerciseId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Vyberte cvik pro nahrazení']);
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

    $stmtSnapshot = $pdo->prepare(
        'SELECT exercise_order, sport_type
         FROM training_session_exercises
         WHERE session_id = ? AND exercise_id = ?
         LIMIT 1'
    );
    $stmtSnapshot->execute([$sessionId, $exerciseId]);
    $snapshot = $stmtSnapshot->fetch();
    if (!$snapshot) {
        throw new RuntimeException('Cvik v tomto tréninku nenalezen');
    }

    if ($action === 'replace') {
        if ($newExerciseId === $exerciseId) {
            throw new RuntimeException('Vybraný cvik je stejný jako původní');
        }

        $stmtNewExercise = $pdo->prepare(
            'SELECT id, name, sport_type, is_timed
             FROM exercises
             WHERE id = ? AND (coach_id = ? OR is_global = 1)
             LIMIT 1'
        );
        $stmtNewExercise->execute([$newExerciseId, $coachId]);
        $newExercise = $stmtNewExercise->fetch();
        if (!$newExercise) {
            throw new RuntimeException('Nový cvik nebyl nalezen');
        }

        $stmtDuplicate = $pdo->prepare(
            'SELECT id
             FROM training_session_exercises
             WHERE session_id = ? AND exercise_id = ?
             LIMIT 1'
        );
        $stmtDuplicate->execute([$sessionId, $newExerciseId]);
        if ($stmtDuplicate->fetch()) {
            throw new RuntimeException('Tento cvik už v tréninku je');
        }

        $pdo->prepare(
            'DELETE FROM session_series WHERE session_id = ? AND exercise_id = ?'
        )->execute([$sessionId, $exerciseId]);

        $stmtReplace = $pdo->prepare(
            'UPDATE training_session_exercises
             SET exercise_id = ?, exercise_name = ?, sport_type = ?, is_timed = ?
             WHERE session_id = ? AND exercise_id = ?'
        );
        $stmtReplace->execute([
            $newExerciseId,
            $newExercise['name'],
            $newExercise['sport_type'] ?? 'standard',
            (int)($newExercise['is_timed'] ?? 0),
            $sessionId,
            $exerciseId,
        ]);
    } else {
        $pdo->prepare(
            'DELETE FROM session_series WHERE session_id = ? AND exercise_id = ?'
        )->execute([$sessionId, $exerciseId]);

        $pdo->prepare(
            'DELETE FROM training_session_exercises WHERE session_id = ? AND exercise_id = ?'
        )->execute([$sessionId, $exerciseId]);

        $stmtOrder = $pdo->prepare(
            'SELECT exercise_id
             FROM training_session_exercises
             WHERE session_id = ?
             ORDER BY exercise_order ASC, exercise_id ASC'
        );
        $stmtOrder->execute([$sessionId]);
        $rows = $stmtOrder->fetchAll();
        $newOrder = 1;
        $stmtReorder = $pdo->prepare(
            'UPDATE training_session_exercises
             SET exercise_order = ?
             WHERE session_id = ? AND exercise_id = ?'
        );
        foreach ($rows as $row) {
            $stmtReorder->execute([$newOrder, $sessionId, (int)$row['exercise_id']]);
            $newOrder++;
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
