<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_billing.php';

header('Content-Type: application/json; charset=utf-8');

if (!athleteIsLoggedIn()) {
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

$athleteId = (int)getCurrentAthleteId();
$mode = trim((string)($input['mode'] ?? 'create'));
$eventId = (int)($input['event_id'] ?? 0);
$requestChangeForEventId = (int)($input['request_change_for_event_id'] ?? 0);
$isEditMode = ($mode === 'edit' && $eventId > 0);
$isRequestChangeMode = ($mode === 'request_change' && $requestChangeForEventId > 0);
$startsAtRaw = trim((string)($input['starts_at'] ?? ''));
$location = trim((string)($input['location'] ?? ''));
$titleType = trim((string)($input['title_type'] ?? 'training'));
$isMakeupSession = !empty($input['is_makeup_session']) ? 1 : 0;

if (!in_array($mode, ['create', 'edit', 'request_change'], true)) {
    echo json_encode(['success' => false, 'error' => 'Neplatný režim uložení']);
    exit;
}

$start = DateTime::createFromFormat('Y-m-d\TH:i', $startsAtRaw);
if (!$start) {
    echo json_encode(['success' => false, 'error' => 'Neplatný začátek termínu']);
    exit;
}

$end = clone $start;
$end->modify('+60 minutes');

if (!in_array($titleType, ['training', 'consultation', 'other'], true)) {
    $titleType = 'training';
}

$titleLabels = [
    'training' => 'Trénink',
    'consultation' => 'Konzultační hodina',
    'other' => 'Jiné',
];
$customTitle = $titleLabels[$titleType];
if ($location !== '') {
    $location = mb_substr($location, 0, 255, 'UTF-8');
} else {
    $location = null;
}

$existingEvent = null;
$requestChangeEvent = null;

$pdo = getDB();
paymentEnsureBillingIssueSchema($pdo);

function athleteReserveTableExists(PDO $pdo, string $tableName): bool
{
    $quoted = $pdo->quote($tableName);
    $stmt = $pdo->query("SHOW TABLES LIKE {$quoted}");
    return $stmt !== false && (bool)$stmt->fetchColumn();
}

function athleteReserveColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $quoted = $pdo->quote($columnName);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quoted}");
    return $stmt !== false && (bool)$stmt->fetch();
}

function athleteFindRequiredReplacementCancellation(PDO $pdo, int $coachId, int $athleteId, ?int $eventId = null): ?array
{
    return paymentFindActiveReplacementCancellation($pdo, $coachId, $athleteId, $eventId);
}

