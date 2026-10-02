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
$replacementMode = trim((string)($input['replacement_mode'] ?? 'none'));
if (!in_array($replacementMode, ['none', '14_days'], true)) {
    $replacementMode = 'none';
}
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
            e.title_type,
        e.requested_by_athlete_id,
        e.approval_status,
            e.billing_month,
            e.billing_issue_number,
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
                title_type,
                billing_month,
                billing_issue_number,
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
        'id' => (int)$event['id'],
        'coach_id' => $coachId,
        'athlete_id' => $event['athlete_id'] ?? null,
        'second_athlete_id' => $event['second_athlete_id'] ?? null,
        'approval_status' => $event['approval_status'] ?? 'approved',
        'is_makeup_session' => $event['is_makeup_session'] ?? 0,
        'title_type' => $event['title_type'] ?? 'training',
        'billing_month' => $event['billing_month'] ?? null,
        'billing_issue_number' => $event['billing_issue_number'] ?? 1,
        'custom_title' => $event['custom_title'] ?? null,
        'location' => $event['location'] ?? null,
        'starts_at' => $event['starts_at'] ?? null,
        'ends_at' => $event['ends_at'] ?? null,
    ];
}

$hasBillingMonth = coachDeleteHasColumn($pdo, 'coach_calendar_events', 'billing_month');
$hasBillingIssue = coachDeleteHasColumn($pdo, 'coach_calendar_events', 'billing_issue_number');
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
              AND p.issue_number = " . ($hasBillingIssue ? 'COALESCE(e.billing_issue_number, 1)' : '1') . "
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
            $billingMonthStmt = $pdo->prepare('SELECT DATE_FORMAT(COALESCE(billing_month, starts_at), "%Y-%m-01") AS billing_month, ' . ($hasBillingIssue ? 'billing_issue_number' : '1 AS billing_issue_number') . ' FROM coach_calendar_events WHERE id = ? LIMIT 1');
            $billingMonthStmt->execute([$eventId]);
            $billingEvent = $billingMonthStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $billingMonthSql = (string)($billingEvent['billing_month'] ?? $billingMonthSql);
            $billingIssueNumber = max(1, (int)($billingEvent['billing_issue_number'] ?? 1));
        } else {
            $billingIssueNumber = 1;
        }

        if (!empty($event['athlete_id'])) {
            $paidAffectedStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM athlete_monthly_payments
                 WHERE coach_id = ?
                   AND athlete_id = ?
                   AND billing_month = ?
                     AND issue_number = ?
                   AND status = "paid"'
            );
                 $paidAffectedStmt->execute([$coachId, (int)$event['athlete_id'], $billingMonthSql, $billingIssueNumber]);
            $paidAffectedCount = (int)$paidAffectedStmt->fetchColumn();
        }
    }
}

