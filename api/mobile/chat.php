<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$coach = mobileRequireCoach();
$pdo = getDB();

$action = (string)($_REQUEST['action'] ?? 'conversations');

if ($action === 'conversations') {
    $conversations = [];

    try {
        $stmt = $pdo->prepare(
            "SELECT body, created_at FROM admin_coach_chat_messages
             WHERE coach_id = ? ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$coach['id']]);
        $lastAdmin = $stmt->fetch();

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
            'last_message' => $lastAdmin ? (string)$lastAdmin['body'] : 'Vítáme vás v aplikaci TrainerApp.',
            'last_time' => $lastAdmin ? date('H:i', strtotime((string)$lastAdmin['created_at'])) : '09:00',
            'unread_count' => $unreadCount,
        ];
    } catch (Throwable $e) {}

    try {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.first_name, a.last_name, a.email, a.photo,
                    (SELECT body FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = a.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_body,
                    (SELECT created_at FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = a.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_at,
                    (SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = a.id AND sender = 'athlete' AND coach_read_at IS NULL) AS unread_count
             FROM athletes a
             WHERE a.coach_id = ?
             ORDER BY (last_at IS NULL) ASC, last_at DESC, a.first_name ASC, a.last_name ASC"
        );
        $stmt->execute([$coach['id'], $coach['id'], $coach['id'], $coach['id']]);

        foreach ($stmt->fetchAll() as $row) {
            $fullName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
            if ($fullName === '') $fullName = 'Sportovec';

            $conversations[] = [
                'id' => (string)$row['id'],
                'name' => $fullName,
                'subtitle' => !empty($row['email']) ? (string)$row['email'] : 'Sportovec',
                'icon' => '👤',
                'photo' => mobilePublicPhoto((string)($row['photo'] ?? ''), 'athletes'),
                'is_admin' => false,
                'last_message' => !empty($row['last_body']) ? (string)$row['last_body'] : 'Zatiaľ žádná zpráva',
                'last_time' => !empty($row['last_at']) ? date('H:i', strtotime((string)$row['last_at'])) : '',
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

            $stmt = $pdo->prepare("SELECT * FROM admin_coach_chat_messages WHERE coach_id = ? ORDER BY created_at ASC, id ASC LIMIT 200");
            $stmt->execute([$coach['id']]);

            foreach ($stmt->fetchAll() as $m) {
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

            $stmt = $pdo->prepare(
                'SELECT m.*, a.first_name, a.last_name
                 FROM coach_athlete_chat_messages m
                 JOIN athletes a ON a.id = m.athlete_id
                 WHERE m.coach_id = ? AND m.athlete_id = ?
                 ORDER BY m.created_at ASC, m.id ASC LIMIT 200'
            );
            $stmt->execute([$coach['id'], $athleteId]);

            foreach ($stmt->fetchAll() as $m) {
                $isMe = $m['sender'] === 'coach';
                $athName = trim((string)$m['first_name'] . ' ' . (string)$m['last_name']);
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
            mobileJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (Throwable $e) {
            mobileJson(['success' => false, 'error' => 'Chyba odeslání: ' . $e->getMessage()], 500);
        }
    }
}
