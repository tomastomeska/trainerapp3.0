<?php

require_once __DIR__ . '/online_training.php';

function paymentBillingMonthAdjective(string $month): string
{
    $timestamp = strtotime($month);
    if ($timestamp === false) {
        return 'měsíční';
    }
    $forms = [
        1 => 'lednové',
        2 => 'únorové',
        3 => 'březnové',
        4 => 'dubnové',
        5 => 'květnové',
        6 => 'červnové',
        7 => 'červencové',
        8 => 'srpnové',
        9 => 'zářijové',
        10 => 'říjnové',
        11 => 'listopadové',
        12 => 'prosincové',
    ];
    return $forms[(int)date('n', $timestamp)] ?? 'měsíční';
}

function paymentBillingMonthGenitive(string $month): string
{
    $timestamp = strtotime($month);
    if ($timestamp === false) {
        return 'měsíci';
    }
    $forms = [
        1 => 'lednu',
        2 => 'únoru',
        3 => 'březnu',
        4 => 'dubnu',
        5 => 'květnu',
        6 => 'červnu',
        7 => 'červenci',
        8 => 'srpnu',
        9 => 'září',
        10 => 'říjnu',
        11 => 'listopadu',
        12 => 'prosinci',
    ];
    return $forms[(int)date('n', $timestamp)] ?? 'měsíci';
}

function paymentBillingColumnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ' . $pdo->quote($column));
        return $stmt !== false && (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function paymentBillingEffectiveMonthExpression(string $alias, bool $hasBillingMonth, bool $hasTransferReason): string
{
    $calendarMonth = "DATE_FORMAT({$alias}.starts_at, '%Y-%m-01')";
    if (!$hasBillingMonth) {
        return $calendarMonth;
    }

    $billingMonth = "DATE_FORMAT(COALESCE({$alias}.billing_month, {$alias}.starts_at), '%Y-%m-01')";
    if (!$hasTransferReason) {
        return $billingMonth;
    }

    return "CASE
        WHEN {$alias}.billing_transfer_reason IS NULL OR {$alias}.billing_transfer_reason = '' THEN {$calendarMonth}
        ELSE {$billingMonth}
    END";
}

function paymentFindActiveReplacementCancellation(PDO $pdo, int $coachId, int $athleteId, ?int $eventId = null): ?array
{
    if (!paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
        || !paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at')
        || !paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')) {
        return null;
    }

    $hasSecondAthlete = paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'second_athlete_id');
    $participantWhere = $hasSecondAthlete
        ? '(c.athlete_id = ? OR c.second_athlete_id = ?)'
        : 'c.athlete_id = ?';
    $snapshotSelect = paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot')
        ? 'c.payment_status_snapshot'
        : "'none' AS payment_status_snapshot";
    $billingMonthSelect = paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
        ? 'c.billing_month'
        : 'NULL AS billing_month';
    $billingIssueSelect = paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_issue_number')
        ? 'c.billing_issue_number'
        : '1 AS billing_issue_number';

    $replacementWhere = $eventId !== null
        ? 'c.replacement_event_id = ?'
        : '(c.replacement_event_id IS NULL AND c.replacement_deadline_at > NOW())';
    $revokedWhere = paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_revoked_at')
        ? 'AND c.replacement_revoked_at IS NULL'
        : '';
    $sql = "SELECT c.id, c.starts_at AS canceled_starts_at, {$billingMonthSelect}, {$billingIssueSelect}, {$snapshotSelect}, c.replacement_deadline_at
            FROM coach_calendar_event_cancellations c
            WHERE c.coach_id = ?
              AND {$participantWhere}
              AND c.canceled_by IN ('athlete', 'coach')
              AND c.replacement_required = 1
              {$revokedWhere}
              AND {$replacementWhere}
            ORDER BY c.replacement_deadline_at ASC, c.id ASC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $params = [$coachId, $athleteId];
    if ($hasSecondAthlete) {
        $params[] = $athleteId;
    }
    if ($eventId !== null) {
        $params[] = $eventId;
    }
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function paymentIssueRows(PDO $pdo, int $coachId, int $athleteId, string $month): array
{
    $stmt = $pdo->prepare(
        'SELECT issue_number, session_rate, planned_sessions, billed_amount, status
         FROM athlete_monthly_payments
         WHERE coach_id = ? AND athlete_id = ? AND billing_month = ?
         ORDER BY issue_number DESC'
    );
    $stmt->execute([$coachId, $athleteId, $month]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function paymentNextOpenBillingMonth(PDO $pdo, int $coachId, array $athleteIds, string $afterMonth): string
{
    $month = new DateTimeImmutable($afterMonth);
    for ($offset = 0; $offset < 24; $offset++) {
        $month = $month->modify('first day of next month');
        $candidate = $month->format('Y-m-01');
        $locked = false;
        foreach ($athleteIds as $athleteId) {
            foreach (paymentIssueRows($pdo, $coachId, (int)$athleteId, $candidate) as $payment) {
                if (in_array((string)$payment['status'], ['pending', 'paid'], true)) {
                    $locked = true;
                    break 2;
                }
            }
        }
        if (!$locked) {
            return $candidate;
        }
    }
    throw new RuntimeException('Nepodařilo se najít další volný měsíc pro výzvu.');
}

function paymentResolveTrainingAssignment(PDO $pdo, int $coachId, array $athleteIds, string $calendarMonth, string $decision = ''): array
{
    $athleteIds = array_values(array_unique(array_filter(array_map('intval', $athleteIds))));
    $contexts = [];
    foreach ($athleteIds as $athleteId) {
        $rows = paymentIssueRows($pdo, $coachId, $athleteId, $calendarMonth);
        $pending = array_values(array_filter($rows, static fn(array $row): bool => (string)$row['status'] === 'pending'));
        $paid = array_values(array_filter($rows, static fn(array $row): bool => (string)$row['status'] === 'paid'));
        $selected = $pending[0] ?? $paid[0] ?? null;
        if ($selected !== null) {
            $contexts[] = [
                'athlete_id' => $athleteId,
                'kind' => $pending !== [] ? 'pending' : 'paid',
                'payment' => $selected,
                'rows' => $rows,
            ];
        }
    }

    $kinds = array_values(array_unique(array_column($contexts, 'kind')));
    if ($kinds === []) {
        return [
            'requires_choice' => false,
            'billing_month' => $calendarMonth,
            'issue_number' => 1,
            'additional_athletes' => [],
            'billing_transfer_reason' => null,
        ];
    }
    if (count($contexts) !== count($athleteIds)) {
        return ['error' => 'Účastníci párového tréninku nemají stejný stav výzvy. Rozdělte událost nebo sjednoťte výzvy.'];
    }
    if (count($kinds) !== 1) {
        return [
            'error' => 'Párový trénink zasahuje do různých stavů výzev. Rozdělte jej na samostatné události, aby bylo možné výzvy zachovat beze změny.',
        ];
    }

    $kind = $kinds[0];
    $issueNumbers = array_values(array_unique(array_map(
        static fn(array $context): int => (int)$context['payment']['issue_number'],
        $contexts
    )));
    if (count($issueNumbers) !== 1) {
        return ['error' => 'Účastníci párového tréninku mají v tomto měsíci různá čísla výzev. Trénink nebyl uložen.'];
    }
    $issueNumber = $issueNumbers[0];
    $samplePayment = $contexts[0]['payment'];
    $rate = $samplePayment['session_rate'] !== null ? (float)$samplePayment['session_rate'] : 0.0;
    $sessionRates = [];
    foreach ($contexts as $context) {
        $storedRate = $context['payment']['session_rate'] ?? null;
        $sessionRates[(int)$context['athlete_id']] = $storedRate !== null ? (float)$storedRate : null;
    }

    if ($decision === '') {
        $monthAdjective = paymentBillingMonthAdjective($calendarMonth);
        $nextMonth = paymentNextOpenBillingMonth($pdo, $coachId, $athleteIds, $calendarMonth);
        $nextMonthLabel = date('m/Y', strtotime($nextMonth));
        return [
            'requires_choice' => true,
            'choice' => [
                'kind' => $kind,
                'billing_month' => $calendarMonth,
                'issue_number' => $issueNumber,
                'current_amount' => (float)$samplePayment['billed_amount'],
                'current_sessions' => (int)$samplePayment['planned_sessions'],
                'session_rate' => $rate,
                'next_amount' => $kind === 'pending'
                    ? (float)$samplePayment['billed_amount'] + $rate
                    : $rate,
                'next_sessions' => $kind === 'pending'
                    ? (int)$samplePayment['planned_sessions'] + 1
                    : 1,
                'next_month' => $nextMonth,
                'options' => $kind === 'pending'
                    ? [
                        ['value' => 'include', 'label' => 'Přidat do ' . $monthAdjective . ' výzvy'],
                        ['value' => 'defer', 'label' => 'Účtovat v dalším otevřeném měsíci'],
                    ]
                    : [
                        ['value' => 'additional', 'label' => 'Vystavit dodatečnou výzvu'],
                        ['value' => 'defer', 'label' => 'Přesunout k výzvě za ' . $nextMonthLabel],
                    ],
            ],
        ];
    }

    if ($kind === 'pending') {
        if (!in_array($decision, ['include', 'defer'], true)) {
            return ['error' => 'Zvolte, zda trénink patří do čekající výzvy, nebo do dalšího měsíce.'];
        }
        if ($decision === 'include') {
            return [
                'requires_choice' => false,
                'billing_month' => $calendarMonth,
                'issue_number' => $issueNumber,
                'refresh_athletes' => array_column($contexts, 'athlete_id'),
                'billing_transfer_reason' => null,
            ];
        }
    } else {
        if (!in_array($decision, ['additional', 'defer'], true)) {
            return ['error' => 'Zvolte, zda vytvořit dodatečnou výzvu, nebo účtovat trénink v dalším měsíci.'];
        }
        if ($decision === 'additional') {
            $latestIssue = 0;
            foreach ($contexts as $context) {
                foreach ($context['rows'] as $row) {
                    $latestIssue = max($latestIssue, (int)$row['issue_number']);
                }
            }
            return [
                'requires_choice' => false,
                'billing_month' => $calendarMonth,
                'issue_number' => $latestIssue + 1,
                'additional_athletes' => array_column($contexts, 'athlete_id'),
                'session_rate' => $rate,
                'session_rates' => $sessionRates,
                'billing_transfer_reason' => null,
            ];
        }
    }

    return [
        'requires_choice' => false,
        'billing_month' => paymentNextOpenBillingMonth($pdo, $coachId, $athleteIds, $calendarMonth),
        'issue_number' => 1,
        'refresh_athletes' => [],
        'additional_athletes' => [],
        'billing_transfer_reason' => $kind === 'pending' ? 'pending_not_included' : 'paid_not_paid',
    ];
}

function calculateAthleteMonthlyBill(PDO $pdo, int $coachId, int $athleteId, string $billingMonth, array $additionalSessions = [], int $issueNumber = 1, ?float $sessionRateOverride = null, bool $includeOnlineBilling = true): array
{
    $hasSecondAthlete = paymentBillingColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
    $hasBillingMonth = paymentBillingColumnExists($pdo, 'coach_calendar_events', 'billing_month');
    $hasMakeup = paymentBillingColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session');
    $monthExpr = "DATE_FORMAT(e.starts_at, '%Y-%m-01')";
    $billingMonthExpr = $hasBillingMonth
        ? "DATE_FORMAT(COALESCE(e.billing_month, e.starts_at), '%Y-%m-01')"
        : $monthExpr;
    $participantWhere = $hasSecondAthlete ? '(e.athlete_id = ? OR e.second_athlete_id = ?)' : 'e.athlete_id = ?';
    $calendarSql = "DATE_FORMAT(e.starts_at, '%Y-%m-01')";
    $reasonSql = paymentBillingColumnExists($pdo, 'coach_calendar_events', 'billing_transfer_reason')
        ? 'e.billing_transfer_reason'
        : "''";
    $issueSql = paymentBillingColumnExists($pdo, 'coach_calendar_events', 'billing_issue_number')
        ? 'COALESCE(e.billing_issue_number, 1)'
        : '1';
    $memberSql = "(
        ({$calendarSql} = ? AND ({$billingMonthExpr} = ? OR {$reasonSql} IS NULL OR {$reasonSql} = ''))
        OR ({$billingMonthExpr} = ? AND {$reasonSql} IN ('pending_not_included', 'paid_not_paid') AND {$calendarSql} <> ?)
    )";
    $params = [$coachId, $athleteId];
    if ($hasSecondAthlete) {
        $params[] = $athleteId;
    }
    array_push($params, $billingMonth, $billingMonth, $billingMonth, $billingMonth, $issueNumber);
    $makeupExpr = $hasMakeup ? 'COALESCE(e.is_makeup_session, 0)' : '0';
    $pairedExpr = $hasSecondAthlete ? 'CASE WHEN e.second_athlete_id IS NOT NULL THEN 1 ELSE 0 END' : '0';
    $hasPaidReplacementLinks = paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
        && paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot');
    $paidReplacementExpr = $hasPaidReplacementLinks
        ? "EXISTS (
                SELECT 1 FROM coach_calendar_event_cancellations c
                WHERE c.coach_id = e.coach_id
                  AND c.replacement_event_id = e.id
                  AND c.payment_status_snapshot IN ('pending', 'paid')
            )"
        : '0';
    $transferredMakeupExpr = $hasPaidReplacementLinks
        ? "SUM(CASE WHEN {$paidReplacementExpr} THEN 1 ELSE 0 END)"
        : '0';
    $eventsStmt = $pdo->prepare(
        "SELECT COUNT(*) AS billed_sessions,
                SUM(CASE WHEN {$pairedExpr} = 1 THEN 1 ELSE 0 END) AS paired_sessions,
                SUM(CASE WHEN {$pairedExpr} = 0 THEN 1 ELSE 0 END) AS single_sessions,
                SUM(CASE WHEN {$makeupExpr} = 1 THEN 1 ELSE 0 END) AS makeup_sessions,
                SUM(CASE WHEN DATE_FORMAT(e.starts_at, '%Y-%m-01') <> {$monthExpr} THEN 1 ELSE 0 END) AS transferred_sessions,
                {$transferredMakeupExpr} AS transferred_makeup_sessions,
                SUM(CASE WHEN {$paidReplacementExpr} AND {$pairedExpr} = 0 THEN 1 ELSE 0 END) AS paid_replacement_single_sessions,
                SUM(CASE WHEN {$paidReplacementExpr} AND {$pairedExpr} = 1 THEN 1 ELSE 0 END) AS paid_replacement_paired_sessions
         FROM coach_calendar_events e
         WHERE e.coach_id = ?
           AND e.approval_status = 'approved'
           AND {$participantWhere}
           AND {$memberSql}
           AND {$issueSql} = ?"
    );
    $eventsStmt->execute($params);
    $row = $eventsStmt->fetch() ?: [];
    $stats = [
        'billed_sessions' => (int)($row['billed_sessions'] ?? 0),
        'single_sessions' => (int)($row['single_sessions'] ?? 0),
        'paired_sessions' => (int)($row['paired_sessions'] ?? 0),
        'makeup_sessions' => (int)($row['makeup_sessions'] ?? 0),
        'transferred_sessions' => (int)($row['transferred_sessions'] ?? 0),
        'transferred_makeup_sessions' => (int)($row['transferred_makeup_sessions'] ?? 0),
        'paid_replacement_single_sessions' => (int)($row['paid_replacement_single_sessions'] ?? 0),
        'paid_replacement_paired_sessions' => (int)($row['paid_replacement_paired_sessions'] ?? 0),
    ];

    foreach ($additionalSessions as $session) {
        $isPaired = !empty($session['is_paired']);
        $isMakeup = !empty($session['is_makeup']);
        $stats['billed_sessions']++;
        $stats[$isPaired ? 'paired_sessions' : 'single_sessions']++;
        if (!empty($session['is_makeup'])) {
            $stats['makeup_sessions']++;
        }
        if (($session['calendar_month'] ?? $billingMonth) !== $billingMonth) {
            $stats['transferred_sessions']++;
            if ($isMakeup) {
                $stats['transferred_makeup_sessions']++;
            }
        }
    }

    $sessionCount = $stats['single_sessions'] + $stats['paired_sessions'];
    $paidReplacementSingle = min($stats['single_sessions'], $stats['paid_replacement_single_sessions']);
    $paidReplacementPaired = min($stats['paired_sessions'], $stats['paid_replacement_paired_sessions']);
    $carryoverUsed = min($sessionCount, $paidReplacementSingle + $paidReplacementPaired);
    $billableSingle = max(0, $stats['single_sessions'] - $paidReplacementSingle);
    $billablePaired = max(0, $stats['paired_sessions'] - $paidReplacementPaired);

    $rateStmt = $pdo->prepare(
        paymentBillingColumnExists($pdo, 'athletes', 'paired_training_rate')
            ? 'SELECT training_rate, paired_training_rate FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1'
            : 'SELECT training_rate, NULL AS paired_training_rate FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1'
    );
    $rateStmt->execute([$athleteId, $coachId]);
    $rates = $rateStmt->fetch() ?: [];
    $singleRate = $sessionRateOverride ?? (($rates['training_rate'] ?? null) !== null ? (float)$rates['training_rate'] : null);
    $pairedRate = $sessionRateOverride ?? (($rates['paired_training_rate'] ?? null) !== null ? (float)$rates['paired_training_rate'] : $singleRate);
    $amount = $singleRate !== null && $pairedRate !== null
        ? ($billableSingle * $singleRate) + ($billablePaired * $pairedRate)
        : null;
    if ($amount !== null && $includeOnlineBilling && $issueNumber === 1) {
        $amount += onlineTrainingBillingAmount($pdo, $coachId, $athleteId, $billingMonth);
    }

    return [
        'stats' => $stats,
        'carryover_used' => $carryoverUsed,
        'planned_sessions' => $billableSingle + $billablePaired,
        'billed_amount' => $amount,
        'session_rate' => $singleRate,
    ];
}

function paymentBuildReceiptSnapshot(PDO $pdo, int $coachId, int $athleteId, string $billingMonth, int $issueNumber = 1, ?float $sessionRateOverride = null, ?float $displayAmountOverride = null): ?string
{
    $hasSecondAthlete = paymentBillingColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
    $hasBillingMonth = paymentBillingColumnExists($pdo, 'coach_calendar_events', 'billing_month');
    $hasTransferReason = paymentBillingColumnExists($pdo, 'coach_calendar_events', 'billing_transfer_reason');
    $hasBillingIssue = paymentBillingColumnExists($pdo, 'coach_calendar_events', 'billing_issue_number');
    $hasMakeup = paymentBillingColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session');
    $monthExpr = paymentBillingEffectiveMonthExpression('e', $hasBillingMonth, $hasTransferReason);
    $issueExpr = $hasBillingIssue ? 'COALESCE(e.billing_issue_number, 1)' : '1';
    $makeupExpr = $hasMakeup ? 'COALESCE(e.is_makeup_session, 0)' : '0';
    $reasonExpr = $hasTransferReason ? 'e.billing_transfer_reason' : "''";
    $participantWhere = $hasSecondAthlete
        ? '(e.athlete_id = ? OR e.second_athlete_id = ?)'
        : 'e.athlete_id = ?';
    $pairedExpr = $hasSecondAthlete
        ? 'CASE WHEN e.second_athlete_id IS NOT NULL THEN 1 ELSE 0 END'
        : '0';

    $stmt = $pdo->prepare(
        "SELECT e.id, e.starts_at, e.ends_at, e.location,
                {$monthExpr} AS billing_month,
                {$issueExpr} AS billing_issue_number,
                {$reasonExpr} AS billing_transfer_reason,
                {$makeupExpr} AS is_makeup_session,
                {$pairedExpr} AS is_paired
         FROM coach_calendar_events e
         WHERE e.coach_id = ?
           AND e.approval_status = 'approved'
           AND {$participantWhere}
           AND {$monthExpr} = ?
           AND {$issueExpr} = ?
         ORDER BY e.starts_at ASC, e.id ASC"
    );
    $params = [$coachId, $athleteId];
    if ($hasSecondAthlete) {
        $params[] = $athleteId;
    }
    array_push($params, $billingMonth, $issueNumber);
    $stmt->execute($params);
    $eventRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $bill = calculateAthleteMonthlyBill(
        $pdo,
        $coachId,
        $athleteId,
        $billingMonth,
        [],
        $issueNumber,
        $sessionRateOverride
    );
    $stats = $bill['stats'];
    $singleSessions = max(0, (int)($stats['single_sessions'] ?? 0));
    $pairedSessions = max(0, (int)($stats['paired_sessions'] ?? 0));
    $paidReplacementSingle = min($singleSessions, max(0, (int)($stats['paid_replacement_single_sessions'] ?? 0)));
    $paidReplacementPaired = min($pairedSessions, max(0, (int)($stats['paid_replacement_paired_sessions'] ?? 0)));
    $hasPairedRate = paymentBillingColumnExists($pdo, 'athletes', 'paired_training_rate');
    $rateStmt = $pdo->prepare(
        $hasPairedRate
            ? 'SELECT training_rate, paired_training_rate FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1'
            : 'SELECT training_rate, NULL AS paired_training_rate FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1'
    );
    $rateStmt->execute([$athleteId, $coachId]);
    $rates = $rateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $singleRate = $sessionRateOverride ?? (($rates['training_rate'] ?? null) !== null ? (float)$rates['training_rate'] : null);
    $pairedRate = $sessionRateOverride ?? (($rates['paired_training_rate'] ?? null) !== null ? (float)$rates['paired_training_rate'] : $singleRate);
    $billableSingle = max(0, $singleSessions - $paidReplacementSingle);
    $billablePaired = max(0, $pairedSessions - $paidReplacementPaired);
    $snapshotAmount = $displayAmountOverride ?? ($bill['billed_amount'] !== null ? (float)$bill['billed_amount'] : null);

    return json_encode([
        'event_rows' => $eventRows,
        'breakdown' => [
            'total_sessions' => $singleSessions + $pairedSessions,
            'carryover_used' => $paidReplacementSingle + $paidReplacementPaired,
            'billable_single_sessions' => $billableSingle,
            'billable_paired_sessions' => $billablePaired,
            'billable_sessions' => $billableSingle + $billablePaired,
            'single_rate' => $singleRate,
            'paired_rate' => $pairedRate,
            'amount' => $snapshotAmount,
        ],
        'display_amount' => $snapshotAmount,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function paymentRefreshPendingIssue(PDO $pdo, int $coachId, int $athleteId, string $billingMonth, int $issueNumber): void
{
    $stmt = $pdo->prepare(
        'SELECT session_rate
         FROM athlete_monthly_payments
         WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = ? AND status = "pending"
         LIMIT 1'
    );
    $stmt->execute([$coachId, $athleteId, $billingMonth, $issueNumber]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        return;
    }

    $sessionRate = $payment['session_rate'] !== null ? (float)$payment['session_rate'] : null;
    $bill = calculateAthleteMonthlyBill($pdo, $coachId, $athleteId, $billingMonth, [], $issueNumber, $sessionRate);
    if ($bill['billed_amount'] === null) {
        throw new RuntimeException('Výzvu se nepodařilo přepočítat, protože chybí sazba.');
    }
    $receiptSnapshot = paymentBuildReceiptSnapshot($pdo, $coachId, $athleteId, $billingMonth, $issueNumber, null, (float)$bill['billed_amount']);

    $update = $pdo->prepare(
        'UPDATE athlete_monthly_payments
         SET planned_sessions = ?, carryover_used_sessions = ?, billed_amount = ?, receipt_snapshot_json = ?
         WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = ? AND status = "pending"'
    );
    $update->execute([
        $bill['planned_sessions'],
        $bill['carryover_used'],
        number_format((float)$bill['billed_amount'], 2, '.', ''),
        $receiptSnapshot,
        $coachId,
        $athleteId,
        $billingMonth,
        $issueNumber,
    ]);
}

function paymentCreateAdditionalIssue(PDO $pdo, int $coachId, int $athleteId, string $billingMonth, int $issueNumber, ?float $sessionRate): void
{
    paymentEnsureBillingIssueSchema($pdo);
    $bill = calculateAthleteMonthlyBill($pdo, $coachId, $athleteId, $billingMonth, [], $issueNumber, $sessionRate, false);
    if ($bill['billed_amount'] === null) {
        throw new RuntimeException('Dodatečnou výzvu se nepodařilo vytvořit, protože chybí sazba.');
    }
    $receiptSnapshot = paymentBuildReceiptSnapshot($pdo, $coachId, $athleteId, $billingMonth, $issueNumber, $sessionRate, (float)$bill['billed_amount']);
    $insert = $pdo->prepare(
        'INSERT INTO athlete_monthly_payments
            (coach_id, athlete_id, billing_month, issue_number, session_rate, planned_sessions, carryover_used_sessions, billed_amount, status, paid_at, receipt_snapshot_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending", NULL, ?)'
    );
    $insert->execute([
        $coachId,
        $athleteId,
        $billingMonth,
        $issueNumber,
        $sessionRate !== null ? number_format($sessionRate, 2, '.', '') : null,
        $bill['planned_sessions'],
        $bill['carryover_used'],
        number_format((float)$bill['billed_amount'], 2, '.', ''),
        $receiptSnapshot,
    ]);
}

function paymentEnsureBillingIssueSchema(PDO $pdo): void
{
    try {
        $tableStmt = $pdo->query("SHOW TABLES LIKE 'athlete_monthly_payments'");
        if ($tableStmt === false || !$tableStmt->fetchColumn()) {
            return;
        }
        if (!paymentBillingColumnExists($pdo, 'athlete_monthly_payments', 'issue_number')) {
            $pdo->exec('ALTER TABLE athlete_monthly_payments ADD COLUMN issue_number INT NOT NULL DEFAULT 1 AFTER billing_month');
        }
        if (!paymentBillingColumnExists($pdo, 'athlete_monthly_payments', 'receipt_snapshot_json')) {
            $pdo->exec('ALTER TABLE athlete_monthly_payments ADD COLUMN receipt_snapshot_json LONGTEXT NULL');
        }
        $indexStmt = $pdo->query('SHOW INDEX FROM athlete_monthly_payments');
        $indexes = [];
        if ($indexStmt !== false) {
            foreach ($indexStmt->fetchAll(PDO::FETCH_ASSOC) as $indexRow) {
                $name = (string)($indexRow['Key_name'] ?? '');
                if ($name === '' || $name === 'PRIMARY' || (int)($indexRow['Non_unique'] ?? 1) !== 0) {
                    continue;
                }
                $indexes[$name][(int)($indexRow['Seq_in_index'] ?? 0)] = (string)($indexRow['Column_name'] ?? '');
            }
        }
        if (!isset($indexes['uq_athlete_monthly_payment_issue'])) {
            foreach ($indexes as $name => $columns) {
                ksort($columns);
                if (array_values($columns) === ['coach_id', 'athlete_id', 'billing_month']) {
                    $pdo->exec('ALTER TABLE athlete_monthly_payments DROP INDEX `' . str_replace('`', '', $name) . '`');
                }
            }
            $pdo->exec('ALTER TABLE athlete_monthly_payments ADD UNIQUE KEY uq_athlete_monthly_payment_issue (coach_id, athlete_id, billing_month, issue_number)');
        }
    } catch (Throwable $e) {
    }

    try {
        if (paymentBillingColumnExists($pdo, 'coach_calendar_events', 'billing_month')
            && !paymentBillingColumnExists($pdo, 'coach_calendar_events', 'billing_issue_number')) {
            $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN billing_issue_number INT NOT NULL DEFAULT 1 AFTER billing_month');
        }
        if (paymentBillingColumnExists($pdo, 'coach_calendar_events', 'billing_issue_number')
            && !paymentBillingColumnExists($pdo, 'coach_calendar_events', 'billing_transfer_reason')) {
            $pdo->exec("ALTER TABLE coach_calendar_events ADD COLUMN billing_transfer_reason ENUM('pending_not_included','paid_not_paid') NULL DEFAULT NULL AFTER billing_issue_number");
        }
    } catch (Throwable $e) {
    }
}

