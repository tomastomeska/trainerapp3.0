<?php
/**
 * MyCoach App – migrace DB schématu
 * Spuštění: php scripts/migrate_mycoach_app.php
 * nebo přes URL: /scripts/migrate_mycoach_app.php?secret=TRAINERAPP_MIGRATE_SECRET
 */

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/functions.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    $secret   = getCronSecret();
    $provided = trim((string)($_GET['secret'] ?? ''));
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        exit('Unauthorized');
    }
}

$pdo = getDB();

$log = [];
$errors = [];

function migLog(array &$log, string $msg): void {
    $log[] = $msg;
    if (PHP_SAPI === 'cli') {
        echo $msg . "\n";
    }
}

function migExec(PDO $pdo, string $sql, array &$log, array &$errors, string $label): void {
    try {
        $pdo->exec($sql);
        migLog($log, "  [OK] $label");
    } catch (Throwable $e) {
        $errors[] = "$label: " . $e->getMessage();
        migLog($log, "  [ERR] $label – " . $e->getMessage());
    }
}

// ─── Tabulka přístupů / trial / předplatné ─────────────────────────────────
migExec($pdo, "
CREATE TABLE IF NOT EXISTS mycoach_app_access (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_type       ENUM('coach','athlete') NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  trial_started_at DATETIME DEFAULT NULL,
  trial_used      TINYINT(1) NOT NULL DEFAULT 0,
  subscription_start DATE DEFAULT NULL,
  subscription_end   DATE DEFAULT NULL,
  notes           TEXT DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user (user_type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", $log, $errors, 'mycoach_app_access');

// ─── Sekce / dlaždice obsahu ────────────────────────────────────────────────
migExec($pdo, "
CREATE TABLE IF NOT EXISTS mycoach_app_sections (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title        VARCHAR(200) NOT NULL,
  subtitle     VARCHAR(500) DEFAULT NULL,
  icon_class   VARCHAR(100) NOT NULL DEFAULT 'fa-play-circle',
  tile_color   VARCHAR(80) NOT NULL DEFAULT 'orange',
  bg_image     VARCHAR(1000) DEFAULT NULL,
  section_type ENUM('videos','workout','exercises','article','foods','mixed') NOT NULL DEFAULT 'videos',
  description  TEXT DEFAULT NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  audience     ENUM('all','coach','athlete') NOT NULL DEFAULT 'all',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", $log, $errors, 'mycoach_app_sections');

// ─── Videa ──────────────────────────────────────────────────────────────────
migExec($pdo, "
CREATE TABLE IF NOT EXISTS mycoach_app_videos (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id       INT UNSIGNED DEFAULT NULL,
  title            VARCHAR(300) NOT NULL,
  description      TEXT DEFAULT NULL,
  video_type       ENUM('upload','youtube','vimeo','url') NOT NULL DEFAULT 'upload',
  video_url        VARCHAR(1000) DEFAULT NULL,
  video_path       VARCHAR(1000) DEFAULT NULL,
  thumbnail        VARCHAR(1000) DEFAULT NULL,
  duration_seconds INT UNSIGNED DEFAULT NULL,
  tags             VARCHAR(500) DEFAULT NULL,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_section (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", $log, $errors, 'mycoach_app_videos');

// ─── Progres přehrávání videa ───────────────────────────────────────────────
migExec($pdo, "
CREATE TABLE IF NOT EXISTS mycoach_app_video_progress (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_type        ENUM('coach','athlete') NOT NULL,
  user_id          INT UNSIGNED NOT NULL,
  video_id         INT UNSIGNED NOT NULL,
  watched_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
  duration_seconds INT UNSIGNED DEFAULT NULL,
  is_completed     TINYINT(1) NOT NULL DEFAULT 0,
  last_watched_at  DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_progress (user_type, user_id, video_id),
  KEY idx_video (video_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", $log, $errors, 'mycoach_app_video_progress');

// ─── Encyklopedie cviků ─────────────────────────────────────────────────────
migExec($pdo, "
CREATE TABLE IF NOT EXISTS mycoach_app_exercises (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(300) NOT NULL,
  slug           VARCHAR(300) NOT NULL,
  category       VARCHAR(100) DEFAULT NULL,
  description    TEXT DEFAULT NULL,
  instructions   TEXT DEFAULT NULL,
  muscle_groups  VARCHAR(500) DEFAULT NULL,
  equipment      VARCHAR(300) DEFAULT NULL,
  difficulty     ENUM('beginner','intermediate','advanced') NOT NULL DEFAULT 'intermediate',
  video_url      VARCHAR(1000) DEFAULT NULL,
  thumbnail      VARCHAR(1000) DEFAULT NULL,
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_slug (slug),
  KEY idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", $log, $errors, 'mycoach_app_exercises');

// ─── Tréninky (inline workout templates) ────────────────────────────────────
migExec($pdo, "
CREATE TABLE IF NOT EXISTS mycoach_app_workouts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id       INT UNSIGNED DEFAULT NULL,
  title            VARCHAR(300) NOT NULL,
  description      TEXT DEFAULT NULL,
  difficulty       ENUM('beginner','intermediate','advanced') NOT NULL DEFAULT 'intermediate',
  duration_minutes SMALLINT UNSIGNED DEFAULT NULL,
  thumbnail        VARCHAR(1000) DEFAULT NULL,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_section (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", $log, $errors, 'mycoach_app_workouts');

// ─── Cviky v tréninku ───────────────────────────────────────────────────────
migExec($pdo, "
CREATE TABLE IF NOT EXISTS mycoach_app_workout_exercises (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  workout_id       INT UNSIGNED NOT NULL,
  exercise_id      INT UNSIGNED NOT NULL,
  sets             TINYINT UNSIGNED DEFAULT NULL,
  reps             TINYINT UNSIGNED DEFAULT NULL,
  duration_seconds SMALLINT UNSIGNED DEFAULT NULL,
  rest_seconds     SMALLINT UNSIGNED DEFAULT NULL,
  notes            VARCHAR(500) DEFAULT NULL,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_workout (workout_id),
  KEY idx_exercise (exercise_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", $log, $errors, 'mycoach_app_workout_exercises');

// ─── Databáze potravin ──────────────────────────────────────────────────────
migExec($pdo, "
CREATE TABLE IF NOT EXISTS mycoach_app_foods (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                VARCHAR(300) NOT NULL,
  category            VARCHAR(100) DEFAULT NULL,
  description         TEXT DEFAULT NULL,
  calories_per_100g   DECIMAL(8,2) DEFAULT NULL,
  protein_g           DECIMAL(8,2) DEFAULT NULL,
  carbs_g             DECIMAL(8,2) DEFAULT NULL,
  fat_g               DECIMAL(8,2) DEFAULT NULL,
  fiber_g             DECIMAL(8,2) DEFAULT NULL,
  thumbnail           VARCHAR(1000) DEFAULT NULL,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", $log, $errors, 'mycoach_app_foods');

// ─── app_settings výchozí hodnoty ──────────────────────────────────────────
try {
    $pdo->exec("
        INSERT INTO app_settings (`key`, `value`)
        VALUES ('mycoach_app_status', 'development')
        ON DUPLICATE KEY UPDATE `key` = `key`
    ");
    migLog($log, '  [OK] app_settings: mycoach_app_status (default=development)');
} catch (Throwable $e) {
    $errors[] = 'app_settings default: ' . $e->getMessage();
    migLog($log, '  [ERR] app_settings: ' . $e->getMessage());
}

try {
    $pdo->exec("
        INSERT INTO app_settings (`key`, `value`)
        VALUES ('mycoach_app_trial_days', '3')
        ON DUPLICATE KEY UPDATE `key` = `key`
    ");
    migLog($log, '  [OK] app_settings: mycoach_app_trial_days (default=3)');
} catch (Throwable $e) {
    $errors[] = 'app_settings trial: ' . $e->getMessage();
}

// ─── Výsledek ───────────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "\n=== MyCoach App Migration ===\n";
echo implode("\n", $log) . "\n";

if ($errors) {
    echo "\nCHYBY:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
    exit(1);
} else {
    echo "\nMigrace dokončena bez chyb.\n";
    exit(0);
}
