<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!athleteIsLoggedIn()) {
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
if ($eventId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Chybí ID termínu']);
    exit;
}

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

function athleteDeleteHasColumn(PDO $pdo, string $table, string $column): bool
{
    $quotedColumn = $pdo->quote($column);
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$quotedColumn}");
    return $stmt !== false && (bool)$stmt->fetch();
}

$eventStmt = $pdo->prepare(
    'SELECT e.id,
            e.coach_id,
            e.athlete_id,
                        e.second_athlete_id,
            e.requested_by_athlete_id,
            e.approval_status,
            e.is_makeup_session,
            e.custom_title,
            e.location,
            e.billing_month,
            e.starts_at,
            e.ends_at,
            c.name AS coach_name,
            c.username AS coach_username,
                        a1.first_name,
                        a1.last_name,
                        a2.first_name AS second_first_name,
                        a2.last_name AS second_last_name
     FROM coach_calendar_events e
     JOIN coaches c ON c.id = e.coach_id
         LEFT JOIN athletes a1 ON a1.id = e.athlete_id
         LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE e.id = ?
             AND (e.athlete_id = ? OR e.second_athlete_id = ?)
     LIMIT 1'
);
$eventStmt->execute([$eventId, $athleteId, $athleteId]);
$event = $eventStmt->fetch();

if (!$event) {
    echo json_encode(['success' => false, 'error' => 'Termín nebyl nalezen.']);
    exit;
}

$eventStartTs = strtotime((string)$event['starts_at']);
$nowTs = time();
if ($eventStartTs !== false && $eventStartTs <= $nowTs) {
    echo json_encode([
        'success' => false,
        'error' => 'Minulé nebo právě probíhající termíny nelze rušit.',
    ]);
    exit;
}

$isLateCancellation = false;
if ($eventStartTs !== false) {
    $secondsToStart = $eventStartTs - $nowTs;
    $isLateCancellation = ($secondsToStart > 0 && $secondsToStart < (12 * 60 * 60));
}

$hasBillingMonth = athleteDeleteHasColumn($pdo, 'coach_calendar_events', 'billing_month');
$hasPayments = false;
try {
    $hasPaymentsStmt = $pdo->query("SHOW TABLES LIKE 'athlete_monthly_payments'");
    $hasPayments = $hasPaymentsStmt !== false && (bool)$hasPaymentsStmt->fetchColumn();
} catch (Throwable $e) {
    $hasPayments = false;
}

$billingMonthSql = $hasBillingMonth && !empty($event['billing_month'])
    ? date('Y-m-01', strtotime((string)$event['billing_month']))
    : date('Y-m-01', strtotime((string)$event['starts_at']));

$paymentStatus = 'none';
$wasAlreadyPaid = false;
if ($hasPayments) {
    $paidStmt = $pdo->prepare(
        'SELECT status
         FROM athlete_monthly_payments
         WHERE coach_id = ?
           AND athlete_id = ?
           AND billing_month = ?
           AND status IN ("pending", "paid")
         LIMIT 1'
    );
    $paidStmt->execute([(int)$event['coach_id'], $athleteId, $billingMonthSql]);
    $paymentStatus = (string)($paidStmt->fetchColumn() ?: 'none');
    $wasAlreadyPaid = ($paymentStatus === 'paid');
}

$requiresReplacement = (!$isLateCancellation && in_array($paymentStatus, ['pending', 'paid'], true));
$hasCoachDeadline = athleteDeleteHasColumn($pdo, 'coaches', 'makeup_booking_deadline_days');
$coachMakeupDeadlineDays = 14;
if ($hasCoachDeadline) {
    $deadlineStmt = $pdo->prepare('SELECT makeup_booking_deadline_days FROM coaches WHERE id = ? LIMIT 1');
    $deadlineStmt->execute([(int)$event['coach_id']]);
    $coachMakeupDeadlineDaysRaw = $deadlineStmt->fetchColumn();
    if ($coachMakeupDeadlineDaysRaw !== false && $coachMakeupDeadlineDaysRaw !== null) {
        $coachMakeupDeadlineDays = max(1, (int)$coachMakeupDeadlineDaysRaw);
    }
}

