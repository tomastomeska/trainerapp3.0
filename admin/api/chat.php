<?php
// admin/api/chat.php – AJAX chat administrátora s trenéry i sportovci (společný hub)
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/chat_attachments.php';
require_once __DIR__ . '/../../includes/chat_message_deletions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdminLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$pdo = getDB();
ensureChatMessageDeletionTable($pdo);

function adminChatMessageToArray(array $m): array {
    return [
        'id' => (int)$m['id'],
        'sender' => (string)$m['sender'],
        'body' => (string)$m['body'],
        'attachment_url' => !empty($m['attachment_path']) ? BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path']) : null,
        'attachment_name' => $m['attachment_name'] ?? null,
        'attachment_type' => !empty($m['attachment_name']) ? chatAttachmentMimeType((string)$m['attachment_name']) : null,
        'read_at' => $m['sender'] === 'admin'
            ? ($m['athlete_read_at'] ?? $m['coach_read_at'] ?? null)
            : ($m['admin_read_at'] ?? null),
        'created_at' => formatDateTime((string)$m['created_at']),
        'deleted_for_everyone' => !empty($m['deleted_for_everyone']),
        'deleted_event_id' => (int)($m['deleted_event_id'] ?? 0),
    ];
}

$action = (string)($_REQUEST['action'] ?? '');
$athleteTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_athlete_chat_messages'")->fetchColumn();
$coachTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_coach_chat_messages'")->fetchColumn();

if ($action === 'unread_count') {
    $total = 0;
    if ($athleteTableExists) {
        $total += (int)$pdo->query("SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE sender = 'athlete' AND admin_read_at IS NULL")->fetchColumn();
    }
    if ($coachTableExists) {
        $total += (int)$pdo->query("SELECT COUNT(*) FROM admin_coach_chat_messages WHERE sender = 'coach' AND admin_read_at IS NULL")->fetchColumn();
    }
    echo json_encode(['ok' => true, 'unread_count' => $total]);
    exit;
}

