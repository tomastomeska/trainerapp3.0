<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/food_diary.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

$athleteStmt = $pdo->prepare(
    'SELECT a.id, a.coach_id, a.first_name, a.last_name
     FROM athletes a
     WHERE a.id = ?
     LIMIT 1'
);
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    session_destroy();
    redirect(BASE_URL . '/login.php');
}

$selectedDate = foodDiaryResolveSelectedDate((string)($_GET['date'] ?? date('Y-m-d')));
$period = foodDiaryNormalizePeriod($_GET, $selectedDate);
$rows = foodDiaryExportRows($pdo, $athleteId, (int)$athlete['coach_id'], $period['from'], $period['to']);

$filename = sprintf(
    'strava_%s_%s_%s.csv',
    preg_replace('/\s+/', '_', (string)$athlete['last_name']),
    $period['from'],
    $period['to']
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

fputcsv($out, ['TrainerApp - Strava export'], ';');
fputcsv($out, ['Sportovec', trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])], ';');
fputcsv($out, ['Období', formatDate($period['from']) . ' - ' . formatDate($period['to'])], ';');
fputcsv($out, [], ';');

fputcsv($out, [
    'Datum',
    'Den',
    'Typ jídla',
    'Čas',
    'Název položky',
    'Množství',
    'Jednotka',
    'Poznámka sportovce',
    'Poznámka trenéra',
    'Aktivita dne',
], ';');

$dayNames = [
    1 => 'Pondělí',
    2 => 'Úterý',
    3 => 'Středa',
    4 => 'Čtvrtek',
    5 => 'Pátek',
    6 => 'Sobota',
    7 => 'Neděle',
];

foreach ($rows as $row) {
    $date = (string)$row['date'];
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    $dayLabel = $dt ? ($dayNames[(int)$dt->format('N')] ?? '') : '';

    fputcsv($out, [
        formatDate($date),
        $dayLabel,
        foodDiaryMealTypeLabel((string)$row['meal_type']),
        !empty($row['meal_time']) ? substr((string)$row['meal_time'], 0, 5) : '',
        (string)$row['food_name'],
        (string)$row['quantity'] !== '' ? str_replace('.', ',', (string)$row['quantity']) : '',
        (string)$row['unit'],
        (string)$row['athlete_note'],
        (string)$row['coach_note'],
        (string)$row['activity'],
    ], ';');
}

fclose($out);
exit;
