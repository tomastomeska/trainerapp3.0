<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/performance_deployment.php';
$options = getopt('', ['port:', 'database:']);
$port = (string)($options['port'] ?? '');
$database = (string)($options['database'] ?? '');
if (!ctype_digit($port) || !preg_match('/^trainerapp_performance_test_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Use a NEW isolated empty database: --port=3399 --database=trainerapp_performance_test_deploy\n");
    exit(2);
}
$dsn = "mysql:host=127.0.0.1;port=$port;dbname=$database;charset=utf8mb4";
$attributes = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, 'root', '', $attributes);
$passed = 0;
function dcheck(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $GLOBALS['passed']++;
}
function dreject(callable $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException | RuntimeException $e) { dcheck(true, $label); return; }
    throw new RuntimeException('FAIL: expected rejection: ' . $label);
}
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
dcheck(performanceBackupCanInspectTriggers(['GRANT ALL PRIVILEGES ON *.* TO `fixture`@`localhost`'], 'fixture', 'app_settings'), 'global trigger visibility');
dcheck(performanceBackupCanInspectTriggers(['GRANT SELECT, TRIGGER ON `fixture`.* TO `fixture`@`localhost`'], 'fixture', 'app_settings'), 'schema trigger visibility');
dcheck(!performanceBackupCanInspectTriggers(['GRANT SELECT ON `fixture`.* TO `fixture`@`localhost`'], 'fixture', 'app_settings'), 'SELECT alone cannot verify triggers');
dcheck(!performanceBackupCanInspectTriggers(['GRANT TRIGGER ON `other`.* TO `fixture`@`localhost`'], 'fixture', 'app_settings'), 'other schema grant insufficient');
dcheck(performanceBackupCanInspectTriggers(['GRANT TRIGGER ON `fixture\\_db`.* TO `fixture`@`localhost`'], 'fixture_db', 'app_settings'), 'escaped underscore in database grant');
dcheck((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() === 0, 'isolated DB must be empty');
foreach (explode(';', (string)file_get_contents(__DIR__ . '/performance_fixture.sql')) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
$special = "Příliš žluťoučký 🏋️\n apostrophe ' slash \\ ; nul \0";
$pdo->prepare('INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)')->execute(['fixture_special', $special]);
$pdo->prepare('INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)')->execute(['fixture_empty', '']);
$pdo->exec("UPDATE mycoach_daily_questionnaires SET workout_meta_json = '{\"note\":\"Český text\"}' WHERE id=1");
$before = $pdo->query('SELECT * FROM mycoach_daily_questionnaires ORDER BY id')->fetchAll();
$file = tmpfile();
if ($file === false) throw new RuntimeException('No temp file');
try {
    $receipt = performanceDeploymentBackup($pdo, $file);
    $sql = stream_get_contents($file);
    dcheck($receipt['database'] === $database && strlen($receipt['fingerprint']) === 64, 'target binding');
    dcheck($receipt['sha256'] === hash('sha256', $sql) && $receipt['bytes'] === strlen($sql), 'backup digest and byte count');
    dcheck($receipt['rows']['mycoach_daily_questionnaires'] === 2, 'all legacy questionnaire rows exported');
    dcheck(str_ends_with($sql, "-- BACKUP COMPLETE\n"), 'complete marker');
    dcheck(str_contains($sql, 'DROP TABLE IF EXISTS `performance_results`') && !str_contains($sql, 'DROP TABLE IF EXISTS `athletes`'), 'rollback removes newly added tables but never source profiles');
    dcheck(!performanceSchemaReady($pdo), 'backup performs no migration');
    dcheck(!$pdo->inTransaction() && $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY), 'connection state restored');
    $input = ['backup_saved' => '1', 'maintenance_confirmed' => '1', 'database_confirmation' => $database];
    performanceDeploymentAuthorize($pdo, $receipt, $input);
    dcheck(true, 'valid confirmation');
    dreject(fn() => performanceDeploymentAuthorize($pdo, [], $input), 'no backup no migration');
    dreject(fn() => performanceDeploymentAuthorize($pdo, ['created_at' => time() - 1801] + $receipt, $input), 'expired backup');
    dreject(fn() => performanceDeploymentAuthorize($pdo, ['fingerprint' => str_repeat('0', 64)] + $receipt, $input), 'wrong server or database');
    dreject(fn() => performanceDeploymentAuthorize($pdo, $receipt, ['database_confirmation' => 'other'] + $input), 'typed target mismatch');
    dreject(fn() => performanceDeploymentAuthorize($pdo, $receipt, ['backup_saved' => '0'] + $input), 'download must be acknowledged');
    dreject(fn() => performanceDeploymentAuthorize($pdo, $receipt, ['maintenance_confirmed' => '0'] + $input), 'maintenance must be acknowledged');
    $second = new PDO($dsn, 'root', '', $attributes);
    $lock = performanceMigrationLock($second);
    try {
        dreject(fn() => performanceMigrate($pdo), 'concurrent migration rejected');
        dreject(fn() => performanceDeploymentBackup($pdo, $file), 'backup and migration cannot overlap');
    } finally { performanceMigrationUnlock($second, $lock); }
    performanceMigrate($pdo);
    dcheck(performanceSchemaReady($pdo), 'migration after backup');
    performanceMigrate($pdo);
    dcheck(performanceSchemaReady($pdo), 'migration remains idempotent under lock');
    $pdo->exec("UPDATE mycoach_daily_questionnaires SET energy_score=9 WHERE id=1");
    $pdo->exec($sql);
    dcheck(!performanceSchemaReady($pdo), 'restore rolls schema back to pre-migration state');
    dcheck($pdo->query('SELECT * FROM mycoach_daily_questionnaires ORDER BY id')->fetchAll() === $before, 'restore recovers all legacy feedback exactly');
    $get = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = ?');
    $get->execute(['fixture_special']);
    dcheck($get->fetchColumn() === $special, 'restore preserves Unicode, NUL, slash, quotes, semicolon');
    $get->execute(['fixture_empty']);
    dcheck($get->fetchColumn() === '', 'restore preserves empty string not NULL');
    dcheck((int)$pdo->query('SELECT COUNT(*) FROM athletes')->fetchColumn() === 3 && (int)$pdo->query('SELECT COUNT(*) FROM training_sessions')->fetchColumn() === 5, 'restore never changes source profiles or workouts');
    performanceMigrate($pdo);
    $pdo->exec("INSERT INTO performance_settings (athlete_id,intelligence_enabled,share_performance,share_recovery) VALUES (6,1,1,0)");
    $moduleBefore = $pdo->query('SELECT * FROM performance_settings')->fetchAll();
    $secondFile = tmpfile();
    if ($secondFile === false) throw new RuntimeException('No second temp file');
    try {
        performanceDeploymentBackup($pdo, $secondFile);
        $sqlWithModule = stream_get_contents($secondFile);
        $pdo->exec('DELETE FROM performance_settings');
        $pdo->exec($sqlWithModule);
        dcheck(performanceSchemaReady($pdo) && $pdo->query('SELECT * FROM performance_settings')->fetchAll() === $moduleBefore, 'snapshot restores already migrated module as well');
    } finally { fclose($secondFile); }
    $pdo->exec("CREATE TRIGGER fixture_backup_trigger BEFORE UPDATE ON app_settings FOR EACH ROW SET NEW.`value` = NEW.`value`");
    dreject(fn() => performanceDeploymentBackup($pdo, $file), 'triggers require complete external backup');
    $pdo->exec('DROP TRIGGER fixture_backup_trigger');
    $pdo->exec('ALTER TABLE app_settings ADD fixture_blob BLOB NULL');
    dreject(fn() => performanceDeploymentBackup($pdo, $file), 'binary extensions require external backup');
    $pdo->exec('ALTER TABLE app_settings DROP COLUMN fixture_blob');
    $pdo->exec('ALTER TABLE app_settings ADD fixture_generated INT AS (CHAR_LENGTH(`key`)) STORED');
    $generatedFile = tmpfile();
    if ($generatedFile === false) throw new RuntimeException('No generated test file');
    try {
        performanceDeploymentBackup($pdo, $generatedFile);
        $generatedSql = stream_get_contents($generatedFile);
        $pdo->exec($generatedSql);
        dcheck((int)$pdo->query("SELECT fixture_generated FROM app_settings WHERE `key`='fixture_special'")->fetchColumn() === strlen('fixture_special'), 'restore excludes generated columns from INSERTs');
    } finally { fclose($generatedFile); }
    $pdo->beginTransaction();
    try {
        dreject(fn() => performanceMigrate($pdo), 'migration cannot implicitly commit caller transaction');
        dreject(fn() => performanceDeploymentBackup($pdo, $file), 'backup cannot replace caller transaction');
    } finally { $pdo->rollBack(); }
    $lock = performanceMigrationLock($second);
    performanceMigrationUnlock($second, $lock);
    dcheck(true, 'all failure paths release migration lock');
    echo "OK: $passed performance deployment assertions\n";
} finally {
    fclose($file);
}
