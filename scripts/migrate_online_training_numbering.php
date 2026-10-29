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
        echo '<!doctype html><html lang="cs"><meta charset="utf-8"><title>Číslování online tréninků</title><body>';
        echo '<h1>Číslování online tréninků</h1><p>Před spuštěním zálohujte databázi. Každý sportovec bude mít u každého trenéra vlastní řadu. Existující čísla zůstanou zachována.</p>';
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
    $columns = $pdo->query("SHOW INDEX FROM online_trainings WHERE Key_name = 'uq_online_training_sequence'")->fetchAll(PDO::FETCH_ASSOC);
    usort($columns, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
    if (array_column($columns, 'Column_name') !== ['trainer_id', 'athlete_id', 'sequence_number']
        || array_filter($columns, static fn(array $column): bool => (int)$column['Non_unique'] !== 0)) {
        $drop = $columns ? 'DROP INDEX uq_online_training_sequence, ' : '';
        $pdo->exec('ALTER TABLE online_trainings ' . $drop . 'ADD UNIQUE KEY uq_online_training_sequence (trainer_id, athlete_id, sequence_number)');
    }
    echo "OK: každý sportovec má u každého trenéra vlastní číselnou řadu online tréninků.\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Online training numbering migration: ' . $e->getMessage());
    echo 'Chyba migrace: ' . $e->getMessage() . "\n";
    exit(1);
}
