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
if (!defined('APP_VERSION')) define('APP_VERSION', '1.1.01');
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

// BASE_URL: automaticka detekce z DOCUMENT_ROOT (env.php muze prepsat)
if (!defined('BASE_URL')) {
    if (php_sapi_name() === 'cli') {
        define('BASE_URL', '');
    } else {
        $__baseUrl = '';
        $__appRoot = realpath(dirname(__DIR__));
        $__scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : false;
        $__scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

        // Nejpresnejsi varianta: porovnat fyzickou cestu skriptu s URL skriptu.
        if ($__appRoot && $__scriptFile && $__scriptName !== '') {
            $__appRootN = rtrim(str_replace('\\', '/', $__appRoot), '/');
            $__scriptFileN = str_replace('\\', '/', $__scriptFile);
            if (strncmp($__scriptFileN, $__appRootN, strlen($__appRootN)) === 0) {
                $__relativeScript = ltrim(substr($__scriptFileN, strlen($__appRootN)), '/');
                $__scriptNameN = '/' . ltrim($__scriptName, '/');
                if ($__relativeScript !== '') {
                    $__suffix = '/' . $__relativeScript;
                    if (substr($__scriptNameN, -strlen($__suffix)) === $__suffix) {
                        $__baseUrl = substr($__scriptNameN, 0, -strlen($__suffix));
                    }
                }
            }
        }

        // Fallback: odhad z DOCUMENT_ROOT, pokud je dostupny.
        if ($__baseUrl === '') {
            $__docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
            if ($__docRoot && $__appRoot) {
                $__docRootN = rtrim(str_replace('\\', '/', $__docRoot), '/');
                $__appRootN = rtrim(str_replace('\\', '/', $__appRoot), '/');
                if ($__docRootN !== '' && strncmp($__appRootN, $__docRootN, strlen($__docRootN)) === 0) {
                    $__baseUrl = substr($__appRootN, strlen($__docRootN));
                }
            }
        }

        $__baseUrl = trim((string)$__baseUrl);
        if ($__baseUrl === '/' || $__baseUrl === '.') {
            $__baseUrl = '';
        }
        if ($__baseUrl !== '' && $__baseUrl[0] !== '/') {
            $__baseUrl = '/' . $__baseUrl;
        }
        $__baseUrl = rtrim($__baseUrl, '/');

        define('BASE_URL', $__baseUrl);
        unset($__baseUrl, $__appRoot, $__scriptFile, $__scriptName);
        unset($__appRootN, $__scriptFileN, $__relativeScript, $__scriptNameN, $__suffix);
        unset($__docRoot, $__docRootN);
    }
}

// SESSION_SECURE: automaticka detekce HTTPS (env.php muze prepsat)
if (!defined('SESSION_SECURE')) {
    define(
        'SESSION_SECURE',
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );
}
// ENABLE_SETUP_ADMIN: bezpecnostni pojistka pro setup_admin.php
// Na produkci ponechat vzdy false; docasne zapnout jen pri zrizeni admina.
if (!defined('ENABLE_SETUP_ADMIN')) {
    define('ENABLE_SETUP_ADMIN', false);
}

// Casova zona
date_default_timezone_set('Europe/Prague');
