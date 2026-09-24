<?php
// admin/api/chat.php – AJAX chat administrátora s trenéry i sportovci (společný hub)
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdminLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$pdo = getDB();

function adminChatMessageToArray(array $m): array {
    return [
        'id' => (int)$m['id'],
        'sender' => (string)$m['sender'],
        'body' => (string)$m['body'],
        'attachment_url' => !empty($m['attachment_path']) ? BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path']) : null,
        'attachment_name' => $m['attachment_name'] ?? null,
        'read_at' => $m['sender'] === 'admin'
            ? ($m['athlete_read_at'] ?? $m['coach_read_at'] ?? null)
            : ($m['admin_read_at'] ?? null),
        'created_at' => formatDateTime((string)$m['created_at']),
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
                    (SELECT body FROM admin_athlete_chat_messages WHERE athlete_id = a.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_body,
                    (SELECT created_at FROM admin_athlete_chat_messages WHERE athlete_id = a.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_at,
                    (SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE athlete_id = a.id AND sender = 'athlete' AND admin_read_at IS NULL) AS unread_count
             FROM athletes a
             WHERE a.login_enabled = 1"
        )->fetchAll();
        foreach ($rows as $r) {
            $items[] = [
                'type' => 'athlete',
                'id' => (int)$r['id'],
                'name' => trim((string)$r['first_name'] . ' ' . (string)$r['last_name']),
                'last_body' => $r['last_body'],
                'last_at' => $r['last_at'] ? formatDateTime((string)$r['last_at']) : null,
                'last_at_raw' => $r['last_at'],
                'unread_count' => (int)$r['unread_count'],
            ];
        }
    }

    if ($coachTableExists) {
        $rows = $pdo->query(
            "SELECT c.id, c.name, c.username,
                    (SELECT body FROM admin_coach_chat_messages WHERE coach_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_body,
                    (SELECT created_at FROM admin_coach_chat_messages WHERE coach_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_at,
                    (SELECT COUNT(*) FROM admin_coach_chat_messages WHERE coach_id = c.id AND sender = 'coach' AND admin_read_at IS NULL) AS unread_count
             FROM coaches c
             WHERE c.is_active = 1"
        )->fetchAll();
        foreach ($rows as $r) {
            $items[] = [
                'type' => 'coach',
                'id' => (int)$r['id'],
                'name' => ($r['name'] ?: $r['username']),
                'last_body' => $r['last_body'],
                'last_at' => $r['last_at'] ? formatDateTime((string)$r['last_at']) : null,
                'last_at_raw' => $r['last_at'],
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

if (in_array($action, ['thread', 'poll', 'send'], true)) {
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
        $stmt = $pdo->prepare('SELECT * FROM admin_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at ASC, id ASC LIMIT 200');
        $stmt->execute([$targetId]);
    } else {
        $pdo->prepare("UPDATE admin_coach_chat_messages SET admin_read_at = NOW() WHERE coach_id = ? AND sender = 'coach' AND admin_read_at IS NULL")->execute([$targetId]);
        $stmt = $pdo->prepare('SELECT * FROM admin_coach_chat_messages WHERE coach_id = ? ORDER BY created_at ASC, id ASC LIMIT 200');
        $stmt->execute([$targetId]);
    }
    echo json_encode(['ok' => true, 'messages' => array_map('adminChatMessageToArray', $stmt->fetchAll())]);
    exit;
}

if ($action === 'poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    if ($type === 'athlete') {
        $pdo->prepare("UPDATE admin_athlete_chat_messages SET admin_read_at = NOW() WHERE athlete_id = ? AND sender = 'athlete' AND admin_read_at IS NULL")->execute([$targetId]);
        $stmt = $pdo->prepare('SELECT * FROM admin_athlete_chat_messages WHERE athlete_id = ? AND id > ? ORDER BY created_at ASC, id ASC LIMIT 100');
        $stmt->execute([$targetId, $sinceId]);
    } else {
        $pdo->prepare("UPDATE admin_coach_chat_messages SET admin_read_at = NOW() WHERE coach_id = ? AND sender = 'coach' AND admin_read_at IS NULL")->execute([$targetId]);
        $stmt = $pdo->prepare('SELECT * FROM admin_coach_chat_messages WHERE coach_id = ? AND id > ? ORDER BY created_at ASC, id ASC LIMIT 100');
        $stmt->execute([$targetId, $sinceId]);
    }
    echo json_encode(['ok' => true, 'messages' => array_map('adminChatMessageToArray', $stmt->fetchAll())]);
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

    if ($type === 'athlete') {
        $athleteStmt = $pdo->prepare('SELECT first_name, last_name, email FROM athletes WHERE id = ? LIMIT 1');
        $athleteStmt->execute([$targetId]);
        $athlete = $athleteStmt->fetch();
        if (!$athlete) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found']);
            exit;
        }
        $pdo->prepare('INSERT INTO admin_athlete_chat_messages (athlete_id, sender, body, admin_read_at) VALUES (?, "admin", ?, NOW())')->execute([$targetId, $body]);
        $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
        if (!empty($athlete['email'])) {
            if (sendAthleteMessageNotificationEmail((string)$athlete['email'], $athleteName !== '' ? $athleteName : 'sportovče', 'Nová zpráva od administrátora', $body)) {
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
        $pdo->prepare('INSERT INTO admin_coach_chat_messages (coach_id, sender, body, admin_read_at) VALUES (?, "admin", ?, NOW())')->execute([$targetId, $body]);
        if (!empty($coach['email'])) {
            $coachName = ($coach['name'] ?? '') !== '' ? (string)$coach['name'] : (string)($coach['username'] ?? 'trenér');
            sendCoachChatMessageNotificationEmail((string)$coach['email'], $coachName, $body);
        }
    }

    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_action']);
