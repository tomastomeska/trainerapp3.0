<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$token = mobileBearerToken();
if ($token !== '') {
    $pdo = getDB();
    $pdo->prepare('UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE token_hash = ?')
        ->execute([hash('sha256', $token)]);
}
mobileJson(['success' => true]);
