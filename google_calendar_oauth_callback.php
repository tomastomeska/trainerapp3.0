<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$coachId = (int)getCurrentCoachId();
$state = trim((string)($_GET['state'] ?? ''));
$code = trim((string)($_GET['code'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

$expectedState = (string)($_SESSION['google_calendar_oauth_state'] ?? '');
$expectedCoachId = (int)($_SESSION['google_calendar_oauth_coach_id'] ?? 0);
$targetCalendarId = trim((string)($_SESSION['google_calendar_oauth_calendar_id'] ?? 'primary'));
if ($targetCalendarId === '') {
    $targetCalendarId = 'primary';
}

unset($_SESSION['google_calendar_oauth_state'], $_SESSION['google_calendar_oauth_coach_id'], $_SESSION['google_calendar_oauth_calendar_id']);

if ($error !== '') {
    flash('danger', 'Google autorizace byla zrusena nebo selhala: ' . $error);
    redirect(BASE_URL . '/calendar.php?tab=google');
}

if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state) || $expectedCoachId !== $coachId) {
    flash('danger', 'Neplatny OAuth stav. Zkuste propojeni znovu.');
    redirect(BASE_URL . '/calendar.php?tab=google');
}

if ($code === '') {
    flash('danger', 'Google neposlal autorizacni kod.');
    redirect(BASE_URL . '/calendar.php?tab=google');
}

if (!isGoogleCalendarApiConfigured()) {
    flash('danger', 'Google Calendar API neni nakonfigurovana.');
    redirect(BASE_URL . '/calendar.php?tab=google');
}

if (!function_exists('curl_init')) {
    flash('danger', 'Na serveru chybi CURL rozsirení.');
    redirect(BASE_URL . '/calendar.php?tab=google');
}

$redirectUri = trim((string)GOOGLE_CALENDAR_REDIRECT_URI);
if ($redirectUri === '') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $redirectUri = $scheme . '://' . $host . BASE_URL . '/google_calendar_oauth_callback.php';
}

$postFields = http_build_query([
    'code' => $code,
    'client_id' => (string)GOOGLE_CALENDAR_CLIENT_ID,
    'client_secret' => (string)GOOGLE_CALENDAR_CLIENT_SECRET,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);

$raw = curl_exec($ch);
$curlErr = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($raw === false) {
    flash('danger', 'Google OAuth token exchange selhal: ' . $curlErr);
    redirect(BASE_URL . '/calendar.php?tab=google');
}

$json = json_decode((string)$raw, true);
if ($status < 200 || $status >= 300 || !is_array($json)) {
    flash('danger', 'Google OAuth token exchange selhal (HTTP ' . $status . ').');
    redirect(BASE_URL . '/calendar.php?tab=google');
}

$accessToken = trim((string)($json['access_token'] ?? ''));
$refreshToken = trim((string)($json['refresh_token'] ?? ''));
$scope = trim((string)($json['scope'] ?? ''));
$expiresIn = max(60, (int)($json['expires_in'] ?? 3600));

if ($accessToken === '') {
    flash('danger', 'Google OAuth nevratil access token.');
    redirect(BASE_URL . '/calendar.php?tab=google');
}

$pdo = getDB();
$existingRefreshStmt = $pdo->prepare('SELECT google_oauth_refresh_token FROM coaches WHERE id = ? LIMIT 1');
$existingRefreshStmt->execute([$coachId]);
$existingRefreshToken = trim((string)($existingRefreshStmt->fetchColumn() ?: ''));
if ($refreshToken === '') {
    $refreshToken = $existingRefreshToken;
}

$expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
$upd = $pdo->prepare(
    'UPDATE coaches
     SET google_calendar_sync_enabled = 1,
         google_calendar_id = ?,
         google_oauth_access_token = ?,
         google_oauth_refresh_token = ?,
         google_oauth_expires_at = ?,
         google_oauth_scope = ?,
         google_sync_last_error = NULL
     WHERE id = ?'
);
$upd->execute([
    $targetCalendarId,
    $accessToken,
    $refreshToken !== '' ? $refreshToken : null,
    $expiresAt,
    $scope !== '' ? $scope : null,
    $coachId,
]);

flash('success', 'Google Calendar byl uspesne propojen.');
redirect(BASE_URL . '/calendar.php?tab=google');