function athleteExtractRescheduleTargetEventId(?string $seriesId): int
{
    if (!is_string($seriesId)) {
        return 0;
    }

    if (preg_match('/^reschedule:(\d+)$/', trim($seriesId), $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

function athleteResolveOpenBillingMonth(PDO $pdo, int $coachId, int $athleteId, string $targetMonthSql): string
{
    return $targetMonthSql;
}

$athleteStmt = $pdo->prepare(
    'SELECT a.id, a.first_name, a.last_name, a.email, a.coach_id,
            c.name AS coach_name, c.username AS coach_username, c.email AS coach_email
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id
     WHERE a.id = ?
     LIMIT 1'
);
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    echo json_encode(['success' => false, 'error' => 'Sportovec nenalezen']);
    exit;
}

if ($isEditMode) {
    $eventStmt = $pdo->prepare(
        'SELECT id, coach_id, athlete_id, second_athlete_id, requested_by_athlete_id, approval_status, is_makeup_session, billing_month, custom_title, location, starts_at, ends_at
         FROM coach_calendar_events
         WHERE id = ?
           AND coach_id = ?
           AND approval_status = "pending"
           AND requested_by_athlete_id = ?
         LIMIT 1'
    );
    $eventStmt->execute([$eventId, (int)$athlete['coach_id'], $athleteId]);
    $existingEvent = $eventStmt->fetch() ?: null;
    if (!$existingEvent) {
        echo json_encode(['success' => false, 'error' => 'Tento čekající požadavek už nelze upravit.']);
        exit;
    }

    $existingEventStartTs = strtotime((string)($existingEvent['starts_at'] ?? ''));
    if ($existingEventStartTs !== false && $existingEventStartTs <= time()) {
        echo json_encode(['success' => false, 'error' => 'Minulé nebo právě probíhající termíny nelze upravovat.']);
        exit;
    }
}

if ($isRequestChangeMode) {
    $requestChangeStmt = $pdo->prepare(
                'SELECT id, coach_id, athlete_id, second_athlete_id, requested_by_athlete_id, approval_status, is_makeup_session, billing_month, color_key, custom_title, location, starts_at, ends_at, series_id
         FROM coach_calendar_events
         WHERE id = ?
           AND coach_id = ?
           AND (athlete_id = ? OR second_athlete_id = ?)
         LIMIT 1'
    );
    $requestChangeStmt->execute([$requestChangeForEventId, (int)$athlete['coach_id'], $athleteId, $athleteId]);
    $requestChangeEvent = $requestChangeStmt->fetch() ?: null;
    if (!$requestChangeEvent) {
        echo json_encode(['success' => false, 'error' => 'Požadavek na změnu se nepodařilo najít.']);
        exit;
    }
}

if ($location !== null) {
    $venueStmt = $pdo->prepare('SELECT name FROM training_venues WHERE name = ? AND created_by_coach_id IS NULL AND is_active = 1 LIMIT 1');
    $venueStmt->execute([$location]);
    $venue = $venueStmt->fetch();
    if ($venue && !empty($venue['name'])) {
        $location = (string)$venue['name'];
    } else {
        $location = null;
    }
}

$startSql = $start->format('Y-m-d H:i:s');
$endSql = $end->format('Y-m-d H:i:s');
$billingMonthSql = $start->format('Y-m-01');
$billingIssueNumber = 1;
$replacementCancellationId = null;
$replacementCancellation = null;

if (!$isEditMode && !$isRequestChangeMode && $titleType === 'training') {
    $replacementCancellation = athleteFindRequiredReplacementCancellation($pdo, (int)$athlete['coach_id'], $athleteId);
    if ($replacementCancellation) {
        $isMakeupSession = 1;
    }
}

if ($isMakeupSession === 1 && !$isRequestChangeMode) {
    if ($titleType !== 'training') {
        echo json_encode(['success' => false, 'error' => 'Náhradní termín musí být trénink.']);
        exit;
    }
    if (!$replacementCancellation) {
        $replacementCancellation = athleteFindRequiredReplacementCancellation(
            $pdo,
            (int)$athlete['coach_id'],
            $athleteId,
            $isEditMode ? $eventId : null
        );
    }
    if (!$replacementCancellation) {
        echo json_encode(['success' => false, 'error' => 'Nemáte žádné aktivní storno s nárokem na náhradu. Historické nevyužité platby už nelze použít jako náhradní termín.']);
        exit;
    }

    $replacementCancellationId = (int)$replacementCancellation['id'];
    $billingMonthSql = !empty($replacementCancellation['billing_month'])
        ? date('Y-m-01', strtotime((string)$replacementCancellation['billing_month']))
        : $start->format('Y-m-01');
    $billingIssueNumber = max(1, (int)($replacementCancellation['billing_issue_number'] ?? 1));
} else {
    $billingMonthSql = athleteResolveOpenBillingMonth($pdo, (int)$athlete['coach_id'], $athleteId, $billingMonthSql);
}

if ($isRequestChangeMode) {
    $eventStartTs = strtotime($startSql);
    if ($eventStartTs !== false && $eventStartTs <= time()) {
        echo json_encode(['success' => false, 'error' => 'Minulé nebo právě probíhající termíny nelze měnit.']);
        exit;
    }

    if ((string)($requestChangeEvent['approval_status'] ?? 'approved') !== 'approved') {
        echo json_encode(['success' => false, 'error' => 'Změnu lze požádat jen u schváleného termínu.']);
        exit;
    }

    if (athleteExtractRescheduleTargetEventId((string)($requestChangeEvent['series_id'] ?? '')) > 0) {
        echo json_encode(['success' => false, 'error' => 'U tohoto termínu už je změna ve zpracování.']);
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
    $lockStmt->execute([(int)$athlete['coach_id'], $endSql, $startSql]);
    if ($lockStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Navržený čas je uzamčený. Vyberte prosím jiný termín.']);
        exit;
    }

    $overlapStmt = $pdo->prepare(
        'SELECT id
         FROM coach_calendar_events
         WHERE coach_id = ?
           AND starts_at < ?
           AND ends_at > ?
           AND id <> ?
         LIMIT 1'
    );
    $overlapStmt->execute([(int)$athlete['coach_id'], $endSql, $startSql, $requestChangeForEventId]);
    if ($overlapStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Navržený čas je už obsazený.']);
        exit;
    }

    $seriesMarker = 'reschedule:' . $requestChangeForEventId;
    $duplicateRequestStmt = $pdo->prepare(
        'SELECT id
         FROM coach_calendar_events
         WHERE coach_id = ?
           AND requested_by_athlete_id = ?
           AND approval_status = "pending"
           AND series_id = ?
         LIMIT 1'
    );
    $duplicateRequestStmt->execute([(int)$athlete['coach_id'], $athleteId, $seriesMarker]);
    if ($duplicateRequestStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Pro tento termín už čeká žádost o změnu.']);
        exit;
    }

    $insertRequestStmt = $pdo->prepare(
        'INSERT INTO coach_calendar_events (coach_id, athlete_id, second_athlete_id, requested_by_athlete_id, approval_status, coach_modified_at, is_makeup_session, billing_month, series_id, color_key, custom_title, location, starts_at, ends_at)
         VALUES (?, ?, ?, ?, "pending", NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertRequestStmt->execute([
        (int)$athlete['coach_id'],
        (int)($requestChangeEvent['athlete_id'] ?? 0) > 0 ? (int)$requestChangeEvent['athlete_id'] : null,
        (int)($requestChangeEvent['second_athlete_id'] ?? 0) > 0 ? (int)$requestChangeEvent['second_athlete_id'] : null,
        $athleteId,
        (int)($requestChangeEvent['is_makeup_session'] ?? 0) === 1 ? 1 : 0,
        !empty($requestChangeEvent['billing_month']) ? (string)$requestChangeEvent['billing_month'] : $billingMonthSql,
        $seriesMarker,
        (string)($requestChangeEvent['color_key'] ?? 'green') !== '' ? (string)$requestChangeEvent['color_key'] : 'green',
        $customTitle,
        $location,
        $startSql,
        $endSql,
    ]);
    $newRequestEventId = (int)$pdo->lastInsertId();

    $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
    $timeLabel = $start->format('d.m.Y H:i');
    $oldStartLabel = !empty($requestChangeEvent['starts_at']) ? date('d.m.Y H:i', strtotime((string)$requestChangeEvent['starts_at'])) : 'původní termín';
    $oldLocation = trim((string)($requestChangeEvent['location'] ?? ''));
    $calendarDay = $start->format('Y-m-d');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $calendarUrl = $host !== ''
        ? ($scheme . '://' . $host . BASE_URL . '/calendar.php?tab=week&focus_date=' . rawurlencode($calendarDay) . '&focus_event=' . $newRequestEventId)
        : (BASE_URL . '/calendar.php?tab=week&focus_date=' . rawurlencode($calendarDay) . '&focus_event=' . $newRequestEventId);
    $subject = 'Žádost o změnu termínu';
    $body = 'Sportovec ' . $athleteName . ' požádal o změnu termínu.';
    $body .= ' Původně: ' . $oldStartLabel . '.';
    $body .= ' Nově navržené: ' . $timeLabel . '.';
    if ($location) {
        $body .= ' Místo: ' . $location . '.';
    }
    if ($oldLocation !== '' && $oldLocation !== (string)$location) {
        $body .= ' Původní místo: ' . $oldLocation . '.';
    }
    $body .= "\nPřejít do kalendáře: " . $calendarUrl;

    createCoachSystemMessage((int)$athlete['coach_id'], $subject, $body, true);
    createAthleteNotification($athleteId, 'Žádost o změnu odeslána', 'Tvoje žádost o změnu termínu byla odeslána trenérovi.');

    enqueueCoachGoogleCalendarSync((int)$athlete['coach_id'], $newRequestEventId, 'upsert');
    enqueueCoachAppleCaldavSync((int)$athlete['coach_id'], $newRequestEventId, 'upsert');
    $syncAthleteIds = array_values(array_unique(array_filter([
        (int)($requestChangeEvent['athlete_id'] ?? 0),
        (int)($requestChangeEvent['second_athlete_id'] ?? 0),
    ])));
    foreach ($syncAthleteIds as $syncAthleteId) {
        enqueueAthleteAppleCaldavSync($syncAthleteId, $newRequestEventId, 'upsert');
    }
    processInlineCalendarSyncQueues(2, 3, 3);

    echo json_encode([
        'success' => true,
        'message' => 'Žádost o změnu byla odeslána trenérovi.',
        'coach_apple_sync_ok' => null,
        'athlete_apple_sync_ok' => null,
        'sync_queued' => true,
    ]);
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
$lockStmt->execute([(int)$athlete['coach_id'], $endSql, $startSql]);
if ($lockStmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Termín je uzamčený a nelze jej rezervovat.']);
    exit;
}

$overlapStmt = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_events
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
     LIMIT 1'
);
$overlapStmt->execute([(int)$athlete['coach_id'], $endSql, $startSql]);
if ($overlapStmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'V tomto čase je slot obsazený.']);
    exit;
}

$newEventId = 0;
if ($isEditMode) {
    $update = $pdo->prepare(
        'UPDATE coach_calendar_events
         SET approval_status = "pending",
             coach_modified_at = NULL,
             is_makeup_session = ?,
             billing_month = ?,
             billing_issue_number = ?,
             color_key = ?,
             custom_title = ?,
             location = ?,
             starts_at = ?,
             ends_at = ?
         WHERE id = ?
           AND coach_id = ?
           AND approval_status = "pending"
           AND requested_by_athlete_id = ?'
    );
    $update->execute([
        $isMakeupSession,
        $billingMonthSql,
        $billingIssueNumber,
        'green',
        $customTitle,
        $location,
        $startSql,
        $endSql,
        $eventId,
        (int)$athlete['coach_id'],
        $athleteId,
    ]);

    if ($update->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Požadavek se nepodařilo upravit.']);
        exit;
    }

    $newEventId = $eventId;
} else {
    $insert = $pdo->prepare(
        'INSERT INTO coach_calendar_events (coach_id, athlete_id, requested_by_athlete_id, approval_status, coach_modified_at, is_makeup_session, billing_month, billing_issue_number, series_id, color_key, custom_title, location, starts_at, ends_at)
        VALUES (?, ?, ?, ?, NULL, ?, ?, ?, NULL, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        (int)$athlete['coach_id'],
        $athleteId,
        $athleteId,
        'pending',
        $isMakeupSession,
        $billingMonthSql,
        $billingIssueNumber,
        'green',
        $customTitle,
        $location,
        $startSql,
        $endSql,
    ]);

    $newEventId = (int)$pdo->lastInsertId();
}

if ($replacementCancellationId !== null
    && athleteReserveTableExists($pdo, 'coach_calendar_event_cancellations')
    && athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
) {
    $bindReplacementStmt = $pdo->prepare(
        'UPDATE coach_calendar_event_cancellations
         SET replacement_event_id = ?
                 WHERE id = ?
           AND coach_id = ?
                     AND (athlete_id = ? OR second_athlete_id = ?)
                     AND replacement_required = 1
                     AND replacement_deadline_at > NOW()
           AND replacement_event_id IS NULL'
    );
        $bindReplacementStmt->execute([$newEventId, $replacementCancellationId, (int)$athlete['coach_id'], $athleteId, $athleteId]);
        if ($bindReplacementStmt->rowCount() !== 1) {
                $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND coach_id = ?')->execute([$newEventId, (int)$athlete['coach_id']]);
                echo json_encode(['success' => false, 'error' => 'Nárok na náhradu mezitím vypršel nebo byl již využit. Obnovte kalendář a zkuste jiný termín.']);
                exit;
        }
}


$athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
$timeLabel = $start->format('d.m.Y H:i');
if ($isEditMode) {
    $subject = "Upravený požadavek termínu - {$athleteName}";
    $body = "Sportovec {$athleteName} upravil svůj čekající požadavek na termín {$timeLabel}.";
    if ($location) {
        $body .= " Místo: {$location}.";
    }
    if ($customTitle !== '') {
        $body .= " Poznámka: {$customTitle}.";
    }
    createCoachSystemMessage((int)$athlete['coach_id'], $subject, $body, true);
    createAthleteNotification($athleteId, 'Požadavek upraven', "Tvůj čekající požadavek na termín {$timeLabel} byl upraven a čeká znovu na schválení trenérem.");
} else {
    $subject = "Nový požadavek termínu - {$athleteName}";
    $body = "Sportovec {$athleteName} si rezervoval termín {$timeLabel}.";
    if ($location) {
        $body .= " Místo: {$location}.";
    }
    if ($customTitle !== '') {
        $body .= " Poznámka: {$customTitle}.";
    }
    createCoachSystemMessage((int)$athlete['coach_id'], $subject, $body, true);

    createAthleteNotification($athleteId, 'Požadavek odeslán ke schválení', "Tvůj požadavek na termín {$timeLabel} čeká na schválení trenérem.");
}

enqueueCoachGoogleCalendarSync((int)$athlete['coach_id'], $newEventId, 'upsert');
enqueueCoachAppleCaldavSync((int)$athlete['coach_id'], $newEventId, 'upsert');
enqueueAthleteAppleCaldavSync($athleteId, $newEventId, 'upsert');
processInlineCalendarSyncQueues(2, 3, 3);

echo json_encode([
    'success' => true,
    'message' => $isEditMode ? 'Požadavek byl upraven.' : 'Požadavek byl odeslán ke schválení.',
    'coach_apple_sync_ok' => null,
    'athlete_apple_sync_ok' => null,
    'sync_queued' => true,
]);
