<?php

class ChatMessageDeletionException extends RuntimeException {}

function ensureChatMessageDeletionTable(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `chat_message_deletions` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `message_type` ENUM('coach_athlete','admin_athlete','admin_coach') NOT NULL,
            `message_id` INT NOT NULL,
            `scope` ENUM('me','everyone') NOT NULL,
            `actor_type` ENUM('admin','coach','athlete','all') NOT NULL,
            `actor_id` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_chat_message_deletion` (`message_type`, `message_id`, `scope`, `actor_type`, `actor_id`),
            KEY `idx_chat_message_deletion_poll` (`message_type`, `scope`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function chatMessageType(string $table): string {
    $types = [
        'coach_athlete_chat_messages' => 'coach_athlete',
        'admin_athlete_chat_messages' => 'admin_athlete',
        'admin_coach_chat_messages' => 'admin_coach',
    ];
    if (!isset($types[$table])) {
        throw new InvalidArgumentException('Neplatný typ konverzace.');
    }

    return $types[$table];
}

function fetchChatMessagesForUser(
    PDO $pdo,
    string $table,
    array $threadFilters,
    string $actorType,
    int $actorId,
    ?int $sinceId = null,
    int $sinceDeletionId = 0,
    int $limit = 200
): array {
    $messageType = chatMessageType($table);
    $allowedColumns = [
        'coach_id' => true,
        'athlete_id' => true,
    ];
    $where = [];
    $params = [$messageType];
    foreach ($threadFilters as $column => $value) {
        if (!isset($allowedColumns[$column])) {
            throw new InvalidArgumentException('Neplatný filtr konverzace.');
        }
        $where[] = 'm.' . $column . ' = ?';
        $params[] = (int)$value;
    }
    if ($sinceId !== null) {
        $where[] = '(m.id > ? OR COALESCE(global_deletion.id, 0) > ?)';
        $params[] = max(0, $sinceId);
        $params[] = max(0, $sinceDeletionId);
    }
    $where[] = "NOT EXISTS (
        SELECT 1 FROM chat_message_deletions own_deletion
        WHERE own_deletion.message_type = ?
          AND own_deletion.message_id = m.id
          AND own_deletion.scope = 'me'
          AND own_deletion.actor_type = ?
          AND own_deletion.actor_id = ?
    )";
    $params[] = $messageType;
    $params[] = $actorType;
    $params[] = $actorId;

    $limit = max(1, min(200, $limit));
    $sql = 'SELECT m.*, global_deletion.id AS deleted_event_id
            FROM `' . $table . '` m
            LEFT JOIN chat_message_deletions global_deletion
              ON global_deletion.message_type = ?
             AND global_deletion.message_id = m.id
             AND global_deletion.scope = "everyone"
             AND global_deletion.actor_type = "all"
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY m.created_at ASC, m.id ASC
            LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    foreach ($messages as &$message) {
        if ((int)($message['deleted_event_id'] ?? 0) > 0) {
            $message['deleted_for_everyone'] = true;
            $message['body'] = 'Tato zpráva byla odstraněna.';
            $message['attachment_path'] = null;
            $message['attachment_name'] = null;
        } else {
            $message['deleted_for_everyone'] = false;
        }
    }
    unset($message);

    return $messages;
}

function fetchChatMessagePreview(PDO $pdo, string $table, array $threadFilters, string $actorType, int $actorId): array {
    $messageType = chatMessageType($table);
    $allowedColumns = ['coach_id' => true, 'athlete_id' => true];
    $where = [];
    $params = [$messageType];
    foreach ($threadFilters as $column => $value) {
        if (!isset($allowedColumns[$column])) {
            throw new InvalidArgumentException('Neplatný filtr konverzace.');
        }
        $where[] = 'm.' . $column . ' = ?';
        $params[] = (int)$value;
    }
    $where[] = "NOT EXISTS (
        SELECT 1 FROM chat_message_deletions own_deletion
        WHERE own_deletion.message_type = ?
          AND own_deletion.message_id = m.id
          AND own_deletion.scope = 'me'
          AND own_deletion.actor_type = ?
          AND own_deletion.actor_id = ?
    )";
    array_push($params, $messageType, $actorType, $actorId);

    $stmt = $pdo->prepare('
        SELECT m.body, m.created_at, global_deletion.id AS deleted_event_id
        FROM `' . $table . '` m
        LEFT JOIN chat_message_deletions global_deletion
          ON global_deletion.message_type = ?
         AND global_deletion.message_id = m.id
         AND global_deletion.scope = "everyone"
         AND global_deletion.actor_type = "all"
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT 1
    ');
    $stmt->execute($params);
    $preview = $stmt->fetch();
    if (!$preview) {
        return ['body' => null, 'created_at' => null];
    }
    if ((int)($preview['deleted_event_id'] ?? 0) > 0) {
        $preview['body'] = 'Tato zpráva byla odstraněna.';
    }
    return ['body' => $preview['body'], 'created_at' => $preview['created_at']];
}

function deleteChatMessageForUser(
    PDO $pdo,
    string $table,
    array $threadFilters,
    int $messageId,
    string $scope,
    string $actorType,
    int $actorId
): array {
    ensureChatMessageDeletionTable($pdo);
    $messageType = chatMessageType($table);
    if (!in_array($scope, ['me', 'everyone'], true) || !in_array($actorType, ['admin', 'coach', 'athlete'], true)) {
        throw new InvalidArgumentException('Neplatný požadavek na odstranění zprávy.');
    }
    $allowedColumns = ['coach_id' => true, 'athlete_id' => true];
    $where = ['id = ?'];
    $params = [$messageId];
    foreach ($threadFilters as $column => $value) {
        if (!isset($allowedColumns[$column])) {
            throw new InvalidArgumentException('Neplatný filtr konverzace.');
        }
        $where[] = $column . ' = ?';
        $params[] = (int)$value;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT sender, attachment_path FROM `' . $table . '` WHERE ' . implode(' AND ', $where) . ' FOR UPDATE');
        $stmt->execute($params);
        $message = $stmt->fetch();
        if (!$message) {
            throw new ChatMessageDeletionException('Zprávu se nepodařilo najít.');
        }

        if ($scope === 'everyone') {
            if ((string)$message['sender'] !== $actorType) {
                throw new ChatMessageDeletionException('Pro všechny lze odstranit pouze vlastní zprávu.');
            }
            $deletionStmt = $pdo->prepare("
                INSERT IGNORE INTO chat_message_deletions (message_type, message_id, scope, actor_type, actor_id)
                VALUES (?, ?, 'everyone', 'all', 0)
            ");
            $deletionStmt->execute([$messageType, $messageId]);
            $cleanupColumns = [
                'coach_athlete_chat_messages' => ['coach_read_at', 'athlete_read_at'],
                'admin_athlete_chat_messages' => ['admin_read_at', 'athlete_read_at'],
                'admin_coach_chat_messages' => ['admin_read_at', 'coach_read_at'],
            ][$table];
            $setRead = implode(', ', array_map(static function (string $column): string {
                return '`' . $column . '` = COALESCE(`' . $column . '`, NOW())';
            }, $cleanupColumns));
            $pdo->prepare('UPDATE `' . $table . '` SET body = "", attachment_path = NULL, attachment_name = NULL, ' . $setRead . ' WHERE ' . implode(' AND ', $where))
                ->execute($params);
            $eventStmt = $pdo->prepare("
                SELECT id FROM chat_message_deletions
                WHERE message_type = ? AND message_id = ? AND scope = 'everyone' AND actor_type = 'all' AND actor_id = 0
                LIMIT 1
            ");
            $eventStmt->execute([$messageType, $messageId]);
            $eventId = (int)$eventStmt->fetchColumn();
            $pdo->commit();

            $attachmentRemoved = removeChatAttachmentFile($message['attachment_path'] ?? null);
            return [
                'scope' => 'everyone',
                'deleted_event_id' => $eventId,
                'attachment_removed' => $attachmentRemoved,
            ];
        }

        $pdo->prepare("
            INSERT IGNORE INTO chat_message_deletions (message_type, message_id, scope, actor_type, actor_id)
            VALUES (?, ?, 'me', ?, ?)
        ")->execute([$messageType, $messageId, $actorType, $actorId]);
        $readColumn = [
            'admin' => 'admin_read_at',
            'coach' => 'coach_read_at',
            'athlete' => 'athlete_read_at',
        ][$actorType];
        $pdo->prepare('UPDATE `' . $table . '` SET `' . $readColumn . '` = COALESCE(`' . $readColumn . '`, NOW()) WHERE ' . implode(' AND ', $where))
            ->execute($params);
        $pdo->commit();

        return ['scope' => 'me', 'attachment_removed' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function removeChatAttachmentFile(?string $fileName): bool {
    if ($fileName === null || $fileName === '') {
        return true;
    }
    if (basename($fileName) !== $fileName) {
        error_log('Chat attachment deletion refused an invalid stored path.');
        return false;
    }

    $path = dirname(__DIR__) . '/uploads/messages/' . $fileName;
    if (!is_file($path)) {
        return true;
    }
    if (!unlink($path)) {
        error_log('Chat attachment could not be removed: ' . $fileName);
        return false;
    }

    return true;
}
