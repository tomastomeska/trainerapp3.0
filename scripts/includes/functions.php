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

if (!function_exists('ibanToNumericString')) {
  function ibanToNumericString(string $iban): string {
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    $len = strlen($rearranged);
    for ($i = 0; $i < $len; $i++) {
      $char = $rearranged[$i];
      if ($char >= '0' && $char <= '9') {
        $numeric .= $char;
      } elseif ($char >= 'A' && $char <= 'Z') {
        $numeric .= (string)(ord($char) - 55);
      }
    }
    return $numeric;
  }
}

if (!function_exists('digitsMod97')) {
  function digitsMod97(string $digits): int {
    $remainder = 0;
    $len = strlen($digits);
    for ($i = 0; $i < $len; $i++) {
      $remainder = ($remainder * 10 + (int)$digits[$i]) % 97;
    }
    return $remainder;
  }
}

if (!function_exists('isValidIban')) {
  function isValidIban(string $iban): bool {
    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban) !== 1) {
      return false;
    }

    return digitsMod97(ibanToNumericString($iban)) === 1;
  }
}

if (!function_exists('buildCzIbanFromLocal')) {
  function buildCzIbanFromLocal(string $localAccount): ?string {
    if (preg_match('/^(?:(\d{1,6})-)?(\d{2,10})\/(\d{4})$/', $localAccount, $m) !== 1) {
      return null;
    }

    $prefix = str_pad((string)($m[1] ?? '0'), 6, '0', STR_PAD_LEFT);
    $account = str_pad($m[2], 10, '0', STR_PAD_LEFT);
    $bankCode = $m[3];
    $bban = $bankCode . $prefix . $account;

    $checkBase = $bban . '123500';
    $checkDigits = 98 - digitsMod97($checkBase);
    $iban = 'CZ' . str_pad((string)$checkDigits, 2, '0', STR_PAD_LEFT) . $bban;

    return isValidIban($iban) ? $iban : null;
  }
}

if (!function_exists('accountForSpd')) {
  function accountForSpd(?string $bankAccount): ?string {
    if ($bankAccount === null || $bankAccount === '') {
      return null;
    }

    if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $bankAccount) === 1) {
      return isValidIban($bankAccount) ? $bankAccount : null;
    }

    return buildCzIbanFromLocal($bankAccount);
  }
}

if (!function_exists('paymentAsciiText')) {
  function paymentAsciiText(string $value): string {
    $text = trim($value);
    if ($text === '') {
      return '';
    }

    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
      $text = $converted;
    }

    $text = preg_replace('/[^A-Za-z0-9 .\/-]/', '', $text) ?? '';
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    return trim($text);
  }
}

