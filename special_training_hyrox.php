<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

if (!function_exists('coachSpecialTrainingUnlocked')) {
    function coachSpecialTrainingUnlocked(PDO $pdo, int $coachId): bool {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'special_training_enabled'");
            if ($columnStmt === false || !$columnStmt->fetch()) {
                return false;
            }

            $valueStmt = $pdo->prepare('SELECT special_training_enabled FROM coaches WHERE id = ? LIMIT 1');
            $valueStmt->execute([$coachId]);
            return ((int)$valueStmt->fetchColumn()) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}

$pdo = getDB();
$coachId = (int)getCurrentCoachId();
if (!coachSpecialTrainingUnlocked($pdo, $coachId)) {
    flash('warning', 'Events jsou pro váš účet zatím uzamčené.');
    redirect(BASE_URL . '/dashboard.php');
}
redirect(BASE_URL . '/special_training_event.php?event=hyrox');
