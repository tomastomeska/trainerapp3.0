<?php
// ============================================================
// Admin autentizace – superadministrátoři
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

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
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => defined('SESSION_SECURE') ? SESSION_SECURE : false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isAdminLoggedIn(): bool {
    return !empty($_SESSION['superadmin_id']);
}

function requireAdminLogin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: ' . BASE_URL . '/login_admin.php');
        exit;
    }
}

function getCurrentAdmin(): ?array {
    if (!isAdminLoggedIn()) return null;
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, username, name, email FROM superadmins WHERE id = ?');
    $stmt->execute([$_SESSION['superadmin_id']]);
    return $stmt->fetch() ?: null;
}

// Sdílí CSRF a flash z auth.php – funkce jsou definovány jen jednou
if (!function_exists('csrfToken')) {
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
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

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
}

if (!function_exists('h')) {
    function h(?string $str): string {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('intParam')) {
    function intParam(array $source, string $key, int $default = 0): int {
        return isset($source[$key]) ? (int)$source[$key] : $default;
    }
}

if (!function_exists('formatDate')) {
    function formatDate(?string $dt): string {
        return $dt ? date('d.m.Y', strtotime($dt)) : '–';
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime(?string $dt): string {
        return $dt ? date('d.m.Y H:i', strtotime($dt)) : '–';
    }
}
