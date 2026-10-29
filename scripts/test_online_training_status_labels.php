<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../includes/online_training.php';

$expected = [
    'sent' => ['Odesláno', 'Nový'],
    'in_progress' => ['Trénink zahájen – probíhá', 'Rozpracováno'],
    'completed' => ['Dokončený', 'Dokončeno'],
];

foreach ($expected as $status => [$coachLabel, $athleteLabel]) {
    if (onlineTrainingStatusLabel($status) !== $coachLabel || onlineTrainingAthleteStatusLabel($status) !== $athleteLabel) {
        fwrite(STDERR, "FAIL: incorrect labels for $status\n");
        exit(1);
    }
}

echo "PASS: coach and athlete online training status labels\n";