if (!function_exists('athletePaymentsAscii')) {
  function athletePaymentsAscii(string $value): string {
    return paymentAsciiText($value);
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
    'SELECT wse.*, e.name AS exercise_name, e.sport_type
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
        'SELECT tse.exercise_id, tse.exercise_order, tse.exercise_name, tse.sport_type
         FROM training_session_exercises tse
         WHERE tse.session_id = ?
         ORDER BY tse.exercise_order ASC'
    );
    $snapshot->execute([$sessionId]);
    $snapshotRows = $snapshot->fetchAll();
    if (!empty($snapshotRows)) {
      $needsSportType = false;
      foreach ($snapshotRows as $row) {
        if (!isset($row['sport_type']) || $row['sport_type'] === '' || $row['sport_type'] === null) {
          $needsSportType = true;
          break;
        }
      }

      if ($needsSportType) {
        $ids = array_values(array_unique(array_map(fn($row) => (int)$row['exercise_id'], $snapshotRows)));
        if (!empty($ids)) {
          $inClause = implode(',', array_fill(0, count($ids), '?'));
          $stmtTypes = $pdo->prepare("SELECT id, sport_type FROM exercises WHERE id IN ($inClause)");
          $stmtTypes->execute($ids);
          $typesById = [];
          foreach ($stmtTypes->fetchAll() as $typeRow) {
            $typesById[(int)$typeRow['id']] = $typeRow['sport_type'] ?? 'standard';
          }
          foreach ($snapshotRows as &$row) {
            if (!isset($row['sport_type']) || $row['sport_type'] === '' || $row['sport_type'] === null) {
              $row['sport_type'] = $typesById[(int)$row['exercise_id']] ?? 'standard';
            }
            if (in_array(($row['sport_type'] ?? 'standard'), ['golf', 'run_outdoor', 'run_treadmill'], true)) {
              $row['sport_type'] = 'standard';
            }
          }
          unset($row);
        }
      }

      foreach ($snapshotRows as &$row) {
        if (in_array(($row['sport_type'] ?? 'standard'), ['golf', 'run_outdoor', 'run_treadmill'], true)) {
          $row['sport_type'] = 'standard';
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
        ];
        if ($ord > $maxOrder) {
            $maxOrder = $ord;
        }
    }

    // Starší data bez snapshotu: doplň cviky, které už nejsou v sadě, ale mají série.
    $fromSeries = $pdo->prepare(
        'SELECT DISTINCT ss.exercise_id, e.name AS exercise_name, e.sport_type
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
 *
 * @param \GdImage|resource $img
 * @return \GdImage|resource
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
 * Uloží jednu nebo více fotek z upload inputu.
 * Podporuje input typu single i multiple.
 *
        if (!function_exists('ibanToNumericString')) {
          function ibanToNumericString(string $iban): string {
            $rearranged = substr($iban, 4) . substr($iban, 0, 4);
            $numeric = '';
            $len = strlen($rearranged);
            for ($i = 0; $i < $len; $i++) {
              $char = $rearranged[$i];
              if ($char >= '0' && $char <= '9') {
                $numeric .= $char;
              } elseif ($char >= 'A' && $char <= 'Z') {
                $numeric .= (string)(ord($char) - 55);
              }
            }
            return $numeric;
          }
        }
    
        if (!function_exists('digitsMod97')) {
          function digitsMod97(string $digits): int {
            $remainder = 0;
            $len = strlen($digits);
            for ($i = 0; $i < $len; $i++) {
              $remainder = ($remainder * 10 + (int)$digits[$i]) % 97;
            }
            return $remainder;
          }
        }
    
        if (!function_exists('isValidIban')) {
          function isValidIban(string $iban): bool {
            if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban) !== 1) {
              return false;
            }
            return digitsMod97(ibanToNumericString($iban)) === 1;
          }
        }
    
        if (!function_exists('buildCzIbanFromLocal')) {
          function buildCzIbanFromLocal(string $localAccount): ?string {
            if (preg_match('/^(?:(\d{1,6})-)?(\d{2,10})\/(\d{4})$/', $localAccount, $m) !== 1) {
              return null;
            }
            $prefix = str_pad((string)($m[1] ?? '0'), 6, '0', STR_PAD_LEFT);
            $account = str_pad($m[2], 10, '0', STR_PAD_LEFT);
            $bankCode = $m[3];
            $bban = $bankCode . $prefix . $account;
            $checkBase = $bban . '123500';
            $checkDigits = 98 - digitsMod97($checkBase);
            $iban = 'CZ' . str_pad((string)$checkDigits, 2, '0', STR_PAD_LEFT) . $bban;
            return isValidIban($iban) ? $iban : null;
          }
        }
    
        if (!function_exists('accountForSpd')) {
          function accountForSpd(?string $bankAccount): ?string {
            if ($bankAccount === null || $bankAccount === '') {
              return null;
            }
            if (preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $bankAccount) === 1) {
              return isValidIban($bankAccount) ? $bankAccount : null;
            }
            return buildCzIbanFromLocal($bankAccount);
          }
        }
    
        if (!function_exists('paymentAsciiText')) {
          function paymentAsciiText(string $value): string {
            $text = trim($value);
            if ($text === '') {
              return '';
            }
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
              $text = $converted;
            }
            $text = preg_replace('/[^A-Za-z0-9 .\/-]/', '', $text) ?? '';
            $text = preg_replace('/\s+/', ' ', $text) ?? '';
            return trim($text);
          }
        }
 * @return string[] Pole názvů uložených souborů.
 */
function saveTrainingPhotosFromInput(string $inputName, string $subDir = 'trainings', int $maxPhotoSize = 8388608): array {
  if (empty($_FILES[$inputName])) {
    return [];
  }

  $file = $_FILES[$inputName];
  $saved = [];

  // multiple input: name="foo[]"
  if (is_array($file['name'] ?? null)) {
    $count = count($file['name']);
    for ($i = 0; $i < $count; $i++) {
      $err = (int)($file['error'][$i] ?? UPLOAD_ERR_NO_FILE);
      if ($err === UPLOAD_ERR_NO_FILE) {
        continue;
      }
      if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Nahrávání fotografie selhalo.');
      }

      $size = (int)($file['size'][$i] ?? 0);
      if ($size > $maxPhotoSize) {
        throw new RuntimeException('Jedna z fotografií je příliš velká. Maximum je 8 MB.');
      }

      $tmpKey = '__upload_photo_tmp';
      $_FILES[$tmpKey] = [
        'name' => $file['name'][$i] ?? '',
        'type' => $file['type'][$i] ?? '',
        'tmp_name' => $file['tmp_name'][$i] ?? '',
        'error' => $err,
        'size' => $size,
      ];
      $filename = resizeAndSavePhoto($tmpKey, $subDir);
      unset($_FILES[$tmpKey]);

      if (!$filename) {
        throw new RuntimeException('Podporujeme pouze obrázky JPG, PNG, GIF nebo WEBP.');
      }
      $saved[] = $filename;
    }

    return $saved;
  }

  // single input
  $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($err === UPLOAD_ERR_NO_FILE) {
    return [];
  }
  if ($err !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Nahrávání fotografie selhalo.');
  }

  $size = (int)($file['size'] ?? 0);
  if ($size > $maxPhotoSize) {
    throw new RuntimeException('Fotografie je příliš velká. Maximum je 8 MB.');
  }

  $filename = resizeAndSavePhoto($inputName, $subDir);
  if (!$filename) {
    throw new RuntimeException('Podporujeme pouze obrázky JPG, PNG, GIF nebo WEBP.');
  }

  return [$filename];
}

