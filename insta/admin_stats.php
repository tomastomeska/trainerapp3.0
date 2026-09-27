<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input');
    $requestData = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    $pin = is_array($requestData) ? trim((string)($requestData['pin'] ?? '')) : '';
} else {
    $pin = (string)($_GET['pin'] ?? '');
}
if ($pin === '' || !hash_equals(instaGetAdminPin(), $pin)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Neplatny PIN.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stats = instaReadStats();
$profilesOut = [];
foreach (instaGetProfiles() as $profileId => $profile) {
    $profilesOut[] = [
        'id' => $profileId,
        'label' => (string)$profile['label'],
        'description' => (string)($profile['description'] ?? ''),
        'url' => (string)$profile['url'],
        'clicks' => (int)($stats['clicks'][$profileId] ?? 0),
    ];
}

echo json_encode([
    'success' => true,
    'stats' => [
        'visits_total' => (int)($stats['visits_total'] ?? 0),
        'updated_at' => (string)($stats['updated_at'] ?? ''),
    ],
    'profiles' => $profilesOut,
], JSON_UNESCAPED_UNICODE);
