<?php
$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';

    $secret = getCronSecret();
    $provided = (string)($_GET['secret'] ?? '');
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Unauthorized - neplatny secret token.');
    }
}

require_once __DIR__ . '/../includes/calendar_replacements.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    calendarMigrateReplacementRevocation($pdo);
    echo json_encode([
        'success' => true,
        'message' => 'Evidence zrušení nároku na náhradu byla doplněna.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}