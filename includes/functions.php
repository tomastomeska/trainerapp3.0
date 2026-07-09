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

function mealTypeOptions(): array {
  return [
    'breakfast' => 'Snídaně',
    'snack' => 'Svačina',
    'lunch' => 'Oběd',
    'dinner' => 'Večeře',
    'second_dinner' => 'Druhá večeře',
    'post_workout' => 'Po tréninku',
    'cheat_day' => 'Cheat day',
  ];
}

function mealDayOptions(): array {
  return [
    'monday' => 'Pondělí',
    'tuesday' => 'Úterý',
    'wednesday' => 'Středa',
    'thursday' => 'Čtvrtek',
    'friday' => 'Pátek',
    'saturday' => 'Sobota',
    'sunday' => 'Neděle',
  ];
}

function mealDayOrder(): array {
  return [
    'monday' => 1,
    'tuesday' => 2,
    'wednesday' => 3,
    'thursday' => 4,
    'friday' => 5,
    'saturday' => 6,
    'sunday' => 7,
  ];
}

function mealTypeLabel(string $type): string {
  $types = mealTypeOptions();
  return $types[$type] ?? $type;
}

function mealDayLabel(string $day): string {
  $days = mealDayOptions();
  return $days[$day] ?? $day;
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

function formatSeriesDuration(int $seconds): string {
  $seconds = max(0, $seconds);
  $hours = intdiv($seconds, 3600);
  $minutes = intdiv($seconds % 3600, 60);
  $restSeconds = $seconds % 60;

  if ($hours > 0) {
    return sprintf('%d:%02d:%02d', $hours, $minutes, $restSeconds);
  }

  return sprintf('%d:%02d', $minutes, $restSeconds);
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

  function ensureFlexibleWorkoutSet(int $coachId): int {
    $pdo = getDB();

    $stmt = $pdo->prepare(
      'SELECT id
       FROM workout_sets
       WHERE coach_id = ? AND name = ?
       LIMIT 1'
    );
    $stmt->execute([$coachId, 'Flexibilní sada']);
    $existingId = (int)$stmt->fetchColumn();
    if ($existingId > 0) {
      return $existingId;
    }

    $insert = $pdo->prepare('INSERT INTO workout_sets (coach_id, name) VALUES (?, ?)');
    $insert->execute([$coachId, 'Flexibilní sada']);

    return (int)$pdo->lastInsertId();
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
      $needsSportType = false;
      $needsTimed = false;
        foreach ($snapshotRows as $row) {
            if (!isset($row['sport_type']) || $row['sport_type'] === '' || $row['sport_type'] === null) {
                $needsSportType = true;
            }
            if (!isset($row['is_timed']) || $row['is_timed'] === '' || $row['is_timed'] === null) {
                $needsTimed = true;
            }
        }

      // Kompatibilita: některé session byly založeny bez is_timed ve snapshotu (výchozí 0).
      if (!$needsTimed) {
        $hasTimedExerciseInDb = false;
        $ids = array_values(array_unique(array_map(fn($row) => (int)$row['exercise_id'], $snapshotRows)));
        if (!empty($ids)) {
          $inClause = implode(',', array_fill(0, count($ids), '?'));
          $stmtAnyTimed = $pdo->prepare("SELECT COUNT(*) FROM exercises WHERE id IN ($inClause) AND is_timed = 1");
          $stmtAnyTimed->execute($ids);
          $hasTimedExerciseInDb = ((int)$stmtAnyTimed->fetchColumn()) > 0;
        }
        if ($hasTimedExerciseInDb) {
          $needsTimed = true;
        }
      }

        if ($needsSportType || $needsTimed) {
            $ids = array_values(array_unique(array_map(fn($row) => (int)$row['exercise_id'], $snapshotRows)));
            if (!empty($ids)) {
                $inClause = implode(',', array_fill(0, count($ids), '?'));
                $stmtTypes = $pdo->prepare("SELECT id, sport_type, is_timed FROM exercises WHERE id IN ($inClause)");
                $stmtTypes->execute($ids);
                $metaById = [];
                foreach ($stmtTypes->fetchAll() as $typeRow) {
                    $metaById[(int)$typeRow['id']] = [
                        'sport_type' => $typeRow['sport_type'] ?? 'standard',
                        'is_timed' => (int)($typeRow['is_timed'] ?? 0),
                    ];
                }
                foreach ($snapshotRows as &$row) {
                    $exerciseMeta = $metaById[(int)$row['exercise_id']] ?? ['sport_type' => 'standard', 'is_timed' => 0];
                    if (!isset($row['sport_type']) || $row['sport_type'] === '' || $row['sport_type'] === null) {
                        $row['sport_type'] = $exerciseMeta['sport_type'];
                    }
                  if (in_array(($row['sport_type'] ?? 'standard'), ['golf', 'run_outdoor', 'run_treadmill'], true)) {
                    $row['sport_type'] = 'standard';
                  }
                    if (!isset($row['is_timed']) || $row['is_timed'] === '' || $row['is_timed'] === null) {
                        $row['is_timed'] = $exerciseMeta['is_timed'];
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
 *
 * @param \GdImage|resource $img
 * @return \GdImage|resource
 */
function _applyExifOrientation($img, int $orientation) {
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
          $weight = number_format((float)$s['weight'] + (float)($s['equipment_weight'] ?? 0), 1, ',', '') . ' kg';
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
 * Odešle sportovci e-mailovou výzvu k platbě včetně QR kódu.
 */
function sendPaymentRequestEmail(string $toEmail, array $data): bool {
    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
        error_log('sendPaymentRequestEmail: PHPMailer not found at ' . $phpmailerSrc);
        return false;
    }

    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $h = fn(?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

    $athleteName = (string)($data['athlete_name'] ?? 'Sportovec');
    $coachName = (string)($data['coach_name'] ?? 'Váš trenér');
    $monthLabel = (string)($data['month_label'] ?? '');
    $amountText = (string)($data['amount_text'] ?? '');
    $account = (string)($data['account'] ?? '');
    $note = (string)($data['note'] ?? '');
    $qrUrl = (string)($data['qr_url'] ?? '');

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Výzva k platbě</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:28px 0;">
    <tr><td align="center">
      <table width="100%" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);">
        <tr>
          <td style="background:linear-gradient(135deg,#7c3aed,#a78bfa);padding:32px 36px;text-align:center;">
            <div style="font-size:34px;margin-bottom:8px;">💳</div>
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Výzva k platbě</h1>
            <p style="margin:8px 0 0;color:#e9d5ff;font-size:13px;">Tréninky za období {$h($monthLabel)}</p>
          </td>
        </tr>
        <tr>
          <td style="padding:28px 34px;">
            <p style="margin:0 0 14px;color:#374151;font-size:15px;">Dobrý den, {$h($athleteName)},</p>
            <p style="margin:0 0 20px;color:#374151;font-size:15px;line-height:1.6;">
              zasílám výzvu k platbě za tréninky za období <strong>{$h($monthLabel)}</strong>.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:20px;">
              <tr>
                <td style="padding:16px 18px;">
                  <div style="font-size:13px;color:#6b7280;margin-bottom:6px;">Částka</div>
                  <div style="font-size:24px;font-weight:800;color:#111827;">{$h($amountText)}</div>
                  <div style="margin-top:10px;font-size:13px;color:#374151;"><strong>Účet:</strong> {$h($account)}</div>
                  <div style="margin-top:6px;font-size:13px;color:#374151;"><strong>Poznámka:</strong> {$h($note)}</div>
                </td>
              </tr>
            </table>

            <div style="text-align:center;margin-bottom:16px;">
              <img src="{$h($qrUrl)}" alt="QR platba" width="220" height="220" style="display:inline-block;border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#ffffff;">
            </div>

            <p style="margin:0;color:#6b7280;font-size:13px;line-height:1.5;">Pokud se obrázek QR nezobrazil, můžete použít tento odkaz:</p>
            <p style="margin:6px 0 0;font-size:12px;word-break:break-all;"><a href="{$h($qrUrl)}">{$h($qrUrl)}</a></p>

            <p style="margin:20px 0 0;color:#374151;font-size:14px;">S pozdravem,<br><strong>{$h($coachName)}</strong></p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $altBody =
        "Výzva k platbě za tréninky {$monthLabel}\n\n"
        . "Sportovec: {$athleteName}\n"
        . "Částka: {$amountText}\n"
        . "Účet: {$account}\n"
        . "Poznámka: {$note}\n"
        . "QR: {$qrUrl}\n\n"
        . "S pozdravem\n{$coachName}\n";

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        _configureMail($mail);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Výzva k platbě - ' . $monthLabel;
        $mail->Body = $htmlBody;
        $mail->AltBody = $altBody;
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('sendPaymentRequestEmail error: ' . $mail->ErrorInfo . ' | Exception: ' . $e->getMessage());
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

function generateRandomPassword(int $length = 12): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
  $max = strlen($alphabet) - 1;
  $password = '';
  for ($i = 0; $i < max(8, $length); $i++) {
    $password .= $alphabet[random_int(0, $max)];
  }
  return $password;
}

function sendAthleteWelcomeEmail(string $toEmail, string $athleteName, string $password, string $loginUrl): bool {
  $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
  if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
    return false;
  }
  require_once $phpmailerSrc . '/Exception.php';
  require_once $phpmailerSrc . '/PHPMailer.php';
  require_once $phpmailerSrc . '/SMTP.php';

  $safeName = htmlspecialchars($athleteName, ENT_QUOTES, 'UTF-8');
  $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

  $htmlBody = "<p>Ahoj <strong>{$safeName}</strong>,</p>"
    . "<p>trenér ti vytvořil přístup do aplikace TrainerApp.</p>"
    . "<p><strong>Přihlašovací jméno:</strong> " . htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8') . "<br>"
    . "<strong>Dočasné heslo:</strong> " . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . "</p>"
    . "<p>Po prvním přihlášení bude vyžadována změna hesla.</p>"
    . "<p><a href=\"{$safeLoginUrl}\" style=\"background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block\">"
    . "Přihlásit se</a></p>"
    . "<hr><p style=\"color:#777;font-size:.9em\">TrainerApp</p>";

  $altBody = "Ahoj {$athleteName},\n\n"
    . "trenér ti vytvořil přístup do aplikace TrainerApp.\n"
    . "Přihlašovací jméno: {$toEmail}\n"
    . "Dočasné heslo: {$password}\n\n"
    . "Po prvním přihlášení bude vyžadována změna hesla.\n"
    . "Přihlášení: {$loginUrl}\n\n"
    . "TrainerApp";

  $mail = new PHPMailer\PHPMailer\PHPMailer(true);
  try {
    _configureMail($mail);
    $mail->addAddress($toEmail);
    $mail->isHTML(true);
    $mail->Subject = 'Přístup do TrainerApp';
    $mail->Body = $htmlBody;
    $mail->AltBody = $altBody;
    $mail->send();
    return true;
  } catch (\Exception $e) {
    error_log('sendAthleteWelcomeEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
    return false;
  }
}

function sendAthleteCalendarNotificationEmail(string $toEmail, string $athleteName, string $subject, string $message): bool {
  $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
  if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
    return false;
  }
  require_once $phpmailerSrc . '/Exception.php';
  require_once $phpmailerSrc . '/PHPMailer.php';
  require_once $phpmailerSrc . '/SMTP.php';

  $safeName = htmlspecialchars($athleteName, ENT_QUOTES, 'UTF-8');
  $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

  $htmlBody = "<p>Ahoj <strong>{$safeName}</strong>,</p>"
    . "<p>{$safeMessage}</p>"
    . "<p>Detail najdeš po přihlášení do TrainerApp.</p>"
    . "<hr><p style=\"color:#777;font-size:.9em\">TrainerApp – kalendář</p>";

  $mail = new PHPMailer\PHPMailer\PHPMailer(true);
  try {
    _configureMail($mail);
    $mail->addAddress($toEmail);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = "Ahoj {$athleteName},\n\n{$message}\n\nTrainerApp";
    $mail->send();
    return true;
  } catch (\Exception $e) {
    error_log('sendAthleteCalendarNotificationEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
    return false;
  }
}

function createCoachSystemMessage(int $coachId, string $subject, string $body, bool $sendEmail = true): ?int {
  $pdo = getDB();
  $ins = $pdo->prepare("INSERT INTO admin_messages (subject, body, sent_at, message_source) VALUES (?, ?, NOW(), 'system')");
  $ins->execute([$subject, $body]);
  $messageId = (int)$pdo->lastInsertId();

  $recipient = $pdo->prepare("INSERT IGNORE INTO admin_message_recipients (message_id, coach_id, status) VALUES (?, ?, 'inbox')");
  $recipient->execute([$messageId, $coachId]);

  if ($sendEmail) {
    $coachStmt = $pdo->prepare('SELECT name, username, email FROM coaches WHERE id = ?');
    $coachStmt->execute([$coachId]);
    $coach = $coachStmt->fetch();
    if ($coach && !empty($coach['email'])) {
      $coachName = ($coach['name'] ?? '') !== '' ? (string)$coach['name'] : (string)($coach['username'] ?? 'trenér');
      sendMessageNotificationEmail((string)$coach['email'], $coachName, $subject, $messageId);
    }
  }

  return $messageId;
}

function createAthleteNotification(int $athleteId, string $subject, string $body): int {
  $pdo = getDB();
  $stmt = $pdo->prepare('INSERT INTO athlete_notifications (athlete_id, subject, body) VALUES (?, ?, ?)');
  $stmt->execute([$athleteId, $subject, $body]);
  return (int)$pdo->lastInsertId();
}

/**
 * Sportovec pošle zprávu trenérovi. Uloží do admin_messages (trenér ji uvidí v zprávy.php),
 * zároveň nastaví from_athlete_id pro detekci odpovědi.
 */
function createAthleteToCoachMessage(int $athleteId, int $coachId, string $subject, string $body): int {
  $pdo = getDB();
  $ins = $pdo->prepare("INSERT INTO admin_messages (subject, body, from_athlete_id, sent_at, message_source) VALUES (?, ?, ?, NOW(), 'athlete')");
  $ins->execute([$subject, $body, $athleteId]);
  $messageId = (int)$pdo->lastInsertId();

  $pdo->prepare("INSERT IGNORE INTO admin_message_recipients (message_id, coach_id, status) VALUES (?, ?, 'inbox')")
      ->execute([$messageId, $coachId]);

  return $messageId;
}

/**
 * Odešle superadminům e-mailovou notifikaci o novém ticketu podpory.
 * Vrací počet úspěšně odeslaných e-mailů.
 */
function sendSupportTicketNotificationEmail(int $ticketId, array $ticket, array $extraRecipients = []): int {
  $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
  if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
    return 0;
  }
  require_once $phpmailerSrc . '/Exception.php';
  require_once $phpmailerSrc . '/PHPMailer.php';
  require_once $phpmailerSrc . '/SMTP.php';

  $pdo = getDB();
  $admins = $pdo->query("SELECT email FROM superadmins WHERE email IS NOT NULL AND email <> ''")->fetchAll();
  if (empty($admins)) {
    return 0;
  }

  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
  $scheme = $isHttps ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  $ticketUrl = $scheme . '://' . $host . BASE_URL . '/admin/podpora.php?id=' . $ticketId;

  $reporter = htmlspecialchars((string)($ticket['reporter_name'] ?? 'Uživatel'), ENT_QUOTES, 'UTF-8');
  $reporterEmail = htmlspecialchars((string)($ticket['reporter_email'] ?? ''), ENT_QUOTES, 'UTF-8');
  $subject = htmlspecialchars((string)($ticket['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
  $issueType = htmlspecialchars((string)($ticket['issue_type'] ?? ''), ENT_QUOTES, 'UTF-8');
  $description = nl2br(htmlspecialchars((string)($ticket['description'] ?? ''), ENT_QUOTES, 'UTF-8'));
  $pageUrl = htmlspecialchars((string)($ticket['page_url'] ?? ''), ENT_QUOTES, 'UTF-8');
  $hasScreenshot = !empty($ticket['screenshot_path']);

  $htmlBody = "<p>Dobrý den,</p>"
    . "<p>v aplikaci byl vytvořen nový ticket podpory <strong>#{$ticketId}</strong>.</p>"
    . "<p><strong>Odesílatel:</strong> {$reporter}"
    . ($reporterEmail !== '' ? " ({$reporterEmail})" : '')
    . "<br><strong>Předmět:</strong> {$subject}"
    . "<br><strong>Typ problému:</strong> {$issueType}"
    . ($pageUrl !== '' ? "<br><strong>Stránka:</strong> {$pageUrl}" : '')
    . "<br><strong>Screenshot:</strong> " . ($hasScreenshot ? 'Ano' : 'Ne')
    . "</p>"
    . "<p><strong>Popis problému:</strong><br>{$description}</p>"
    . "<p><a href=\"{$ticketUrl}\" style=\"background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block\">"
    . "Otevřít tiket v administraci</a></p>"
    . "<hr><p style=\"color:#777;font-size:.9em\">TrainerApp – automatické notifikace</p>";

  $altBody = "Dobrý den,\n\n"
    . "v aplikaci byl vytvořen nový ticket podpory #{$ticketId}.\n\n"
    . "Odesílatel: " . (string)($ticket['reporter_name'] ?? 'Uživatel')
    . (!empty($ticket['reporter_email']) ? ' (' . (string)$ticket['reporter_email'] . ')' : '') . "\n"
    . "Předmět: " . (string)($ticket['subject'] ?? '') . "\n"
    . "Typ problému: " . (string)($ticket['issue_type'] ?? '') . "\n"
    . (!empty($ticket['page_url']) ? "Stránka: " . (string)$ticket['page_url'] . "\n" : '')
    . "Screenshot: " . ($hasScreenshot ? 'Ano' : 'Ne') . "\n\n"
    . "Popis:\n" . (string)($ticket['description'] ?? '') . "\n\n"
    . "Detail ticketu: {$ticketUrl}\n\n"
    . "TrainerApp – automatické notifikace";

  $recipientMap = [];
  foreach ($admins as $admin) {
    $to = trim((string)($admin['email'] ?? ''));
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
      $recipientMap[mb_strtolower($to, 'UTF-8')] = $to;
    }
  }
  foreach ($extraRecipients as $extraRecipient) {
    $to = trim((string)$extraRecipient);
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
      $recipientMap[mb_strtolower($to, 'UTF-8')] = $to;
    }
  }

  $sent = 0;
  foreach ($recipientMap as $to) {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
      _configureMail($mail);
      $mail->addAddress($to);
      $mail->isHTML(true);
      $mail->Subject = 'Nový ticket podpory #' . $ticketId . ': ' . (string)($ticket['subject'] ?? '');
      $mail->Body = $htmlBody;
      $mail->AltBody = $altBody;
      $mail->send();
      $sent++;
    } catch (\Exception $e) {
      error_log('sendSupportTicketNotificationEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
    }
  }

  return $sent;
}

function sendCoachAccessRequestOwnerEmail(string $ownerEmail, array $request): bool {
  $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
  if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
    return false;
  }
  require_once $phpmailerSrc . '/Exception.php';
  require_once $phpmailerSrc . '/PHPMailer.php';
  require_once $phpmailerSrc . '/SMTP.php';

  $h = fn(?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
  $name = trim((string)($request['first_name'] ?? '') . ' ' . (string)($request['last_name'] ?? ''));
  $email = (string)($request['email'] ?? '');
  $note = trim((string)($request['note'] ?? ''));
  $createdAt = formatDateTime((string)($request['created_at'] ?? date('Y-m-d H:i:s')));

  $htmlBody = "<p>Dobrý den,</p>"
    . "<p>přišla nová <strong>žádost o přístup trenéra</strong>.</p>"
    . "<p><strong>Jméno:</strong> " . $h($name) . "<br>"
    . "<strong>E-mail:</strong> " . $h($email) . "<br>"
    . "<strong>Čas:</strong> " . $h($createdAt) . "</p>"
    . ($note !== '' ? "<p><strong>Poznámka žadatele:</strong><br>" . nl2br($h($note)) . "</p>" : "")
    . "<hr><p style=\"color:#777;font-size:.9em\">TrainerApp – automatické notifikace</p>";

  $altBody = "Nová žádost o přístup trenéra\n\n"
    . "Jméno: {$name}\n"
    . "E-mail: {$email}\n"
    . "Čas: {$createdAt}\n"
    . ($note !== '' ? "\nPoznámka:\n{$note}\n" : "")
    . "\nTrainerApp – automatické notifikace\n";

  $mail = new PHPMailer\PHPMailer\PHPMailer(true);
  try {
    _configureMail($mail);
    $mail->addAddress($ownerEmail);
    $mail->isHTML(true);
    $mail->Subject = 'Nová žádost o přístup trenéra';
    $mail->Body = $htmlBody;
    $mail->AltBody = $altBody;
    $mail->send();
    return true;
  } catch (\Exception $e) {
    error_log('sendCoachAccessRequestOwnerEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
    return false;
  }
}

function sendPasswordResetEmail(string $toEmail, string $displayName, string $resetUrl, string $accountTypeLabel): bool {
  $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
  if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
    return false;
  }
  require_once $phpmailerSrc . '/Exception.php';
  require_once $phpmailerSrc . '/PHPMailer.php';
  require_once $phpmailerSrc . '/SMTP.php';

  $h = fn(?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

  $htmlBody = "<p>Dobrý den " . $h($displayName) . ",</p>"
    . "<p>obdrželi jsme žádost o reset hesla pro účet typu <strong>" . $h($accountTypeLabel) . "</strong>.</p>"
    . "<p>Pro nastavení nového hesla klikněte na odkaz níže (platnost 60 minut):</p>"
    . "<p><a href=\"" . $h($resetUrl) . "\" style=\"background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block\">"
    . "Nastavit nové heslo</a></p>"
    . "<p>Pokud jste reset hesla nepožadovali, tento e-mail ignorujte.</p>"
    . "<hr><p style=\"color:#777;font-size:.9em\">TrainerApp – automatické notifikace</p>";

  $altBody = "Dobrý den {$displayName},\n\n"
    . "obdrželi jsme žádost o reset hesla pro účet typu {$accountTypeLabel}.\n"
    . "Pro nastavení nového hesla otevřete následující odkaz (platnost 60 minut):\n{$resetUrl}\n\n"
    . "Pokud jste reset hesla nepožadovali, tento e-mail ignorujte.\n\n"
    . "TrainerApp – automatické notifikace\n";

  $mail = new PHPMailer\PHPMailer\PHPMailer(true);
  try {
    _configureMail($mail);
    $mail->addAddress($toEmail);
    $mail->isHTML(true);
    $mail->Subject = 'Reset hesla - TrainerApp';
    $mail->Body = $htmlBody;
    $mail->AltBody = $altBody;
    $mail->send();
    return true;
  } catch (\Exception $e) {
    error_log('sendPasswordResetEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
    return false;
  }
}

function getAthleteWeightLogById(int $logId, int $athleteId = 0): ?array {
  $pdo = getDB();
  $sql = 'SELECT * FROM athlete_weight_logs WHERE id = ?';
  $params = [$logId];

  if ($athleteId > 0) {
    $sql .= ' AND athlete_id = ?';
    $params[] = $athleteId;
  }

  $sql .= ' LIMIT 1';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  return $stmt->fetch() ?: null;
}

function updateAthleteWeightLog(int $logId, int $athleteId, string $measuredAt, float $weightKg): bool {
  if (!getAthleteWeightLogById($logId, $athleteId)) {
    return false;
  }

  $pdo = getDB();
  $stmt = $pdo->prepare(
    'UPDATE athlete_weight_logs
     SET measured_at = ?, weight_kg = ?
     WHERE id = ? AND athlete_id = ?'
  );

  return $stmt->execute([$measuredAt, $weightKg, $logId, $athleteId]);
}

function deleteAthleteWeightLog(int $logId, int $athleteId): bool {
  if (!getAthleteWeightLogById($logId, $athleteId)) {
    return false;
  }

  $pdo = getDB();
  $stmt = $pdo->prepare('DELETE FROM athlete_weight_logs WHERE id = ? AND athlete_id = ?');

  return $stmt->execute([$logId, $athleteId]);
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
                c.id AS coach_id,
                c.email AS coach_email, c.name AS coach_name, c.username AS coach_username
         FROM athletes a
         JOIN coaches c ON c.id = a.coach_id
         WHERE a.birth_date IS NOT NULL
           AND DATE_FORMAT(a.birth_date, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
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

        $fullName = trim((string)$a['first_name'] . ' ' . (string)$a['last_name']);
        $messageSubject = 'Narozeniny: ' . $fullName;
        $messageBody = 'Dnes má narozeniny váš sportovec ' . $fullName
            . ' (' . $age . ' let). Nezapomeňte mu/jí popřát.';
        $messageId = createCoachSystemMessage((int)$a['coach_id'], $messageSubject, $messageBody, false);

        $canSendEmail = !empty($a['coach_email']) && filter_var((string)$a['coach_email'], FILTER_VALIDATE_EMAIL);
        $sent = false;
        if ($canSendEmail) {
            $sent = sendBirthdayTodayEmail(
                $a['coach_email'], $coachName,
                $a['first_name'], $a['last_name'],
                $age
            );
        }

        if ($sent || $messageId !== null) {
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
          'sent'        => ($sent || $messageId !== null),
            'age'         => $age,
        ];
    }

    return $results;
}

/**
 * Vrátí plánované kalendářové tréninky trenéra v daném intervalu.
 *
 * @return array[]
 */
function getCoachCalendarEventsInRange(int $coachId, DateTimeInterface $from, DateTimeInterface $to): array {
  $pdo = getDB();
  $stmt = $pdo->prepare(
    "SELECT e.id,
        e.starts_at,
        e.ends_at,
        e.location,
        e.custom_title,
        a.first_name,
        a.last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     WHERE e.coach_id = ?
       AND e.approval_status = 'approved'
       AND e.starts_at >= ?
       AND e.starts_at < ?
     ORDER BY e.starts_at ASC, e.id ASC"
  );
  $stmt->execute([
    $coachId,
    $from->format('Y-m-d H:i:s'),
    $to->format('Y-m-d H:i:s'),
  ]);

  return $stmt->fetchAll();
}

/**
 * Odešle trenérovi e-mail s přehledem tréninků z kalendáře.
 */
function sendCoachCalendarDigestEmail(
  string $toEmail,
  string $coachName,
  string $subject,
  string $periodLabel,
  array $events
): bool {
  $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
  if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
    error_log('sendCoachCalendarDigestEmail: PHPMailer not found at ' . $phpmailerSrc);
    return false;
  }

  require_once $phpmailerSrc . '/Exception.php';
  require_once $phpmailerSrc . '/PHPMailer.php';
  require_once $phpmailerSrc . '/SMTP.php';

  $h = static fn(?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

  $rowsHtml = '';
  $rowsPlain = '';
  foreach ($events as $event) {
    $person = trim((string)($event['last_name'] ?? '') . ' ' . (string)($event['first_name'] ?? ''));
    if ($person === '') {
      $person = (string)($event['custom_title'] ?? 'Trénink bez názvu');
    }

    $start = formatDateTime($event['starts_at'] ?? null);
    $end = formatDateTime($event['ends_at'] ?? null);
    $location = trim((string)($event['location'] ?? ''));
    $locationText = $location !== '' ? $location : 'Bez místa';

    $rowsHtml .= '<tr>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">' . $h($start) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">' . $h($end) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-weight:600;">' . $h($person) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;">' . $h($locationText) . '</td>'
      . '</tr>';

    $rowsPlain .= '- ' . $start . ' - ' . $end . ' | ' . $person . ' | ' . $locationText . "\n";
  }

  if ($rowsHtml === '') {
    $rowsHtml = '<tr><td colspan="4" style="padding:12px;color:#6b7280;">V daném období nemáte žádný naplánovaný trénink.</td></tr>';
    $rowsPlain = "- V daném období nemáte žádný naplánovaný trénink.\n";
  }

  $htmlBody = '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"></head><body style="font-family:Arial,Helvetica,sans-serif;background:#f4f4f7;padding:20px;">'
    . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:700px;margin:0 auto;background:#fff;border-radius:10px;border:1px solid #e5e7eb;">'
    . '<tr><td style="padding:20px 24px;background:#111827;color:#fff;border-radius:10px 10px 0 0;">'
    . '<h2 style="margin:0;font-size:20px;">Přehled tréninků</h2>'
    . '<p style="margin:6px 0 0;color:#d1d5db;">' . $h($periodLabel) . '</p>'
    . '</td></tr>'
    . '<tr><td style="padding:16px 24px;color:#374151;">Dobrý den, <strong>' . $h($coachName) . '</strong>, posíláme přehled naplánovaných tréninků.</td></tr>'
    . '<tr><td style="padding:0 24px 24px;">'
    . '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
    . '<thead><tr style="background:#f9fafb;color:#374151;">'
    . '<th align="left" style="padding:10px 12px;">Začátek</th>'
    . '<th align="left" style="padding:10px 12px;">Konec</th>'
    . '<th align="left" style="padding:10px 12px;">Sportovec / název</th>'
    . '<th align="left" style="padding:10px 12px;">Místo</th>'
    . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
    . '</td></tr>'
    . '</table></body></html>';

  $altBody = "Dobrý den, {$coachName},\n\n"
    . "přehled tréninků ({$periodLabel}):\n"
    . $rowsPlain
    . "\nTrainerApp\n";

  $mail = new PHPMailer\PHPMailer\PHPMailer(true);
  try {
    _configureMail($mail);
    $mail->addAddress($toEmail);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = $altBody;
    $mail->send();
    return true;
  } catch (\Exception $e) {
    error_log('sendCoachCalendarDigestEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
    return false;
  }
}

/**
 * Zpracuje kalendářové digest e-maily trenérům.
 * - každý den po 18:00: přehled zítřejších tréninků
 * - pátek odpoledne: přehled příštího týdne (Po-Ne)
 *
 * @return array[]
 */
function processCoachCalendarDigestNotifications(?DateTimeImmutable $now = null): array {
  $pdo = getDB();
  $results = [];
  $now = $now ?? new DateTimeImmutable('now');

  $shouldSendDaily = (int)$now->format('G') >= 18;
  $shouldSendWeekly = ((int)$now->format('N') === 5) && ((int)$now->format('G') >= 12);

  if (!$shouldSendDaily && !$shouldSendWeekly) {
    return $results;
  }

  $coaches = $pdo->query(
    "SELECT id, name, username, email
     FROM coaches
     WHERE is_active = 1
       AND email IS NOT NULL
       AND email <> ''"
  )->fetchAll();

  $checkSentStmt = $pdo->prepare(
    'SELECT id
     FROM coach_calendar_digest_notifications
     WHERE coach_id = ?
       AND digest_type = ?
       AND digest_date = ?
     LIMIT 1'
  );

  $insertSentStmt = $pdo->prepare(
    'INSERT INTO coach_calendar_digest_notifications (coach_id, digest_type, digest_date, sent_at)
     VALUES (?, ?, ?, NOW())'
  );

  foreach ($coaches as $coach) {
    $coachId = (int)$coach['id'];
    $coachName = (string)($coach['name'] ?: $coach['username']);
    $coachEmail = (string)$coach['email'];

    if ($shouldSendDaily) {
      $tomorrowStart = $now->modify('tomorrow')->setTime(0, 0, 0);
      $tomorrowEnd = $tomorrowStart->modify('+1 day');
      $digestDate = $tomorrowStart->format('Y-m-d');

      $checkSentStmt->execute([$coachId, 'daily_tomorrow', $digestDate]);
      if (!$checkSentStmt->fetch()) {
        $events = getCoachCalendarEventsInRange($coachId, $tomorrowStart, $tomorrowEnd);
        $sent = sendCoachCalendarDigestEmail(
          $coachEmail,
          $coachName,
          'Zítřejší přehled tréninků',
          'Zítra: ' . $tomorrowStart->format('d.m.Y'),
          $events
        );

        if ($sent) {
          $insertSentStmt->execute([$coachId, 'daily_tomorrow', $digestDate]);
        }

        $results[] = [
          'type' => 'daily_tomorrow',
          'coach_id' => $coachId,
          'coach_email' => $coachEmail,
          'digest_date' => $digestDate,
          'events_count' => count($events),
          'sent' => $sent,
        ];
      }
    }

    if ($shouldSendWeekly) {
      $nextWeekMonday = $now->modify('next monday')->setTime(0, 0, 0);
      $nextWeekEnd = $nextWeekMonday->modify('+7 days');
      $digestDate = $nextWeekMonday->format('Y-m-d');

      $checkSentStmt->execute([$coachId, 'weekly_next_week', $digestDate]);
      if (!$checkSentStmt->fetch()) {
        $events = getCoachCalendarEventsInRange($coachId, $nextWeekMonday, $nextWeekEnd);
        $sent = sendCoachCalendarDigestEmail(
          $coachEmail,
          $coachName,
          'Přehled tréninků na příští týden',
          'Příští týden: ' . $nextWeekMonday->format('d.m.Y') . ' - ' . $nextWeekEnd->modify('-1 day')->format('d.m.Y'),
          $events
        );

        if ($sent) {
          $insertSentStmt->execute([$coachId, 'weekly_next_week', $digestDate]);
        }

        $results[] = [
          'type' => 'weekly_next_week',
          'coach_id' => $coachId,
          'coach_email' => $coachEmail,
          'digest_date' => $digestDate,
          'events_count' => count($events),
          'sent' => $sent,
        ];
      }
    }
  }

  return $results;
}

function buildCalendarSummaryAthleteLabel(array $event): string {
  $names = [];
  $first = trim((string)($event['first_name'] ?? ''));
  $last = trim((string)($event['last_name'] ?? ''));
  $secondFirst = trim((string)($event['second_first_name'] ?? ''));
  $secondLast = trim((string)($event['second_last_name'] ?? ''));

  if ($last !== '' || $first !== '') {
    $names[] = trim($last . ' ' . $first);
  }
  if ($secondLast !== '' || $secondFirst !== '') {
    $names[] = trim($secondLast . ' ' . $secondFirst);
  }

  return implode(' + ', array_filter($names));
}

function buildCalendarSummaryTypeLabel(array $event): string {
  $customTitle = trim((string)($event['custom_title'] ?? ''));
  if ($customTitle !== '') {
    return $customTitle;
  }

  $hasPrimary = (int)($event['athlete_id'] ?? 0) > 0;
  $hasSecond = (int)($event['second_athlete_id'] ?? 0) > 0;
  if ($hasPrimary && $hasSecond) {
    return 'Párový trénink';
  }
  if ($hasPrimary) {
    return 'Trénink';
  }
  return 'Rezervace';
}

function buildCalendarSummaryStatusLabel(array $event): string {
  $status = ((string)($event['approval_status'] ?? 'approved') === 'pending')
    ? 'Zatím neschválený'
    : 'Schválený';

  if (!empty($event['is_makeup_session'])) {
    $status .= ' • Náhradní';
  }

  return $status;
}

function getCoachCalendarSummaryEventsInRange(int $coachId, DateTimeInterface $from, DateTimeInterface $to): array {
  $pdo = getDB();
  $stmt = $pdo->prepare(
    "SELECT e.id,
            e.athlete_id,
            e.second_athlete_id,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.approval_status,
            e.is_makeup_session,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE e.coach_id = ?
       AND e.starts_at >= ?
       AND e.starts_at < ?
     ORDER BY e.starts_at ASC, e.id ASC"
  );
  $stmt->execute([
    $coachId,
    $from->format('Y-m-d H:i:s'),
    $to->format('Y-m-d H:i:s'),
  ]);

  return $stmt->fetchAll();
}

function getAthleteCalendarSummaryEventsInRange(int $athleteId, DateTimeInterface $from, DateTimeInterface $to): array {
  $pdo = getDB();
  $stmt = $pdo->prepare(
    "SELECT e.id,
            e.athlete_id,
            e.second_athlete_id,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.approval_status,
            e.is_makeup_session,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE (e.athlete_id = ? OR e.second_athlete_id = ?)
       AND e.starts_at >= ?
       AND e.starts_at < ?
       AND (e.approval_status = 'approved' OR e.athlete_id = ? OR e.second_athlete_id = ?)
     ORDER BY e.starts_at ASC, e.id ASC"
  );
  $stmt->execute([
    $athleteId,
    $athleteId,
    $from->format('Y-m-d H:i:s'),
    $to->format('Y-m-d H:i:s'),
    $athleteId,
    $athleteId,
  ]);

  return $stmt->fetchAll();
}

function sendCalendarSummaryDigestEmail(
  string $toEmail,
  string $recipientName,
  string $subject,
  string $periodLabel,
  string $introText,
  array $events,
  bool $includeAthleteColumn = false
): bool {
  $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
  if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
    error_log('sendCalendarSummaryDigestEmail: PHPMailer not found at ' . $phpmailerSrc);
    return false;
  }

  require_once $phpmailerSrc . '/Exception.php';
  require_once $phpmailerSrc . '/PHPMailer.php';
  require_once $phpmailerSrc . '/SMTP.php';

  $h = static fn(?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

  $rowsHtml = '';
  $rowsPlain = '';
  foreach ($events as $event) {
    $start = strtotime((string)($event['starts_at'] ?? ''));
    $end = strtotime((string)($event['ends_at'] ?? ''));
    $dateLabel = $start ? date('d.m.Y', $start) : '—';
    $timeLabel = ($start && $end) ? (date('H:i', $start) . ' - ' . date('H:i', $end)) : '—';
    $typeLabel = buildCalendarSummaryTypeLabel($event);
    $statusLabel = buildCalendarSummaryStatusLabel($event);
    $locationLabel = trim((string)($event['location'] ?? '')) !== '' ? trim((string)$event['location']) : 'Bez místa';
    $athleteLabel = buildCalendarSummaryAthleteLabel($event);

    $rowsHtml .= '<tr>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">' . $h($dateLabel) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">' . $h($timeLabel) . '</td>'
      . ($includeAthleteColumn
          ? '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;font-weight:600;">' . $h($athleteLabel !== '' ? $athleteLabel : 'Bez sportovce') . '</td>'
          : '')
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;">' . $h($typeLabel) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;">' . $h($locationLabel) . '</td>'
      . '<td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;">' . $h($statusLabel) . '</td>'
      . '</tr>';

    $rowsPlain .= '- ' . $dateLabel . ' | ' . $timeLabel
      . ($includeAthleteColumn ? (' | ' . ($athleteLabel !== '' ? $athleteLabel : 'Bez sportovce')) : '')
      . ' | ' . $typeLabel
      . ' | ' . $locationLabel
      . ' | ' . $statusLabel
      . "\n";
  }

  $colspan = $includeAthleteColumn ? 6 : 5;
  if ($rowsHtml === '') {
    $rowsHtml = '<tr><td colspan="' . $colspan . '" style="padding:12px;color:#6b7280;">V daném období nejsou žádné naplánované termíny.</td></tr>';
    $rowsPlain = "- V daném období nejsou žádné naplánované termíny.\n";
  }

  $athleteHeader = $includeAthleteColumn ? '<th align="left" style="padding:10px 12px;">Sportovec</th>' : '';

  $htmlBody = '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"></head><body style="font-family:Arial,Helvetica,sans-serif;background:#f4f4f7;padding:20px;">'
    . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:760px;margin:0 auto;background:#fff;border-radius:12px;border:1px solid #e5e7eb;overflow:hidden;">'
    . '<tr><td style="padding:20px 24px;background:#111827;color:#fff;">'
    . '<h2 style="margin:0;font-size:20px;">Kalendářový přehled</h2>'
    . '<p style="margin:6px 0 0;color:#d1d5db;">' . $h($periodLabel) . '</p>'
    . '</td></tr>'
    . '<tr><td style="padding:16px 24px 8px;color:#374151;">'
    . 'Dobrý den, <strong>' . $h($recipientName) . '</strong>.<br>' . $h($introText)
    . '</td></tr>'
    . '<tr><td style="padding:8px 24px 24px;">'
    . '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
    . '<thead><tr style="background:#f9fafb;color:#374151;">'
    . '<th align="left" style="padding:10px 12px;">Datum</th>'
    . '<th align="left" style="padding:10px 12px;">Čas</th>'
    . $athleteHeader
    . '<th align="left" style="padding:10px 12px;">Typ</th>'
    . '<th align="left" style="padding:10px 12px;">Místo</th>'
    . '<th align="left" style="padding:10px 12px;">Stav</th>'
    . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
    . '</td></tr>'
    . '<tr><td style="padding:0 24px 20px;color:#6b7280;font-size:12px;">TrainerApp • automatický přehled kalendáře</td></tr>'
    . '</table></body></html>';

  $altBody = "Dobrý den, {$recipientName},\n\n"
    . $introText . "\n"
    . "Období: {$periodLabel}\n\n"
    . $rowsPlain
    . "\nTrainerApp\n";

  $mail = new PHPMailer\PHPMailer\PHPMailer(true);
  try {
    _configureMail($mail);
    $mail->addAddress($toEmail);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = $altBody;
    $mail->send();
    return true;
  } catch (\Exception $e) {
    error_log('sendCalendarSummaryDigestEmail error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
    return false;
  }
}

function processCalendarSummaryNotifications(?DateTimeImmutable $now = null): array {
  $pdo = getDB();
  $results = [];
  $now = $now ?? new DateTimeImmutable('now');

  $shouldSendWeekly = ((int)$now->format('N') === 7) && ((int)$now->format('G') >= 12);
  $shouldSendMonthly = ((int)$now->format('j') === 28) && ((int)$now->format('G') >= 12);

  if (!$shouldSendWeekly && !$shouldSendMonthly) {
    return $results;
  }

  $digestPlans = [];
  if ($shouldSendWeekly) {
    $from = $now->modify('next monday')->setTime(0, 0, 0);
    $to = $from->modify('+7 days');
    $digestPlans[] = [
      'type' => 'weekly_next_week',
      'from' => $from,
      'to' => $to,
      'digest_date' => $from->format('Y-m-d'),
      'period_label' => 'Příští týden: ' . $from->format('d.m.Y') . ' - ' . $to->modify('-1 day')->format('d.m.Y'),
      'coach_subject' => 'Týdenní přehled tréninků',
      'athlete_subject' => 'Týdenní přehled vašich tréninků',
      'coach_intro' => 'Posíláme chronologický přehled tréninků všech vašich sportovců na následující týden.',
      'athlete_intro' => 'Posíláme přehled vašich tréninků na následující týden.',
    ];
  }

  if ($shouldSendMonthly) {
    $from = $now->modify('first day of next month')->setTime(0, 0, 0);
    $to = $from->modify('+1 month');
    $digestPlans[] = [
      'type' => 'monthly_next_month',
      'from' => $from,
      'to' => $to,
      'digest_date' => $from->format('Y-m-d'),
      'period_label' => 'Příští měsíc: ' . $from->format('m/Y'),
      'coach_subject' => 'Měsíční přehled tréninků',
      'athlete_subject' => 'Měsíční přehled vašich tréninků',
      'coach_intro' => 'Posíláme chronologický přehled tréninků všech vašich sportovců na následující měsíc.',
      'athlete_intro' => 'Posíláme přehled vašich tréninků na následující měsíc.',
    ];
  }

  $coaches = $pdo->query(
    "SELECT id, name, username, email
     FROM coaches
     WHERE is_active = 1
       AND email IS NOT NULL
       AND email <> ''"
  )->fetchAll();

  $athletesStmt = $pdo->query(
    "SELECT a.id, a.coach_id, a.first_name, a.last_name, a.email
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id
     WHERE a.login_enabled = 1
       AND c.is_active = 1
       AND a.email IS NOT NULL
       AND a.email <> ''"
  );
  $athletes = $athletesStmt->fetchAll();

  $checkSentStmt = $pdo->prepare(
    'SELECT id
     FROM calendar_summary_notifications
     WHERE recipient_type = ?
       AND recipient_id = ?
       AND digest_type = ?
       AND digest_date = ?
     LIMIT 1'
  );

  $insertSentStmt = $pdo->prepare(
    'INSERT INTO calendar_summary_notifications (recipient_type, recipient_id, digest_type, digest_date, sent_at)
     VALUES (?, ?, ?, ?, NOW())'
  );

  foreach ($digestPlans as $plan) {
    foreach ($athletes as $athlete) {
      $athleteId = (int)$athlete['id'];
      $athleteEmail = trim((string)($athlete['email'] ?? ''));
      if ($athleteEmail === '') {
        continue;
      }

      $checkSentStmt->execute(['athlete', $athleteId, $plan['type'], $plan['digest_date']]);
      if ($checkSentStmt->fetch()) {
        continue;
      }

      $athleteName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
      if ($athleteName === '') {
        $athleteName = 'Sportovec';
      }

      $events = getAthleteCalendarSummaryEventsInRange($athleteId, $plan['from'], $plan['to']);
      $sent = sendCalendarSummaryDigestEmail(
        $athleteEmail,
        $athleteName,
        $plan['athlete_subject'],
        $plan['period_label'],
        $plan['athlete_intro'],
        $events,
        false
      );

      if ($sent) {
        $insertSentStmt->execute(['athlete', $athleteId, $plan['type'], $plan['digest_date']]);
      }

      $results[] = [
        'recipient_type' => 'athlete',
        'recipient_id' => $athleteId,
        'recipient_email' => $athleteEmail,
        'digest_type' => $plan['type'],
        'digest_date' => $plan['digest_date'],
        'events_count' => count($events),
        'sent' => $sent,
      ];
    }

    foreach ($coaches as $coach) {
      $coachId = (int)$coach['id'];
      $coachEmail = trim((string)($coach['email'] ?? ''));
      if ($coachEmail === '') {
        continue;
      }

      $checkSentStmt->execute(['coach', $coachId, $plan['type'], $plan['digest_date']]);
      if ($checkSentStmt->fetch()) {
        continue;
      }

      $coachName = trim((string)($coach['name'] ?? ''));
      if ($coachName === '') {
        $coachName = trim((string)($coach['username'] ?? 'Trenér'));
      }

      $events = getCoachCalendarSummaryEventsInRange($coachId, $plan['from'], $plan['to']);
      $sent = sendCalendarSummaryDigestEmail(
        $coachEmail,
        $coachName,
        $plan['coach_subject'],
        $plan['period_label'],
        $plan['coach_intro'],
        $events,
        true
      );

      if ($sent) {
        $insertSentStmt->execute(['coach', $coachId, $plan['type'], $plan['digest_date']]);
      }

      $results[] = [
        'recipient_type' => 'coach',
        'recipient_id' => $coachId,
        'recipient_email' => $coachEmail,
        'digest_type' => $plan['type'],
        'digest_date' => $plan['digest_date'],
        'events_count' => count($events),
        'sent' => $sent,
      ];
    }
  }

  return $results;
}
