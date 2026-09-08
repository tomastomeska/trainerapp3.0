<?php
$isCli = php_sapi_name() === 'cli';
if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    $secret = getCronSecret();
    $provided = (string)($_GET['secret'] ?? '');
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        exit('Unauthorized - neplatny secret token.');
    }
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    $rateColumn = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'online_training_rate'");
    if (!$rateColumn || !$rateColumn->fetch()) {
        $pdo->exec("ALTER TABLE athletes ADD COLUMN online_training_rate DECIMAL(10,2) NULL AFTER training_rate");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS online_training_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        athlete_id INT NOT NULL,
        total_trainings INT NOT NULL,
        used_trainings INT NOT NULL DEFAULT 0,
        remaining_trainings INT NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        purchased_at DATETIME NOT NULL,
        status ENUM('active','exhausted','cancelled') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_online_sub_athlete (athlete_id, status, remaining_trainings),
        CONSTRAINT fk_online_sub_trainer FOREIGN KEY (trainer_id) REFERENCES coaches(id) ON DELETE CASCADE,
        CONSTRAINT fk_online_sub_athlete FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS workout_set_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workout_set_id INT NOT NULL,
        exercise_id INT NULL,
        attachment_type ENUM('photo','video','url') NOT NULL,
        file_path VARCHAR(500) NULL,
        original_name VARCHAR(255) NULL,
        external_url VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_set_attachment_set (workout_set_id),
        CONSTRAINT fk_set_attachment_set FOREIGN KEY (workout_set_id) REFERENCES workout_sets(id) ON DELETE CASCADE,
        CONSTRAINT fk_set_attachment_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS online_trainings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trainer_id INT NOT NULL,
        athlete_id INT NOT NULL,
        training_set_id INT NOT NULL,
        sequence_number INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        status ENUM('created','sent','in_progress','completed') NOT NULL DEFAULT 'created',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        duration_seconds INT NULL,
        billing_type ENUM('free','single','subscription') NOT NULL DEFAULT 'free',
        price DECIMAL(10,2) NOT NULL DEFAULT 0,
        subscription_id INT NULL,
        coach_note TEXT NULL,
        athlete_note TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_online_training_sequence (athlete_id, sequence_number),
        KEY idx_online_training_athlete_status (athlete_id, status, sent_at),
        KEY idx_online_training_trainer_status (trainer_id, status, sent_at),
        CONSTRAINT fk_online_training_trainer FOREIGN KEY (trainer_id) REFERENCES coaches(id) ON DELETE CASCADE,
        CONSTRAINT fk_online_training_athlete FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE,
        CONSTRAINT fk_online_training_set FOREIGN KEY (training_set_id) REFERENCES workout_sets(id) ON DELETE RESTRICT,
        CONSTRAINT fk_online_training_subscription FOREIGN KEY (subscription_id) REFERENCES online_training_subscriptions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS online_training_exercises (
        id INT AUTO_INCREMENT PRIMARY KEY,
        online_training_id INT NOT NULL,
        exercise_id INT NOT NULL,
        exercise_order INT NOT NULL,
        exercise_name VARCHAR(200) NOT NULL,
        sport_type VARCHAR(40) NOT NULL DEFAULT 'standard',
        is_timed TINYINT(1) NOT NULL DEFAULT 0,
        instructions TEXT NULL,
        KEY idx_online_exercise_order (online_training_id, exercise_order),
        CONSTRAINT fk_online_exercise_training FOREIGN KEY (online_training_id) REFERENCES online_trainings(id) ON DELETE CASCADE,
        CONSTRAINT fk_online_exercise_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS online_training_series (
        id INT AUTO_INCREMENT PRIMARY KEY,
        online_training_exercise_id INT NOT NULL,
        series_order INT NOT NULL,
        prescribed_weight DECIMAL(10,2) NULL,
        prescribed_reps INT NULL,
        prescribed_duration_seconds INT NULL,
        prescribed_equipment_weight DECIMAL(10,2) NULL,
        UNIQUE KEY uq_online_series_order (online_training_exercise_id, series_order),
        CONSTRAINT fk_online_series_exercise FOREIGN KEY (online_training_exercise_id) REFERENCES online_training_exercises(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS online_training_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        online_training_id INT NOT NULL,
        online_training_series_id INT NOT NULL,
        actual_weight DECIMAL(10,2) NULL,
        actual_reps INT NULL,
        actual_duration_seconds INT NULL,
        actual_equipment_weight DECIMAL(10,2) NULL,
        note TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_online_result_series (online_training_series_id),
        CONSTRAINT fk_online_result_training FOREIGN KEY (online_training_id) REFERENCES online_trainings(id) ON DELETE CASCADE,
        CONSTRAINT fk_online_result_series FOREIGN KEY (online_training_series_id) REFERENCES online_training_series(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS online_training_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        online_training_id INT NOT NULL,
        online_training_exercise_id INT NULL,
        attachment_type ENUM('photo','video','url') NOT NULL,
        file_path VARCHAR(500) NULL,
        original_name VARCHAR(255) NULL,
        external_url VARCHAR(1000) NULL,
        uploaded_by ENUM('trainer','athlete') NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_online_attachment_training (online_training_id),
        CONSTRAINT fk_online_attachment_training FOREIGN KEY (online_training_id) REFERENCES online_trainings(id) ON DELETE CASCADE,
        CONSTRAINT fk_online_attachment_exercise FOREIGN KEY (online_training_exercise_id) REFERENCES online_training_exercises(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS online_training_billing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        online_training_id INT NULL,
        subscription_id INT NULL,
        trainer_id INT NOT NULL,
        athlete_id INT NOT NULL,
        billing_type ENUM('single','subscription') NOT NULL,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        billing_date DATE NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_online_billing_date (trainer_id, athlete_id, billing_date),
        CONSTRAINT fk_online_billing_training FOREIGN KEY (online_training_id) REFERENCES online_trainings(id) ON DELETE SET NULL,
        CONSTRAINT fk_online_billing_subscription FOREIGN KEY (subscription_id) REFERENCES online_training_subscriptions(id) ON DELETE SET NULL,
        CONSTRAINT fk_online_billing_trainer FOREIGN KEY (trainer_id) REFERENCES coaches(id) ON DELETE CASCADE,
        CONSTRAINT fk_online_billing_athlete FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Online training schema migration completed.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
