<?php

function intakeQuestionTypes(): array {
    return [
        'yes_no' => 'ANO / NE',
        'text' => 'Vlastní text',
        'combined' => 'ANO / NE + volitelný text',
        'single_choice' => 'Výběr jedné možnosti + volitelný text',
        'multiple_choice' => 'Výběr více možností + volitelný text',
    ];
}

function intakeDecodeOptions(?string $json): array {
    if ($json === null || $json === '') return [];
    $options = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($options)) throw new RuntimeException('Možnosti vstupního dotazníku mají neplatný formát.');
    return array_values(array_map('strval', $options));
}

function intakeParseOptions(string $raw, string $questionType): array {
    if (!in_array($questionType, ['single_choice', 'multiple_choice'], true)) return [];
    $options = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $option) {
        $option = trim($option);
        if ($option !== '' && !in_array($option, $options, true)) $options[] = $option;
    }
    if (count($options) < 2) throw new RuntimeException('U výběrové otázky zadejte alespoň dvě různé možnosti.');
    foreach ($options as $option) {
        if (mb_strlen($option) > 255) throw new RuntimeException('Text možnosti může mít nejvýše 255 znaků.');
    }
    return $options;
}

function intakeEnsureDefaultQuestionsTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS default_intake_questions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question_text TEXT NOT NULL,
            question_type ENUM('yes_no','text','combined','single_choice','multiple_choice') NOT NULL DEFAULT 'yes_no',
            options_json TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_default_intake_questions_active (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function intakeEnsureCoachQuestions(PDO $pdo, int $coachId): void {
    intakeEnsureDefaultQuestionsTable($pdo);
    $pdo->beginTransaction();
    try {
        $coach = $pdo->prepare('SELECT id FROM coaches WHERE id = ? FOR UPDATE');
        $coach->execute([$coachId]);
        if (!$coach->fetch()) throw new RuntimeException('Trenér nebyl nalezen.');
        $state = $pdo->prepare('SELECT initialized FROM coach_intake_settings WHERE coach_id = ? FOR UPDATE');
        $state->execute([$coachId]);
        if ($state->fetch()) {
            $pdo->commit();
            return;
        }

        $existing = $pdo->prepare('SELECT COUNT(*) FROM coach_intake_questions WHERE coach_id = ?');
        $existing->execute([$coachId]);
        $hasExistingQuestions = (int)$existing->fetchColumn() > 0;
        if (!$hasExistingQuestions) {
            $template = $pdo->query('SELECT question_text, question_type, options_json, sort_order FROM default_intake_questions WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
            if ($template) {
                $insert = $pdo->prepare('INSERT INTO coach_intake_questions (coach_id, question_text, question_type, options_json, sort_order) VALUES (?, ?, ?, ?, ?)');
                foreach ($template as $question) {
                    $insert->execute([$coachId, $question['question_text'], $question['question_type'], $question['options_json'], $question['sort_order']]);
                }
            }
        }
        if ($hasExistingQuestions || !empty($template)) {
            $mark = $pdo->prepare('INSERT INTO coach_intake_settings (coach_id, initialized) VALUES (?, 1)');
            $mark->execute([$coachId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function intakeQuestions(PDO $pdo, int $coachId): array {
    intakeEnsureCoachQuestions($pdo, $coachId);
    $stmt = $pdo->prepare('SELECT id, question_text, question_type, options_json, sort_order FROM coach_intake_questions WHERE coach_id = ? AND is_active = 1 ORDER BY sort_order, id');
    $stmt->execute([$coachId]);
    $questions = $stmt->fetchAll();
    foreach ($questions as &$question) {
        $question['options'] = intakeDecodeOptions($question['options_json'] ?? null);
    }
    return $questions;
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