if ($action === 'list') {
    $items = [];

    if ($athleteTableExists) {
        $rows = $pdo->query(
            "SELECT a.id, a.first_name, a.last_name,
                    (SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE athlete_id = a.id AND sender = 'athlete' AND admin_read_at IS NULL) AS unread_count
             FROM athletes a
             WHERE a.login_enabled = 1"
        )->fetchAll();
        foreach ($rows as $r) {
            $preview = fetchChatMessagePreview($pdo, 'admin_athlete_chat_messages', ['athlete_id' => (int)$r['id']], 'admin', 0);
            $items[] = [
                'type' => 'athlete',
                'id' => (int)$r['id'],
                'name' => trim((string)$r['first_name'] . ' ' . (string)$r['last_name']) . ' (ID: ' . (int)$r['id'] . ')',
                'last_body' => $preview['body'],
                'last_at' => $preview['created_at'] ? formatDateTime((string)$preview['created_at']) : null,
                'last_at_raw' => $preview['created_at'],
                'unread_count' => (int)$r['unread_count'],
            ];
        }
    }

    if ($coachTableExists) {
        $rows = $pdo->query(
            "SELECT c.id, c.name, c.username,
                    (SELECT COUNT(*) FROM admin_coach_chat_messages WHERE coach_id = c.id AND sender = 'coach' AND admin_read_at IS NULL) AS unread_count
             FROM coaches c
             WHERE c.is_active = 1"
        )->fetchAll();
        foreach ($rows as $r) {
            $preview = fetchChatMessagePreview($pdo, 'admin_coach_chat_messages', ['coach_id' => (int)$r['id']], 'admin', 0);
            $items[] = [
                'type' => 'coach',
                'id' => (int)$r['id'],
                'name' => ($r['name'] ?: $r['username']),
                'last_body' => $preview['body'],
                'last_at' => $preview['created_at'] ? formatDateTime((string)$preview['created_at']) : null,
                'last_at_raw' => $preview['created_at'],
                'unread_count' => (int)$r['unread_count'],
            ];
        }
    }

    usort($items, static function (array $a, array $b): int {
        if (($a['unread_count'] > 0) !== ($b['unread_count'] > 0)) {
            return $a['unread_count'] > 0 ? -1 : 1;
        }
        $aAt = $a['last_at_raw'] ?? '';
        $bAt = $b['last_at_raw'] ?? '';
        if ($aAt === $bAt) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $aAt > $bAt ? -1 : 1;
    });

    foreach ($items as &$item) {
        unset($item['last_at_raw']);
    }
    unset($item);

    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

$type = ($_REQUEST['type'] ?? '') === 'coach' ? 'coach' : 'athlete';
$targetId = (int)($_REQUEST['target_id'] ?? 0);

if (in_array($action, ['thread', 'poll', 'send', 'delete'], true)) {
    if ($type === 'athlete' && (!$athleteTableExists || $targetId <= 0)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    if ($type === 'coach' && (!$coachTableExists || $targetId <= 0)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
}

if ($action === 'thread' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($type === 'athlete') {
        $pdo->prepare("UPDATE admin_athlete_chat_messages SET admin_read_at = NOW() WHERE athlete_id = ? AND sender = 'athlete' AND admin_read_at IS NULL")->execute([$targetId]);
        $rows = fetchChatMessagesForUser($pdo, 'admin_athlete_chat_messages', ['athlete_id' => $targetId], 'admin', 0);
    } else {
        $pdo->prepare("UPDATE admin_coach_chat_messages SET admin_read_at = NOW() WHERE coach_id = ? AND sender = 'coach' AND admin_read_at IS NULL")->execute([$targetId]);
        $rows = fetchChatMessagesForUser($pdo, 'admin_coach_chat_messages', ['coach_id' => $targetId], 'admin', 0);
    }
    echo json_encode(['ok' => true, 'messages' => array_map('adminChatMessageToArray', $rows)]);
    exit;
}

if ($action === 'poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    $sinceDeletionId = max(0, (int)($_GET['since_deletion_id'] ?? 0));
    if ($type === 'athlete') {
        $pdo->prepare("UPDATE admin_athlete_chat_messages SET admin_read_at = NOW() WHERE athlete_id = ? AND sender = 'athlete' AND admin_read_at IS NULL")->execute([$targetId]);
        $rows = fetchChatMessagesForUser($pdo, 'admin_athlete_chat_messages', ['athlete_id' => $targetId], 'admin', 0, $sinceId, $sinceDeletionId, 100);
    } else {
        $pdo->prepare("UPDATE admin_coach_chat_messages SET admin_read_at = NOW() WHERE coach_id = ? AND sender = 'coach' AND admin_read_at IS NULL")->execute([$targetId]);
        $rows = fetchChatMessagesForUser($pdo, 'admin_coach_chat_messages', ['coach_id' => $targetId], 'admin', 0, $sinceId, $sinceDeletionId, 100);
    }
    echo json_encode(['ok' => true, 'messages' => array_map('adminChatMessageToArray', $rows)]);
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }

    $table = $type === 'athlete' ? 'admin_athlete_chat_messages' : 'admin_coach_chat_messages';
    $threadFilters = [($type === 'athlete' ? 'athlete_id' : 'coach_id') => $targetId];
    try {
        $result = deleteChatMessageForUser(
            $pdo,
            $table,
            $threadFilters,
            max(0, (int)($_POST['message_id'] ?? 0)),
            (string)($_POST['scope'] ?? ''),
            'admin',
            0
        );
    } catch (ChatMessageDeletionException | InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $response = ['ok' => true, 'scope' => $result['scope']];
    if ($result['scope'] === 'everyone') {
        $rows = fetchChatMessagesForUser($pdo, $table, $threadFilters, 'admin', 0);
        foreach ($rows as $row) {
            if ((int)$row['id'] === (int)($_POST['message_id'] ?? 0)) {
                $response['message'] = adminChatMessageToArray($row);
                break;
            }
        }
        if (!$result['attachment_removed']) {
            $response['warning'] = 'Zpráva byla odstraněna, ale soubor se nepodařilo odstranit ze serveru.';
        }
    }
    echo json_encode($response);
    exit;
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }

    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000, 'UTF-8');
    $attachment = receiveChatAttachment($_FILES);
    if ($attachment['error'] !== null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $attachment['error']]);
        exit;
    }
    if ($body === '' && $attachment['path'] === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Zadejte zprávu nebo přidejte přílohu.']);
        exit;
    }

    if ($type === 'athlete') {
        $athleteStmt = $pdo->prepare('SELECT first_name, last_name, email FROM athletes WHERE id = ? LIMIT 1');
        $athleteStmt->execute([$targetId]);
        $athlete = $athleteStmt->fetch();
        if (!$athlete) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found']);
            exit;
        }
        $pdo->prepare('INSERT INTO admin_athlete_chat_messages (athlete_id, sender, body, attachment_path, attachment_name, admin_read_at) VALUES (?, "admin", ?, ?, ?, NOW())')
            ->execute([$targetId, $body, $attachment['path'], $attachment['name']]);
        $chatMessageId = (int)$pdo->lastInsertId();
        $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
        if (!empty($athlete['email']) && isFirstChatMessageToday('admin_athlete_chat_messages', ['athlete_id' => $targetId], 'admin', $chatMessageId)) {
            if (sendAthleteMessageNotificationEmail((string)$athlete['email'], $athleteName !== '' ? $athleteName : 'sportovče', 'Nová zpráva od administrátora', chatNotificationText($body, $attachment['name']))) {
                processEmailNotificationQueue(200, 'athlete_message_notification');
            }
        }
    } else {
        $coachStmt = $pdo->prepare('SELECT name, username, email FROM coaches WHERE id = ? LIMIT 1');
        $coachStmt->execute([$targetId]);
        $coach = $coachStmt->fetch();
        if (!$coach) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found']);
            exit;
        }
        $pdo->prepare('INSERT INTO admin_coach_chat_messages (coach_id, sender, body, attachment_path, attachment_name, admin_read_at) VALUES (?, "admin", ?, ?, ?, NOW())')
            ->execute([$targetId, $body, $attachment['path'], $attachment['name']]);
        $chatMessageId = (int)$pdo->lastInsertId();
        if (!empty($coach['email']) && isFirstChatMessageToday('admin_coach_chat_messages', ['coach_id' => $targetId], 'admin', $chatMessageId)) {
            $coachName = ($coach['name'] ?? '') !== '' ? (string)$coach['name'] : (string)($coach['username'] ?? 'trenér');
            sendCoachChatMessageNotificationEmail((string)$coach['email'], $coachName, chatNotificationText($body, $attachment['name']));
        }
    }

    echo json_encode(['ok' => true, 'id' => $chatMessageId]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action']);
