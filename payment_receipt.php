<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment_billing.php';

$coachMode = isLoggedIn();
$athleteMode = !$coachMode && athleteIsLoggedIn();

if ($coachMode) {
    require_once __DIR__ . '/includes/header.php';
    requireLogin();
} elseif ($athleteMode) {
    require_once __DIR__ . '/includes/athlete_header.php';
    requireAthleteLogin();
} else {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$pdo = getDB();
paymentEnsureBillingIssueSchema($pdo);

function receiptTableExists(PDO $pdo, string $tableName): bool
{
    $quotedTable = $pdo->quote($tableName);
    $stmt = $pdo->query("SHOW TABLES LIKE {$quotedTable}");
    return $stmt !== false && (bool)$stmt->fetchColumn();
}

function receiptColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $quotedColumn = $pdo->quote($columnName);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quotedColumn}");
    return $stmt !== false && (bool)$stmt->fetch();
}

function receiptFetchHistoricalActualByMonth(PDO $pdo, int $coachId, int $athleteId, string $beforeMonthSql, bool $hasBillingMonth, bool $hasSecondAthlete): array
{
    $monthExpr = "DATE_FORMAT(e.starts_at, '%Y-%m-01')";

    if ($hasSecondAthlete) {
        $participantsSql = "
            SELECT {$monthExpr} AS billing_month
            FROM coach_calendar_events e
            WHERE e.coach_id = ?
              AND e.approval_status = 'approved'
              AND e.athlete_id = ?
            UNION ALL
            SELECT {$monthExpr} AS billing_month
            FROM coach_calendar_events e
            WHERE e.coach_id = ?
              AND e.approval_status = 'approved'
              AND e.second_athlete_id = ?
        ";
        $params = [$coachId, $athleteId, $coachId, $athleteId, $beforeMonthSql];
    } else {
        $participantsSql = "
            SELECT {$monthExpr} AS billing_month
            FROM coach_calendar_events e
            WHERE e.coach_id = ?
              AND e.approval_status = 'approved'
              AND e.athlete_id = ?
        ";
        $params = [$coachId, $athleteId, $beforeMonthSql];
    }

    $stmt = $pdo->prepare(
        "SELECT t.billing_month, COUNT(*) AS billed_sessions
         FROM ({$participantsSql}) t
         WHERE t.billing_month < ?
         GROUP BY t.billing_month
         ORDER BY t.billing_month ASC"
    );
    $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(string)$row['billing_month']] = (int)$row['billed_sessions'];
    }

    return $result;
}

function receiptFetchOutstandingCarryover(PDO $pdo, int $coachId, int $athleteId, string $beforeMonthSql, array $actualByMonth): int
{
    if (!receiptTableExists($pdo, 'athlete_monthly_payments')) {
        return 0;
    }

    $hasCarryover = receiptColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions');
    $stmt = $pdo->prepare(
        'SELECT billing_month, planned_sessions, ' . ($hasCarryover ? 'carryover_used_sessions' : '0 AS carryover_used_sessions') . '
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND athlete_id = ?
           AND status = "paid"
           AND billing_month < ?
         ORDER BY billing_month ASC'
    );
    $stmt->execute([$coachId, $athleteId, $beforeMonthSql]);

    $outstanding = 0;
    foreach ($stmt->fetchAll() as $row) {
        $month = (string)$row['billing_month'];
        $planned = max(0, (int)$row['planned_sessions']);
        $actual = max(0, (int)($actualByMonth[$month] ?? 0));
        $generated = max(0, $planned - $actual);
        $used = max(0, (int)($row['carryover_used_sessions'] ?? 0));

        $outstanding += $generated;
        $outstanding = max(0, $outstanding - $used);
    }

    return $outstanding;
}

function receiptBuildBillable(int $singleSessions, int $pairedSessions, int $carryoverApplied, ?float $singleRate, ?float $pairedRate, int $paidReplacementSingle = 0, int $paidReplacementPaired = 0): array
{
    $totalSessions = max(0, $singleSessions) + max(0, $pairedSessions);
    $paidReplacementSingle = min(max(0, $singleSessions), max(0, $paidReplacementSingle));
    $paidReplacementPaired = min(max(0, $pairedSessions), max(0, $paidReplacementPaired));
    $carryoverApplied = min(max(0, $carryoverApplied), $totalSessions);

    $genericCarryover = max(0, $carryoverApplied - $paidReplacementSingle - $paidReplacementPaired);
    $billableSingle = max(0, $singleSessions - $paidReplacementSingle - $genericCarryover);
    $remainingCarryover = max(0, $genericCarryover - ($singleSessions - $paidReplacementSingle));
    $billablePaired = max(0, $pairedSessions - $paidReplacementPaired - $remainingCarryover);

    $effectiveSingleRate = $singleRate;
    $effectivePairedRate = $pairedRate ?? $singleRate;
    $amount = null;

    if ($effectiveSingleRate !== null && $effectivePairedRate !== null) {
        $amount = ($billableSingle * $effectiveSingleRate) + ($billablePaired * $effectivePairedRate);
    }

    return [
        'raw_total' => $totalSessions,
        'carryover_applied' => $carryoverApplied,
        'paid_replacement_single' => $paidReplacementSingle,
        'paid_replacement_paired' => $paidReplacementPaired,
        'billable_single' => $billableSingle,
        'billable_paired' => $billablePaired,
        'billable_total' => $billableSingle + $billablePaired,
        'single_rate' => $effectiveSingleRate,
        'paired_rate' => $effectivePairedRate,
        'computed_amount' => $amount,
    ];
}

