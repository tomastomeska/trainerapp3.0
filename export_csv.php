<?php
// export_csv.php – Export tréninku do CSV (Excel compatible)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$coachId   = getCurrentCoachId();
$sessionId = intParam($_GET, 'session_id');
$pdo       = getDB();

$stmt = $pdo->prepare(
    'SELECT ts.*, a.first_name, a.last_name, ws.name AS set_name
     FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
         WHERE ts.id = ? AND a.coach_id = ?
             AND ts.completed_at IS NOT NULL
             AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Trénink nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$exercises = getSessionExercises($sessionId, (int)$session['workout_set_id']);

$filename = sprintf(
    'trening_%s_%s_%s.csv',
    preg_replace('/\s+/', '_', $session['last_name']),
    preg_replace('/\s+/', '_', $session['set_name']),
    date('Y-m-d', strtotime($session['completed_at']))
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
// BOM pro správné zobrazení v Excelu
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Hlavička souboru
fputcsv($out, ['TrainerApp – Tréninkový záznam'], ';');
fputcsv($out, ['Sportovec', $session['first_name'] . ' ' . $session['last_name']], ';');
fputcsv($out, ['Sada', $session['set_name']], ';');
fputcsv($out, ['Datum', formatDateTime($session['completed_at'])], ';');
if ($session['location']) {
    fputcsv($out, ['Místo', $session['location']], ';');
}
fputcsv($out, [], ';');

foreach ($exercises as $ex) {
    fputcsv($out, ['Cvik ' . $ex['exercise_order'] . ': ' . $ex['exercise_name']], ';');
    fputcsv($out, ['#', 'Váha celkem (kg)', 'Opakování', 'Dopomoc', 'Objem (kg×rep)'], ';');

    $series = getSeriesForExercise($sessionId, $ex['exercise_id']);
    foreach ($series as $s) {
        fputcsv($out, [
            $s['series_order'],
            number_format((float)$s['weight'] + (float)($s['equipment_weight'] ?? 0), 2, ',', ''),
            $s['reps'],
            $s['assistance_reps'],
            number_format(((float)$s['weight'] + (float)($s['equipment_weight'] ?? 0)) * $s['reps'], 2, ',', ''),
        ], ';');
    }
    fputcsv($out, [], ';');
}

fclose($out);
exit;
