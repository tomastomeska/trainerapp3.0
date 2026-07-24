<?php
// ============================================================
// config/env.example.php - Sablona ENV profilu
// Kopii ulozte jako config/env.local.php nebo config/env.production.php.
// Aktivni profil je vzdy zkopirovan do config/env.php.
// ============================================================

// Databaze
define('DB_HOST',    'md433.wedos.net');
define('DB_NAME',    'nazev_databaze');
define('DB_USER',    'uzivatel_db');
define('DB_PASS',    'heslo_db');
// Timeout pripojeni k DB v sekundach (snizi dlouhe zaseky pri vypadku DB hostu).
define('DB_CONNECT_TIMEOUT', 5);
// Na produkci nechat false: schema migrace nepatri do beznych requestu.
define('DB_AUTO_SCHEMA_UPGRADE', false);

// BASE_URL standardne NENASTAVUJTE, aby bezela automaticka detekce.
// Pokud chcete vynutit podslozku, odkomentujte nasledujici radek:
// define('BASE_URL', '/trenerapp');

// true na produkci (HTTPS), false lokalne.
define('SESSION_SECURE', false);

// Pro soubezny beh vice verzi na jedne domene nastavte vlastni nazev session.
// Napr. v2: define('SESSION_NAME', 'trainerapp_v2_sess');
// define('SESSION_NAME', 'trainerapp_sess');

// Bezpecnostni pojistka setup_admin.php (na produkci ponechte false).
define('ENABLE_SETUP_ADMIN', false);

// SMTP (volitelne)
define('SMTP_HOST',      '');
define('SMTP_PORT',      587);
define('SMTP_TIMEOUT',   8);
define('SMTP_USER',      '');
define('SMTP_PASS',      '');
define('SMTP_FROM',      'noreply@example.com');
define('SMTP_FROM_NAME', 'TrainerApp');

// Externi Apple CalDAV timeouty (v sekundach)
define('APPLE_CALDAV_CONNECT_TIMEOUT', 5);
define('APPLE_CALDAV_HTTP_TIMEOUT', 12);

// Zapne odesilani notifikacnich emailu pres DB frontu + cron worker
define('EMAIL_QUEUE_ENABLED', true);

// Nouzovy hybrid: po enqueue zpracuje malou cast sync fronty i v uzivatelskem requestu
define('CALENDAR_SYNC_INLINE_ENABLED', false);

// Zapne zpracovani queue workeru v cron skriptech (email/google/apple)
define('QUEUE_WORKERS_ENABLED', false);
