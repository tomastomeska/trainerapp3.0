<?php

require_once __DIR__ . '/push_notifications.php';

function pushNotifyCoachAthleteChatMessage(PDO $pdo, int $messageId): void
{
    try {
        if ($messageId < 1 || $pdo->inTransaction()) {
            throw new LogicException('Chat push requires a committed message.');
        }

        $stmt = $pdo->prepare(
            'SELECT m.coach_id, m.athlete_id, m.sender, a.first_name, a.last_name
             FROM coach_athlete_chat_messages m
             JOIN athletes a ON a.id = m.athlete_id AND a.coach_id = m.coach_id
             WHERE m.id = ? LIMIT 1'
        );
        $stmt->execute([$messageId]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$message) {
            throw new RuntimeException('Chat message or its coach-athlete relationship was not found.');
        }

        $coachId = (int)$message['coach_id'];
        $athleteId = (int)$message['athlete_id'];
        if ($message['sender'] === 'coach') {
            $role = 'athlete';
            $recipientId = $athleteId;
            $body = 'Máš novou zprávu od trenéra.';
            $url = '/athlete_chat_mobile.php?target=coach';
            $type = 'coach_chat_message';
        } elseif ($message['sender'] === 'athlete') {
            $role = 'coach';
            $recipientId = $coachId;
            $name = trim((string)$message['first_name'] . ' ' . (string)$message['last_name']);
            if ($name !== '' && preg_match('//u', $name) === 1) {
                $name = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name));
            } else {
                $name = '';
            }
            if ($name === '') {
                error_log('Chat push athlete name is unavailable.');
                $body = 'Máte novou zprávu od sportovce.';
            } else {
                if (mb_strlen($name, 'UTF-8') > 100) {
                    $name = mb_substr($name, 0, 99, 'UTF-8') . '…';
                }
                $body = 'Nová zpráva od sportovce: ' . $name . '.';
            }
            $url = '/coach_chat_mobile.php?athlete_id=' . $athleteId;
            $type = 'athlete_chat_message';
        } else {
            throw new RuntimeException('Unexpected chat message sender.');
        }

        $queued = pushEnqueueNotification(
            $pdo, $role, $recipientId, 'TrainerApp', $body, $url, $type, 'messages',
            'coach_athlete_chat_messages:' . $messageId
        );
        if ($queued > 0 && !pushDispatchAfterResponse($pdo)) {
            error_log('Chat push is queued for the scheduled worker.');
        }
    } catch (Throwable $e) {
        error_log('Chat push enqueue failed: ' . get_class($e));
    }
}

function pushNotifyAthleteBroadcast(PDO $pdo, string $sender, int $messageId, array $athleteIds): void
{
    try {
        if ($messageId < 1 || $pdo->inTransaction() || !in_array($sender, ['admin', 'coach'], true)) {
            throw new LogicException('Broadcast push requires a committed message and valid sender.');
        }

        $body = $sender === 'admin'
            ? 'Máš novou hromadnou zprávu od administrátora.'
            : 'Máš novou hromadnou zprávu od trenéra.';
        $queued = 0;
        foreach (array_unique($athleteIds) as $athleteId) {
            try {
                $queued += pushEnqueueNotification(
                    $pdo, 'athlete', (int)$athleteId, 'TrainerApp', $body,
                    '/athlete_zpravy.php?tab=inbox', $sender . '_athlete_broadcast', 'messages',
                    $sender . '_athlete_broadcast:' . $messageId
                );
            } catch (Throwable $e) {
                error_log('Broadcast push recipient enqueue failed: ' . get_class($e));
            }
        }
        if ($queued > 0 && !pushDispatchAfterResponse($pdo)) {
            error_log('Broadcast push is queued for the scheduled worker.');
        }
    } catch (Throwable $e) {
        error_log('Broadcast push enqueue failed: ' . get_class($e));
    }
}
