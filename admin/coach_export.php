<?php
// admin/coach_export.php – stáhne SQL zálohu dat trenéra
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/coach_account_lifecycle.php';

requireAdminLogin();

$coachId = intParam($_GET, 'id');
$pdo     = getDB();

// Ověřit, že trenér existuje
$backup = coachAccountBuildSqlBackup($pdo, $coachId);
$coach = $backup['coach'];
$sql = $backup['sql'];
$safeUsername = preg_replace('/[^a-z0-9_]/i', '_', (string)$coach['username']);
$filename = 'zaloha_trenera_' . $safeUsername . '_' . date('Ymd_His') . '.sql';

// ── Odeslat jako soubor ke stažení ───────────────────────────────────────────
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($sql));
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $sql;
exit;
