<?php
// ============================================================
// cron_calendar_summary.php – týdenní/měsíční kalendářové souhrny
// ============================================================
// Volání přes cron (CLI):
//   php /cesta/k/projektu/cron_calendar_summary.php
//
// Volání přes URL (cron manager hostingu):
//   https://example.com/cron_calendar_summary.php?secret=TOKEN
//
// Doporučené spuštění:
// - každou neděli ve 12:00 (týdenní přehled na další týden)
// - každý 28. den v měsíci ve 12:00 (měsíční přehled na další měsíc)
// ============================================================

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/functions.php';
} else {
    require_once __DIR__ . '/includes/admin_auth.php';

    $secret = getCronSecret();
    $provided = $_GET['secret'] ?? '';

    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Unauthorized - neplatny secret token.');
    }
}

$results = processCalendarSummaryNotifications();

if ($isCli) {
    $total = count($results);
    $sent = count(array_filter($results, static function ($r) {
        return !empty($r['sent']);
    }));

    echo '=== Calendar summary notifications: ' . date('Y-m-d H:i:s') . " ===\n";
    echo "Celkem zpracovano: {$total}, odeslano: {$sent}, chyby: " . ($total - $sent) . "\n\n";

    foreach ($results as $row) {
        $icon = !empty($row['sent']) ? '[OK]' : '[CHYBA]';
        $recipientType = (string)($row['recipient_type'] ?? '') === 'coach' ? 'TRENÉR' : 'SPORTOVEC';
        $digestType = (string)($row['digest_type'] ?? '') === 'monthly_next_month' ? 'MESICNI' : 'TYDENNI';
        $recipientEmail = (string)($row['recipient_email'] ?? '');
        $digestDate = (string)($row['digest_date'] ?? '');
        $eventsCount = (int)($row['events_count'] ?? 0);

        echo "{$icon} [{$recipientType}] [{$digestType}] {$recipientEmail} | {$digestDate} | udalosti: {$eventsCount}\n";
    }
} else {
    header('Content-Type: application/json; charset=UTF-8');

    $sent = count(array_filter($results, static function ($r) {
        return !empty($r['sent']);
    }));

    echo json_encode([
        'processed_at' => date('c'),
        'total' => count($results),
        'sent' => $sent,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
