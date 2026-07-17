<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

if (!isGoogleCalendarApiConfigured()) {
    flash('danger', 'Google Calendar API neni nakonfigurovana. Nastavte TRAINERAPP_GOOGLE_CALENDAR_CLIENT_ID a TRAINERAPP_GOOGLE_CALENDAR_CLIENT_SECRET.');
    redirect(BASE_URL . '/calendar.php?tab=google');
}

$coachId = (int)getCurrentCoachId();
if ($coachId <= 0) {
    flash('danger', 'Neplatna session trenera.');
    redirect(BASE_URL . '/login.php');
}

$requestedCalendarId = trim((string)($_GET['calendar_id'] ?? 'primary'));
if ($requestedCalendarId === '') {
    $requestedCalendarId = 'primary';
}

$state = bin2hex(random_bytes(24));
$_SESSION['google_calendar_oauth_state'] = $state;
$_SESSION['google_calendar_oauth_coach_id'] = $coachId;
$_SESSION['google_calendar_oauth_calendar_id'] = $requestedCalendarId;

$redirectUri = trim((string)GOOGLE_CALENDAR_REDIRECT_URI);
if ($redirectUri === '') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $redirectUri = $scheme . '://' . $host . BASE_URL . '/google_calendar_oauth_callback.php';
}

$params = [
    'client_id' => (string)GOOGLE_CALENDAR_CLIENT_ID,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/calendar.events',
    'access_type' => 'offline',
    'include_granted_scopes' => 'true',
    'prompt' => 'consent',
    'state' => $state,
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
redirect($authUrl);
