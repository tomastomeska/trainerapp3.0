<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$raw = file_get_contents('php://input');
$data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($data)) {
    $data = [];
}

$pin = trim((string)($data['pin'] ?? ''));
if ($pin === '' || !hash_equals(instaGetAdminPin(), $pin)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Neplatny PIN.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stats = instaResetStats();

echo json_encode([
    'success' => true,
    'message' => 'Statistiky byly resetovany.',
    'stats' => [
        'visits_total' => (int)($stats['visits_total'] ?? 0),
        'updated_at' => (string)($stats['updated_at'] ?? ''),
    ],
], JSON_UNESCAPED_UNICODE);
