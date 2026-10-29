<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../includes/online_training.php';

$dsn = getenv('ONLINE_TRAINING_TEST_DSN');
if (!$dsn) {
    fwrite(STDERR, "Set ONLINE_TRAINING_TEST_DSN to an isolated MySQL/MariaDB database.\n");
    exit(1);
}
$pdo = new PDO($dsn, getenv('ONLINE_TRAINING_TEST_USER') ?: 'root', getenv('ONLINE_TRAINING_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('CREATE TEMPORARY TABLE online_trainings (id INT PRIMARY KEY, athlete_id INT, athlete_note TEXT)');
$pdo->exec('CREATE TEMPORARY TABLE online_training_exercises (id INT PRIMARY KEY, online_training_id INT)');
$pdo->exec('CREATE TEMPORARY TABLE online_training_series (id INT PRIMARY KEY, online_training_exercise_id INT)');
$pdo->exec('CREATE TEMPORARY TABLE online_training_results (online_training_id INT, online_training_series_id INT PRIMARY KEY, actual_weight DECIMAL(10,2), actual_reps INT, note TEXT)');
$pdo->exec("INSERT INTO online_trainings VALUES (1, 1, NULL)");
$pdo->exec('INSERT INTO online_training_exercises VALUES (1, 1), (2, 1), (3, 1)');
$pdo->exec('INSERT INTO online_training_series VALUES (1, 1), (2, 2), (3, 3)');
$data = [
    'series_count' => '3',
    'athlete_note' => 'Poznámka sportovce',
    'series' => [
        1 => ['weight' => '41', 'reps' => '8'],
        2 => ['weight' => '52', 'reps' => '9'],
        3 => ['weight' => '63', 'reps' => '10'],
    ],
];
$pdo->beginTransaction();
onlineTrainingSaveAthleteResults($pdo, 1, 1, $data);
$pdo->commit();
$rows = $pdo->query('SELECT online_training_series_id, actual_weight, actual_reps FROM online_training_results ORDER BY online_training_series_id')->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 3 || (float)$rows[1]['actual_weight'] !== 52.0 || (int)$rows[2]['actual_reps'] !== 10 || $pdo->query('SELECT athlete_note FROM online_trainings WHERE id = 1')->fetchColumn() !== $data['athlete_note']) {
    throw new RuntimeException('Results for all exercises and the note must be saved.');
}
$incomplete = $data;
unset($incomplete['series'][3]);
$incomplete['athlete_note'] = 'Must not be saved';
$pdo->beginTransaction();
try {
    onlineTrainingSaveAthleteResults($pdo, 1, 1, $incomplete);
    throw new LogicException('Incomplete results were accepted.');
} catch (RuntimeException $e) {
    $pdo->rollBack();
}
if ($pdo->query('SELECT athlete_note FROM online_trainings WHERE id = 1')->fetchColumn() !== $data['athlete_note']) {
    throw new RuntimeException('Incomplete results changed the note.');
}
$invalid = $data;
$invalid['series'][1]['weight'] = '99';
$invalid['series'][2]['reps'] = 'invalid';
$pdo->beginTransaction();
try {
    onlineTrainingSaveAthleteResults($pdo, 1, 1, $invalid);
    throw new LogicException('Invalid results were accepted.');
} catch (RuntimeException $e) {
    $pdo->rollBack();
}
if ((float)$pdo->query('SELECT actual_weight FROM online_training_results WHERE online_training_series_id = 1')->fetchColumn() !== 41.0) {
    throw new RuntimeException('Invalid results were not rolled back.');
}
echo "PASS: multiple exercises, athlete note and invalid submission rollback\n";
