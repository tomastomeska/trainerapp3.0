<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/online_training.php';
require_once __DIR__ . '/includes/payment_billing.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = (int)getCurrentCoachId();
$pdo = getDB();
$onlineBillingRows = [];
try {
    $onlineBillingStmt = $pdo->prepare(
        'SELECT ob.*, a.first_name, a.last_name, ot.sequence_number
         FROM online_training_billing ob
         JOIN athletes a ON a.id = ob.athlete_id
         LEFT JOIN online_trainings ot ON ot.id = ob.online_training_id
         WHERE ob.trainer_id = ? AND DATE_FORMAT(COALESCE(ob.billing_month, ob.billing_date), "%Y-%m") = ?
         ORDER BY ob.billing_date DESC, ob.id DESC'
    );
    $onlineBillingStmt->execute([$coachId, (string)($_GET['month'] ?? date('Y-m'))]);
    $onlineBillingRows = $onlineBillingStmt->fetchAll();
} catch (Throwable $e) {
    $onlineBillingRows = [];
}

function parseBillingMonthValue(?string $raw): DateTimeImmutable
{
    $raw = trim((string)$raw);
    if ($raw !== '' && preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw . '-01 00:00:00');
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }
    }

    return new DateTimeImmutable('first day of next month 00:00:00');
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $quotedTable = $pdo->quote($tableName);
    $stmt = $pdo->query("SHOW TABLES LIKE {$quotedTable}");
    return $stmt !== false && (bool)$stmt->fetchColumn();
}

function isAthleteMonthReleased(int $athleteId, bool $isMonthReleased, array $releasedAthleteIds): bool
{
    return isset($releasedAthleteIds[$athleteId]);
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $quotedColumn = $pdo->quote($columnName);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quotedColumn}");
    return $stmt !== false && (bool)$stmt->fetch();
}

