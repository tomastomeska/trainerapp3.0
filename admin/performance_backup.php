<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/performance_deployment.php';
requireAdminLogin();
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Zálohu lze vytvořit pouze potvrzeným formulářem v administraci Kalkulaček.');
}
if (!verifyCsrf(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
    http_response_code(403);
    exit('Neplatný bezpečnostní token.');
}
unset($_SESSION['performance_backup']);
$file = tmpfile();
if ($file === false) {
    error_log('performance backup: tmpfile unavailable');
    http_response_code(503);
    exit('Nelze vytvořit privátní dočasný soubor zálohy. Migrace není povolena.');
}
try {
    $receipt = performanceDeploymentBackup(getDB(), $file);
    $_SESSION['performance_backup'] = $receipt;
    session_write_close();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="trainerapp_performance_before_migration_' . gmdate('Ymd_His') . '.sql"');
    header('Content-Length: ' . $receipt['bytes']);
    header('X-Content-Type-Options: nosniff');
    header('X-Backup-SHA256: ' . $receipt['sha256']);
    fpassthru($file);
} catch (Throwable $e) {
    error_log('performance backup: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Zálohu se nepodařilo dokončit. Migraci nespouštějte. Ověřte InnoDB, přímá práva SELECT a TRIGGER, nepřítomnost triggerů a volné místo. Binární rozšíření tabulek vyžaduje zálohu správce databáze. Podrobnost je v chybovém logu.';
} finally {
    fclose($file);
}
