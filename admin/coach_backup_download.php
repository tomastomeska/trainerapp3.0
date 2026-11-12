<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/coach_account_lifecycle.php';

requireAdminLogin();

$pdo = getDB();
coachAccountEnsureSchema($pdo);
$stmt = $pdo->prepare(
    'SELECT coach_username, backup_sql
     FROM coach_account_backups
     WHERE id = ? AND expires_at > NOW()
     LIMIT 1'
);
$stmt->execute([intParam($_GET, 'id')]);
$backup = $stmt->fetch();
if (!$backup) {
    flash('danger', 'Záloha nebyla nalezena nebo již vypršela její platnost.');
    redirect(BASE_URL . '/admin/coach_backups.php');
}

$safeUsername = preg_replace('/[^a-z0-9_]/i', '_', (string)$backup['coach_username']);
$filename = 'zaloha_trenera_' . $safeUsername . '_' . date('Ymd_His') . '.sql';
$sql = (string)$backup['backup_sql'];
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($sql));
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $sql;
exit;
