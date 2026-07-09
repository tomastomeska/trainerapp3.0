<?php
// login_admin.php – přihlašovací stránka superadministrátora
// Tato stránka není linkována z aplikace – přístup pouze přes přímou URL.
require_once __DIR__ . '/includes/admin_auth.php';

function resolvePhpMailerSrc(array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (file_exists($candidate . '/PHPMailer.php')) {
            return $candidate;
        }
    }
    return null;
}

function sendAdminTwoFactorCodeEmail(string $toEmail, string $adminName, string $code): bool {
    $phpmailerSrc = resolvePhpMailerSrc([
        __DIR__ . '/vendor/phpmailer/phpmailer/src',
        dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src',
    ]);
    if ($phpmailerSrc === null) {
        return false;
    }

    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        if (function_exists('_configureMail')) {
            _configureMail($mail);
        } else {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->AuthType = 'LOGIN';
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        }

        $safeName = htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8');
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = '2FA kód pro přihlášení do administrace';
        $mail->Body = '<p>Dobrý den ' . $safeName . ',</p>'
            . '<p>váš ověřovací kód pro přihlášení je:</p>'
            . '<p style="font-size:28px;font-weight:700;letter-spacing:4px;">' . $code . '</p>'
            . '<p>Kód je platný 10 minut.</p>';
        $mail->AltBody = "Váš ověřovací kód pro přihlášení je: {$code}. Kód je platný 10 minut.";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('sendAdminTwoFactorCodeEmail SMTP error: ' . $e->getMessage());
    }

    // Fallback for shared-hosting setups where external SMTP can be blocked.
    try {
        $fallback = new PHPMailer\PHPMailer\PHPMailer(true);
        $fallback->isMail();
        $fallback->CharSet = 'UTF-8';
        $fallback->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $safeName = htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8');
        $fallback->addAddress($toEmail);
        $fallback->isHTML(true);
        $fallback->Subject = '2FA kód pro přihlášení do administrace';
        $fallback->Body = '<p>Dobrý den ' . $safeName . ',</p>'
            . '<p>váš ověřovací kód pro přihlášení je:</p>'
            . '<p style="font-size:28px;font-weight:700;letter-spacing:4px;">' . $code . '</p>'
            . '<p>Kód je platný 10 minut.</p>';
        $fallback->AltBody = "Váš ověřovací kód pro přihlášení je: {$code}. Kód je platný 10 minut.";
        $fallback->send();
        return true;
    } catch (Throwable $e) {
        error_log('sendAdminTwoFactorCodeEmail fallback error: ' . $e->getMessage());
        if (function_exists('appLogEvent')) {
            appLogEvent(
                'admin_2fa_email_failed',
                'error',
                '2FA email delivery failed (SMTP + fallback)',
                [
                    'smtp_host' => defined('SMTP_HOST') ? SMTP_HOST : null,
                    'smtp_port' => defined('SMTP_PORT') ? SMTP_PORT : null,
                    'to_email' => $toEmail,
                    'error' => $e->getMessage(),
                ],
                'guest',
                null,
                $adminName
            );
        }
        return false;
    }
}

// Přesměrovat přihlášeného admina
if (isAdminLoggedIn()) {
    redirect(BASE_URL . '/admin/dashboard.php');
}

