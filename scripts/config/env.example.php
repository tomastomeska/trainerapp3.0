<?php
// ============================================================
// config/env.example.php - Sablona ENV profilu
// Kopii ulozte jako config/env.local.php nebo config/env.production.php.
// Aktivni profil je vzdy zkopirovan do config/env.php.
// ============================================================

// Databaze
define('DB_HOST',    'localhost');
define('DB_NAME',    'nazev_databaze');
define('DB_USER',    'uzivatel_db');
define('DB_PASS',    'heslo_db');

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
define('SMTP_USER',      '');
define('SMTP_PASS',      '');
define('SMTP_FROM',      'noreply@example.com');
define('SMTP_FROM_NAME', 'TrainerApp');