$replacementDeadlineAtSql = null;
if ($requiresReplacement && $coachMakeupDeadlineDays !== null) {
    $replacementDeadlineAtSql = date('Y-m-d H:i:s', strtotime('+' . $coachMakeupDeadlineDays . ' days'));
}

$hasCancelBillingMonth = athleteDeleteHasColumn($pdo, 'coach_calendar_event_cancellations', 'billing_month');
$hasCancelPaymentSnapshot = athleteDeleteHasColumn($pdo, 'coach_calendar_event_cancellations', 'payment_status_snapshot');
$hasCancelReplacementRequired = athleteDeleteHasColumn($pdo, 'coach_calendar_event_cancellations', 'replacement_required');
$hasCancelReplacementDeadline = athleteDeleteHasColumn($pdo, 'coach_calendar_event_cancellations', 'replacement_deadline_at');
$hasCancelReplacementEvent = athleteDeleteHasColumn($pdo, 'coach_calendar_event_cancellations', 'replacement_event_id');

$cancelColumns = [
    'coach_id',
    'athlete_id',
    'second_athlete_id',
    'canceled_by',
    'canceled_by_athlete_id',
    'cancellation_scope',
    'approval_status',
    'is_makeup_session',
    'custom_title',
    'location',
    'starts_at',
    'ends_at',
    'canceled_at',
];
$cancelValuesSql = [
    '?',
    '?',
    '?',
    '"athlete"',
    '?',
    '?',
    '?',
    '?',
    '?',
    '?',
    '?',
    '?',
    'NOW()',
];

if ($hasCancelBillingMonth) {
    $cancelColumns[] = 'billing_month';
    $cancelValuesSql[] = '?';
}
if ($hasCancelPaymentSnapshot) {
    $cancelColumns[] = 'payment_status_snapshot';
    $cancelValuesSql[] = '?';
}
if ($hasCancelReplacementRequired) {
    $cancelColumns[] = 'replacement_required';
    $cancelValuesSql[] = '?';
}
if ($hasCancelReplacementDeadline) {
    $cancelColumns[] = 'replacement_deadline_at';
    $cancelValuesSql[] = '?';
}
if ($hasCancelReplacementEvent) {
    $cancelColumns[] = 'replacement_event_id';
    $cancelValuesSql[] = 'NULL';
}

$primaryAthleteId = (int)($event['athlete_id'] ?? 0);
$secondAthleteId = (int)($event['second_athlete_id'] ?? 0);
$isPrimaryParticipant = ($primaryAthleteId === $athleteId);
$isSecondaryParticipant = ($secondAthleteId === $athleteId);

if (!$isPrimaryParticipant && !$isSecondaryParticipant) {
    echo json_encode(['success' => false, 'error' => 'Termín se nepodařilo zrušit.']);
    exit;
}

$cancelInsert = $pdo->prepare(
    'INSERT INTO coach_calendar_event_cancellations (' . implode(', ', $cancelColumns) . ')
     VALUES (' . implode(', ', $cancelValuesSql) . ')'
);

$selfStmt = $pdo->prepare('SELECT first_name, last_name FROM athletes WHERE id = ? LIMIT 1');
$selfStmt->execute([$athleteId]);
$self = $selfStmt->fetch();
$athleteName = trim((string)($self['first_name'] ?? '') . ' ' . (string)($self['last_name'] ?? ''));
$athleteName = $athleteName !== '' ? $athleteName : 'Sportovec';