function receiptResolveCarryoverUsage(int $outstandingBefore, int $transferredSessions, int $totalSessions): int
{
    return min(max(0, $totalSessions), max(0, $transferredSessions));
}

function receiptDecodeSnapshot(?string $raw): ?array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function receiptFetchReplacementOrigins(PDO $pdo, int $coachId, int $athleteId): array
{
    if (!receiptTableExists($pdo, 'coach_calendar_event_cancellations')) {
        return [];
    }

    if (!receiptColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
        || !receiptColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at')) {
        return [];
    }

    $billingField = receiptColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
        ? 'billing_month'
        : 'NULL AS billing_month';
    $statusField = receiptColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot')
        ? 'payment_status_snapshot'
        : '"none" AS payment_status_snapshot';

        $stmt = $pdo->prepare("SELECT replacement_event_id, starts_at, {$billingField}, {$statusField} FROM coach_calendar_event_cancellations WHERE coach_id = ? AND replacement_event_id IS NOT NULL ORDER BY id ASC");
        $stmt->execute([$coachId]);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $replacementEventId = (int)($row['replacement_event_id'] ?? 0);
        if ($replacementEventId > 0 && !isset($result[$replacementEventId])) {
            $result[$replacementEventId] = [
                'starts_at' => (string)($row['starts_at'] ?? ''),
                'billing_month' => (string)($row['billing_month'] ?? ''),
                'payment_status_snapshot' => (string)($row['payment_status_snapshot'] ?? 'none'),
            ];
        }
    }

    return $result;
}

