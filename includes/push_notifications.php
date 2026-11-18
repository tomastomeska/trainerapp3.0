<?php

require_once __DIR__ . '/push_endpoint.php';
require_once __DIR__ . '/push_unread.php';
require_once __DIR__ . '/push_content_notifications.php';

function pushNotificationInputIsValid(
    string $role,
    int $ownerId,
    string $title,
    string $body,
    string $targetUrl,
    string $type,
    string $category,
    string $eventKey
): bool {
    if (!in_array($role, ['coach', 'athlete'], true) || $ownerId < 1
        || !in_array($category, ['messages', 'calendar', 'online_training', 'athlete', 'payments', 'surveys', 'info_channel', 'gallery'], true)
        || preg_match('/^[a-z0-9_]{1,64}$/D', $type) !== 1
        || $eventKey === '' || strlen($eventKey) > 200 || preg_match('//u', $eventKey) !== 1
    ) {
        return false;
    }

    foreach ([[$title, 100], [$body, 240]] as [$text, $maxLength]) {
        if (trim($text) === '' || preg_match('//u', $text) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $text) === 1
            || mb_strlen($text, 'UTF-8') > $maxLength
        ) {
            return false;
        }
    }

    if ($targetUrl === '' || strlen($targetUrl) > 1024
        || $targetUrl[0] !== '/' || str_starts_with($targetUrl, '//')
        || preg_match('//u', $targetUrl) !== 1
        || preg_match('/[\x00-\x20\x7f\\\\]/', $targetUrl) === 1
        || preg_match('/%(?![0-9a-f]{2})/i', $targetUrl) === 1
    ) {
        return false;
    }

    $decoded = rawurldecode($targetUrl);
    if (str_starts_with($decoded, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $decoded) === 1) {
        return false;
    }
    $parts = parse_url($targetUrl);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
        return false;
    }

    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'url' => $targetUrl,
        'type' => $type,
        'tag' => 'push-' . str_repeat('9', 20),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return strlen($payload) <= 2820;
}

/**
 * Queue one notification per currently active device. The event key must be
 * stable for retries of the same source event and unique for distinct events.
 * The caller must handle failures without undoing an already committed event.
 */
function pushEnqueueNotification(
    PDO $pdo,
    string $role,
    int $ownerId,
    string $title,
    string $body,
    string $targetUrl,
    string $type,
    string $category,
    string $eventKey
): int {
    if (!pushNotificationInputIsValid($role, $ownerId, $title, $body, $targetUrl, $type, $category, $eventKey)) {
        throw new InvalidArgumentException('Invalid push notification input.');
    }

    $nested = $pdo->inTransaction();
    $savepoint = 'push_' . bin2hex(random_bytes(8));
    if ($nested) {
        $pdo->exec('SAVEPOINT ' . $savepoint);
    } else {
        $pdo->beginTransaction();
    }

    try {
        $ownerSql = $role === 'coach'
            ? 'coach_id = ? AND athlete_id IS NULL'
            : 'athlete_id = ? AND coach_id IS NULL';
        $preference = $pdo->prepare(
            'SELECT enabled FROM push_notification_settings WHERE ' . $ownerSql . ' AND category = ? LIMIT 1'
        );
        $preference->execute([$ownerId, $category]);
        $enabled = $preference->fetchColumn();
        $created = 0;

        if ($enabled === false || (int)$enabled === 1) {
            $subscriptions = $pdo->prepare(
                'SELECT id FROM push_subscriptions WHERE ' . $ownerSql . " AND status = 'active' ORDER BY id"
            );
            $subscriptions->execute([$ownerId]);
            $insertLog = $pdo->prepare(
                "INSERT INTO push_notification_logs
                    (idempotency_key, coach_id, athlete_id, subscription_id, notification_type, title, target_url, result)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'queued')
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
            );
            $insertQueue = $pdo->prepare(
                'INSERT INTO push_delivery_queue (log_id, category, body) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = id'
            );

            while (($subscriptionId = $subscriptions->fetchColumn()) !== false) {
                $key = hash('sha256', json_encode(
                    [$role, $ownerId, $type, $eventKey, (int)$subscriptionId],
                    JSON_THROW_ON_ERROR
                ));
                $insertLog->execute([
                    $key, $role === 'coach' ? $ownerId : null, $role === 'athlete' ? $ownerId : null,
                    (int)$subscriptionId, $type, $title, $targetUrl,
                ]);
                $logId = (int)$pdo->lastInsertId();
                if ($logId < 1) {
                    throw new RuntimeException('Push log ID is unavailable.');
                }
                $insertQueue->execute([$logId, $category, $body]);
                $created += $insertQueue->rowCount() === 1 ? 1 : 0;
            }
        }

        if ($nested) {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } else {
            $pdo->commit();
        }
        return $created;
    } catch (Throwable $e) {
        if ($nested && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pushCreateSender(PDO $pdo): \Minishlink\WebPush\WebPush
{
    $autoload = __DIR__ . '/../dependencies/webpush/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Push dependencies are missing.');
    }
    require_once $autoload;

    $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute(['push_vapid_keys']);
    $stored = $stmt->fetchColumn();
    if (!is_string($stored)) {
        throw new RuntimeException('Push VAPID keys are not configured.');
    }
    $keys = json_decode($stored, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($keys) || !is_string($keys['publicKey'] ?? null) || !is_string($keys['privateKey'] ?? null)) {
        throw new RuntimeException('Push VAPID keys are invalid.');
    }

    return new \Minishlink\WebPush\WebPush(
        ['VAPID' => [
            'subject' => 'https://www.reservio.online/',
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ]],
        ['TTL' => 3600],
        5,
        ['allow_redirects' => false, 'connect_timeout' => 2, 'verify' => true]
    );
}

