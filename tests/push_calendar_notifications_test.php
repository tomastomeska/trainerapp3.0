<?php

require_once __DIR__ . '/../includes/push_calendar_notifications.php';

class CalendarPushPdo extends PDO
{
    public bool $transaction = false;
    public array $owners = [11 => 5, 12 => 5, 13 => 6];
    public array $names = [11 => 'Jana Nová', 12 => 'Petr Nový', 13 => 'Jiný sportovec'];
    public array $devices = ['coach:5' => [101], 'athlete:11' => [201, 202], 'athlete:12' => [203]];
    public array $disabled = [];
    public array $logs = [];
    public array $queue = [];
    public int $lastId = 0;
    public int $failAthlete = 0;

    public function __construct() {}
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new CalendarPushStatement($this, $query);
    }
}

class CalendarPushStatement extends PDOStatement
{
    private CalendarPushPdo $db;
    private string $sql;
    private array $rows = [];
    private int $affected = 0;

    public function __construct(CalendarPushPdo $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(?array $params = null): bool
    {
        $params = $params ?? [];
        $this->rows = [];
        $this->affected = 0;
        if (str_starts_with($this->sql, "SELECT CONCAT_WS(' ', first_name, last_name) FROM athletes")) {
            if (($this->db->owners[$params[0]] ?? null) === $params[1]) {
                $this->rows[] = $this->db->names[$params[0]] ?? null;
            }
        } elseif (str_starts_with($this->sql, 'SELECT id FROM athletes')) {
            if ($params[0] === $this->db->failAthlete) {
                throw new RuntimeException('Simulated recipient lookup failure.');
            }
            if (($this->db->owners[$params[0]] ?? null) === $params[1]) {
                $this->rows[] = $params[0];
            }
        } elseif (str_starts_with($this->sql, 'SELECT enabled FROM push_notification_settings')) {
            $role = str_contains($this->sql, 'coach_id = ? AND athlete_id IS NULL') ? 'coach' : 'athlete';
            if (in_array($role . ':' . $params[0], $this->db->disabled, true)) {
                $this->rows[] = 0;
            }
        } elseif (str_starts_with($this->sql, 'SELECT id FROM push_subscriptions')) {
            $role = str_contains($this->sql, 'coach_id = ? AND athlete_id IS NULL') ? 'coach' : 'athlete';
            $this->rows = $this->db->devices[$role . ':' . $params[0]] ?? [];
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
            throw new RuntimeException('Unexpected SQL in calendar push test.');
        }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return array_shift($this->rows) ?? false; }
    public function rowCount(): int { return $this->affected; }
}

function expectCalendarPush(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new CalendarPushPdo();
pushCalendarNotify($db, 'athlete_request', 5, [], 40, 'calendar-request:40', '2030-01-02 09:00:00', true, 11, 'Tělocvična');
$firstLog = array_values($db->logs)[0]['data'];
expectCalendarPush($firstLog[1] === 5 && $firstLog[2] === null, 'Request must go to the coach.');
expectCalendarPush($firstLog[6] === '/calendar.php?tab=week&focus_date=2030-01-02&focus_event=40', 'Request target must open the event.');
expectCalendarPush(str_contains($db->queue[1][2], 'Jana Nová'), 'Coach must see the requesting athlete name.');
expectCalendarPush(str_contains($db->queue[1][2], '02.01.2030 09:00') && str_contains($db->queue[1][2], 'Tělocvična'), 'Request must include the event time and location.');
pushCalendarNotify($db, 'athlete_request', 5, [], 40, 'calendar-request:40', '2030-01-02 09:00:00', true, 11);
expectCalendarPush(count($db->queue) === 1, 'A repeated source event must not queue a duplicate.');

pushCalendarNotify($db, 'coach_created', 5, [11, 12, 12, 13], 41, 'calendar-created:41', '2030-02-03 14:15:00', true, 0, 'Studio');
expectCalendarPush(count($db->queue) === 4, 'Both participants and all their devices should be queued once; foreign athletes must be excluded.');
expectCalendarPush(str_contains($db->queue[2][2], '03.02.2030 14:15') && str_contains($db->queue[2][2], 'Studio'), 'Created event details must reach the athlete.');
foreach (array_slice(array_values($db->logs), 1) as $log) {
    expectCalendarPush($log['data'][1] === null && $log['data'][6] === '/athlete_calendar.php?focus_date=2030-02-03&focus_event=41', 'Athlete notification has an incorrect owner or target.');
}

$db->disabled[] = 'athlete:12';
pushCalendarNotify($db, 'coach_approved', 5, [11, 12], 42, 'calendar-approval:42', '2030-03-04 10:30:00', true, 0, 'Hřiště');
expectCalendarPush(count($db->queue) === 6, 'The disabled calendar category must suppress notifications.');
expectCalendarPush(str_contains($db->queue[5][2], '04.03.2030 10:30') && str_contains($db->queue[5][2], 'Hřiště'), 'Approval must include the approved time and location.');
$db->failAthlete = 11;
pushCalendarNotify($db, 'coach_changed', 5, [11, 12], 42, '');
expectCalendarPush(count($db->queue) === 6, 'A failed lookup or disabled category must not queue a notification.');
$db->disabled = [];
pushCalendarNotify($db, 'coach_changed', 5, [11, 12], 42, '');
expectCalendarPush(count($db->queue) === 7, 'A recipient failure must not block other participants.');
$db->failAthlete = 0;
pushCalendarNotify($db, 'coach_changed', 5, [12], 42, '');
pushCalendarNotify($db, 'coach_changed', 5, [12], 42, '');
expectCalendarPush(count($db->queue) === 9, 'Distinct committed edits must not be collapsed.');

$db->transaction = true;
pushCalendarNotify($db, 'athlete_cancelled', 5, [], 40, 'calendar-athlete-cancel:40:11');
expectCalendarPush(count($db->queue) === 9, 'An uncommitted event must not be queued.');
$db->transaction = false;
pushCalendarNotify($db, 'athlete_request_cancelled', 5, [], 40, 'calendar-request-cancel:40', '2030-01-02 09:00:00', false, 11, 'Tělocvična');
$lastLog = array_values($db->logs)[count($db->logs) - 1]['data'];
expectCalendarPush($lastLog[6] === '/calendar.php?tab=week&focus_date=2030-01-02', 'Deleted events must not be focused.');
$lastQueued = end($db->queue);
expectCalendarPush(str_contains($lastQueued[2], 'Jana Nová'), 'Coach must see the cancelling athlete name.');
expectCalendarPush(str_contains($lastQueued[2], '02.01.2030 09:00') && str_contains($lastQueued[2], 'Tělocvična'), 'Cancelled request must use the original details.');
pushCalendarNotify($db, 'coach_cancelled', 5, [11], 43, 'coach-cancelled:43', '2030-02-03 14:15:00', false, 0, 'Studio');
$lastLog = array_values($db->logs)[count($db->logs) - 1]['data'];
expectCalendarPush($lastLog[6] === '/athlete_calendar.php?focus_date=2030-02-03', 'A cancelled athlete event must open its week without focusing the deleted event.');

$db->names[11] = "Jana\n" . str_repeat('ž', 300);
pushCalendarNotify($db, 'athlete_cancelled', 5, [], 44, 'cancel-long-name', '', false, 11);
$lastBody = end($db->queue)[2];
expectCalendarPush(str_contains($lastBody, 'Jana ') && !str_contains($lastBody, "\n") && mb_strlen($lastBody, 'UTF-8') <= 240, 'Names must be sanitized and fit the payload.');
pushCalendarNotify($db, 'athlete_request', 5, [], 45, 'foreign-name', '', true, 13);
$lastBody = end($db->queue)[2];
expectCalendarPush(!str_contains($lastBody, 'Jiný sportovec'), 'Another coach\'s athlete name must not be disclosed.');
expectCalendarPush(str_contains($lastBody, 'Termín: neuveden.') && str_contains($lastBody, 'Místo: neuvedeno.'), 'Missing details must not be invented.');
pushCalendarNotify($db, 'athlete_cancelled', 5, [], 46, 'long-location', '2030-04-05 12:45:00', false, 11, str_repeat('ž', 300));
$lastBody = end($db->queue)[2];
expectCalendarPush(mb_strlen($lastBody, 'UTF-8') <= 240 && str_contains($lastBody, '05.04.2030 12:45') && str_contains($lastBody, '…'), 'Long names and locations must leave room for date and fit the payload.');

$source = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../api/calendar_delete_event.php'));
$commitAt = strpos($source, '$pdo->commit();');
$catchEnd = $commitAt === false ? false : strpos($source, "    exit;\n}\n\n", $commitAt);
expectCalendarPush($catchEnd !== false, 'Coach deletion commit boundary missing.');
$catchEnd += strlen("    exit;\n}\n\n");
$sectionEnd = strpos($source, '$deletedEventIds = [];', $catchEnd);
expectCalendarPush($sectionEnd !== false, 'Coach deletion push boundary missing.');
$deleteSection = substr($source, $catchEnd, $sectionEnd - $catchEnd);
expectCalendarPush(str_contains($deleteSection, 'pushCalendarNotify'), 'Coach deletion push integration missing.');
$pdo = new CalendarPushPdo();
$coachId = 5;
$event = ['approval_status' => 'approved'];
$eventsToCancel = [['athlete_id' => 11, 'second_athlete_id' => 12, 'starts_at' => '2030-05-06 17:00:00', 'location' => 'Hala']];
$removeOnly = true;
$deleteScope = 'single';
$eventId = 50;
eval($deleteSection);
expectCalendarPush(count($pdo->queue) === 3, 'Deleting from the detail must notify both participants.');
expectCalendarPush(str_contains($pdo->queue[1][2], '06.05.2030 17:00') && str_contains($pdo->queue[1][2], 'Hala'), 'Coach cancellation must include the deleted event details.');
$removeOnly = false;
$eventId = 51;
eval($deleteSection);
expectCalendarPush(count($pdo->queue) === 6, 'Cancelling from the list must notify both participants.');
$deleteScope = 'future';
$eventId = 52;
$eventsToCancel = [
    ['athlete_id' => 11, 'starts_at' => '2030-05-06 17:00:00', 'location' => 'Hala'],
    ['athlete_id' => 12, 'starts_at' => '2030-05-13 18:00:00', 'location' => 'Studio'],
    ['athlete_id' => 11, 'starts_at' => '2030-05-20 17:00:00', 'location' => 'Hala'],
];
eval($deleteSection);
expectCalendarPush(count($pdo->queue) === 9, 'A series deletion must notify each device once.');
$seriesEntries = array_slice(array_values($pdo->queue), -3);
expectCalendarPush(str_contains($seriesEntries[0][2], 'Série od: 06.05.2030 17:00') && str_contains($seriesEntries[2][2], 'Série od: 13.05.2030 18:00') && str_contains($seriesEntries[2][2], 'Studio'), 'Each participant must get details of their first affected occurrence.');

echo "Calendar push routing and queue tests passed.\n";
