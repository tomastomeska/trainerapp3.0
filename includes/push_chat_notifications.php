<?php

require_once __DIR__ . '/push_notifications.php';

function pushNotifyCoachAthleteChatMessage(PDO $pdo, int $messageId): void
{
    try {
        if ($messageId < 1 || $pdo->inTransaction()) {
            throw new LogicException('Chat push requires a committed message.');
        }

        $stmt = $pdo->prepare(
            'SELECT m.coach_id, m.athlete_id, m.sender
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
            $body = 'Máte novou zprávu od sportovce.';
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