function addTrainingSessionPhotos(int $sessionId, array $filenames): void {
  if (empty($filenames)) {
    return;
  }

  $pdo = getDB();
  $stmtOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM training_session_photos WHERE session_id = ?');
  $stmtOrder->execute([$sessionId]);
  $nextOrder = (int)$stmtOrder->fetchColumn();

  $stmtIns = $pdo->prepare(
    'INSERT INTO training_session_photos (session_id, filename, sort_order)
     VALUES (?, ?, ?)'
  );

  foreach ($filenames as $filename) {
    $nextOrder++;
    $stmtIns->execute([$sessionId, (string)$filename, $nextOrder]);
  }
}

function getTrainingSessionPhotos(int $sessionId): array {
  $pdo = getDB();
  $stmt = $pdo->prepare(
    'SELECT id, session_id, filename, sort_order, created_at
     FROM training_session_photos
     WHERE session_id = ?
     ORDER BY sort_order ASC, id ASC'
  );
  $stmt->execute([$sessionId]);
  return $stmt->fetchAll();
}

function deleteTrainingSessionPhotoById(int $photoId): ?array {
  $pdo = getDB();
  $stmt = $pdo->prepare('SELECT * FROM training_session_photos WHERE id = ? LIMIT 1');
  $stmt->execute([$photoId]);
  $row = $stmt->fetch();
  if (!$row) {
    return null;
  }

  $pdo->prepare('DELETE FROM training_session_photos WHERE id = ?')->execute([$photoId]);
  return $row;
}

function deleteTrainingSessionPhotosByFilename(int $sessionId, string $filename): void {
  $pdo = getDB();
  $stmt = $pdo->prepare('DELETE FROM training_session_photos WHERE session_id = ? AND filename = ?');
  $stmt->execute([$sessionId, $filename]);
}

/**
 * Odešle e-mail sportovci se souhrnem dokončeného tréninku přes SMTP (PHPMailer).
 * Vrátí true při úspěchu, false při chybě.
 *
 * @param string $toEmail     E-mail sportovce
 * @param array  $session     Řádek training_sessions (+ set_name, first_name, last_name, location, notes, completed_at)
 * @param array  $exercises   Výsledek getSessionExercises()
 * @param array  $coach       Řádek coaches (name, username)
 */
