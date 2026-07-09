<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

function athleteCalendarEscapeText(string $value): string
{
    return str_replace(
        ["\\", ";", ",", "\r\n", "\r", "\n"],
        ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
        $value
    );
}

function athleteCalendarFormatUtc(?string $dateTimeSql): string
{
    if (!$dateTimeSql) {
        return gmdate('Ymd\THis\Z');
    }

    $date = new DateTime($dateTimeSql, new DateTimeZone(date_default_timezone_get()));
    $date->setTimezone(new DateTimeZone('UTC'));

    return $date->format('Ymd\THis\Z');
}

function athleteCalendarParseToken(): string
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
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/athlete_calendar_feed.php');
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

$token = athleteCalendarParseToken();
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit;
}

$pdo = getDB();
$athleteStmt = $pdo->prepare(
    'SELECT a.id,
            a.first_name,
            a.last_name,
            c.name AS coach_name,
            c.username AS coach_username
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id
     WHERE a.apple_calendar_sync_enabled = 1
       AND a.apple_calendar_token = ?
     LIMIT 1'
);
$athleteStmt->execute([$token]);
$athlete = $athleteStmt->fetch();

if (!$athlete) {
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
            e.athlete_id,
            e.second_athlete_id,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE e.athlete_id = ? OR e.second_athlete_id = ?
     ORDER BY e.starts_at ASC, e.id ASC'
);
$eventsStmt->execute([(int)$athlete['id'], (int)$athlete['id']]);
$events = $eventsStmt->fetchAll();

$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$athleteDisplayName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
if ($athleteDisplayName === '') {
    $athleteDisplayName = 'Sportovec';
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="trainerapp-athlete-calendar.ics"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//TrainerApp//Apple Athlete Calendar//CS\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo 'X-WR-CALNAME:' . athleteCalendarEscapeText('TrainerApp - ' . $athleteDisplayName) . "\r\n";
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
        $summary = 'Trénink';
    }
    if (($event['approval_status'] ?? 'approved') === 'pending') {
        $summary = 'Čeká na schválení - ' . $summary;
    }

    $descriptionParts = [];
    $coachName = trim((string)($athlete['coach_name'] ?? $athlete['coach_username'] ?? ''));
    if ($coachName !== '') {
        $descriptionParts[] = 'Trenér: ' . $coachName;
    }
    if (!empty($participants)) {
        $descriptionParts[] = 'Účastníci: ' . implode(', ', $participants);
    }
    if (($event['approval_status'] ?? 'approved') === 'pending') {
        $descriptionParts[] = 'Stav: čeká na schválení';
    } else {
        $descriptionParts[] = 'Stav: schváleno';
    }

    echo "BEGIN:VEVENT\r\n";
    echo 'UID:' . athleteCalendarEscapeText('athlete-event-' . (int)$event['id'] . '-athlete-' . (int)$athlete['id'] . '@' . $host) . "\r\n";
    echo 'DTSTAMP:' . athleteCalendarFormatUtc((string)($event['updated_at'] ?? $event['created_at'] ?? '')) . "\r\n";
    echo 'DTSTART:' . athleteCalendarFormatUtc((string)$event['starts_at']) . "\r\n";
    echo 'DTEND:' . athleteCalendarFormatUtc((string)$event['ends_at']) . "\r\n";
    echo 'SUMMARY:' . athleteCalendarEscapeText($summary) . "\r\n";

    if (!empty($event['location'])) {
        echo 'LOCATION:' . athleteCalendarEscapeText((string)$event['location']) . "\r\n";
    }

    if (!empty($descriptionParts)) {
        echo 'DESCRIPTION:' . athleteCalendarEscapeText(implode("\n", $descriptionParts)) . "\r\n";
    }

    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";