function pushClaimDelivery(PDO $pdo): ?array
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Push worker cannot run inside another transaction.');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query(
            "SELECT id FROM push_delivery_queue
             WHERE (status = 'pending' AND available_at <= NOW())
                OR (status = 'processing' AND locked_until <= NOW())
             ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $pdo->commit();
            return null;
        }
        $token = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare(
            "UPDATE push_delivery_queue
             SET status = 'processing', attempts = attempts + 1, lock_token = ?,
                 locked_until = DATE_ADD(NOW(), INTERVAL 90 SECOND)
             WHERE id = ?"
        );
        $stmt->execute([$token, (int)$id]);
        $stmt = $pdo->prepare(
            'SELECT q.id AS queue_id, q.log_id, q.category, q.body, q.attempts,
                    l.coach_id, l.athlete_id, l.subscription_id, l.notification_type, l.title, l.target_url,
                    s.coach_id AS subscription_coach_id, s.athlete_id AS subscription_athlete_id,
                    s.status AS subscription_status, s.endpoint, s.endpoint_hash, s.p256dh_key, s.auth_key
             FROM push_delivery_queue q
             JOIN push_notification_logs l ON l.id = q.log_id
             LEFT JOIN push_subscriptions s ON s.id = l.subscription_id
             WHERE q.id = ? AND q.lock_token = ?'
        );
        $stmt->execute([(int)$id, $token]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($delivery)) {
            throw new RuntimeException('Claimed push delivery could not be loaded.');
        }
        $delivery['lock_token'] = $token;
        $pdo->commit();
        return $delivery;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pushDeliveryIsEnabled(PDO $pdo, array $delivery): bool
{
    $coachId = $delivery['coach_id'] === null ? null : (int)$delivery['coach_id'];
    $athleteId = $delivery['athlete_id'] === null ? null : (int)$delivery['athlete_id'];
    if (($coachId === null) === ($athleteId === null)
        || $delivery['subscription_status'] !== 'active'
        || ($delivery['subscription_coach_id'] === null ? null : (int)$delivery['subscription_coach_id']) !== $coachId
        || ($delivery['subscription_athlete_id'] === null ? null : (int)$delivery['subscription_athlete_id']) !== $athleteId
    ) {
        return false;
    }

    if ($coachId !== null) {
        $account = $pdo->prepare(
            'SELECT 1 FROM coaches WHERE id = ? AND is_active = 1
             AND (force_password_change IS NULL OR force_password_change <> 1)'
        );
        $ownerSql = 'coach_id = ? AND athlete_id IS NULL';
        $ownerId = $coachId;
    } else {
        $account = $pdo->prepare(
            'SELECT 1 FROM athletes a JOIN coaches c ON c.id = a.coach_id
             WHERE a.id = ? AND (a.force_password_change IS NULL OR a.force_password_change <> 1)
               AND c.is_active = 1'
        );
        $ownerSql = 'athlete_id = ? AND coach_id IS NULL';
        $ownerId = $athleteId;
    }
    $account->execute([$ownerId]);
    if ($account->fetchColumn() === false) {
        return false;
    }
    $preference = $pdo->prepare(
        'SELECT enabled FROM push_notification_settings WHERE ' . $ownerSql . ' AND category = ? LIMIT 1'
    );
    $preference->execute([$ownerId, $delivery['category']]);
    $enabled = $preference->fetchColumn();
    return $enabled === false || (int)$enabled === 1;
}

