<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_billing.php';

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
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $currentCsrfToken = csrfToken();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Neplatný CSRF token',
        'csrf_token' => $currentCsrfToken,
    ]);
    exit;
}

$coachId = (int)getCurrentCoachId();
$pdo = getDB();
paymentEnsureBillingIssueSchema($pdo);
$titleTypeColumn = $pdo->query("SHOW COLUMNS FROM coach_calendar_events LIKE 'title_type'")->fetch();
if (!$titleTypeColumn) {
    echo json_encode([
        'success' => false,
        'error' => 'Kalendář vyžaduje jednorázovou aktualizaci databáze administrátorem.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function generateUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    $hex = bin2hex($data);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function parseRepeatUntil(DateTime $start, string $repeatMode, string $repeatUntilRaw): ?DateTime
{
    if ($repeatMode === 'none') {
        return null;
    }

    if ($repeatMode === 'weekly_until_date') {
        $until = DateTime::createFromFormat('Y-m-d', $repeatUntilRaw);
        if (!$until) {
            return null;
        }
        $until->setTime(23, 59, 59);
        return $until;
    }

    if ($repeatMode === 'weekly_end_of_next_month') {
        $until = clone $start;
        $until->modify('last day of next month')->setTime(23, 59, 59);
        return $until;
    }

    if ($repeatMode === 'weekly_end_of_year') {
        $until = clone $start;
        $until->setDate((int)$start->format('Y'), 12, 31)->setTime(23, 59, 59);
        return $until;
    }

    return null;
}

function extractRescheduleTargetEventId(?string $seriesId): int
{
    if (!is_string($seriesId)) {
        return 0;
    }

    if (preg_match('/^reschedule:(\d+)$/', trim($seriesId), $matches)) {
        return (int)$matches[1];
    }

    return 0;
}

function resolveOpenBillingMonth(PDO $pdo, int $coachId, int $athleteId, string $targetMonthSql): string
{
    return $targetMonthSql;
}

$eventId = (int)($input['event_id'] ?? 0);
$athleteId = (int)($input['athlete_id'] ?? 0);
$secondAthleteId = (int)($input['second_athlete_id'] ?? 0);
$titleType = trim((string)($input['title_type'] ?? 'training'));
$customTitle = trim((string)($input['custom_title'] ?? ''));
$location = trim((string)($input['location'] ?? ''));
$startsAtRaw = trim((string)($input['starts_at'] ?? ''));
$repeatMode = trim((string)($input['repeat_mode'] ?? 'none'));
$repeatUntilRaw = trim((string)($input['repeat_until'] ?? ''));
$colorKey = trim((string)($input['color_key'] ?? 'green'));
$approvalAction = trim((string)($input['approval_action'] ?? ''));
$isMakeupSession = !empty($input['is_makeup_session']);

$allowedRepeatModes = ['none', 'weekly_until_date', 'weekly_end_of_next_month', 'weekly_end_of_year'];
if (!in_array($repeatMode, $allowedRepeatModes, true)) {
    $repeatMode = 'none';
}

$allowedColorKeys = ['blue', 'green', 'red', 'orange', 'teal', 'yellow', 'purple', 'gray'];
if (!in_array($colorKey, $allowedColorKeys, true)) {
    $colorKey = 'green';
}

if (!in_array($titleType, ['training', 'consultation', 'other', 'group_lesson'], true)) {
    $titleType = 'training';
}

$titleLabels = [
    'training' => 'Trénink',
    'consultation' => 'Konzultační hodina',
    'other' => 'Jiné',
    'group_lesson' => 'Skupinová lekce',
];

if ($customTitle === '' && in_array($titleType, ['consultation', 'other'], true)) {
    $customTitle = $titleLabels[$titleType];
}

$start = DateTime::createFromFormat('Y-m-d\TH:i', $startsAtRaw);
if (!$start) {
    echo json_encode(['success' => false, 'error' => 'Neplatný začátek tréninku']);
    exit;
}

$end = clone $start;
$end->modify('+60 minutes');

if ($athleteId <= 0 && $customTitle === '') {
    echo json_encode(['success' => false, 'error' => 'Vyberte sportovce nebo vyplňte vlastní název']);
    exit;
}

if ($athleteId <= 0 && $secondAthleteId > 0) {
    $athleteId = $secondAthleteId;
    $secondAthleteId = 0;
}

if ($athleteId > 0 && $secondAthleteId > 0 && $athleteId === $secondAthleteId) {
    echo json_encode(['success' => false, 'error' => 'Párový trénink vyžaduje dva různé sportovce']);
    exit;
}

if ($athleteId > 0) {
    $athleteStmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ?');
    $athleteStmt->execute([$athleteId, $coachId]);
    if (!$athleteStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Sportovec nepatří tomuto trenérovi']);
        exit;
    }
} else {
    $athleteId = null;
}

if ($secondAthleteId > 0) {
    $athleteStmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ?');
    $athleteStmt->execute([$secondAthleteId, $coachId]);
    if (!$athleteStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Druhý sportovec nepatří tomuto trenérovi']);
        exit;
    }
} else {
    $secondAthleteId = null;
}

if ($titleType === 'group_lesson') {
    if ($customTitle === '') {
        echo json_encode(['success' => false, 'error' => 'U skupinové lekce vyplňte název.']);
        exit;
    }
    if ($location === '') {
        echo json_encode(['success' => false, 'error' => 'U skupinové lekce vyberte nebo vyplňte místo konání.']);
        exit;
    }
    $athleteId = null;
    $secondAthleteId = null;
    $isMakeupSession = false;
}

if ($customTitle !== '') {
    $customTitle = mb_substr($customTitle, 0, 140, 'UTF-8');
} else {
    $customTitle = null;
}

if ($location !== '') {
    rememberTrainingVenue($location, $coachId);

    $venueStmt = $pdo->prepare('SELECT name FROM training_venues WHERE name = ? AND created_by_coach_id IS NULL LIMIT 1');
    $venueStmt->execute([$location]);
    $venue = $venueStmt->fetch();
    if ($venue && !empty($venue['name'])) {
        $location = (string)$venue['name'];
    }

    $location = mb_substr($location, 0, 255, 'UTF-8');
} else {
    $location = null;
}

if ($eventId > 0) {
    $ownerStmt = $pdo->prepare(
        'SELECT e.id,
                e.athlete_id,
            e.second_athlete_id,
                e.series_id,
                e.requested_by_athlete_id,
                e.approval_status,
                e.coach_modified_at,
                e.is_makeup_session,
                e.billing_month,
                e.billing_issue_number,
                e.billing_transfer_reason,
                e.title_type,
                e.custom_title,
                e.location,
                e.starts_at,
                e.ends_at,
                a.email AS athlete_email,
                a.first_name,
                  a.last_name,
                  a2.email AS second_athlete_email,
                  a2.first_name AS second_first_name,
                  a2.last_name AS second_last_name
         FROM coach_calendar_events e
         LEFT JOIN athletes a ON a.id = e.athlete_id
              LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
         WHERE e.id = ? AND e.coach_id = ?'
    );
    $ownerStmt->execute([$eventId, $coachId]);
    $existingEvent = $ownerStmt->fetch();
    if (!$existingEvent) {
        echo json_encode(['success' => false, 'error' => 'Událost nenalezena']);
        exit;
    }
}

$startSql = $start->format('Y-m-d H:i:s');
$endSql = $end->format('Y-m-d H:i:s');
$targetMonthSql = $start->format('Y-m-01');
$billingMonthSql = $targetMonthSql;
$billingIssueNumberSql = $eventId > 0 ? max(1, (int)($existingEvent['billing_issue_number'] ?? 1)) : 1;
$billingTransferReasonSql = $eventId > 0 ? ($existingEvent['billing_transfer_reason'] ?? null) : null;
$replacementCancellationId = null;
$billingAssignment = null;
$repeatUntilForUpdate = null;
$shouldCreateRecurrenceFromUpdate = false;

if ($isMakeupSession) {
    if ($athleteId === null || (int)$athleteId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Náhradní termín lze použít jen pro konkrétního sportovce.']);
        exit;
    }

    if ($secondAthleteId !== null) {
        echo json_encode(['success' => false, 'error' => 'Náhradní termín nelze použít u párového tréninku.']);
        exit;
    }
    if ($repeatMode !== 'none') {
        echo json_encode(['success' => false, 'error' => 'Náhradní termín nelze vytvářet jako opakovanou sérii.']);
        exit;
    }
    $replacementCancellation = paymentFindActiveReplacementCancellation(
        $pdo,
        $coachId,
        (int)$athleteId,
        $eventId > 0 ? $eventId : null
    );
    if (!$replacementCancellation) {
        echo json_encode(['success' => false, 'error' => 'Sportovec nemá žádné aktivní storno s nevypršenou 14denní lhůtou.']);
        exit;
    }
    $replacementCancellationId = (int)$replacementCancellation['id'];
    $billingMonthSql = !empty($replacementCancellation['billing_month'])
        ? date('Y-m-01', strtotime((string)$replacementCancellation['billing_month']))
        : $targetMonthSql;
    $billingIssueNumberSql = max(1, (int)($replacementCancellation['billing_issue_number'] ?? 1));
} elseif ($athleteId > 0) {
    $billingMonthSql = resolveOpenBillingMonth($pdo, $coachId, (int)$athleteId, $targetMonthSql);
}

$isNewAthleteApproval = $eventId > 0
    && (string)($existingEvent['approval_status'] ?? '') === 'pending'
    && (int)($existingEvent['requested_by_athlete_id'] ?? 0) > 0
    && extractRescheduleTargetEventId((string)($existingEvent['series_id'] ?? '')) === 0
    && !$isMakeupSession;
if ($isNewAthleteApproval) {
    $decisionMap = is_array($input['billing_decisions'] ?? null) ? $input['billing_decisions'] : [];
    $decision = (string)($decisionMap[$targetMonthSql] ?? $input['billing_decision'] ?? '');
    $billingAssignment = paymentResolveTrainingAssignment(
        $pdo,
        $coachId,
        [(int)($athleteId ?? 0), (int)($secondAthleteId ?? 0)],
        $targetMonthSql,
        $decision
    );
    if (isset($billingAssignment['error'])) {
        echo json_encode(['success' => false, 'error' => $billingAssignment['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($billingAssignment['requires_choice'])) {
        echo json_encode([
            'success' => false,
            'billing_choice_required' => true,
            'billing_choices' => [$billingAssignment['choice']],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $billingMonthSql = (string)$billingAssignment['billing_month'];
    $billingIssueNumberSql = (int)$billingAssignment['issue_number'];
    $billingTransferReasonSql = $billingAssignment['billing_transfer_reason'] ?? null;
}

if ($eventId > 0 && $repeatMode !== 'none') {
    $existingSeriesId = trim((string)($existingEvent['series_id'] ?? ''));
    if ($existingSeriesId !== '') {
        echo json_encode(['success' => false, 'error' => 'Tato událost už je součástí série. Vytvořte nové opakování z jednorázové události.']);
        exit;
    }

    $repeatUntilForUpdate = parseRepeatUntil($start, $repeatMode, $repeatUntilRaw);
    if (!$repeatUntilForUpdate) {
        echo json_encode(['success' => false, 'error' => 'Neplatné datum opakování']);
        exit;
    }

    $nextWeek = (clone $start)->modify('+7 days');
    if ($nextWeek > $repeatUntilForUpdate) {
        echo json_encode(['success' => false, 'error' => 'Pro opakování vyberte pozdější datum konce (alespoň o týden).']);
        exit;
    }

    $shouldCreateRecurrenceFromUpdate = true;
}

if ($eventId > 0) {
    $isPendingRequest = (($existingEvent['approval_status'] ?? 'approved') === 'pending') && !empty($existingEvent['requested_by_athlete_id']);
    $rescheduleSourceEventId = $isPendingRequest ? extractRescheduleTargetEventId((string)($existingEvent['series_id'] ?? '')) : 0;

    $lockStmt = $pdo->prepare(
        'SELECT id
         FROM coach_calendar_locks
         WHERE coach_id = ?
           AND starts_at < ?
           AND ends_at > ?
         LIMIT 1'
    );
    $lockStmt->execute([$coachId, $endSql, $startSql]);
    if ($lockStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Čas je uzamčený. Nejprve upravte uzamčení.']);
        exit;
    }

    $overlapStmt = $pdo->prepare(
        'SELECT id
         FROM coach_calendar_events
         WHERE coach_id = ?
           AND starts_at < ?
           AND ends_at > ?
                     AND id <> ?
                     AND (? <= 0 OR id <> ?)
         LIMIT 1'
    );
        $overlapStmt->execute([$coachId, $endSql, $startSql, $eventId, $rescheduleSourceEventId, $rescheduleSourceEventId]);
    if ($overlapStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'V tomto čase už máte jiný trénink']);
        exit;
    }

    $upd = $pdo->prepare(
        'UPDATE coach_calendar_events
         SET athlete_id = ?,
             second_athlete_id = ?,
             series_id = ?,
             approval_status = ?,
             coach_modified_at = ?,
             is_makeup_session = ?,
             billing_month = ?,
             billing_issue_number = ?,
             billing_transfer_reason = ?,
             color_key = ?,
             title_type = ?,
             custom_title = ?,
             location = ?,
             starts_at = ?,
             ends_at = ?
         WHERE id = ? AND coach_id = ?'
    );

    $oldStart = (string)$existingEvent['starts_at'];
    $oldEnd = (string)$existingEvent['ends_at'];
    $oldSeriesId = (string)($existingEvent['series_id'] ?? '');
    $oldSecondAthleteId = (int)($existingEvent['second_athlete_id'] ?? 0);
    $oldLocation = (string)($existingEvent['location'] ?? '');
    $oldTitleType = (string)($existingEvent['title_type'] ?? 'training');
    $oldTitle = (string)($existingEvent['custom_title'] ?? '');
    $oldIsMakeup = (int)($existingEvent['is_makeup_session'] ?? 0);
    $oldBillingMonth = (string)($existingEvent['billing_month'] ?? '');
    $oldBillingIssueNumber = max(1, (int)($existingEvent['billing_issue_number'] ?? 1));
    $newSeriesId = $oldSeriesId !== '' ? $oldSeriesId : null;
    if ($shouldCreateRecurrenceFromUpdate) {
        $newSeriesId = generateUuidV4();
    }
    $changed = ($oldStart !== $startSql)
        || ($oldEnd !== $endSql)
        || ($oldSeriesId !== (string)$newSeriesId)
        || ($oldSecondAthleteId !== (int)($secondAthleteId ?? 0))
        || ($oldLocation !== (string)$location)
        || ($oldTitleType !== $titleType)
        || ($oldTitle !== (string)$customTitle)
        || ($oldIsMakeup !== (int)$isMakeupSession)
        || ($oldBillingMonth !== $billingMonthSql)
        || ($oldBillingIssueNumber !== $billingIssueNumberSql)
        || (($existingEvent['billing_transfer_reason'] ?? null) !== $billingTransferReasonSql);

    if ($isPendingRequest && $rescheduleSourceEventId > 0) {
        $sourceStmt = $pdo->prepare(
            'SELECT e.id,
                    e.athlete_id,
                    e.second_athlete_id,
                    e.starts_at,
                    e.ends_at,
                    e.title_type,
                    e.custom_title,
                    e.location,
                    e.is_makeup_session,
                    e.billing_month,
                    e.billing_issue_number,
                    e.billing_transfer_reason,
                    a.email AS athlete_email,
                    a.first_name,
                    a.last_name,
                    a2.email AS second_athlete_email,
                    a2.first_name AS second_first_name,
                    a2.last_name AS second_last_name
             FROM coach_calendar_events e
             LEFT JOIN athletes a ON a.id = e.athlete_id
             LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
             WHERE e.id = ?
               AND e.coach_id = ?
             LIMIT 1'
        );
        $sourceStmt->execute([$rescheduleSourceEventId, $coachId]);
        $sourceEvent = $sourceStmt->fetch();
        if (!$sourceEvent) {
            echo json_encode(['success' => false, 'error' => 'Původní termín pro změnu se nepodařilo dohledat.']);
            exit;
        }

        $billingMonthSql = !empty($sourceEvent['billing_month'])
            ? (string)$sourceEvent['billing_month']
            : date('Y-m-01', strtotime((string)($sourceEvent['starts_at'] ?? $startSql)));
        $billingIssueNumberSql = max(1, (int)($sourceEvent['billing_issue_number'] ?? 1));
        $billingTransferReasonSql = $sourceEvent['billing_transfer_reason'] ?? null;

        $sourceOldStart = (string)($sourceEvent['starts_at'] ?? '');
        $sourceOldEnd = (string)($sourceEvent['ends_at'] ?? '');
        $sourceOldTitleType = (string)($sourceEvent['title_type'] ?? 'training');
        $sourceOldTitle = (string)($sourceEvent['custom_title'] ?? '');
        $sourceOldLocation = (string)($sourceEvent['location'] ?? '');
        $sourceOldIsMakeup = (int)($sourceEvent['is_makeup_session'] ?? 0);
        $sourceOldBillingMonth = (string)($sourceEvent['billing_month'] ?? '');
        $sourceChanged = ($sourceOldStart !== $startSql)
            || ($sourceOldEnd !== $endSql)
            || ($sourceOldTitleType !== $titleType)
            || ($sourceOldTitle !== (string)$customTitle)
            || ($sourceOldLocation !== (string)$location)
            || ($sourceOldIsMakeup !== (int)$isMakeupSession)
            || ($sourceOldBillingMonth !== $billingMonthSql)
            || ((int)($sourceEvent['athlete_id'] ?? 0) !== (int)($athleteId ?? 0))
            || ((int)($sourceEvent['second_athlete_id'] ?? 0) !== (int)($secondAthleteId ?? 0));

        $sourceUpdateStmt = $pdo->prepare(
            'UPDATE coach_calendar_events
             SET athlete_id = ?,
                 second_athlete_id = ?,
                 requested_by_athlete_id = NULL,
                 approval_status = "approved",
                 coach_modified_at = ?,
                 is_makeup_session = ?,
                 billing_month = ?,
                 billing_issue_number = ?,
                 billing_transfer_reason = ?,
                 color_key = ?,
                 title_type = ?,
                 custom_title = ?,
                 location = ?,
                 starts_at = ?,
                 ends_at = ?
             WHERE id = ?
               AND coach_id = ?'
        );

        $pendingRequestsStmt = $pdo->prepare(
            'SELECT id
             FROM coach_calendar_events
             WHERE coach_id = ?
               AND approval_status = "pending"
               AND requested_by_athlete_id IS NOT NULL
               AND series_id = ?'
        );
        $pendingRequestsStmt->execute([$coachId, (string)($existingEvent['series_id'] ?? '')]);
        $pendingRequestIds = array_map('intval', $pendingRequestsStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array($eventId, $pendingRequestIds, true)) {
            $pendingRequestIds[] = $eventId;
        }
        $deleteRequestStmt = $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND coach_id = ? LIMIT 1');

        try {
            $pdo->beginTransaction();

            $sourceUpdateStmt->execute([
                $athleteId,
                $secondAthleteId,
                $sourceChanged ? date('Y-m-d H:i:s') : null,
                (int)$isMakeupSession,
                $billingMonthSql,
                $billingIssueNumberSql,
                $billingTransferReasonSql,
                $colorKey,
                $titleType,
                $customTitle,
                $location,
                $startSql,
                $endSql,
                $rescheduleSourceEventId,
                $coachId,
            ]);

            foreach ($pendingRequestIds as $pendingRequestId) {
                $deleteRequestStmt->execute([$pendingRequestId, $coachId]);
            }

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
            (int)($athleteId ?? 0),
            (int)($secondAthleteId ?? 0),
            (int)($existingEvent['requested_by_athlete_id'] ?? 0),
        ])));

        enqueueCoachGoogleCalendarSync($coachId, $rescheduleSourceEventId, 'upsert');
        enqueueCoachAppleCaldavSync($coachId, $rescheduleSourceEventId, 'upsert');
        foreach ($pendingRequestIds as $pendingRequestId) {
            enqueueCoachGoogleCalendarSync($coachId, $pendingRequestId, 'delete');
            enqueueCoachAppleCaldavSync($coachId, $pendingRequestId, 'delete');
        }
        foreach ($syncAthleteIds as $syncAthleteId) {
            enqueueAthleteAppleCaldavSync($syncAthleteId, $rescheduleSourceEventId, 'upsert');
            foreach ($pendingRequestIds as $pendingRequestId) {
                enqueueAthleteAppleCaldavSync($syncAthleteId, $pendingRequestId, 'delete');
            }
        }
        processInlineCalendarSyncQueues(2, 3, 3);

        $participants = [];
        if ((int)($athleteId ?? 0) > 0) {
            $participants[] = [
                'id' => (int)$athleteId,
                'email' => (string)($sourceEvent['athlete_email'] ?? ''),
                'name' => trim((string)($sourceEvent['first_name'] ?? '') . ' ' . (string)($sourceEvent['last_name'] ?? '')),
            ];
        }
        if ((int)($secondAthleteId ?? 0) > 0) {
            $participants[] = [
                'id' => (int)$secondAthleteId,
                'email' => (string)($sourceEvent['second_athlete_email'] ?? ''),
                'name' => trim((string)($sourceEvent['second_first_name'] ?? '') . ' ' . (string)($sourceEvent['second_last_name'] ?? '')),
            ];
        }

        $newStartLabel = date('d.m.Y H:i', strtotime($startSql));
        foreach ($participants as $participant) {
            if ((int)($participant['id'] ?? 0) <= 0) {
                continue;
            }
            $subject = 'Žádost o změnu byla schválena';
            $body = 'Trenér schválil změnu termínu. Nový termín: ' . $newStartLabel . '.';
            if ($location) {
                $body .= ' Místo: ' . $location . '.';
            }
            createAthleteNotification((int)$participant['id'], $subject, $body);
            if (!empty($participant['email'])) {
                $recipientName = $participant['name'] !== '' ? $participant['name'] : 'sportovec';
                sendAthleteCalendarNotificationEmail((string)$participant['email'], $recipientName, $subject, $body);
            }
        }

        echo json_encode(['success' => true, 'id' => $rescheduleSourceEventId, 'mode' => 'updated', 'approval_status' => 'approved']);
        exit;
    }

    $nextApprovalStatus = ($approvalAction === 'approve' || $isPendingRequest) ? 'approved' : (string)($existingEvent['approval_status'] ?? 'approved');
    $coachModifiedAt = $changed ? date('Y-m-d H:i:s') : ($existingEvent['coach_modified_at'] ?: null);
    $syncedEventIds = [$eventId];
    $athleteSyncIds = [
        (int)($existingEvent['athlete_id'] ?? 0),
        (int)($existingEvent['second_athlete_id'] ?? 0),
        (int)($athleteId ?? 0),
        (int)($secondAthleteId ?? 0),
    ];

    try {
        $pdo->beginTransaction();

        $upd->execute([$athleteId, $secondAthleteId, $newSeriesId, $nextApprovalStatus, $coachModifiedAt, (int)$isMakeupSession, $billingMonthSql, $billingIssueNumberSql, $billingTransferReasonSql, $colorKey, $titleType, $customTitle, $location, $startSql, $endSql, $eventId, $coachId]);
        if ($replacementCancellationId !== null) {
            $bindReplacementStmt = $pdo->prepare(
                'UPDATE coach_calendar_event_cancellations
                 SET replacement_event_id = ?
                 WHERE id = ? AND coach_id = ? AND athlete_id = ? AND replacement_required = 1
                   AND ((replacement_event_id IS NULL AND replacement_deadline_at > NOW()) OR replacement_event_id = ?)'
            );
            $bindReplacementStmt->execute([$eventId, $replacementCancellationId, $coachId, (int)$athleteId, $eventId]);
            if ($bindReplacementStmt->rowCount() !== 1) {
                $linkedStmt = $pdo->prepare('SELECT id FROM coach_calendar_event_cancellations WHERE id = ? AND replacement_event_id = ? LIMIT 1');
                $linkedStmt->execute([$replacementCancellationId, $eventId]);
                if (!$linkedStmt->fetchColumn()) {
                    throw new RuntimeException('Nárok na náhradu mezitím vypršel nebo byl využit.');
                }
            }
        }

        if ($shouldCreateRecurrenceFromUpdate && $repeatUntilForUpdate instanceof DateTime) {
            $lockStmtFuture = $pdo->prepare(
                'SELECT id
                 FROM coach_calendar_locks
                 WHERE coach_id = ?
                   AND starts_at < ?
                   AND ends_at > ?
                 LIMIT 1'
            );

            $overlapStmtFuture = $pdo->prepare(
                'SELECT id
                 FROM coach_calendar_events
                 WHERE coach_id = ?
                   AND starts_at < ?
                   AND ends_at > ?
                 LIMIT 1'
            );

            $insertStmtFuture = $pdo->prepare(
                'INSERT INTO coach_calendar_events (coach_id, athlete_id, second_athlete_id, requested_by_athlete_id, approval_status, coach_modified_at, is_makeup_session, billing_month, billing_issue_number, billing_transfer_reason, series_id, color_key, title_type, custom_title, location, starts_at, ends_at)
                 VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, 1, NULL, ?, ?, ?, ?, ?, ?, ?)'
            );

            $cursor = clone $start;
            while (true) {
                $cursor->modify('+7 days');
                if ($cursor > $repeatUntilForUpdate) {
                    break;
                }

                $occurrenceEnd = clone $cursor;
                $occurrenceEnd->modify('+60 minutes');

                $occurrenceStartSql = $cursor->format('Y-m-d H:i:s');
                $occurrenceEndSql = $occurrenceEnd->format('Y-m-d H:i:s');

                $lockStmtFuture->execute([$coachId, $occurrenceEndSql, $occurrenceStartSql]);
                if ($lockStmtFuture->fetch()) {
                    throw new RuntimeException('Čas je uzamčený: ' . $cursor->format('d.m.Y H:i'));
                }

                $overlapStmtFuture->execute([$coachId, $occurrenceEndSql, $occurrenceStartSql]);
                if ($overlapStmtFuture->fetch()) {
                    throw new RuntimeException('V tomto čase už máte trénink: ' . $cursor->format('d.m.Y H:i'));
                }

                $occurrenceBillingMonthSql = $isMakeupSession
                    ? $billingMonthSql
                    : resolveOpenBillingMonth($pdo, $coachId, (int)($athleteId ?? 0), $cursor->format('Y-m-01'));

                $insertStmtFuture->execute([
                    $coachId,
                    $athleteId,
                    $secondAthleteId,
                    $nextApprovalStatus,
                    (int)$isMakeupSession,
                    $occurrenceBillingMonthSql,
                    $newSeriesId,
                    $colorKey,
                    $titleType,
                    $customTitle,
                    $location,
                    $occurrenceStartSql,
                    $occurrenceEndSql,
                ]);
                $syncedEventIds[] = (int)$pdo->lastInsertId();
            }
        }

        if (is_array($billingAssignment)) {
            foreach ($billingAssignment['refresh_athletes'] ?? [] as $refreshAthleteId) {
                paymentRefreshPendingIssue($pdo, $coachId, (int)$refreshAthleteId, $billingMonthSql, $billingIssueNumberSql);
            }
            foreach ($billingAssignment['additional_athletes'] ?? [] as $additionalAthleteId) {
                paymentCreateAdditionalIssue($pdo, $coachId, (int)$additionalAthleteId, $billingMonthSql, $billingIssueNumberSql, $billingAssignment['session_rates'][(int)$additionalAthleteId] ?? $billingAssignment['session_rate'] ?? null);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    if (!empty($existingEvent['athlete_id'])) {
        if ($changed || ($approvalAction === 'approve' && $isPendingRequest)) {
            $athleteEventId = (int)$existingEvent['athlete_id'];
            $athleteName = trim((string)$existingEvent['first_name'] . ' ' . (string)$existingEvent['last_name']);
            $newStartLabel = date('d.m.Y H:i', strtotime($startSql));
            if ($changed) {
                $subject = 'Trenér upravil termín tréninku';
                $body = 'Trenér upravil váš trénink. Nový termín: ' . $newStartLabel . '.';
                if ($location) {
                    $body .= ' Místo: ' . $location . '.';
                }
                if ($isMakeupSession) {
                    $body .= ' Termín je veden jako náhrada hrazená v měsíci ' . date('m/Y', strtotime($billingMonthSql)) . '.';
                }
            } else {
                $subject = 'Trénink byl schválen';
                $body = 'Trenér schválil váš termín ' . $newStartLabel . '.';
                if ($location) {
                    $body .= ' Místo: ' . $location . '.';
                }
            }

            createAthleteNotification($athleteEventId, $subject, $body);
            if (!empty($existingEvent['athlete_email'])) {
                sendAthleteCalendarNotificationEmail((string)$existingEvent['athlete_email'], $athleteName, $subject, $body);
            }
        }
    }

    foreach (array_values(array_unique(array_filter(array_map('intval', $syncedEventIds)))) as $syncEventId) {
        enqueueCoachGoogleCalendarSync($coachId, $syncEventId, 'upsert');
        enqueueCoachAppleCaldavSync($coachId, $syncEventId, 'upsert');
        foreach (array_values(array_unique(array_filter(array_map('intval', $athleteSyncIds)))) as $athleteSyncId) {
            enqueueAthleteAppleCaldavSync($athleteSyncId, $syncEventId, 'upsert');
        }
    }
    processInlineCalendarSyncQueues(2, 3, 3);

    echo json_encode(['success' => true, 'id' => $eventId, 'mode' => 'updated', 'approval_status' => $nextApprovalStatus]);
    exit;
}

$repeatUntil = parseRepeatUntil($start, $repeatMode, $repeatUntilRaw);
if ($repeatMode !== 'none' && !$repeatUntil) {
    echo json_encode(['success' => false, 'error' => 'Neplatné datum opakování']);
    exit;
}

$occurrences = [];
$cursor = clone $start;
$maxOccurrences = 260;

while (true) {
    $occurrences[] = clone $cursor;

    if ($repeatMode === 'none') {
        break;
    }

    if (count($occurrences) >= $maxOccurrences) {
        break;
    }

    $cursor->modify('+7 days');
    if ($repeatUntil instanceof DateTime && $cursor > $repeatUntil) {
        break;
    }
}

$billingDecisions = is_array($input['billing_decisions'] ?? null) ? $input['billing_decisions'] : [];
$billingAssignmentsByMonth = [];
$billingChoices = [];
if ((int)($athleteId ?? 0) > 0) {
    foreach ($occurrences as $occurrenceStart) {
        $month = $occurrenceStart->format('Y-m-01');
        if (isset($billingAssignmentsByMonth[$month])) {
            continue;
        }
        $assignment = paymentResolveTrainingAssignment(
            $pdo,
            $coachId,
            [(int)$athleteId, (int)($secondAthleteId ?? 0)],
            $month,
            (string)($billingDecisions[$month] ?? '')
        );
        if (isset($assignment['error'])) {
            echo json_encode(['success' => false, 'error' => $assignment['error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!empty($assignment['requires_choice'])) {
            $billingChoices[$month] = $assignment['choice'];
        } else {
            $billingAssignmentsByMonth[$month] = $assignment;
        }
    }
    if ($billingChoices !== []) {
        echo json_encode([
            'success' => false,
            'billing_choice_required' => true,
            'billing_choices' => array_values($billingChoices),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$lockStmt = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_locks
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
     LIMIT 1'
);

$overlapStmt = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_events
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
     LIMIT 1'
);

$insertStmt = $pdo->prepare(
    'INSERT INTO coach_calendar_events (coach_id, athlete_id, second_athlete_id, requested_by_athlete_id, approval_status, coach_modified_at, is_makeup_session, billing_month, billing_issue_number, billing_transfer_reason, series_id, color_key, title_type, custom_title, location, starts_at, ends_at)
     VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$seriesId = $repeatMode === 'none' ? null : generateUuidV4();
$createdIds = [];

try {
    $pdo->beginTransaction();

    foreach ($occurrences as $occurrenceStart) {
        $occurrenceEnd = clone $occurrenceStart;
        $occurrenceEnd->modify('+60 minutes');

        $occurrenceStartSql = $occurrenceStart->format('Y-m-d H:i:s');
        $occurrenceEndSql = $occurrenceEnd->format('Y-m-d H:i:s');

        $lockStmt->execute([$coachId, $occurrenceEndSql, $occurrenceStartSql]);
        if ($lockStmt->fetch()) {
            throw new RuntimeException('Čas je uzamčený: ' . $occurrenceStart->format('d.m.Y H:i'));
        }

        $overlapStmt->execute([$coachId, $occurrenceEndSql, $occurrenceStartSql]);
        if ($overlapStmt->fetch()) {
            throw new RuntimeException('V tomto čase už máte trénink: ' . $occurrenceStart->format('d.m.Y H:i'));
        }

        $calendarMonth = $occurrenceStart->format('Y-m-01');
        $assignment = $billingAssignmentsByMonth[$calendarMonth] ?? [
            'billing_month' => $calendarMonth,
            'issue_number' => 1,
            'refresh_athletes' => [],
            'additional_athletes' => [],
        ];
        $occurrenceBillingMonthSql = (string)$assignment['billing_month'];
        $occurrenceIssueNumber = (int)$assignment['issue_number'];
        $occurrenceTransferReason = $assignment['billing_transfer_reason'] ?? null;

        $insertStmt->execute([
            $coachId,
            $athleteId,
            $secondAthleteId,
            'approved',
            (int)$isMakeupSession,
            $occurrenceBillingMonthSql,
            $occurrenceIssueNumber,
            $occurrenceTransferReason,
            $seriesId,
            $colorKey,
            $titleType,
            $customTitle,
            $location,
            $occurrenceStartSql,
            $occurrenceEndSql,
        ]);
        $createdIds[] = (int)$pdo->lastInsertId();
    }

        if ($replacementCancellationId !== null && !empty($createdIds[0])) {
            $bindReplacementStmt = $pdo->prepare(
                'UPDATE coach_calendar_event_cancellations
                 SET replacement_event_id = ?
                 WHERE id = ? AND coach_id = ? AND athlete_id = ?
                   AND replacement_required = 1 AND replacement_event_id IS NULL
                   AND replacement_deadline_at > NOW()'
            );
            $bindReplacementStmt->execute([$createdIds[0], $replacementCancellationId, $coachId, (int)$athleteId]);
            if ($bindReplacementStmt->rowCount() !== 1) {
                throw new RuntimeException('Nárok na náhradu mezitím vypršel nebo byl využit.');
            }
        }

    foreach ($billingAssignmentsByMonth as $month => $assignment) {
        foreach ($assignment['refresh_athletes'] ?? [] as $refreshAthleteId) {
            paymentRefreshPendingIssue($pdo, $coachId, (int)$refreshAthleteId, (string)$assignment['billing_month'], (int)$assignment['issue_number']);
        }
        foreach ($assignment['additional_athletes'] ?? [] as $additionalAthleteId) {
            paymentCreateAdditionalIssue($pdo, $coachId, (int)$additionalAthleteId, (string)$assignment['billing_month'], (int)$assignment['issue_number'], $assignment['session_rates'][(int)$additionalAthleteId] ?? $assignment['session_rate'] ?? null);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

foreach ($createdIds as $syncEventId) {
    enqueueCoachGoogleCalendarSync($coachId, (int)$syncEventId, 'upsert');
    enqueueCoachAppleCaldavSync($coachId, (int)$syncEventId, 'upsert');
    if ((int)($athleteId ?? 0) > 0) {
        enqueueAthleteAppleCaldavSync((int)$athleteId, (int)$syncEventId, 'upsert');
    }
    if ((int)($secondAthleteId ?? 0) > 0) {
        enqueueAthleteAppleCaldavSync((int)$secondAthleteId, (int)$syncEventId, 'upsert');
    }
}
processInlineCalendarSyncQueues(2, 3, 3);

echo json_encode([
    'success' => true,
    'id' => $createdIds[0] ?? 0,
    'created_count' => count($createdIds),
    'mode' => $repeatMode === 'none' ? 'created' : 'created_series',
]);
