<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();

try {
    $hasTrainerId = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'trainer_id'");
        if ($chk && $chk->fetch()) {
            $hasTrainerId = true;
        }
    } catch (Throwable $e) {}

    if ($hasTrainerId) {
        $stmt = $pdo->prepare('SELECT * FROM athletes WHERE coach_id = ? OR trainer_id = ? ORDER BY first_name ASC, last_name ASC');
        $stmt->execute([$coach['id'], $coach['id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM athletes WHERE coach_id = ? ORDER BY first_name ASC, last_name ASC');
        $stmt->execute([$coach['id']]);
    }
    $athletes = $stmt->fetchAll();

    if (empty($athletes)) {
        $stmtAll = $pdo->query('SELECT * FROM athletes ORDER BY first_name ASC, last_name ASC LIMIT 100');
        if ($stmtAll) {
            $athletes = $stmtAll->fetchAll();
        }
    }

    $result = [];
    foreach ($athletes as $ath) {
        $fullName = trim((string)($ath['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim((string)($ath['first_name'] ?? '') . ' ' . (string)($ath['last_name'] ?? ''));
        }
        if ($fullName === '') {
            $fullName = 'Sportovec #' . $ath['id'];
        }

        $email = (string)($ath['email'] ?? '');
        $phone = (string)($ath['phone_contact'] ?? $ath['phone'] ?? $ath['telephone'] ?? '');
        $note = (string)($ath['note'] ?? $ath['detail'] ?? '');
        $photo = (string)($ath['photo'] ?? $ath['avatar'] ?? $ath['image'] ?? '');

        $result[] = [
            'id' => (int)$ath['id'],
            'first_name' => (string)($ath['first_name'] ?? ''),
            'last_name' => (string)($ath['last_name'] ?? ''),
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'photo' => $photo,
            'note' => $note,
        ];
    }

    mobileJson(['success' => true, 'athletes' => $result]);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => 'Chyba serveru: ' . $e->getMessage()], 500);
}
