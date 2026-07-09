<?php
// video_stream.php - bezpecny stream videa s kontrolou opravneni
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$fileId = intParam($_GET, 'id');
if ($fileId <= 0) {
    http_response_code(404);
    exit;
}

$pdo = getDB();
$coachId = null;
$file = null;

if (isLoggedIn()) {
    $coachId = (int)getCurrentCoachId();
    $stmt = $pdo->prepare('SELECT * FROM video_files WHERE id = ? AND coach_id = ?');
    $stmt->execute([$fileId, $coachId]);
    $file = $stmt->fetch();
} elseif (athleteIsLoggedIn()) {
    $athleteId = (int)getCurrentAthleteId();

    $athleteStmt = $pdo->prepare('SELECT coach_id FROM athletes WHERE id = ?');
    $athleteStmt->execute([$athleteId]);
    $athlete = $athleteStmt->fetch();
    if (!$athlete) {
        http_response_code(403);
        exit;
    }

    $coachId = (int)$athlete['coach_id'];
    $stmt = $pdo->prepare(
        "SELECT vf.*
         FROM video_files vf
         WHERE vf.id = ?
           AND vf.coach_id = ?
           AND (
               vf.visibility = 'all_athletes'
               OR (
                   vf.visibility = 'specific_athletes'
                   AND EXISTS (
                       SELECT 1
                       FROM video_file_athletes vfa
                       WHERE vfa.file_id = vf.id
                         AND vfa.athlete_id = ?
                   )
               )
           )"
    );
    $stmt->execute([$fileId, $coachId, $athleteId]);
    $file = $stmt->fetch();
} else {
    http_response_code(403);
    exit;
}

if (!$file || !$coachId) {
    http_response_code(404);
    exit;
}

$filePath = (string)($file['file_path'] ?? '');
if ($filePath === '' || basename($filePath) !== $filePath) {
    http_response_code(404);
    exit;
}

$fullPath = __DIR__ . '/uploads/videos/coach_' . $coachId . '/' . $filePath;
if (!is_file($fullPath)) {
    http_response_code(404);
    exit;
}

$size = filesize($fullPath);
if ($size === false || $size < 0) {
    http_response_code(500);
    exit;
}

$mime = (string)($file['mime_type'] ?? '');
if ($mime === '' || stripos($mime, 'video/') !== 0) {
    $detected = mime_content_type($fullPath);
    $mime = (is_string($detected) && stripos($detected, 'video/') === 0) ? $detected : 'video/mp4';
}

$start = 0;
$end = $size - 1;
$statusCode = 200;

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
if (is_string($rangeHeader) && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $m)) {
    if ($m[1] !== '') {
        $start = (int)$m[1];
    }
    if ($m[2] !== '') {
        $end = (int)$m[2];
    }

    if ($m[1] === '' && $m[2] !== '') {
        $suffix = (int)$m[2];
        if ($suffix > 0) {
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        }
    }

    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }

    $end = min($end, $size - 1);
    $statusCode = 206;
}

$length = $end - $start + 1;

http_response_code($statusCode);
header('Content-Type: ' . $mime);
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('Content-Disposition: inline; filename="video.mp4"');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if ($statusCode === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$fp = fopen($fullPath, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit;
}

if ($start > 0) {
    fseek($fp, $start);
}

$remaining = $length;
$chunkSize = 1024 * 1024;
while ($remaining > 0 && !feof($fp)) {
    $readSize = (int)min($chunkSize, $remaining);
    $buffer = fread($fp, $readSize);
    if ($buffer === false) {
        break;
    }
    echo $buffer;
    flush();
    $remaining -= strlen($buffer);
}

fclose($fp);
exit;