function sendTrainingEmail(string $toEmail, array $session, array $exercises, array $coach): bool {
    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
        error_log('sendTrainingEmail: PHPMailer not found at ' . $phpmailerSrc);
        return false;
    }

    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $h = fn(?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

    $athleteFirstName = $session['first_name'];
    $coachName        = $coach['name'] ?: $coach['username'];
    $setName          = $session['set_name'];
    $completedAt      = formatDateTime($session['completed_at']);
    $location         = $session['location'] ?? '';
    $notes            = $session['notes']    ?? '';

    // ── Sestavení řádků cvičení (HTML + plain) ──────────────────────────────
    $exerciseRowsHtml  = '';
    $exerciseRowsPlain = '';
    $totalSeries       = 0;

    foreach ($exercises as $i => $ex) {
        $series = getSeriesForExercise((int)$session['id'], (int)$ex['exercise_id']);
        if (empty($series)) continue;

        $totalSeries += count($series);
        $bgHeader  = ($i % 2 === 0) ? '#1e1b4b' : '#312e81';
        $bgRow     = ($i % 2 === 0) ? '#f9fafb' : '#f3f4f6';

        $exerciseRowsHtml .= <<<HTML
        <tr>
          <td colspan="4" style="background:{$bgHeader};color:#e9d5ff;font-size:12px;font-weight:700;
              letter-spacing:.8px;padding:10px 16px;text-transform:uppercase;">
            {$h((string)$ex['exercise_order'])}. {$h($ex['exercise_name'])}
          </td>
        </tr>
        HTML;

        $exerciseRowsPlain .= strtoupper($ex['exercise_order'] . '. ' . $ex['exercise_name']) . "\n";
        $exerciseRowsPlain .= sprintf("  %-4s %-10s %-10s %-10s\n", '#', 'Váha', 'Opa.', 'Dopomoc');

        foreach ($series as $s) {
            $assist = $s['assistance_reps'] > 0 ? $s['assistance_reps'] . 'x' : '–';
            $weight = number_format((float)$s['weight'], 1, ',', '') . ' kg';
            $reps   = $s['reps'] . 'x';
            $assistColor = $s['assistance_reps'] > 0 ? '#b45309' : '#9ca3af';

            $exerciseRowsHtml .= <<<HTML
            <tr style="background:{$bgRow};border-bottom:1px solid #e5e7eb;">
              <td style="padding:9px 16px;color:#6b7280;font-size:12px;width:36px;text-align:center;">
                {$h((string)$s['series_order'])}.
              </td>
              <td style="padding:9px 8px;font-weight:700;color:#111827;font-size:14px;">
                {$h($weight)}
              </td>
              <td style="padding:9px 8px;color:#374151;font-size:14px;">
                {$h($reps)}
              </td>
              <td style="padding:9px 16px;color:{$assistColor};font-size:13px;">
                {$h($assist)}
              </td>
            </tr>
            HTML;

            $exerciseRowsPlain .= sprintf("  %-4s %-10s %-10s %-10s\n",
                $s['series_order'] . '.',
                $weight,
                $reps,
                $assist
            );
        }
        $exerciseRowsPlain .= "\n";
    }

    // ── Volitelné bloky ─────────────────────────────────────────────────────
    $locationHtml = '';
    if ($location !== '') {
        $locationHtml = '<td style="padding:8px 0;color:#6b7280;font-size:13px;border-top:1px solid #e5e7eb;width:110px;">
                            📍 Místo</td>
                         <td style="padding:8px 0;color:#374151;font-size:13px;font-weight:600;border-top:1px solid #e5e7eb;">'
                         . $h($location) . '</td>';
    }
    $notesHtml = '';
    if ($notes !== '') {
        $notesHtml = <<<HTML
        <tr>
          <td style="padding:20px 32px 0;">
            <div style="background:#fffbeb;border-left:4px solid #f59e0b;border-radius:4px;padding:12px 16px;">
              <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#92400e;text-transform:uppercase;
                         letter-spacing:.5px;">Poznámky trenéra</p>
              <p style="margin:0;font-size:14px;color:#78350f;line-height:1.6;">{$h($notes)}</p>
            </div>
          </td>
        </tr>
        HTML;
    }

    // ── Statistika ───────────────────────────────────────────────────────────
    $exCount     = count($exercises);
    $statExercises = $exCount  . ' ' . ($exCount  === 1 ? 'cvik'   : ($exCount  < 5 ? 'cviky'   : 'cviků'));
    $statSeries    = $totalSeries . ' ' . ($totalSeries === 1 ? 'série' : ($totalSeries < 5 ? 'série' : 'sérií'));

    // ── HTML šablona ─────────────────────────────────────────────────────────
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Tréninkový záznam</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:32px 0;">
<tr><td align="center">
<table width="100%" style="max-width:580px;background:#ffffff;border-radius:14px;
       overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.10);">

  <!-- ░░ HLAVIČKA ░░ -->
  <tr>
    <td style="background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 60%,#a78bfa 100%);
               padding:40px 36px 32px;text-align:center;">
      <div style="font-size:40px;line-height:1;margin-bottom:12px;">💪</div>
      <h1 style="margin:0 0 6px;color:#ffffff;font-size:24px;font-weight:800;letter-spacing:.3px;">
        Trénink dokončen!
      </h1>
      <p style="margin:0;color:#c4b5fd;font-size:14px;">
        Skvělá práce, {$h($athleteFirstName)}!
      </p>
    </td>
  </tr>

  <!-- ░░ METADATA TRÉNINKU ░░ -->
  <tr>
    <td style="padding:28px 32px 0;">
      <table width="100%" cellpadding="0" cellspacing="0"
             style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;">
        <tr>
          <td style="padding:16px 20px;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:8px 0;color:#6b7280;font-size:13px;width:110px;">📋 Tréninkový plán</td>
                <td style="padding:8px 0;color:#111827;font-size:13px;font-weight:700;">{$h($setName)}</td>
              </tr>
              <tr>
                <td style="padding:8px 0;color:#6b7280;font-size:13px;border-top:1px solid #e5e7eb;">🗓️ Datum</td>
                <td style="padding:8px 0;color:#374151;font-size:13px;border-top:1px solid #e5e7eb;">{$h($completedAt)}</td>
              </tr>
              {$locationHtml}
            </table>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- ░░ STATISTIKY ░░ -->
  <tr>
    <td style="padding:20px 32px 0;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td width="50%" style="padding:0 6px 0 0;">
            <div style="background:#ede9fe;border-radius:10px;padding:16px;text-align:center;">
              <div style="font-size:26px;font-weight:800;color:#5b21b6;line-height:1;">{$exCount}</div>
              <div style="font-size:11px;color:#7c3aed;font-weight:600;text-transform:uppercase;
                           letter-spacing:.6px;margin-top:4px;">Cviky</div>
            </div>
          </td>
          <td width="50%" style="padding:0 0 0 6px;">
            <div style="background:#dbeafe;border-radius:10px;padding:16px;text-align:center;">
              <div style="font-size:26px;font-weight:800;color:#1d4ed8;line-height:1;">{$totalSeries}</div>
              <div style="font-size:11px;color:#2563eb;font-weight:600;text-transform:uppercase;
                           letter-spacing:.6px;margin-top:4px;">Série</div>
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  {$notesHtml}

  <!-- ░░ TABULKA CVIKŮ ░░ -->
  <tr>
    <td style="padding:24px 32px 0;">
      <p style="margin:0 0 12px;font-size:13px;font-weight:700;color:#374151;
                text-transform:uppercase;letter-spacing:.6px;">Podrobný záznam</p>
      <table width="100%" cellpadding="0" cellspacing="0"
             style="border-radius:10px;overflow:hidden;border:1px solid #e5e7eb;">
        {$exerciseRowsHtml}
      </table>
    </td>
  </tr>

  <!-- ░░ MOTIVAČNÍ TEXT ░░ -->
  <tr>
    <td style="padding:28px 32px;">
      <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:10px;
                  padding:20px 24px;text-align:center;">
        <div style="font-size:22px;margin-bottom:8px;">🏆</div>
        <p style="margin:0;font-size:14px;color:#166534;line-height:1.7;">
          Každý odcvičený trénink tě posouvá blíž k cíli.<br>
          <strong>Uvidíme se na dalším!</strong>
        </p>
      </div>
    </td>
  </tr>

  <!-- ░░ PODPIS ░░ -->
  <tr>
    <td style="padding:0 32px 32px;">
      <p style="margin:0;font-size:14px;color:#374151;">S pozdravem,<br>
        <strong style="color:#111827;">{$h($coachName)}</strong>
        <span style="color:#6b7280;font-size:13px;"> – Tvůj trenér</span>
      </p>
    </td>
  </tr>

  <!-- ░░ PATIČKA ░░ -->
  <tr>
    <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:18px 32px;text-align:center;">
      <p style="margin:0 0 4px;color:#9ca3af;font-size:11px;">
        Zpráva vygenerována aplikací <strong style="color:#6b7280;">TrainerApp</strong>
      </p>
      <p style="margin:0;color:#9ca3af;font-size:11px;">
        Vytvořil <strong style="color:#6b7280;">Tomáš Tomeška</strong>
        &nbsp;·&nbsp;
        <a href="mailto:tomas.tomeska@seznam.cz?subject=Zpr%C3%A1va%20z%20TrainerApp" style="color:#7c3aed;text-decoration:none;">tomas.tomeska@seznam.cz</a>
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;

    // ── Plain-text alternativa ───────────────────────────────────────────────
    $altBody  = "Ahoj {$athleteFirstName},\n\n";
    $altBody .= "posílám ti záznam z dnešního tréninku. Skvělá práce!\n\n";
    $altBody .= "Tréninkový plán: {$setName}\n";
    $altBody .= "Datum: {$completedAt}\n";
    if ($location !== '') $altBody .= "Místo: {$location}\n";
    $altBody .= "\n" . str_repeat('─', 42) . "\n\n";
    $altBody .= $exerciseRowsPlain;
    $altBody .= str_repeat('─', 42) . "\n\n";
    if ($notes !== '') $altBody .= "Poznámky trenéra:\n{$notes}\n\n";
    $altBody .= "S pozdravem,\n{$coachName} – Tvůj trenér\n\n";
    $altBody .= "---\nZpráva vygenerována aplikací TrainerApp\n";

    // ── Odeslání ─────────────────────────────────────────────────────────────
    $subject = 'Tréninkový záznam – ' . $setName . ' – ' . formatDate($session['completed_at']);

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        _configureMail($mail);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('sendTrainingEmail error: ' . $mail->ErrorInfo . ' | Exception: ' . $e->getMessage());
        return false;
    }
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
        _configureMail($mail);
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

