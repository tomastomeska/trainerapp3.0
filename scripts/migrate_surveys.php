<?php
$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    requireAdminLogin();
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    $statements = [
        "CREATE TABLE IF NOT EXISTS surveys (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            survey_type ENUM('poll','questionnaire') NOT NULL DEFAULT 'poll',
            audience ENUM('all','athletes','coaches') NOT NULL DEFAULT 'all',
            status ENUM('draft','published','ended') NOT NULL DEFAULT 'draft',
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            published_at DATETIME NULL,
            ended_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_surveys_status_audience (status, audience),
            INDEX idx_surveys_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS survey_questions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            survey_id INT UNSIGNED NOT NULL,
            question_text TEXT NOT NULL,
            question_type ENUM('single_choice','multiple_choice','text','textarea') NOT NULL DEFAULT 'single_choice',
            sort_order INT NOT NULL DEFAULT 0,
            CONSTRAINT fk_survey_questions_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE,
            INDEX idx_survey_questions_survey (survey_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS survey_options (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question_id INT UNSIGNED NOT NULL,
            option_text VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            CONSTRAINT fk_survey_options_question FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE,
            INDEX idx_survey_options_question (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS survey_responses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            survey_id INT UNSIGNED NOT NULL,
            user_type ENUM('athlete','coach') NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_survey_response_user (survey_id, user_type, user_id),
            CONSTRAINT fk_survey_responses_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE,
            INDEX idx_survey_responses_user (user_type, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS survey_answers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            response_id BIGINT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NOT NULL,
            option_id INT UNSIGNED NULL,
            answer_text TEXT NULL,
            CONSTRAINT fk_survey_answers_response FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
            CONSTRAINT fk_survey_answers_question FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE,
            CONSTRAINT fk_survey_answers_option FOREIGN KEY (option_id) REFERENCES survey_options(id) ON DELETE SET NULL,
            INDEX idx_survey_answer_question (response_id, question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    // Upgrade existing survey installations for questions with multiple choices.
    try {
        $pdo->exec("ALTER TABLE survey_questions MODIFY question_type ENUM('single_choice','multiple_choice','text','textarea') NOT NULL DEFAULT 'single_choice'");
    } catch (Throwable $e) {
        // Fresh installations are already created with the extended enum.
    }
    $indexRows = $pdo->query('SHOW INDEX FROM survey_answers')->fetchAll();
    foreach ($indexRows as $indexRow) {
        $indexName = (string)($indexRow['Key_name'] ?? '');
        if ($indexName === '' || $indexName === 'PRIMARY' || (int)($indexRow['Non_unique'] ?? 1) !== 0) {
            continue;
        }
        $pdo->exec('ALTER TABLE survey_answers DROP INDEX `' . str_replace('`', '``', $indexName) . '`');
    }
    $hasAnswerIndex = false;
    foreach ($pdo->query('SHOW INDEX FROM survey_answers')->fetchAll() as $indexRow) {
        if ((string)($indexRow['Key_name'] ?? '') === 'idx_survey_answer_question') {
            $hasAnswerIndex = true;
            break;
        }
    }
    if (!$hasAnswerIndex) {
        $pdo->exec('ALTER TABLE survey_answers ADD INDEX idx_survey_answer_question (response_id, question_id)');
    }

    echo json_encode(['success' => true, 'message' => 'Průzkumy byly připraveny.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}