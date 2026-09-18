<?php
// api/coach_chat_admin.php – AJAX chat mezi přihlášeným trenérem a administrátorem
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$coachId = (int)getCurrentCoachId();
$pdo = getDB();

$chatTableCheck = $pdo->query("SHOW TABLES LIKE 'admin_coach_chat_messages'");
if ($chatTableCheck === false || !(bool)$chatTableCheck->fetchColumn()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_migrated']);
    exit;
}

function coachChatMessageToArray(array $m): array {
    return [
        'id' => (int)$m['id'],
        'sender' => (string)$m['sender'],
        'body' => (string)$m['body'],
        'attachment_url' => !empty($m['attachment_path']) ? BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path']) : null,
        'attachment_name' => $m['attachment_name'] ?? null,
        'created_at' => formatDateTime((string)$m['created_at']),
    ];
}

$action = (string)($_REQUEST['action'] ?? '');

if ($action === 'unread_count') {
    session_write_close();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_coach_chat_messages WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL");
    $stmt->execute([$coachId]);
    echo json_encode(['ok' => true, 'unread_count' => (int)$stmt->fetchColumn()]);
    exit;
}

if ($action === 'thread' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    $pdo->prepare("UPDATE admin_coach_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL")
        ->execute([$coachId]);

    $stmt = $pdo->prepare('SELECT * FROM admin_coach_chat_messages WHERE coach_id = ? ORDER BY created_at ASC, id ASC LIMIT 200');
    $stmt->execute([$coachId]);
    $messages = array_map('coachChatMessageToArray', $stmt->fetchAll());

    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

if ($action === 'poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    $pdo->prepare("UPDATE admin_coach_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL")
        ->execute([$coachId]);
    session_write_close();

    $stmt = $pdo->prepare('SELECT * FROM admin_coach_chat_messages WHERE coach_id = ? AND id > ? ORDER BY created_at ASC, id ASC LIMIT 100');
    $stmt->execute([$coachId, $sinceId]);
    $messages = array_map('coachChatMessageToArray', $stmt->fetchAll());

    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }

    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000, 'UTF-8');
    if ($body === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'empty_body']);
        exit;
    }

    $pdo->prepare("INSERT INTO admin_coach_chat_messages (coach_id, sender, body, coach_read_at) VALUES (?, 'coach', ?, NOW())")
        ->execute([$coachId, $body]);
    $newId = (int)$pdo->lastInsertId();
    session_write_close();

    $coach = getCurrentCoach();
    $coachName = trim((string)($coach['name'] ?? '')) !== '' ? (string)$coach['name'] : trim((string)($coach['username'] ?? 'trenér'));
    notifyAdminAboutNewCoachChatMessage($coachId, $newId, $coachName, $body);

    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action']);
