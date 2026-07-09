<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

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

function receiptBuildBillable(int $singleSessions, int $pairedSessions, int $carryoverApplied, ?float $singleRate, ?float $pairedRate): array
{
    $totalSessions = max(0, $singleSessions) + max(0, $pairedSessions);
    $carryoverApplied = min(max(0, $carryoverApplied), $totalSessions);

    $billableSingle = max(0, $singleSessions - $carryoverApplied);
    $remainingCarryover = max(0, $carryoverApplied - $singleSessions);
    $billablePaired = max(0, $pairedSessions - $remainingCarryover);

    $effectiveSingleRate = $singleRate;
    $effectivePairedRate = $pairedRate ?? $singleRate;
    $amount = null;

    if ($effectiveSingleRate !== null && $effectivePairedRate !== null) {
        $amount = ($billableSingle * $effectiveSingleRate) + ($billablePaired * $effectivePairedRate);
    }

    return [
        'raw_total' => $totalSessions,
        'carryover_applied' => $carryoverApplied,
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
    $totalSessions = max(0, $totalSessions);
    if ($totalSessions === 0) {
        return 0;
    }

    $fromHistory = min(max(0, $outstandingBefore), $totalSessions);
    $fromTransferred = min(max(0, $transferredSessions), $totalSessions);

    // Sessions billed in another month must not be charged again in this month.
    return max($fromHistory, $fromTransferred);
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

$monthParam = trim((string)($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$monthSql = $monthParam . '-01';

$hasBillingMonth = receiptColumnExists($pdo, 'coach_calendar_events', 'billing_month');
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

$monthExpr = "DATE_FORMAT(e.starts_at, '%Y-%m-01')";
$monthFilter = $monthExpr . ' = ?';
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
               CASE WHEN e.second_athlete_id IS NOT NULL THEN 1 ELSE 0 END AS is_paired
        FROM coach_calendar_events e
        WHERE e.coach_id = ?
          AND e.approval_status = 'approved'
          AND e.athlete_id = ?
          AND {$monthFilter}
        UNION ALL
        SELECT e.id,
               e.starts_at,
               e.ends_at,
               e.location,
               {$isMakeupSelect} AS is_makeup_session,
               {$billingMonthSelect} AS billing_month,
               1 AS is_paired
        FROM coach_calendar_events e
        WHERE e.coach_id = ?
          AND e.approval_status = 'approved'
          AND e.second_athlete_id = ?
          AND {$monthFilter}
        ORDER BY starts_at ASC
    ";
    $eventsParams = [$coachId, $athleteId, $monthSql, $coachId, $athleteId, $monthSql];
} else {
    $eventsSql = "
        SELECT e.id,
               e.starts_at,
               e.ends_at,
               e.location,
               {$isMakeupSelect} AS is_makeup_session,
               {$billingMonthSelect} AS billing_month,
               0 AS is_paired
        FROM coach_calendar_events e
        WHERE e.coach_id = ?
          AND e.approval_status = 'approved'
          AND e.athlete_id = ?
          AND {$monthFilter}
        ORDER BY starts_at ASC
    ";
    $eventsParams = [$coachId, $athleteId, $monthSql];
}

$eventsStmt = $pdo->prepare($eventsSql);
$eventsStmt->execute($eventsParams);
$eventRows = $eventsStmt->fetchAll();

$singleSessions = 0;
$pairedSessions = 0;
$makeupSessions = 0;
$transferredSessions = 0;

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
    $transferredSessions,
    $singleSessions + $pairedSessions
);
$breakdown = receiptBuildBillable($singleSessions, $pairedSessions, $carryoverForMonth, $singleRate, $pairedRate);

$paymentRow = null;
if ($hasPaymentsTable) {
    $paymentStmt = $pdo->prepare(
        'SELECT session_rate, planned_sessions, '
        . ($hasCarryover ? 'carryover_used_sessions' : '0 AS carryover_used_sessions') . ',
        billed_amount, status, paid_at'
    . ($hasReceiptSnapshot ? ', receipt_snapshot_json' : '') . '
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND athlete_id = ?
           AND billing_month = ?
         LIMIT 1'
    );
    $paymentStmt->execute([$coachId, $athleteId, $monthSql]);
    $paymentRow = $paymentStmt->fetch() ?: null;
}

$usesSnapshot = false;
if ($paymentRow && ($paymentRow['status'] ?? '') === 'paid' && $hasReceiptSnapshot) {
    $snapshot = receiptDecodeSnapshot((string)($paymentRow['receipt_snapshot_json'] ?? ''));
    if ($snapshot !== null) {
        $snapshotEvents = $snapshot['event_rows'] ?? null;
        if (is_array($snapshotEvents)) {
            $eventRows = [];
            foreach ($snapshotEvents as $snapshotEvent) {
                if (!is_array($snapshotEvent)) {
                    continue;
                }
                $eventRows[] = [
                    'id' => (int)($snapshotEvent['id'] ?? 0),
                    'starts_at' => (string)($snapshotEvent['starts_at'] ?? ''),
                    'ends_at' => (string)($snapshotEvent['ends_at'] ?? ''),
                    'location' => (string)($snapshotEvent['location'] ?? ''),
                    'billing_month' => (string)($snapshotEvent['billing_month'] ?? ''),
                    'is_makeup_session' => (int)($snapshotEvent['is_makeup_session'] ?? 0),
                    'is_paired' => (int)($snapshotEvent['is_paired'] ?? 0),
                ];
            }
        }

        $singleSessions = max(0, (int)($snapshot['single_sessions'] ?? $singleSessions));
        $pairedSessions = max(0, (int)($snapshot['paired_sessions'] ?? $pairedSessions));
        $makeupSessions = max(0, (int)($snapshot['makeup_sessions'] ?? $makeupSessions));
        $transferredSessions = max(0, (int)($snapshot['transferred_sessions'] ?? $transferredSessions));

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

$carryoverRemaining = max(0, (int)($breakdown['carryover_applied'] ?? $outstandingBefore));
$rowCharges = [];
foreach ($eventRows as $eventRow) {
    $rowRate = ((int)($eventRow['is_paired'] ?? 0) === 1)
        ? ($pairedRate ?? $singleRate)
        : $singleRate;
    $isCarryover = $carryoverRemaining > 0;
    $rowCharges[] = [
        'amount' => ($rowRate !== null && !$isCarryover) ? (float)$rowRate : 0.0,
        'note' => $isCarryover
            ? 'Hrazeno v předešlém období' . (!empty($paymentRow['paid_at']) ? ' (' . date('m/Y', strtotime((string)$paymentRow['paid_at'])) . ')' : '')
            : '',
    ];
    if ($carryoverRemaining > 0) {
        $carryoverRemaining--;
    }
}

$displayAmount = $breakdown['computed_amount'];
if ($paymentRow && ($paymentRow['status'] ?? '') === 'paid' && isset($paymentRow['billed_amount'])) {
    $displayAmount = (float)$paymentRow['billed_amount'];
}

$athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
$monthLabel = date('m/Y', strtotime($monthSql));
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
                                <td><?= h($rowNote !== '' ? $rowNote : (((string)($event['billing_month'] ?? '') !== $monthSql) ? 'Převod z předchozího období' : '')) ?></td>
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
                    $defaultNote = ((string)($event['billing_month'] ?? '') !== $monthSql) ? 'Převod z předchozího období' : '';
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
                        <?php if ($rowNote !== '' || $defaultNote !== ''): ?>
                            <div class="receipt-event-note"><?= h($rowNote !== '' ? $rowNote : $defaultNote) ?></div>
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
