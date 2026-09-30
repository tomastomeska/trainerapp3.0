<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$token = mobileBearerToken();
if ($token !== '') {
    $pdo = getDB();
    $pdo->prepare('UPDATE mobile_api_tokens
                   SET revoked_at = NOW(),
                       last_used_at = IF(last_used_at IS NULL OR last_used_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), last_used_at)
                   WHERE token_hash = ?')
        ->execute([hash('sha256', $token)]);
}
mobileJson(['success' => true]);
