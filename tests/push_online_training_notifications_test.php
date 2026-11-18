<?php

require_once __DIR__ . '/../includes/push_online_training_notifications.php';

class OnlinePushPdo extends PDO
{
    public bool $transaction = false;
    public array $training = [
        'trainer_id' => 5, 'athlete_id' => 11, 'sequence_number' => 12,
        'status' => 'sent', 'first_name' => 'Jana', 'last_name' => 'Nová',
    ];
    public array $logs = [];
    public array $queue = [];
    public bool $disabled = false;
    public bool $relationshipExists = true;
    public bool $failLookup = false;
    public int $lastId = 0;

    public function __construct() {}
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new OnlinePushStatement($this, $query);
    }
}

class OnlinePushStatement extends PDOStatement
{
    private OnlinePushPdo $db;
    private string $sql;
    private array $rows = [];
    private int $affected = 0;

    public function __construct(OnlinePushPdo $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(?array $params = null): bool
    {
        $params = $params ?? [];
        $this->rows = [];
        $this->affected = 0;
        if (str_starts_with($this->sql, 'SELECT ot.trainer_id')) {
            if ($this->db->failLookup) throw new RuntimeException('Simulated lookup failure.');
            if ($this->db->relationshipExists && $params[0] === 40) {
                $this->rows[] = $this->db->training;
            }
        } elseif (str_starts_with($this->sql, 'SELECT enabled FROM push_notification_settings')) {
            if ($this->db->disabled) $this->rows[] = 0;
        } elseif (str_starts_with($this->sql, 'SELECT id FROM push_subscriptions')) {
            $this->rows = str_contains($this->sql, 'coach_id = ? AND athlete_id IS NULL') ? [101] : [201, 202];
        } elseif (str_contains($this->sql, 'INSERT INTO push_notification_logs')) {
            $key = $params[0];
            if (!isset($this->db->logs[$key])) {
                $this->db->logs[$key] = ['id' => count($this->db->logs) + 1, 'data' => $params];
            }
            $this->db->lastId = $this->db->logs[$key]['id'];
        } elseif (str_contains($this->sql, 'INSERT INTO push_delivery_queue')) {
            if (!isset($this->db->queue[$params[0]])) {
                $this->db->queue[$params[0]] = $params;
                $this->affected = 1;
            }
        } else {
            throw new RuntimeException('Unexpected SQL in online training push test.');
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?? false;
    }
    public function fetchColumn(int $column = 0): mixed { return array_shift($this->rows) ?? false; }
    public function rowCount(): int { return $this->affected; }
}

function expectOnlinePush(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new OnlinePushPdo();
pushOnlineTrainingNotify($db, 40, 'sent');
expectOnlinePush(count($db->queue) === 2, 'The sent training must notify all athlete devices.');
$sentLog = array_values($db->logs)[0]['data'];
expectOnlinePush($sentLog[1] === null && $sentLog[2] === 11 && $sentLog[6] === '/online_training.php?id=40', 'Sent training has the wrong recipient or target.');
expectOnlinePush($db->queue[1][1] === 'online_training' && str_contains($db->queue[1][2], '#012'), 'Sent training must use the right category and number.');
pushOnlineTrainingNotify($db, 40, 'sent');
expectOnlinePush(count($db->queue) === 2, 'The same sent training must not queue duplicates.');

$db->training['status'] = 'created';
pushOnlineTrainingNotify($db, 40, 'note');
expectOnlinePush(count($db->queue) === 2, 'Draft changes must not notify the athlete.');
$db->training['status'] = 'in_progress';
pushOnlineTrainingNotify($db, 40, 'completed');
expectOnlinePush(count($db->queue) === 2, 'An unfinished training must not notify completion.');
$db->training['status'] = 'completed';
pushOnlineTrainingNotify($db, 40, 'completed');
$completedLog = array_values($db->logs)[2]['data'];
expectOnlinePush($completedLog[1] === 5 && $completedLog[2] === null, 'Completion must go to the coach.');
expectOnlinePush(str_contains($db->queue[3][2], 'Jana Nová') && str_contains($db->queue[3][2], '#012'), 'Completion must identify athlete and training.');
pushOnlineTrainingNotify($db, 40, 'completed');
expectOnlinePush(count($db->queue) === 3, 'Completion retries must not queue duplicates.');

pushOnlineTrainingNotify($db, 40, 'reaction');
pushOnlineTrainingNotify($db, 40, 'note');
expectOnlinePush(count($db->queue) === 7, 'Reaction and changed note must notify the athlete.');
expectOnlinePush(str_contains($db->queue[4][2], '#012') && !str_contains($db->queue[4][2], 'Jana'), 'Reaction must identify the training without leaking note text.');
pushOnlineTrainingNotify($db, 40, 'reaction');
expectOnlinePush(count($db->queue) === 9, 'Distinct reactions must not be deduplicated.');
$db->disabled = true;
pushOnlineTrainingNotify($db, 40, 'reaction');
expectOnlinePush(count($db->queue) === 9, 'Disabled online training preference must suppress push.');
$db->disabled = false;
$db->relationshipExists = false;
pushOnlineTrainingNotify($db, 40, 'reaction');
expectOnlinePush(count($db->queue) === 9, 'A foreign coach-athlete relationship must not receive push.');
$db->relationshipExists = true;
$db->transaction = true;
pushOnlineTrainingNotify($db, 40, 'reaction');
expectOnlinePush(count($db->queue) === 9, 'Uncommitted events must not be queued.');
$db->transaction = false;
$db->failLookup = true;
pushOnlineTrainingNotify($db, 40, 'reaction');
expectOnlinePush(count($db->queue) === 9, 'A push failure must not escape or queue a notification.');

echo "Online training push routing and queue tests passed.\n";
