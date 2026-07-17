<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$config = getAthleteAppleCaldavConfig($athleteId);
if (!$config || empty($config['apple_caldav_sync_enabled'])) {
	flash('danger', 'Apple profil nejde vygenerovat. Nejdriv vyplnte Apple ID, app-specific heslo, ulozte Apple CalDAV a zapnete synchronizaci.');
	redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
}

$calendarUrl = normalizeAppleCaldavCalendarUrl((string)($config['apple_caldav_calendar_url'] ?? ''));
$username = trim((string)($config['apple_caldav_username'] ?? ''));
$password = trim((string)($config['apple_caldav_app_password'] ?? ''));

if ($calendarUrl === '' || $username === '' || $password === '') {
	flash('danger', 'Apple profil nejde vygenerovat. Nejdriv vyplnte Apple ID a app-specific heslo (nejedna se o heslo k Apple ID), potom ulozte nastaveni.');
	redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
}

// Pro export profilu pouzijeme aktualne ulozenou CalDAV URL.
// Kontrola existence kalendare TrainerApp na Apple strane je nespolehliva,
// proto uzivatele neblokujeme pri stahovani profilu.
$resolvedCalendarUrl = $calendarUrl;

$parsed = parse_url($resolvedCalendarUrl);
$host = trim((string)($parsed['host'] ?? ''));
$scheme = strtolower(trim((string)($parsed['scheme'] ?? 'https')));
$port = isset($parsed['port']) ? (int)$parsed['port'] : 443;
$path = trim((string)($parsed['path'] ?? '/'));
$query = trim((string)($parsed['query'] ?? ''));
if ($query !== '') {
	$path .= '?' . $query;
}
if ($path === '') {
	$path = '/';
}
if (!str_starts_with($path, '/')) {
	$path = '/' . $path;
}

if ($host === '' || $scheme !== 'https') {
	flash('danger', 'CalDAV URL musi byt validni HTTPS adresa.');
	redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
}

$mobileConfig = buildAppleCaldavMobileConfig([
	'profile_display_name' => 'TrainerApp - Apple Kalendar (Sportovec)',
	'profile_description' => 'Automaticke nastaveni Apple CalDAV pro TrainerApp ucet sportovce.',
	'payload_identifier' => 'online.reservio.trainerapp.athlete.' . $athleteId . '.caldav',
	'account_description' => 'TrainerApp Sportovec',
	'host_name' => $host,
	'port' => $port,
	'principal_url' => $path,
	'username' => $username,
	'password' => $password,
]);

$filename = 'trainerapp-athlete-' . $athleteId . '-caldav.mobileconfig';

if (!headers_sent()) {
	header('Content-Type: application/x-apple-aspen-config; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	header('X-Content-Type-Options: nosniff');
}

echo $mobileConfig;
exit;

