<?php

const REMEMBER_COOKIE = 'trainerapp_remember';
const REMEMBER_SECONDS = 30 * 24 * 60 * 60;

function trainerAppRequestUsesHttps(): bool
{
    return (defined('SESSION_SECURE') && SESSION_SECURE)
        || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

function rememberCookieOptions(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => defined('BASE_URL') && BASE_URL !== '' ? BASE_URL : '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function rememberCookieParts(mixed $value): ?array
{
    if (!is_string($value) || preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/D', $value, $match) !== 1) {
        return null;
    }
    return [$match[1], $match[2]];
}

function rememberClearCookie(): void
{
    if (!setcookie(REMEMBER_COOKIE, '', rememberCookieOptions(time() - 3600))) {
        throw new RuntimeException('Unable to clear remembered-device cookie.');
    }
    unset($_COOKIE[REMEMBER_COOKIE]);
}

function rememberOwnerSql(string $role): string
{
    if ($role === 'coach') return 'coach_id = ? AND athlete_id IS NULL';
    if ($role === 'athlete') return 'athlete_id = ? AND coach_id IS NULL';
    throw new InvalidArgumentException('Invalid remembered-device role.');
}

function rememberDeviceName(string $agent): string
{
    if (stripos($agent, 'iPhone') !== false) return 'iPhone';
    if (stripos($agent, 'iPad') !== false) return 'iPad';
    if (stripos($agent, 'Android') !== false) return 'Android';
    if (stripos($agent, 'Windows') !== false) return 'Windows';
    if (stripos($agent, 'Macintosh') !== false) return 'Mac';
    return 'Jiné zařízení';
}

function rememberShouldRenew(): bool
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return !preg_match('#/(api|admin/api)/#', $script);
}

function rememberLoad(PDO $pdo, string $selector): ?array
{
    $stmt = $pdo->prepare(
        'SELECT d.*, c.password AS coach_password, c.is_active AS coach_active,
                c.name AS coach_name, c.username AS coach_username, c.force_password_change AS coach_force,
                a.password AS athlete_password, a.first_name, a.last_name, a.coach_id AS athlete_coach_id,
                a.login_enabled, a.force_password_change AS athlete_force, ac.id AS athlete_coach_exists
         FROM remembered_devices d
         LEFT JOIN coaches c ON c.id = d.coach_id
         LEFT JOIN athletes a ON a.id = d.athlete_id
         LEFT JOIN coaches ac ON ac.id = a.coach_id
         WHERE d.selector = ? AND d.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([$selector]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function rememberAccountIsValid(array $row): bool
{
    $coach = $row['coach_id'] !== null && $row['athlete_id'] === null;
    $athlete = $row['athlete_id'] !== null && $row['coach_id'] === null;
    if ($coach && (int)$row['coach_active'] === 1 && is_string($row['coach_password'])) {
        $password = $row['coach_password'];
    } elseif ($athlete && (int)$row['login_enabled'] === 1 && $row['athlete_coach_exists'] !== null
        && is_string($row['athlete_password']) && $row['athlete_password'] !== '') {
        $password = $row['athlete_password'];
    } else {
        return false;
    }
    return hash_equals((string)$row['credential_hash'], hash('sha256', $password));
}

function rememberIssue(PDO $pdo, string $role, int $ownerId, string $passwordHash): void
{
    if (!trainerAppRequestUsesHttps() || headers_sent()) {
        throw new RuntimeException('Persistent login requires secure HTTPS cookies.');
    }
    $ownerSql = rememberOwnerSql($role);
    if ($ownerId < 1 || $passwordHash === '') throw new InvalidArgumentException('Invalid remembered-device owner.');
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $old = rememberCookieParts($_COOKIE[REMEMBER_COOKIE] ?? null);
    $pdo->beginTransaction();
    try {
        if ($old !== null) {
            $delete = $pdo->prepare('DELETE FROM remembered_devices WHERE selector = ? AND validator_hash = ?');
            $delete->execute([$old[0], hash('sha256', $old[1])]);
        }
        $deleteExpired = $pdo->prepare('DELETE FROM remembered_devices WHERE ' . $ownerSql . ' AND expires_at <= NOW()');
        $deleteExpired->execute([$ownerId]);
        $insert = $pdo->prepare(
            'INSERT INTO remembered_devices (selector, validator_hash, credential_hash, coach_id, athlete_id, device_name, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))'
        );
        $insert->execute([
            $selector, hash('sha256', $validator), hash('sha256', $passwordHash),
            $role === 'coach' ? $ownerId : null, $role === 'athlete' ? $ownerId : null,
            rememberDeviceName((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ]);
        if (!setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, rememberCookieOptions(time() + REMEMBER_SECONDS))) {
            throw new RuntimeException('Unable to set remembered-device cookie.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $_COOKIE[REMEMBER_COOKIE] = $selector . ':' . $validator;
    $_SESSION['remember_selector'] = $selector;
}

function rememberRestoreSession(PDO $pdo): void
{
    $parts = rememberCookieParts($_COOKIE[REMEMBER_COOKIE] ?? null);
    if ($parts === null) return;
    $row = rememberLoad($pdo, $parts[0]);
    if (!$row || !hash_equals((string)$row['validator_hash'], hash('sha256', $parts[1])) || !rememberAccountIsValid($row)) {
        rememberClearCookie();
        return;
    }
    if (!session_regenerate_id(true)) throw new RuntimeException('Unable to renew session ID.');
    unset($_SESSION['coach_id'], $_SESSION['coach_name'], $_SESSION['coach_force_password_change'],
        $_SESSION['athlete_id'], $_SESSION['athlete_name'], $_SESSION['athlete_coach_id'], $_SESSION['athlete_force_password_change'], $_SESSION['csrf_token']);
    if ($row['coach_id'] !== null) {
        $_SESSION['coach_id'] = (int)$row['coach_id'];
        $_SESSION['coach_name'] = (string)($row['coach_name'] ?: $row['coach_username']);
        $_SESSION['coach_force_password_change'] = (int)$row['coach_force'];
    } else {
        $_SESSION['athlete_id'] = (int)$row['athlete_id'];
        $_SESSION['athlete_name'] = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
        $_SESSION['athlete_coach_id'] = (int)$row['athlete_coach_id'];
        $_SESSION['athlete_force_password_change'] = (int)$row['athlete_force'];
    }
    $_SESSION['remember_selector'] = $parts[0];
}

function rememberValidateSession(PDO $pdo): void
{
    $selector = $_SESSION['remember_selector'] ?? null;
    if (!is_string($selector) || preg_match('/^[a-f0-9]{32}$/D', $selector) !== 1) return;
    $row = rememberLoad($pdo, $selector);
    $matches = $row && rememberAccountIsValid($row) && (
        ($row['coach_id'] !== null && (int)$row['coach_id'] === (int)($_SESSION['coach_id'] ?? 0) && empty($_SESSION['athlete_id']))
        || ($row['athlete_id'] !== null && (int)$row['athlete_id'] === (int)($_SESSION['athlete_id'] ?? 0) && empty($_SESSION['coach_id']))
    );
    if (!$matches) {
        unset($_SESSION['coach_id'], $_SESSION['coach_name'], $_SESSION['coach_force_password_change'],
            $_SESSION['athlete_id'], $_SESSION['athlete_name'], $_SESSION['athlete_coach_id'],
            $_SESSION['athlete_force_password_change'], $_SESSION['remember_selector']);
        rememberClearCookie();
        if (!session_regenerate_id(true)) throw new RuntimeException('Unable to renew session ID.');
        return;
    }
    if ($row['coach_id'] !== null) {
        $_SESSION['coach_force_password_change'] = (int)$row['coach_force'];
    } else {
        $_SESSION['athlete_force_password_change'] = (int)$row['athlete_force'];
    }
    $parts = rememberCookieParts($_COOKIE[REMEMBER_COOKIE] ?? null);
    if (rememberShouldRenew() && $parts !== null && $parts[0] === $selector
        && hash_equals((string)$row['validator_hash'], hash('sha256', $parts[1]))) {
        $touch = $pdo->prepare(
            'UPDATE remembered_devices SET last_seen_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
             WHERE selector = ? AND last_seen_at < DATE_SUB(NOW(), INTERVAL 1 HOUR) AND expires_at > NOW()'
        );
        $touch->execute([$selector]);
        if ($touch->rowCount() > 0
            && !setcookie(REMEMBER_COOKIE, implode(':', $parts), rememberCookieOptions(time() + REMEMBER_SECONDS))) {
            throw new RuntimeException('Unable to renew remembered-device cookie.');
        }
    }
}

function rememberPasswordChanged(PDO $pdo, string $role, int $ownerId, string $newPasswordHash): bool
{
    try {
        if (isset($_SESSION['remember_selector'])) {
            rememberIssue($pdo, $role, $ownerId, $newPasswordHash);
        }
        return true;
    } catch (Throwable $e) {
        error_log('Persistent login password update failed: ' . get_class($e));
        unset($_SESSION['remember_selector']);
        try {
            rememberClearCookie();
        } catch (Throwable $cookieError) {
            error_log('Persistent login cookie clearing failed: ' . get_class($cookieError));
        }
        return false;
    }
}

function rememberLoginFailure(): void
{
    unset($_SESSION['remember_selector']);
    try {
        rememberClearCookie();
    } catch (Throwable $e) {
        error_log('Persistent login cookie clearing failed: ' . get_class($e));
    }
}

function rememberRevokeCurrent(PDO $pdo): void
{
    $selector = $_SESSION['remember_selector'] ?? null;
    if (is_string($selector) && preg_match('/^[a-f0-9]{32}$/D', $selector) === 1) {
        $stmt = $pdo->prepare('DELETE FROM remembered_devices WHERE selector = ?');
        $stmt->execute([$selector]);
    }
    $parts = rememberCookieParts($_COOKIE[REMEMBER_COOKIE] ?? null);
    if ($parts !== null) {
        $stmt = $pdo->prepare('DELETE FROM remembered_devices WHERE selector = ? AND validator_hash = ?');
        $stmt->execute([$parts[0], hash('sha256', $parts[1])]);
    }
    rememberClearCookie();
    unset($_SESSION['remember_selector']);
}

function renderRememberedDevices(PDO $pdo, string $role, int $ownerId): void
{
    if (!empty($_SESSION['superadmin_id']) || !empty($_SESSION['impersonating_admin_id'])) return;
    try {
        $stmt = $pdo->prepare(
            'SELECT id, selector, device_name, created_at, last_seen_at, expires_at
             FROM remembered_devices WHERE ' . rememberOwnerSql($role) . ' AND expires_at > NOW()
             ORDER BY last_seen_at DESC, id DESC'
        );
        $stmt->execute([$ownerId]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Remembered device list failed: ' . get_class($e));
        echo '<div class="alert alert-danger">Přihlášená zařízení se nepodařilo načíst.</div>';
        return;
    }
    ?>
    <section class="card border-0 shadow-sm mb-4" id="remembered-devices">
        <div class="card-header bg-dark text-white"><i class="fas fa-shield-halved me-2"></i>Přihlášená zařízení</div>
        <div class="card-body">
            <p class="small text-muted">Zapamatované přihlášení platí 30 dní od posledního použití. Odhlášení zařízení nezruší jeho push oznámení.</p>
            <?php if (!$devices): ?>
                <p class="text-muted mb-0">Žádná zapamatovaná zařízení.</p>
            <?php else: ?>
                <ul class="list-group">
                    <?php foreach ($devices as $device): ?>
                        <?php $current = hash_equals((string)$device['selector'], (string)($_SESSION['remember_selector'] ?? '')); ?>
                        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <strong><?= h((string)$device['device_name']) ?></strong><?= $current ? ' (toto zařízení)' : '' ?>
                                <div class="small text-muted">Přidáno: <?= h((string)$device['created_at']) ?> · Naposledy použito: <?= h((string)$device['last_seen_at']) ?></div>
                            </div>
                            <form method="post" action="<?= BASE_URL ?>/remembered_device_remove.php">
                                <?= csrfField() ?>
                                <input type="hidden" name="device_id" value="<?= (int)$device['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit"
                                    onclick="return confirm('Odhlásit toto zařízení?');">Odhlásit</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
    <?php
}
