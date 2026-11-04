<?php
$isCli = php_sapi_name() === 'cli';
if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    $provided = (string)($_GET['secret'] ?? '');
    if ($provided === '' || !hash_equals(getCronSecret(), $provided)) {
        if (!isAdminLoggedIn()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            exit('Nejprve se <a href="' . htmlspecialchars(BASE_URL . '/login_admin.php', ENT_QUOTES, 'UTF-8') . '">přihlaste jako administrátor</a>.');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="cs"><meta charset="utf-8"><title>Migrace sportovců</title><body><h1>Migrace sportovců a vstupního dotazníku</h1><p>Před spuštěním zálohujte databázi.</p><form method="post">' . csrfField() . '<button type="submit">Spustit migraci</button></form></body></html>';
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
            http_response_code(403);
            exit('Neplatný bezpečnostní token.');
        }
    }
}
header('Content-Type: text/plain; charset=utf-8');
try {
    $pdo = getDB();
    $column = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'online_only'");
    if (!$column->fetch()) $pdo->exec('ALTER TABLE athletes ADD COLUMN online_only TINYINT(1) NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS coach_intake_questions (
        id INT AUTO_INCREMENT PRIMARY KEY, coach_id INT NOT NULL,
        question_text TEXT NOT NULL, question_type ENUM('yes_no','text','combined') NOT NULL,
        sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_intake_coach (coach_id, is_active, sort_order, id),
        CONSTRAINT fk_intake_question_coach FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS athlete_intake_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY, coach_id INT NOT NULL, athlete_id INT NOT NULL,
        answers_json LONGTEXT NOT NULL, submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_intake_athlete (athlete_id),
        CONSTRAINT fk_intake_submission_coach FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE,
        CONSTRAINT fk_intake_submission_athlete FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: režim pouze ONLINE a vstupní dotazník jsou připraveny.\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Online athlete and intake migration: ' . $e->getMessage());
    echo "Migrace se nezdařila: " . $e->getMessage() . "\n";
    exit(1);
}
