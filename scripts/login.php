<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

// Přesměrovat přihlášeného na dashboard
if (isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
}
if (athleteIsLoggedIn()) {
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$error = null;
$notice = null;
$noticeType = 'success';
$openModal = '';
$loginType = 'coach';
$accessRequest = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'note' => '',
];
$resetRequest = [
    'account_type' => 'coach',
    'identity' => '',
];

function loginClientIpAddress(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if (str_contains((string)$ip, ',')) {
        $ip = trim(explode(',', (string)$ip)[0]);
    }
    return mb_substr((string)$ip, 0, 45);
}

function isLoginRateLimited(PDO $pdo, string $ipAddress, int $windowMinutes = 15, int $maxAttempts = 20): bool {
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM app_event_log
             WHERE event_type IN ("login_failed", "login_blocked")
               AND ip_address = ?
               AND created_at >= (NOW() - INTERVAL ? MINUTE)'
        );
        $stmt->execute([$ipAddress, max(1, $windowMinutes)]);
        return (int)$stmt->fetchColumn() >= max(1, $maxAttempts);
    } catch (Throwable $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'login');

    if ($action === 'request_coach_access') {
        $accessRequest['first_name'] = trim((string)($_POST['first_name'] ?? ''));
        $accessRequest['last_name'] = trim((string)($_POST['last_name'] ?? ''));
        $accessRequest['email'] = mb_strtolower(trim((string)($_POST['email'] ?? '')), 'UTF-8');
        $accessRequest['note'] = trim((string)($_POST['note'] ?? ''));

        if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
            $error = 'Neplatný bezpečnostní token. Zkuste to znovu.';
            $openModal = 'coachAccessModal';
        } elseif ($accessRequest['first_name'] === '' || $accessRequest['last_name'] === '' || $accessRequest['email'] === '') {
            $error = 'Vyplňte jméno, příjmení a e-mail.';
            $openModal = 'coachAccessModal';
        } elseif (!filter_var($accessRequest['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Zadejte platný e-mail.';
            $openModal = 'coachAccessModal';
        } else {
            $pdo = getDB();
            $reporterName = trim($accessRequest['first_name'] . ' ' . $accessRequest['last_name']);
            $description = "Žádost o přístup trenéra z přihlašovací stránky.\n\n"
                . "Jméno: {$reporterName}\n"
                . "E-mail: {$accessRequest['email']}\n"
                . ($accessRequest['note'] !== '' ? "Poznámka:\n{$accessRequest['note']}\n" : '');

            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
            if (str_contains((string)$ip, ',')) {
                $ip = trim(explode(',', (string)$ip)[0]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO support_tickets (
                    reporter_type, coach_id, athlete_id, reporter_name, reporter_email,
                    subject, issue_type, description, page_url,
                    ip_address, user_agent, status, created_at, updated_at
                 ) VALUES (?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, "new", NOW(), NOW())'
            );
            $stmt->execute([
                'coach',
                $reporterName,
                $accessRequest['email'],
                'Žádost o přístup trenéra',
                'Žádost o přístup',
                $description,
                BASE_URL . '/login.php',
                mb_substr((string)$ip, 0, 45),
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
            ]);

            $ticketId = (int)$pdo->lastInsertId();
            $ticketPayload = [
                'reporter_name' => $reporterName,
                'reporter_email' => $accessRequest['email'],
                'subject' => 'Žádost o přístup trenéra',
                'issue_type' => 'Žádost o přístup',
                'description' => $description,
                'page_url' => BASE_URL . '/login.php',
                'screenshot_path' => null,
            ];

            sendSupportTicketNotificationEmail($ticketId, $ticketPayload, ['info@reservio.online']);
            sendCoachAccessRequestOwnerEmail('tomas.tomeska@seznam.cz', [
                'first_name' => $accessRequest['first_name'],
                'last_name' => $accessRequest['last_name'],
                'email' => $accessRequest['email'],
                'note' => $accessRequest['note'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $noticeType = 'success';
            $notice = 'Žádost byla odeslána do administrace. Po schválení budete kontaktován na uvedený e-mail.';
            $accessRequest = ['first_name' => '', 'last_name' => '', 'email' => '', 'note' => ''];
            $openModal = 'coachAccessModal';
        }
    } elseif ($action === 'request_password_reset') {
        $resetRequest['account_type'] = (($_POST['account_type'] ?? '') === 'athlete') ? 'athlete' : 'coach';
        $resetRequest['identity'] = trim((string)($_POST['identity'] ?? ''));

        if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
            $error = 'Neplatný bezpečnostní token. Zkuste to znovu.';
            $openModal = 'forgotPasswordModal';
        } elseif ($resetRequest['identity'] === '') {
            $error = 'Vyplňte požadovaný údaj pro reset hesla.';
            $openModal = 'forgotPasswordModal';
        } else {
            $pdo = getDB();
            $accountType = $resetRequest['account_type'];
            $identity = $resetRequest['identity'];
            $targetEmail = '';
            $displayName = '';
            $coachId = null;
            $athleteId = null;

            if ($accountType === 'athlete') {
                $email = mb_strtolower($identity, 'UTF-8');
                $stmt = $pdo->prepare(
                    'SELECT id, first_name, last_name, email
                     FROM athletes
                     WHERE email = ? AND login_enabled = 1
                     LIMIT 1'
                );
                $stmt->execute([$email]);
                $row = $stmt->fetch();
                if ($row && !empty($row['email'])) {
                    $athleteId = (int)$row['id'];
                    $targetEmail = (string)$row['email'];
                    $displayName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
                }
            } else {
                $email = mb_strtolower($identity, 'UTF-8');
                $stmt = $pdo->prepare(
                    'SELECT id, name, username, email
                     FROM coaches
                     WHERE username = ? OR LOWER(email) = ?
                     LIMIT 1'
                );
                $stmt->execute([$identity, $email]);
                $row = $stmt->fetch();
                if ($row && !empty($row['email'])) {
                    $coachId = (int)$row['id'];
                    $targetEmail = (string)$row['email'];
                    $displayName = (string)($row['name'] ?: $row['username']);
                }
            }

            if ($targetEmail !== '' && filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
                $reset = createPasswordResetRequest($accountType, $coachId, $athleteId, $targetEmail, 60);
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                $baseUrlAbsolute = $host !== '' ? ($scheme . '://' . $host . BASE_URL) : BASE_URL;
                $resetUrl = rtrim($baseUrlAbsolute, '/') . '/reset_password.php?token=' . urlencode((string)$reset['token']);
                sendPasswordResetEmail(
                    $targetEmail,
                    $displayName !== '' ? $displayName : 'uživateli',
                    $resetUrl,
                    $accountType === 'athlete' ? 'sportovec' : 'trenér'
                );
            }

            $noticeType = 'success';
            $notice = 'Pokud účet existuje, byl na něj odeslán e-mail s odkazem pro reset hesla.';
            $resetRequest = ['account_type' => 'coach', 'identity' => ''];
        }
    } else {
        $loginType = ($_POST['login_type'] ?? '') === 'athlete' ? 'athlete' : 'coach';
        if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
            $error = 'Neplatný bezpečnostní token. Zkuste to znovu.';
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($username === '' || $password === '') {
                $error = 'Vyplňte uživatelské jméno i heslo.';
            } elseif ($loginType === 'coach') {
                $pdo  = getDB();
                if (isLoginRateLimited($pdo, loginClientIpAddress())) {
                    $error = 'Příliš mnoho pokusů o přihlášení. Zkuste to prosím znovu za několik minut.';
                } else {
                $stmt = $pdo->prepare('SELECT id, password, name, is_active, force_password_change FROM coaches WHERE username = ?');
                $stmt->execute([$username]);
                $coach = $stmt->fetch();

                if ($coach && password_verify($password, $coach['password'])) {
                    if (!$coach['is_active']) {
                        $error = 'Váš účet byl zablokován. Kontaktujte správce.';
                    } else {
                        session_regenerate_id(true);
                        unset($_SESSION['athlete_id'], $_SESSION['athlete_name'], $_SESSION['athlete_coach_id'], $_SESSION['athlete_force_password_change']);
                        $_SESSION['coach_id']   = $coach['id'];
                        $_SESSION['coach_name'] = $coach['name'] ?: $username;
                        $_SESSION['coach_force_password_change'] = (int)($coach['force_password_change'] ?? 0);
                        // Aktualizace posledního přihlášení
                        $pdo->prepare('UPDATE coaches SET last_login = NOW() WHERE id = ?')->execute([$coach['id']]);
                        redirect(BASE_URL . '/dashboard.php');
                    }
                } else {
                    usleep(350000);
                    $error = 'Nesprávné přihlašovací údaje.';
                }
                }
            } else {
                $pdo = getDB();
                if (isLoginRateLimited($pdo, loginClientIpAddress())) {
                    $error = 'Příliš mnoho pokusů o přihlášení. Zkuste to prosím znovu za několik minut.';
                } else {
                $email = mb_strtolower($username, 'UTF-8');
                $stmt = $pdo->prepare(
                    'SELECT id, coach_id, email, password, first_name, last_name, login_enabled, force_password_change
                     FROM athletes
                     WHERE email = ?
                     LIMIT 1'
                );
                $stmt->execute([$email]);
                $athlete = $stmt->fetch();

                if (!$athlete || !(int)$athlete['login_enabled']) {
                    $error = 'Účet sportovce ještě není aktivovaný. Kontaktujte trenéra.';
                } elseif (empty($athlete['password']) || !password_verify($password, (string)$athlete['password'])) {
                    usleep(350000);
                    $error = 'Nesprávné přihlašovací údaje.';
                } else {
                    session_regenerate_id(true);
                    unset($_SESSION['coach_id'], $_SESSION['coach_name']);
                    $_SESSION['athlete_id'] = (int)$athlete['id'];
                    $_SESSION['athlete_name'] = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
                    $_SESSION['athlete_coach_id'] = (int)$athlete['coach_id'];
                    $_SESSION['athlete_force_password_change'] = (int)($athlete['force_password_change'] ?? 1);

                    $pdo->prepare('UPDATE athletes SET last_login = NOW() WHERE id = ?')->execute([(int)$athlete['id']]);

                    if (!empty($_SESSION['athlete_force_password_change'])) {
                        redirect(BASE_URL . '/athlete_change_password.php');
                    }
                    redirect(BASE_URL . '/athlete_dashboard.php');
                }
                }
            }
        }
    }
}

$logoUrl = null;
$configuredLogoPath = trim(getAppSetting('login_logo_path', ''));
if ($configuredLogoPath !== '') {
    $configuredAbsolute = __DIR__ . '/' . ltrim($configuredLogoPath, '/');
    if (is_file($configuredAbsolute)) {
        $logoUrl = BASE_URL . '/' . ltrim($configuredLogoPath, '/');
    }
}

if ($logoUrl === null) {
    $logoFile = null;
    $logoDir = __DIR__ . '/uploads/logo';
    if (is_dir($logoDir)) {
        $allowedExt = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
        foreach (scandir($logoDir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExt, true)) {
                $logoFile = $file;
                break;
            }
        }
    }
    $logoUrl = $logoFile ? (BASE_URL . '/uploads/logo/' . rawurlencode($logoFile)) : null;
}
$showFormOnLoad = $_SERVER['REQUEST_METHOD'] === 'POST';
?>
<!DOCTYPE html>
<html lang="cs">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Přihlášení – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        :root {
            --brand-dark: #101f46;
            --brand-darker: #070d1e;
            --brand-gold: #f3b300;
            --panel-bg: rgba(255, 255, 255, 0.97);
            --text-main: #1b2433;
            --text-soft: #6b7485;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", "Trebuchet MS", sans-serif;
            background:
                radial-gradient(900px 600px at 12% 90%, rgba(243, 179, 0, 0.16), transparent 60%),
                radial-gradient(820px 560px at 100% 10%, rgba(30, 73, 170, 0.28), transparent 58%),
                linear-gradient(150deg, #050b18 0%, #0a1531 30%, #11285f 68%, #132d6e 100%);
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px 16px;
        }

        .login-wrap {
            width: 100%;
            max-width: 620px;
        }

        .brand {
            text-align: center;
            margin-bottom: 8px;
        }

        .brand-stage {
            display: inline-block;
            background: linear-gradient(160deg, rgba(2, 6, 16, 0.95), rgba(10, 18, 35, 0.94));
            border: 1px solid rgba(243, 179, 0, 0.3);
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.35);
            transition: padding .4s ease, transform .4s ease, box-shadow .4s ease;
        }

        .brand-logo {
            max-width: min(72vw, 540px);
            width: 100%;
            height: auto;
            display: inline-block;
            cursor: pointer;
            user-select: none;
            border-radius: 8px;
            transition: max-width .45s ease;
        }

        .brand-fallback {
            color: #ffffff;
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin: 0;
            cursor: pointer;
            user-select: none;
            text-shadow: 0 6px 22px rgba(0, 0, 0, 0.35);
        }

        .intro-actions {
            text-align: center;
            margin-top: 16px;
            opacity: 1;
            transform: translateY(0);
            transition: opacity .25s ease, transform .25s ease;
        }

        .intro-actions .btn-login {
            width: min(92vw, 380px);
        }

        .login-type-switch {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 14px;
        }

        .login-type-btn {
            border-radius: 10px;
            border: 1px solid #d6dced;
            background: #fff;
            font-weight: 700;
            color: #273758;
            padding: .5rem .7rem;
        }

        .login-type-btn.active {
            background: #0f234f;
            border-color: #0f234f;
            color: #fff;
        }

        .login-card {
            background: var(--panel-bg);
            border: 0;
            border-radius: 18px;
            box-shadow: 0 18px 50px rgba(7, 18, 44, 0.45);
            overflow: hidden;
            opacity: 0;
            transform: translateY(20px);
            max-height: 0;
            margin-top: 0;
            pointer-events: none;
            transition: opacity .35s ease, transform .35s ease, max-height .4s ease, margin-top .35s ease;
        }

        .login-card .card-body {
            padding: 26px 24px;
        }

        .login-title {
            text-align: center;
            font-size: 1.85rem;
            margin: 0 0 4px;
            font-weight: 800;
            color: #0f234f;
        }

        .login-sub {
            text-align: center;
            margin: 0 0 22px;
            color: var(--text-soft);
        }

        .form-label {
            font-weight: 700;
            color: #263552;
            margin-bottom: 7px;
        }

        .form-control {
            border-radius: 10px;
            border: 2px solid #d8dfeb;
            min-height: 44px;
            font-size: 0.98rem;
        }

        .form-control:focus {
            border-color: var(--brand-gold);
            box-shadow: 0 0 0 0.25rem rgba(243, 179, 0, 0.2);
        }

        .btn-login {
            min-height: 46px;
            border: 0;
            border-radius: 10px;
            font-weight: 800;
            font-size: 1.05rem;
            color: #10275b;
            background: linear-gradient(180deg, #ffca2f 0%, #f3b300 100%);
            box-shadow: 0 8px 18px rgba(243, 179, 0, 0.35);
        }

        .btn-login:hover {
            color: #0a1a3d;
            transform: translateY(-1px);
            background: linear-gradient(180deg, #ffd34f 0%, #f7bc1f 100%);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .footer-meta {
            text-align: center;
            margin-top: 18px;
            color: #d0d9ee;
            font-size: 0.95rem;
            opacity: 0;
            transform: translateY(8px);
            pointer-events: none;
            transition: opacity .25s ease, transform .25s ease;
        }

        .footer-meta a {
            color: #ffd55b;
            text-decoration: none;
            font-weight: 700;
        }

        .footer-meta a:hover {
            text-decoration: underline;
        }

        body.show-form .login-wrap {
            max-width: 500px;
        }

        body.show-form .brand-stage {
            padding: 10px;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.35);
            transform: translateY(-2px);
        }

        body.show-form .brand-logo {
            max-width: 300px;
        }

        body.show-form .intro-actions {
            opacity: 0;
            transform: translateY(-8px);
            pointer-events: none;
            height: 0;
            margin: 0;
            overflow: hidden;
        }

        body.show-form .login-card {
            opacity: 1;
            transform: translateY(0);
            max-height: 700px;
            margin-top: 12px;
            pointer-events: auto;
        }

        body.show-form .footer-meta {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        @media (max-width: 575px) {
            .login-card .card-body {
                padding: 22px 18px;
            }

            .login-title {
                font-size: 1.55rem;
            }

            .brand-logo {
                max-width: min(84vw, 440px);
            }

            body.show-form .brand-logo {
                max-width: 240px;
            }
        }
    </style>
</head>

<body class="<?= $showFormOnLoad ? 'show-form' : '' ?>">
    <div class="login-wrap">
        <div class="brand">
            <?php if ($logoUrl): ?>
                <div class="brand-stage">
                    <img src="<?= h($logoUrl) ?>"
                        alt="<?= h(APP_NAME) ?>"
                        id="brandLogo"
                        class="brand-logo"
                        title="Dvojklik pro administraci">
                </div>
            <?php else: ?>
                <h1 id="brandLogo" class="brand-fallback" title="Dvojklik pro administraci"><?= h(APP_NAME) ?></h1>
            <?php endif; ?>
        </div>

        <div class="intro-actions">
            <button type="button" id="btnShowLogin" class="btn btn-login">Přihlášení</button>
            <button type="button" class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#coachAccessModal">Žádost o přístup trenéra</button>
        </div>

        <div class="card login-card">
            <div class="card-body">
                <h2 class="login-title">Přihlášení</h2>
                <p class="login-sub" id="loginSubTitle">Přihlášení pro trenéry</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 mb-3"><?= h($error) ?></div>
                <?php endif; ?>
                <?php if ($notice): ?>
                    <div class="alert alert-<?= h($noticeType) ?> py-2 mb-3"><?= h($notice) ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="login_type" id="loginTypeInput" value="<?= h($loginType) ?>">

                    <div class="login-type-switch" role="group" aria-label="Typ přihlášení">
                        <button type="button" class="login-type-btn <?= $loginType === 'coach' ? 'active' : '' ?>" data-type="coach">
                            Trenér
                        </button>
                        <button type="button" class="login-type-btn <?= $loginType === 'athlete' ? 'active' : '' ?>" data-type="athlete">
                            Sportovec
                        </button>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="username" id="usernameLabel">Uživatelské jméno</label>
                        <input id="username" type="text" name="username" class="form-control"
                            value="<?= h($_POST['username'] ?? '') ?>"
                            autofocus autocomplete="username" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="password">Heslo</label>
                        <input id="password" type="password" name="password" class="form-control"
                            autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn btn-login w-100">Přihlásit se</button>
                </form>

                <div class="login-links mt-3 d-flex flex-wrap gap-2">
                    <button type="button" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">Zapomenuté heslo</button>
                    <button type="button" data-bs-toggle="modal" data-bs-target="#coachAccessModal">Žádost o přístup trenéra</button>
                </div>
            </div>
        </div>

        <div class="modal fade" id="coachAccessModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Žádost o přístup trenéra</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
                    </div>
                    <form method="post" novalidate>
                        <div class="modal-body">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="request_coach_access">

                            <div class="alert alert-info py-2">
                                Žádost o přístup je určena pouze trenérům. Sportovcům přístup zřizuje výhradně jejich trenér.
                            </div>

                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label" for="access_first_name">Jméno</label>
                                    <input id="access_first_name" type="text" name="first_name" class="form-control" value="<?= h($accessRequest['first_name']) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="access_last_name">Příjmení</label>
                                    <input id="access_last_name" type="text" name="last_name" class="form-control" value="<?= h($accessRequest['last_name']) ?>" required>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label" for="access_email">E-mail</label>
                                <input id="access_email" type="email" name="email" class="form-control" value="<?= h($accessRequest['email']) ?>" required>
                            </div>

                            <div>
                                <label class="form-label" for="access_note">Doplňující text (volitelné)</label>
                                <textarea id="access_note" name="note" class="form-control" rows="3" maxlength="2000"><?= h($accessRequest['note']) ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="coachAccessCancelBtn">Zrušit</button>
                            <button type="submit" class="btn btn-primary" id="coachAccessSubmitBtn">Odeslat žádost</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Zapomenuté heslo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
                    </div>
                    <form method="post" novalidate>
                        <div class="modal-body">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="request_password_reset">

                            <div class="mb-2">
                                <label class="form-label" for="reset_account_type">Typ účtu</label>
                                <select id="reset_account_type" name="account_type" class="form-select" required>
                                    <option value="coach" <?= $resetRequest['account_type'] === 'coach' ? 'selected' : '' ?>>Trenér</option>
                                    <option value="athlete" <?= $resetRequest['account_type'] === 'athlete' ? 'selected' : '' ?>>Sportovec</option>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label" for="reset_identity" id="reset_identity_label">Uživatelské jméno nebo e-mail</label>
                                <input id="reset_identity" type="text" name="identity" class="form-control" value="<?= h($resetRequest['identity']) ?>" required>
                                <div class="form-text">Pokud účet existuje, odešleme odkaz pro reset hesla na registrovaný e-mail.</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                            <button type="submit" class="btn btn-primary">Odeslat odkaz</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="footer-meta">
            <div class="mb-1">verze <?= h(getAppSetting('app_version', defined('APP_VERSION') ? APP_VERSION : '—')) ?></div>
            <div>
                Vytvořil <strong>WebNexGen</strong>
                &nbsp;·&nbsp;
                <a href="mailto:info@reservio.online?subject=Zpr%C3%A1va%20z%20TrainerApp">Kontaktujte nás</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const brandLogo = document.getElementById('brandLogo');
        if (brandLogo) {
            brandLogo.addEventListener('dblclick', function() {
                window.location.href = '<?= BASE_URL ?>/login_admin.php';
            });
        }

        const btnShowLogin = document.getElementById('btnShowLogin');
        if (btnShowLogin) {
            btnShowLogin.addEventListener('click', function() {
                document.body.classList.add('show-form');
                setTimeout(function() {
                    const username = document.getElementById('username');
                    if (username) username.focus();
                }, 260);
            });
        }

        <?php if ($openModal === 'coachAccessModal'): ?>
        const coachAccessModalEl = document.getElementById('coachAccessModal');
        if (coachAccessModalEl) {
            const coachAccessModal = new bootstrap.Modal(coachAccessModalEl);
            coachAccessModal.show();
        }
        <?php endif; ?>

        const loginTypeInput = document.getElementById('loginTypeInput');
        const loginSubTitle = document.getElementById('loginSubTitle');
        const usernameLabel = document.getElementById('usernameLabel');
        const typeButtons = Array.from(document.querySelectorAll('.login-type-btn'));

        function applyLoginType(type) {
            const isAthlete = type === 'athlete';
            if (loginTypeInput) {
                loginTypeInput.value = isAthlete ? 'athlete' : 'coach';
            }
            if (loginSubTitle) {
                loginSubTitle.textContent = isAthlete ? 'Přihlášení pro sportovce' : 'Přihlášení pro trenéry';
            }
            if (usernameLabel) {
                usernameLabel.textContent = isAthlete ? 'E-mail' : 'Uživatelské jméno';
            }
            typeButtons.forEach((btn) => {
                btn.classList.toggle('active', btn.dataset.type === (isAthlete ? 'athlete' : 'coach'));
            });
        }

        typeButtons.forEach((btn) => {
            btn.addEventListener('click', function() {
                applyLoginType(btn.dataset.type === 'athlete' ? 'athlete' : 'coach');
            });
        });

        applyLoginType((loginTypeInput && loginTypeInput.value === 'athlete') ? 'athlete' : 'coach');
    </script>
</body>

</html>