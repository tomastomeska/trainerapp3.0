<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$mycoachAccessModeKey = 'mycoach_access_mode';

if (!function_exists('adminMyCoachNormalizeMode')) {
    function adminMyCoachNormalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));
        if (!in_array($normalized, ['disabled', 'all', 'selected'], true)) {
            return 'selected';
        }
        return $normalized;
    }
}

if (!function_exists('adminEnsureMyCoachAccessColumns')) {
    function adminEnsureMyCoachAccessColumns(PDO $pdo): void
    {
        try {
            $coachColumn = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'mycoach_enabled'");
            if ($coachColumn !== false && !$coachColumn->fetch()) {
                $pdo->exec('ALTER TABLE coaches ADD COLUMN mycoach_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER special_training_enabled');
            }
        } catch (Throwable $e) {
            // Ignore schema hardening failure, page will continue in best-effort mode.
        }

        try {
            $athleteColumn = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'mycoach_enabled'");
            if ($athleteColumn !== false && !$athleteColumn->fetch()) {
                $pdo->exec('ALTER TABLE athletes ADD COLUMN mycoach_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER special_training_enabled');
            }
        } catch (Throwable $e) {
            // Ignore schema hardening failure, page will continue in best-effort mode.
        }
    }
}

adminEnsureMyCoachAccessColumns($pdo);

