<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();

$stmt = $pdo->prepare(
    'SELECT ws.id, ws.name, ws.description, ws.is_active,
            COUNT(wse.id) AS exercise_count
     FROM workout_sets ws
     LEFT JOIN workout_set_exercises wse ON wse.workout_set_id = ws.id
     WHERE ws.coach_id = ? AND COALESCE(ws.is_active, 1) = 1
     GROUP BY ws.id, ws.name, ws.description, ws.is_active
     ORDER BY ws.name ASC'
);
$stmt->execute([$coach['id']]);

mobileJson(['success' => true, 'workout_sets' => array_map(static function(array $row): array {
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'description' => (string)($row['description'] ?? ''),
        'is_active' => (bool)((int)$row['is_active']),
        'exercise_count' => (int)$row['exercise_count'],
    ];
}, $stmt->fetchAll())]);
