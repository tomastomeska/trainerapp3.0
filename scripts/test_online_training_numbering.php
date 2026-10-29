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
$pdo->exec('CREATE TEMPORARY TABLE online_trainings (trainer_id INT, athlete_id INT, sequence_number INT, UNIQUE KEY uq_online_training_sequence (trainer_id, athlete_id, sequence_number))');
foreach ([[1, 1, 1], [1, 2, 1], [2, 1, 1]] as [$coachId, $athleteId, $expected]) {
    if (onlineTrainingNextSequence($pdo, $coachId, $athleteId) !== $expected) throw new RuntimeException('Each pair must start at 1.');
    $pdo->prepare('INSERT INTO online_trainings VALUES (?, ?, ?)')->execute([$coachId, $athleteId, $expected]);
}
if (onlineTrainingNextSequence($pdo, 1, 1) !== 2) throw new RuntimeException('Existing pair must continue at 2.');
$pdo->exec('DELETE FROM online_trainings WHERE trainer_id = 1 AND athlete_id = 1');
if (onlineTrainingNextSequence($pdo, 1, 1) !== 1) throw new RuntimeException('Empty pair must restart at 1.');
if (onlineTrainingNextSequence($pdo, 2, 1) !== 2) throw new RuntimeException('Deleting tests must not reset another coach.');
echo "PASS: independent coach/athlete sequences and reset after deleting tests\n";
