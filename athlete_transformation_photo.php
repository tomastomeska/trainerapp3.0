<?php
require_once __DIR__ . '/includes/auth.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$entryId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$entryId || $entryId < 1) {
    http_response_code(404);
    exit;
}

$pdo = getDB();
$tableStmt = $pdo->query("SHOW TABLES LIKE 'athlete_transformation_entries'");
if ($tableStmt === false || !$tableStmt->fetchColumn()) {
    http_response_code(404);
    exit;
}

$position = filter_input(INPUT_GET, 'pos', FILTER_VALIDATE_INT) ?: 1;
$stmt = $pdo->prepare(
    'SELECT p.photo_path, p.photo_mime_type
     FROM athlete_transformation_photos p
     WHERE p.entry_id = ? AND p.athlete_id = ? AND p.position = ?
     LIMIT 1'
);
$stmt->execute([$entryId, $athleteId, $position]);
$photo = $stmt->fetch();
if (!$photo && $position === 1) {
    $stmt = $pdo->prepare(
        'SELECT photo_path, photo_mime_type
         FROM athlete_transformation_entries
         WHERE id = ? AND athlete_id = ?
         LIMIT 1'
    );
    $stmt->execute([$entryId, $athleteId]);
    $photo = $stmt->fetch();
}
if (!$photo || !in_array($photo['photo_mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

$photoName = (string)$photo['photo_path'];
if ($photoName === '' || basename($photoName) !== $photoName) {
    http_response_code(404);
    exit;
}

$photoPath = __DIR__ . '/uploads/athlete_transformations/athlete_' . $athleteId . '/' . $photoName;
if (!is_file($photoPath) || !is_readable($photoPath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $photo['photo_mime_type']);
header('Content-Length: ' . (string)filesize($photoPath));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($photoPath);
