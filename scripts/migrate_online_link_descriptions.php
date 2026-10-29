<?php
$isCli = php_sapi_name() === 'cli';
if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    if (!isAdminLoggedIn()) {
        http_response_code(403);
        $loginUrl = htmlspecialchars(BASE_URL . '/login_admin.php', ENT_QUOTES, 'UTF-8');
        exit('Pro spuštění migrace se nejprve <a href="' . $loginUrl . '">přihlaste jako administrátor</a>, poté tuto stránku znovu otevřete.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="cs"><meta charset="utf-8"><title>Migrace popisů odkazů</title><body>';
        echo '<h1>Migrace popisů odkazů online tréninku</h1><p>Před spuštěním zálohujte databázi.</p>';
        echo '<form method="post">' . csrfField() . '<button type="submit">Spustit migraci</button></form></body></html>';
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Neplatný bezpečnostní token. Otevřete stránku migrace znovu.');
    }
}
header('Content-Type: text/plain; charset=utf-8');
try {
    $pdo = getDB();
    $column = $pdo->query("SHOW COLUMNS FROM online_training_attachments LIKE 'description'");
    if (!$column->fetch()) $pdo->exec('ALTER TABLE online_training_attachments ADD COLUMN description TEXT NULL');
    echo "OK: popisy odkazů online tréninku jsou připraveny.\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Online link descriptions migration: ' . $e->getMessage());
    echo 'Chyba migrace: ' . $e->getMessage() . "\n";
    exit(1);
}
