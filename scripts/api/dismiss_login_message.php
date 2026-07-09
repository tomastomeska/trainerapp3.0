<?php
// api/dismiss_login_message.php – trenér trvale skryje aktuální hlášku
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášen']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Neplatná metoda']);
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Neplatný token']);
    exit;
}

$version = intval($_POST['message_version'] ?? 0);
if ($version <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Chybí verze zprávy']);
    exit;
}

$coachId = getCurrentCoachId();
$pdo     = getDB();

$pdo->prepare(
    'INSERT IGNORE INTO coach_message_seen (coach_id, message_version) VALUES (?, ?)'
)->execute([$coachId, $version]);

echo json_encode(['ok' => true]);