$monthParam = trim((string)($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$monthSql = $monthParam . '-01';
$issueNumber = max(1, (int)($_GET['issue'] ?? 1));

$hasBillingMonth = receiptColumnExists($pdo, 'coach_calendar_events', 'billing_month');
$hasBillingTransferReason = receiptColumnExists($pdo, 'coach_calendar_events', 'billing_transfer_reason');
$hasSecondAthlete = receiptColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
$hasIsMakeup = receiptColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session');
$hasPairedTrainingRate = receiptColumnExists($pdo, 'athletes', 'paired_training_rate');
$hasPaymentsTable = receiptTableExists($pdo, 'athlete_monthly_payments');
$hasCarryover = $hasPaymentsTable && receiptColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions');
$hasReceiptSnapshot = $hasPaymentsTable && receiptColumnExists($pdo, 'athlete_monthly_payments', 'receipt_snapshot_json');

if ($coachMode) {
    $coachId = (int)getCurrentCoachId();
    $athleteId = (int)($_GET['athlete_id'] ?? 0);

    if ($athleteId <= 0) {
        flash('danger', 'Chybí sportovec pro účtenku.');
        redirect(BASE_URL . '/payments.php?month=' . urlencode($monthParam));
    }

    $athleteStmt = $pdo->prepare(
        'SELECT a.id, a.first_name, a.last_name, a.training_rate'
        . ($hasPairedTrainingRate ? ', a.paired_training_rate' : '') . '
         FROM athletes a
         WHERE a.id = ? AND a.coach_id = ?
         LIMIT 1'
    );
    $athleteStmt->execute([$athleteId, $coachId]);
    $athlete = $athleteStmt->fetch();

    if (!$athlete) {
        flash('danger', 'Sportovec nebyl nalezen.');
        redirect(BASE_URL . '/payments.php?month=' . urlencode($monthParam));
    }

    $coach = getCurrentCoach();
    $coachName = trim((string)($coach['name'] ?? $coach['username'] ?? 'Trenér'));
    $backUrl = BASE_URL . '/payments.php?month=' . urlencode($monthParam);
    $pageTitle = 'Účtenka platby';
} else {
    $athleteId = (int)getCurrentAthleteId();

    $athleteStmt = $pdo->prepare(
        'SELECT a.id, a.coach_id, a.first_name, a.last_name, a.training_rate'
        . ($hasPairedTrainingRate ? ', a.paired_training_rate' : '') . ',
                c.name AS coach_name, c.username AS coach_username
         FROM athletes a
         JOIN coaches c ON c.id = a.coach_id
         WHERE a.id = ?
         LIMIT 1'
    );
    $athleteStmt->execute([$athleteId]);
    $athlete = $athleteStmt->fetch();

    if (!$athlete) {
        flash('danger', 'Sportovec nebyl nalezen.');
        redirect(BASE_URL . '/athlete_payments.php');
    }

    $coachId = (int)$athlete['coach_id'];
    $coachName = trim((string)($athlete['coach_name'] ?: $athlete['coach_username'] ?: 'Trenér'));
    $backUrl = BASE_URL . '/athlete_payments.php';
    $pageTitle = 'Moje účtenka platby';
}

$monthExpr = paymentBillingEffectiveMonthExpression('e', $hasBillingMonth, $hasBillingTransferReason);
$monthFilter = "{$monthExpr} = ?";
$issueExpr = receiptColumnExists($pdo, 'coach_calendar_events', 'billing_issue_number') ? 'COALESCE(e.billing_issue_number, 1)' : '1';
$transferReasonSelect = receiptColumnExists($pdo, 'coach_calendar_events', 'billing_transfer_reason')
    ? 'e.billing_transfer_reason'
    : 'NULL AS billing_transfer_reason';
$isMakeupSelect = $hasIsMakeup ? 'e.is_makeup_session' : '0';
$billingMonthSelect = $hasBillingMonth
    ? "DATE_FORMAT(COALESCE(e.billing_month, e.starts_at), '%Y-%m-01')"
    : "DATE_FORMAT(e.starts_at, '%Y-%m-01')";

if ($hasSecondAthlete) {
    $eventsSql = "
        SELECT e.id,
               e.starts_at,
               e.ends_at,
               e.location,
               {$isMakeupSelect} AS is_makeup_session,
               {$billingMonthSelect} AS billing_month,
               {$transferReasonSelect},
               CASE WHEN e.second_athlete_id IS NOT NULL THEN 1 ELSE 0 END AS is_paired
        FROM coach_calendar_events e
        WHERE e.coach_id = ?
          AND e.approval_status = 'approved'
          AND e.athlete_id = ?
          AND {$monthFilter}
          AND {$issueExpr} = ?
        UNION ALL
        SELECT e.id,
               e.starts_at,
               e.ends_at,
               e.location,
               {$isMakeupSelect} AS is_makeup_session,
               {$billingMonthSelect} AS billing_month,
               {$transferReasonSelect},
               1 AS is_paired
        FROM coach_calendar_events e
        WHERE e.coach_id = ?
          AND e.approval_status = 'approved'
          AND e.second_athlete_id = ?
          AND {$monthFilter}
          AND {$issueExpr} = ?
        ORDER BY starts_at ASC
    ";
    $eventsParams = [
        $coachId, $athleteId, $monthSql, $issueNumber,
        $coachId, $athleteId, $monthSql, $issueNumber,
    ];
} else {
    $eventsSql = "
        SELECT e.id,
               e.starts_at,
               e.ends_at,
               e.location,
               {$isMakeupSelect} AS is_makeup_session,
               {$billingMonthSelect} AS billing_month,
               {$transferReasonSelect},
               0 AS is_paired
        FROM coach_calendar_events e
        WHERE e.coach_id = ?
          AND e.approval_status = 'approved'
          AND e.athlete_id = ?
          AND {$monthFilter}
          AND {$issueExpr} = ?
        ORDER BY starts_at ASC
    ";
    $eventsParams = [$coachId, $athleteId, $monthSql, $issueNumber];
}

$eventsStmt = $pdo->prepare($eventsSql);
$eventsStmt->execute($eventsParams);
$eventRows = $eventsStmt->fetchAll();
$replacementOrigins = receiptFetchReplacementOrigins($pdo, $coachId, $athleteId);
$replacementOriginsByEventId = $replacementOrigins;

$singleSessions = 0;
$pairedSessions = 0;
$makeupSessions = 0;
$transferredSessions = 0;
$transferredMakeupSessions = 0;
$paidReplacementSingle = 0;
$paidReplacementPaired = 0;

foreach ($eventRows as $eventRow) {
    $isPaired = ((int)$eventRow['is_paired'] === 1);
    if ($isPaired) {
        $pairedSessions++;
    } else {
        $singleSessions++;
    }

    if ((int)($eventRow['is_makeup_session'] ?? 0) === 1) {
        $makeupSessions++;
    }

    $eventMonth = date('Y-m-01', strtotime((string)$eventRow['starts_at']));
    $billingMonth = date('Y-m-01', strtotime((string)$eventRow['billing_month']));
    if ($eventMonth !== $billingMonth) {
        $transferredSessions++;
    }
    $replacementOrigin = $replacementOriginsByEventId[(int)($eventRow['id'] ?? 0)] ?? null;
    if (is_array($replacementOrigin) && in_array((string)($replacementOrigin['payment_status_snapshot'] ?? ''), ['pending', 'paid'], true)) {
        $transferredMakeupSessions++;
        if ($isPaired) {
            $paidReplacementPaired++;
        } else {
            $paidReplacementSingle++;
        }
    }
}

$actualByMonth = receiptFetchHistoricalActualByMonth(
    $pdo,
    $coachId,
    $athleteId,
    $monthSql,
    $hasBillingMonth,
    $hasSecondAthlete
);
$outstandingBefore = receiptFetchOutstandingCarryover($pdo, $coachId, $athleteId, $monthSql, $actualByMonth);

$singleRate = $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
$pairedRate = ($hasPairedTrainingRate && array_key_exists('paired_training_rate', $athlete) && $athlete['paired_training_rate'] !== null)
    ? (float)$athlete['paired_training_rate']
    : $singleRate;

$carryoverForMonth = receiptResolveCarryoverUsage(
    $outstandingBefore,
    $transferredMakeupSessions,
    $singleSessions + $pairedSessions
);
$breakdown = receiptBuildBillable($singleSessions, $pairedSessions, $carryoverForMonth, $singleRate, $pairedRate, $paidReplacementSingle, $paidReplacementPaired);
$onlineBillingRows = [];
if ($issueNumber === 1) {
    try {
        $onlineBillingStmt = $pdo->prepare('SELECT description, amount, billing_date FROM online_training_billing WHERE trainer_id = ? AND athlete_id = ? AND billing_month = ? ORDER BY billing_date, id');
        $onlineBillingStmt->execute([$coachId, $athleteId, $monthSql]);
        $onlineBillingRows = $onlineBillingStmt->fetchAll();
    } catch (Throwable $e) {
        $onlineBillingRows = [];
    }
}
$onlineBillingAmount = array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $onlineBillingRows));

