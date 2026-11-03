<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once __DIR__ . '/../includes/performance_schema.php';
$options = getopt('', ['host:', 'port:', 'database:', 'user:', 'apply']);
foreach (['host', 'database', 'user'] as $name) {
    if (empty($options[$name]) || !is_string($options[$name])) {
        fwrite(STDERR, "Usage: php scripts\\migrate_performance.php --host=127.0.0.1 --port=3306 --database=NAME --user=USER [--apply]\nPassword: TRAINERAPP_PERFORMANCE_DB_PASS environment variable. Without --apply: read-only audit.\n");
        exit(2);
    }
}
$port = (string)($options['port'] ?? '3306');
if (!preg_match('/^[a-zA-Z0-9_]+$/', $options['database']) || preg_match('/[;\x00-\x20]/', $options['host']) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
    fwrite(STDERR, "Invalid host, database name or port (1-65535).\n");
    exit(2);
}
try {
    $pdo = new PDO('mysql:host=' . $options['host'] . ';port=' . ($options['port'] ?? '3306') . ';dbname=' . $options['database'] . ';charset=utf8mb4', $options['user'], (string)getenv('TRAINERAPP_PERFORMANCE_DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $check = performanceSchemaCheck($pdo);
    echo 'Server: ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
    echo 'Prerequisites: ' . ($check['missing'] ? implode(', ', $check['missing']) : 'OK') . "\n";
    echo 'Module: ' . (performanceSchemaReady($pdo) ? 'ready' : 'migration required') . "\n";
    if ($check['missing']) exit(1);
    if (array_key_exists('apply', $options)) {
        performanceMigrate($pdo);
        echo "Migration complete. Existing feature settings were preserved; enable the module in calculator administration when ready.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
