<?php
$isCli = php_sapi_name() === 'cli';
if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    $providedSecret = (string)($_GET['secret'] ?? '');
    $authorizedBySecret = $providedSecret !== '' && hash_equals(getCronSecret(), $providedSecret);
    if (!$authorizedBySecret) {
        if (!isAdminLoggedIn()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            $loginUrl = htmlspecialchars(BASE_URL . '/login_admin.php', ENT_QUOTES, 'UTF-8');
            exit('Pro spuštění migrace se nejprve <a href="' . $loginUrl . '">přihlaste jako administrátor</a>, poté tuto stránku znovu otevřete.');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="cs"><meta charset="utf-8"><title>Migrace online předplatného</title><body>';
            echo '<h1>Migrace online předplatného</h1><p>Před spuštěním zálohujte databázi.</p>';
            echo '<form method="post">' . csrfField() . '<button type="submit">Spustit migraci</button></form></body></html>';
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
            http_response_code(403);
            exit('Neplatný bezpečnostní token. Otevřete stránku migrace znovu.');
        }
    }
}

header('Content-Type: text/plain; charset=utf-8');
try {
    $pdo = getDB();
    foreach ([
        ['online_training_subscriptions', 'starts_on', 'DATE NULL'],
        ['online_training_subscriptions', 'ends_on', 'DATE NULL'],
        ['online_training_billing', 'issue_number', 'INT NOT NULL DEFAULT 1'],
    ] as [$table, $column, $definition]) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
    echo "OK: časová předplatná a čísla platebních výzev jsou připravena.\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Online subscription migration: ' . $e->getMessage());
    echo "Chyba migrace: " . $e->getMessage() . "\n";
    exit(1);
}
