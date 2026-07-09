<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

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
$startsAtRaw = trim((string)($input['starts_at'] ?? ''));
$location = trim((string)($input['location'] ?? ''));
$titleType = trim((string)($input['title_type'] ?? 'training'));
$isMakeupSession = !empty($input['is_makeup_session']) ? 1 : 0;
$allowAutoMakeup = !empty($input['allow_auto_makeup']);

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

$pdo = getDB();

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

function athleteFindRequiredReplacementCancellation(PDO $pdo, int $coachId, int $athleteId): ?array
{
    if (!athleteReserveTableExists($pdo, 'coach_calendar_event_cancellations')) {
        return null;
    }
    if (!athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')) {
        return null;
    }
    if (!athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')) {
        return null;
    }

    $hasBillingMonth = athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month');
    $hasReplacementDeadline = athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at');
    $hasPaymentSnapshot = athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot');
    $hasCanceledAt = athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at');
    $hasStartsAt = athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at');

    $selectFields = ['id', 'canceled_at'];
    $selectFields[] = $hasBillingMonth ? 'billing_month' : 'NULL AS billing_month';
    $selectFields[] = $hasReplacementDeadline ? 'replacement_deadline_at' : 'NULL AS replacement_deadline_at';
    $selectFields[] = $hasPaymentSnapshot ? 'payment_status_snapshot' : '"none" AS payment_status_snapshot';

    $replacementWhere = 'replacement_required = 1';
    if ($hasPaymentSnapshot && $hasCanceledAt && $hasStartsAt) {
        $replacementWhere = '(
            replacement_required = 1
            OR (
                payment_status_snapshot IN ("pending", "paid")
                AND (
                    canceled_at IS NULL
                    OR starts_at IS NULL
                    OR TIMESTAMPDIFF(MINUTE, canceled_at, starts_at) >= 720
                )
            )
        )';
    }

    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $selectFields) . '
         FROM coach_calendar_event_cancellations
         WHERE coach_id = ?
           AND athlete_id = ?
           AND canceled_by = "athlete"
           AND ' . $replacementWhere . '
                     AND (replacement_event_id IS NULL OR NOT EXISTS (SELECT 1 FROM coach_calendar_events ce WHERE ce.id = replacement_event_id))
         ORDER BY canceled_at ASC, id ASC
         LIMIT 1'
    );
    $stmt->execute([$coachId, $athleteId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function athleteIsLastWeekOfMonth(string $dateTimeValue, string $monthSql): bool
{
    $ts = strtotime($dateTimeValue);
    $monthTs = strtotime($monthSql);
    if ($ts === false || $monthTs === false) {
        return false;
    }

    $monthEndTs = strtotime(date('Y-m-t 23:59:59', $monthTs));
    if ($monthEndTs === false) {
        return false;
    }

    return ($monthEndTs - $ts) < (7 * 24 * 60 * 60);
}

function athleteReserveMonthKey(string $value): string
{
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }

    $timestamp = strtotime($normalized);
    return $timestamp !== false ? date('Y-m-01', $timestamp) : $normalized;
}

function athleteReserveShouldReplaceMonthPayment(?array $current, array $candidate): bool
{
    if ($current === null) {
        return true;
    }

    $currentTs = strtotime((string)($current['updated_at'] ?? $current['created_at'] ?? $current['billing_month'] ?? '')) ?: 0;
    $candidateTs = strtotime((string)($candidate['updated_at'] ?? $candidate['created_at'] ?? $candidate['billing_month'] ?? '')) ?: 0;
    if ($candidateTs !== $currentTs) {
        return $candidateTs > $currentTs;
    }

    return ((int)($candidate['id'] ?? 0)) > ((int)($current['id'] ?? 0));
}

function athleteResolveAutoMakeupBillingMonth(PDO $pdo, int $coachId, int $athleteId, string $targetMonthSql): ?string
{
    if (!athleteReserveTableExists($pdo, 'athlete_monthly_payments') || !athleteReserveTableExists($pdo, 'coach_calendar_events')) {
        return null;
    }

    $hasBillingMonth = athleteReserveColumnExists($pdo, 'coach_calendar_events', 'billing_month');
    $hasSecondAthlete = athleteReserveColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
    $hasCarryoverUsed = athleteReserveColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions');
    $hasIsMakeupSession = athleteReserveColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session');
    $hasRequestedByAthlete = athleteReserveColumnExists($pdo, 'coach_calendar_events', 'requested_by_athlete_id');
    $currentMonthSql = (new DateTime('now'))->format('Y-m-01');
    $carryoverCutoffSql = strcmp($targetMonthSql, $currentMonthSql) < 0 ? $targetMonthSql : $currentMonthSql;

    $monthExpr = $hasBillingMonth
        ? "DATE_FORMAT(COALESCE(t.billing_month, t.starts_at), '%Y-%m-01')"
        : "DATE_FORMAT(t.starts_at, '%Y-%m-01')";
    $billingField = $hasBillingMonth ? 'billing_month' : 'NULL AS billing_month';

    if ($hasSecondAthlete) {
        $participantsSql = "
            SELECT starts_at, {$billingField}
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND approval_status = 'approved'
              AND athlete_id = ?
            UNION ALL
            SELECT starts_at, {$billingField}
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND approval_status = 'approved'
              AND second_athlete_id = ?
        ";
        $actualParams = [$coachId, $athleteId, $coachId, $athleteId, $carryoverCutoffSql];
    } else {
        $participantsSql = "
            SELECT starts_at, {$billingField}
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND approval_status = 'approved'
              AND athlete_id = ?
        ";
        $actualParams = [$coachId, $athleteId, $carryoverCutoffSql];
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
        $monthKey = athleteReserveMonthKey((string)$row['billing_month']);
        if ($monthKey === '') {
            continue;
        }
        $actualByMonth[$monthKey] = (int)$row['billed_sessions'];
    }

    $paymentStmt = $pdo->prepare(
        'SELECT id, billing_month, planned_sessions, ' . ($hasCarryoverUsed ? 'carryover_used_sessions' : '0 AS carryover_used_sessions') . ', created_at, updated_at
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND athlete_id = ?
           AND status = "paid"
            AND billing_month < ?
         ORDER BY billing_month ASC'
    );
    $paymentStmt->execute([$coachId, $athleteId, $carryoverCutoffSql]);

    $paymentsByMonth = [];
    foreach ($paymentStmt->fetchAll() as $paymentRow) {
        $monthKey = athleteReserveMonthKey((string)($paymentRow['billing_month'] ?? ''));
        if ($monthKey === '') {
            continue;
        }
        $currentPayment = $paymentsByMonth[$monthKey] ?? null;
        if (athleteReserveShouldReplaceMonthPayment($currentPayment, $paymentRow)) {
            $paymentsByMonth[$monthKey] = $paymentRow;
        }
    }

    $forfeitedByMonth = [];
    if (athleteReserveTableExists($pdo, 'coach_calendar_event_cancellations')
        && athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
        && athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot')
        && athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
        && athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
        && athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at')
        && athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at')
        && athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at')
    ) {
        $forfeitedStmt = $pdo->prepare(
            "SELECT billing_month,
                    COUNT(*) AS forfeited_count
             FROM coach_calendar_event_cancellations
             WHERE coach_id = ?
               AND athlete_id = ?
               AND billing_month < ?
               AND payment_status_snapshot IN ('pending', 'paid')
               AND (
                   (starts_at > canceled_at AND TIMESTAMPDIFF(MINUTE, canceled_at, starts_at) < 720)
                   OR
                   (
                       replacement_required = 1
                       AND (replacement_event_id IS NULL OR NOT EXISTS (SELECT 1 FROM coach_calendar_events ce WHERE ce.id = replacement_event_id))
                       AND replacement_deadline_at IS NOT NULL
                       AND replacement_deadline_at < NOW()
                   )
               )
             GROUP BY billing_month"
        );
        $forfeitedStmt->execute([$coachId, $athleteId, $carryoverCutoffSql]);
        foreach ($forfeitedStmt->fetchAll() as $forfeitedRow) {
            $monthKey = athleteReserveMonthKey((string)$forfeitedRow['billing_month']);
            if ($monthKey === '') {
                continue;
            }
            $forfeitedByMonth[$monthKey] = (int)$forfeitedRow['forfeited_count'];
        }
    }

    $balances = [];
    $monthsAsc = array_keys($paymentsByMonth);
    sort($monthsAsc);
    foreach ($monthsAsc as $month) {
        $row = $paymentsByMonth[$month];
        $planned = max(0, (int)($row['planned_sessions'] ?? 0));
        $actual = max(0, (int)($actualByMonth[$month] ?? 0));
        $forfeited = (int)($forfeitedByMonth[$month] ?? 0);
        $generated = max(0, $planned - $actual - $forfeited);
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

    $pendingReservedTotal = 0;
    if ($hasIsMakeupSession) {
        $pendingMonthExpr = $hasBillingMonth
            ? "DATE_FORMAT(COALESCE(e.billing_month, e.starts_at), '%Y-%m-01')"
            : "DATE_FORMAT(e.starts_at, '%Y-%m-01')";
        $pendingParticipantFilter = $hasSecondAthlete
            ? '(e.athlete_id = ? OR e.second_athlete_id = ?)'
            : 'e.athlete_id = ?';
        $pendingRequesterFilter = $hasRequestedByAthlete ? ' AND e.requested_by_athlete_id = ?' : '';

        $pendingCountSql =
            "SELECT COUNT(*)
             FROM coach_calendar_events e
             WHERE e.coach_id = ?
               AND e.approval_status = 'pending'
               AND e.is_makeup_session = 1
               AND {$pendingParticipantFilter}
               {$pendingRequesterFilter}
               AND {$pendingMonthExpr} < ?";

        $pendingCountStmt = $pdo->prepare($pendingCountSql);
        $pendingCountParams = [(int)$coachId, $athleteId];
        if ($hasSecondAthlete) {
            $pendingCountParams[] = $athleteId;
        }
        if ($hasRequestedByAthlete) {
            $pendingCountParams[] = $athleteId;
        }
        $pendingCountParams[] = $carryoverCutoffSql;
        $pendingCountStmt->execute($pendingCountParams);
        $pendingReservedTotal = max(0, (int)$pendingCountStmt->fetchColumn());
    }

    while ($pendingReservedTotal > 0 && !empty($balances)) {
        $deduct = min($pendingReservedTotal, (int)$balances[0]['remaining']);
        $balances[0]['remaining'] -= $deduct;
        $pendingReservedTotal -= $deduct;

        if ((int)$balances[0]['remaining'] <= 0) {
            array_shift($balances);
        }
    }

    return empty($balances) ? null : (string)$balances[0]['month'];
}

function athleteResolveOpenBillingMonth(PDO $pdo, int $coachId, int $athleteId, string $targetMonthSql): string
{
    if (!athleteReserveTableExists($pdo, 'athlete_monthly_payments')) {
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

$athleteStmt = $pdo->prepare(
    'SELECT a.id, a.first_name, a.last_name, a.email, a.coach_id,
            c.name AS coach_name, c.username AS coach_username, c.email AS coach_email
            ' . (athleteReserveColumnExists($pdo, 'coaches', 'makeup_booking_deadline_days') ? ', c.makeup_booking_deadline_days' : ', NULL AS makeup_booking_deadline_days') . '
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

if ($location !== null) {
    rememberTrainingVenue($location, (int)$athlete['coach_id']);

    $venueStmt = $pdo->prepare('SELECT name FROM training_venues WHERE name = ? LIMIT 1');
    $venueStmt->execute([$location]);
    $venue = $venueStmt->fetch();
    if ($venue && !empty($venue['name'])) {
        $location = (string)$venue['name'];
    }
}

$startSql = $start->format('Y-m-d H:i:s');
$endSql = $end->format('Y-m-d H:i:s');
$billingMonthSql = $start->format('Y-m-01');
$replacementCancellationId = null;

if ($isMakeupSession === 0 && $allowAutoMakeup) {
    $targetMonthSql = $start->format('Y-m-01');
    $autoMakeupMonthSql = athleteResolveAutoMakeupBillingMonth($pdo, (int)$athlete['coach_id'], $athleteId, $targetMonthSql);
    if (!empty($autoMakeupMonthSql)) {
        $isMakeupSession = 1;
    }
}

if ($isMakeupSession === 1) {
    $targetMonthSql = $start->format('Y-m-01');
    $requiredReplacement = athleteFindRequiredReplacementCancellation($pdo, (int)$athlete['coach_id'], $athleteId);
    if ($requiredReplacement) {
        $deadlineRaw = (string)($requiredReplacement['replacement_deadline_at'] ?? '');
        if ($deadlineRaw !== '') {
            $deadlineTs = strtotime($deadlineRaw);
            if ($deadlineTs !== false && $deadlineTs < time()) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Lhuta pro vyber nahradniho terminu uz uplynula. Kontaktujte prosim trenera.',
                ]);
                exit;
            }
        }

        $requiredBillingMonthSql = !empty($requiredReplacement['billing_month'])
            ? date('Y-m-01', strtotime((string)$requiredReplacement['billing_month']))
            : $targetMonthSql;
        $billingMonthSql = $requiredBillingMonthSql;

        if ($targetMonthSql !== $requiredBillingMonthSql) {
            $requiredMonthTs = strtotime($requiredBillingMonthSql);
            $allowedNextMonthSql = $requiredMonthTs !== false
                ? date('Y-m-01', strtotime('+1 month', $requiredMonthTs))
                : null;
            $canceledAtRaw = (string)($requiredReplacement['canceled_at'] ?? '');
            $isLastWeek = athleteIsLastWeekOfMonth($canceledAtRaw, $requiredBillingMonthSql);

            if ($allowedNextMonthSql === null || $targetMonthSql !== $allowedNextMonthSql || !$isLastWeek) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Náhradní termín je potřeba primárně zadat do stejného měsíce jako zrušený termín. Do dalšího měsíce lze náhradu přesunout jen při zrušení v posledním týdnu měsíce.',
                ]);
                exit;
            }
        }

        $replacementCancellationId = (int)$requiredReplacement['id'];
    } else {
        $billingMonthSql = athleteResolveAutoMakeupBillingMonth($pdo, (int)$athlete['coach_id'], $athleteId, $targetMonthSql) ?: '';
        if ($billingMonthSql === '') {
            echo json_encode(['success' => false, 'error' => 'Momentálně nemáte dostupný žádný nevyužitý uhrazený trénink.']);
            exit;
        }
    }
} else {
    $billingMonthSql = athleteResolveOpenBillingMonth($pdo, (int)$athlete['coach_id'], $athleteId, $billingMonthSql);
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

$insert = $pdo->prepare(
    'INSERT INTO coach_calendar_events (coach_id, athlete_id, requested_by_athlete_id, approval_status, coach_modified_at, is_makeup_session, billing_month, series_id, color_key, custom_title, location, starts_at, ends_at)
    VALUES (?, ?, ?, ?, NULL, ?, ?, NULL, ?, ?, ?, ?, ?)'
);
$insert->execute([
    (int)$athlete['coach_id'],
    $athleteId,
    $athleteId,
    'pending',
    $isMakeupSession,
    $billingMonthSql,
    'green',
    $customTitle,
    $location,
    $startSql,
    $endSql,
]);

$newEventId = (int)$pdo->lastInsertId();

if ($replacementCancellationId !== null
    && athleteReserveTableExists($pdo, 'coach_calendar_event_cancellations')
    && athleteReserveColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
) {
    $bindReplacementStmt = $pdo->prepare(
        'UPDATE coach_calendar_event_cancellations
         SET replacement_event_id = ?
         WHERE id = ?
           AND coach_id = ?
           AND athlete_id = ?
           AND replacement_event_id IS NULL'
    );
    $bindReplacementStmt->execute([$newEventId, $replacementCancellationId, (int)$athlete['coach_id'], $athleteId]);
}

$athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
$timeLabel = $start->format('d.m.Y H:i');
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

echo json_encode(['success' => true, 'message' => 'Požadavek byl odeslán ke schválení.']);
