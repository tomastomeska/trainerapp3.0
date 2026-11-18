<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/push_endpoint.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function pushSubscriptionRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    pushSubscriptionRespond(405, ['success' => false, 'error' => 'method_not_allowed']);
}

$isCoach = isLoggedIn();
$isAthlete = athleteIsLoggedIn();
if ($isCoach === $isAthlete) {
    pushSubscriptionRespond(401, ['success' => false, 'error' => 'not_authenticated']);
}

$coachId = $isCoach ? (int)getCurrentCoachId() : null;
$athleteId = $isAthlete ? (int)getCurrentAthleteId() : null;
try {
    $pdo = getDB();
    if ($isCoach) {
        $accountStmt = $pdo->prepare('SELECT is_active, force_password_change FROM coaches WHERE id = ? LIMIT 1');
        $accountStmt->execute([$coachId]);
        $account = $accountStmt->fetch();
        if (!$account || (int)$account['is_active'] !== 1 || (int)$account['force_password_change'] === 1) {
            pushSubscriptionRespond(403, ['success' => false, 'error' => 'account_not_available']);
        }
    } else {
        $accountStmt = $pdo->prepare(
            'SELECT a.force_password_change
             FROM athletes a
             JOIN coaches c ON c.id = a.coach_id
             WHERE a.id = ? AND c.is_active = 1
             LIMIT 1'
        );
        $accountStmt->execute([$athleteId]);
        $account = $accountStmt->fetch();
        if (!$account || (int)$account['force_password_change'] === 1) {
            pushSubscriptionRespond(403, ['success' => false, 'error' => 'account_not_available']);
        }
    }
} catch (Throwable $e) {
    error_log('Push subscription account verification failed: ' . $e->getMessage());
    pushSubscriptionRespond(503, ['success' => false, 'error' => 'push_service_unavailable']);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 8192) {
    pushSubscriptionRespond(413, ['success' => false, 'error' => 'request_too_large']);
}

$rawInput = file_get_contents('php://input');
if (!is_string($rawInput) || strlen($rawInput) > 8192) {
    pushSubscriptionRespond(413, ['success' => false, 'error' => 'request_too_large']);
}

