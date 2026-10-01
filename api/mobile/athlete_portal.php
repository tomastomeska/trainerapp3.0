<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/payment_billing.php';

$athlete = mobileRequireAthlete();
$pdo = getDB();
$input = mobileInput();
$action = (string)($_REQUEST['action'] ?? $input['action'] ?? 'home');
$athleteId = (int)$athlete['id'];
$coachId = (int)$athlete['coach_id'];
paymentEnsureBillingIssueSchema($pdo);

function athletePortalStamp(string $raw): ?DateTime
{
    $value = trim($raw);
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $value) ?: DateTime::createFromFormat('Y-m-d H:i', $value);
    return $date ?: null;
}

function athletePortalBusy(PDO $pdo, int $coachId, string $startSql, string $endSql, int $ignoreEventId = 0): ?string
{
    $lock = $pdo->prepare('SELECT id FROM coach_calendar_locks WHERE coach_id = ? AND starts_at < ? AND ends_at > ? LIMIT 1');
    $lock->execute([$coachId, $endSql, $startSql]);
    if ($lock->fetch()) {
        return 'Tento čas je uzamčený.';
    }
    $overlap = $pdo->prepare('SELECT id FROM coach_calendar_events WHERE coach_id = ? AND starts_at < ? AND ends_at > ? AND id <> ? LIMIT 1');
    $overlap->execute([$coachId, $endSql, $startSql, $ignoreEventId]);
    if ($overlap->fetch()) {
        return 'V tomto čase je slot obsazený.';
    }
    return null;
}

function athletePortalRefreshPendingPayments(PDO $pdo, int $coachId, int $athleteId): void
{
    if (!athletePortalColumnExists($pdo, 'athlete_monthly_payments', 'issue_number')) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT billing_month, issue_number FROM athlete_monthly_payments WHERE coach_id = ? AND athlete_id = ? AND status = "pending"');
        $stmt->execute([$coachId, $athleteId]);
        foreach ($stmt->fetchAll() as $row) {
            $month = substr((string)$row['billing_month'], 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $month)) {
                continue;
            }
            try {
                paymentRefreshPendingIssue($pdo, $coachId, $athleteId, $month, max(1, (int)($row['issue_number'] ?? 1)));
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
    }
}

function athletePortalAscii(string $value): string
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

