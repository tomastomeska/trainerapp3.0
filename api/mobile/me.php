<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$photo = '';
try {
    $photoStmt = getDB()->prepare('SELECT photo FROM coaches WHERE id = ? LIMIT 1');
    $photoStmt->execute([(int)$coach['id']]);
    $photo = mobilePublicPhoto((string)($photoStmt->fetchColumn() ?: ''), 'coaches');
} catch (Throwable $e) {
}
$coach['photo'] = $photo;
mobileJson(['success' => true, 'coach' => $coach]);
