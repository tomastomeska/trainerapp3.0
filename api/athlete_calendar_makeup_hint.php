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

$start = DateTime::createFromFormat('Y-m-d\TH:i', $startsAtRaw);
if (!$start) {
    echo json_encode(['success' => false, 'error' => 'Neplatný začátek tréninku']);
    exit;
}

$targetMonthSql = $start->format('Y-m-01');
$currentMonthSql = (new DateTime('now'))->format('Y-m-01');
$carryoverCutoffSql = strcmp($targetMonthSql, $currentMonthSql) < 0 ? $targetMonthSql : $currentMonthSql;

$pdo = getDB();

function athleteMakeupHintTableExists(PDO $pdo, string $tableName): bool
{
    $quoted = $pdo->quote($tableName);
    $stmt = $pdo->query("SHOW TABLES LIKE {$quoted}");
    return $stmt !== false && (bool)$stmt->fetchColumn();
}

function athleteMakeupHintColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $quoted = $pdo->quote($columnName);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quoted}");
    return $stmt !== false && (bool)$stmt->fetch();
}

function athleteMakeupHintMonthKey(string $value): string
{
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }

    $timestamp = strtotime($normalized);
    return $timestamp !== false ? date('Y-m-01', $timestamp) : $normalized;
}

function athleteMakeupHintShouldReplaceMonthPayment(?array $current, array $candidate): bool
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

if (!athleteMakeupHintTableExists($pdo, 'athlete_monthly_payments') || !athleteMakeupHintTableExists($pdo, 'coach_calendar_events')) {
    echo json_encode([
        'success' => true,
        'has_outstanding' => false,
        'outstanding_sessions' => 0,
        'has_required_replacement' => false,
        'required_replacement_count' => 0,
        'required_replacement_deadline_at' => null,
        'target_month' => $targetMonthSql,
        'target_month_label' => date('m/Y', strtotime($targetMonthSql)),
    ]);
    exit;
}

$hasBillingMonth = athleteMakeupHintColumnExists($pdo, 'coach_calendar_events', 'billing_month');
$hasSecondAthlete = athleteMakeupHintColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
$hasCarryoverUsed = athleteMakeupHintColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions');
$hasIsMakeupSession = athleteMakeupHintColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session');
$hasRequestedByAthlete = athleteMakeupHintColumnExists($pdo, 'coach_calendar_events', 'requested_by_athlete_id');

$athleteStmt = $pdo->prepare('SELECT id, coach_id FROM athletes WHERE id = ? LIMIT 1');
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    echo json_encode(['success' => false, 'error' => 'Sportovec nebyl nalezen']);
    exit;
}
$coachId = (int)$athlete['coach_id'];

$participantsSql = '';
$params = [];
$monthExpr = $hasBillingMonth
    ? "DATE_FORMAT(COALESCE(t.billing_month, t.starts_at), '%Y-%m-01')"
    : "DATE_FORMAT(t.starts_at, '%Y-%m-01')";

if ($hasSecondAthlete) {
    $participantsSql = "
        SELECT starts_at, billing_month
        FROM coach_calendar_events
        WHERE coach_id = ?
          AND approval_status = 'approved'
          AND athlete_id = ?
        UNION ALL
        SELECT starts_at, billing_month
        FROM coach_calendar_events
        WHERE coach_id = ?
          AND approval_status = 'approved'
          AND second_athlete_id = ?
    ";
    $params = [$coachId, $athleteId, $coachId, $athleteId, $carryoverCutoffSql];
} else {
    $participantsSql = "
        SELECT starts_at, billing_month
        FROM coach_calendar_events
        WHERE coach_id = ?
          AND approval_status = 'approved'
          AND athlete_id = ?
    ";
    $params = [$coachId, $athleteId, $carryoverCutoffSql];
}

