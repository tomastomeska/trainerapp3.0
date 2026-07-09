<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAthleteLogin();

$athlete = getCurrentAthlete();
$coachId = (int)($athlete['coach_id'] ?? 0);
$agreementId = intParam($_GET, 'agreement_id');

if ($agreementId <= 0) {
    http_response_code(404);
    exit('Soubor nebyl nalezen.');
}

$pdo = getDB();
$stmt = $pdo->prepare(
    'SELECT id, attachment_path, attachment_name
     FROM coach_athlete_agreements
     WHERE id = ? AND coach_id = ? AND is_active = 1
     LIMIT 1'
);
$stmt->execute([$agreementId, $coachId]);
$agreement = $stmt->fetch();

if (!$agreement || empty($agreement['attachment_path']) || empty($agreement['attachment_name'])) {
    http_response_code(404);
    exit('Soubor nebyl nalezen.');
}

$storedFile = basename((string)$agreement['attachment_path']);
$filePath = __DIR__ . '/uploads/agreements/' . $storedFile;

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('Soubor nebyl nalezen.');
}

$fileName = (string)$agreement['attachment_name'];
$mimeType = 'application/octet-stream';
if (function_exists('mime_content_type')) {
    $detected = (string)@mime_content_type($filePath);
    if ($detected !== '') {
        $mimeType = $detected;
    }
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
header('Content-Length: ' . (string)filesize($filePath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($filePath);
exit;
