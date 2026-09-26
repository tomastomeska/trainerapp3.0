<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();

$stmt = $pdo->prepare(
    'SELECT a.id, a.first_name, a.last_name, a.email, a.phone,
            a.birth_date, a.height, a.weight, a.login_enabled,
            a.created_at,
            MAX(ts.started_at) AS last_training_at
     FROM athletes a
     LEFT JOIN training_sessions ts ON ts.athlete_id = a.id
     WHERE a.coach_id = ?
     GROUP BY a.id, a.first_name, a.last_name, a.email, a.phone,
              a.birth_date, a.height, a.weight, a.login_enabled, a.created_at
     ORDER BY a.last_name ASC, a.first_name ASC'
);
$stmt->execute([$coach['id']]);

$athletes = [];
foreach ($stmt->fetchAll() as $row) {
    $athletes[] = [
        'id' => (int)$row['id'],
        'first_name' => (string)$row['first_name'],
        'last_name' => (string)$row['last_name'],
        'full_name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
        'email' => (string)($row['email'] ?? ''),
        'phone' => (string)($row['phone'] ?? ''),
        'birth_date' => $row['birth_date'],
        'height' => $row['height'] !== null ? (float)$row['height'] : null,
        'weight' => $row['weight'] !== null ? (float)$row['weight'] : null,
        'login_enabled' => (bool)((int)($row['login_enabled'] ?? 0)),
        'last_training_at' => $row['last_training_at'],
    ];
}

mobileJson(['success' => true, 'athletes' => $athletes]);
