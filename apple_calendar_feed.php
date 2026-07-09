<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

function appleCalendarEscapeText(string $value): string
{
    return str_replace(
        ["\\", ";", ",", "\r\n", "\r", "\n"],
        ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
        $value
    );
}

function appleCalendarFormatUtc(?string $dateTimeSql): string
{
    if (!$dateTimeSql) {
        return gmdate('Ymd\THis\Z');
    }

    $date = new DateTime($dateTimeSql, new DateTimeZone(date_default_timezone_get()));
    $date->setTimezone(new DateTimeZone('UTC'));

    return $date->format('Ymd\THis\Z');
}

function appleCalendarParseToken(): string
{
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    $pathInfo = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
    if ($pathInfo !== '') {
        $pathParts = explode('/', $pathInfo);
        $candidate = trim((string)($pathParts[0] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/apple_calendar_feed.php');
    $path = (string)parse_url($requestUri, PHP_URL_PATH);
    $needle = $scriptName . '/';
    $needlePos = strpos($path, $needle);
    if ($needlePos !== false) {
        $remainder = substr($path, $needlePos + strlen($needle));
        $pathParts = explode('/', trim((string)$remainder, '/'));
        $candidate = trim((string)($pathParts[0] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

$token = appleCalendarParseToken();

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit;
}

$pdo = getDB();
$coachStmt = $pdo->prepare(
    'SELECT id, name
     FROM coaches
     WHERE apple_calendar_sync_enabled = 1
       AND apple_calendar_token = ?
     LIMIT 1'
);
$coachStmt->execute([$token]);
$coach = $coachStmt->fetch();

if (!$coach) {
    http_response_code(404);
    exit;
}

$eventsStmt = $pdo->prepare(
    'SELECT e.id,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.approval_status,
            e.updated_at,
            e.created_at,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE e.coach_id = ?
     ORDER BY e.starts_at ASC, e.id ASC'
);
$eventsStmt->execute([(int)$coach['id']]);
$events = $eventsStmt->fetchAll();

$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$calendarName = trim((string)($coach['name'] ?? 'Trenér'));

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="trainerapp-coach-calendar.ics"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//TrainerApp//Apple Coach Calendar//CS\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo 'X-WR-CALNAME:' . appleCalendarEscapeText('TrainerApp - ' . $calendarName) . "\r\n";
echo "X-WR-TIMEZONE:UTC\r\n";

foreach ($events as $event) {
    $participants = [];
    $firstAthlete = trim((string)($event['first_name'] ?? '') . ' ' . (string)($event['last_name'] ?? ''));
    $secondAthlete = trim((string)($event['second_first_name'] ?? '') . ' ' . (string)($event['second_last_name'] ?? ''));
    if ($firstAthlete !== '') {
        $participants[] = $firstAthlete;
    }
    if ($secondAthlete !== '') {
        $participants[] = $secondAthlete;
    }

    $summary = trim((string)($event['custom_title'] ?? ''));
    if ($summary === '') {
        $summary = !empty($participants) ? 'Trénink - ' . implode(' + ', $participants) : 'Trénink';
    }

    if (($event['approval_status'] ?? 'approved') === 'pending') {
        $summary = 'Čeká na schválení - ' . $summary;
    }

    $descriptionParts = [];
    if (!empty($participants)) {
        $descriptionParts[] = 'Sportovci: ' . implode(', ', $participants);
    }
    if (($event['approval_status'] ?? 'approved') === 'pending') {
        $descriptionParts[] = 'Stav: čeká na schválení';
    } else {
        $descriptionParts[] = 'Stav: schváleno';
    }

    echo "BEGIN:VEVENT\r\n";
    echo 'UID:' . appleCalendarEscapeText('coach-event-' . (int)$event['id'] . '@' . $host) . "\r\n";
    echo 'DTSTAMP:' . appleCalendarFormatUtc((string)($event['updated_at'] ?? $event['created_at'] ?? '')) . "\r\n";
    echo 'DTSTART:' . appleCalendarFormatUtc((string)$event['starts_at']) . "\r\n";
    echo 'DTEND:' . appleCalendarFormatUtc((string)$event['ends_at']) . "\r\n";
    echo 'SUMMARY:' . appleCalendarEscapeText($summary) . "\r\n";

    if (!empty($event['location'])) {
        echo 'LOCATION:' . appleCalendarEscapeText((string)$event['location']) . "\r\n";
    }

    if (!empty($descriptionParts)) {
        echo 'DESCRIPTION:' . appleCalendarEscapeText(implode("\n", $descriptionParts)) . "\r\n";
    }

    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";