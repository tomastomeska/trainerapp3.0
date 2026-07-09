<?php
// ============================================================
// cron_calendar_digest.php – digest e-maily z kalendáře trenérům
// ============================================================
// Volání přes cron (CLI):
//   php /cesta/k/projektu/cron_calendar_digest.php
//
// Volání přes URL (cron manager hostingu):
//   https://example.com/cron_calendar_digest.php?secret=TOKEN
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

$results = processCoachCalendarDigestNotifications();

if ($isCli) {
    $total = count($results);
    $sent = count(array_filter($results, static function ($r) {
        return !empty($r['sent']);
    }));

    echo '=== Calendar digest notifications: ' . date('Y-m-d H:i:s') . " ===\n";
    echo "Celkem zpracovano: {$total}, odeslano: {$sent}, chyby: " . ($total - $sent) . "\n\n";

    foreach ($results as $r) {
        $icon = !empty($r['sent']) ? '[OK]' : '[CHYBA]';
        $type = ($r['type'] ?? '') === 'weekly_next_week' ? 'TYDENNI PREHLED' : 'ZITREJSI PREHLED';
        $coachEmail = (string)($r['coach_email'] ?? '');
        $digestDate = (string)($r['digest_date'] ?? '');
        $eventsCount = (int)($r['events_count'] ?? 0);

        echo "{$icon} [{$type}] {$coachEmail} | {$digestDate} | treninku: {$eventsCount}\n";
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
