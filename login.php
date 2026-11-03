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

            $supportTicketEmailsSent = sendSupportTicketNotificationEmail($ticketId, $ticketPayload);
            $ownerEmailSent = sendCoachAccessRequestOwnerEmail(getAdminNotificationEmail(), [
                'first_name' => $accessRequest['first_name'],
                'last_name' => $accessRequest['last_name'],
                'email' => $accessRequest['email'],
                'note' => $accessRequest['note'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($supportTicketEmailsSent < 1 && !$ownerEmailSent) {
                $noticeType = 'warning';
                $notice = 'Žádost byla uložena, ale e-mailovou notifikaci se nepodařilo odeslat. Zkuste to prosím později.';
            } else {
                $noticeType = 'success';
                $notice = 'Žádost byla odeslána do administrace. Po schválení budete kontaktován na uvedený e-mail.';
            }
            $accessRequest = ['first_name' => '', 'last_name' => '', 'email' => '', 'note' => ''];
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
                $resetEmailSent = sendPasswordResetEmail(
                    $targetEmail,
                    $displayName !== '' ? $displayName : 'uživateli',
                    $resetUrl,
                    $accountType === 'athlete' ? 'sportovec' : 'trenér'
                );
                if (!$resetEmailSent) {
                    $noticeType = 'warning';
                    $notice = 'Žádost o reset byla zaznamenána, ale e-mail s odkazem se nepodařilo odeslat. Zkontrolujte nastavení pošty.';
                }
            }

            if ($notice === null) {
                $noticeType = 'success';
                $notice = 'Pokud účet existuje, byl na něj odeslán e-mail s odkazem pro reset hesla.';
            }
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
                        appLogEvent(
                            'login_blocked',
                            'warning',
                            'Pokus o prihlaseni na deaktivovany ucet trenera',
                            ['username' => $username],
                            'coach',
                            (int)$coach['id'],
                            (string)($coach['name'] ?: $username)
                        );
                        $error = 'Váš účet byl zablokován. Kontaktujte správce.';
                    } else {
                        session_regenerate_id(true);
                        unset($_SESSION['athlete_id'], $_SESSION['athlete_name'], $_SESSION['athlete_coach_id'], $_SESSION['athlete_force_password_change']);
                        $_SESSION['coach_id']   = $coach['id'];
                        $_SESSION['coach_name'] = $coach['name'] ?: $username;
                        $_SESSION['coach_force_password_change'] = (int)($coach['force_password_change'] ?? 0);
                        // Aktualizace posledního přihlášení
                        $pdo->prepare('UPDATE coaches SET last_login = NOW() WHERE id = ?')->execute([$coach['id']]);
                        appLogEvent(
                            'login_success',
                            'info',
                            'Uspesne prihlaseni trenera',
                            [],
                            'coach',
                            (int)$coach['id'],
                            (string)($coach['name'] ?: $username)
                        );
                        redirect(BASE_URL . '/dashboard.php');
                    }
                } else {
                    appLogEvent(
                        'login_failed',
                        'warning',
                        'Neplatne prihlasovaci udaje trenera',
                        ['username' => $username],
                        'guest',
                        null,
                        $username
                    );
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
                    appLogEvent(
                        'login_blocked',
                        'warning',
                        'Pokus o prihlaseni neaktivniho sportovce',
                        ['email' => $email],
                        'guest',
                        null,
                        $email
                    );
                    $error = 'Účet sportovce ještě není aktivovaný. Kontaktujte trenéra.';
                } elseif (empty($athlete['password']) || !password_verify($password, (string)$athlete['password'])) {
                    appLogEvent(
                        'login_failed',
                        'warning',
                        'Neplatne prihlasovaci udaje sportovce',
                        ['email' => $email],
                        'athlete',
                        (int)($athlete['id'] ?? 0) ?: null,
                        trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''))
                    );
                    usleep(350000);
                    $error = 'Nesprávné přihlašovací údaje.';
                } else {
                    session_regenerate_id(true);
                    unset($_SESSION['coach_id'], $_SESSION['coach_name']);
                    $_SESSION['athlete_id'] = (int)$athlete['id'];
                    $_SESSION['athlete_name'] = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
                    $_SESSION['athlete_coach_id'] = (int)$athlete['coach_id'];
                    $_SESSION['athlete_force_password_change'] = (int)($athlete['force_password_change'] ?? 1);
                    unset($_SESSION['coach_force_password_change']);

                    $pdo->prepare('UPDATE athletes SET last_login = NOW() WHERE id = ?')->execute([(int)$athlete['id']]);
                    appLogEvent(
                        'login_success',
                        'info',
                        'Uspesne prihlaseni sportovce',
                        [],
                        'athlete',
                        (int)$athlete['id'],
                        trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])
                    );

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

