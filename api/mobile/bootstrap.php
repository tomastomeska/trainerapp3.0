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
    $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
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

function mobileIssueToken(PDO $pdo, int $coachId, int $days = 30): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $hash = hash('sha256', $token);
    $expires = (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');

    // Jednoduché pravidlo: při novém přihlášení zrušíme starší tokeny stejného trenéra.
    $pdo->prepare(
        "UPDATE mobile_api_tokens
         SET revoked_at = NOW()
         WHERE account_type = 'coach' AND user_id = ? AND revoked_at IS NULL"
    )->execute([$coachId]);

    $pdo->prepare(
        'INSERT INTO mobile_api_tokens
            (account_type, user_id, token_hash, expires_at, created_at, last_used_at)
         VALUES (\'coach\', ?, ?, ?, NOW(), NOW())'
    )->execute([$coachId, $hash, $expires]);

    return $token;
}
