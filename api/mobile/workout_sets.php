<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();
$input = mobileInput();
$action = (string)($_REQUEST['action'] ?? $input['action'] ?? '');

function mobileWorkoutExerciseIds(array $input): array
{
    $raw = $input['exercise_ids'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function mobileSaveCoachWorkoutSet(PDO $pdo, int $coachId, string $name, array $exerciseIds, int $setId = 0): array
{
    if ($name === '') {
        return ['ok' => false, 'error' => 'Zadejte název sady.'];
    }
    if ($exerciseIds === []) {
        return ['ok' => false, 'error' => 'Přidejte alespoň jeden cvik.'];
    }
    if (count($exerciseIds) !== count(array_unique($exerciseIds))) {
        return ['ok' => false, 'error' => 'Stejný cvik může být v sadě jen jednou.'];
    }
    $inClause = implode(',', array_fill(0, count($exerciseIds), '?'));
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM exercises WHERE id IN ($inClause) AND (coach_id = ? OR is_global = 1)");
        $check->execute([...$exerciseIds, $coachId]);
    } catch (Throwable $e) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM exercises WHERE id IN ($inClause) AND coach_id = ?");
        $check->execute([...$exerciseIds, $coachId]);
    }
    if ((int)$check->fetchColumn() !== count($exerciseIds)) {
        return ['ok' => false, 'error' => 'Neplatné cviky.'];
    }
    $runCheck = $pdo->prepare("SELECT COUNT(*) FROM exercises WHERE id IN ($inClause) AND sport_type IN ('run_outdoor', 'run_treadmill')");
    $runCheck->execute($exerciseIds);
    if ((int)$runCheck->fetchColumn() > 0 && count($exerciseIds) !== 1) {
        return ['ok' => false, 'error' => 'Běh (venku i na páse) může být v sadě pouze jako samostatný cvik.'];
    }
    if ($setId > 0) {
        $owned = $pdo->prepare('SELECT id FROM workout_sets WHERE id = ? AND coach_id = ?');
        $owned->execute([$setId, $coachId]);
        if (!$owned->fetch()) {
            return ['ok' => false, 'error' => 'Sada nenalezena.'];
        }
    }

    $pdo->beginTransaction();
    try {
        if ($setId > 0) {
            $pdo->prepare('UPDATE workout_sets SET name = ? WHERE id = ? AND coach_id = ?')->execute([$name, $setId, $coachId]);
            $pdo->prepare('DELETE FROM workout_set_exercises WHERE workout_set_id = ?')->execute([$setId]);
        } else {
            $pdo->prepare('INSERT INTO workout_sets (coach_id, name) VALUES (?, ?)')->execute([$coachId, $name]);
            $setId = (int)$pdo->lastInsertId();
        }
        $insert = $pdo->prepare('INSERT INTO workout_set_exercises (workout_set_id, exercise_id, exercise_order) VALUES (?, ?, ?)');
        foreach (array_values($exerciseIds) as $order => $exerciseId) {
            $insert->execute([$setId, $exerciseId, $order + 1]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Sadu se nepodařilo uložit.'];
    }
    return ['ok' => true, 'id' => $setId];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create', 'update'], true)) {
    $saved = mobileSaveCoachWorkoutSet(
        $pdo,
        (int)$coach['id'],
        trim((string)($input['name'] ?? '')),
        mobileWorkoutExerciseIds($input),
        $action === 'update' ? (int)($input['id'] ?? 0) : 0
    );
    if (!$saved['ok']) {
        mobileJson(['success' => false, 'error' => $saved['error']], 400);
    }
    mobileJson(['success' => true, 'id' => (int)$saved['id']]);
}

if ($action === 'mine' || (string)($_GET['scope'] ?? '') === 'own') {
    if (function_exists('ensureFlexibleWorkoutSet')) {
        try {
            ensureFlexibleWorkoutSet((int)$coach['id']);
        } catch (Throwable $e) {
        }
    }
    $setStmt = $pdo->prepare(
        'SELECT id, name, COALESCE(is_active, 1) AS is_active
         FROM workout_sets
         WHERE coach_id = ?
         ORDER BY CASE WHEN name = "Flexibilní sada" THEN 0 ELSE 1 END, name ASC'
    );
    try {
        $setStmt->execute([(int)$coach['id']]);
        $setRows = $setStmt->fetchAll();
    } catch (Throwable $e) {
        $setStmt = $pdo->prepare(
            'SELECT id, name, 1 AS is_active
             FROM workout_sets
             WHERE coach_id = ?
             ORDER BY CASE WHEN name = "Flexibilní sada" THEN 0 ELSE 1 END, name ASC'
        );
        $setStmt->execute([(int)$coach['id']]);
        $setRows = $setStmt->fetchAll();
    }
    $sets = [];
    foreach ($setRows as $row) {
        $sets[(int)$row['id']] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'is_active' => (int)$row['is_active'] === 1,
            'exercises' => [],
        ];
    }
    if ($sets !== []) {
        $placeholders = implode(',', array_fill(0, count($sets), '?'));
        $exerciseStmt = $pdo->prepare(
            "SELECT wse.workout_set_id, e.id, e.name, e.sport_type
             FROM workout_set_exercises wse
             JOIN exercises e ON e.id = wse.exercise_id
             WHERE wse.workout_set_id IN ($placeholders)
             ORDER BY wse.exercise_order ASC, wse.id ASC"
        );
        $exerciseStmt->execute(array_keys($sets));
        foreach ($exerciseStmt->fetchAll() as $exercise) {
            $setId = (int)$exercise['workout_set_id'];
            $sets[$setId]['exercises'][] = [
                'id' => (int)$exercise['id'],
                'name' => (string)$exercise['name'],
                'sport_type' => (string)($exercise['sport_type'] ?? 'standard'),
            ];
        }
    }
    mobileJson(['success' => true, 'sets' => array_values($sets)]);
}

$stmt = $pdo->prepare(
    'SELECT ws.id, ws.name, ws.description, ws.is_active, COALESCE(ws.is_global, 0) AS is_global,
            COUNT(wse.id) AS exercise_count
     FROM workout_sets ws
     LEFT JOIN workout_set_exercises wse ON wse.workout_set_id = ws.id
     WHERE (ws.coach_id = ? OR ws.is_global = 1 OR ws.coach_id IS NULL)
       AND COALESCE(ws.is_active, 1) = 1
     GROUP BY ws.id, ws.name, ws.description, ws.is_active, ws.is_global
     ORDER BY CASE WHEN ws.name = "Flexibilní sada" THEN 0 ELSE 1 END, ws.is_global DESC, ws.name ASC'
);
$stmt->execute([$coach['id']]);

mobileJson(['success' => true, 'workout_sets' => array_map(static function(array $row): array {
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'description' => (string)($row['description'] ?? ''),
        'is_active' => (bool)((int)$row['is_active']),
        'is_global' => (bool)((int)$row['is_global']),
        'exercise_count' => (int)$row['exercise_count'],
    ];
}, $stmt->fetchAll())]);
