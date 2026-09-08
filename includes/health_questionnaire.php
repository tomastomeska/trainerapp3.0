<?php

if (!function_exists('healthQuestionnaireEnsureSchema')) {
    function healthQuestionnaireEnsureSchema(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        try {
            $athleteGenderCol = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'gender'")->fetch();
            if (!$athleteGenderCol) {
                $pdo->exec("ALTER TABLE athletes ADD COLUMN gender ENUM('unknown','female','male','other','prefer_not_say') NOT NULL DEFAULT 'unknown' AFTER birth_date");
            }
        } catch (Throwable $e) {
            // Ignore runtime schema change errors.
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
                `ignore_no_issue_options` TINYINT(1) NOT NULL DEFAULT 1,
                `no_issue_values_json` JSON NULL,
                `target_gender` ENUM('all','female','male','other') NOT NULL DEFAULT 'all',
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

        try {
            $ignoreNoIssueCol = $pdo->query("SHOW COLUMNS FROM athlete_health_questionnaire_questions LIKE 'ignore_no_issue_options'")->fetch();
            if (!$ignoreNoIssueCol) {
                $pdo->exec("ALTER TABLE athlete_health_questionnaire_questions ADD COLUMN ignore_no_issue_options TINYINT(1) NOT NULL DEFAULT 1 AFTER alert_text");
            }
        } catch (Throwable $e) {
            // Ignore runtime schema change errors.
        }

        try {
            $noIssueValuesCol = $pdo->query("SHOW COLUMNS FROM athlete_health_questionnaire_questions LIKE 'no_issue_values_json'")->fetch();
            if (!$noIssueValuesCol) {
                $pdo->exec("ALTER TABLE athlete_health_questionnaire_questions ADD COLUMN no_issue_values_json JSON NULL AFTER ignore_no_issue_options");
            }
        } catch (Throwable $e) {
            // Ignore runtime schema change errors.
        }

        try {
            $targetGenderCol = $pdo->query("SHOW COLUMNS FROM athlete_health_questionnaire_questions LIKE 'target_gender'")->fetch();
            if (!$targetGenderCol) {
                $pdo->exec("ALTER TABLE athlete_health_questionnaire_questions ADD COLUMN target_gender ENUM('all','female','male','other') NOT NULL DEFAULT 'all' AFTER no_issue_values_json");
            }
        } catch (Throwable $e) {
            // Ignore runtime schema change errors.
        }

        healthQuestionnaireSeedDefaults($pdo);
        healthQuestionnaireSeedFemaleQuestions($pdo);
        healthQuestionnaireEnsureConsentLast($pdo);
        $ready = true;
    }
}

if (!function_exists('healthQuestionnaireAthleteAccessEnabled')) {
    function healthQuestionnaireAthleteAccessEnabled(): bool
    {
        $raw = trim((string)getAppSetting('athlete_health_questionnaire_enabled', '1'));
        if ($raw === '') {
            return true;
        }

        $normalized = mb_strtolower($raw, 'UTF-8');
        if (in_array($normalized, ['0', 'false', 'off', 'no', 'ne', 'disabled', 'deactivated'], true)) {
            return false;
        }

        return true;
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

            [10, 'Souhlas', 'consent_truth', 'Potvrzuji, že uvedené informace jsou pravdivé a budu trenéra informovat o změnách zdravotního stavu.', 'consent', null, null, 1, null, null, 'none', null, null, 999],
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

if (!function_exists('healthQuestionnaireSeedFemaleQuestions')) {
    function healthQuestionnaireSeedFemaleQuestions(PDO $pdo): void
    {
        $questions = [
            [9, 'Specifické otázky pro ženy', 'female_pregnancy_birth', 'Byla jste těhotná nebo jste rodila?', 'yes_no', null, null, 1, null, null, 'none', null, null, 10],
            [9, 'Specifické otázky pro ženy', 'female_postpartum_complications', 'Měla jste po porodu nějaké komplikace (např. diastáza)?', 'yes_no', null, null, 1, null, null, 'when_yes', null, 'Sportovkyně uvedla komplikace po porodu.', 20],
            [9, 'Specifické otázky pro ženy', 'female_postpartum_complications_details', 'Pokud ano, jaké komplikace?', 'textarea', null, 'Např. diastáza, bolesti, jizva po císařském řezu...', 1, 'female_postpartum_complications', 'ano', 'when_nonempty', null, 'Sportovkyně doplnila komplikace po porodu.', 30],
            [9, 'Specifické otázky pro ženy', 'female_pelvic_floor_issues', 'Máte potíže s pánevním dnem nebo únikem moči?', 'yes_no', null, null, 1, null, null, 'when_yes', null, 'Sportovkyně uvedla potíže s pánevním dnem nebo únikem moči.', 40],
            [9, 'Specifické otázky pro ženy', 'female_menstrual_cycle_issues', 'Máte výrazné problémy během menstruace nebo hormonálního cyklu?', 'yes_no', null, null, 1, null, null, 'when_yes', null, 'Sportovkyně uvedla výrazné problémy během menstruace nebo hormonálního cyklu.', 50],
            [9, 'Specifické otázky pro ženy', 'female_digestion_hunger_swings', 'Máte časté problémy s trávením nebo výrazné výkyvy hladu?', 'yes_no', null, null, 1, null, null, 'when_yes', null, 'Sportovkyně uvedla časté problémy s trávením nebo výrazné výkyvy hladu.', 60],
            [9, 'Specifické otázky pro ženy', 'female_health_other_info', 'Je něco dalšího, co by měl trenér o vašem zdraví vědět?', 'textarea', null, 'Volitelně doplňte další informace pro trenéra', 0, null, null, 'none', null, null, 70],
        ];

        $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM athlete_health_questionnaire_questions WHERE question_key = ?');
        $insertStmt = $pdo->prepare(
            'INSERT INTO athlete_health_questionnaire_questions
            (step_index, section_title, question_key, question_label, input_type, options_json, placeholder, is_required, show_when_question_key, show_when_value, alert_mode, alert_values_json, alert_text, target_gender, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );

        foreach ($questions as $question) {
            $existsStmt->execute([(string)$question[2]]);
            if ((int)$existsStmt->fetchColumn() > 0) {
                continue;
            }

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
                'female',
                (int)$question[13],
            ]);
        }
    }
}

if (!function_exists('healthQuestionnaireEnsureConsentLast')) {
    function healthQuestionnaireEnsureConsentLast(PDO $pdo): void
    {
        try {
            $baseMaxStmt = $pdo->query(
                "SELECT COALESCE(MAX(step_index), 0)
                 FROM athlete_health_questionnaire_questions
                                 WHERE NOT (question_key = 'consent_truth' OR input_type = 'consent' OR question_label LIKE 'Potvrzuji,%')
                   AND question_key NOT LIKE 'female\\_%'"
            );
            $baseMaxStep = $baseMaxStmt ? (int)$baseMaxStmt->fetchColumn() : 0;
            $femaleStep = max(1, $baseMaxStep + 1);
            $consentStep = $femaleStep + 1;

            $consentExistsStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM athlete_health_questionnaire_questions
                 WHERE question_key = ? OR input_type = ? OR question_label LIKE ?"
            );
            $consentExistsStmt->execute(['consent_truth', 'consent', 'Potvrzuji,%']);
            if ((int)$consentExistsStmt->fetchColumn() === 0) {
                $insertConsentStmt = $pdo->prepare(
                    'INSERT INTO athlete_health_questionnaire_questions
                    (step_index, section_title, question_key, question_label, input_type, options_json, placeholder, is_required, show_when_question_key, show_when_value, alert_mode, alert_values_json, alert_text, target_gender, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, NULL, NULL, 1, NULL, NULL, ?, NULL, NULL, ?, 999, 1)'
                );
                $insertConsentStmt->execute([
                    $consentStep,
                    'Souhlas',
                    'consent_truth',
                    'Potvrzuji, že uvedené informace jsou pravdivé a budu trenéra informovat o změnách zdravotního stavu.',
                    'consent',
                    'none',
                    'all',
                ]);
            }

            $femaleStmt = $pdo->prepare(
                "UPDATE athlete_health_questionnaire_questions
                 SET step_index = ?,
                     section_title = 'Specifické otázky pro ženy',
                     updated_at = updated_at
                 WHERE question_key LIKE 'female\\_%'
                   AND target_gender = 'female'
                   AND (step_index <> ? OR section_title <> 'Specifické otázky pro ženy')"
            );
            $femaleStmt->execute([$femaleStep, $femaleStep]);

            $consentStmt = $pdo->prepare(
                "UPDATE athlete_health_questionnaire_questions
                 SET step_index = ?,
                                         sort_order = 999,
                     section_title = 'Souhlas',
                     updated_at = updated_at
                                 WHERE (question_key = 'consent_truth' OR input_type = 'consent' OR question_label LIKE 'Potvrzuji,%')
                                     AND (step_index <> ? OR sort_order <> 999 OR section_title <> 'Souhlas')"
            );
            $consentStmt->execute([$consentStep, $consentStep]);
        } catch (Throwable $e) {
            // Ignore runtime ordering repair errors.
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

if (!function_exists('healthQuestionnaireIsOtherOptionValue')) {
    function healthQuestionnaireIsOtherOptionValue(string $value): bool
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        return in_array($normalized, ['jiné', 'jine', 'jiný', 'jiny', 'other'], true);
    }
}

if (!function_exists('healthQuestionnaireTargetGenderOptions')) {
    function healthQuestionnaireTargetGenderOptions(): array
    {
        return [
            'all' => 'Všichni',
            'female' => 'Pouze ženy',
            'male' => 'Pouze muži',
            'other' => 'Jiné / neuvedeno',
        ];
    }
}

if (!function_exists('healthQuestionnaireNormalizeAthleteGender')) {
    function healthQuestionnaireNormalizeAthleteGender(?string $gender): string
    {
        $gender = trim((string)$gender);
        return in_array($gender, ['female', 'male', 'other'], true) ? $gender : 'other';
    }
}

if (!function_exists('healthQuestionnaireFetchAthleteGender')) {
    function healthQuestionnaireFetchAthleteGender(PDO $pdo, int $athleteId): string
    {
        healthQuestionnaireEnsureSchema($pdo);

        try {
            $stmt = $pdo->prepare('SELECT gender FROM athletes WHERE id = ? LIMIT 1');
            $stmt->execute([$athleteId]);
            $raw = $stmt->fetchColumn();
            return healthQuestionnaireNormalizeAthleteGender($raw !== false ? (string)$raw : null);
        } catch (Throwable $e) {
            return 'other';
        }
    }
}

if (!function_exists('healthQuestionnaireIsNoIssueOptionValue')) {
    function healthQuestionnaireIsNoIssueOptionValue(string $value): bool
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        if ($normalized === '') {
            return false;
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if ($ascii === false || $ascii === null) {
            $ascii = $normalized;
        }
        $ascii = strtolower(trim((string)$ascii));

        $exact = [
            'zadne',
            'zadny problem',
            'zadne problemy',
            'bez omezeni',
            'bez obtizi',
            'bez problemu',
            'bez problemu a bolesti',
            'nemam',
            'nic',
            'none',
            'no issues',
            'no problem',
            'ne',
        ];

        if (in_array($ascii, $exact, true)) {
            return true;
        }

        if (strpos($ascii, 'bez omezen') !== false) {
            return true;
        }
        if (strpos($ascii, 'bez obtiz') !== false) {
            return true;
        }
        if (strpos($ascii, 'zadn') === 0) {
            return true;
        }
        if (strpos($ascii, 'nemam') === 0) {
            return true;
        }

        return false;
    }
}

if (!function_exists('healthQuestionnaireComparableOptionValue')) {
    function healthQuestionnaireComparableOptionValue(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        if ($normalized === '') {
            return '';
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if ($ascii === false || $ascii === null) {
            $ascii = $normalized;
        }

        $ascii = strtolower(trim((string)$ascii));
        return preg_replace('/\s+/', ' ', $ascii) ?? $ascii;
    }
}

if (!function_exists('healthQuestionnaireShouldIgnoreValueForQuestion')) {
    function healthQuestionnaireShouldIgnoreValueForQuestion(string $value, array $question): bool
    {
        if ((int)($question['ignore_no_issue_options'] ?? 1) !== 1) {
            return false;
        }

        if (healthQuestionnaireIsNoIssueOptionValue($value)) {
            return true;
        }

        $configured = is_array($question['no_issue_values'] ?? null) ? $question['no_issue_values'] : [];
        if (empty($configured)) {
            return false;
        }

        $valueComparable = healthQuestionnaireComparableOptionValue($value);
        if ($valueComparable === '') {
            return false;
        }

        foreach ($configured as $candidate) {
            if ($valueComparable === healthQuestionnaireComparableOptionValue((string)$candidate)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('healthQuestionnaireQuestionHasOtherOption')) {
    function healthQuestionnaireQuestionHasOtherOption(array $question): bool
    {
        $options = (array)($question['options'] ?? []);
        foreach ($options as $optionKey => $optionLabel) {
            $value = is_string($optionKey) ? $optionKey : (string)$optionLabel;
            $label = is_string($optionLabel) ? $optionLabel : (string)$optionKey;
            if (healthQuestionnaireIsOtherOptionValue($value) || healthQuestionnaireIsOtherOptionValue($label)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('healthQuestionnaireFetchQuestions')) {
    function healthQuestionnaireFetchQuestions(PDO $pdo, bool $activeOnly = true, ?string $targetGender = null): array
    {
        healthQuestionnaireEnsureSchema($pdo);

        $sql = 'SELECT * FROM athlete_health_questionnaire_questions';
        $where = [];
        $params = [];
        if ($activeOnly) {
            $where[] = 'is_active = 1';
        }
        if ($targetGender !== null) {
            $normalizedTargetGender = healthQuestionnaireNormalizeAthleteGender($targetGender);
            $where[] = '(target_gender = ? OR target_gender = ?)';
            $params[] = 'all';
            $params[] = $normalizedTargetGender;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY step_index ASC, sort_order ASC, id ASC';

        if (!empty($params)) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } else {
            $rows = $pdo->query($sql)->fetchAll();
        }
        foreach ($rows as &$row) {
            $row['options'] = [];
            $row['alert_values'] = [];
            $row['no_issue_values'] = [];
            $row['ignore_no_issue_options'] = (int)($row['ignore_no_issue_options'] ?? 1);
            $row['target_gender'] = array_key_exists((string)($row['target_gender'] ?? 'all'), healthQuestionnaireTargetGenderOptions())
                ? (string)$row['target_gender']
                : 'all';

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

            $noIssueValuesRaw = trim((string)($row['no_issue_values_json'] ?? ''));
            if ($noIssueValuesRaw !== '') {
                $decodedNoIssueValues = json_decode($noIssueValuesRaw, true);
                if (is_array($decodedNoIssueValues)) {
                    $row['no_issue_values'] = $decodedNoIssueValues;
                }
            }
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('healthQuestionnaireFetchQuestionsForAthlete')) {
    function healthQuestionnaireFetchQuestionsForAthlete(PDO $pdo, int $athleteId, bool $activeOnly = true): array
    {
        return healthQuestionnaireFetchQuestions($pdo, $activeOnly, healthQuestionnaireFetchAthleteGender($pdo, $athleteId));
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
                        if ($itemValue === '') {
                            continue;
                    }

                        $parts = preg_split('/[\r\n,;]+/', $itemValue) ?: [];
                        if (empty($parts)) {
                            $parts = [$itemValue];
                        }

                        foreach ($parts as $part) {
                            $part = trim((string)$part);
                            if ($part === '' || in_array($part, $values, true)) {
                                continue;
                            }
                            $values[] = $part;
                        }
                }
                $answers[$key] = $values;

                if (healthQuestionnaireQuestionHasOtherOption($question)) {
                    $answers[$key . '_other_reason'] = trim((string)($post[$key . '_other_reason'] ?? ''));
                }

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

            $key = (string)($question['question_key'] ?? '');
            $label = (string)($question['question_label'] ?? $key);
            $value = $answers[$key] ?? null;
            $inputType = (string)($question['input_type'] ?? '');

            $required = (int)($question['is_required'] ?? 0) === 1;
            if ($required) {
                // Failsafe: pokud je single/multi bez nabídky možností, nemá blokovat odeslání.
                if (in_array($inputType, ['single', 'multi'], true)) {
                    $options = is_array($question['options'] ?? null) ? $question['options'] : [];
                    if (empty($options)) {
                        continue;
                    }
                }

                if (is_array($value) && count($value) === 0) {
                    $errors[] = 'Vyplňte otázku: ' . $label;
                    continue;
                }

                if (!is_array($value) && trim((string)$value) === '') {
                    $errors[] = 'Vyplňte otázku: ' . $label;
                }

                if ($inputType === 'consent' && ($answers[$key] ?? '') !== 'ano') {
                    $errors[] = 'Je nutné potvrdit souhlas se zpracováním informací.';
                }
            }

            if ($inputType === 'multi' && healthQuestionnaireQuestionHasOtherOption($question)) {
                $selectedValues = is_array($answers[$key] ?? null) ? $answers[$key] : [];
                $otherSelected = false;
                foreach ($selectedValues as $selectedValue) {
                    if (healthQuestionnaireIsOtherOptionValue((string)$selectedValue)) {
                        $otherSelected = true;
                        break;
                    }
                }

                if ($otherSelected) {
                    $otherReason = trim((string)($answers[$key . '_other_reason'] ?? ''));
                    if ($otherReason === '') {
                        $errors[] = 'U otázky "' . $label . '" doplňte důvod pro volbu Jiné/Jiný.';
                    }
                }
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
                if (is_array($value)) {
                    $meaningfulValues = array_values(array_filter($value, function ($item) use ($question): bool {
                        return !healthQuestionnaireShouldIgnoreValueForQuestion((string)$item, $question);
                    }));
                    $trigger = count($meaningfulValues) > 0;
                } else {
                    $textValue = trim((string)$value);
                    $trigger = $textValue !== '' && !healthQuestionnaireShouldIgnoreValueForQuestion($textValue, $question);
                }
            } elseif ($mode === 'when_selected') {
                $alertValues = is_array($question['alert_values'] ?? null) ? $question['alert_values'] : [];
                if (is_array($value)) {
                    $selectedValues = array_values(array_filter($value, function ($item) use ($question): bool {
                        return !healthQuestionnaireShouldIgnoreValueForQuestion((string)$item, $question);
                    }));
                    $filteredAlertValues = array_values(array_filter($alertValues, function ($item) use ($question): bool {
                        return !healthQuestionnaireShouldIgnoreValueForQuestion((string)$item, $question);
                    }));
                    $trigger = count(array_intersect($selectedValues, $filteredAlertValues)) > 0;
                } else {
                    $singleValue = trim((string)$value);
                    if (!healthQuestionnaireShouldIgnoreValueForQuestion($singleValue, $question)) {
                        $filteredAlertValues = array_values(array_filter($alertValues, function ($item) use ($question): bool {
                            return !healthQuestionnaireShouldIgnoreValueForQuestion((string)$item, $question);
                        }));
                        $trigger = in_array($singleValue, $filteredAlertValues, true);
                    }
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

        // Recompute alert summary from current rules so neutral choices (e.g. "žádné") do not create false warnings.
        try {
            $activeQuestions = healthQuestionnaireFetchQuestionsForAthlete($pdo, $athleteId, true);
            if (!empty($activeQuestions)) {
                $evaluation = healthQuestionnaireEvaluateAlerts($activeQuestions, $row['answers']);
                $row['alerts'] = is_array($evaluation['alerts'] ?? null) ? $evaluation['alerts'] : [];
                $row['alert_count'] = (int)($evaluation['alert_count'] ?? 0);
                $row['status_flag'] = (string)($evaluation['status_flag'] ?? 'ok');
            }
        } catch (Throwable $e) {
            // Keep stored values if runtime recompute fails.
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
        $athleteGender = healthQuestionnaireFetchAthleteGender($pdo, $athleteId);
        $activeQuestionsUpdatedAt = null;

        try {
            $questionsUpdatedStmt = $pdo->prepare(
                'SELECT MAX(updated_at)
                 FROM athlete_health_questionnaire_questions
                 WHERE is_active = 1
                   AND (target_gender = ? OR target_gender = ?)'
            );
            $questionsUpdatedStmt->execute(['all', $athleteGender]);
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