$pdo->beginTransaction();
try {
    if ($deleteScope === 'future') {
        if (empty($event['series_id'])) {
            throw new RuntimeException('Událost není součástí série.');
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
        throw new RuntimeException('Událost nenalezena.');
    }

    foreach ($eventsToCancel as $cancelEvent) {
        $participantIds = array_values(array_unique(array_filter([
            (int)($cancelEvent['athlete_id'] ?? 0),
            (int)($cancelEvent['second_athlete_id'] ?? 0),
        ])));
        if ($participantIds === []) {
            continue;
        }

        $startsAt = (string)($cancelEvent['starts_at'] ?? '');
        $startDate = new DateTimeImmutable($startsAt);
        $isTraining = (string)($cancelEvent['title_type'] ?? 'training') === 'training';
        $billingMonth = !empty($cancelEvent['billing_month'])
            ? date('Y-m-01', strtotime((string)$cancelEvent['billing_month']))
            : date('Y-m-01', strtotime($startsAt));
        $issueNumber = max(1, (int)($cancelEvent['billing_issue_number'] ?? 1));

        foreach ($participantIds as $participantId) {
            $hasReplacementSlot = coachHasActiveSlotForAthlete(
                $pdo,
                $coachId,
                $participantId,
                $startsAt,
                (string)($cancelEvent['ends_at'] ?? $startsAt)
            );
            $replacementRequired = $replacementMode === '14_days'
                && $isTraining
                && !$hasReplacementSlot
                && (string)($cancelEvent['approval_status'] ?? 'approved') === 'approved';
            $replacementDeadline = $replacementRequired
                ? $startDate->modify('+14 days')->setTime(23, 59, 59)->format('Y-m-d H:i:s')
                : null;
            $paymentStatus = 'none';
            if ($hasPayments) {
                $paymentStatusStmt = $pdo->prepare(
                    'SELECT status FROM athlete_monthly_payments
                     WHERE coach_id = ? AND athlete_id = ? AND billing_month = ? AND issue_number = ?
                     LIMIT 1'
                );
                $paymentStatusStmt->execute([$coachId, $participantId, $billingMonth, $issueNumber]);
                $paymentStatus = (string)($paymentStatusStmt->fetchColumn() ?: 'none');
            }

            $columns = [
                'coach_id', 'athlete_id', 'second_athlete_id', 'canceled_by', 'canceled_by_athlete_id',
                'cancellation_scope', 'approval_status', 'is_makeup_session', 'custom_title', 'location',
                'starts_at', 'ends_at', 'canceled_at',
            ];
            $values = ['?', '?', 'NULL', '"coach"', 'NULL', '?', '?', '?', '?', '?', '?', '?', 'NOW()'];
            $params = [
                (int)($cancelEvent['coach_id'] ?? $coachId),
                $participantId,
                $deleteScope === 'future' ? 'future' : ((count($participantIds) > 1) ? 'pair_exit' : 'single'),
                (string)($cancelEvent['approval_status'] ?? 'approved') === 'pending' ? 'pending' : 'approved',
                !empty($cancelEvent['is_makeup_session']) ? 1 : 0,
                ($cancelEvent['custom_title'] ?? null) !== '' ? (string)$cancelEvent['custom_title'] : null,
                ($cancelEvent['location'] ?? null) !== '' ? (string)$cancelEvent['location'] : null,
                $startsAt,
                (string)($cancelEvent['ends_at'] ?? $startsAt),
            ];
            foreach ([
                'billing_month' => $billingMonth,
                'billing_issue_number' => max(1, (int)($cancelEvent['billing_issue_number'] ?? 1)),
                'payment_status_snapshot' => $paymentStatus,
                'replacement_required' => $replacementRequired ? 1 : 0,
                'replacement_deadline_at' => $replacementDeadline,
            ] as $column => $value) {
                if (coachDeleteHasColumn($pdo, 'coach_calendar_event_cancellations', $column)) {
                    $columns[] = $column;
                    $values[] = '?';
                    $params[] = $value;
                }
            }
            if (coachDeleteHasColumn($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id')) {
                $columns[] = 'replacement_event_id';
                $values[] = 'NULL';
            }

            $cancelInsert = $pdo->prepare(
                'INSERT INTO coach_calendar_event_cancellations (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', $values) . ')'
            );
            $cancelInsert->execute($params);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$deletedEventIds = [];
foreach ($eventsToCancel as $cancelEvent) {
    $id = (int)($cancelEvent['id'] ?? 0);
    if ($id > 0) {
        $deletedEventIds[] = $id;
    }
}
foreach (array_values(array_unique($deletedEventIds)) as $deletedEventId) {
    enqueueCoachGoogleCalendarSync($coachId, $deletedEventId, 'delete');
    enqueueCoachAppleCaldavSync($coachId, $deletedEventId, 'delete');

    foreach ($eventsToCancel as $cancelEvent) {
        if ((int)($cancelEvent['id'] ?? 0) !== (int)$deletedEventId) {
            continue;
        }
        if ((int)($cancelEvent['athlete_id'] ?? 0) > 0) {
            enqueueAthleteAppleCaldavSync((int)$cancelEvent['athlete_id'], $deletedEventId, 'delete');
        }
        if ((int)($cancelEvent['second_athlete_id'] ?? 0) > 0) {
            enqueueAthleteAppleCaldavSync((int)$cancelEvent['second_athlete_id'], $deletedEventId, 'delete');
        }
    }
}
processInlineCalendarSyncQueues(2, 3, 3);

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

        if (!$isPendingRequest && !$hasReplacement && (string)($event['title_type'] ?? 'training') === 'training') {
            $body .= $replacementMode === '14_days'
                ? ' Trenér vám ponechal možnost vybrat náhradní termín do 14 kalendářních dní od zrušeného termínu.'
                : ' Trenér zvolil zrušení bez náhradního termínu.';
        }
        if ($paidAffectedCount > 0) {
            $body .= ' Původní uhrazená výzva zůstává beze změny.';
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
        ? ($replacementMode === '14_days'
            ? 'Uhrazený termín byl zrušen. Náhradu rezervujte do 14 kalendářních dní; nebude účtována znovu.'
            : 'Uhrazený termín byl zrušen bez náhrady. Nárok na náhradní slot nevznikl. Původní uhrazená výzva zůstává beze změny; nevzniká zápočet do další fakturace.')
        : ($replacementMode === '14_days'
            ? 'Událost byla zrušena s možností náhrady do 14 kalendářních dní od původního termínu.'
            : 'Událost byla zrušena bez náhrady. Nárok na náhradní slot nevznikl.'),
]);
