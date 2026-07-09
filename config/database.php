<?php
// ============================================================
// Konfigurace databaze
// Upravte podle vaseho nastaveni WAMP/MySQL
// ============================================================

// Bezpecnostni fallback: nacti env profil i kdyz vstupni skript nenasel config.php
$_envCandidates = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/env.php',
    __DIR__ . '/env.production.php',
];
foreach ($_envCandidates as $_envFile) {
    if (file_exists($_envFile)) {
        require_once $_envFile;
        break;
    }
}
unset($_envCandidates, $_envFile);

if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    'trainerapp_v2_dev');
if (!defined('DB_USER'))    define('DB_USER',    'root');
if (!defined('DB_PASS'))    define('DB_PASS',    '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

function isLocalDbHostEntry(string $hostEntry): bool {
    $host = trim($hostEntry);
    if ($host === '') {
        return false;
    }

    if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $m)) {
        $host = $m[1];
    } elseif (preg_match('/^([^:]+):(\d+)$/', $host, $m)) {
        $host = $m[1];
    }

    $host = strtolower(trim($host));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $hosts = [];
        foreach (explode(',', (string)DB_HOST) as $h) {
            $h = trim($h);
            if ($h !== '') {
                $hosts[] = $h;
            }
        }
        if (empty($hosts)) {
            $hosts[] = 'localhost';
        }

        // Pokud je v konfiguraci kombinace lokalnich a vzdalenych hostu,
        // zkus nejdriv vzdaleny host a lokalni nech jako fallback.
        if (count($hosts) > 1) {
            $remoteHosts = [];
            $localHosts = [];
            foreach ($hosts as $entry) {
                if (isLocalDbHostEntry($entry)) {
                    $localHosts[] = $entry;
                } else {
                    $remoteHosts[] = $entry;
                }
            }
            if (!empty($remoteHosts) && !empty($localHosts)) {
                $hosts = array_merge($remoteHosts, $localHosts);
            }
        }

        // Fallback na druhou local variantu zkus jen tehdy,
        // kdy konfigurace obsahuje pouze jednu lokalni hodnotu bez portu.
        if (count($hosts) === 1) {
            if ($hosts[0] === 'localhost') {
                $hosts[] = '127.0.0.1';
            } elseif ($hosts[0] === '127.0.0.1') {
                $hosts[] = 'localhost';
            }
        }

        $errors = [];
        try {
            foreach ($hosts as $host) {
                $port = null;
                if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $m)) {
                    $host = $m[1];
                    $port = (int)$m[2];
                } elseif (preg_match('/^([^:]+):(\d+)$/', $host, $m)) {
                    $host = $m[1];
                    $port = (int)$m[2];
                }

                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=%s',
                    $host,
                    DB_NAME,
                    DB_CHARSET
                );
                if ($port !== null) {
                    $dsn .= ';port=' . $port;
                }
                try {
                    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                    ensureSchemaUpgrades($pdo);
                    if (!empty($errors) && function_exists('appLogEvent')) {
                        appLogEvent(
                            'db_connect_retry',
                            'warning',
                            'Pripojeni k DB probehlo az po opakovani',
                            ['attempt_errors' => $errors],
                            'system',
                            null,
                            'database'
                        );
                    }
                    break;
                } catch (PDOException $e) {
                    $errors[] = $host . ': ' . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'unexpected: ' . $e->getMessage();
        }

        if ($pdo === null) {
            error_log('DB connection failed (' . implode(' | ', $errors) . ')');
            die('<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8">
                <title>Chyba DB</title>
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
                </head><body class="bg-light"><div class="container mt-5">
                <div class="alert alert-danger">
                    <h4>Nelze se pripojit k databazi</h4>
                    <p>Zkontrolujte nastaveni v <code>config/env.php</code> dle <code>config/env.example.php</code> a ujistete se, ze databazovy server je dostupny.</p>
                </div></div></body></html>');
        }
    }
    return $pdo;
}

