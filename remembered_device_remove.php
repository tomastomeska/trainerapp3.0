<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Neplatný požadavek.');
}
if (!empty($_SESSION['superadmin_id']) || !empty($_SESSION['impersonating_admin_id'])) {
    http_response_code(403);
    exit('Správa zařízení není při zastupování dostupná.');
}
$isCoach = isLoggedIn();
$isAthlete = athleteIsLoggedIn();
if ($isCoach === $isAthlete) {
    http_response_code(401);
    exit('Přihlaste se.');
}
if ($isCoach) {
    requireLogin();
    $role = 'coach';
    $ownerId = (int)getCurrentCoachId();
    $back = BASE_URL . '/profile.php';
} else {
    requireAthleteLogin();
    $role = 'athlete';
    $ownerId = (int)getCurrentAthleteId();
    $back = BASE_URL . '/athlete_profile_edit.php';
}
$deviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT);
if ($deviceId === false || $deviceId === null || $deviceId < 1) {
    http_response_code(400);
    exit('Neplatné zařízení.');
}
try {
    $pdo = getDB();
    $stmt = $pdo->prepare('DELETE FROM remembered_devices WHERE id = ? AND ' . rememberOwnerSql($role));
    $stmt->execute([$deviceId, $ownerId]);
    if ($stmt->rowCount() !== 1) {
        flash('warning', 'Zařízení nebylo nalezeno nebo již bylo odhlášeno.');
    } else {
        flash('success', 'Zařízení bylo odhlášeno.');
    }
} catch (Throwable $e) {
    error_log('Remembered device removal failed: ' . get_class($e));
    flash('danger', 'Zařízení se nepodařilo odhlásit. Zkuste to znovu.');
}
redirect($back);