/**
 * Odešle trenérovi email: sportovec bude mít za X dní narozeniny.
 */
function sendBirthdayWarningEmail(
    string $toEmail,
    string $coachName,
    string $athleteFirst,
    string $athleteLast,
    string $birthDate,
    int    $daysLeft
): bool {
    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
        error_log('sendBirthdayWarningEmail: PHPMailer not found');
        return false;
    }
    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $h         = function (?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); };
    $fullName  = trim($athleteFirst . ' ' . $athleteLast);
    $bdFormatted = '';
    $birthdayAge = '';
    try { $bdFormatted = (new DateTime($birthDate))->format('d.m.'); } catch (\Exception $e) {}
    try {
      $birthdayAge = (string)(((new DateTime($birthDate))->diff(new DateTime('today'))->y) + 1);
    } catch (\Exception $e) {}

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:32px 0;">
    <tr><td align="center">
      <table width="100%" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">
        <tr>
          <td style="background:linear-gradient(135deg,#7c3aed,#a78bfa);padding:36px 40px;text-align:center;">
            <div style="font-size:40px;margin-bottom:8px;">&#x1F382;</div>
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Blíží se narozeniny!</h1>
            <p style="margin:6px 0 0;color:#e9d5ff;font-size:13px;">TrainerApp – připomínka</p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 40px;">
            <p style="margin:0 0 16px;color:#374151;font-size:15px;">Dobrý den, <strong>{COACH_NAME}</strong>,</p>
            <p style="margin:0 0 24px;color:#374151;font-size:15px;">
              váš sportovec <strong>{ATHLETE_NAME}</strong> bude mít
              za <strong style="color:#7c3aed;">{DAYS_LEFT} dní</strong> narozeniny
              <strong>({BD_DATE})</strong>.
            </p>
            <p style="margin:0 0 20px;color:#6b7280;font-size:14px;">
              V den narozenin mu/jí bude <strong>{AGE}</strong> let.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ff;border-left:4px solid #7c3aed;border-radius:4px;margin-bottom:28px;">
              <tr>
                <td style="padding:16px 20px;color:#4c1d95;font-size:14px;line-height:1.6;">
                  &#127881; Možná je čas popřát mu/jí a naplánovat speciální trénink!
                </td>
              </tr>
            </table>
            <p style="margin:0;color:#6b7280;font-size:13px;">S pozdravem,<br><strong style="color:#374151;">TrainerApp – automatické notifikace</strong></p>
          </td>
        </tr>
        <tr>
          <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:16px 40px;text-align:center;">
            <p style="margin:0;color:#9ca3af;font-size:12px;">Aplikaci vytvořil a spravuje <strong style="color:#6b7280;">Tomáš Tomeška</strong></p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $htmlBody = str_replace(
      ['{COACH_NAME}', '{ATHLETE_NAME}', '{DAYS_LEFT}', '{BD_DATE}', '{AGE}'],
      [$h($coachName), $h($fullName), (string)$daysLeft, $h($bdFormatted), $h($birthdayAge)],
        $htmlBody
    );

    $altBody = "Dobrý den, {$coachName},\n\n"
        . "váš sportovec {$fullName} bude mít za {$daysLeft} dní narozeniny ({$bdFormatted}).\n\n"
      . "V den narozenin mu/jí bude {$birthdayAge} let.\n\n"
        . "S pozdravem\nTrainerApp – automatické notifikace\n";

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        _configureMail($mail);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Blíží se narozeniny: ' . $fullName . ' (' . $birthdayAge . ' let)';
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('sendBirthdayWarningEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
        return false;
    }
}

