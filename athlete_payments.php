<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

function athletePaymentsColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $quotedColumn = $pdo->quote($columnName);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quotedColumn}");
    return $stmt !== false && (bool)$stmt->fetch();
}

function athletePaymentsNormalizeBankAccount(?string $raw): ?string
{
    $value = strtoupper(str_replace(' ', '', trim((string)$raw)));
    if ($value === '') {
        return null;
    }

    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $value) === 1) {
        return $value;
    }

    if (
        preg_match('/^[0-9]{1,6}-[0-9]{2,10}\/[0-9]{4}$/', $value) === 1 ||
        preg_match('/^[0-9]{2,10}\/[0-9]{4}$/', $value) === 1
    ) {
        return $value;
    }

    return null;
}

function athletePaymentsDigitsMod97(string $numeric): int
{
    $remainder = 0;
    $len = strlen($numeric);
    for ($i = 0; $i < $len; $i++) {
        $char = $numeric[$i];
        if ($char < '0' || $char > '9') {
            continue;
        }
        $remainder = (($remainder * 10) + (int)$char) % 97;
    }
    return $remainder;
}

function athletePaymentsIbanToNumeric(string $iban): string
{
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    $len = strlen($rearranged);
    for ($i = 0; $i < $len; $i++) {
        $char = $rearranged[$i];
        if ($char >= '0' && $char <= '9') {
            $numeric .= $char;
        } elseif ($char >= 'A' && $char <= 'Z') {
            $numeric .= (string)(ord($char) - 55);
        }
    }
    return $numeric;
}

function athletePaymentsValidIban(string $iban): bool
{
    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban) !== 1) {
        return false;
    }
    return athletePaymentsDigitsMod97(athletePaymentsIbanToNumeric($iban)) === 1;
}

function athletePaymentsCzIban(string $localAccount): ?string
{
    if (preg_match('/^(?:(\d{1,6})-)?(\d{2,10})\/(\d{4})$/', $localAccount, $m) !== 1) {
        return null;
    }

    $prefix = str_pad((string)($m[1] ?? '0'), 6, '0', STR_PAD_LEFT);
    $account = str_pad($m[2], 10, '0', STR_PAD_LEFT);
    $bankCode = $m[3];
    $bban = $bankCode . $prefix . $account;
    $checkBase = $bban . '123500';
    $checkDigits = 98 - athletePaymentsDigitsMod97($checkBase);
    $iban = 'CZ' . str_pad((string)$checkDigits, 2, '0', STR_PAD_LEFT) . $bban;

    return athletePaymentsValidIban($iban) ? $iban : null;
}

function athletePaymentsAccountForSpd(?string $bankAccount): ?string
{
    if ($bankAccount === null || $bankAccount === '') {
        return null;
    }

    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $bankAccount) === 1) {
        return athletePaymentsValidIban($bankAccount) ? $bankAccount : null;
    }

    return athletePaymentsCzIban($bankAccount);
}

function athletePaymentsAscii(string $value): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }

    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }

    $text = preg_replace('/[^A-Za-z0-9 .\/-]/', '', $text) ?? '';
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    return trim($text);
}

function athletePaymentsMonthKey(string $raw): string
{
    $normalized = trim($raw);
    if ($normalized === '') {
        return '';
    }

    $ts = strtotime($normalized);
    if ($ts === false) {
        return $normalized;
    }

    return date('Y-m-01', $ts);
}

function athletePaymentsStatusRank(string $status): int
{
    if ($status === 'paid') {
        return 2;
    }
    if ($status === 'pending') {
        return 1;
    }
    return 0;
}

function athletePaymentsShouldReplaceMonthPayment(?array $current, array $candidate): bool
{
    if ($current === null) {
        return true;
    }

    $currentRank = athletePaymentsStatusRank((string)($current['status'] ?? ''));
    $candidateRank = athletePaymentsStatusRank((string)($candidate['status'] ?? ''));
    if ($candidateRank !== $currentRank) {
        return $candidateRank > $currentRank;
    }

    $currentTs = strtotime((string)($current['updated_at'] ?? $current['created_at'] ?? $current['billing_month'] ?? '')) ?: 0;
    $candidateTs = strtotime((string)($candidate['updated_at'] ?? $candidate['created_at'] ?? $candidate['billing_month'] ?? '')) ?: 0;
    if ($candidateTs !== $currentTs) {
        return $candidateTs > $currentTs;
    }

    return ((int)($candidate['id'] ?? 0)) > ((int)($current['id'] ?? 0));
}

