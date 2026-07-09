<?php
// ============================================================
// Pomocné funkce
// ============================================================

if (!function_exists('h')) {
    function h(?string $str): string {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('getAppSetting')) {
    function getAppSetting(string $key, string $default = ''): string {
        static $cache = [];
        if (isset($cache[$key])) return $cache[$key];
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE `key` = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            $cache[$key] = $row ? $row['value'] : $default;
        } catch (\Throwable $e) {
            $cache[$key] = $default;
        }
        return $cache[$key];
    }
}

if (!function_exists('formatDate')) {
    function formatDate(?string $dt): string {
        return $dt ? date('d.m.Y', strtotime($dt)) : '–';
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime(?string $dt): string {
        return $dt ? date('d.m.Y H:i', strtotime($dt)) : '–';
    }
}

function calculateAge(?string $birthDate): ?int {
    if (!$birthDate) {
        return null;
    }

    try {
        $dob = new DateTime($birthDate);
        $now = new DateTime();
        if ($dob > $now) {
            return null;
        }
        return (int)$now->diff($dob)->y;
    } catch (Exception $e) {
        return null;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }
}

// Vrátí poslední dokončenou session sportovce
function getLastSession(int $athleteId): ?array {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT ts.*, ws.name AS set_name
         FROM training_sessions ts
         JOIN workout_sets ws ON ts.workout_set_id = ws.id
                 WHERE ts.athlete_id = ?
                     AND ts.completed_at IS NOT NULL
                     AND ts.deleted_by_coach_at IS NULL
         ORDER BY ts.completed_at DESC
         LIMIT 1'
    );
    $stmt->execute([$athleteId]);
    return $stmt->fetch() ?: null;
}

// Vrátí počet dokončených sezení sportovce
function getSessionCount(int $athleteId): int {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM training_sessions
                 WHERE athlete_id = ?
                     AND completed_at IS NOT NULL
                     AND deleted_by_coach_at IS NULL'
    );
    $stmt->execute([$athleteId]);
    return (int)$stmt->fetchColumn();
}

// Vrátí série pro dané sezení a cvik
function getSeriesForExercise(int $sessionId, int $exerciseId): array {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT * FROM session_series
         WHERE session_id = ? AND exercise_id = ?
         ORDER BY series_order ASC'
    );
    $stmt->execute([$sessionId, $exerciseId]);
    return $stmt->fetchAll();
}

// Vrátí celý obsah sady (cviky seřazené)
function getWorkoutSetExercises(int $setId): array {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT wse.*, e.name AS exercise_name, e.sport_type, e.is_timed
         FROM workout_set_exercises wse
         JOIN exercises e ON wse.exercise_id = e.id
         WHERE wse.workout_set_id = ?
         ORDER BY wse.exercise_order ASC'
    );
    $stmt->execute([$setId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if (in_array(($row['sport_type'] ?? 'standard'), ['golf', 'run_outdoor', 'run_treadmill'], true)) {
            $row['sport_type'] = 'standard';
        }
    }
    unset($row);
    return $rows;
}