$actualByMonthStmt = $pdo->prepare(
    "SELECT {$monthExpr} AS billing_month,
            COUNT(*) AS billed_sessions
     FROM ({$participantsSql}) t
     WHERE {$monthExpr} < ?
     GROUP BY {$monthExpr}
     ORDER BY {$monthExpr} ASC"
);
$actualByMonthStmt->execute($params);

$actualByMonth = [];
foreach ($actualByMonthStmt->fetchAll() as $row) {
    $actualByMonth[athleteMakeupHintMonthKey((string)$row['billing_month'])] = (int)$row['billed_sessions'];
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
    $monthKey = athleteMakeupHintMonthKey((string)($paymentRow['billing_month'] ?? ''));
    if ($monthKey === '') {
        continue;
    }
    $currentPayment = $paymentsByMonth[$monthKey] ?? null;
    if (athleteMakeupHintShouldReplaceMonthPayment($currentPayment, $paymentRow)) {
        $paymentsByMonth[$monthKey] = $paymentRow;
    }
}

$forfeitedByMonth = [];
if (athleteMakeupHintTableExists($pdo, 'coach_calendar_event_cancellations')
    && athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
    && athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot')
    && athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
    && athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
    && athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at')
    && athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at')
    && athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at')
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
        $forfeitedByMonth[athleteMakeupHintMonthKey((string)$forfeitedRow['billing_month'])] = (int)$forfeitedRow['forfeited_count'];
    }
}

$outstanding = 0;
$monthsAsc = array_keys($paymentsByMonth);
sort($monthsAsc);
foreach ($monthsAsc as $month) {
    $row = $paymentsByMonth[$month];
    $planned = max(0, (int)($row['planned_sessions'] ?? 0));
    $actual = max(0, (int)($actualByMonth[$month] ?? 0));
    $forfeited = (int)($forfeitedByMonth[$month] ?? 0);
    $generated = max(0, $planned - $actual - $forfeited);
    $used = max(0, (int)($row['carryover_used_sessions'] ?? 0));

    $outstanding += $generated;
    $outstanding = max(0, $outstanding - $used);
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

$outstanding = max(0, $outstanding - $pendingReservedTotal);

$requiredReplacementCount = 0;
$requiredReplacementDeadlineAt = null;

if (athleteMakeupHintTableExists($pdo, 'coach_calendar_event_cancellations')
    && athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
    && athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
) {
    $hasReplacementDeadline = athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at');
    $hasPaymentSnapshot = athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot');
    $hasCanceledAt = athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at');
    $hasStartsAt = athleteMakeupHintColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at');

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

    $replacementStmt = $pdo->prepare(
        'SELECT id, ' . ($hasReplacementDeadline ? 'replacement_deadline_at' : 'NULL AS replacement_deadline_at') . '
         FROM coach_calendar_event_cancellations
         WHERE coach_id = ?
           AND athlete_id = ?
           AND canceled_by = "athlete"
           AND ' . $replacementWhere . '
                     AND (replacement_event_id IS NULL OR NOT EXISTS (SELECT 1 FROM coach_calendar_events ce WHERE ce.id = replacement_event_id))
         ORDER BY canceled_at ASC, id ASC'
    );
    $replacementStmt->execute([$coachId, $athleteId]);
    $replacementRows = $replacementStmt->fetchAll();
    $requiredReplacementCount = count($replacementRows);
    if (!empty($replacementRows)) {
        $requiredReplacementDeadlineAt = (string)($replacementRows[0]['replacement_deadline_at'] ?? '');
        if ($requiredReplacementDeadlineAt === '') {
            $requiredReplacementDeadlineAt = null;
        }
    }
}

echo json_encode([
    'success' => true,
    'has_outstanding' => $outstanding > 0,
    'outstanding_sessions' => $outstanding,
    'has_required_replacement' => $requiredReplacementCount > 0,
    'required_replacement_count' => $requiredReplacementCount,
    'required_replacement_deadline_at' => $requiredReplacementDeadlineAt,
    'target_month' => $targetMonthSql,
    'target_month_label' => date('m/Y', strtotime($targetMonthSql)),
]);