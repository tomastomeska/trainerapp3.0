<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
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
    echo json_encode(['success' => false, 'error' => 'Neplatné ID události']);
    exit;
}

$coachId = (int)getCurrentCoachId();
$pdo = getDB();

$eventStmt = $pdo->prepare(
    'SELECT e.id,
            e.athlete_id,
            e.requested_by_athlete_id,
            e.approval_status,
            e.starts_at,
            e.location,
            a.email AS athlete_email,
            a.first_name,
            a.last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     WHERE e.id = ? AND e.coach_id = ?
     LIMIT 1'
);
$eventStmt->execute([$eventId, $coachId]);
$event = $eventStmt->fetch();

if (!$event) {
    echo json_encode(['success' => false, 'error' => 'Událost nenalezena']);
    exit;
}

$isPendingRequest = ((string)($event['approval_status'] ?? 'approved') === 'pending')
    && ((int)($event['requested_by_athlete_id'] ?? 0) > 0);

if (!$isPendingRequest) {
    echo json_encode(['success' => false, 'error' => 'Tuto událost nelze schválit z měsíčního seznamu.']);
    exit;
}

$updateStmt = $pdo->prepare(
    'UPDATE coach_calendar_events
     SET approval_status = "approved"
     WHERE id = ? AND coach_id = ?'
);
$updateStmt->execute([$eventId, $coachId]);

$athleteId = (int)($event['athlete_id'] ?? 0);
if ($athleteId > 0) {
    $athleteName = trim((string)($event['first_name'] ?? '') . ' ' . (string)($event['last_name'] ?? ''));
    $timeStamp = strtotime((string)($event['starts_at'] ?? ''));
    $timeLabel = $timeStamp !== false ? date('d.m.Y H:i', $timeStamp) : 'zvolený termín';

    $subject = 'Trénink byl schválen';
    $body = 'Trenér schválil váš termín ' . $timeLabel . '.';
    $location = trim((string)($event['location'] ?? ''));
    if ($location !== '') {
        $body .= ' Místo: ' . $location . '.';
    }

    createAthleteNotification($athleteId, $subject, $body);

    $email = trim((string)($event['athlete_email'] ?? ''));
    if ($email !== '') {
        sendAthleteCalendarNotificationEmail($email, $athleteName !== '' ? $athleteName : 'sportovec', $subject, $body);
    }
}

echo json_encode(['success' => true, 'id' => $eventId, 'approval_status' => 'approved']);
