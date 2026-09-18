<?php
// api/coach_athlete_chat.php – AJAX chat mezi přihlášeným trenérem a jedním jeho sportovcem
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

$chatTableCheck = $pdo->query("SHOW TABLES LIKE 'coach_athlete_chat_messages'");
if ($chatTableCheck === false || !(bool)$chatTableCheck->fetchColumn()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'not_migrated']);
    exit;
}

function coachAthleteChatMessageToArray(array $m): array {
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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE coach_id = ? AND sender = 'athlete' AND coach_read_at IS NULL");
    $stmt->execute([$coachId]);
    echo json_encode(['ok' => true, 'unread_count' => (int)$stmt->fetchColumn()]);
    exit;
}

if ($action === 'list') {
    session_write_close();
    $listStmt = $pdo->prepare(
        "SELECT a.id, a.first_name, a.last_name,
                (SELECT body FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = a.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_body,
                (SELECT created_at FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = a.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_at,
                (SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = a.id AND sender = 'athlete' AND coach_read_at IS NULL) AS unread_count
         FROM athletes a
         WHERE a.coach_id = ?
         ORDER BY (last_at IS NULL) ASC, last_at DESC, a.first_name ASC, a.last_name ASC"
    );
    $listStmt->execute([$coachId, $coachId, $coachId, $coachId]);
    $athletes = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
            'last_body' => $row['last_body'],
            'last_at' => $row['last_at'] ? formatDateTime((string)$row['last_at']) : null,
            'unread_count' => (int)$row['unread_count'],
        ];
    }, $listStmt->fetchAll());

    echo json_encode(['ok' => true, 'athletes' => $athletes]);
    exit;
}

$athleteId = (int)($_REQUEST['athlete_id'] ?? 0);
$athleteCheckStmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
$athleteCheckStmt->execute([$athleteId, $coachId]);
$athlete = $athleteCheckStmt->fetch();
if (!$athlete) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'athlete_not_found']);
    exit;
}

if ($action === 'thread' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    $pdo->prepare("UPDATE coach_athlete_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND athlete_id = ? AND sender = 'athlete' AND coach_read_at IS NULL")
        ->execute([$coachId, $athleteId]);

    $stmt = $pdo->prepare('SELECT * FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = ? ORDER BY created_at ASC, id ASC LIMIT 200');
    $stmt->execute([$coachId, $athleteId]);
    $messages = array_map('coachAthleteChatMessageToArray', $stmt->fetchAll());

    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

if ($action === 'poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    $pdo->prepare("UPDATE coach_athlete_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND athlete_id = ? AND sender = 'athlete' AND coach_read_at IS NULL")
        ->execute([$coachId, $athleteId]);
    session_write_close();

    $stmt = $pdo->prepare('SELECT * FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = ? AND id > ? ORDER BY created_at ASC, id ASC LIMIT 100');
    $stmt->execute([$coachId, $athleteId, $sinceId]);
    $messages = array_map('coachAthleteChatMessageToArray', $stmt->fetchAll());

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

    $pdo->prepare("INSERT INTO coach_athlete_chat_messages (coach_id, athlete_id, sender, body, coach_read_at) VALUES (?, ?, 'coach', ?, NOW())")
        ->execute([$coachId, $athleteId, $body]);
    $newId = (int)$pdo->lastInsertId();
    session_write_close();

    $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
    $coach = getCurrentCoach();
    $coachName = trim((string)($coach['name'] ?? '')) !== '' ? (string)$coach['name'] : trim((string)($coach['username'] ?? 'trenér'));
    if (!empty($athlete['email'])) {
        notifyAthleteAboutNewCoachChatMessage($athleteId, $newId, (string)$athlete['email'], $athleteName, $coachName, $body);
    }

    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action']);
