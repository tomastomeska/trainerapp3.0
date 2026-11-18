<?php

function pushUnreadChatCount(PDO $pdo, string $role, int $ownerId): int
{
    if (!in_array($role, ['coach', 'athlete'], true) || $ownerId < 1) {
        throw new InvalidArgumentException('Invalid push unread count owner.');
    }

    $sources = $role === 'coach'
        ? [
            ['coach_athlete_chat_messages', "coach_id = ? AND sender = 'athlete' AND coach_read_at IS NULL"],
            ['admin_coach_chat_messages', "coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL"],
        ]
        : [
            ['coach_athlete_chat_messages', "athlete_id = ? AND sender = 'coach' AND athlete_read_at IS NULL"],
            ['admin_athlete_chat_messages', "athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL"],
        ];

    $tableStmt = $pdo->query('SHOW TABLES');
    if ($tableStmt === false) {
        throw new RuntimeException('Chat table list is unavailable.');
    }
    $tables = $tableStmt->fetchAll(PDO::FETCH_COLUMN);
    $count = 0;
    foreach ($sources as [$table, $condition]) {
        if (!in_array($table, $tables, true)) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $condition);
        $stmt->execute([$ownerId]);
        $value = $stmt->fetchColumn();
        if ($value === false || !is_numeric($value)) {
            throw new RuntimeException('Unread chat count is unavailable.');
        }
        $count += (int)$value;
    }
    return $count;
}
