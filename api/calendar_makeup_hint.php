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
$athleteId = (int)($input['athlete_id'] ?? 0);
$startsAtRaw = trim((string)($input['starts_at'] ?? ''));

if ($athleteId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Chybí sportovec']);
    exit;
}

$start = DateTime::createFromFormat('Y-m-d\TH:i', $startsAtRaw);
if (!$start) {
    echo json_encode(['success' => false, 'error' => 'Neplatný začátek tréninku']);
    exit;
}

$targetMonthSql = $start->format('Y-m-01');
$currentMonthSql = (new DateTime('now'))->format('Y-m-01');
$carryoverCutoffSql = strcmp($targetMonthSql, $currentMonthSql) < 0 ? $targetMonthSql : $currentMonthSql;

$pdo = getDB();

function makeupHintTableExists(PDO $pdo, string $tableName): bool
{
    $quoted = $pdo->quote($tableName);
    $stmt = $pdo->query("SHOW TABLES LIKE {$quoted}");
    return $stmt !== false && (bool)$stmt->fetchColumn();
}

function makeupHintColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $quoted = $pdo->quote($columnName);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quoted}");
    return $stmt !== false && (bool)$stmt->fetch();
}

if (!makeupHintTableExists($pdo, 'athlete_monthly_payments') || !makeupHintTableExists($pdo, 'coach_calendar_events')) {
    echo json_encode([
        'success' => true,
        'has_outstanding' => false,
        'outstanding_sessions' => 0,
        'target_month' => $targetMonthSql,
        'target_month_label' => date('m/Y', strtotime($targetMonthSql)),
    ]);
    exit;
}

$hasBillingMonth = makeupHintColumnExists($pdo, 'coach_calendar_events', 'billing_month');
$hasSecondAthlete = makeupHintColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
$hasCarryoverUsed = makeupHintColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions');

$athleteStmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
$athleteStmt->execute([$athleteId, $coachId]);
if (!$athleteStmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Sportovec nepatří tomuto trenérovi']);
    exit;
}

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

$outstanding = 0;
foreach ($paymentStmt->fetchAll() as $row) {
    $month = (string)$row['billing_month'];
    $planned = max(0, (int)($row['planned_sessions'] ?? 0));
    $actual = max(0, (int)($actualByMonth[$month] ?? 0));
    $generated = max(0, $planned - $actual);
    $used = max(0, (int)($row['carryover_used_sessions'] ?? 0));

    $outstanding += $generated;
    $outstanding = max(0, $outstanding - $used);
}

echo json_encode([
    'success' => true,
    'has_outstanding' => $outstanding > 0,
    'outstanding_sessions' => $outstanding,
    'target_month' => $targetMonthSql,
    'target_month_label' => date('m/Y', strtotime($targetMonthSql)),
]);