$removedFromPairOnly = false;
if ($primaryAthleteId > 0 && $secondAthleteId > 0) {
    if ($isPrimaryParticipant) {
        $updateStmt = $pdo->prepare(
            'UPDATE coach_calendar_events
             SET athlete_id = second_athlete_id,
                 second_athlete_id = NULL,
                 requested_by_athlete_id = CASE WHEN requested_by_athlete_id = ? THEN NULL ELSE requested_by_athlete_id END
             WHERE id = ?
             LIMIT 1'
        );
        $updateStmt->execute([$athleteId, $eventId]);
    } else {
        $updateStmt = $pdo->prepare(
            'UPDATE coach_calendar_events
             SET second_athlete_id = NULL,
                 requested_by_athlete_id = CASE WHEN requested_by_athlete_id = ? THEN NULL ELSE requested_by_athlete_id END
             WHERE id = ?
             LIMIT 1'
        );
        $updateStmt->execute([$athleteId, $eventId]);
    }

    if ($updateStmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Termín se nepodařilo zrušit.']);
        exit;
    }

    try {
        $cancelParams = [
            (int)$event['coach_id'],
            $athleteId,
            null,
            $athleteId,
            'pair_exit',
            (string)($event['approval_status'] ?? 'approved') === 'pending' ? 'pending' : 'approved',
            !empty($event['is_makeup_session']) ? 1 : 0,
            ($event['custom_title'] ?? null) !== '' ? (string)$event['custom_title'] : null,
            ($event['location'] ?? null) !== '' ? (string)$event['location'] : null,
            (string)$event['starts_at'],
            (string)($event['ends_at'] ?? $event['starts_at']),
        ];
        if ($hasCancelBillingMonth) {
            $cancelParams[] = $billingMonthSql;
        }
        if ($hasCancelPaymentSnapshot) {
            $cancelParams[] = $paymentStatus;
        }
        if ($hasCancelReplacementRequired) {
            $cancelParams[] = $requiresReplacement ? 1 : 0;
        }
        if ($hasCancelReplacementDeadline) {
            $cancelParams[] = $replacementDeadlineAtSql;
        }
        $cancelInsert->execute($cancelParams);
    } catch (Throwable $e) {
        error_log('athlete cancellation log insert failed: ' . $e->getMessage());
    }

    $removedFromPairOnly = true;
} else {
    $deleteStmt = $pdo->prepare('DELETE FROM coach_calendar_events WHERE id = ? AND (athlete_id = ? OR second_athlete_id = ?) LIMIT 1');
    $deleteStmt->execute([$eventId, $athleteId, $athleteId]);

    if ($deleteStmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Termín se nepodařilo zrušit.']);
        exit;
    }

    try {
        $cancelParams = [
            (int)$event['coach_id'],
            !empty($event['athlete_id']) ? (int)$event['athlete_id'] : null,
            !empty($event['second_athlete_id']) ? (int)$event['second_athlete_id'] : null,
            $athleteId,
            'single',
            (string)($event['approval_status'] ?? 'approved') === 'pending' ? 'pending' : 'approved',
            !empty($event['is_makeup_session']) ? 1 : 0,
            ($event['custom_title'] ?? null) !== '' ? (string)$event['custom_title'] : null,
            ($event['location'] ?? null) !== '' ? (string)$event['location'] : null,
            (string)$event['starts_at'],
            (string)($event['ends_at'] ?? $event['starts_at']),
        ];
        if ($hasCancelBillingMonth) {
            $cancelParams[] = $billingMonthSql;
        }
        if ($hasCancelPaymentSnapshot) {
            $cancelParams[] = $paymentStatus;
        }
        if ($hasCancelReplacementRequired) {
            $cancelParams[] = $requiresReplacement ? 1 : 0;
        }
        if ($hasCancelReplacementDeadline) {
            $cancelParams[] = $replacementDeadlineAtSql;
        }
        $cancelInsert->execute($cancelParams);
    } catch (Throwable $e) {
        error_log('athlete cancellation log insert failed: ' . $e->getMessage());
    }
}

