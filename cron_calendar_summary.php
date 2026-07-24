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

function acquireCronLock(string $lockKey): array {
    $safeKey = preg_replace('/[^a-z0-9_\-]/i', '_', $lockKey) ?: 'default';
    $lockPath = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'trainerapp_' . $safeKey . '.lock';
    $handle = @fopen($lockPath, 'c');
    if (!is_resource($handle)) {
        return ['acquired' => false, 'handle' => null, 'path' => $lockPath, 'reason' => 'lock_file_unavailable'];
    }

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return ['acquired' => false, 'handle' => null, 'path' => $lockPath, 'reason' => 'already_running'];
    }

    return ['acquired' => true, 'handle' => $handle, 'path' => $lockPath, 'reason' => 'acquired'];
}

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

$queueEnabled = defined('QUEUE_WORKERS_ENABLED') ? (bool)QUEUE_WORKERS_ENABLED : true;
if (isset($_GET['queue_enabled'])) {
    $queueEnabled = ((string)$_GET['queue_enabled'] !== '0');
}

$calendarWorkersEnabled = ((string)($_GET['calendar_workers_enabled'] ?? '1') !== '0');
$emailWorkerEnabled = ((string)($_GET['email_worker_enabled'] ?? '0') === '1');
$appleBootstrapEnabled = ((string)($_GET['apple_bootstrap_enabled'] ?? '0') === '1');

$emailLimitRaw = isset($_GET['email_limit']) ? (int)$_GET['email_limit'] : 40;
$emailLimit = max(1, min(80, $emailLimitRaw));

$googleLimitRaw = isset($_GET['google_limit']) ? (int)$_GET['google_limit'] : 8;
$googleLimit = max(1, min(12, $googleLimitRaw));

$appleLimitRaw = isset($_GET['apple_limit']) ? (int)$_GET['apple_limit'] : 8;
$appleLimit = max(1, min(12, $appleLimitRaw));

$appleBootstrapCoachLimitRaw = isset($_GET['apple_bootstrap_coach_limit']) ? (int)$_GET['apple_bootstrap_coach_limit'] : 8;
$appleBootstrapCoachLimit = max(1, min(20, $appleBootstrapCoachLimitRaw));

$appleBootstrapEventsLimitRaw = isset($_GET['apple_bootstrap_events_limit']) ? (int)$_GET['apple_bootstrap_events_limit'] : 300;
$appleBootstrapEventsLimit = max(1, min(250, $appleBootstrapEventsLimitRaw));

$lock = acquireCronLock('calendar_workers');
$lockAcquired = (bool)$lock['acquired'];
$lockReason = (string)$lock['reason'];

$summaryResults = [];
$emailResults = [];
$googleResults = [];
$appleBootstrap = ['coaches_scanned' => 0, 'jobs_enqueued' => 0];
$appleCoachResults = [];
$appleAthleteResults = [];

if ($lockAcquired && isset($lock['handle']) && is_resource($lock['handle'])) {
    register_shutdown_function(static function () use ($lock): void {
        if (isset($lock['handle']) && is_resource($lock['handle'])) {
            @flock($lock['handle'], LOCK_UN);
            @fclose($lock['handle']);
        }
    });
}

if ($lockAcquired) {
    $summaryResults = processCalendarSummaryNotifications();

    if ($queueEnabled) {
        if ($emailWorkerEnabled) {
            $emailResults = processEmailNotificationQueue($emailLimit);
        }

        if ($calendarWorkersEnabled) {
            $googleResults = processCoachGoogleCalendarSyncQueue($googleLimit);
            if ($appleBootstrapEnabled) {
                $appleBootstrap = bootstrapCoachAppleCaldavMissingEvents($appleBootstrapCoachLimit, $appleBootstrapEventsLimit);
            }
            $appleCoachResults = processCoachAppleCaldavSyncQueue($appleLimit);
            $appleAthleteResults = processAthleteAppleCaldavSyncQueue($appleLimit);
        }
    }
}

if ($isCli) {
    $total = count($summaryResults);
    $sent = count(array_filter($summaryResults, static function ($r) {
        return !empty($r['sent']);
    }));

    echo '=== Calendar summary notifications: ' . date('Y-m-d H:i:s') . " ===\n";
    echo "Celkem zpracovano: {$total}, odeslano: {$sent}, chyby: " . ($total - $sent) . "\n";
    echo "Queue worker: email=" . count($emailResults)
        . ", google=" . count($googleResults)
        . ", apple_coach=" . count($appleCoachResults)
        . ", apple_athlete=" . count($appleAthleteResults)
        . ", apple_bootstrap_jobs=" . (int)($appleBootstrap['jobs_enqueued'] ?? 0)
        . ", lock=" . $lockReason
        . "\n\n";

    foreach ($summaryResults as $row) {
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

    $sent = count(array_filter($summaryResults, static function ($r) {
        return !empty($r['sent']);
    }));

    echo json_encode([
        'processed_at' => date('c'),
        'total' => count($summaryResults),
        'sent' => $sent,
        'results' => $summaryResults,
        'queue' => [
            'enabled' => $queueEnabled,
            'calendar_workers_enabled' => $calendarWorkersEnabled,
            'email_worker_enabled' => $emailWorkerEnabled,
            'apple_bootstrap_enabled' => $appleBootstrapEnabled,
            'lock_acquired' => $lockAcquired,
            'lock_reason' => $lockReason,
            'lock_path' => (string)($lock['path'] ?? ''),
            'email_processed' => count($emailResults),
            'google_processed' => count($googleResults),
            'apple_bootstrap' => $appleBootstrap,
            'apple_coach_processed' => count($appleCoachResults),
            'apple_athlete_processed' => count($appleAthleteResults),
            'email_results' => $emailResults,
            'google_results' => $googleResults,
            'apple_coach_results' => $appleCoachResults,
            'apple_athlete_results' => $appleAthleteResults,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