$paymentRow = null;
if ($hasPaymentsTable) {
    $paymentStmt = $pdo->prepare(
        'SELECT issue_number, session_rate, planned_sessions, '
        . ($hasCarryover ? 'carryover_used_sessions' : '0 AS carryover_used_sessions') . ',
        billed_amount, status, paid_at'
    . ($hasReceiptSnapshot ? ', receipt_snapshot_json' : '') . '
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND athlete_id = ?
           AND billing_month = ?
            AND issue_number = ?
         LIMIT 1'
    );
        $paymentStmt->execute([$coachId, $athleteId, $monthSql, $issueNumber]);
    $paymentRow = $paymentStmt->fetch() ?: null;
}

    if ($paymentRow && $paymentRow['session_rate'] !== null) {
        $singleRate = (float)$paymentRow['session_rate'];
        $pairedRate = $singleRate;
    }

$usesSnapshot = false;
if ($paymentRow && in_array((string)($paymentRow['status'] ?? ''), ['pending', 'paid'], true) && $hasReceiptSnapshot) {
    $snapshot = receiptDecodeSnapshot((string)($paymentRow['receipt_snapshot_json'] ?? ''));
    if ($snapshot !== null) {
        $snapshotEvents = $snapshot['event_rows'] ?? null;
        if (is_array($snapshotEvents)) {
            $eventRows = [];
            foreach ($snapshotEvents as $snapshotEvent) {
                if (!is_array($snapshotEvent)) {
                    continue;
                }
                $snapshotStart = (string)($snapshotEvent['starts_at'] ?? '');
                $snapshotBillingMonth = (string)($snapshotEvent['billing_month'] ?? '');
                $snapshotIssue = max(1, (int)($snapshotEvent['billing_issue_number'] ?? $issueNumber));
                if ($snapshotStart === ''
                    || ($snapshotBillingMonth !== '' && substr($snapshotBillingMonth, 0, 10) !== $monthSql)
                    || $snapshotIssue !== $issueNumber) {
                    continue;
                }
                $eventRows[] = [
                    'id' => (int)($snapshotEvent['id'] ?? 0),
                    'starts_at' => $snapshotStart,
                    'ends_at' => (string)($snapshotEvent['ends_at'] ?? ''),
                    'location' => (string)($snapshotEvent['location'] ?? ''),
                    'billing_month' => $snapshotBillingMonth,
                    'billing_issue_number' => $snapshotIssue,
                    'billing_transfer_reason' => (string)($snapshotEvent['billing_transfer_reason'] ?? ''),
                    'is_makeup_session' => (int)($snapshotEvent['is_makeup_session'] ?? 0),
                    'is_paired' => (int)($snapshotEvent['is_paired'] ?? 0),
                ];
            }
            $singleSessions = 0;
            $pairedSessions = 0;
            $makeupSessions = 0;
            $transferredSessions = 0;
            foreach ($eventRows as $snapshotEvent) {
                if ((int)$snapshotEvent['is_paired'] === 1) {
                    $pairedSessions++;
                } else {
                    $singleSessions++;
                }
                if ((int)$snapshotEvent['is_makeup_session'] === 1) {
                    $makeupSessions++;
                }
                if (substr((string)$snapshotEvent['billing_month'], 0, 10) !== $monthSql) {
                    $transferredSessions++;
                }
            }
        }

        $snapshotBreakdown = $snapshot['breakdown'] ?? null;
        if (is_array($snapshotBreakdown)) {
            $breakdown = array_merge($breakdown, [
                'raw_total' => (int)($snapshotBreakdown['total_sessions'] ?? $snapshotBreakdown['raw_total'] ?? $breakdown['raw_total']),
                'carryover_applied' => (int)($snapshotBreakdown['carryover_used'] ?? $snapshotBreakdown['carryover_applied'] ?? $breakdown['carryover_applied']),
                'billable_single' => (int)($snapshotBreakdown['billable_single_sessions'] ?? $snapshotBreakdown['billable_single'] ?? $breakdown['billable_single']),
                'billable_paired' => (int)($snapshotBreakdown['billable_paired_sessions'] ?? $snapshotBreakdown['billable_paired'] ?? $breakdown['billable_paired']),
                'billable_total' => (int)($snapshotBreakdown['billable_sessions'] ?? $snapshotBreakdown['billable_total'] ?? $breakdown['billable_total']),
                'single_rate' => array_key_exists('single_rate', $snapshotBreakdown) ? ($snapshotBreakdown['single_rate'] !== null ? (float)$snapshotBreakdown['single_rate'] : null) : $breakdown['single_rate'],
                'paired_rate' => array_key_exists('paired_rate', $snapshotBreakdown) ? ($snapshotBreakdown['paired_rate'] !== null ? (float)$snapshotBreakdown['paired_rate'] : null) : $breakdown['paired_rate'],
                'computed_amount' => array_key_exists('amount', $snapshotBreakdown)
                    ? ($snapshotBreakdown['amount'] !== null ? (float)$snapshotBreakdown['amount'] : null)
                    : (array_key_exists('computed_amount', $snapshotBreakdown)
                        ? ($snapshotBreakdown['computed_amount'] !== null ? (float)$snapshotBreakdown['computed_amount'] : null)
                        : $breakdown['computed_amount']),
            ]);
        }

        if (array_key_exists('display_amount', $snapshot) && $snapshot['display_amount'] !== null) {
            $displayAmount = (float)$snapshot['display_amount'];
        }

        $usesSnapshot = true;
    }
}