// Vrátí cviky konkrétní session ze snapshotu; fallback pro starší data.
function getSessionExercises(int $sessionId, int $setId): array {
    $pdo = getDB();

    $snapshot = $pdo->prepare(
        'SELECT tse.exercise_id, tse.exercise_order, tse.exercise_name, tse.sport_type, tse.is_timed
         FROM training_session_exercises tse
         WHERE tse.session_id = ?
         ORDER BY tse.exercise_order ASC'
    );
    $snapshot->execute([$sessionId]);
    $snapshotRows = $snapshot->fetchAll();
    if (!empty($snapshotRows)) {
        $ids = array_values(array_unique(array_map(fn($row) => (int)$row['exercise_id'], $snapshotRows)));
        $metaById = [];
        if (!empty($ids)) {
            $inClause = implode(',', array_fill(0, count($ids), '?'));
            $stmtTypes = $pdo->prepare("SELECT id, sport_type, is_timed FROM exercises WHERE id IN ($inClause)");
            $stmtTypes->execute($ids);
            foreach ($stmtTypes->fetchAll() as $typeRow) {
                $metaById[(int)$typeRow['id']] = [
                    'sport_type' => $typeRow['sport_type'] ?? 'standard',
                    'is_timed' => (int)($typeRow['is_timed'] ?? 0),
                ];
            }
        }
        foreach ($snapshotRows as &$row) {
            $meta = $metaById[(int)$row['exercise_id']] ?? ['sport_type' => 'standard', 'is_timed' => 0];
            if (!isset($row['sport_type']) || $row['sport_type'] === '' || $row['sport_type'] === null) {
                $row['sport_type'] = $meta['sport_type'];
            }
            if (in_array(($row['sport_type'] ?? 'standard'), ['golf', 'run_outdoor', 'run_treadmill'], true)) {
                $row['sport_type'] = 'standard';
            }
            if (!isset($row['is_timed']) || $row['is_timed'] === '' || $row['is_timed'] === null || ((int)$row['is_timed'] === 0 && $meta['is_timed'] === 1)) {
                $row['is_timed'] = $meta['is_timed'];
            }
        }
        unset($row);
        return $snapshotRows;
    }

    $setExercises = getWorkoutSetExercises($setId);
    $result = [];
    $maxOrder = 0;
    foreach ($setExercises as $row) {
        $eid = (int)$row['exercise_id'];
        $ord = (int)$row['exercise_order'];
        $result[$eid] = [
            'exercise_id'    => $eid,
            'exercise_order' => $ord,
            'exercise_name'  => $row['exercise_name'],
            'sport_type'     => in_array(($row['sport_type'] ?? 'standard'), ['golf', 'run_outdoor', 'run_treadmill'], true) ? 'standard' : ($row['sport_type'] ?? 'standard'),
            'is_timed'       => (int)($row['is_timed'] ?? 0),
        ];
        if ($ord > $maxOrder) {
            $maxOrder = $ord;
        }
    }

    // Starší data bez snapshotu: doplň cviky, které už nejsou v sadě, ale mají série.
    $fromSeries = $pdo->prepare(
        'SELECT DISTINCT ss.exercise_id, e.name AS exercise_name, e.sport_type, e.is_timed
         FROM session_series ss
         JOIN exercises e ON e.id = ss.exercise_id
         WHERE ss.session_id = ?
         ORDER BY ss.exercise_id ASC'
    );
    $fromSeries->execute([$sessionId]);
    foreach ($fromSeries->fetchAll() as $row) {
        $eid = (int)$row['exercise_id'];
        if (!isset($result[$eid])) {
            $maxOrder++;
            $result[$eid] = [
                'exercise_id'    => $eid,
                'exercise_order' => $maxOrder,
                'exercise_name'  => $row['exercise_name'],
                'sport_type'     => in_array(($row['sport_type'] ?? 'standard'), ['golf', 'run_outdoor', 'run_treadmill'], true) ? 'standard' : ($row['sport_type'] ?? 'standard'),
                'is_timed'       => (int)($row['is_timed'] ?? 0),
            ];
        }
    }

    usort($result, fn($a, $b) => ((int)$a['exercise_order']) <=> ((int)$b['exercise_order']));
    return array_values($result);
}

