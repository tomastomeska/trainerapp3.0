<?php
require_once __DIR__ . '/../includes/admin_auth.php';

requireAdminLogin();
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; base-uri 'none'");
header('X-Robots-Tag: noindex, nofollow');

$cronUrl = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
        http_response_code(403);
        $error = 'Neplatný bezpečnostní token.';
    } elseif (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        http_response_code(403);
        $error = 'Adresu lze zobrazit pouze přes HTTPS.';
    } else {
        try {
            $pdo = getDB();
            $insert = $pdo->prepare('INSERT IGNORE INTO app_settings (`key`, `value`) VALUES (?, ?)');
            $insert->execute(['push_cron_secret', bin2hex(random_bytes(32))]);
            $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ? LIMIT 1');
            $stmt->execute(['push_cron_secret']);
            $secret = $stmt->fetchColumn();
            if (!is_string($secret) || preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1) {
                throw new RuntimeException('Invalid push cron secret.');
            }
            $cronUrl = 'https://www.reservio.online/cron_push_notifications.php?secret=' . $secret;
        } catch (Throwable $e) {
            error_log('Push cron setup failed: ' . get_class($e));
            http_response_code(503);
            $error = 'Adresu se nepodařilo připravit. Zkontrolujte serverový log.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CRON pro push oznámení</title>
</head>
<body>
    <h1>CRON pro push oznámení</h1>
    <?php if ($error !== null): ?>
        <p role="alert"><?= h($error) ?></p>
    <?php elseif ($cronUrl !== null): ?>
        <p>Adresu zkopírujte pouze do nastavení CRONu ve WEDOSu. Nikomu ji neposílejte.</p>
        <label for="cron-url">Tajná adresa CRONu:</label>
        <input id="cron-url" type="text" readonly value="<?= h($cronUrl) ?>" size="100">
    <?php else: ?>
        <p>Adresa obsahuje tajný klíč určený pouze pro WEDOS CRON.</p>
        <form method="post">
            <?= csrfField() ?>
            <button type="submit">Připravit nebo zobrazit adresu CRONu</button>
        </form>
    <?php endif; ?>
</body>
</html>