$rowCharges = [];
foreach ($eventRows as $eventRow) {
    $rowRate = ((int)($eventRow['is_paired'] ?? 0) === 1)
        ? ($pairedRate ?? $singleRate)
        : $singleRate;
    $rowNotes = [];
    $replacementOrigin = $replacementOriginsByEventId[(int)($eventRow['id'] ?? 0)] ?? null;
    $isPaidReplacement = is_array($replacementOrigin)
        && in_array((string)($replacementOrigin['payment_status_snapshot'] ?? ''), ['pending', 'paid'], true);

    if ($isPaidReplacement) {
        $originMonthRaw = trim((string)($replacementOrigin['billing_month'] ?? ''));
        if ($originMonthRaw === '') {
            $originMonthRaw = (string)($replacementOrigin['starts_at'] ?? '');
        }
        if ($originMonthRaw !== '' && strtotime($originMonthRaw) !== false) {
            $rowNotes[] = ((string)($replacementOrigin['payment_status_snapshot'] ?? '') === 'paid' ? 'Hrazeno v ' : 'Zahrnuto ve výzvě za ')
                . date('m/Y', strtotime($originMonthRaw));
        }
    }
    if (is_array($replacementOrigin) && !empty($replacementOrigin['starts_at'])) {
        $rowNotes[] = 'Původní termín: ' . formatDate((string)$replacementOrigin['starts_at']);
    }
    $calendarMonth = date('Y-m-01', strtotime((string)$eventRow['starts_at']));
    $assignedMonth = !empty($eventRow['billing_month'])
        ? date('Y-m-01', strtotime((string)$eventRow['billing_month']))
        : $calendarMonth;
    if ($calendarMonth !== $assignedMonth) {
        $calendarDay = date('j. n. Y', strtotime((string)$eventRow['starts_at']));
        if ((string)($eventRow['billing_transfer_reason'] ?? '') === 'pending_not_included') {
            $rowNotes[] = 'Tento trénink proběhl ' . $calendarDay . ' a nebyl zahrnut do ' . paymentBillingMonthAdjective($calendarMonth) . ' výzvy.';
        } elseif ((string)($eventRow['billing_transfer_reason'] ?? '') === 'paid_not_paid') {
            $rowNotes[] = 'Tento trénink proběhl ' . $calendarDay . ' a nebyl uhrazen v ' . paymentBillingMonthGenitive($calendarMonth) . '.';
        }
    }

    $rowCharges[] = [
        'amount' => $isPaidReplacement ? 0.0 : ($rowRate !== null ? (float)$rowRate : 0.0),
        'note' => implode('; ', $rowNotes),
    ];
}