/**
 * Odešle trenérovi email: dnes má sportovec narozeniny.
 */
function sendBirthdayTodayEmail(
    string $toEmail,
    string $coachName,
    string $athleteFirst,
    string $athleteLast,
    int    $age
): bool {
    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
        error_log('sendBirthdayTodayEmail: PHPMailer not found');
        return false;
    }
    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $h        = function (?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); };
    $fullName = trim($athleteFirst . ' ' . $athleteLast);

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:32px 0;">
    <tr><td align="center">
      <table width="100%" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">
        <tr>
          <td style="background:linear-gradient(135deg,#f59e0b,#fbbf24);padding:36px 40px;text-align:center;">
            <div style="font-size:48px;margin-bottom:8px;">&#x1F389;</div>
            <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;">Dnes má narozeniny!</h1>
            <p style="margin:6px 0 0;color:#fef3c7;font-size:13px;">TrainerApp – narozeninová gratulace</p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 40px;text-align:center;">
            <p style="margin:0 0 8px;color:#374151;font-size:15px;">Dobrý den, <strong>{COACH_NAME}</strong>,</p>
            <p style="margin:0 0 28px;color:#374151;font-size:15px;">dnes slaví narozeniny váš sportovec:</p>
            <div style="background:linear-gradient(135deg,#7c3aed,#a78bfa);border-radius:12px;padding:28px 24px;margin-bottom:28px;display:inline-block;width:100%;box-sizing:border-box;">
              <p style="margin:0 0 6px;color:#e9d5ff;font-size:14px;letter-spacing:.5px;">&#x1F3C6; Oslavenec/oslavenkyně</p>
              <p style="margin:0 0 12px;color:#ffffff;font-size:26px;font-weight:700;">{ATHLETE_NAME}</p>
              <p style="margin:0;color:#ddd6fe;font-size:18px;font-weight:600;">slaví <strong style="color:#fbbf24;font-size:28px;">{AGE}</strong> let</p>
            </div>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px;margin-bottom:28px;">
              <tr>
                <td style="padding:14px 18px;color:#92400e;font-size:14px;text-align:left;">
                  &#127881; Nezapomeňte mu/jí popřát a třeba zorganizovat narozeninový trénink!
                </td>
              </tr>
            </table>
            <p style="margin:0;color:#6b7280;font-size:13px;text-align:left;">S pozdravem,<br><strong style="color:#374151;">TrainerApp – automatické notifikace</strong></p>
          </td>
        </tr>
        <tr>
          <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:16px 40px;text-align:center;">
            <p style="margin:0;color:#9ca3af;font-size:12px;">Aplikaci vytvořil a spravuje <strong style="color:#6b7280;">Tomáš Tomeška</strong></p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $htmlBody = str_replace(
        ['{COACH_NAME}', '{ATHLETE_NAME}', '{AGE}'],
        [$h($coachName), $h($fullName), (string)$age],
        $htmlBody
    );

    $altBody = "Dobrý den, {$coachName},\n\n"
        . "dnes slaví narozeniny váš sportovec {$fullName} – je mu/jí {$age} let!\n\n"
        . "Nezapomeňte mu/jí popřát.\n\n"
        . "S pozdravem\nTrainerApp – automatické notifikace\n";

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        _configureMail($mail);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Narozeniny: ' . $fullName . ' slaví dnes ' . $age . ' let!';
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('sendBirthdayTodayEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
        return false;
    }
}

/**
 * Nakonfiguruje PHPMailer instanci dle SMTP_HOST:
 * - 'localhost' nebo prázdný host → isMail() (PHP mail(), bez auth, Wedos hosting)
 * - jinak → isSMTP() s STARTTLS a autentizací
 */
