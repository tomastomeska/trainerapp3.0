<?php

function acquireCronLock(string $lockKey): array {
    $safeKey = preg_replace('/[^a-z0-9_\-]/i', '_', $lockKey) ?: 'default';
    $lockPath = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'trainerapp_' . $safeKey . '.lock';
    $handle = @fopen($lockPath, 'c');
    if (!is_resource($handle)) {
        return ['acquired' => false, 'handle' => null, 'path' => $lockPath, 'reason' => 'lock_file_unavailable'];
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return ['acquired' => false, 'handle' => null, 'path' => $lockPath, 'reason' => 'already_running'];
    }
    return ['acquired' => true, 'handle' => $handle, 'path' => $lockPath, 'reason' => 'acquired'];
}

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/functions.php';
} else {
    require_once __DIR__ . '/includes/admin_auth.php';
    $secret = getCronSecret();
    $provided = $_GET['secret'] ?? '';
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');

$limitRaw = isset($_GET['limit']) ? (int)$_GET['limit'] : 8;
$limit = max(1, min(20, $limitRaw));

$lock = acquireCronLock('calendar_workers');
if (empty($lock['acquired'])) {
    echo json_encode([
        'success' => true,
        'skipped' => true,
        'reason' => (string)($lock['reason'] ?? 'already_running'),
        'processed' => 0,
        'results' => [],
    ]);
    exit;
}

register_shutdown_function(static function () use ($lock): void {
    if (isset($lock['handle']) && is_resource($lock['handle'])) {
        @flock($lock['handle'], LOCK_UN);
        @fclose($lock['handle']);
    }
});

try {
    $results = processCoachGoogleCalendarSyncQueue($limit);
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
