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
                        source.location AS source_location
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
         LEFT JOIN coach_calendar_events source
             ON source.coach_id = e.coach_id
            AND source.id = CAST(SUBSTRING(e.series_id, 12) AS UNSIGNED)
         WHERE e.starts_at >= CURDATE()
             AND (
                        (e.approval_status = "approved"
                         AND (e.athlete_id = ? OR e.second_athlete_id = ?)
                         AND NOT EXISTS (
                                 SELECT 1
                                 FROM coach_calendar_events pending_change
                                 WHERE pending_change.coach_id = e.coach_id
                                     AND pending_change.approval_status = "pending"
                                     AND pending_change.requested_by_athlete_id = ?
                                     AND pending_change.series_id = CONCAT("reschedule:", e.id)
                         ))
                        OR (e.approval_status = "pending"
                            AND e.requested_by_athlete_id = ?
                            AND (e.athlete_id = ? OR e.second_athlete_id = ?))
             )
     ORDER BY e.starts_at ASC, e.id ASC
     LIMIT 500'
);
$stmt->execute([$athleteId, $athleteId, $athleteId, $athleteId, $athleteId, $athleteId]);
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
    ];
}

echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
