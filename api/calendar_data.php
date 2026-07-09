<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen']);
    exit;
}

$coachId = getCurrentCoachId();
$pdo = getDB();

$weekStartRaw = trim((string)($_GET['week_start'] ?? ''));
$weekBase = DateTimeImmutable::createFromFormat('Y-m-d', $weekStartRaw) ?: new DateTimeImmutable('today');
$weekStart = $weekBase->modify('-' . ((int)$weekBase->format('N') - 1) . ' days')->setTime(0, 0, 0);
$weekEnd = $weekStart->modify('+7 days');

$eventsStmt = $pdo->prepare(
    'SELECT e.id,
            e.athlete_id,
            e.second_athlete_id,
            e.requested_by_athlete_id,
            e.series_id,
            e.color_key,
            e.approval_status,
            e.coach_modified_at,
            e.is_makeup_session,
            e.billing_month,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
        p.status AS payment_status,
        p.paid_at AS payment_paid_at,
        p.billed_amount AS payment_billed_amount,
                 a.first_name,
                 a.last_name,
                 a2.first_name AS second_first_name,
                 a2.last_name AS second_last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
             LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     LEFT JOIN athlete_monthly_payments p
        ON p.coach_id = e.coach_id
       AND p.athlete_id = e.athlete_id
       AND p.billing_month = COALESCE(e.billing_month, DATE_FORMAT(e.starts_at, "%Y-%m-01"))
     WHERE e.coach_id = ?
       AND e.starts_at < ?
       AND e.ends_at > ?
     ORDER BY e.starts_at ASC, e.id ASC'
);
$eventsStmt->execute([
    $coachId,
    $weekEnd->format('Y-m-d H:i:s'),
    $weekStart->format('Y-m-d H:i:s'),
]);
$events = $eventsStmt->fetchAll();

$locksStmt = $pdo->prepare(
    'SELECT id, note, starts_at, ends_at
     FROM coach_calendar_locks
     WHERE coach_id = ?
       AND starts_at < ?
       AND ends_at > ?
     ORDER BY starts_at ASC, id ASC'
);
$locksStmt->execute([
    $coachId,
    $weekEnd->format('Y-m-d H:i:s'),
    $weekStart->format('Y-m-d H:i:s'),
]);
$locks = $locksStmt->fetchAll();

echo json_encode([
    'success' => true,
    'week_start' => $weekStart->format('Y-m-d'),
    'week_end' => $weekEnd->modify('-1 day')->format('Y-m-d'),
    'events' => $events,
    'locks' => $locks,
]);
