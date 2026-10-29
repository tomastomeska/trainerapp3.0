<?php

function intakeQuestions(PDO $pdo, int $coachId): array {
    $stmt = $pdo->prepare('SELECT id, question_text, question_type, sort_order FROM coach_intake_questions WHERE coach_id = ? AND is_active = 1 ORDER BY sort_order, id');
    $stmt->execute([$coachId]);
    return $stmt->fetchAll();
}

function intakeSubmission(PDO $pdo, int $coachId, int $athleteId): ?array {
    $stmt = $pdo->prepare('SELECT answers_json, submitted_at FROM athlete_intake_submissions WHERE coach_id = ? AND athlete_id = ?');
    $stmt->execute([$coachId, $athleteId]);
    $submission = $stmt->fetch();
    if (!$submission) return null;
    $answers = json_decode((string)$submission['answers_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($answers)) throw new RuntimeException('Uložený vstupní dotazník má neplatný formát.');
    $submission['answers'] = $answers;
    return $submission;
}
