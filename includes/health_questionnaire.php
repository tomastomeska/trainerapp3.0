<?php

if (!function_exists('healthQuestionnaireEnsureSchema')) {
    function healthQuestionnaireEnsureSchema(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $pdo->exec(" 
            CREATE TABLE IF NOT EXISTS `athlete_health_questionnaire_questions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `step_index` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `section_title` VARCHAR(120) NOT NULL,
                `question_key` VARCHAR(80) NOT NULL,
                `question_label` VARCHAR(255) NOT NULL,
                `input_type` ENUM('yes_no','single','multi','text','textarea','number','date','consent') NOT NULL DEFAULT 'text',
                `options_json` JSON NULL,
                `placeholder` VARCHAR(255) NULL,
                `is_required` TINYINT(1) NOT NULL DEFAULT 0,
                `show_when_question_key` VARCHAR(80) NULL,
                `show_when_value` VARCHAR(120) NULL,
                `alert_mode` ENUM('none','when_yes','when_no','when_nonempty','when_selected') NOT NULL DEFAULT 'none',
                `alert_values_json` JSON NULL,
                `alert_text` VARCHAR(255) NULL,
                `sort_order` INT NOT NULL DEFAULT 100,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_health_question_key` (`question_key`),
                KEY `idx_health_questions_step` (`step_index`, `sort_order`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec(" 
            CREATE TABLE IF NOT EXISTS `athlete_health_questionnaire_submissions` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `athlete_id` INT NOT NULL,
                `coach_id` INT NOT NULL,
                `answers_json` LONGTEXT NOT NULL,
                `alerts_json` LONGTEXT NULL,
                `alert_count` INT NOT NULL DEFAULT 0,
                `status_flag` ENUM('ok','warning') NOT NULL DEFAULT 'ok',
                `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_health_submissions_athlete` (`athlete_id`, `submitted_at`),
                KEY `idx_health_submissions_coach` (`coach_id`, `submitted_at`),
                CONSTRAINT `fk_health_submission_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_health_submission_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec(" 
            CREATE TABLE IF NOT EXISTS `athlete_health_status_updates` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `athlete_id` INT NOT NULL,
                `coach_id` INT NOT NULL,
                `change_category` VARCHAR(80) NOT NULL,
                `change_details` TEXT NOT NULL,
                `effective_date` DATE NULL,
                `severity` ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
                `coach_seen_at` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_health_updates_athlete` (`athlete_id`, `created_at`),
                KEY `idx_health_updates_coach_seen` (`coach_id`, `coach_seen_at`),
                CONSTRAINT `fk_health_update_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_health_update_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        healthQuestionnaireSeedDefaults($pdo);
        $ready = true;
    }
}

if (!function_exists('healthQuestionnaireDefaultQuestions')) {
    function healthQuestionnaireDefaultQuestions(): array
    {
        return [
            [1, 'Osobní údaje', 'fill_date', 'Datum vyplnění', 'date', null, null, 1, null, null, 'none', null, null, 10],
            [1, 'Osobní údaje', 'height_cm', 'Výška (cm)', 'number', null, 'Např. 178', 1, null, null, 'none', null, null, 20],
            [1, 'Osobní údaje', 'weight_kg', 'Hmotnost (kg)', 'number', null, 'Např. 78.5', 1, null, null, 'none', null, null, 30],

            [2, 'Zdravotní stav', 'health_limitation', 'Máte nějaké zdravotní omezení?', 'yes_no', null, null, 1, null, null, 'when_yes', null, 'Sportovec uvedl zdravotní omezení.', 10],
            [2, 'Zdravotní stav', 'health_limitation_details', 'Pokud ano, uveďte omezení', 'textarea', null, 'Popište omezení', 0, 'health_limitation', 'ano', 'when_nonempty', null, 'Sportovec doplnil zdravotní omezení.', 20],
            [2, 'Zdravotní stav', 'diseases', 'Trpíte některým z následujících onemocnění?', 'multi', [
                'Vysoký krevní tlak',
                'Nízký krevní tlak',
                'Cukrovka',
                'Astma',
                'Srdeční onemocnění',
                'Epilepsie',
                'Onemocnění kloubů',
                'Onemocnění páteře',
                'Jiné',
            ], null, 0, null, null, 'when_selected', [
                'Vysoký krevní tlak',
                'Nízký krevní tlak',
                'Cukrovka',
                'Astma',
                'Srdeční onemocnění',
                'Epilepsie',
                'Onemocnění kloubů',
                'Onemocnění páteře',
                'Jiné',
            ], 'Sportovec uvedl onemocnění důležité pro trénink.', 30],

            [3, 'Zranění a bolesti', 'injury_recent', 'Prodělali jste v posledních 2 letech vážnější zranění?', 'yes_no', null, null, 1, null, null, 'when_yes', null, 'Sportovec uvedl vážnější zranění.', 10],
            [3, 'Zranění a bolesti', 'injury_area', 'Pokud ano: oblast těla', 'text', null, 'Např. koleno', 0, 'injury_recent', 'ano', 'none', null, null, 20],
            [3, 'Zranění a bolesti', 'injury_year', 'Pokud ano: rok zranění', 'number', null, 'Např. 2025', 0, 'injury_recent', 'ano', 'none', null, null, 30],
            [3, 'Zranění a bolesti', 'injury_still_limits', 'Pokud ano: stále omezuje pohyb?', 'yes_no', null, null, 0, 'injury_recent', 'ano', 'when_yes', null, 'Dřívější zranění stále omezuje pohyb.', 40],
            [3, 'Zranění a bolesti', 'pain_areas', 'Pociťujete pravidelně bolesti?', 'multi', [
                'Krční páteř',
                'Ramena',
                'Lokty',
                'Zápěstí',
                'Bederní páteř',
                'Kyčle',
                'Kolena',
                'Kotníky',
                'Jiné',
                'Bez obtíží',
            ], null, 0, null, null, 'when_selected', [
                'Krční páteř',
                'Ramena',
                'Lokty',
                'Zápěstí',
                'Bederní páteř',
                'Kyčle',
                'Kolena',
                'Kotníky',
                'Jiné',
            ], 'Sportovec uvedl pravidelné bolesti.', 50],

            [4, 'Léky a alergie', 'regular_meds', 'Užíváte pravidelně nějaké léky?', 'yes_no', null, null, 1, null, null, 'when_yes', null, 'Sportovec užívá pravidelně léky.', 10],
            [4, 'Léky a alergie', 'regular_meds_details', 'Pokud ano, jaké léky?', 'textarea', null, 'Napište název léků', 0, 'regular_meds', 'ano', 'when_nonempty', null, 'Sportovec doplnil seznam léků.', 20],
            [4, 'Léky a alergie', 'allergies', 'Máte alergie důležité pro sport?', 'yes_no', null, null, 1, null, null, 'when_yes', null, 'Sportovec uvedl alergie.', 30],
            [4, 'Léky a alergie', 'allergies_details', 'Pokud ano, uveďte alergie', 'textarea', null, 'Popis alergií', 0, 'allergies', 'ano', 'when_nonempty', null, 'Sportovec doplnil alergie.', 40],

            [5, 'Pohybová omezení', 'painfree_squat', 'Dokážete bez bolesti dřepnout si?', 'yes_no', null, null, 1, null, null, 'when_no', null, 'Sportovec nedokáže bez bolesti dřep.', 10],
            [5, 'Pohybová omezení', 'painfree_hands_up', 'Dokážete bez bolesti zvednout ruce nad hlavu?', 'yes_no', null, null, 1, null, null, 'when_no', null, 'Sportovec nedokáže bez bolesti zvednout ruce nad hlavu.', 20],
            [5, 'Pohybová omezení', 'painfree_toes', 'Dokážete bez bolesti dotknout se špiček nohou?', 'yes_no', null, null, 1, null, null, 'when_no', null, 'Sportovec nedokáže bez bolesti dotknout se špiček nohou.', 30],

            [6, 'Sportovní zkušenosti', 'training_experience', 'Jak dlouho pravidelně cvičíte?', 'single', [
                'Začátečník',
                'Do 1 roku',
                '1-3 roky',
                'Více než 3 roky',
            ], null, 1, null, null, 'none', null, null, 10],

            [7, 'Cíl spolupráce', 'main_goal', 'Co je vaším hlavním cílem?', 'single', [
                'Hubnutí',
                'Nabírání svalů',
                'Síla',
                'Kondice',
                'HYROX',
                'Běh',
                'Rehabilitace',
                'Jiný',
            ], null, 1, null, null, 'none', null, null, 10],
            [7, 'Cíl spolupráce', 'main_goal_other', 'Pokud Jiný, doplňte cíl', 'text', null, 'Vlastní cíl', 0, 'main_goal', 'Jiný', 'none', null, null, 20],

            [8, 'Souhlas', 'consent_truth', 'Potvrzuji, že uvedené informace jsou pravdivé a budu trenéra informovat o změnách zdravotního stavu.', 'consent', null, null, 1, null, null, 'none', null, null, 10],
        ];
    }
}

if (!function_exists('healthQuestionnaireSeedDefaults')) {
    function healthQuestionnaireSeedDefaults(PDO $pdo): void
    {
        $count = 0;
        try {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM athlete_health_questionnaire_questions')->fetchColumn();
        } catch (Throwable $e) {
            $count = 0;
        }

        if ($count > 0) {
            return;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO athlete_health_questionnaire_questions
            (step_index, section_title, question_key, question_label, input_type, options_json, placeholder, is_required, show_when_question_key, show_when_value, alert_mode, alert_values_json, alert_text, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );

        foreach (healthQuestionnaireDefaultQuestions() as $question) {
            $insertStmt->execute([
                (int)$question[0],
                (string)$question[1],
                (string)$question[2],
                (string)$question[3],
                (string)$question[4],
                $question[5] !== null ? json_encode($question[5], JSON_UNESCAPED_UNICODE) : null,
                $question[6],
                (int)$question[7],
                $question[8],
                $question[9],
                (string)$question[10],
                $question[11] !== null ? json_encode($question[11], JSON_UNESCAPED_UNICODE) : null,
                $question[12],
                (int)$question[13],
            ]);
        }
    }
}

if (!function_exists('healthQuestionnaireNormalizeYesNo')) {
    function healthQuestionnaireNormalizeYesNo(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        if (in_array($value, ['ano', 'yes', '1', 'true'], true)) {
            return 'ano';
        }
        if (in_array($value, ['ne', 'no', '0', 'false'], true)) {
            return 'ne';
        }
        return '';
    }
}

if (!function_exists('healthQuestionnaireFetchQuestions')) {
    function healthQuestionnaireFetchQuestions(PDO $pdo, bool $activeOnly = true): array
    {
        healthQuestionnaireEnsureSchema($pdo);

        $sql = 'SELECT * FROM athlete_health_questionnaire_questions';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY step_index ASC, sort_order ASC, id ASC';

        $rows = $pdo->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            $row['options'] = [];
            $row['alert_values'] = [];

            $optionsRaw = trim((string)($row['options_json'] ?? ''));
            if ($optionsRaw !== '') {
                $decodedOptions = json_decode($optionsRaw, true);
                if (is_array($decodedOptions)) {
                    $row['options'] = $decodedOptions;
                }
            }

            $alertValuesRaw = trim((string)($row['alert_values_json'] ?? ''));
            if ($alertValuesRaw !== '') {
                $decodedAlertValues = json_decode($alertValuesRaw, true);
                if (is_array($decodedAlertValues)) {
                    $row['alert_values'] = $decodedAlertValues;
                }
            }
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('healthQuestionnaireGroupBySteps')) {
    function healthQuestionnaireGroupBySteps(array $questions): array
    {
        $steps = [];
        foreach ($questions as $question) {
            $step = (int)($question['step_index'] ?? 1);
            if (!isset($steps[$step])) {
                $steps[$step] = [
                    'step_index' => $step,
                    'section_title' => (string)($question['section_title'] ?? ''),
                    'questions' => [],
                ];
            }
            $steps[$step]['questions'][] = $question;
        }
        ksort($steps);
        return array_values($steps);
    }
}

if (!function_exists('healthQuestionnaireCollectAnswersFromPost')) {
    function healthQuestionnaireCollectAnswersFromPost(array $questions, array $post): array
    {
        $answers = [];
        foreach ($questions as $question) {
            $key = (string)($question['question_key'] ?? '');
            if ($key === '') {
                continue;
            }

            $type = (string)($question['input_type'] ?? 'text');
            if ($type === 'multi') {
                $raw = $post[$key] ?? [];
                $rawList = is_array($raw) ? $raw : [$raw];
                $values = [];
                foreach ($rawList as $item) {
                    $itemValue = trim((string)$item);
                    if ($itemValue === '' || in_array($itemValue, $values, true)) {
                        continue;
                    }
                    $values[] = $itemValue;
                }
                $answers[$key] = $values;
                continue;
            }

            $rawValue = trim((string)($post[$key] ?? ''));
            if ($type === 'yes_no' || $type === 'consent') {
                $answers[$key] = healthQuestionnaireNormalizeYesNo($rawValue);
            } else {
                $answers[$key] = $rawValue;
            }
        }
        return $answers;
    }
}

if (!function_exists('healthQuestionnaireQuestionVisibleForAnswers')) {
    function healthQuestionnaireQuestionVisibleForAnswers(array $question, array $answers): bool
    {
        $dependsOn = trim((string)($question['show_when_question_key'] ?? ''));
        if ($dependsOn === '') {
            return true;
        }

        $expected = trim((string)($question['show_when_value'] ?? ''));
        $actual = $answers[$dependsOn] ?? null;

        if (is_array($actual)) {
            return $expected !== '' && in_array($expected, $actual, true);
        }

        return trim((string)$actual) === $expected;
    }
}

if (!function_exists('healthQuestionnaireValidateAnswers')) {
    function healthQuestionnaireValidateAnswers(array $questions, array $answers): array
    {
        $errors = [];
        foreach ($questions as $question) {
            if (!healthQuestionnaireQuestionVisibleForAnswers($question, $answers)) {
                continue;
            }

            $required = (int)($question['is_required'] ?? 0) === 1;
            if (!$required) {
                continue;
            }

            $key = (string)($question['question_key'] ?? '');
            $label = (string)($question['question_label'] ?? $key);
            $value = $answers[$key] ?? null;

            if (is_array($value) && count($value) === 0) {
                $errors[] = 'Vyplňte otázku: ' . $label;
                continue;
            }

            if (!is_array($value) && trim((string)$value) === '') {
                $errors[] = 'Vyplňte otázku: ' . $label;
            }

            if ((string)($question['input_type'] ?? '') === 'consent' && ($answers[$key] ?? '') !== 'ano') {
                $errors[] = 'Je nutné potvrdit souhlas se zpracováním informací.';
            }
        }

        return $errors;
    }
}

if (!function_exists('healthQuestionnaireEvaluateAlerts')) {
    function healthQuestionnaireEvaluateAlerts(array $questions, array $answers): array
    {
        $alerts = [];

        foreach ($questions as $question) {
            if (!healthQuestionnaireQuestionVisibleForAnswers($question, $answers)) {
                continue;
            }

            $mode = (string)($question['alert_mode'] ?? 'none');
            if ($mode === 'none') {
                continue;
            }

            $key = (string)($question['question_key'] ?? '');
            $value = $answers[$key] ?? null;
            $trigger = false;

            if ($mode === 'when_yes') {
                $trigger = trim((string)$value) === 'ano';
            } elseif ($mode === 'when_no') {
                $trigger = trim((string)$value) === 'ne';
            } elseif ($mode === 'when_nonempty') {
                $trigger = is_array($value) ? count($value) > 0 : trim((string)$value) !== '';
            } elseif ($mode === 'when_selected') {
                $alertValues = is_array($question['alert_values'] ?? null) ? $question['alert_values'] : [];
                if (is_array($value)) {
                    $trigger = count(array_intersect($value, $alertValues)) > 0;
                } else {
                    $trigger = in_array(trim((string)$value), $alertValues, true);
                }
            }

            if ($trigger) {
                $alerts[] = [
                    'question_key' => $key,
                    'question_label' => (string)($question['question_label'] ?? $key),
                    'alert_text' => trim((string)($question['alert_text'] ?? '')) !== ''
                        ? (string)$question['alert_text']
                        : (string)($question['question_label'] ?? $key),
                ];
            }
        }

        return [
            'alerts' => $alerts,
            'alert_count' => count($alerts),
            'status_flag' => count($alerts) > 0 ? 'warning' : 'ok',
        ];
    }
}

if (!function_exists('healthQuestionnaireSaveSubmission')) {
    function healthQuestionnaireSaveSubmission(PDO $pdo, int $athleteId, int $coachId, array $answers, array $evaluation): bool
    {
        healthQuestionnaireEnsureSchema($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO athlete_health_questionnaire_submissions
            (athlete_id, coach_id, answers_json, alerts_json, alert_count, status_flag, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );

        return $stmt->execute([
            $athleteId,
            $coachId,
            json_encode($answers, JSON_UNESCAPED_UNICODE),
            json_encode($evaluation['alerts'] ?? [], JSON_UNESCAPED_UNICODE),
            (int)($evaluation['alert_count'] ?? 0),
            (string)($evaluation['status_flag'] ?? 'ok'),
        ]);
    }
}

if (!function_exists('healthQuestionnaireFetchLatestSubmission')) {
    function healthQuestionnaireFetchLatestSubmission(PDO $pdo, int $athleteId): ?array
    {
        healthQuestionnaireEnsureSchema($pdo);

        $stmt = $pdo->prepare(
            'SELECT *
             FROM athlete_health_questionnaire_submissions
             WHERE athlete_id = ?
             ORDER BY submitted_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$athleteId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['answers'] = [];
        $row['alerts'] = [];

        $answers = json_decode((string)($row['answers_json'] ?? '{}'), true);
        if (is_array($answers)) {
            $row['answers'] = $answers;
        }

        $alerts = json_decode((string)($row['alerts_json'] ?? '[]'), true);
        if (is_array($alerts)) {
            $row['alerts'] = $alerts;
        }

        return $row;
    }
}

if (!function_exists('healthQuestionnaireCreateUpdate')) {
    function healthQuestionnaireCreateUpdate(PDO $pdo, int $athleteId, int $coachId, string $category, string $details, ?string $effectiveDate = null, string $severity = 'warning'): bool
    {
        healthQuestionnaireEnsureSchema($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO athlete_health_status_updates
            (athlete_id, coach_id, change_category, change_details, effective_date, severity)
            VALUES (?, ?, ?, ?, ?, ?)'
        );

        return $stmt->execute([
            $athleteId,
            $coachId,
            $category,
            $details,
            $effectiveDate !== '' ? $effectiveDate : null,
            in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'warning',
        ]);
    }
}

if (!function_exists('healthQuestionnaireFetchUpdatesForCoach')) {
    function healthQuestionnaireFetchUpdatesForCoach(PDO $pdo, int $athleteId, int $limit = 20): array
    {
        healthQuestionnaireEnsureSchema($pdo);

        $limit = max(1, min(100, $limit));
        $stmt = $pdo->prepare(
            'SELECT *
             FROM athlete_health_status_updates
             WHERE athlete_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$athleteId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('healthQuestionnaireFetchUpdatesForAthlete')) {
    function healthQuestionnaireFetchUpdatesForAthlete(PDO $pdo, int $athleteId, int $coachId, int $limit = 20): array
    {
        healthQuestionnaireEnsureSchema($pdo);

        $limit = max(1, min(100, $limit));
        $stmt = $pdo->prepare(
            'SELECT *
             FROM athlete_health_status_updates
             WHERE athlete_id = ?
               AND coach_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$athleteId, $coachId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('healthQuestionnaireUpdateAthleteUpdate')) {
    function healthQuestionnaireUpdateAthleteUpdate(
        PDO $pdo,
        int $updateId,
        int $athleteId,
        int $coachId,
        string $category,
        string $details,
        ?string $effectiveDate,
        string $severity
    ): bool {
        healthQuestionnaireEnsureSchema($pdo);

        $stmt = $pdo->prepare(
            'UPDATE athlete_health_status_updates
             SET change_category = ?,
                 change_details = ?,
                 effective_date = ?,
                 severity = ?,
                 coach_seen_at = NULL
             WHERE id = ?
               AND athlete_id = ?
               AND coach_id = ?'
        );

        return $stmt->execute([
            $category,
            $details,
            $effectiveDate !== '' ? $effectiveDate : null,
            in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'warning',
            $updateId,
            $athleteId,
            $coachId,
        ]);
    }
}

if (!function_exists('healthQuestionnaireDeleteAthleteUpdate')) {
    function healthQuestionnaireDeleteAthleteUpdate(PDO $pdo, int $updateId, int $athleteId, int $coachId): bool
    {
        healthQuestionnaireEnsureSchema($pdo);

        $stmt = $pdo->prepare(
            'DELETE FROM athlete_health_status_updates
             WHERE id = ?
               AND athlete_id = ?
               AND coach_id = ?'
        );

        $stmt->execute([$updateId, $athleteId, $coachId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('healthQuestionnaireMarkUpdatesSeen')) {
    function healthQuestionnaireMarkUpdatesSeen(PDO $pdo, int $athleteId, int $coachId): void
    {
        healthQuestionnaireEnsureSchema($pdo);

        $stmt = $pdo->prepare(
            'UPDATE athlete_health_status_updates
             SET coach_seen_at = NOW()
             WHERE athlete_id = ?
               AND coach_id = ?
               AND coach_seen_at IS NULL'
        );
        $stmt->execute([$athleteId, $coachId]);
    }
}

if (!function_exists('healthQuestionnaireFetchStatus')) {
    function healthQuestionnaireFetchStatus(PDO $pdo, int $athleteId): array
    {
        healthQuestionnaireEnsureSchema($pdo);

        $latest = healthQuestionnaireFetchLatestSubmission($pdo, $athleteId);
        $activeQuestionsUpdatedAt = null;

        try {
            $questionsUpdatedStmt = $pdo->query(
                'SELECT MAX(updated_at)
                 FROM athlete_health_questionnaire_questions
                 WHERE is_active = 1'
            );
            $activeQuestionsUpdatedAtRaw = $questionsUpdatedStmt ? $questionsUpdatedStmt->fetchColumn() : null;
            if ($activeQuestionsUpdatedAtRaw !== false && $activeQuestionsUpdatedAtRaw !== null && trim((string)$activeQuestionsUpdatedAtRaw) !== '') {
                $activeQuestionsUpdatedAt = (string)$activeQuestionsUpdatedAtRaw;
            }
        } catch (Throwable $e) {
            $activeQuestionsUpdatedAt = null;
        }

        $pendingUpdates = 0;
        try {
            $pendingStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM athlete_health_status_updates
                 WHERE athlete_id = ?
                   AND coach_seen_at IS NULL'
            );
            $pendingStmt->execute([$athleteId]);
            $pendingUpdates = (int)$pendingStmt->fetchColumn();
        } catch (Throwable $e) {
            $pendingUpdates = 0;
        }

        if ($latest === null) {
            return [
                'state' => 'missing',
                'label' => 'Zdravotní dotazník není vyplněn',
                'filled_at' => null,
                'alerts' => [],
                'alert_count' => 0,
                'pending_updates' => $pendingUpdates,
                'needs_refresh' => false,
                'questions_updated_at' => $activeQuestionsUpdatedAt,
            ];
        }

        $state = (string)($latest['status_flag'] ?? 'ok') === 'warning' ? 'warning' : 'ok';
        $label = $state === 'ok'
            ? 'Zdravotní dotazník vyplněn, vše je v pořádku.'
            : 'Zdravotní dotazník obsahuje omezení.';
        $needsRefresh = false;

        if ($activeQuestionsUpdatedAt !== null && !empty($latest['submitted_at'])) {
            $questionTs = strtotime($activeQuestionsUpdatedAt);
            $submissionTs = strtotime((string)$latest['submitted_at']);
            if ($questionTs !== false && $submissionTs !== false && $questionTs > $submissionTs) {
                $needsRefresh = true;
                $state = 'warning';
                $label = 'Dotazník byl upraven administrátorem. Pro úplnost ho prosím aktualizujte.';
            }
        }

        if ($pendingUpdates > 0) {
            $state = 'warning';
            $label .= ' Nové změny čekají na kontrolu trenéra.';
        }

        return [
            'state' => $state,
            'label' => $label,
            'filled_at' => (string)($latest['submitted_at'] ?? ''),
            'alerts' => is_array($latest['alerts'] ?? null) ? $latest['alerts'] : [],
            'alert_count' => (int)($latest['alert_count'] ?? 0),
            'pending_updates' => $pendingUpdates,
            'needs_refresh' => $needsRefresh,
            'questions_updated_at' => $activeQuestionsUpdatedAt,
        ];
    }
}

if (!function_exists('healthQuestionnaireInputTypeOptions')) {
    function healthQuestionnaireInputTypeOptions(): array
    {
        return [
            'yes_no' => 'Ano / Ne',
            'single' => 'Jedna volba',
            'multi' => 'Více voleb',
            'text' => 'Krátký text',
            'textarea' => 'Delší text',
            'number' => 'Číslo',
            'date' => 'Datum',
            'consent' => 'Souhlas',
        ];
    }
}

if (!function_exists('healthQuestionnaireAlertModeOptions')) {
    function healthQuestionnaireAlertModeOptions(): array
    {
        return [
            'none' => 'Bez upozornění',
            'when_yes' => 'Upozornit při ANO',
            'when_no' => 'Upozornit při NE',
            'when_nonempty' => 'Upozornit při vyplnění hodnoty',
            'when_selected' => 'Upozornit při vybraných možnostech',
        ];
    }
}
