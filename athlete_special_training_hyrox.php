<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

if (!function_exists('athleteSpecialTrainingUnlocked')) {
    function athleteSpecialTrainingUnlocked(PDO $pdo, int $athleteId): bool {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'special_training_enabled'");
            if ($columnStmt === false || !$columnStmt->fetch()) {
                return false;
            }

            $valueStmt = $pdo->prepare('SELECT special_training_enabled FROM athletes WHERE id = ? LIMIT 1');
            $valueStmt->execute([$athleteId]);
            return ((int)$valueStmt->fetchColumn()) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}

$pdo = getDB();
$athleteId = (int)getCurrentAthleteId();
if (!athleteSpecialTrainingUnlocked($pdo, $athleteId)) {
    flash('warning', 'Events jsou pro váš účet zatím uzamčené.');
    redirect(BASE_URL . '/athlete_dashboard.php');
}
redirect(BASE_URL . '/athlete_special_training_event.php?event=hyrox');
