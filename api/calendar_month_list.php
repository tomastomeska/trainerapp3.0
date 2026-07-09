<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

$monthRaw = trim((string)($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $monthRaw)) {
    $monthRaw = date('Y-m');
}

$monthStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $monthRaw . '-01 00:00:00');
if (!$monthStart) {
    $monthStart = new DateTimeImmutable(date('Y-m-01 00:00:00'));
}
$monthEnd = $monthStart->modify('+1 month');
$now = new DateTimeImmutable('now');
$coachId = (int)getCurrentCoachId();
$pdo = getDB();

$eventsStmt = $pdo->prepare(
    'SELECT e.id,
            "active" AS record_type,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.approval_status,
            e.is_makeup_session,
            e.athlete_id,
            e.second_athlete_id,
            e.requested_by_athlete_id,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE e.coach_id = ?
       AND e.starts_at >= ?
       AND e.starts_at < ?'
);
$eventsStmt->execute([
    $coachId,
    $monthStart->format('Y-m-d H:i:s'),
    $monthEnd->format('Y-m-d H:i:s'),
]);
$rows = $eventsStmt->fetchAll();

$cancellationsStmt = $pdo->prepare(
    'SELECT c.id,
            "cancelled" AS record_type,
            c.custom_title,
            c.location,
            c.starts_at,
            c.ends_at,
            c.approval_status,
            c.is_makeup_session,
            c.athlete_id,
            c.second_athlete_id,
            NULL AS requested_by_athlete_id,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
     FROM coach_calendar_event_cancellations c
     LEFT JOIN athletes a ON a.id = c.athlete_id
     LEFT JOIN athletes a2 ON a2.id = c.second_athlete_id
     WHERE c.coach_id = ?
       AND c.starts_at >= ?
       AND c.starts_at < ?'
);
$cancellationsStmt->execute([
    $coachId,
    $monthStart->format('Y-m-d H:i:s'),
    $monthEnd->format('Y-m-d H:i:s'),
]);
$rows = array_merge($rows, $cancellationsStmt->fetchAll());

usort($rows, static function (array $a, array $b): int {
    $aTs = strtotime((string)($a['starts_at'] ?? '')) ?: 0;
    $bTs = strtotime((string)($b['starts_at'] ?? '')) ?: 0;
    if ($aTs === $bTs) {
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    }
    return $aTs <=> $bTs;
});

$items = [];
foreach ($rows as $row) {
    $startsAt = (string)($row['starts_at'] ?? '');
    $endsAt = (string)($row['ends_at'] ?? '');
    $startTs = strtotime($startsAt);
    $endTs = strtotime($endsAt);

    $firstName = trim((string)($row['first_name'] ?? ''));
    $lastName = trim((string)($row['last_name'] ?? ''));
    $secondFirstName = trim((string)($row['second_first_name'] ?? ''));
    $secondLastName = trim((string)($row['second_last_name'] ?? ''));

    $athleteParts = [];
    if ($lastName !== '' || $firstName !== '') {
        $athleteParts[] = trim($lastName . ' ' . $firstName);
    }
    if ($secondLastName !== '' || $secondFirstName !== '') {
        $athleteParts[] = trim($secondLastName . ' ' . $secondFirstName);
    }
    $athleteLabel = implode(' + ', array_filter($athleteParts));

    $customTitle = trim((string)($row['custom_title'] ?? ''));
    if ($customTitle !== '') {
        $typeLabel = $customTitle;
    } elseif ((int)($row['athlete_id'] ?? 0) > 0 && (int)($row['second_athlete_id'] ?? 0) > 0) {
        $typeLabel = 'Párový trénink';
    } elseif ((int)($row['athlete_id'] ?? 0) > 0) {
        $typeLabel = 'Trénink';
    } else {
        $typeLabel = 'Rezervace';
    }

    $statusLabel = 'Schválený';
    $statusClass = 'success';

    if (($row['record_type'] ?? '') === 'cancelled') {
        $statusLabel = 'Zrušený';
        $statusClass = 'danger';
    } elseif ((string)($row['approval_status'] ?? 'approved') === 'pending') {
        $statusLabel = 'Zatím neschválený';
        $statusClass = 'warning';
    } elseif (!empty($row['is_makeup_session'])) {
        $statusLabel = 'Náhradní';
        $statusClass = 'info';
    } elseif ($endTs !== false && $endTs < $now->getTimestamp()) {
        $statusLabel = 'Proběhlý';
        $statusClass = 'secondary';
    }

    $items[] = [
        'id' => (int)($row['id'] ?? 0),
        'record_type' => (string)($row['record_type'] ?? 'active'),
        'can_approve' => (($row['record_type'] ?? '') === 'active')
            && ((string)($row['approval_status'] ?? 'approved') === 'pending')
            && ((int)($row['requested_by_athlete_id'] ?? 0) > 0),
        'date_label' => $startTs !== false ? date('d.m.Y', $startTs) : '-',
        'time_label' => ($startTs !== false && $endTs !== false) ? (date('H:i', $startTs) . ' - ' . date('H:i', $endTs)) : '-',
        'athlete_label' => $athleteLabel !== '' ? $athleteLabel : 'Bez sportovce',
        'type_label' => $typeLabel,
        'location_label' => trim((string)($row['location'] ?? '')) !== '' ? trim((string)$row['location']) : 'Bez místa',
        'status_label' => $statusLabel,
        'status_class' => $statusClass,
        'starts_at' => $startsAt,
    ];
}

echo json_encode([
    'success' => true,
    'month' => $monthStart->format('Y-m'),
    'items' => $items,
]);
