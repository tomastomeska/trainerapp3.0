<?php

require_once __DIR__ . '/../includes/push_notifications.php';

class ContentPushPdo extends PDO
{
    public bool $transaction = false;
    public int $lastId = 0;
    public array $records = [];
    public array $subscriptions = [
        ['id' => 101, 'role' => 'coach', 'owner' => 1, 'coach' => 1],
        ['id' => 201, 'role' => 'athlete', 'owner' => 1, 'coach' => 1],
        ['id' => 202, 'role' => 'athlete', 'owner' => 1, 'coach' => 1],
        ['id' => 301, 'role' => 'athlete', 'owner' => 2, 'coach' => 2],
    ];
    public array $disabled = [];
    public array $logs = [];
    public array $queue = [];
    public array $allowedCoaches = [1];
    public array $allowedAthletes = [1];
    public bool $failRecipient = false;

    public function __construct() {}
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ContentPushStatement($this, $query);
    }
}

class ContentPushStatement extends PDOStatement
{
    private array $rows = [];
    private int $affected = 0;
    public function __construct(private ContentPushPdo $db, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $params = $params ?? [];
        $this->rows = [];
        $this->affected = 0;
        $kind = null;
        if (str_contains($this->sql, 'FROM surveys')) $kind = 'survey';
        elseif (str_contains($this->sql, 'FROM info_articles')) $kind = 'info_article';
        elseif (str_contains($this->sql, 'FROM admin_gallery_files')) $kind = 'admin_gallery';
        elseif (str_contains($this->sql, 'FROM gallery_files')) $kind = 'coach_gallery';
        if ($kind !== null) {
            $record = $this->db->records[$kind][$params[0]] ?? null;
            if ($record && ($record['available'] ?? true)) $this->rows[] = $record;
        } elseif (str_starts_with($this->sql, 'SELECT DISTINCT s.')) {
            $role = str_contains($this->sql, 'SELECT DISTINCT s.coach_id') ? 'coach' : 'athlete';
            $owners = [];
            foreach ($this->db->subscriptions as $sub) {
                if ($sub['role'] !== $role) continue;
                if (str_contains($this->sql, 's.id = ?') && $sub['id'] !== end($params)) continue;
                if (str_contains($this->sql, 'admin_gallery_file_coaches') && !in_array($sub['owner'], $this->db->allowedCoaches, true)) continue;
                if (str_contains($this->sql, 'admin_gallery_file_athletes') && !in_array($sub['owner'], $this->db->allowedAthletes, true)) continue;
                if (str_contains($this->sql, 'a.coach_id = ?') && $sub['coach'] !== $params[0]) continue;
                if (str_contains($this->sql, 'gallery_file_athletes') && !in_array($sub['owner'], $this->db->allowedAthletes, true)) continue;
                $owners[$sub['owner']] = $sub['owner'];
            }
            $this->rows = array_values($owners);
        } elseif (str_starts_with($this->sql, 'SELECT enabled FROM push_notification_settings')) {
            $role = str_contains($this->sql, 'coach_id = ? AND athlete_id IS NULL') ? 'coach' : 'athlete';
            if (in_array($role . ':' . $params[0] . ':' . $params[1], $this->db->disabled, true)) $this->rows[] = 0;
        } elseif (str_starts_with($this->sql, 'SELECT id FROM push_subscriptions')) {
            $role = str_contains($this->sql, 'coach_id = ? AND athlete_id IS NULL') ? 'coach' : 'athlete';
            if ($this->db->failRecipient && $role === 'coach') throw new RuntimeException('Simulated failure.');
            foreach ($this->db->subscriptions as $sub) {
                if ($sub['role'] === $role && $sub['owner'] === $params[0]) $this->rows[] = $sub['id'];
            }
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
            throw new RuntimeException('Unexpected SQL: ' . $this->sql);
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?? false;
    }
    public function fetchColumn(int $column = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function rowCount(): int { return $this->affected; }
}

function expectContent(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new ContentPushPdo();
$db->records = [
    'survey' => [10 => ['id' => 10, 'title' => 'Nový cíl', 'survey_type' => 'poll', 'audience' => 'all']],
    'info_article' => [20 => ['id' => 20, 'title' => 'Novinky', 'target_audience' => 'all', 'category_audience' => 'athlete']],
    'admin_gallery' => [30 => ['id' => 30, 'title' => 'Foto příběh', 'visibility' => 'specific_athletes']],
    'coach_gallery' => [40 => ['id' => 40, 'title' => 'Plán.pdf', 'visibility' => 'specific_athletes', 'coach_id' => 1]],
];
$db->disabled = ['athlete:2:surveys'];
pushContentNotify($db, 'survey', 10);
expectContent(count($db->queue) === 3, 'Poll must reach the coach and both devices of the enabled athlete.');
$logs = array_values($db->logs);
expectContent($logs[0]['data'][6] === '/surveys.php?id=10', 'Coach poll target is incorrect.');
expectContent($logs[1]['data'][6] === '/athlete_surveys.php?id=10', 'Athlete poll target is incorrect.');
expectContent(str_contains($db->queue[1][2], 'Nový cíl'), 'Poll name is missing.');
pushContentNotify($db, 'survey', 10);
expectContent(count($db->queue) === 3, 'A repeated event must not queue duplicates.');

$db->records['survey'][10]['survey_type'] = 'questionnaire';
expectContent(str_contains(pushContentBody('survey', $db->records['survey'][10]), 'Nový dotazník'), 'Questionnaire label is incorrect.');
pushContentNotify($db, 'info_article', 20);
expectContent(count($db->queue) === 6, 'Article category audience must restrict notifications to athletes.');
$logs = array_values($db->logs);
expectContent($logs[3]['data'][6] === '/athlete_infokanal.php?article=20', 'Article link must select the article.');
expectContent($db->queue[4][1] === 'info_channel' && str_contains($db->queue[4][2], 'Novinky'), 'Article title or preference is incorrect.');
pushContentNotify($db, 'info_article', 20, ['athlete']);
expectContent(count($db->queue) === 6, 'Editing an already visible article must not notify again.');
$db->records['info_article'][20]['available'] = false;
pushContentNotify($db, 'info_article', 20);
expectContent(count($db->queue) === 6, 'Unavailable or future articles must not be queued.');

pushContentNotify($db, 'admin_gallery', 30);
expectContent(count($db->queue) === 8, 'Admin gallery must notify only specifically selected athletes.');
pushContentNotify($db, 'coach_gallery', 40);
expectContent(count($db->queue) === 10, 'Coach gallery must notify only selected athletes of that coach.');
$logs = array_values($db->logs);
expectContent($logs[8]['data'][6] === '/athlete_gallery.php?coach_file=40', 'Coach gallery target is incorrect.');
expectContent(str_contains($db->queue[9][2], 'Plán.pdf') && $db->queue[9][1] === 'gallery', 'Coach gallery name or preference is incorrect.');
$db->records['coach_gallery'][40]['visibility'] = 'private';
pushContentNotify($db, 'coach_gallery', 40);
expectContent(count($db->queue) === 10, 'Private files must not notify.');

$delivery = ['coach_id' => null, 'athlete_id' => 1, 'subscription_id' => 201,
    'notification_type' => 'content_coach_gallery', 'target_url' => '/athlete_gallery.php?coach_file=40'];
expectContent(!pushContentDeliveryAllowed($db, $delivery), 'A queued push must be skipped when sharing is revoked.');
$db->records['coach_gallery'][40]['visibility'] = 'specific_athletes';
expectContent(pushContentDeliveryAllowed($db, $delivery), 'A visible file must remain eligible.');
$db->allowedAthletes = [];
expectContent(!pushContentDeliveryAllowed($db, $delivery), 'Removing a selected recipient must prevent queued delivery.');
$delivery['target_url'] = '/athlete_gallery.php?coach_file=40&other=1';
expectContent(!pushContentDeliveryAllowed($db, $delivery), 'Unexpected content target must be rejected.');

$long = $db->records['survey'][10];
$long['title'] = str_repeat('ž', 300);
expectContent(mb_strlen(pushContentBody('survey', $long), 'UTF-8') === 240, 'Long Unicode title must fit the push body limit.');
$db->transaction = true;
pushContentNotify($db, 'survey', 10);
expectContent(count($db->queue) === 10, 'An uncommitted event must not enqueue a push.');
$db->transaction = false;
$db->records['survey'][11] = ['id' => 11, 'title' => 'Nová anketa', 'survey_type' => 'poll', 'audience' => 'all'];
$db->failRecipient = true;
pushContentNotify($db, 'survey', 11);
expectContent(count($db->queue) === 12, 'A failed recipient must not prevent another recipient from being notified.');

$adminDb = new ContentPushPdo();
$adminDb->records['admin_gallery'][31] = ['id' => 31, 'title' => 'Nový příspěvek', 'visibility' => 'all_users'];
$adminDb->disabled = ['athlete:1:gallery'];
pushContentNotify($adminDb, 'admin_gallery', 31);
$adminLogs = array_values($adminDb->logs);
expectContent(count($adminDb->queue) === 2, 'The gallery preference must suppress all devices for that user.');
expectContent($adminLogs[0]['data'][6] === '/gallery.php?admin_file=31', 'Coach admin-gallery link is incorrect.');
expectContent($adminLogs[1]['data'][6] === '/athlete_gallery.php?admin_file=31', 'Athlete admin-gallery link is incorrect.');
$adminDelivery = ['coach_id' => 1, 'athlete_id' => null, 'subscription_id' => 101,
    'notification_type' => 'content_admin_gallery', 'target_url' => '/gallery.php?admin_file=31'];
expectContent(pushContentDeliveryAllowed($adminDb, $adminDelivery), 'An eligible coach must receive admin gallery content.');
$adminDb->records['admin_gallery'][31]['visibility'] = 'all_athletes';
expectContent(!pushContentDeliveryAllowed($adminDb, $adminDelivery), 'A changed audience must suppress an already queued delivery.');

echo "Content push routing, preferences, deduplication and visibility recheck passed.\n";
