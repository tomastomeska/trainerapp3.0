<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/push_chat_notifications.php';
require_once __DIR__ . '/../../includes/chat_message_deletions.php';

$coach = mobileRequireCoach();
$pdo = getDB();
ensureChatMessageDeletionTable($pdo);

$action = (string)($_REQUEST['action'] ?? 'conversations');

if ($action === 'conversations') {
    $conversations = [];

    try {
        $lastAdmin = fetchChatMessagePreview($pdo, 'admin_coach_chat_messages', ['coach_id' => (int)$coach['id']], 'coach', (int)$coach['id']);

        $unreadStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM admin_coach_chat_messages
             WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL"
        );
        $unreadStmt->execute([$coach['id']]);
        $unreadCount = (int)$unreadStmt->fetchColumn();

        $conversations[] = [
            'id' => 'admin',
            'name' => 'Administrátor TrainerApp',
            'subtitle' => 'Systémová podpora a administrace',
            'icon' => '🛠️',
            'is_admin' => true,
            'last_message' => $lastAdmin['body'] !== null ? (string)$lastAdmin['body'] : 'Vítáme vás v aplikaci TrainerApp.',
            'last_time' => $lastAdmin['created_at'] ? date('H:i', strtotime((string)$lastAdmin['created_at'])) : '09:00',
            'unread_count' => $unreadCount,
        ];
    } catch (Throwable $e) {}

    try {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.first_name, a.last_name, a.email, a.photo,
                    (SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = a.id AND sender = 'athlete' AND coach_read_at IS NULL) AS unread_count
             FROM athletes a
             WHERE a.coach_id = ?
             ORDER BY a.first_name ASC, a.last_name ASC"
        );
        $stmt->execute([$coach['id'], $coach['id']]);

        foreach ($stmt->fetchAll() as $row) {
            $fullName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
            if ($fullName === '') $fullName = 'Sportovec';
            $preview = fetchChatMessagePreview($pdo, 'coach_athlete_chat_messages', ['coach_id' => (int)$coach['id'], 'athlete_id' => (int)$row['id']], 'coach', (int)$coach['id']);

            $conversations[] = [
                'id' => (string)$row['id'],
                'name' => $fullName,
                'subtitle' => !empty($row['email']) ? (string)$row['email'] : 'Sportovec',
                'icon' => '👤',
                'photo' => mobilePublicPhoto((string)($row['photo'] ?? ''), 'athletes'),
                'is_admin' => false,
                'last_message' => $preview['body'] !== null ? (string)$preview['body'] : 'Zatiaľ žádná zpráva',
                'last_time' => !empty($preview['created_at']) ? date('H:i', strtotime((string)$preview['created_at'])) : '',
                'unread_count' => (int)$row['unread_count'],
            ];
        }
    } catch (Throwable $e) {}

    mobileJson(['success' => true, 'conversations' => $conversations]);
}