$paidReplacementSingle = 0;
$paidReplacementPaired = 0;
foreach ($eventRows as $eventRow) {
    $origin = $replacementOriginsByEventId[(int)($eventRow['id'] ?? 0)] ?? null;
    if (!is_array($origin) || !in_array((string)($origin['payment_status_snapshot'] ?? ''), ['pending', 'paid'], true)) {
        continue;
    }
    if ((int)($eventRow['is_paired'] ?? 0) === 1) {
        $paidReplacementPaired++;
    } else {
        $paidReplacementSingle++;
    }
}
$breakdown['raw_total'] = count($eventRows);
$breakdown['billable_single'] = max(0, $singleSessions - $paidReplacementSingle);
$breakdown['billable_paired'] = max(0, $pairedSessions - $paidReplacementPaired);
$breakdown['billable_total'] = $breakdown['billable_single'] + $breakdown['billable_paired'];
$breakdown['carryover_applied'] = $paidReplacementSingle + $paidReplacementPaired;

$displayAmount = $breakdown['computed_amount'];
if ($displayAmount !== null) {
    $displayAmount += $onlineBillingAmount;
} elseif ($onlineBillingAmount > 0) {
    $displayAmount = $onlineBillingAmount;
}
if ($paymentRow && in_array((string)($paymentRow['status'] ?? ''), ['pending', 'paid'], true) && isset($paymentRow['billed_amount'])) {
    $displayAmount = (float)$paymentRow['billed_amount'];
}

$athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
$monthLabel = date('m/Y', strtotime($monthSql)) . ($issueNumber > 1 ? ' — dodatečná výzva' : '');
$paymentStatus = $paymentRow ? (string)($paymentRow['status'] ?? 'pending') : 'pending';
$statusLabel = $paymentStatus === 'paid' ? 'Uhrazeno' : 'Neuhrazeno';
$statusClass = $paymentStatus === 'paid' ? 'success' : 'secondary';

if ($coachMode) {
    renderHeader($pageTitle);
} else {
    renderAthleteHeader($pageTitle);
}
?>

<style>
.receipt-paper {
    max-width: 430px;
    margin: 0 auto;
}

.receipt-paper .card {
    border: 1px dashed #7f7f7f !important;
    box-shadow: none !important;
}

.receipt-paper .card-header {
    font-size: 0.82rem;
    padding-top: 0.45rem;
    padding-bottom: 0.45rem;
}

.receipt-paper .card-body,
.receipt-paper .table,
.receipt-paper .table th,
.receipt-paper .table td {
    font-size: 0.84rem;
}

.receipt-paper .table th,
.receipt-paper .table td {
    padding: 0.35rem 0.4rem;
}

.receipt-paper .receipt-events-table {
    width: 100%;
}

.receipt-paper .receipt-events-table th,
.receipt-paper .receipt-events-table td {
    white-space: normal;
    overflow-wrap: break-word;
}

.receipt-paper .receipt-events-cards {
    display: block;
    padding: 0.6rem;
}

.receipt-paper .receipt-event-card {
    border: 1px solid #e1e5ea;
    border-radius: 10px;
    padding: 0.65rem;
    margin-bottom: 0.55rem;
    background: #fff;
}

.receipt-paper .receipt-event-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
    border-bottom: 1px dashed #d9dfe6;
    padding-bottom: 0.45rem;
    margin-bottom: 0.45rem;
}

.receipt-paper .receipt-event-amount {
    font-weight: 700;
    color: #000;
}

.receipt-paper .receipt-event-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.35rem 0.65rem;
}

.receipt-paper .receipt-event-item {
    min-width: 0;
}

.receipt-paper .receipt-event-label {
    display: block;
    font-size: 0.72rem;
    color: #6c757d;
    margin-bottom: 0.1rem;
}

.receipt-paper .receipt-event-value {
    font-size: 0.84rem;
    font-weight: 600;
    overflow-wrap: break-word;
}

.receipt-paper .receipt-event-note {
    margin-top: 0.4rem;
    font-size: 0.78rem;
    color: #495057;
    overflow-wrap: break-word;
}

.receipt-paper .receipt-events-table-wrap {
    display: none;
}

#supportWidgetRoot,
.support-fab-stack {
    display: none !important;
}