function athletePaymentsResolveCarryoverApplied(array $stats, int $outstandingBefore): int
{
    $rawSessions = max(0, (int)($stats['billed_sessions'] ?? 0));
    if ($rawSessions === 0) {
        return 0;
    }

    $fromHistory = min(max(0, $outstandingBefore), $rawSessions);
    $fromTransferred = min(max(0, (int)($stats['transferred_sessions'] ?? 0)), $rawSessions);

    // Sessions billed in another month must not be charged again in the current month.
    return max($fromHistory, $fromTransferred);
}

function athletePaymentsBackfillPendingSnapshot(PDO $pdo, int $coachId, int $athleteId, string $billingMonthSql, ?float $sessionRate, int $plannedSessions, int $carryoverUsed, float $billedAmount): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO athlete_monthly_payments (coach_id, athlete_id, billing_month, session_rate, planned_sessions, carryover_used_sessions, billed_amount, status, paid_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, "pending", NULL)
         ON DUPLICATE KEY UPDATE
            status = CASE WHEN status = "paid" THEN "paid" ELSE "pending" END,
            paid_at = CASE WHEN status = "paid" THEN paid_at ELSE NULL END'
    );
    $stmt->execute([
        $coachId,
        $athleteId,
        $billingMonthSql,
        $sessionRate !== null ? number_format($sessionRate, 2, '.', '') : null,
        max(0, $plannedSessions),
        max(0, $carryoverUsed),
        number_format($billedAmount, 2, '.', ''),
    ]);
}

function athletePaymentsQrUrl(string $bankAccount, float $amount, string $note): string
{
    $parts = [
        'SPD*1.0',
        'ACC:' . $bankAccount,
        'CC:CZK',
        'AM:' . number_format($amount, 2, '.', ''),
    ];

    if ($note !== '') {
        $parts[] = 'MSG:' . str_replace('*', ' ', $note);
    }

    return 'https://quickchart.io/qr?size=220&text=' . rawurlencode(implode('*', $parts));
}

$hasBillingMonth = athletePaymentsColumnExists($pdo, 'coach_calendar_events', 'billing_month');
$hasIsMakeup = athletePaymentsColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session');
$hasSecondAthlete = athletePaymentsColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
$hasCoachBankAccount = athletePaymentsColumnExists($pdo, 'coaches', 'bank_account');
$hasCarryoverUsed = athletePaymentsColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions');
$hasPairedTrainingRate = athletePaymentsColumnExists($pdo, 'athletes', 'paired_training_rate');
$hasCancellationsTable = false;
try {
    $checkCancellationsTable = $pdo->query("SHOW TABLES LIKE 'coach_calendar_event_cancellations'");
    $hasCancellationsTable = $checkCancellationsTable !== false && (bool)$checkCancellationsTable->fetchColumn();
} catch (Throwable $e) {
    $hasCancellationsTable = false;
}

$athleteStmt = $pdo->prepare(
    'SELECT a.id, a.first_name, a.last_name, a.training_rate'
    . ($hasPairedTrainingRate ? ', a.paired_training_rate' : '')
    . ', c.id AS coach_id, c.name AS coach_name, c.username AS coach_username'
    . ($hasCoachBankAccount ? ', c.bank_account' : '') . '
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id
     WHERE a.id = ?
     LIMIT 1'
);
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();

if (!$athlete) {
    session_destroy();
    redirect(BASE_URL . '/login.php');
}

$coachDisplayName = trim((string)($athlete['coach_name'] ?: $athlete['coach_username']));
$coachLastNameParts = preg_split('/\s+/u', $coachDisplayName) ?: [];
$coachLastName = trim((string)end($coachLastNameParts));
if ($coachLastName === '') {
    $coachLastName = 'Trener';
}

$coachBankAccount = $hasCoachBankAccount
    ? athletePaymentsAccountForSpd(athletePaymentsNormalizeBankAccount($athlete['bank_account'] ?? null))
    : null;

