<?php
// api/athlete_chat_admin.php – AJAX chat mezi přihlášeným sportovcem a administrátorem
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/chat_attachments.php';
require_once __DIR__ . '/../includes/chat_message_deletions.php';

header('Content-Type: application/json; charset=utf-8');

if (!athleteIsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();
ensureChatMessageDeletionTable($pdo);

$chatTableCheck = $pdo->query("SHOW TABLES LIKE 'admin_athlete_chat_messages'");
if ($chatTableCheck === false || !(bool)$chatTableCheck->fetchColumn()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_migrated']);
    exit;
}

function chatMessageToArray(array $m): array {
    return [
        'id' => (int)$m['id'],
        'sender' => (string)$m['sender'],
        'body' => (string)$m['body'],
        'attachment_url' => !empty($m['attachment_path']) ? BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path']) : null,
        'attachment_name' => $m['attachment_name'] ?? null,
        'attachment_type' => !empty($m['attachment_name']) ? chatAttachmentMimeType((string)$m['attachment_name']) : null,
        'read_at' => $m['sender'] === 'admin' ? ($m['athlete_read_at'] ?? null) : ($m['admin_read_at'] ?? null),
        'created_at' => formatDateTime((string)$m['created_at']),
        'deleted_for_everyone' => !empty($m['deleted_for_everyone']),
        'deleted_event_id' => (int)($m['deleted_event_id'] ?? 0),
    ];
}

$action = (string)($_REQUEST['action'] ?? '');

if ($action === 'unread_count') {
    session_write_close();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL");
    $stmt->execute([$athleteId]);
    echo json_encode(['ok' => true, 'unread_count' => (int)$stmt->fetchColumn()]);
    exit;
}

if ($action === 'thread' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    $pdo->prepare("UPDATE admin_athlete_chat_messages SET athlete_read_at = NOW() WHERE athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL")
        ->execute([$athleteId]);

    $rows = fetchChatMessagesForUser($pdo, 'admin_athlete_chat_messages', ['athlete_id' => $athleteId], 'athlete', $athleteId);
    $messages = array_map('chatMessageToArray', $rows);

    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

if ($action === 'poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    $sinceDeletionId = max(0, (int)($_GET['since_deletion_id'] ?? 0));
    if (($_GET['mark_read'] ?? '') !== '0') {
        $pdo->prepare("UPDATE admin_athlete_chat_messages SET athlete_read_at = NOW() WHERE athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL")
            ->execute([$athleteId]);
    }
    session_write_close();

    $rows = fetchChatMessagesForUser($pdo, 'admin_athlete_chat_messages', ['athlete_id' => $athleteId], 'athlete', $athleteId, $sinceId, $sinceDeletionId, 100);
    $messages = array_map('chatMessageToArray', $rows);

    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    try {
        $result = deleteChatMessageForUser(
            $pdo,
            'admin_athlete_chat_messages',
            ['athlete_id' => $athleteId],
            max(0, (int)($_POST['message_id'] ?? 0)),
            (string)($_POST['scope'] ?? ''),
            'athlete',
            $athleteId
        );
    } catch (ChatMessageDeletionException | InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    $response = ['ok' => true, 'scope' => $result['scope']];
    if ($result['scope'] === 'everyone') {
        $rows = fetchChatMessagesForUser($pdo, 'admin_athlete_chat_messages', ['athlete_id' => $athleteId], 'athlete', $athleteId);
        foreach ($rows as $row) {
            if ((int)$row['id'] === (int)($_POST['message_id'] ?? 0)) {
                $response['message'] = chatMessageToArray($row);
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

    $pdo->prepare("INSERT INTO admin_athlete_chat_messages (athlete_id, sender, body, attachment_path, attachment_name, athlete_read_at) VALUES (?, 'athlete', ?, ?, ?, NOW())")
        ->execute([$athleteId, $body, $attachment['path'], $attachment['name']]);
    $newId = (int)$pdo->lastInsertId();
    session_write_close();

    $athlete = getCurrentAthlete();
    $athleteName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
    notifyAdminAboutNewAthleteChatMessage($athleteId, $newId, $athleteName !== '' ? $athleteName : 'sportovec', chatNotificationText($body, $attachment['name']));

    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action']);
