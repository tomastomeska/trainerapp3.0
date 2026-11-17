<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
    || empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off'
    || !is_string($_GET['secret'] ?? null)
    || preg_match('/^[a-f0-9]{64}$/D', $_GET['secret']) !== 1
) {
    http_response_code(403);
    echo '{"success":false}';
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/push_notifications.php';

try {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute(['push_cron_secret']);
    $secret = $stmt->fetchColumn();
    if (!is_string($secret) || preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1
        || !hash_equals($secret, $_GET['secret'])
    ) {
        http_response_code(403);
        echo '{"success":false}';
        exit;
    }
    $processed = pushProcessQueue($pdo);
    echo json_encode(['success' => true, 'processed' => $processed], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Push cron failed: ' . get_class($e));
    http_response_code(503);
    echo '{"success":false}';
}
