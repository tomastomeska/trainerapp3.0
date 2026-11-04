<?php
$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    $secret = getCronSecret();
    $provided = (string)($_GET['secret'] ?? '');
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Unauthorized - neplatny secret token.');
    }
}

header('Content-Type: application/json; charset=utf-8');

function paymentIssueColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($column));
    return $stmt !== false && (bool)$stmt->fetch();
}

function paymentIssueIndexes(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW INDEX FROM `' . $table . '`');
    $indexes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string)$row['Key_name'];
        $indexes[$name]['non_unique'] = (int)$row['Non_unique'];
        $indexes[$name]['columns'][(int)$row['Seq_in_index']] = (string)$row['Column_name'];
    }
    foreach ($indexes as &$index) {
        ksort($index['columns']);
        $index['columns'] = array_values($index['columns']);
    }
    unset($index);
    return $indexes;
}

try {
    $pdo = getDB();

    if (!paymentIssueColumnExists($pdo, 'coach_calendar_events', 'billing_month')) {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN billing_month DATE NULL AFTER is_makeup_session');
        $pdo->exec("UPDATE coach_calendar_events SET billing_month = DATE_FORMAT(starts_at, '%Y-%m-01') WHERE billing_month IS NULL");
    }
    if (!paymentIssueColumnExists($pdo, 'coach_calendar_events', 'billing_issue_number')) {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN billing_issue_number INT NOT NULL DEFAULT 1 AFTER billing_month');
    }
    if (!paymentIssueColumnExists($pdo, 'coach_calendar_events', 'billing_transfer_reason')) {
        $pdo->exec("ALTER TABLE coach_calendar_events ADD COLUMN billing_transfer_reason ENUM('pending_not_included','paid_not_paid') NULL DEFAULT NULL AFTER billing_issue_number");
    }

    if (!paymentIssueColumnExists($pdo, 'athlete_monthly_payments', 'issue_number')) {
        $pdo->exec('ALTER TABLE athlete_monthly_payments ADD COLUMN issue_number INT NOT NULL DEFAULT 1 AFTER billing_month');
    }

    $indexes = paymentIssueIndexes($pdo, 'athlete_monthly_payments');
    $targetColumns = ['coach_id', 'athlete_id', 'billing_month', 'issue_number'];
    $hasTargetUnique = false;
    foreach ($indexes as $name => $index) {
        if ((int)$index['non_unique'] === 0 && $index['columns'] === $targetColumns) {
            $hasTargetUnique = true;
        }
    }

    foreach ($indexes as $name => $index) {
        if ((int)$index['non_unique'] === 0
            && $index['columns'] === ['coach_id', 'athlete_id', 'billing_month']) {
            $pdo->exec('ALTER TABLE athlete_monthly_payments DROP INDEX `' . str_replace('`', '', $name) . '`');
        }
    }
    if (!$hasTargetUnique) {
        $pdo->exec('ALTER TABLE athlete_monthly_payments ADD UNIQUE KEY uq_athlete_monthly_payment_issue (coach_id, athlete_id, billing_month, issue_number)');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Cisla vyzev a vazba udalosti na vyzvu byly pripraveny.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}