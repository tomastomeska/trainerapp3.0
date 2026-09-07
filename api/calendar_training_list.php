<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

$coachId = (int)getCurrentCoachId();
$athleteId = (int)($_GET['athlete_id'] ?? 0);
$statusFilter = trim((string)($_GET['status'] ?? 'actionable'));
if (!in_array($statusFilter, ['actionable', 'pending', 'change', 'approved', 'all'], true)) {
    $statusFilter = 'actionable';
}
$pdo = getDB();

$actionCountSql = 'SELECT COUNT(*)
                                     FROM coach_calendar_events
                                     WHERE coach_id = ?
                                         AND starts_at >= CURDATE()
                                         AND approval_status = "pending"';
$actionCountParams = [$coachId];
$actionCountStmt = $pdo->prepare($actionCountSql);
$actionCountStmt->execute($actionCountParams);
$actionCount = (int)$actionCountStmt->fetchColumn();

$sql = 'SELECT e.id,
               e.custom_title,
               e.location,
               e.starts_at,
               e.ends_at,
               e.approval_status,
               e.athlete_id,
               e.second_athlete_id,
               e.requested_by_athlete_id,
               e.series_id,
               a.first_name,
               a.last_name,
               a2.first_name AS second_first_name,
               a2.last_name AS second_last_name,
               source.id AS source_id,
               source.starts_at AS source_starts_at,
               source.ends_at AS source_ends_at,
               source.location AS source_location
        FROM coach_calendar_events e
        LEFT JOIN athletes a ON a.id = e.athlete_id
        LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
        LEFT JOIN coach_calendar_events source
          ON source.coach_id = e.coach_id
         AND source.id = CAST(SUBSTRING(e.series_id, 12) AS UNSIGNED)
        WHERE e.coach_id = ?
          AND e.starts_at >= CURDATE()';
$params = [$coachId];

if ($athleteId > 0) {
    $sql .= ' AND (e.athlete_id = ? OR e.second_athlete_id = ?)';
    $params[] = $athleteId;
    $params[] = $athleteId;
}

if ($statusFilter === 'actionable') {
    $sql .= ' AND e.approval_status = "pending"';
} elseif ($statusFilter === 'pending') {
    $sql .= ' AND e.approval_status = "pending" AND (e.series_id IS NULL OR e.series_id NOT LIKE "reschedule:%")';
} elseif ($statusFilter === 'change') {
    $sql .= ' AND e.approval_status = "pending" AND e.series_id LIKE "reschedule:%"';
} elseif ($statusFilter === 'approved') {
    $sql .= ' AND e.approval_status = "approved"';
}

$sql .= ' ORDER BY CASE WHEN e.approval_status = "pending" THEN 0 ELSE 1 END, e.starts_at ASC, e.id ASC LIMIT 500';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$items = [];
foreach ($rows as $row) {
    $startTs = strtotime((string)$row['starts_at']);
    $endTs = strtotime((string)$row['ends_at']);
    $names = [];
    foreach ([[$row['last_name'] ?? '', $row['first_name'] ?? ''], [$row['second_last_name'] ?? '', $row['second_first_name'] ?? '']] as $nameParts) {
        $name = trim(trim((string)$nameParts[0]) . ' ' . trim((string)$nameParts[1]));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    $seriesId = trim((string)($row['series_id'] ?? ''));
    $isReschedule = (string)($row['approval_status'] ?? 'approved') === 'pending' && preg_match('/^reschedule:\d+$/', $seriesId);
    $status = $isReschedule ? 'change' : ((string)($row['approval_status'] ?? 'approved') === 'pending' ? 'pending' : 'approved');

    $items[] = [
        'id' => (int)$row['id'],
        'status' => $status,
        'can_approve' => $status !== 'approved',
        'athlete_label' => implode(' + ', $names),
        'title' => trim((string)($row['custom_title'] ?? '')) ?: ((count($names) > 1) ? 'Párový trénink' : 'Trénink'),
        'location' => trim((string)($row['location'] ?? '')),
        'date_label' => $startTs !== false ? date('d.m.Y', $startTs) : '-',
        'time_label' => ($startTs !== false && $endTs !== false) ? date('H:i', $startTs) . ' - ' . date('H:i', $endTs) : '-',
        'starts_at' => (string)$row['starts_at'],
        'source' => $isReschedule && (int)($row['source_id'] ?? 0) > 0 ? [
            'date_label' => strtotime((string)$row['source_starts_at']) !== false ? date('d.m.Y', strtotime((string)$row['source_starts_at'])) : '-',
            'time_label' => (strtotime((string)$row['source_starts_at']) !== false && strtotime((string)$row['source_ends_at']) !== false) ? date('H:i', strtotime((string)$row['source_starts_at'])) . ' - ' . date('H:i', strtotime((string)$row['source_ends_at'])) : '-',
            'location' => trim((string)($row['source_location'] ?? '')),
        ] : null,
    ];
}

echo json_encode([
    'success' => true,
    'items' => $items,
    'action_count' => $actionCount,
], JSON_UNESCAPED_UNICODE);