if ($action === 'thread') {
    $convId = (string)($_REQUEST['conversation_id'] ?? 'admin');
    $messages = [];

    if ($convId === 'admin') {
        try {
            $unreadIds = [];
            $unreadStmt = $pdo->prepare("SELECT id FROM admin_coach_chat_messages WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL");
            $unreadStmt->execute([$coach['id']]);
            foreach ($unreadStmt->fetchAll(PDO::FETCH_COLUMN) as $unreadId) {
                $unreadIds[(int)$unreadId] = true;
            }
            $pdo->prepare("UPDATE admin_coach_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL")
                ->execute([$coach['id']]);

            $rows = fetchChatMessagesForUser($pdo, 'admin_coach_chat_messages', ['coach_id' => (int)$coach['id']], 'coach', (int)$coach['id']);

            foreach ($rows as $m) {
                $isMe = $m['sender'] === 'coach';
                $isRead = !empty($m['admin_read_at']);

                $messages[] = [
                    'id' => (string)$m['id'],
                    'sender_name' => $isMe ? 'Trenér (Vy)' : 'Administrátor',
                    'is_me' => $isMe,
                    'is_read' => $isRead,
                    'coach_unread' => isset($unreadIds[(int)$m['id']]),
                    'text' => (string)$m['body'],
                    'time' => date('H:i', strtotime((string)$m['created_at'])),
                ];
            }
        } catch (Throwable $e) {}
    } else {
        $athleteId = (int)$convId;
        try {
            $unreadIds = [];
            $unreadStmt = $pdo->prepare("SELECT id FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = ? AND sender = 'athlete' AND coach_read_at IS NULL");
            $unreadStmt->execute([$coach['id'], $athleteId]);
            foreach ($unreadStmt->fetchAll(PDO::FETCH_COLUMN) as $unreadId) {
                $unreadIds[(int)$unreadId] = true;
            }
            $pdo->prepare("UPDATE coach_athlete_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND athlete_id = ? AND sender = 'athlete' AND coach_read_at IS NULL")
                ->execute([$coach['id'], $athleteId]);

            $athleteStmt = $pdo->prepare('SELECT first_name, last_name FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
            $athleteStmt->execute([$athleteId, $coach['id']]);
            $athlete = $athleteStmt->fetch();
            $rows = $athlete
                ? fetchChatMessagesForUser($pdo, 'coach_athlete_chat_messages', ['coach_id' => (int)$coach['id'], 'athlete_id' => $athleteId], 'coach', (int)$coach['id'])
                : [];

            foreach ($rows as $m) {
                $isMe = $m['sender'] === 'coach';
                $athName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
                if ($athName === '') $athName = 'Sportovec';

                $isRead = !empty($m['athlete_read_at']);

                $messages[] = [
                    'id' => (string)$m['id'],
                    'sender_name' => $isMe ? 'Trenér (Vy)' : $athName,
                    'is_me' => $isMe,
                    'is_read' => $isRead,
                    'coach_unread' => isset($unreadIds[(int)$m['id']]),
                    'text' => (string)$m['body'],
                    'time' => date('H:i', strtotime((string)$m['created_at'])),
                ];
            }
        } catch (Throwable $e) {}
    }

    mobileJson(['success' => true, 'messages' => $messages]);
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = mobileInput();
    $convId = (string)($input['conversation_id'] ?? $_POST['conversation_id'] ?? 'admin');
    $messageId = max(0, (int)($input['message_id'] ?? $_POST['message_id'] ?? 0));
    $scope = (string)($input['scope'] ?? $_POST['scope'] ?? '');
    $table = $convId === 'admin' ? 'admin_coach_chat_messages' : 'coach_athlete_chat_messages';
    $threadFilters = $convId === 'admin'
        ? ['coach_id' => (int)$coach['id']]
        : ['coach_id' => (int)$coach['id'], 'athlete_id' => max(0, (int)$convId)];
    try {
        $result = deleteChatMessageForUser($pdo, $table, $threadFilters, $messageId, $scope, 'coach', (int)$coach['id']);
    } catch (ChatMessageDeletionException | InvalidArgumentException $e) {
        mobileJson(['success' => false, 'error' => $e->getMessage()], 400);
    }
    mobileJson(['success' => true, 'scope' => $result['scope'], 'warning' => !$result['attachment_removed'] ? 'Soubor se nepodařilo odstranit ze serveru.' : null]);
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = mobileInput();
    $convId = (string)($input['conversation_id'] ?? $_POST['conversation_id'] ?? 'admin');
    $body = trim((string)($input['body'] ?? $_POST['body'] ?? ''));

    if ($body === '') {
        mobileJson(['success' => false, 'error' => 'Není co odeslat.'], 422);
    }

    if ($convId === 'admin') {
        try {
            $stmt = $pdo->prepare("INSERT INTO admin_coach_chat_messages (coach_id, sender, body, coach_read_at, created_at) VALUES (?, 'coach', ?, NOW(), NOW())");
            $stmt->execute([$coach['id'], $body]);
            mobileJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            mobileJson(['success' => false, 'error' => 'Chyba odeslání: ' . $e->getMessage()], 500);
        }
    } else {
        $athleteId = (int)$convId;
        try {
            $stmt = $pdo->prepare("INSERT INTO coach_athlete_chat_messages (coach_id, athlete_id, sender, body, coach_read_at, created_at) VALUES (?, ?, 'coach', ?, NOW(), NOW())");
            $stmt->execute([$coach['id'], $athleteId, $body]);
            $newId = (int)$pdo->lastInsertId();
            pushNotifyCoachAthleteChatMessage($pdo, $newId);
            mobileJson(['success' => true, 'id' => $newId]);
        } catch (Throwable $e) {
            mobileJson(['success' => false, 'error' => 'Chyba odeslání: ' . $e->getMessage()], 500);
        }
    }
}