function _configureMail(object $mail): void {
    $host = defined('SMTP_HOST') ? SMTP_HOST : '';
    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
        $mail->isMail();
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        return;
    }
    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->AuthType   = 'LOGIN';
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $mail->CharSet    = 'UTF-8';
    $mail->SMTPOptions = ['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
        'ciphers'           => 'DEFAULT:@SECLEVEL=0',
    ]];
    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
}

/**
 * Odešle trenérovi email s informací o nové zprávě v aplikaci.
 * @param string $toEmail   Email trenéra
 * @param string $coachName Jméno trenéra
 * @param string $subject   Předmět zprávy
 * @param int    $messageId ID zprávy (pro odkaz)
 * @return bool
 */
function sendMessageNotificationEmail(string $toEmail, string $coachName, string $subject, int $messageId): bool {
    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
        return false;
    }
    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $link = 'https://reservio.online/zpravy.php';

    $htmlBody = "<p>Dobrý den, <strong>" . htmlspecialchars($coachName, ENT_QUOTES) . "</strong>,</p>"
        . "<p>obdrželi jste novou zprávu v aplikaci <strong>TrainerApp</strong>.</p>"
        . "<p><strong>Předmět:</strong> " . htmlspecialchars($subject, ENT_QUOTES) . "</p>"
        . "<p><a href=\"{$link}\" style=\"background:#0d6efd;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none\">Přejít do aplikace TrainerApp</a></p>"
        . "<hr><p style=\"color:#888;font-size:.85em\">TrainerApp – automatické notifikace</p>";

    $altBody = "Dobrý den, {$coachName},\n\n"
        . "obdrželi jste novou zprávu v aplikaci TrainerApp.\n"
        . "Předmět: {$subject}\n\n"
        . "Přejít do aplikace: https://reservio.online/zpravy.php\n\n"
        . "TrainerApp – automatické notifikace";

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        _configureMail($mail);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Nová zpráva v TrainerApp: ' . $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('sendMessageNotificationEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
        return false;
    }
}

  /**
   * Odešle sportovci výzvu k zadání aktuální tělesné hmotnosti přes bezpečný odkaz.
   */
  function sendAthleteWeightInviteEmail(
    string $toEmail,
    string $athleteName,
    string $coachName,
    string $entryUrl,
    string $expiresAt
  ): bool {
    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
      return false;
    }
    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $safeAthleteName = htmlspecialchars($athleteName, ENT_QUOTES, 'UTF-8');
    $safeCoachName = htmlspecialchars($coachName, ENT_QUOTES, 'UTF-8');
    $safeEntryUrl = htmlspecialchars($entryUrl, ENT_QUOTES, 'UTF-8');
    $expiresText = formatDateTime($expiresAt);

    $htmlBody = "<p>Ahoj <strong>{$safeAthleteName}</strong>,</p>"
      . "<p>trenér <strong>{$safeCoachName}</strong> tě žádá o zadání aktuální tělesné hmotnosti.</p>"
      . "<p><a href=\"{$safeEntryUrl}\" style=\"background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block\">"
      . "Zadat aktuální hmotnost</a></p>"
      . "<p>Odkaz je platný do <strong>" . htmlspecialchars($expiresText, ENT_QUOTES, 'UTF-8') . "</strong>.</p>"
      . "<hr><p style=\"color:#777;font-size:.9em\">TrainerApp – automatická výzva</p>";

    $altBody = "Ahoj {$athleteName},\n\n"
      . "trenér {$coachName} tě žádá o zadání aktuální tělesné hmotnosti.\n"
      . "Vyplň ji zde: {$entryUrl}\n"
      . "Odkaz je platný do {$expiresText}.\n\n"
      . "TrainerApp";

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
      _configureMail($mail);
      $mail->addAddress($toEmail);
      $mail->isHTML(true);
      $mail->Subject = 'Výzva k zadání tělesné hmotnosti';
      $mail->Body = $htmlBody;
      $mail->AltBody = $altBody;
      $mail->send();
      return true;
    } catch (\Exception $e) {
      error_log('sendAthleteWeightInviteEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
      return false;
    }
  }

/**
 * Odešle testovací email. Vrátí 'ok' nebo chybovou zprávu.
 */
function sendTestEmail(string $toEmail): string {
    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
        return 'PHPMailer nebyl nalezen.';
    }
    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $host     = defined('SMTP_HOST') ? SMTP_HOST : '';
    $useSendmail = ($host === '' || $host === 'localhost' || $host === '127.0.0.1');

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $debugLog = '';

    try {
        if ($useSendmail) {
            $mail->isMail();
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        } else {
            $mail->isSMTP();
            $mail->SMTPDebug   = 3;
            $mail->Debugoutput = function (string $str, int $level) use (&$debugLog): void {
                $debugLog .= trim($str) . "\n";
            };
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->AuthType   = 'LOGIN';
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
            $mail->CharSet    = 'UTF-8';
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'ciphers' => 'DEFAULT:@SECLEVEL=0']];
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        }

        $mail->addAddress($toEmail);
        $mail->isHTML(false);
        $mail->Subject = 'Testovací e-mail z TrainerApp';
        $mail->Body    = "Tento e-mail potvrzuje, že notifikace fungují.\n\nMód: "
            . ($useSendmail ? 'sendmail/mail()' : ($host . ':' . SMTP_PORT))
            . "\nOdesílatel: " . SMTP_FROM;
        $mail->send();
        return 'ok';
    } catch (\Exception $e) {
        $err = $mail->ErrorInfo ?: $e->getMessage();
        $lines = array_filter(explode("\n", $debugLog), function (string $l): bool {
            return $l !== '' && !str_contains($l, 'CLIENT ->') && !str_contains($l, 'Connection:');
        });
        $debugSummary = implode(' | ', array_slice(array_values($lines), -6));
        return $err . ($debugSummary ? ' [DEBUG: ' . $debugSummary . ']' : '');
    }
}

