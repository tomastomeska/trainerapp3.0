<?php
// ============================================================
// admin/config/env.local.php - secure local override
// ============================================================

function envValue(string $key, ?string $default = null): ?string {
	$value = getenv($key);
	if ($value === false) {
		return $default;
	}
	$value = trim((string)$value);
	return $value === '' ? $default : $value;
}

if (!defined('DB_HOST')) define('DB_HOST', (string)envValue('TRAINERAPP_DB_HOST', 'localhost,md433.wedos.net'));
if (!defined('DB_NAME')) define('DB_NAME', (string)envValue('TRAINERAPP_DB_NAME', 'd391857_tplan'));
if (!defined('DB_USER')) define('DB_USER', (string)envValue('TRAINERAPP_DB_USER', 'a391857_tplan'));
if (!defined('DB_PASS')) define('DB_PASS', (string)envValue('TRAINERAPP_DB_PASS', 'rfea4txM'));

if (!defined('BASE_URL')) define('BASE_URL', (string)envValue('TRAINERAPP_BASE_URL', ''));
if (!defined('SESSION_NAME')) define('SESSION_NAME', (string)envValue('TRAINERAPP_SESSION_NAME', 'trainerapp_v2_sess'));
if (!defined('SESSION_SECURE')) define('SESSION_SECURE', envValue('TRAINERAPP_SESSION_SECURE', '0') === '1');
if (!defined('ENABLE_SETUP_ADMIN')) define('ENABLE_SETUP_ADMIN', envValue('TRAINERAPP_ENABLE_SETUP_ADMIN', '0') === '1');

if (!defined('SMTP_HOST'))      define('SMTP_HOST',      (string)envValue('TRAINERAPP_SMTP_HOST', 'smtp.wedos.com'));
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      (int)envValue('TRAINERAPP_SMTP_PORT', '587'));
if (!defined('SMTP_USER'))      define('SMTP_USER',      (string)envValue('TRAINERAPP_SMTP_USER', 'no_reply@reservio.online'));
if (!defined('SMTP_PASS'))      define('SMTP_PASS',      (string)envValue('TRAINERAPP_SMTP_PASS', '20Tomeska@17'));
if (!defined('SMTP_FROM'))      define('SMTP_FROM',      (string)envValue('TRAINERAPP_SMTP_FROM', 'no_reply@reservio.online'));
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', (string)envValue('TRAINERAPP_SMTP_FROM_NAME', 'TrainerApp'));