// Vrátí poslední dokončené série daného cviku u sportovce (pro porovnání během tréninku).
function getLastCompletedSeriesForExercise(int $athleteId, int $exerciseId, int $excludeSessionId = 0): ?array {
    $pdo = getDB();

    $lastSessionStmt = $pdo->prepare(
        'SELECT ts.id, ts.completed_at, ws.name AS set_name
         FROM training_sessions ts
         JOIN workout_sets ws ON ws.id = ts.workout_set_id
         JOIN session_series ss ON ss.session_id = ts.id
         WHERE ts.athlete_id = ?
           AND ss.exercise_id = ?
           AND ts.completed_at IS NOT NULL
           AND ts.deleted_by_coach_at IS NULL
           AND ts.id <> ?
         ORDER BY ts.completed_at DESC
         LIMIT 1'
    );
    $lastSessionStmt->execute([$athleteId, $exerciseId, $excludeSessionId]);
    $session = $lastSessionStmt->fetch();
    if (!$session) {
        return null;
    }

    $series = getSeriesForExercise((int)$session['id'], $exerciseId);
    if (empty($series)) {
        return null;
    }

    return [
        'session' => $session,
        'series'  => $series,
    ];
}

// Bezpečný int z $_GET / $_POST
if (!function_exists('intParam')) {
    function intParam(array $source, string $key, int $default = 0): int {
        return isset($source[$key]) ? (int)$source[$key] : $default;
    }
}

// ============================================================
// Upload fotografií
// ============================================================

/**
 * Nahraje a automaticky zmenší fotografii z $_FILES[$inputName] do uploads/$subDir/.
 * Používá GD pro resize (max 1920 px na delší stranu) a úsporu místa.
 * Vrátí název souboru nebo null při chybě / žádný soubor.
 */
function resizeAndSavePhoto(string $inputName, string $subDir, int $maxDim = 1920, int $quality = 82): ?string {
    if (empty($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$inputName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!array_key_exists($mime, $allowed)) {
        return null;
    }

    $dir = dirname(__DIR__) . '/uploads/' . $subDir . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Pokud GD není dostupné, ulož soubor bez resize
    if (!extension_loaded('gd')) {
        $ext      = $allowed[$mime];
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            return null;
        }
        return $filename;
    }

    // Načti obraz přes GD
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => @imagecreatefrompng($file['tmp_name']),
        'image/gif'  => @imagecreatefromgif($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
        default      => false,
    };

    if (!$src) {
        // GD nepodporuje soubor, ulož přímo
        $ext      = $allowed[$mime];
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            return null;
        }
        return $filename;
    }

    // Oprav orientaci dle EXIF (fotky z mobilů jsou často "na šířku" se EXIF rotací)
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif        = @exif_read_data($file['tmp_name']);
        $orientation = (int)($exif['Orientation'] ?? 1);
        if ($orientation > 1) {
            $corrected = _applyExifOrientation($src, $orientation);
            if ($corrected !== $src) {
                imagedestroy($src);
                $src = $corrected;
            }
        }
    }

    $origW = imagesx($src);
    $origH = imagesy($src);

    // Vypočítej nové rozměry (zmenšení jen pokud je větší než maxDim)
    if ($origW > $maxDim || $origH > $maxDim) {
        $ratio  = min($maxDim / $origW, $maxDim / $origH);
        $newW   = (int)round($origW * $ratio);
        $newH   = (int)round($origH * $ratio);
    } else {
        $newW = $origW;
        $newH = $origH;
    }

    $dst = imagecreatetruecolor($newW, $newH);
    if (!$dst) {
        imagedestroy($src);
        return null;
    }

    // Zachovej průhlednost pro PNG
    if ($mime === 'image/png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($src);

    // Vždy ukládej jako JPEG (kromě PNG s průhledností) pro kompaktnější soubor
    if ($mime === 'image/png') {
        $ext      = 'png';
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $saved    = imagepng($dst, $dir . $filename, min(9, (int)round((100 - $quality) / 10)));
    } else {
        $ext      = 'jpg';
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $saved    = imagejpeg($dst, $dir . $filename, $quality);
    }
    imagedestroy($dst);

    return $saved ? $filename : null;
}

/**
 * Opraví orientaci GD obrazu dle EXIF orientation tagu (1–8).
 * Vrátí nový nebo původní resource.
 */
