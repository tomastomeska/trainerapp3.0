<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/payment_billing.php';
require_once __DIR__ . '/../../includes/calendar_replacements.php';

$coach = mobileRequireCoach();
$pdo = getDB();
$input = mobileInput();
$action = (string)($_REQUEST['action'] ?? $input['action'] ?? 'list');

try {
    calendarMigrateReplacementRevocation($pdo);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => 'Přehled náhrad se nepodařilo připravit.'], 500);
}

if ($action === 'revoke') {
    $confirm = $input['confirm'] ?? $_REQUEST['confirm'] ?? '';
    if (!in_array($confirm, [1, '1', true], true)) {
        mobileJson(['success' => false, 'error' => 'Potvrďte zrušení nároku na náhradu.'], 422);
    }
    try {
        calendarRevokeReplacement(
            $pdo,
            (int)$coach['id'],
            (int)($input['cancellation_id'] ?? $_REQUEST['cancellation_id'] ?? 0),
            (string)($input['reason'] ?? $_REQUEST['reason'] ?? '')
        );
        mobileJson([
            'success' => true,
            'message' => 'Nárok na náhradu byl zrušen. Záznam zůstává v historii; původní výzva i platby jsou beze změny.',
        ]);
    } catch (RuntimeException $e) {
        mobileJson(['success' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        mobileJson(['success' => false, 'error' => 'Zrušení nároku se nepodařilo uložit. Zkuste obnovit přehled.'], 500);
    }
}

$statusLabels = [
    'attention' => 'K řešení',
    'all' => 'Všechny záznamy',
    'open' => 'Čeká na výběr slotu',
    'soon' => 'Končí do 48 hodin',
    'pending' => 'Čeká na schválení',
    'approved' => 'Náhrada schválena',
    'expired' => 'Lhůta propadla',
    'none' => 'Bez náhrady',
    'revoked' => 'Nárok zrušen trenérem',
    'missing' => 'Chybějící údaje nebo slot',
];
$status = (string)($input['status'] ?? $_REQUEST['status'] ?? 'attention');
if (!isset($statusLabels[$status])) {
    $status = 'attention';
}
$athleteId = max(0, (int)($input['athlete_id'] ?? $_REQUEST['athlete_id'] ?? 0));
$dateFilters = [];
foreach (['from', 'to'] as $dateKey) {
    $value = (string)($input[$dateKey] ?? $_REQUEST[$dateKey] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateFilters[$dateKey] = $date && $date->format('Y-m-d') === $value ? $value : '';
}

try {
    foreach (['replacement_required', 'replacement_deadline_at', 'replacement_event_id', 'payment_status_snapshot', 'billing_month'] as $column) {
        if (!paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', $column)) {
            throw new RuntimeException('missing_schema');
        }
    }
    $issueSelect = paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_issue_number')
        ? 'c.billing_issue_number'
        : '1 AS billing_issue_number';
    $sql = "SELECT c.*, {$issueSelect}, a.first_name, a.last_name,
                   actor.first_name AS actor_first_name, actor.last_name AS actor_last_name,
                   replacement.id AS replacement_id,
                   replacement.starts_at AS replacement_starts_at,
                   replacement.ends_at AS replacement_ends_at,
                   replacement.location AS replacement_location,
                   replacement.approval_status AS replacement_approval_status
            FROM coach_calendar_event_cancellations c
            LEFT JOIN athletes a ON a.id = c.athlete_id AND a.coach_id = c.coach_id
            LEFT JOIN athletes actor ON actor.id = c.canceled_by_athlete_id AND actor.coach_id = c.coach_id
            LEFT JOIN coach_calendar_events replacement
                   ON replacement.id = c.replacement_event_id AND replacement.coach_id = c.coach_id
                  AND (replacement.athlete_id = c.athlete_id OR replacement.second_athlete_id = c.athlete_id)
            WHERE c.coach_id = ?";
    $params = [(int)$coach['id']];
    if ($athleteId > 0) {
        $sql .= ' AND c.athlete_id = ?';
        $params[] = $athleteId;
    }
    if ($dateFilters['from'] !== '') {
        $sql .= ' AND c.starts_at >= ?';
        $params[] = $dateFilters['from'] . ' 00:00:00';
    }
    if ($dateFilters['to'] !== '') {
        $sql .= ' AND c.starts_at < ?';
        $params[] = (new DateTimeImmutable($dateFilters['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => 'Přehled náhrad se nepodařilo načíst.'], 500);
}

$now = new DateTimeImmutable();
$counts = array_fill_keys(array_keys($statusLabels), 0);
foreach ($rows as &$row) {
    $row['state'] = calendarReplacementState($row, $now);
    $counts[$row['state']['key']]++;
    $counts['all']++;
    if (in_array($row['state']['key'], ['open', 'soon', 'pending', 'missing'], true)) {
        $counts['attention']++;
    }
}
unset($row);

$filtered = array_values(array_filter($rows, static function (array $row) use ($status): bool {
    if ($status === 'all') {
        return true;
    }
    if ($status === 'attention') {
        return in_array($row['state']['key'], ['open', 'soon', 'pending', 'missing'], true);
    }
    if ($status === 'open') {
        return in_array($row['state']['key'], ['open', 'soon'], true);
    }
    return $row['state']['key'] === $status;
}));
usort($filtered, static function (array $left, array $right): int {
    $priority = $left['state']['priority'] <=> $right['state']['priority'];
    if ($priority !== 0) {
        return $priority;
    }
    return strcmp((string)$right['starts_at'], (string)$left['starts_at']) ?: ((int)$right['id'] <=> (int)$left['id']);
});

$items = [];
foreach ($filtered as $row) {
    $paymentStatus = (string)($row['payment_status_snapshot'] ?? 'none');
    $paymentLabel = $paymentStatus === 'paid'
        ? 'Při zrušení uhrazeno'
        : ($paymentStatus === 'pending' ? 'Při zrušení neuhrazeno' : 'Bez vystavené výzvy při zrušení');
    $athleteName = trim((string)($row['last_name'] ?? '') . ' ' . (string)($row['first_name'] ?? ''));
    $stateKey = (string)$row['state']['key'];
    $slotLabel = '';
    if (!empty($row['replacement_id'])) {
        $slotLabel = (string)$row['replacement_starts_at'];
    } elseif ($stateKey === 'missing') {
        $slotLabel = 'Nutná kontrola evidence';
    } elseif (in_array($stateKey, ['open', 'soon'], true)) {
        $slotLabel = 'Dosud nevybrán';
    }
    $items[] = [
        'id' => (int)$row['id'],
        'athlete_id' => (int)($row['athlete_id'] ?? 0),
        'athlete_name' => $athleteName !== '' ? $athleteName : 'Sportovec již není dostupný',
        'starts_at' => (string)($row['starts_at'] ?? ''),
        'location' => (string)($row['location'] ?? ''),
        'canceled_by' => (string)($row['canceled_by'] ?? '') === 'coach' ? 'Trenér' : 'Sportovec',
        'canceled_at' => (string)($row['canceled_at'] ?? ''),
        'state_key' => $stateKey,
        'state_label' => (string)$row['state']['label'],
        'deadline_at' => (string)($row['replacement_deadline_at'] ?? ''),
        'remaining' => in_array($stateKey, ['open', 'soon'], true) && !empty($row['replacement_deadline_at'])
            ? calendarReplacementRemaining((string)$row['replacement_deadline_at'], $now)
            : '',
        'replacement_starts_at' => (string)($row['replacement_starts_at'] ?? ''),
        'replacement_location' => (string)($row['replacement_location'] ?? ''),
        'slot_label' => $slotLabel,
        'payment_status' => $paymentStatus,
        'payment_label' => $paymentLabel,
        'billing_month' => (string)($row['billing_month'] ?? ''),
        'issue_number' => max(1, (int)($row['billing_issue_number'] ?? 1)),
        'revocation_reason' => (string)($row['replacement_revocation_reason'] ?? ''),
        'can_revoke' => !empty($row['replacement_required']) && empty($row['replacement_revoked_at']) && empty($row['replacement_id']),
    ];
}

mobileJson([
    'success' => true,
    'status' => $status,
    'counts' => $counts,
    'items' => $items,
]);