$coachDisplayName = ($event['coach_name'] ?? '') !== '' ? (string)$event['coach_name'] : (string)($event['coach_username'] ?? 'trenér');
$subject = $removedFromPairOnly
    ? "Sportovec zrušil účast na párovém termínu - {$athleteName}"
    : "Sportovec zrušil termín - {$athleteName}";
$body = $removedFromPairOnly
    ? "Sportovec {$athleteName} zrušil svou účast na párovém termínu " . date('d.m.Y H:i', strtotime((string)$event['starts_at'])) . '. Slot zůstal aktivní pro druhého účastníka.'
    : "Sportovec {$athleteName} zrušil termín " . date('d.m.Y H:i', strtotime((string)$event['starts_at'])) . ".";
if ($wasAlreadyPaid) {
    $body .= ' Termín byl již uhrazen a systém jej automaticky započte jako zápočet do další fakturace.';
} elseif ($paymentStatus === 'pending') {
    $body .= ' Termín byl součástí vystavené výzvy k platbě. Částka výzvy zůstává beze změny a čeká se na náhradní termín.';
}
if ($isLateCancellation) {
    $body .= ' Zrušení proběhlo méně než 12 hodin před začátkem, termín je bez nároku na kompenzaci a nelze jej nahradit.';
}
if ($requiresReplacement && $coachMakeupDeadlineDays !== null) {
    $body .= ' Sportovec má povinnost vybrat náhradní termín do ' . $coachMakeupDeadlineDays . ' dnů.';
}
createCoachSystemMessage((int)$event['coach_id'], $subject, $body, true);

createAthleteNotification(
    $athleteId,
    $removedFromPairOnly ? 'Potvrzení zrušení účasti' : 'Potvrzení zrušení termínu',
    ($removedFromPairOnly
        ? "Tvoje účast na párovém termínu {$event['starts_at']} byla zrušena."
        : "Tvůj termín {$event['starts_at']} byl zrušen.")
    . ($wasAlreadyPaid ? ' Tento termín byl již uhrazen a bude započten do další fakturace jako zápočet.' : '')
    . ($paymentStatus === 'pending' ? ' Tento termín byl součástí otevřené výzvy k platbě, částka výzvy se nesnižuje.' : '')
    . ($isLateCancellation ? ' Zrušení proběhlo méně než 12 hodin před začátkem, termín je bez nároku na kompenzaci a nelze jej nahradit.' : '')
    . (($requiresReplacement && $coachMakeupDeadlineDays !== null)
        ? (' Náhradní termín je potřeba vybrat do ' . $coachMakeupDeadlineDays . ' dnů.')
        : '')
);

$responseMessage = ($removedFromPairOnly
        ? "Účast na párovém termínu byla zrušena. Trenér {$coachDisplayName} byl informován."
        : "Termín byl zrušen. Trenér {$coachDisplayName} byl informován.")
    . ($wasAlreadyPaid ? ' Jednalo se o již uhrazený termín, který bude započten v další fakturaci.' : '')
    . ($paymentStatus === 'pending' ? ' Termín byl součástí výzvy k platbě a částka výzvy se nesnižuje.' : '')
    . ($isLateCancellation ? ' Zrušení proběhlo méně než 12 hodin před začátkem, termín je bez nároku na kompenzaci a nelze jej nahradit.' : '')
    . (($requiresReplacement && $coachMakeupDeadlineDays !== null)
        ? (' Náhradní termín musíte vybrat do ' . $coachMakeupDeadlineDays . ' dnů.')
        : '');

echo json_encode([
    'success' => true,
    'message' => $responseMessage,
    'was_paid' => $wasAlreadyPaid,
    'payment_status' => $paymentStatus,
    'late_cancellation' => $isLateCancellation,
    'replacement_required' => $requiresReplacement,
    'replacement_deadline_days' => $coachMakeupDeadlineDays,
    'replacement_deadline_at' => $replacementDeadlineAtSql,
    'removed_from_pair' => $removedFromPairOnly,
]);
