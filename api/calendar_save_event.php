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

$coachId = (int)getCurrentCoachId();
$pdo = getDB();

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

function saveEventTableExists(PDO $pdo, string $tableName): bool
{
    $quoted = $pdo->quote($tableName);
    $stmt = $pdo->query("SHOW TABLES LIKE {$quoted}");
    return $stmt !== false && (bool)$stmt->fetchColumn();
}

function saveEventColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $quoted = $pdo->quote($columnName);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quoted}");
    return $stmt !== false && (bool)$stmt->fetch();
}

function resolveAutoMakeupBillingMonth(PDO $pdo, int $coachId, int $athleteId, string $targetMonthSql, ?int $excludeEventId = null): ?string
{
    if (!saveEventTableExists($pdo, 'athlete_monthly_payments') || !saveEventTableExists($pdo, 'coach_calendar_events')) {
        return null;
    }

    $hasBillingMonth = saveEventColumnExists($pdo, 'coach_calendar_events', 'billing_month');
    $hasSecondAthlete = saveEventColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
    $hasCarryoverUsed = saveEventColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions');
    $currentMonthSql = (new DateTime('now'))->format('Y-m-01');
    $carryoverCutoffSql = strcmp($targetMonthSql, $currentMonthSql) < 0 ? $targetMonthSql : $currentMonthSql;

    $monthExpr = $hasBillingMonth
        ? "DATE_FORMAT(COALESCE(t.billing_month, t.starts_at), '%Y-%m-01')"
        : "DATE_FORMAT(t.starts_at, '%Y-%m-01')";
    $billingField = $hasBillingMonth ? 'billing_month' : 'NULL AS billing_month';
    $excludeSql = ($excludeEventId !== null && $excludeEventId > 0) ? ' AND id <> ?' : '';

    if ($hasSecondAthlete) {
        $participantsSql = "
            SELECT starts_at, {$billingField}
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND approval_status = 'approved'
              AND athlete_id = ?{$excludeSql}
            UNION ALL
            SELECT starts_at, {$billingField}
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND approval_status = 'approved'
              AND second_athlete_id = ?{$excludeSql}
        ";
        $actualParams = [$coachId, $athleteId];
        if ($excludeSql !== '') {
            $actualParams[] = $excludeEventId;
        }
        $actualParams[] = $coachId;
        $actualParams[] = $athleteId;
        if ($excludeSql !== '') {
            $actualParams[] = $excludeEventId;
        }
        $actualParams[] = $carryoverCutoffSql;
    } else {
        $participantsSql = "
            SELECT starts_at, {$billingField}
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND approval_status = 'approved'
              AND athlete_id = ?{$excludeSql}
        ";
        $actualParams = [$coachId, $athleteId];
        if ($excludeSql !== '') {
            $actualParams[] = $excludeEventId;
        }
        $actualParams[] = $carryoverCutoffSql;
    }

    $actualByMonthStmt = $pdo->prepare(
        "SELECT {$monthExpr} AS billing_month,
                COUNT(*) AS billed_sessions
         FROM ({$participantsSql}) t
         WHERE {$monthExpr} < ?
         GROUP BY {$monthExpr}
         ORDER BY {$monthExpr} ASC"
    );
    $actualByMonthStmt->execute($actualParams);

    $actualByMonth = [];
    foreach ($actualByMonthStmt->fetchAll() as $row) {
        $actualByMonth[(string)$row['billing_month']] = (int)$row['billed_sessions'];
    }

    $paymentStmt = $pdo->prepare(
        'SELECT billing_month, planned_sessions, ' . ($hasCarryoverUsed ? 'carryover_used_sessions' : '0 AS carryover_used_sessions') . '
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND athlete_id = ?
           AND status = "paid"
            AND billing_month < ?
         ORDER BY billing_month ASC'
    );
        $paymentStmt->execute([$coachId, $athleteId, $carryoverCutoffSql]);

    $balances = [];
    foreach ($paymentStmt->fetchAll() as $row) {
        $month = (string)$row['billing_month'];
        $planned = max(0, (int)($row['planned_sessions'] ?? 0));
        $actual = max(0, (int)($actualByMonth[$month] ?? 0));
        $generated = max(0, $planned - $actual);
        $used = max(0, (int)($row['carryover_used_sessions'] ?? 0));

        if ($generated > 0) {
            $balances[] = [
                'month' => $month,
                'remaining' => $generated,
            ];
        }

        while ($used > 0 && !empty($balances)) {
            $deduct = min($used, (int)$balances[0]['remaining']);
            $balances[0]['remaining'] -= $deduct;
            $used -= $deduct;

            if ((int)$balances[0]['remaining'] <= 0) {
                array_shift($balances);
            }
        }
    }

    return empty($balances) ? null : (string)$balances[0]['month'];
}

function requireAutoMakeupBillingMonth(PDO $pdo, int $coachId, int $athleteId, string $targetMonthSql, ?int $excludeEventId = null): string
{
    $resolved = resolveAutoMakeupBillingMonth($pdo, $coachId, $athleteId, $targetMonthSql, $excludeEventId);
    if ($resolved === null) {
        throw new RuntimeException('Sportovec momentálně nemá dostupný žádný nevyužitý uhrazený trénink.');
    }

    return $resolved;
}

