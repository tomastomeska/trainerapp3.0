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

function appleCalendarEventUnixTime(?string $dateTimeSql): int
{
    if (!$dateTimeSql) {
        return time();
    }

    try {
        $date = new DateTime($dateTimeSql, new DateTimeZone(date_default_timezone_get()));
        return $date->getTimestamp();
    } catch (Throwable $e) {
        return time();
    }
}

function appleCalendarFormatLocalLabel(?string $dateTimeSql): string
{
    if (!$dateTimeSql) {
        return '';
    }

    try {
        $date = new DateTime($dateTimeSql, new DateTimeZone(date_default_timezone_get()));
        return $date->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return '';
    }
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

$refreshInterval = 'PT15M';
$lastModifiedTs = time();
$etagParts = ['coach', (string)$coach['id']];
foreach ($events as $event) {
    $eventChangedAt = (string)($event['updated_at'] ?? $event['created_at'] ?? '');
    $eventChangedTs = appleCalendarEventUnixTime($eventChangedAt);
    if ($eventChangedTs > $lastModifiedTs) {
        $lastModifiedTs = $eventChangedTs;
    }

    $etagParts[] = implode('|', [
        (string)($event['id'] ?? ''),
        $eventChangedAt,
        (string)($event['starts_at'] ?? ''),
        (string)($event['ends_at'] ?? ''),
        (string)($event['approval_status'] ?? ''),
        (string)($event['custom_title'] ?? ''),
        (string)($event['location'] ?? ''),
    ]);
}

$etag = '"' . sha1(implode('||', $etagParts)) . '"';
$lastModifiedHeader = gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT';

$ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModifiedHeader);
header('Cache-Control: private, max-age=300, must-revalidate');
header('Expires: ' . gmdate('D, d M Y H:i:s', $lastModifiedTs + 300) . ' GMT');

if ($ifNoneMatch === $etag) {
    http_response_code(304);
    exit;
}

if ($ifModifiedSince !== '') {
    $ifModifiedSinceTs = strtotime($ifModifiedSince);
    if ($ifModifiedSinceTs !== false && $ifModifiedSinceTs >= $lastModifiedTs) {
        http_response_code(304);
        exit;
    }
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="trainerapp-coach-calendar.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//TrainerApp//Apple Coach Calendar//CS\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo 'X-WR-CALNAME:' . appleCalendarEscapeText('TrainerApp - ' . $calendarName) . "\r\n";
echo "X-WR-TIMEZONE:UTC\r\n";
echo 'REFRESH-INTERVAL;VALUE=DURATION:' . $refreshInterval . "\r\n";
echo 'X-PUBLISHED-TTL:' . $refreshInterval . "\r\n";

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

    $startLabel = appleCalendarFormatLocalLabel((string)($event['starts_at'] ?? ''));
    $endLabel = appleCalendarFormatLocalLabel((string)($event['ends_at'] ?? ''));
    $descriptionParts = [];
    $descriptionParts[] = 'Název: ' . $summary;
    if ($startLabel !== '' && $endLabel !== '') {
        $descriptionParts[] = 'Termín: ' . $startLabel . ' - ' . $endLabel;
    }
    if (!empty($participants)) {
        $descriptionParts[] = 'Sportovci: ' . implode(', ', $participants);
    }
    if (!empty($event['location'])) {
        $descriptionParts[] = 'Místo: ' . (string)$event['location'];
    }
    if (($event['approval_status'] ?? 'approved') === 'pending') {
        $descriptionParts[] = 'Stav: čeká na schválení';
    } else {
        $descriptionParts[] = 'Stav: schváleno';
    }

    echo "BEGIN:VEVENT\r\n";
    echo 'UID:' . appleCalendarEscapeText('coach-event-' . (int)$event['id'] . '@' . $host) . "\r\n";
    echo 'DTSTAMP:' . appleCalendarFormatUtc((string)($event['updated_at'] ?? $event['created_at'] ?? '')) . "\r\n";
    echo 'CREATED:' . appleCalendarFormatUtc((string)($event['created_at'] ?? $event['updated_at'] ?? '')) . "\r\n";
    echo 'LAST-MODIFIED:' . appleCalendarFormatUtc((string)($event['updated_at'] ?? $event['created_at'] ?? '')) . "\r\n";
    echo 'SEQUENCE:' . max(0, appleCalendarEventUnixTime((string)($event['updated_at'] ?? $event['created_at'] ?? ''))) . "\r\n";
    echo 'STATUS:' . (($event['approval_status'] ?? 'approved') === 'pending' ? 'TENTATIVE' : 'CONFIRMED') . "\r\n";
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