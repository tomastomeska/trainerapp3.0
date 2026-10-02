<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function mobileEventActor(): array
{
    $token = mobileBearerToken();
    if ($token === '') {
        mobileJson(['success' => false, 'error' => 'Chybí přístupový token.'], 401);
    }

    $pdo = getDB();
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT account_type
         FROM mobile_api_tokens
         WHERE token_hash = ?
           AND revoked_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $type = (string)$stmt->fetchColumn();
    if ($type === 'coach') {
        $coach = mobileRequireCoach();
        return [
            'role' => 'coach',
            'id' => (int)$coach['id'],
            'name' => (string)($coach['name'] ?? ''),
            'email' => (string)($coach['email'] ?? ''),
        ];
    }
    if ($type === 'athlete') {
        $athlete = mobileRequireAthlete();
        return [
            'role' => 'athlete',
            'id' => (int)$athlete['id'],
            'name' => trim((string)($athlete['name'] ?? '')),
            'email' => (string)($athlete['email'] ?? ''),
        ];
    }

    mobileJson(['success' => false, 'error' => 'Neplatný nebo prošlý přístupový token.'], 401);
}

function mobileEventsUnlocked(PDO $pdo, string $role, int $userId): bool
{
    $table = $role === 'coach' ? 'coaches' : 'athletes';
    try {
        $column = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'special_training_enabled'");
        if ($column === false || !$column->fetch()) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT special_training_enabled FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return ((int)$stmt->fetchColumn()) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function mobileEventPublicUrl(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    if (str_starts_with($value, '/')) {
        return 'https://www.reservio.online' . $value;
    }
    return '';
}

function mobileEventHtml(string $html): string
{
    $clean = function_exists('sanitizeSpecialEventHtml') ? sanitizeSpecialEventHtml($html) : $html;
    $clean = preg_replace('#(src|href)\s*=\s*([\'"])/#i', '$1=$2https://www.reservio.online/', $clean) ?? $clean;
    return $clean;
}

function mobileEventImage(string $filename): string
{
    $filename = trim($filename);
    if ($filename === '') {
        return '';
    }
    return function_exists('mobilePublicPhoto') ? mobilePublicPhoto($filename, 'events') : '';
}

function mobileEventListItem(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'slug' => (string)$row['slug'],
        'name' => (string)($row['name'] ?? 'Event'),
        'description' => (string)($row['description'] ?? ''),
        'badge' => (string)($row['badge_label'] ?? ''),
        'image' => mobileEventImage((string)($row['tile_image'] ?? '')),
    ];
}

function mobileEventRevision(PDO $pdo, string $audience, array $events): string
{
    $parts = [];
    foreach ($events as $event) {
        $eventId = (int)$event['id'];
        $parts[] = $eventId . '|' . (string)$event['name'] . '|' . (string)($event['description'] ?? '') . '|' . (string)($event['badge_label'] ?? '') . '|' . (string)($event['tile_image'] ?? '');
        try {
            $tabs = $pdo->prepare(
                "SELECT id, title, MD5(COALESCE(content_html, '')) AS content_hash
                 FROM special_event_tabs
                 WHERE event_id = ? AND is_active = 1 AND (audience = 'both' OR audience = ?)
                 ORDER BY sort_order ASC, id ASC"
            );
            $tabs->execute([$eventId, $audience]);
            foreach ($tabs->fetchAll() as $tab) {
                $parts[] = 't' . (int)$tab['id'] . '|' . (string)$tab['title'] . '|' . (string)$tab['content_hash'];
            }
        } catch (Throwable $e) {
        }
        if (function_exists('specialEventHasUpcomingItemsTable') && specialEventHasUpcomingItemsTable($pdo)) {
            try {
                $items = $pdo->prepare(
                    'SELECT id, title, event_date, target_url, tab_id
                     FROM special_event_upcoming_items
                     WHERE event_id = ? AND is_active = 1
                     ORDER BY event_date ASC, id ASC'
                );
                $items->execute([$eventId]);
                foreach ($items->fetchAll() as $item) {
                    $parts[] = 'u' . (int)$item['id'] . '|' . (string)$item['title'] . '|' . (string)$item['event_date'] . '|' . (string)($item['target_url'] ?? '') . '|' . (int)($item['tab_id'] ?? 0);
                }
            } catch (Throwable $e) {
            }
        }
    }
    return md5(implode("\n", $parts));
}

function mobileEventDetail(PDO $pdo, array $event): array
{
    $upcomingByTab = [];
    foreach ($event['upcoming_items'] ?? [] as $item) {
        $date = trim((string)($item['event_date'] ?? ''));
        $state = function_exists('specialEventUpcomingItemState') ? specialEventUpcomingItemState($date) : null;
        if (!$state || empty($state['visible'])) {
            continue;
        }
        $tabId = (int)($item['tab_id'] ?? 0);
        $upcomingByTab[$tabId][] = [
            'title' => trim((string)($item['title'] ?? '')) !== '' ? (string)$item['title'] : 'Událost',
            'date' => $date,
            'date_label' => function_exists('formatDate') ? formatDate($date) : $date,
            'status' => (string)($state['text'] ?? ''),
            'running' => !empty($state['is_running']),
            'past' => !empty($state['is_past']),
            'url' => mobileEventPublicUrl((string)($item['target_url'] ?? '')),
        ];
    }

    $tabs = [];
    foreach ($event['tabs'] ?? [] as $tab) {
        $tabId = (int)$tab['id'];
        $tabs[] = [
            'id' => $tabId,
            'title' => (string)($tab['title'] ?? 'Záložka'),
            'html' => mobileEventHtml((string)($tab['content_html'] ?? '')),
            'upcoming' => $upcomingByTab[$tabId] ?? [],
        ];
    }

    return [
        'id' => (int)$event['id'],
        'slug' => (string)$event['slug'],
        'name' => (string)($event['name'] ?? 'Event'),
        'description' => (string)($event['description'] ?? ''),
        'badge' => (string)($event['badge_label'] ?? ''),
        'image' => mobileEventImage((string)($event['tile_image'] ?? '')),
        'tabs' => $tabs,
    ];
}

$actor = mobileEventActor();
$pdo = getDB();
$input = mobileInput();
$action = (string)($_REQUEST['action'] ?? $input['action'] ?? '');
$role = (string)$actor['role'];
$userId = (int)$actor['id'];
$locked = !mobileEventsUnlocked($pdo, $role, $userId);

if ($locked) {
    mobileJson(['success' => true, 'locked' => true, 'intro' => '', 'revision' => '', 'events' => []]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submit_form') {
    $slug = strtolower(trim((string)($input['slug'] ?? '')));
    $slug = preg_replace('/[^a-z0-9-]/', '', $slug) ?? '';
    $event = $slug !== '' && function_exists('loadSpecialEventBySlug') ? loadSpecialEventBySlug($pdo, $slug, $role) : null;
    if (!$event) {
        mobileJson(['success' => false, 'error' => 'Požadovaný event nebyl nalezen nebo ještě není aktivní.'], 404);
    }

    $toEmail = function_exists('getAppSetting') ? trim((string)getAppSetting('events_forms_email_to', '')) : '';
    if ($toEmail === '' && function_exists('getAdminNotificationEmail')) {
        $toEmail = trim((string)getAdminNotificationEmail());
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        mobileJson(['success' => false, 'error' => 'Cílový e-mail pro formuláře Events není správně nastaven.'], 409);
    }

    $reserved = ['csrf_token' => true, '_event_slug' => true, '_event_name' => true, '_event_form_subject' => true, '_return_url' => true];
    $rawFields = $input['fields'] ?? [];
    if (!is_array($rawFields)) {
        $rawFields = [];
    }
    $fields = [];
    foreach ($rawFields as $key => $value) {
        $fieldKey = trim((string)$key);
        if ($fieldKey === '' || isset($reserved[$fieldKey])) {
            continue;
        }
        if (is_array($value)) {
            $vals = [];
            foreach ($value as $item) {
                $text = trim((string)$item);
                if ($text !== '') {
                    $vals[] = mb_substr($text, 0, 4000, 'UTF-8');
                }
            }
            if ($vals !== []) {
                $fields[$fieldKey] = $vals;
            }
            continue;
        }
        $textValue = trim((string)$value);
        if ($textValue === '') {
            continue;
        }
        $fields[$fieldKey] = mb_substr($textValue, 0, 4000, 'UTF-8');
    }
    if ($fields === []) {
        mobileJson(['success' => false, 'error' => 'Formulář neobsahoval vyplněná pole.'], 400);
    }

    $subject = trim((string)($input['subject'] ?? ''));
    if ($subject === '') {
        $subject = 'Events formular';
    }
    $eventName = (string)($event['name'] ?? '');
    if ($eventName !== '') {
        $subject .= ' - ' . $eventName;
    }
    $sent = function_exists('sendSpecialEventFormEmail') && sendSpecialEventFormEmail($toEmail, $subject, $fields, [
        'event_name' => $eventName,
        'event_slug' => $slug,
        'sender_name' => (string)$actor['name'],
        'sender_email' => (string)$actor['email'],
        'sender_role' => $role,
    ]);
    if (!$sent) {
        mobileJson(['success' => false, 'error' => 'Odeslání formuláře se nepodařilo. Zkuste to prosím znovu.'], 500);
    }
    mobileJson(['success' => true, 'message' => 'Formulář byl úspěšně odeslán.']);
}

$slug = strtolower(trim((string)($_GET['slug'] ?? $input['slug'] ?? '')));
$slug = preg_replace('/[^a-z0-9-]/', '', $slug) ?? '';
if ($slug !== '') {
    $event = function_exists('loadSpecialEventBySlug') ? loadSpecialEventBySlug($pdo, $slug, $role) : null;
    if (!$event) {
        mobileJson(['success' => false, 'error' => 'Požadovaný event nebyl nalezen nebo ještě není aktivní.'], 404);
    }
    mobileJson(['success' => true, 'locked' => false, 'event' => mobileEventDetail($pdo, $event)]);
}

$rows = function_exists('loadSpecialEvents') ? loadSpecialEvents($pdo, $role) : [];
$items = [];
foreach ($rows as $row) {
    $items[] = mobileEventListItem($row);
}
$introKey = $role === 'coach' ? 'events_intro_text_coach' : 'events_intro_text_athlete';
$intro = function_exists('getAppSetting')
    ? trim((string)getAppSetting($introKey, 'Vyber event a otevři jeho záložky.'))
    : '';
mobileJson([
    'success' => true,
    'locked' => false,
    'intro' => $intro,
    'revision' => mobileEventRevision($pdo, $role, $rows),
    'events' => $items,
]);
