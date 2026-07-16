<?php
// ============================================================
// config/env.example.php – ŠABLONA pro lokální i produkční nasazení
// Zkopírujte jako config/env.php a vyplňte skutečné údaje.
// ============================================================

// Databáze
define('DB_HOST',    'localhost');        // nebo hostname od hostingu
define('DB_NAME',    'nazev_databaze');
define('DB_USER',    'uzivatel_db');
define('DB_PASS',    'heslo_db');
// Timeout pripojeni k DB v sekundach (snizi dlouhe zaseky pri vypadku DB hostu).
define('DB_CONNECT_TIMEOUT', 5);
// Na produkci nechat false: schema migrace nepatri do beznych requestu.
define('DB_AUTO_SCHEMA_UPGRADE', false);

// Adresa aplikace
// Příklady:
//   define('BASE_URL', '');           // kořen domény: https://example.com/
//   define('BASE_URL', '/trenerapp'); // podsložka:    https://example.com/trenerapp/
define('BASE_URL',   '');

// true na produkci (HTTPS), false při lokálním vývoji
define('SESSION_SECURE', false);


// Bezpecnostni pojistka setup_admin.php (na produkci ponechte false)
define('ENABLE_SETUP_ADMIN', false);
