<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function mobileJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mobileInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function mobileBearerToken(): string
{
    $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }

    if (!empty($_SERVER['HTTP_X_TOKEN'])) {
        return trim((string)$_SERVER['HTTP_X_TOKEN']);
    }
    if (!empty($_GET['token'])) {
        return trim((string)$_GET['token']);
    }
    if (!empty($_GET['access_token'])) {
        return trim((string)$_GET['access_token']);
    }

    $input = mobileInput();
    if (!empty($input['token'])) {
        return trim((string)$input['token']);
    }
    if (!empty($input['access_token'])) {
        return trim((string)$input['access_token']);
    }

    return '';
}

function mobileRequireCoach(): array
{
    $token = mobileBearerToken();
    if ($token === '') {
        mobileJson(['success' => false, 'error' => 'Chybí přístupový token.'], 401);
    }

    $pdo = getDB();
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT t.id AS token_id, t.user_id, t.expires_at,
                c.id, c.username, c.name, c.email, c.is_active
         FROM mobile_api_tokens t
         JOIN coaches c ON c.id = t.user_id
         WHERE t.token_hash = ?
           AND t.account_type = \'coach\'
           AND t.revoked_at IS NULL
           AND t.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $coach = $stmt->fetch();

    if (!$coach || !(int)$coach['is_active']) {
        mobileJson(['success' => false, 'error' => 'Neplatný nebo prošlý přístupový token.'], 401);
    }

    $pdo->prepare('UPDATE mobile_api_tokens SET last_used_at = NOW() WHERE id = ?')
        ->execute([(int)$coach['token_id']]);

    return [
        'token_id' => (int)$coach['token_id'],
        'id' => (int)$coach['id'],
        'username' => (string)$coach['username'],
        'name' => (string)$coach['name'],
        'email' => (string)$coach['email'],
    ];
}

function mobileIssueToken(PDO $pdo, int $userId, string $accountType = 'coach', int $days = 30): string
{
    if ($accountType !== 'coach' && $accountType !== 'athlete') {
        $accountType = 'coach';
    }
    $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $hash = hash('sha256', $token);
    $expires = (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');

    $pdo->prepare(
        'INSERT INTO mobile_api_tokens
            (account_type, user_id, token_hash, expires_at, created_at, last_used_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())'
    )->execute([$accountType, $userId, $hash, $expires]);

    return $token;
}

function mobilePublicPhoto(?string $filename, string $subDir): string
{
    $filename = trim((string)$filename);
    if ($filename === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $filename)) {
        return $filename;
    }
    $path = function_exists('photoUrl') ? photoUrl($filename, $subDir) : ('/uploads/' . $subDir . '/' . rawurlencode($filename));
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return 'https://www.reservio.online' . (str_starts_with($path, '/') ? $path : '/' . $path);
}

function mobileRequireAthlete(): array
{
    $token = mobileBearerToken();
    if ($token === '') {
        mobileJson(['success' => false, 'error' => 'Chybí přístupový token.'], 401);
    }

    $pdo = getDB();
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT t.id AS token_id, t.user_id, t.expires_at,
                a.id, a.coach_id, a.email, a.first_name, a.last_name, a.login_enabled
         FROM mobile_api_tokens t
         JOIN athletes a ON a.id = t.user_id
         WHERE t.token_hash = ?
           AND t.account_type = \'athlete\'
           AND t.revoked_at IS NULL
           AND t.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $athlete = $stmt->fetch();

    if (!$athlete || !(int)$athlete['login_enabled']) {
        mobileJson(['success' => false, 'error' => 'Neplatný nebo prošlý přístupový token.'], 401);
    }

    $pdo->prepare('UPDATE mobile_api_tokens SET last_used_at = NOW() WHERE id = ?')
        ->execute([(int)$athlete['token_id']]);

    $name = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);

    return [
        'token_id' => (int)$athlete['token_id'],
        'id' => (int)$athlete['id'],
        'coach_id' => (int)$athlete['coach_id'],
        'email' => (string)$athlete['email'],
        'name' => $name !== '' ? $name : 'Sportovec',
    ];
}
