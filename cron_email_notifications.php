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
require_once __DIR__ . '/includes/coach_account_lifecycle.php';

$limitRaw = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;
$limit = max(1, min(300, $limitRaw));

try {
    $results = processEmailNotificationQueue($limit);
    $queueError = null;
} catch (Throwable $e) {
    $results = [];
    $queueError = $e->getMessage();
}

try {
    $coachLifecycle = coachAccountProcessLifecycle(getDB());
    $lifecycleError = null;
} catch (Throwable $e) {
    $coachLifecycle = null;
    $lifecycleError = $e->getMessage();
}

$errors = [];
if ($queueError !== null) {
    error_log('Email notification cron failed: ' . $queueError);
    $errors['email_notifications'] = $queueError;
}
if ($lifecycleError !== null) {
    error_log('Coach account lifecycle in email notification cron failed: ' . $lifecycleError);
    $errors['coach_account_lifecycle'] = $lifecycleError;
}

if ($errors) {
    http_response_code(500);
}

echo json_encode([
    'success' => !$errors,
    'processed' => count($results),
    'results' => $results,
    'coach_account_lifecycle' => $coachLifecycle,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE);