/**
 * Vrátí nebo vygeneruje secret token pro cron URL.
 */
function getCronSecret(): string {
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = 'cron_secret'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && $row['value'] !== '') {
        $secret = $row['value'];
        return $secret;
    }
    $secret = bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('cron_secret', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$secret]);
    return $secret;
}

/**
 * Zpracuje narozeninové notifikace:
 * - Za 4 dny narozeniny  → email trenérovi (upozornění)
 * - Dnes narozeniny       → email trenérovi (gratulace)
 * Zabraňuje duplicitám přes tabulku birthday_notifications.
 *
 * @return array[]['type','athlete','coach_email','sent','age'?,'error'?]
 */
function processBirthdayNotifications(): array {
    $pdo     = getDB();
    $results = [];

    // Sportovci s narozeninami za 4 dny – pouze pokud upozornění ještě nebylo odesláno letos
    $warnRows = $pdo->query(
        "SELECT a.id, a.first_name, a.last_name, a.birth_date,
                c.email AS coach_email, c.name AS coach_name, c.username AS coach_username
         FROM athletes a
         JOIN coaches c ON c.id = a.coach_id
         WHERE a.birth_date IS NOT NULL
           AND DATE_FORMAT(a.birth_date, '%m-%d') = DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 4 DAY), '%m-%d')
           AND c.email IS NOT NULL AND c.email != ''
           AND NOT EXISTS (
               SELECT 1 FROM birthday_notifications bn
               WHERE bn.athlete_id = a.id
                 AND bn.notification_type = 'warning'
                 AND bn.year = YEAR(CURDATE())
           )"
    )->fetchAll();

    foreach ($warnRows as $a) {
        $coachName = $a['coach_name'] ?: $a['coach_username'];
        $sent = sendBirthdayWarningEmail(
            $a['coach_email'], $coachName,
            $a['first_name'], $a['last_name'],
            $a['birth_date'], 4
        );
        if ($sent) {
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO birthday_notifications (athlete_id, notification_type, year, sent_at)
                     VALUES (?, 'warning', YEAR(CURDATE()), NOW())"
                )->execute([(int)$a['id']]);
            } catch (\Throwable $e) {
                error_log('birthday_notifications insert error: ' . $e->getMessage());
            }
        }
        $results[] = [
            'type'        => 'warning',
            'athlete'     => $a['first_name'] . ' ' . $a['last_name'],
            'coach_email' => $a['coach_email'],
            'sent'        => $sent,
        ];
    }

    // Sportovci s narozeninami dnes – pouze pokud gratulace ještě nebyla odeslána letos
    $todayRows = $pdo->query(
        "SELECT a.id, a.first_name, a.last_name, a.birth_date,
                c.email AS coach_email, c.name AS coach_name, c.username AS coach_username
         FROM athletes a
         JOIN coaches c ON c.id = a.coach_id
         WHERE a.birth_date IS NOT NULL
           AND DATE_FORMAT(a.birth_date, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
           AND c.email IS NOT NULL AND c.email != ''
           AND NOT EXISTS (
               SELECT 1 FROM birthday_notifications bn
               WHERE bn.athlete_id = a.id
                 AND bn.notification_type = 'birthday'
                 AND bn.year = YEAR(CURDATE())
           )"
    )->fetchAll();

    foreach ($todayRows as $a) {
        $coachName = $a['coach_name'] ?: $a['coach_username'];
        $age = 0;
        try {
            $age = (int)(new DateTime())->diff(new DateTime($a['birth_date']))->y;
        } catch (\Exception $e) {}

        $sent = sendBirthdayTodayEmail(
            $a['coach_email'], $coachName,
            $a['first_name'], $a['last_name'],
            $age
        );
        if ($sent) {
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO birthday_notifications (athlete_id, notification_type, year, sent_at)
                     VALUES (?, 'birthday', YEAR(CURDATE()), NOW())"
                )->execute([(int)$a['id']]);
            } catch (\Throwable $e) {
                error_log('birthday_notifications insert error: ' . $e->getMessage());
            }
        }
        $results[] = [
            'type'        => 'birthday',
            'athlete'     => $a['first_name'] . ' ' . $a['last_name'],
            'coach_email' => $a['coach_email'],
            'sent'        => $sent,
            'age'         => $age,
        ];
    }

    return $results;
}
