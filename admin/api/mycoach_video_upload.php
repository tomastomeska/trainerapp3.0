<?php
require_once __DIR__ . '/../../includes/admin_auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdminLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nepřihlášen.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Neplatná metoda.']);
    exit;
}

if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Neplatný CSRF token.']);
    exit;
}

if (empty($_FILES['video']) || !is_array($_FILES['video'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Soubor nebyl přijat.']);
    exit;
}

$file = $_FILES['video'];
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    $msg = match ($uploadError) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Soubor překračuje povolenou velikost (zkontroluj upload_max_filesize v php.ini).',
        UPLOAD_ERR_PARTIAL => 'Soubor byl nahrán jen částečně.',
        UPLOAD_ERR_NO_FILE  => 'Nebyl vybrán soubor.',
        default             => 'Upload selhal (kód ' . $uploadError . ').',
    };
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$tmpPath = (string)($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Dočasný soubor je neplatný.']);
    exit;
}

// Max 800 MB – velká videa jsou běžná
$maxBytes = 800 * 1024 * 1024;
$fileSize = (int)($file['size'] ?? 0);
if ($fileSize <= 0 || $fileSize > $maxBytes) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Soubor musí být větší než 0 a max 800 MB.']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($tmpPath);

$allowed = [
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/ogg'       => 'ogv',
    'video/quicktime' => 'mov',
    'video/x-msvideo' => 'avi',
    'video/x-matroska'=> 'mkv',
];

if (!isset($allowed[$mime])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Nepodporovaný formát. Povoleno: MP4, WebM, MOV, AVI, MKV.']);
    exit;
}

$targetDir = dirname(__DIR__, 2) . '/uploads/mycoach_app/videos/';
if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Nepodařilo se vytvořit cílovou složku.']);
    exit;
}

$ext      = $allowed[$mime];
$fileName = bin2hex(random_bytes(16)) . '.' . $ext;
$targetPath = $targetDir . $fileName;

if (!move_uploaded_file($tmpPath, $targetPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Soubor se nepodařilo uložit.']);
    exit;
}

$relativePath = 'uploads/mycoach_app/videos/' . $fileName;

echo json_encode([
    'success' => true,
    'path'    => $relativePath,
    'mime'    => $mime,
    'size'    => $fileSize,
    'name'    => $fileName,
]);
