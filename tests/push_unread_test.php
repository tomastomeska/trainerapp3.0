<?php

require_once __DIR__ . '/../includes/push_unread.php';

class UnreadTestPdo extends PDO
{
    public array $tables = [];
    public array $calls = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new UnreadTestStatement($this, $query);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->calls[] = [$query, []];
        return new UnreadTestStatement($this, $query);
    }
}

class UnreadTestStatement extends PDOStatement
{
    private UnreadTestPdo $db;
    private string $sql;
    private mixed $result = false;
    public function __construct(UnreadTestPdo $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(?array $params = null): bool
    {
        $this->db->calls[] = [$this->sql, $params];
        preg_match('/FROM `([^`]+)`/', $this->sql, $matches);
        $this->result = $this->db->tables[$matches[1]];
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return array_keys($this->db->tables); }
    public function fetchColumn(int $column = 0): mixed { return $this->result; }
}

function expectUnread(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$db = new UnreadTestPdo();
$db->tables = ['coach_athlete_chat_messages' => '3', 'admin_athlete_chat_messages' => '2'];
expectUnread(pushUnreadChatCount($db, 'athlete', 42) === 5, 'Athlete unread count is wrong.');
expectUnread(str_contains($db->calls[1][0], "athlete_id = ? AND sender = 'coach' AND athlete_read_at IS NULL"), 'Athlete chat scope is wrong.');
expectUnread($db->calls[1][1] === [42], 'Athlete owner was not bound.');
expectUnread(str_contains($db->calls[2][0], "athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL"), 'Athlete admin scope is wrong.');

$db = new UnreadTestPdo();
$db->tables = ['coach_athlete_chat_messages' => '4'];
expectUnread(pushUnreadChatCount($db, 'coach', 9) === 4, 'Coach count or missing optional table handling is wrong.');
expectUnread(str_contains($db->calls[1][0], "coach_id = ? AND sender = 'athlete' AND coach_read_at IS NULL"), 'Coach chat scope is wrong.');
expectUnread($db->calls[1][1] === [9], 'Coach owner was not bound.');

foreach ([['admin', 1], ['athlete', 0]] as [$role, $id]) {
    try {
        pushUnreadChatCount($db, $role, $id);
        expectUnread(false, 'Invalid owner was accepted.');
    } catch (InvalidArgumentException $e) {
    }
}
$db->tables['coach_athlete_chat_messages'] = false;
try {
    pushUnreadChatCount($db, 'coach', 9);
    expectUnread(false, 'A failed count was treated as zero.');
} catch (RuntimeException $e) {
}

echo "Unread chat badge counting and owner scoping passed.\n";
