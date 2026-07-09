<?php
// api/message_action.php – zaloguje stisk akčního tlačítka / uložení podpisu
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireLogin();
$coachId = getCurrentCoachId();

if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$actionId = intParam($_POST, 'action_id');
if ($actionId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action_id']);
    exit;
}

$pdo = getDB();

// Ověř, že akce patří ke zprávě, jejímž je trenér příjemcem
$check = $pdo->prepare("
    SELECT a.id, a.action_type, a.message_id
    FROM message_actions a
    JOIN admin_message_recipients r ON r.message_id = a.message_id AND r.coach_id = ?
    WHERE a.id = ?
");
$check->execute([$coachId, $actionId]);
$action = $check->fetch();

if (!$action) {
    http_response_code(403);
    echo json_encode(['error' => 'Action not found or not authorized']);
    exit;
}

// Blokace: pokud trenér stiskl jiné tlačítko pro tuto zprávu, odmítnout
$alreadyStmt = $pdo->prepare("
    SELECT l.action_id FROM message_action_logs l
    JOIN message_actions a ON a.id = l.action_id
    WHERE a.message_id = ? AND l.coach_id = ? AND l.action_id != ?
    LIMIT 1
");
$alreadyStmt->execute([$action['message_id'], $coachId, $actionId]);
if ($alreadyStmt->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'Jinou volbu jste již potvrdil. Volbu nelze změnit.']);
    exit;
}

// Signature data – pouze pro typ signature, validace base64 PNG
$signatureData = null;
if ($action['action_type'] === 'signature' && !empty($_POST['signature_data'])) {
    $raw = $_POST['signature_data'];
    // Musí začínat data:image/png;base64,
    if (str_starts_with($raw, 'data:image/png;base64,')) {
        $b64 = substr($raw, strlen('data:image/png;base64,'));
        if (base64_decode($b64, true) !== false) {
            $signatureData = $raw; // uložíme jako data URL
        }
    }
}

// IP adresa klienta
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (str_contains($ip, ',')) {
    $ip = trim(explode(',', $ip)[0]);
}
$ip = substr($ip, 0, 45);

$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

// INSERT OR UPDATE (unikátní klíč action_id+coach_id)
// Pokud už existuje, přepíšeme (trenér může podpis opravit)
$stmt = $pdo->prepare("
    INSERT INTO message_action_logs (action_id, coach_id, pressed_at, ip_address, user_agent, signature_data)
    VALUES (?, ?, NOW(), ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        pressed_at     = VALUES(pressed_at),
        ip_address     = VALUES(ip_address),
        user_agent     = VALUES(user_agent),
        signature_data = IF(VALUES(signature_data) IS NOT NULL, VALUES(signature_data), signature_data)
");
$stmt->execute([$actionId, $coachId, $ip, $ua, $signatureData]);

$markReadStmt = $pdo->prepare("\n    UPDATE admin_message_recipients\n    SET read_at = IFNULL(read_at, NOW())\n    WHERE message_id = ? AND coach_id = ?\n");
$markReadStmt->execute([$action['message_id'], $coachId]);

echo json_encode(['ok' => true]);