function athletePortalPaymentQrUrl(PDO $pdo, int $coachId, float $amount, string $monthSql, int $issueNumber): ?string
{
    if ($amount <= 0 || !athletePortalColumnExists($pdo, 'coaches', 'bank_account')) {
        return null;
    }
    static $coaches = [];
    if (!array_key_exists($coachId, $coaches)) {
        $stmt = $pdo->prepare('SELECT name, username, bank_account FROM coaches WHERE id = ? LIMIT 1');
        $stmt->execute([$coachId]);
        $coaches[$coachId] = $stmt->fetch() ?: [];
    }
    $coach = $coaches[$coachId];
    $raw = strtoupper(str_replace(' ', '', trim((string)($coach['bank_account'] ?? ''))));
    $accountOk = preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $raw) === 1
        || preg_match('/^[0-9]{1,6}-[0-9]{2,10}\/[0-9]{4}$/', $raw) === 1
        || preg_match('/^[0-9]{2,10}\/[0-9]{4}$/', $raw) === 1;
    $account = $accountOk ? accountForSpd($raw) : null;
    if ($account === null) {
        return null;
    }
    $name = trim((string)($coach['name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($coach['username'] ?? ''));
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    $last = trim((string)end($parts));
    if ($last === '') {
        $last = 'Trener';
    }
    $note = athletePortalAscii($last . ' ' . date('m/Y', strtotime($monthSql)));
    if ($issueNumber > 1) {
        $note = trim($note . ' dodatecna');
    }
    $spd = ['SPD*1.0', 'ACC:' . $account, 'CC:CZK', 'AM:' . number_format($amount, 2, '.', '')];
    if ($note !== '') {
        $spd[] = 'MSG:' . str_replace('*', ' ', $note);
    }
    return 'https://quickchart.io/qr?size=440&text=' . rawurlencode(implode('*', $spd));
}

function athletePortalColumnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ' . $pdo->quote($column));
        return $stmt !== false && (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function athletePortalReceiptEvents(PDO $pdo, int $coachId, int $athleteId, string $monthSql, int $issueNumber = 1): array
{
    $hasSecond = athletePortalColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
    $makeupSql = athletePortalColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session') ? 'COALESCE(e.is_makeup_session, 0)' : '0';
    $calendarSql = "DATE_FORMAT(e.starts_at, '%Y-%m-01')";
    $billingSql = athletePortalColumnExists($pdo, 'coach_calendar_events', 'billing_month')
        ? "DATE_FORMAT(COALESCE(e.billing_month, e.starts_at), '%Y-%m-01')"
        : $calendarSql;
    $issueSql = athletePortalColumnExists($pdo, 'coach_calendar_events', 'billing_issue_number') ? 'COALESCE(e.billing_issue_number, 1)' : '1';
    $transferReasonSql = athletePortalColumnExists($pdo, 'coach_calendar_events', 'billing_transfer_reason')
        ? 'e.billing_transfer_reason'
        : "''";
    $titleSql = athletePortalColumnExists($pdo, 'coach_calendar_events', 'custom_title') ? 'e.custom_title' : "''";
    $memberSql = "(
        ({$calendarSql} = ? AND ({$billingSql} = ? OR {$transferReasonSql} IS NULL OR {$transferReasonSql} = ''))
        OR ({$billingSql} = ? AND {$transferReasonSql} IN ('pending_not_included', 'paid_not_paid') AND {$calendarSql} <> ?)
    )";
    if ($hasSecond) {
        $sql = "SELECT e.id, {$titleSql} AS custom_title, e.starts_at, e.ends_at, e.location, {$makeupSql} AS is_makeup_session, {$billingSql} AS billing_month, {$transferReasonSql} AS billing_transfer_reason,
                       CASE WHEN e.second_athlete_id IS NOT NULL THEN 1 ELSE 0 END AS is_paired
                FROM coach_calendar_events e
                WHERE e.coach_id = ? AND e.approval_status = 'approved' AND e.athlete_id = ? AND {$memberSql} AND {$issueSql} = ?
                UNION ALL
                SELECT e.id, {$titleSql}, e.starts_at, e.ends_at, e.location, {$makeupSql}, {$billingSql}, {$transferReasonSql}, 1
                FROM coach_calendar_events e
                WHERE e.coach_id = ? AND e.approval_status = 'approved' AND e.second_athlete_id = ? AND {$memberSql} AND {$issueSql} = ?
                ORDER BY starts_at ASC";
        $params = [
            $coachId, $athleteId, $monthSql, $monthSql, $monthSql, $monthSql, $issueNumber,
            $coachId, $athleteId, $monthSql, $monthSql, $monthSql, $monthSql, $issueNumber,
        ];
    } else {
        $sql = "SELECT e.id, {$titleSql} AS custom_title, e.starts_at, e.ends_at, e.location, {$makeupSql} AS is_makeup_session, {$billingSql} AS billing_month, {$transferReasonSql} AS billing_transfer_reason, 0 AS is_paired
                FROM coach_calendar_events e
                WHERE e.coach_id = ? AND e.approval_status = 'approved' AND e.athlete_id = ? AND {$memberSql} AND {$issueSql} = ?
                ORDER BY starts_at ASC";
        $params = [$coachId, $athleteId, $monthSql, $monthSql, $monthSql, $monthSql, $issueNumber];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function athletePortalReceiptLines(PDO $pdo, int $coachId, int $athleteId, string $monthSql, array $payment): array
{
    $issueNumber = max(1, (int)($payment['issue_number'] ?? 1));
    try {
        $events = athletePortalReceiptEvents($pdo, $coachId, $athleteId, $monthSql, $issueNumber);
    } catch (Throwable $e) {
        $events = [];
    }

    $singleRate = null;
    $pairedRate = null;
    try {
        $rateSql = athletePortalColumnExists($pdo, 'athletes', 'paired_training_rate')
            ? 'SELECT training_rate, paired_training_rate FROM athletes WHERE id = ?'
            : 'SELECT training_rate, NULL AS paired_training_rate FROM athletes WHERE id = ?';
        $rateStmt = $pdo->prepare($rateSql);
        $rateStmt->execute([$athleteId]);
        $rateRow = $rateStmt->fetch();
        if ($rateRow && $rateRow['training_rate'] !== null) {
            $singleRate = (float)$rateRow['training_rate'];
        }
        if ($rateRow && array_key_exists('paired_training_rate', $rateRow) && $rateRow['paired_training_rate'] !== null) {
            $pairedRate = (float)$rateRow['paired_training_rate'];
        }
    } catch (Throwable $e) {
    }
    if ($singleRate === null && isset($payment['session_rate']) && $payment['session_rate'] !== null && $payment['session_rate'] !== '') {
        $singleRate = (float)$payment['session_rate'];
    }
    if ($pairedRate === null) {
        $pairedRate = $singleRate;
    }
    if (isset($payment['session_rate']) && $payment['session_rate'] !== null && $payment['session_rate'] !== '') {
        $singleRate = (float)$payment['session_rate'];
        $pairedRate = $singleRate;
    }

    if ((string)($payment['status'] ?? '') === 'paid' && athletePortalColumnExists($pdo, 'athlete_monthly_payments', 'receipt_snapshot_json')) {
        try {
            $snapStmt = $pdo->prepare('SELECT receipt_snapshot_json FROM athlete_monthly_payments WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = ? LIMIT 1');
            $snapStmt->execute([$coachId, $athleteId, (string)($payment['billing_month'] ?? $monthSql), $issueNumber]);
            $snapshot = json_decode((string)($snapStmt->fetchColumn() ?: ''), true);
            if (is_array($snapshot) && isset($snapshot['event_rows']) && is_array($snapshot['event_rows'])) {
                $events = [];
                foreach ($snapshot['event_rows'] as $snapshotEvent) {
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
                    $events[] = [
                        'id' => (int)($snapshotEvent['id'] ?? 0),
                        'custom_title' => '',
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
            }
        } catch (Throwable $e) {
        }
    }
    $directOrigins = [];
    try {
        if (athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
            && athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at')) {
            $originBillingField = athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')
                ? 'billing_month'
                : 'NULL AS billing_month';
            $originStatusField = athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot')
                ? 'payment_status_snapshot'
                : '"none" AS payment_status_snapshot';
            $originStmt = $pdo->prepare("SELECT replacement_event_id, starts_at, {$originBillingField}, {$originStatusField} FROM coach_calendar_event_cancellations WHERE coach_id = ? AND replacement_event_id IS NOT NULL ORDER BY id ASC");
            $originStmt->execute([$coachId]);
            foreach ($originStmt->fetchAll() as $originRow) {
                $replacementId = (int)($originRow['replacement_event_id'] ?? 0);
                if ($replacementId > 0 && !isset($directOrigins[$replacementId])) {
                    $directOrigins[$replacementId] = [
                        'starts_at' => (string)($originRow['starts_at'] ?? ''),
                        'billing_month' => (string)($originRow['billing_month'] ?? ''),
                        'payment_status_snapshot' => (string)($originRow['payment_status_snapshot'] ?? 'none'),
                    ];
                }
            }
        }
    } catch (Throwable $e) {
    }
    $lines = [];
    foreach ($events as $event) {
        $paired = (int)($event['is_paired'] ?? 0) === 1;
        $rate = $paired ? ($pairedRate ?? $singleRate) : $singleRate;
        $notes = [];
        $origin = $directOrigins[(int)($event['id'] ?? 0)] ?? null;
        $isPaidReplacement = is_array($origin)
            && (string)($origin['payment_status_snapshot'] ?? '') === 'paid';
        $amount = $isPaidReplacement ? 0.0 : ($rate !== null ? $rate : 0.0);
        if ($isPaidReplacement) {
            $originMonth = trim((string)($origin['billing_month'] ?? ''));
            if ($originMonth === '') {
                $originMonth = (string)($origin['starts_at'] ?? '');
            }
            if ($originMonth !== '' && strtotime($originMonth) !== false) {
                $notes[] = 'Hrazeno v ' . date('m/Y', strtotime($originMonth));
            }
        }
        $originStart = is_array($origin) ? (string)($origin['starts_at'] ?? '') : '';
        $originTs = $originStart !== '' ? strtotime($originStart) : false;
        if ($originTs !== false) {
            $notes[] = 'Původní termín: ' . date('d.m.Y', $originTs);
        }
        $startTs = strtotime((string)$event['starts_at']);
        if ($startTs === false) {
            continue;
        }
        $calendarMonth = date('Y-m-01', $startTs);
        $billingTs = strtotime((string)($event['billing_month'] ?? ''));
        $assignedMonth = $billingTs !== false ? date('Y-m-01', $billingTs) : $calendarMonth;
        if ($calendarMonth !== $assignedMonth) {
            $calendarDay = date('j. n. Y', strtotime((string)$event['starts_at']));
            if ((string)($event['billing_transfer_reason'] ?? '') === 'pending_not_included') {
                $notes[] = 'Tento trénink proběhl ' . $calendarDay . ' a nebyl zahrnut do ' . paymentBillingMonthAdjective($calendarMonth) . ' výzvy.';
            } elseif ((string)($event['billing_transfer_reason'] ?? '') === 'paid_not_paid') {
                $notes[] = 'Tento trénink proběhl ' . $calendarDay . ' a nebyl uhrazen v ' . paymentBillingMonthGenitive($calendarMonth) . '.';
            }
        }
        $lines[] = [
            'title' => trim((string)($event['custom_title'] ?? '')) !== '' ? (string)$event['custom_title'] : 'Trénink',
            'location' => (string)($event['location'] ?? ''),
            'starts_at' => (string)$event['starts_at'],
            'ends_at' => (string)$event['ends_at'],
            'is_paired' => $paired,
            'is_makeup' => (int)($event['is_makeup_session'] ?? 0) === 1,
            'amount' => $amount,
            'note' => implode('; ', $notes),
        ];
    }
    return $lines;
}

if ($action === 'home') {
    $upcoming = [];
    $stmt = $pdo->prepare(
        'SELECT id, custom_title, location, starts_at, ends_at, approval_status
         FROM coach_calendar_events
         WHERE coach_id = ?
           AND (athlete_id = ? OR second_athlete_id = ?)
           AND ends_at >= NOW()
         ORDER BY starts_at ASC
         LIMIT 20'
    );
    $stmt->execute([$coachId, $athleteId, $athleteId]);
    foreach ($stmt->fetchAll() as $row) {
        $upcoming[] = [
            'id' => (int)$row['id'],
            'title' => trim((string)($row['custom_title'] ?? '')) !== '' ? (string)$row['custom_title'] : 'Trénink',
            'location' => (string)($row['location'] ?? ''),
            'starts_at' => (string)$row['starts_at'],
            'ends_at' => (string)$row['ends_at'],
            'approval_status' => (string)($row['approval_status'] ?? 'approved'),
        ];
    }

    $payments = [];
    try {
        athletePortalRefreshPendingPayments($pdo, $coachId, $athleteId);
        $hasIssue = athletePortalColumnExists($pdo, 'athlete_monthly_payments', 'issue_number');
        $issueSelect = $hasIssue ? 'issue_number' : '1 AS issue_number';
        $issueOrder = $hasIssue ? 'issue_number ASC' : 'billing_month ASC';
        $pay = $pdo->prepare(
            "SELECT billing_month, {$issueSelect}, planned_sessions, billed_amount, status, paid_at, session_rate
             FROM athlete_monthly_payments
             WHERE athlete_id = ? AND coach_id = ?
             ORDER BY billing_month DESC, {$issueOrder}
             LIMIT 12"
        );
        $pay->execute([$athleteId, $coachId]);
        foreach ($pay->fetchAll() as $row) {
            $monthRaw = (string)$row['billing_month'];
            $monthSql = substr($monthRaw, 0, 7) . '-01';
            $trainings = athletePortalReceiptLines($pdo, $coachId, $athleteId, $monthSql, $row);
            $payments[] = [
                'month' => $monthRaw,
                'issue_number' => max(1, (int)($row['issue_number'] ?? 1)),
                'label' => max(1, (int)($row['issue_number'] ?? 1)) > 1 ? date('m/Y', strtotime($monthRaw)) . ' — dodatečná výzva' : date('m/Y', strtotime($monthRaw)),
                'sessions' => count($trainings),
                'amount' => $row['billed_amount'] !== null ? (float)$row['billed_amount'] : null,
                'status' => (string)($row['status'] ?? 'pending'),
                'paid_at' => (string)($row['paid_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
    }

    $weight = null;
    try {
        $w = $pdo->prepare('SELECT id, measured_at, weight_kg FROM athlete_weight_logs WHERE athlete_id = ? ORDER BY measured_at DESC, id DESC LIMIT 1');
        $w->execute([$athleteId]);
        $row = $w->fetch();
        if ($row) {
            $weight = [
                'id' => (int)$row['id'],
                'measured_at' => (string)$row['measured_at'],
                'weight_kg' => (float)$row['weight_kg'],
            ];
        }
    } catch (Throwable $e) {
    }

    $coachUnread = 0;
    try {
        $u = $pdo->prepare("SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE athlete_id = ? AND sender = 'coach' AND athlete_read_at IS NULL");
        $u->execute([$athleteId]);
        $coachUnread = (int)$u->fetchColumn();
    } catch (Throwable $e) {
    }
    $adminUnread = 0;
    try {
        $u = $pdo->prepare("SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL");
        $u->execute([$athleteId]);
        $adminUnread = (int)$u->fetchColumn();
    } catch (Throwable $e) {
    }

    $coachName = 'Trenér';
    $coachPhoto = '';
    try {
        $coachRow = $pdo->prepare('SELECT name FROM coaches WHERE id = ? LIMIT 1');
        $coachRow->execute([$coachId]);
        $fetchedName = trim((string)$coachRow->fetchColumn());
        if ($fetchedName !== '') {
            $coachName = $fetchedName;
        }
    } catch (Throwable $e) {
    }
    try {
        $coachPhotoStmt = $pdo->prepare('SELECT photo FROM coaches WHERE id = ? LIMIT 1');
        $coachPhotoStmt->execute([$coachId]);
        $coachPhoto = mobilePublicPhoto((string)($coachPhotoStmt->fetchColumn() ?: ''), 'coaches');
    } catch (Throwable $e) {
    }

    mobileJson([
        'success' => true,
        'athlete' => [
            'id' => $athleteId,
            'name' => $athlete['name'],
            'email' => $athlete['email'],
            'photo' => mobilePublicPhoto((string)($pdo->query('SELECT photo FROM athletes WHERE id = ' . $athleteId)->fetchColumn() ?: ''), 'athletes'),
        ],
        'coach' => [
            'name' => $coachName,
            'photo' => $coachPhoto,
        ],
        'upcoming' => $upcoming,
        'payments' => $payments,
        'weight' => $weight,
        'unread_chat' => $coachUnread + $adminUnread,
        'coach_unread' => $coachUnread,
        'admin_unread' => $adminUnread,
    ]);
}

if ($action === 'calendar') {
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    $start = DateTime::createFromFormat('Y-m-d', $from) ?: new DateTime('monday this week');
    $end = DateTime::createFromFormat('Y-m-d', $to) ?: (clone $start)->modify('+6 days');
    $start->setTime(0, 0, 0);
    $end->setTime(23, 59, 59);
    $events = [];
    $stmt = $pdo->prepare(
        'SELECT e.id, e.athlete_id, e.second_athlete_id, e.requested_by_athlete_id, e.series_id,
                e.approval_status, e.color_key, e.custom_title, e.location, e.starts_at, e.ends_at,
                a.first_name, a.last_name
         FROM coach_calendar_events e
         LEFT JOIN athletes a ON a.id = e.athlete_id
         WHERE e.coach_id = ?
           AND e.starts_at < ?
           AND e.ends_at > ?
           AND (e.approval_status = "approved" OR e.athlete_id = ? OR e.second_athlete_id = ? OR e.requested_by_athlete_id = ?)
         ORDER BY e.starts_at ASC'
    );
    $stmt->execute([$coachId, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s'), $athleteId, $athleteId, $athleteId]);
    foreach ($stmt->fetchAll() as $row) {
        $mine = (int)$row['athlete_id'] === $athleteId || (int)($row['second_athlete_id'] ?? 0) === $athleteId;
        $requested = (int)($row['requested_by_athlete_id'] ?? 0) === $athleteId;
        $foreign = !$mine && !$requested;
        $starts = (string)$row['starts_at'];
        $ends = (string)$row['ends_at'];
        $events[] = [
            'id' => (int)$row['id'],
            'title' => $foreign ? 'Obsazeno' : (trim((string)($row['custom_title'] ?? '')) !== '' ? (string)$row['custom_title'] : 'Trénink'),
            'location' => $foreign ? '' : (string)($row['location'] ?? ''),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'date_label' => substr($starts, 0, 10),
            'time_label' => substr($starts, 11, 5),
            'start_hour' => (int)substr($starts, 11, 2),
            'end_hour' => (int)substr($ends, 11, 2),
            'approval_status' => $foreign ? 'approved' : (string)($row['approval_status'] ?? 'approved'),
            'is_mine' => $mine || $requested,
            'is_foreign' => $foreign,
            'can_request_change' => $mine && (string)$row['approval_status'] === 'approved' && strtotime($starts) > time(),
            'can_cancel_event' => $mine && (string)$row['approval_status'] === 'approved' && strtotime($starts) > time(),
            'can_cancel_request' => $requested && (string)$row['approval_status'] === 'pending',
            'can_edit' => $requested && (string)$row['approval_status'] === 'pending' && strtotime($starts) > time() && !str_starts_with((string)($row['series_id'] ?? ''), 'reschedule:'),
            'series_id' => (string)($row['series_id'] ?? ''),
            'color_key' => (string)($row['color_key'] ?? 'green'),
            'athlete_name' => $foreign ? '' : trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
        ];
    }
    $locks = [];
    try {
        $lockStmt = $pdo->prepare('SELECT id, note, starts_at, ends_at FROM coach_calendar_locks WHERE coach_id = ? AND starts_at < ? AND ends_at > ? ORDER BY starts_at ASC');
        $lockStmt->execute([$coachId, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]);
        foreach ($lockStmt->fetchAll() as $row) {
            $locks[] = [
                'id' => 200000 + (int)$row['id'],
                'title' => trim((string)($row['note'] ?? '')) !== '' ? (string)$row['note'] : 'Uzamčeno',
                'starts_at' => (string)$row['starts_at'],
                'ends_at' => (string)$row['ends_at'],
                'date_label' => substr((string)$row['starts_at'], 0, 10),
                'time_label' => substr((string)$row['starts_at'], 11, 5),
                'start_hour' => (int)substr((string)$row['starts_at'], 11, 2),
                'end_hour' => (int)substr((string)$row['ends_at'], 11, 2),
                'is_locked' => true,
            ];
        }
    } catch (Throwable $e) {
    }
    $venues = [];
    try {
        $venueStmt = $pdo->query('SELECT id, name, address FROM training_venues WHERE is_active = 1 ORDER BY name ASC');
        foreach ($venueStmt->fetchAll() as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $venues[$name] = [
                'id' => (int)$row['id'],
                'name' => $name,
                'address' => (string)($row['address'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
    }
    try {
        $coachVenueStmt = $pdo->prepare('SELECT id, name FROM coach_training_venues WHERE coach_id = ? AND is_active = 1 ORDER BY name ASC');
        $coachVenueStmt->execute([$coachId]);
        foreach ($coachVenueStmt->fetchAll() as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '' || isset($venues[$name])) {
                continue;
            }
            $venues[$name] = [
                'id' => 10000 + (int)$row['id'],
                'name' => $name,
                'address' => '',
            ];
        }
    } catch (Throwable $e) {
    }
    mobileJson(['success' => true, 'events' => $events, 'locks' => $locks, 'venues' => array_values($venues)]);
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $start = athletePortalStamp((string)($input['starts_at'] ?? ''));
    if (!$start) {
        mobileJson(['success' => false, 'error' => 'Neplatný začátek termínu.'], 422);
    }
    $end = clone $start;
    $end->modify('+60 minutes');
    $startSql = $start->format('Y-m-d H:i:s');
    $endSql = $end->format('Y-m-d H:i:s');
    $busy = athletePortalBusy($pdo, $coachId, $startSql, $endSql);
    if ($busy !== null) {
        mobileJson(['success' => false, 'error' => $busy], 409);
    }
    $title = trim((string)($input['title'] ?? 'Trénink'));
    $location = trim((string)($input['location'] ?? ''));
    $stmt = $pdo->prepare(
        'INSERT INTO coach_calendar_events
            (coach_id, athlete_id, requested_by_athlete_id, approval_status, is_makeup_session, billing_month, color_key, custom_title, location, starts_at, ends_at)
         VALUES (?, ?, ?, "pending", 0, ?, "green", ?, ?, ?, ?)'
    );
    $stmt->execute([$coachId, $athleteId, $athleteId, $start->format('Y-m-01'), $title !== '' ? $title : 'Trénink', $location !== '' ? $location : null, $startSql, $endSql]);
    mobileJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'request_change' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = (int)($input['event_id'] ?? 0);
    $start = athletePortalStamp((string)($input['starts_at'] ?? ''));
    if ($eventId <= 0 || !$start) {
        mobileJson(['success' => false, 'error' => 'Vyberte termín a nový čas.'], 422);
    }
    $own = $pdo->prepare('SELECT id, custom_title, location, starts_at FROM coach_calendar_events WHERE id = ? AND coach_id = ? AND athlete_id = ? AND approval_status = "approved" LIMIT 1');
    $own->execute([$eventId, $coachId, $athleteId]);
    $event = $own->fetch();
    if (!$event || strtotime((string)$event['starts_at']) <= time()) {
        mobileJson(['success' => false, 'error' => 'Změnu lze požádat jen u budoucího schváleného termínu.'], 422);
    }
    $end = clone $start;
    $end->modify('+60 minutes');
    $startSql = $start->format('Y-m-d H:i:s');
    $endSql = $end->format('Y-m-d H:i:s');
    $busy = athletePortalBusy($pdo, $coachId, $startSql, $endSql, $eventId);
    if ($busy !== null) {
        mobileJson(['success' => false, 'error' => $busy], 409);
    }
    $marker = 'reschedule:' . $eventId;
    $dup = $pdo->prepare('SELECT id FROM coach_calendar_events WHERE coach_id = ? AND requested_by_athlete_id = ? AND approval_status = "pending" AND series_id = ? LIMIT 1');
    $dup->execute([$coachId, $athleteId, $marker]);
    if ($dup->fetch()) {
        mobileJson(['success' => false, 'error' => 'Pro tento termín už čeká žádost o změnu.'], 409);
    }
    $postedLocation = trim((string)($input['location'] ?? ''));
    $location = $postedLocation !== '' ? $postedLocation : trim((string)($event['location'] ?? ''));
    $postedTitle = trim((string)($input['title'] ?? ''));
    $title = $postedTitle !== '' ? $postedTitle : trim((string)($event['custom_title'] ?? ''));
    if ($title === '') {
        $title = 'Trénink';
    }
    $stmt = $pdo->prepare(
        'INSERT INTO coach_calendar_events
            (coach_id, athlete_id, requested_by_athlete_id, approval_status, series_id, color_key, custom_title, location, starts_at, ends_at)
         VALUES (?, ?, ?, "pending", ?, "green", ?, ?, ?, ?)'
    );
    $stmt->execute([
        $coachId, $athleteId, $athleteId, $marker,
        $title,
        $location !== '' ? $location : null,
        $startSql, $endSql,
    ]);
    mobileJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'update_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = (int)($input['event_id'] ?? 0);
    $start = athletePortalStamp((string)($input['starts_at'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    $title = trim((string)($input['title'] ?? 'Trénink'));
    if ($eventId <= 0 || !$start) {
        mobileJson(['success' => false, 'error' => 'Vyberte datum a čas.'], 422);
    }
    if ($location === '') {
        mobileJson(['success' => false, 'error' => 'Vyberte místo z databáze.'], 422);
    }
    $own = $pdo->prepare('SELECT id, starts_at, series_id FROM coach_calendar_events WHERE id = ? AND coach_id = ? AND requested_by_athlete_id = ? AND approval_status = "pending" LIMIT 1');
    $own->execute([$eventId, $coachId, $athleteId]);
    $event = $own->fetch();
    if (!$event || str_starts_with((string)($event['series_id'] ?? ''), 'reschedule:')) {
        mobileJson(['success' => false, 'error' => 'Tento požadavek nelze upravit.'], 422);
    }
    $end = clone $start;
    $end->modify('+60 minutes');
    $startSql = $start->format('Y-m-d H:i:s');
    $endSql = $end->format('Y-m-d H:i:s');
    $busy = athletePortalBusy($pdo, $coachId, $startSql, $endSql, $eventId);
    if ($busy !== null) {
        mobileJson(['success' => false, 'error' => $busy], 409);
    }
    $update = $pdo->prepare(
        'UPDATE coach_calendar_events
         SET custom_title = ?, location = ?, starts_at = ?, ends_at = ?, billing_month = ?
         WHERE id = ? AND coach_id = ? AND requested_by_athlete_id = ? AND approval_status = "pending"'
    );
    $update->execute([$title !== '' ? $title : 'Trénink', $location, $startSql, $endSql, $start->format('Y-m-01'), $eventId, $coachId, $athleteId]);
    mobileJson(['success' => true]);
}

if ($action === 'cancel_event' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = (int)($input['event_id'] ?? 0);
    if ($eventId <= 0) {
        mobileJson(['success' => false, 'error' => 'Chybí termín.'], 422);
    }
    $eventStmt = $pdo->prepare(
        'SELECT e.id, e.coach_id, e.athlete_id, e.second_athlete_id, e.requested_by_athlete_id, e.series_id,
                e.approval_status, e.is_makeup_session, e.custom_title, e.location, e.billing_month, e.billing_issue_number, e.starts_at, e.ends_at,
                c.name AS coach_name, c.username AS coach_username
         FROM coach_calendar_events e
         JOIN coaches c ON c.id = e.coach_id
         WHERE e.id = ? AND (e.athlete_id = ? OR e.second_athlete_id = ?)
         LIMIT 1'
    );
    $eventStmt->execute([$eventId, $athleteId, $athleteId]);
    $event = $eventStmt->fetch();
    if (!$event) {
        mobileJson(['success' => false, 'error' => 'Termín nebyl nalezen.'], 404);
    }
    if ((string)($event['approval_status'] ?? '') === 'pending'
        && (int)($event['requested_by_athlete_id'] ?? 0) === $athleteId
        && preg_match('/^reschedule:\d+$/', (string)($event['series_id'] ?? ''))) {
        $deleteRequestStmt = $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND athlete_id = ? AND requested_by_athlete_id = ? AND approval_status = "pending" LIMIT 1');
        $deleteRequestStmt->execute([$eventId, $athleteId, $athleteId]);
        if ($deleteRequestStmt->rowCount() !== 1) {
            mobileJson(['success' => false, 'error' => 'Žádost o změnu se nepodařilo zrušit.'], 404);
        }
        mobileJson(['success' => true, 'message' => 'Žádost o změnu termínu byla zrušena. Původní termín zůstává zachovaný.']);
    }
    $eventStartTs = strtotime((string)$event['starts_at']);
    if ($eventStartTs !== false && $eventStartTs <= time()) {
        mobileJson(['success' => false, 'error' => 'Minulé nebo právě probíhající termíny nelze rušit.'], 422);
    }
    $isLateCancellation = $eventStartTs !== false && ($eventStartTs - time()) > 0 && ($eventStartTs - time()) < (12 * 60 * 60);
    $billingMonthSql = !empty($event['billing_month'])
        ? date('Y-m-01', strtotime((string)$event['billing_month']))
        : date('Y-m-01', strtotime((string)$event['starts_at']));
    $paymentStatus = 'none';
    try {
        $paidStmt = $pdo->prepare('SELECT status FROM athlete_monthly_payments WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = ? AND status IN ("pending", "paid") LIMIT 1');
        $paidStmt->execute([(int)$event['coach_id'], $athleteId, $billingMonthSql, max(1, (int)($event['billing_issue_number'] ?? 1))]);
        $paymentStatus = (string)($paidStmt->fetchColumn() ?: 'none');
    } catch (Throwable $e) {
        $paymentStatus = 'none';
    }
    $wasAlreadyPaid = $paymentStatus === 'paid';
    $requiresReplacement = !$isLateCancellation && in_array($paymentStatus, ['pending', 'paid'], true);
    $coachMakeupDeadlineDays = 14;
    try {
        if (athletePortalColumnExists($pdo, 'coaches', 'makeup_booking_deadline_days')) {
            $deadlineStmt = $pdo->prepare('SELECT makeup_booking_deadline_days FROM coaches WHERE id = ? LIMIT 1');
            $deadlineStmt->execute([(int)$event['coach_id']]);
            $rawDays = $deadlineStmt->fetchColumn();
            if ($rawDays !== false && $rawDays !== null) {
                $coachMakeupDeadlineDays = max(1, (int)$rawDays);
            }
        }
    } catch (Throwable $e) {
    }
    $replacementDeadlineAtSql = $requiresReplacement ? date('Y-m-d H:i:s', strtotime('+' . $coachMakeupDeadlineDays . ' days')) : null;
    $primaryAthleteId = (int)($event['athlete_id'] ?? 0);
    $secondAthleteId = (int)($event['second_athlete_id'] ?? 0);
    $removedFromPairOnly = false;
    if ($primaryAthleteId > 0 && $secondAthleteId > 0) {
        if ($primaryAthleteId === $athleteId) {
            $updateStmt = $pdo->prepare('UPDATE coach_calendar_events SET athlete_id = second_athlete_id, second_athlete_id = NULL, requested_by_athlete_id = CASE WHEN requested_by_athlete_id = ? THEN NULL ELSE requested_by_athlete_id END WHERE id = ? LIMIT 1');
        } else {
            $updateStmt = $pdo->prepare('UPDATE coach_calendar_events SET second_athlete_id = NULL, requested_by_athlete_id = CASE WHEN requested_by_athlete_id = ? THEN NULL ELSE requested_by_athlete_id END WHERE id = ? LIMIT 1');
        }
        $updateStmt->execute([$athleteId, $eventId]);
        if ($updateStmt->rowCount() === 0) {
            mobileJson(['success' => false, 'error' => 'Termín se nepodařilo zrušit.'], 409);
        }
        $removedFromPairOnly = true;
        $scope = 'pair_exit';
        $logAthleteId = $athleteId;
        $logSecondId = null;
    } else {
        $deleteStmt = $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND (athlete_id = ? OR second_athlete_id = ?) LIMIT 1');
        $deleteStmt->execute([$eventId, $athleteId, $athleteId]);
        if ($deleteStmt->rowCount() === 0) {
            mobileJson(['success' => false, 'error' => 'Termín se nepodařilo zrušit.'], 409);
        }
        $scope = 'single';
        $logAthleteId = $primaryAthleteId > 0 ? $primaryAthleteId : null;
        $logSecondId = $secondAthleteId > 0 ? $secondAthleteId : null;
    }
    try {
        $columns = ['coach_id', 'athlete_id', 'second_athlete_id', 'canceled_by', 'canceled_by_athlete_id', 'cancellation_scope', 'approval_status', 'is_makeup_session', 'custom_title', 'location', 'starts_at', 'ends_at', 'canceled_at'];
        $values = ['?', '?', '?', '"athlete"', '?', '?', '?', '?', '?', '?', '?', '?', 'NOW()'];
        $params = [
            (int)$event['coach_id'],
            $logAthleteId,
            $logSecondId,
            $athleteId,
            $scope,
            (string)($event['approval_status'] ?? 'approved') === 'pending' ? 'pending' : 'approved',
            !empty($event['is_makeup_session']) ? 1 : 0,
            trim((string)($event['custom_title'] ?? '')) !== '' ? (string)$event['custom_title'] : null,
            trim((string)($event['location'] ?? '')) !== '' ? (string)$event['location'] : null,
            (string)$event['starts_at'],
            (string)($event['ends_at'] ?? $event['starts_at']),
        ];
        if (athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_month')) {
            $columns[] = 'billing_month';
            $values[] = '?';
            $params[] = $billingMonthSql;
        }
        if (athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot')) {
            $columns[] = 'payment_status_snapshot';
            $values[] = '?';
            $params[] = $paymentStatus;
        }
        if (athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_required')) {
            $columns[] = 'replacement_required';
            $values[] = '?';
            $params[] = $requiresReplacement ? 1 : 0;
        }
        if (athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at')) {
            $columns[] = 'replacement_deadline_at';
            $values[] = '?';
            $params[] = $replacementDeadlineAtSql;
        }
        if (athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')) {
            $columns[] = 'replacement_event_id';
            $values[] = 'NULL';
        }
        $pdo->prepare('INSERT INTO coach_calendar_event_cancellations (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')')->execute($params);
    } catch (Throwable $e) {
    }
    $selfStmt = $pdo->prepare('SELECT first_name, last_name FROM athletes WHERE id = ? LIMIT 1');
    $selfStmt->execute([$athleteId]);
    $self = $selfStmt->fetch();
    $athleteName = trim((string)($self['first_name'] ?? '') . ' ' . (string)($self['last_name'] ?? ''));
    if ($athleteName === '') {
        $athleteName = 'Sportovec';
    }
    $coachDisplayName = trim((string)($event['coach_name'] ?? '')) !== '' ? (string)$event['coach_name'] : (string)($event['coach_username'] ?? 'trenér');
    $whenLabel = date('d.m.Y H:i', strtotime((string)$event['starts_at']));
    $subject = $removedFromPairOnly ? "Sportovec zrušil účast na párovém termínu - {$athleteName}" : "Sportovec zrušil termín - {$athleteName}";
    $body = $removedFromPairOnly
        ? "Sportovec {$athleteName} zrušil svou účast na párovém termínu {$whenLabel}. Slot zůstal aktivní pro druhého účastníka."
        : "Sportovec {$athleteName} zrušil termín {$whenLabel}.";
    if ($wasAlreadyPaid) {
        $body .= ' Termín byl již uhrazen a systém jej automaticky započte jako zápočet do další fakturace.';
    } elseif ($paymentStatus === 'pending') {
        $body .= ' Termín byl součástí vystavené výzvy k platbě. Částka výzvy zůstává beze změny a čeká se na náhradní termín.';
    }
    if ($isLateCancellation) {
        $body .= ' Zrušení proběhlo méně než 12 hodin před začátkem, termín je bez nároku na kompenzaci a nelze jej nahradit.';
    }
    if ($requiresReplacement) {
        $body .= ' Sportovec má povinnost vybrat náhradní termín do ' . $coachMakeupDeadlineDays . ' dnů.';
    }
    if (function_exists('createCoachSystemMessage')) {
        createCoachSystemMessage((int)$event['coach_id'], $subject, $body, true);
    }
    if (function_exists('createAthleteNotification')) {
        createAthleteNotification(
            $athleteId,
            $removedFromPairOnly ? 'Potvrzení zrušení účasti' : 'Potvrzení zrušení termínu',
            ($removedFromPairOnly ? "Tvoje účast na párovém termínu {$event['starts_at']} byla zrušena." : "Tvůj termín {$event['starts_at']} byl zrušen.")
            . ($wasAlreadyPaid ? ' Tento termín byl již uhrazen a bude započten do další fakturace jako zápočet.' : '')
            . ($paymentStatus === 'pending' ? ' Tento termín byl součástí otevřené výzvy k platbě, částka výzvy se nesnižuje.' : '')
            . ($isLateCancellation ? ' Zrušení proběhlo méně než 12 hodin před začátkem, termín je bez nároku na kompenzaci a nelze jej nahradit.' : '')
            . ($requiresReplacement ? (' Náhradní termín je potřeba vybrat do ' . $coachMakeupDeadlineDays . ' dnů.') : '')
        );
    }
    if ($removedFromPairOnly) {
        if (function_exists('enqueueCoachGoogleCalendarSync')) enqueueCoachGoogleCalendarSync((int)$event['coach_id'], $eventId, 'upsert');
        if (function_exists('enqueueCoachAppleCaldavSync')) enqueueCoachAppleCaldavSync((int)$event['coach_id'], $eventId, 'upsert');
        if (function_exists('enqueueAthleteAppleCaldavSync')) {
            enqueueAthleteAppleCaldavSync($athleteId, $eventId, 'delete');
            $remainingAthleteId = $primaryAthleteId === $athleteId ? $secondAthleteId : $primaryAthleteId;
            if ($remainingAthleteId > 0) enqueueAthleteAppleCaldavSync($remainingAthleteId, $eventId, 'upsert');
        }
    } else {
        if (function_exists('enqueueCoachGoogleCalendarSync')) enqueueCoachGoogleCalendarSync((int)$event['coach_id'], $eventId, 'delete');
        if (function_exists('enqueueCoachAppleCaldavSync')) enqueueCoachAppleCaldavSync((int)$event['coach_id'], $eventId, 'delete');
        if (function_exists('enqueueAthleteAppleCaldavSync')) enqueueAthleteAppleCaldavSync($athleteId, $eventId, 'delete');
    }
    if (function_exists('processInlineCalendarSyncQueues')) {
        processInlineCalendarSyncQueues(2, 3, 3);
    }
    $message = ($removedFromPairOnly
            ? "Účast na párovém termínu byla zrušena. Trenér {$coachDisplayName} byl informován."
            : "Termín byl zrušen. Trenér {$coachDisplayName} byl informován.")
        . ($wasAlreadyPaid ? ' Jednalo se o již uhrazený termín, který bude započten v další fakturaci.' : '')
        . ($paymentStatus === 'pending' ? ' Termín byl součástí výzvy k platbě a částka výzvy se nesnižuje.' : '')
        . ($isLateCancellation ? ' Zrušení proběhlo méně než 12 hodin před začátkem, termín je bez nároku na kompenzaci a nelze jej nahradit.' : '')
        . ($requiresReplacement ? (' Náhradní termín musíte vybrat do ' . $coachMakeupDeadlineDays . ' dnů.') : '');
    mobileJson([
        'success' => true,
        'message' => $message,
        'was_paid' => $wasAlreadyPaid,
        'payment_status' => $paymentStatus,
        'late_cancellation' => $isLateCancellation,
        'replacement_required' => $requiresReplacement,
    ]);
}

if ($action === 'cancel_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = (int)($input['event_id'] ?? 0);
    $del = $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND coach_id = ? AND requested_by_athlete_id = ? AND approval_status = "pending"');
    $del->execute([$eventId, $coachId, $athleteId]);
    if ($del->rowCount() < 1) {
        mobileJson(['success' => false, 'error' => 'Žádost se nepodařilo zrušit.'], 404);
    }
    mobileJson(['success' => true]);
}

if ($action === 'chat') {
    $with = (string)($_GET['with'] ?? $input['with'] ?? 'coach');
    $messages = [];
    if ($with === 'admin') {
        try {
            $pdo->prepare("UPDATE admin_athlete_chat_messages SET athlete_read_at = NOW() WHERE athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL")->execute([$athleteId]);
            $stmt = $pdo->prepare('SELECT id, sender, body, created_at FROM admin_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at ASC, id ASC LIMIT 200');
            $stmt->execute([$athleteId]);
            foreach ($stmt->fetchAll() as $row) {
                $messages[] = [
                    'id' => (int)$row['id'],
                    'sender' => (string)$row['sender'],
                    'body' => (string)$row['body'],
                    'created_at' => (string)$row['created_at'],
                    'is_me' => (string)$row['sender'] === 'athlete',
                ];
            }
        } catch (Throwable $e) {
            mobileJson(['success' => false, 'error' => 'Chat s administrátorem se nepodařilo načíst.'], 500);
        }
        mobileJson(['success' => true, 'with' => 'admin', 'messages' => $messages]);
    }
    try {
        $pdo->prepare("UPDATE coach_athlete_chat_messages SET athlete_read_at = NOW() WHERE athlete_id = ? AND sender = 'coach' AND athlete_read_at IS NULL")->execute([$athleteId]);
    } catch (Throwable $e) {
    }
    $stmt = $pdo->prepare('SELECT id, sender, body, created_at FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = ? ORDER BY created_at ASC, id ASC LIMIT 200');
    $stmt->execute([$coachId, $athleteId]);
    foreach ($stmt->fetchAll() as $row) {
        $messages[] = [
            'id' => (int)$row['id'],
            'sender' => (string)$row['sender'],
            'body' => (string)$row['body'],
            'created_at' => (string)$row['created_at'],
            'is_me' => (string)$row['sender'] === 'athlete',
        ];
    }
    mobileJson(['success' => true, 'with' => 'coach', 'messages' => $messages, 'coach_name' => 'Trenér']);
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = trim((string)($input['body'] ?? ''));
    if ($body === '') {
        mobileJson(['success' => false, 'error' => 'Není co odeslat.'], 422);
    }
    $with = (string)($input['with'] ?? 'coach');
    if ($with === 'admin') {
        try {
            $stmt = $pdo->prepare("INSERT INTO admin_athlete_chat_messages (athlete_id, sender, body, athlete_read_at) VALUES (?, 'athlete', ?, NOW())");
            $stmt->execute([$athleteId, $body]);
            $newId = (int)$pdo->lastInsertId();
            if (function_exists('notifyAdminAboutNewAthleteChatMessage')) {
                notifyAdminAboutNewAthleteChatMessage($athleteId, $newId, (string)$athlete['name'], $body);
            }
            mobileJson(['success' => true, 'id' => $newId]);
        } catch (Throwable $e) {
            mobileJson(['success' => false, 'error' => 'Zprávu administrátorovi se nepodařilo odeslat.'], 500);
        }
    }
    $stmt = $pdo->prepare("INSERT INTO coach_athlete_chat_messages (coach_id, athlete_id, sender, body, created_at) VALUES (?, ?, 'athlete', ?, NOW())");
    $stmt->execute([$coachId, $athleteId, $body]);
    mobileJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'payments') {
    $rows = [];
    try {
        athletePortalRefreshPendingPayments($pdo, $coachId, $athleteId);
        $hasIssue = athletePortalColumnExists($pdo, 'athlete_monthly_payments', 'issue_number');
        $issueSelect = $hasIssue ? 'issue_number' : '1 AS issue_number';
        $issueOrder = $hasIssue ? 'issue_number ASC' : 'billing_month ASC';
        $pay = $pdo->prepare(
            "SELECT billing_month, {$issueSelect}, planned_sessions, billed_amount, status, paid_at, session_rate
             FROM athlete_monthly_payments
             WHERE athlete_id = ? AND coach_id = ?
             ORDER BY billing_month DESC, {$issueOrder}
             LIMIT 24"
        );
        $pay->execute([$athleteId, $coachId]);
        foreach ($pay->fetchAll() as $row) {
            $monthRaw = (string)$row['billing_month'];
            $monthSql = preg_match('/^\d{4}-\d{2}$/', $monthRaw) ? $monthRaw . '-01' : substr($monthRaw, 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $monthSql)) {
                $monthSql = date('Y-m-01');
            }
            $trainings = athletePortalReceiptLines($pdo, $coachId, $athleteId, substr($monthSql, 0, 7) . '-01', $row);
            $issueNumber = max(1, (int)($row['issue_number'] ?? 1));
            $amountValue = $row['billed_amount'] !== null ? (float)$row['billed_amount'] : null;
            $rows[] = [
                'month' => $monthRaw,
                'issue_number' => $issueNumber,
                'qr_url' => ((string)($row['status'] ?? '') === 'pending' && $amountValue !== null)
                    ? athletePortalPaymentQrUrl($pdo, $coachId, $amountValue, substr($monthSql, 0, 7) . '-01', $issueNumber)
                    : null,
                'label' => date('m/Y', strtotime($monthSql)) . ($issueNumber > 1 ? ' — dodatečná výzva' : ''),
                'sessions' => count($trainings),
                'amount' => $row['billed_amount'] !== null ? (float)$row['billed_amount'] : null,
                'status' => (string)($row['status'] ?? ''),
                'paid_at' => (string)($row['paid_at'] ?? ''),
                'session_rate' => $row['session_rate'] !== null ? (float)$row['session_rate'] : null,
                'trainings' => $trainings,
            ];
        }
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Platby se nepodařilo načíst.'], 500);
    }
    mobileJson(['success' => true, 'payments' => $rows]);
}

if ($action === 'weight' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $logs = [];
    $stmt = $pdo->prepare('SELECT id, measured_at, weight_kg FROM athlete_weight_logs WHERE athlete_id = ? ORDER BY measured_at DESC, id DESC LIMIT 400');
    $stmt->execute([$athleteId]);
    foreach ($stmt->fetchAll() as $row) {
        $logs[] = ['id' => (int)$row['id'], 'measured_at' => (string)$row['measured_at'], 'weight_kg' => (float)$row['weight_kg']];
    }
    mobileJson(['success' => true, 'logs' => $logs]);
}

if ($action === 'save_weight' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $weightInput = str_replace(',', '.', trim((string)($input['weight_kg'] ?? '')));
    $measuredAt = preg_replace('/[^0-9\-]/', '', (string)($input['measured_at'] ?? date('Y-m-d')));
    $weightKg = is_numeric($weightInput) ? (float)$weightInput : 0.0;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredAt)) {
        mobileJson(['success' => false, 'error' => 'Zadejte platné datum vážení.'], 422);
    }
    if ($weightKg < 20 || $weightKg > 400) {
        mobileJson(['success' => false, 'error' => 'Zadejte platnou hmotnost v kg.'], 422);
    }
    $existingWeight = function_exists('getAthleteWeightLogForDate') ? getAthleteWeightLogForDate($athleteId, $measuredAt) : null;
    if ($existingWeight !== null) {
        mobileJson([
            'success' => false,
            'error' => 'Na ' . date('j. n. Y', strtotime($measuredAt)) . ' už máte zadanou váhu ' . number_format((float)$existingWeight['weight_kg'], 1, ',', '') . ' kg. Znovu ji zadat nejde.',
        ], 409);
    }
    if (function_exists('addAthleteWeightLog')) {
        $newWeightId = addAthleteWeightLog($athleteId, $measuredAt, $weightKg, 'athlete_link', null, null);
        if ($newWeightId <= 0) {
            mobileJson(['success' => false, 'error' => 'Na tento den už je váha zadaná. Znovu ji zadat nejde.'], 409);
        }
    } else {
        $stmt = $pdo->prepare('INSERT INTO athlete_weight_logs (athlete_id, measured_at, weight_kg, source) VALUES (?, ?, ?, "athlete_link")');
        $stmt->execute([$athleteId, $measuredAt, $weightKg]);
    }
    mobileJson(['success' => true]);
}

mobileJson(['success' => false, 'error' => 'Neznámá akce.'], 400);