function _applyExifOrientation(GdImage $img, int $orientation): GdImage {
    switch ($orientation) {
        case 2:
            imageflip($img, IMG_FLIP_HORIZONTAL);
            return $img;
        case 3:
            return imagerotate($img, 180, 0);
        case 4:
            imageflip($img, IMG_FLIP_VERTICAL);
            return $img;
        case 5:
            $img = imagerotate($img, -90, 0);
            imageflip($img, IMG_FLIP_HORIZONTAL);
            return $img;
        case 6:
            return imagerotate($img, -90, 0);
        case 7:
            $img = imagerotate($img, 90, 0);
            imageflip($img, IMG_FLIP_HORIZONTAL);
            return $img;
        case 8:
            return imagerotate($img, 90, 0);
        default:
            return $img;
    }
}

/**
 * Nahraje soubor z $_FILES[$inputName] do uploads/$subDir/.
 * Vrátí název souboru nebo null při chybě / žádný soubor.
 */
function saveUploadedPhoto(string $inputName, string $subDir): ?string {
    if (empty($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$inputName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!array_key_exists($mime, $allowed)) {
        return null;
    }
    $dir = dirname(__DIR__) . '/uploads/' . $subDir . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        return null;
    }
    return $filename;
}

/** Smaže soubor fotografie z disku. */
function deleteUploadedPhoto(?string $filename, string $subDir): void {
    if (!$filename) {
        return;
    }
    $path = dirname(__DIR__) . '/uploads/' . $subDir . '/' . $filename;
    if (is_file($path)) {
        unlink($path);
    }
}

/** Vrátí URL fotografie nebo prázdný řetězec. */
function photoUrl(?string $filename, string $subDir): string {
    if (!$filename) {
        return '';
    }
    return BASE_URL . '/uploads/' . $subDir . '/' . rawurlencode($filename);
}

/**
 * Odešle uvítací e-mail trenérovi s přihlašovacími údaji přes SMTP (PHPMailer).
 * Vrátí true při úspěchu, false při chybě.
 */
