<?php
// ============================================================
// cron_errorlog_cleanup.php – retence logů app_event_log
// ============================================================
// CLI:
//   php /cesta/k/projektu/cron_errorlog_cleanup.php
//   php /cesta/k/projektu/cron_errorlog_cleanup.php --dry-run
//
// URL (cron manager hostingu):
//   https://example.com/cron_errorlog_cleanup.php?secret=TOKEN
//   https://example.com/cron_errorlog_cleanup.php?secret=TOKEN&dry_run=1
//
// Doporučené spuštění:
// - 1x denně (např. 03:20)
// ============================================================

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
} else {
    require_once __DIR__ . '/includes/admin_auth.php';

    $secret = getCronSecret();
    $provided = (string)($_GET['secret'] ?? '');

    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Unauthorized - neplatny secret token.');
    }
}

$dryRun = false;
if ($isCli) {
    global $argv;
    $dryRun = in_array('--dry-run', $argv ?? [], true);
} else {
    $dryRun = ((string)($_GET['dry_run'] ?? '0') === '1');
}

$daysErrors = 7;       // error + critical
$daysWarnings = 7;     // warning + info
$daysDbRetry = 7;      // db_connect_retry je technicky sum
$batchSize = 5000;

$pdo = getDB();

$countOlder = static function (PDO $pdo, string $where, int $days): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM app_event_log WHERE ' . $where . ' AND created_at < (NOW() - INTERVAL ? DAY)');
    $stmt->execute([$days]);
    return (int)$stmt->fetchColumn();
};

$deleteInBatches = static function (PDO $pdo, string $where, int $days, int $batchSize): int {
    $deleted = 0;
    while (true) {
        $stmt = $pdo->prepare('DELETE FROM app_event_log WHERE ' . $where . ' AND created_at < (NOW() - INTERVAL ? DAY) LIMIT ' . (int)$batchSize);
        $stmt->execute([$days]);
        $affected = (int)$stmt->rowCount();
        $deleted += $affected;
        if ($affected < $batchSize) {
            break;
        }
    }
    return $deleted;
};

$rules = [
    [
        'label' => 'db_connect_retry',
        'where' => 'event_type = "db_connect_retry"',
        'days' => $daysDbRetry,
    ],
    [
        'label' => 'warning_info',
        'where' => 'severity IN ("info", "warning") AND event_type <> "db_connect_retry"',
        'days' => $daysWarnings,
    ],
    [
        'label' => 'error_critical',
        'where' => 'severity IN ("error", "critical")',
        'days' => $daysErrors,
    ],
];

$results = [];
$totalAffected = 0;

foreach ($rules as $rule) {
    $wouldDelete = $countOlder($pdo, $rule['where'], (int)$rule['days']);
    $deleted = 0;

    if (!$dryRun && $wouldDelete > 0) {
        $deleted = $deleteInBatches($pdo, $rule['where'], (int)$rule['days'], $batchSize);
    }

    $affected = $dryRun ? $wouldDelete : $deleted;
    $totalAffected += $affected;

    $results[] = [
        'rule' => $rule['label'],
        'retention_days' => (int)$rule['days'],
        'affected_rows' => $affected,
    ];
}

$payload = [
    'processed_at' => date('c'),
    'dry_run' => $dryRun,
    'total_affected_rows' => $totalAffected,
    'results' => $results,
];

if ($isCli) {
    echo '=== Errorlog cleanup: ' . date('Y-m-d H:i:s') . " ===\n";
    echo $dryRun ? "REZIM: DRY-RUN\n" : "REZIM: DELETE\n";
    foreach ($results as $row) {
        echo '- ' . $row['rule'] . ' | retence: ' . $row['retention_days'] . ' dnu | radku: ' . $row['affected_rows'] . "\n";
    }
    echo 'Celkem ovlivneno: ' . $totalAffected . "\n";
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
