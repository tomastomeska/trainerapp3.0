<?php
// api/support_ticket_create.php - vytvoreni ticketu podpory z plovouciho widgetu
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn() && !athleteIsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nejste přihlášen.']);
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Neplatný bezpečnostní token.']);
    exit;
}

$subject = trim((string)($_POST['subject'] ?? ''));
$issueType = trim((string)($_POST['issue_type'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$pageUrl = trim((string)($_POST['page_url'] ?? ''));

if ($subject === '' || mb_strlen($subject) > 255) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Předmět je povinný (max. 255 znaků).']);
    exit;
}
if ($issueType === '' || mb_strlen($issueType) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Vyberte typ problému.']);
    exit;
}
if ($description === '' || mb_strlen($description) > 5000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Popis problému je povinný (max. 5000 znaků).']);
    exit;
}
if (mb_strlen($pageUrl) > 500) {
    $pageUrl = mb_substr($pageUrl, 0, 500);
}

$reporterType = 'coach';
$coachId = null;
$athleteId = null;
$reporterName = '';
$reporterEmail = null;

if (athleteIsLoggedIn()) {
    $athlete = getCurrentAthlete();
    if (!$athlete) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Uživatel nenalezen.']);
        exit;
    }

    $reporterType = 'athlete';
    $athleteId = (int)$athlete['id'];
    $coachId = isset($athlete['coach_id']) ? (int)$athlete['coach_id'] : null;
    $reporterName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
    $reporterEmail = !empty($athlete['email']) ? (string)$athlete['email'] : null;
} else {
    $coach = getCurrentCoach();
    if (!$coach) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Uživatel nenalezen.']);
        exit;
    }

    $reporterType = 'coach';
    $coachId = (int)$coach['id'];
    $reporterName = (string)($coach['name'] ?: $coach['username']);
    $reporterEmail = !empty($coach['email']) ? (string)$coach['email'] : null;
}

if ($reporterName === '') {
    $reporterName = 'Uživatel';
}

$screenshotPath = null;
$screenshotName = null;
$file = $_FILES['screenshot'] ?? null;

if ($file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $err = (int)$file['error'];
    if ($err !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Nahrávání screenshotu selhalo (kód ' . $err . ').']);
        exit;
    }

    $maxSize = 8 * 1024 * 1024;
    if ((int)$file['size'] > $maxSize) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Screenshot může mít maximálně 8 MB.']);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!array_key_exists($mime, $allowed)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Podporujeme pouze obrázky JPG, PNG, GIF nebo WEBP.']);
        exit;
    }

    $uploadDir = dirname(__DIR__) . '/uploads/support/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = $allowed[$mime];
    $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Screenshot se nepodařilo uložit.']);
        exit;
    }

    $screenshotPath = $newName;
    $screenshotName = mb_substr((string)$file['name'], 0, 255);
}

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
if (str_contains($ip, ',')) {
    $ip = trim(explode(',', $ip)[0]);
}
$ip = mb_substr($ip, 0, 45);
$userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

$pdo = getDB();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO support_tickets (
            reporter_type, coach_id, athlete_id, reporter_name, reporter_email,
            subject, issue_type, description, page_url,
            screenshot_path, screenshot_name,
            ip_address, user_agent, status, created_at, updated_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new", NOW(), NOW())'
    );

    $stmt->execute([
        $reporterType,
        $coachId,
        $athleteId,
        $reporterName,
        $reporterEmail,
        $subject,
        $issueType,
        $description,
        $pageUrl,
        $screenshotPath,
        $screenshotName,
        $ip,
        $userAgent,
    ]);

    $ticketId = (int)$pdo->lastInsertId();

    sendSupportTicketNotificationEmail($ticketId, [
        'reporter_name' => $reporterName,
        'reporter_email' => $reporterEmail,
        'subject' => $subject,
        'issue_type' => $issueType,
        'description' => $description,
        'page_url' => $pageUrl,
        'screenshot_path' => $screenshotPath,
    ]);

    echo json_encode(['ok' => true, 'ticket_id' => $ticketId]);
} catch (Throwable $e) {
    if ($screenshotPath) {
        $full = dirname(__DIR__) . '/uploads/support/' . basename($screenshotPath);
        if (is_file($full)) {
            @unlink($full);
        }
    }

    error_log('support_ticket_create error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ticket se nepodařilo uložit. Zkuste to prosím znovu.']);
}