$sublogoUrl = null;
$configuredSublogoPath = trim(getAppSetting('login_sublogo_path', ''));
if ($configuredSublogoPath !== '') {
    $configuredSublogoAbsolute = __DIR__ . '/' . ltrim($configuredSublogoPath, '/');
    if (is_file($configuredSublogoAbsolute)) {
        $sublogoUrl = BASE_URL . '/' . ltrim($configuredSublogoPath, '/');
    }
}
$androidMarketUrl = trim(getAppSetting('mobile_app_android_market_url', ''));
$androidApkPath = trim(getAppSetting('mobile_app_android_apk_path', ''));
$iosMarketUrl = trim(getAppSetting('mobile_app_ios_market_url', ''));
$androidApkUrl = '';
if (str_starts_with($androidApkPath, 'uploads/apps/android/') && !str_contains($androidApkPath, '..')) {
    $androidApkAbsolutePath = __DIR__ . '/' . $androidApkPath;
    if (is_file($androidApkAbsolutePath)) {
        $androidApkUrl = BASE_URL . '/' . implode('/', array_map('rawurlencode', explode('/', $androidApkPath)));
    }
}
$androidStoreUrl = filter_var($androidMarketUrl, FILTER_VALIDATE_URL) && strtolower((string)parse_url($androidMarketUrl, PHP_URL_SCHEME)) === 'https'
    ? $androidMarketUrl
    : $androidApkUrl;
$iosStoreUrl = filter_var($iosMarketUrl, FILTER_VALIDATE_URL) && strtolower((string)parse_url($iosMarketUrl, PHP_URL_SCHEME)) === 'https'
    ? $iosMarketUrl
    : '';
