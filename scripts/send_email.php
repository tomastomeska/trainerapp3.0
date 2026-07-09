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

$sent = sendTrainingEmail($session['athlete_email'], $session, $exercises, $coach);

if ($sent) {
    flash('success', 'E-mail byl odeslán na ' . $session['athlete_email'] . '.');
} else {
    flash('danger', 'E-mail se nepodařilo odeslat. Zkontrolujte SMTP nastavení.');
}

redirect(BASE_URL . '/training_detail.php?id=' . $sessionId);

