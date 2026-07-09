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
        if (!in_array('localhost', $hosts, true)) {
            $hosts[] = 'localhost';
        }
        if (!in_array('127.0.0.1', $hosts, true)) {
            $hosts[] = '127.0.0.1';
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

    // Vychozi globalni cviky pro specialni sporty
    $specialGlobalExercises = [
        ['name' => 'Golf', 'sport_type' => 'golf'],
    ];
    $stmtSpecialExists = $pdo->prepare(
        'SELECT COUNT(*) FROM exercises WHERE is_global = 1 AND sport_type = ?'
    );
    $stmtSpecialInsert = $pdo->prepare(
        'INSERT INTO exercises (coach_id, name, photo, sport_type, is_global) VALUES (NULL, ?, NULL, ?, 1)'
    );
    foreach ($specialGlobalExercises as $specialExercise) {
        $stmtSpecialExists->execute([$specialExercise['sport_type']]);
        if ((int)$stmtSpecialExists->fetchColumn() === 0) {
            $stmtSpecialInsert->execute([$specialExercise['name'], $specialExercise['sport_type']]);
        }
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

function rememberTrainingVenue(string $name, ?int $createdByCoachId = null): ?int {
    $name = normalizeTrainingVenueName($name);
    if ($name === '') {
        return null;
    }

    $pdo = getDB();
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