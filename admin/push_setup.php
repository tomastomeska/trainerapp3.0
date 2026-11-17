<?php
require_once __DIR__ . '/../includes/admin_auth.php';

requireAdminLogin();
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$autoload = __DIR__ . '/../dependencies/webpush/vendor/autoload.php';
$error = null;
$message = null;
$configured = false;

try {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT 1 FROM app_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute(['push_vapid_keys']);
    $configured = (bool)$stmt->fetchColumn();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            $error = 'Neplatný bezpečnostní token.';
        } elseif ($configured) {
            $message = 'Klíče už existují. Nové nebyly vytvořeny.';
        } elseif (!is_file($autoload)) {
            $error = 'Chybí knihovna pro push oznámení.';
        } else {
            require_once $autoload;
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
            $value = json_encode($keys, JSON_THROW_ON_ERROR);
            $insert = $pdo->prepare('INSERT IGNORE INTO app_settings (`key`, `value`) VALUES (?, ?)');
            $insert->execute(['push_vapid_keys', $value]);
            $configured = true;
            $message = $insert->rowCount() === 1
                ? 'Klíče byly bezpečně vytvořeny.'
                : 'Klíče už byly vytvořeny jiným požadavkem.';
        }
    }
} catch (Throwable $e) {
    error_log('Push setup failed: ' . get_class($e));
    http_response_code(503);
    $error = 'Kontrola nebo vytvoření klíčů se nezdařilo. Zkontrolujte serverový log.';
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nastavení push oznámení</title>
</head>
<body>
    <h1>Nastavení push oznámení</h1>
    <?php if ($error !== null): ?>
        <p role="alert"><?= h($error) ?></p>
    <?php elseif ($message !== null): ?>
        <p role="status"><?= h($message) ?></p>
    <?php endif; ?>
    <?php if ($configured): ?>
        <p>Klíče pro oznámení jsou nastavené.</p>
    <?php else: ?>
        <p>Klíče pro oznámení zatím nejsou nastavené.</p>
        <form method="post">
            <?= csrfField() ?>
            <button type="submit">Vytvořit klíče pro oznámení</button>
        </form>
    <?php endif; ?>
</body>
</html>
