<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();

try {
    // Check if 'is_global' exists in exercises
    $hasGlobal = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'is_global'");
        if ($chk && $chk->fetch()) $hasGlobal = true;
    } catch (Throwable $e) {}

    if ($hasGlobal) {
        $stmt = $pdo->prepare('SELECT id, name, sport_type FROM exercises WHERE coach_id = ? OR is_global = 1 ORDER BY name ASC');
        $stmt->execute([$coach['id']]);
    } else {
        $stmt = $pdo->prepare('SELECT id, name, sport_type FROM exercises WHERE coach_id = ? ORDER BY name ASC');
        $stmt->execute([$coach['id']]);
    }

    $exercises = [];
    foreach ($stmt->fetchAll() as $row) {
        $exercises[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'sport_type' => (string)($row['sport_type'] ?? 'standard'),
        ];
    }
    mobileJson(['success' => true, 'exercises' => $exercises]);
} catch (Throwable $e) {
    mobileJson(['success' => false, 'error' => $e->getMessage()], 500);
}
