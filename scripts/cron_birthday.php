<?php
// ============================================================
// cron_birthday.php – automatické narozeninové notifikace
// ============================================================
// Volání přes cron (CLI):
//   php /cesta/k/projektu/cron_birthday.php
//
// Volání přes URL (nastavení URL v cron manageru hostingu):
//   https://reservio.online/cron_birthday.php?secret=TOKEN
//   TOKEN je zobrazen v admin panelu: Admin → E-mailové notifikace
// Doporučený čas spuštění: každý den v 6:00 ráno.
// ============================================================

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    // CLI: načti vše ručně (session není potřeba)
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/functions.php';
} else {
    // HTTP: autorizace přes secret token z app_settings
    require_once __DIR__ . '/includes/admin_auth.php';  // načte config, db, functions, spustí session

    $secret = getCronSecret();
    $provided = $_GET['secret'] ?? '';

    // Konstantní-časové porovnání (odolnost vůči timing attack)
    if (!hash_equals($secret, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Unauthorized – neplatný secret token. Zkopírujte URL z admin panelu.');
    }
}

$results = processBirthdayNotifications();

if ($isCli) {
    $total   = count($results);
    $sent    = count(array_filter($results, function ($r) { return $r['sent']; }));
    $skipped = $total - $sent;
    echo "=== Birthday notifications: " . date('Y-m-d H:i:s') . " ===\n";
    echo "Celkem zpracováno: {$total}, odesláno: {$sent}, chyby: {$skipped}\n\n";
    foreach ($results as $r) {
        $icon   = $r['sent'] ? '[OK]' : '[CHYBA]';
        $type   = $r['type'] === 'birthday' ? 'NAROZENINY dnes' : 'UPOZORNENI 4 dny';
        $age    = isset($r['age']) ? " ({$r['age']} let)" : '';
        echo "{$icon} [{$type}] {$r['athlete']}{$age} → {$r['coach_email']}\n";
    }
} else {
    header('Content-Type: application/json; charset=UTF-8');
    $sent = count(array_filter($results, function ($r) { return $r['sent']; }));
    echo json_encode([
        'processed_at' => date('c'),
        'total'        => count($results),
        'sent'         => $sent,
        'results'      => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
