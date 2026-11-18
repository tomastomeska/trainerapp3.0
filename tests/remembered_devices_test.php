<?php
define('BASE_URL', '');
define('SESSION_SECURE', false);
$_SERVER['HTTPS'] = 'on';
require_once __DIR__ . '/../includes/remembered_devices.php';

class RememberTestPdo extends PDO
{
    public array $rows = [];
    public bool $transaction = false;
    public bool $failInsert = false;
    public array $accounts = [
        'coach:5' => ['password' => 'coach-password-hash', 'name' => 'Trenér', 'username' => 'coach'],
        'athlete:7' => ['password' => 'athlete-password-hash', 'first_name' => 'Jana', 'last_name' => 'Nová'],
    ];
    public function __construct() {}
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RememberTestStatement($this, $query);
    }
}

class RememberTestStatement extends PDOStatement
{
    private RememberTestPdo $db;
    private string $sql;
    private mixed $result = false;
    private int $affected = 0;
    public function __construct(RememberTestPdo $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(?array $params = null): bool
    {
        $params = $params ?? [];
        $this->affected = 0;
        if (str_starts_with($this->sql, 'SELECT d.*')) {
            $this->result = $this->db->rows[$params[0]] ?? false;
        } elseif (str_starts_with($this->sql, 'INSERT INTO remembered_devices')) {
            if ($this->db->failInsert) throw new RuntimeException('Simulated storage error.');
            [$selector, $validator, $credential, $coach, $athlete] = $params;
            $account = $this->db->accounts[$coach !== null ? 'coach:' . $coach : 'athlete:' . $athlete];
            $this->db->rows[$selector] = [
                'selector' => $selector, 'validator_hash' => $validator, 'credential_hash' => $credential,
                'coach_id' => $coach, 'athlete_id' => $athlete,
                'coach_password' => $coach !== null ? $account['password'] : null,
                'coach_active' => $coach !== null ? 1 : null,
                'coach_name' => $account['name'] ?? null, 'coach_username' => $account['username'] ?? null,
                'coach_force' => 0, 'athlete_password' => $athlete !== null ? $account['password'] : null,
                'first_name' => $account['first_name'] ?? null, 'last_name' => $account['last_name'] ?? null,
                'athlete_coach_id' => $athlete !== null ? 5 : null,
                'athlete_coach_exists' => $athlete !== null ? 5 : null,
                'login_enabled' => $athlete !== null ? 1 : null, 'athlete_force' => 0,
            ];
            $this->affected = 1;
        } elseif (str_starts_with($this->sql, 'UPDATE remembered_devices SET last_seen_at')) {
            $this->affected = 0;
        } elseif (str_starts_with($this->sql, 'DELETE FROM remembered_devices WHERE selector')) {
            $row = $this->db->rows[$params[0]] ?? null;
            if ($row && (count($params) === 1 || hash_equals($row['validator_hash'], $params[1]))) {
                unset($this->db->rows[$params[0]]);
                $this->affected = 1;
            }
        } elseif (!str_starts_with($this->sql, 'DELETE FROM remembered_devices WHERE ')) {
            throw new RuntimeException('Unexpected SQL in remembered device test.');
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->result; }
    public function rowCount(): int { return $this->affected; }
}

function expectRemember(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

session_start();
$db = new RememberTestPdo();
$_SESSION = ['coach_id' => 5];
$_SERVER['HTTP_USER_AGENT'] = 'iPhone';
rememberIssue($db, 'coach', 5, 'coach-password-hash');
$cookie = $_COOKIE[REMEMBER_COOKIE];
$parts = rememberCookieParts($cookie);
expectRemember($parts !== null && $db->rows[$parts[0]]['validator_hash'] !== $parts[1], 'Only a validator hash should be stored.');
$_SESSION = [];
rememberRestoreSession($db);
expectRemember($_SESSION['coach_id'] === 5 && empty($_SESSION['athlete_id']), 'The coach should be restored without an athlete identity.');
rememberValidateSession($db);
$db->rows[$parts[0]]['coach_password'] = 'changed-password-hash';
rememberValidateSession($db);
expectRemember(empty($_SESSION['coach_id']) && !isset($_COOKIE[REMEMBER_COOKIE]), 'Changing the password must invalidate old devices.');

$_SESSION = ['athlete_id' => 7];
rememberIssue($db, 'athlete', 7, 'athlete-password-hash');
$athleteCookie = $_COOKIE[REMEMBER_COOKIE];
$athleteParts = rememberCookieParts($athleteCookie);
$_SESSION = [];
rememberRestoreSession($db);
expectRemember($_SESSION['athlete_id'] === 7 && $_SESSION['athlete_coach_id'] === 5 && empty($_SESSION['coach_id']), 'Athlete restoration has incorrect ownership.');
$db->accounts['athlete:7']['password'] = 'new-athlete-password-hash';
expectRemember(rememberPasswordChanged($db, 'athlete', 7, 'new-athlete-password-hash'), 'Current device should survive a successful password change.');
expectRemember(!isset($db->rows[$athleteParts[0]]), 'Password change must revoke the old current-device token.');
$athleteParts = rememberCookieParts($_COOKIE[REMEMBER_COOKIE]);
rememberValidateSession($db);
expectRemember($_SESSION['athlete_id'] === 7, 'Password change should retain the current session.');
$db->rows[$athleteParts[0]]['login_enabled'] = 0;
rememberValidateSession($db);
expectRemember(empty($_SESSION['athlete_id']), 'A disabled athlete must not remain signed in.');

$_SESSION = ['athlete_id' => 7];
$db->failInsert = true;
try {
    rememberIssue($db, 'athlete', 7, 'athlete-password-hash');
    throw new RuntimeException('Storage failure was not reported.');
} catch (RuntimeException $e) {
    expectRemember($e->getMessage() === 'Simulated storage error.' && !$db->inTransaction(), 'A storage failure must roll back.');
}
expectRemember($_SESSION['athlete_id'] === 7, 'A remember failure must not undo the normal login.');
expectRemember(rememberCookieParts('invalid') === null, 'Malformed cookies must be rejected.');
expectRemember(rememberOwnerSql('coach') !== rememberOwnerSql('athlete'), 'Device owner roles must remain separate.');
expectRemember(trainerAppRequestUsesHttps(), 'An HTTPS request must allow secure remembered-login cookies even if the override is false.');
unset($_SERVER['HTTPS']);
try {
    rememberIssue($db, 'coach', 5, 'coach-password-hash');
    throw new RuntimeException('HTTP must not issue remembered-login cookies.');
} catch (RuntimeException $e) {
    expectRemember($e->getMessage() === 'Persistent login requires secure HTTPS cookies.', 'HTTP must be rejected with a clear reason.');
}
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
expectRemember(trainerAppRequestUsesHttps(), 'HTTPS forwarded by the hosting proxy must be detected.');
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
$_SERVER['HTTPS'] = 'on';
$db->failInsert = false;
$_SESSION = ['coach_id' => 5];
rememberIssue($db, 'coach', 5, 'coach-password-hash');
$revokedCookie = $_COOKIE[REMEMBER_COOKIE];
$revokedParts = rememberCookieParts($revokedCookie);
rememberRevokeCurrent($db);
expectRemember(!isset($db->rows[$revokedParts[0]]) && !isset($_COOKIE[REMEMBER_COOKIE]), 'Logout must revoke and clear the current device.');
$_SESSION = [];
$_COOKIE[REMEMBER_COOKIE] = $revokedCookie;
rememberRestoreSession($db);
expectRemember(empty($_SESSION['coach_id']), 'A revoked cookie must not restore a session.');

$_SESSION = ['coach_id' => 5];
rememberIssue($db, 'coach', 5, 'coach-password-hash');
$parts = rememberCookieParts($_COOKIE[REMEMBER_COOKIE]);
$_SESSION['coach_id'] = 6;
rememberValidateSession($db);
expectRemember(empty($_SESSION['coach_id']), 'A device cannot authenticate a different owner.');
$_SESSION = [];
$_COOKIE[REMEMBER_COOKIE] = $parts[0] . ':' . str_repeat('0', 64);
rememberRestoreSession($db);
expectRemember(empty($_SESSION['coach_id']), 'An incorrect validator must not restore a session.');
$_SERVER['SCRIPT_NAME'] = '/api/athlete_coach_chat.php';
expectRemember(!rememberShouldRenew(), 'Background API polling must not renew inactivity expiry.');
$_SERVER['SCRIPT_NAME'] = '/athlete_dashboard.php';
expectRemember(rememberShouldRenew(), 'Normal page use must renew inactivity expiry.');
session_destroy();
echo "Remembered device authentication and security tests passed.\n";