$selectedCoachFilter = intParam($_GET, 'coach_id');
$search = trim((string)($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'run_migration') {
        $migTables = [
            'mycoach_app_access' => "CREATE TABLE IF NOT EXISTS mycoach_app_access (
              id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
              user_type        ENUM('coach','athlete') NOT NULL,
              user_id          INT UNSIGNED NOT NULL,
              trial_started_at DATETIME DEFAULT NULL,
              trial_used       TINYINT(1) NOT NULL DEFAULT 0,
              subscription_start DATE DEFAULT NULL,
              subscription_end   DATE DEFAULT NULL,
              notes            TEXT DEFAULT NULL,
              created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_user (user_type, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'mycoach_app_sections' => "CREATE TABLE IF NOT EXISTS mycoach_app_sections (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'mycoach_app_videos' => "CREATE TABLE IF NOT EXISTS mycoach_app_videos (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'mycoach_app_video_progress' => "CREATE TABLE IF NOT EXISTS mycoach_app_video_progress (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'mycoach_app_exercises' => "CREATE TABLE IF NOT EXISTS mycoach_app_exercises (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'mycoach_app_workouts' => "CREATE TABLE IF NOT EXISTS mycoach_app_workouts (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'mycoach_app_workout_exercises' => "CREATE TABLE IF NOT EXISTS mycoach_app_workout_exercises (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'mycoach_app_foods' => "CREATE TABLE IF NOT EXISTS mycoach_app_foods (
              id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
              name              VARCHAR(300) NOT NULL,
              category          VARCHAR(100) DEFAULT NULL,
              description       TEXT DEFAULT NULL,
              calories_per_100g DECIMAL(8,2) DEFAULT NULL,
              protein_g         DECIMAL(8,2) DEFAULT NULL,
              carbs_g           DECIMAL(8,2) DEFAULT NULL,
              fat_g             DECIMAL(8,2) DEFAULT NULL,
              fiber_g           DECIMAL(8,2) DEFAULT NULL,
              thumbnail         VARCHAR(1000) DEFAULT NULL,
              is_active         TINYINT(1) NOT NULL DEFAULT 1,
              sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'mycoach_app_section_items' => "CREATE TABLE IF NOT EXISTS mycoach_app_section_items (
              id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
              section_id       INT UNSIGNED NOT NULL,
              item_type        ENUM('video_youtube','video_vimeo','video_upload','link','article','image') NOT NULL DEFAULT 'video_youtube',
              title            VARCHAR(300) NOT NULL,
              description      TEXT DEFAULT NULL,
              url              VARCHAR(2000) DEFAULT NULL,
              file_path        VARCHAR(1000) DEFAULT NULL,
              thumbnail        VARCHAR(1000) DEFAULT NULL,
              duration_seconds INT UNSIGNED DEFAULT NULL,
              content          MEDIUMTEXT DEFAULT NULL,
              sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              is_active        TINYINT(1) NOT NULL DEFAULT 1,
              created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_section (section_id, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'mycoach_app_subscription_plans' => "CREATE TABLE IF NOT EXISTS mycoach_app_subscription_plans (
              id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
              name        VARCHAR(200) NOT NULL,
              days        INT UNSIGNED NOT NULL,
              price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              currency    VARCHAR(10) NOT NULL DEFAULT 'CZK',
              description TEXT DEFAULT NULL,
              sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              is_active   TINYINT(1) NOT NULL DEFAULT 1,
              created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        $migErrors = [];
        foreach ($migTables as $tableName => $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                $migErrors[] = $tableName . ': ' . $e->getMessage();
            }
        }

        // app_settings defaults (nezahazovat existující hodnoty)
        try {
            $pdo->exec("INSERT INTO app_settings (`key`,`value`) VALUES ('mycoach_app_status','development') ON DUPLICATE KEY UPDATE `key`=`key`");
            $pdo->exec("INSERT INTO app_settings (`key`,`value`) VALUES ('mycoach_app_trial_days','3') ON DUPLICATE KEY UPDATE `key`=`key`");
        } catch (Throwable $e) {
            $migErrors[] = 'app_settings: ' . $e->getMessage();
        }

        if (empty($migErrors)) {
            flash('success', 'Migrace MyCoach App proběhla úspěšně – všechny tabulky byly vytvořeny.');
        } else {
            flash('danger', 'Migrace dokončena s chybami: ' . implode('; ', $migErrors));
        }
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'save_mode') {
        $mode = adminMyCoachNormalizeMode((string)($_POST['mycoach_access_mode'] ?? 'selected'));
        $pdo->prepare(
            'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        )->execute([$mycoachAccessModeKey, $mode]);

        $message = 'MyCoach režim byl uložen.';
        if ($mode === 'disabled') {
            $message = 'MyCoach je nyní globálně vypnutý pro všechny.';
        } elseif ($mode === 'all') {
            $message = 'MyCoach je nyní globálně zapnutý pro všechny.';
        } elseif ($mode === 'selected') {
            $message = 'MyCoach je nyní povolen jen vybraným uživatelům.';
        }

        flash('success', $message);
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'set_coach_access') {
        $coachId = intParam($_POST, 'coach_id');
        $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        if ($coachId > 0) {
            $pdo->prepare('UPDATE coaches SET mycoach_enabled = ? WHERE id = ?')->execute([$enabled, $coachId]);
            flash('success', $enabled === 1 ? 'MyCoach byl trenérovi povolen.' : 'MyCoach byl trenérovi vypnut.');
        }
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'set_athlete_access') {
        $athleteId = intParam($_POST, 'athlete_id');
        $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        if ($athleteId > 0) {
            $pdo->prepare('UPDATE athletes SET mycoach_enabled = ? WHERE id = ?')->execute([$enabled, $athleteId]);
            flash('success', $enabled === 1 ? 'MyCoach byl sportovci povolen.' : 'MyCoach byl sportovci vypnut.');
        }

        $redirectUrl = BASE_URL . '/admin/mycoach.php';
        if ($selectedCoachFilter > 0) {
            $redirectUrl .= '?coach_id=' . $selectedCoachFilter;
        }
        redirect($redirectUrl);
    }

    if ($action === 'set_all_coaches_access') {
        $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        $pdo->prepare('UPDATE coaches SET mycoach_enabled = ?')->execute([$enabled]);
        flash('success', $enabled === 1 ? 'MyCoach byl povolen všem trenérům.' : 'MyCoach byl vypnut všem trenérům.');
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'set_all_athletes_access') {
        $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        if ($selectedCoachFilter > 0) {
            $pdo->prepare('UPDATE athletes SET mycoach_enabled = ? WHERE coach_id = ?')->execute([$enabled, $selectedCoachFilter]);
            flash('success', $enabled === 1 ? 'MyCoach byl povolen všem sportovcům vybraného trenéra.' : 'MyCoach byl vypnut všem sportovcům vybraného trenéra.');
        } else {
            $pdo->prepare('UPDATE athletes SET mycoach_enabled = ?')->execute([$enabled]);
            flash('success', $enabled === 1 ? 'MyCoach byl povolen všem sportovcům.' : 'MyCoach byl vypnut všem sportovcům.');
        }

        $redirectUrl = BASE_URL . '/admin/mycoach.php';
        if ($selectedCoachFilter > 0) {
            $redirectUrl .= '?coach_id=' . $selectedCoachFilter;
        }
        redirect($redirectUrl);
    }

    // ── MyCoach App status (development / live) ─────────────
    if ($action === 'save_app_status') {
        $newStatus = in_array(trim((string)($_POST['mycoach_app_status'] ?? '')), ['development', 'live'], true)
            ? trim((string)$_POST['mycoach_app_status'])
            : 'development';
        $pdo->prepare("INSERT INTO app_settings (`key`,`value`) VALUES ('mycoach_app_status',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")
            ->execute([$newStatus]);
        flash('success', $newStatus === 'live'
            ? 'MyCoach App je nyní LIVE – uživatelé mohou vstoupit a aktivovat trial/předplatné.'
            : 'MyCoach App je přepnuta zpět do režimu Ve vývoji.');
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    // ── Správa předplatného / trialu ─────────────────────────
    if ($action === 'grant_subscription') {
        $subType  = in_array(trim((string)($_POST['sub_user_type'] ?? '')), ['coach','athlete'], true) ? trim((string)$_POST['sub_user_type']) : '';
        $subId    = (int)($_POST['sub_user_id'] ?? 0);
        $subStart = trim((string)($_POST['sub_start'] ?? date('Y-m-d')));
        $subEnd   = trim((string)($_POST['sub_end'] ?? ''));
        $subNotes = trim((string)($_POST['sub_notes'] ?? ''));
        if ($subType && $subId > 0 && $subEnd !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $subStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $subEnd)) {
                $granted = mycoachAppGrantSubscription($pdo, $subType, $subId, $subStart, $subEnd, $subNotes);
                if ($granted) {
                    flash('success', 'Předplatné bylo uděleno.');
                } else {
                    flash('danger', 'Předplatné se nepodařilo uložit – tabulka mycoach_app_access pravděpodobně neexistuje. Spusťte scripts/migrate_mycoach_app.php.');
                }
            } else {
                flash('danger', 'Neplatný formát data.');
            }
        } else {
            flash('danger', 'Vyplňte datum konce předplatného.');
        }
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'reset_trial') {
        $subType = in_array(trim((string)($_POST['sub_user_type'] ?? '')), ['coach','athlete'], true) ? trim((string)$_POST['sub_user_type']) : '';
        $subId   = (int)($_POST['sub_user_id'] ?? 0);
        if ($subType && $subId > 0) {
            $ok = mycoachAppResetTrial($pdo, $subType, $subId);
            $ok ? flash('success', 'Trial byl resetován – uživatel může spustit trial znovu.') : flash('danger', 'Reset trialu se nezdařil – spusťte scripts/migrate_mycoach_app.php.');
        }
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'revoke_subscription') {
        $subType = in_array(trim((string)($_POST['sub_user_type'] ?? '')), ['coach','athlete'], true) ? trim((string)$_POST['sub_user_type']) : '';
        $subId   = (int)($_POST['sub_user_id'] ?? 0);
        if ($subType && $subId > 0) {
            $ok = mycoachAppRevokeSubscription($pdo, $subType, $subId);
            $ok ? flash('success', 'Předplatné bylo odebráno.') : flash('danger', 'Odebrání předplatného se nezdařilo – spusťte scripts/migrate_mycoach_app.php.');
        }
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    // ── Plány předplatného ────────────────────────────────────
    if ($action === 'save_plan') {
        $planId  = (int)($_POST['plan_id'] ?? 0);
        $pName   = trim((string)($_POST['plan_name'] ?? ''));
        $pDays   = max(1, (int)($_POST['plan_days'] ?? 30));
        $pPrice  = max(0, (float)str_replace(',', '.', (string)($_POST['plan_price'] ?? '0')));
        $pCurr   = strtoupper(trim((string)($_POST['plan_currency'] ?? 'CZK')));
        $pDesc   = trim((string)($_POST['plan_description'] ?? ''));
        $pSort   = (int)($_POST['plan_sort'] ?? 0);
        $pActive = isset($_POST['plan_active']) ? 1 : 0;
        if ($pName === '') { flash('danger', 'Název plánu je povinný.'); redirect(BASE_URL . '/admin/mycoach.php'); }
        try {
            if ($planId > 0) {
                $pdo->prepare('UPDATE mycoach_app_subscription_plans SET name=?,days=?,price=?,currency=?,description=?,sort_order=?,is_active=? WHERE id=?')
                    ->execute([$pName,$pDays,$pPrice,$pCurr,$pDesc,$pSort,$pActive,$planId]);
                flash('success', 'Plán upraven.');
            } else {
                $pdo->prepare('INSERT INTO mycoach_app_subscription_plans (name,days,price,currency,description,sort_order,is_active) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$pName,$pDays,$pPrice,$pCurr,$pDesc,$pSort,$pActive]);
                flash('success', 'Plán přidán.');
            }
        } catch (Throwable $e) { flash('danger', 'Chyba: ' . $e->getMessage()); }
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'delete_plan') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        if ($planId > 0) { $pdo->prepare('DELETE FROM mycoach_app_subscription_plans WHERE id=?')->execute([$planId]); flash('success', 'Plán smazán.'); }
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'save_bank_settings') {
        $iban  = trim(str_replace(' ', '', strtoupper((string)($_POST['bank_iban'] ?? ''))));
        $accNo = trim((string)($_POST['bank_account_no'] ?? ''));
        $pdo->prepare("INSERT INTO app_settings (`key`,`value`) VALUES ('mycoach_bank_iban',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")->execute([$iban]);
        $pdo->prepare("INSERT INTO app_settings (`key`,`value`) VALUES ('mycoach_bank_account_no',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)")->execute([$accNo]);
        flash('success', 'Bankovní údaje uloženy.');
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    // ── Aktivace předplatného s emailem ──────────────────────
    if ($action === 'grant_subscription') {
        $subType  = in_array(trim((string)($_POST['sub_user_type'] ?? '')), ['coach','athlete'], true) ? trim((string)$_POST['sub_user_type']) : '';
        $subId    = (int)($_POST['sub_user_id'] ?? 0);
        $subStart = trim((string)($_POST['sub_start'] ?? date('Y-m-d')));
        $subEnd   = trim((string)($_POST['sub_end'] ?? ''));
        $subNotes = trim((string)($_POST['sub_notes'] ?? ''));
        if ($subType && $subId > 0 && $subEnd !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $subStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $subEnd)) {
                $granted = mycoachAppGrantSubscription($pdo, $subType, $subId, $subStart, $subEnd, $subNotes);
                if ($granted) {
                    // Odeslat email s potvrzením aktivace
                    sendMyCoachSubscriptionActivatedEmail($pdo, $subType, $subId, $subStart, $subEnd);
                    flash('success', 'Předplatné bylo uděleno a uživatel byl informován e-mailem.');
                } else {
                    flash('danger', 'Předplatné se nepodařilo uložit – tabulka mycoach_app_access pravděpodobně neexistuje. Spusťte migrace v admin/mycoach.php.');
                }
            } else {
                flash('danger', 'Neplatný formát data.');
            }
        } else {
            flash('danger', 'Vyplňte datum konce předplatného.');
        }
        redirect(BASE_URL . '/admin/mycoach.php');
    }
}

// Načti plány předplatného a bankovní nastavení
$subscriptionPlans = [];
try { $subscriptionPlans = $pdo->query('SELECT * FROM mycoach_app_subscription_plans ORDER BY sort_order ASC, id ASC')->fetchAll() ?: []; } catch(Throwable $e){}
$bankIban      = getAppSetting('mycoach_bank_iban', '');
$bankAccountNo = getAppSetting('mycoach_bank_account_no', '');

$currentMode = adminMyCoachNormalizeMode((string)getAppSetting($mycoachAccessModeKey, 'selected'));
$mycoachAppCurrentStatus = getAppSetting('mycoach_app_status', 'development');
$mycoachAppIsLive = ($mycoachAppCurrentStatus === 'live');

$accessByKey = [];
try {
    if (mycoachAppEnsureAccessTable($pdo)) {
        $accessRows = $pdo->query('SELECT * FROM mycoach_app_access')->fetchAll();
        foreach ($accessRows as $ar) {
            $accessByKey[$ar['user_type'] . '_' . $ar['user_id']] = $ar;
        }
    }
} catch (Throwable $e) { $accessByKey = []; }

$coachSummary = [
    'total' => 0,
    'enabled' => 0,
    'active' => 0,
];
$athleteSummary = [
    'total' => 0,
    'enabled' => 0,
];

try {
    $coachSummary = $pdo->query('SELECT COUNT(*) AS total, SUM(CASE WHEN mycoach_enabled = 1 THEN 1 ELSE 0 END) AS enabled, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active FROM coaches')->fetch() ?: $coachSummary;
} catch (Throwable $e) {
    $coachSummary = ['total' => 0, 'enabled' => 0, 'active' => 0];
}

try {
    $athleteSummary = $pdo->query('SELECT COUNT(*) AS total, SUM(CASE WHEN mycoach_enabled = 1 THEN 1 ELSE 0 END) AS enabled FROM athletes')->fetch() ?: $athleteSummary;
} catch (Throwable $e) {
    $athleteSummary = ['total' => 0, 'enabled' => 0];
}

$coachRows = [];
try {
    $coachRows = $pdo->query(
        'SELECT c.id, c.name, c.username, c.email, c.is_active, c.mycoach_enabled,
                (SELECT COUNT(*) FROM athletes a WHERE a.coach_id = c.id) AS athlete_count,
                (SELECT COUNT(*) FROM athletes a WHERE a.coach_id = c.id AND a.mycoach_enabled = 1) AS athlete_enabled_count
         FROM coaches c
         ORDER BY c.is_active DESC, c.name ASC, c.username ASC'
    )->fetchAll() ?: [];
} catch (Throwable $e) {
    $coachRows = [];
}

$coachFilterOptions = [];
foreach ($coachRows as $coachRow) {
    $coachFilterOptions[] = [
        'id' => (int)$coachRow['id'],
        'label' => trim((string)($coachRow['name'] ?? '')) !== '' ? (string)$coachRow['name'] : (string)$coachRow['username'],
    ];
}

$athleteWhere = [];
$athleteParams = [];
if ($selectedCoachFilter > 0) {
    $athleteWhere[] = 'a.coach_id = ?';
    $athleteParams[] = $selectedCoachFilter;
}
if ($search !== '') {
    $athleteWhere[] = '(a.first_name LIKE ? OR a.last_name LIKE ? OR a.email LIKE ? OR c.name LIKE ? OR c.username LIKE ?)';
    $like = '%' . $search . '%';
    $athleteParams[] = $like;
    $athleteParams[] = $like;
    $athleteParams[] = $like;
    $athleteParams[] = $like;
    $athleteParams[] = $like;
}

$athleteSql =
    'SELECT a.id, a.first_name, a.last_name, a.email, a.mycoach_enabled, a.coach_id,
            c.name AS coach_name, c.username AS coach_username
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id';
if (!empty($athleteWhere)) {
    $athleteSql .= ' WHERE ' . implode(' AND ', $athleteWhere);
}
$athleteSql .= ' ORDER BY c.name ASC, c.username ASC, a.first_name ASC, a.last_name ASC, a.id ASC LIMIT 600';

$athleteRows = [];
try {
    $stmtAthletes = $pdo->prepare($athleteSql);
    $stmtAthletes->execute($athleteParams);
    $athleteRows = $stmtAthletes->fetchAll() ?: [];
} catch (Throwable $e) {
    $athleteRows = [];
}

renderAdminHeader('MyCoach administrace');

$accessTableExists = mycoachAppEnsureAccessTable($pdo);
// Zkontroluj i ostatní klíčové tabulky
$missingTables = [];
foreach (['mycoach_app_access','mycoach_app_sections','mycoach_app_videos','mycoach_app_exercises','mycoach_app_workouts','mycoach_app_foods','mycoach_app_section_items','mycoach_app_subscription_plans'] as $tbl) {
    try {
        $r = $pdo->query("SHOW TABLES LIKE '$tbl'");
        if (!$r || !$r->fetch()) $missingTables[] = $tbl;
    } catch (Throwable $e) { $missingTables[] = $tbl; }
}
?>

<?php if (!empty($missingTables)): ?>
<div class="alert alert-danger border-0 shadow-sm mb-4">
    <i class="fas fa-triangle-exclamation me-2"></i>
    <strong>Chybí DB tabulky MyCoach App:</strong> <?= h(implode(', ', $missingTables)) ?>
    <form method="post" class="d-inline ms-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="run_migration">
        <button type="submit" class="btn btn-danger btn-sm fw-semibold"
                onclick="return confirm('Spustit migraci a vytvořit všechny chybějící tabulky?')">
            <i class="fas fa-database me-1"></i>Spustit migraci nyní
        </button>
    </form>
</div>
<?php endif; ?>

<div class="alert alert-info border-0 shadow-sm mb-4">
    <i class="fas fa-info-circle me-2"></i>MyCoach App je
    <?php if ($mycoachAppIsLive): ?>
        <strong class="text-success">LIVE</strong> – uživatelé mohou vstoupit a aktivovat trial/předplatné.
    <?php else: ?>
        <strong class="text-warning">Ve vývoji</strong> – všichni vidí oznámení o brzkém spuštění.
    <?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1">
            <i class="fas fa-brain me-2" style="color:#a78bfa"></i>MyCoach administrace
        </h4>
        <div class="text-muted small">Správa statusu aplikace, předplatného a obsahu.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/admin/mycoach_content.php" class="btn btn-sm btn-outline-warning">
            <i class="fas fa-layer-group me-1"></i>Správa obsahu
        </a>
        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Přehled
        </a>
    </div>
</div>

<!-- ═══ App Status karta ════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold" style="background:#0d0d0d;color:#f7941d;">
        <i class="fas fa-rocket me-2"></i>Status aplikace MyCoach App
    </div>
    <div class="card-body">
        <div class="d-flex align-items-center gap-4 flex-wrap">
            <div>
                <span class="badge <?= $mycoachAppIsLive ? 'bg-success' : 'bg-warning text-dark' ?> fs-6 px-3 py-2">
                    <?= $mycoachAppIsLive ? '🟢 LIVE' : '🟡 Ve vývoji' ?>
                </span>
            </div>
            <form method="post" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_app_status">
                <input type="hidden" name="mycoach_app_status" value="<?= $mycoachAppIsLive ? 'development' : 'live' ?>">
                <button type="submit" class="btn <?= $mycoachAppIsLive ? 'btn-outline-warning' : 'btn-success' ?>"
                        onclick="return confirm('<?= $mycoachAppIsLive ? 'Přepnout MyCoach App do režimu VE VÝVOJI?' : 'Spustit MyCoach App jako LIVE? Uživatelé budou moci vstoupit a aktivovat trial.' ?>')">
                    <i class="fas fa-toggle-<?= $mycoachAppIsLive ? 'off' : 'on' ?> me-1"></i>
                    <?= $mycoachAppIsLive ? 'Přepnout zpět do Vývoje' : 'Spustit jako LIVE' ?>
                </button>
            </form>
            <div class="text-muted small">
                <?php if ($mycoachAppIsLive): ?>
                    Kliknutím na dlaždici MyCoach uživatelé vstoupí do aplikace (trial / předplatné).
                <?php else: ?>
                    Kliknutím na dlaždici MyCoach uživatelé vidí jen oznámení „Brzy spouštíme".
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<!-- ════════════════════════════════════════════════════════════════════ -->

<?php if ($currentMode !== 'selected'): ?>
<div class="alert alert-warning border-0 shadow-sm">
    <i class="fas fa-triangle-exclamation me-1"></i>
    Individuální přepínače trenérů a sportovců jsou teď evidované, ale účinné budou až v režimu <strong>Jen vybraní uživatelé</strong>.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold" style="color:#7c3aed"><?= (int)($coachSummary['total'] ?? 0) ?></div>
                <div class="text-muted small">Trenérů celkem</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold" style="color:#0ea5e9"><?= (int)($coachSummary['enabled'] ?? 0) ?></div>
                <div class="text-muted small">Trenéři s MyCoach povolením</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold" style="color:#7c3aed"><?= (int)($athleteSummary['total'] ?? 0) ?></div>
                <div class="text-muted small">Sportovců celkem</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold" style="color:#0ea5e9"><?= (int)($athleteSummary['enabled'] ?? 0) ?></div>
                <div class="text-muted small">Sportovci s MyCoach povolením</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-toggle-on me-2"></i>Globální režim MyCoach
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_mode">

            <div class="vstack gap-2 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mycoach_access_mode" id="mycoachModeDisabled" value="disabled" <?= $currentMode === 'disabled' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mycoachModeDisabled">
                        <span class="fw-semibold">Globálně vypnout</span>
                        <span class="text-muted d-block small">MyCoach nebude dostupný nikomu.</span>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mycoach_access_mode" id="mycoachModeAll" value="all" <?= $currentMode === 'all' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mycoachModeAll">
                        <span class="fw-semibold">Globálně zapnout</span>
                        <span class="text-muted d-block small">MyCoach bude dostupný všem bez ohledu na individuální stav.</span>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mycoach_access_mode" id="mycoachModeSelected" value="selected" <?= $currentMode === 'selected' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mycoachModeSelected">
                        <span class="fw-semibold">Jen vybraní uživatelé</span>
                        <span class="text-muted d-block small">Povolení řídíte níže po uživatelích.</span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn fw-bold" style="background:#7c3aed;color:#fff;border:none">
                <i class="fas fa-save me-1"></i>Uložit režim
            </button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:#1e1e2e;color:#fff">
        <span><i class="fas fa-user-tie me-2"></i>Trenéři</span>
        <div class="d-flex gap-2">
            <form method="post" onsubmit="return confirm('Opravdu povolit MyCoach všem trenérům?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_all_coaches_access">
                <input type="hidden" name="enabled" value="1">
                <button type="submit" class="btn btn-sm btn-success fw-semibold">Povolit všem</button>
            </form>
            <form method="post" onsubmit="return confirm('Opravdu vypnout MyCoach všem trenérům?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_all_coaches_access">
                <input type="hidden" name="enabled" value="0">
                <button type="submit" class="btn btn-sm btn-outline-light fw-semibold">Vypnout všem</button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($coachRows)): ?>
        <div class="p-4 text-muted">Nenalezeni žádní trenéři.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Trenér</th>
                    <th>E-mail</th>
                    <th class="text-center">Stav účtu</th>
                    <th class="text-center">MyCoach (trenér)</th>
                    <th class="text-center">MyCoach (sportovci)</th>
                    <th class="text-end">Akce</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($coachRows as $coachRow): ?>
                <?php
                    $coachName = trim((string)($coachRow['name'] ?? ''));
                    if ($coachName === '') {
                        $coachName = (string)($coachRow['username'] ?? '');
                    }
                    $coachEnabled = ((int)($coachRow['mycoach_enabled'] ?? 0)) === 1;
                    $coachActive = ((int)($coachRow['is_active'] ?? 0)) === 1;
                    $athleteTotal = (int)($coachRow['athlete_count'] ?? 0);
                    $athleteEnabledCount = (int)($coachRow['athlete_enabled_count'] ?? 0);
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= h($coachName) ?></div>
                        <div class="small text-muted">@<?= h((string)($coachRow['username'] ?? '')) ?></div>
                    </td>
                    <td>
                        <?php if (trim((string)($coachRow['email'] ?? '')) !== ''): ?>
                            <a href="mailto:<?= h((string)$coachRow['email']) ?>"><?= h((string)$coachRow['email']) ?></a>
                        <?php else: ?>
                            <span class="text-muted">Bez e-mailu</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $coachActive ? 'bg-success' : 'bg-secondary' ?>"><?= $coachActive ? 'Aktivní' : 'Neaktivní' ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $coachEnabled ? 'bg-success' : 'bg-secondary' ?>"><?= $coachEnabled ? 'Povoleno' : 'Vypnuto' ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-info text-dark"><?= $athleteEnabledCount ?> / <?= $athleteTotal ?></span>
                    </td>
                    <td class="text-end">
                        <div class="d-flex justify-content-end gap-2">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="set_coach_access">
                                <input type="hidden" name="coach_id" value="<?= (int)$coachRow['id'] ?>">
                                <input type="hidden" name="enabled" value="<?= $coachEnabled ? '0' : '1' ?>">
                                <button type="submit" class="btn btn-sm <?= $coachEnabled ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                    <?= $coachEnabled ? 'Vypnout' : 'Povolit' ?>
                                </button>
                            </form>
                            <a href="<?= BASE_URL ?>/admin/mycoach.php?coach_id=<?= (int)$coachRow['id'] ?>" class="btn btn-sm btn-outline-primary">Sportovci</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ Plány předplatného ══════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-tags me-2"></i>Plány předplatného
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-5">
                <form method="post" id="planForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_plan">
                    <input type="hidden" name="plan_id" id="planId" value="0">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Název plánu *</label>
                        <input type="text" name="plan_name" id="planName" class="form-control form-control-sm" placeholder="např. Měsíční předplatné" required>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-5">
                            <label class="form-label small fw-semibold">Délka (dní) *</label>
                            <input type="number" name="plan_days" id="planDays" class="form-control form-control-sm" value="30" min="1" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-semibold">Cena *</label>
                            <input type="text" name="plan_price" id="planPrice" class="form-control form-control-sm" value="0" required>
                        </div>
                        <div class="col-3">
                            <label class="form-label small fw-semibold">Měna</label>
                            <input type="text" name="plan_currency" id="planCurrency" class="form-control form-control-sm" value="CZK" maxlength="5">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Popis (nepovinný)</label>
                        <textarea name="plan_description" id="planDesc" class="form-control form-control-sm" rows="2" placeholder="Co plán zahrnuje..."></textarea>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Pořadí</label>
                            <input type="number" name="plan_sort" id="planSort" class="form-control form-control-sm" value="0">
                        </div>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="plan_active" id="planActive" class="form-check-input" value="1" checked>
                        <label class="form-check-label small" for="planActive">Aktivní (viditelný uživatelům)</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning btn-sm fw-semibold flex-grow-1">Uložit plán</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="planFormReset()">Reset</button>
                    </div>
                </form>
            </div>
            <div class="col-lg-7">
                <?php if (empty($subscriptionPlans)): ?>
                    <div class="text-muted p-3">Žádné plány. Přidejte první vlevo.</div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th>Název</th><th class="text-center">Dní</th><th class="text-center">Cena</th>
                        <th class="text-center">Aktivní</th><th class="text-end">Akce</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($subscriptionPlans as $plan): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= h($plan['name']) ?></div>
                            <?php if ($plan['description']): ?><div class="small text-muted"><?= h(mb_substr($plan['description'],0,60,'UTF-8')) ?></div><?php endif; ?>
                        </td>
                        <td class="text-center"><?= (int)$plan['days'] ?></td>
                        <td class="text-center"><?= number_format((float)$plan['price'], 2, ',', ' ') ?> <?= h($plan['currency']) ?></td>
                        <td class="text-center"><span class="badge <?= $plan['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $plan['is_active'] ? 'Ano' : 'Ne' ?></span></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-xs btn-outline-primary btn-sm"
                                    onclick='planFormFill(<?= json_encode($plan) ?>)'>
                                <i class="fas fa-pen"></i>
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('Smazat plán?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_plan">
                                <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                                <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Bankovní údaje pro platby ═══════════════════════════════════════════ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-building-columns me-2"></i>Bankovní údaje pro platby (QR kód)
    </div>
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_bank_settings">
            <div class="col-md-5">
                <label class="form-label fw-semibold">IBAN <small class="text-muted">(pro QR kód platby, např. CZ6508000000192000145399)</small></label>
                <input type="text" name="bank_iban" class="form-control" value="<?= h($bankIban) ?>" placeholder="CZ65...">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Číslo účtu <small class="text-muted">(zobrazí se uživateli, např. 123456789/0800)</small></label>
                <input type="text" name="bank_account_no" class="form-control" value="<?= h($bankAccountNo) ?>" placeholder="123456789/0800">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary w-100">Uložit bankovní údaje</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-users me-2"></i>Sportovci
    </div>
    <div class="card-body border-bottom">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="coachFilter">Trenér</label>
                <select name="coach_id" id="coachFilter" class="form-select">
                    <option value="0">Všichni trenéři</option>
                    <?php foreach ($coachFilterOptions as $coachOption): ?>
                    <option value="<?= (int)$coachOption['id'] ?>" <?= $selectedCoachFilter === (int)$coachOption['id'] ? 'selected' : '' ?>>
                        <?= h((string)$coachOption['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-semibold" for="searchInput">Hledat sportovce</label>
                <input type="text" name="q" id="searchInput" class="form-control" value="<?= h($search) ?>" placeholder="Jméno, e-mail nebo trenér...">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary w-100">Filtrovat</button>
                <a href="<?= BASE_URL ?>/admin/mycoach.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>

        <div class="d-flex gap-2 mt-3 flex-wrap">
            <form method="post" onsubmit="return confirm('Opravdu povolit MyCoach všem sportovcům v aktuálním filtru?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_all_athletes_access">
                <input type="hidden" name="enabled" value="1">
                <button type="submit" class="btn btn-sm btn-success fw-semibold">Povolit všechny sportovce</button>
            </form>
            <form method="post" onsubmit="return confirm('Opravdu vypnout MyCoach všem sportovcům v aktuálním filtru?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_all_athletes_access">
                <input type="hidden" name="enabled" value="0">
                <button type="submit" class="btn btn-sm btn-outline-danger fw-semibold">Vypnout všechny sportovce</button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($athleteRows)): ?>
        <div class="p-4 text-muted">Žádní sportovci pro aktuální filtr.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Sportovec</th>
                    <th>Trenér</th>
                    <th>E-mail</th>
                    <th class="text-center">MyCoach (přístup)</th>
                    <th class="text-center">App přístup</th>
                    <th class="text-end">Akce</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($athleteRows as $athleteRow): ?>
                <?php
                    $fullName = trim((string)($athleteRow['first_name'] ?? '') . ' ' . (string)($athleteRow['last_name'] ?? ''));
                    if ($fullName === '') {
                        $fullName = 'Sportovec #' . (int)$athleteRow['id'];
                    }
                    $coachName = trim((string)($athleteRow['coach_name'] ?? ''));
                    if ($coachName === '') {
                        $coachName = (string)($athleteRow['coach_username'] ?? '');
                    }
                    $enabled = ((int)($athleteRow['mycoach_enabled'] ?? 0)) === 1;
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= h($fullName) ?></div>
                        <div class="small text-muted">ID <?= (int)$athleteRow['id'] ?></div>
                    </td>
                    <td><?= h($coachName) ?></td>
                    <td>
                        <?php if (trim((string)($athleteRow['email'] ?? '')) !== ''): ?>
                        <a href="mailto:<?= h((string)$athleteRow['email']) ?>"><?= h((string)$athleteRow['email']) ?></a>
                        <?php else: ?>
                        <span class="text-muted">Bez e-mailu</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $enabled ? 'bg-success' : 'bg-secondary' ?>"><?= $enabled ? 'Povoleno' : 'Vypnuto' ?></span>
                    </td>
                    <?php
                        $accRow = $accessByKey['athlete_' . (int)$athleteRow['id']] ?? null;
                        $appStatus = mycoachAppCheckStatus($pdo, 'athlete', (int)$athleteRow['id']);
                        $appLabel  = mycoachAppStatusLabel($appStatus);
                    ?>
                    <td class="text-center">
                        <span class="badge <?= h($appLabel['badge']) ?> small"><?= h($appLabel['text']) ?></span>
                        <?php if ($accRow && !empty($accRow['subscription_end'])): ?>
                            <div class="text-muted" style="font-size:.72rem;">do <?= h($accRow['subscription_end']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="set_athlete_access">
                                <input type="hidden" name="athlete_id" value="<?= (int)$athleteRow['id'] ?>">
                                <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                                <button type="submit" class="btn btn-sm <?= $enabled ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                    <?= $enabled ? 'Vypnout' : 'Povolit' ?>
                                </button>
                            </form>
                            <button type="button"
                                    class="btn btn-sm btn-outline-warning"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalGrantSub"
                                    data-user-type="athlete"
                                    data-user-id="<?= (int)$athleteRow['id'] ?>"
                                    data-user-name="<?= h($fullName) ?>"
                                    title="Přidat/upravit předplatné">
                                <i class="fas fa-calendar-plus"></i>
                            </button>
                            <?php if ($accRow && (!empty($accRow['subscription_end']) || !empty($accRow['trial_started_at']))): ?>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Odebrat předplatné / přístup pro <?= h(addslashes($fullName)) ?>? Uživatel ztratí přístup do MyCoach App ihned.')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="revoke_subscription">
                                <input type="hidden" name="sub_user_type" value="athlete">
                                <input type="hidden" name="sub_user_id" value="<?= (int)$athleteRow['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Odebrat předplatné">
                                    <i class="fas fa-ban"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($accRow && (int)($accRow['trial_used'] ?? 0) === 1): ?>
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reset_trial">
                                <input type="hidden" name="sub_user_type" value="athlete">
                                <input type="hidden" name="sub_user_id" value="<?= (int)$athleteRow['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Reset trial"
                                        onclick="return confirm('Resetovat trial pro <?= h(addslashes($fullName)) ?>?')">
                                    <i class="fas fa-rotate-left"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderAdminFooter();
/* ── Modál: udělení předplatného ─────────────────────────── */ ?>

<div class="modal fade" id="modalGrantSub" tabindex="-1" aria-labelledby="modalGrantSubLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalGrantSubLabel">
          <i class="fas fa-calendar-plus me-2 text-warning"></i>Předplatné MyCoach App
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="grant_subscription">
        <input type="hidden" name="sub_user_type" id="subUserType" value="">
        <input type="hidden" name="sub_user_id" id="subUserId" value="">
        <div class="modal-body">
          <p class="mb-3">Uživatel: <strong id="subUserName"></strong></p>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label small fw-semibold">Od</label>
              <input type="date" name="sub_start" class="form-control form-control-sm"
                     value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Do</label>
              <input type="date" name="sub_end" class="form-control form-control-sm"
                     value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Poznámka (nepovinná)</label>
              <input type="text" name="sub_notes" class="form-control form-control-sm" placeholder="např. Roční předplatné">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Zrušit</button>
          <button type="submit" class="btn btn-warning btn-sm fw-semibold">
            <i class="fas fa-check me-1"></i>Uložit předplatné
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('modalGrantSub').addEventListener('show.bs.modal', function(e) {
  const btn = e.relatedTarget;
  document.getElementById('subUserType').value = btn.dataset.userType || '';
  document.getElementById('subUserId').value   = btn.dataset.userId   || '';
  document.getElementById('subUserName').textContent = btn.dataset.userName || '';
});

function planFormReset() {
  document.getElementById('planId').value='0';
  document.getElementById('planName').value='';
  document.getElementById('planDays').value='30';
  document.getElementById('planPrice').value='0';
  document.getElementById('planCurrency').value='CZK';
  document.getElementById('planDesc').value='';
  document.getElementById('planSort').value='0';
  document.getElementById('planActive').checked=true;
}
function planFormFill(p) {
  document.getElementById('planId').value=p.id;
  document.getElementById('planName').value=p.name||'';
  document.getElementById('planDays').value=p.days||30;
  document.getElementById('planPrice').value=p.price||0;
  document.getElementById('planCurrency').value=p.currency||'CZK';
  document.getElementById('planDesc').value=p.description||'';
  document.getElementById('planSort').value=p.sort_order||0;
  document.getElementById('planActive').checked=p.is_active=='1'||p.is_active===1;
  document.getElementById('planForm').scrollIntoView({behavior:'smooth',block:'start'});
}
</script>
