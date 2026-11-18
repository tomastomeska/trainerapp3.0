<?php

require_once __DIR__ . '/push_notifications.php';

function pushOnlineTrainingNotify(PDO $pdo, int $trainingId, string $kind): void
{
    try {
        if ($trainingId < 1 || $pdo->inTransaction()) {
            throw new LogicException('Online training push requires a committed event.');
        }
        $specs = [
            'sent' => ['athlete', 'online_training_sent'],
            'completed' => ['coach', 'online_training_completed'],
            'reaction' => ['athlete', 'online_training_reaction'],
            'note' => ['athlete', 'online_training_note'],
        ];
        if (!isset($specs[$kind])) {
            throw new InvalidArgumentException('Invalid online training push kind.');
        }
        $stmt = $pdo->prepare(
            'SELECT ot.trainer_id, ot.athlete_id, ot.sequence_number, ot.status, a.first_name, a.last_name
             FROM online_trainings ot
             JOIN athletes a ON a.id = ot.athlete_id AND a.coach_id = ot.trainer_id
             WHERE ot.id = ? LIMIT 1'
        );
        $stmt->execute([$trainingId]);
        $training = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$training) {
            throw new RuntimeException('Online training or its coach-athlete relationship was not found.');
        }
        $status = (string)$training['status'];
        if (($kind === 'completed' || $kind === 'reaction') && $status !== 'completed') {
            throw new LogicException('Online training is not completed.');
        }
        if (($kind === 'sent' || $kind === 'note') && !in_array($status, ['sent', 'in_progress', 'completed'], true)) {
            throw new LogicException('Online training has not been sent.');
        }
        $number = str_pad((string)(int)$training['sequence_number'], 3, '0', STR_PAD_LEFT);
        [$role, $type] = $specs[$kind];
        if ($kind === 'completed') {
            $name = trim((string)$training['first_name'] . ' ' . (string)$training['last_name']);
            if ($name === '' || preg_match('//u', $name) !== 1) {
                throw new RuntimeException('Online training athlete name is unavailable.');
            }
            $name = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name));
            if (mb_strlen($name, 'UTF-8') > 100) {
                $name = mb_substr($name, 0, 99, 'UTF-8') . '…';
            }
            $body = 'Sportovec: ' . $name . '. Dokončen online trénink #' . $number . '.';
        } elseif ($kind === 'sent') {
            $body = 'Trenér vám odeslal online trénink #' . $number . '.';
        } elseif ($kind === 'reaction') {
            $body = 'Trenér reagoval na online trénink #' . $number . '.';
        } else {
            $body = 'Trenér upravil poznámku k online tréninku #' . $number . '.';
        }
        $eventKey = 'online-training:' . $trainingId . ':' . $kind;
        if ($kind === 'reaction' || $kind === 'note') {
            $eventKey .= ':' . bin2hex(random_bytes(16));
        }
        $ownerId = $role === 'coach' ? (int)$training['trainer_id'] : (int)$training['athlete_id'];
        $queued = pushEnqueueNotification(
            $pdo, $role, $ownerId, 'TrainerApp', $body, '/online_training.php?id=' . $trainingId,
            $type, 'online_training', $eventKey
        );
        if ($queued > 0 && !pushDispatchAfterResponse($pdo)) {
            error_log('Online training push remains queued; after-response dispatch is unavailable.');
        }
    } catch (Throwable $e) {
        error_log('Online training push enqueue failed: ' . get_class($e));
    }
}
