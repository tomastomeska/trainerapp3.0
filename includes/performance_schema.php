<?php
declare(strict_types=1);

function performanceSchemaInventory(PDO $pdo): array {
    $stmt = $pdo->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
    $tables = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $tables[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row['COLUMN_TYPE'];
    return $tables;
}

function performanceSchemaCheck(PDO $pdo): array {
    $tables = performanceSchemaInventory($pdo);
    $required = [
        'athletes' => ['id', 'coach_id', 'first_name', 'last_name', 'birth_date'],
        'coaches' => ['id'], 'app_settings' => ['key', 'value'],
        'mycoach_users' => ['id', 'athlete_id', 'role'],
        'mycoach_daily_questionnaires' => ['id', 'user_id', 'entry_date', 'workout_id', 'sleep_hours', 'feeling_score', 'energy_score', 'motivation_score', 'muscle_pain_score', 'joint_pain_score', 'rpe_score', 'training_duration_minutes', 'workout_meta_json', 'updated_at', 'created_at'],
    ];
    $missing = [];
    foreach ($required as $table => $columns) foreach ($columns as $column) {
        if (!isset($tables[$table][$column])) $missing[] = $table . '.' . $column;
    }
    if (isset($tables['mycoach_users']['athlete_id'])) {
        $indexes = [];
        foreach ($pdo->query('SHOW INDEX FROM mycoach_users WHERE Non_unique = 0')->fetchAll(PDO::FETCH_ASSOC) as $index) $indexes[$index['Key_name']][] = $index['Column_name'];
        if (!in_array(['athlete_id'], $indexes, true)) $missing[] = 'mycoach_users UNIQUE(athlete_id)';
    }
    return ['tables' => $tables, 'missing' => $missing];
}

function performanceSchemaReady(PDO $pdo): bool {
    $t = performanceSchemaInventory($pdo);
    $required = [
        'performance_settings' => ['athlete_id', 'intelligence_enabled', 'share_performance', 'share_recovery'],
        'performance_results' => ['id', 'athlete_id', 'module_key', 'entry_date', 'actor_type', 'actor_id', 'inputs_json', 'results_json', 'sources_json', 'algorithm_version', 'request_key', 'coach_note', 'coach_note_at', 'created_at'],
        'athlete_sweat_tests' => ['id', 'athlete_id', 'entry_date', 'inputs_json', 'results_json', 'request_key'],
        'athlete_hyrox_attempts' => ['id', 'athlete_id', 'entry_date', 'category', 'standard_json', 'total_seconds', 'activity_key', 'request_key'],
        'athlete_hyrox_splits' => ['attempt_id', 'segment_key', 'seconds'],
        'performance_activity_links' => ['athlete_id', 'custom_activity_id', 'target_key'],
        'mycoach_daily_questionnaires' => ['performance_key'],
    ];
    foreach ($required as $table => $columns) foreach ($columns as $column) if (!isset($t[$table][$column])) return false;
    return true;
}

function performanceMigrationLock(PDO $pdo): string {
    $name = 'trainerapp-performance-' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([$name]);
    if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Právě probíhá jiná záloha nebo migrace modulu. Zkuste to po jejím dokončení.');
    return $name;
}

function performanceMigrationUnlock(PDO $pdo, string $name): void {
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([$name]);
}

function performanceMigrate(PDO $pdo): void {
    if ($pdo->inTransaction()) throw new RuntimeException('Migraci nelze spustit uvnitř transakce.');
    $lock = performanceMigrationLock($pdo);
    try {
        performanceMigrateSchema($pdo);
    } finally {
        performanceMigrationUnlock($pdo, $lock);
    }
}

function performanceMigrateSchema(PDO $pdo): void {
    $check = performanceSchemaCheck($pdo);
    if ($check['missing']) throw new RuntimeException('Nejprve doplňte existující schéma: ' . implode(', ', $check['missing']));
    $type = $check['tables']['athletes']['id'];
    if (!preg_match('/^(?:bigint|int)(?:\(\d+\))?(?: unsigned)?$/i', $type)) throw new RuntimeException('Nepodporovaný typ athletes.id.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS performance_settings (
        athlete_id $type NOT NULL PRIMARY KEY, intelligence_enabled TINYINT NOT NULL DEFAULT 0,
        share_performance TINYINT NOT NULL DEFAULT 0, share_recovery TINYINT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS performance_results (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, athlete_id $type NOT NULL,
        module_key VARCHAR(20) NOT NULL, entry_date DATE NOT NULL, actor_type VARCHAR(10) NOT NULL,
        actor_id INT NOT NULL, inputs_json MEDIUMTEXT NOT NULL, results_json MEDIUMTEXT NOT NULL,
        sources_json MEDIUMTEXT NOT NULL, algorithm_version VARCHAR(20) NOT NULL,
        request_key CHAR(32) NOT NULL, coach_note TEXT NULL, coach_note_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_performance_request (athlete_id, request_key),
        KEY idx_performance_history (athlete_id, entry_date, id),
        FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS athlete_sweat_tests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, athlete_id $type NOT NULL, entry_date DATE NOT NULL,
        inputs_json TEXT NOT NULL, results_json TEXT NOT NULL, request_key CHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sweat_request (athlete_id, request_key), KEY idx_sweat_date (athlete_id, entry_date, id),
        FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS athlete_hyrox_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, athlete_id $type NOT NULL, entry_date DATE NOT NULL,
        category VARCHAR(60) NOT NULL, standard_json MEDIUMTEXT NOT NULL, total_seconds INT NOT NULL,
        activity_key VARCHAR(80) NOT NULL, request_key CHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hyrox_activity (athlete_id, activity_key),
        UNIQUE KEY uq_hyrox_request (athlete_id, request_key),
        FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS athlete_hyrox_splits (
        attempt_id BIGINT UNSIGNED NOT NULL, segment_key VARCHAR(30) NOT NULL, seconds INT NOT NULL,
        PRIMARY KEY (attempt_id, segment_key),
        FOREIGN KEY (attempt_id) REFERENCES athlete_hyrox_attempts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS performance_activity_links (
        athlete_id $type NOT NULL, custom_activity_id INT NOT NULL, target_key VARCHAR(80) NOT NULL,
        PRIMARY KEY (athlete_id, custom_activity_id),
        FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $tables = performanceSchemaInventory($pdo);
    if (!isset($tables['mycoach_daily_questionnaires']['performance_key'])) {
        $pdo->exec('ALTER TABLE mycoach_daily_questionnaires ADD COLUMN performance_key VARCHAR(80) NULL');
    }
    $index = $pdo->query("SHOW INDEX FROM mycoach_daily_questionnaires WHERE Key_name = 'uq_daily_performance'")->fetch();
    if (!$index) $pdo->exec('ALTER TABLE mycoach_daily_questionnaires ADD UNIQUE KEY uq_daily_performance (user_id, performance_key)');
    $stmt = $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('calculator_performance_enabled', '0') ON DUPLICATE KEY UPDATE `key` = VALUES(`key`)");
    $stmt->execute();
    if (!performanceSchemaReady($pdo)) throw new RuntimeException('Migrace není úplná. Existující tabulky modulu mají jiné sloupce; je nutná kontrola správce databáze.');
}
