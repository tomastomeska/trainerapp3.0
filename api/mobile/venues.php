<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();

$venuesMap = [];

// 1. Zavedená sportoviště z training_venues
try {
    $stmt = $pdo->prepare('SELECT id, name, address FROM training_venues WHERE is_active = 1 ORDER BY name ASC');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') {
            $venuesMap[$name] = [
                'id' => (int)$row['id'],
                'name' => $name,
                'address' => (string)($row['address'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {}

// 2. Vlastní sportoviště trenéra z coach_training_venues
try {
    $stmt2 = $pdo->prepare('SELECT id, name FROM coach_training_venues WHERE coach_id = ? AND is_active = 1 ORDER BY name ASC');
    $stmt2->execute([$coach['id']]);
    foreach ($stmt2->fetchAll() as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '' && !isset($venuesMap[$name])) {
            $venuesMap[$name] = [
                'id' => 10000 + (int)$row['id'],
                'name' => $name,
                'address' => '',
            ];
        }
    }
} catch (Throwable $e) {}

// 3. Unikátní místa z předchozích událostí
try {
    $stmt3 = $pdo->prepare('SELECT DISTINCT location FROM coach_calendar_events WHERE coach_id = ? AND location IS NOT NULL AND location != "" ORDER BY location ASC');
    $stmt3->execute([$coach['id']]);
    $idx = 20000;
    foreach ($stmt3->fetchAll() as $row) {
        $loc = trim((string)($row['location'] ?? ''));
        if ($loc !== '' && !isset($venuesMap[$loc])) {
            $venuesMap[$loc] = [
                'id' => $idx++,
                'name' => $loc,
                'address' => '',
            ];
        }
    }
} catch (Throwable $e) {}

mobileJson(['success' => true, 'venues' => array_values($venuesMap)]);
