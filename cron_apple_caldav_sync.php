<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$limitRaw = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
$limit = max(1, min(200, $limitRaw));

try {
    $coachResults = processCoachAppleCaldavSyncQueue($limit);
    $athleteResults = processAthleteAppleCaldavSyncQueue($limit);
    $results = array_merge($coachResults, $athleteResults);
    echo json_encode([
        'success' => true,
        'processed_coach' => count($coachResults),
        'processed_athlete' => count($athleteResults),
        'processed' => count($results),
        'results' => $results,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