$error = null;
$info = null;
$pending2fa = !empty($_SESSION['pending_superadmin_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token. Zkuste to znovu.';
    } else {
        $action = (string)($_POST['action'] ?? 'authenticate');

        if ($action === 'verify_2fa') {
            if (empty($_SESSION['pending_superadmin_id']) || empty($_SESSION['pending_superadmin_2fa_hash']) || empty($_SESSION['pending_superadmin_2fa_expires'])) {
                $error = 'Relace pro 2FA ověření vypršela. Přihlaste se prosím znovu.';
                unset($_SESSION['pending_superadmin_id'], $_SESSION['pending_superadmin_name'], $_SESSION['pending_superadmin_username'], $_SESSION['pending_superadmin_2fa_hash'], $_SESSION['pending_superadmin_2fa_expires'], $_SESSION['pending_superadmin_2fa_attempts'], $_SESSION['pending_superadmin_2fa_email']);
            } else {
                $code = trim((string)($_POST['two_factor_code'] ?? ''));
                $attempts = (int)($_SESSION['pending_superadmin_2fa_attempts'] ?? 0);
                if ($attempts >= 5) {
                    $error = 'Příliš mnoho neúspěšných pokusů. Přihlaste se znovu.';
                    unset($_SESSION['pending_superadmin_id'], $_SESSION['pending_superadmin_name'], $_SESSION['pending_superadmin_username'], $_SESSION['pending_superadmin_2fa_hash'], $_SESSION['pending_superadmin_2fa_expires'], $_SESSION['pending_superadmin_2fa_attempts'], $_SESSION['pending_superadmin_2fa_email']);
                } elseif (time() > (int)$_SESSION['pending_superadmin_2fa_expires']) {
                    $error = 'Platnost ověřovacího kódu vypršela. Přihlaste se znovu.';
                    unset($_SESSION['pending_superadmin_id'], $_SESSION['pending_superadmin_name'], $_SESSION['pending_superadmin_username'], $_SESSION['pending_superadmin_2fa_hash'], $_SESSION['pending_superadmin_2fa_expires'], $_SESSION['pending_superadmin_2fa_attempts'], $_SESSION['pending_superadmin_2fa_email']);
                } elseif (!preg_match('/^\d{6}$/', $code)) {
                    $_SESSION['pending_superadmin_2fa_attempts'] = $attempts + 1;
                    $error = 'Zadejte platný 6místný kód.';
                } elseif (!hash_equals((string)$_SESSION['pending_superadmin_2fa_hash'], hash('sha256', $code))) {
                    $_SESSION['pending_superadmin_2fa_attempts'] = $attempts + 1;
                    $error = 'Ověřovací kód není správný.';
                } else {
                    $pdo = getDB();
                    $adminId = (int)$_SESSION['pending_superadmin_id'];
                    $adminName = (string)($_SESSION['pending_superadmin_name'] ?? ($_SESSION['pending_superadmin_username'] ?? 'admin'));
                    session_regenerate_id(true);
                    $_SESSION['superadmin_id'] = $adminId;
                    $_SESSION['superadmin_name'] = $adminName;
                    unset($_SESSION['pending_superadmin_id'], $_SESSION['pending_superadmin_name'], $_SESSION['pending_superadmin_username'], $_SESSION['pending_superadmin_2fa_hash'], $_SESSION['pending_superadmin_2fa_expires'], $_SESSION['pending_superadmin_2fa_attempts'], $_SESSION['pending_superadmin_2fa_email']);
                    try {
                        $pdo->prepare('UPDATE superadmins SET last_login = NOW(), two_factor_skip_until = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?')->execute([$adminId]);
                    } catch (Throwable $e) {
                        error_log('Admin last_login update failed: ' . $e->getMessage());
                    }
                    redirect(BASE_URL . '/admin/dashboard.php');
                }
            }
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($username === '' || $password === '') {
                $error = 'Vyplňte uživatelské jméno i heslo.';
            } else {
                $pdo  = getDB();
                $stmt = $pdo->prepare('SELECT id, password, name, email, COALESCE(two_factor_enabled, 1) AS two_factor_enabled, two_factor_skip_until FROM superadmins WHERE username = ?');
                $stmt->execute([$username]);
                $admin = $stmt->fetch();

                if ($admin && password_verify($password, (string)$admin['password'])) {
                    $twoFactorEnabled = ((int)($admin['two_factor_enabled'] ?? 1) === 1);
                    $skipUntilTs = !empty($admin['two_factor_skip_until']) ? strtotime((string)$admin['two_factor_skip_until']) : false;
                    $twoFactorSkipActive = ($skipUntilTs !== false && $skipUntilTs > time());

                    if (!$twoFactorEnabled || $twoFactorSkipActive) {
                        $adminId = (int)$admin['id'];
                        $adminName = (string)($admin['name'] ?: $username);
                        session_regenerate_id(true);
                        $_SESSION['superadmin_id'] = $adminId;
                        $_SESSION['superadmin_name'] = $adminName;
                        unset($_SESSION['pending_superadmin_id'], $_SESSION['pending_superadmin_name'], $_SESSION['pending_superadmin_username'], $_SESSION['pending_superadmin_2fa_hash'], $_SESSION['pending_superadmin_2fa_expires'], $_SESSION['pending_superadmin_2fa_attempts'], $_SESSION['pending_superadmin_2fa_email']);
                        try {
                            $pdo->prepare('UPDATE superadmins SET last_login = NOW() WHERE id = ?')->execute([$adminId]);
                        } catch (Throwable $e) {
                            error_log('Admin last_login update failed: ' . $e->getMessage());
                        }
                        redirect(BASE_URL . '/admin/dashboard.php');
                    } else {
                        $adminEmail = trim((string)($admin['email'] ?? ''));
                        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                            $error = 'Admin účet nemá nastavený platný e-mail pro 2FA. Kontaktujte správce systému.';
                        } else {
                            $code = (string)random_int(100000, 999999);
                            $_SESSION['pending_superadmin_id'] = (int)$admin['id'];
                            $_SESSION['pending_superadmin_name'] = (string)($admin['name'] ?: $username);
                            $_SESSION['pending_superadmin_username'] = $username;
                            $_SESSION['pending_superadmin_2fa_hash'] = hash('sha256', $code);
                            $_SESSION['pending_superadmin_2fa_expires'] = time() + 600;
                            $_SESSION['pending_superadmin_2fa_attempts'] = 0;
                            $_SESSION['pending_superadmin_2fa_email'] = $adminEmail;

                            if (!sendAdminTwoFactorCodeEmail($adminEmail, (string)($admin['name'] ?: $username), $code)) {
                                unset($_SESSION['pending_superadmin_id'], $_SESSION['pending_superadmin_name'], $_SESSION['pending_superadmin_username'], $_SESSION['pending_superadmin_2fa_hash'], $_SESSION['pending_superadmin_2fa_expires'], $_SESSION['pending_superadmin_2fa_attempts'], $_SESSION['pending_superadmin_2fa_email']);
                                $error = 'Nepodařilo se odeslat 2FA kód e-mailem. Zkuste to prosím znovu.';
                            } else {
                                $pending2fa = true;
                                $masked = preg_replace('/(^.).*(@.*$)/', '$1***$2', $adminEmail) ?: $adminEmail;
                                $info = 'Ověřovací kód byl odeslán na e-mail ' . $masked . '.';
                            }
                        }
                    }
                } else {
                    usleep(500000);
                    $error = 'Nesprávné přihlašovací údaje.';
                }
            }
        }
    }
}

