<?php
declare(strict_types=1);
require_once __DIR__ . '/performance_schema.php';

function performanceDeploymentTarget(PDO $pdo): array {
    $row = $pdo->query('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port')->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['db']) throw new RuntimeException('Není vybraná cílová databáze.');
    return ['database' => $row['db'], 'fingerprint' => hash('sha256', $row['host'] . ':' . $row['port'] . ':' . $row['db'])];
}

function performanceDeploymentTables(): array {
    return [
        'app_settings', 'mycoach_daily_questionnaires', 'performance_settings', 'performance_results',
        'athlete_sweat_tests', 'athlete_hyrox_attempts', 'athlete_hyrox_splits', 'performance_activity_links',
    ];
}

/** @param resource $stream */
function performanceBackupWrite($stream, string $text): void {
    $length = strlen($text);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($stream, substr($text, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('Zálohu se nepodařilo úplně zapsat. Migraci nespouštějte.');
        $offset += $written;
    }
}

function performanceBackupCanInspectTriggers(array $grants, string $database, string $table): bool {
    $scope = static fn(string $name): string => '`' . str_replace('`', '``', $name) . '`';
    $allowed = ['*.*', $scope($database) . '.*', $scope($database) . '.' . $scope($table)];
    $visible = false;
    foreach ($grants as $grant) {
        if (preg_match('/^REVOKE .*\\bTRIGGER\\b/i', $grant)) return false;
        if (!preg_match('/^GRANT (.+) ON (.+) TO /i', $grant, $matches)) continue;
        $grantScope = str_replace(['\\_', '\\%'], ['_', '%'], $matches[2]);
        if (in_array($grantScope, $allowed, true) && preg_match('/(?:^|,\\s*)(?:ALL PRIVILEGES|TRIGGER)(?:,|$)/i', $matches[1])) $visible = true;
    }
    return $visible;
}

/**
 * SQL snapshot of exactly the tables this migration can change.
 * The stream must be private (tmpfile), not a file in the document root.
 *
 * @param resource $stream
 */
function performanceDeploymentBackup(PDO $pdo, $stream): array {
    if (!is_resource($stream)) throw new InvalidArgumentException('Chybí soubor zálohy.');
    if ($pdo->inTransaction()) throw new RuntimeException('Zálohu nelze vytvářet uvnitř jiné transakce.');
    $target = performanceDeploymentTarget($pdo);
    $lock = performanceMigrationLock($pdo);
    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    try {
        $check = performanceSchemaCheck($pdo);
        if ($check['missing']) throw new RuntimeException('Nejprve doplňte původní schéma: ' . implode(', ', $check['missing']));
        $tables = performanceDeploymentTables();
        $definitions = [];
        $columns = [];
        $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (!isset($check['tables'][$table])) continue;
            if (!performanceBackupCanInspectTriggers($grants, $target['database'], $table)) throw new RuntimeException('Pro ověření úplnosti zálohy chybí přímé oprávnění TRIGGER: ' . $table . '. Použijte zálohu správce databáze a CLI migraci.');
            $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $engine->execute([$table]);
            if (strtoupper((string)$engine->fetchColumn()) !== 'INNODB') throw new RuntimeException('Konzistentní webová záloha vyžaduje InnoDB: ' . $table);
            $triggers = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?');
            $triggers->execute([$table]);
            if ((int)$triggers->fetchColumn() !== 0) throw new RuntimeException('Tabulka ' . $table . ' obsahuje triggery. Použijte úplnou zálohu správce databáze a CLI migraci.');
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            $definitions[$table] = $create[1];
            $fields = $pdo->query("SHOW FULL COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($fields as $field) {
                if (preg_match('/\b(?:VIRTUAL|STORED) GENERATED\b/i', $field['Extra'])) continue;
                if (preg_match('/^(?:binary|varbinary|tinyblob|blob|mediumblob|longblob|bit|geometry|point|linestring|polygon|multi|geomcollection)/i', $field['Type'])) throw new RuntimeException('Sloupec ' . $table . '.' . $field['Field'] . ' vyžaduje binární export správce databáze.');
                $columns[$table][] = $field['Field'];
            }
            if (empty($columns[$table])) throw new RuntimeException('Tabulka nemá exportovatelné sloupce: ' . $table);
        }
        performanceBackupWrite($stream, "-- TrainerApp performance migration rollback snapshot\n-- NOT a full database or uploads backup. Restore only with application writes stopped.\n-- Created UTC: " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET @performance_old_fk = @@FOREIGN_KEY_CHECKS;\nSET @performance_old_mode = @@SQL_MODE;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS = 0;\n");
        foreach (array_reverse($tables) as $table) performanceBackupWrite($stream, "DROP TABLE IF EXISTS `$table`;\n");
        foreach ($definitions as $sql) performanceBackupWrite($stream, $sql . ";\n");
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $counts = [];
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        foreach ($definitions as $table => $definition) {
            $names = implode(', ', array_map(static fn($v) => '`' . str_replace('`', '``', $v) . '`', $columns[$table]));
            $rows = $pdo->query("SELECT $names FROM `$table`");
            $counts[$table] = 0;
            try {
                while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                    $values = array_map(static fn($v) => $v === null ? 'NULL' : "CONVERT(X'" . bin2hex((string)$v) . "' USING utf8mb4)", $row);
                    performanceBackupWrite($stream, "INSERT INTO `$table` ($names) VALUES (" . implode(', ', $values) . ");\n");
                    $counts[$table]++;
                }
            } finally {
                $rows->closeCursor();
            }
        }
        $pdo->commit();
        performanceBackupWrite($stream, "SET FOREIGN_KEY_CHECKS = @performance_old_fk;\nSET SQL_MODE = @performance_old_mode;\n-- BACKUP COMPLETE\n");
        if (!fflush($stream) || !rewind($stream)) throw new RuntimeException('Zálohu nelze dokončit.');
        $hash = hash_init('sha256');
        if (hash_update_stream($hash, $stream) === false) throw new RuntimeException('Nelze ověřit soubor zálohy.');
        $size = ftell($stream);
        if ($size === false || $size === 0 || !rewind($stream)) throw new RuntimeException('Soubor zálohy je prázdný.');
        return $target + ['sha256' => hash_final($hash), 'bytes' => $size, 'rows' => $counts, 'created_at' => time()];
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        performanceMigrationUnlock($pdo, $lock);
    }
}

function performanceDeploymentAuthorize(PDO $pdo, array $receipt, array $input): void {
    $target = performanceDeploymentTarget($pdo);
    if (($receipt['fingerprint'] ?? '') !== $target['fingerprint'] || !is_int($receipt['created_at'] ?? null)
        || $receipt['created_at'] > time() || $receipt['created_at'] < time() - 1800 || empty($receipt['sha256'])) {
        throw new InvalidArgumentException('Nejprve stáhněte novou zálohu této databáze (platí 30 minut).');
    }
    if (($input['backup_saved'] ?? '') !== '1' || ($input['maintenance_confirmed'] ?? '') !== '1') throw new InvalidArgumentException('Potvrďte uložení zálohy a pozastavení zápisů do aplikace.');
    if (($input['database_confirmation'] ?? '') !== $target['database']) throw new InvalidArgumentException('Název potvrzené databáze neodpovídá aktuálnímu připojení.');
}
