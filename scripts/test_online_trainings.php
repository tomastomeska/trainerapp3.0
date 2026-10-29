<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain; charset=utf-8');

$tables = [
    'online_training_subscriptions',
    'workout_set_attachments',
    'online_trainings',
    'online_training_exercises',
    'online_training_series',
    'online_training_results',
    'online_training_attachments',
    'online_training_billing',
];

try {
    $pdo = getDB();
    $missing = [];
    foreach ($tables as $table) {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        if (!$stmt || !$stmt->fetchColumn()) $missing[] = $table;
    }
    if ($missing) {
        http_response_code(500);
        echo "FAIL: chybi tabulky: " . implode(', ', $missing) . PHP_EOL;
        exit;
    }

    $uniqueStmt = $pdo->query("SHOW INDEX FROM online_trainings WHERE Key_name = 'uq_online_training_sequence'");
    $sequenceColumns = $uniqueStmt ? $uniqueStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    usort($sequenceColumns, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
    if (array_column($sequenceColumns, 'Column_name') !== ['trainer_id', 'athlete_id', 'sequence_number']
        || array_filter($sequenceColumns, static fn(array $column): bool => (int)$column['Non_unique'] !== 0)) {
        http_response_code(500);
        echo "FAIL: chybi unikatni cislovani online treningu pro dvojici trener/sportovec." . PHP_EOL;
        exit;
    }


    $pdo->query('SELECT id FROM training_sessions WHERE completed_at IS NOT NULL LIMIT 1')->fetch();
    $pdo->query('SELECT id FROM online_training_results LIMIT 1')->fetch();
    echo "PASS: online schema, cislovani a oddelene klasicke/online vysledky jsou dostupne." . PHP_EOL;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'FAIL: ' . $e->getMessage() . PHP_EOL;
}