function ensurePaymentsRuntimeSchema(PDO $pdo): array
{
    $warnings = [];

    try {
        if (!columnExists($pdo, 'athletes', 'training_rate')) {
            $pdo->exec('ALTER TABLE athletes ADD COLUMN training_rate DECIMAL(10,2) NULL AFTER email');
        }
    } catch (Throwable $e) {
        $warnings[] = 'Sloupec athletes.training_rate se nepodařilo doplnit.';
    }

    try {
        if (!columnExists($pdo, 'athletes', 'paired_training_rate')) {
            $pdo->exec('ALTER TABLE athletes ADD COLUMN paired_training_rate DECIMAL(10,2) NULL AFTER training_rate');
        }
    } catch (Throwable $e) {
        $warnings[] = 'Sloupec athletes.paired_training_rate se nepodařilo doplnit.';
    }

    try {
        if (!columnExists($pdo, 'coach_calendar_events', 'second_athlete_id')) {
            $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN second_athlete_id INT NULL AFTER athlete_id');
        }
    } catch (Throwable $e) {
        $warnings[] = 'Sloupec coach_calendar_events.second_athlete_id se nepodařilo doplnit.';
    }

    try {
        if (!columnExists($pdo, 'coach_calendar_events', 'is_makeup_session')) {
            $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN is_makeup_session TINYINT(1) NOT NULL DEFAULT 0 AFTER coach_modified_at');
        }
    } catch (Throwable $e) {
        $warnings[] = 'Sloupec coach_calendar_events.is_makeup_session se nepodařilo doplnit.';
    }

    try {
        if (!columnExists($pdo, 'coach_calendar_events', 'billing_month')) {
            $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN billing_month DATE NULL AFTER is_makeup_session');
            $pdo->exec("UPDATE coach_calendar_events SET billing_month = DATE_FORMAT(starts_at, '%Y-%m-01') WHERE billing_month IS NULL");
        }
        if (!columnExists($pdo, 'coach_calendar_events', 'billing_issue_number')) {
            $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN billing_issue_number INT NOT NULL DEFAULT 1 AFTER billing_month');
        }
        if (!columnExists($pdo, 'coach_calendar_events', 'billing_transfer_reason')) {
            $pdo->exec("ALTER TABLE coach_calendar_events ADD COLUMN billing_transfer_reason ENUM('pending_not_included','paid_not_paid') NULL DEFAULT NULL AFTER billing_issue_number");
        }
    } catch (Throwable $e) {
        $warnings[] = 'Sloupec coach_calendar_events.billing_month se nepodařilo doplnit.';
    }

    try {
        if (!columnExists($pdo, 'coaches', 'bank_account')) {
            $pdo->exec('ALTER TABLE coaches ADD COLUMN bank_account VARCHAR(64) NULL AFTER email');
        }
    } catch (Throwable $e) {
        $warnings[] = 'Sloupec coaches.bank_account se nepodařilo doplnit.';
    }

    try {
        $pdo->exec(" 
            CREATE TABLE IF NOT EXISTS `athlete_monthly_payments` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `coach_id`         INT NOT NULL,
                `athlete_id`       INT NOT NULL,
                `billing_month`    DATE NOT NULL,
                `issue_number`     INT NOT NULL DEFAULT 1,
                `session_rate`     DECIMAL(10,2) NULL,
                `planned_sessions` INT NOT NULL DEFAULT 0,
                `billed_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0,
                `status`           ENUM('pending','paid') NOT NULL DEFAULT 'pending',
                `paid_at`          DATETIME NULL,
                `note`             TEXT NULL,
                `receipt_snapshot_json` LONGTEXT NULL,
                `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_athlete_monthly_payment_issue` (`coach_id`, `athlete_id`, `billing_month`, `issue_number`),
                KEY `idx_athlete_monthly_payment_month` (`coach_id`, `billing_month`, `status`),
                CONSTRAINT `fk_athlete_monthly_payment_coach`
                    FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_athlete_monthly_payment_athlete`
                    FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        if (!columnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions')) {
            $pdo->exec('ALTER TABLE athlete_monthly_payments ADD COLUMN carryover_used_sessions INT NOT NULL DEFAULT 0 AFTER planned_sessions');
        }
        if (!columnExists($pdo, 'athlete_monthly_payments', 'receipt_snapshot_json')) {
            $pdo->exec('ALTER TABLE athlete_monthly_payments ADD COLUMN receipt_snapshot_json LONGTEXT NULL');
        }
        paymentEnsureBillingIssueSchema($pdo);
    } catch (Throwable $e) {
        $warnings[] = 'Tabulku athlete_monthly_payments se nepodařilo vytvořit.';
    }

    try {
        $pdo->exec(" 
            CREATE TABLE IF NOT EXISTS `coach_billing_months` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `coach_id`      INT NOT NULL,
                `billing_month` DATE NOT NULL,
                `status`        ENUM('draft','released') NOT NULL DEFAULT 'draft',
                `released_at`   DATETIME NULL,
                `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_coach_billing_month` (`coach_id`, `billing_month`),
                KEY `idx_coach_billing_month_status` (`coach_id`, `status`, `billing_month`),
                CONSTRAINT `fk_coach_billing_month_coach`
                    FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        $warnings[] = 'Tabulku coach_billing_months se nepodařilo vytvořit.';
    }

    try {
        $pdo->exec(" 
            CREATE TABLE IF NOT EXISTS `coach_billing_month_athletes` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `coach_id`      INT NOT NULL,
                `athlete_id`    INT NOT NULL,
                `billing_month` DATE NOT NULL,
                `status`        ENUM('draft','released') NOT NULL DEFAULT 'draft',
                `released_at`   DATETIME NULL,
                `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_coach_billing_month_athlete` (`coach_id`, `athlete_id`, `billing_month`),
                KEY `idx_coach_billing_month_athlete_status` (`coach_id`, `billing_month`, `status`),
                CONSTRAINT `fk_coach_billing_month_athlete_coach`
                    FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_coach_billing_month_athlete_athlete`
                    FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        $warnings[] = 'Tabulku coach_billing_month_athletes se nepodařilo vytvořit.';
    }

    return [
        'warnings' => $warnings,
        'has_training_rate' => columnExists($pdo, 'athletes', 'training_rate'),
        'has_paired_training_rate' => columnExists($pdo, 'athletes', 'paired_training_rate'),
        'has_second_athlete' => columnExists($pdo, 'coach_calendar_events', 'second_athlete_id'),
        'has_is_makeup_session' => columnExists($pdo, 'coach_calendar_events', 'is_makeup_session'),
        'has_billing_month' => columnExists($pdo, 'coach_calendar_events', 'billing_month'),
        'has_coach_bank_account' => columnExists($pdo, 'coaches', 'bank_account'),
        'has_payments_table' => tableExists($pdo, 'athlete_monthly_payments'),
    ];
}

function normalizeCoachBankAccount(?string $raw): ?string
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

function digitsMod97(string $numeric): int
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

function ibanToNumericString(string $iban): string
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

function isValidIban(string $iban): bool
{
    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban) !== 1) {
        return false;
    }
    return digitsMod97(ibanToNumericString($iban)) === 1;
}

function buildCzIbanFromLocal(string $localAccount): ?string
{
    if (preg_match('/^(?:(\d{1,6})-)?(\d{2,10})\/(\d{4})$/', $localAccount, $m) !== 1) {
        return null;
    }

    $prefix = str_pad((string)($m[1] ?? '0'), 6, '0', STR_PAD_LEFT);
    $account = str_pad($m[2], 10, '0', STR_PAD_LEFT);
    $bankCode = $m[3];
    $bban = $bankCode . $prefix . $account;

    $checkBase = $bban . '123500';
    $checkDigits = 98 - digitsMod97($checkBase);
    $iban = 'CZ' . str_pad((string)$checkDigits, 2, '0', STR_PAD_LEFT) . $bban;

    return isValidIban($iban) ? $iban : null;
}

function accountForSpd(?string $bankAccount): ?string
{
    if ($bankAccount === null || $bankAccount === '') {
        return null;
    }

    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $bankAccount) === 1) {
        return isValidIban($bankAccount) ? $bankAccount : null;
    }

    return buildCzIbanFromLocal($bankAccount);
}

function paymentAsciiText(string $value): string
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

function buildPaymentQrUrl(string $bankAccount, float $amount, string $note): string
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

    $spd = implode('*', $parts);
    return 'https://quickchart.io/qr?size=220&text=' . rawurlencode($spd);
}

function fetchBillingStats(PDO $pdo, int $coachId, string $billingMonthSql, bool $hasIsMakeupSession, bool $hasBillingMonth, bool $hasSecondAthlete, ?int $athleteId = null, int $issueNumber = 1): array
{
    $makeupExpr = $hasIsMakeupSession ? 'SUM(CASE WHEN t.is_makeup_session = 1 THEN 1 ELSE 0 END)' : '0';
    $hasBillingIssue = columnExists($pdo, 'coach_calendar_events', 'billing_issue_number');
    $hasTransferReason = columnExists($pdo, 'coach_calendar_events', 'billing_transfer_reason');
    $billingField = $hasBillingMonth ? 'billing_month' : 'starts_at AS billing_month';
    $issueField = $hasBillingIssue ? 'billing_issue_number' : '1 AS billing_issue_number';
    $transferReasonField = $hasTransferReason ? 'billing_transfer_reason' : "'' AS billing_transfer_reason";
    $effectiveBillingMonth = paymentBillingEffectiveMonthExpression('t', $hasBillingMonth, $hasTransferReason);
    $transferredExpr = $hasBillingMonth
        ? "SUM(CASE WHEN DATE_FORMAT(t.starts_at, '%Y-%m-01') <> {$effectiveBillingMonth} THEN 1 ELSE 0 END)"
        : '0';
    $hasPaidReplacementLinks = tableExists($pdo, 'coach_calendar_event_cancellations')
        && columnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
        && columnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot');
    $paidReplacementField = $hasPaidReplacementLinks
        ? "CASE WHEN EXISTS (
                SELECT 1 FROM coach_calendar_event_cancellations c
                WHERE c.coach_id = coach_calendar_events.coach_id
                  AND c.replacement_event_id = coach_calendar_events.id
                                      AND c.payment_status_snapshot IN ('pending', 'paid')
            ) THEN 1 ELSE 0 END AS is_paid_replacement"
        : '0 AS is_paid_replacement';
    $transferredMakeupExpr = $hasPaidReplacementLinks
        ? 'SUM(CASE WHEN t.is_paid_replacement = 1 THEN 1 ELSE 0 END)'
        : '0';
    $billingFilter = "AND {$effectiveBillingMonth} = ? AND t.billing_issue_number = ?";

    if ($hasSecondAthlete) {
        $participantsSql = "
            SELECT id AS event_id, coach_id, athlete_id AS participant_id,
                   starts_at,
                   {$billingField},
                   {$issueField},
                   {$transferReasonField},
                   is_makeup_session,
                   {$paidReplacementField},
                   CASE WHEN second_athlete_id IS NOT NULL THEN 1 ELSE 0 END AS is_paired
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND athlete_id IS NOT NULL
              AND approval_status = 'approved'
            UNION ALL
            SELECT id AS event_id, coach_id, second_athlete_id AS participant_id,
                   starts_at,
                   {$billingField},
                   {$issueField},
                   {$transferReasonField},
                   is_makeup_session,
                   {$paidReplacementField},
                   1 AS is_paired
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND second_athlete_id IS NOT NULL
              AND approval_status = 'approved'
        ";
        $params = [$coachId, $coachId, $billingMonthSql];
        $params[] = $issueNumber;
    } else {
        $participantsSql = "
            SELECT id AS event_id, coach_id, athlete_id AS participant_id,
                   starts_at,
                   {$billingField},
                   {$issueField},
                   {$transferReasonField},
                   is_makeup_session,
                   {$paidReplacementField},
                   0 AS is_paired
            FROM coach_calendar_events
            WHERE coach_id = ?
              AND athlete_id IS NOT NULL
              AND approval_status = 'approved'
        ";
        $params = [$coachId, $billingMonthSql];
        $params[] = $issueNumber;
    }

    $sql = "SELECT t.participant_id AS athlete_id,
                   COUNT(*) AS billed_sessions,
                   SUM(CASE WHEN t.is_paired = 1 THEN 1 ELSE 0 END) AS paired_sessions,
                   SUM(CASE WHEN t.is_paired = 0 THEN 1 ELSE 0 END) AS single_sessions,
                   {$makeupExpr} AS makeup_sessions,
                   {$transferredExpr} AS transferred_sessions,
                   {$transferredMakeupExpr} AS transferred_makeup_sessions,
                   SUM(CASE WHEN t.is_paid_replacement = 1 AND t.is_paired = 0 THEN 1 ELSE 0 END) AS paid_replacement_single_sessions,
                   SUM(CASE WHEN t.is_paid_replacement = 1 AND t.is_paired = 1 THEN 1 ELSE 0 END) AS paid_replacement_paired_sessions
            FROM ({$participantsSql}) t
            WHERE 1=1
              {$billingFilter}";

    if ($athleteId !== null) {
        $sql .= ' AND t.participant_id = ?';
        $params[] = $athleteId;
    }

    $sql .= ' GROUP BY t.participant_id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $result[(int)$row['athlete_id']] = [
            'billed_sessions' => (int)$row['billed_sessions'],
            'paired_sessions' => (int)($row['paired_sessions'] ?? 0),
            'single_sessions' => (int)($row['single_sessions'] ?? 0),
            'makeup_sessions' => (int)$row['makeup_sessions'],
            'transferred_sessions' => (int)$row['transferred_sessions'],
            'transferred_makeup_sessions' => (int)($row['transferred_makeup_sessions'] ?? 0),
            'paid_replacement_single_sessions' => (int)($row['paid_replacement_single_sessions'] ?? 0),
            'paid_replacement_paired_sessions' => (int)($row['paid_replacement_paired_sessions'] ?? 0),
        ];
    }

    return $result;
}

function fetchHistoricalActualSessionsByMonth(PDO $pdo, int $coachId, string $beforeMonthSql, bool $hasBillingMonth, bool $hasSecondAthlete): array
{
    $monthExpr = "DATE_FORMAT(t.starts_at, '%Y-%m-01')";
    $monthFilter = "{$monthExpr} < ?";

        if ($hasSecondAthlete) {
                $participantsSql = "
                        SELECT athlete_id AS participant_id, starts_at
                        FROM coach_calendar_events
                        WHERE coach_id = ?
                            AND athlete_id IS NOT NULL
                            AND approval_status = 'approved'
                        UNION ALL
                        SELECT second_athlete_id AS participant_id, starts_at
                        FROM coach_calendar_events
                        WHERE coach_id = ?
                            AND second_athlete_id IS NOT NULL
                            AND approval_status = 'approved'
                ";
                $params = [$coachId, $coachId, $beforeMonthSql];
        } else {
                $participantsSql = "
                        SELECT athlete_id AS participant_id, starts_at
                        FROM coach_calendar_events
                        WHERE coach_id = ?
                            AND athlete_id IS NOT NULL
                            AND approval_status = 'approved'
                ";
                $params = [$coachId, $beforeMonthSql];
        }

        $stmt = $pdo->prepare(
                "SELECT t.participant_id AS athlete_id,
                                {$monthExpr} AS billing_month,
                                COUNT(*) AS billed_sessions
                 FROM ({$participantsSql}) t
                 WHERE {$monthFilter}
                 GROUP BY t.participant_id, {$monthExpr}"
        );
        $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $aid = (int)$row['athlete_id'];
        $month = (string)$row['billing_month'];
        $result[$aid][$month] = (int)$row['billed_sessions'];
    }

    return $result;
}

function computeBillableBreakdown(array $stats, int $carryoverUsed, ?float $singleRate, ?float $pairedRate): array
{
    $singleSessions = max(0, (int)($stats['single_sessions'] ?? 0));
    $pairedSessions = max(0, (int)($stats['paired_sessions'] ?? 0));
    $totalSessions = $singleSessions + $pairedSessions;
    $paidReplacementSingle = min($singleSessions, max(0, (int)($stats['paid_replacement_single_sessions'] ?? 0)));
    $paidReplacementPaired = min($pairedSessions, max(0, (int)($stats['paid_replacement_paired_sessions'] ?? 0)));
    $carryoverUsed = $paidReplacementSingle + $paidReplacementPaired;
    $billableSingle = max(0, $singleSessions - $paidReplacementSingle);
    $billablePaired = max(0, $pairedSessions - $paidReplacementPaired);

    $effectiveSingleRate = $singleRate;
    $effectivePairedRate = $pairedRate ?? $singleRate;
    $amount = null;

    if ($effectiveSingleRate !== null && $effectivePairedRate !== null) {
        $amount = ($billableSingle * $effectiveSingleRate) + ($billablePaired * $effectivePairedRate);
    }

    return [
        'single_sessions' => $singleSessions,
        'paired_sessions' => $pairedSessions,
        'total_sessions' => $totalSessions,
        'carryover_used' => $carryoverUsed,
        'paid_replacement_single_sessions' => $paidReplacementSingle,
        'paid_replacement_paired_sessions' => $paidReplacementPaired,
        'billable_single_sessions' => $billableSingle,
        'billable_paired_sessions' => $billablePaired,
        'billable_sessions' => $billableSingle + $billablePaired,
        'amount' => $amount,
        'single_rate' => $effectiveSingleRate,
        'paired_rate' => $effectivePairedRate,
    ];
}

function resolveCarryoverUsageForStats(array $stats, int $outstandingCarryover): int
{
    return max(0, (int)($stats['paid_replacement_single_sessions'] ?? 0))
        + max(0, (int)($stats['paid_replacement_paired_sessions'] ?? 0));
}

function fetchPaidHistoryBeforeMonth(PDO $pdo, int $coachId, string $beforeMonthSql): array
{
    $stmt = $pdo->prepare(
        'SELECT athlete_id, billing_month, planned_sessions, carryover_used_sessions
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND status = "paid"
           AND billing_month < ?
         ORDER BY athlete_id ASC, billing_month ASC'
    );
    $stmt->execute([$coachId, $beforeMonthSql]);
    return $stmt->fetchAll();
}

function fetchForfeitedCarryoverByAthleteMonth(PDO $pdo, int $coachId, string $beforeMonthSql): array
{
    if (!tableExists($pdo, 'coach_calendar_event_cancellations')) {
        return [];
    }
    if (!columnExists($pdo, 'coach_calendar_event_cancellations', 'athlete_id')
        || !columnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
        || !columnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
        || !columnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
        || !columnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at')
    ) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT athlete_id,
                billing_month,
                COUNT(*) AS forfeited_count
         FROM coach_calendar_event_cancellations
         WHERE coach_id = ?
           AND athlete_id IS NOT NULL
           AND billing_month < ?
           AND replacement_required = 1
           AND replacement_event_id IS NULL
           AND replacement_deadline_at < NOW()
         GROUP BY athlete_id, billing_month"
    );
    $stmt->execute([$coachId, $beforeMonthSql]);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $aid = (int)$row['athlete_id'];
        $month = (string)$row['billing_month'];
        $result[$aid][$month] = (int)$row['forfeited_count'];
    }

    return $result;
}

function fetchForfeitedBillingSessionsByAthlete(PDO $pdo, int $coachId, string $billingMonthSql): array
{
    if (!tableExists($pdo, 'coach_calendar_event_cancellations')) {
        return [];
    }
    if (!columnExists($pdo, 'coach_calendar_event_cancellations', 'athlete_id')
        || !columnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
        || !columnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
        || !columnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
        || !columnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at')
    ) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT athlete_id,
                COUNT(*) AS forfeited_count
         FROM coach_calendar_event_cancellations
         WHERE coach_id = ?
           AND athlete_id IS NOT NULL
           AND billing_month = ?
           AND replacement_required = 1
           AND replacement_event_id IS NULL
           AND replacement_deadline_at < NOW()
         GROUP BY athlete_id"
    );
    $stmt->execute([$coachId, $billingMonthSql]);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int)$row['athlete_id']] = (int)$row['forfeited_count'];
    }

    return $result;
}

function computeOutstandingCarryoverByAthlete(array $paidHistoryRows, array $actualByAthleteMonth, array $forfeitedByAthleteMonth = []): array
{
    $outstandingByAthlete = [];

    foreach ($paidHistoryRows as $row) {
        $aid = (int)$row['athlete_id'];
        $month = (string)$row['billing_month'];
        $planned = max(0, (int)$row['planned_sessions']);
        $used = max(0, (int)($row['carryover_used_sessions'] ?? 0));
        $actual = (int)($actualByAthleteMonth[$aid][$month] ?? 0);
        $forfeited = (int)($forfeitedByAthleteMonth[$aid][$month] ?? 0);

        $generated = max(0, $planned - $actual - $forfeited);
        $running = (int)($outstandingByAthlete[$aid] ?? 0);
        $running += $generated;
        $running = max(0, $running - $used);

        $outstandingByAthlete[$aid] = $running;
    }

    return $outstandingByAthlete;
}

function upsertAthleteMonthlyPayment(PDO $pdo, int $coachId, int $athleteId, string $billingMonthSql, ?float $sessionRate, int $plannedSessions, int $carryoverUsed, float $billedAmount, string $status, bool $amountIncludesOnline = false, int $issueNumber = 1): void
{
    $safeStatus = $status === 'paid' ? 'paid' : 'pending';
    if (!$amountIncludesOnline) {
        $billedAmount += onlineTrainingBillingAmount($pdo, $coachId, $athleteId, $billingMonthSql);
    }
    $receiptSnapshot = paymentBuildReceiptSnapshot($pdo, $coachId, $athleteId, $billingMonthSql, $issueNumber, null, $billedAmount);
    $sql = "INSERT INTO athlete_monthly_payments (coach_id, athlete_id, billing_month, issue_number, session_rate, planned_sessions, carryover_used_sessions, billed_amount, status, paid_at, receipt_snapshot_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($safeStatus === 'paid' ? 'NOW()' : 'NULL') . ", ?)
            ON DUPLICATE KEY UPDATE
                session_rate = CASE
                    WHEN athlete_monthly_payments.status IN ('pending', 'paid') THEN athlete_monthly_payments.session_rate
                    ELSE VALUES(session_rate)
                END,
                planned_sessions = CASE
                    WHEN athlete_monthly_payments.status IN ('pending', 'paid') THEN athlete_monthly_payments.planned_sessions
                    ELSE VALUES(planned_sessions)
                END,
                carryover_used_sessions = CASE
                    WHEN athlete_monthly_payments.status IN ('pending', 'paid') THEN athlete_monthly_payments.carryover_used_sessions
                    ELSE VALUES(carryover_used_sessions)
                END,
                billed_amount = CASE
                    WHEN athlete_monthly_payments.status IN ('pending', 'paid') THEN athlete_monthly_payments.billed_amount
                    ELSE VALUES(billed_amount)
                END,
                receipt_snapshot_json = CASE
                    WHEN athlete_monthly_payments.status IN ('pending', 'paid') THEN COALESCE(athlete_monthly_payments.receipt_snapshot_json, VALUES(receipt_snapshot_json))
                    ELSE VALUES(receipt_snapshot_json)
                END,
                status = VALUES(status),
                paid_at = " . ($safeStatus === 'paid' ? 'NOW()' : 'NULL');

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $coachId,
        $athleteId,
        $billingMonthSql,
        max(1, $issueNumber),
        $sessionRate !== null ? number_format($sessionRate, 2, '.', '') : null,
        $plannedSessions,
        $carryoverUsed,
        number_format($billedAmount, 2, '.', ''),
        $safeStatus,
        $receiptSnapshot,
    ]);
}

$schema = ensurePaymentsRuntimeSchema($pdo);
$schemaWarnings = $schema['warnings'];
$hasTrainingRate = (bool)$schema['has_training_rate'];
$hasPairedTrainingRate = (bool)($schema['has_paired_training_rate'] ?? false);
$hasSecondAthlete = (bool)($schema['has_second_athlete'] ?? false);
$hasIsMakeupSession = (bool)$schema['has_is_makeup_session'];
$hasBillingMonth = (bool)$schema['has_billing_month'];
$hasCoachBankAccount = (bool)$schema['has_coach_bank_account'];
$hasPaymentsTable = (bool)$schema['has_payments_table'];

$coachStmt = $pdo->prepare('SELECT name' . ($hasCoachBankAccount ? ', bank_account' : '') . ' FROM coaches WHERE id = ? LIMIT 1');
$coachStmt->execute([$coachId]);
$coach = $coachStmt->fetch() ?: [];
$coachName = trim((string)($coach['name'] ?? ($_SESSION['coach_name'] ?? '')));
$coachLastNameParts = preg_split('/\s+/u', $coachName) ?: [];
$coachLastName = trim((string)end($coachLastNameParts));
if ($coachLastName === '') {
    $coachLastName = 'Trener';
}
$coachBankAccountRaw = $hasCoachBankAccount ? normalizeCoachBankAccount($coach['bank_account'] ?? null) : null;
$coachBankAccount = accountForSpd($coachBankAccountRaw);

$selectedMonth = parseBillingMonthValue($_REQUEST['month'] ?? null);
$selectedMonthSql = $selectedMonth->format('Y-m-01');
$selectedMonthParam = $selectedMonth->format('Y-m');
$monthFormatter = new IntlDateFormatter('cs_CZ', IntlDateFormatter::LONG, IntlDateFormatter::NONE, date_default_timezone_get(), null, 'LLLL yyyy');
$monthTitle = (string)$monthFormatter->format($selectedMonth);
$prevMonthParam = $selectedMonth->modify('-1 month')->format('Y-m');
$nextMonthParam = $selectedMonth->modify('+1 month')->format('Y-m');

$monthReleaseRow = null;
try {
    $releaseStmt = $pdo->prepare('SELECT status, released_at FROM coach_billing_months WHERE coach_id = ? AND billing_month = ? LIMIT 1');
    $releaseStmt->execute([$coachId, $selectedMonthSql]);
    $monthReleaseRow = $releaseStmt->fetch() ?: null;
} catch (Throwable $e) {
    $monthReleaseRow = null;
}
$isMonthReleased = (($monthReleaseRow['status'] ?? 'draft') === 'released');

$releasedAthleteIds = [];
try {
    $releasedAthleteStmt = $pdo->prepare(
        'SELECT athlete_id
         FROM coach_billing_month_athletes
         WHERE coach_id = ? AND billing_month = ? AND status = "released"'
    );
    $releasedAthleteStmt->execute([$coachId, $selectedMonthSql]);
    foreach ($releasedAthleteStmt->fetchAll() as $releasedRow) {
        $releasedAthleteIds[(int)$releasedRow['athlete_id']] = true;
    }
} catch (Throwable $e) {
    $releasedAthleteIds = [];
}

$releasedAthleteCount = count($releasedAthleteIds);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    $athleteId = (int)($_POST['athlete_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));
    $issueNumber = max(1, (int)($_POST['issue_number'] ?? 1));

    $athleteStmt = $pdo->prepare(
        'SELECT id, first_name, last_name, email, training_rate'
        . ($hasPairedTrainingRate ? ', paired_training_rate' : '') . '
         FROM athletes
         WHERE id = ? AND coach_id = ?
         LIMIT 1'
    );
    $athleteStmt->execute([$athleteId, $coachId]);
    $athlete = $athleteStmt->fetch();

    if (!$athlete) {
        flash('danger', 'Sportovec nebyl nalezen.');
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
    $isAthleteReleased = isAthleteMonthReleased($athleteId, $isMonthReleased, $releasedAthleteIds);

    if ($action === 'release_month_athlete') {
        try {
            $releaseAthleteUpsert = $pdo->prepare(
                "INSERT INTO coach_billing_month_athletes (coach_id, athlete_id, billing_month, status, released_at)
                 VALUES (?, ?, ?, 'released', NOW())
                 ON DUPLICATE KEY UPDATE status = 'released', released_at = NOW()"
            );
            $releaseAthleteUpsert->execute([$coachId, $athleteId, $selectedMonthSql]);

            if ($hasPaymentsTable) {
                $existingStmt = $pdo->prepare(
                    'SELECT status
                     FROM athlete_monthly_payments
                     WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = 1
                     LIMIT 1'
                );
                $existingStmt->execute([$coachId, $athleteId, $selectedMonthSql]);
                $existingPayment = $existingStmt->fetch();

                if (!$existingPayment) {
                    $stats = fetchBillingStats($pdo, $coachId, $selectedMonthSql, $hasIsMakeupSession, $hasBillingMonth, $hasSecondAthlete, $athleteId);
                    $athleteStats = $stats[$athleteId] ?? [
                        'billed_sessions' => 0,
                        'single_sessions' => 0,
                        'paired_sessions' => 0,
                        'makeup_sessions' => 0,
                        'transferred_sessions' => 0,
                    ];

                    $rate = $hasTrainingRate && $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
                    $pairedRate = $hasPairedTrainingRate && array_key_exists('paired_training_rate', $athlete) && $athlete['paired_training_rate'] !== null
                        ? (float)$athlete['paired_training_rate']
                        : $rate;

                    if ($rate !== null) {
                        $actualByAthleteMonth = fetchHistoricalActualSessionsByMonth($pdo, $coachId, $selectedMonthSql, $hasBillingMonth, $hasSecondAthlete);
                        $paidHistoryRows = fetchPaidHistoryBeforeMonth($pdo, $coachId, $selectedMonthSql);
                        $forfeitedByAthleteMonth = fetchForfeitedCarryoverByAthleteMonth($pdo, $coachId, $selectedMonthSql);
                        $outstandingByAthlete = computeOutstandingCarryoverByAthlete($paidHistoryRows, $actualByAthleteMonth, $forfeitedByAthleteMonth);

                        $carryoverUsedNow = resolveCarryoverUsageForStats($athleteStats, (int)($outstandingByAthlete[$athleteId] ?? 0));
                        $billable = computeBillableBreakdown($athleteStats, $carryoverUsedNow, $rate, $pairedRate);

                        upsertAthleteMonthlyPayment(
                            $pdo,
                            $coachId,
                            $athleteId,
                            $selectedMonthSql,
                            $rate,
                            (int)$billable['billable_sessions'],
                            $carryoverUsedNow,
                            (float)($billable['amount'] ?? 0.0),
                            'pending'
                        );
                    }
                }
            }

            flash('success', 'Výzva byla otevřena pro sportovce ' . $athleteName . '.');
        } catch (Throwable $e) {
            flash('danger', 'Výzvu se nepodařilo otevřít pro vybraného sportovce.');
        }
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    if ($action === 'unrelease_month_athlete') {
        try {
            $releaseAthleteUpsert = $pdo->prepare(
                "INSERT INTO coach_billing_month_athletes (coach_id, athlete_id, billing_month, status, released_at)
                 VALUES (?, ?, ?, 'draft', NULL)
                 ON DUPLICATE KEY UPDATE status = 'draft', released_at = NULL"
            );
            $releaseAthleteUpsert->execute([$coachId, $athleteId, $selectedMonthSql]);

            if ($hasPaymentsTable) {
                $deletePending = $pdo->prepare(
                    'DELETE FROM athlete_monthly_payments
                     WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = 1 AND status = "pending"'
                );
                $deletePending->execute([$coachId, $athleteId, $selectedMonthSql]);
            }

            flash('success', 'Výzva byla vrácena do konceptu pro sportovce ' . $athleteName . '.');
        } catch (Throwable $e) {
            flash('danger', 'Výzvu se nepodařilo vrátit do konceptu pro vybraného sportovce.');
        }
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    if ($action === 'send_payment_email') {
        if (!$isAthleteReleased) {
            flash('danger', 'Nejdříve otevřete výzvu k platbě pro konkrétního sportovce.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $athleteEmail = trim((string)($athlete['email'] ?? ''));
        if ($athleteEmail === '' || !filter_var($athleteEmail, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Sportovec nemá platný e-mail.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        if ($coachBankAccount === null) {
            flash('danger', 'Nejprve nastavte platné číslo účtu v profilu trenéra.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $currentAmount = 0.0;
        $billableSessions = 0;
        $carryoverUsedNow = 0;
        $rate = $hasTrainingRate && $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;

        if ($rate === null) {
            flash('danger', 'Sportovec nemá nastavenou sazbu za trénink.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $existingStmt = $pdo->prepare(
            'SELECT session_rate, planned_sessions, carryover_used_sessions, billed_amount, status, receipt_snapshot_json
             FROM athlete_monthly_payments
               WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = ?
             LIMIT 1'
        );
           $existingStmt->execute([$coachId, $athleteId, $selectedMonthSql, $issueNumber]);
        $existingPayment = $existingStmt->fetch() ?: null;

        if ($existingPayment && (string)($existingPayment['status'] ?? '') === 'paid') {
            flash('danger', 'Tato výzva už je uhrazená a nelze ji znovu odeslat.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        if ($existingPayment && (string)($existingPayment['status'] ?? '') === 'pending') {
            $storedRate = $existingPayment['session_rate'] !== null ? (float)$existingPayment['session_rate'] : $rate;
            $currentBill = calculateAthleteMonthlyBill(
                $pdo,
                $coachId,
                $athleteId,
                $selectedMonthSql,
                [],
                $issueNumber,
                $issueNumber > 1 ? $storedRate : null
            );
            $currentAmountForCheck = (float)($currentBill['billed_amount'] ?? 0.0);
            $currentReceiptSnapshot = json_decode(
                (string)paymentBuildReceiptSnapshot($pdo, $coachId, $athleteId, $selectedMonthSql, $issueNumber, null, $currentAmountForCheck),
                true
            );
            $savedReceiptSnapshot = json_decode((string)($existingPayment['receipt_snapshot_json'] ?? ''), true);
            $eventListChanged = false;
            if (is_array($savedReceiptSnapshot['event_rows'] ?? null) && is_array($currentReceiptSnapshot['event_rows'] ?? null)) {
                $signature = static function (array $rows): array {
                    $result = [];
                    foreach ($rows as $row) {
                        $result[] = [
                            (int)($row['id'] ?? 0),
                            (string)($row['starts_at'] ?? ''),
                            (int)($row['is_paired'] ?? 0),
                            (int)($row['is_makeup_session'] ?? 0),
                        ];
                    }
                    sort($result);
                    return $result;
                };
                $eventListChanged = $signature($savedReceiptSnapshot['event_rows']) !== $signature($currentReceiptSnapshot['event_rows']);
            }

            if ((int)$currentBill['planned_sessions'] !== (int)$existingPayment['planned_sessions']
                || abs($currentAmountForCheck - (float)$existingPayment['billed_amount']) > 0.009
                || $eventListChanged) {
                flash('danger', 'Kalendář se od otevření výzvy změnil. Výzvu neodesílám. Zkontrolujte termíny, zavřete a znovu otevřete výzvu, potom znovu ověřte účtenku.');
                redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
            }
            $billableSessions = (int)($existingPayment['planned_sessions'] ?? 0);
            $carryoverUsedNow = (int)($existingPayment['carryover_used_sessions'] ?? 0);
            $currentAmount = (float)($existingPayment['billed_amount'] ?? 0.0);
            if (empty($existingPayment['receipt_snapshot_json'])) {
                upsertAthleteMonthlyPayment(
                    $pdo,
                    $coachId,
                    $athleteId,
                    $selectedMonthSql,
                    $storedRate,
                    $billableSessions,
                    $carryoverUsedNow,
                    $currentAmount,
                    'pending',
                    true,
                    $issueNumber
                );
            }
        } else {
            $bill = calculateAthleteMonthlyBill($pdo, $coachId, $athleteId, $selectedMonthSql, [], $issueNumber);
            $carryoverUsedNow = (int)$bill['carryover_used'];
            $billableSessions = (int)$bill['planned_sessions'];
            $currentAmount = (float)($bill['billed_amount'] ?? 0.0);

            upsertAthleteMonthlyPayment(
                $pdo,
                $coachId,
                $athleteId,
                $selectedMonthSql,
                $rate,
                $billableSessions,
                $carryoverUsedNow,
                $currentAmount,
                'pending',
                true,
                $issueNumber
            );
        }

        if ($currentAmount <= 0) {
            flash('danger', 'Není co fakturovat. Částka je 0 Kč.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $paymentNote = paymentAsciiText($coachLastName . ' ' . $selectedMonth->format('m/Y') . ($issueNumber > 1 ? ' dodatecna vyzva' : ''));
        $paymentQrUrl = buildPaymentQrUrl($coachBankAccount, $currentAmount, $paymentNote);
        $amountText = number_format($currentAmount, 0, ',', ' ') . ' Kč';

        $sent = sendPaymentRequestEmail($athleteEmail, [
            'athlete_name' => $athleteName,
            'coach_name' => $coachName !== '' ? $coachName : 'Váš trenér',
            'month_label' => $selectedMonth->format('m/Y') . ($issueNumber > 1 ? ' — dodatečná výzva' : ''),
            'amount_text' => $amountText,
            'account' => $coachBankAccount,
            'note' => $paymentNote,
            'qr_url' => $paymentQrUrl,
        ]);

        if ($sent) {
            $invoiceLabel = $selectedMonth->format('m/Y') . ($issueNumber > 1 ? ' — dodatečná výzva' : '');
            createAthleteNotification(
                $athleteId,
                $issueNumber > 1 ? 'Nová dodatečná výzva k platbě' : 'Nová výzva k platbě',
                'Trenér vám poslal výzvu k platbě za období ' . $invoiceLabel
                . ' ve výši ' . $amountText . '. Přehled včetně QR kódu najdete v sekci Platby.'
            );
            flash('success', 'Výzva k platbě byla odeslána na ' . $athleteEmail . '.');
        } else {
            flash('danger', 'E-mail se nepodařilo odeslat. Zkontrolujte SMTP nastavení.');
        }
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    if ($action === 'mark_paid') {
        if (!$hasPaymentsTable) {
            flash('danger', 'Evidenční tabulka plateb není dostupná.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }
        if (!$isAthleteReleased) {
            flash('danger', 'Nejdříve otevřete výzvu k platbě pro konkrétního sportovce.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $rate = $hasTrainingRate && $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
        if ($rate === null) {
            flash('danger', 'Sportovec nemá nastavenou sazbu za trénink.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $plannedSessions = 0;
        $carryoverUsedNow = 0;
        $billedAmount = 0.0;
        $amountIncludesOnline = false;

        $existingStmt = $pdo->prepare(
            'SELECT session_rate, planned_sessions, carryover_used_sessions, billed_amount, status
             FROM athlete_monthly_payments
               WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = ?
             LIMIT 1'
        );
           $existingStmt->execute([$coachId, $athleteId, $selectedMonthSql, $issueNumber]);
        $existingPayment = $existingStmt->fetch() ?: null;

        if ($existingPayment && (($existingPayment['status'] ?? '') === 'pending' || ($existingPayment['status'] ?? '') === 'paid')) {
            $plannedSessions = (int)($existingPayment['planned_sessions'] ?? 0);
            $carryoverUsedNow = (int)($existingPayment['carryover_used_sessions'] ?? 0);
            $billedAmount = (float)($existingPayment['billed_amount'] ?? 0.0);
            $storedRate = $existingPayment['session_rate'] !== null ? (float)$existingPayment['session_rate'] : $rate;
            $rate = $storedRate;
        } else {
            $bill = calculateAthleteMonthlyBill($pdo, $coachId, $athleteId, $selectedMonthSql, [], $issueNumber);
            $carryoverUsedNow = (int)$bill['carryover_used'];
            $plannedSessions = (int)$bill['planned_sessions'];
            $billedAmount = (float)($bill['billed_amount'] ?? 0.0);
            $amountIncludesOnline = true;
        }

        upsertAthleteMonthlyPayment(
            $pdo,
            $coachId,
            $athleteId,
            $selectedMonthSql,
            $rate,
            $plannedSessions,
            $carryoverUsedNow,
            $billedAmount,
            'paid',
            $amountIncludesOnline,
            $issueNumber
        );

        createAthleteNotification(
            $athleteId,
            'Platba byla označena jako uhrazená',
            'Trenér označil platbu za období ' . $selectedMonth->format('m/Y')
            . ' jako uhrazenou. Evidovaná částka je ' . number_format($billedAmount, 0, ',', ' ') . ' Kč.'
            . ($carryoverUsedNow > 0 ? (' Zároveň bylo započteno ' . $carryoverUsedNow . ' náhradních tréninků.') : '')
        );

        flash('success', 'Úhrada pro ' . $athleteName . ' byla označena jako uhrazená.');
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    if ($action === 'mark_unpaid') {
        if (!$hasPaymentsTable) {
            flash('danger', 'Evidenční tabulka plateb není dostupná.');
            redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
        }

        $markPending = $pdo->prepare(
            'UPDATE athlete_monthly_payments
             SET status = "pending", paid_at = NULL
               WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = ?'
        );
           $markPending->execute([$coachId, $athleteId, $selectedMonthSql, $issueNumber]);

        createAthleteNotification(
            $athleteId,
            'Evidence úhrady byla zrušena',
            'Trenér zrušil evidenci úhrady za období ' . $selectedMonth->format('m/Y') . '. V sekci Platby znovu vidíte aktivní výzvu k platbě.'
        );

        flash('success', 'Evidence úhrady pro ' . $athleteName . ' byla zrušena.');
        redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
    }

    flash('danger', 'Neznámá akce.');
    redirect(BASE_URL . '/payments.php?month=' . urlencode($selectedMonthParam));
}

$athletesStmt = $pdo->prepare(
    'SELECT id, first_name, last_name, email, phone_contact'
    . ($hasTrainingRate ? ', training_rate' : '')
    . ($hasPairedTrainingRate ? ', paired_training_rate' : '') . '
     FROM athletes
     WHERE coach_id = ?
    ORDER BY first_name ASC, last_name ASC'
);
$athletesStmt->execute([$coachId]);
$athletes = $athletesStmt->fetchAll();

$statsByAthlete = fetchBillingStats($pdo, $coachId, $selectedMonthSql, $hasIsMakeupSession, $hasBillingMonth, $hasSecondAthlete);

$paymentsRows = [];
if ($hasPaymentsTable) {
    $paymentsStmt = $pdo->prepare(
        'SELECT athlete_id, issue_number, session_rate, planned_sessions, carryover_used_sessions, billed_amount, status, paid_at, receipt_snapshot_json
         FROM athlete_monthly_payments
         WHERE coach_id = ? AND billing_month = ?'
    );
    $paymentsStmt->execute([$coachId, $selectedMonthSql]);
    $paymentsRows = $paymentsStmt->fetchAll();
}
$paymentsByAthlete = [];
$additionalPaymentsByAthlete = [];
foreach ($paymentsRows as $row) {
    $paymentAthleteId = (int)$row['athlete_id'];
    if ((int)($row['issue_number'] ?? 1) > 1) {
        $additionalPaymentsByAthlete[$paymentAthleteId][] = $row;
    } else {
        $paymentsByAthlete[$paymentAthleteId] = $row;
    }
}

$actualByAthleteMonth = $hasPaymentsTable
    ? fetchHistoricalActualSessionsByMonth($pdo, $coachId, $selectedMonthSql, $hasBillingMonth, $hasSecondAthlete)
    : [];
$paidHistoryRows = $hasPaymentsTable
    ? fetchPaidHistoryBeforeMonth($pdo, $coachId, $selectedMonthSql)
    : [];
$forfeitedByAthleteMonth = $hasPaymentsTable
    ? fetchForfeitedCarryoverByAthleteMonth($pdo, $coachId, $selectedMonthSql)
    : [];
$forfeitedCurrentMonthByAthlete = $hasPaymentsTable
    ? fetchForfeitedBillingSessionsByAthlete($pdo, $coachId, $selectedMonthSql)
    : [];
$outstandingByAthlete = $hasPaymentsTable
    ? computeOutstandingCarryoverByAthlete($paidHistoryRows, $actualByAthleteMonth, $forfeitedByAthleteMonth)
    : [];

$totalSessions = 0;
$totalAmount = 0.0;
$totalPaidAmount = 0.0;
$totalPaidAthleteIds = [];

foreach ($athletes as $athlete) {
    $athleteId = (int)$athlete['id'];
    $stats = $statsByAthlete[$athleteId] ?? [
        'billed_sessions' => 0,
        'single_sessions' => 0,
        'paired_sessions' => 0,
        'makeup_sessions' => 0,
        'transferred_sessions' => 0,
    ];
    $rate = $hasTrainingRate && array_key_exists('training_rate', $athlete) && $athlete['training_rate'] !== null
        ? (float)$athlete['training_rate']
        : null;
    $pairedRate = $hasPairedTrainingRate && array_key_exists('paired_training_rate', $athlete) && $athlete['paired_training_rate'] !== null
        ? (float)$athlete['paired_training_rate']
        : $rate;

    $carryoverUsedNow = resolveCarryoverUsageForStats($stats, (int)($outstandingByAthlete[$athleteId] ?? 0));
    $billable = computeBillableBreakdown($stats, $carryoverUsedNow, $rate, $pairedRate);
    $billableSessions = (int)$billable['billable_sessions'];
    $payment = $paymentsByAthlete[$athleteId] ?? null;
    $isPaid = $payment && (($payment['status'] ?? '') === 'paid');

    $isSnapshotLocked = $payment && in_array(($payment['status'] ?? ''), ['pending', 'paid'], true);
    $snapshot = $payment && !empty($payment['receipt_snapshot_json'])
        ? json_decode((string)$payment['receipt_snapshot_json'], true)
        : null;
    $snapshotBreakdown = is_array($snapshot) && is_array($snapshot['breakdown'] ?? null)
        ? $snapshot['breakdown']
        : [];
    $displaySessions = is_array($snapshotBreakdown) && array_key_exists('total_sessions', $snapshotBreakdown)
        ? (int)$snapshotBreakdown['total_sessions']
        : (($payment && in_array((string)($payment['status'] ?? ''), ['pending', 'paid'], true))
            ? (int)($payment['planned_sessions'] ?? 0)
            : (int)($stats['billed_sessions'] ?? 0));
    $displayAmount = $isSnapshotLocked
        ? (float)($payment['billed_amount'] ?? 0)
        : ($billable['amount'] !== null ? (float)$billable['amount'] : null);

    $totalSessions += $displaySessions;
    if ($displayAmount !== null) {
        $totalAmount += $displayAmount;
    }

    if ($isPaid) {
        $totalPaidAmount += (float)$payment['billed_amount'];
        $totalPaidAthleteIds[$athleteId] = true;
    }

    foreach ($additionalPaymentsByAthlete[$athleteId] ?? [] as $additionalPayment) {
        $issueNumber = max(2, (int)$additionalPayment['issue_number']);
        $issueStats = fetchBillingStats(
            $pdo,
            $coachId,
            $selectedMonthSql,
            $hasIsMakeupSession,
            $hasBillingMonth,
            $hasSecondAthlete,
            $athleteId,
            $issueNumber
        )[$athleteId] ?? ['billed_sessions' => 0];
        $issueSnapshot = json_decode((string)($additionalPayment['receipt_snapshot_json'] ?? ''), true);
        $issueSnapshotEvents = is_array($issueSnapshot) && is_array($issueSnapshot['event_rows'] ?? null)
            ? $issueSnapshot['event_rows']
            : null;
        $totalSessions += $issueSnapshotEvents !== null
            ? count($issueSnapshotEvents)
            : (int)$issueStats['billed_sessions'];
        $totalAmount += (float)$additionalPayment['billed_amount'];
        if ((string)$additionalPayment['status'] === 'paid') {
            $totalPaidAmount += (float)$additionalPayment['billed_amount'];
            $totalPaidAthleteIds[$athleteId] = true;
        }
    }
}

renderHeader('Platby', false, true);
?>

<?php if (!empty($onlineBillingRows)): ?>
<div class="card border-warning shadow-sm mb-4">
    <div class="card-header bg-warning text-dark fw-bold"><i class="fas fa-laptop me-2"></i>Online tréninky v tomto vyúčtování</div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>Datum</th><th>Položka</th><th>Sportovec</th><th class="text-end">Částka</th></tr></thead><tbody>
    <?php foreach ($onlineBillingRows as $row): ?><tr><td><?= h(formatDate((string)$row['billing_date'])) ?></td><td><span class="badge bg-warning text-dark">ONLINE</span> <?= h((string)$row['description']) ?></td><td><?= h($row['first_name'] . ' ' . $row['last_name']) ?></td><td class="text-end fw-semibold"><?= number_format((float)$row['amount'], 2, ',', ' ') ?> Kč</td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<?php if (!empty($schemaWarnings)): ?>
<div class="alert alert-warning">
    <strong>Platby běží v omezeném režimu.</strong>
    <div class="small mt-1">Některé databázové změny se nepodařilo automaticky aplikovat.</div>
    <ul class="mb-0 mt-2">
        <?php foreach ($schemaWarnings as $warn): ?>
        <li><?= h($warn) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-1"><i class="fas fa-wallet me-2 text-warning"></i>Platby</h2>
        <div class="text-muted">Částky se počítají z kalendáře podle hrazeného měsíce. Náhradní termín se může započítat do jiného měsíce než kdy fyzicky proběhne.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/payments.php?month=<?= urlencode($prevMonthParam) ?>">
            <i class="fas fa-chevron-left me-1"></i>Předchozí měsíc
        </a>
        <form method="get" class="d-flex gap-2 align-items-center">
            <input type="month" name="month" class="form-control form-control-sm" value="<?= h($selectedMonthParam) ?>">
            <button type="submit" class="btn btn-dark btn-sm">Zobrazit</button>
        </form>
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/payments.php?month=<?= urlencode($nextMonthParam) ?>">
            Další měsíc<i class="fas fa-chevron-right ms-1"></i>
        </a>
    </div>
</div>

<?php if ($coachBankAccount === null): ?>
<div class="alert alert-info">
    QR platby se zobrazí po nastavení platného čísla účtu v <a href="<?= BASE_URL ?>/profile.php">profilu trenéra</a>.
    Akceptováno je české číslo účtu (např. 123456789/0800) nebo validní IBAN.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Hrazený měsíc</div>
                <div class="fs-5 fw-bold"><?= h($monthTitle) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Započítané tréninky</div>
                <div class="fs-5 fw-bold"><?= (int)$totalSessions ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Očekávaná částka</div>
                <div class="fs-5 fw-bold"><?= number_format($totalAmount, 0, ',', ' ') ?> Kč</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Uhrazeno</div>
                <div class="fs-5 fw-bold"><?= number_format($totalPaidAmount, 0, ',', ' ') ?> Kč</div>
                <div class="small text-muted"><?= count($totalPaidAthleteIds) ?> sportovců</div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <strong>Výzvy k platbě se otevírají jednotlivě pro sportovce.</strong>
        <div class="small mt-1">
            Vyberte sportovce v řádku a použijte tlačítko Otevřít výzvu.
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Sportovec</th>
                        <th>Sazba</th>
                        <th>Tréninky</th>
                        <th>Částka</th>
                        <th>Stav</th>
                        <th class="text-end">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($athletes as $athlete): ?>
                        <?php
                        $athleteId = (int)$athlete['id'];
                        $stats = $statsByAthlete[$athleteId] ?? [
                            'billed_sessions' => 0,
                            'single_sessions' => 0,
                            'paired_sessions' => 0,
                            'makeup_sessions' => 0,
                            'transferred_sessions' => 0,
                        ];
                        $payment = $paymentsByAthlete[$athleteId] ?? null;
                        $isAthleteReleased = isAthleteMonthReleased($athleteId, $isMonthReleased, $releasedAthleteIds);
                        $rate = $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
                        $pairedRate = ($hasPairedTrainingRate && array_key_exists('paired_training_rate', $athlete) && $athlete['paired_training_rate'] !== null)
                            ? (float)$athlete['paired_training_rate']
                            : $rate;
                        $rawSessions = (int)$stats['billed_sessions'];
                        $rawSingleSessions = (int)($stats['single_sessions'] ?? 0);
                        $rawPairedSessions = (int)($stats['paired_sessions'] ?? 0);
                        $forfeitedCompensations = (int)($forfeitedCurrentMonthByAthlete[$athleteId] ?? 0);
                        $carryoverUsedNow = resolveCarryoverUsageForStats($stats, (int)($outstandingByAthlete[$athleteId] ?? 0));
                        $billable = computeBillableBreakdown($stats, $carryoverUsedNow, $rate, $pairedRate);
                        $currentSessions = (int)$billable['billable_sessions'];
                        $currentAmount = $billable['amount'];
                        $onlineBillingAmount = onlineTrainingBillingAmount($pdo, $coachId, $athleteId, $selectedMonthSql);
                        if ($currentAmount !== null) {
                            $currentAmount = (float)$currentAmount + $onlineBillingAmount;
                        } elseif ($onlineBillingAmount > 0) {
                            $currentAmount = $onlineBillingAmount;
                        }
                        $isPaid = $payment && (($payment['status'] ?? '') === 'paid');
                        $isSnapshotLocked = $payment && in_array(($payment['status'] ?? ''), ['pending', 'paid'], true);
                        $receiptSnapshot = $payment && !empty($payment['receipt_snapshot_json'])
                            ? json_decode((string)$payment['receipt_snapshot_json'], true)
                            : null;
                        $receiptBreakdown = is_array($receiptSnapshot) && is_array($receiptSnapshot['breakdown'] ?? null)
                            ? $receiptSnapshot['breakdown']
                            : [];
                        $displayStats = $stats;
                        $snapshotEventRows = is_array($receiptSnapshot) && is_array($receiptSnapshot['event_rows'] ?? null)
                            ? $receiptSnapshot['event_rows']
                            : null;
                        if ($snapshotEventRows !== null) {
                            $displayStats['billed_sessions'] = count($snapshotEventRows);
                            $displayStats['single_sessions'] = 0;
                            $displayStats['paired_sessions'] = 0;
                            $displayStats['makeup_sessions'] = 0;
                            $displayStats['transferred_sessions'] = 0;
                            foreach ($snapshotEventRows as $snapshotEvent) {
                                $isPairedSnapshot = !empty($snapshotEvent['is_paired']);
                                $displayStats[$isPairedSnapshot ? 'paired_sessions' : 'single_sessions']++;
                                if (!empty($snapshotEvent['is_makeup_session'])) {
                                    $displayStats['makeup_sessions']++;
                                }
                                $snapshotStartMonth = date('Y-m-01', strtotime((string)($snapshotEvent['starts_at'] ?? '')));
                                $snapshotBillingMonth = date('Y-m-01', strtotime((string)($snapshotEvent['billing_month'] ?? '')));
                                if ($snapshotStartMonth !== $snapshotBillingMonth) {
                                    $displayStats['transferred_sessions']++;
                                }
                            }
                        }
                        $displaySessions = $isSnapshotLocked
                            ? (int)($payment['planned_sessions'] ?? 0)
                            : $currentSessions;
                        $displayRawSessions = $snapshotEventRows !== null
                            ? count($snapshotEventRows)
                            : ($isSnapshotLocked ? (int)($payment['planned_sessions'] ?? 0) : $rawSessions);
                        $displayAmount = $isSnapshotLocked
                            ? (float)($payment['billed_amount'] ?? 0)
                            : ($currentAmount !== null ? (float)$currentAmount : null);
                        $paymentNote = paymentAsciiText($coachLastName . ' ' . $selectedMonth->format('m/Y'));
                        $athleteName = trim((string)$athlete['last_name'] . ' ' . (string)$athlete['first_name']);
                        $athleteEmail = trim((string)($athlete['email'] ?? ''));
                        $athletePhone = preg_replace('/\s+/', '', trim((string)($athlete['phone_contact'] ?? ''))) ?? '';
                        $shareAmountText = $displayAmount !== null
                            ? number_format($displayAmount, 0, ',', ' ') . ' Kč'
                            : '0 Kč';
                        $shareText = 'Výzva k platbě za tréninky ' . $selectedMonth->format('m/Y')
                            . ' pro ' . $athleteName
                            . '. Částka: ' . $shareAmountText
                            . '. Účet: ' . ($coachBankAccount ?? '')
                            . '. Poznámka: ' . $paymentNote
                            . '. QR: ';
                        $paymentQrUrl = ($isAthleteReleased && $coachBankAccount !== null && $displayAmount !== null && $displayAmount > 0)
                            ? buildPaymentQrUrl($coachBankAccount, $displayAmount, $paymentNote)
                            : null;
                        $hasDiff = $payment && (((int)$payment['planned_sessions'] !== $currentSessions) || ((float)$payment['billed_amount'] !== (float)($currentAmount ?? 0)));
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= h(trim((string)$athlete['last_name'] . ' ' . (string)$athlete['first_name'])) ?></div>
                                <?php if ((int)$displayStats['makeup_sessions'] > 0): ?>
                                    <div class="small text-muted">Náhradní termíny: <?= (int)$displayStats['makeup_sessions'] ?>x</div>
                                <?php endif; ?>
                                <?php if ((int)$displayStats['transferred_sessions'] > 0): ?>
                                    <div class="small text-muted">Z toho v jiném kalendářním měsíci: <?= (int)$displayStats['transferred_sessions'] ?>x</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rate !== null): ?>
                                    <span class="fw-semibold"><?= number_format($rate, 0, ',', ' ') ?> Kč</span>
                                    <?php if ($pairedRate !== null && abs($pairedRate - $rate) > 0.0001): ?>
                                        <div class="small text-muted">Párová sazba: <?= number_format($pairedRate, 0, ',', ' ') ?> Kč</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-danger">Chybí sazba</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-semibold"><?= $displaySessions ?></span>
                                <div class="small text-muted">tréninků v této výzvě</div>
                                <div class="small text-muted">celkem ve výzvě: <?= $displayRawSessions ?></div>
                                <?php if ((int)$displayStats['paired_sessions'] > 0): ?>
                                    <div class="small text-muted">z toho párové: <?= (int)$displayStats['paired_sessions'] ?>x</div>
                                <?php endif; ?>
                                <?php if ((int)$displayStats['single_sessions'] > 0): ?>
                                    <div class="small text-muted">z toho individuální: <?= (int)$displayStats['single_sessions'] ?>x</div>
                                <?php endif; ?>
                                <?php if ($carryoverUsedNow > 0): ?>
                                    <div class="small text-warning">Započteno jako náhrada: <?= $carryoverUsedNow ?>x</div>
                                <?php endif; ?>
                                <?php if ($forfeitedCompensations > 0): ?>
                                    <div class="small text-danger">Propadlá kompenzace: <?= $forfeitedCompensations ?> tréninků</div>
                                <?php endif; ?>
                                <?php if ($isPaid): ?>
                                    <div class="small text-muted">Uhrazená evidence je uzamčená.</div>
                                <?php elseif ($isSnapshotLocked): ?>
                                    <div class="small text-muted">Částka ve výzvě je uzamčená do uzavření nebo úhrady.</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($displayAmount !== null): ?>
                                    <span class="fw-semibold"><?= number_format($displayAmount, 0, ',', ' ') ?> Kč</span>
                                    <?php if ((float)$displayAmount <= 0.0001): ?>
                                        <div class="small text-success">Fakturovaná částka: 0 Kč</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Nelze spočítat</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isPaid): ?>
                                    <span class="badge bg-success">Uhrazeno</span>
                                    <?php if (!empty($payment['paid_at'])): ?>
                                        <div class="small text-muted mt-1"><?= h(date('d.m.Y H:i', strtotime((string)$payment['paid_at']))) ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted">Evidováno: <?= (int)$payment['planned_sessions'] ?> tréninků / <?= number_format((float)$payment['billed_amount'], 0, ',', ' ') ?> Kč</div>
                                    <?php if ($hasDiff): ?>
                                        <div class="small text-danger">Kalendář se od poslední evidence změnil.</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($isAthleteReleased): ?>
                                        <span class="badge bg-secondary">Neuhrazeno</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Čeká na vystavení výzvy</span>
                                    <?php endif; ?>
                                    <?php if ($isSnapshotLocked && $hasDiff): ?>
                                        <div class="small text-danger mt-1">Kalendář se od otevření výzvy změnil. Před odesláním výzvu zkontrolujte a znovu otevřete.</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                    <a href="<?= BASE_URL ?>/payment_receipt.php?month=<?= urlencode($selectedMonthParam) ?>&athlete_id=<?= $athleteId ?>&issue=1" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-receipt me-1"></i>Účtenka
                                    </a>
                                    <?php if ($paymentQrUrl !== null): ?>
                                        <button
                                            type="button"
                                            class="btn btn-outline-dark btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#paymentQrModal"
                                            data-athlete-id="<?= $athleteId ?>"
                                            data-issue-number="1"
                                            data-athlete-name="<?= h($athleteName) ?>"
                                            data-athlete-email="<?= h($athleteEmail) ?>"
                                            data-athlete-phone="<?= h($athletePhone) ?>"
                                            data-sessions="<?= (int)$displayRawSessions ?>"
                                            data-amount="<?= h($shareAmountText) ?>"
                                            data-account="<?= h($coachBankAccount ?? '') ?>"
                                            data-note="<?= h($paymentNote) ?>"
                                            data-qr-url="<?= h($paymentQrUrl) ?>"
                                            data-share-text="<?= h($shareText) ?>"
                                        >
                                            <i class="fas fa-qrcode me-1"></i>QR platba
                                        </button>
                                    <?php endif; ?>
                                    <a href="<?= BASE_URL ?>/athlete_edit.php?id=<?= $athleteId ?>&return_to=<?= urlencode(BASE_URL . '/payments.php?month=' . $selectedMonthParam) ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-pen me-1"></i>Sazba
                                    </a>
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="month" value="<?= h($selectedMonthParam) ?>">
                                        <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                                        <input type="hidden" name="action" value="<?= $isAthleteReleased ? 'unrelease_month_athlete' : 'release_month_athlete' ?>">
                                        <button type="submit" class="btn <?= $isAthleteReleased ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-sm">
                                            <?= $isAthleteReleased ? 'Zavřít výzvu' : 'Otevřít výzvu' ?>
                                        </button>
                                    </form>
                                    <?php if ($payment && ($payment['status'] ?? '') === 'paid'): ?>
                                        <form method="post" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="month" value="<?= h($selectedMonthParam) ?>">
                                            <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                                            <input type="hidden" name="issue_number" value="1">
                                            <input type="hidden" name="action" value="mark_unpaid">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Zrušit úhradu</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="month" value="<?= h($selectedMonthParam) ?>">
                                            <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                                            <input type="hidden" name="issue_number" value="1">
                                            <input type="hidden" name="action" value="mark_paid">
                                            <button type="submit" class="btn btn-success btn-sm" <?= ($rate === null || !$isAthleteReleased) ? 'disabled' : '' ?>>Označit uhrazeno</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php foreach ($additionalPaymentsByAthlete[$athleteId] ?? [] as $additionalPayment): ?>
                            <?php
                            $additionalIssue = max(2, (int)$additionalPayment['issue_number']);
                            $additionalStats = fetchBillingStats(
                                $pdo,
                                $coachId,
                                $selectedMonthSql,
                                $hasIsMakeupSession,
                                $hasBillingMonth,
                                $hasSecondAthlete,
                                $athleteId,
                                $additionalIssue
                            )[$athleteId] ?? ['billed_sessions' => 0, 'single_sessions' => 0, 'paired_sessions' => 0];
                            $additionalSnapshot = json_decode((string)($additionalPayment['receipt_snapshot_json'] ?? ''), true);
                            $additionalSnapshotEvents = is_array($additionalSnapshot) && is_array($additionalSnapshot['event_rows'] ?? null)
                                ? $additionalSnapshot['event_rows']
                                : null;
                            if ($additionalSnapshotEvents !== null) {
                                $additionalStats['billed_sessions'] = count($additionalSnapshotEvents);
                                $additionalStats['single_sessions'] = 0;
                                $additionalStats['paired_sessions'] = 0;
                                foreach ($additionalSnapshotEvents as $snapshotEvent) {
                                    $additionalStats[!empty($snapshotEvent['is_paired']) ? 'paired_sessions' : 'single_sessions']++;
                                }
                            }
                            $additionalAmount = (float)$additionalPayment['billed_amount'];
                            $additionalStatus = (string)$additionalPayment['status'];
                            $additionalPending = $additionalStatus === 'pending';
                            $additionalPaid = $additionalStatus === 'paid';
                            $additionalQr = ($additionalPending && $isAthleteReleased && $coachBankAccount !== null && $additionalAmount > 0)
                                ? buildPaymentQrUrl($coachBankAccount, $additionalAmount, paymentAsciiText($coachLastName . ' ' . $selectedMonth->format('m/Y') . ' dodatecna'))
                                : null;
                            ?>
                            <tr class="table-light">
                                <td>
                                    <div class="fw-semibold"><?= h(trim((string)$athlete['last_name'] . ' ' . (string)$athlete['first_name'])) ?></div>
                                    <div class="small text-primary">Dodatečná výzva #<?= $additionalIssue ?></div>
                                </td>
                                <td><?= $additionalPayment['session_rate'] !== null ? number_format((float)$additionalPayment['session_rate'], 0, ',', ' ') . ' Kč' : '—' ?></td>
                                <td><span class="fw-semibold"><?= (int)$additionalStats['billed_sessions'] ?></span><div class="small text-muted">tréninků ve výzvě</div></td>
                                <td><span class="fw-semibold"><?= number_format($additionalAmount, 0, ',', ' ') ?> Kč</span></td>
                                <td>
                                    <?php if ($additionalPaid): ?>
                                        <span class="badge bg-success">Uhrazeno</span>
                                        <?php if (!empty($additionalPayment['paid_at'])): ?><div class="small text-muted"><?= h(date('d.m.Y H:i', strtotime((string)$additionalPayment['paid_at']))) ?></div><?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Čeká na úhradu</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2 flex-wrap">
                                        <a href="<?= BASE_URL ?>/payment_receipt.php?month=<?= urlencode($selectedMonthParam) ?>&athlete_id=<?= $athleteId ?>&issue=<?= $additionalIssue ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-receipt me-1"></i>Účtenka</a>
                                        <?php if ($additionalQr !== null): ?>
                                            <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#paymentQrModal" data-athlete-id="<?= $athleteId ?>" data-issue-number="<?= $additionalIssue ?>" data-athlete-name="<?= h($athleteName) ?>" data-athlete-email="<?= h($athleteEmail) ?>" data-athlete-phone="<?= h($athletePhone) ?>" data-sessions="<?= (int)$additionalStats['billed_sessions'] ?>" data-amount="<?= h(number_format($additionalAmount, 0, ',', ' ') . ' Kč') ?>" data-account="<?= h($coachBankAccount ?? '') ?>" data-note="<?= h(paymentAsciiText($coachLastName . ' ' . $selectedMonth->format('m/Y') . ' dodatecna')) ?>" data-qr-url="<?= h($additionalQr) ?>" data-share-text="<?= h('Dodatečná výzva za ' . $selectedMonth->format('m/Y') . ' pro ' . $athleteName . '. Částka: ' . number_format($additionalAmount, 0, ',', ' ') . ' Kč. Účet: ' . ($coachBankAccount ?? '')) ?>"><i class="fas fa-qrcode me-1"></i>QR platba</button>
                                        <?php endif; ?>
                                        <form method="post" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="month" value="<?= h($selectedMonthParam) ?>">
                                            <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                                            <input type="hidden" name="issue_number" value="<?= $additionalIssue ?>">
                                            <input type="hidden" name="action" value="<?= $additionalPaid ? 'mark_unpaid' : 'mark_paid' ?>">
                                            <button type="submit" class="btn <?= $additionalPaid ? 'btn-outline-danger' : 'btn-success' ?> btn-sm"><?= $additionalPaid ? 'Zrušit úhradu' : 'Označit uhrazeno' ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php if (empty($athletes)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Nemáte žádné sportovce.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentQrModal" tabindex="-1" aria-labelledby="paymentQrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentQrModalLabel">QR výzva k platbě</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <img id="paymentQrImage" src="" alt="QR platba" class="img-fluid border rounded p-2 bg-white" style="max-width:220px;">
                </div>
                <div class="small text-muted mb-1" id="paymentQrAthlete"></div>
                <div class="small"><strong>Tréninků:</strong> <span id="paymentQrSessions"></span></div>
                <div class="small"><strong>Částka:</strong> <span id="paymentQrAmount"></span></div>
                <div class="small"><strong>Účet:</strong> <span id="paymentQrAccount"></span></div>
                <div class="small"><strong>Poznámka:</strong> <span id="paymentQrNote"></span></div>
            </div>
            <div class="modal-footer d-flex justify-content-between flex-wrap gap-2">
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" id="paymentQrEmailSendBtn" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-envelope me-1"></i>E-mail
                    </button>
                    <a id="paymentQrWhatsappLink" href="#" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm">
                        <i class="fab fa-whatsapp me-1"></i>WhatsApp
                    </a>
                    <a id="paymentQrSmsLink" href="#" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-comment-sms me-1"></i>SMS
                    </a>
                    <button type="button" id="paymentQrNativeShare" class="btn btn-dark btn-sm">
                        <i class="fas fa-share-alt me-1"></i>Sdílet
                    </button>
                </div>
                <button type="button" class="btn btn-outline-dark btn-sm" data-bs-dismiss="modal">Zavřít</button>
            </div>
        </div>
    </div>
</div>

<form id="paymentQrEmailForm" method="post" class="d-none">
    <?= csrfField() ?>
    <input type="hidden" name="month" value="<?= h($selectedMonthParam) ?>">
    <input type="hidden" name="athlete_id" id="paymentQrEmailAthleteId" value="">
    <input type="hidden" name="issue_number" id="paymentQrEmailIssueNumber" value="1">
    <input type="hidden" name="action" value="send_payment_email">
</form>

<script>
(function () {
    const modal = document.getElementById('paymentQrModal');
    if (!modal) return;

    const qrImage = document.getElementById('paymentQrImage');
    const athleteLabel = document.getElementById('paymentQrAthlete');
    const amountLabel = document.getElementById('paymentQrAmount');
    const sessionsLabel = document.getElementById('paymentQrSessions');
    const accountLabel = document.getElementById('paymentQrAccount');
    const noteLabel = document.getElementById('paymentQrNote');
    const emailSendBtn = document.getElementById('paymentQrEmailSendBtn');
    const emailForm = document.getElementById('paymentQrEmailForm');
    const emailAthleteId = document.getElementById('paymentQrEmailAthleteId');
    const emailIssueNumber = document.getElementById('paymentQrEmailIssueNumber');
    const whatsappLink = document.getElementById('paymentQrWhatsappLink');
    const smsLink = document.getElementById('paymentQrSmsLink');
    const nativeShareBtn = document.getElementById('paymentQrNativeShare');

    let currentSharePayload = null;
    let canSendEmail = false;

    modal.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;

        const data = trigger.dataset;
        const athleteId = data.athleteId || '';
        const athleteName = data.athleteName || '';
        const athleteEmail = data.athleteEmail || '';
        const athletePhone = data.athletePhone || '';
        const amount = data.amount || '';
        const sessions = data.sessions || '0';
        const account = data.account || '';
        const note = data.note || '';
        const qrUrl = data.qrUrl || '';
        const shareTextBase = data.shareText || '';
        const shareText = shareTextBase + qrUrl;

        qrImage.src = qrUrl;
        athleteLabel.textContent = athleteName;
        amountLabel.textContent = amount;
        sessionsLabel.textContent = sessions;
        accountLabel.textContent = account;
        noteLabel.textContent = note;

        emailAthleteId.value = athleteId;
        emailIssueNumber.value = data.issueNumber || '1';

        if (athleteEmail) {
            canSendEmail = true;
            emailSendBtn.classList.remove('disabled');
            emailSendBtn.setAttribute('aria-disabled', 'false');
        } else {
            canSendEmail = false;
            emailSendBtn.classList.add('disabled');
            emailSendBtn.setAttribute('aria-disabled', 'true');
        }

        whatsappLink.href = 'https://wa.me/?text=' + encodeURIComponent(shareText);

        const smsTarget = athletePhone ? athletePhone : '';
        smsLink.href = 'sms:' + encodeURIComponent(smsTarget) + '?body=' + encodeURIComponent(shareText);

        currentSharePayload = {
            title: 'Výzva k platbě',
            text: shareText,
            url: qrUrl,
        };

        if (!navigator.share) {
            nativeShareBtn.classList.add('d-none');
        } else {
            nativeShareBtn.classList.remove('d-none');
        }
    });

    nativeShareBtn.addEventListener('click', async function () {
        if (!navigator.share || !currentSharePayload) return;
        try {
            await navigator.share(currentSharePayload);
        } catch (e) {
            // Uživatel sdílení zavřel nebo zařízení sdílení nepodporuje.
        }
    });

    emailSendBtn.addEventListener('click', function () {
        if (!canSendEmail || !emailForm || !emailAthleteId.value) return;
        if (!confirm(`Před odesláním zkontrolujte výzvu ${noteLabel.textContent}: ${sessionsLabel.textContent} tréninků, ${amountLabel.textContent}. Odeslat e-mailem?`)) return;
        emailForm.submit();
    });
})();
</script>

<?php renderFooter();