function resolveOpenBillingMonth(PDO $pdo, int $coachId, int $athleteId, string $targetMonthSql): string
{
    if ($athleteId <= 0) {
        return $targetMonthSql;
    }

    $hasPaymentsTable = saveEventTableExists($pdo, 'athlete_monthly_payments');
    if (!$hasPaymentsTable) {
        return $targetMonthSql;
    }

    $month = DateTime::createFromFormat('Y-m-d', $targetMonthSql) ?: new DateTime($targetMonthSql);
    if (!$month) {
        return $targetMonthSql;
    }

    $checkStmt = $pdo->prepare(
        'SELECT status
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND athlete_id = ?
           AND billing_month = ?
         LIMIT 1'
    );

    for ($i = 0; $i < 24; $i++) {
        $monthSql = $month->format('Y-m-01');
        $checkStmt->execute([$coachId, $athleteId, $monthSql]);
        $status = (string)($checkStmt->fetchColumn() ?: '');
        if ($status !== 'paid') {
            return $monthSql;
        }

        $month->modify('first day of next month');
    }

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

    $venueStmt = $pdo->prepare('SELECT name FROM training_venues WHERE name = ? LIMIT 1');
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

    $excludeEventId = $eventId > 0 ? $eventId : null;
    try {
        $billingMonthSql = requireAutoMakeupBillingMonth($pdo, $coachId, (int)$athleteId, $targetMonthSql, $excludeEventId);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
} elseif ($athleteId > 0) {
    $billingMonthSql = resolveOpenBillingMonth($pdo, $coachId, (int)$athleteId, $targetMonthSql);
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
         LIMIT 1'
    );
    $overlapStmt->execute([$coachId, $endSql, $startSql, $eventId]);
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
             color_key = ?,
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
    $oldTitle = (string)($existingEvent['custom_title'] ?? '');
    $oldIsMakeup = (int)($existingEvent['is_makeup_session'] ?? 0);
    $oldBillingMonth = (string)($existingEvent['billing_month'] ?? '');
    $newSeriesId = $oldSeriesId !== '' ? $oldSeriesId : null;
    if ($shouldCreateRecurrenceFromUpdate) {
        $newSeriesId = generateUuidV4();
    }
    $changed = ($oldStart !== $startSql)
        || ($oldEnd !== $endSql)
        || ($oldSeriesId !== (string)$newSeriesId)
        || ($oldSecondAthleteId !== (int)($secondAthleteId ?? 0))
        || ($oldLocation !== (string)$location)
        || ($oldTitle !== (string)$customTitle)
        || ($oldIsMakeup !== (int)$isMakeupSession)
        || ($oldBillingMonth !== $billingMonthSql);
    $isPendingRequest = (($existingEvent['approval_status'] ?? 'approved') === 'pending') && !empty($existingEvent['requested_by_athlete_id']);
    $nextApprovalStatus = ($approvalAction === 'approve' || $isPendingRequest) ? 'approved' : (string)($existingEvent['approval_status'] ?? 'approved');
    $coachModifiedAt = $changed ? date('Y-m-d H:i:s') : ($existingEvent['coach_modified_at'] ?: null);

    try {
        $pdo->beginTransaction();

        $upd->execute([$athleteId, $secondAthleteId, $newSeriesId, $nextApprovalStatus, $coachModifiedAt, (int)$isMakeupSession, $billingMonthSql, $colorKey, $customTitle, $location, $startSql, $endSql, $eventId, $coachId]);

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
                'INSERT INTO coach_calendar_events (coach_id, athlete_id, second_athlete_id, requested_by_athlete_id, approval_status, coach_modified_at, is_makeup_session, billing_month, series_id, color_key, custom_title, location, starts_at, ends_at)
                 VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                    $customTitle,
                    $location,
                    $occurrenceStartSql,
                    $occurrenceEndSql,
                ]);
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
    'INSERT INTO coach_calendar_events (coach_id, athlete_id, second_athlete_id, requested_by_athlete_id, approval_status, coach_modified_at, is_makeup_session, billing_month, series_id, color_key, custom_title, location, starts_at, ends_at)
     VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
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

        $occurrenceBillingMonthSql = $isMakeupSession
            ? $billingMonthSql
            : resolveOpenBillingMonth($pdo, $coachId, (int)($athleteId ?? 0), $occurrenceStart->format('Y-m-01'));

        $insertStmt->execute([
            $coachId,
            $athleteId,
            $secondAthleteId,
            'approved',
            (int)$isMakeupSession,
            $occurrenceBillingMonthSql,
            $seriesId,
            $colorKey,
            $customTitle,
            $location,
            $occurrenceStartSql,
            $occurrenceEndSql,
        ]);
        $createdIds[] = (int)$pdo->lastInsertId();
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'id' => $createdIds[0] ?? 0,
    'created_count' => count($createdIds),
    'mode' => $repeatMode === 'none' ? 'created' : 'created_series',
]);
