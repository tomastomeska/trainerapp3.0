<?php
require_once __DIR__ . '/../includes/admin_auth.php';

requireAdminLogin();

header('Content-Type: application/json; charset=utf-8');

$pdo    = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {

    // Sportovci daného trenéra
    case 'athletes':
        $coachId = (int)($_GET['coach_id'] ?? 0);
        if (!$coachId) { echo '[]'; exit; }
        $stmt = $pdo->prepare(
            'SELECT id, first_name, last_name
             FROM athletes
             WHERE coach_id = ?
             ORDER BY last_name, first_name'
        );
        $stmt->execute([$coachId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    // Sady daného trenéra
    case 'sets':
        $coachId = (int)($_GET['coach_id'] ?? 0);
        if (!$coachId) { echo '[]'; exit; }
        $stmt = $pdo->prepare(
            'SELECT id, name
             FROM workout_sets
             WHERE coach_id = ?
             ORDER BY name'
        );
        $stmt->execute([$coachId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    // Cviky v sadě
    case 'exercises':
        $setId = (int)($_GET['set_id'] ?? 0);
        if (!$setId) { echo '[]'; exit; }
        $stmt = $pdo->prepare(
            'SELECT wse.exercise_id, wse.exercise_order, e.name AS exercise_name
             FROM workout_set_exercises wse
             JOIN exercises e ON wse.exercise_id = e.id
             WHERE wse.workout_set_id = ?
             ORDER BY wse.exercise_order'
        );
        $stmt->execute([$setId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Neznámá akce']);
}
