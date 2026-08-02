<?php
// ============================================================
// Pomocné funkce
// ============================================================

if (!function_exists('h')) {
    function h(?string $str): string {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('loadSpecialEvents')) {
  function loadSpecialEvents(PDO $pdo, string $audience): array {
    try {
      $stmt = $pdo->prepare(
        "SELECT id, slug, name, icon_class, description, badge_label, tile_image, is_active, sort_order, audience
         FROM special_events
         WHERE is_active = 1 AND (audience = 'both' OR audience = ?)
         ORDER BY sort_order ASC, name ASC"
      );
      $stmt->execute([$audience]);
      return $stmt->fetchAll();
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('loadSpecialEventBySlug')) {
  function loadSpecialEventBySlug(PDO $pdo, string $slug, string $audience): ?array {
    try {
      $eventStmt = $pdo->prepare(
        "SELECT id, slug, name, icon_class, description, badge_label, tile_image, is_active, sort_order, audience
         FROM special_events
         WHERE slug = ? AND is_active = 1 AND (audience = 'both' OR audience = ?)
         LIMIT 1"
      );
      $eventStmt->execute([$slug, $audience]);
      $event = $eventStmt->fetch();
      if (!$event) {
        return null;
      }

      $tabsStmt = $pdo->prepare(
        "SELECT id, tab_key, title, content_html, is_active, sort_order, audience
         FROM special_event_tabs
         WHERE event_id = ? AND is_active = 1 AND (audience = 'both' OR audience = ?)
         ORDER BY sort_order ASC, id ASC"
      );
      $tabsStmt->execute([(int)$event['id'], $audience]);
      $event['tabs'] = $tabsStmt->fetchAll();

      $event['upcoming_items'] = [];
      if (specialEventHasUpcomingItemsTable($pdo)) {
        $itemsSql = specialEventHasUpcomingTabColumn($pdo)
          ? "SELECT id, event_id, tab_id, title, event_date, target_url, sort_order, is_active
             FROM special_event_upcoming_items
             WHERE event_id = ? AND is_active = 1
              ORDER BY event_date ASC, id ASC"
          : "SELECT id, event_id, NULL AS tab_id, title, event_date, target_url, sort_order, is_active
             FROM special_event_upcoming_items
             WHERE event_id = ? AND is_active = 1
              ORDER BY event_date ASC, id ASC";

        $itemsStmt = $pdo->prepare($itemsSql);
        $itemsStmt->execute([(int)$event['id']]);
        $event['upcoming_items'] = $itemsStmt->fetchAll();
      }

      return $event;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('specialEventHasUpcomingItemsTable')) {
  function specialEventHasUpcomingItemsTable(PDO $pdo): bool {
    static $hasTable = null;

    if ($hasTable !== null) {
      return $hasTable;
    }

    try {
      $stmt = $pdo->query("SHOW TABLES LIKE 'special_event_upcoming_items'");
      $hasTable = ($stmt !== false && $stmt->fetch()) ? true : false;
    } catch (Throwable $e) {
      $hasTable = false;
    }

    return $hasTable;
  }
}

if (!function_exists('specialEventHasUpcomingTabColumn')) {
  function specialEventHasUpcomingTabColumn(PDO $pdo): bool {
    static $hasColumn = null;

    if ($hasColumn !== null) {
      return $hasColumn;
    }

    if (!specialEventHasUpcomingItemsTable($pdo)) {
      $hasColumn = false;
      return $hasColumn;
    }

    try {
      $stmt = $pdo->query("SHOW COLUMNS FROM special_event_upcoming_items LIKE 'tab_id'");
      $hasColumn = ($stmt !== false && $stmt->fetch()) ? true : false;
    } catch (Throwable $e) {
      $hasColumn = false;
    }

    return $hasColumn;
  }
}

if (!function_exists('specialEventUpcomingItemState')) {
  function specialEventUpcomingItemState(string $eventDate): ?array {
    $date = trim($eventDate);
    if ($date === '') {
      return null;
    }

    try {
      $eventDay = new DateTimeImmutable($date . ' 00:00:00');
      $today = new DateTimeImmutable('today');
      $daysDiff = (int)$today->diff($eventDay)->format('%r%a');

      if ($daysDiff > 0) {
        return [
          'visible' => true,
          'badge_class' => 'special-event-upcoming-status--future',
          'text' => 'Do začátku události zbývá ' . specialEventDayLabel($daysDiff),
          'days_diff' => $daysDiff,
          'is_running' => false,
          'is_past' => false,
        ];
      }

      if ($daysDiff === 0) {
        return [
          'visible' => true,
          'badge_class' => 'special-event-upcoming-status--running',
          'text' => 'Probíhá',
          'days_diff' => 0,
          'is_running' => true,
          'is_past' => false,
        ];
      }

      return [
        'visible' => true,
        'badge_class' => 'special-event-upcoming-status--past',
        'text' => 'Událost již proběhla',
        'days_diff' => $daysDiff,
        'is_running' => false,
        'is_past' => true,
      ];
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('specialEventDayLabel')) {
  function specialEventDayLabel(int $days): string {
    if ($days === 1) {
      return '1 den';
    }

    $mod100 = $days % 100;
    $mod10 = $days % 10;
    if ($mod100 >= 11 && $mod100 <= 14) {
      return $days . ' dní';
    }
    if ($mod10 === 2 || $mod10 === 3 || $mod10 === 4) {
      return $days . ' dny';
    }

    return $days . ' dní';
  }
}

if (!function_exists('renderSpecialEventUpcomingItems')) {
  function renderSpecialEventUpcomingItems(array $items): string {
    $visibleItems = [];
    foreach ($items as $item) {
      $eventDate = trim((string)($item['event_date'] ?? ''));
      $state = specialEventUpcomingItemState($eventDate);
      if (!$state || !$state['visible']) {
        continue;
      }
      $visibleItems[] = [$item, $state];
    }

    if (empty($visibleItems)) {
      return '<div class="alert alert-warning mb-0">Tento event zatím nemá publikované žádné nadcházející události.</div>';
    }

    ob_start();
    ?>
    <div class="special-event-upcoming-list d-grid gap-3">
        <?php foreach ($visibleItems as [$item, $state]): ?>
            <?php
                $title = trim((string)($item['title'] ?? ''));
                $eventDate = trim((string)($item['event_date'] ?? ''));
                $targetUrl = trim((string)($item['target_url'] ?? ''));
            ?>
            <article class="special-event-upcoming-item border rounded-4 p-3 p-md-4" data-event-date="<?= h($eventDate) ?>">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div class="flex-grow-1">
                  <div class="fw-bold fs-5 mb-1 special-event-upcoming-title"><?= h($title !== '' ? $title : 'Událost') ?></div>
                  <div class="small mb-0 special-event-upcoming-date">Datum: <?= h(formatDate($eventDate)) ?></div>
                    </div>
                <span class="badge px-3 py-2 special-event-upcoming-status <?= h((string)$state['badge_class']) ?>" data-upcoming-status>
                        <?= h((string)$state['text']) ?>
                    </span>
                </div>
                <?php if ($targetUrl !== ''): ?>
                    <div class="mt-3">
                  <a href="<?= h($targetUrl) ?>" class="btn btn-sm special-event-upcoming-link" target="_blank" rel="noopener noreferrer">
                            Registrace / oficiální stránka
                        </a>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
    return trim((string)ob_get_clean());
  }
}

if (!function_exists('sanitizeSpecialEventHtml')) {
  function sanitizeSpecialEventHtml(string $html): string {
    if ($html === '') {
      return '';
    }

    $clean = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
    $clean = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $clean) ?? '';
    $clean = preg_replace('/\son[a-z]+\s*=\s*"[^"]*"/i', '', $clean) ?? '';
    $clean = preg_replace("/\son[a-z]+\s*=\s*'[^']*'/i", '', $clean) ?? '';
    $clean = preg_replace('/javascript\s*:/i', '', $clean) ?? '';

    return $clean;
  }
}

if (!function_exists('sendSpecialEventFormEmail')) {
  function sendSpecialEventFormEmail(string $toEmail, string $subject, array $fields, array $context = []): bool {
    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
      return false;
    }

    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $safeSubject = trim($subject) !== '' ? trim($subject) : 'Nova prihlaska z Events';
    $safeSubject = mb_substr($safeSubject, 0, 180, 'UTF-8');

    $rowsHtml = '';
    foreach ($fields as $key => $value) {
      $label = trim((string)$key);
      if ($label === '') {
        continue;
      }

      $textValue = is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
      $textValue = trim($textValue);
      if ($textValue === '') {
        continue;
      }

      $rowsHtml .= '<tr>'
        . '<td style="padding:8px 10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:600;vertical-align:top;">' . h($label) . '</td>'
        . '<td style="padding:8px 10px;border:1px solid #e5e7eb;vertical-align:top;">' . nl2br(h($textValue)) . '</td>'
        . '</tr>';
    }

    if ($rowsHtml === '') {
      $rowsHtml = '<tr><td style="padding:8px 10px;border:1px solid #e5e7eb;" colspan="2">Formular neobsahoval vyplnena pole.</td></tr>';
    }

    $eventName = trim((string)($context['event_name'] ?? ''));
    $eventSlug = trim((string)($context['event_slug'] ?? ''));
    $senderName = trim((string)($context['sender_name'] ?? ''));
    $senderEmail = trim((string)($context['sender_email'] ?? ''));
    $senderRole = trim((string)($context['sender_role'] ?? ''));

    $metaHtml = '';
    if ($eventName !== '') {
      $metaHtml .= '<li><strong>Event:</strong> ' . h($eventName) . '</li>';
    }
    if ($eventSlug !== '') {
      $metaHtml .= '<li><strong>Slug:</strong> ' . h($eventSlug) . '</li>';
    }
    if ($senderName !== '') {
      $metaHtml .= '<li><strong>Odesilatel:</strong> ' . h($senderName) . '</li>';
    }
    if ($senderEmail !== '') {
      $metaHtml .= '<li><strong>E-mail:</strong> ' . h($senderEmail) . '</li>';
    }
    if ($senderRole !== '') {
      $metaHtml .= '<li><strong>Role:</strong> ' . h($senderRole) . '</li>';
    }

    $htmlBody = '<h3 style="margin:0 0 12px 0;">Nova odeslana prihlaska z Events</h3>'
      . ($metaHtml !== '' ? '<ul style="margin:0 0 14px 0;padding-left:18px;">' . $metaHtml . '</ul>' : '')
      . '<table style="border-collapse:collapse;width:100%;max-width:900px;">'
      . $rowsHtml
      . '</table>';

    $plainMeta = [];
    if ($eventName !== '') {
      $plainMeta[] = 'Event: ' . $eventName;
    }
    if ($eventSlug !== '') {
      $plainMeta[] = 'Slug: ' . $eventSlug;
    }
    if ($senderName !== '') {
      $plainMeta[] = 'Odesilatel: ' . $senderName;
    }
    if ($senderEmail !== '') {
      $plainMeta[] = 'E-mail: ' . $senderEmail;
    }
    if ($senderRole !== '') {
      $plainMeta[] = 'Role: ' . $senderRole;
    }

    $plainFields = [];
    foreach ($fields as $key => $value) {
      $label = trim((string)$key);
      if ($label === '') {
        continue;
      }
      $textValue = is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
      $textValue = trim($textValue);
      if ($textValue === '') {
        continue;
      }
      $plainFields[] = $label . ': ' . $textValue;
    }

    $altBody = "Nova odeslana prihlaska z Events\n\n"
      . (!empty($plainMeta) ? implode("\n", $plainMeta) . "\n\n" : '')
      . (!empty($plainFields) ? implode("\n", $plainFields) : 'Formular neobsahoval vyplnena pole.');

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
      _configureMail($mail);
      $mail->addAddress($toEmail);
      $mail->isHTML(true);
      $mail->Subject = $safeSubject;
      $mail->Body = $htmlBody;
      $mail->AltBody = $altBody;
      $mail->send();
      return true;
    } catch (Throwable $e) {
      error_log('sendSpecialEventFormEmail error: ' . $e->getMessage());
      return false;
    }
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
 * - prázdný host → isMail() (PHP mail(), bez auth, Wedos hosting)
 * - jinak → isSMTP() s STARTTLS a autentizací
 */
function _configureMail(object $mail): void {
    $host = defined('SMTP_HOST') ? SMTP_HOST : '';
  $smtpTimeout = max(3, (int)(defined('SMTP_TIMEOUT') ? SMTP_TIMEOUT : 8));

    if ($host === '') {
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
    $mail->Timeout    = $smtpTimeout;
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
  if (isEmailQueueEnabled() && emailNotificationQueueTableAvailable()) {
    return enqueueEmailNotificationJob(
      'coach_message_notification',
      $toEmail,
      $subject,
      [
        'coach_name' => $coachName,
        'message_id' => $messageId,
      ]
    );
  }

  return sendMessageNotificationEmailNow($toEmail, $coachName, $subject, $messageId);
}

function sendMessageNotificationEmailNow(string $toEmail, string $coachName, string $subject, int $messageId): bool {
    $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
    if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
        return false;
    }
    require_once $phpmailerSrc . '/Exception.php';
    require_once $phpmailerSrc . '/PHPMailer.php';
    require_once $phpmailerSrc . '/SMTP.php';

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $link = $scheme . '://' . $host . BASE_URL . '/zpravy.php';

    $htmlBody = "<p>Dobrý den, <strong>" . htmlspecialchars($coachName, ENT_QUOTES) . "</strong>,</p>"
        . "<p>obdrželi jste novou zprávu v aplikaci <strong>TrainerApp</strong>.</p>"
        . "<p><strong>Předmět:</strong> " . htmlspecialchars($subject, ENT_QUOTES) . "</p>"
        . "<p><a href=\"{$link}\" style=\"background:#0d6efd;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none\">Přejít do aplikace TrainerApp</a></p>"
        . "<hr><p style=\"color:#888;font-size:.85em\">TrainerApp – automatické notifikace</p>";

    $altBody = "Dobrý den, {$coachName},\n\n"
        . "obdrželi jste novou zprávu v aplikaci TrainerApp.\n"
        . "Předmět: {$subject}\n\n"
        . "Přejít do aplikace: {$link}\n\n"
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

function ensurePasswordAuditColumns(PDO $pdo): void {
  foreach (['coaches', 'athletes'] as $table) {
    try {
      $columnStmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'password_changed_at'");
      $hasColumn = $columnStmt !== false && (bool)$columnStmt->fetch();
      if (!$hasColumn) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL AFTER password");
      }

      $pdo->exec("UPDATE {$table} SET password_changed_at = COALESCE(password_changed_at, created_at) WHERE password_changed_at IS NULL");
    } catch (Throwable $e) {
      error_log('ensurePasswordAuditColumns error for ' . $table . ': ' . $e->getMessage());
    }
  }
}

function sendAthleteWelcomeEmail(string $toEmail, string $athleteName, string $password, string $loginUrl): bool {
  $phpmailerSrc = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
  if (!file_exists($phpmailerSrc . '/PHPMailer.php')) {
    error_log('sendAthleteWelcomeEmail: PHPMailer not found at ' . $phpmailerSrc);
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
    error_log('sendAthleteWelcomeEmail SMTP error: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
  }

  try {
    $fallback = new PHPMailer\PHPMailer\PHPMailer(true);
    $fallback->isMail();
    $fallback->CharSet = 'UTF-8';
    $fallback->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $fallback->addAddress($toEmail);
    $fallback->isHTML(true);
    $fallback->Subject = 'Přístup do TrainerApp';
    $fallback->Body = $htmlBody;
    $fallback->AltBody = $altBody;
    $fallback->send();
    return true;
  } catch (\Exception $e) {
    error_log('sendAthleteWelcomeEmail fallback error: ' . $e->getMessage());
    return false;
  }
}

function sendAthleteCalendarNotificationEmail(string $toEmail, string $athleteName, string $subject, string $message): bool {
  if (isEmailQueueEnabled() && emailNotificationQueueTableAvailable()) {
    return enqueueEmailNotificationJob(
      'athlete_calendar_notification',
      $toEmail,
      $subject,
      [
        'athlete_name' => $athleteName,
        'message' => $message,
      ]
    );
  }

  return sendAthleteCalendarNotificationEmailNow($toEmail, $athleteName, $subject, $message);
}

function sendAthleteCalendarNotificationEmailNow(string $toEmail, string $athleteName, string $subject, string $message): bool {
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

function getAdminNotificationEmail(): string {
  return 'info@reservio.online';
}

/**
 * Odešle e-mailovou notifikaci o novém ticketu podpory na admin adresu.
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

  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
  $scheme = $isHttps ? 'https' : 'http';
  $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
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
  $recipientMap[mb_strtolower(getAdminNotificationEmail(), 'UTF-8')] = getAdminNotificationEmail();
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
      try {
        $fallback = new PHPMailer\PHPMailer\PHPMailer(true);
        $fallback->isMail();
        $fallback->CharSet = 'UTF-8';
        $fallback->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $fallback->addAddress($to);
        $fallback->isHTML(true);
        $fallback->Subject = 'Nový ticket podpory #' . $ticketId . ': ' . (string)($ticket['subject'] ?? '');
        $fallback->Body = $htmlBody;
        $fallback->AltBody = $altBody;
        $fallback->send();
        $sent++;
      } catch (\Exception $fallbackException) {
        error_log('sendSupportTicketNotificationEmail fallback error: ' . $fallbackException->getMessage());
      }
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
  }

  try {
    $fallback = new PHPMailer\PHPMailer\PHPMailer(true);
    $fallback->isMail();
    $fallback->CharSet = 'UTF-8';
    $fallback->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $fallback->addAddress($ownerEmail);
    $fallback->isHTML(true);
    $fallback->Subject = 'Nová žádost o přístup trenéra';
    $fallback->Body = $htmlBody;
    $fallback->AltBody = $altBody;
    $fallback->send();
    return true;
  } catch (\Exception $e) {
    error_log('sendCoachAccessRequestOwnerEmail fallback error: ' . $e->getMessage());
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
  }

  try {
    $fallback = new PHPMailer\PHPMailer\PHPMailer(true);
    $fallback->isMail();
    $fallback->CharSet = 'UTF-8';
    $fallback->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $fallback->addAddress($toEmail);
    $fallback->isHTML(true);
    $fallback->Subject = 'Reset hesla - TrainerApp';
    $fallback->Body = $htmlBody;
    $fallback->AltBody = $altBody;
    $fallback->send();
    return true;
  } catch (\Exception $e) {
    error_log('sendPasswordResetEmail fallback error: ' . $e->getMessage());
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
    $useSendmail = ($host === '');

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

function isEmailQueueEnabled(): bool {
  return !defined('EMAIL_QUEUE_ENABLED') || EMAIL_QUEUE_ENABLED;
}

function emailNotificationQueueTableAvailable(): bool {
  static $available = null;
  if ($available !== null) {
    return $available;
  }

  try {
    $pdo = getDB();
    $table = $pdo->query("SHOW TABLES LIKE 'email_notification_jobs'");
    $available = ($table !== false && (bool)$table->fetchColumn());
  } catch (Throwable $e) {
    $available = false;
  }

  return $available;
}

function enqueueEmailNotificationJob(string $templateKey, string $recipientEmail, string $subject, array $payload): bool {
  if (!isEmailQueueEnabled() || !emailNotificationQueueTableAvailable()) {
    return false;
  }

  $recipientEmail = trim($recipientEmail);
  if ($recipientEmail === '') {
    return false;
  }

  $subject = trim($subject);
  if ($subject === '') {
    $subject = 'Notifikace TrainerApp';
  }

  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($payloadJson)) {
    return false;
  }

  try {
    $pdo = getDB();
    $ins = $pdo->prepare(
      'INSERT INTO email_notification_jobs (template_key, recipient_email, subject, payload_json, status, attempt_count, next_attempt_at)
       VALUES (?, ?, ?, ?, "pending", 0, NOW())'
    );
    $ins->execute([$templateKey, $recipientEmail, $subject, $payloadJson]);
    return true;
  } catch (Throwable $e) {
    error_log('enqueueEmailNotificationJob error: ' . $e->getMessage());
    return false;
  }
}

function processEmailNotificationQueue(int $limit = 20): array {
  $results = [];
  $maxAttempts = 5;

  if (!isEmailQueueEnabled() || !emailNotificationQueueTableAvailable()) {
    return $results;
  }

  $pdo = getDB();
  $limit = max(1, min(200, $limit));

  $resetStale = $pdo->prepare(
    'UPDATE email_notification_jobs
     SET status = "failed",
         last_error = COALESCE(last_error, "Email job byl resetovan po prerusenem nebo timeout requestu."),
         next_attempt_at = NOW(),
         updated_at = NOW()
     WHERE status = "processing"
       AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
  );
  $resetStale->execute();

  $jobStmt = $pdo->prepare(
    'SELECT id, template_key, recipient_email, subject, payload_json, attempt_count
     FROM email_notification_jobs
     WHERE status IN ("pending", "failed")
       AND attempt_count < ' . $maxAttempts . '
       AND next_attempt_at <= NOW()
     ORDER BY id ASC
     LIMIT ' . $limit
  );
  $jobStmt->execute();
  $jobs = $jobStmt->fetchAll();

  foreach ($jobs as $job) {
    $jobId = (int)($job['id'] ?? 0);
    $attemptCount = (int)($job['attempt_count'] ?? 0);
    $templateKey = trim((string)($job['template_key'] ?? ''));
    $recipientEmail = trim((string)($job['recipient_email'] ?? ''));
    $subject = trim((string)($job['subject'] ?? ''));

    if ($jobId <= 0 || $templateKey === '' || $recipientEmail === '') {
      continue;
    }

    $markProcessing = $pdo->prepare(
      'UPDATE email_notification_jobs
       SET status = "processing", updated_at = NOW(), attempt_count = attempt_count + 1
       WHERE id = ?'
    );
    $markProcessing->execute([$jobId]);

    try {
      $payloadRaw = (string)($job['payload_json'] ?? '{}');
      $payload = json_decode($payloadRaw, true);
      if (!is_array($payload)) {
        $payload = [];
      }

      $sent = false;
      if ($templateKey === 'coach_message_notification') {
        $coachName = trim((string)($payload['coach_name'] ?? 'trenér'));
        $messageId = (int)($payload['message_id'] ?? 0);
        $sent = sendMessageNotificationEmailNow($recipientEmail, $coachName, $subject, $messageId);
      } elseif ($templateKey === 'athlete_calendar_notification') {
        $athleteName = trim((string)($payload['athlete_name'] ?? 'sportovec'));
        $message = trim((string)($payload['message'] ?? ''));
        $sent = sendAthleteCalendarNotificationEmailNow($recipientEmail, $athleteName, $subject, $message);
      } else {
        throw new RuntimeException('Neznamy template email fronty: ' . $templateKey);
      }

      if (!$sent) {
        throw new RuntimeException('Odeslani e-mailu selhalo bez detailu.');
      }

      $markDone = $pdo->prepare(
        'UPDATE email_notification_jobs
         SET status = "done", last_error = NULL, processed_at = NOW(), updated_at = NOW()
         WHERE id = ?'
      );
      $markDone->execute([$jobId]);

      $results[] = ['job_id' => $jobId, 'status' => 'done', 'template' => $templateKey];
    } catch (Throwable $e) {
      $nextAttemptCount = $attemptCount + 1;
      if ($nextAttemptCount >= $maxAttempts) {
        $markDead = $pdo->prepare(
          'UPDATE email_notification_jobs
           SET status = "dead",
               last_error = ?,
               updated_at = NOW()
           WHERE id = ?'
        );
        $markDead->execute([mb_substr($e->getMessage(), 0, 2000, 'UTF-8'), $jobId]);

        $results[] = ['job_id' => $jobId, 'status' => 'dead', 'template' => $templateKey, 'error' => $e->getMessage()];
      } else {
        $delayMinutes = min(360, max(2, (int)pow(2, max(0, $attemptCount))));
        $markFailed = $pdo->prepare(
          'UPDATE email_notification_jobs
           SET status = "failed",
               last_error = ?,
               next_attempt_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
               updated_at = NOW()
           WHERE id = ?'
        );
        $markFailed->execute([mb_substr($e->getMessage(), 0, 2000, 'UTF-8'), $delayMinutes, $jobId]);

        $results[] = ['job_id' => $jobId, 'status' => 'failed', 'template' => $templateKey, 'error' => $e->getMessage()];
      }
    }
  }

  return $results;
}

function isInlineCalendarSyncEnabled(): bool {
  return defined('CALENDAR_SYNC_INLINE_ENABLED') && CALENDAR_SYNC_INLINE_ENABLED;
}

function processInlineCalendarSyncQueues(int $googleLimit = 2, int $appleLimit = 3, int $athleteAppleLimit = 3): array {
  $summary = [
    'google' => 0,
    'apple_coach' => 0,
    'apple_athlete' => 0,
  ];

  if (!isInlineCalendarSyncEnabled()) {
    return $summary;
  }

  try {
    $google = processCoachGoogleCalendarSyncQueue(max(1, min(20, $googleLimit)));
    $summary['google'] = count($google);
  } catch (Throwable $e) {
    error_log('processInlineCalendarSyncQueues google error: ' . $e->getMessage());
  }

  try {
    $appleCoach = processCoachAppleCaldavSyncQueue(max(1, min(20, $appleLimit)));
    $summary['apple_coach'] = count($appleCoach);
  } catch (Throwable $e) {
    error_log('processInlineCalendarSyncQueues apple coach error: ' . $e->getMessage());
  }

  try {
    $appleAthlete = processAthleteAppleCaldavSyncQueue(max(1, min(20, $athleteAppleLimit)));
    $summary['apple_athlete'] = count($appleAthlete);
  } catch (Throwable $e) {
    error_log('processInlineCalendarSyncQueues apple athlete error: ' . $e->getMessage());
  }

  return $summary;
}

function isGoogleCalendarApiConfigured(): bool {
  return defined('GOOGLE_CALENDAR_CLIENT_ID')
    && defined('GOOGLE_CALENDAR_CLIENT_SECRET')
    && trim((string)GOOGLE_CALENDAR_CLIENT_ID) !== ''
    && trim((string)GOOGLE_CALENDAR_CLIENT_SECRET) !== '';
}

function enqueueCoachGoogleCalendarSync(int $coachId, ?int $eventId, string $syncAction = 'upsert'): void {
  if (!in_array($syncAction, ['upsert', 'delete'], true)) {
    $syncAction = 'upsert';
  }

  if ($coachId <= 0) {
    return;
  }

  if (!googleCalendarSyncTablesAvailable()) {
    return;
  }

  try {
    $pdo = getDB();

    $cleanup = $pdo->prepare(
      'DELETE FROM coach_google_calendar_sync_jobs
       WHERE coach_id = ?
         AND ((event_id = ?) OR (event_id IS NULL AND ? IS NULL))
         AND sync_action = ?
         AND status IN ("pending", "failed")'
    );
    $cleanup->execute([$coachId, $eventId, $eventId, $syncAction]);

    $ins = $pdo->prepare(
      'INSERT INTO coach_google_calendar_sync_jobs (coach_id, event_id, sync_action, status, attempt_count, next_attempt_at)
       VALUES (?, ?, ?, "pending", 0, NOW())'
    );
    $ins->execute([$coachId, $eventId, $syncAction]);
  } catch (Throwable $e) {
    error_log('enqueueCoachGoogleCalendarSync error: ' . $e->getMessage());
  }
}

function processCoachGoogleCalendarSyncQueue(int $limit = 8): array {
  $results = [];

  if (!isGoogleCalendarApiConfigured()) {
    return $results;
  }

  if (!googleCalendarSyncTablesAvailable()) {
    return $results;
  }

  try {
    $pdo = getDB();
    $limit = max(1, min(50, $limit));

    $jobStmt = $pdo->prepare(
      'SELECT id, coach_id, event_id, sync_action, attempt_count
       FROM coach_google_calendar_sync_jobs
       WHERE status IN ("pending", "failed")
         AND next_attempt_at <= NOW()
       ORDER BY id ASC
       LIMIT ' . $limit
    );
    $jobStmt->execute();
    $jobs = $jobStmt->fetchAll();
  } catch (Throwable $e) {
    error_log('processCoachGoogleCalendarSyncQueue bootstrap error: ' . $e->getMessage());
    return $results;
  }

  foreach ($jobs as $job) {
    $jobId = (int)($job['id'] ?? 0);
    $coachId = (int)($job['coach_id'] ?? 0);
    $eventId = isset($job['event_id']) ? (int)$job['event_id'] : null;
    $syncAction = (string)($job['sync_action'] ?? 'upsert');
    $attemptCount = (int)($job['attempt_count'] ?? 0);

    if ($jobId <= 0 || $coachId <= 0) {
      continue;
    }

    $markProcessing = $pdo->prepare(
      'UPDATE coach_google_calendar_sync_jobs
       SET status = "processing", updated_at = NOW(), attempt_count = attempt_count + 1
       WHERE id = ?'
    );
    $markProcessing->execute([$jobId]);

    try {
      if ($syncAction === 'delete') {
        syncCoachEventDeleteToGoogle($coachId, $eventId);
      } else {
        syncCoachEventUpsertToGoogle($coachId, $eventId);
      }

      $markDone = $pdo->prepare(
        'UPDATE coach_google_calendar_sync_jobs
         SET status = "done", last_error = NULL, processed_at = NOW(), updated_at = NOW()
         WHERE id = ?'
      );
      $markDone->execute([$jobId]);

      $results[] = ['job_id' => $jobId, 'status' => 'done'];
    } catch (Throwable $e) {
      $delayMinutes = min(360, max(2, (int)pow(2, max(0, $attemptCount))));
      $markFailed = $pdo->prepare(
        'UPDATE coach_google_calendar_sync_jobs
         SET status = "failed",
             last_error = ?,
             next_attempt_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
             updated_at = NOW()
         WHERE id = ?'
      );
      $markFailed->execute([mb_substr($e->getMessage(), 0, 2000, 'UTF-8'), $delayMinutes, $jobId]);

      $results[] = ['job_id' => $jobId, 'status' => 'failed', 'error' => $e->getMessage()];
    }
  }

  return $results;
}

function syncCoachEventUpsertToGoogle(int $coachId, ?int $eventId): void {
  if ($coachId <= 0 || $eventId === null || $eventId <= 0) {
    return;
  }

  if (!googleCalendarSyncTablesAvailable()) {
    return;
  }

  $pdo = getDB();
  $eventStmt = $pdo->prepare(
    'SELECT e.id,
            e.coach_id,
            e.athlete_id,
            e.second_athlete_id,
            e.requested_by_athlete_id,
            e.approval_status,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.updated_at,
            e.created_at,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE e.id = ?
       AND e.coach_id = ?
     LIMIT 1'
  );
  $eventStmt->execute([$eventId, $coachId]);
  $event = $eventStmt->fetch();

  if (!$event) {
    syncCoachEventDeleteToGoogle($coachId, $eventId);
    return;
  }

  $coach = getCoachGoogleSyncConfig($coachId);
  if (!$coach || empty($coach['google_calendar_sync_enabled'])) {
    return;
  }

  $calendarId = trim((string)($coach['google_calendar_id'] ?? ''));
  if ($calendarId === '') {
    throw new RuntimeException('Google sync: chybi google_calendar_id.');
  }

  $accessToken = getFreshGoogleAccessToken($coach);
  $payload = buildGoogleCalendarEventPayload($event, $coachId);

  $linkStmt = $pdo->prepare(
    'SELECT google_event_id
     FROM coach_google_calendar_event_links
     WHERE coach_id = ? AND event_id = ?
     LIMIT 1'
  );
  $linkStmt->execute([$coachId, $eventId]);
  $existingGoogleEventId = (string)($linkStmt->fetchColumn() ?: '');

  $response = null;
  if ($existingGoogleEventId !== '') {
    $response = googleCalendarApiJsonRequest(
      'PUT',
      'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($existingGoogleEventId),
      $accessToken,
      $payload
    );

    if (($response['status'] ?? 0) === 404) {
      $delStmt = $pdo->prepare('DELETE FROM coach_google_calendar_event_links WHERE coach_id = ? AND event_id = ?');
      $delStmt->execute([$coachId, $eventId]);
      $existingGoogleEventId = '';
      $response = null;
    }
  }

  if ($response === null) {
    $response = googleCalendarApiJsonRequest(
      'POST',
      'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events',
      $accessToken,
      $payload
    );
  }

  $status = (int)($response['status'] ?? 0);
  if ($status < 200 || $status >= 300) {
    throw new RuntimeException('Google sync upsert selhal: HTTP ' . $status . ' | ' . (string)($response['raw'] ?? '')); 
  }

  $googleEventId = trim((string)($response['body']['id'] ?? ''));
  if ($googleEventId === '') {
    throw new RuntimeException('Google sync upsert selhal: chybi id udalosti v odpovedi.');
  }

  $upsertLink = $pdo->prepare(
    'INSERT INTO coach_google_calendar_event_links (coach_id, event_id, google_event_id, last_synced_at, last_error)
     VALUES (?, ?, ?, NOW(), NULL)
     ON DUPLICATE KEY UPDATE google_event_id = VALUES(google_event_id), last_synced_at = NOW(), last_error = NULL'
  );
  $upsertLink->execute([$coachId, $eventId, $googleEventId]);

  markCoachGoogleSyncSuccess($coachId);
}

function syncCoachEventDeleteToGoogle(int $coachId, ?int $eventId): void {
  if ($coachId <= 0 || $eventId === null || $eventId <= 0) {
    return;
  }

  if (!googleCalendarSyncTablesAvailable()) {
    return;
  }

  $pdo = getDB();
  $linkStmt = $pdo->prepare(
    'SELECT google_event_id
     FROM coach_google_calendar_event_links
     WHERE coach_id = ? AND event_id = ?
     LIMIT 1'
  );
  $linkStmt->execute([$coachId, $eventId]);
  $googleEventId = trim((string)($linkStmt->fetchColumn() ?: ''));

  if ($googleEventId === '') {
    return;
  }

  $coach = getCoachGoogleSyncConfig($coachId);
  if (!$coach || empty($coach['google_calendar_sync_enabled'])) {
    $deleteLocalLink = $pdo->prepare('DELETE FROM coach_google_calendar_event_links WHERE coach_id = ? AND event_id = ?');
    $deleteLocalLink->execute([$coachId, $eventId]);
    return;
  }

  $calendarId = trim((string)($coach['google_calendar_id'] ?? ''));
  if ($calendarId === '') {
    throw new RuntimeException('Google sync delete: chybi google_calendar_id.');
  }

  $accessToken = getFreshGoogleAccessToken($coach);
  $response = googleCalendarApiJsonRequest(
    'DELETE',
    'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($googleEventId),
    $accessToken,
    null
  );

  $status = (int)($response['status'] ?? 0);
  if (!in_array($status, [200, 204, 404], true)) {
    throw new RuntimeException('Google sync delete selhal: HTTP ' . $status . ' | ' . (string)($response['raw'] ?? ''));
  }

  $deleteLink = $pdo->prepare('DELETE FROM coach_google_calendar_event_links WHERE coach_id = ? AND event_id = ?');
  $deleteLink->execute([$coachId, $eventId]);

  markCoachGoogleSyncSuccess($coachId);
}

function getCoachGoogleSyncConfig(int $coachId): ?array {
  $pdo = getDB();
  $stmt = $pdo->prepare(
    'SELECT id,
            google_calendar_sync_enabled,
            google_calendar_id,
            google_oauth_access_token,
            google_oauth_refresh_token,
            google_oauth_expires_at
     FROM coaches
     WHERE id = ?
     LIMIT 1'
  );
  $stmt->execute([$coachId]);
  $coach = $stmt->fetch();

  return $coach ?: null;
}

function getFreshGoogleAccessToken(array $coach): string {
  $coachId = (int)($coach['id'] ?? 0);
  if ($coachId <= 0) {
    throw new RuntimeException('Google sync: chybi coach id.');
  }

  $accessToken = trim((string)($coach['google_oauth_access_token'] ?? ''));
  $refreshToken = trim((string)($coach['google_oauth_refresh_token'] ?? ''));
  $expiresAt = trim((string)($coach['google_oauth_expires_at'] ?? ''));
  $expiresTs = $expiresAt !== '' ? strtotime($expiresAt) : false;

  if ($accessToken !== '' && $expiresTs !== false && $expiresTs > (time() + 120)) {
    return $accessToken;
  }

  if ($refreshToken === '') {
    throw new RuntimeException('Google sync: chybi refresh token.');
  }

  $tokenData = googleRefreshAccessToken($refreshToken);
  $newAccessToken = trim((string)($tokenData['access_token'] ?? ''));
  if ($newAccessToken === '') {
    throw new RuntimeException('Google sync: nepodarilo se obnovit access token.');
  }

  $expiresIn = max(60, (int)($tokenData['expires_in'] ?? 3600));
  $newExpiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

  $pdo = getDB();
  $upd = $pdo->prepare(
    'UPDATE coaches
     SET google_oauth_access_token = ?,
         google_oauth_expires_at = ?,
         google_sync_last_error = NULL
     WHERE id = ?'
  );
  $upd->execute([$newAccessToken, $newExpiresAt, $coachId]);

  return $newAccessToken;
}

function googleRefreshAccessToken(string $refreshToken): array {
  if (!isGoogleCalendarApiConfigured()) {
    throw new RuntimeException('Google API neni nakonfigurovana.');
  }

  if (!function_exists('curl_init')) {
    throw new RuntimeException('Na serveru neni dostupne CURL rozsireni.');
  }

  $postFields = http_build_query([
    'client_id' => (string)GOOGLE_CALENDAR_CLIENT_ID,
    'client_secret' => (string)GOOGLE_CALENDAR_CLIENT_SECRET,
    'refresh_token' => $refreshToken,
    'grant_type' => 'refresh_token',
  ]);

  $ch = curl_init('https://oauth2.googleapis.com/token');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
  ]);

  $raw = curl_exec($ch);
  $curlErr = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($raw === false) {
    throw new RuntimeException('Google OAuth refresh selhal: ' . $curlErr);
  }

  $json = json_decode((string)$raw, true);
  if ($status < 200 || $status >= 300 || !is_array($json)) {
    throw new RuntimeException('Google OAuth refresh selhal: HTTP ' . $status . ' | ' . mb_substr((string)$raw, 0, 500, 'UTF-8'));
  }

  return $json;
}

function googleCalendarApiJsonRequest(string $method, string $url, string $accessToken, ?array $payload): array {
  if (!function_exists('curl_init')) {
    throw new RuntimeException('Na serveru neni dostupne CURL rozsireni.');
  }

  $method = strtoupper(trim($method));
  $ch = curl_init($url);

  $headers = [
    'Authorization: Bearer ' . $accessToken,
    'Accept: application/json',
  ];

  $jsonPayload = null;
  if ($payload !== null) {
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
      throw new RuntimeException('Google sync: nelze serializovat JSON payload.');
    }
    $headers[] = 'Content-Type: application/json';
  }

  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => $headers,
  ]);

  if ($jsonPayload !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
  }

  $raw = curl_exec($ch);
  $curlErr = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($raw === false) {
    throw new RuntimeException('Google API request selhal: ' . $curlErr);
  }

  $body = null;
  if (trim((string)$raw) !== '') {
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) {
      $body = $decoded;
    }
  }

  return [
    'status' => $status,
    'raw' => (string)$raw,
    'body' => $body,
  ];
}

function buildGoogleCalendarEventPayload(array $event, int $coachId): array {
  $participants = [];
  $primary = trim((string)($event['first_name'] ?? '') . ' ' . (string)($event['last_name'] ?? ''));
  $secondary = trim((string)($event['second_first_name'] ?? '') . ' ' . (string)($event['second_last_name'] ?? ''));
  if ($primary !== '') {
    $participants[] = $primary;
  }
  if ($secondary !== '') {
    $participants[] = $secondary;
  }

  $summary = trim((string)($event['custom_title'] ?? ''));
  if ($summary === '') {
    $summary = !empty($participants) ? ('Trenink - ' . implode(' + ', $participants)) : 'Trenink';
  }
  if ((string)($event['approval_status'] ?? 'approved') === 'pending') {
    $summary = 'Ceka na schvaleni - ' . $summary;
  }

  $descriptionParts = [
    'Zdroj: TrainerApp',
    'Lokalni ID udalosti: ' . (int)($event['id'] ?? 0),
  ];
  if (!empty($participants)) {
    $descriptionParts[] = 'Ucastnici: ' . implode(', ', $participants);
  }
  $location = trim((string)($event['location'] ?? ''));
  if ($location !== '') {
    $descriptionParts[] = 'Misto: ' . $location;
  }
  $descriptionParts[] = ((string)($event['approval_status'] ?? 'approved') === 'pending')
    ? 'Stav: ceka na schvaleni'
    : 'Stav: schvaleno';

  $timeZone = date_default_timezone_get() ?: 'UTC';

  return [
    'summary' => $summary,
    'description' => implode("\n", $descriptionParts),
    'location' => $location !== '' ? $location : null,
    'status' => ((string)($event['approval_status'] ?? 'approved') === 'pending') ? 'tentative' : 'confirmed',
    'start' => [
      'dateTime' => date('c', strtotime((string)($event['starts_at'] ?? 'now'))),
      'timeZone' => $timeZone,
    ],
    'end' => [
      'dateTime' => date('c', strtotime((string)($event['ends_at'] ?? 'now'))),
      'timeZone' => $timeZone,
    ],
    'extendedProperties' => [
      'private' => [
        'trainerapp_event_id' => (string)((int)($event['id'] ?? 0)),
        'trainerapp_coach_id' => (string)$coachId,
      ],
    ],
  ];
}

function markCoachGoogleSyncSuccess(int $coachId): void {
  if ($coachId <= 0) {
    return;
  }

  $pdo = getDB();
  $stmt = $pdo->prepare(
    'UPDATE coaches
     SET google_sync_last_error = NULL,
         google_sync_last_success_at = NOW()
     WHERE id = ?'
  );
  $stmt->execute([$coachId]);
}

function googleCalendarSyncTablesAvailable(): bool {
  static $available = null;
  if ($available !== null) {
    return $available;
  }

  try {
    $pdo = getDB();
    $jobs = $pdo->query("SHOW TABLES LIKE 'coach_google_calendar_sync_jobs'");
    $links = $pdo->query("SHOW TABLES LIKE 'coach_google_calendar_event_links'");
    $available = ($jobs !== false && (bool)$jobs->fetchColumn())
      && ($links !== false && (bool)$links->fetchColumn());
  } catch (Throwable $e) {
    $available = false;
  }

  return $available;
}

function enqueueCoachAppleCaldavSync(int $coachId, ?int $eventId, string $syncAction = 'upsert'): void {
  if (!in_array($syncAction, ['upsert', 'delete'], true)) {
    $syncAction = 'upsert';
  }

  if ($coachId <= 0 || !appleCaldavSyncTablesAvailable()) {
    return;
  }

  try {
    $pdo = getDB();

    $cleanup = $pdo->prepare(
      'DELETE FROM coach_apple_caldav_sync_jobs
       WHERE coach_id = ?
         AND ((event_id = ?) OR (event_id IS NULL AND ? IS NULL))
         AND sync_action = ?
         AND status IN ("pending", "failed", "done")'
    );
    $cleanup->execute([$coachId, $eventId, $eventId, $syncAction]);

    $ins = $pdo->prepare(
      'INSERT INTO coach_apple_caldav_sync_jobs (coach_id, event_id, sync_action, status, attempt_count, next_attempt_at)
       VALUES (?, ?, ?, "pending", 0, NOW())'
    );
    $ins->execute([$coachId, $eventId, $syncAction]);
  } catch (Throwable $e) {
    error_log('enqueueCoachAppleCaldavSync error: ' . $e->getMessage());
  }
}

function processCoachAppleCaldavSyncQueue(int $limit = 8): array {
  $results = [];
  if (!appleCaldavSyncTablesAvailable()) {
    return $results;
  }

  $pdo = getDB();
  $limit = max(1, min(50, $limit));

  $resetStale = $pdo->prepare(
    'UPDATE coach_apple_caldav_sync_jobs
     SET status = "failed",
         last_error = COALESCE(last_error, "Apple CalDAV job byl resetovan po prerusenem nebo timeout requestu."),
         next_attempt_at = NOW(),
         updated_at = NOW()
     WHERE status = "processing"
       AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
  );
  $resetStale->execute();

  $jobStmt = $pdo->prepare(
    'SELECT id, coach_id, event_id, sync_action, attempt_count
     FROM coach_apple_caldav_sync_jobs
     WHERE status IN ("pending", "failed")
       AND next_attempt_at <= NOW()
     ORDER BY id DESC
     LIMIT ' . $limit
  );
  $jobStmt->execute();
  $jobs = $jobStmt->fetchAll();

  foreach ($jobs as $job) {
    $jobId = (int)($job['id'] ?? 0);
    $coachId = (int)($job['coach_id'] ?? 0);
    $eventId = isset($job['event_id']) ? (int)$job['event_id'] : null;
    $syncAction = (string)($job['sync_action'] ?? 'upsert');
    $attemptCount = (int)($job['attempt_count'] ?? 0);

    if ($jobId <= 0 || $coachId <= 0) {
      continue;
    }

    $markProcessing = $pdo->prepare(
      'UPDATE coach_apple_caldav_sync_jobs
       SET status = "processing", updated_at = NOW(), attempt_count = attempt_count + 1
       WHERE id = ?'
    );
    $markProcessing->execute([$jobId]);

    try {
      if ($syncAction === 'delete') {
        syncCoachEventDeleteToAppleCaldav($coachId, $eventId);
      } else {
        syncCoachEventUpsertToAppleCaldav($coachId, $eventId);
      }

      $markDone = $pdo->prepare(
        'UPDATE coach_apple_caldav_sync_jobs
         SET status = "done", last_error = NULL, processed_at = NOW(), updated_at = NOW()
         WHERE id = ?'
      );
      $markDone->execute([$jobId]);

      $results[] = ['job_id' => $jobId, 'status' => 'done'];
    } catch (Throwable $e) {
      $delayMinutes = min(360, max(2, (int)pow(2, max(0, $attemptCount))));
      $markFailed = $pdo->prepare(
        'UPDATE coach_apple_caldav_sync_jobs
         SET status = "failed",
             last_error = ?,
             next_attempt_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
             updated_at = NOW()
         WHERE id = ?'
      );
      $markFailed->execute([mb_substr($e->getMessage(), 0, 2000, 'UTF-8'), $delayMinutes, $jobId]);

      $results[] = ['job_id' => $jobId, 'status' => 'failed', 'error' => $e->getMessage()];
    }
  }

  return $results;
}

function bootstrapCoachAppleCaldavMissingEvents(int $coachLimit = 5, int $eventsPerCoachLimit = 250): array {
  $summary = [
    'coaches_scanned' => 0,
    'jobs_enqueued' => 0,
  ];

  if (!appleCaldavSyncTablesAvailable()) {
    return $summary;
  }

  $pdo = getDB();
  $coachLimit = max(1, min(100, $coachLimit));
  $eventsPerCoachLimit = max(1, min(2000, $eventsPerCoachLimit));

  $coachStmt = $pdo->prepare(
    'SELECT id
     FROM coaches
     WHERE apple_caldav_sync_enabled = 1
       AND apple_caldav_calendar_url IS NOT NULL
       AND apple_caldav_calendar_url <> ""
       AND apple_caldav_username IS NOT NULL
       AND apple_caldav_username <> ""
       AND apple_caldav_app_password IS NOT NULL
       AND apple_caldav_app_password <> ""
     ORDER BY id ASC
     LIMIT ' . $coachLimit
  );
  $coachStmt->execute();
  $coachIds = $coachStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

  foreach ($coachIds as $coachIdRaw) {
    $coachId = (int)$coachIdRaw;
    if ($coachId <= 0) {
      continue;
    }

    $summary['coaches_scanned']++;

    $eventStmt = $pdo->prepare(
      'SELECT e.id
       FROM coach_calendar_events e
       LEFT JOIN coach_apple_caldav_event_links l ON l.coach_id = e.coach_id AND l.event_id = e.id
       WHERE e.coach_id = ?
         AND e.starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         AND l.event_id IS NULL
       ORDER BY e.starts_at ASC
       LIMIT ' . $eventsPerCoachLimit
    );
    $eventStmt->execute([$coachId]);
    $eventIds = $eventStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($eventIds as $eventIdRaw) {
      $eventId = (int)$eventIdRaw;
      if ($eventId <= 0) {
        continue;
      }

      enqueueCoachAppleCaldavSync($coachId, $eventId, 'upsert');
      $summary['jobs_enqueued']++;
    }
  }

  return $summary;
}

function syncCoachEventUpsertToAppleCaldav(int $coachId, ?int $eventId): void {
  if ($coachId <= 0 || $eventId === null || $eventId <= 0 || !appleCaldavSyncTablesAvailable()) {
    return;
  }

  $pdo = getDB();
  $eventStmt = $pdo->prepare(
    'SELECT e.id,
            e.coach_id,
            e.athlete_id,
            e.second_athlete_id,
            e.approval_status,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.updated_at,
            e.created_at,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE e.id = ?
       AND e.coach_id = ?
     LIMIT 1'
  );
  $eventStmt->execute([$eventId, $coachId]);
  $event = $eventStmt->fetch();

  if (!$event) {
    syncCoachEventDeleteToAppleCaldav($coachId, $eventId);
    return;
  }

  $coach = getCoachAppleCaldavConfig($coachId);
  if (!$coach || empty($coach['apple_caldav_sync_enabled'])) {
    return;
  }

  $calendarUrl = normalizeAppleCaldavCalendarUrl((string)($coach['apple_caldav_calendar_url'] ?? ''));
  if ($calendarUrl === '') {
    throw new RuntimeException('Apple CalDAV: chybi URL kalendare.');
  }

  $username = trim((string)($coach['apple_caldav_username'] ?? ''));
  $password = trim((string)($coach['apple_caldav_app_password'] ?? ''));
  if ($username === '' || $password === '') {
    throw new RuntimeException('Apple CalDAV: chybi prihlasovaci udaje.');
  }

  $uid = buildAppleCaldavEventUid($coachId, $event);
  $remoteHref = buildAppleCaldavRemoteHref($calendarUrl, $uid);
  $icsPayload = buildAppleCaldavEventIcs($coachId, $event, $uid);

  $response = appleCaldavHttpRequest('PUT', $remoteHref, $username, $password, $icsPayload, [
    'Content-Type: text/calendar; charset=utf-8',
  ]);
  $status = (int)($response['status'] ?? 0);
  if (!in_array($status, [200, 201, 204], true)) {
    throw new RuntimeException('Apple CalDAV upsert selhal: HTTP ' . $status . ' | ' . (string)($response['body'] ?? ''));
  }

  $etag = trim((string)($response['headers']['etag'] ?? ''));
  $upsertLink = $pdo->prepare(
    'INSERT INTO coach_apple_caldav_event_links (coach_id, event_id, remote_href, remote_etag, last_synced_at, last_error)
     VALUES (?, ?, ?, ?, NOW(), NULL)
     ON DUPLICATE KEY UPDATE remote_href = VALUES(remote_href), remote_etag = VALUES(remote_etag), last_synced_at = NOW(), last_error = NULL'
  );
  $upsertLink->execute([$coachId, $eventId, $remoteHref, $etag !== '' ? $etag : null]);

  markCoachAppleCaldavSyncSuccess($coachId);
}

function syncCoachEventDeleteToAppleCaldav(int $coachId, ?int $eventId): void {
  if ($coachId <= 0 || $eventId === null || $eventId <= 0 || !appleCaldavSyncTablesAvailable()) {
    return;
  }

  $pdo = getDB();
  $linkStmt = $pdo->prepare(
    'SELECT remote_href
     FROM coach_apple_caldav_event_links
     WHERE coach_id = ? AND event_id = ?
     LIMIT 1'
  );
  $linkStmt->execute([$coachId, $eventId]);
  $remoteHref = trim((string)($linkStmt->fetchColumn() ?: ''));

  $coach = getCoachAppleCaldavConfig($coachId);
  if (!$coach || empty($coach['apple_caldav_sync_enabled'])) {
    $deleteLocal = $pdo->prepare('DELETE FROM coach_apple_caldav_event_links WHERE coach_id = ? AND event_id = ?');
    $deleteLocal->execute([$coachId, $eventId]);
    return;
  }

  $calendarUrl = normalizeAppleCaldavCalendarUrl((string)($coach['apple_caldav_calendar_url'] ?? ''));
  $candidateDeleteHrefs = [];
  if ($remoteHref !== '') {
    $candidateDeleteHrefs[] = $remoteHref;
  }
  if ($calendarUrl !== '') {
    $uid = 'trainerapp-coach-' . $coachId . '-event-' . $eventId . '@reservio.online';
    foreach ([
      buildAppleCaldavRemoteHref($calendarUrl, $uid),
      buildAppleCaldavLegacyRemoteHref($calendarUrl, $uid),
    ] as $href) {
      if (!in_array($href, $candidateDeleteHrefs, true)) {
        $candidateDeleteHrefs[] = $href;
      }
    }
  }
  if (empty($candidateDeleteHrefs)) {
    return;
  }

  $username = trim((string)($coach['apple_caldav_username'] ?? ''));
  $password = trim((string)($coach['apple_caldav_app_password'] ?? ''));
  if ($username === '' || $password === '') {
    throw new RuntimeException('Apple CalDAV delete: chybi prihlasovaci udaje.');
  }

  $lastDeleteError = null;
  foreach ($candidateDeleteHrefs as $candidateHref) {
    $response = appleCaldavHttpRequest('DELETE', $candidateHref, $username, $password, null, []);
    $status = (int)($response['status'] ?? 0);
    if (in_array($status, [200, 202, 204, 404], true)) {
      $lastDeleteError = null;
      break;
    }
    $lastDeleteError = 'Apple CalDAV delete selhal: HTTP ' . $status . ' | ' . (string)($response['body'] ?? '');
  }
  if ($lastDeleteError !== null) {
    throw new RuntimeException($lastDeleteError);
  }

  $deleteLink = $pdo->prepare('DELETE FROM coach_apple_caldav_event_links WHERE coach_id = ? AND event_id = ?');
  $deleteLink->execute([$coachId, $eventId]);

  markCoachAppleCaldavSyncSuccess($coachId);
}

function getCoachAppleCaldavConfig(int $coachId): ?array {
  $pdo = getDB();
  $stmt = $pdo->prepare(
    'SELECT id,
            apple_caldav_sync_enabled,
            apple_caldav_calendar_url,
            apple_caldav_username,
            apple_caldav_app_password
     FROM coaches
     WHERE id = ?
     LIMIT 1'
  );
  $stmt->execute([$coachId]);
  $row = $stmt->fetch();

  return $row ?: null;
}

function buildAppleCaldavEventUid(int $coachId, array $event): string {
  $domain = 'reservio.online';
  $eventId = (int)($event['id'] ?? 0);
  return 'trainerapp-coach-' . $coachId . '-event-' . $eventId . '@' . $domain;
}

function buildAppleCaldavRemoteHref(string $calendarUrl, string $uid): string {
  $base = rtrim($calendarUrl, '/') . '/';

  // Nazev souboru musi byt URL-safe; nektere CalDAV servery odmitaji %40 a podobne znaky.
  $fileBase = strtolower(preg_replace('/[^a-z0-9._-]+/i', '-', (string)$uid));
  $fileBase = trim((string)$fileBase, '-._');
  if ($fileBase === '') {
    $fileBase = 'event-' . substr(sha1((string)$uid), 0, 24);
  }
  if (!str_ends_with($fileBase, '.ics')) {
    $fileBase .= '.ics';
  }

  return $base . $fileBase;
}

function buildAppleCaldavLegacyRemoteHref(string $calendarUrl, string $uid): string {
  $base = rtrim($calendarUrl, '/') . '/';
  return $base . rawurlencode($uid) . '.ics';
}

function normalizeAppleCaldavCalendarUrl(string $url): string {
  $url = trim($url);
  if ($url === '') {
    return '';
  }

  if (!preg_match('#^https://#i', $url)) {
    return '';
  }

  return rtrim($url, '/') . '/';
}

function ensureAppleCaldavTrainerAppCalendarUrl(string $username, string $password, string $displayName = 'TrainerApp', ?bool &$created = null): string {
  $created = false;
  $displayName = trim($displayName);
  if ($displayName === '') {
    $displayName = 'TrainerApp';
  }

  $diagnostics = [];
  $existing = discoverAppleCaldavTrainerAppCalendarUrl($username, $password, $displayName, $diagnostics);
  if (is_string($existing) && trim($existing) !== '') {
    return normalizeAppleCaldavCalendarUrl($existing);
  }

  try {
    [$baseUrl, $homeUrl] = discoverAppleCaldavHomeUrl($username, $password);
    $createdUrl = appleCaldavCreateTrainerAppCalendarUrl($baseUrl, $homeUrl, $username, $password, $displayName);
    if ($createdUrl !== '') {
      $created = true;
      return normalizeAppleCaldavCalendarUrl($createdUrl);
    }
  } catch (Throwable $e) {
    $diagnostics['detail'] = trim((string)$e->getMessage());
  }

  $availableNames = array_values(array_unique(array_filter((array)($diagnostics['available_names'] ?? []), fn($value) => trim((string)$value) !== '')));
  $availableUrls = array_values(array_unique(array_filter((array)($diagnostics['available_urls'] ?? []), fn($value) => trim((string)$value) !== '')));
  $detail = trim((string)($diagnostics['detail'] ?? ''));

  $hint = '';
  if (!empty($availableNames)) {
    $hint .= ' Nalezene kalendare: ' . implode(', ', array_slice($availableNames, 0, 10)) . '.';
  }
  if (!empty($availableUrls)) {
    $hint .= ' Kandidatni URL: ' . implode(' | ', array_slice($availableUrls, 0, 6)) . '.';
  }
  if ($detail !== '') {
    $hint .= ' Detail: ' . mb_substr(preg_replace('/\s+/', ' ', $detail), 0, 260, 'UTF-8') . '.';
  }

  throw new RuntimeException('Apple CalDAV: existujici kalendar "' . $displayName . '" se nepodarilo dohledat.' . $hint);
}

function appleCaldavCreateTrainerAppCalendarUrl(string $baseUrl, string $homeUrl, string $username, string $password, string $displayName): string {
  $displayName = trim($displayName);
  if ($displayName === '') {
    $displayName = 'TrainerApp';
  }

  $homeUrl = normalizeAppleCaldavCalendarUrl($homeUrl);
  if ($homeUrl === '') {
    throw new RuntimeException('Apple CalDAV: calendar-home-set je neplatny.');
  }

  $calendarSlug = rawurlencode($displayName);
  $createTargets = [rtrim($homeUrl, '/') . '/' . $calendarSlug . '/'];

  $homePath = strtolower((string)parse_url($homeUrl, PHP_URL_PATH));
  if (str_ends_with($homePath, '/calendars/')) {
    $createTargets[] = rtrim($homeUrl, '/') . '/home/' . $calendarSlug . '/';
    $createTargets[] = rtrim($homeUrl, '/') . '/work/' . $calendarSlug . '/';
  }

  try {
    $rawCandidates = listAppleCaldavRawCalendarCandidates($baseUrl, $homeUrl, $username, $password);
    foreach ($rawCandidates as $candidateUrl) {
      $candidateUrl = normalizeAppleCaldavCalendarUrl((string)$candidateUrl);
      if ($candidateUrl === '') {
        continue;
      }

      $candidatePath = strtolower((string)parse_url($candidateUrl, PHP_URL_PATH));
      if (!(str_ends_with($candidatePath, '/calendars/') || str_ends_with($candidatePath, '/home/') || str_ends_with($candidatePath, '/work/'))) {
        continue;
      }

      $createTargets[] = rtrim($candidateUrl, '/') . '/' . $calendarSlug . '/';
    }
  } catch (Throwable $e) {
    // Pokud selze nacitani kandidatu, pokracujeme s vychozimi create targety.
  }

  $createTargets = array_values(array_unique(array_filter($createTargets)));
  $createBody = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
    . '<d:set>'
    . '<d:prop>'
    . '<d:displayname>' . htmlspecialchars($displayName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</d:displayname>'
    . '<c:supported-calendar-component-set><c:comp name="VEVENT" /></c:supported-calendar-component-set>'
    . '</d:prop>'
    . '</d:set>'
    . '</c:mkcalendar>';

  $attemptErrors = [];
  foreach ($createTargets as $calendarUrl) {
    $response = appleCaldavHttpRequest('MKCALENDAR', $calendarUrl, $username, $password, $createBody, [
      'Content-Type: application/xml; charset=utf-8',
    ]);
    $status = (int)($response['status'] ?? 0);
    if (in_array($status, [200, 201, 204], true)) {
      return $calendarUrl;
    }

    $bodySnippet = trim((string)($response['body'] ?? ''));
    if ($bodySnippet !== '') {
      $bodySnippet = preg_replace('/\s+/', ' ', $bodySnippet);
      $bodySnippet = mb_substr((string)$bodySnippet, 0, 140, 'UTF-8');
    }
    $attemptErrors[] = $calendarUrl . ' -> HTTP ' . $status . ($bodySnippet !== '' ? ' (' . $bodySnippet . ')' : '');
  }

  $detail = !empty($attemptErrors) ? (' Zkousene cesty: ' . implode(' | ', array_slice($attemptErrors, 0, 5)) . '.') : '';
  throw new RuntimeException('Apple CalDAV: kalendar ' . $displayName . ' se nepodarilo vytvorit.' . $detail);
}

function discoverAppleCaldavHomeUrl(string $username, string $password): array {
  $baseUrl = 'https://caldav.icloud.com';

  $principalBody = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<d:propfind xmlns:d="DAV:">'
    . '<d:prop><d:current-user-principal /></d:prop>'
    . '</d:propfind>';
  $principalResponse = appleCaldavHttpRequest('PROPFIND', $baseUrl . '/', $username, $password, $principalBody, [
    'Depth: 0',
    'Content-Type: application/xml; charset=utf-8',
  ]);
  $principalStatus = (int)($principalResponse['status'] ?? 0);
  if (!in_array($principalStatus, [200, 207], true)) {
    throw new RuntimeException('Apple CalDAV discovery selhal v kroku principal (HTTP ' . $principalStatus . ').');
  }

  $principalXml = @simplexml_load_string((string)($principalResponse['body'] ?? ''));
  if (!$principalXml) {
    throw new RuntimeException('Apple CalDAV discovery: principal XML nelze nacist.');
  }
  $principalXml->registerXPathNamespace('d', 'DAV:');
  $principalHrefs = $principalXml->xpath('//d:current-user-principal/d:href');
  $principalHref = isset($principalHrefs[0]) ? trim((string)$principalHrefs[0]) : '';
  if ($principalHref === '') {
    throw new RuntimeException('Apple CalDAV discovery: current-user-principal nebyl nalezen.');
  }

  $principalUrl = appleCaldavAbsoluteHref($baseUrl, $principalHref);

  $homeBody = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
    . '<d:prop><c:calendar-home-set /></d:prop>'
    . '</d:propfind>';
  $homeResponse = appleCaldavHttpRequest('PROPFIND', $principalUrl, $username, $password, $homeBody, [
    'Depth: 0',
    'Content-Type: application/xml; charset=utf-8',
  ]);
  $homeStatus = (int)($homeResponse['status'] ?? 0);
  if (!in_array($homeStatus, [200, 207], true)) {
    throw new RuntimeException('Apple CalDAV discovery selhal v kroku home-set (HTTP ' . $homeStatus . ').');
  }

  $homeXml = @simplexml_load_string((string)($homeResponse['body'] ?? ''));
  if (!$homeXml) {
    throw new RuntimeException('Apple CalDAV discovery: home-set XML nelze nacist.');
  }
  $homeXml->registerXPathNamespace('d', 'DAV:');
  $homeXml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
  $homeHrefs = $homeXml->xpath('//c:calendar-home-set/d:href');
  $homeHref = isset($homeHrefs[0]) ? trim((string)$homeHrefs[0]) : '';
  if ($homeHref === '') {
    throw new RuntimeException('Apple CalDAV discovery: calendar-home-set nebyl nalezen.');
  }

  $homeUrl = appleCaldavAbsoluteHref($baseUrl, $homeHref);

  return [$baseUrl, rtrim($homeUrl, '/') . '/'];
}

function appleCaldavDisplayNameLooksLike(string $candidateDisplayName, string $expectedDisplayName): bool {
  $candidateDisplayName = trim($candidateDisplayName);
  $expectedDisplayName = trim($expectedDisplayName);
  if ($candidateDisplayName === '' || $expectedDisplayName === '') {
    return false;
  }

  $candidateNorm = mb_strtolower($candidateDisplayName, 'UTF-8');
  $expectedNorm = mb_strtolower($expectedDisplayName, 'UTF-8');
  $candidateCompact = preg_replace('/[\s\p{P}\p{S}_-]+/u', '', $candidateNorm) ?? $candidateNorm;
  $expectedCompact = preg_replace('/[\s\p{P}\p{S}_-]+/u', '', $expectedNorm) ?? $expectedNorm;

  return $candidateNorm === $expectedNorm
    || $candidateCompact === $expectedCompact
    || str_contains($candidateNorm, $expectedNorm)
    || str_contains($candidateCompact, $expectedCompact);
}

function findAppleCaldavCalendarByDisplayName(string $baseUrl, string $homeUrl, string $username, string $password, string $displayName): ?string {
  $calendars = listAppleCaldavCalendars($baseUrl, $homeUrl, $username, $password);
  $displayName = trim($displayName);
  if ($displayName === '') {
    return null;
  }
  $remoteCheckCandidates = [];

  foreach ($calendars as $calendar) {
    $candidateDisplay = (string)($calendar['display_name'] ?? '');
    if (appleCaldavDisplayNameLooksLike($candidateDisplay, $displayName)) {
      return (string)$calendar['url'];
    }

    $candidateUrl = (string)($calendar['url'] ?? '');
    if ($candidateUrl !== '' && (appleCaldavUrlLikelyTrainerAppCalendar($candidateUrl) || trim($candidateDisplay) === '')) {
      $remoteCheckCandidates[] = $candidateUrl;
    }
  }

  $remoteCheckCandidates = array_values(array_unique($remoteCheckCandidates));
  foreach (array_slice($remoteCheckCandidates, 0, 4) as $candidateUrl) {
    if (appleCaldavCalendarDisplayNameMatches($candidateUrl, $username, $password, $displayName)) {
      return $candidateUrl;
    }
  }

  return null;
}

function appleCaldavCalendarDisplayNameMatches(string $calendarUrl, string $username, string $password, string $expectedDisplayName): bool {
  $calendarUrl = normalizeAppleCaldavCalendarUrl($calendarUrl);
  $expectedDisplayName = trim($expectedDisplayName);
  if ($calendarUrl === '' || $expectedDisplayName === '') {
    return false;
  }

  $body = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<d:propfind xmlns:d="DAV:\"><d:prop><d:displayname /></d:prop></d:propfind>';
  $response = appleCaldavHttpRequest('PROPFIND', $calendarUrl, $username, $password, $body, [
    'Depth: 0',
    'Content-Type: application/xml; charset=utf-8',
  ]);
  $status = (int)($response['status'] ?? 0);
  if (!in_array($status, [200, 207], true)) {
    return false;
  }

  $xml = @simplexml_load_string((string)($response['body'] ?? ''));
  if (!$xml) {
    return false;
  }

  $xml->registerXPathNamespace('d', 'DAV:');
  $nodes = $xml->xpath('//d:displayname|//*[local-name()="displayname"]');
  $display = isset($nodes[0]) ? trim((string)$nodes[0]) : '';
  if ($display === '') {
    return false;
  }

  $displayNorm = mb_strtolower($display, 'UTF-8');
  $expectedNorm = mb_strtolower($expectedDisplayName, 'UTF-8');
  $displayCompact = preg_replace('/[\s\p{P}\p{S}_-]+/u', '', $displayNorm) ?? $displayNorm;
  $expectedCompact = preg_replace('/[\s\p{P}\p{S}_-]+/u', '', $expectedNorm) ?? $expectedNorm;

  return $displayNorm === $expectedNorm
    || $displayCompact === $expectedCompact
    || str_contains($displayNorm, $expectedNorm)
    || str_contains($displayCompact, $expectedCompact);
}

function appleCaldavUrlLikelyTrainerAppCalendar(string $calendarUrl): bool {
  $calendarUrl = normalizeAppleCaldavCalendarUrl($calendarUrl);
  if ($calendarUrl === '') {
    return false;
  }

  $path = strtolower((string)parse_url($calendarUrl, PHP_URL_PATH));
  if ($path === '') {
    return false;
  }

  $decodedPath = strtolower(rawurldecode($path));
  return str_contains($path, 'trainerapp') || str_contains($decodedPath, 'trainerapp');
}

function listAppleCaldavCalendars(string $baseUrl, string $homeUrl, string $username, string $password): array {
  $listBody = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
    . '<d:prop><d:resourcetype /><d:displayname /></d:prop>'
    . '</d:propfind>';

  $queue = [rtrim($homeUrl, '/') . '/'];
  $scanned = [];
  $itemsByUrl = [];
  $maxCollectionsToScan = 24;

  while (!empty($queue) && count($scanned) < $maxCollectionsToScan) {
    $collectionUrl = rtrim((string)array_shift($queue), '/') . '/';
    if ($collectionUrl === '' || isset($scanned[$collectionUrl])) {
      continue;
    }
    $scanned[$collectionUrl] = true;

    $listResponse = appleCaldavHttpRequest('PROPFIND', $collectionUrl, $username, $password, $listBody, [
      'Depth: 1',
      'Content-Type: application/xml; charset=utf-8',
    ]);
    $listStatus = (int)($listResponse['status'] ?? 0);
    if (!in_array($listStatus, [200, 207], true)) {
      continue;
    }

    $listXml = @simplexml_load_string((string)($listResponse['body'] ?? ''));
    if (!$listXml) {
      continue;
    }
    $listXml->registerXPathNamespace('d', 'DAV:');
    $listXml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
    $responses = $listXml->xpath('//d:response');
    if (!is_array($responses)) {
      continue;
    }

    foreach ($responses as $responseNode) {
      $hrefNodes = $responseNode->xpath('./d:href');
      $href = isset($hrefNodes[0]) ? trim((string)$hrefNodes[0]) : '';
      if ($href === '') {
        continue;
      }

      $absolute = rtrim(appleCaldavAbsoluteHref($baseUrl, $href), '/') . '/';
      $pathLower = strtolower((string)parse_url($absolute, PHP_URL_PATH));
      if (str_contains($pathLower, '/inbox/') || str_contains($pathLower, '/outbox/') || str_contains($pathLower, '/notification/')) {
        continue;
      }

      $displayNodes = $responseNode->xpath('./d:propstat/d:prop/d:displayname|.//*[local-name()="displayname"]');
      $displayName = isset($displayNodes[0]) ? trim((string)$displayNodes[0]) : '';

      $resourcetype = $responseNode->xpath('.//d:resourcetype');
      $isCollection = false;
      $isCalendar = false;
      if (is_array($resourcetype) && isset($resourcetype[0])) {
        $rt = $resourcetype[0];
        $rt->registerXPathNamespace('d', 'DAV:');
        $rt->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        $isCollection = !empty($rt->xpath('./d:collection'));
        $isCalendar = !empty($rt->xpath('./c:calendar'));
      }

      if ($isCollection && !$isCalendar && $absolute !== $collectionUrl && !isset($scanned[$absolute])) {
        $queue[] = $absolute;
      }

      if (!($isCollection && $isCalendar)) {
        if ($isCollection && $absolute !== $collectionUrl) {
          $itemsByUrl[$absolute] = [
            'display_name' => $displayName,
            'url' => $absolute,
            'is_calendar' => false,
          ];
        }
        continue;
      }

      $itemsByUrl[$absolute] = [
        'display_name' => $displayName,
        'url' => $absolute,
        'is_calendar' => true,
      ];
    }
  }

  return array_values($itemsByUrl);
}

function generateMobileConfigUuidV4(): string {
  $bytes = random_bytes(16);
  $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
  $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
  $hex = bin2hex($bytes);

  return sprintf(
    '%s-%s-%s-%s-%s',
    substr($hex, 0, 8),
    substr($hex, 8, 4),
    substr($hex, 12, 4),
    substr($hex, 16, 4),
    substr($hex, 20, 12)
  );
}

function mobileConfigXmlEscape(string $value): string {
  return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function buildAppleCaldavMobileConfig(array $options): string {
  $profileDisplayName = trim((string)($options['profile_display_name'] ?? 'TrainerApp Apple Kalendar'));
  $profileDescription = trim((string)($options['profile_description'] ?? 'Automaticke nastaveni Apple CalDAV pro TrainerApp.'));
  $payloadIdentifier = trim((string)($options['payload_identifier'] ?? 'online.reservio.trainerapp.caldav'));
  $accountDescription = trim((string)($options['account_description'] ?? 'TrainerApp'));
  $hostName = trim((string)($options['host_name'] ?? ''));
  $port = (int)($options['port'] ?? 443);
  $principalUrl = trim((string)($options['principal_url'] ?? '/'));
  $username = trim((string)($options['username'] ?? ''));
  $password = (string)($options['password'] ?? '');

  if ($hostName === '' || $username === '') {
    throw new RuntimeException('Mobileconfig: chybi host nebo uzivatelske jmeno.');
  }

  if ($principalUrl === '') {
    $principalUrl = '/';
  }
  if (!str_starts_with($principalUrl, '/')) {
    $principalUrl = '/' . $principalUrl;
  }

  $profileUuid = generateMobileConfigUuidV4();
  $accountUuid = generateMobileConfigUuidV4();

  $profileDisplayNameEsc = mobileConfigXmlEscape($profileDisplayName);
  $profileDescriptionEsc = mobileConfigXmlEscape($profileDescription);
  $payloadIdentifierEsc = mobileConfigXmlEscape($payloadIdentifier);
  $accountDescriptionEsc = mobileConfigXmlEscape($accountDescription);
  $hostNameEsc = mobileConfigXmlEscape($hostName);
  $principalUrlEsc = mobileConfigXmlEscape($principalUrl);
  $usernameEsc = mobileConfigXmlEscape($username);
  $profileUuidEsc = mobileConfigXmlEscape($profileUuid);
  $accountUuidEsc = mobileConfigXmlEscape($accountUuid);

  $passwordXml = '';
  if ($password !== '') {
    $passwordXml = "\n      <key>CalDAVPassword</key>\n      <string>" . mobileConfigXmlEscape($password) . "</string>";
  }

  return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
    . "<!DOCTYPE plist PUBLIC \"-//Apple//DTD PLIST 1.0//EN\" \"http://www.apple.com/DTDs/PropertyList-1.0.dtd\">\n"
    . "<plist version=\"1.0\">\n"
    . "<dict>\n"
    . "  <key>PayloadContent</key>\n"
    . "  <array>\n"
    . "    <dict>\n"
    . "      <key>PayloadType</key>\n"
    . "      <string>com.apple.caldav.account</string>\n"
    . "      <key>PayloadVersion</key>\n"
    . "      <integer>1</integer>\n"
    . "      <key>PayloadIdentifier</key>\n"
    . "      <string>{$payloadIdentifierEsc}.account</string>\n"
    . "      <key>PayloadUUID</key>\n"
    . "      <string>{$accountUuidEsc}</string>\n"
    . "      <key>PayloadDisplayName</key>\n"
    . "      <string>{$accountDescriptionEsc}</string>\n"
    . "      <key>CalDAVAccountDescription</key>\n"
    . "      <string>{$accountDescriptionEsc}</string>\n"
    . "      <key>CalDAVHostName</key>\n"
    . "      <string>{$hostNameEsc}</string>\n"
    . "      <key>CalDAVPort</key>\n"
    . "      <integer>{$port}</integer>\n"
    . "      <key>CalDAVUseSSL</key>\n"
    . "      <true/>\n"
    . "      <key>CalDAVPrincipalURL</key>\n"
    . "      <string>{$principalUrlEsc}</string>\n"
    . "      <key>CalDAVUsername</key>\n"
    . "      <string>{$usernameEsc}</string>"
    . $passwordXml . "\n"
    . "    </dict>\n"
    . "  </array>\n"
    . "  <key>PayloadType</key>\n"
    . "  <string>Configuration</string>\n"
    . "  <key>PayloadVersion</key>\n"
    . "  <integer>1</integer>\n"
    . "  <key>PayloadIdentifier</key>\n"
    . "  <string>{$payloadIdentifierEsc}</string>\n"
    . "  <key>PayloadUUID</key>\n"
    . "  <string>{$profileUuidEsc}</string>\n"
    . "  <key>PayloadDisplayName</key>\n"
    . "  <string>{$profileDisplayNameEsc}</string>\n"
    . "  <key>PayloadDescription</key>\n"
    . "  <string>{$profileDescriptionEsc}</string>\n"
    . "  <key>PayloadOrganization</key>\n"
    . "  <string>TrainerApp</string>\n"
    . "  <key>PayloadRemovalDisallowed</key>\n"
    . "  <false/>\n"
    . "</dict>\n"
    . "</plist>\n";
}

function discoverAppleCaldavCalendarUrl(string $username, string $password): string {
  $baseUrl = 'https://caldav.icloud.com';
  $debug = [];

  $principalBody = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<d:propfind xmlns:d="DAV:">'
    . '<d:prop><d:current-user-principal /></d:prop>'
    . '</d:propfind>';
  $principalResponse = appleCaldavHttpRequest('PROPFIND', $baseUrl . '/', $username, $password, $principalBody, [
    'Depth: 0',
    'Content-Type: application/xml; charset=utf-8',
  ]);
  $principalStatus = (int)($principalResponse['status'] ?? 0);
  $debug[] = 'principal=' . $principalStatus;
  if (!in_array($principalStatus, [200, 207], true)) {
    throw new RuntimeException('Apple CalDAV discovery selhal v kroku principal (HTTP ' . $principalStatus . ').');
  }

  $principalXml = @simplexml_load_string((string)($principalResponse['body'] ?? ''));
  if (!$principalXml) {
    throw new RuntimeException('Apple CalDAV discovery: principal XML nelze nacist.');
  }
  $principalXml->registerXPathNamespace('d', 'DAV:');
  $principalHrefs = $principalXml->xpath('//d:current-user-principal/d:href');
  $principalHref = isset($principalHrefs[0]) ? trim((string)$principalHrefs[0]) : '';
  if ($principalHref === '') {
    throw new RuntimeException('Apple CalDAV discovery: current-user-principal nebyl nalezen.');
  }

  $principalUrl = appleCaldavAbsoluteHref($baseUrl, $principalHref);

  $homeBody = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
    . '<d:prop><c:calendar-home-set /></d:prop>'
    . '</d:propfind>';
  $homeResponse = appleCaldavHttpRequest('PROPFIND', $principalUrl, $username, $password, $homeBody, [
    'Depth: 0',
    'Content-Type: application/xml; charset=utf-8',
  ]);
  $homeStatus = (int)($homeResponse['status'] ?? 0);
  $debug[] = 'home=' . $homeStatus;
  if (!in_array($homeStatus, [200, 207], true)) {
    throw new RuntimeException('Apple CalDAV discovery selhal v kroku home-set (HTTP ' . $homeStatus . ').');
  }

  $homeXml = @simplexml_load_string((string)($homeResponse['body'] ?? ''));
  if (!$homeXml) {
    throw new RuntimeException('Apple CalDAV discovery: home-set XML nelze nacist.');
  }
  $homeXml->registerXPathNamespace('d', 'DAV:');
  $homeXml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
  $homeHrefs = $homeXml->xpath('//c:calendar-home-set/d:href');
  $homeHref = isset($homeHrefs[0]) ? trim((string)$homeHrefs[0]) : '';
  if ($homeHref === '') {
    throw new RuntimeException('Apple CalDAV discovery: calendar-home-set nebyl nalezen.');
  }

  $homeUrl = appleCaldavAbsoluteHref($baseUrl, $homeHref);

  $listBody = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
    . '<d:prop><d:resourcetype /><d:displayname /></d:prop>'
    . '</d:propfind>';
  $listResponse = appleCaldavHttpRequest('PROPFIND', $homeUrl, $username, $password, $listBody, [
    'Depth: 1',
    'Content-Type: application/xml; charset=utf-8',
  ]);
  $listStatus = (int)($listResponse['status'] ?? 0);
  $debug[] = 'list=' . $listStatus;
  if (!in_array($listStatus, [200, 207], true)) {
    throw new RuntimeException('Apple CalDAV discovery selhal v kroku seznamu kalendaru (HTTP ' . $listStatus . ').');
  }

  $listXml = @simplexml_load_string((string)($listResponse['body'] ?? ''));
  if (!$listXml) {
    throw new RuntimeException('Apple CalDAV discovery: seznam kalendaru XML nelze nacist.');
  }
  $listXml->registerXPathNamespace('d', 'DAV:');
  $listXml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

  $responses = $listXml->xpath('//d:response');
  if (!is_array($responses)) {
    throw new RuntimeException('Apple CalDAV discovery: odpoved neobsahuje zadne kalendare.');
  }

  $preferredCandidates = [];
  $fallbackCandidates = [];
  $normalizedHomeUrl = rtrim($homeUrl, '/') . '/';

  foreach ($responses as $responseNode) {
    $hrefNodes = $responseNode->xpath('./d:href');
    $href = isset($hrefNodes[0]) ? trim((string)$hrefNodes[0]) : '';
    if ($href === '') {
      continue;
    }

    $candidate = rtrim(appleCaldavAbsoluteHref($baseUrl, $href), '/') . '/';
    $candidatePath = (string)parse_url($candidate, PHP_URL_PATH);
    $pathLower = strtolower($candidatePath);
    if (str_contains($pathLower, '/inbox/') || str_contains($pathLower, '/outbox/') || str_contains($pathLower, '/notification/')) {
      continue;
    }

    $resourcetype = $responseNode->xpath('.//d:resourcetype');
    if (is_array($resourcetype) && isset($resourcetype[0])) {
      $rt = $resourcetype[0];
      $rt->registerXPathNamespace('d', 'DAV:');
      $rt->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
      $isCollection = !empty($rt->xpath('./d:collection'));
      $isCalendar = !empty($rt->xpath('./c:calendar'));
      if ($isCollection && $isCalendar) {
        $preferredCandidates[] = $candidate;
        continue;
      }
    }

    // Fallback: nektere iCloud odpovedi nevraci c:calendar konzistentne,
    // ale href je i tak validni cilova kolekce.
    $fallbackCandidates[] = $candidate;
  }

  // Dalsi fallback: vytahnout href i z raw XML (bez ohledu na namespace).
  $rawHrefCandidates = extractAppleCaldavHrefCandidatesFromXml((string)($listResponse['body'] ?? ''), $baseUrl);
  foreach ($rawHrefCandidates as $candidate) {
    $candidate = rtrim((string)$candidate, '/') . '/';
    $candidatePath = strtolower((string)parse_url($candidate, PHP_URL_PATH));
    if ($candidate === $normalizedHomeUrl) {
      continue;
    }
    if (str_contains($candidatePath, '/inbox/') || str_contains($candidatePath, '/outbox/') || str_contains($candidatePath, '/notification/')) {
      continue;
    }

    if (!in_array($candidate, $fallbackCandidates, true) && !in_array($candidate, $preferredCandidates, true)) {
      $fallbackCandidates[] = $candidate;
    }
  }

  $probeCandidates = [];
  foreach ([$preferredCandidates, $fallbackCandidates, [$normalizedHomeUrl]] as $group) {
    foreach ($group as $candidate) {
      $candidate = rtrim((string)$candidate, '/') . '/';
      if ($candidate === '') {
        continue;
      }
      if (!in_array($candidate, $probeCandidates, true)) {
        $probeCandidates[] = $candidate;
      }
    }
  }

  // iCloud casto vraci jen /calendars/ root, ale zapis je povolen az do podkolekce (typicky /home/).
  $heuristicCandidates = [];
  foreach ($probeCandidates as $candidate) {
    $candidatePath = strtolower((string)parse_url($candidate, PHP_URL_PATH));
    if (str_ends_with($candidatePath, '/calendars/')) {
      $heuristicCandidates[] = rtrim($candidate, '/') . '/home/';
      $heuristicCandidates[] = rtrim($candidate, '/') . '/default/';
      $heuristicCandidates[] = rtrim($candidate, '/') . '/calendar/';
    }
  }
  foreach ($heuristicCandidates as $candidate) {
    $candidate = rtrim((string)$candidate, '/') . '/';
    if (!in_array($candidate, $probeCandidates, true)) {
      $probeCandidates[] = $candidate;
    }
  }

  foreach ($probeCandidates as $candidate) {
    $probe = appleCaldavProbeCollectionWritable($candidate, $username, $password);
    $debug[] = 'probe(' . $candidate . ')=' . ($probe['detail'] ?? 'unknown');
    if (!empty($probe['ok'])) {
      return $candidate;
    }
  }

  throw new RuntimeException(
    'Apple CalDAV discovery: nenasel jsem zadny zapisovatelny kalendar. Zadejte prosim CalDAV URL rucne. [' . implode('; ', $debug) . ']'
  );
}

function discoverAppleCaldavTrainerAppCalendarUrl(string $username, string $password, string $displayName = 'TrainerApp', array &$diagnostics = []): ?string {
  $diagnostics = [
    'available_names' => [],
    'available_urls' => [],
    'detail' => '',
  ];

  $displayName = trim($displayName);
  if ($displayName === '') {
    $displayName = 'TrainerApp';
  }

  try {
    [$baseUrl, $homeUrl] = discoverAppleCaldavHomeUrl($username, $password);

    $calendars = listAppleCaldavCalendars($baseUrl, $homeUrl, $username, $password);
    $candidateUrls = [];
    foreach ($calendars as $calendar) {
      $name = trim((string)($calendar['display_name'] ?? ''));
      $url = trim((string)($calendar['url'] ?? ''));
      if ($name !== '') {
        $diagnostics['available_names'][] = $name;
      }
      if ($url !== '') {
        $diagnostics['available_urls'][] = $url;
        $candidateUrls[] = $url;
      }
    }
    $diagnostics['available_names'] = array_values(array_unique($diagnostics['available_names']));
    $diagnostics['available_urls'] = array_values(array_unique($diagnostics['available_urls']));

    $rawCandidateUrls = listAppleCaldavRawCalendarCandidates($baseUrl, $homeUrl, $username, $password);
    foreach ($rawCandidateUrls as $rawCandidateUrl) {
      $normalizedRawCandidateUrl = normalizeAppleCaldavCalendarUrl((string)$rawCandidateUrl);
      if ($normalizedRawCandidateUrl === '') {
        continue;
      }
      if (!in_array($normalizedRawCandidateUrl, $candidateUrls, true)) {
        $candidateUrls[] = $normalizedRawCandidateUrl;
      }
      if (!in_array($normalizedRawCandidateUrl, $diagnostics['available_urls'], true)) {
        $diagnostics['available_urls'][] = $normalizedRawCandidateUrl;
      }
    }
    $diagnostics['available_urls'] = array_values(array_unique($diagnostics['available_urls']));

    $trainerPathCandidates = [];
    $remoteCheckCandidates = [];
    foreach ($candidateUrls as $candidateUrl) {
      $candidateDisplay = '';
      foreach ($calendars as $calendar) {
        if (normalizeAppleCaldavCalendarUrl((string)($calendar['url'] ?? '')) === normalizeAppleCaldavCalendarUrl($candidateUrl)) {
          $candidateDisplay = (string)($calendar['display_name'] ?? '');
          break;
        }
      }

      if (appleCaldavDisplayNameLooksLike($candidateDisplay, $displayName)) {
        return normalizeAppleCaldavCalendarUrl($candidateUrl);
      }
      if (appleCaldavUrlLikelyTrainerAppCalendar($candidateUrl)) {
        $trainerPathCandidates[] = normalizeAppleCaldavCalendarUrl($candidateUrl);
      }
      $remoteCheckCandidates[] = normalizeAppleCaldavCalendarUrl($candidateUrl);
    }

    $remoteCheckCandidates = array_values(array_unique(array_filter($remoteCheckCandidates)));
    foreach (array_slice($remoteCheckCandidates, 0, 4) as $candidateUrl) {
      if (appleCaldavCalendarDisplayNameMatches($candidateUrl, $username, $password, $displayName)) {
        return normalizeAppleCaldavCalendarUrl($candidateUrl);
      }
    }

    if (count($remoteCheckCandidates) > 4) {
      foreach (array_slice($remoteCheckCandidates, 4, 4) as $candidateUrl) {
        if (appleCaldavCalendarDisplayNameMatches($candidateUrl, $username, $password, $displayName)) {
          return normalizeAppleCaldavCalendarUrl($candidateUrl);
        }
      }
    }

    $trainerPathCandidates = array_values(array_unique(array_filter($trainerPathCandidates)));
    if (!empty($trainerPathCandidates)) {
      return (string)$trainerPathCandidates[0];
    }

    $diagnostics['detail'] = 'TrainerApp kalendar se nepodarilo spolehlive odlisit od ostatnich iCloud kalendaru pri rychlem skenu. Automaticky fallback na jiny zapisovatelny kalendar byl zablokovan.';
    return null;
  } catch (Throwable $e) {
    $diagnostics['detail'] = trim((string)$e->getMessage());
    return null;
  }
}

function appleCaldavCalendarUrlAcceptedForTrainerApp(string $calendarUrl, string $username, string $password, string $displayName = 'TrainerApp', array &$diagnostics = []): bool {
  $diagnostics = [
    'detail' => '',
    'discovered_url' => '',
  ];

  $normalizedInput = normalizeAppleCaldavCalendarUrl($calendarUrl);
  if ($normalizedInput === '') {
    $diagnostics['detail'] = 'Neplatna URL kalendare.';
    return false;
  }

  if (appleCaldavCalendarDisplayNameMatches($normalizedInput, $username, $password, $displayName)) {
    return true;
  }

  $discoveryDiagnostics = [];
  $autoDiscoveredUrl = discoverAppleCaldavTrainerAppCalendarUrl($username, $password, $displayName, $discoveryDiagnostics);
  $normalizedDiscovered = normalizeAppleCaldavCalendarUrl((string)$autoDiscoveredUrl);
  if ($normalizedDiscovered !== '') {
    $diagnostics['discovered_url'] = $normalizedDiscovered;
  }

  if ($normalizedDiscovered !== '' && $normalizedInput === $normalizedDiscovered) {
    return true;
  }

  $detail = trim((string)($discoveryDiagnostics['detail'] ?? ''));
  if ($detail !== '') {
    $diagnostics['detail'] = $detail;
  } else {
    $diagnostics['detail'] = 'URL nebyla potvrzena jako kalendar TrainerApp.';
  }

  return false;
}

function extractAppleCaldavHrefCandidatesFromXml(string $xml, string $baseUrl): array {
  $xml = trim($xml);
  if ($xml === '') {
    return [];
  }

  $candidates = [];

  if (preg_match_all('#<[^>]*href[^>]*>\s*([^<]+)\s*</[^>]*href>#i', $xml, $hrefMatches)) {
    foreach (($hrefMatches[1] ?? []) as $href) {
      $href = trim(html_entity_decode((string)$href, ENT_QUOTES | ENT_XML1, 'UTF-8'));
      if ($href === '') {
        continue;
      }
      $absolute = rtrim(appleCaldavAbsoluteHref($baseUrl, $href), '/') . '/';
      if (!in_array($absolute, $candidates, true)) {
        $candidates[] = $absolute;
      }
    }
  }

  if (preg_match_all('#https?://[^\s"\'\<]+/calendars/[^\s"\'\<]*/?#i', $xml, $urlMatches)) {
    foreach (($urlMatches[0] ?? []) as $url) {
      $url = rtrim((string)$url, '/') . '/';
      if (!in_array($url, $candidates, true)) {
        $candidates[] = $url;
      }
    }
  }

  return $candidates;
}

function listAppleCaldavRawCalendarCandidates(string $baseUrl, string $homeUrl, string $username, string $password): array {
  $listBody = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
    . '<d:prop><d:resourcetype /><d:displayname /></d:prop>'
    . '</d:propfind>';

  $queue = [rtrim($homeUrl, '/') . '/'];
  $scanned = [];
  $candidates = [];
  $maxCollectionsToScan = 12;

  while (!empty($queue) && count($scanned) < $maxCollectionsToScan) {
    $collectionUrl = rtrim((string)array_shift($queue), '/') . '/';
    if ($collectionUrl === '' || isset($scanned[$collectionUrl])) {
      continue;
    }
    $scanned[$collectionUrl] = true;

    try {
      $listResponse = appleCaldavHttpRequest('PROPFIND', $collectionUrl, $username, $password, $listBody, [
        'Depth: 1',
        'Content-Type: application/xml; charset=utf-8',
      ]);
    } catch (Throwable $e) {
      continue;
    }

    $listStatus = (int)($listResponse['status'] ?? 0);
    if (!in_array($listStatus, [200, 207], true)) {
      continue;
    }

    $hrefCandidates = extractAppleCaldavHrefCandidatesFromXml((string)($listResponse['body'] ?? ''), $baseUrl);
    foreach ($hrefCandidates as $candidateUrl) {
      $candidateUrl = normalizeAppleCaldavCalendarUrl((string)$candidateUrl);
      if ($candidateUrl === '' || $candidateUrl === $collectionUrl) {
        continue;
      }

      $candidatePath = strtolower((string)parse_url($candidateUrl, PHP_URL_PATH));
      if (str_contains($candidatePath, '/inbox/') || str_contains($candidatePath, '/outbox/') || str_contains($candidatePath, '/notification/')) {
        continue;
      }

      if (!in_array($candidateUrl, $candidates, true)) {
        $candidates[] = $candidateUrl;
      }
      if (!isset($scanned[$candidateUrl])) {
        $queue[] = $candidateUrl;
      }
    }
  }

  return $candidates;
}

function appleCaldavProbeCollectionWritable(string $calendarUrl, string $username, string $password): array {
  $calendarUrl = rtrim(trim($calendarUrl), '/') . '/';
  if ($calendarUrl === '' || !preg_match('#^https://#i', $calendarUrl)) {
    return ['ok' => false, 'detail' => 'invalid-url'];
  }

  $propfindBody = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<d:propfind xmlns:d="DAV:">'
    . '<d:prop><d:resourcetype /></d:prop>'
    . '</d:propfind>';

  $probeUid = 'trainerapp-probe-' . bin2hex(random_bytes(8)) . '@reservio.online';
  $probeHref = buildAppleCaldavRemoteHref($calendarUrl, $probeUid);
  $now = gmdate('Ymd\\THis\\Z');
  $later = gmdate('Ymd\\THis\\Z', time() + 300);
  $ics = "BEGIN:VCALENDAR\r\n"
    . "VERSION:2.0\r\n"
    . "PRODID:-//TrainerApp//Apple CalDAV Probe//CS\r\n"
    . "CALSCALE:GREGORIAN\r\n"
    . "BEGIN:VEVENT\r\n"
    . 'UID:' . appleCaldavEscapeText($probeUid) . "\r\n"
    . 'DTSTAMP:' . $now . "\r\n"
    . 'DTSTART:' . $now . "\r\n"
    . 'DTEND:' . $later . "\r\n"
    . "SUMMARY:TrainerApp Probe\r\n"
    . "DESCRIPTION:Temporary write test\r\n"
    . "END:VEVENT\r\n"
    . "END:VCALENDAR\r\n";

  try {
    $pf = appleCaldavHttpRequest('PROPFIND', $calendarUrl, $username, $password, $propfindBody, [
      'Depth: 0',
      'Content-Type: application/xml; charset=utf-8',
    ]);
    $pfStatus = (int)($pf['status'] ?? 0);
    if (!in_array($pfStatus, [200, 207], true)) {
      return ['ok' => false, 'detail' => 'pf:' . $pfStatus];
    }

    $put = appleCaldavHttpRequest('PUT', $probeHref, $username, $password, $ics, [
      'Content-Type: text/calendar; charset=utf-8',
    ]);
    $putStatus = (int)($put['status'] ?? 0);
    if (!in_array($putStatus, [200, 201, 204], true)) {
      $putBodySnippet = trim((string)($put['body'] ?? ''));
      if ($putBodySnippet !== '') {
        $putBodySnippet = preg_replace('/\s+/', ' ', $putBodySnippet);
        $putBodySnippet = mb_substr((string)$putBodySnippet, 0, 180, 'UTF-8');
      }
      return ['ok' => false, 'detail' => 'pf:' . $pfStatus . ',put:' . $putStatus . ($putBodySnippet !== '' ? ',body:' . $putBodySnippet : '')];
    }

    $del = appleCaldavHttpRequest('DELETE', $probeHref, $username, $password, null, []);
    $delStatus = (int)($del['status'] ?? 0);
    if (!in_array($delStatus, [200, 202, 204, 404], true)) {
      return ['ok' => false, 'detail' => 'pf:' . $pfStatus . ',put:' . $putStatus . ',del:' . $delStatus];
    }

    return ['ok' => true, 'detail' => 'pf:' . $pfStatus . ',put:' . $putStatus . ',del:' . $delStatus];
  } catch (Throwable $e) {
    return ['ok' => false, 'detail' => 'err:' . preg_replace('/\s+/', ' ', trim($e->getMessage()))];
  }
}

function appleCaldavAbsoluteHref(string $baseUrl, string $href): string {
  $href = trim($href);
  if ($href === '') {
    return rtrim($baseUrl, '/') . '/';
  }

  if (preg_match('#^https?://#i', $href)) {
    return $href;
  }

  return rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
}

function buildAppleCaldavEventIcs(int $coachId, array $event, string $uid): string {
  $participants = [];
  $primary = trim((string)($event['first_name'] ?? '') . ' ' . (string)($event['last_name'] ?? ''));
  $secondary = trim((string)($event['second_first_name'] ?? '') . ' ' . (string)($event['second_last_name'] ?? ''));
  if ($primary !== '') {
    $participants[] = $primary;
  }
  if ($secondary !== '') {
    $participants[] = $secondary;
  }

  $participantSummary = !empty($participants) ? implode(' + ', $participants) : 'Trenink';
  $location = trim((string)($event['location'] ?? ''));
  $summary = $participantSummary;
  if ($location !== '') {
    $summary .= ' | ' . $location;
  }

  // Pokud je vyplneny vlastni nazev, nechame ho v popisu kvuli detailu,
  // ale v SUMMARY priorizujeme sportovce + misto kvuli iOS mesicnimu prehledu.
  $customTitle = trim((string)($event['custom_title'] ?? ''));
  if ((string)($event['approval_status'] ?? 'approved') === 'pending') {
    $summary = 'Ceka na schvaleni - ' . $summary;
  }

  $descriptionParts = [
    'Zdroj: TrainerApp',
    'Lokalni ID udalosti: ' . (int)($event['id'] ?? 0),
  ];
  if ($customTitle !== '') {
    $descriptionParts[] = 'Nazev: ' . $customTitle;
  }
  if (!empty($participants)) {
    $descriptionParts[] = 'Ucastnici: ' . implode(', ', $participants);
  }
  if ($location !== '') {
    $descriptionParts[] = 'Misto: ' . $location;
  }

  $dtStart = gmdate('Ymd\\THis\\Z', strtotime((string)($event['starts_at'] ?? 'now')));
  $dtEnd = gmdate('Ymd\\THis\\Z', strtotime((string)($event['ends_at'] ?? 'now')));
  $dtStamp = gmdate('Ymd\\THis\\Z');
  $created = gmdate('Ymd\\THis\\Z', strtotime((string)($event['created_at'] ?? 'now')));
  $lastModified = gmdate('Ymd\\THis\\Z', strtotime((string)($event['updated_at'] ?? ($event['created_at'] ?? 'now'))));
  $sequence = max(0, strtotime((string)($event['updated_at'] ?? ($event['created_at'] ?? 'now'))));

  $lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//TrainerApp//Apple CalDAV//CS',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:' . appleCaldavEscapeText($uid),
    'DTSTAMP:' . $dtStamp,
    'CREATED:' . $created,
    'LAST-MODIFIED:' . $lastModified,
    'SEQUENCE:' . $sequence,
    'STATUS:' . (((string)($event['approval_status'] ?? 'approved') === 'pending') ? 'TENTATIVE' : 'CONFIRMED'),
    'DTSTART:' . $dtStart,
    'DTEND:' . $dtEnd,
    'SUMMARY:' . appleCaldavEscapeText($summary),
  ];

  if ($location !== '') {
    $lines[] = 'LOCATION:' . appleCaldavEscapeText($location);
  }
  if (!empty($descriptionParts)) {
    $lines[] = 'DESCRIPTION:' . appleCaldavEscapeText(implode("\n", $descriptionParts));
  }

  $lines[] = 'END:VEVENT';
  $lines[] = 'END:VCALENDAR';

  return implode("\r\n", $lines) . "\r\n";
}

function appleCaldavEscapeText(string $value): string {
  return str_replace(
    ["\\", ";", ",", "\r\n", "\r", "\n"],
    ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
    $value
  );
}

function appleCaldavHttpRequest(string $method, string $url, string $username, string $password, ?string $body, array $extraHeaders): array {
  if (!function_exists('curl_init')) {
    throw new RuntimeException('Na serveru neni dostupne CURL rozsireni.');
  }

  $method = strtoupper(trim($method));
  $ch = curl_init($url);

  $headers = array_merge([
    'User-Agent: TrainerApp-AppleCalDAV/1.0',
  ], $extraHeaders);

  $connectTimeout = max(2, (int)(defined('APPLE_CALDAV_CONNECT_TIMEOUT') ? APPLE_CALDAV_CONNECT_TIMEOUT : 5));
  $requestTimeout = max($connectTimeout, (int)(defined('APPLE_CALDAV_HTTP_TIMEOUT') ? APPLE_CALDAV_HTTP_TIMEOUT : 12));

  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
    CURLOPT_TIMEOUT => $requestTimeout,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $username . ':' . $password,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_HEADER => true,
  ]);

  if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }

  $raw = curl_exec($ch);
  $curlErr = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  if ($raw === false) {
    throw new RuntimeException('Apple CalDAV request selhal: ' . $curlErr);
  }

  $rawHeaders = substr((string)$raw, 0, $headerSize);
  $rawBody = substr((string)$raw, $headerSize);

  $parsedHeaders = [];
  foreach (preg_split('/\r\n|\n|\r/', (string)$rawHeaders) as $line) {
    if (strpos($line, ':') === false) {
      continue;
    }
    [$name, $value] = explode(':', $line, 2);
    $name = strtolower(trim($name));
    if ($name === '') {
      continue;
    }
    $parsedHeaders[$name] = trim($value);
  }

  return [
    'status' => $status,
    'headers' => $parsedHeaders,
    'body' => (string)$rawBody,
  ];
}

function markCoachAppleCaldavSyncSuccess(int $coachId): void {
  if ($coachId <= 0) {
    return;
  }

  $pdo = getDB();
  $stmt = $pdo->prepare(
    'UPDATE coaches
     SET apple_caldav_last_error = NULL,
         apple_caldav_last_success_at = NOW()
     WHERE id = ?'
  );
  $stmt->execute([$coachId]);
}

function markCoachAppleCaldavSyncError(int $coachId, string $errorMessage): void {
  if ($coachId <= 0) {
    return;
  }

  $pdo = getDB();
  $stmt = $pdo->prepare(
    'UPDATE coaches
     SET apple_caldav_last_error = ?
     WHERE id = ?'
  );
  $stmt->execute([mb_substr($errorMessage, 0, 2000, 'UTF-8'), $coachId]);
}

function attemptImmediateCoachAppleCaldavSync(int $coachId, ?int $eventId, string $syncAction = 'upsert'): bool {
  if ($coachId <= 0 || $eventId === null || $eventId <= 0) {
    return false;
  }

  $config = getCoachAppleCaldavConfig($coachId);
  if (!$config || empty($config['apple_caldav_sync_enabled'])) {
    return false;
  }

  $calendarUrl = normalizeAppleCaldavCalendarUrl((string)($config['apple_caldav_calendar_url'] ?? ''));
  $username = trim((string)($config['apple_caldav_username'] ?? ''));
  $password = trim((string)($config['apple_caldav_app_password'] ?? ''));
  if ($calendarUrl === '' || $username === '' || $password === '') {
    return false;
  }

  try {
    if ($syncAction === 'delete') {
      syncCoachEventDeleteToAppleCaldav($coachId, $eventId);
      if (appleCaldavSyncTablesAvailable()) {
        $pdo = getDB();
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM coach_apple_caldav_event_links WHERE coach_id = ? AND event_id = ?');
        $checkStmt->execute([$coachId, $eventId]);
        return ((int)$checkStmt->fetchColumn()) === 0;
      }
    } else {
      syncCoachEventUpsertToAppleCaldav($coachId, $eventId);
      return verifyCoachAppleCaldavRemoteEvent($coachId, $eventId, $username, $password);
    }
    return true;
  } catch (Throwable $e) {
    markCoachAppleCaldavSyncError($coachId, $e->getMessage());
    error_log('attemptImmediateCoachAppleCaldavSync error: ' . $e->getMessage());
    return false;
  }
}

function attemptImmediateAthleteAppleCaldavSync(int $athleteId, ?int $eventId, string $syncAction = 'upsert'): bool {
  if ($athleteId <= 0 || $eventId === null || $eventId <= 0) {
    return false;
  }

  $config = getAthleteAppleCaldavConfig($athleteId);
  if (!$config || empty($config['apple_caldav_sync_enabled'])) {
    return false;
  }

  $calendarUrl = normalizeAppleCaldavCalendarUrl((string)($config['apple_caldav_calendar_url'] ?? ''));
  $username = trim((string)($config['apple_caldav_username'] ?? ''));
  $password = trim((string)($config['apple_caldav_app_password'] ?? ''));
  if ($calendarUrl === '' || $username === '' || $password === '') {
    return false;
  }

  try {
    if ($syncAction === 'delete') {
      syncAthleteEventDeleteToAppleCaldav($athleteId, $eventId);
      if (athleteAppleCaldavEventLinksTableAvailable()) {
        $pdo = getDB();
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM athlete_apple_caldav_event_links WHERE athlete_id = ? AND event_id = ?');
        $checkStmt->execute([$athleteId, $eventId]);
        return ((int)$checkStmt->fetchColumn()) === 0;
      }
    } else {
      syncAthleteEventUpsertToAppleCaldav($athleteId, $eventId);
      return verifyAthleteAppleCaldavRemoteEvent($athleteId, $eventId, $username, $password);
    }
    return true;
  } catch (Throwable $e) {
    markAthleteAppleCaldavSyncError($athleteId, $e->getMessage());
    error_log('attemptImmediateAthleteAppleCaldavSync error: ' . $e->getMessage());
    return false;
  }
}

function verifyCoachAppleCaldavRemoteEvent(int $coachId, int $eventId, string $username, string $password): bool {
  if ($coachId <= 0 || $eventId <= 0 || !appleCaldavSyncTablesAvailable()) {
    return false;
  }

  $pdo = getDB();
  $hrefStmt = $pdo->prepare(
    'SELECT remote_href
     FROM coach_apple_caldav_event_links
     WHERE coach_id = ? AND event_id = ?
     LIMIT 1'
  );
  $hrefStmt->execute([$coachId, $eventId]);
  $remoteHref = trim((string)($hrefStmt->fetchColumn() ?: ''));
  if ($remoteHref === '') {
    return false;
  }

  $response = appleCaldavHttpRequest('GET', $remoteHref, $username, $password, null, []);
  $status = (int)($response['status'] ?? 0);
  $body = (string)($response['body'] ?? '');

  return $status === 200 && $body !== '' && stripos($body, 'BEGIN:VEVENT') !== false;
}

function verifyAthleteAppleCaldavRemoteEvent(int $athleteId, int $eventId, string $username, string $password): bool {
  if ($athleteId <= 0 || $eventId <= 0 || !athleteAppleCaldavEventLinksTableAvailable()) {
    return false;
  }

  $pdo = getDB();
  $hrefStmt = $pdo->prepare(
    'SELECT remote_href
     FROM athlete_apple_caldav_event_links
     WHERE athlete_id = ? AND event_id = ?
     LIMIT 1'
  );
  $hrefStmt->execute([$athleteId, $eventId]);
  $remoteHref = trim((string)($hrefStmt->fetchColumn() ?: ''));
  if ($remoteHref === '') {
    return false;
  }

  $response = appleCaldavHttpRequest('GET', $remoteHref, $username, $password, null, []);
  $status = (int)($response['status'] ?? 0);
  $body = (string)($response['body'] ?? '');

  return $status === 200 && $body !== '' && stripos($body, 'BEGIN:VEVENT') !== false;
}

function appleCaldavSyncTablesAvailable(): bool {
  static $available = null;
  if ($available !== null) {
    return $available;
  }

  try {
    $pdo = getDB();
    $jobs = $pdo->query("SHOW TABLES LIKE 'coach_apple_caldav_sync_jobs'");
    $links = $pdo->query("SHOW TABLES LIKE 'coach_apple_caldav_event_links'");
    $available = ($jobs !== false && (bool)$jobs->fetchColumn())
      && ($links !== false && (bool)$links->fetchColumn());
  } catch (Throwable $e) {
    $available = false;
  }

  return $available;
}

function enqueueAthleteAppleCaldavSync(int $athleteId, ?int $eventId, string $syncAction = 'upsert'): void {
  if (!in_array($syncAction, ['upsert', 'delete'], true)) {
    $syncAction = 'upsert';
  }

  if ($athleteId <= 0) {
    return;
  }

  if (!athleteAppleCaldavSyncTablesAvailable()) {
    // Fallback pro instalace bez athlete queue tabulek: proved sync hned.
    try {
      if ($syncAction === 'delete') {
        syncAthleteEventDeleteToAppleCaldav($athleteId, $eventId);
      } else {
        syncAthleteEventUpsertToAppleCaldav($athleteId, $eventId);
      }
    } catch (Throwable $e) {
      markAthleteAppleCaldavSyncError($athleteId, $e->getMessage());
      error_log('enqueueAthleteAppleCaldavSync fallback error: ' . $e->getMessage());
    }
    return;
  }

  try {
    $pdo = getDB();
    $cleanup = $pdo->prepare(
      'DELETE FROM athlete_apple_caldav_sync_jobs
       WHERE athlete_id = ?
         AND ((event_id = ?) OR (event_id IS NULL AND ? IS NULL))
         AND sync_action = ?
         AND status IN ("pending", "failed", "done")'
    );
    $cleanup->execute([$athleteId, $eventId, $eventId, $syncAction]);

    $ins = $pdo->prepare(
      'INSERT INTO athlete_apple_caldav_sync_jobs (athlete_id, event_id, sync_action, status, attempt_count, next_attempt_at)
       VALUES (?, ?, ?, "pending", 0, NOW())'
    );
    $ins->execute([$athleteId, $eventId, $syncAction]);
  } catch (Throwable $e) {
    error_log('enqueueAthleteAppleCaldavSync error: ' . $e->getMessage());
  }
}

function processAthleteAppleCaldavSyncQueue(int $limit = 8): array {
  $results = [];
  if (!athleteAppleCaldavSyncTablesAvailable()) {
    return $results;
  }

  $pdo = getDB();
  $limit = max(1, min(50, $limit));

  $resetStale = $pdo->prepare(
    'UPDATE athlete_apple_caldav_sync_jobs
     SET status = "failed",
         last_error = COALESCE(last_error, "Apple CalDAV job byl resetovan po prerusenem nebo timeout requestu."),
         next_attempt_at = NOW(),
         updated_at = NOW()
     WHERE status = "processing"
       AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
  );
  $resetStale->execute();

  $jobStmt = $pdo->prepare(
    'SELECT id, athlete_id, event_id, sync_action, attempt_count
     FROM athlete_apple_caldav_sync_jobs
     WHERE status IN ("pending", "failed")
       AND next_attempt_at <= NOW()
     ORDER BY id DESC
     LIMIT ' . $limit
  );
  $jobStmt->execute();
  $jobs = $jobStmt->fetchAll();

  foreach ($jobs as $job) {
    $jobId = (int)($job['id'] ?? 0);
    $athleteId = (int)($job['athlete_id'] ?? 0);
    $eventId = isset($job['event_id']) ? (int)$job['event_id'] : null;
    $syncAction = (string)($job['sync_action'] ?? 'upsert');
    $attemptCount = (int)($job['attempt_count'] ?? 0);

    if ($jobId <= 0 || $athleteId <= 0) {
      continue;
    }

    $markProcessing = $pdo->prepare(
      'UPDATE athlete_apple_caldav_sync_jobs
       SET status = "processing", updated_at = NOW(), attempt_count = attempt_count + 1
       WHERE id = ?'
    );
    $markProcessing->execute([$jobId]);

    try {
      if ($syncAction === 'delete') {
        syncAthleteEventDeleteToAppleCaldav($athleteId, $eventId);
      } else {
        syncAthleteEventUpsertToAppleCaldav($athleteId, $eventId);
      }

      $markDone = $pdo->prepare(
        'UPDATE athlete_apple_caldav_sync_jobs
         SET status = "done", last_error = NULL, processed_at = NOW(), updated_at = NOW()
         WHERE id = ?'
      );
      $markDone->execute([$jobId]);
      $results[] = ['job_id' => $jobId, 'status' => 'done'];
    } catch (Throwable $e) {
      markAthleteAppleCaldavSyncError($athleteId, $e->getMessage());
      $delayMinutes = min(360, max(2, (int)pow(2, max(0, $attemptCount))));
      $markFailed = $pdo->prepare(
        'UPDATE athlete_apple_caldav_sync_jobs
         SET status = "failed",
             last_error = ?,
             next_attempt_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
             updated_at = NOW()
         WHERE id = ?'
      );
      $markFailed->execute([mb_substr($e->getMessage(), 0, 2000, 'UTF-8'), $delayMinutes, $jobId]);
      $results[] = ['job_id' => $jobId, 'status' => 'failed', 'error' => $e->getMessage()];
    }
  }

  return $results;
}

function syncAthleteEventUpsertToAppleCaldav(int $athleteId, ?int $eventId): void {
  if ($athleteId <= 0 || $eventId === null || $eventId <= 0) {
    return;
  }

  $pdo = getDB();
  $eventStmt = $pdo->prepare(
    'SELECT e.id,
            e.coach_id,
            e.athlete_id,
            e.second_athlete_id,
            e.approval_status,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            e.updated_at,
            e.created_at,
            c.name AS coach_name,
            a.first_name,
            a.last_name,
            a2.first_name AS second_first_name,
            a2.last_name AS second_last_name
     FROM coach_calendar_events e
     JOIN coaches c ON c.id = e.coach_id
     LEFT JOIN athletes a ON a.id = e.athlete_id
     LEFT JOIN athletes a2 ON a2.id = e.second_athlete_id
     WHERE e.id = ?
       AND (e.athlete_id = ? OR e.second_athlete_id = ?)
     LIMIT 1'
  );
  $eventStmt->execute([$eventId, $athleteId, $athleteId]);
  $event = $eventStmt->fetch();

  if (!$event) {
    syncAthleteEventDeleteToAppleCaldav($athleteId, $eventId);
    return;
  }

  $athlete = getAthleteAppleCaldavConfig($athleteId);
  if (!$athlete || empty($athlete['apple_caldav_sync_enabled'])) {
    return;
  }

  $calendarUrl = normalizeAppleCaldavCalendarUrl((string)($athlete['apple_caldav_calendar_url'] ?? ''));
  if ($calendarUrl === '') {
    throw new RuntimeException('Athlete Apple CalDAV: chybi URL kalendare.');
  }

  $username = trim((string)($athlete['apple_caldav_username'] ?? ''));
  $password = trim((string)($athlete['apple_caldav_app_password'] ?? ''));
  if ($username === '' || $password === '') {
    throw new RuntimeException('Athlete Apple CalDAV: chybi prihlasovaci udaje.');
  }

  $uid = 'trainerapp-athlete-' . $athleteId . '-event-' . (int)($event['id'] ?? 0) . '@reservio.online';
  $remoteHref = buildAppleCaldavRemoteHref($calendarUrl, $uid);
  $icsPayload = buildAthleteAppleCaldavEventIcs($athleteId, $event, $uid);

  $response = appleCaldavHttpRequest('PUT', $remoteHref, $username, $password, $icsPayload, [
    'Content-Type: text/calendar; charset=utf-8',
  ]);
  $status = (int)($response['status'] ?? 0);
  if (!in_array($status, [200, 201, 204], true)) {
    throw new RuntimeException('Athlete Apple CalDAV upsert selhal: HTTP ' . $status . ' | ' . (string)($response['body'] ?? ''));
  }

  if (athleteAppleCaldavEventLinksTableAvailable()) {
    $etag = trim((string)($response['headers']['etag'] ?? ''));
    $upsertLink = $pdo->prepare(
      'INSERT INTO athlete_apple_caldav_event_links (athlete_id, event_id, remote_href, remote_etag, last_synced_at, last_error)
       VALUES (?, ?, ?, ?, NOW(), NULL)
       ON DUPLICATE KEY UPDATE remote_href = VALUES(remote_href), remote_etag = VALUES(remote_etag), last_synced_at = NOW(), last_error = NULL'
    );
    $upsertLink->execute([$athleteId, $eventId, $remoteHref, $etag !== '' ? $etag : null]);
  }

  markAthleteAppleCaldavSyncSuccess($athleteId);
}

function syncAthleteEventDeleteToAppleCaldav(int $athleteId, ?int $eventId): void {
  if ($athleteId <= 0 || $eventId === null || $eventId <= 0) {
    return;
  }

  $pdo = getDB();
  $hasLinksTable = athleteAppleCaldavEventLinksTableAvailable();
  $remoteHref = '';
  if ($hasLinksTable) {
    $linkStmt = $pdo->prepare(
      'SELECT remote_href
       FROM athlete_apple_caldav_event_links
       WHERE athlete_id = ? AND event_id = ?
       LIMIT 1'
    );
    $linkStmt->execute([$athleteId, $eventId]);
    $remoteHref = trim((string)($linkStmt->fetchColumn() ?: ''));
  }

  $athlete = getAthleteAppleCaldavConfig($athleteId);
  if (!$athlete || empty($athlete['apple_caldav_sync_enabled'])) {
    if ($hasLinksTable) {
      $deleteLocal = $pdo->prepare('DELETE FROM athlete_apple_caldav_event_links WHERE athlete_id = ? AND event_id = ?');
      $deleteLocal->execute([$athleteId, $eventId]);
    }
    return;
  }

  $calendarUrl = normalizeAppleCaldavCalendarUrl((string)($athlete['apple_caldav_calendar_url'] ?? ''));
  $candidateDeleteHrefs = [];
  if ($remoteHref !== '') {
    $candidateDeleteHrefs[] = $remoteHref;
  }
  if ($calendarUrl !== '') {
    $uid = 'trainerapp-athlete-' . $athleteId . '-event-' . $eventId . '@reservio.online';
    foreach ([
      buildAppleCaldavRemoteHref($calendarUrl, $uid),
      buildAppleCaldavLegacyRemoteHref($calendarUrl, $uid),
    ] as $href) {
      if (!in_array($href, $candidateDeleteHrefs, true)) {
        $candidateDeleteHrefs[] = $href;
      }
    }
  }
  if (empty($candidateDeleteHrefs)) {
    return;
  }

  $username = trim((string)($athlete['apple_caldav_username'] ?? ''));
  $password = trim((string)($athlete['apple_caldav_app_password'] ?? ''));
  if ($username === '' || $password === '') {
    throw new RuntimeException('Athlete Apple CalDAV delete: chybi prihlasovaci udaje.');
  }

  $lastDeleteError = null;
  foreach ($candidateDeleteHrefs as $candidateHref) {
    $response = appleCaldavHttpRequest('DELETE', $candidateHref, $username, $password, null, []);
    $status = (int)($response['status'] ?? 0);
    if (in_array($status, [200, 202, 204, 404], true)) {
      $lastDeleteError = null;
      break;
    }
    $lastDeleteError = 'Athlete Apple CalDAV delete selhal: HTTP ' . $status . ' | ' . (string)($response['body'] ?? '');
  }
  if ($lastDeleteError !== null) {
    throw new RuntimeException($lastDeleteError);
  }

  if ($hasLinksTable) {
    $deleteLink = $pdo->prepare('DELETE FROM athlete_apple_caldav_event_links WHERE athlete_id = ? AND event_id = ?');
    $deleteLink->execute([$athleteId, $eventId]);
  }

  markAthleteAppleCaldavSyncSuccess($athleteId);
}

function purgeAthleteAppleCaldavRemoteEvents(int $athleteId, string $username, string $password): array {
  if ($athleteId <= 0 || !athleteAppleCaldavEventLinksTableAvailable()) {
    return ['deleted' => 0, 'failed' => 0];
  }

  $username = trim($username);
  $password = trim($password);
  if ($username === '' || $password === '') {
    throw new RuntimeException('Athlete Apple CalDAV cleanup: chybi prihlasovaci udaje.');
  }

  $pdo = getDB();
  $stmt = $pdo->prepare(
    'SELECT event_id, remote_href
     FROM athlete_apple_caldav_event_links
     WHERE athlete_id = ?'
  );
  $stmt->execute([$athleteId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $deleted = 0;
  $failed = 0;
  foreach ($rows as $row) {
    $eventId = (int)($row['event_id'] ?? 0);
    $remoteHref = trim((string)($row['remote_href'] ?? ''));
    if ($remoteHref === '') {
      continue;
    }

    try {
      $response = appleCaldavHttpRequest('DELETE', $remoteHref, $username, $password, null, []);
      $status = (int)($response['status'] ?? 0);
      if (in_array($status, [200, 202, 204, 404], true)) {
        $deleted++;
        $deleteStmt = $pdo->prepare('DELETE FROM athlete_apple_caldav_event_links WHERE athlete_id = ? AND event_id = ?');
        $deleteStmt->execute([$athleteId, $eventId]);
        continue;
      }
      $failed++;
    } catch (Throwable $e) {
      $failed++;
    }
  }

  return ['deleted' => $deleted, 'failed' => $failed];
}

function cleanupAthleteAppleCaldavOrphanedRemoteEvents(int $athleteId, string $username, string $password): array {
  if ($athleteId <= 0 || !athleteAppleCaldavEventLinksTableAvailable()) {
    return ['deleted' => 0, 'failed' => 0, 'matched' => 0];
  }

  $username = trim($username);
  $password = trim($password);
  if ($username === '' || $password === '') {
    throw new RuntimeException('Athlete Apple CalDAV cleanup: chybi prihlasovaci udaje.');
  }

  $pdo = getDB();
  $stmt = $pdo->prepare(
    'SELECT l.event_id, l.remote_href
     FROM athlete_apple_caldav_event_links l
     LEFT JOIN coach_calendar_events e ON e.id = l.event_id AND (e.athlete_id = ? OR e.second_athlete_id = ?)
     WHERE l.athlete_id = ?
       AND e.id IS NULL'
  );
  $stmt->execute([$athleteId, $athleteId, $athleteId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $deleted = 0;
  $failed = 0;
  foreach ($rows as $row) {
    $eventId = (int)($row['event_id'] ?? 0);
    $remoteHref = trim((string)($row['remote_href'] ?? ''));
    if ($eventId <= 0 || $remoteHref === '') {
      continue;
    }

    try {
      $response = appleCaldavHttpRequest('DELETE', $remoteHref, $username, $password, null, []);
      $status = (int)($response['status'] ?? 0);
      if (in_array($status, [200, 202, 204, 404], true)) {
        $deleteStmt = $pdo->prepare('DELETE FROM athlete_apple_caldav_event_links WHERE athlete_id = ? AND event_id = ?');
        $deleteStmt->execute([$athleteId, $eventId]);
        $deleted++;
      } else {
        $failed++;
      }
    } catch (Throwable $e) {
      $failed++;
    }
  }

  return ['deleted' => $deleted, 'failed' => $failed, 'matched' => count($rows)];
}

function purgeCoachAppleCaldavRemoteEvents(int $coachId, string $username, string $password): array {
  if ($coachId <= 0 || !appleCaldavSyncTablesAvailable()) {
    return ['deleted' => 0, 'failed' => 0];
  }

  $username = trim($username);
  $password = trim($password);
  if ($username === '' || $password === '') {
    throw new RuntimeException('Apple CalDAV cleanup: chybi prihlasovaci udaje.');
  }

  $pdo = getDB();
  $stmt = $pdo->prepare(
    'SELECT event_id, remote_href
     FROM coach_apple_caldav_event_links
     WHERE coach_id = ?'
  );
  $stmt->execute([$coachId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $deleted = 0;
  $failed = 0;
  foreach ($rows as $row) {
    $eventId = (int)($row['event_id'] ?? 0);
    $remoteHref = trim((string)($row['remote_href'] ?? ''));
    if ($remoteHref === '') {
      continue;
    }

    try {
      $response = appleCaldavHttpRequest('DELETE', $remoteHref, $username, $password, null, []);
      $status = (int)($response['status'] ?? 0);
      if (in_array($status, [200, 202, 204, 404], true)) {
        $deleted++;
        $deleteStmt = $pdo->prepare('DELETE FROM coach_apple_caldav_event_links WHERE coach_id = ? AND event_id = ?');
        $deleteStmt->execute([$coachId, $eventId]);
        continue;
      }
      $failed++;
    } catch (Throwable $e) {
      $failed++;
    }
  }

  return ['deleted' => $deleted, 'failed' => $failed];
}

function cleanupCoachAppleCaldavOrphanedRemoteEvents(int $coachId, string $username, string $password): array {
  if ($coachId <= 0 || !appleCaldavSyncTablesAvailable()) {
    return ['deleted' => 0, 'failed' => 0, 'matched' => 0];
  }

  $username = trim($username);
  $password = trim($password);
  if ($username === '' || $password === '') {
    throw new RuntimeException('Apple CalDAV cleanup: chybi prihlasovaci udaje.');
  }

  $pdo = getDB();
  $stmt = $pdo->prepare(
    'SELECT l.event_id, l.remote_href
     FROM coach_apple_caldav_event_links l
     LEFT JOIN coach_calendar_events e ON e.id = l.event_id AND e.coach_id = ?
     WHERE l.coach_id = ?
       AND e.id IS NULL'
  );
  $stmt->execute([$coachId, $coachId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $deleted = 0;
  $failed = 0;
  foreach ($rows as $row) {
    $eventId = (int)($row['event_id'] ?? 0);
    $remoteHref = trim((string)($row['remote_href'] ?? ''));
    if ($eventId <= 0 || $remoteHref === '') {
      continue;
    }

    try {
      $response = appleCaldavHttpRequest('DELETE', $remoteHref, $username, $password, null, []);
      $status = (int)($response['status'] ?? 0);
      if (in_array($status, [200, 202, 204, 404], true)) {
        $deleteStmt = $pdo->prepare('DELETE FROM coach_apple_caldav_event_links WHERE coach_id = ? AND event_id = ?');
        $deleteStmt->execute([$coachId, $eventId]);
        $deleted++;
      } else {
        $failed++;
      }
    } catch (Throwable $e) {
      $failed++;
    }
  }

  return ['deleted' => $deleted, 'failed' => $failed, 'matched' => count($rows)];
}

function getAthleteAppleCaldavConfig(int $athleteId): ?array {
  $pdo = getDB();
  $stmt = $pdo->prepare(
    'SELECT id,
            apple_caldav_sync_enabled,
            apple_caldav_calendar_url,
            apple_caldav_username,
            apple_caldav_app_password
     FROM athletes
     WHERE id = ?
     LIMIT 1'
  );
  $stmt->execute([$athleteId]);
  $row = $stmt->fetch();

  return $row ?: null;
}

function buildAthleteAppleCaldavEventIcs(int $athleteId, array $event, string $uid): string {
  $participants = [];
  $primary = trim((string)($event['first_name'] ?? '') . ' ' . (string)($event['last_name'] ?? ''));
  $secondary = trim((string)($event['second_first_name'] ?? '') . ' ' . (string)($event['second_last_name'] ?? ''));
  if ($primary !== '') {
    $participants[] = $primary;
  }
  if ($secondary !== '') {
    $participants[] = $secondary;
  }

  $summary = trim((string)($event['custom_title'] ?? ''));
  if ($summary === '') {
    $summary = 'Trenink';
  }
  $location = trim((string)($event['location'] ?? ''));
  if ($location !== '') {
    $summary .= ' | ' . $location;
  }
  if ((string)($event['approval_status'] ?? 'approved') === 'pending') {
    $summary = 'Ceka na schvaleni - ' . $summary;
  }

  $descriptionParts = [
    'Zdroj: TrainerApp',
    'Lokalni ID udalosti: ' . (int)($event['id'] ?? 0),
  ];
  $coachName = trim((string)($event['coach_name'] ?? ''));
  if ($coachName !== '') {
    $descriptionParts[] = 'Trener: ' . $coachName;
  }
  if (!empty($participants)) {
    $descriptionParts[] = 'Ucastnici: ' . implode(', ', $participants);
  }
  if ($location !== '') {
    $descriptionParts[] = 'Misto: ' . $location;
  }

  $dtStart = gmdate('Ymd\\THis\\Z', strtotime((string)($event['starts_at'] ?? 'now')));
  $dtEnd = gmdate('Ymd\\THis\\Z', strtotime((string)($event['ends_at'] ?? 'now')));
  $dtStamp = gmdate('Ymd\\THis\\Z');
  $created = gmdate('Ymd\\THis\\Z', strtotime((string)($event['created_at'] ?? 'now')));
  $lastModified = gmdate('Ymd\\THis\\Z', strtotime((string)($event['updated_at'] ?? ($event['created_at'] ?? 'now'))));
  $sequence = max(0, strtotime((string)($event['updated_at'] ?? ($event['created_at'] ?? 'now'))));

  $lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//TrainerApp//Athlete Apple CalDAV//CS',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:' . appleCaldavEscapeText($uid),
    'DTSTAMP:' . $dtStamp,
    'CREATED:' . $created,
    'LAST-MODIFIED:' . $lastModified,
    'SEQUENCE:' . $sequence,
    'STATUS:' . (((string)($event['approval_status'] ?? 'approved') === 'pending') ? 'TENTATIVE' : 'CONFIRMED'),
    'DTSTART:' . $dtStart,
    'DTEND:' . $dtEnd,
    'SUMMARY:' . appleCaldavEscapeText($summary),
  ];

  if ($location !== '') {
    $lines[] = 'LOCATION:' . appleCaldavEscapeText($location);
  }
  if (!empty($descriptionParts)) {
    $lines[] = 'DESCRIPTION:' . appleCaldavEscapeText(implode("\n", $descriptionParts));
  }

  $lines[] = 'END:VEVENT';
  $lines[] = 'END:VCALENDAR';

  return implode("\r\n", $lines) . "\r\n";
}

function markAthleteAppleCaldavSyncSuccess(int $athleteId): void {
  if ($athleteId <= 0) {
    return;
  }

  $pdo = getDB();
  $stmt = $pdo->prepare(
    'UPDATE athletes
     SET apple_caldav_last_error = NULL,
         apple_caldav_last_success_at = NOW()
     WHERE id = ?'
  );
  $stmt->execute([$athleteId]);
}

function markAthleteAppleCaldavSyncError(int $athleteId, string $errorMessage): void {
  if ($athleteId <= 0) {
    return;
  }

  $pdo = getDB();
  $stmt = $pdo->prepare(
    'UPDATE athletes
     SET apple_caldav_last_error = ?
     WHERE id = ?'
  );
  $stmt->execute([mb_substr($errorMessage, 0, 2000, 'UTF-8'), $athleteId]);
}

function athleteAppleCaldavSyncTablesAvailable(): bool {
  static $available = null;
  if ($available !== null) {
    return $available;
  }

  try {
    $pdo = getDB();
    $jobs = $pdo->query("SHOW TABLES LIKE 'athlete_apple_caldav_sync_jobs'");
    $links = $pdo->query("SHOW TABLES LIKE 'athlete_apple_caldav_event_links'");
    $available = ($jobs !== false && (bool)$jobs->fetchColumn())
      && ($links !== false && (bool)$links->fetchColumn());
  } catch (Throwable $e) {
    $available = false;
  }

  return $available;
}

function athleteAppleCaldavEventLinksTableAvailable(): bool {
  static $available = null;
  if ($available !== null) {
    return $available;
  }

  try {
    $pdo = getDB();
    $links = $pdo->query("SHOW TABLES LIKE 'athlete_apple_caldav_event_links'");
    $available = ($links !== false && (bool)$links->fetchColumn());
  } catch (Throwable $e) {
    $available = false;
  }

  return $available;
}