function ensureSchemaUpgrades(PDO $pdo): void {
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `app_event_log` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `event_type` VARCHAR(64) NOT NULL,
            `severity` ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
            `message` VARCHAR(500) NOT NULL,
            `context_json` TEXT NULL,
            `user_type` ENUM('admin','coach','athlete','guest','system') NOT NULL DEFAULT 'guest',
            `user_id` INT NULL,
            `user_name` VARCHAR(120) NULL,
            `request_uri` VARCHAR(255) NULL,
            `request_method` VARCHAR(10) NULL,
            `http_status` SMALLINT NULL,
            `duration_ms` INT NULL,
            `ip_address` VARCHAR(45) NULL,
            `user_agent` VARCHAR(255) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_app_event_log_created` (`created_at`),
            KEY `idx_app_event_log_user` (`user_type`, `user_id`, `created_at`),
            KEY `idx_app_event_log_severity` (`severity`, `created_at`),
            KEY `idx_app_event_log_event_type` (`event_type`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Kompatibilita: starsi instalace mely u sportovce pouze sloupec "age".
    $stmt = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'birth_date'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN birth_date DATE NULL AFTER last_name');
    }

    // Foto sloupec pro cviky
    $stmt2 = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'photo'");
    if (!$stmt2->fetch()) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN photo VARCHAR(255) NULL');
    }

    // Typ sportu pro cviky
    $stmtExerciseSportType = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'sport_type'");
    if (!$stmtExerciseSportType->fetch()) {
        $pdo->exec("ALTER TABLE exercises ADD COLUMN sport_type ENUM('standard','golf','run_outdoor','run_treadmill') NOT NULL DEFAULT 'standard' AFTER photo");
    }
    $pdo->exec("UPDATE exercises SET sport_type = 'standard' WHERE sport_type IN ('run_outdoor', 'run_treadmill')");

    // Cvik měřený časem
    $stmtExerciseTimed = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'is_timed'");
    if (!$stmtExerciseTimed->fetch()) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN is_timed TINYINT(1) NOT NULL DEFAULT 0 AFTER sport_type');
    }

    // Kategorie svalových partií pro filtrování cviků (JSON pole)
    $stmtExerciseMuscleCategories = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'muscle_categories'");
    if (!$stmtExerciseMuscleCategories->fetch()) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN muscle_categories TEXT NULL AFTER is_timed');
    }

    // Globalni cviky mohou mit coach_id = NULL
    $stmtCoachId = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'coach_id'");
    $coachIdColumn = $stmtCoachId->fetch();
    if ($coachIdColumn && strtoupper((string)($coachIdColumn['Null'] ?? 'NO')) !== 'YES') {
        $pdo->exec('ALTER TABLE exercises MODIFY COLUMN coach_id INT NULL');
    }

    // Foto sloupec pro sportovce
    $stmt3 = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'photo'");
    if (!$stmt3->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN photo VARCHAR(255) NULL');
    }

    // Foto sloupec pro dokonceny trenink
    $stmtTsPhoto = $pdo->query("SHOW COLUMNS FROM training_sessions LIKE 'training_photo'");
    if (!$stmtTsPhoto->fetch()) {
        $pdo->exec('ALTER TABLE training_sessions ADD COLUMN training_photo VARCHAR(255) NULL AFTER notes');
    }

    try {
        $stmtOutdoorWeather = $pdo->query("SHOW COLUMNS FROM run_outdoor_sessions LIKE 'weather'");
        if ($stmtOutdoorWeather && !$stmtOutdoorWeather->fetch()) {
            $pdo->exec('ALTER TABLE run_outdoor_sessions ADD COLUMN weather VARCHAR(120) NULL AFTER surface');
        }
    } catch (Throwable $e) {
        // Tabulka run_outdoor_sessions může být v decommission režimu odstraněná.
    }

    // Galerie fotek k tréninku (více fotek)
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `training_session_photos` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `session_id` INT NOT NULL,
            `filename`   VARCHAR(255) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_training_session_photos_session` (`session_id`),
            CONSTRAINT `fk_training_session_photos_session`
                FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Speciální sporty - základní tabulky (běh venku, běh na páse, golf)
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `run_treadmill_sessions` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `session_id`      INT NOT NULL,
            `duration_seconds` INT NOT NULL DEFAULT 0,
            `distance_km`     DECIMAL(7,2) NOT NULL DEFAULT 0,
            `calories_burned` INT NULL,
            `location`        VARCHAR(255) NULL,
            `feeling`         TEXT NULL,
            `started_at`      DATETIME NULL,
            `ended_at`        DATETIME NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_run_treadmill_session` (`session_id`),
            CONSTRAINT `fk_run_treadmill_session_training`
                FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `run_outdoor_sessions` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `session_id`       INT NOT NULL,
            `duration_seconds` INT NOT NULL DEFAULT 0,
            `distance_km`      DECIMAL(7,2) NOT NULL DEFAULT 0,
            `run_type`         ENUM('free','interval','tempo','hill','long') NOT NULL DEFAULT 'free',
            `surface`          ENUM('asphalt','trail','track','treadmill','other') NOT NULL DEFAULT 'asphalt',
            `weather`          VARCHAR(120) NULL,
            `avg_pace`         VARCHAR(10) NULL,
            `max_speed`        DECIMAL(5,2) NULL,
            `calories_burned`  INT NULL,
            `step_count`       INT NULL,
            `rpe`              TINYINT NULL,
            `tempo_variability` DECIMAL(5,2) NULL,
            `feeling`          TEXT NULL,
            `started_at`       DATETIME NULL,
            `ended_at`         DATETIME NULL,
            `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_run_outdoor_session` (`session_id`),
            CONSTRAINT `fk_run_outdoor_session_training`
                FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `run_outdoor_splits` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `run_session_id`   INT NOT NULL,
            `km_marker`        DECIMAL(4,2) NOT NULL,
            `split_time`       VARCHAR(10) NOT NULL,
            `pace`             VARCHAR(10) NULL,
            `max_speed_at_km`  DECIMAL(5,2) NULL,
            `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_run_outdoor_split` (`run_session_id`, `km_marker`),
            KEY `idx_run_outdoor_splits` (`run_session_id`),
            CONSTRAINT `fk_run_outdoor_splits_session`
                FOREIGN KEY (`run_session_id`) REFERENCES `run_outdoor_sessions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `golf_sessions` (
            `id`                 INT AUTO_INCREMENT PRIMARY KEY,
            `session_id`         INT NOT NULL,
            `course_id`          INT NULL,
            `tee_id`             INT NULL,
            `tee_name`           VARCHAR(80) NULL,
            `course_name`        VARCHAR(255) NOT NULL,
            `num_holes`          INT NOT NULL DEFAULT 18,
            `game_type`          ENUM('training','stroke_play','stableford','match_play') NOT NULL DEFAULT 'training',
            `distance_km`        DECIMAL(7,2) NULL,
            `calories_burned`    INT NULL,
            `weather`            VARCHAR(120) NULL,
            `players`            VARCHAR(255) NULL,
            `handicap_before`    DECIMAL(5,1) NULL,
            `handicap_after`     DECIMAL(5,1) NULL,
            `count_for_handicap` TINYINT(1) NOT NULL DEFAULT 1,
            `course_rating`      DECIMAL(4,1) NULL,
            `slope_rating`       SMALLINT NULL,
            `score_total`        INT NULL,
            `score_differential` DECIMAL(5,1) NULL,
            `duration_minutes`   INT NULL,
            `feeling`            TEXT NULL,
            `started_at`         DATETIME NULL,
            `ended_at`           DATETIME NULL,
            `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_golf_session_training` (`session_id`),
            KEY `idx_golf_sessions_course` (`course_id`),
            KEY `idx_golf_sessions_tee` (`tee_id`),
            CONSTRAINT `fk_golf_session_training`
                FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `golf_holes` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `golf_session_id` INT NOT NULL,
            `hole_number`     TINYINT NOT NULL,
            `par`             TINYINT NOT NULL,
            `score`           TINYINT NULL,
            `notes`           TEXT NULL,
            UNIQUE KEY `uq_golf_hole` (`golf_session_id`, `hole_number`),
            KEY `idx_golf_holes_session` (`golf_session_id`),
            CONSTRAINT `fk_golf_holes_session`
                FOREIGN KEY (`golf_session_id`) REFERENCES `golf_sessions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Golf metadata pro HCP výpočet
    $stmtGolfBefore = $pdo->query("SHOW COLUMNS FROM golf_sessions LIKE 'handicap_before'");
    if (!$stmtGolfBefore->fetch()) {
        $pdo->exec('ALTER TABLE golf_sessions ADD COLUMN handicap_before DECIMAL(5,1) NULL AFTER players');
    }
    $stmtGolfCount = $pdo->query("SHOW COLUMNS FROM golf_sessions LIKE 'count_for_handicap'");
    if (!$stmtGolfCount->fetch()) {
        $pdo->exec('ALTER TABLE golf_sessions ADD COLUMN count_for_handicap TINYINT(1) NOT NULL DEFAULT 1 AFTER handicap_before');
    }
    $stmtGolfCourseRating = $pdo->query("SHOW COLUMNS FROM golf_sessions LIKE 'course_rating'");
    if (!$stmtGolfCourseRating->fetch()) {
        $pdo->exec('ALTER TABLE golf_sessions ADD COLUMN course_rating DECIMAL(4,1) NULL AFTER count_for_handicap');
    }
    $stmtGolfSlope = $pdo->query("SHOW COLUMNS FROM golf_sessions LIKE 'slope_rating'");
    if (!$stmtGolfSlope->fetch()) {
        $pdo->exec('ALTER TABLE golf_sessions ADD COLUMN slope_rating SMALLINT NULL AFTER course_rating');
    }
    $stmtGolfScoreTotal = $pdo->query("SHOW COLUMNS FROM golf_sessions LIKE 'score_total'");
    if (!$stmtGolfScoreTotal->fetch()) {
        $pdo->exec('ALTER TABLE golf_sessions ADD COLUMN score_total INT NULL AFTER slope_rating');
    }
    $stmtGolfDiff = $pdo->query("SHOW COLUMNS FROM golf_sessions LIKE 'score_differential'");
    if (!$stmtGolfDiff->fetch()) {
        $pdo->exec('ALTER TABLE golf_sessions ADD COLUMN score_differential DECIMAL(5,1) NULL AFTER score_total');
    }
    $stmtGolfCourseId = $pdo->query("SHOW COLUMNS FROM golf_sessions LIKE 'course_id'");
    if (!$stmtGolfCourseId->fetch()) {
        $pdo->exec('ALTER TABLE golf_sessions ADD COLUMN course_id INT NULL AFTER session_id');
    }
    $stmtGolfTeeId = $pdo->query("SHOW COLUMNS FROM golf_sessions LIKE 'tee_id'");
    if (!$stmtGolfTeeId->fetch()) {
        $pdo->exec('ALTER TABLE golf_sessions ADD COLUMN tee_id INT NULL AFTER course_id');
    }
    $stmtGolfTeeName = $pdo->query("SHOW COLUMNS FROM golf_sessions LIKE 'tee_name'");
    if (!$stmtGolfTeeName->fetch()) {
        $pdo->exec('ALTER TABLE golf_sessions ADD COLUMN tee_name VARCHAR(80) NULL AFTER tee_id');
    }

    // Databáze golfových hřišť a odpališť
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `golf_courses` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `name`       VARCHAR(255) NOT NULL,
            `location`   VARCHAR(255) NULL,
            `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_golf_course_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `golf_course_tees` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `course_id`     INT NOT NULL,
            `tee_name`      VARCHAR(80) NOT NULL,
            `gender`        ENUM('men','women','unisex') NOT NULL DEFAULT 'unisex',
            `par`           INT NOT NULL DEFAULT 72,
            `course_rating` DECIMAL(4,1) NOT NULL,
            `slope_rating`  SMALLINT NOT NULL,
            `length_m`      INT NULL,
            `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
            `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_course_tee_gender` (`course_id`, `tee_name`, `gender`),
            KEY `idx_tee_course` (`course_id`),
            CONSTRAINT `fk_tee_course` FOREIGN KEY (`course_id`) REFERENCES `golf_courses`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Katalog sportovišť a míst tréninku
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `training_venues` (
            `id`                  INT AUTO_INCREMENT PRIMARY KEY,
            `name`                VARCHAR(255) NOT NULL,
            `address`             VARCHAR(255) NULL,
            `note`                TEXT NULL,
            `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
            `created_by_coach_id` INT NULL,
            `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_training_venue_name` (`name`),
            KEY `idx_training_venues_active_name` (`is_active`, `name`),
            CONSTRAINT `fk_training_venue_coach` FOREIGN KEY (`created_by_coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Soukromá místa trenérů (nezobrazují se ostatním trenérům)
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `coach_training_venues` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`   INT NOT NULL,
            `name`       VARCHAR(255) NOT NULL,
            `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_coach_training_venue_name` (`coach_id`, `name`),
            KEY `idx_coach_training_venues_active_name` (`coach_id`, `is_active`, `name`),
            CONSTRAINT `fk_coach_training_venue_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Kompatibilita starší verze sportovišť (admin_note -> note + adresa)
    $stmtVenueAddress = $pdo->query("SHOW COLUMNS FROM training_venues LIKE 'address'");
    if (!$stmtVenueAddress->fetch()) {
        $pdo->exec('ALTER TABLE training_venues ADD COLUMN address VARCHAR(255) NULL AFTER name');
    }
    $stmtVenueNote = $pdo->query("SHOW COLUMNS FROM training_venues LIKE 'note'");
    if (!$stmtVenueNote->fetch()) {
        $pdo->exec('ALTER TABLE training_venues ADD COLUMN note TEXT NULL AFTER address');
    }
    $stmtVenueAdminNote = $pdo->query("SHOW COLUMNS FROM training_venues LIKE 'admin_note'");
    if ($stmtVenueAdminNote->fetch()) {
        $pdo->exec('UPDATE training_venues SET note = admin_note WHERE note IS NULL AND admin_note IS NOT NULL');
    }

    // Soft-delete tréninku trenérem (pro admin obnovu)
    $stmtTsDeleted = $pdo->query("SHOW COLUMNS FROM training_sessions LIKE 'deleted_by_coach_at'");
    if (!$stmtTsDeleted->fetch()) {
        $pdo->exec('ALTER TABLE training_sessions ADD COLUMN deleted_by_coach_at DATETIME NULL AFTER completed_at');
    }

    // ID trenéra, který trénink smazal (audit)
    $stmtTsDeletedBy = $pdo->query("SHOW COLUMNS FROM training_sessions LIKE 'deleted_by_coach_id'");
    if (!$stmtTsDeletedBy->fetch()) {
        $pdo->exec('ALTER TABLE training_sessions ADD COLUMN deleted_by_coach_id INT NULL AFTER deleted_by_coach_at');
    }

    // Snapshot cviků v konkrétní session (historie nezávislá na editaci sady)
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `training_session_exercises` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `session_id`     INT NOT NULL,
            `exercise_id`    INT NOT NULL,
            `exercise_order` INT NOT NULL,
            `exercise_name`  VARCHAR(200) NOT NULL,
            `sport_type`     ENUM('standard','golf','run_outdoor','run_treadmill') NOT NULL DEFAULT 'standard',
            UNIQUE KEY `uniq_session_exercise` (`session_id`, `exercise_id`),
            KEY `idx_session_order` (`session_id`, `exercise_order`),
            CONSTRAINT `fk_tse_session`
                FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_tse_exercise`
                FOREIGN KEY (`exercise_id`) REFERENCES `exercises`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Typ sportu ve snapshotu cviku (starsi DB sloupec nema)
    $stmtTseSportType = $pdo->query("SHOW COLUMNS FROM training_session_exercises LIKE 'sport_type'");
    if (!$stmtTseSportType->fetch()) {
        $pdo->exec("ALTER TABLE training_session_exercises ADD COLUMN sport_type ENUM('standard','golf','run_outdoor','run_treadmill') NOT NULL DEFAULT 'standard' AFTER exercise_name");
    }
    $pdo->exec("UPDATE training_session_exercises SET sport_type = 'standard' WHERE sport_type IN ('run_outdoor', 'run_treadmill')");

    $stmtTseTimed = $pdo->query("SHOW COLUMNS FROM training_session_exercises LIKE 'is_timed'");
    if (!$stmtTseTimed->fetch()) {
        $pdo->exec('ALTER TABLE training_session_exercises ADD COLUMN is_timed TINYINT(1) NOT NULL DEFAULT 0 AFTER sport_type');
    }

    try {
        $stmtSeriesEquipmentWeight = $pdo->query("SHOW COLUMNS FROM session_series LIKE 'equipment_weight'");
        if (!$stmtSeriesEquipmentWeight->fetch()) {
            $pdo->exec('ALTER TABLE session_series ADD COLUMN equipment_weight DECIMAL(7,2) NULL AFTER weight');
        }
        $stmtSeriesDuration = $pdo->query("SHOW COLUMNS FROM session_series LIKE 'duration_seconds'");
        if (!$stmtSeriesDuration->fetch()) {
            $pdo->exec('ALTER TABLE session_series ADD COLUMN duration_seconds INT NULL AFTER equipment_weight');
        }
    } catch (Throwable $e) {
        // Tabulka session_series může v některých instalacích vznikat mimo bootstrap.
    }

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `run_treadmill_splits` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `run_session_id`  INT NOT NULL,
            `km_marker`       DECIMAL(4,2) NOT NULL,
            `split_time`      VARCHAR(10) NOT NULL,
            `pace`            VARCHAR(10) NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_run_treadmill_split` (`run_session_id`, `km_marker`),
            KEY `idx_run_treadmill_splits` (`run_session_id`),
            CONSTRAINT `fk_run_treadmill_splits_session`
                FOREIGN KEY (`run_session_id`) REFERENCES `run_treadmill_sessions`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Tel. kontakt pro sportovce
    $stmtPhone = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'phone_contact'");
    if (!$stmtPhone->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN phone_contact VARCHAR(20) NULL AFTER birth_date');
    }

    // Prihlasovaci udaje sportovce (email jako login)
    $stmtAthletePass = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'password'");
    if (!$stmtAthletePass->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN password VARCHAR(255) NULL AFTER email');
    }

    $stmtAthleteLoginEnabled = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'login_enabled'");
    if (!$stmtAthleteLoginEnabled->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN login_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER password');
    }

    $stmtAthleteForcePass = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'force_password_change'");
    if (!$stmtAthleteForcePass->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 1 AFTER login_enabled');
    }

    $stmtAthleteLastLogin = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'last_login'");
    if (!$stmtAthleteLastLogin->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN last_login DATETIME NULL AFTER force_password_change');
    }

    $stmtTrainingRate = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'training_rate'");
    if (!$stmtTrainingRate->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN training_rate DECIMAL(10,2) NULL AFTER email');
    }

    $stmtPairedTrainingRate = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'paired_training_rate'");
    if (!$stmtPairedTrainingRate->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN paired_training_rate DECIMAL(10,2) NULL AFTER training_rate');
    }

    // Poslední přihlášení trenéra
    $stmtLogin = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'last_login'");
    if (!$stmtLogin->fetch()) {
        $pdo->exec('ALTER TABLE coaches ADD COLUMN last_login DATETIME NULL');
    }

    // Vynuceni zmeny hesla trenera po prvnim prihlaseni
    $stmtCoachForcePass = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'force_password_change'");
    if (!$stmtCoachForcePass->fetch()) {
        $pdo->exec('ALTER TABLE coaches ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    }

    // Číslo účtu trenéra pro QR platby
    $stmtCoachBank = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'bank_account'");
    if (!$stmtCoachBank->fetch()) {
        $pdo->exec('ALTER TABLE coaches ADD COLUMN bank_account VARCHAR(64) NULL AFTER email');
    }

    // Lhůta (ve dnech) pro výběr náhradního termínu po zrušení tréninku sportovcem
    $stmtCoachMakeupDeadline = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'makeup_booking_deadline_days'");
    if (!$stmtCoachMakeupDeadline->fetch()) {
        $pdo->exec('ALTER TABLE coaches ADD COLUMN makeup_booking_deadline_days INT NOT NULL DEFAULT 14 AFTER bank_account');
    } else {
        $pdo->exec('UPDATE coaches SET makeup_booking_deadline_days = 14 WHERE makeup_booking_deadline_days IS NULL OR makeup_booking_deadline_days <= 0');
        $pdo->exec('ALTER TABLE coaches MODIFY COLUMN makeup_booking_deadline_days INT NOT NULL DEFAULT 14');
    }

    // Tabulka superadminu
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `superadmins` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `username`   VARCHAR(100) NOT NULL UNIQUE,
            `password`   VARCHAR(255) NOT NULL,
            `name`       VARCHAR(200),
            `email`      VARCHAR(255),
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // last_login pro superadminy (starsi instalace sloupec nemaji)
    $stmtSALogin = $pdo->query("SHOW COLUMNS FROM superadmins LIKE 'last_login'");
    if (!$stmtSALogin->fetch()) {
        $pdo->exec('ALTER TABLE superadmins ADD COLUMN last_login DATETIME NULL');
    }

    // Zapnuti 2FA pro superadminy (emailovy OTP kod)
    $stmtSATwoFactor = $pdo->query("SHOW COLUMNS FROM superadmins LIKE 'two_factor_enabled'");
    if (!$stmtSATwoFactor->fetch()) {
        $pdo->exec('ALTER TABLE superadmins ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER email');
    }

    $stmtSATwoFactorSkip = $pdo->query("SHOW COLUMNS FROM superadmins LIKE 'two_factor_skip_until'");
    if (!$stmtSATwoFactorSkip->fetch()) {
        $pdo->exec('ALTER TABLE superadmins ADD COLUMN two_factor_skip_until DATETIME NULL AFTER two_factor_enabled');
    }

    // Aktivni stav trenera
    $stmtAct = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'is_active'");
    if (!$stmtAct->fetch()) {
        $pdo->exec('ALTER TABLE coaches ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
    }

    // Globalni cviky
    $stmtGlob = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'is_global'");
    if (!$stmtGlob->fetch()) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0');
    }

    // Hlaska po prihlaseni (admin edituje zpravy pro trenery)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `login_message` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `message`    TEXT NOT NULL,
            `version`    INT NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Sledovani trvaleho skryti hlasky konkretnim trenerem
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `coach_message_seen` (
            `coach_id`        INT NOT NULL,
            `message_version` INT NOT NULL,
            PRIMARY KEY (`coach_id`, `message_version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Nastaveni aplikace (klic-hodnota)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `app_settings` (
            `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
            `value`      TEXT NOT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Kalendář trenéra: plánované tréninky (sloty po 60 minutách)
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `coach_calendar_events` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`     INT NOT NULL,
            `athlete_id`   INT NULL,
            `second_athlete_id` INT NULL,
            `requested_by_athlete_id` INT NULL,
            `approval_status` ENUM('pending','approved') NOT NULL DEFAULT 'approved',
            `coach_modified_at` DATETIME NULL,
            `series_id`    CHAR(36) NULL,
            `color_key`    VARCHAR(20) NOT NULL DEFAULT 'blue',
            `custom_title` VARCHAR(140) NULL,
            `location`     VARCHAR(255) NULL,
            `starts_at`    DATETIME NOT NULL,
            `ends_at`      DATETIME NOT NULL,
            `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_calendar_events_series_start` (`coach_id`, `series_id`, `starts_at`),
            KEY `idx_calendar_events_coach_start` (`coach_id`, `starts_at`),
            KEY `idx_calendar_events_coach_end` (`coach_id`, `ends_at`),
            KEY `idx_calendar_events_second_athlete` (`second_athlete_id`),
            KEY `idx_calendar_events_requested_by_athlete` (`requested_by_athlete_id`),
            KEY `idx_calendar_events_approval_status` (`coach_id`, `approval_status`, `starts_at`),
            CONSTRAINT `fk_calendar_events_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_calendar_events_athlete`
                FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Uzamčené časové úseky (trenér není k dispozici)
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `coach_calendar_locks` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`   INT NOT NULL,
            `note`       VARCHAR(255) NULL,
            `starts_at`  DATETIME NOT NULL,
            `ends_at`    DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_calendar_locks_coach_start` (`coach_id`, `starts_at`),
            KEY `idx_calendar_locks_coach_end` (`coach_id`, `ends_at`),
            CONSTRAINT `fk_calendar_locks_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtSeries = $pdo->query("SHOW COLUMNS FROM coach_calendar_events LIKE 'series_id'");
    if (!$stmtSeries->fetch()) {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN series_id CHAR(36) NULL AFTER athlete_id');
    }

    $stmtSeriesIdx = $pdo->query("SHOW INDEX FROM coach_calendar_events WHERE Key_name = 'idx_calendar_events_series_start'");
    if (!$stmtSeriesIdx->fetch()) {
        $pdo->exec('CREATE INDEX idx_calendar_events_series_start ON coach_calendar_events (coach_id, series_id, starts_at)');
    }

    $stmtColorKey = $pdo->query("SHOW COLUMNS FROM coach_calendar_events LIKE 'color_key'");
    if (!$stmtColorKey->fetch()) {
        $pdo->exec("ALTER TABLE coach_calendar_events ADD COLUMN color_key VARCHAR(20) NOT NULL DEFAULT 'blue' AFTER series_id");
    }

    $stmtRequestedByAthlete = $pdo->query("SHOW COLUMNS FROM coach_calendar_events LIKE 'requested_by_athlete_id'");
    if (!$stmtRequestedByAthlete->fetch()) {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN requested_by_athlete_id INT NULL AFTER athlete_id');
    }

    $stmtSecondAthlete = $pdo->query("SHOW COLUMNS FROM coach_calendar_events LIKE 'second_athlete_id'");
    if (!$stmtSecondAthlete->fetch()) {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN second_athlete_id INT NULL AFTER athlete_id');
    }

    $stmtSecondAthleteIdx = $pdo->query("SHOW INDEX FROM coach_calendar_events WHERE Key_name = 'idx_calendar_events_second_athlete'");
    if (!$stmtSecondAthleteIdx->fetch()) {
        $pdo->exec('CREATE INDEX idx_calendar_events_second_athlete ON coach_calendar_events (second_athlete_id)');
    }

    $stmtReqIdx = $pdo->query("SHOW INDEX FROM coach_calendar_events WHERE Key_name = 'idx_calendar_events_requested_by_athlete'");
    if (!$stmtReqIdx->fetch()) {
        $pdo->exec('CREATE INDEX idx_calendar_events_requested_by_athlete ON coach_calendar_events (requested_by_athlete_id)');
    }

    $stmtApprovalStatus = $pdo->query("SHOW COLUMNS FROM coach_calendar_events LIKE 'approval_status'");
    if (!$stmtApprovalStatus->fetch()) {
        $pdo->exec("ALTER TABLE coach_calendar_events ADD COLUMN approval_status ENUM('pending','approved') NOT NULL DEFAULT 'approved' AFTER requested_by_athlete_id");
        $pdo->exec("UPDATE coach_calendar_events SET approval_status = CASE WHEN requested_by_athlete_id IS NULL THEN 'approved' ELSE 'pending' END WHERE approval_status = 'approved'");
    }

    $stmtApprovalIdx = $pdo->query("SHOW INDEX FROM coach_calendar_events WHERE Key_name = 'idx_calendar_events_approval_status'");
    if (!$stmtApprovalIdx->fetch()) {
        $pdo->exec('CREATE INDEX idx_calendar_events_approval_status ON coach_calendar_events (coach_id, approval_status, starts_at)');
    }

    $stmtCoachModifiedAt = $pdo->query("SHOW COLUMNS FROM coach_calendar_events LIKE 'coach_modified_at'");
    if (!$stmtCoachModifiedAt->fetch()) {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN coach_modified_at DATETIME NULL AFTER approval_status');
    }

    $stmtIsMakeupSession = $pdo->query("SHOW COLUMNS FROM coach_calendar_events LIKE 'is_makeup_session'");
    if (!$stmtIsMakeupSession->fetch()) {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN is_makeup_session TINYINT(1) NOT NULL DEFAULT 0 AFTER coach_modified_at');
    }

    $stmtBillingMonth = $pdo->query("SHOW COLUMNS FROM coach_calendar_events LIKE 'billing_month'");
    if (!$stmtBillingMonth->fetch()) {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD COLUMN billing_month DATE NULL AFTER is_makeup_session');
        $pdo->exec("UPDATE coach_calendar_events SET billing_month = DATE_FORMAT(starts_at, '%Y-%m-01') WHERE billing_month IS NULL");
    }

    $stmtBillingIdx = $pdo->query("SHOW INDEX FROM coach_calendar_events WHERE Key_name = 'idx_calendar_events_billing'");
    if (!$stmtBillingIdx->fetch()) {
        $pdo->exec('CREATE INDEX idx_calendar_events_billing ON coach_calendar_events (coach_id, billing_month, approval_status, athlete_id)');
    }

    try {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD CONSTRAINT fk_calendar_events_requested_by_athlete FOREIGN KEY (requested_by_athlete_id) REFERENCES athletes(id) ON DELETE SET NULL');
    } catch (Throwable $e) {
        // Constraint uz muze existovat.
    }

    try {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD CONSTRAINT fk_calendar_events_second_athlete FOREIGN KEY (second_athlete_id) REFERENCES athletes(id) ON DELETE SET NULL');
    } catch (Throwable $e) {
        // Constraint uz muze existovat.
    }

    // Výchozí verze z konstanty – pouze pokud záznam ještě neexistuje
    $pdo->exec("
        INSERT IGNORE INTO `app_settings` (`key`, `value`)
        VALUES ('app_version', '" . APP_VERSION . "')
    ");

    // Poslední přihlášení superadmina
    $stmtAdminLogin = $pdo->query("SHOW COLUMNS FROM superadmins LIKE 'last_login'");
    if (!$stmtAdminLogin->fetch()) {
        $pdo->exec('ALTER TABLE superadmins ADD COLUMN last_login DATETIME NULL');
    }

    $stmtAdminTwoFactor = $pdo->query("SHOW COLUMNS FROM superadmins LIKE 'two_factor_enabled'");
    if (!$stmtAdminTwoFactor->fetch()) {
        $pdo->exec('ALTER TABLE superadmins ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER email');
    }

    // Párový trénink: tabulka skupin a cizí klíč v training_sessions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `paired_sessions` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`   INT NOT NULL,
            `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $stmtPaired = $pdo->query("SHOW COLUMNS FROM `training_sessions` LIKE 'paired_session_id'");
    if (!$stmtPaired->fetch()) {
        $pdo->exec('ALTER TABLE `training_sessions` ADD COLUMN `paired_session_id` INT NULL DEFAULT NULL');
    }

    // Narozeninové notifikace – log odeslaných emailů (zabraňuje duplicitám)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `birthday_notifications` (
            `id`                INT AUTO_INCREMENT PRIMARY KEY,
            `athlete_id`        INT NOT NULL,
            `notification_type` ENUM('warning','birthday') NOT NULL,
            `year`              YEAR NOT NULL,
            `sent_at`           DATETIME NOT NULL,
            UNIQUE KEY `uq_athlete_year_type` (`athlete_id`, `year`, `notification_type`),
            FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Kalendářové digest notifikace trenérům (zabraňuje duplicitám)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `coach_calendar_digest_notifications` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`    INT NOT NULL,
            `digest_type` ENUM('daily_tomorrow','weekly_next_week') NOT NULL,
            `digest_date` DATE NOT NULL,
            `sent_at`     DATETIME NOT NULL,
            UNIQUE KEY `uq_calendar_digest` (`coach_id`, `digest_type`, `digest_date`),
            KEY `idx_calendar_digest_type_date` (`digest_type`, `digest_date`),
            FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Historie zrušených kalendářových termínů (pro měsíční přehledy)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `coach_calendar_event_cancellations` (
            `id`                    INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`              INT NOT NULL,
            `athlete_id`            INT NULL,
            `second_athlete_id`     INT NULL,
            `canceled_by`           ENUM('coach','athlete') NOT NULL,
            `canceled_by_athlete_id` INT NULL,
            `cancellation_scope`    ENUM('single','future','pair_exit') NOT NULL DEFAULT 'single',
            `approval_status`       ENUM('pending','approved') NOT NULL DEFAULT 'approved',
            `is_makeup_session`     TINYINT(1) NOT NULL DEFAULT 0,
            `billing_month`         DATE NULL,
            `payment_status_snapshot` ENUM('none','pending','paid') NOT NULL DEFAULT 'none',
            `replacement_required`  TINYINT(1) NOT NULL DEFAULT 0,
            `replacement_deadline_at` DATETIME NULL,
            `replacement_event_id`  INT NULL,
            `custom_title`          VARCHAR(140) NULL,
            `location`              VARCHAR(255) NULL,
            `starts_at`             DATETIME NOT NULL,
            `ends_at`               DATETIME NOT NULL,
            `canceled_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_calendar_cancel_coach_start` (`coach_id`, `starts_at`),
            KEY `idx_calendar_cancel_athlete_start` (`athlete_id`, `starts_at`),
            KEY `idx_calendar_cancel_second_athlete_start` (`second_athlete_id`, `starts_at`),
            KEY `idx_calendar_cancel_canceled_at` (`canceled_at`),
            KEY `idx_calendar_cancel_replacement_required` (`coach_id`, `athlete_id`, `replacement_required`, `replacement_event_id`, `canceled_at`),
            FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`second_athlete_id`) REFERENCES `athletes`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`canceled_by_athlete_id`) REFERENCES `athletes`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtCancelBillingMonth = $pdo->query("SHOW COLUMNS FROM coach_calendar_event_cancellations LIKE 'billing_month'");
    if (!$stmtCancelBillingMonth->fetch()) {
        $pdo->exec('ALTER TABLE coach_calendar_event_cancellations ADD COLUMN billing_month DATE NULL AFTER is_makeup_session');
    }

    $stmtCancelPaymentStatus = $pdo->query("SHOW COLUMNS FROM coach_calendar_event_cancellations LIKE 'payment_status_snapshot'");
    if (!$stmtCancelPaymentStatus->fetch()) {
        $pdo->exec("ALTER TABLE coach_calendar_event_cancellations ADD COLUMN payment_status_snapshot ENUM('none','pending','paid') NOT NULL DEFAULT 'none' AFTER billing_month");
    }

    $stmtCancelReplacementRequired = $pdo->query("SHOW COLUMNS FROM coach_calendar_event_cancellations LIKE 'replacement_required'");
    if (!$stmtCancelReplacementRequired->fetch()) {
        $pdo->exec('ALTER TABLE coach_calendar_event_cancellations ADD COLUMN replacement_required TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_status_snapshot');
    }

    $stmtCancelReplacementDeadline = $pdo->query("SHOW COLUMNS FROM coach_calendar_event_cancellations LIKE 'replacement_deadline_at'");
    if (!$stmtCancelReplacementDeadline->fetch()) {
        $pdo->exec('ALTER TABLE coach_calendar_event_cancellations ADD COLUMN replacement_deadline_at DATETIME NULL AFTER replacement_required');
    }

    $stmtCancelReplacementEvent = $pdo->query("SHOW COLUMNS FROM coach_calendar_event_cancellations LIKE 'replacement_event_id'");
    if (!$stmtCancelReplacementEvent->fetch()) {
        $pdo->exec('ALTER TABLE coach_calendar_event_cancellations ADD COLUMN replacement_event_id INT NULL AFTER replacement_deadline_at');
    }

    $stmtCancelReplacementIdx = $pdo->query("SHOW INDEX FROM coach_calendar_event_cancellations WHERE Key_name = 'idx_calendar_cancel_replacement_required'");
    if (!$stmtCancelReplacementIdx->fetch()) {
        $pdo->exec('CREATE INDEX idx_calendar_cancel_replacement_required ON coach_calendar_event_cancellations (coach_id, athlete_id, replacement_required, replacement_event_id, canceled_at)');
    }

    // Log odeslaných souhrnných přehledů kalendáře (trenér/sportovec)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `calendar_summary_notifications` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `recipient_type` ENUM('coach','athlete') NOT NULL,
            `recipient_id`   INT NOT NULL,
            `digest_type`    ENUM('weekly_next_week','monthly_next_month') NOT NULL,
            `digest_date`    DATE NOT NULL,
            `sent_at`        DATETIME NOT NULL,
            UNIQUE KEY `uq_calendar_summary_digest` (`recipient_type`, `recipient_id`, `digest_type`, `digest_date`),
            KEY `idx_calendar_summary_type_date` (`digest_type`, `digest_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Zprávy od admina trenérům
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_messages` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `subject`         VARCHAR(255) NOT NULL,
            `body`            TEXT NOT NULL,
            `attachment_path` VARCHAR(500) NULL,
            `attachment_name` VARCHAR(255) NULL,
            `sent_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Příjemci zpráv (trenéři) + stav přečtení + složka
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_message_recipients` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `message_id` INT NOT NULL,
            `coach_id`   INT NOT NULL,
            `read_at`    DATETIME NULL,
            `status`     ENUM('inbox','archived','deleted') NOT NULL DEFAULT 'inbox',
            UNIQUE KEY `uq_msg_coach` (`message_id`, `coach_id`),
            FOREIGN KEY (`message_id`) REFERENCES `admin_messages`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`coach_id`)   REFERENCES `coaches`(`id`)        ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Přidat status sloupec pokud tabulka existuje bez něj (starší instalace)
    $stmtMsgStatus = $pdo->query("SHOW COLUMNS FROM admin_message_recipients LIKE 'status'");
    if (!$stmtMsgStatus->fetch()) {
        $pdo->exec("ALTER TABLE admin_message_recipients ADD COLUMN `status` ENUM('inbox','archived','deleted') NOT NULL DEFAULT 'inbox'");
    }

    // Přidat from_athlete_id – odkaz na sportovce, který zprávu napsal (obousměrná komunikace)
    $stmtMsgAthlete = $pdo->query("SHOW COLUMNS FROM admin_messages LIKE 'from_athlete_id'");
    if (!$stmtMsgAthlete->fetch()) {
        $pdo->exec("ALTER TABLE admin_messages ADD COLUMN `from_athlete_id` INT NULL AFTER `sent_at`");
    }

    // Typ odesílatele zprávy pro oddělení admin / systém / sportovec
    $stmtMsgSource = $pdo->query("SHOW COLUMNS FROM admin_messages LIKE 'message_source'");
    if (!$stmtMsgSource->fetch()) {
        $pdo->exec("ALTER TABLE admin_messages ADD COLUMN `message_source` ENUM('admin','system','athlete') NULL AFTER `from_athlete_id`");

        // Backfill stávajících dat při prvním nasazení sloupce.
        $pdo->exec("UPDATE admin_messages SET message_source = 'athlete' WHERE message_source IS NULL AND from_athlete_id IS NOT NULL");
        $pdo->exec("UPDATE admin_messages SET message_source = 'system' WHERE message_source IS NULL AND (
            subject LIKE 'Nová hmotnost - %'
            OR subject LIKE 'Upravená hmotnost - %'
            OR subject LIKE 'Smazaná hmotnost - %'
            OR subject LIKE 'Nový požadavek termínu - %'
            OR subject LIKE 'Sportovec zrušil termín - %'
            OR subject LIKE 'Narozeniny: %'
            OR subject LIKE 'Podpora #% - %'
            OR subject = 'Nový soubor v galerii od administrátora'
        )");
        $pdo->exec("UPDATE admin_messages SET message_source = 'admin' WHERE message_source IS NULL");
        $pdo->exec("ALTER TABLE admin_messages MODIFY COLUMN `message_source` ENUM('admin','system','athlete') NOT NULL DEFAULT 'system'");
    }

    // Akční tlačítka zpráv (volitelná tlačítka/podpisy přidaná adminem do zprávy)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `message_actions` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `message_id`  INT NOT NULL,
            `label`       VARCHAR(100) NOT NULL,
            `action_type` ENUM('button','signature') NOT NULL DEFAULT 'button',
            `sort_order`  INT NOT NULL DEFAULT 0,
            FOREIGN KEY (`message_id`) REFERENCES `admin_messages`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Logy stisku akčních tlačítek trenéry
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `message_action_logs` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `action_id`      INT NOT NULL,
            `coach_id`       INT NOT NULL,
            `pressed_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ip_address`     VARCHAR(45),
            `user_agent`     TEXT,
            `signature_data` MEDIUMTEXT NULL,
            UNIQUE KEY `uq_action_coach` (`action_id`, `coach_id`),
            FOREIGN KEY (`action_id`) REFERENCES `message_actions`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`coach_id`)  REFERENCES `coaches`(`id`)          ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Tickety podpory od trenérů/sportovců
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `support_tickets` (
            `id`                  INT AUTO_INCREMENT PRIMARY KEY,
            `reporter_type`       ENUM('coach','athlete') NOT NULL,
            `coach_id`            INT NULL,
            `athlete_id`          INT NULL,
            `reporter_name`       VARCHAR(255) NOT NULL,
            `reporter_email`      VARCHAR(255) NULL,
            `subject`             VARCHAR(255) NOT NULL,
            `issue_type`          VARCHAR(120) NOT NULL,
            `description`         TEXT NOT NULL,
            `page_url`            VARCHAR(500) NULL,
            `screenshot_path`     VARCHAR(255) NULL,
            `screenshot_name`     VARCHAR(255) NULL,
            `ip_address`          VARCHAR(45) NULL,
            `user_agent`          TEXT NULL,
            `status`              ENUM('new','open','resolved') NOT NULL DEFAULT 'new',
            `admin_note`          TEXT NULL,
            `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_support_tickets_status_created` (`status`, `created_at`),
            KEY `idx_support_tickets_coach` (`coach_id`),
            KEY `idx_support_tickets_athlete` (`athlete_id`),
            CONSTRAINT `fk_support_tickets_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_support_tickets_athlete`
                FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Historie tělesné hmotnosti sportovců + výzvy přes bezpečný odkaz
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `athlete_weight_invites` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `athlete_id` INT NOT NULL,
            `coach_id`   INT NOT NULL,
            `email`      VARCHAR(255) NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `used_at`    DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_weight_invite_token_hash` (`token_hash`),
            KEY `idx_weight_invites_athlete` (`athlete_id`),
            KEY `idx_weight_invites_expires` (`expires_at`),
            CONSTRAINT `fk_weight_invites_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_weight_invites_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `athlete_weight_logs` (
            `id`                  INT AUTO_INCREMENT PRIMARY KEY,
            `athlete_id`          INT NOT NULL,
            `measured_at`         DATE NOT NULL,
            `weight_kg`           DECIMAL(5,2) NOT NULL,
            `source`              ENUM('coach','athlete_link') NOT NULL DEFAULT 'coach',
            `invite_id`           INT NULL,
            `created_by_coach_id` INT NULL,
            `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_weight_logs_athlete_date` (`athlete_id`, `measured_at`),
            KEY `idx_weight_logs_invite` (`invite_id`),
            CONSTRAINT `fk_weight_logs_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_weight_logs_invite` FOREIGN KEY (`invite_id`) REFERENCES `athlete_weight_invites`(`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_weight_logs_coach` FOREIGN KEY (`created_by_coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Tokeny pro reset hesla (trenér + sportovec)
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `password_reset_requests` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `user_type`   ENUM('coach','athlete') NOT NULL,
            `coach_id`    INT NULL,
            `athlete_id`  INT NULL,
            `email`       VARCHAR(255) NOT NULL,
            `token_hash`  CHAR(64) NOT NULL,
            `expires_at`  DATETIME NOT NULL,
            `used_at`     DATETIME NULL,
            `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_password_reset_token_hash` (`token_hash`),
            KEY `idx_password_reset_expires` (`expires_at`),
            KEY `idx_password_reset_coach` (`coach_id`),
            KEY `idx_password_reset_athlete` (`athlete_id`),
            CONSTRAINT `fk_password_reset_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_password_reset_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Decommission: odstranění tabulek zrušených sportů (běh/golf).
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DROP TABLE IF EXISTS run_outdoor_splits');
        $pdo->exec('DROP TABLE IF EXISTS run_treadmill_splits');
        $pdo->exec('DROP TABLE IF EXISTS golf_holes');
        $pdo->exec('DROP TABLE IF EXISTS run_outdoor_sessions');
        $pdo->exec('DROP TABLE IF EXISTS run_treadmill_sessions');
        $pdo->exec('DROP TABLE IF EXISTS golf_sessions');
        $pdo->exec('DROP TABLE IF EXISTS golf_course_tees');
        $pdo->exec('DROP TABLE IF EXISTS golf_courses');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $ignored) {
        }
    }

    // Interne zpravy pro sportovce (napr. zmena/zruseni terminu)
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `athlete_notifications` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `athlete_id` INT NOT NULL,
            `subject`    VARCHAR(255) NOT NULL,
            `body`       TEXT NOT NULL,
            `read_at`    DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_athlete_notifications_athlete` (`athlete_id`, `created_at`),
            CONSTRAINT `fk_athlete_notifications_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Zpravy od trenera sportovcum (hromadne i jednotlive) + prehled precteni
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `coach_athlete_messages` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`   INT NOT NULL,
            `subject`    VARCHAR(255) NOT NULL,
            `body`       TEXT NOT NULL,
            `is_bulk`    TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_coach_athlete_messages_coach_created` (`coach_id`, `created_at`),
            CONSTRAINT `fk_coach_athlete_messages_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `coach_athlete_message_recipients` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `message_id`      INT NOT NULL,
            `athlete_id`      INT NOT NULL,
            `notification_id` INT NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_coach_athlete_message_recipient` (`message_id`, `athlete_id`),
            KEY `idx_coach_athlete_message_recipients_notification` (`notification_id`),
            KEY `idx_coach_athlete_message_recipients_athlete` (`athlete_id`, `created_at`),
            CONSTRAINT `fk_coach_athlete_message_recipients_message`
                FOREIGN KEY (`message_id`) REFERENCES `coach_athlete_messages`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_coach_athlete_message_recipients_athlete`
                FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_coach_athlete_message_recipients_notification`
                FOREIGN KEY (`notification_id`) REFERENCES `athlete_notifications`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Dohoda s trenérem: text dohody + reakce sportovce (schváleno/zamítnuto)
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `coach_athlete_agreements` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`      INT NOT NULL,
            `version`       INT NOT NULL DEFAULT 1,
            `title`         VARCHAR(255) NOT NULL,
            `body`          TEXT NOT NULL,
            `approve_label` VARCHAR(80) NOT NULL DEFAULT 'Schvaluji',
            `reject_label`  VARCHAR(80) NOT NULL DEFAULT 'Zamítám',
            `attachment_path` VARCHAR(500) NULL,
            `attachment_name` VARCHAR(255) NULL,
            `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
            `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_coach_athlete_agreements_coach` (`coach_id`, `is_active`, `created_at`),
            KEY `idx_coach_athlete_agreements_version` (`coach_id`, `version`),
            CONSTRAINT `fk_coach_athlete_agreements_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtAgreementVersion = $pdo->query("SHOW COLUMNS FROM coach_athlete_agreements LIKE 'version'");
    if (!$stmtAgreementVersion->fetch()) {
        $pdo->exec("ALTER TABLE coach_athlete_agreements ADD COLUMN `version` INT NOT NULL DEFAULT 1 AFTER `coach_id`");
    }

    $stmtAgreementAttachmentPath = $pdo->query("SHOW COLUMNS FROM coach_athlete_agreements LIKE 'attachment_path'");
    if (!$stmtAgreementAttachmentPath->fetch()) {
        $pdo->exec("ALTER TABLE coach_athlete_agreements ADD COLUMN `attachment_path` VARCHAR(500) NULL AFTER `reject_label`");
    }

    $stmtAgreementAttachmentName = $pdo->query("SHOW COLUMNS FROM coach_athlete_agreements LIKE 'attachment_name'");
    if (!$stmtAgreementAttachmentName->fetch()) {
        $pdo->exec("ALTER TABLE coach_athlete_agreements ADD COLUMN `attachment_name` VARCHAR(255) NULL AFTER `attachment_path`");
    }

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `coach_athlete_agreement_responses` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `agreement_id` INT NOT NULL,
            `athlete_id`   INT NOT NULL,
            `response`     ENUM('approved','rejected') NOT NULL,
            `responded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ip_address`   VARCHAR(45) NULL,
            `user_agent`   TEXT NULL,
            UNIQUE KEY `uq_coach_athlete_agreement_response` (`agreement_id`, `athlete_id`),
            KEY `idx_coach_athlete_agreement_responses_athlete` (`athlete_id`, `responded_at`),
            CONSTRAINT `fk_coach_athlete_agreement_responses_agreement`
                FOREIGN KEY (`agreement_id`) REFERENCES `coach_athlete_agreements`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_coach_athlete_agreement_responses_athlete`
                FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Infokanal: kategorie pro tipy/novinky + clanky + stav precteni pro role
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `info_categories` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `name`        VARCHAR(180) NOT NULL,
            `audience`    ENUM('all','coach','athlete') NOT NULL DEFAULT 'all',
            `sort_order`  INT NOT NULL DEFAULT 100,
            `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
            `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_info_categories_audience` (`audience`, `is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `info_articles` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `category_id`     INT NOT NULL,
            `title`           VARCHAR(220) NOT NULL,
            `body`            TEXT NOT NULL,
            `target_audience` ENUM('all','coach','athlete') NOT NULL DEFAULT 'all',
            `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
            `published_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by`      INT NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_info_articles_category` (`category_id`),
            KEY `idx_info_articles_visibility` (`is_active`, `published_at`, `target_audience`),
            CONSTRAINT `fk_info_articles_category`
                FOREIGN KEY (`category_id`) REFERENCES `info_categories`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `info_article_reads_coach` (
            `article_id` INT NOT NULL,
            `coach_id`   INT NOT NULL,
            `read_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`article_id`, `coach_id`),
            KEY `idx_info_article_reads_coach_coach` (`coach_id`, `read_at`),
            CONSTRAINT `fk_info_reads_coach_article`
                FOREIGN KEY (`article_id`) REFERENCES `info_articles`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_info_reads_coach_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `info_article_reads_athlete` (
            `article_id` INT NOT NULL,
            `athlete_id` INT NOT NULL,
            `read_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`article_id`, `athlete_id`),
            KEY `idx_info_article_reads_athlete_athlete` (`athlete_id`, `read_at`),
            CONSTRAINT `fk_info_reads_athlete_article`
                FOREIGN KEY (`article_id`) REFERENCES `info_articles`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_info_reads_athlete_athlete`
                FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Databáze jídel trenéra
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `global_meals` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `name`       VARCHAR(200) NOT NULL,
            `description` TEXT NULL,
            `grams`      INT NULL,
            `meal_type`  ENUM('breakfast','snack','lunch','dinner','second_dinner','post_workout','cheat_day') NULL,
            `fat_per_100g` DECIMAL(7,2) NULL,
            `sugars_per_100g` DECIMAL(7,2) NULL,
            `protein_per_100g` DECIMAL(7,2) NULL,
            `fiber_per_100g` DECIMAL(7,2) NULL,
            `salt_per_100g` DECIMAL(7,2) NULL,
            `photo`      VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_global_meals_name` (`name`),
            KEY `idx_global_meals_type` (`meal_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Databáze jídel trenéra
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `coach_meals` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`   INT NOT NULL,
            `global_meal_id` INT NULL,
            `name`       VARCHAR(200) NOT NULL,
            `description` TEXT NULL,
            `grams`      INT NULL,
            `meal_type`  ENUM('breakfast','snack','lunch','dinner','second_dinner','post_workout','cheat_day') NULL,
            `fat_per_100g` DECIMAL(7,2) NULL,
            `sugars_per_100g` DECIMAL(7,2) NULL,
            `protein_per_100g` DECIMAL(7,2) NULL,
            `fiber_per_100g` DECIMAL(7,2) NULL,
            `salt_per_100g` DECIMAL(7,2) NULL,
            `photo`      VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_coach_meals_coach_type` (`coach_id`, `meal_type`),
            KEY `idx_coach_meals_coach_name` (`coach_id`, `name`),
            KEY `idx_coach_meals_global` (`global_meal_id`),
            CONSTRAINT `fk_coach_meals_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Hlavičky jídelníčků
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `coach_meal_plans` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`   INT NOT NULL,
            `name`       VARCHAR(200) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_coach_meal_plans_coach` (`coach_id`, `created_at`),
            CONSTRAINT `fk_coach_meal_plans_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Položky jídelníčku
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `coach_meal_plan_items` (
            `id`                INT AUTO_INCREMENT PRIMARY KEY,
            `meal_plan_id`      INT NOT NULL,
            `day_of_week`       ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
            `meal_type`         ENUM('breakfast','snack','lunch','dinner','second_dinner','post_workout','cheat_day') NOT NULL,
            `meal_id`           INT NULL,
            `meal_name_snapshot` VARCHAR(200) NOT NULL,
            `grams`             INT NULL,
            `note`              TEXT NULL,
            `position`          INT NOT NULL DEFAULT 1,
            `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_meal_plan_items_plan_day_pos` (`meal_plan_id`, `day_of_week`, `position`),
            KEY `idx_meal_plan_items_plan` (`meal_plan_id`),
            KEY `idx_meal_plan_items_meal` (`meal_id`),
            CONSTRAINT `fk_meal_plan_items_plan` FOREIGN KEY (`meal_plan_id`) REFERENCES `coach_meal_plans`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_meal_plan_items_meal` FOREIGN KEY (`meal_id`) REFERENCES `coach_meals`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Přiřazení jídelníčku sportovcům
    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `athlete_meal_plans` (
            `id`                 INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`           INT NOT NULL,
            `athlete_id`         INT NOT NULL,
            `meal_plan_id`       INT NOT NULL,
            `assigned_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `removed_at`         DATETIME NULL,
            `removed_by_coach_id` INT NULL,
            KEY `idx_athlete_meal_plans_athlete_active` (`athlete_id`, `removed_at`),
            KEY `idx_athlete_meal_plans_plan` (`meal_plan_id`),
            KEY `idx_athlete_meal_plans_coach` (`coach_id`),
            CONSTRAINT `fk_athlete_meal_plans_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_athlete_meal_plans_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_athlete_meal_plans_plan` FOREIGN KEY (`meal_plan_id`) REFERENCES `coach_meal_plans`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_athlete_meal_plans_removed_by` FOREIGN KEY (`removed_by_coach_id`) REFERENCES `coaches`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Kompatibilita starších instalací jídel
    $stmtCoachMealsGrams = $pdo->query("SHOW COLUMNS FROM coach_meals LIKE 'grams'");
    if (!$stmtCoachMealsGrams->fetch()) {
        $pdo->exec('ALTER TABLE coach_meals ADD COLUMN grams INT NULL AFTER description');
    }

    $stmtCoachMealsType = $pdo->query("SHOW COLUMNS FROM coach_meals LIKE 'meal_type'");
    if (!$stmtCoachMealsType->fetch()) {
        $pdo->exec("ALTER TABLE coach_meals ADD COLUMN meal_type ENUM('breakfast','snack','lunch','dinner','second_dinner','post_workout','cheat_day') NOT NULL DEFAULT 'breakfast' AFTER grams");
    }

    $stmtCoachMealsGlobalMealId = $pdo->query("SHOW COLUMNS FROM coach_meals LIKE 'global_meal_id'");
    if (!$stmtCoachMealsGlobalMealId->fetch()) {
        $pdo->exec('ALTER TABLE coach_meals ADD COLUMN global_meal_id INT NULL AFTER coach_id');
        $pdo->exec('ALTER TABLE coach_meals ADD KEY idx_coach_meals_global (global_meal_id)');
    }

    // Umožni typ jídla jako volitelný (NULL)
    $stmtCoachMealsTypeDef = $pdo->query("SHOW COLUMNS FROM coach_meals LIKE 'meal_type'");
    $coachMealsTypeDef = $stmtCoachMealsTypeDef->fetch();
    if ($coachMealsTypeDef && strtoupper((string)($coachMealsTypeDef['Null'] ?? 'YES')) !== 'YES') {
        $pdo->exec("ALTER TABLE coach_meals MODIFY COLUMN meal_type ENUM('breakfast','snack','lunch','dinner','second_dinner','post_workout','cheat_day') NULL DEFAULT NULL");
    }

    $stmtCoachMealsFat = $pdo->query("SHOW COLUMNS FROM coach_meals LIKE 'fat_per_100g'");
    if (!$stmtCoachMealsFat->fetch()) {
        $pdo->exec('ALTER TABLE coach_meals ADD COLUMN fat_per_100g DECIMAL(7,2) NULL AFTER meal_type');
    }

    $stmtCoachMealsSugars = $pdo->query("SHOW COLUMNS FROM coach_meals LIKE 'sugars_per_100g'");
    if (!$stmtCoachMealsSugars->fetch()) {
        $pdo->exec('ALTER TABLE coach_meals ADD COLUMN sugars_per_100g DECIMAL(7,2) NULL AFTER fat_per_100g');
    }

    $stmtCoachMealsProtein = $pdo->query("SHOW COLUMNS FROM coach_meals LIKE 'protein_per_100g'");
    if (!$stmtCoachMealsProtein->fetch()) {
        $pdo->exec('ALTER TABLE coach_meals ADD COLUMN protein_per_100g DECIMAL(7,2) NULL AFTER sugars_per_100g');
    }

    $stmtCoachMealsFiber = $pdo->query("SHOW COLUMNS FROM coach_meals LIKE 'fiber_per_100g'");
    if (!$stmtCoachMealsFiber->fetch()) {
        $pdo->exec('ALTER TABLE coach_meals ADD COLUMN fiber_per_100g DECIMAL(7,2) NULL AFTER protein_per_100g');
    }

    $stmtCoachMealsSalt = $pdo->query("SHOW COLUMNS FROM coach_meals LIKE 'salt_per_100g'");
    if (!$stmtCoachMealsSalt->fetch()) {
        $pdo->exec('ALTER TABLE coach_meals ADD COLUMN salt_per_100g DECIMAL(7,2) NULL AFTER fiber_per_100g');
    }

    $stmtCoachMealsPhoto = $pdo->query("SHOW COLUMNS FROM coach_meals LIKE 'photo'");
    if (!$stmtCoachMealsPhoto->fetch()) {
        $pdo->exec('ALTER TABLE coach_meals ADD COLUMN photo VARCHAR(255) NULL AFTER salt_per_100g');
    }

    $stmtPlanItemSnapshot = $pdo->query("SHOW COLUMNS FROM coach_meal_plan_items LIKE 'meal_name_snapshot'");
    if (!$stmtPlanItemSnapshot->fetch()) {
        $pdo->exec('ALTER TABLE coach_meal_plan_items ADD COLUMN meal_name_snapshot VARCHAR(200) NOT NULL AFTER meal_id');
        $pdo->exec('UPDATE coach_meal_plan_items i LEFT JOIN coach_meals m ON m.id = i.meal_id SET i.meal_name_snapshot = COALESCE(m.name, \"Jidlo\") WHERE i.meal_name_snapshot = \"\" OR i.meal_name_snapshot IS NULL');
    }

    $stmtPlanItemNote = $pdo->query("SHOW COLUMNS FROM coach_meal_plan_items LIKE 'note'");
    if (!$stmtPlanItemNote->fetch()) {
        $pdo->exec('ALTER TABLE coach_meal_plan_items ADD COLUMN note TEXT NULL AFTER meal_name_snapshot');
    }

    $stmtPlanItemPosition = $pdo->query("SHOW COLUMNS FROM coach_meal_plan_items LIKE 'position'");
    if (!$stmtPlanItemPosition->fetch()) {
        $pdo->exec('ALTER TABLE coach_meal_plan_items ADD COLUMN position INT NOT NULL DEFAULT 1 AFTER note');
        $pdo->exec('UPDATE coach_meal_plan_items SET position = id WHERE position = 1');
    }

    $stmtPlanItemGrams = $pdo->query("SHOW COLUMNS FROM coach_meal_plan_items LIKE 'grams'");
    if (!$stmtPlanItemGrams->fetch()) {
        $pdo->exec('ALTER TABLE coach_meal_plan_items ADD COLUMN grams INT NULL AFTER meal_name_snapshot');
        $pdo->exec('UPDATE coach_meal_plan_items i LEFT JOIN coach_meals m ON m.id = i.meal_id SET i.grams = m.grams WHERE i.grams IS NULL AND m.grams IS NOT NULL');
    }

    $stmtAthleteMealRemovedBy = $pdo->query("SHOW COLUMNS FROM athlete_meal_plans LIKE 'removed_by_coach_id'");
    if (!$stmtAthleteMealRemovedBy->fetch()) {
        $pdo->exec('ALTER TABLE athlete_meal_plans ADD COLUMN removed_by_coach_id INT NULL AFTER removed_at');
    }

    // ============================================================
    // GALERIE – složky, soubory, oprávnění
    // ============================================================

    // Složky trenéra (vlastní + automaticky vytvořené pro sportovce)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gallery_folders` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`    INT NOT NULL,
            `name`        VARCHAR(200) NOT NULL,
            `folder_type` ENUM('custom','athlete') NOT NULL DEFAULT 'custom',
            `athlete_id`  INT NULL,
            `sort_order`  INT NOT NULL DEFAULT 0,
            `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_gallery_folders_coach` (`coach_id`, `sort_order`),
            KEY `idx_gallery_folders_athlete` (`coach_id`, `athlete_id`),
            CONSTRAINT `fk_gallery_folders_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_gallery_folders_athlete`
                FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Soubory v galerii trenéra
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gallery_files` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`      INT NOT NULL,
            `folder_id`     INT NULL,
            `file_path`     VARCHAR(500) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `file_size`     INT NOT NULL DEFAULT 0,
            `file_type`     ENUM('image','video','document') NOT NULL DEFAULT 'document',
            `mime_type`     VARCHAR(100) NULL,
            `description`   TEXT NULL,
            `visibility`    ENUM('private','all_athletes','specific_athletes') NOT NULL DEFAULT 'private',
            `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_gallery_files_coach` (`coach_id`, `created_at`),
            KEY `idx_gallery_files_folder` (`folder_id`),
            CONSTRAINT `fk_gallery_files_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_gallery_files_folder`
                FOREIGN KEY (`folder_id`) REFERENCES `gallery_folders`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Konkrétní sportovci s přístupem k souboru (visibility = specific_athletes)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gallery_file_athletes` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `file_id`    INT NOT NULL,
            `athlete_id` INT NOT NULL,
            UNIQUE KEY `uq_gallery_file_athlete` (`file_id`, `athlete_id`),
            CONSTRAINT `fk_gallery_file_athletes_file`
                FOREIGN KEY (`file_id`) REFERENCES `gallery_files`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_gallery_file_athletes_athlete`
                FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Soubory admina (pro trenéry)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_gallery_files` (
            `id`                  INT AUTO_INCREMENT PRIMARY KEY,
            `file_path`           VARCHAR(500) NOT NULL,
            `original_name`       VARCHAR(255) NOT NULL,
            `file_size`           INT NOT NULL DEFAULT 0,
            `file_type`           ENUM('image','video','document') NOT NULL DEFAULT 'document',
            `mime_type`           VARCHAR(100) NULL,
            `description`         TEXT NULL,
            `visibility`          ENUM('all_coaches','specific_coaches') NOT NULL DEFAULT 'all_coaches',
            `uploaded_by_admin_id` INT NULL,
            `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_admin_gallery_files_created` (`created_at`),
            CONSTRAINT `fk_admin_gallery_files_admin`
                FOREIGN KEY (`uploaded_by_admin_id`) REFERENCES `superadmins`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Konkrétní trenéři s přístupem k souboru admina (visibility = specific_coaches)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_gallery_file_coaches` (
            `id`       INT AUTO_INCREMENT PRIMARY KEY,
            `file_id`  INT NOT NULL,
            `coach_id` INT NOT NULL,
            UNIQUE KEY `uq_admin_gallery_file_coach` (`file_id`, `coach_id`),
            CONSTRAINT `fk_admin_gallery_file_coaches_file`
                FOREIGN KEY (`file_id`) REFERENCES `admin_gallery_files`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_admin_gallery_file_coaches_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Migrace: vytvořit chybějící gallery_folders pro stávající sportovce
    $pdo->exec("
        INSERT IGNORE INTO `gallery_folders` (coach_id, name, folder_type, athlete_id)
        SELECT a.coach_id,
               CONCAT(a.first_name, ' ', a.last_name),
               'athlete',
               a.id
        FROM `athletes` a
        WHERE NOT EXISTS (
            SELECT 1 FROM `gallery_folders` gf
            WHERE gf.athlete_id = a.id AND gf.coach_id = a.coach_id
        )
    ");
    // Migrace gallery_folders – ve vlastním try/catch, aby neblokovala zbytek schématu
    try {
        $pdo->exec("
            INSERT IGNORE INTO `gallery_folders` (coach_id, name, folder_type, athlete_id)
            SELECT a.coach_id,
                   CONCAT(a.first_name, ' ', a.last_name),
                   'athlete',
                   a.id
            FROM `athletes` a
            WHERE NOT EXISTS (
                SELECT 1 FROM `gallery_folders` gf
                WHERE gf.athlete_id = a.id AND gf.coach_id = a.coach_id
            )
        ");
    } catch (Throwable $e) {
        error_log('gallery_folders migration error: ' . $e->getMessage());
    }
}

function normalizeTrainingVenueName(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    return mb_substr($name, 0, 255, 'UTF-8');
}

function getTrainingVenues(bool $includeInactive = false): array {
    $pdo = getDB();
    if ($includeInactive) {
        $stmt = $pdo->query(
            'SELECT *
             FROM `training_venues`
             ORDER BY `is_active` DESC, `name` ASC'
        );
        return $stmt->fetchAll();
    }

    $stmt = $pdo->query(
        'SELECT *
         FROM `training_venues`
         WHERE `is_active` = 1
         ORDER BY `name` ASC'
    );
    return $stmt->fetchAll();
}

function getTrainingVenuesForCoach(int $coachId, bool $includeInactive = false): array {
    $pdo = getDB();

    $globalSql = 'SELECT *
                  FROM `training_venues`
                  WHERE (`created_by_coach_id` IS NULL OR `created_by_coach_id` = ?)';
    if (!$includeInactive) {
        $globalSql .= ' AND `is_active` = 1';
    }
    $globalSql .= ' ORDER BY `is_active` DESC, `name` ASC';

    $globalStmt = $pdo->prepare($globalSql);
    $globalStmt->execute([$coachId]);
    $venues = $globalStmt->fetchAll();

    $privateSql = 'SELECT `id`, `coach_id`, `name`, `is_active`, `created_at`, `updated_at`
                   FROM `coach_training_venues`
                   WHERE `coach_id` = ?';
    if (!$includeInactive) {
        $privateSql .= ' AND `is_active` = 1';
    }
    $privateSql .= ' ORDER BY `is_active` DESC, `name` ASC';

    $privateStmt = $pdo->prepare($privateSql);
    $privateStmt->execute([$coachId]);
    $privateVenues = $privateStmt->fetchAll();

    $merged = [];
    foreach ($venues as $venue) {
        $name = trim((string)($venue['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $merged[mb_strtolower($name, 'UTF-8')] = $venue;
    }

    foreach ($privateVenues as $privateVenue) {
        $name = trim((string)($privateVenue['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $key = mb_strtolower($name, 'UTF-8');
        if (isset($merged[$key])) {
            continue;
        }

        $merged[$key] = [
            'id' => (int)$privateVenue['id'],
            'name' => $name,
            'address' => null,
            'note' => null,
            'is_active' => (int)$privateVenue['is_active'],
            'created_by_coach_id' => $coachId,
            'created_at' => $privateVenue['created_at'] ?? null,
            'updated_at' => $privateVenue['updated_at'] ?? null,
            'is_private_for_coach' => 1,
        ];
    }

    $result = array_values($merged);
    usort($result, static function (array $a, array $b): int {
        $aActive = (int)($a['is_active'] ?? 0);
        $bActive = (int)($b['is_active'] ?? 0);
        if ($aActive !== $bActive) {
            return $bActive <=> $aActive;
        }
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    return $result;
}

function rememberTrainingVenue(string $name, ?int $createdByCoachId = null): ?int {
    $name = normalizeTrainingVenueName($name);
    if ($name === '') {
        return null;
    }

    $pdo = getDB();

    if ($createdByCoachId !== null) {
        $existingStmt = $pdo->prepare(
            'SELECT `id`, `created_by_coach_id`, `is_active`
             FROM `training_venues`
             WHERE `name` = ? AND (`created_by_coach_id` IS NULL OR `created_by_coach_id` = ?)
             ORDER BY CASE WHEN `created_by_coach_id` IS NULL THEN 0 ELSE 1 END, `id` ASC
             LIMIT 1'
        );
        $existingStmt->execute([$name, $createdByCoachId]);
        $existingVenue = $existingStmt->fetch();

        if ($existingVenue) {
            if ($existingVenue['created_by_coach_id'] !== null && (int)$existingVenue['is_active'] === 0) {
                $pdo->prepare('UPDATE `training_venues` SET `is_active` = 1, `updated_at` = NOW() WHERE `id` = ?')
                    ->execute([(int)$existingVenue['id']]);
            }
            return (int)$existingVenue['id'];
        }

        $privateStmt = $pdo->prepare(
            'INSERT INTO `coach_training_venues` (`coach_id`, `name`, `is_active`)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE
                `id` = LAST_INSERT_ID(`id`),
                `is_active` = 1,
                `updated_at` = NOW()'
        );
        $privateStmt->execute([$createdByCoachId, $name]);

        return (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare(
        'INSERT INTO `training_venues` (`name`, `created_by_coach_id`, `is_active`)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE
            `id` = LAST_INSERT_ID(`id`),
            `is_active` = 1'
    );
    $stmt->execute([$name, $createdByCoachId]);

    return (int)$pdo->lastInsertId();
}

// ============================================================
// Speciální sporty – Běh na páse
// ============================================================

/**
 * Vytvoří záznam běhu na páse po startu tréninku
 */
function createRunTreadmillSession(int $sessionId, int $durationSeconds, float $distanceKm): int {
    $pdo = getDB();
    $stmt = $pdo->prepare('
        INSERT INTO `run_treadmill_sessions`
            (`session_id`, `duration_seconds`, `distance_km`, `started_at`, `created_at`)
        VALUES (?, ?, ?, NOW(), NOW())
    ');
    $stmt->execute([$sessionId, $durationSeconds, $distanceKm]);
    return (int)$pdo->lastInsertId();
}

function saveRunTreadmillSplits(int $runSessionId, array $splits): void {
    $pdo = getDB();
    $pdo->prepare('DELETE FROM `run_treadmill_splits` WHERE `run_session_id` = ?')->execute([$runSessionId]);

    if (empty($splits)) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO `run_treadmill_splits` (`run_session_id`, `km_marker`, `split_time`, `pace`)
         VALUES (?, ?, ?, ?)'
    );

    foreach ($splits as $split) {
        $ins->execute([
            $runSessionId,
            (float)$split['km_marker'],
            $split['split_time'],
            $split['pace'] ?? null,
        ]);
    }
}

function getRunTreadmillSplits(int $runSessionId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT * FROM `run_treadmill_splits`
         WHERE `run_session_id` = ?
         ORDER BY `km_marker` ASC'
    );
    $stmt->execute([$runSessionId]);
    return $stmt->fetchAll();
}

/**
 * Aktualizuje běh na páse (po ukončení, v detailu)
 */
function updateRunTreadmillSession(int $runSessionId, int $durationSeconds, float $distanceKm, ?int $caloriesBurned = null, ?string $location = null, ?string $feeling = null): bool {
    $pdo = getDB();
    $stmt = $pdo->prepare('
        UPDATE `run_treadmill_sessions`
        SET `duration_seconds` = ?,
            `distance_km` = ?,
            `calories_burned` = ?,
            `location` = ?,
            `feeling` = ?,
            `ended_at` = NOW(),
            `updated_at` = NOW()
        WHERE `id` = ?
    ');
    $stmt->execute([$durationSeconds, $distanceKm, $caloriesBurned, $location, $feeling, $runSessionId]);
    return $stmt->rowCount() > 0;
}

/**
 * Načte běh na páse z session_id
 */
function getRunTreadmillSessionByTrainingSession(int $sessionId): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM `run_treadmill_sessions` WHERE `session_id` = ?');
    $stmt->execute([$sessionId]);
    return $stmt->fetch() ?: null;
}

/**
 * Načte běh na páse z ID běhu
 */
function getRunTreadmillSession(int $runSessionId): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM `run_treadmill_sessions` WHERE `id` = ?');
    $stmt->execute([$runSessionId]);
    return $stmt->fetch() ?: null;
}

/**
 * Vrátí poslední běhy na páse pro sportovce
 */
function getRunTreadmillHistory(int $athleteId, int $limit = 10): array {
    $pdo = getDB();
    $stmt = $pdo->prepare('
        SELECT rts.*, ts.completed_at, ts.started_at as ts_started_at
        FROM `run_treadmill_sessions` rts
        JOIN `training_sessions` ts ON ts.id = rts.session_id
        WHERE ts.athlete_id = ? AND ts.completed_at IS NOT NULL
        ORDER BY ts.completed_at DESC
        LIMIT ?
    ');
    $stmt->execute([$athleteId, $limit]);
    return $stmt->fetchAll();
}

/**
 * Vypočítá statistiky běhu na páse (průměr, totál)
 */
function calculateRunTreadmillStats(int $athleteId, int $daysBack = 30): array {
    $pdo = getDB();
    $stmt = $pdo->prepare('
        SELECT 
            COUNT(*) as total_runs,
            SUM(rts.distance_km) as total_km,
            AVG(rts.distance_km) as avg_km,
            SUM(rts.calories_burned) as total_calories,
            SUM(rts.duration_seconds) as total_seconds
        FROM `run_treadmill_sessions` rts
        JOIN `training_sessions` ts ON ts.id = rts.session_id
        WHERE ts.athlete_id = ? 
            AND ts.completed_at IS NOT NULL
            AND ts.completed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ');
    $stmt->execute([$athleteId, $daysBack]);
    $row = $stmt->fetch();
    
    return [
        'total_runs'      => (int)($row['total_runs'] ?? 0),
        'total_km'        => (float)($row['total_km'] ?? 0),
        'avg_km'          => (float)($row['avg_km'] ?? 0),
        'total_calories'  => (int)($row['total_calories'] ?? 0),
        'total_seconds'   => (int)($row['total_seconds'] ?? 0),
        'avg_pace_seconds' => $row['total_seconds'] > 0 && $row['total_km'] > 0 
            ? (int)($row['total_seconds'] / $row['total_km']) 
            : 0,
    ];
}

// ============================================================
// Speciální sporty – Běh venku
// ============================================================

function createRunOutdoorSession(int $sessionId): int {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO `run_outdoor_sessions`
            (`session_id`, `duration_seconds`, `distance_km`, `run_type`, `surface`, `started_at`, `created_at`)
         VALUES (?, 0, 0, "free", "asphalt", NOW(), NOW())
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $stmt->execute([$sessionId]);
    return (int)$pdo->lastInsertId();
}

function updateRunOutdoorSession(
    int $runSessionId,
    int $durationSeconds,
    float $distanceKm,
    string $runType,
    string $surface,
    ?string $weather = null,
    ?float $maxSpeed = null,
    ?int $caloriesBurned = null,
    ?int $stepCount = null,
    ?int $rpe = null,
    ?float $tempoVariability = null,
    ?string $feeling = null
): bool {
    $avgPace = null;
    if ($durationSeconds > 0 && $distanceKm > 0) {
        $paceSeconds = (int)round($durationSeconds / $distanceKm);
        $avgPace = sprintf('%02d:%02d', intdiv($paceSeconds, 60), $paceSeconds % 60);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'UPDATE `run_outdoor_sessions`
         SET `duration_seconds` = ?,
             `distance_km` = ?,
             `run_type` = ?,
             `surface` = ?,
             `weather` = ?,
             `avg_pace` = ?,
             `max_speed` = ?,
             `calories_burned` = ?,
             `step_count` = ?,
             `rpe` = ?,
             `tempo_variability` = ?,
             `feeling` = ?,
             `ended_at` = NOW(),
             `updated_at` = NOW()
         WHERE `id` = ?'
    );

    $stmt->execute([
        max(0, $durationSeconds),
        max(0, $distanceKm),
        $runType,
        $surface,
        $weather,
        $avgPace,
        $maxSpeed,
        $caloriesBurned,
        $stepCount,
        $rpe,
        $tempoVariability,
        $feeling,
        $runSessionId,
    ]);
    return $stmt->rowCount() > 0;
}

function getRunOutdoorSessionByTrainingSession(int $sessionId): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM `run_outdoor_sessions` WHERE `session_id` = ?');
    $stmt->execute([$sessionId]);
    return $stmt->fetch() ?: null;
}

function saveRunOutdoorSplits(int $runSessionId, array $splits): void {
    $pdo = getDB();
    $pdo->prepare('DELETE FROM `run_outdoor_splits` WHERE `run_session_id` = ?')->execute([$runSessionId]);

    if (empty($splits)) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO `run_outdoor_splits` (`run_session_id`, `km_marker`, `split_time`, `pace`, `max_speed_at_km`)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($splits as $split) {
        $ins->execute([
            $runSessionId,
            (float)$split['km_marker'],
            $split['split_time'],
            $split['pace'] ?? null,
            $split['max_speed_at_km'] ?? null,
        ]);
    }
}

function getRunOutdoorSplits(int $runSessionId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT * FROM `run_outdoor_splits`
         WHERE `run_session_id` = ?
         ORDER BY `km_marker` ASC'
    );
    $stmt->execute([$runSessionId]);
    return $stmt->fetchAll();
}

function getRunOutdoorHistory(int $athleteId, int $limit = 10): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT ros.*, ts.completed_at, ts.started_at AS ts_started_at
         FROM `run_outdoor_sessions` ros
         JOIN `training_sessions` ts ON ts.id = ros.session_id
         WHERE ts.athlete_id = ?
           AND ts.completed_at IS NOT NULL
         ORDER BY ts.completed_at DESC
         LIMIT ?'
    );
    $stmt->execute([$athleteId, $limit]);
    return $stmt->fetchAll();
}

function calculateRunOutdoorStats(int $athleteId, int $daysBack = 30): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) as total_runs,
                SUM(ros.distance_km) as total_km,
                AVG(ros.distance_km) as avg_km,
                SUM(ros.calories_burned) as total_calories,
                SUM(ros.duration_seconds) as total_seconds
         FROM `run_outdoor_sessions` ros
         JOIN `training_sessions` ts ON ts.id = ros.session_id
         WHERE ts.athlete_id = ?
           AND ts.completed_at IS NOT NULL
           AND ts.completed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    $stmt->execute([$athleteId, $daysBack]);
    $row = $stmt->fetch();

    return [
        'total_runs'       => (int)($row['total_runs'] ?? 0),
        'total_km'         => (float)($row['total_km'] ?? 0),
        'avg_km'           => (float)($row['avg_km'] ?? 0),
        'total_calories'   => (int)($row['total_calories'] ?? 0),
        'total_seconds'    => (int)($row['total_seconds'] ?? 0),
        'avg_pace_seconds' => ($row['total_seconds'] ?? 0) > 0 && ($row['total_km'] ?? 0) > 0
            ? (int)($row['total_seconds'] / $row['total_km'])
            : 0,
    ];
}

// ============================================================
// Speciální sporty – Golf
// ============================================================

function createGolfSession(int $sessionId): int {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO `golf_sessions`
            (`session_id`, `course_name`, `num_holes`, `game_type`, `started_at`, `created_at`)
         VALUES (?, "Nezadano", 18, "training", NOW(), NOW())
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $stmt->execute([$sessionId]);
    return (int)$pdo->lastInsertId();
}

function updateGolfSession(
    int $golfSessionId,
    string $courseName,
    int $numHoles,
    string $gameType,
    ?float $distanceKm = null,
    ?int $caloriesBurned = null,
    ?string $weather = null,
    ?string $players = null,
    ?float $handicapAfter = null,
    ?string $feeling = null,
    ?int $durationMinutes = null,
    ?int $courseId = null,
    ?int $teeId = null,
    ?string $teeName = null
): bool {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'UPDATE `golf_sessions`
         SET `course_id` = ?,
             `tee_id` = ?,
             `tee_name` = ?,
             `course_name` = ?,
             `num_holes` = ?,
             `game_type` = ?,
             `distance_km` = ?,
             `calories_burned` = ?,
             `weather` = ?,
             `players` = ?,
             `handicap_after` = ?,
             `feeling` = ?,
             `duration_minutes` = ?,
             `ended_at` = NOW(),
             `updated_at` = NOW()
         WHERE `id` = ?'
    );

    $stmt->execute([
        $courseId,
        $teeId,
        $teeName,
        $courseName,
        max(1, $numHoles),
        $gameType,
        $distanceKm,
        $caloriesBurned,
        $weather,
        $players,
        $handicapAfter,
        $feeling,
        $durationMinutes,
        $golfSessionId,
    ]);
    return $stmt->rowCount() > 0;
}

function updateGolfHandicapFields(
    int $golfSessionId,
    ?float $handicapBefore,
    bool $countForHandicap,
    ?float $courseRating,
    ?int $slopeRating,
    ?int $scoreTotal,
    ?float $scoreDifferential,
    ?float $handicapAfter
): bool {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'UPDATE `golf_sessions`
         SET `handicap_before` = ?,
             `count_for_handicap` = ?,
             `course_rating` = ?,
             `slope_rating` = ?,
             `score_total` = ?,
             `score_differential` = ?,
             `handicap_after` = ?,
             `updated_at` = NOW()
         WHERE `id` = ?'
    );

    $stmt->execute([
        $handicapBefore,
        $countForHandicap ? 1 : 0,
        $courseRating,
        $slopeRating,
        $scoreTotal,
        $scoreDifferential,
        $handicapAfter,
        $golfSessionId,
    ]);

    return $stmt->rowCount() > 0;
}

function getGolfSessionByTrainingSession(int $sessionId): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM `golf_sessions` WHERE `session_id` = ?');
    $stmt->execute([$sessionId]);
    return $stmt->fetch() ?: null;
}

function getGolfCourses(): array {
    $pdo = getDB();
    $stmt = $pdo->query(
        'SELECT gc.*, COUNT(gct.id) AS tees_count
         FROM `golf_courses` gc
         LEFT JOIN `golf_course_tees` gct ON gct.course_id = gc.id AND gct.is_active = 1
         WHERE gc.is_active = 1
         GROUP BY gc.id
         ORDER BY gc.name ASC'
    );
    return $stmt->fetchAll();
}

function getGolfCourseTees(int $courseId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT *
         FROM `golf_course_tees`
         WHERE `course_id` = ? AND `is_active` = 1
         ORDER BY `tee_name` ASC, `gender` ASC'
    );
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

function getGolfCourseTeeById(int $teeId): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT gct.*, gc.name AS course_name, gc.location AS course_location
         FROM `golf_course_tees` gct
         JOIN `golf_courses` gc ON gc.id = gct.course_id
         WHERE gct.id = ? AND gct.is_active = 1 AND gc.is_active = 1'
    );
    $stmt->execute([$teeId]);
    return $stmt->fetch() ?: null;
}

function getLatestCountedGolfHandicap(int $athleteId, ?int $excludeSessionId = null): ?float {
    $pdo = getDB();
    $sql = '
        SELECT gs.handicap_after
        FROM `golf_sessions` gs
        JOIN `training_sessions` ts ON ts.id = gs.session_id
        WHERE ts.athlete_id = ?
          AND ts.completed_at IS NOT NULL
          AND COALESCE(gs.count_for_handicap, 1) = 1
    ';
    $params = [$athleteId];
    if ($excludeSessionId !== null && $excludeSessionId > 0) {
        $sql .= ' AND gs.session_id <> ?';
        $params[] = $excludeSessionId;
    }
    $sql .= ' ORDER BY ts.completed_at DESC, gs.id DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return ($row && $row['handicap_after'] !== null) ? (float)$row['handicap_after'] : null;
}

function getGolfCountedDifferentials(int $athleteId, ?int $excludeSessionId = null, int $limit = 20): array {
    $pdo = getDB();
    $sql = '
        SELECT gs.score_differential
        FROM `golf_sessions` gs
        JOIN `training_sessions` ts ON ts.id = gs.session_id
        WHERE ts.athlete_id = ?
          AND ts.completed_at IS NOT NULL
          AND COALESCE(gs.count_for_handicap, 1) = 1
          AND gs.score_differential IS NOT NULL
    ';
    $params = [$athleteId];
    if ($excludeSessionId !== null && $excludeSessionId > 0) {
        $sql .= ' AND gs.session_id <> ?';
        $params[] = $excludeSessionId;
    }
    $sql .= ' ORDER BY ts.completed_at DESC, gs.id DESC LIMIT ?';
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['score_differential'] !== null) {
            $result[] = (float)$row['score_differential'];
        }
    }

    return $result;
}

function calculateGolfScoreTotal(array $holes): int {
    $total = 0;
    foreach ($holes as $hole) {
        $total += (int)($hole['score'] ?? 0);
    }
    return $total;
}

function calculateGolfScoreDifferential(?int $grossScore, ?float $courseRating, ?int $slopeRating): ?float {
    if ($grossScore === null || $courseRating === null || $slopeRating === null || $slopeRating <= 0) {
        return null;
    }

    return round((($grossScore - $courseRating) * 113) / $slopeRating, 1);
}

function calculateGolfHandicapIndexFromDifferentials(array $differentials, ?float $startingHandicap = null): ?float {
    if (empty($differentials)) {
        return $startingHandicap !== null ? round($startingHandicap, 1) : null;
    }

    sort($differentials, SORT_NUMERIC);
    $count = count($differentials);

    if ($count < 3) {
        $baseline = $startingHandicap !== null ? $startingHandicap : $differentials[0];
        $currentAvg = array_sum($differentials) / $count;
        return round(($baseline + $currentAvg) / 2, 1);
    }

    $useCount = 1;
    $adjustment = 0.0;

    if ($count === 3) {
        $useCount = 1;
        $adjustment = -2.0;
    } elseif ($count === 4) {
        $useCount = 1;
        $adjustment = -1.0;
    } elseif ($count === 5) {
        $useCount = 1;
        $adjustment = 0.0;
    } elseif ($count === 6) {
        $useCount = 2;
        $adjustment = -1.0;
    } elseif ($count <= 8) {
        $useCount = 2;
        $adjustment = 0.0;
    } elseif ($count <= 11) {
        $useCount = 3;
    } elseif ($count <= 14) {
        $useCount = 4;
    } elseif ($count <= 16) {
        $useCount = 5;
    } elseif ($count <= 18) {
        $useCount = 6;
    } elseif ($count === 19) {
        $useCount = 7;
    } else {
        $useCount = 8;
    }

    $selected = array_slice($differentials, 0, $useCount);
    return round((array_sum($selected) / $useCount) + $adjustment, 1);
}

function calculateGolfHandicapProjection(
    int $athleteId,
    int $sessionId,
    ?float $startingHandicap,
    ?float $courseRating,
    ?int $slopeRating,
    int $grossScore,
    bool $countForHandicap
): array {
    $differential = calculateGolfScoreDifferential($grossScore, $courseRating, $slopeRating);
    $previousHandicap = getLatestCountedGolfHandicap($athleteId, $sessionId);
    if ($previousHandicap === null) {
        $previousHandicap = $startingHandicap;
    }

    $differentials = getGolfCountedDifferentials($athleteId, $sessionId);
    if ($differential !== null) {
        array_unshift($differentials, $differential);
    }

    $handicapAfter = calculateGolfHandicapIndexFromDifferentials($differentials, $previousHandicap);

    return [
        'handicap_before'     => $previousHandicap !== null ? round($previousHandicap, 1) : null,
        'score_total'         => $grossScore,
        'score_differential'  => $differential,
        'handicap_after'      => $handicapAfter,
    ];
}

function saveGolfHoles(int $golfSessionId, array $holes): void {
    $pdo = getDB();
    $pdo->prepare('DELETE FROM `golf_holes` WHERE `golf_session_id` = ?')->execute([$golfSessionId]);

    if (empty($holes)) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO `golf_holes` (`golf_session_id`, `hole_number`, `par`, `score`, `notes`)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($holes as $hole) {
        $ins->execute([
            $golfSessionId,
            (int)$hole['hole_number'],
            (int)$hole['par'],
            $hole['score'],
            $hole['notes'] ?? null,
        ]);
    }
}

function getGolfHoles(int $golfSessionId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT * FROM `golf_holes`
         WHERE `golf_session_id` = ?
         ORDER BY `hole_number` ASC'
    );
    $stmt->execute([$golfSessionId]);
    return $stmt->fetchAll();
}

function getGolfHistory(int $athleteId, int $limit = 10): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
    'SELECT gs.*, ts.completed_at, ts.started_at AS ts_started_at,
                COALESCE(SUM(gh.score), 0) AS total_score,
                COALESCE(SUM(gh.par), 0) AS total_par
         FROM `golf_sessions` gs
         JOIN `training_sessions` ts ON ts.id = gs.session_id
         LEFT JOIN `golf_holes` gh ON gh.golf_session_id = gs.id
         WHERE ts.athlete_id = ?
           AND ts.completed_at IS NOT NULL
         GROUP BY gs.id, ts.completed_at, ts.started_at
         ORDER BY ts.completed_at DESC
         LIMIT ?'
    );
    $stmt->execute([$athleteId, $limit]);
    return $stmt->fetchAll();
}

function calculateGolfStats(int $athleteId, int $daysBack = 90): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT gs.id) AS total_rounds,
                AVG(gs.handicap_after) AS avg_handicap,
                COALESCE(SUM(gs.distance_km), 0) AS total_km,
                COALESCE(SUM(gs.calories_burned), 0) AS total_calories,
                COALESCE(SUM(gh.score), 0) AS total_score,
                COALESCE(SUM(gh.par), 0) AS total_par
         FROM `golf_sessions` gs
         JOIN `training_sessions` ts ON ts.id = gs.session_id
         LEFT JOIN `golf_holes` gh ON gh.golf_session_id = gs.id
         WHERE ts.athlete_id = ?
           AND ts.completed_at IS NOT NULL
                     AND ts.completed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                     AND COALESCE(gs.count_for_handicap, 1) = 1'
    );
    $stmt->execute([$athleteId, $daysBack]);
    $row = $stmt->fetch();

    return [
        'total_rounds'   => (int)($row['total_rounds'] ?? 0),
        'avg_handicap'   => $row['avg_handicap'] !== null ? (float)$row['avg_handicap'] : null,
        'total_km'       => (float)($row['total_km'] ?? 0),
        'total_calories' => (int)($row['total_calories'] ?? 0),
        'total_score'    => (int)($row['total_score'] ?? 0),
        'total_par'      => (int)($row['total_par'] ?? 0),
    ];
}

