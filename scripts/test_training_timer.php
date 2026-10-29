<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../includes/functions.php';

$validInput = [
    'duration_minutes' => '1',
    'duration_seconds' => '2',
];
$invalidSeconds = [
    'duration_minutes' => '0',
    'duration_seconds' => '60',
];
$invalidType = [
    'duration_minutes' => ['1'],
    'duration_seconds' => '0',
];

if (formatTrainingDuration(9846) !== '1 min 38 s'
    || formatTrainingDuration(540723) !== '90 min 7 s'
    || trainingDurationCentisecondsFromInput($validInput) !== 6200
    || trainingDurationCentisecondsFromInput($invalidSeconds) !== null
    || trainingDurationCentisecondsFromInput($invalidType) !== null) {
    fwrite(STDERR, "FAIL: training timer formatting or validation\n");
    exit(1);
}

echo "PASS: training timer formatting and input validation\n";