$pending2fa = !empty($_SESSION['pending_superadmin_id']);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrace – <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="admin-auth">
<div class="container admin-auth-wrap">
    <div class="text-center mb-4">
        <div class="display-4 admin-auth-logo mb-2">
            <i class="fas fa-shield-halved"></i>
        </div>
        <h2 class="fw-bold admin-auth-title"><?= APP_NAME ?></h2>
        <p class="admin-auth-subtitle">Administrátorský přístup</p>
    </div>
    <div class="card shadow-lg border-0 admin-auth-card">
        <div class="card-body p-4">
            <h5 class="card-title mb-4 text-center admin-auth-heading">
                <i class="fas fa-user-shield me-2"></i>Přihlásit se
            </h5>

            <?php if ($info): ?>
            <div class="alert alert-info py-2"><?= h($info) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrfField() ?>
                <?php if ($pending2fa): ?>
                <input type="hidden" name="action" value="verify_2fa">
                <div class="mb-3">
                    <label class="form-label fw-semibold admin-auth-label">Ověřovací kód (2FA)</label>
                    <input type="text" name="two_factor_code" class="form-control admin-auth-input"
                           inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus autocomplete="one-time-code"
                           placeholder="123456">
                </div>
                <button type="submit" class="btn w-100 fw-bold py-2 admin-auth-submit">
                    <i class="fas fa-shield-check me-2"></i>Ověřit a přihlásit se
                </button>
                <?php else: ?>
                <input type="hidden" name="action" value="authenticate">
                <div class="mb-3">
                    <label class="form-label fw-semibold admin-auth-label">Uživatelské jméno</label>
                    <input type="text" name="username" class="form-control admin-auth-input"
                           value="<?= h($_POST['username'] ?? '') ?>"
                           required autofocus autocomplete="username">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold admin-auth-label">Heslo</label>
                    <input type="password" name="password" class="form-control admin-auth-input"
                           required autocomplete="current-password">
                </div>
                <button type="submit" class="btn w-100 fw-bold py-2 admin-auth-submit">
                    <i class="fas fa-sign-in-alt me-2"></i>Přihlásit se
                </button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <p class="text-center mt-3 small admin-auth-back">
        <a href="<?= BASE_URL ?>/login.php" class="admin-auth-back text-decoration-none">← Zpět na přihlášení trenérů</a>
    </p>
</div>
</body>
</html>