$activeReplacementNotice = null;
if ($hasCancellationsTable
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
) {
    $hasReplacementDeadline = athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at');
    $hasPaymentSnapshot = athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot');
    $hasCanceledAt = athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at');
    $hasStartsAt = athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at');
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
    $replacementStmt->execute([(int)$athlete['coach_id'], $athleteId]);
    $replacementRows = $replacementStmt->fetchAll();

    if (!empty($replacementRows)) {
        $firstDeadlineRaw = (string)($replacementRows[0]['replacement_deadline_at'] ?? '');
        $activeReplacementNotice = [
            'count' => count($replacementRows),
            'deadline_at' => $firstDeadlineRaw !== '' ? $firstDeadlineRaw : null,
            'is_overdue' => ($firstDeadlineRaw !== '' && strtotime($firstDeadlineRaw) !== false)
                ? (strtotime($firstDeadlineRaw) < time())
                : false,
        ];
    }
}

$billingSelect = "DATE_FORMAT(starts_at, '%Y-%m-01')";
$sourceBillingSelect = $hasBillingMonth
    ? "DATE_FORMAT(COALESCE(billing_month, starts_at), '%Y-%m-01')"
    : "DATE_FORMAT(starts_at, '%Y-%m-01')";
$billingFilter = '1=1';
$transferredExpr = 'SUM(CASE WHEN t.billing_month <> t.source_billing_month THEN 1 ELSE 0 END)';
$makeupExpr = $hasIsMakeup ? 'SUM(CASE WHEN t.is_makeup_session = 1 THEN 1 ELSE 0 END)' : '0';

$statsSql = "
    SELECT t.billing_month,
           COUNT(*) AS billed_sessions,
           SUM(CASE WHEN t.is_paired = 1 THEN 1 ELSE 0 END) AS paired_sessions,
           SUM(CASE WHEN t.is_paired = 0 THEN 1 ELSE 0 END) AS single_sessions,
           {$makeupExpr} AS makeup_sessions,
           {$transferredExpr} AS transferred_sessions
    FROM (
        SELECT {$billingSelect} AS billing_month,
             {$sourceBillingSelect} AS source_billing_month,
               starts_at,
               " . ($hasIsMakeup ? 'is_makeup_session' : '0') . " AS is_makeup_session,
               " . ($hasSecondAthlete ? 'CASE WHEN second_athlete_id IS NOT NULL THEN 1 ELSE 0 END' : '0') . " AS is_paired
        FROM coach_calendar_events
        WHERE approval_status = 'approved'
          AND athlete_id = ?
          AND {$billingFilter}
" . ($hasSecondAthlete ? "
        UNION ALL
        SELECT {$billingSelect} AS billing_month,
             {$sourceBillingSelect} AS source_billing_month,
               starts_at,
               " . ($hasIsMakeup ? 'is_makeup_session' : '0') . " AS is_makeup_session,
               1 AS is_paired
        FROM coach_calendar_events
        WHERE approval_status = 'approved'
          AND second_athlete_id = ?
          AND {$billingFilter}
" : '') . "
    ) t
    GROUP BY t.billing_month
    ORDER BY t.billing_month DESC
";

$statsStmt = $pdo->prepare($statsSql);
if ($hasSecondAthlete) {
    $statsStmt->execute([$athleteId, $athleteId]);
} else {
    $statsStmt->execute([$athleteId]);
}
$statsRows = $statsStmt->fetchAll();

$paymentRows = [];
try {
    $paymentStmt = $pdo->prepare(
                'SELECT id, billing_month, session_rate, planned_sessions, '
                . ($hasCarryoverUsed ? 'carryover_used_sessions' : '0 AS carryover_used_sessions') . ', billed_amount, status, paid_at, created_at, updated_at
         FROM athlete_monthly_payments
         WHERE athlete_id = ?
           AND coach_id = ?
         ORDER BY billing_month DESC'
    );
    $paymentStmt->execute([$athleteId, (int)$athlete['coach_id']]);
    $paymentRows = $paymentStmt->fetchAll();
} catch (Throwable $e) {
    $paymentRows = [];
}

$paymentsByMonth = [];
foreach ($paymentRows as $row) {
    $monthKey = athletePaymentsMonthKey((string)($row['billing_month'] ?? ''));
    if ($monthKey === '') {
        continue;
    }
    $currentMonthPayment = $paymentsByMonth[$monthKey] ?? null;
    if (athletePaymentsShouldReplaceMonthPayment($currentMonthPayment, $row)) {
        $paymentsByMonth[$monthKey] = $row;
    }
}

