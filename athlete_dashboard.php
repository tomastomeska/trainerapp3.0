<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

if (!function_exists('getAthleteWeightLogById')) {
    function getAthleteWeightLogById(int $logId, int $athleteId = 0): ?array {
        $pdo = getDB();
        $sql = 'SELECT * FROM athlete_weight_logs WHERE id = ?';
        $params = [$logId];

        if ($athleteId > 0) {
            $sql .= ' AND athlete_id = ?';
            $params[] = $athleteId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('updateAthleteWeightLog')) {
    function updateAthleteWeightLog(int $logId, int $athleteId, string $measuredAt, float $weightKg): bool {
        if (!getAthleteWeightLogById($logId, $athleteId)) {
            return false;
        }

        $pdo = getDB();
        $stmt = $pdo->prepare(
            'UPDATE athlete_weight_logs
             SET measured_at = ?, weight_kg = ?
             WHERE id = ? AND athlete_id = ?'
        );

        return $stmt->execute([$measuredAt, $weightKg, $logId, $athleteId]);
    }
}

if (!function_exists('deleteAthleteWeightLog')) {
    function deleteAthleteWeightLog(int $logId, int $athleteId): bool {
        if (!getAthleteWeightLogById($logId, $athleteId)) {
            return false;
        }

        $pdo = getDB();
        $stmt = $pdo->prepare('DELETE FROM athlete_weight_logs WHERE id = ? AND athlete_id = ?');

        return $stmt->execute([$logId, $athleteId]);
    }
}

if (!function_exists('athleteDashboardPaymentColumnExists')) {
    function athleteDashboardPaymentColumnExists(PDO $pdo, string $tableName, string $columnName): bool {
        $quotedColumn = $pdo->quote($columnName);
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE {$quotedColumn}");
        return $stmt !== false && (bool)$stmt->fetch();
    }
}

if (!function_exists('athleteDashboardMonthKey')) {
    function athleteDashboardMonthKey(string $raw): string {
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
}

if (!function_exists('athleteDashboardStatusRank')) {
    function athleteDashboardStatusRank(string $status): int {
        if ($status === 'paid') {
            return 2;
        }
        if ($status === 'pending') {
            return 1;
        }
        return 0;
    }
}

if (!function_exists('athleteDashboardShouldReplaceMonthPayment')) {
    function athleteDashboardShouldReplaceMonthPayment(?array $current, array $candidate): bool {
        if ($current === null) {
            return true;
        }

        $currentRank = athleteDashboardStatusRank((string)($current['status'] ?? ''));
        $candidateRank = athleteDashboardStatusRank((string)($candidate['status'] ?? ''));
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
}

if (!function_exists('athleteDashboardResolveCarryoverApplied')) {
    function athleteDashboardResolveCarryoverApplied(array $stats, int $outstandingBefore): int {
        $rawSessions = max(0, (int)($stats['billed_sessions'] ?? 0));
        if ($rawSessions === 0) {
            return 0;
        }

        $fromHistory = min(max(0, $outstandingBefore), $rawSessions);
        $fromTransferred = min(max(0, (int)($stats['transferred_sessions'] ?? 0)), $rawSessions);

        // Sessions billed in another month must not be charged again in the current month.
        return max($fromHistory, $fromTransferred);
    }
}

if (!function_exists('athleteDashboardBackfillPendingSnapshot')) {
    function athleteDashboardBackfillPendingSnapshot(PDO $pdo, int $coachId, int $athleteId, string $billingMonthSql, ?float $sessionRate, int $plannedSessions, int $carryoverUsed, float $billedAmount): void {
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
}

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

$athleteStmt = $pdo->prepare(
    'SELECT a.*, c.id AS coach_id, c.name AS coach_name, c.username AS coach_username
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

$supportBankAccount = trim(getAppSetting('support_bank_account', ''));
$supportContributorName = trim((string)($athlete['first_name'] . ' ' . $athlete['last_name']));
if ($supportContributorName === '') {
    $supportContributorName = 'sportovec';
}
$supportBankAccountForQr = accountForSpd($supportBankAccount);
$supportQrNote = paymentAsciiText('Podpora TrainerApp - ' . $supportContributorName);

$unreadInboxCount = 0;
try {
    $unreadStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM athlete_notifications
         WHERE athlete_id = ?
           AND read_at IS NULL'
    );
    $unreadStmt->execute([$athleteId]);
    $unreadInboxCount = (int)$unreadStmt->fetchColumn();
} catch (Throwable $e) {
    $unreadInboxCount = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_dashboard.php');
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_weight' || $action === 'update_weight') {
        $weightInput = str_replace(',', '.', trim((string)($_POST['weight_kg'] ?? '')));
        $measuredAt = preg_replace('/[^0-9\-]/', '', (string)($_POST['measured_at'] ?? date('Y-m-d')));
        $weightKg = is_numeric($weightInput) ? (float)$weightInput : 0.0;
        $weightLogId = (int)($_POST['weight_log_id'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredAt)) {
            flash('danger', 'Zadejte platné datum vážení.');
        } elseif ($weightKg < 20 || $weightKg > 400) {
            flash('danger', 'Zadejte platnou hmotnost v kg.');
        } elseif ($action === 'update_weight' && $weightLogId <= 0) {
            flash('danger', 'Vybraný záznam hmotnosti nebyl nalezen.');
        } else {
            if ($action === 'save_weight') {
                addAthleteWeightLog($athleteId, $measuredAt, $weightKg, 'athlete_link', null, null);

                flash('success', 'Hmotnost byla uložena.');
            } elseif (updateAthleteWeightLog($weightLogId, $athleteId, $measuredAt, $weightKg)) {
                flash('success', 'Záznam hmotnosti byl upraven.');
            } else {
                flash('danger', 'Záznam hmotnosti se nepodařilo upravit.');
            }
        }

        redirect(BASE_URL . '/athlete_dashboard.php');
    }

    if ($action === 'delete_weight') {
        $weightLogId = (int)($_POST['weight_log_id'] ?? 0);

        if ($weightLogId <= 0) {
            flash('danger', 'Vybraný záznam hmotnosti nebyl nalezen.');
        } elseif (deleteAthleteWeightLog($weightLogId, $athleteId)) {
            flash('success', 'Záznam hmotnosti byl smazán.');
        } else {
            flash('danger', 'Záznam hmotnosti se nepodařilo smazat.');
        }

        redirect(BASE_URL . '/athlete_dashboard.php');
    }
}

$sessionsStmt = $pdo->prepare(
    'SELECT ts.id, ts.started_at, ts.completed_at, ts.location, ws.name AS set_name
     FROM training_sessions ts
     JOIN workout_sets ws ON ws.id = ts.workout_set_id
     WHERE ts.athlete_id = ?
       AND ts.deleted_by_coach_at IS NULL
     ORDER BY ts.started_at DESC
     LIMIT 120'
);
$sessionsStmt->execute([$athleteId]);
$sessions = $sessionsStmt->fetchAll();

$weightHistory = getAthleteWeightHistory($athleteId, 200);
usort($weightHistory, static function (array $a, array $b): int {
    $dateCompare = strcmp((string)($b['measured_at'] ?? ''), (string)($a['measured_at'] ?? ''));
    if ($dateCompare !== 0) {
        return $dateCompare;
    }

    return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
});
$weightStats = getAthleteWeightStats($athleteId);
$editWeightLogId = intParam($_GET, 'edit_weight');
$editingWeightLog = $editWeightLogId > 0 ? getAthleteWeightLogById($editWeightLogId, $athleteId) : null;
$weightFormAction = $editingWeightLog ? 'update_weight' : 'save_weight';
$weightFormDate = $editingWeightLog['measured_at'] ?? date('Y-m-d');
$weightFormValue = $editingWeightLog['weight_kg'] ?? '';
$weightPreviewLimit = 5;
$weightVisibleRows = array_slice($weightHistory, 0, $weightPreviewLimit);
$weightCollapsedRows = array_slice($weightHistory, $weightPreviewLimit);
$weightMobileRows = array_slice($weightHistory, 0, 4);
$weightMobileCollapsedRows = array_slice($weightHistory, 4);
$weightShouldExpandAll = $editingWeightLog !== null;
$trainingPreviewLimit = 5;
$trainingVisibleRows = array_slice($sessions, 0, $trainingPreviewLimit);
$trainingCollapsedRows = array_slice($sessions, $trainingPreviewLimit);
$trainingMobileRows = array_slice($sessions, 0, 4);
$trainingMobileCollapsedRows = array_slice($sessions, 4);

$coachDisplayName = trim((string)($athlete['coach_name'] ?: $athlete['coach_username']));
$coachLastNameParts = preg_split('/\s+/u', $coachDisplayName) ?: [];
$coachLastName = trim((string)end($coachLastNameParts));
if ($coachLastName === '') {
    $coachLastName = 'Trener';
}

$hasBillingMonth = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_events', 'billing_month');
$hasIsMakeup = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session');
$hasSecondAthlete = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
$hasRequestedByAthlete = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_events', 'requested_by_athlete_id');
$hasCoachBankAccount = athleteDashboardPaymentColumnExists($pdo, 'coaches', 'bank_account');
$hasCarryoverUsed = athleteDashboardPaymentColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions');
$hasPairedTrainingRate = athleteDashboardPaymentColumnExists($pdo, 'athletes', 'paired_training_rate');
$hasCancellationTable = false;
try {
    $checkCancellationTable = $pdo->query("SHOW TABLES LIKE 'coach_calendar_event_cancellations'");
    $hasCancellationTable = $checkCancellationTable !== false && (bool)$checkCancellationTable->fetchColumn();
} catch (Throwable $e) {
    $hasCancellationTable = false;
}

$activeReplacementNotice = null;
if ($hasCancellationTable
    && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
    && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
) {
    $hasReplacementDeadline = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at');
    $hasPaymentSnapshot = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot');
    $hasCanceledAt = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at');
    $hasStartsAt = athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at');
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

$pendingMonthPreview = null;
try {
    $currentMonthSql = date('Y-m-01');
    // Pending preview is calendar-facing, so group by the month of requested slot start.
    $pendingMonthExpr = "DATE_FORMAT(e.starts_at, '%Y-%m-01')";
    $isMakeupExpr = $hasIsMakeup ? 'e.is_makeup_session' : '0';
    $isPairedExpr = $hasSecondAthlete ? 'CASE WHEN e.second_athlete_id IS NOT NULL THEN 1 ELSE 0 END' : '0';

    if ($hasRequestedByAthlete) {
        $pendingFilter = 'e.requested_by_athlete_id = ?';
        $pendingParams = [$athleteId, (int)$athlete['coach_id']];
    } elseif ($hasSecondAthlete) {
        $pendingFilter = '(e.athlete_id = ? OR e.second_athlete_id = ?)';
        $pendingParams = [$athleteId, $athleteId, (int)$athlete['coach_id']];
    } else {
        $pendingFilter = 'e.athlete_id = ?';
        $pendingParams = [$athleteId, (int)$athlete['coach_id']];
    }

    $pendingStmt = $pdo->prepare(
        "SELECT
                {$pendingMonthExpr} AS pending_month,
                COUNT(*) AS total_pending,
                SUM(CASE WHEN {$isMakeupExpr} = 1 THEN 1 ELSE 0 END) AS makeup_pending,
                SUM(CASE WHEN {$isMakeupExpr} = 0 AND {$isPairedExpr} = 1 THEN 1 ELSE 0 END) AS paired_pending,
                SUM(CASE WHEN {$isMakeupExpr} = 0 AND {$isPairedExpr} = 0 THEN 1 ELSE 0 END) AS single_pending
         FROM coach_calendar_events e
         WHERE {$pendingFilter}
           AND e.coach_id = ?
           AND e.approval_status = 'pending'
         GROUP BY {$pendingMonthExpr}
         ORDER BY {$pendingMonthExpr} ASC"
    );
    $pendingStmt->execute($pendingParams);
    $pendingRows = $pendingStmt->fetchAll();

    $pendingRow = null;
    $lastPastRow = null;
    foreach ($pendingRows as $candidateRow) {
        $month = (string)($candidateRow['pending_month'] ?? '');
        if ($month === '') {
            continue;
        }
        if ($month >= $currentMonthSql) {
            $pendingRow = $candidateRow;
            break;
        }
        $lastPastRow = $candidateRow;
    }
    if ($pendingRow === null) {
        $pendingRow = $lastPastRow;
    }

    $pendingTotal = (int)($pendingRow['total_pending'] ?? 0);
    if ($pendingTotal > 0) {
        $pendingMonthSql = (string)($pendingRow['pending_month'] ?? $currentMonthSql);
        $pendingMakeup = (int)($pendingRow['makeup_pending'] ?? 0);
        $pendingSingle = (int)($pendingRow['single_pending'] ?? 0);
        $pendingPaired = (int)($pendingRow['paired_pending'] ?? 0);

        $rate = isset($athlete['training_rate']) && $athlete['training_rate'] !== null ? (float)$athlete['training_rate'] : null;
        $pairedRate = ($hasPairedTrainingRate && array_key_exists('paired_training_rate', $athlete) && $athlete['paired_training_rate'] !== null)
            ? (float)$athlete['paired_training_rate']
            : $rate;
        $estimatedAmount = ($rate !== null && $pairedRate !== null)
            ? (($pendingSingle * $rate) + ($pendingPaired * $pairedRate))
            : null;

        $pendingMonthPreview = [
            'month_label' => date('m/Y', strtotime($pendingMonthSql)),
            'total' => $pendingTotal,
            'makeup' => $pendingMakeup,
            'single' => $pendingSingle,
            'paired' => $pendingPaired,
            'estimated_amount' => $estimatedAmount,
        ];
    }
} catch (Throwable $e) {
    $pendingMonthPreview = null;
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
            $monthKey = athleteDashboardMonthKey((string)($row['billing_month'] ?? ''));
            if ($monthKey === '') {
                continue;
            }
            $currentMonthPayment = $paymentsByMonth[$monthKey] ?? null;
            if (athleteDashboardShouldReplaceMonthPayment($currentMonthPayment, $row)) {
                $paymentsByMonth[$monthKey] = $row;
            }
        }

        $rowsByMonth = [];
        foreach ($statsRows as $row) {
            $month = athleteDashboardMonthKey((string)($row['billing_month'] ?? ''));
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

        if ($hasCancellationTable
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'cancellation_scope')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_by')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at')
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
                $month = athleteDashboardMonthKey((string)($lateRow['billing_month'] ?? ''));
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

        if ($hasCancellationTable
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'cancellation_scope')
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
                $month = athleteDashboardMonthKey((string)($lockedRow['billing_month'] ?? ''));
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
                    $month = athleteDashboardMonthKey((string)($releaseAthleteRow['billing_month'] ?? ''));
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
        if ($hasCancellationTable
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at')
            && athleteDashboardPaymentColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at')
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
                $month = athleteDashboardMonthKey((string)($forfeitedRow['billing_month'] ?? ''));
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

        foreach ($rowsByMonth as $month => $stats) {
            $payment = $paymentsByMonth[$month] ?? null;
            $paymentStatus = (string)($payment['status'] ?? '');
            $rawSessions = (int)$stats['billed_sessions'];
            $rawSingleSessions = (int)($stats['single_sessions'] ?? $rawSessions);
            $rawPairedSessions = (int)($stats['paired_sessions'] ?? 0);
            $carryoverApplied = athleteDashboardResolveCarryoverApplied($stats, (int)($outstandingBeforeByMonth[$month] ?? 0));
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
                        athleteDashboardBackfillPendingSnapshot(
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

            $displayAmount = ($payment && isset($payment['billed_amount']) && $payment['billed_amount'] !== null)
                ? (float)$payment['billed_amount']
                : $amount;
            $note = paymentAsciiText($coachLastName . ' ' . date('m/Y', strtotime($month)));
            $isAthleteReleased = !empty($releasedAthleteByMonth[$month]);
            $isReleased = $isAthleteReleased || $paymentStatus === 'pending' || $paymentStatus === 'paid';
            $isPaid = $paymentStatus === 'paid';
            $isPending = ($paymentStatus === 'pending');
            $isPendingVisible = ($isPending && $isAthleteReleased);

            // Legacy data fix: if a month is released but missing snapshot row, create one now and freeze future changes.
            if ($payment === null && $isAthleteReleased && $rate !== null && $displayAmount !== null) {
                try {
                    athleteDashboardBackfillPendingSnapshot(
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
                    $isPaid = false;
                } catch (Throwable $e) {
                    // Ignore backfill failures, dashboard still shows computed values.
                }
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
                'is_released' => $isAthleteReleased,
                'is_pending' => $isPendingVisible,
                'is_paid' => $isPaid,
                'is_snapshot_locked' => $isSnapshotLocked,
            ];
        }

                $paymentRowsForView = array_slice($paymentRowsForView, 0, 3);

                $upcomingPlannedCount = 0;
                $nearestPlannedTraining = null;
                try {
                    $upcomingCountStmt = $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM coach_calendar_events
                         WHERE athlete_id = ?
                           AND starts_at >= NOW()
                           AND approval_status IN ('approved', 'pending')"
                    );
                    $upcomingCountStmt->execute([$athleteId]);
                    $upcomingPlannedCount = (int)$upcomingCountStmt->fetchColumn();

                    $nearestTrainingStmt = $pdo->prepare(
                        "SELECT starts_at, ends_at, location, custom_title, approval_status
                         FROM coach_calendar_events
                         WHERE athlete_id = ?
                           AND starts_at >= NOW()
                           AND approval_status IN ('approved', 'pending')
                         ORDER BY starts_at ASC
                         LIMIT 1"
                    );
                    $nearestTrainingStmt->execute([$athleteId]);
                    $nearestPlannedTraining = $nearestTrainingStmt->fetch() ?: null;
                } catch (Throwable $e) {
                    $upcomingPlannedCount = 0;
                    $nearestPlannedTraining = null;
                }

renderAthleteHeader('Profil sportovce', false, true);
?>

<style>
@media (max-width: 767.98px) {
    .athlete-desktop-only {
        display: none !important;
    }

    .athlete-mobile-summary-card {
        border: 1px solid #dde5ee;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }

    .athlete-mobile-summary-card__head {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        align-items: flex-start;
        padding: 0.8rem 0.85rem;
        border-bottom: 1px solid #eef2f7;
        background: #f8fafc;
    }

    .athlete-mobile-summary-card__title {
        font-weight: 800;
        font-size: 0.98rem;
        line-height: 1.2;
    }

    .athlete-mobile-summary-card__body {
        padding: 0.8rem 0.85rem;
    }

    .athlete-mobile-summary-meta {
        display: grid;
        gap: 0.5rem;
    }

    .athlete-mobile-summary-line {
        display: grid;
        gap: 0.08rem;
    }

    .athlete-mobile-summary-label {
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #64748b;
    }

    .athlete-mobile-payment-card {
        border: 1px solid #e6edf5;
        border-radius: 12px;
        padding: 0.65rem 0.7rem;
        background: #fff;
    }

    .athlete-mobile-payment-card + .athlete-mobile-payment-card {
        margin-top: 0.55rem;
    }

    .athlete-mobile-payment-top {
        display: flex;
        justify-content: space-between;
        gap: 0.6rem;
        align-items: flex-start;
        margin-bottom: 0.35rem;
    }

    .athlete-mobile-payment-month {
        font-weight: 800;
    }

    .athlete-mobile-payment-grid {
        display: grid;
        gap: 0.25rem;
        font-size: 0.84rem;
    }

    .athlete-mobile-history-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #fff;
        padding: 0.7rem;
    }

    .athlete-mobile-history-card + .athlete-mobile-history-card {
        margin-top: 0.55rem;
    }

    .athlete-mobile-history-grid {
        display: grid;
        gap: 0.25rem;
        font-size: 0.84rem;
    }

    .athlete-mobile-history-actions {
        display: flex;
        gap: 0.45rem;
        flex-wrap: wrap;
        margin-top: 0.55rem;
    }

    .athlete-mobile-accordion .accordion-button {
        padding: 0.8rem 0.9rem;
        font-weight: 700;
    }

    .athlete-mobile-accordion .accordion-body {
        padding: 0.8rem 0.75rem;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-user me-2 text-warning"></i>Můj profil</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-danger btn-sm">
            <i class="fas fa-sign-out-alt me-1"></i>Odhlásit
        </a>
    </div>
</div>

<div class="dashboard-quick-tiles mb-3">
    <a href="<?= BASE_URL ?>/athlete_zpravy.php" class="quick-tile quick-tile-danger">
        <span class="quick-tile__label"><i class="fas fa-envelope me-1"></i>Zprávy</span>
        <span class="quick-tile__value"><?= (int)$unreadInboxCount ?></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_mealplans.php" class="quick-tile quick-tile-success">
        <span class="quick-tile__label"><i class="fas fa-utensils me-1"></i>Jídelníčky</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_graphs.php" class="quick-tile quick-tile-info">
        <span class="quick-tile__label"><i class="fas fa-chart-line me-1"></i>Grafy</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_calendar.php" class="quick-tile quick-tile-warning">
        <span class="quick-tile__label"><i class="fas fa-calendar-alt me-1"></i>Kalendář</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_change_password.php" class="quick-tile quick-tile-muted">
        <span class="quick-tile__label"><i class="fas fa-key me-1"></i>Heslo</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_gallery.php" class="quick-tile quick-tile-info">
        <span class="quick-tile__label"><i class="fas fa-images me-1"></i>Galerie</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_manual.php" class="quick-tile quick-tile-success">
        <span class="quick-tile__label"><i class="fas fa-circle-question me-1"></i>Návod</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/athlete_terms.php" class="quick-tile quick-tile-warning">
        <span class="quick-tile__label"><i class="fas fa-file-contract me-1"></i>Podmínky</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
</div>

<div class="d-md-none mb-4">
    <div class="athlete-mobile-summary-card mb-3">
        <div class="athlete-mobile-summary-card__head">
            <div>
                <div class="athlete-mobile-summary-card__title"><i class="fas fa-calendar-check me-2 text-primary"></i>Nejbližší trénink</div>
            </div>
            <a href="<?= BASE_URL ?>/athlete_calendar.php" class="btn btn-outline-primary btn-sm">
                Kalendář
            </a>
        </div>
        <div class="athlete-mobile-summary-card__body">
            <?php if ($nearestPlannedTraining): ?>
            <div class="athlete-mobile-summary-meta">
                <div class="athlete-mobile-summary-line">
                    <span class="athlete-mobile-summary-label">Datum a čas</span>
                    <strong><?= formatDateTime((string)$nearestPlannedTraining['starts_at']) ?></strong>
                </div>
                <div class="athlete-mobile-summary-line">
                    <span class="athlete-mobile-summary-label">Místo</span>
                    <strong><?= !empty($nearestPlannedTraining['location']) ? h((string)$nearestPlannedTraining['location']) : 'neuvedeno' ?></strong>
                </div>
                <?php if (($nearestPlannedTraining['approval_status'] ?? 'approved') === 'pending'): ?>
                <span class="badge bg-warning text-dark align-self-start">Ke schválení</span>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="text-muted">Nejbližší termín zatím není naplánovaný.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="athlete-mobile-summary-card mb-3">
        <div class="athlete-mobile-summary-card__head">
            <div>
                <div class="athlete-mobile-summary-card__title"><i class="fas fa-wallet me-2 text-warning"></i>Platby</div>
                <div class="small text-muted">Poslední přehled</div>
            </div>
            <a href="<?= BASE_URL ?>/athlete_payments.php" class="btn btn-warning btn-sm fw-semibold">
                Všechny
            </a>
        </div>
        <div class="athlete-mobile-summary-card__body">
            <?php if (!empty($paymentRowsForView)): ?>
                <?php foreach (array_slice($paymentRowsForView, 0, 3) as $row): ?>
                <div class="athlete-mobile-payment-card">
                    <div class="athlete-mobile-payment-top">
                        <div class="athlete-mobile-payment-month"><?= h($row['month_label']) ?></div>
                        <?php if ($row['is_paid']): ?>
                            <span class="badge bg-success">Uhrazeno</span>
                        <?php elseif ($row['is_pending']): ?>
                            <span class="badge bg-warning text-dark">Čeká</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Nevystaveno</span>
                        <?php endif; ?>
                    </div>
                    <div class="athlete-mobile-payment-grid">
                        <div><span class="text-muted">Tréninky:</span> <strong><?= (int)$row['billable_sessions'] ?></strong></div>
                        <div><span class="text-muted">Částka:</span> <strong><?= $row['display_amount'] !== null ? number_format((float)$row['display_amount'], 0, ',', ' ') . ' Kč' : 'nelze spočítat' ?></strong></div>
                        <?php if (!empty($row['payment']['paid_at'])): ?>
                        <div><span class="text-muted">Uhrazeno:</span> <?= formatDateTime((string)$row['payment']['paid_at']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-muted">Aktuálně tu nemáte žádnou evidovanou platbu.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="accordion athlete-mobile-accordion" id="athleteMobileAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="headingWeightInputMobile">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseWeightInputMobile" aria-expanded="false" aria-controls="collapseWeightInputMobile">
                    Zapsat váhu
                </button>
            </h2>
            <div id="collapseWeightInputMobile" class="accordion-collapse collapse" aria-labelledby="headingWeightInputMobile" data-bs-parent="#athleteMobileAccordion">
                <div class="accordion-body">
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="<?= h($weightFormAction) ?>">
                        <?php if ($editingWeightLog): ?>
                        <input type="hidden" name="weight_log_id" value="<?= (int)$editingWeightLog['id'] ?>">
                        <?php endif; ?>
                        <div class="mb-2">
                            <label class="form-label fw-semibold" for="measured_at_mobile">Datum vážení</label>
                            <input type="date" id="measured_at_mobile" name="measured_at" class="form-control" value="<?= h((string)$weightFormDate) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="weight_kg_mobile">Hmotnost (kg)</label>
                            <input type="number" id="weight_kg_mobile" name="weight_kg" class="form-control" min="20" max="400" step="0.1" value="<?= h((string)$weightFormValue) ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-semibold">
                            <i class="fas fa-save me-1"></i><?= $editingWeightLog ? 'Uložit změny' : 'Uložit váhu' ?>
                        </button>
                    </form>
                    <small class="text-muted d-block mt-2">Svou historii hmotnosti můžete průběžně doplňovat, upravovat i mazat.</small>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header" id="headingWeightHistoryMobile">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseWeightHistoryMobile" aria-expanded="false" aria-controls="collapseWeightHistoryMobile">
                    Historie hmotnosti
                </button>
            </h2>
            <div id="collapseWeightHistoryMobile" class="accordion-collapse collapse" aria-labelledby="headingWeightHistoryMobile" data-bs-parent="#athleteMobileAccordion">
                <div class="accordion-body">
                    <?php if (empty($weightHistory)): ?>
                        <div class="text-muted text-center py-2">Zatím tu není žádný záznam hmotnosti.</div>
                    <?php else: ?>
                        <?php foreach ($weightMobileRows as $weightRow): ?>
                            <?php
                                $sourceLabel = 'Ruční záznam';
                                if (($weightRow['source'] ?? '') === 'coach') {
                                    $sourceLabel = 'Trenér';
                                } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                                    $sourceLabel = 'Sportovec';
                                }
                            ?>
                            <div class="athlete-mobile-history-card <?= $editingWeightLog && (int)$editingWeightLog['id'] === (int)$weightRow['id'] ? 'border border-warning' : '' ?>">
                                <div class="athlete-mobile-history-grid">
                                    <div><span class="text-muted">Datum:</span> <strong><?= formatDate((string)$weightRow['measured_at']) ?></strong></div>
                                    <div><span class="text-muted">Hmotnost:</span> <strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></div>
                                    <div><span class="text-muted">Zdroj:</span> <span class="badge bg-secondary"><?= h($sourceLabel) ?></span></div>
                                </div>
                                <div class="athlete-mobile-history-actions">
                                    <a href="<?= BASE_URL ?>/athlete_dashboard.php?edit_weight=<?= (int)$weightRow['id'] ?>#weight-history" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-pen me-1"></i>Upravit
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_weight">
                                        <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash me-1"></i>Smazat
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!empty($weightMobileCollapsedRows)): ?>
                        <div class="collapse mt-2" id="athleteWeightMobileMore">
                            <?php foreach ($weightMobileCollapsedRows as $weightRow): ?>
                                <?php
                                    $sourceLabel = 'Ruční záznam';
                                    if (($weightRow['source'] ?? '') === 'coach') {
                                        $sourceLabel = 'Trenér';
                                    } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                                        $sourceLabel = 'Sportovec';
                                    }
                                ?>
                                <div class="athlete-mobile-history-card">
                                    <div class="athlete-mobile-history-grid">
                                        <div><span class="text-muted">Datum:</span> <strong><?= formatDate((string)$weightRow['measured_at']) ?></strong></div>
                                        <div><span class="text-muted">Hmotnost:</span> <strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></div>
                                        <div><span class="text-muted">Zdroj:</span> <span class="badge bg-secondary"><?= h($sourceLabel) ?></span></div>
                                    </div>
                                    <div class="athlete-mobile-history-actions">
                                        <a href="<?= BASE_URL ?>/athlete_dashboard.php?edit_weight=<?= (int)$weightRow['id'] ?>#weight-history" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-pen me-1"></i>Upravit
                                        </a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_weight">
                                            <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash me-1"></i>Smazat
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center mt-2">
                            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteWeightMobileMore" aria-expanded="false" aria-controls="athleteWeightMobileMore">
                                <i class="fas fa-chevron-down me-1"></i>Zobrazit všechny záznamy (<?= count($weightMobileCollapsedRows) ?>)
                            </button>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="accordion-item">
            <h2 class="accordion-header" id="headingTrainingHistoryMobile">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTrainingHistoryMobile" aria-expanded="false" aria-controls="collapseTrainingHistoryMobile">
                    Historie tréninků
                </button>
            </h2>
            <div id="collapseTrainingHistoryMobile" class="accordion-collapse collapse" aria-labelledby="headingTrainingHistoryMobile" data-bs-parent="#athleteMobileAccordion">
                <div class="accordion-body">
                    <?php if (empty($sessions)): ?>
                        <div class="text-muted text-center py-2">Zatím nemáte žádné tréninky.</div>
                    <?php else: ?>
                        <?php foreach ($trainingMobileRows as $s): ?>
                        <div class="athlete-mobile-history-card">
                            <div class="athlete-mobile-history-grid">
                                <div><span class="text-muted">Datum:</span> <strong><?= formatDateTime((string)$s['started_at']) ?></strong></div>
                                <div><span class="text-muted">Sada:</span> <strong><?= h((string)$s['set_name']) ?></strong></div>
                                <div><span class="text-muted">Místo:</span> <?= !empty($s['location']) ? h((string)$s['location']) : '–' ?></div>
                                <div><span class="text-muted">Stav:</span> <?php if (!empty($s['completed_at'])): ?><span class="badge bg-success">Dokončeno</span><?php else: ?><span class="badge bg-warning text-dark">Naplánováno / probíhá</span><?php endif; ?></div>
                            </div>
                            <div class="athlete-mobile-history-actions">
                                <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-eye me-1"></i>Detail
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!empty($trainingMobileCollapsedRows)): ?>
                        <div class="collapse mt-2" id="athleteTrainingMobileMore">
                            <?php foreach ($trainingMobileCollapsedRows as $s): ?>
                            <div class="athlete-mobile-history-card">
                                <div class="athlete-mobile-history-grid">
                                    <div><span class="text-muted">Datum:</span> <strong><?= formatDateTime((string)$s['started_at']) ?></strong></div>
                                    <div><span class="text-muted">Sada:</span> <strong><?= h((string)$s['set_name']) ?></strong></div>
                                    <div><span class="text-muted">Místo:</span> <?= !empty($s['location']) ? h((string)$s['location']) : '–' ?></div>
                                    <div><span class="text-muted">Stav:</span> <?php if (!empty($s['completed_at'])): ?><span class="badge bg-success">Dokončeno</span><?php else: ?><span class="badge bg-warning text-dark">Naplánováno / probíhá</span><?php endif; ?></div>
                                </div>
                                <div class="athlete-mobile-history-actions">
                                    <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-eye me-1"></i>Detail
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center mt-2">
                            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteTrainingMobileMore" aria-expanded="false" aria-controls="athleteTrainingMobileMore">
                                <i class="fas fa-chevron-down me-1"></i>Zobrazit všechny tréninky (<?= count($trainingMobileCollapsedRows) ?>)
                            </button>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4 athlete-desktop-only">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white"><i class="fas fa-id-card me-2"></i>Informace</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted fw-semibold" style="width:45%">Jméno</td><td><?= h(trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])) ?></td></tr>
                    <tr><td class="text-muted fw-semibold">E-mail</td><td><?= h((string)$athlete['email']) ?></td></tr>
                    <tr><td class="text-muted fw-semibold">Trenér</td><td><?= h((string)($athlete['coach_name'] ?: $athlete['coach_username'])) ?></td></tr>
                    <tr><td class="text-muted fw-semibold">Datum narození</td><td><?= !empty($athlete['birth_date']) ? formatDate((string)$athlete['birth_date']) : '–' ?></td></tr>
                    <tr><td class="text-muted fw-semibold">Aktuální váha</td><td><?= $weightStats['current_weight'] !== null ? number_format((float)$weightStats['current_weight'], 1, ',', '') . ' kg' : '–' ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-primary text-white"><i class="fas fa-weight-scale me-2"></i>Zaznamenat aktuální hmotnost</div>
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= h($weightFormAction) ?>">
                    <?php if ($editingWeightLog): ?>
                    <input type="hidden" name="weight_log_id" value="<?= (int)$editingWeightLog['id'] ?>">
                    <?php endif; ?>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Datum vážení</label>
                        <input type="date" name="measured_at" class="form-control" value="<?= h((string)$weightFormDate) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Hmotnost (kg)</label>
                        <input type="number" name="weight_kg" class="form-control" min="20" max="400" step="0.1" value="<?= h((string)$weightFormValue) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary w-100 fw-semibold"><?= $editingWeightLog ? 'Uložit změny' : 'Uložit' ?></button>
                            <?php if ($editingWeightLog): ?>
                            <a href="<?= BASE_URL ?>/athlete_dashboard.php#weight-history" class="btn btn-outline-secondary">Zrušit</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
                <small class="text-muted d-block mt-2">Svou historii hmotnosti můžete průběžně doplňovat, upravovat i mazat.</small>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 athlete-desktop-only" id="weight-history">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-clock-rotate-left me-2"></i>Historie hmotnosti</span>
        <span class="badge bg-light text-dark"><?= count($weightHistory) ?> záznamů</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($weightHistory)): ?>
        <div class="text-center text-muted py-4">Zatím tu není žádný záznam hmotnosti.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                <tr>
                    <th>Datum</th>
                    <th>Hmotnost</th>
                    <th>Zdroj</th>
                    <th class="text-end">Akce</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($weightVisibleRows as $weightRow): ?>
                    <?php
                        $sourceLabel = 'Ruční záznam';
                        if (($weightRow['source'] ?? '') === 'coach') {
                            $sourceLabel = 'Trenér';
                        } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                            $sourceLabel = 'Sportovec';
                        }
                    ?>
                <tr class="<?= $editingWeightLog && (int)$editingWeightLog['id'] === (int)$weightRow['id'] ? 'table-warning' : '' ?>">
                    <td><?= formatDate((string)$weightRow['measured_at']) ?></td>
                    <td><strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></td>
                    <td><span class="badge bg-secondary"><?= h($sourceLabel) ?></span></td>
                    <td class="text-end">
                        <a href="<?= BASE_URL ?>/athlete_dashboard.php?edit_weight=<?= (int)$weightRow['id'] ?>#weight-history" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-pen me-1"></i>Upravit
                        </a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_weight">
                            <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (!empty($weightCollapsedRows)): ?>
                <tbody id="athleteWeightHistoryCollapse" class="collapse <?= $weightShouldExpandAll ? 'show' : '' ?>">
                <?php foreach ($weightCollapsedRows as $weightRow): ?>
                    <?php
                        $sourceLabel = 'Ruční záznam';
                        if (($weightRow['source'] ?? '') === 'coach') {
                            $sourceLabel = 'Trenér';
                        } elseif (($weightRow['source'] ?? '') === 'athlete_link') {
                            $sourceLabel = 'Sportovec';
                        }
                    ?>
                <tr class="<?= $editingWeightLog && (int)$editingWeightLog['id'] === (int)$weightRow['id'] ? 'table-warning' : '' ?>">
                    <td><?= formatDate((string)$weightRow['measured_at']) ?></td>
                    <td><strong><?= number_format((float)$weightRow['weight_kg'], 1, ',', '') ?> kg</strong></td>
                    <td><span class="badge bg-secondary"><?= h($sourceLabel) ?></span></td>
                    <td class="text-end">
                        <a href="<?= BASE_URL ?>/athlete_dashboard.php?edit_weight=<?= (int)$weightRow['id'] ?>#weight-history" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-pen me-1"></i>Upravit
                        </a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento záznam hmotnosti?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_weight">
                            <input type="hidden" name="weight_log_id" value="<?= (int)$weightRow['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($weightCollapsedRows)): ?>
        <div class="border-top p-3 text-center bg-light">
            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteWeightHistoryCollapse" aria-expanded="<?= $weightShouldExpandAll ? 'true' : 'false' ?>" aria-controls="athleteWeightHistoryCollapse">
                <i class="fas fa-chevron-down me-1"></i>
                <?= $weightShouldExpandAll ? 'Skrýt starší záznamy' : 'Zobrazit starší záznamy (' . count($weightCollapsedRows) . ')' ?>
            </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 athlete-desktop-only">
    <div class="card-header bg-dark text-white"><i class="fas fa-calendar-check me-2"></i>Plán tréninků</div>
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="text-muted small">Zaplánované tréninky</div>
                <div class="display-6 fw-bold mb-0"><?= (int)$upcomingPlannedCount ?></div>
            </div>
            <div class="text-start text-md-end">
                <?php if ($nearestPlannedTraining): ?>
                    <div class="fw-semibold">Nejbližší termín</div>
                    <div><?= formatDateTime((string)$nearestPlannedTraining['starts_at']) ?></div>
                    <div class="text-muted small">
                        Místo: <?= !empty($nearestPlannedTraining['location']) ? h((string)$nearestPlannedTraining['location']) : 'neuvedeno' ?>
                    </div>
                    <?php if (($nearestPlannedTraining['approval_status'] ?? 'approved') === 'pending'): ?>
                    <span class="badge bg-warning text-dark mt-1">Ke schválení</span>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted">Nejbližší termín zatím není naplánovaný.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 athlete-desktop-only">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-wallet me-2"></i>Platby</span>
        <a href="<?= BASE_URL ?>/athlete_payments.php" class="btn btn-warning btn-sm fw-semibold">
            <i class="fas fa-eye me-1"></i>Zobrazit platby
        </a>
    </div>
    <div class="card-body">
        <?php if ($pendingMonthPreview): ?>
            <div class="alert alert-info mb-3">
                <div class="fw-semibold mb-1"><i class="fas fa-hourglass-half me-1"></i>Rozpracované požadavky pro <?= h((string)$pendingMonthPreview['month_label']) ?></div>
                <div>
                    Máte <?= (int)$pendingMonthPreview['total'] ?> neschválený(é) požadavek(y).
                    <?php if ((int)$pendingMonthPreview['makeup'] > 0): ?>
                        Náhradní: <?= (int)$pendingMonthPreview['makeup'] ?>x (0 Kč).
                    <?php endif; ?>
                    <?php if ((int)$pendingMonthPreview['single'] > 0): ?>
                        Individuální: <?= (int)$pendingMonthPreview['single'] ?>x.
                    <?php endif; ?>
                    <?php if ((int)$pendingMonthPreview['paired'] > 0): ?>
                        Párové: <?= (int)$pendingMonthPreview['paired'] ?>x.
                    <?php endif; ?>
                </div>
                <?php if ($pendingMonthPreview['estimated_amount'] !== null): ?>
                    <div class="small mt-1">Orientační částka po schválení: <strong><?= number_format((float)$pendingMonthPreview['estimated_amount'], 0, ',', ' ') ?> Kč</strong>.</div>
                <?php else: ?>
                    <div class="small mt-1">Orientační částku teď nelze spočítat (chybí sazba).</div>
                <?php endif; ?>
                <div class="small mt-1 text-muted">Jde o předběžný přehled. Finální stav se promítne po schválení trenérem.</div>
            </div>
        <?php endif; ?>

        <?php if ($activeReplacementNotice): ?>
            <div id="dashboardReplacementNotice" class="alert <?= $activeReplacementNotice['is_overdue'] ? 'alert-danger' : 'alert-warning' ?> mb-3">
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
                    Náhradní trénink můžete naplánovat v <a href="<?= BASE_URL ?>/athlete_calendar.php">kalendáři</a>. Přednostně vybírejte stejný měsíc; do dalšího měsíce lze náhradu přesunout jen při zrušení v posledním týdnu měsíce.
                </div>
            </div>
        <?php elseif (($outstanding ?? 0) > 0): ?>
            <div id="dashboardReplacementNotice" class="alert alert-warning mb-3">
                <div class="fw-semibold mb-1"><i class="fas fa-circle-exclamation me-1"></i>Nevyužitý uhrazený trénink</div>
                <div>
                    Máte <?= (int)$outstanding ?> nevyužitý(é) uhrazený(é) trénink(y), které můžete použít jako náhradu.
                </div>
                <div class="small mt-2">
                    Náhradní trénink můžete naplánovat v <a href="<?= BASE_URL ?>/athlete_calendar.php">kalendáři</a>.
                </div>
            </div>
        <?php else: ?>
            <div id="dashboardReplacementNotice" class="alert alert-warning mb-3 d-none">
                <div class="fw-semibold mb-1"><i class="fas fa-triangle-exclamation me-1"></i>Náhradní termín po zrušení</div>
                <div id="dashboardReplacementNoticeText"></div>
                <div class="small mt-2">
                    Náhradní trénink můžete naplánovat v <a href="<?= BASE_URL ?>/athlete_calendar.php">kalendáři</a>. Přednostně vybírejte stejný měsíc; do dalšího měsíce lze náhradu přesunout jen při zrušení v posledním týdnu měsíce.
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($paymentRowsForView)): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Měsíc</th>
                        <th>Tréninky</th>
                        <th>Částka</th>
                        <th>Stav</th>
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
                                        <div class="small text-muted mt-1"><?= formatDateTime((string)$row['payment']['paid_at']) ?></div>
                                    <?php endif; ?>
                                <?php elseif ($row['is_pending']): ?>
                                    <span class="badge bg-warning text-dark">Čeká na úhradu</span>
                                    <div class="small text-muted mt-1">Poznámka: <?= h($row['note']) ?></div>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Čeká na vystavení výzvy</span>
                                    <div class="small text-muted mt-1">Trenér ještě neuzavřel měsíc.</div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-muted">Aktuálně tu nemáte žádnou evidovanou platbu. Jakmile trenér připraví výzvu, uvidíte ji zde i v sekci Platby.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm athlete-desktop-only">
    <div class="card-header bg-dark text-white"><i class="fas fa-history me-2"></i>Historie tréninků</div>
    <div class="card-body p-0">
        <?php if (empty($sessions)): ?>
        <div class="text-center text-muted py-4">Zatím nemáte žádné tréninky.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                <tr>
                    <th>Datum</th>
                    <th>Sada</th>
                    <th>Místo</th>
                    <th>Stav</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($trainingVisibleRows as $s): ?>
                <tr>
                    <td><?= formatDateTime((string)$s['started_at']) ?></td>
                    <td><?= h((string)$s['set_name']) ?></td>
                    <td><?= !empty($s['location']) ? h((string)$s['location']) : '–' ?></td>
                    <td>
                        <?php if (!empty($s['completed_at'])): ?>
                        <span class="badge bg-success">Dokončeno</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Naplánováno / probíhá</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye me-1"></i>Detail
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (!empty($trainingCollapsedRows)): ?>
                <tbody id="athleteTrainingHistoryCollapse" class="collapse">
                <?php foreach ($trainingCollapsedRows as $s): ?>
                <tr>
                    <td><?= formatDateTime((string)$s['started_at']) ?></td>
                    <td><?= h((string)$s['set_name']) ?></td>
                    <td><?= !empty($s['location']) ? h((string)$s['location']) : '–' ?></td>
                    <td>
                        <?php if (!empty($s['completed_at'])): ?>
                        <span class="badge bg-success">Dokončeno</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Naplánováno / probíhá</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-eye me-1"></i>Detail
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>
        <?php if (!empty($trainingCollapsedRows)): ?>
        <div class="border-top p-3 text-center bg-light">
            <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteTrainingHistoryCollapse" aria-expanded="false" aria-controls="athleteTrainingHistoryCollapse">
                <i class="fas fa-chevron-down me-1"></i>
                Zobrazit starší tréninky (<?= count($trainingCollapsedRows) ?>)
            </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="supportContributionModal" tabindex="-1" aria-labelledby="supportContributionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="supportContributionModalLabel"><i class="fas fa-heart me-2 text-warning"></i>Dobrovolná podpora provozu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">Jde jen o volitelnou podporu provozu aplikace. Aplikace zůstává zdarma a nic není potřeba platit.</p>
                <?php if ($supportBankAccountForQr === null): ?>
                <div class="alert alert-warning mb-3">Pro tento účet zatím není v administraci nastavené číslo účtu.</div>
                <?php else: ?>
                <div class="mb-3">
                    <label for="supportContributionAmount" class="form-label fw-semibold">Částka</label>
                    <input type="number" min="1" step="1" class="form-control form-control-lg" id="supportContributionAmount" placeholder="Např. 100">
                </div>
                <div class="border rounded-3 p-3 bg-light mb-3">
                    <img id="supportContributionQrImage" src="" alt="QR kód pro příspěvek" class="img-fluid border rounded p-2 bg-white d-none" style="max-width:220px;">
                    <div id="supportContributionQrEmpty" class="text-muted small">Zadejte částku a QR kód se zobrazí automaticky.</div>
                </div>
                <div class="small"><strong>Účet:</strong> <span id="supportContributionAccount"><?= h($supportBankAccount) ?></span></div>
                <div class="small"><strong>Odesílatel:</strong> <span id="supportContributionSender"><?= h($supportContributorName) ?></span></div>
                <div class="small"><strong>Poznámka:</strong> <span id="supportContributionNotePreview"><?= h($supportQrNote) ?></span></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer justify-content-between flex-wrap gap-2">
                <div class="small text-muted">Aplikace zůstává bezplatná. Příspěvek je pouze dobrovolná pomoc s provozem.</div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zavřít</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const supportBankAccount = <?= json_encode($supportBankAccountForQr, JSON_UNESCAPED_UNICODE) ?>;
    const supportContributorName = <?= json_encode($supportContributorName, JSON_UNESCAPED_UNICODE) ?>;
    const supportQrNote = <?= json_encode($supportQrNote, JSON_UNESCAPED_UNICODE) ?>;
    const amountInput = document.getElementById('supportContributionAmount');
    const qrImage = document.getElementById('supportContributionQrImage');
    const qrEmpty = document.getElementById('supportContributionQrEmpty');

    if (!amountInput || !qrImage || !qrEmpty || supportBankAccount === null) {
        return;
    }

    const buildQrUrl = (amount) => {
        const spd = [
            'SPD*1.0',
            'ACC:' + supportBankAccount,
            'CC:CZK',
            'AM:' + amount.toFixed(2),
            'MSG:' + supportQrNote,
        ].join('*');

        return 'https://quickchart.io/qr?size=220&text=' + encodeURIComponent(spd);
    };

    const updateQr = () => {
        const amount = parseFloat(String(amountInput.value || '').replace(',', '.'));
        if (!Number.isFinite(amount) || amount <= 0) {
            qrImage.classList.add('d-none');
            qrEmpty.classList.remove('d-none');
            qrImage.removeAttribute('src');
            return;
        }

        qrImage.src = buildQrUrl(amount);
        qrImage.classList.remove('d-none');
        qrEmpty.classList.add('d-none');
    };

    amountInput.addEventListener('input', updateQr);
    amountInput.addEventListener('change', updateQr);
})();
</script>

<script>
(function () {
    const noticeEl = document.getElementById('dashboardReplacementNotice');
    if (!noticeEl) {
        return;
    }

    // If server-side notice is already rendered, keep it and do not override.
    if (!noticeEl.classList.contains('d-none')) {
        return;
    }

    const noticeTextEl = document.getElementById('dashboardReplacementNoticeText');
    if (!noticeTextEl) {
        return;
    }

    const now = new Date();
    const localDate = now.toISOString().slice(0, 10);
    const localHour = String(now.getHours()).padStart(2, '0');
    const localMinute = String(now.getMinutes()).padStart(2, '0');
    const startsAt = `${localDate}T${localHour}:${localMinute}`;

    fetch('<?= BASE_URL ?>/api/athlete_calendar_makeup_hint.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf_token: <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE) ?>,
            starts_at: startsAt,
        }),
    })
    .then((response) => response.json())
    .then((payload) => {
        if (!payload || payload.success !== true) {
            return;
        }

        const count = Number(payload.required_replacement_count || 0);
        const outstanding = Number(payload.outstanding_sessions || 0);
        const hasRequired = Boolean(payload.has_required_replacement) && count > 0;
        const hasOutstanding = Boolean(payload.has_outstanding) && outstanding > 0;

        if (!hasRequired && !hasOutstanding) {
            return;
        }

        let text = '';
        if (hasRequired) {
            text = count > 1
                ? `Máte ${count} zrušené termíny, které je potřeba nahradit.`
                : 'Máte zrušený termín, který je potřeba nahradit.';
        } else {
            text = `Máte ${outstanding} nevyužitý(é) uhrazený(é) trénink(y), které můžete použít jako náhradu.`;
        }

        if (hasRequired && payload.required_replacement_deadline_at) {
            const deadline = new Date(String(payload.required_replacement_deadline_at).replace(' ', 'T'));
            if (!Number.isNaN(deadline.getTime())) {
                text += ` Nejzazší termín rezervace je ${deadline.toLocaleString('cs-CZ')}.`;
            }
        }

        noticeTextEl.textContent = text;
        noticeEl.classList.remove('d-none');
    })
    .catch(() => {
        // Silent fallback; dashboard remains unchanged if API call fails.
    });
})();
</script>

<?php renderAthleteFooter();