$showFormOnLoad = $_SERVER['REQUEST_METHOD'] === 'POST';
?>
<!DOCTYPE html>
<html lang="cs">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Přihlášení – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --brand-navy: #243143;
            --brand-steel: #3a404b;
            --brand-gold-1: #fbe7a1;
            --brand-gold-2: #d4a73c;
            --brand-gold-3: #9c7420;
            --panel-border: #b9c3cf;
            --text-main: #202c3c;
            --text-soft: #66758a;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", "Trebuchet MS", sans-serif;
            background:
                radial-gradient(900px 600px at 12% 90%, rgba(212, 167, 60, 0.14), transparent 60%),
                radial-gradient(820px 560px at 100% 8%, rgba(70, 83, 104, 0.16), transparent 58%),
                repeating-linear-gradient(90deg, rgba(70, 85, 105, .02) 0 1px, transparent 1px 3px),
                linear-gradient(150deg, #f8fafc 0%, #edf1f6 55%, #dce3ec 100%);
            color: var(--text-main);
            color-scheme: light;
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
            transition: opacity .52s cubic-bezier(.22,.61,.36,1), transform .52s cubic-bezier(.22,.61,.36,1), height .52s cubic-bezier(.22,.61,.36,1), margin .52s cubic-bezier(.22,.61,.36,1);
        }

        .brand-stage {
            display: inline-block;
            background: transparent;
            border: 0;
            border-radius: 0;
            padding: 0;
            box-shadow: none;
            transition: transform .16s ease;
        }

        .brand-logo {
            max-width: min(88vw, 500px);
            max-height: 240px;
            width: auto;
            height: auto;
            display: inline-block;
            cursor: pointer;
            user-select: none;
            border-radius: 8px;
            filter: drop-shadow(0 16px 28px rgba(35, 50, 70, 0.22));
            transition: max-width .45s ease;
        }

        .brand-fallback {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin: 0;
            cursor: pointer;
            user-select: none;
            background: linear-gradient(100deg, #735016 0%, #906717 45%, #735016 70%, #684612 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .sublogo-wrap {
            text-align: center;
            margin: 8px 0 12px;
            transition: opacity .52s cubic-bezier(.22,.61,.36,1), transform .52s cubic-bezier(.22,.61,.36,1), height .52s cubic-bezier(.22,.61,.36,1), margin .52s cubic-bezier(.22,.61,.36,1);
        }

        .sublogo-img {
            max-width: min(84vw, 320px);
            max-height: 130px;
            width: auto;
            height: auto;
            display: inline-block;
        }

        .intro-actions {
            text-align: center;
            margin-top: 16px;
            opacity: 1;
            transform: translateY(0);
            transition: opacity .42s cubic-bezier(.22,.61,.36,1), transform .42s cubic-bezier(.22,.61,.36,1);
        }

        .intro-actions .btn-login {
            width: min(92vw, 380px);
        }

        .app-store-links {
            display: flex;
            justify-content: center;
            width: min(92vw, 380px);
            gap: 10px;
            margin: 38px auto 0;
            transition: opacity .42s ease, transform .42s ease, height .42s ease, margin .42s ease;
        }

        .app-store-badge {
            flex: 1 1 0;
            min-width: 0;
            min-height: 66px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 8px 14px;
            border: 1px solid var(--brand-steel);
            border-radius: 11px;
            background: linear-gradient(150deg, #2b303a 0%, #16181d 100%);
            color: #fff;
            text-decoration: none;
            box-shadow: 0 8px 20px rgba(35, 50, 70, .22);
            transition: transform .16s ease, border-color .16s ease, background .16s ease;
        }

        a.app-store-badge:hover {
            color: #fff;
            border-color: var(--brand-gold-2);
            transform: translateY(-2px);
        }

        .app-store-badge i {
            flex: 0 0 auto;
            font-size: 2rem;
        }

        .app-store-badge .google-play-icon {
            color: #42d982;
        }

        .app-store-copy {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            line-height: 1.05;
            white-space: nowrap;
        }

        .app-store-copy small {
            font-size: .67rem;
            font-weight: 500;
        }

        .app-store-copy strong {
            font-size: 1.23rem;
            font-weight: 600;
        }

        .app-store-badge.is-disabled {
            border-color: #c4ccd6;
            background: linear-gradient(150deg, #eef2f7 0%, #dfe6ee 100%);
            color: #8b95a3;
            box-shadow: none;
            cursor: default;
        }

        .app-store-badge.is-disabled i {
            color: #9aa4b1;
        }

        .app-store-badge.is-disabled .app-store-copy small {
            color: #9aa4b1;
        }

        .app-store-badge .development-label {
            display: block;
            margin: 5px 0 0;
            color: #d1d5db;
            font-size: .62rem;
            line-height: 1;
            text-transform: uppercase;
        }

        .app-store-badge.is-disabled .development-label {
            color: #9aa4b1;
        }

        .btn-request-access {
            width: min(92vw, 380px);
            margin-top: 8px;
            min-height: 42px;
            border-radius: 12px;
            font-weight: 700;
            border: 1px solid var(--panel-border);
            color: #26354a;
            background: linear-gradient(150deg, #ffffff 0%, #eef2f7 60%, #dfe6ee 100%);
            box-shadow: 0 6px 16px rgba(35, 50, 70, .12), inset 0 1px 0 #fff;
        }

        .btn-request-access:hover {
            color: var(--text-main);
            background: linear-gradient(150deg, #ffffff 0%, #f4f7fb 60%, #e8edf4 100%);
            border-color: #9fb0c4;
            transform: translateY(-1px);
        }

        .login-type-switch {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 14px;
        }

        .login-type-btn {
            border-radius: 12px;
            border: 1px solid var(--panel-border);
            background: #fff;
            font-weight: 700;
            color: #26354a;
            padding: .5rem .7rem;
            transition: background .16s ease, border-color .16s ease, color .16s ease;
        }

        .login-type-btn.active {
            background: linear-gradient(135deg, #465368, #303c4e 65%, #243143);
            border-color: var(--brand-navy);
            color: #fff;
        }

        .login-card {
            background:
                linear-gradient(115deg, rgba(255, 255, 255, .65), transparent 38%, rgba(255, 255, 255, .4) 62%, transparent),
                repeating-linear-gradient(90deg, rgba(70, 85, 105, .018) 0 1px, transparent 1px 3px),
                linear-gradient(150deg, #ffffff 0%, #edf1f6 60%, #dde4ed 100%);
            border: 1px solid var(--panel-border);
            border-radius: 22px;
            box-shadow: 0 10px 28px rgba(35, 50, 70, .14), inset 0 1px 0 #fff;
            overflow: hidden;
            opacity: 0;
            transform: translateY(20px);
            max-height: 0;
            margin-top: 0;
            pointer-events: none;
            transition: opacity .52s cubic-bezier(.22,.61,.36,1), transform .52s cubic-bezier(.22,.61,.36,1), max-height .58s cubic-bezier(.22,.61,.36,1), margin-top .52s cubic-bezier(.22,.61,.36,1);
            transition-delay: .08s;
        }

        .login-card .card-body {
            padding: 26px 24px;
        }

        .login-title {
            text-align: center;
            font-size: 1.85rem;
            margin: 0 0 4px;
            font-weight: 800;
            letter-spacing: -.01em;
            background: linear-gradient(100deg, #26354a 0%, #536176 40%, #35465c 60%, #26354a 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .login-sub {
            text-align: center;
            margin: 0 0 22px;
            text-transform: uppercase;
            letter-spacing: .18em;
            font-size: .76rem;
            font-weight: 700;
            color: var(--text-soft);
        }

        .form-label {
            font-weight: 700;
            color: #26354a;
            margin-bottom: 7px;
        }

        .form-control {
            border-radius: 12px;
            border: 1px solid var(--panel-border);
            background: #fff;
            color: var(--text-main);
            min-height: 44px;
            font-size: 0.98rem;
        }

        .form-control:focus {
            border-color: var(--brand-gold-2);
            box-shadow: 0 0 0 0.25rem rgba(212, 167, 60, 0.22);
        }

        .btn-login {
            min-height: 46px;
            border: 0;
            border-radius: 12px;
            font-weight: 800;
            font-size: 1.05rem;
            color: #1b1505;
            background: linear-gradient(120deg, var(--brand-gold-1), var(--brand-gold-2) 60%, var(--brand-gold-3));
            box-shadow: 0 8px 20px rgba(212, 167, 60, 0.38), inset 0 1px 0 rgba(255, 255, 255, .55);
        }

        .btn-login:hover {
            color: #1b1505;
            transform: translateY(-1px);
            background: linear-gradient(120deg, #fdf0bb, #e0b54e 60%, #aa8026);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .login-links {
            margin-top: 14px;
            text-align: center;
            display: flex;
            justify-content: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .login-links button {
            border: 0;
            background: transparent;
            color: #805c12;
            font-size: 0.93rem;
            font-weight: 700;
            text-decoration: underline;
            padding: 0;
        }

        .login-links button:hover {
            color: #5e430b;
        }

        .footer-meta {
            text-align: center;
            margin-top: 18px;
            color: var(--text-soft);
            font-size: 0.95rem;
            opacity: 0;
            transform: translateY(8px);
            pointer-events: none;
            transition: opacity .34s cubic-bezier(.22,.61,.36,1), transform .34s cubic-bezier(.22,.61,.36,1);
        }

        .footer-meta a {
            color: #805c12;
            text-decoration: none;
            font-weight: 700;
        }

        .footer-meta a:hover {
            text-decoration: underline;
        }

        .modal-content {
            background:
                linear-gradient(115deg, rgba(255, 255, 255, .65), transparent 38%, rgba(255, 255, 255, .4) 62%, transparent),
                repeating-linear-gradient(90deg, rgba(70, 85, 105, .018) 0 1px, transparent 1px 3px),
                linear-gradient(150deg, #ffffff 0%, #edf1f6 60%, #dde4ed 100%);
            border: 1px solid var(--panel-border);
            border-radius: 22px;
            box-shadow: 0 18px 44px rgba(35, 50, 70, .28);
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #465368, #303c4e 65%, #243143);
            border-bottom: 0;
            padding: 1rem 1.25rem;
        }

        .modal-header .modal-title {
            color: #f5f7fa;
            font-weight: 800;
            letter-spacing: .01em;
        }

        .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        .modal-body {
            color: var(--text-main);
        }

        .modal-body .alert-info {
            background: linear-gradient(150deg, #eef2f7 0%, #e3e9f1 100%);
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            color: #26354a;
        }

        .modal-body .form-text {
            color: var(--text-soft);
        }

        .modal-footer {
            border-top: 1px solid rgba(185, 195, 207, .55);
            background: transparent;
        }

        .modal .btn-primary {
            border: 0;
            border-radius: 12px;
            font-weight: 800;
            color: #1b1505;
            background: linear-gradient(120deg, var(--brand-gold-1), var(--brand-gold-2) 60%, var(--brand-gold-3));
            box-shadow: 0 6px 16px rgba(212, 167, 60, 0.35), inset 0 1px 0 rgba(255, 255, 255, .55);
        }

        .modal .btn-primary:hover,
        .modal .btn-primary:focus,
        .modal .btn-primary:active {
            color: #1b1505;
            background: linear-gradient(120deg, #fdf0bb, #e0b54e 60%, #aa8026);
        }

        .modal .btn-primary:disabled {
            color: #4a3c15;
            background: linear-gradient(120deg, #efe3b6, #d3bc7d 60%, #b39a59);
            box-shadow: none;
        }

        .modal .btn-outline-secondary {
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            font-weight: 700;
            color: #26354a;
            background: linear-gradient(150deg, #ffffff 0%, #eef2f7 60%, #dfe6ee 100%);
        }

        .modal .btn-outline-secondary:hover,
        .modal .btn-outline-secondary:focus,
        .modal .btn-outline-secondary:active {
            color: var(--text-main);
            border-color: #9fb0c4;
            background: linear-gradient(150deg, #ffffff 0%, #f4f7fb 60%, #e8edf4 100%);
        }

        .modal .form-select {
            border-radius: 12px;
            border: 1px solid var(--panel-border);
            background-color: #fff;
            color: var(--text-main);
            min-height: 44px;
        }

        .modal .form-select:focus,
        .modal textarea.form-control:focus {
            border-color: var(--brand-gold-2);
            box-shadow: 0 0 0 0.25rem rgba(212, 167, 60, 0.22);
        }

        body.show-form .login-wrap {
            max-width: 500px;
        }

        body.show-form .brand-stage {
            transform: translateY(-2px);
        }

        body.show-form .brand,
        body.show-form .sublogo-wrap {
            opacity: 0;
            transform: translateY(-8px);
            pointer-events: none;
            height: 0;
            margin: 0;
            overflow: hidden;
        }

        body.show-form .intro-actions {
            opacity: 0;
            transform: translateY(-8px);
            pointer-events: none;
            height: 0;
            margin: 0;
            overflow: hidden;
        }

        body.show-form .app-store-links {
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
            transition-delay: .12s;
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
                max-width: min(86vw, 420px);
                max-height: 190px;
            }

            .sublogo-img {
                max-width: min(82vw, 300px);
                max-height: 118px;
            }

            .app-store-links {
                width: min(92vw, 380px);
                gap: 8px;
                margin-top: 28px;
            }

            .app-store-badge {
                min-height: 58px;
                gap: 6px;
                padding: 7px 5px;
            }

            .app-store-badge i {
                font-size: 1.65rem;
            }

            .app-store-copy small {
                font-size: .58rem;
            }

            .app-store-copy strong {
                font-size: 1.02rem;
            }

            .app-store-badge .development-label {
                font-size: .52rem;
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

        <?php if ($sublogoUrl): ?>
            <div class="sublogo-wrap">
                <img src="<?= h($sublogoUrl) ?>" alt="Podlogo přihlášení" class="sublogo-img">
            </div>
        <?php endif; ?>

        <div class="intro-actions">
            <button type="button" id="btnShowLogin" class="btn btn-login">Přihlášení</button>
            <button type="button" class="btn btn-request-access" data-bs-toggle="modal" data-bs-target="#coachAccessModal">Žádost o přístup trenéra</button>
        </div>

        <div class="app-store-links" aria-label="Mobilní aplikace">
            <?php if ($iosStoreUrl !== ''): ?>
                <a class="app-store-badge" href="<?= h($iosStoreUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Stáhnout TrainerApp z App Store">
            <?php else: ?>
                <span class="app-store-badge is-disabled" aria-disabled="true" aria-label="Aplikace pro iPhone je ve vývoji">
            <?php endif; ?>
                <i class="fab fa-apple" aria-hidden="true"></i>
                <span class="app-store-copy"><small>Download on the</small><strong>App Store</strong><?php if ($iosStoreUrl === ''): ?><span class="development-label">Ve vývoji</span><?php endif; ?></span>
            <?php if ($iosStoreUrl !== ''): ?></a><?php else: ?></span><?php endif; ?>

            <?php if ($androidStoreUrl !== ''): ?>
                <a class="app-store-badge" href="<?= h($androidStoreUrl) ?>" <?= $androidStoreUrl === $androidApkUrl ? 'download="' . h(basename($androidApkPath)) . '"' : 'target="_blank" rel="noopener noreferrer"' ?> aria-label="Stáhnout TrainerApp pro Android">
            <?php else: ?>
                <span class="app-store-badge is-disabled" aria-disabled="true" aria-label="Aplikace pro Android je ve vývoji">
            <?php endif; ?>
                <i class="fab fa-google-play google-play-icon" aria-hidden="true"></i>
                <span class="app-store-copy"><small>GET IT ON</small><strong>Google Play</strong><?php if ($androidStoreUrl === ''): ?><span class="development-label">Ve vývoji</span><?php endif; ?></span>
            <?php if ($androidStoreUrl !== ''): ?></a><?php else: ?></span><?php endif; ?>
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
                    <input type="hidden" name="action" value="login">
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

                <div class="login-links">
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
                    <form method="post" novalidate id="coachAccessForm">
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
                            <button type="submit" class="btn btn-primary" id="coachAccessSubmitBtn">
                                <span class="coach-access-submit-label">Odeslat žádost</span>
                                <span class="coach-access-submit-loading d-none">
                                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Odesílám...
                                </span>
                            </button>
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
                Vytvořil <strong>Tomáš Tomeška</strong>
                &nbsp;·&nbsp;
                <a href="mailto:info@reservio.online?subject=Zpr%C3%A1va%20z%20TrainerApp">Kontaktujte nás</a>
                &nbsp;·&nbsp;
                <a href="<?= h(BASE_URL) ?>/privacy_policy.php">Zásady ochrany soukromí</a>
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
                }, 420);
            });
        }

        const loginTypeInput = document.getElementById('loginTypeInput');
        const loginSubTitle = document.getElementById('loginSubTitle');
        const usernameLabel = document.getElementById('usernameLabel');
        const typeButtons = Array.from(document.querySelectorAll('.login-type-btn'));
        const resetTypeInput = document.getElementById('reset_account_type');
        const resetIdentityLabel = document.getElementById('reset_identity_label');

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

        function applyResetType(type) {
            if (!resetIdentityLabel) return;
            resetIdentityLabel.textContent = type === 'athlete' ? 'E-mail sportovce' : 'Uživatelské jméno nebo e-mail';
        }

        if (resetTypeInput) {
            resetTypeInput.addEventListener('change', function() {
                applyResetType(resetTypeInput.value === 'athlete' ? 'athlete' : 'coach');
            });
            applyResetType(resetTypeInput.value === 'athlete' ? 'athlete' : 'coach');
        }

        const coachAccessForm = document.getElementById('coachAccessForm');
        const coachAccessSubmitBtn = document.getElementById('coachAccessSubmitBtn');
        const coachAccessCancelBtn = document.getElementById('coachAccessCancelBtn');
        if (coachAccessForm && coachAccessSubmitBtn) {
            let coachAccessSubmitting = false;
            coachAccessForm.addEventListener('submit', function(event) {
                if (coachAccessSubmitting) {
                    event.preventDefault();
                    return;
                }
                coachAccessSubmitting = true;

                coachAccessSubmitBtn.disabled = true;
                coachAccessSubmitBtn.querySelector('.coach-access-submit-label')?.classList.add('d-none');
                coachAccessSubmitBtn.querySelector('.coach-access-submit-loading')?.classList.remove('d-none');
                if (coachAccessCancelBtn) {
                    coachAccessCancelBtn.disabled = true;
                }
            });
        }

        const modalToOpen = <?= json_encode($openModal) ?>;
        if (modalToOpen) {
            const el = document.getElementById(modalToOpen);
            if (el) {
                const modal = new bootstrap.Modal(el);
                modal.show();
            }
        }

        applyLoginType((loginTypeInput && loginTypeInput.value === 'athlete') ? 'athlete' : 'coach');
    </script>
</body>

</html>