// ============================================================
// Tělesná hmotnost sportovců
// ============================================================

function addAthleteWeightLog(
    int $athleteId,
    string $measuredAt,
    float $weightKg,
    string $source = 'coach',
    ?int $createdByCoachId = null,
    ?int $inviteId = null
): int {
    $allowedSources = ['coach', 'athlete_link'];
    if (!in_array($source, $allowedSources, true)) {
        $source = 'coach';
    }

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO `athlete_weight_logs`
            (`athlete_id`, `measured_at`, `weight_kg`, `source`, `invite_id`, `created_by_coach_id`)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $athleteId,
        $measuredAt,
        round($weightKg, 2),
        $source,
        $inviteId,
        $createdByCoachId,
    ]);

    return (int)$pdo->lastInsertId();
}

function getAthleteWeightHistory(int $athleteId, int $limit = 365): array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT `id`, `athlete_id`, `measured_at`, `weight_kg`, `source`, `invite_id`, `created_by_coach_id`, `created_at`
         FROM `athlete_weight_logs`
         WHERE `athlete_id` = ?
         ORDER BY `measured_at` ASC, `id` ASC
         LIMIT ?'
    );
    $stmt->execute([$athleteId, max(1, $limit)]);
    return $stmt->fetchAll();
}

function getAthleteWeightStats(int $athleteId): array {
    $history = getAthleteWeightHistory($athleteId, 2000);
    if (empty($history)) {
        return [
            'entries' => 0,
            'first_weight' => null,
            'first_date' => null,
            'current_weight' => null,
            'current_date' => null,
            'change_kg' => null,
            'change_percent' => null,
        ];
    }

    $first = $history[0];
    $last = $history[count($history) - 1];
    $firstWeight = (float)$first['weight_kg'];
    $currentWeight = (float)$last['weight_kg'];
    $changeKg = round($currentWeight - $firstWeight, 2);
    $changePercent = $firstWeight > 0
        ? round(($changeKg / $firstWeight) * 100, 1)
        : null;

    return [
        'entries' => count($history),
        'first_weight' => $firstWeight,
        'first_date' => (string)$first['measured_at'],
        'current_weight' => $currentWeight,
        'current_date' => (string)$last['measured_at'],
        'change_kg' => $changeKg,
        'change_percent' => $changePercent,
    ];
}

