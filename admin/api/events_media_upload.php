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

$mediaType = strtolower(trim((string)($_POST['media_type'] ?? '')));
if (!in_array($mediaType, ['image', 'video'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Neplatný typ média.']);
    exit;
}

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Soubor nebyl přijat.']);
    exit;
}

$file = $_FILES['file'];
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    $errorMessage = 'Upload selhal.';
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        $errorMessage = 'Soubor je příliš velký.';
    } elseif ($uploadError === UPLOAD_ERR_NO_FILE) {
        $errorMessage = 'Nebyl vybrán soubor.';
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $errorMessage]);
    exit;
}

$tmpPath = (string)($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Dočasný soubor je neplatný.']);
    exit;
}

$maxImageBytes = 12 * 1024 * 1024;
$maxVideoBytes = 120 * 1024 * 1024;
$fileSize = (int)($file['size'] ?? 0);
$maxBytes = ($mediaType === 'image') ? $maxImageBytes : $maxVideoBytes;
if ($fileSize <= 0 || $fileSize > $maxBytes) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ($mediaType === 'image')
            ? 'Obrázek je příliš velký (max 12 MB).'
            : 'Video je příliš velké (max 120 MB).',
    ]);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmpPath);

$imageAllowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

$videoAllowed = [
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'video/ogg' => 'ogv',
    'video/quicktime' => 'mov',
];

$allowed = ($mediaType === 'image') ? $imageAllowed : $videoAllowed;
if (!isset($allowed[$mime])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => ($mediaType === 'image')
            ? 'Nepodporovaný formát obrázku.'
            : 'Nepodporovaný formát videa.',
    ]);
    exit;
}

$subDir = ($mediaType === 'image') ? 'events/images' : 'events/videos';
$targetDir = dirname(__DIR__, 2) . '/uploads/' . $subDir . '/';
if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Nepodařilo se vytvořit cílovou složku.']);
    exit;
}

$extension = $allowed[$mime];
$fileName = bin2hex(random_bytes(16)) . '.' . $extension;
$targetPath = $targetDir . $fileName;

if (!move_uploaded_file($tmpPath, $targetPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Soubor se nepodařilo uložit.']);
    exit;
}

$baseUrl = (defined('BASE_URL') && BASE_URL !== '') ? BASE_URL : '';
$fileUrl = $baseUrl . '/uploads/' . $subDir . '/' . rawurlencode($fileName);

echo json_encode([
    'success' => true,
    'url' => $fileUrl,
    'mime' => $mime,
    'type' => $mediaType,
    'size' => $fileSize,
    'name' => $fileName,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
