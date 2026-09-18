<?php
// api/athlete_coach_chat.php – AJAX chat mezi přihlášeným sportovcem a jeho trenérem
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!athleteIsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$athleteId = (int)getCurrentAthleteId();
$athlete = getCurrentAthlete();
$coachId = (int)($athlete['coach_id'] ?? 0);
$pdo = getDB();

if ($coachId <= 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'coach_not_found']);
    exit;
}

$chatTableCheck = $pdo->query("SHOW TABLES LIKE 'coach_athlete_chat_messages'");
if ($chatTableCheck === false || !(bool)$chatTableCheck->fetchColumn()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_migrated']);
    exit;
}

function athleteCoachChatMessageToArray(array $m): array {
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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE athlete_id = ? AND sender = 'coach' AND athlete_read_at IS NULL");
    $stmt->execute([$athleteId]);
    echo json_encode(['ok' => true, 'unread_count' => (int)$stmt->fetchColumn()]);
    exit;
}

if ($action === 'thread' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    $pdo->prepare("UPDATE coach_athlete_chat_messages SET athlete_read_at = NOW() WHERE athlete_id = ? AND sender = 'coach' AND athlete_read_at IS NULL")
        ->execute([$athleteId]);

    $stmt = $pdo->prepare('SELECT * FROM coach_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at ASC, id ASC LIMIT 200');
    $stmt->execute([$athleteId]);
    $messages = array_map('athleteCoachChatMessageToArray', $stmt->fetchAll());

    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

if ($action === 'poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    $pdo->prepare("UPDATE coach_athlete_chat_messages SET athlete_read_at = NOW() WHERE athlete_id = ? AND sender = 'coach' AND athlete_read_at IS NULL")
        ->execute([$athleteId]);
    session_write_close();

    $stmt = $pdo->prepare('SELECT * FROM coach_athlete_chat_messages WHERE athlete_id = ? AND id > ? ORDER BY created_at ASC, id ASC LIMIT 100');
    $stmt->execute([$athleteId, $sinceId]);
    $messages = array_map('athleteCoachChatMessageToArray', $stmt->fetchAll());

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

    $pdo->prepare("INSERT INTO coach_athlete_chat_messages (coach_id, athlete_id, sender, body, athlete_read_at) VALUES (?, ?, 'athlete', ?, NOW())")
        ->execute([$coachId, $athleteId, $body]);
    $newId = (int)$pdo->lastInsertId();
    session_write_close();

    $athleteName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
    $coachStmt = $pdo->prepare('SELECT name, username, email FROM coaches WHERE id = ? LIMIT 1');
    $coachStmt->execute([$coachId]);
    $coach = $coachStmt->fetch();
    if ($coach && !empty($coach['email'])) {
        $coachName = ($coach['name'] ?? '') !== '' ? (string)$coach['name'] : (string)($coach['username'] ?? 'trenér');
        notifyCoachAboutNewAthleteChatMessage($coachId, $athleteId, $newId, (string)$coach['email'], $coachName, $athleteName !== '' ? $athleteName : 'sportovec', $body);
    }

    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action']);
