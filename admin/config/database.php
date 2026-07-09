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
            UNIQUE KEY `uniq_session_exercise` (`session_id`, `exercise_id`),
            KEY `idx_session_order` (`session_id`, `exercise_order`),
            CONSTRAINT `fk_tse_session`
                FOREIGN KEY (`session_id`) REFERENCES `training_sessions`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_tse_exercise`
                FOREIGN KEY (`exercise_id`) REFERENCES `exercises`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Tel. kontakt pro sportovce
    $stmtPhone = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'phone_contact'");
    if (!$stmtPhone->fetch()) {
        $pdo->exec('ALTER TABLE athletes ADD COLUMN phone_contact VARCHAR(20) NULL AFTER birth_date');
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

    // Číslo účtu trenéra pro QR platby
    $stmtCoachBank = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'bank_account'");
    if (!$stmtCoachBank->fetch()) {
        $pdo->exec('ALTER TABLE coaches ADD COLUMN bank_account VARCHAR(64) NULL AFTER email');
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

    // Kategorie svalových partií pro filtrování cviků (JSON pole)
    $stmtExerciseMuscleCategories = $pdo->query("SHOW COLUMNS FROM exercises LIKE 'muscle_categories'");
    if (!$stmtExerciseMuscleCategories->fetch()) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN muscle_categories TEXT NULL AFTER is_global');
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
        // constraint uz existuje nebo ji nelze doplnit kvuli starsim datum
    }

    try {
        $pdo->exec('ALTER TABLE coach_calendar_events ADD CONSTRAINT fk_calendar_events_second_athlete FOREIGN KEY (second_athlete_id) REFERENCES athletes(id) ON DELETE SET NULL');
    } catch (Throwable $e) {
        // constraint uz existuje nebo ji nelze doplnit kvuli starsim datum
    }

    $pdo->exec(" 
        CREATE TABLE IF NOT EXISTS `athlete_monthly_payments` (
            `id`               INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`         INT NOT NULL,
            `athlete_id`       INT NOT NULL,
            `billing_month`    DATE NOT NULL,
            `session_rate`     DECIMAL(10,2) NULL,
            `planned_sessions` INT NOT NULL DEFAULT 0,
            `carryover_used_sessions` INT NOT NULL DEFAULT 0,
            `billed_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0,
            `status`           ENUM('pending','paid') NOT NULL DEFAULT 'pending',
            `paid_at`          DATETIME NULL,
            `note`             TEXT NULL,
            `receipt_snapshot_json` LONGTEXT NULL,
            `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_athlete_monthly_payment` (`coach_id`, `athlete_id`, `billing_month`),
            KEY `idx_athlete_monthly_payment_month` (`coach_id`, `billing_month`, `status`),
            CONSTRAINT `fk_athlete_monthly_payment_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_athlete_monthly_payment_athlete`
                FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
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

    $stmtAdminTwoFactorSkip = $pdo->query("SHOW COLUMNS FROM superadmins LIKE 'two_factor_skip_until'");
    if (!$stmtAdminTwoFactorSkip->fetch()) {
        $pdo->exec('ALTER TABLE superadmins ADD COLUMN two_factor_skip_until DATETIME NULL AFTER two_factor_enabled');
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
            `custom_title`          VARCHAR(140) NULL,
            `location`              VARCHAR(255) NULL,
            `starts_at`             DATETIME NOT NULL,
            `ends_at`               DATETIME NOT NULL,
            `canceled_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_calendar_cancel_coach_start` (`coach_id`, `starts_at`),
            KEY `idx_calendar_cancel_athlete_start` (`athlete_id`, `starts_at`),
            KEY `idx_calendar_cancel_second_athlete_start` (`second_athlete_id`, `starts_at`),
            KEY `idx_calendar_cancel_canceled_at` (`canceled_at`),
            FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`second_athlete_id`) REFERENCES `athletes`(`id`) ON DELETE SET NULL,
            FOREIGN KEY (`canceled_by_athlete_id`) REFERENCES `athletes`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

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

    // Galerie – složky trenéra
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gallery_folders` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`    INT NOT NULL,
            `name`        VARCHAR(200) NOT NULL,
            `folder_type` ENUM('custom','athlete') NOT NULL DEFAULT 'custom',
            `athlete_id`  INT NULL,
            `sort_order`  INT NOT NULL DEFAULT 0,
            `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_gallery_folders_coach` (`coach_id`),
            FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Galerie – soubory trenéra
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gallery_files` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`      INT NOT NULL,
            `folder_id`     INT NULL,
            `file_path`     VARCHAR(500) NOT NULL,
            `original_name` VARCHAR(500) NOT NULL,
            `file_size`     BIGINT NOT NULL DEFAULT 0,
            `file_type`     ENUM('image','video','document') NOT NULL DEFAULT 'document',
            `mime_type`     VARCHAR(200) NULL,
            `description`   TEXT NULL,
            `visibility`    ENUM('private','all_athletes','specific_athletes') NOT NULL DEFAULT 'private',
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_gallery_files_coach` (`coach_id`),
            KEY `idx_gallery_files_folder` (`folder_id`),
            FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`folder_id`) REFERENCES `gallery_folders`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Galerie – viditelnost souboru pro konkrétní sportovce
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `gallery_file_athletes` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `file_id`    INT NOT NULL,
            `athlete_id` INT NOT NULL,
            UNIQUE KEY `uq_gallery_file_athlete` (`file_id`, `athlete_id`),
            FOREIGN KEY (`file_id`) REFERENCES `gallery_files`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Galerie administrátora
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_gallery_files` (
            `id`                  INT AUTO_INCREMENT PRIMARY KEY,
            `file_path`           VARCHAR(500) NOT NULL,
            `original_name`       VARCHAR(500) NOT NULL,
            `file_size`           BIGINT NOT NULL DEFAULT 0,
            `file_type`           ENUM('image','video','document') NOT NULL DEFAULT 'document',
            `mime_type`           VARCHAR(200) NULL,
            `description`         TEXT NULL,
            `visibility`          ENUM('all_coaches','specific_coaches') NOT NULL DEFAULT 'all_coaches',
            `uploaded_by_admin_id` INT NULL,
            `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Galerie administrátora – viditelnost pro konkrétní trenéry
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `admin_gallery_file_coaches` (
            `id`       INT AUTO_INCREMENT PRIMARY KEY,
            `file_id`  INT NOT NULL,
            `coach_id` INT NOT NULL,
            UNIQUE KEY `uq_admin_gallery_file_coach` (`file_id`, `coach_id`),
            CONSTRAINT `fk_admin_gc_file`
                FOREIGN KEY (`file_id`) REFERENCES `admin_gallery_files`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_admin_gc_coach`
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
        error_log('gallery_folders migration error (admin): ' . $e->getMessage());
    }
}