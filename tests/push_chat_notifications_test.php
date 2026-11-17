<?php

require_once __DIR__ . '/../includes/push_chat_notifications.php';

class ChatPushTestPdo extends PDO
{
    public array $message = [];
    public array $devices = [11, 12];
    public array $logs = [];
    public array $queue = [];
    public bool $enabled = true;
    public bool $failSubscriptions = false;
    public bool $transaction = false;
    public int $lastId = 0;

    public function __construct() {}
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ChatPushTestStatement($this, $query);
    }
}

class ChatPushTestStatement extends PDOStatement
{
    private ChatPushTestPdo $db;
    private string $sql;
    private int $position = 0;
    private int $rows = 0;

    public function __construct(ChatPushTestPdo $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(?array $params = null): bool
    {
        if (str_contains($this->sql, 'SELECT id FROM push_subscriptions') && $this->db->failSubscriptions) {
            throw new RuntimeException('Simulated database error.');
        }
        if (str_contains($this->sql, 'INSERT INTO push_notification_logs')) {
            $key = $params[0];
            if (!isset($this->db->logs[$key])) {
                $this->db->logs[$key] = ['id' => count($this->db->logs) + 1, 'params' => $params];
            }
            $this->db->lastId = $this->db->logs[$key]['id'];
        } elseif (str_contains($this->sql, 'INSERT INTO push_delivery_queue')) {
            $logId = $params[0];
            $this->rows = isset($this->db->queue[$logId]) ? 0 : 1;
            $this->db->queue[$logId] = $params;
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->db->message ?: false;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->sql, 'push_notification_settings')) {
            return $this->db->enabled ? 1 : 0;
        }
        if (str_contains($this->sql, 'push_subscriptions')) {
            return $this->db->devices[$this->position++] ?? false;
        }
        return false;
    }
    public function rowCount(): int { return $this->rows; }
}

function expectChatPush(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$db = new ChatPushTestPdo();
$db->message = ['coach_id' => 5, 'athlete_id' => 7, 'sender' => 'coach'];
pushNotifyCoachAthleteChatMessage($db, 101);
expectChatPush(count($db->queue) === 2, 'Coach message was not queued for both devices.');
$log = array_values($db->logs)[0]['params'];
expectChatPush($log[1] === null && $log[2] === 7 && $log[4] === 'coach_chat_message', 'Wrong coach-message recipient.');
expectChatPush($log[6] === '/athlete_chat_mobile.php?target=coach', 'Wrong athlete chat URL.');
expectChatPush(array_values($db->queue)[0][2] === 'Máš novou zprávu od trenéra.', 'Unexpected notification body.');
pushNotifyCoachAthleteChatMessage($db, 101);
expectChatPush(count($db->queue) === 2 && count($db->logs) === 2, 'Message was queued twice.');

$db = new ChatPushTestPdo();
$db->message = ['coach_id' => 5, 'athlete_id' => 7, 'sender' => 'athlete'];
pushNotifyCoachAthleteChatMessage($db, 102);
$log = array_values($db->logs)[0]['params'];
expectChatPush($log[1] === 5 && $log[2] === null && $log[4] === 'athlete_chat_message', 'Wrong athlete-message recipient.');
expectChatPush($log[6] === '/coach_chat_mobile.php?athlete_id=7', 'Wrong coach chat URL.');

$db = new ChatPushTestPdo();
$db->message = ['coach_id' => 5, 'athlete_id' => 7, 'sender' => 'coach'];
$db->enabled = false;
pushNotifyCoachAthleteChatMessage($db, 103);
expectChatPush($db->queue === [], 'Disabled category queued a notification.');
$db->enabled = true;
$db->failSubscriptions = true;
pushNotifyCoachAthleteChatMessage($db, 104);
expectChatPush(!$db->inTransaction() && $db->queue === [], 'Push failure was not safely rolled back.');
$db->failSubscriptions = false;
$db->message = [];
pushNotifyCoachAthleteChatMessage($db, 105);
expectChatPush($db->queue === [], 'Missing or unrelated message was queued.');

echo "Chat push routing, preferences, deduplication and failure handling passed.\n";
