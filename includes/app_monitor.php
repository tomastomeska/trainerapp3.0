<?php

if (defined('APP_MONITOR_BOOTSTRAPPED')) {
    return;
}
define('APP_MONITOR_BOOTSTRAPPED', true);

if (!defined('APP_SLOW_REQUEST_MS')) {
    define('APP_SLOW_REQUEST_MS', 2500);
}

$GLOBALS['app_monitor_started_at'] = microtime(true);

function appMonitorIpAddress(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if (str_contains((string)$ip, ',')) {
        $ip = trim(explode(',', (string)$ip)[0]);
    }
    return mb_substr((string)$ip, 0, 45);
}

function appMonitorRequestUri(): string {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? ($_SERVER['PHP_SELF'] ?? ''));
    return mb_substr($uri, 0, 255);
}

function appMonitorUserContext(): array {
    if (!empty($_SESSION['superadmin_id'])) {
        return [
            'user_type' => 'admin',
            'user_id' => (int)$_SESSION['superadmin_id'],
            'user_name' => (string)($_SESSION['superadmin_name'] ?? ''),
        ];
    }

    if (!empty($_SESSION['coach_id'])) {
        return [
            'user_type' => 'coach',
            'user_id' => (int)$_SESSION['coach_id'],
            'user_name' => (string)($_SESSION['coach_name'] ?? ''),
        ];
    }

    if (!empty($_SESSION['athlete_id'])) {
        return [
            'user_type' => 'athlete',
            'user_id' => (int)$_SESSION['athlete_id'],
            'user_name' => (string)($_SESSION['athlete_name'] ?? ''),
        ];
    }

    return [
        'user_type' => 'guest',
        'user_id' => null,
        'user_name' => '',
    ];
}

function appMonitorThrottleWindowSeconds(string $eventType): int {
    return match ($eventType) {
        'db_connect_retry' => 1800,
        'slow_request' => 120,
        'http_5xx' => 60,
        default => 0,
    };
}

function appLogEvent(
    string $eventType,
    string $severity,
    string $message,
    array $context = [],
    ?string $userType = null,
    ?int $userId = null,
    ?string $userName = null,
    ?int $durationMs = null,
    ?int $httpStatus = null
): bool {
    static $isLogging = false;

    if ($isLogging) {
        return false;
    }

    if (!function_exists('getDB')) {
        return false;
    }

    $severity = strtolower(trim($severity));
    if (!in_array($severity, ['info', 'warning', 'error', 'critical'], true)) {
        $severity = 'info';
    }

    $userCtx = appMonitorUserContext();
    $finalUserType = $userType ?? $userCtx['user_type'];
    if (!in_array($finalUserType, ['admin', 'coach', 'athlete', 'guest', 'system'], true)) {
        $finalUserType = 'guest';
    }

    $finalUserId = $userId ?? $userCtx['user_id'];
    $finalUserName = trim((string)($userName ?? $userCtx['user_name']));
    if ($finalUserName === '') {
        $finalUserName = null;
    }

    $status = $httpStatus;
    if ($status === null) {
        $resolvedStatus = (int)http_response_code();
        $status = $resolvedStatus > 0 ? $resolvedStatus : null;
    }

    $requestUri = appMonitorRequestUri();

    $contextJson = null;
    if (!empty($context)) {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $contextJson = $encoded;
        }
    }

    try {
        $isLogging = true;
        $pdo = getDB();

        $throttleWindow = appMonitorThrottleWindowSeconds($eventType);
        if ($throttleWindow > 0) {
            try {
                $dupStmt = null;
                $dupParams = [];

                if ($eventType === 'db_connect_retry') {
                    $dupStmt = $pdo->prepare(
                        'SELECT id
                         FROM app_event_log
                         WHERE event_type = ?
                           AND created_at >= (NOW() - INTERVAL ? SECOND)
                         LIMIT 1'
                    );
                    $dupParams = [$eventType, $throttleWindow];
                } elseif ($eventType === 'slow_request') {
                    $dupStmt = $pdo->prepare(
                        'SELECT id
                         FROM app_event_log
                         WHERE event_type = ?
                           AND user_type = ?
                           AND request_uri = ?
                           AND ((user_id IS NULL AND ? IS NULL) OR user_id = ?)
                           AND created_at >= (NOW() - INTERVAL ? SECOND)
                         LIMIT 1'
                    );
                    $dupParams = [$eventType, $finalUserType, $requestUri, $finalUserId, $finalUserId, $throttleWindow];
                } elseif ($eventType === 'http_5xx') {
                    $dupStmt = $pdo->prepare(
                        'SELECT id
                         FROM app_event_log
                         WHERE event_type = ?
                           AND request_uri = ?
                           AND ((http_status IS NULL AND ? IS NULL) OR http_status = ?)
                           AND created_at >= (NOW() - INTERVAL ? SECOND)
                         LIMIT 1'
                    );
                    $dupParams = [$eventType, $requestUri, $status, $status, $throttleWindow];
                }

                if ($dupStmt !== null) {
                    $dupStmt->execute($dupParams);
                    if ($dupStmt->fetch()) {
                        $isLogging = false;
                        return false;
                    }
                }
            } catch (Throwable $e) {
                // Pokud throttle dotaz selze, logovani nesmi spadnout.
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO app_event_log
             (event_type, severity, message, context_json, user_type, user_id, user_name,
              request_uri, request_method, http_status, duration_ms, ip_address, user_agent, created_at)
             VALUES
             (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            mb_substr($eventType, 0, 64),
            $severity,
            mb_substr($message, 0, 500),
            $contextJson,
            $finalUserType,
            $finalUserId,
            $finalUserName,
            $requestUri,
            mb_substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 10),
            $status,
            $durationMs,
            appMonitorIpAddress(),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $isLogging = false;
        return true;
    } catch (Throwable $e) {
        $isLogging = false;
        return false;
    }
}

function appLogDbIssue(string $source, Throwable $e, array $context = []): void {
    $payload = $context;
    $payload['source'] = $source;
    $payload['error_class'] = get_class($e);
    $payload['error_message'] = $e->getMessage();

    appLogEvent('db_error', 'error', 'Databazova chyba: ' . $source, $payload);
}

register_shutdown_function(static function (): void {
    $startedAt = (float)($GLOBALS['app_monitor_started_at'] ?? microtime(true));
    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

    if ($durationMs >= APP_SLOW_REQUEST_MS) {
        appLogEvent(
            'slow_request',
            'warning',
            'Pomaly request',
            ['threshold_ms' => APP_SLOW_REQUEST_MS],
            null,
            null,
            null,
            $durationMs
        );
    }

    $fatal = error_get_last();
    if ($fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        appLogEvent(
            'php_fatal',
            'critical',
            'Fatalni chyba aplikace',
            [
                'error_message' => (string)($fatal['message'] ?? ''),
                'file' => (string)($fatal['file'] ?? ''),
                'line' => (int)($fatal['line'] ?? 0),
                'error_type' => (int)($fatal['type'] ?? 0),
            ],
            null,
            null,
            null,
            $durationMs,
            500
        );
        return;
    }

    $status = (int)http_response_code();
    if ($status >= 500) {
        appLogEvent(
            'http_5xx',
            'error',
            'HTTP odpoved 5xx',
            ['status_code' => $status],
            null,
            null,
            null,
            $durationMs,
            $status
        );
    }
});
