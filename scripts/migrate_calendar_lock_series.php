<?php
/**
 * Přidá vazbu opakovaných uzamčení na sérii.
 * Spuštění: php scripts/migrate_calendar_lock_series.php
 */
require_once __DIR__ . '/../config/config.php';

$pdo = getDB();
$column = $pdo->query("SHOW COLUMNS FROM coach_calendar_locks LIKE 'series_id'")->fetch();
if (!$column) {
    $pdo->exec("ALTER TABLE coach_calendar_locks ADD COLUMN series_id VARCHAR(64) NULL AFTER coach_id");
}

$index = $pdo->query("SHOW INDEX FROM coach_calendar_locks WHERE Key_name = 'idx_calendar_locks_series'")->fetch();
if (!$index) {
    $pdo->exec('ALTER TABLE coach_calendar_locks ADD KEY idx_calendar_locks_series (coach_id, series_id)');
}

echo "Calendar lock series migration completed.\n";