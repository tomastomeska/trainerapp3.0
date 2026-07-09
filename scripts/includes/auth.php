<?php
// ============================================================
// Autentizace a sessions
// ============================================================

if (ob_get_level() === 0) {
    ob_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if ((defined('SESSION_SECURE') && SESSION_SECURE) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $cookiePath = (defined('BASE_URL') && BASE_URL !== '') ? BASE_URL : '/';
    if (!headers_sent()) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $cookiePath,
            'secure'   => defined('SESSION_SECURE') ? SESSION_SECURE : false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    @session_start();
    unset($cookiePath);
}

function isLoggedIn(): bool {
    return !empty($_SESSION['coach_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $adminPrefix = '';
        if ($script !== '' && preg_match('#^(.*)/admin/[^/]+\\.php$#', $script, $m)) {
            $adminPrefix = rtrim(($m[1] ?? ''), '/');
        }

        if ($adminPrefix !== '') {
            header('Location: ' . $adminPrefix . '/login_admin.php');
        } elseif (strpos($script, '/admin/') === 0) {
            header('Location: /login_admin.php');
        } else {
            header('Location: ' . BASE_URL . '/login.php');
        }
        exit;
    }

    if (!empty($_SESSION['coach_force_password_change'])) {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script !== 'dashboard.php' && $script !== 'logout.php') {
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
    }
}

function getCurrentCoachId(): ?int {
    return $_SESSION['coach_id'] ?? null;
}

function getCurrentCoach(): ?array {
    if (!isLoggedIn()) return null;
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, username, name, email FROM coaches WHERE id = ?');
    $stmt->execute([$_SESSION['coach_id']]);
    return $stmt->fetch() ?: null;
}

// CSRF ochrana
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

// Flash zprávy
function flash(string $type, string $message): void {
    $_SESSION['flash'] = compact('type', 'message');
}

function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