function pushDeliveryPayload(array $delivery): string
{
    $coach = $delivery['coach_id'] !== null;
    if (!pushNotificationInputIsValid(
        $coach ? 'coach' : 'athlete',
        (int)($coach ? $delivery['coach_id'] : $delivery['athlete_id']),
        (string)$delivery['title'],
        (string)$delivery['body'],
        (string)$delivery['target_url'],
        (string)$delivery['notification_type'],
        (string)$delivery['category'],
        (string)$delivery['log_id']
    )) {
        throw new InvalidArgumentException('Invalid stored push notification.');
    }
    $payload = [
        'title' => $delivery['title'],
        'body' => $delivery['body'],
        'url' => $delivery['target_url'],
        'type' => $delivery['notification_type'],
        'tag' => 'push-' . $delivery['log_id'],
    ];
    if (isset($delivery['unread_count']) && is_int($delivery['unread_count']) && $delivery['unread_count'] >= 0) {
        $payload['unread_count'] = $delivery['unread_count'];
    }
    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function pushSendDelivery(\Minishlink\WebPush\WebPush $sender, array $delivery): \Minishlink\WebPush\MessageSentReport
{
    if (!pushEndpointIsAllowed($delivery['endpoint'] ?? null)) {
        throw new InvalidArgumentException('Disallowed push endpoint.');
    }
    $subscription = \Minishlink\WebPush\Subscription::create([
        'endpoint' => $delivery['endpoint'],
        'publicKey' => $delivery['p256dh_key'],
        'authToken' => $delivery['auth_key'],
        'contentEncoding' => 'aes128gcm',
    ]);
    return $sender->sendOneNotification($subscription, pushDeliveryPayload($delivery));
}

function pushClassifyReport(\Minishlink\WebPush\MessageSentReport $report): array
{
    $response = $report->getResponse();
    $status = $response === null ? null : $response->getStatusCode();
    if ($report->isSuccess() && $status !== null && $status >= 200 && $status < 300) {
        return ['sent', $status, null];
    }
    if ($report->isSubscriptionExpired()) {
        return ['expired', $status, 'endpoint_expired'];
    }
    if ($status === null || $status === 429 || $status >= 500) {
        return ['retry', $status, 'temporary_failure'];
    }
    return ['failed', $status, 'provider_rejected'];
}

function pushFinishDelivery(PDO $pdo, array $delivery, string $result, ?int $httpStatus, ?string $errorCode): void
{
    $retry = $result === 'retry' && (int)$delivery['attempts'] < 5;
    $logResult = $retry ? 'queued' : ($result === 'retry' ? 'failed' : $result);
    $queueStatus = $retry ? 'pending' : ($logResult === 'failed' ? 'failed' : 'done');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE push_delivery_queue SET status = ?, lock_token = NULL, locked_until = NULL,
                available_at = CASE WHEN ? = 1 THEN DATE_ADD(NOW(), INTERVAL 5 MINUTE) ELSE available_at END
             WHERE id = ? AND status = 'processing' AND lock_token = ?"
        );
        $stmt->execute([$queueStatus, (int)$retry, $delivery['queue_id'], $delivery['lock_token']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Push delivery lease was lost.');
        }
        $stmt = $pdo->prepare(
            'UPDATE push_notification_logs SET result = ?, http_status = ?, error_code = ?,
                 completed_at = CASE WHEN ? = 1 THEN NULL ELSE NOW() END
             WHERE id = ?'
        );
        $stmt->execute([$logResult, $httpStatus, $errorCode, (int)$retry, $delivery['log_id']]);
        if ($result === 'expired' && $delivery['subscription_id'] !== null) {
            $stmt = $pdo->prepare(
                "UPDATE push_subscriptions SET status = 'invalid'
                 WHERE id = ? AND status = 'active' AND endpoint_hash = ?
                   AND BINARY p256dh_key = BINARY ? AND BINARY auth_key = BINARY ?
                   AND coach_id <=> ? AND athlete_id <=> ?"
            );
            $stmt->execute([
                $delivery['subscription_id'], $delivery['endpoint_hash'], $delivery['p256dh_key'], $delivery['auth_key'],
                $delivery['coach_id'], $delivery['athlete_id'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pushProcessQueue(PDO $pdo, int $maxJobs = 40, int $maxSeconds = 20): int
{
    $sender = pushCreateSender($pdo);
    $deadline = microtime(true) + max(1, min(25, $maxSeconds));
    $processed = 0;
    while ($processed < max(1, min(100, $maxJobs)) && microtime(true) < $deadline) {
        $delivery = pushClaimDelivery($pdo);
        if ($delivery === null) {
            break;
        }
        $status = null;
        $error = null;
        if ((int)$delivery['attempts'] > 5) {
            $result = 'failed';
            $error = 'uncertain_delivery';
        } elseif (!pushDeliveryIsEnabled($pdo, $delivery)) {
            $result = 'skipped';
            $error = 'disabled_or_unavailable';
        } elseif (!pushEndpointIsAllowed($delivery['endpoint'])) {
            $result = 'failed';
            $error = 'invalid_endpoint';
        } else {
            try {
                if (!pushContentDeliveryAllowed($pdo, $delivery)) {
                    $result = 'skipped';
                    $error = 'content_unavailable';
                } else {
                    try {
                        $isCoach = $delivery['coach_id'] !== null;
                        $delivery['unread_count'] = pushUnreadChatCount(
                            $pdo,
                            $isCoach ? 'coach' : 'athlete',
                            (int)($isCoach ? $delivery['coach_id'] : $delivery['athlete_id'])
                        );
                    } catch (Throwable $e) {
                        error_log('Push unread count failed: ' . get_class($e));
                    }
                    $report = pushSendDelivery($sender, $delivery);
                    [$result, $status, $error] = pushClassifyReport($report);
                }
            } catch (InvalidArgumentException $e) {
                $result = 'failed';
                $error = 'invalid_data';
                error_log('Push delivery rejected invalid stored data.');
            } catch (Throwable $e) {
                $result = 'retry';
                $error = 'delivery_error';
                error_log('Push delivery failed: ' . get_class($e));
            }
        }
        pushFinishDelivery($pdo, $delivery, $result, $status, $error);
        $processed++;
    }
    return $processed;
}

function pushDispatchAfterResponse(PDO $pdo): bool
{
    static $scheduled = false;
    if ($scheduled) {
        return true;
    }
    if (PHP_SAPI === 'cli' || !function_exists('fastcgi_finish_request')) {
        return false;
    }
    register_shutdown_function(static function () use ($pdo): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (!fastcgi_finish_request()) {
            error_log('Push immediate dispatch could not finish the HTTP response.');
            return;
        }
        ignore_user_abort(true);
        try {
            if ($pdo->inTransaction()) {
                throw new LogicException('Push dispatch cannot run before the event transaction commits.');
            }
            pushProcessQueue($pdo, 20, 10);
        } catch (Throwable $e) {
            error_log('Push immediate dispatch failed: ' . get_class($e));
        }
    });
    $scheduled = true;
    return true;
}
