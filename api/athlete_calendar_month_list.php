<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!athleteIsLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

$athleteStmt = $pdo->prepare('SELECT id, coach_id FROM athletes WHERE id = ? LIMIT 1');
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    echo json_encode(['success' => false, 'error' => 'Sportovec nenalezen']);
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

$eventsStmt = $pdo->prepare(
    "SELECT e.id,
            'active' AS record_type,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.approval_status,
            e.is_makeup_session,
            e.athlete_id,
            e.second_athlete_id
     FROM coach_calendar_events e
     WHERE e.coach_id = ?
       AND e.starts_at >= ?
       AND e.starts_at < ?
       AND (e.approval_status = 'approved' OR e.athlete_id = ? OR e.second_athlete_id = ?)
       AND (e.athlete_id = ? OR e.second_athlete_id = ?)
     ORDER BY e.starts_at ASC, e.id ASC"
);
$eventsStmt->execute([
    (int)$athlete['coach_id'],
    $monthStart->format('Y-m-d H:i:s'),
    $monthEnd->format('Y-m-d H:i:s'),
    $athleteId,
    $athleteId,
    $athleteId,
    $athleteId,
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
            c.second_athlete_id
     FROM coach_calendar_event_cancellations c
     WHERE c.coach_id = ?
       AND c.starts_at >= ?
       AND c.starts_at < ?
       AND (c.athlete_id = ? OR c.second_athlete_id = ?)
     ORDER BY c.starts_at ASC, c.id ASC'
);
$cancellationsStmt->execute([
    (int)$athlete['coach_id'],
    $monthStart->format('Y-m-d H:i:s'),
    $monthEnd->format('Y-m-d H:i:s'),
    $athleteId,
    $athleteId,
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
        'date_label' => $startTs !== false ? date('d.m.Y', $startTs) : '-',
        'time_label' => ($startTs !== false && $endTs !== false) ? (date('H:i', $startTs) . ' - ' . date('H:i', $endTs)) : '-',
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
