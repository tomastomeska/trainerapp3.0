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

$eventId = (int)($input['event_id'] ?? 0);
$deleteScope = trim((string)($input['delete_scope'] ?? 'single'));
$coachId = (int)getCurrentCoachId();

function coachDeleteHasColumn(PDO $pdo, string $table, string $column): bool
{
    $quotedColumn = $pdo->quote($column);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$quotedColumn}");
    return $stmt !== false && (bool)$stmt->fetch();
}

function coachHasActiveSlotForAthlete(PDO $pdo, int $coachId, int $athleteId, string $startsAt, string $endsAt): bool
{
    if ($athleteId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM coach_calendar_events
         WHERE coach_id = ?
           AND starts_at = ?
           AND ends_at = ?
           AND (athlete_id = ? OR second_athlete_id = ?)
         LIMIT 1'
    );
    $stmt->execute([$coachId, $startsAt, $endsAt, $athleteId, $athleteId]);

    return (bool)$stmt->fetchColumn();
}

if (!in_array($deleteScope, ['single', 'future'], true)) {
    $deleteScope = 'single';
}

if ($eventId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Chybí ID události']);
    exit;
}

$pdo = getDB();

$eventStmt = $pdo->prepare(
    'SELECT e.id,
            e.series_id,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.athlete_id,
            e.second_athlete_id,
        e.is_makeup_session,
        e.requested_by_athlete_id,
        e.approval_status,
            a.email AS athlete_email,
            a.first_name,
            a.last_name,
            a2.email AS second_athlete_email,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
    FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE e.id = ? AND e.coach_id = ?
     LIMIT 1'
);
$eventStmt->execute([$eventId, $coachId]);
$event = $eventStmt->fetch();

if (!$event) {
    echo json_encode(['success' => false, 'error' => 'Událost nenalezena']);
    exit;
}

$eventsToCancel = [];
if ($deleteScope === 'future' && !empty($event['series_id'])) {
    $cancelStmt = $pdo->prepare(
        'SELECT id,
                coach_id,
                athlete_id,
                second_athlete_id,
                approval_status,
                is_makeup_session,
                custom_title,
                location,
                starts_at,
                ends_at
         FROM coach_calendar_events
         WHERE coach_id = ?
           AND series_id = ?
           AND starts_at >= ?
         ORDER BY starts_at ASC, id ASC'
    );
    $cancelStmt->execute([$coachId, $event['series_id'], $event['starts_at']]);
    $eventsToCancel = $cancelStmt->fetchAll();
} else {
    $eventsToCancel[] = [
        'coach_id' => $coachId,
        'athlete_id' => $event['athlete_id'] ?? null,
        'second_athlete_id' => $event['second_athlete_id'] ?? null,
        'approval_status' => $event['approval_status'] ?? 'approved',
        'is_makeup_session' => $event['is_makeup_session'] ?? 0,
        'custom_title' => $event['custom_title'] ?? null,
        'location' => $event['location'] ?? null,
        'starts_at' => $event['starts_at'] ?? null,
        'ends_at' => $event['ends_at'] ?? null,
    ];
}

$hasBillingMonth = coachDeleteHasColumn($pdo, 'coach_calendar_events', 'billing_month');
$hasPayments = false;
try {
    $hasPaymentsStmt = $pdo->query("SHOW TABLES LIKE 'athlete_monthly_payments'");
    $hasPayments = $hasPaymentsStmt !== false && (bool)$hasPaymentsStmt->fetchColumn();
} catch (Throwable $e) {
    $hasPayments = false;
}

$paidAffectedCount = 0;
if ($hasPayments) {
    if ($deleteScope === 'future' && !empty($event['series_id'])) {
        $billingExpr = $hasBillingMonth
            ? "DATE_FORMAT(COALESCE(e.billing_month, e.starts_at), '%Y-%m-01')"
            : "DATE_FORMAT(e.starts_at, '%Y-%m-01')";

        $paidAffectedStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM coach_calendar_events e
             JOIN athlete_monthly_payments p
               ON p.coach_id = e.coach_id
              AND p.athlete_id = e.athlete_id
              AND p.status = 'paid'
              AND p.billing_month = {$billingExpr}
             WHERE e.coach_id = ?
               AND e.series_id = ?
               AND e.starts_at >= ?
               AND e.athlete_id IS NOT NULL"
        );
        $paidAffectedStmt->execute([$coachId, $event['series_id'], $event['starts_at']]);
        $paidAffectedCount = (int)$paidAffectedStmt->fetchColumn();
    } else {
        $billingMonthSql = date('Y-m-01', strtotime((string)$event['starts_at']));
        if ($hasBillingMonth) {
            $billingMonthStmt = $pdo->prepare('SELECT DATE_FORMAT(COALESCE(billing_month, starts_at), "%Y-%m-01") AS billing_month FROM coach_calendar_events WHERE id = ? LIMIT 1');
            $billingMonthStmt->execute([$eventId]);
            $billingMonthSql = (string)($billingMonthStmt->fetchColumn() ?: $billingMonthSql);
        }

        if (!empty($event['athlete_id'])) {
            $paidAffectedStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM athlete_monthly_payments
                 WHERE coach_id = ?
                   AND athlete_id = ?
                   AND billing_month = ?
                   AND status = "paid"'
            );
            $paidAffectedStmt->execute([$coachId, (int)$event['athlete_id'], $billingMonthSql]);
            $paidAffectedCount = (int)$paidAffectedStmt->fetchColumn();
        }
    }
}

