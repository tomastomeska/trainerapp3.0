<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mobileJson(['success' => false, 'error' => 'Neplatná metoda.'], 405);
}

$athlete = mobileRequireAthlete();
$input = mobileInput();
$currentPassword = (string)($input['current_password'] ?? '');
$newPassword = (string)($input['new_password'] ?? '');
$newPassword2 = (string)($input['new_password_confirm'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $newPassword2 === '') {
    mobileJson(['success' => false, 'error' => 'Vyplňte aktuální heslo i nové heslo.'], 422);
}
if (strlen($newPassword) < 8) {
    mobileJson(['success' => false, 'error' => 'Nové heslo musí mít alespoň 8 znaků.'], 422);
}
if ($newPassword !== $newPassword2) {
    mobileJson(['success' => false, 'error' => 'Nová hesla se neshodují.'], 422);
}

$pdo = getDB();
$stmt = $pdo->prepare('SELECT password FROM athletes WHERE id = ? LIMIT 1');
$stmt->execute([(int)$athlete['id']]);
$row = $stmt->fetch();
if (!$row || empty($row['password']) || !password_verify($currentPassword, (string)$row['password'])) {
    mobileJson(['success' => false, 'error' => 'Aktuální heslo není správně.'], 401);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
try {
    $upd = $pdo->prepare('UPDATE athletes SET password = ?, password_changed_at = NOW(), force_password_change = 0 WHERE id = ?');
    $upd->execute([$hash, (int)$athlete['id']]);
} catch (Throwable $e) {
    $upd = $pdo->prepare('UPDATE athletes SET password = ?, force_password_change = 0 WHERE id = ?');
    $upd->execute([$hash, (int)$athlete['id']]);
}

mobileJson(['success' => true]);