$rowsByMonth = [];
foreach ($statsRows as $row) {
    $month = athletePaymentsMonthKey((string)($row['billing_month'] ?? ''));
    if ($month === '') {
        continue;
    }
    $rowsByMonth[$month] = [
        'billing_month' => $month,
        'billed_sessions' => (int)$row['billed_sessions'],
        'paired_sessions' => (int)($row['paired_sessions'] ?? 0),
        'single_sessions' => (int)($row['single_sessions'] ?? 0),
        'makeup_sessions' => (int)$row['makeup_sessions'],
        'transferred_sessions' => (int)$row['transferred_sessions'],
    ];
}

if ($hasCancellationsTable
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'cancellation_scope')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_by')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at')
) {
    $lateCancelStmt = $pdo->prepare(
        "SELECT billing_month,
                SUM(CASE WHEN cancellation_scope = 'pair_exit' THEN 1 ELSE 0 END) AS late_paired,
                SUM(CASE WHEN cancellation_scope = 'pair_exit' THEN 0 ELSE 1 END) AS late_single,
                COUNT(*) AS late_total
         FROM coach_calendar_event_cancellations
         WHERE coach_id = ?
           AND athlete_id = ?
           AND canceled_by = 'athlete'
           AND starts_at > canceled_at
           AND TIMESTAMPDIFF(MINUTE, canceled_at, starts_at) < 720
         GROUP BY billing_month"
    );
    $lateCancelStmt->execute([(int)$athlete['coach_id'], $athleteId]);

    foreach ($lateCancelStmt->fetchAll() as $lateRow) {
        $month = athletePaymentsMonthKey((string)($lateRow['billing_month'] ?? ''));
        if ($month === '') {
            continue;
        }
        if (!isset($rowsByMonth[$month])) {
            $rowsByMonth[$month] = [
                'billing_month' => $month,
                'billed_sessions' => 0,
                'paired_sessions' => 0,
                'single_sessions' => 0,
                'makeup_sessions' => 0,
                'transferred_sessions' => 0,
            ];
        }

        $rowsByMonth[$month]['paired_sessions'] += (int)($lateRow['late_paired'] ?? 0);
        $rowsByMonth[$month]['single_sessions'] += (int)($lateRow['late_single'] ?? 0);
        $rowsByMonth[$month]['billed_sessions'] += (int)($lateRow['late_total'] ?? 0);
    }
}

if ($hasCancellationsTable
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'cancellation_scope')
) {
    $lockedWithoutReplacementStmt = $pdo->prepare(
        "SELECT billing_month,
                SUM(CASE WHEN cancellation_scope = 'pair_exit' THEN 1 ELSE 0 END) AS locked_paired,
                SUM(CASE WHEN cancellation_scope = 'pair_exit' THEN 0 ELSE 1 END) AS locked_single,
                COUNT(*) AS locked_total
         FROM coach_calendar_event_cancellations
         WHERE coach_id = ?
           AND athlete_id = ?
           AND payment_status_snapshot IN ('pending', 'paid')
           AND replacement_required = 1
                     AND (replacement_event_id IS NULL OR NOT EXISTS (SELECT 1 FROM coach_calendar_events ce WHERE ce.id = replacement_event_id))
         GROUP BY billing_month"
    );
    $lockedWithoutReplacementStmt->execute([(int)$athlete['coach_id'], $athleteId]);

    foreach ($lockedWithoutReplacementStmt->fetchAll() as $lockedRow) {
        $month = athletePaymentsMonthKey((string)($lockedRow['billing_month'] ?? ''));
        if ($month === '') {
            continue;
        }
        if (!isset($rowsByMonth[$month])) {
            $rowsByMonth[$month] = [
                'billing_month' => $month,
                'billed_sessions' => 0,
                'paired_sessions' => 0,
                'single_sessions' => 0,
                'makeup_sessions' => 0,
                'transferred_sessions' => 0,
            ];
        }

        $rowsByMonth[$month]['paired_sessions'] += (int)($lockedRow['locked_paired'] ?? 0);
        $rowsByMonth[$month]['single_sessions'] += (int)($lockedRow['locked_single'] ?? 0);
        $rowsByMonth[$month]['billed_sessions'] += (int)($lockedRow['locked_total'] ?? 0);
    }
}

