<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!athleteIsLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

$athleteId = (int)getCurrentAthleteId();
$statusFilter = trim((string)($_GET['status'] ?? 'approved'));
if (!in_array($statusFilter, ['all', 'approved', 'planned', 'completed', 'change_pending', 'cancelled'], true)) {
    $statusFilter = 'approved';
}
$monthRaw = trim((string)($_GET['month'] ?? date('Y-m')));
$monthStart = DateTimeImmutable::createFromFormat('!Y-m', $monthRaw);
if (!$monthStart || $monthStart->format('Y-m') !== $monthRaw) {
    $monthStart = new DateTimeImmutable('first day of this month');
}
$monthEnd = $monthStart->modify('+1 month');
$pdo = getDB();
$athleteCoachStmt = $pdo->prepare('SELECT coach_id FROM athletes WHERE id = ? LIMIT 1');
$athleteCoachStmt->execute([$athleteId]);
$athleteCoachId = (int)$athleteCoachStmt->fetchColumn();
$stmt = $pdo->prepare(
    'SELECT e.id,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.athlete_id,
            e.second_athlete_id,
            e.approval_status,
                        e.requested_by_athlete_id,
                        e.series_id,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
                        a2.last_name AS second_last_name,
                        source.starts_at AS source_starts_at,
                        source.ends_at AS source_ends_at,
                        source.location AS source_location,
                        pending_change.starts_at AS requested_change_starts_at
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
         LEFT JOIN coach_calendar_events source
             ON source.coach_id = e.coach_id
            AND source.id = CAST(SUBSTRING(e.series_id, 12) AS UNSIGNED)
         LEFT JOIN coach_calendar_events pending_change
             ON pending_change.coach_id = e.coach_id
            AND pending_change.approval_status = "pending"
            AND pending_change.requested_by_athlete_id = ?
            AND pending_change.series_id = CONCAT("reschedule:", e.id)
         WHERE e.starts_at >= ?
             AND e.starts_at < ?
             AND (
                        (e.approval_status = "approved"
                         AND (e.athlete_id = ? OR e.second_athlete_id = ?)
                         )
                        OR (e.approval_status = "pending"
                            AND e.requested_by_athlete_id = ?
                            AND (e.athlete_id = ? OR e.second_athlete_id = ?))
             )
    ORDER BY e.starts_at ASC, e.id ASC
    LIMIT 500'
);
$stmt->execute([$athleteId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s'), $athleteId, $athleteId, $athleteId, $athleteId, $athleteId]);
$rows = $stmt->fetchAll();

$items = [];
foreach ($rows as $row) {
    $startTs = strtotime((string)$row['starts_at']);
    $endTs = strtotime((string)$row['ends_at']);
    $title = trim((string)($row['custom_title'] ?? ''));
    if ($title === '') {
        $title = ((int)($row['athlete_id'] ?? 0) > 0 && (int)($row['second_athlete_id'] ?? 0) > 0) ? 'Párový trénink' : 'Trénink';
    }

    $seriesId = trim((string)($row['series_id'] ?? ''));
    $isPendingChange = (string)($row['approval_status'] ?? 'approved') === 'pending'
        && preg_match('/^reschedule:\d+$/', $seriesId);
    $isPending = (string)($row['approval_status'] ?? 'approved') === 'pending';
    if ($statusFilter === 'approved' && $isPending) {
        continue;
    }
    if ($statusFilter === 'planned' && ($isPending || $startTs < time())) {
        continue;
    }
    if ($statusFilter === 'completed' && ($isPending || $startTs >= time())) {
        continue;
    }
    if ($statusFilter === 'change_pending' && !$isPendingChange) {
        continue;
    }
    $requestedChangeStartTs = strtotime((string)($row['requested_change_starts_at'] ?? ''));
    $sourceStartTs = strtotime((string)($row['source_starts_at'] ?? ''));
    $sourceEndTs = strtotime((string)($row['source_ends_at'] ?? ''));

    $items[] = [
        'id' => (int)$row['id'],
        'title' => $title,
        'location' => trim((string)($row['location'] ?? '')),
        'date_label' => $startTs !== false ? date('d.m.Y', $startTs) : '-',
        'time_label' => ($startTs !== false && $endTs !== false) ? date('H:i', $startTs) . ' - ' . date('H:i', $endTs) : '-',
        'starts_at' => (string)$row['starts_at'],
        'status' => $isPendingChange ? 'change_pending' : 'approved',
        'can_cancel' => $startTs !== false && $startTs > time(),
        'can_request_change' => !$isPendingChange && $startTs !== false && $startTs > time(),
        'source' => $isPendingChange && $sourceStartTs !== false ? [
            'date_label' => date('d.m.Y', $sourceStartTs),
            'time_label' => $sourceEndTs !== false ? date('H:i', $sourceStartTs) . ' - ' . date('H:i', $sourceEndTs) : '-',
            'location' => trim((string)($row['source_location'] ?? '')),
        ] : null,
        'requested_change' => !$isPendingChange && $requestedChangeStartTs !== false ? [
            'date_label' => date('d.m.Y', $requestedChangeStartTs),
            'time_label' => date('H:i', $requestedChangeStartTs),
        ] : null,
    ];
}

if ($statusFilter === 'all' || $statusFilter === 'cancelled') {
    try {
        $cancelStmt = $pdo->prepare(
            'SELECT c.id, c.starts_at, c.ends_at, c.custom_title, c.location
             FROM coach_calendar_event_cancellations c
             WHERE c.coach_id = ? AND (c.athlete_id = ? OR c.second_athlete_id = ?) AND c.starts_at >= ? AND c.starts_at < ?
             ORDER BY c.starts_at ASC, c.id ASC'
        );
        $cancelStmt->execute([$athleteCoachId, $athleteId, $athleteId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')]);
        foreach ($cancelStmt->fetchAll() as $row) {
            $startTs = strtotime((string)$row['starts_at']);
            $endTs = strtotime((string)$row['ends_at']);
            $items[] = [
                'id' => 0,
                'title' => trim((string)($row['custom_title'] ?? '')) ?: 'Trénink',
                'location' => trim((string)($row['location'] ?? '')),
                'date_label' => $startTs !== false ? date('d.m.Y', $startTs) : '-',
                'time_label' => ($startTs !== false && $endTs !== false) ? date('H:i', $startTs) . ' - ' . date('H:i', $endTs) : '-',
                'starts_at' => (string)$row['starts_at'],
                'status' => 'cancelled',
                'can_cancel' => false,
                'can_request_change' => false,
                'source' => null,
                'requested_change' => null,
            ];
        }
    } catch (Throwable $e) {
        // Older installations may not yet have the cancellation history table.
    }
}

usort($items, static function (array $left, array $right): int {
    return strcmp((string)$left['starts_at'], (string)$right['starts_at']);
});

echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
