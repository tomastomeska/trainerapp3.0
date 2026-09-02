<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$fileId = intParam($_GET, 'id');
$pdo = getDB();
$fileStmt = $pdo->prepare('SELECT * FROM athlete_files WHERE id = ? LIMIT 1');
$fileStmt->execute([$fileId]);
$file = $fileStmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('Soubor nebyl nalezen.');
}

$canDownload = false;
if (!empty($_SESSION['athlete_id']) && (int)$_SESSION['athlete_id'] === (int)$file['athlete_id']) {
    requireAthleteLogin();
    $canDownload = true;
} elseif (!empty($_SESSION['coach_id']) && (int)$_SESSION['coach_id'] === (int)$file['coach_id']) {
    requireLogin();
    $canDownload = (int)$file['shared_with_coach'] === 1;
}

if (!$canDownload) {
    http_response_code(403);
    exit('K tomuto souboru nemáte přístup.');
}

$path = __DIR__ . '/uploads/athlete_files/athlete_' . (int)$file['athlete_id'] . '/' . $file['file_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Soubor není dostupný.');
}

header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;