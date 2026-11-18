<?php

require_once __DIR__ . '/push_notifications.php';

function pushCalendarNotificationSpec(string $kind): array
{
    $specs = [
        'athlete_request' => ['coach', 'Nová žádost o termín.', 'calendar_request'],
        'athlete_request_changed' => ['coach', 'Změna žádosti o termín.', 'calendar_request_changed'],
        'athlete_request_cancelled' => ['coach', 'Žádost o termín byla zrušena.', 'calendar_request_cancelled'],
        'athlete_cancelled' => ['coach', 'Účast na termínu byla zrušena.', 'calendar_athlete_cancelled'],
        'coach_created' => ['athlete', 'Trenér vám naplánoval termín.', 'calendar_created'],
        'coach_changed' => ['athlete', 'Trenér upravil váš termín.', 'calendar_changed'],
        'coach_approved' => ['athlete', 'Trenér schválil váš požadavek na termín.', 'calendar_approved'],
        'coach_cancelled' => ['athlete', 'Trenér zrušil váš termín.', 'calendar_cancelled'],
        'coach_rejected' => ['athlete', 'Trenér zamítl váš požadavek na termín.', 'calendar_rejected'],
    ];
    if (!isset($specs[$kind])) {
        throw new InvalidArgumentException('Invalid calendar push kind.');
    }
    return $specs[$kind];
}

/**
 * An empty operation key is used for a distinct committed edit that has no
 * persistent revision ID. Other callers provide the source event's stable key.
 */
function pushCalendarNotify(
    PDO $pdo,
    string $kind,
    int $coachId,
    array $athleteIds,
    int $eventId,
    string $operationKey,
    string $startsAt = '',
    bool $eventExists = true,
    int $actorAthleteId = 0,
    string $location = '',
    bool $seriesAction = false
): void {
    try {
        if ($pdo->inTransaction() || $coachId < 1 || $eventId < 1) {
            throw new LogicException('Calendar push requires a committed event.');
        }
        [$role, $body, $type] = pushCalendarNotificationSpec($kind);
        if ($role === 'coach') {
            try {
                if ($actorAthleteId < 1) {
                    throw new InvalidArgumentException('Calendar push athlete is missing.');
                }
                $actor = $pdo->prepare("SELECT CONCAT_WS(' ', first_name, last_name) FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1");
                $actor->execute([$actorAthleteId, $coachId]);
                $name = $actor->fetchColumn();
                if (!is_string($name) || preg_match('//u', $name) !== 1) {
                    throw new RuntimeException('Calendar push athlete name is unavailable.');
                }
                $name = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name));
                if ($name === '') {
                    throw new RuntimeException('Calendar push athlete name is empty.');
                }
                if (mb_strlen($name, 'UTF-8') > 75) {
                    $name = mb_substr($name, 0, 74, 'UTF-8') . '…';
                }
                $body = 'Sportovec: ' . $name . '. ' . $body;
            } catch (Throwable $e) {
                error_log('Calendar push athlete name lookup failed: ' . get_class($e));
            }
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $startsAt);
        if (!$date || $date->format('Y-m-d H:i:s') !== $startsAt) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startsAt);
            if (!$date || $date->format('Y-m-d H:i') !== $startsAt) {
                error_log('Calendar push event time is unavailable or invalid.');
                $date = false;
            }
        }
        $body .= $seriesAction ? ' Série od: ' : ' Termín: ';
        $body .= $date ? $date->format('d.m.Y H:i') . '.' : 'neuveden.';
        if (preg_match('//u', $location) !== 1) {
            error_log('Calendar push location is invalid.');
            $location = '';
        }
        $location = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $location));
        if ($location === '') {
            $location = 'neuvedeno';
        }
        $body .= ' Místo: ';
        $remaining = 240 - mb_strlen($body, 'UTF-8') - 1;
        if (mb_strlen($location, 'UTF-8') > $remaining) {
            $location = mb_substr($location, 0, $remaining - 1, 'UTF-8') . '…';
        }
        $body .= $location . '.';
        $eventKey = $operationKey !== ''
            ? $operationKey
            : 'calendar-edit:' . $eventId . ':' . bin2hex(random_bytes(16));
        $url = $role === 'athlete' ? '/athlete_calendar.php' : '/calendar.php?tab=week';
        if ($date) {
            $url .= ($role === 'coach' ? '&' : '?') . 'focus_date=' . $date->format('Y-m-d');
            if ($eventExists) {
                $url .= '&focus_event=' . $eventId;
            }
        }

        $recipients = $role === 'coach'
            ? [$coachId]
            : array_values(array_unique(array_filter(array_map('intval', $athleteIds), static fn(int $id): bool => $id > 0)));
        $ownership = $role === 'athlete'
            ? $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1')
            : null;
        $queued = 0;
        foreach ($recipients as $recipientId) {
            try {
                if ($ownership !== null) {
                    $ownership->execute([$recipientId, $coachId]);
                    if ($ownership->fetchColumn() === false) {
                        error_log('Calendar push recipient does not belong to the coach.');
                        continue;
                    }
                }
                $queued += pushEnqueueNotification(
                    $pdo, $role, $recipientId, 'TrainerApp', $body, $url, $type, 'calendar', $eventKey
                );
            } catch (Throwable $e) {
                error_log('Calendar push recipient enqueue failed: ' . get_class($e));
            }
        }
        if ($queued > 0 && !pushDispatchAfterResponse($pdo)) {
            error_log('Calendar push is queued for the scheduled worker.');
        }
    } catch (Throwable $e) {
        error_log('Calendar push enqueue failed: ' . get_class($e));
    }
}
