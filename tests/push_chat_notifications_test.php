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
    public array $disabledAthletes = [];
    public array $devicesByAthlete = [];
    public array $failedAthletes = [];
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
    private array $params = [];

    public function __construct(ChatPushTestPdo $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_contains($this->sql, 'SELECT id FROM push_subscriptions')
            && ($this->db->failSubscriptions || in_array($this->params[0], $this->db->failedAthletes, true))) {
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
            return $this->db->enabled && !in_array($this->params[0], $this->db->disabledAthletes, true) ? 1 : 0;
        }
        if (str_contains($this->sql, 'push_subscriptions')) {
            $devices = $this->db->devicesByAthlete[$this->params[0]] ?? $this->db->devices;
            return $devices[$this->position++] ?? false;
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
$db->message = ['coach_id' => 5, 'athlete_id' => 7, 'sender' => 'athlete', 'first_name' => 'Jana', 'last_name' => 'Nová'];
pushNotifyCoachAthleteChatMessage($db, 102);
$log = array_values($db->logs)[0]['params'];
expectChatPush($log[1] === 5 && $log[2] === null && $log[4] === 'athlete_chat_message', 'Wrong athlete-message recipient.');
expectChatPush($log[6] === '/coach_chat_mobile.php?athlete_id=7', 'Wrong coach chat URL.');
expectChatPush(array_values($db->queue)[0][2] === 'Nová zpráva od sportovce: Jana Nová.', 'Coach push must include the athlete name.');

$db = new ChatPushTestPdo();
$db->message = ['coach_id' => 5, 'athlete_id' => 7, 'sender' => 'athlete', 'first_name' => "Jana\n" . str_repeat('ž', 300), 'last_name' => 'Nová'];
pushNotifyCoachAthleteChatMessage($db, 106);
$body = array_values($db->queue)[0][2];
expectChatPush(str_contains($body, 'Jana ') && !str_contains($body, "\n") && mb_strlen($body, 'UTF-8') <= 240, 'Athlete name must be sanitized and fit the payload.');
$db = new ChatPushTestPdo();
$db->message = ['coach_id' => 5, 'athlete_id' => 7, 'sender' => 'athlete', 'first_name' => '', 'last_name' => ''];
pushNotifyCoachAthleteChatMessage($db, 107);
expectChatPush(array_values($db->queue)[0][2] === 'Máte novou zprávu od sportovce.', 'A missing name must not prevent message delivery.');

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

$db = new ChatPushTestPdo();
$db->devicesByAthlete = [7 => [11, 12], 8 => [13], 9 => [], 10 => [14]];
$db->disabledAthletes = [8];
$db->failedAthletes = [10];
pushNotifyAthleteBroadcast($db, 'admin', 201, [10, 7, 8, 9, 7]);
expectChatPush(count($db->queue) === 2, 'Broadcast must continue after a recipient failure and respect preferences and devices.');
$log = array_values($db->logs)[0]['params'];
expectChatPush($log[2] === 7 && $log[4] === 'admin_athlete_broadcast', 'Wrong broadcast recipient or type.');
expectChatPush($log[6] === '/athlete_zpravy.php?tab=inbox', 'Broadcast must open the athlete inbox.');
expectChatPush(array_values($db->queue)[0][2] === 'Máš novou hromadnou zprávu od administrátora.', 'Wrong administrator broadcast body.');
pushNotifyAthleteBroadcast($db, 'admin', 201, [7]);
expectChatPush(count($db->queue) === 2, 'Broadcast retry queued duplicate notifications.');
pushNotifyAthleteBroadcast($db, 'coach', 201, [7]);
expectChatPush(count($db->queue) === 4, 'Coach and administrator broadcast IDs must not collide.');
expectChatPush(array_values($db->queue)[2][2] === 'Máš novou hromadnou zprávu od trenéra.', 'Wrong coach broadcast body.');
$db->beginTransaction();
pushNotifyAthleteBroadcast($db, 'admin', 202, [7]);
expectChatPush(count($db->queue) === 4 && $db->inTransaction(), 'Uncommitted broadcasts must not enqueue or alter the transaction.');
$db->rollBack();

echo "Chat push routing, preferences, deduplication and failure handling passed.\n";
