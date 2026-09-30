<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$athlete = mobileRequireAthlete();
$pdo = getDB();
$input = mobileInput();
$action = (string)($_REQUEST['action'] ?? $input['action'] ?? 'home');
$athleteId = (int)$athlete['id'];
$coachId = (int)$athlete['coach_id'];

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

function athletePortalColumnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ' . $pdo->quote($column));
        return $stmt !== false && (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function athletePortalReceiptEvents(PDO $pdo, int $coachId, int $athleteId, string $monthSql): array
{
    $hasSecond = athletePortalColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
    $makeupSql = athletePortalColumnExists($pdo, 'coach_calendar_events', 'is_makeup_session') ? 'COALESCE(e.is_makeup_session, 0)' : '0';
    $billingSql = athletePortalColumnExists($pdo, 'coach_calendar_events', 'billing_month')
        ? "DATE_FORMAT(COALESCE(e.billing_month, e.starts_at), '%Y-%m-01')"
        : "DATE_FORMAT(e.starts_at, '%Y-%m-01')";
    $titleSql = athletePortalColumnExists($pdo, 'coach_calendar_events', 'custom_title') ? 'e.custom_title' : "''";
    if ($hasSecond) {
        $sql = "SELECT e.id, {$titleSql} AS custom_title, e.starts_at, e.ends_at, e.location, {$makeupSql} AS is_makeup_session, {$billingSql} AS billing_month,
                       CASE WHEN e.second_athlete_id IS NOT NULL THEN 1 ELSE 0 END AS is_paired
                FROM coach_calendar_events e
                WHERE e.coach_id = ? AND e.approval_status = 'approved' AND e.athlete_id = ? AND DATE_FORMAT(e.starts_at, '%Y-%m-01') = ?
                UNION ALL
                SELECT e.id, {$titleSql}, e.starts_at, e.ends_at, e.location, {$makeupSql}, {$billingSql}, 1
                FROM coach_calendar_events e
                WHERE e.coach_id = ? AND e.approval_status = 'approved' AND e.second_athlete_id = ? AND DATE_FORMAT(e.starts_at, '%Y-%m-01') = ?
                ORDER BY starts_at ASC";
        $params = [$coachId, $athleteId, $monthSql, $coachId, $athleteId, $monthSql];
    } else {
        $sql = "SELECT e.id, {$titleSql} AS custom_title, e.starts_at, e.ends_at, e.location, {$makeupSql} AS is_makeup_session, {$billingSql} AS billing_month, 0 AS is_paired
                FROM coach_calendar_events e
                WHERE e.coach_id = ? AND e.approval_status = 'approved' AND e.athlete_id = ? AND DATE_FORMAT(e.starts_at, '%Y-%m-01') = ?
                ORDER BY starts_at ASC";
        $params = [$coachId, $athleteId, $monthSql];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function athletePortalCarryoverBefore(PDO $pdo, int $coachId, int $athleteId, string $monthSql): int
{
    $hasSecond = athletePortalColumnExists($pdo, 'coach_calendar_events', 'second_athlete_id');
    if ($hasSecond) {
        $participants = 'SELECT DATE_FORMAT(e.starts_at, \'%Y-%m-01\') AS billing_month FROM coach_calendar_events e WHERE e.coach_id = ? AND e.approval_status = \'approved\' AND e.athlete_id = ?
                         UNION ALL
                         SELECT DATE_FORMAT(e.starts_at, \'%Y-%m-01\') FROM coach_calendar_events e WHERE e.coach_id = ? AND e.approval_status = \'approved\' AND e.second_athlete_id = ?';
        $params = [$coachId, $athleteId, $coachId, $athleteId, $monthSql];
    } else {
        $participants = 'SELECT DATE_FORMAT(e.starts_at, \'%Y-%m-01\') AS billing_month FROM coach_calendar_events e WHERE e.coach_id = ? AND e.approval_status = \'approved\' AND e.athlete_id = ?';
        $params = [$coachId, $athleteId, $monthSql];
    }
    $actualByMonth = [];
    try {
        $stmt = $pdo->prepare("SELECT t.billing_month, COUNT(*) AS billed_sessions FROM ({$participants}) t WHERE t.billing_month < ? GROUP BY t.billing_month");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $actualByMonth[(string)$row['billing_month']] = (int)$row['billed_sessions'];
        }
    } catch (Throwable $e) {
        return 0;
    }

    $carryCol = athletePortalColumnExists($pdo, 'athlete_monthly_payments', 'carryover_used_sessions') ? 'carryover_used_sessions' : '0';
    try {
        $stmt = $pdo->prepare("SELECT billing_month, planned_sessions, {$carryCol} AS carryover_used_sessions FROM athlete_monthly_payments WHERE coach_id = ? AND athlete_id = ? AND status = 'paid' AND billing_month < ? ORDER BY billing_month ASC");
        $stmt->execute([$coachId, $athleteId, $monthSql]);
    } catch (Throwable $e) {
        return 0;
    }

    $outstanding = 0;
    foreach ($stmt->fetchAll() as $row) {
        $month = substr((string)$row['billing_month'], 0, 7) . '-01';
        $planned = max(0, (int)$row['planned_sessions']);
        $actual = max(0, (int)($actualByMonth[$month] ?? $actualByMonth[(string)$row['billing_month']] ?? 0));
        $outstanding += max(0, $planned - $actual);
        $outstanding = max(0, $outstanding - max(0, (int)($row['carryover_used_sessions'] ?? 0)));
    }
    return $outstanding;
}

function athletePortalReceiptLines(PDO $pdo, int $coachId, int $athleteId, string $monthSql, array $payment): array
{
    try {
        $events = athletePortalReceiptEvents($pdo, $coachId, $athleteId, $monthSql);
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

    $transferred = 0;
    foreach ($events as $event) {
        $billed = substr((string)($event['billing_month'] ?? ''), 0, 10);
        if ($billed !== '' && $billed !== $monthSql) {
            $transferred++;
        }
    }
    $total = count($events);
    $outstanding = athletePortalCarryoverBefore($pdo, $coachId, $athleteId, $monthSql);
    $carryover = $total === 0 ? 0 : max(min($outstanding, $total), min($transferred, $total));
    if ((string)($payment['status'] ?? '') === 'paid' && athletePortalColumnExists($pdo, 'athlete_monthly_payments', 'receipt_snapshot_json')) {
        try {
            $snapStmt = $pdo->prepare('SELECT receipt_snapshot_json FROM athlete_monthly_payments WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? LIMIT 1');
            $snapStmt->execute([$coachId, $athleteId, (string)($payment['billing_month'] ?? $monthSql)]);
            $snapshot = json_decode((string)($snapStmt->fetchColumn() ?: ''), true);
            if (is_array($snapshot) && isset($snapshot['event_rows']) && is_array($snapshot['event_rows'])) {
                $events = [];
                foreach ($snapshot['event_rows'] as $snapshotEvent) {
                    if (!is_array($snapshotEvent)) {
                        continue;
                    }
                    $events[] = [
                        'id' => (int)($snapshotEvent['id'] ?? 0),
                        'custom_title' => '',
                        'starts_at' => (string)($snapshotEvent['starts_at'] ?? ''),
                        'ends_at' => (string)($snapshotEvent['ends_at'] ?? ''),
                        'location' => (string)($snapshotEvent['location'] ?? ''),
                        'billing_month' => (string)($snapshotEvent['billing_month'] ?? ''),
                        'is_makeup_session' => (int)($snapshotEvent['is_makeup_session'] ?? 0),
                        'is_paired' => (int)($snapshotEvent['is_paired'] ?? 0),
                    ];
                }
                $snapshotBreakdown = $snapshot['breakdown'] ?? null;
                if (is_array($snapshotBreakdown)) {
                    $carryover = (int)($snapshotBreakdown['carryover_used'] ?? $snapshotBreakdown['carryover_applied'] ?? $carryover);
                }
            }
        } catch (Throwable $e) {
        }
    }
    $directOrigins = [];
    $fallbackOrigins = [];
    try {
        if (athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')
            && athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'starts_at')) {
            $originOrder = athletePortalColumnExists($pdo, 'coach_calendar_event_cancellations', 'canceled_at') ? 'canceled_at ASC, id ASC' : 'id ASC';
            $originStmt = $pdo->prepare("SELECT replacement_event_id, starts_at FROM coach_calendar_event_cancellations WHERE coach_id = ? ORDER BY {$originOrder}");
            $originStmt->execute([$coachId]);
            foreach ($originStmt->fetchAll() as $originRow) {
                $replacementId = (int)($originRow['replacement_event_id'] ?? 0);
                $originStart = (string)($originRow['starts_at'] ?? '');
                if ($replacementId > 0 && !isset($directOrigins[$replacementId])) {
                    $directOrigins[$replacementId] = $originStart;
                    continue;
                }
                if ($originStart !== '') {
                    $fallbackOrigins[] = $originStart;
                }
            }
        }
    } catch (Throwable $e) {
    }
    $carryLeft = $carryover;
    $paidAt = trim((string)($payment['paid_at'] ?? ''));
    $lines = [];
    foreach ($events as $event) {
        $paired = (int)($event['is_paired'] ?? 0) === 1;
        $rate = $paired ? ($pairedRate ?? $singleRate) : $singleRate;
        $notes = [];
        $amount = 0.0;
        if ($carryLeft > 0) {
            $paidLabel = $paidAt !== '' ? ' (' . date('m/Y', strtotime($paidAt)) . ')' : '';
            $notes[] = 'Hrazeno v předešlém období' . $paidLabel;
        } elseif ($rate !== null) {
            $amount = $rate;
        }
        $originStart = (string)($directOrigins[(int)($event['id'] ?? 0)] ?? '');
        if ($originStart === '' && (int)($event['is_makeup_session'] ?? 0) === 1 && $fallbackOrigins !== []) {
            $originStart = (string)array_shift($fallbackOrigins);
        }
        if ($originStart !== '') {
            $notes[] = 'Původní termín: ' . date('d.m.Y', strtotime($originStart));
        }
        if ($carryLeft > 0) {
            $carryLeft--;
        }
        $billed = substr((string)($event['billing_month'] ?? ''), 0, 10);
        $defaultNote = ($billed !== '' && $billed !== $monthSql) ? 'Převod z předchozího období' : '';
        $lines[] = [
            'title' => trim((string)($event['custom_title'] ?? '')) !== '' ? (string)$event['custom_title'] : 'Trénink',
            'location' => (string)($event['location'] ?? ''),
            'starts_at' => (string)$event['starts_at'],
            'ends_at' => (string)$event['ends_at'],
            'is_paired' => $paired,
            'is_makeup' => (int)($event['is_makeup_session'] ?? 0) === 1,
            'amount' => $amount,
            'note' => $notes !== [] ? implode('; ', $notes) : $defaultNote,
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
        $pay = $pdo->prepare(
            'SELECT billing_month, planned_sessions, billed_amount, status, paid_at
             FROM athlete_monthly_payments
             WHERE athlete_id = ? AND coach_id = ?
             ORDER BY billing_month DESC
             LIMIT 12'
        );
        $pay->execute([$athleteId, $coachId]);
        foreach ($pay->fetchAll() as $row) {
            $payments[] = [
                'month' => (string)$row['billing_month'],
                'sessions' => (int)($row['planned_sessions'] ?? 0),
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
        $pay = $pdo->prepare(
            'SELECT billing_month, planned_sessions, billed_amount, status, paid_at, session_rate
             FROM athlete_monthly_payments
             WHERE athlete_id = ? AND coach_id = ?
             ORDER BY billing_month DESC
             LIMIT 24'
        );
        $pay->execute([$athleteId, $coachId]);
        foreach ($pay->fetchAll() as $row) {
            $monthRaw = (string)$row['billing_month'];
            $monthSql = preg_match('/^\d{4}-\d{2}$/', $monthRaw) ? $monthRaw . '-01' : substr($monthRaw, 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $monthSql)) {
                $monthSql = date('Y-m-01');
            }
            $rows[] = [
                'month' => $monthRaw,
                'sessions' => (int)($row['planned_sessions'] ?? 0),
                'amount' => $row['billed_amount'] !== null ? (float)$row['billed_amount'] : null,
                'status' => (string)($row['status'] ?? ''),
                'paid_at' => (string)($row['paid_at'] ?? ''),
                'session_rate' => $row['session_rate'] !== null ? (float)$row['session_rate'] : null,
                'trainings' => athletePortalReceiptLines($pdo, $coachId, $athleteId, substr($monthSql, 0, 7) . '-01', $row),
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
    if (function_exists('addAthleteWeightLog')) {
        addAthleteWeightLog($athleteId, $measuredAt, $weightKg, 'athlete_link', null, null);
    } else {
        $stmt = $pdo->prepare('INSERT INTO athlete_weight_logs (athlete_id, measured_at, weight_kg, source) VALUES (?, ?, ?, "athlete_link")');
        $stmt->execute([$athleteId, $measuredAt, $weightKg]);
    }
    mobileJson(['success' => true]);
}

mobileJson(['success' => false, 'error' => 'Neznámá akce.'], 400);
