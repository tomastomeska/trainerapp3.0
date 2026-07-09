<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Přesměrovat přihlášeného na dashboard
if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token. Zkuste to znovu.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Vyplňte uživatelské jméno i heslo.';
        } else {
            $pdo  = getDB();
            $stmt = $pdo->prepare('SELECT id, password, name, is_active, force_password_change FROM coaches WHERE username = ?');
            $stmt->execute([$username]);
            $coach = $stmt->fetch();

            if ($coach && password_verify($password, $coach['password'])) {
                if (!$coach['is_active']) {
                    $error = 'Váš účet byl zablokován. Kontaktujte správce.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['coach_id']   = $coach['id'];
                    $_SESSION['coach_name'] = $coach['name'] ?: $username;
                    $_SESSION['coach_force_password_change'] = (int)($coach['force_password_change'] ?? 0);
                    // Aktualizace posledního přihlášení
                    $pdo->prepare('UPDATE coaches SET last_login = NOW() WHERE id = ?')->execute([$coach['id']]);
                    redirect(BASE_URL . '/dashboard.php');
                }
            } else {
                $error = 'Nesprávné přihlašovací údaje.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Přihlášení – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-page bg-dark">
<div class="container" style="max-width:420px;margin-top:10vh">
    <div class="text-center mb-4">
        <div class="display-4 text-warning mb-2">🏋️</div>
        <h2 class="text-white fw-bold" id="appTitle" style="cursor:default;user-select:none"><?= APP_NAME ?></h2>
        <p class="text-secondary">Aplikace pro trenéry</p>
    </div>
    <div class="card shadow-lg border-0">
        <div class="card-body p-4">
            <h5 class="card-title mb-4 text-center">Přihlásit se</h5>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2">
                    <i class="fas fa-exclamation-circle me-1"></i><?= h($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">Uživatelské jméno</label>
                    <input type="text" name="username" class="form-control form-control-lg"
                           value="<?= h($_POST['username'] ?? '') ?>"
                           autofocus autocomplete="username" required>
                </div>
                <div class="mb-4">
                    <label class="form-label">Heslo</label>
                    <input type="password" name="password" class="form-control form-control-lg"
                           autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-warning btn-lg w-100 fw-bold">
                    Přihlásit se
                </button>
            </form>
        </div>
    </div>
    </div>
    <div class="text-center mt-4 text-secondary small">
        <div class="mb-1">verze <?= h(getAppSetting('app_version', defined('APP_VERSION') ? APP_VERSION : '—')) ?></div>
        <div>
            Vytvořil <strong class="text-white">Tomáš Tomeška</strong>
            &nbsp;·&nbsp;
            <a href="mailto:info@reservio.online?subject=Zpr%C3%A1va%20z%20TrainerApp" class="text-warning text-decoration-none">
                <i class="fas fa-envelope me-1"></i>info@reservio.online
            </a>
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('appTitle').addEventListener('dblclick', function () {
    window.location.href = '<?= BASE_URL ?>/login_admin.php';
});
</script>
</body>
</html>
