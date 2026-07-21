<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo 'Nepřihlášen';
    exit;
}

$coachId = (int)getCurrentCoachId();
$eventId = (int)($_GET['event_id'] ?? 0);
if ($coachId <= 0 || $eventId <= 0) {
    http_response_code(400);
    echo 'Chybí ID události';
    exit;
}

$pdo = getDB();
$eventStmt = $pdo->prepare(
    'SELECT e.id,
            e.coach_id,
            e.athlete_id,
            e.second_athlete_id,
            e.approval_status,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.updated_at,
            e.created_at,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE e.id = ?
       AND e.coach_id = ?
     LIMIT 1'
);
$eventStmt->execute([$eventId, $coachId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    echo 'Událost nenalezena';
    exit;
}

$uid = buildAppleCaldavEventUid($coachId, $event);
$ics = buildAppleCaldavEventIcs($coachId, $event, $uid);
$filename = 'trainerapp-coach-event-' . $eventId . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

echo $ics;