try {
    $input = json_decode($rawInput, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    pushSubscriptionRespond(400, ['success' => false, 'error' => 'invalid_json']);
}

if (!is_array($input) || !verifyCsrf(is_string($input['csrf_token'] ?? null) ? $input['csrf_token'] : '')) {
    pushSubscriptionRespond(403, ['success' => false, 'error' => 'csrf']);
}

$ownerSql = $isCoach ? '`coach_id` = ? AND `athlete_id` IS NULL' : '`athlete_id` = ? AND `coach_id` IS NULL';
$ownerId = $isCoach ? $coachId : $athleteId;
$action = is_string($input['action'] ?? null) ? $input['action'] : '';

function pushSubscriptionEndpointHash(mixed $value): ?string
{
    if (!is_string($value) || $value === '' || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $parts = parse_url($value);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || preg_match('/[\x00-\x20\x7f]/', $value) === 1
    ) {
        return null;
    }

    return hash('sha256', $value, true);
}

function pushSubscriptionDecodeKey(mixed $value, int $expectedLength): ?string
{
    if (!is_string($value) || $value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
        return null;
    }

    $base64 = strtr($value, '-_', '+/');
    $base64 .= str_repeat('=', (4 - (strlen($base64) % 4)) % 4);
    $decoded = base64_decode($base64, true);
    if (!is_string($decoded) || strlen($decoded) !== $expectedLength) {
        return null;
    }

    return $decoded;
}

function pushSubscriptionDeviceType(mixed $value): string
{
    return is_string($value) && in_array($value, ['desktop', 'android', 'ios'], true) ? $value : 'unknown';
}

function pushSubscriptionDeviceName(mixed $value): string
{
    if (!is_string($value)) {
        return 'Zařízení';
    }

    $name = preg_replace('/[\x00-\x1f\x7f]/', '', trim($value));
    if (!is_string($name) || $name === '') {
        return 'Zařízení';
    }

    if (preg_match('/^.{0,120}/us', $name, $matches) !== 1) {
        return 'Zařízení';
    }

    return $matches[0] !== '' ? $matches[0] : 'Zařízení';
}

try {
    if ($action === 'get_unread_count') {
        require_once __DIR__ . '/../includes/push_unread.php';
        $count = pushUnreadChatCount($pdo, $isCoach ? 'coach' : 'athlete', (int)$ownerId);
        pushSubscriptionRespond(200, ['success' => true, 'unread_count' => $count]);
    }

    if ($action === 'get_public_key') {
        $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute(['push_vapid_keys']);
        $stored = $stmt->fetchColumn();
        $keys = is_string($stored) ? json_decode($stored, true, 4, JSON_THROW_ON_ERROR) : null;
        $publicKey = is_array($keys) ? ($keys['publicKey'] ?? null) : null;
        $decoded = pushSubscriptionDecodeKey($publicKey, 65);
        if ($decoded === null || ord($decoded[0]) !== 4) {
            throw new RuntimeException('Push public key is unavailable or invalid.');
        }
        pushSubscriptionRespond(200, ['success' => true, 'public_key' => $publicKey]);
    }

    if ($action === 'register') {
        $subscription = $input['subscription'] ?? null;
        $keys = is_array($subscription) && is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
        $endpoint = is_array($subscription) ? ($subscription['endpoint'] ?? null) : null;
        $endpointHash = pushSubscriptionEndpointHash($endpoint);
        $p256dh = pushSubscriptionDecodeKey($keys['p256dh'] ?? null, 65);
        $auth = pushSubscriptionDecodeKey($keys['auth'] ?? null, 16);
        if ($endpointHash === null || !pushEndpointIsAllowed($endpoint)
            || $p256dh === null || ord($p256dh[0]) !== 4 || $auth === null
        ) {
            pushSubscriptionRespond(400, ['success' => false, 'error' => 'invalid_subscription']);
        }

        $deviceType = pushSubscriptionDeviceType($input['device_type'] ?? null);
        $deviceName = pushSubscriptionDeviceName($input['device_name'] ?? null);
        $pdo->prepare(
            'INSERT INTO push_subscriptions
                (coach_id, athlete_id, endpoint_hash, endpoint, p256dh_key, auth_key, device_type, device_name, registered_at, last_activity_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'active\')
             ON DUPLICATE KEY UPDATE
                coach_id = VALUES(coach_id),
                athlete_id = VALUES(athlete_id),
                endpoint = VALUES(endpoint),
                p256dh_key = VALUES(p256dh_key),
                auth_key = VALUES(auth_key),
                device_type = VALUES(device_type),
                device_name = VALUES(device_name),
                last_activity_at = NOW(),
                status = \'active\''
        )->execute([$coachId, $athleteId, $endpointHash, $endpoint, $keys['p256dh'], $keys['auth'], $deviceType, $deviceName]);

        $idStmt = $pdo->prepare(
            'SELECT id FROM push_subscriptions WHERE endpoint_hash = ? AND ' . $ownerSql . ' LIMIT 1'
        );
        $idStmt->execute(array_merge([$endpointHash], [$ownerId]));
        $subscriptionId = $idStmt->fetchColumn();
        if ($subscriptionId === false) {
            throw new RuntimeException('Registered push subscription could not be loaded.');
        }
        pushSubscriptionRespond(200, ['success' => true, 'subscription_id' => (int)$subscriptionId]);
    }

    if ($action === 'list') {
        $currentHash = pushSubscriptionEndpointHash($input['current_endpoint'] ?? null);
        if ($currentHash !== null) {
            $activityStmt = $pdo->prepare(
                'UPDATE push_subscriptions SET last_activity_at = NOW()
                 WHERE endpoint_hash = ? AND status = \'active\' AND ' . $ownerSql
            );
            $activityStmt->execute(array_merge([$currentHash], [$ownerId]));
        }

        $listStmt = $pdo->prepare(
            'SELECT id, device_type, device_name, registered_at, last_activity_at,
                    CASE WHEN endpoint_hash = ? THEN 1 ELSE 0 END AS is_current
             FROM push_subscriptions
             WHERE status = \'active\' AND ' . $ownerSql . '
             ORDER BY last_activity_at DESC, id DESC'
        );
        $listStmt->execute(array_merge([$currentHash ?? str_repeat("\0", 32)], [$ownerId]));
        pushSubscriptionRespond(200, ['success' => true, 'devices' => $listStmt->fetchAll()]);
    }

    if ($action === 'revoke') {
        $subscriptionId = filter_var($input['subscription_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($subscriptionId === false) {
            pushSubscriptionRespond(400, ['success' => false, 'error' => 'invalid_subscription_id']);
        }

        $revokeStmt = $pdo->prepare(
            'UPDATE push_subscriptions SET status = \'revoked\'
             WHERE id = ? AND ' . $ownerSql
        );
        $revokeStmt->execute(array_merge([(int)$subscriptionId], [$ownerId]));
        if ($revokeStmt->rowCount() !== 1) {
            $ownedStmt = $pdo->prepare(
                'SELECT id FROM push_subscriptions WHERE id = ? AND ' . $ownerSql . ' LIMIT 1'
            );
            $ownedStmt->execute(array_merge([(int)$subscriptionId], [$ownerId]));
            if ($ownedStmt->fetchColumn() === false) {
                pushSubscriptionRespond(404, ['success' => false, 'error' => 'device_not_found']);
            }
        }

        pushSubscriptionRespond(200, ['success' => true]);
    }

    if ($action === 'disable') {
        $disableStmt = $pdo->prepare(
            'UPDATE push_subscriptions SET status = \'revoked\'
             WHERE status = \'active\' AND ' . $ownerSql
        );
        $disableStmt->execute([$ownerId]);
        pushSubscriptionRespond(200, ['success' => true, 'revoked' => $disableStmt->rowCount()]);
    }

    if ($action === 'set_preference') {
        $category = is_string($input['category'] ?? null) ? $input['category'] : '';
        $enabled = $input['enabled'] ?? null;
        $categories = ['messages', 'calendar', 'online_training', 'athlete', 'payments', 'surveys', 'info_channel', 'gallery'];
        if (!in_array($category, $categories, true) || !in_array($enabled, [0, 1, false, true], true)) {
            pushSubscriptionRespond(400, ['success' => false, 'error' => 'invalid_preference']);
        }

        if ($isCoach) {
            $settingsStmt = $pdo->prepare(
                'INSERT INTO push_notification_settings (coach_id, athlete_id, category, enabled)
                 VALUES (?, NULL, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
            );
        } else {
            $settingsStmt = $pdo->prepare(
                'INSERT INTO push_notification_settings (coach_id, athlete_id, category, enabled)
                 VALUES (NULL, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
            );
        }
        $settingsStmt->execute([$ownerId, $category, (int)(bool)$enabled]);
        pushSubscriptionRespond(200, ['success' => true]);
    }

    if ($action === 'get_preferences') {
        $settingsStmt = $pdo->prepare(
            'SELECT category, enabled FROM push_notification_settings WHERE ' . $ownerSql
        );
        $settingsStmt->execute([$ownerId]);
        $preferences = array_fill_keys(['messages', 'calendar', 'online_training', 'athlete', 'payments', 'surveys', 'info_channel', 'gallery'], true);
        foreach ($settingsStmt->fetchAll() as $setting) {
            $preferences[(string)$setting['category']] = (bool)$setting['enabled'];
        }
        pushSubscriptionRespond(200, ['success' => true, 'preferences' => $preferences]);
    }

    pushSubscriptionRespond(400, ['success' => false, 'error' => 'invalid_action']);
} catch (Throwable $e) {
    error_log('Push subscription API failed: ' . $e->getMessage());
    pushSubscriptionRespond(503, ['success' => false, 'error' => 'push_service_unavailable']);
}
