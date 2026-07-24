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

// Canonical host redirect: rezervio.online -> www.reservio.online
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    $hostHeader = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = strtolower((string)preg_replace('/:\\d+$/', '', $hostHeader));
    if ($host === 'reservio.online') {
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: https://www.reservio.online' . $requestUri, true, 302);
        exit;
    }
}

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

// BASE_URL: env.php muze nastavit vlastni hodnotu; jinak autodetekce s podporou /admin
if (!defined('BASE_URL') || BASE_URL === '') {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $baseUrl = '';

    if ($script !== '' && preg_match('#^(.*)/admin/[^/]+\\.php$#', $script, $m)) {
        $baseUrl = rtrim((string)($m[1] ?? ''), '/');
    }

    if ($baseUrl !== '' && $baseUrl[0] !== '/') {
        $baseUrl = '/' . $baseUrl;
    }
    if ($baseUrl === '/' || $baseUrl === '.') {
        $baseUrl = '';
    }

    define('BASE_URL', $baseUrl);
    unset($script, $baseUrl, $m);
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