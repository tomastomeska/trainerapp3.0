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
    $pdo->exec("CREATE TABLE IF NOT EXISTS coach_intake_questions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        coach_id INT UNSIGNED NOT NULL,
        question_text TEXT NOT NULL,
        question_type ENUM('yes_no','text','combined','single_choice','multiple_choice') NOT NULL DEFAULT 'yes_no',
        options_json TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_coach_intake_questions_coach (coach_id, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("ALTER TABLE coach_intake_questions MODIFY question_type ENUM('yes_no','text','combined','single_choice','multiple_choice') NOT NULL DEFAULT 'yes_no'");
    $coachColumns = $pdo->query("SHOW COLUMNS FROM coach_intake_questions LIKE 'options_json'")->fetch();
    if (!$coachColumns) $pdo->exec('ALTER TABLE coach_intake_questions ADD COLUMN options_json TEXT NULL AFTER question_type');

    $pdo->exec("CREATE TABLE IF NOT EXISTS default_intake_questions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question_text TEXT NOT NULL,
        question_type ENUM('yes_no','text','combined','single_choice','multiple_choice') NOT NULL DEFAULT 'yes_no',
        options_json TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_default_intake_questions_active (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS coach_intake_settings (
        coach_id INT UNSIGNED PRIMARY KEY,
        initialized TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo json_encode(['success' => true, 'message' => 'Vstupní dotazníky byly připraveny.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Intake questionnaire migration: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