if ($deleteScope === 'future') {
    if (empty($event['series_id'])) {
        echo json_encode(['success' => false, 'error' => 'Událost není součástí série.']);
        exit;
    }

    $del = $pdo->prepare(
        'DELETE FROM coach_calendar_events
         WHERE coach_id = ?
           AND series_id = ?
           AND starts_at >= ?'
    );
    $del->execute([$coachId, $event['series_id'], $event['starts_at']]);
} else {
    $del = $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND coach_id = ?');
    $del->execute([$eventId, $coachId]);
}

if ($del->rowCount() === 0) {
    echo json_encode(['success' => false, 'error' => 'Událost nenalezena']);
    exit;
}

if (!empty($eventsToCancel)) {
    $cancelInsert = $pdo->prepare(
        'INSERT INTO coach_calendar_event_cancellations
            (coach_id, athlete_id, second_athlete_id, canceled_by, canceled_by_athlete_id, cancellation_scope,
             approval_status, is_makeup_session, custom_title, location, starts_at, ends_at, canceled_at)
         VALUES (?, ?, ?, "coach", NULL, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    foreach ($eventsToCancel as $cancelEvent) {
        try {
            $cancelInsert->execute([
                (int)($cancelEvent['coach_id'] ?? $coachId),
                !empty($cancelEvent['athlete_id']) ? (int)$cancelEvent['athlete_id'] : null,
                !empty($cancelEvent['second_athlete_id']) ? (int)$cancelEvent['second_athlete_id'] : null,
                $deleteScope === 'future' ? 'future' : 'single',
                (string)($cancelEvent['approval_status'] ?? 'approved') === 'pending' ? 'pending' : 'approved',
                !empty($cancelEvent['is_makeup_session']) ? 1 : 0,
                ($cancelEvent['custom_title'] ?? null) !== '' ? (string)$cancelEvent['custom_title'] : null,
                ($cancelEvent['location'] ?? null) !== '' ? (string)$cancelEvent['location'] : null,
                (string)($cancelEvent['starts_at'] ?? ''),
                (string)($cancelEvent['ends_at'] ?? ''),
            ]);
        } catch (Throwable $e) {
            error_log('calendar cancellation log insert failed: ' . $e->getMessage());
        }
    }
}

$participants = [];
if (!empty($event['athlete_id'])) {
    $participants[] = [
        'id' => (int)$event['athlete_id'],
        'email' => (string)($event['athlete_email'] ?? ''),
        'name' => trim((string)($event['first_name'] ?? '') . ' ' . (string)($event['last_name'] ?? '')),
    ];
}
if (!empty($event['second_athlete_id'])) {
    $participants[] = [
        'id' => (int)$event['second_athlete_id'],
        'email' => (string)($event['second_athlete_email'] ?? ''),
        'name' => trim((string)($event['second_first_name'] ?? '') . ' ' . (string)($event['second_last_name'] ?? '')),
    ];
}

if (!empty($participants)) {
    $isPendingRequest = (($event['approval_status'] ?? 'approved') === 'pending') && !empty($event['requested_by_athlete_id']);
    $startLabel = date('d.m.Y H:i', strtotime((string)$event['starts_at']));

    foreach ($participants as $participant) {
        if (($participant['id'] ?? 0) <= 0) {
            continue;
        }

        $participantId = (int)$participant['id'];
        $hasReplacement = $deleteScope === 'single'
            && coachHasActiveSlotForAthlete(
                $pdo,
                $coachId,
                $participantId,
                (string)$event['starts_at'],
                (string)$event['ends_at']
            );

        if ($isPendingRequest) {
            $subject = 'Požadavek termínu byl zamítnut';
            $body = 'Trenér zamítl váš požadavek na termín ' . $startLabel . '.';
        } elseif ($deleteScope === 'future') {
            $subject = 'Zrušení série tréninků';
            $body = 'Trenér zrušil navazující termíny od ' . $startLabel . '.';
        } elseif ($hasReplacement) {
            $subject = 'Úprava termínu tréninku';
            $body = 'Původní termín ' . $startLabel . ' byl trenérem změněn. Ve stejném čase je evidovaný nový aktivní termín.';
        } else {
            $subject = 'Zrušení tréninku';
            $body = 'Trenér zrušil trénink naplánovaný na ' . $startLabel . '.';
        }

        if ($paidAffectedCount > 0) {
            $body .= ' Šlo o již uhrazený termín, který aplikace automaticky započte do další fakturace jako zápočet.';
        }

        createAthleteNotification((int)$participant['id'], $subject, $body);
        if (!empty($participant['email'])) {
            $name = $participant['name'] !== '' ? $participant['name'] : 'sportovec';
            sendAthleteCalendarNotificationEmail((string)$participant['email'], $name, $subject, $body);
        }
    }
}

echo json_encode([
    'success' => true,
    'deleted_count' => $del->rowCount(),
    'scope' => $deleteScope,
    'paid_affected_count' => $paidAffectedCount,
    'message' => $paidAffectedCount > 0
        ? 'Byl zrušen již uhrazený termín. Systém jej započte do další fakturace.'
        : 'Událost byla zrušena.',
]);