foreach ($paymentsByMonth as $month => $payment) {
    if (!isset($rowsByMonth[$month])) {
        $rowsByMonth[$month] = [
            'billing_month' => $month,
            'billed_sessions' => (int)($payment['planned_sessions'] ?? 0),
            'paired_sessions' => 0,
            'single_sessions' => (int)($payment['planned_sessions'] ?? 0),
            'makeup_sessions' => 0,
            'transferred_sessions' => 0,
        ];
    }
}

$releasedAthleteByMonth = [];

try {
    $releaseAthleteStmt = $pdo->prepare(
        'SELECT billing_month, status
         FROM coach_billing_month_athletes
         WHERE coach_id = ? AND athlete_id = ?'
    );
    $releaseAthleteStmt->execute([(int)$athlete['coach_id'], $athleteId]);
    foreach ($releaseAthleteStmt->fetchAll() as $releaseAthleteRow) {
        if ((string)($releaseAthleteRow['status'] ?? 'draft') === 'released') {
            $month = athletePaymentsMonthKey((string)($releaseAthleteRow['billing_month'] ?? ''));
            if ($month !== '') {
                $releasedAthleteByMonth[$month] = true;
            }
        }
    }
} catch (Throwable $e) {
    // Tabulka s individuálním otevřením nemusí v některých starších instalacích existovat.
}

krsort($rowsByMonth);

$monthsAsc = array_keys($rowsByMonth);
sort($monthsAsc);
$outstanding = 0;
$outstandingBeforeByMonth = [];

