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

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    $column = $pdo->query("SHOW COLUMNS FROM coach_calendar_event_cancellations LIKE 'billing_issue_number'")->fetch();
    if (!$column) {
        $pdo->exec(
            'ALTER TABLE coach_calendar_event_cancellations
             ADD COLUMN billing_issue_number INT NOT NULL DEFAULT 1 AFTER billing_month'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Číslo původní výzvy u náhradních termínů bylo doplněno.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}