function sendCoachWelcomeEmail(string $toEmail, string $username, string $password, string $loginUrl): bool {
    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
        error_log('sendCoachWelcomeEmail: PHPMailer not found at ' . $phpmailerSrc);
        return false;
    }

    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:32px 0;">
    <tr><td align="center">
      <table width="100%" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">

        <!-- Hlavicka -->
        <tr>
          <td style="background:linear-gradient(135deg,#7c3aed,#a78bfa);padding:36px 40px;text-align:center;">
            <div style="font-size:32px;margin-bottom:8px;">&#x1F4AA;</div>
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:.5px;">TrainerApp</h1>
            <p style="margin:6px 0 0;color:#e9d5ff;font-size:13px;">V&#225;&#353; tr&#233;ninkov&#253; syst&#233;m</p>
          </td>
        </tr>

        <!-- Obsah -->
        <tr>
          <td style="padding:36px 40px;">
            <p style="margin:0 0 16px;color:#374151;font-size:15px;">Dobr&#253; den,</p>
            <p style="margin:0 0 24px;color:#374151;font-size:15px;">
              byl V&#225;m vytvo&#345;en &#250;&#269;et <strong>tren&#233;ra</strong> v aplikaci <strong>TrainerApp</strong>.
              N&#237;&#382;e najdete sv&#233; p&#345;ihla&#353;ovac&#237; &#250;daje.
            </p>

            <!-- Prihlasovaci udaje -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:24px;">
              <tr>
                <td style="padding:20px 24px;">
                  <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="padding:7px 0;color:#6b7280;font-size:13px;width:170px;">P&#345;ihla&#353;ovac&#237; str&#225;nka</td>
                      <td style="padding:7px 0;">
                        <a href="{LOGIN_URL_RAW}" style="color:#7c3aed;font-weight:600;font-size:13px;text-decoration:none;">{LOGIN_URL_SAFE}</a>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:7px 0;color:#6b7280;font-size:13px;border-top:1px solid #e5e7eb;">U&#382;ivatelsk&#233; jm&#233;no</td>
                      <td style="padding:7px 0;font-weight:700;font-size:14px;color:#111827;border-top:1px solid #e5e7eb;">{USERNAME}</td>
                    </tr>
                    <tr>
                      <td style="padding:7px 0;color:#6b7280;font-size:13px;border-top:1px solid #e5e7eb;">Heslo</td>
                      <td style="padding:7px 0;font-weight:700;font-size:14px;color:#111827;border-top:1px solid #e5e7eb;font-family:monospace;">{PASSWORD}</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <!-- CTA tlacitko -->
            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
              <tr>
                <td align="center">
                  <a href="{LOGIN_URL_RAW}"
                     style="display:inline-block;background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;padding:14px 36px;border-radius:8px;">
                    P&#345;ihl&#225;sit se do TrainerApp
                  </a>
                </td>
              </tr>
            </table>

            <!-- Upozorneni -->
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#fffbeb;border-left:4px solid #f59e0b;border-radius:4px;margin-bottom:28px;">
              <tr>
                <td style="padding:12px 16px;color:#92400e;font-size:13px;">
                  &#9888;&#65039; <strong>Doporu&#269;en&#237;:</strong> Po prvn&#237;m p&#345;ihl&#225;&#353;en&#237; si heslo ihned zm&#283;&#328;te v nastaven&#237; profilu.
                </td>
              </tr>
            </table>

            <p style="margin:0;color:#6b7280;font-size:13px;">S pozdravem,<br><strong style="color:#374151;">Administrace TrainerApp</strong></p>
          </td>
        </tr>

        <!-- Paticka -->
        <tr>
          <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:20px 40px;text-align:center;">
            <p style="margin:0 0 6px;color:#9ca3af;font-size:12px;">
              Aplikaci vytvo&#345;il a spravuje <strong style="color:#6b7280;">Tom&#225;&#353; Tome&#353;ka</strong>
            </p>
            <p style="margin:0;color:#9ca3af;font-size:12px;">
              Dotazy a podpora:
              <a href="mailto:admin@reservio.online" style="color:#7c3aed;text-decoration:none;">admin@reservio.online</a>
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $htmlBody = str_replace(
        ['{LOGIN_URL_RAW}', '{LOGIN_URL_SAFE}', '{USERNAME}', '{PASSWORD}'],
        [$loginUrl, $safeLoginUrl, $safeUsername, $safePassword],
        $htmlBody
    );

    $altBody =
        "Dobrý den,\n\n" .
        "byl Vám vytvořen účet trenéra v aplikaci TrainerApp.\n\n" .
        "Přihlašovací stránka: " . $loginUrl . "\n" .
        "Uživatelské jméno: " . $username . "\n" .
        "Heslo: " . $password . "\n\n" .
        "Doporučení: po prvním přihlášení si heslo ihned změňte v profilu.\n\n" .
        "S pozdravem\n" .
        "Administrace TrainerApp\n\n" .
        "---\n" .
        "Aplikaci vytvořil a spravuje Tomáš Tomeška\n" .
        "Podpora: admin@reservio.online\n";

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Přihlašovací údaje do TrainerApp';
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody;

        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('sendCoachWelcomeEmail SMTP error: ' . $mail->ErrorInfo . ' | Exception: ' . $e->getMessage());
    }

    try {
        $fallback = new PHPMailer\PHPMailer\PHPMailer(true);
        $fallback->isMail();
        $fallback->CharSet = 'UTF-8';
        $fallback->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $fallback->addAddress($toEmail);
        $fallback->isHTML(true);
        $fallback->Subject = 'Přihlašovací údaje do TrainerApp';
        $fallback->Body = $htmlBody;
        $fallback->AltBody = $altBody;
        $fallback->send();
        return true;
    } catch (\Exception $e) {
        error_log('sendCoachWelcomeEmail fallback error: ' . $e->getMessage());
        return false;
    }
}
