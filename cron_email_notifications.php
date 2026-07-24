<?php
$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/functions.php';
} else {
    require_once __DIR__ . '/includes/admin_auth.php';

    $secret = getCronSecret();
    $provided = (string)($_GET['secret'] ?? '');
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Unauthorized - neplatny secret token.');
    }
}

header('Content-Type: application/json; charset=utf-8');

$limitRaw = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;
$limit = max(1, min(300, $limitRaw));

try {
    $results = processEmailNotificationQueue($limit);
    echo json_encode([
        'success' => true,
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
