<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mobileJson(['success' => false, 'error' => 'Neplatná metoda.'], 405);
}

$input = mobileInput();
$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($username === '' || $password === '') {
    mobileJson(['success' => false, 'error' => 'Vyplňte uživatelské jméno a heslo.'], 422);
}

$pdo = getDB();
$stmt = $pdo->prepare(
    'SELECT id, password, name, username, email, is_active, force_password_change
     FROM coaches WHERE username = ? LIMIT 1'
);
$stmt->execute([$username]);
$coach = $stmt->fetch();

if (!$coach || !password_verify($password, (string)$coach['password'])) {
    usleep(250000);
    mobileJson(['success' => false, 'error' => 'Nesprávné přihlašovací údaje.'], 401);
}

if (!(int)$coach['is_active']) {
    mobileJson(['success' => false, 'error' => 'Váš účet byl zablokován.'], 403);
}

$remember = !empty($input['remember']);
$token = mobileIssueToken($pdo, (int)$coach['id'], 'coach', $remember ? 3650 : 30);
$photo = '';
try {
    $photoStmt = $pdo->prepare('SELECT photo FROM coaches WHERE id = ? LIMIT 1');
    $photoStmt->execute([(int)$coach['id']]);
    $photo = mobilePublicPhoto((string)($photoStmt->fetchColumn() ?: ''), 'coaches');
} catch (Throwable $e) {
}
$pdo->prepare('UPDATE coaches SET last_login = NOW() WHERE id = ?')->execute([(int)$coach['id']]);

mobileJson([
    'success' => true,
    'token' => $token,
    'token_type' => 'Bearer',
    'expires_in_days' => $remember ? 3650 : 30,
    'force_password_change' => (bool)((int)($coach['force_password_change'] ?? 0)),
    'coach' => [
        'id' => (int)$coach['id'],
        'username' => (string)$coach['username'],
        'name' => (string)$coach['name'],
        'email' => (string)$coach['email'],
        'photo' => $photo,
    ],
]);