$forfeitedByMonth = [];
if ($hasCancellationsTable
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at')
    && athletePaymentsColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at')
) {
    $forfeitedStmt = $pdo->prepare(
        "SELECT billing_month,
                COUNT(*) AS forfeited_count
         FROM coach_calendar_event_cancellations
         WHERE coach_id = ?
           AND athlete_id = ?
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
    $forfeitedStmt->execute([(int)$athlete['coach_id'], $athleteId]);
    foreach ($forfeitedStmt->fetchAll() as $forfeitedRow) {
        $month = athletePaymentsMonthKey((string)($forfeitedRow['billing_month'] ?? ''));
        if ($month === '') {
            continue;
        }
        $forfeitedByMonth[$month] = (int)$forfeitedRow['forfeited_count'];
    }
}

foreach ($monthsAsc as $monthKey) {
    $outstandingBeforeByMonth[$monthKey] = $outstanding;
    $paidMonthRow = $paymentsByMonth[$monthKey] ?? null;
    if ($paidMonthRow && (($paidMonthRow['status'] ?? '') === 'paid')) {
        $planned = max(0, (int)($paidMonthRow['planned_sessions'] ?? 0));
        $actual = max(0, (int)($rowsByMonth[$monthKey]['billed_sessions'] ?? 0));
        $forfeited = (int)($forfeitedByMonth[$monthKey] ?? 0);
        $generated = max(0, $planned - $actual - $forfeited);
        $used = max(0, (int)($paidMonthRow['carryover_used_sessions'] ?? 0));
        $outstanding += $generated;
        $outstanding = max(0, $outstanding - $used);
    }
}

$rate = isset($athlete['training_rate']) && $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
$pairedRate = ($hasPairedTrainingRate && array_key_exists('paired_training_rate', $athlete) && $athlete['paired_training_rate'] !== null)
    ? (float)$athlete['paired_training_rate']
    : $rate;
$paymentRowsForView = [];
$openPaymentCount = 0;

foreach ($rowsByMonth as $month => $stats) {
    $payment = $paymentsByMonth[$month] ?? null;
    $paymentStatus = (string)($payment['status'] ?? '');
    $rawSessions = (int)$stats['billed_sessions'];
    $rawSingleSessions = (int)($stats['single_sessions'] ?? $rawSessions);
    $rawPairedSessions = (int)($stats['paired_sessions'] ?? 0);
    $carryoverApplied = athletePaymentsResolveCarryoverApplied($stats, (int)($outstandingBeforeByMonth[$month] ?? 0));
    $billableSingle = max(0, $rawSingleSessions - $carryoverApplied);
    $remainingCarryover = max(0, $carryoverApplied - $rawSingleSessions);
    $billablePaired = max(0, $rawPairedSessions - $remainingCarryover);
    $billableSessions = $billableSingle + $billablePaired;
    $isSnapshotLocked = in_array($paymentStatus, ['pending', 'paid'], true);
    $displayBillableSessions = $isSnapshotLocked
        ? max(0, (int)($payment['planned_sessions'] ?? $billableSessions))
        : $billableSessions;
    $displayCarryoverApplied = $isSnapshotLocked
        ? max(0, (int)($payment['carryover_used_sessions'] ?? $carryoverApplied))
        : $carryoverApplied;
    $amount = ($rate !== null && $pairedRate !== null)
        ? (($billableSingle * $rate) + ($billablePaired * $pairedRate))
        : null;

    if ($paymentStatus === 'pending' && $rate !== null && $amount !== null) {
        $pendingPlan = max(0, (int)($payment['planned_sessions'] ?? 0));
        $pendingCarry = max(0, (int)($payment['carryover_used_sessions'] ?? 0));
        $pendingAmount = (float)($payment['billed_amount'] ?? 0.0);
        $pendingNeedsRefresh = $pendingPlan !== $billableSessions
            || $pendingCarry !== $carryoverApplied
            || abs($pendingAmount - (float)$amount) > 0.009;

        if ($pendingNeedsRefresh) {
            try {
                athletePaymentsBackfillPendingSnapshot(
                    $pdo,
                    (int)$athlete['coach_id'],
                    $athleteId,
                    $month,
                    $rate,
                    $billableSessions,
                    $carryoverApplied,
                    (float)$amount
                );

                $payment['planned_sessions'] = $billableSessions;
                $payment['carryover_used_sessions'] = $carryoverApplied;
                $payment['billed_amount'] = (float)$amount;
            } catch (Throwable $e) {
                // Keep current snapshot values if refresh fails.
            }
        }
    }

    $displayAmount = ($payment && isset($payment['billed_amount']))
        ? (float)$payment['billed_amount']
        : $amount;
    $note = athletePaymentsAscii($coachLastName . ' ' . date('m/Y', strtotime($month)));
    $isAthleteReleased = !empty($releasedAthleteByMonth[$month]);
    $isReleased = $isAthleteReleased || $paymentStatus === 'pending' || $paymentStatus === 'paid';

    // Legacy data fix: if a month is released but missing snapshot row, create one now and freeze future changes.
    if ($payment === null && $isAthleteReleased && $rate !== null && $displayAmount !== null) {
        try {
            athletePaymentsBackfillPendingSnapshot(
                $pdo,
                (int)$athlete['coach_id'],
                $athleteId,
                $month,
                $rate,
                $displayBillableSessions,
                $displayCarryoverApplied,
                (float)$displayAmount
            );
            $payment = [
                'billing_month' => $month,
                'session_rate' => $rate,
                'planned_sessions' => $displayBillableSessions,
                'carryover_used_sessions' => $displayCarryoverApplied,
                'billed_amount' => $displayAmount,
                'status' => 'pending',
                'paid_at' => null,
            ];
            $paymentStatus = 'pending';
            $isSnapshotLocked = true;
        } catch (Throwable $e) {
            // Ignore backfill failures, display still works with computed values.
        }
    }

    $isPaid = $paymentStatus === 'paid';
    $isPending = $paymentStatus === 'pending';
    $isPendingVisible = ($isPending && $isAthleteReleased);
    $qrUrl = ($isReleased && $coachBankAccount !== null && $displayAmount !== null && $displayAmount > 0)
        ? athletePaymentsQrUrl($coachBankAccount, $displayAmount, $note)
        : null;
    if (!$isPendingVisible) {
        $qrUrl = null;
    }

    if ((!$isPaid) && $isPendingVisible && $displayAmount !== null && $displayAmount > 0) {
        $openPaymentCount++;
    }

    $paymentRowsForView[] = [
        'billing_month' => $month,
        'month_label' => date('m/Y', strtotime($month)),
        'stats' => $stats,
        'payment' => $payment,
        'amount' => $amount,
        'display_amount' => $displayAmount,
        'billable_sessions' => $displayBillableSessions,
        'billable_single_sessions' => $billableSingle,
        'billable_paired_sessions' => $billablePaired,
        'paired_sessions' => $rawPairedSessions,
        'single_sessions' => $rawSingleSessions,
        'carryover_applied' => $displayCarryoverApplied,
        'forfeited_compensations' => (int)($forfeitedByMonth[$month] ?? 0),
        'note' => $note,
        'qr_url' => $qrUrl,
        'is_released' => $isAthleteReleased,
        'is_pending' => $isPendingVisible,
        'is_paid' => $isPaid,
        'is_snapshot_locked' => $isSnapshotLocked,
    ];
}

renderAthleteHeader('Platby');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-1"><i class="fas fa-wallet me-2 text-warning"></i>Platby</h2>
        <div class="text-muted">Tady vidíte platební výzvy od trenéra, QR kód k úhradě a stav zaplacení jednotlivých měsíců.</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <a href="<?= BASE_URL ?>/athlete_zpravy.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-envelope me-1"></i>Zprávy od trenéra
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Trenér</div>
                <div class="fs-5 fw-bold"><?= h($coachDisplayName) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Otevřené výzvy</div>
                <div class="fs-5 fw-bold"><?= $openPaymentCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Sazba za trénink</div>
                <div class="fs-5 fw-bold"><?= $rate !== null ? number_format($rate, 0, ',', ' ') . ' Kč' : 'Nenastavena' ?></div>
                <?php if ($pairedRate !== null && $rate !== null && abs($pairedRate - $rate) > 0.0001): ?>
                    <div class="small text-muted mt-1">Párový trénink: <?= number_format($pairedRate, 0, ',', ' ') ?> Kč</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($activeReplacementNotice): ?>
<div class="alert <?= $activeReplacementNotice['is_overdue'] ? 'alert-danger' : 'alert-warning' ?>">
    <div class="fw-semibold mb-1"><i class="fas fa-triangle-exclamation me-1"></i>Náhradní termín po zrušení</div>
    <div>
        <?= $activeReplacementNotice['count'] > 1
            ? ('Máte ' . (int)$activeReplacementNotice['count'] . ' zrušené termíny, které je potřeba nahradit.')
            : 'Máte zrušený termín, který je potřeba nahradit.' ?>
        <?php if (!empty($activeReplacementNotice['deadline_at'])): ?>
            Nejzazší termín rezervace je <strong><?= h(date('d.m.Y H:i', strtotime((string)$activeReplacementNotice['deadline_at']))) ?></strong>.
        <?php endif; ?>
        <?= $activeReplacementNotice['is_overdue'] ? ' Lhůta už uplynula, kontaktujte trenéra.' : '' ?>
    </div>
    <div class="small mt-2">
        Náhradní trénink můžete naplánovat v kalendáři. Přednostně vybírejte stejný měsíc; do dalšího měsíce lze náhradu přesunout jen při zrušení v posledním týdnu měsíce.
    </div>
</div>
<?php endif; ?>

<?php if ($coachBankAccount === null): ?>
<div class="alert alert-info">Trenér zatím nemá nastavené platné číslo účtu, proto zde QR platba nemusí být k dispozici.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($paymentRowsForView)): ?>
            <div class="text-center text-muted py-4">Zatím tu nemáte žádné platební výzvy.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Měsíc</th>
                            <th>Tréninky</th>
                            <th>Částka</th>
                            <th>Stav</th>
                            <th class="text-end">Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentRowsForView as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= h($row['month_label']) ?></div>
                                    <?php if ((int)$row['stats']['makeup_sessions'] > 0): ?>
                                        <div class="small text-muted">Náhradní termíny: <?= (int)$row['stats']['makeup_sessions'] ?>x</div>
                                    <?php endif; ?>
                                    <?php if ((int)$row['stats']['transferred_sessions'] > 0): ?>
                                        <div class="small text-muted">Z jiného kalendářního měsíce: <?= (int)$row['stats']['transferred_sessions'] ?>x</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-semibold"><?= (int)$row['billable_sessions'] ?></span>
                                    <div class="small text-muted">započítaných tréninků</div>
                                    <?php if ((int)($row['paired_sessions'] ?? 0) > 0): ?>
                                        <div class="small text-muted">párové: <?= (int)$row['paired_sessions'] ?>x</div>
                                    <?php endif; ?>
                                    <?php if ((int)($row['single_sessions'] ?? 0) > 0): ?>
                                        <div class="small text-muted">individuální: <?= (int)$row['single_sessions'] ?>x</div>
                                    <?php endif; ?>
                                    <?php if ((int)$row['carryover_applied'] > 0): ?>
                                        <div class="small text-warning">Zápočet z dříve uhrazených: -<?= (int)$row['carryover_applied'] ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['is_snapshot_locked'])): ?>
                                        <div class="small text-muted">Počet ve výzvě je uzamčený.</div>
                                    <?php endif; ?>
                                    <?php if ((int)($row['forfeited_compensations'] ?? 0) > 0): ?>
                                        <div class="small text-danger">Propadlá kompenzace: <?= (int)$row['forfeited_compensations'] ?> tréninků</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['display_amount'] !== null): ?>
                                        <span class="fw-semibold"><?= number_format((float)$row['display_amount'], 0, ',', ' ') ?> Kč</span>
                                        <?php if ((float)$row['display_amount'] <= 0.0001): ?>
                                            <div class="small text-success">Fakturovaná částka: 0 Kč</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Nelze spočítat</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['is_paid']): ?>
                                        <span class="badge bg-success">Uhrazeno</span>
                                        <?php if (!empty($row['payment']['paid_at'])): ?>
                                            <div class="small text-muted mt-1"><?= h(date('d.m.Y H:i', strtotime((string)$row['payment']['paid_at']))) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($row['is_pending']): ?>
                                            <span class="badge bg-warning text-dark">Čeká na úhradu</span>
                                            <div class="small text-muted mt-1">Poznámka: <?= h($row['note']) ?></div>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Čeká na vystavení výzvy</span>
                                            <div class="small text-muted mt-1">Trenér ještě neuzavřel měsíc.</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= BASE_URL ?>/payment_receipt.php?month=<?= urlencode((string)date('Y-m', strtotime((string)$row['billing_month']))) ?>" target="_blank" class="btn btn-outline-primary btn-sm me-1 mb-1">
                                        <i class="fas fa-receipt me-1"></i>Účtenka
                                    </a>
                                    <?php if (!$row['is_paid'] && $row['qr_url'] !== null): ?>
                                        <button
                                            type="button"
                                            class="btn btn-outline-dark btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#athletePaymentQrModal"
                                            data-month-label="<?= h($row['month_label']) ?>"
                                            data-amount="<?= h(number_format((float)$row['display_amount'], 0, ',', ' ') . ' Kč') ?>"
                                            data-account="<?= h($coachBankAccount ?? '') ?>"
                                            data-note="<?= h($row['note']) ?>"
                                            data-qr-url="<?= h($row['qr_url']) ?>"
                                        >
                                            <i class="fas fa-qrcode me-1"></i>Zobrazit QR
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="athletePaymentQrModal" tabindex="-1" aria-labelledby="athletePaymentQrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="athletePaymentQrModalLabel">QR platba</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <img id="athletePaymentQrImage" src="" alt="QR platba" class="img-fluid border rounded p-2 bg-white" style="max-width:220px;">
                </div>
                <div class="small"><strong>Období:</strong> <span id="athletePaymentQrMonth"></span></div>
                <div class="small"><strong>Částka:</strong> <span id="athletePaymentQrAmount"></span></div>
                <div class="small"><strong>Účet:</strong> <span id="athletePaymentQrAccount"></span></div>
                <div class="small"><strong>Poznámka:</strong> <span id="athletePaymentQrNote"></span></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Zavřít</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('athletePaymentQrModal');
    if (!modal) return;

    const qrImage = document.getElementById('athletePaymentQrImage');
    const monthLabel = document.getElementById('athletePaymentQrMonth');
    const amountLabel = document.getElementById('athletePaymentQrAmount');
    const accountLabel = document.getElementById('athletePaymentQrAccount');
    const noteLabel = document.getElementById('athletePaymentQrNote');

    modal.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;

        const data = trigger.dataset;
        qrImage.src = data.qrUrl || '';
        monthLabel.textContent = data.monthLabel || '';
        amountLabel.textContent = data.amount || '';
        accountLabel.textContent = data.account || '';
        noteLabel.textContent = data.note || '';
    });
})();
</script>

<?php renderAthleteFooter(); ?>