function createAthleteWeightInvite(int $athleteId, int $coachId, string $email, int $validHours = 72): array {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + (max(1, $validHours) * 3600));

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO `athlete_weight_invites`
            (`athlete_id`, `coach_id`, `email`, `token_hash`, `expires_at`)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $athleteId,
        $coachId,
        $email,
        $tokenHash,
        $expiresAt,
    ]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'token' => $token,
        'expires_at' => $expiresAt,
    ];
}

function getAthleteWeightInviteByToken(string $token): ?array {
    if ($token === '') {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT awi.*, a.first_name, a.last_name
         FROM `athlete_weight_invites` awi
         JOIN `athletes` a ON a.id = awi.athlete_id
         WHERE awi.token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $isExpired = strtotime((string)$row['expires_at']) < time();
    if ($isExpired || !empty($row['used_at'])) {
        return null;
    }

    return $row;
}

function markAthleteWeightInviteUsed(int $inviteId): void {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'UPDATE `athlete_weight_invites`
         SET `used_at` = NOW()
         WHERE `id` = ? AND `used_at` IS NULL'
    );
    $stmt->execute([$inviteId]);
}

function createPasswordResetRequest(
    string $userType,
    ?int $coachId,
    ?int $athleteId,
    string $email,
    int $validMinutes = 60
): array {
    $userType = $userType === 'athlete' ? 'athlete' : 'coach';
    $coachId = $userType === 'coach' ? (int)$coachId : null;
    $athleteId = $userType === 'athlete' ? (int)$athleteId : null;

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + (max(5, $validMinutes) * 60));

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO `password_reset_requests`
            (`user_type`, `coach_id`, `athlete_id`, `email`, `token_hash`, `expires_at`)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userType,
        $coachId,
        $athleteId,
        mb_strtolower(trim($email), 'UTF-8'),
        $tokenHash,
        $expiresAt,
    ]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'token' => $token,
        'expires_at' => $expiresAt,
    ];
}

function getPasswordResetRequestByToken(string $token): ?array {
    if ($token === '') {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT *
         FROM `password_reset_requests`
         WHERE `token_hash` = ?
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $isExpired = strtotime((string)$row['expires_at']) < time();
    if ($isExpired || !empty($row['used_at'])) {
        return null;
    }

    return $row;
}

function markPasswordResetRequestUsed(int $requestId): void {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'UPDATE `password_reset_requests`
         SET `used_at` = NOW()
         WHERE `id` = ? AND `used_at` IS NULL'
    );
    $stmt->execute([$requestId]);
}