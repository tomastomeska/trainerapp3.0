<?php
// api/coach_athlete_chat.php – AJAX chat mezi přihlášeným trenérem a jedním jeho sportovcem
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/push_chat_notifications.php';
require_once __DIR__ . '/../includes/chat_attachments.php';
require_once __DIR__ . '/../includes/chat_message_deletions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$coachId = (int)getCurrentCoachId();
$pdo = getDB();
ensureChatMessageDeletionTable($pdo);

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
        'attachment_type' => !empty($m['attachment_name']) ? chatAttachmentMimeType((string)$m['attachment_name']) : null,
        'read_at' => $m['sender'] === 'coach' ? ($m['athlete_read_at'] ?? null) : ($m['coach_read_at'] ?? null),
        'created_at' => formatDateTime((string)$m['created_at']),
        'deleted_for_everyone' => !empty($m['deleted_for_everyone']),
        'deleted_event_id' => (int)($m['deleted_event_id'] ?? 0),
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
                (SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = a.id AND sender = 'athlete' AND coach_read_at IS NULL) AS unread_count
         FROM athletes a
         WHERE a.coach_id = ?
         ORDER BY a.first_name ASC, a.last_name ASC"
    );
    $listStmt->execute([$coachId, $coachId]);
    $athletes = array_map(function (array $row) use ($pdo, $coachId): array {
        $preview = fetchChatMessagePreview($pdo, 'coach_athlete_chat_messages', ['coach_id' => $coachId, 'athlete_id' => (int)$row['id']], 'coach', $coachId);
        return [
            'id' => (int)$row['id'],
            'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
            'last_body' => $preview['body'],
            'last_at' => $preview['created_at'] ? formatDateTime((string)$preview['created_at']) : null,
            'last_at_raw' => $preview['created_at'],
            'unread_count' => (int)$row['unread_count'],
        ];
    }, $listStmt->fetchAll());
    usort($athletes, static function (array $a, array $b): int {
        $aAt = $a['last_at_raw'] ?? '';
        $bAt = $b['last_at_raw'] ?? '';
        if ($aAt === $bAt) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $aAt > $bAt ? -1 : 1;
    });
    foreach ($athletes as &$athleteItem) {
        unset($athleteItem['last_at_raw']);
    }
    unset($athleteItem);

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

    $rows = fetchChatMessagesForUser($pdo, 'coach_athlete_chat_messages', ['coach_id' => $coachId, 'athlete_id' => $athleteId], 'coach', $coachId);
    $messages = array_map('coachAthleteChatMessageToArray', $rows);

    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

if ($action === 'poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    $sinceDeletionId = max(0, (int)($_GET['since_deletion_id'] ?? 0));
    $pdo->prepare("UPDATE coach_athlete_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND athlete_id = ? AND sender = 'athlete' AND coach_read_at IS NULL")
        ->execute([$coachId, $athleteId]);
    session_write_close();

    $rows = fetchChatMessagesForUser($pdo, 'coach_athlete_chat_messages', ['coach_id' => $coachId, 'athlete_id' => $athleteId], 'coach', $coachId, $sinceId, $sinceDeletionId, 100);
    $messages = array_map('coachAthleteChatMessageToArray', $rows);

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
            'coach_athlete_chat_messages',
            ['coach_id' => $coachId, 'athlete_id' => $athleteId],
            max(0, (int)($_POST['message_id'] ?? 0)),
            (string)($_POST['scope'] ?? ''),
            'coach',
            $coachId
        );
    } catch (ChatMessageDeletionException | InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $response = ['ok' => true, 'scope' => $result['scope']];
    if ($result['scope'] === 'everyone') {
        $rows = fetchChatMessagesForUser($pdo, 'coach_athlete_chat_messages', ['coach_id' => $coachId, 'athlete_id' => $athleteId], 'coach', $coachId);
        foreach ($rows as $row) {
            if ((int)$row['id'] === (int)($_POST['message_id'] ?? 0)) {
                $response['message'] = coachAthleteChatMessageToArray($row);
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

    $pdo->prepare("INSERT INTO coach_athlete_chat_messages (coach_id, athlete_id, sender, body, attachment_path, attachment_name, coach_read_at) VALUES (?, ?, 'coach', ?, ?, ?, NOW())")
        ->execute([$coachId, $athleteId, $body, $attachment['path'], $attachment['name']]);
    $newId = (int)$pdo->lastInsertId();
    pushNotifyCoachAthleteChatMessage($pdo, $newId);
    session_write_close();

    $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
    $coach = getCurrentCoach();
    $coachName = trim((string)($coach['name'] ?? '')) !== '' ? (string)$coach['name'] : trim((string)($coach['username'] ?? 'trenér'));
    if (!empty($athlete['email'])) {
        notifyAthleteAboutNewCoachChatMessage($coachId, $athleteId, $newId, (string)$athlete['email'], $athleteName, $coachName, chatNotificationText($body, $attachment['name']));
    }

    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action']);
