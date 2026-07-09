<?php
// send_email.php – Odeslání souhrnu tréninku e-mailem sportovci
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$coachId   = getCurrentCoachId();
$sessionId = intParam($_GET, 'session_id');
$pdo       = getDB();

$stmt = $pdo->prepare(
    'SELECT ts.*, a.first_name, a.last_name, a.email AS athlete_email,
            ws.name AS set_name
     FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
         WHERE ts.id = ? AND a.coach_id = ?
             AND ts.completed_at IS NOT NULL
             AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
$session = $stmt->fetch();

if (!$session || !$session['athlete_email']) {
    flash('danger', 'Trénink nenalezen nebo sportovec nemá e-mail.');
    redirect(BASE_URL . '/training_detail.php?id=' . $sessionId);
}

$coach     = getCurrentCoach();
$exercises = getSessionExercises($sessionId, (int)$session['workout_set_id']);
$athleteName = $session['first_name'] . ' ' . $session['last_name'];

// Sestavení e-mailu
$subject = 'Tréninkový záznam – ' . $session['set_name'] . ' – ' . formatDate($session['completed_at']);

$body = "Ahoj {$session['first_name']},\n\n";
$body .= "posílám ti záznam z našeho tréninku.\n\n";
$body .= "Datum: " . formatDateTime($session['completed_at']) . "\n";
$body .= "Sada: " . $session['set_name'] . "\n";
if ($session['location']) {
    $body .= "Místo: " . $session['location'] . "\n";
}
$body .= "\n" . str_repeat("─", 40) . "\n\n";

foreach ($exercises as $ex) {
    $series = getSeriesForExercise($sessionId, $ex['exercise_id']);
    $body  .= "CVIK {$ex['exercise_order']}: " . strtoupper($ex['exercise_name']) . "\n";
    $body  .= sprintf("%-5s %-12s %-12s %-10s\n", "#", "Váha (kg)", "Opakování", "Dopomoc");
    foreach ($series as $s) {
        $assist = $s['assistance_reps'] > 0 ? $s['assistance_reps'] : '–';
        $body  .= sprintf("%-5s %-12s %-12s %-10s\n",
            $s['series_order'],
            number_format($s['weight'], 1, ',', '') . ' kg',
            $s['reps'],
            $assist
        );
    }
    $body .= "\n";
}

$body .= str_repeat("─", 40) . "\n";
$body .= "\nS pozdravem,\n";
$body .= ($coach['name'] ?: $coach['username']) . " – Trenér\n";
$body .= "\n---\nZpráva vygenerována aplikací TrainerApp\n";

// Odeslání
$headers = [
    'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
    'Reply-To: ' . MAIL_FROM,
    'X-Mailer: PHP/' . phpversion(),
    'Content-Type: text/plain; charset=UTF-8',
];

$sent = mail(
    $session['athlete_email'],
    '=?UTF-8?B?' . base64_encode($subject) . '?=',
    $body,
    implode("\r\n", $headers)
);

if ($sent) {
    flash('success', "E-mail byl odeslán na {$session['athlete_email']}.");
} else {
    flash('danger', 'E-mail se nepodařilo odeslat. Zkontrolujte nastavení PHP mail() na serveru.');
}

redirect(BASE_URL . '/training_detail.php?id=' . $sessionId);
