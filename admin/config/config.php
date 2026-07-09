<?php
// ============================================================
// Globalni konfigurace aplikace
// env.php (pokud existuje) přepisuje vychozi hodnoty
// ============================================================

// Nacist lokalni/produkcni prepisy (ignorovano gitem)
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

// Zakladni nastaveni aplikace
if (!defined('APP_NAME')) define('APP_NAME', 'TrainerApp');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.0.0');
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'trainerapp_sess');

// E-mail odesilatele (prepisuje env.php, pokud je nastaven SMTP_FROM)
define('MAIL_FROM',      defined('SMTP_FROM')      ? SMTP_FROM      : 'noreply@example.com');
define('MAIL_FROM_NAME', defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'TrainerApp');

// SMTP vychozi hodnoty (pro lokalni vyvoj bez SMTP)
if (!defined('SMTP_HOST'))      define('SMTP_HOST',      '');
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      587);
if (!defined('SMTP_USER'))      define('SMTP_USER',      '');
if (!defined('SMTP_PASS'))      define('SMTP_PASS',      '');
if (!defined('SMTP_FROM'))      define('SMTP_FROM',      'noreply@example.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'TrainerApp');

// BASE_URL: env.php muze nastavit vlastni hodnotu; vychozi pro lokalni dev
if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}

// SESSION_SECURE: true na produkci (HTTPS), false lokalne
if (!defined('SESSION_SECURE')) {
    define('SESSION_SECURE', false);
}
// ENABLE_SETUP_ADMIN: bezpecnostni pojistka pro setup_admin.php
// Na produkci ponechat vzdy false; docasne zapnout jen pri zrizeni admina.
if (!defined('ENABLE_SETUP_ADMIN')) {
    define('ENABLE_SETUP_ADMIN', false);
}

// Casova zona
date_default_timezone_set('Europe/Prague');