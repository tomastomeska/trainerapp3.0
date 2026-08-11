<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Podpora pro coach i athlete session
$userType = null;
$userId   = 0;

if (isLoggedIn()) {
    $userType = 'coach';
    $userId   = (int)getCurrentCoachId();
} elseif (athleteIsLoggedIn()) {
    $userType = 'athlete';
    $userId   = (int)getCurrentAthleteId();
}

if (!$userType || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$videoId  = (int)($_POST['video_id'] ?? 0);
$watched  = max(0, (int)($_POST['watched_seconds'] ?? 0));
$duration = isset($_POST['duration_seconds']) && is_numeric($_POST['duration_seconds'])
    ? max(0, (int)$_POST['duration_seconds'])
    : null;

if ($videoId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_video_id']);
    exit;
}

$pdo = getDB();
$ok  = mycoachAppSaveVideoProgress($pdo, $userType, $userId, $videoId, $watched, $duration);

echo json_encode(['ok' => $ok]);
