<?php
$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/functions.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';

    $secret = getCronSecret();
    $provided = (string)($_GET['secret'] ?? '');
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Unauthorized - neplatny secret token.');
    }
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    ensureSchemaUpgrades($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `mycoach_goal_preferences` (
            `user_id` BIGINT UNSIGNED NOT NULL,
            `primary_goal_id` BIGINT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`),
            KEY `idx_mycoach_goal_pref_goal` (`primary_goal_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        'DELETE gp
         FROM mycoach_goal_preferences gp
         LEFT JOIN mycoach_users mu ON mu.id = gp.user_id
         WHERE mu.id IS NULL'
    );

    $pdo->exec(
        'UPDATE mycoach_goal_preferences gp
         LEFT JOIN mycoach_goals gg ON gg.id = gp.primary_goal_id
         SET gp.primary_goal_id = NULL
         WHERE gp.primary_goal_id IS NOT NULL AND gg.id IS NULL'
    );

    $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

    $fkUserExistsStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = ?
           AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = ?'
    );

    $fkUserExistsStmt->execute([$schema, 'mycoach_goal_preferences', 'fk_mycoach_goal_pref_user', 'FOREIGN KEY']);
    if ((int)$fkUserExistsStmt->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE mycoach_goal_preferences
             ADD CONSTRAINT fk_mycoach_goal_pref_user
             FOREIGN KEY (user_id) REFERENCES mycoach_users(id)
             ON DELETE CASCADE'
        );
    }

    $fkGoalExistsStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = ?
           AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = ?'
    );

    $fkGoalExistsStmt->execute([$schema, 'mycoach_goal_preferences', 'fk_mycoach_goal_pref_goal', 'FOREIGN KEY']);
    if ((int)$fkGoalExistsStmt->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE mycoach_goal_preferences
             ADD CONSTRAINT fk_mycoach_goal_pref_goal
             FOREIGN KEY (primary_goal_id) REFERENCES mycoach_goals(id)
             ON DELETE SET NULL'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Migrace mycoach_goal_preferences probehla v poradku.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