@media print {
    @page {
        size: 80mm auto;
        margin: 4mm;
    }

    html,
    body {
        width: 80mm;
        background: #fff !important;
    }

    .no-print {
        display: none !important;
    }

    #supportWidgetRoot,
    .support-fab-stack {
        display: none !important;
    }

    .navbar,
    footer {
        display: none !important;
    }

    .container-fluid {
        max-width: 80mm !important;
        width: 80mm !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    .receipt-paper {
        max-width: 72mm !important;
        width: 72mm !important;
        margin: 0 auto !important;
        font-size: 10px !important;
        color: #000 !important;
    }

    .receipt-paper .card {
        border: 1px dashed #666 !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        margin-bottom: 2mm !important;
    }

    .receipt-paper .card-header,
    .receipt-paper .card-body,
    .receipt-paper .table,
    .receipt-paper .table th,
    .receipt-paper .table td,
    .receipt-paper .receipt-event-label,
    .receipt-paper .receipt-event-value,
    .receipt-paper .receipt-event-note,
    .receipt-paper .receipt-event-amount,
    .receipt-paper .small,
    .receipt-paper .badge,
    .receipt-paper .fw-semibold,
    .receipt-paper .fs-5,
    .receipt-paper .h4 {
        font-size: 10px !important;
        line-height: 1.25 !important;
    }

    .receipt-paper .h4,
    .receipt-paper h2,
    .receipt-paper .fs-5 {
        font-size: 12px !important;
        margin-bottom: 1mm !important;
    }

    .receipt-paper .row,
    .receipt-paper [class*="col-"] {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .receipt-paper .table th,
    .receipt-paper .table td {
        border-color: #999 !important;
        padding: 1mm !important;
        vertical-align: top !important;
    }

    .receipt-paper .receipt-events-table-wrap {
        display: none !important;
    }

    .receipt-paper .receipt-events-cards {
        display: block !important;
        padding: 1mm !important;
    }

    .receipt-paper .receipt-event-card {
        border: 1px solid #777 !important;
        border-radius: 0 !important;
        margin-bottom: 1.2mm !important;
        padding: 1.2mm !important;
    }

    .receipt-paper .receipt-event-head {
        border-bottom: 1px dashed #888 !important;
        padding-bottom: 0.8mm !important;
        margin-bottom: 0.8mm !important;
    }

    .receipt-paper .receipt-event-grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 0.6mm 1mm !important;
    }

    .receipt-paper .text-warning,
    .receipt-paper .text-success,
    .receipt-paper .text-danger,
    .receipt-paper .text-muted,
    .receipt-paper .text-primary {
        color: #000 !important;
    }

    .receipt-paper .badge {
        border: 1px solid #555 !important;
        background: #fff !important;
        color: #000 !important;
        padding: 0.5mm 1mm !important;
    }

    .receipt-paper hr {
        border-top: 1px dashed #777 !important;
        margin: 1.5mm 0 !important;
    }

    .receipt-paper .d-flex {
        gap: 1mm !important;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 no-print flex-wrap gap-2">
    <a href="<?= h($backUrl) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Zpět
    </a>
    <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
        <i class="fas fa-print me-1"></i>Tisk
    </button>
</div>

<div class="receipt-paper">
<?php if (!empty($onlineBillingRows)): ?>
<div class="card border-warning shadow-sm mb-3">
    <div class="card-header bg-warning text-dark fw-semibold"><i class="fas fa-laptop me-2"></i>Online tréninky</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Datum</th><th>Položka</th><th class="text-end">Částka</th></tr></thead><tbody><?php foreach ($onlineBillingRows as $onlineRow): ?><tr><td><?= h(formatDate((string)$onlineRow['billing_date'])) ?></td><td><?= h((string)$onlineRow['description']) ?></td><td class="text-end fw-semibold"><?= number_format((float)$onlineRow['amount'], 0, ',', ' ') ?> Kč</td></tr><?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h2 class="h4 mb-1"><i class="fas fa-receipt me-2 text-warning"></i><?= h($pageTitle) ?></h2>
                <div class="text-muted">Rozpis fakturovaných tréninků, sazeb a částky.</div>
            </div>
            <span class="badge bg-<?= h($statusClass) ?> fs-6"><?= h($statusLabel) ?></span>
        </div>

        <div class="row g-3 mt-2">
            <div class="col-md-4">
                <div class="small text-muted">Sportovec</div>
                <div class="fw-semibold"><?= h($athleteName) ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Trenér</div>
                <div class="fw-semibold"><?= h($coachName) ?></div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Období</div>
                <div class="fw-semibold"><?= h($monthLabel) ?></div>
            </div>
        </div>

        <?php if ($paymentRow && !empty($paymentRow['paid_at'])): ?>
            <div class="small text-muted mt-2">Uhrazeno dne: <?= h(formatDateTime((string)$paymentRow['paid_at'])) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-light fw-semibold">Rozpis tréninků</div>
    <div class="card-body p-0">
        <?php if (empty($eventRows)): ?>
            <div class="p-3 text-muted">V tomto období nejsou žádné schválené tréninky k fakturaci.</div>
        <?php else: ?>
            <div class="receipt-events-table-wrap">
                <table class="table table-sm align-middle mb-0 receipt-events-table">
                    <thead class="table-light">
                        <tr>
                            <th>Datum</th>
                            <th>Čas</th>
                            <th>Místo</th>
                            <th>Typ</th>
                            <th>Náhradní</th>
                            <th>Částka</th>
                            <th>Poznámka</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventRows as $index => $event): ?>
                            <?php $rowCharge = $rowCharges[$index]['amount'] ?? 0.0; $rowNote = $rowCharges[$index]['note'] ?? ''; ?>
                            <tr>
                                <td><?= h(formatDate((string)$event['starts_at'])) ?></td>
                                <td><?= h(date('H:i', strtotime((string)$event['starts_at']))) ?> - <?= h(date('H:i', strtotime((string)$event['ends_at']))) ?></td>
                                <td><?= h((string)($event['location'] ?? '') !== '' ? (string)$event['location'] : '—') ?></td>
                                <td><?= ((int)$event['is_paired'] === 1) ? 'Párový' : 'Individuální' ?></td>
                                <td><?= ((int)($event['is_makeup_session'] ?? 0) === 1) ? 'Ano' : 'Ne' ?></td>
                                <td><?= number_format((float)$rowCharge, 0, ',', ' ') ?> Kč</td>
                                <td><?= h($rowNote) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="receipt-events-cards">
                <?php foreach ($eventRows as $index => $event): ?>
                    <?php
                    $rowCharge = $rowCharges[$index]['amount'] ?? 0.0;
                    $rowNote = $rowCharges[$index]['note'] ?? '';
                    ?>
                    <div class="receipt-event-card">
                        <div class="receipt-event-head">
                            <div class="fw-semibold"><?= h(formatDate((string)$event['starts_at'])) ?></div>
                            <div class="receipt-event-amount"><?= number_format((float)$rowCharge, 0, ',', ' ') ?> Kč</div>
                        </div>
                        <div class="receipt-event-grid">
                            <div class="receipt-event-item">
                                <span class="receipt-event-label">Čas</span>
                                <div class="receipt-event-value"><?= h(date('H:i', strtotime((string)$event['starts_at']))) ?> - <?= h(date('H:i', strtotime((string)$event['ends_at']))) ?></div>
                            </div>
                            <div class="receipt-event-item">
                                <span class="receipt-event-label">Místo</span>
                                <div class="receipt-event-value"><?= h((string)($event['location'] ?? '') !== '' ? (string)$event['location'] : '—') ?></div>
                            </div>
                            <div class="receipt-event-item">
                                <span class="receipt-event-label">Typ</span>
                                <div class="receipt-event-value"><?= ((int)$event['is_paired'] === 1) ? 'Párový' : 'Individuální' ?></div>
                            </div>
                            <div class="receipt-event-item">
                                <span class="receipt-event-label">Náhradní</span>
                                <div class="receipt-event-value"><?= ((int)($event['is_makeup_session'] ?? 0) === 1) ? 'Ano' : 'Ne' ?></div>
                            </div>
                        </div>
                        <?php if ($rowNote !== ''): ?>
                            <div class="receipt-event-note"><?= h($rowNote) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-light fw-semibold">Výpočet částky</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="small text-muted">Celkem tréninků</div>
                <div class="fw-semibold"><?= (int)$breakdown['raw_total'] ?></div>
            </div>
            <div class="col-md-6">
                <div class="small text-muted">Zápočet z náhrad</div>
                <div class="fw-semibold text-warning">-<?= (int)$breakdown['carryover_applied'] ?></div>
            </div>
            <div class="col-md-6">
                <div class="small text-muted">Individuální (k úhradě)</div>
                <div class="fw-semibold"><?= (int)$breakdown['billable_single'] ?> × <?= $breakdown['single_rate'] !== null ? number_format((float)$breakdown['single_rate'], 0, ',', ' ') . ' Kč' : 'nenastaveno' ?></div>
            </div>
            <div class="col-md-6">
                <div class="small text-muted">Párové (k úhradě)</div>
                <div class="fw-semibold"><?= (int)$breakdown['billable_paired'] ?> × <?= $breakdown['paired_rate'] !== null ? number_format((float)$breakdown['paired_rate'], 0, ',', ' ') . ' Kč' : 'nenastaveno' ?></div>
            </div>
            <div class="col-md-6">
                <div class="small text-muted">Náhradní termíny v období</div>
                <div class="fw-semibold"><?= $makeupSessions ?>x</div>
            </div>
            <div class="col-md-6">
                <div class="small text-muted">Zaúčtováno mimo kalendářní měsíc</div>
                <div class="fw-semibold"><?= $transferredSessions ?>x</div>
            </div>
        </div>

        <hr>

        <?php if ($displayAmount !== null): ?>
            <div class="d-flex justify-content-between align-items-center">
                <div class="fw-semibold">Fakturovaná částka</div>
                <div class="fs-5 fw-bold"><?= number_format((float)$displayAmount, 0, ',', ' ') ?> Kč</div>
            </div>
            <?php if ((float)$displayAmount <= 0.0001): ?>
                <div class="small text-success mt-2">V tomto období vychází fakturovaná částka na 0 Kč (po zápočtu náhrad).</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-warning mb-0">Nelze spočítat částku, protože není nastavená sazba za trénink.</div>
        <?php endif; ?>

        <?php if (!$usesSnapshot && $paymentRow && ($paymentRow['status'] ?? '') === 'paid' && $breakdown['computed_amount'] !== null): ?>
            <?php $delta = abs((float)$paymentRow['billed_amount'] - (float)$breakdown['computed_amount']); ?>
            <?php if ($delta > 0.009): ?>
                <div class="small text-danger mt-2">Poznámka: evidovaná uhrazená částka se liší od aktuálního přepočtu kalendáře.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</div>

<?php
if ($coachMode) {
    renderFooter();
} else {
    renderAthleteFooter();
}
?>
