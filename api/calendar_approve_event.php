<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Neplatná metoda']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Neplatná data']);
    exit;
}

if (!verifyCsrf((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'Neplatný CSRF token']);
    exit;
}

$eventId = (int)($input['event_id'] ?? 0);
if ($eventId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Neplatné ID události']);
    exit;
}

$coachId = (int)getCurrentCoachId();
$pdo = getDB();

function approveExtractRescheduleTargetEventId(?string $seriesId): int
{
    if (!is_string($seriesId)) {
        return 0;
    }

    if (preg_match('/^reschedule:(\d+)$/', trim($seriesId), $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

$eventStmt = $pdo->prepare(
    'SELECT e.id,
            e.athlete_id,
            e.second_athlete_id,
            e.requested_by_athlete_id,
            e.series_id,
            e.approval_status,
            e.is_makeup_session,
            e.billing_month,
            e.color_key,
            e.custom_title,
            e.starts_at,
            e.ends_at,
            e.location,
            a.email AS athlete_email,
            a.first_name,
            a.last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     WHERE e.id = ? AND e.coach_id = ?
     LIMIT 1'
);
$eventStmt->execute([$eventId, $coachId]);
$event = $eventStmt->fetch();

if (!$event) {
    echo json_encode(['success' => false, 'error' => 'Událost nenalezena']);
    exit;
}

$isPendingRequest = ((string)($event['approval_status'] ?? 'approved') === 'pending')
    && ((int)($event['requested_by_athlete_id'] ?? 0) > 0);

if (!$isPendingRequest) {
    echo json_encode(['success' => false, 'error' => 'Tuto událost nelze schválit z měsíčního seznamu.']);
    exit;
}

$rescheduleSourceEventId = approveExtractRescheduleTargetEventId((string)($event['series_id'] ?? ''));

if ($rescheduleSourceEventId > 0) {
    $sourceStmt = $pdo->prepare(
        'SELECT id, coach_id, athlete_id, second_athlete_id
         FROM coach_calendar_events
         WHERE id = ?
           AND coach_id = ?
         LIMIT 1'
    );
    $sourceStmt->execute([$rescheduleSourceEventId, $coachId]);
    $sourceEvent = $sourceStmt->fetch();
    if (!$sourceEvent) {
        echo json_encode(['success' => false, 'error' => 'Původní termín pro přesun nebyl nalezen.']);
        exit;
    }

    $lockStmt = $pdo->prepare(
        'SELECT id
         FROM coach_calendar_locks
         WHERE coach_id = ?
           AND starts_at < ?
           AND ends_at > ?
         LIMIT 1'
    );
    $lockStmt->execute([$coachId, (string)($event['ends_at'] ?? ''), (string)($event['starts_at'] ?? '')]);
    if ($lockStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Navržený čas je uzamčený.']);
        exit;
    }

    $overlapStmt = $pdo->prepare(
        'SELECT id
         FROM coach_calendar_events
         WHERE coach_id = ?
           AND starts_at < ?
           AND ends_at > ?
           AND id <> ?
           AND id <> ?
         LIMIT 1'
    );
    $overlapStmt->execute([
        $coachId,
        (string)($event['ends_at'] ?? ''),
        (string)($event['starts_at'] ?? ''),
        $eventId,
        $rescheduleSourceEventId,
    ]);
    if ($overlapStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Navržený čas už koliduje s jinou událostí.']);
        exit;
    }

    $updateSourceStmt = $pdo->prepare(
        'UPDATE coach_calendar_events
         SET athlete_id = ?,
             second_athlete_id = ?,
             requested_by_athlete_id = NULL,
             approval_status = "approved",
             coach_modified_at = NOW(),
             is_makeup_session = ?,
             billing_month = ?,
             color_key = ?,
             custom_title = ?,
             location = ?,
             starts_at = ?,
             ends_at = ?
         WHERE id = ?
           AND coach_id = ?'
    );
    $deleteRequestStmt = $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND coach_id = ? LIMIT 1');

    try {
        $pdo->beginTransaction();

        $updateSourceStmt->execute([
            (int)($event['athlete_id'] ?? 0) > 0 ? (int)$event['athlete_id'] : null,
            (int)($event['second_athlete_id'] ?? 0) > 0 ? (int)$event['second_athlete_id'] : null,
            (int)($event['is_makeup_session'] ?? 0) === 1 ? 1 : 0,
            (string)($event['billing_month'] ?? null),
            (string)($event['color_key'] ?? 'green') !== '' ? (string)$event['color_key'] : 'green',
            ($event['custom_title'] ?? null) !== '' ? (string)$event['custom_title'] : null,
            ($event['location'] ?? null) !== '' ? (string)$event['location'] : null,
            (string)($event['starts_at'] ?? ''),
            (string)($event['ends_at'] ?? ''),
            $rescheduleSourceEventId,
            $coachId,
        ]);

        $deleteRequestStmt->execute([$eventId, $coachId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $syncAthleteIds = array_values(array_unique(array_filter([
        (int)($sourceEvent['athlete_id'] ?? 0),
        (int)($sourceEvent['second_athlete_id'] ?? 0),
        (int)($event['athlete_id'] ?? 0),
        (int)($event['second_athlete_id'] ?? 0),
        (int)($event['requested_by_athlete_id'] ?? 0),
    ])));

    enqueueCoachGoogleCalendarSync($coachId, $rescheduleSourceEventId, 'upsert');
    enqueueCoachAppleCaldavSync($coachId, $rescheduleSourceEventId, 'upsert');
    enqueueCoachGoogleCalendarSync($coachId, $eventId, 'delete');
    enqueueCoachAppleCaldavSync($coachId, $eventId, 'delete');
    foreach ($syncAthleteIds as $syncAthleteId) {
        enqueueAthleteAppleCaldavSync($syncAthleteId, $rescheduleSourceEventId, 'upsert');
        enqueueAthleteAppleCaldavSync($syncAthleteId, $eventId, 'delete');
    }
    processInlineCalendarSyncQueues(2, 3, 3);

    $athleteId = (int)($event['athlete_id'] ?? 0);
    if ($athleteId > 0) {
        $athleteName = trim((string)($event['first_name'] ?? '') . ' ' . (string)($event['last_name'] ?? ''));
        $timeStamp = strtotime((string)($event['starts_at'] ?? ''));
        $timeLabel = $timeStamp !== false ? date('d.m.Y H:i', $timeStamp) : 'zvolený termín';

        $subject = 'Žádost o změnu byla schválena';
        $body = 'Trenér schválil změnu termínu. Nový termín: ' . $timeLabel . '.';
        $location = trim((string)($event['location'] ?? ''));
        if ($location !== '') {
            $body .= ' Místo: ' . $location . '.';
        }

        createAthleteNotification($athleteId, $subject, $body);

        $email = trim((string)($event['athlete_email'] ?? ''));
        if ($email !== '') {
            sendAthleteCalendarNotificationEmail($email, $athleteName !== '' ? $athleteName : 'sportovec', $subject, $body);
        }
    }

    echo json_encode(['success' => true, 'id' => $rescheduleSourceEventId, 'approval_status' => 'approved']);
    exit;
}

$updateStmt = $pdo->prepare(
    'UPDATE coach_calendar_events
     SET approval_status = "approved"
     WHERE id = ? AND coach_id = ?'
);
$updateStmt->execute([$eventId, $coachId]);

enqueueCoachGoogleCalendarSync($coachId, $eventId, 'upsert');
enqueueCoachAppleCaldavSync($coachId, $eventId, 'upsert');
if ((int)($event['athlete_id'] ?? 0) > 0) {
    enqueueAthleteAppleCaldavSync((int)$event['athlete_id'], $eventId, 'upsert');
}
if ((int)($event['second_athlete_id'] ?? 0) > 0) {
    enqueueAthleteAppleCaldavSync((int)$event['second_athlete_id'], $eventId, 'upsert');
}
processInlineCalendarSyncQueues(2, 3, 3);

$athleteId = (int)($event['athlete_id'] ?? 0);
if ($athleteId > 0) {
    $athleteName = trim((string)($event['first_name'] ?? '') . ' ' . (string)($event['last_name'] ?? ''));
    $timeStamp = strtotime((string)($event['starts_at'] ?? ''));
    $timeLabel = $timeStamp !== false ? date('d.m.Y H:i', $timeStamp) : 'zvolený termín';

    $subject = 'Trénink byl schválen';
    $body = 'Trenér schválil váš termín ' . $timeLabel . '.';
    $location = trim((string)($event['location'] ?? ''));
    if ($location !== '') {
        $body .= ' Místo: ' . $location . '.';
    }

    createAthleteNotification($athleteId, $subject, $body);

    $email = trim((string)($event['athlete_email'] ?? ''));
    if ($email !== '') {
        sendAthleteCalendarNotificationEmail($email, $athleteName !== '' ? $athleteName : 'sportovec', $subject, $body);
    }
}

echo json_encode(['success' => true, 'id' => $eventId, 'approval_status' => 'approved']);
