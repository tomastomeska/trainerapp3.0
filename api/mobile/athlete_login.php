<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mobileJson(['success' => false, 'error' => 'Neplatná metoda.'], 405);
}

$input = mobileInput();
$email = mb_strtolower(trim((string)($input['username'] ?? $input['email'] ?? '')), 'UTF-8');
$password = (string)($input['password'] ?? '');

if ($email === '' || $password === '') {
    mobileJson(['success' => false, 'error' => 'Vyplňte e-mail a heslo.'], 422);
}

$pdo = getDB();
$stmt = $pdo->prepare(
    'SELECT id, coach_id, email, password, first_name, last_name, login_enabled, force_password_change, photo
     FROM athletes
     WHERE email = ?
     LIMIT 1'
);
$stmt->execute([$email]);
$athlete = $stmt->fetch();

if (!$athlete || !(int)$athlete['login_enabled']) {
    usleep(250000);
    mobileJson(['success' => false, 'error' => 'Účet sportovce ještě není aktivovaný. Kontaktujte trenéra.'], 403);
}

if (empty($athlete['password']) || !password_verify($password, (string)$athlete['password'])) {
    usleep(250000);
    mobileJson(['success' => false, 'error' => 'Nesprávné přihlašovací údaje.'], 401);
}

$remember = !empty($input['remember']);
$token = mobileIssueToken($pdo, (int)$athlete['id'], 'athlete', $remember ? 3650 : 30);
try {
    $pdo->prepare('UPDATE athletes SET last_login = NOW() WHERE id = ?')->execute([(int)$athlete['id']]);
} catch (Throwable $e) {
}

$name = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
if ($name === '') {
    $name = 'Sportovec';
}

mobileJson([
    'success' => true,
    'token' => $token,
    'token_type' => 'Bearer',
    'expires_in_days' => $remember ? 3650 : 30,
    'account_type' => 'athlete',
    'force_password_change' => (bool)((int)($athlete['force_password_change'] ?? 0)),
    'athlete' => [
        'id' => (int)$athlete['id'],
        'coach_id' => (int)$athlete['coach_id'],
        'name' => $name,
        'email' => (string)$athlete['email'],
        'photo' => mobilePublicPhoto((string)($athlete['photo'] ?? ''), 'athletes'),
    ],
]);
