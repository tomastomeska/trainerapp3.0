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
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['actionable', 'pending', 'change', 'approved', 'planned', 'completed', 'cancelled', 'all'], true)) {
    $statusFilter = 'all';
}
$monthRaw = trim((string)($_GET['month'] ?? date('Y-m')));
$monthStart = DateTimeImmutable::createFromFormat('!Y-m', $monthRaw);
if (!$monthStart || $monthStart->format('Y-m') !== $monthRaw) {
    $monthStart = new DateTimeImmutable('first day of this month');
}
$monthEnd = $monthStart->modify('+1 month');
$pdo = getDB();

$actionCountSql = 'SELECT COUNT(*)
                                     FROM coach_calendar_events
                                     WHERE coach_id = ?
                                         AND starts_at >= ?
                                         AND starts_at < ?
                                         AND approval_status = "pending"';
$actionCountParams = [$coachId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')];
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
          AND e.starts_at >= ?
          AND e.starts_at < ?';
$params = [$coachId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')];

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
} elseif ($statusFilter === 'planned') {
    $sql .= ' AND e.approval_status = "approved" AND e.starts_at >= NOW()';
} elseif ($statusFilter === 'completed') {
    $sql .= ' AND e.approval_status = "approved" AND e.starts_at < NOW()';
} elseif ($statusFilter === 'cancelled') {
    $sql .= ' AND 1 = 0';
}

$sql .= ' ORDER BY e.starts_at ASC, e.id ASC LIMIT 500';
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

if (in_array($statusFilter, ['all', 'cancelled'], true)) {
    try {
        $cancelStmt = $pdo->prepare(
            'SELECT c.id, c.athlete_id, c.second_athlete_id, c.starts_at, c.ends_at, c.custom_title, c.location,
                    a.first_name, a.last_name, a2.first_name AS second_first_name, a2.last_name AS second_last_name
             FROM coach_calendar_event_cancellations c
             LEFT JOIN athletes a ON a.id = c.athlete_id
             LEFT JOIN athletes a2 ON a2.id = c.second_athlete_id
             WHERE c.coach_id = ? AND c.starts_at >= ? AND c.starts_at < ?
             ORDER BY c.starts_at ASC, c.id ASC'
        );
        $cancelStmt->execute([$coachId, $monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')]);
        foreach ($cancelStmt->fetchAll() as $row) {
            if ($athleteId > 0 && (int)($row['athlete_id'] ?? 0) !== $athleteId && (int)($row['second_athlete_id'] ?? 0) !== $athleteId) {
                continue;
            }
            $startTs = strtotime((string)$row['starts_at']);
            $endTs = strtotime((string)$row['ends_at']);
            $names = array_values(array_filter([
                trim((string)($row['last_name'] ?? '') . ' ' . (string)($row['first_name'] ?? '')),
                trim((string)($row['second_last_name'] ?? '') . ' ' . (string)($row['second_first_name'] ?? '')),
            ]));
            $items[] = [
                'id' => 0,
                'status' => 'cancelled',
                'can_approve' => false,
                'athlete_label' => implode(' + ', $names),
                'title' => trim((string)($row['custom_title'] ?? '')) ?: (count($names) > 1 ? 'Párový trénink' : 'Trénink'),
                'location' => trim((string)($row['location'] ?? '')),
                'date_label' => $startTs !== false ? date('d.m.Y', $startTs) : '-',
                'time_label' => ($startTs !== false && $endTs !== false) ? date('H:i', $startTs) . ' - ' . date('H:i', $endTs) : '-',
                'starts_at' => (string)$row['starts_at'],
                'source' => null,
            ];
        }
    } catch (Throwable $e) {
        // Older installations may not yet have the cancellation history table.
    }
}

usort($items, static function (array $left, array $right): int {
    return strcmp((string)$left['starts_at'], (string)$right['starts_at']);
});

echo json_encode([
    'success' => true,
    'items' => $items,
    'action_count' => $actionCount,
], JSON_UNESCAPED_UNICODE);
