<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

flash('info', 'MyCoach je nyní jen Pro modul. Přístup je řízen administrací.');
redirect(BASE_URL . '/dashboard.php');

$pdo = getDB();
$coachId = (int)getCurrentCoachId();
$coach = getCurrentCoach();
$coachDisplayName = trim((string)($coach['name'] ?? ''));
if ($coachDisplayName === '') {
    $coachDisplayName = trim((string)($coach['username'] ?? ''));
}

if (!mycoachAccessEnabledForCoach($pdo, $coachId)) {
    flash('warning', 'MyCoach je pro váš účet zatím uzamčený.');
    redirect(BASE_URL . '/dashboard.php');
}

$myCoachUser = mycoachResolveUser($pdo, 'coach', $coachId, 0, $coachDisplayName);
if (!$myCoachUser) {
    flash('danger', 'MyCoach profil se nepodařilo načíst.');
    redirect(BASE_URL . '/dashboard.php');
}

$myCoachUserId = (int)$myCoachUser['id'];
$timeline = mycoachFetchDailyTimeline($pdo, $myCoachUserId, 180);
$acwr = mycoachCalculateAcwr($timeline);

$filename = 'mycoach_export_' . date('Y-m-d_H-i') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'entry_date',
    'workout_name',
    'workout_id',
    'readiness_score',
    'acwr_ratio',
    'acwr_label',
    'feeling_score',
    'energy_score',
    'sleep_hours',
    'rpe_score',
    'training_duration_minutes',
    'avg_heart_rate',
    'max_heart_rate',
    'muscle_pain_score',
    'joint_pain_score',
    'motivation_score',
    'started_at',
    'completed_at',
], ';');

foreach ($timeline as $row) {
    fputcsv($output, [
        (string)($row['entry_date'] ?? ''),
        (string)($row['workout_name'] ?? ''),
        (string)($row['workout_id'] ?? ''),
        isset($row['readiness_score']) && $row['readiness_score'] !== null ? (string)round((float)$row['readiness_score']) : '',
        $acwr ? number_format((float)$acwr['ratio'], 2, '.', '') : '',
        $acwr ? (string)$acwr['label'] : '',
        isset($row['feeling_score']) && $row['feeling_score'] !== null ? (string)$row['feeling_score'] : '',
        isset($row['energy_score']) && $row['energy_score'] !== null ? (string)$row['energy_score'] : '',
        isset($row['sleep_hours']) && $row['sleep_hours'] !== null ? number_format((float)$row['sleep_hours'], 2, '.', '') : '',
        isset($row['rpe_score']) && $row['rpe_score'] !== null ? (string)$row['rpe_score'] : '',
        isset($row['training_duration_minutes']) && $row['training_duration_minutes'] !== null ? (string)$row['training_duration_minutes'] : '',
        isset($row['avg_heart_rate']) && $row['avg_heart_rate'] !== null ? (string)$row['avg_heart_rate'] : '',
        isset($row['max_heart_rate']) && $row['max_heart_rate'] !== null ? (string)$row['max_heart_rate'] : '',
        isset($row['muscle_pain_score']) && $row['muscle_pain_score'] !== null ? (string)$row['muscle_pain_score'] : '',
        isset($row['joint_pain_score']) && $row['joint_pain_score'] !== null ? (string)$row['joint_pain_score'] : '',
        isset($row['motivation_score']) && $row['motivation_score'] !== null ? (string)$row['motivation_score'] : '',
        (string)($row['started_at'] ?? ''),
        (string)($row['completed_at'] ?? ''),
    ], ';');
}

fclose($output);
exit;
