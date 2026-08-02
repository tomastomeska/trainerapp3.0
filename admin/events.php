<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

if (!function_exists('adminSlugifyEventValue')) {
    function adminSlugifyEventValue(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($trans !== false) {
            $value = $trans;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return substr($value, 0, 120);
    }
}

if (!function_exists('adminNormalizeEventIcon')) {
    function adminNormalizeEventIcon(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return 'fa-bolt';
        }

        if (!preg_match('/^fa-[a-z0-9-]+$/', $value)) {
            return 'fa-bolt';
        }

        return $value;
    }
}

if (!function_exists('adminEventTileImageUpload')) {
    function adminEventTileImageUpload(): ?string {
        if (empty($_FILES['tile_image']) || (int)($_FILES['tile_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return saveUploadedPhoto('tile_image', 'events');
    }
}

if (!function_exists('adminSpecialEventsHasTileImageColumn')) {
    function adminSpecialEventsHasTileImageColumn(PDO $pdo): bool {
        static $hasTileImage = null;

        if ($hasTileImage !== null) {
            return $hasTileImage;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM special_events LIKE 'tile_image'");
            $hasTileImage = ($stmt !== false && $stmt->fetch()) ? true : false;

            if (!$hasTileImage) {
                try {
                    $pdo->exec('ALTER TABLE special_events ADD COLUMN tile_image VARCHAR(255) NULL AFTER badge_label');
                    $hasTileImage = true;
                } catch (Throwable $e) {
                    $hasTileImage = false;
                }
            }
        } catch (Throwable $e) {
            $hasTileImage = false;
        }

        return $hasTileImage;
    }
}

if (!function_exists('adminSpecialEventsHasUpcomingItemsTable')) {
    function adminSpecialEventsHasUpcomingItemsTable(PDO $pdo): bool {
        if (function_exists('specialEventHasUpcomingItemsTable')) {
            return specialEventHasUpcomingItemsTable($pdo);
        }

        static $hasUpcomingItemsTable = null;

        if ($hasUpcomingItemsTable !== null) {
            return $hasUpcomingItemsTable;
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'special_event_upcoming_items'");
            $hasUpcomingItemsTable = ($stmt !== false && $stmt->fetch()) ? true : false;
        } catch (Throwable $e) {
            $hasUpcomingItemsTable = false;
        }

        return $hasUpcomingItemsTable;
    }
}

if (!function_exists('adminSpecialEventsHasUpcomingTabColumn')) {
    function adminSpecialEventsHasUpcomingTabColumn(PDO $pdo): bool {
        static $hasUpcomingTabColumn = null;

        if ($hasUpcomingTabColumn !== null) {
            return $hasUpcomingTabColumn;
        }

        if (!adminSpecialEventsHasUpcomingItemsTable($pdo)) {
            $hasUpcomingTabColumn = false;
            return $hasUpcomingTabColumn;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM special_event_upcoming_items LIKE 'tab_id'");
            $hasUpcomingTabColumn = ($stmt !== false && $stmt->fetch()) ? true : false;

            if (!$hasUpcomingTabColumn) {
                try {
                    $pdo->exec('ALTER TABLE special_event_upcoming_items ADD COLUMN tab_id INT NULL AFTER event_id');
                    $pdo->exec('ALTER TABLE special_event_upcoming_items ADD INDEX idx_special_event_upcoming_tab_date (tab_id, event_date)');
                    $hasUpcomingTabColumn = true;
                } catch (Throwable $e) {
                    $hasUpcomingTabColumn = false;
                }
            }
        } catch (Throwable $e) {
            $hasUpcomingTabColumn = false;
        }

        return $hasUpcomingTabColumn;
    }
}

if (!function_exists('adminNormalizeEventTargetUrl')) {
    function adminNormalizeEventTargetUrl(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('#^(https?://|/)#i', $value)) {
            return $value;
        }

        return '';
    }
}

if (!function_exists('adminNormalizeEventDate')) {
    function adminNormalizeEventDate(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date) {
            return '';
        }

        $errors = DateTimeImmutable::getLastErrors();
        if ($errors && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return '';
        }

        return $date->format('Y-m-d');
    }
}

if (!function_exists('adminDetectCsvDelimiter')) {
    function adminDetectCsvDelimiter(string $line): string {
        $commaCount = substr_count($line, ',');
        $semicolonCount = substr_count($line, ';');
        $tabCount = substr_count($line, "\t");

        if ($tabCount > $commaCount && $tabCount > $semicolonCount) {
            return "\t";
        }
        if ($semicolonCount > $commaCount) {
            return ';';
        }

        return ',';
    }
}

if (!function_exists('adminNormalizeCsvHeader')) {
    function adminNormalizeCsvHeader(string $header): string {
        $header = trim($header);
        if ($header === '') {
            return '';
        }

        $header = strtolower($header);
        $header = str_replace([' ', '-', '.'], '_', $header);
        return preg_replace('/[^a-z0-9_]/', '', $header) ?? '';
    }
}

if (!function_exists('adminCsvFlagToBool')) {
    function adminCsvFlagToBool(string $value, bool $default = true): bool {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $default;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'ano', 'on', 'active', 'aktivni'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'ne', 'off', 'inactive', 'neaktivni'], true)) {
            return false;
        }

        return $default;
    }
}

$specialEventsHasTileImage = adminSpecialEventsHasTileImageColumn($pdo);
$specialEventsHasUpcomingTabColumn = adminSpecialEventsHasUpcomingTabColumn($pdo);

$defaultCoachIntroText = 'Vyber event a otevri jeho zalozky. Obsah eventu se nyni spravuje centralne v administraci.';
$defaultAthleteIntroText = 'Vyber event a otevri jeho zalozky. Obsah eventu se nyni spravuje centralne v administraci.';
$defaultFormsEmail = getAdminNotificationEmail();

$getAction = trim((string)($_GET['action'] ?? ''));
if ($getAction === 'export_events_csv') {
    $exportSql = $specialEventsHasTileImage
        ? "SELECT slug, name, icon_class, description, badge_label, tile_image, audience, sort_order, is_active
           FROM special_events
           ORDER BY sort_order ASC, name ASC"
        : "SELECT slug, name, icon_class, description, badge_label, NULL AS tile_image, audience, sort_order, is_active
           FROM special_events
           ORDER BY sort_order ASC, name ASC";

    $rows = $pdo->query($exportSql)->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="events_export_' . date('Y-m-d_H-i') . '.csv"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['slug', 'name', 'icon_class', 'description', 'badge_label', 'tile_image', 'audience', 'sort_order', 'is_active'], ';');

    foreach ($rows as $row) {
        fputcsv($out, [
            (string)($row['slug'] ?? ''),
            (string)($row['name'] ?? ''),
            (string)($row['icon_class'] ?? ''),
            (string)($row['description'] ?? ''),
            (string)($row['badge_label'] ?? ''),
            (string)($row['tile_image'] ?? ''),
            (string)($row['audience'] ?? ''),
            (int)($row['sort_order'] ?? 100),
            (int)($row['is_active'] ?? 0),
        ], ';');
    }

    fclose($out);
    exit;
}

if ($getAction === 'export_upcoming_items_csv') {
    $eventIdFilter = (int)($_GET['event_id'] ?? 0);

    if (!adminSpecialEventsHasUpcomingItemsTable($pdo)) {
        flash('danger', 'Nadcházející události zatím nejsou připravené v databázi. Spusťte migraci special events.');
        redirect(BASE_URL . '/admin/events.php' . ($eventIdFilter > 0 ? ('?event_id=' . $eventIdFilter) : ''));
    }

    $params = [];
    $whereSql = '';
    if ($eventIdFilter > 0) {
        $whereSql = ' WHERE ui.event_id = ?';
        $params[] = $eventIdFilter;
    }

    $upcomingSql = $specialEventsHasUpcomingTabColumn
        ? "SELECT se.slug AS event_slug, COALESCE(st.tab_key, '') AS tab_key, ui.title, ui.event_date, ui.target_url, ui.sort_order, ui.is_active
           FROM special_event_upcoming_items ui
           INNER JOIN special_events se ON se.id = ui.event_id
           LEFT JOIN special_event_tabs st ON st.id = ui.tab_id
           $whereSql
           ORDER BY se.slug ASC, ui.event_date ASC, ui.id ASC"
        : "SELECT se.slug AS event_slug, '' AS tab_key, ui.title, ui.event_date, ui.target_url, ui.sort_order, ui.is_active
           FROM special_event_upcoming_items ui
           INNER JOIN special_events se ON se.id = ui.event_id
           $whereSql
           ORDER BY se.slug ASC, ui.event_date ASC, ui.id ASC";

    $stmt = $pdo->prepare($upcomingSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $suffix = ($eventIdFilter > 0) ? ('_event-' . $eventIdFilter) : '_all';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="upcoming_items_export' . $suffix . '_' . date('Y-m-d_H-i') . '.csv"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['event_slug', 'tab_key', 'title', 'event_date', 'target_url', 'sort_order', 'is_active'], ';');

    foreach ($rows as $row) {
        fputcsv($out, [
            (string)($row['event_slug'] ?? ''),
            (string)($row['tab_key'] ?? ''),
            (string)($row['title'] ?? ''),
            (string)($row['event_date'] ?? ''),
            (string)($row['target_url'] ?? ''),
            (int)($row['sort_order'] ?? 100),
            (int)($row['is_active'] ?? 0),
        ], ';');
    }

    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        die('Neplatny CSRF token.');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_events_intro') {
        $coachIntroText = trim((string)($_POST['coach_intro_text'] ?? ''));
        $athleteIntroText = trim((string)($_POST['athlete_intro_text'] ?? ''));
        $redirectEventId = (int)($_GET['event_id'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute(['events_intro_text_coach', $coachIntroText]);
        $stmt->execute(['events_intro_text_athlete', $athleteIntroText]);

        flash('success', 'Uvodni texty pro hlavni stranku Events byly ulozeny.');
        redirect(BASE_URL . '/admin/events.php' . ($redirectEventId > 0 ? ('?event_id=' . $redirectEventId) : ''));
    }

    if ($action === 'update_events_forms_email') {
        $formsEmail = trim((string)($_POST['events_forms_email_to'] ?? ''));
        $redirectEventId = (int)($_GET['event_id'] ?? 0);

        if ($formsEmail !== '' && filter_var($formsEmail, FILTER_VALIDATE_EMAIL) === false) {
            flash('danger', 'Cilovy e-mail pro formularove odesilani neni platny.');
            redirect(BASE_URL . '/admin/events.php' . ($redirectEventId > 0 ? ('?event_id=' . $redirectEventId) : ''));
        }

        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute(['events_forms_email_to', $formsEmail]);

        flash('success', 'E-mail pro formularove odesilani z Events byl ulozen.');
        redirect(BASE_URL . '/admin/events.php' . ($redirectEventId > 0 ? ('?event_id=' . $redirectEventId) : ''));
    }

    if ($action === 'import_events_csv') {
        if (empty($_FILES['events_csv']) || (int)($_FILES['events_csv']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('danger', 'CSV soubor nebyl nahran.');
            redirect(BASE_URL . '/admin/events.php');
        }

        $uploadError = (int)($_FILES['events_csv']['error'] ?? UPLOAD_ERR_OK);
        if ($uploadError !== UPLOAD_ERR_OK) {
            flash('danger', 'CSV soubor se nepodarilo nahrat.');
            redirect(BASE_URL . '/admin/events.php');
        }

        $tmpPath = (string)($_FILES['events_csv']['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            flash('danger', 'CSV soubor se nepodarilo nacist.');
            redirect(BASE_URL . '/admin/events.php');
        }

        $handle = @fopen($tmpPath, 'rb');
        if (!$handle) {
            flash('danger', 'CSV soubor se nepodarilo otevrit.');
            redirect(BASE_URL . '/admin/events.php');
        }

        $firstLine = (string)fgets($handle);
        rewind($handle);
        $delimiter = adminDetectCsvDelimiter($firstLine);

        $headersRaw = fgetcsv($handle, 0, $delimiter);
        if (!is_array($headersRaw) || empty($headersRaw)) {
            fclose($handle);
            flash('danger', 'CSV soubor nema hlavicku sloupcu.');
            redirect(BASE_URL . '/admin/events.php');
        }

        $headers = array_map(static fn($h) => adminNormalizeCsvHeader((string)$h), $headersRaw);
        $slugIndex = array_search('slug', $headers, true);
        $nameIndex = array_search('name', $headers, true);

        if ($slugIndex === false || $nameIndex === false) {
            fclose($handle);
            flash('danger', 'CSV musi obsahovat sloupce slug a name.');
            redirect(BASE_URL . '/admin/events.php');
        }

        $colIndex = [];
        foreach ($headers as $index => $headerName) {
            if ($headerName !== '') {
                $colIndex[$headerName] = $index;
            }
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        $pdo->beginTransaction();
        try {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (!is_array($row) || empty($row)) {
                    continue;
                }

                $rawSlug = trim((string)($row[$colIndex['slug'] ?? -1] ?? ''));
                $rawName = trim((string)($row[$colIndex['name'] ?? -1] ?? ''));

                $slug = adminSlugifyEventValue($rawSlug !== '' ? $rawSlug : $rawName);
                $name = $rawName;
                if ($slug === '' || $name === '') {
                    $skipped++;
                    continue;
                }

                $iconClass = adminNormalizeEventIcon((string)($row[$colIndex['icon_class'] ?? -1] ?? 'fa-bolt'));
                $description = trim((string)($row[$colIndex['description'] ?? -1] ?? ''));
                $badgeLabel = trim((string)($row[$colIndex['badge_label'] ?? -1] ?? ''));
                $audience = strtolower(trim((string)($row[$colIndex['audience'] ?? -1] ?? 'both')));
                if (!in_array($audience, ['coach', 'athlete', 'both'], true)) {
                    $audience = 'both';
                }

                $sortOrderRaw = trim((string)($row[$colIndex['sort_order'] ?? -1] ?? '100'));
                $sortOrder = is_numeric($sortOrderRaw) ? (int)$sortOrderRaw : 100;
                $isActive = adminCsvFlagToBool((string)($row[$colIndex['is_active'] ?? -1] ?? '1'), true) ? 1 : 0;
                $tileImage = trim((string)($row[$colIndex['tile_image'] ?? -1] ?? ''));

                $existingStmt = $pdo->prepare('SELECT id FROM special_events WHERE slug = ? LIMIT 1');
                $existingStmt->execute([$slug]);
                $existingId = (int)($existingStmt->fetchColumn() ?: 0);

                if ($existingId > 0) {
                    if ($specialEventsHasTileImage) {
                        $sql = 'UPDATE special_events
                                SET name = ?, icon_class = ?, description = ?, badge_label = ?, tile_image = ?, audience = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                                WHERE id = ?';
                        $params = [
                            $name,
                            $iconClass,
                            $description !== '' ? $description : null,
                            $badgeLabel !== '' ? $badgeLabel : null,
                            $tileImage !== '' ? $tileImage : null,
                            $audience,
                            $sortOrder,
                            $isActive,
                            $existingId,
                        ];
                    } else {
                        $sql = 'UPDATE special_events
                                SET name = ?, icon_class = ?, description = ?, badge_label = ?, audience = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                                WHERE id = ?';
                        $params = [
                            $name,
                            $iconClass,
                            $description !== '' ? $description : null,
                            $badgeLabel !== '' ? $badgeLabel : null,
                            $audience,
                            $sortOrder,
                            $isActive,
                            $existingId,
                        ];
                    }

                    $updateStmt = $pdo->prepare($sql);
                    $updateStmt->execute($params);
                    $updated++;
                    continue;
                }

                if ($specialEventsHasTileImage) {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO special_events (slug, name, icon_class, description, badge_label, tile_image, audience, sort_order, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insertStmt->execute([
                        $slug,
                        $name,
                        $iconClass,
                        $description !== '' ? $description : null,
                        $badgeLabel !== '' ? $badgeLabel : null,
                        $tileImage !== '' ? $tileImage : null,
                        $audience,
                        $sortOrder,
                        $isActive,
                    ]);
                } else {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO special_events (slug, name, icon_class, description, badge_label, audience, sort_order, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insertStmt->execute([
                        $slug,
                        $name,
                        $iconClass,
                        $description !== '' ? $description : null,
                        $badgeLabel !== '' ? $badgeLabel : null,
                        $audience,
                        $sortOrder,
                        $isActive,
                    ]);
                }

                $inserted++;
            }

            fclose($handle);
            $pdo->commit();

            flash('success', 'CSV import dokoncen. Vlozeno: ' . $inserted . ', aktualizovano: ' . $updated . ', preskoceno: ' . $skipped . '.');
            redirect(BASE_URL . '/admin/events.php');
        } catch (Throwable $e) {
            fclose($handle);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'CSV import selhal. Zkontrolujte format souboru.');
            redirect(BASE_URL . '/admin/events.php');
        }
    }

    if ($action === 'import_upcoming_items_csv') {
        $eventIdFromQuery = (int)($_GET['event_id'] ?? 0);

        if (!adminSpecialEventsHasUpcomingItemsTable($pdo)) {
            flash('danger', 'Nadcházející události zatím nejsou připravené v databázi. Spusťte migraci special events.');
            redirect(BASE_URL . '/admin/events.php' . ($eventIdFromQuery > 0 ? ('?event_id=' . $eventIdFromQuery) : ''));
        }

        if (empty($_FILES['upcoming_items_csv']) || (int)($_FILES['upcoming_items_csv']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('danger', 'CSV soubor nebyl nahran.');
            redirect(BASE_URL . '/admin/events.php' . ($eventIdFromQuery > 0 ? ('?event_id=' . $eventIdFromQuery) : ''));
        }

        $uploadError = (int)($_FILES['upcoming_items_csv']['error'] ?? UPLOAD_ERR_OK);
        if ($uploadError !== UPLOAD_ERR_OK) {
            flash('danger', 'CSV soubor se nepodarilo nahrat.');
            redirect(BASE_URL . '/admin/events.php' . ($eventIdFromQuery > 0 ? ('?event_id=' . $eventIdFromQuery) : ''));
        }

        $tmpPath = (string)($_FILES['upcoming_items_csv']['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            flash('danger', 'CSV soubor se nepodarilo nacist.');
            redirect(BASE_URL . '/admin/events.php' . ($eventIdFromQuery > 0 ? ('?event_id=' . $eventIdFromQuery) : ''));
        }

        $handle = @fopen($tmpPath, 'rb');
        if (!$handle) {
            flash('danger', 'CSV soubor se nepodarilo otevrit.');
            redirect(BASE_URL . '/admin/events.php' . ($eventIdFromQuery > 0 ? ('?event_id=' . $eventIdFromQuery) : ''));
        }

        $firstLine = (string)fgets($handle);
        rewind($handle);
        $delimiter = adminDetectCsvDelimiter($firstLine);

        $headersRaw = fgetcsv($handle, 0, $delimiter);
        if (!is_array($headersRaw) || empty($headersRaw)) {
            fclose($handle);
            flash('danger', 'CSV soubor nema hlavicku sloupcu.');
            redirect(BASE_URL . '/admin/events.php' . ($eventIdFromQuery > 0 ? ('?event_id=' . $eventIdFromQuery) : ''));
        }

        $headers = array_map(static fn($h) => adminNormalizeCsvHeader((string)$h), $headersRaw);
        $eventSlugIndex = array_search('event_slug', $headers, true);
        $titleIndex = array_search('title', $headers, true);
        $eventDateIndex = array_search('event_date', $headers, true);

        if ($eventSlugIndex === false || $titleIndex === false || $eventDateIndex === false) {
            fclose($handle);
            flash('danger', 'CSV musi obsahovat sloupce event_slug, title a event_date.');
            redirect(BASE_URL . '/admin/events.php' . ($eventIdFromQuery > 0 ? ('?event_id=' . $eventIdFromQuery) : ''));
        }

        $colIndex = [];
        foreach ($headers as $index => $headerName) {
            if ($headerName !== '') {
                $colIndex[$headerName] = $index;
            }
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        $pdo->beginTransaction();
        try {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (!is_array($row) || empty($row)) {
                    continue;
                }

                $rawEventSlug = trim((string)($row[$colIndex['event_slug'] ?? -1] ?? ''));
                $eventSlug = adminSlugifyEventValue($rawEventSlug);
                $title = trim((string)($row[$colIndex['title'] ?? -1] ?? ''));
                $eventDate = adminNormalizeEventDate((string)($row[$colIndex['event_date'] ?? -1] ?? ''));
                $targetUrl = adminNormalizeEventTargetUrl((string)($row[$colIndex['target_url'] ?? -1] ?? ''));
                $tabKey = adminSlugifyEventValue((string)($row[$colIndex['tab_key'] ?? -1] ?? ''));
                $sortOrderRaw = trim((string)($row[$colIndex['sort_order'] ?? -1] ?? '100'));
                $sortOrder = is_numeric($sortOrderRaw) ? (int)$sortOrderRaw : 100;
                $isActive = adminCsvFlagToBool((string)($row[$colIndex['is_active'] ?? -1] ?? '1'), true) ? 1 : 0;

                if ($eventSlug === '' || $title === '' || $eventDate === '') {
                    $skipped++;
                    continue;
                }

                $eventStmt = $pdo->prepare('SELECT id FROM special_events WHERE slug = ? LIMIT 1');
                $eventStmt->execute([$eventSlug]);
                $eventId = (int)($eventStmt->fetchColumn() ?: 0);
                if ($eventId <= 0) {
                    $skipped++;
                    continue;
                }

                $tabId = 0;
                if ($specialEventsHasUpcomingTabColumn && $tabKey !== '') {
                    $tabStmt = $pdo->prepare('SELECT id FROM special_event_tabs WHERE event_id = ? AND tab_key = ? LIMIT 1');
                    $tabStmt->execute([$eventId, $tabKey]);
                    $tabId = (int)($tabStmt->fetchColumn() ?: 0);
                }

                $existingStmt = $pdo->prepare(
                    'SELECT id
                     FROM special_event_upcoming_items
                     WHERE event_id = ? AND title = ? AND event_date = ?
                     ORDER BY id ASC
                     LIMIT 1'
                );
                $existingStmt->execute([$eventId, $title, $eventDate]);
                $existingId = (int)($existingStmt->fetchColumn() ?: 0);

                if ($existingId > 0) {
                    if ($specialEventsHasUpcomingTabColumn) {
                        $updateStmt = $pdo->prepare(
                            'UPDATE special_event_upcoming_items
                             SET tab_id = ?, target_url = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                             WHERE id = ?'
                        );
                        $updateStmt->execute([
                            $tabId > 0 ? $tabId : null,
                            $targetUrl !== '' ? $targetUrl : null,
                            $sortOrder,
                            $isActive,
                            $existingId,
                        ]);
                    } else {
                        $updateStmt = $pdo->prepare(
                            'UPDATE special_event_upcoming_items
                             SET target_url = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                             WHERE id = ?'
                        );
                        $updateStmt->execute([
                            $targetUrl !== '' ? $targetUrl : null,
                            $sortOrder,
                            $isActive,
                            $existingId,
                        ]);
                    }
                    $updated++;
                    continue;
                }

                if ($specialEventsHasUpcomingTabColumn) {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO special_event_upcoming_items (event_id, tab_id, title, event_date, target_url, sort_order, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insertStmt->execute([
                        $eventId,
                        $tabId > 0 ? $tabId : null,
                        $title,
                        $eventDate,
                        $targetUrl !== '' ? $targetUrl : null,
                        $sortOrder,
                        $isActive,
                    ]);
                } else {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO special_event_upcoming_items (event_id, title, event_date, target_url, sort_order, is_active)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $insertStmt->execute([
                        $eventId,
                        $title,
                        $eventDate,
                        $targetUrl !== '' ? $targetUrl : null,
                        $sortOrder,
                        $isActive,
                    ]);
                }
                $inserted++;
            }

            fclose($handle);
            $pdo->commit();

            flash('success', 'CSV import nadchazejicich udalosti dokoncen. Vlozeno: ' . $inserted . ', aktualizovano: ' . $updated . ', preskoceno: ' . $skipped . '.');
            redirect(BASE_URL . '/admin/events.php' . ($eventIdFromQuery > 0 ? ('?event_id=' . $eventIdFromQuery) : ''));
        } catch (Throwable $e) {
            fclose($handle);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'CSV import nadchazejicich udalosti selhal. Zkontrolujte format souboru.');
            redirect(BASE_URL . '/admin/events.php' . ($eventIdFromQuery > 0 ? ('?event_id=' . $eventIdFromQuery) : ''));
        }
    }

    if ($action === 'add_event') {
        $name = trim((string)($_POST['name'] ?? ''));
        $slugInput = trim((string)($_POST['slug'] ?? ''));
        $slug = adminSlugifyEventValue($slugInput !== '' ? $slugInput : $name);
        $iconClass = adminNormalizeEventIcon((string)($_POST['icon_class'] ?? 'fa-bolt'));
        $badgeLabel = trim((string)($_POST['badge_label'] ?? ''));
        $tileImage = $specialEventsHasTileImage ? adminEventTileImageUpload() : null;
        $description = trim((string)($_POST['description'] ?? ''));
        $audience = (string)($_POST['audience'] ?? 'both');
        $sortOrder = (int)($_POST['sort_order'] ?? 100);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($audience, ['coach', 'athlete', 'both'], true)) {
            $audience = 'both';
        }

        if ($name === '' || $slug === '') {
            flash('danger', 'Nazev a slug eventu jsou povinne.');
            redirect(BASE_URL . '/admin/events.php');
        }

        if ($specialEventsHasTileImage && !empty($_FILES['tile_image']) && (int)($_FILES['tile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && $tileImage === null) {
            flash('danger', 'Obrazek karty se nepodarilo nahrat.');
            redirect(BASE_URL . '/admin/events.php');
        }

        try {
            if ($specialEventsHasTileImage) {
                $stmt = $pdo->prepare(
                    'INSERT INTO special_events (slug, name, icon_class, description, badge_label, tile_image, audience, sort_order, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $slug,
                    $name,
                    $iconClass,
                    $description !== '' ? $description : null,
                    $badgeLabel !== '' ? $badgeLabel : null,
                    $tileImage,
                    $audience,
                    $sortOrder,
                    $isActive,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO special_events (slug, name, icon_class, description, badge_label, audience, sort_order, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $slug,
                    $name,
                    $iconClass,
                    $description !== '' ? $description : null,
                    $badgeLabel !== '' ? $badgeLabel : null,
                    $audience,
                    $sortOrder,
                    $isActive,
                ]);
            }

            $newId = (int)$pdo->lastInsertId();
            flash('success', 'Event byl vytvoren.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $newId);
        } catch (Throwable $e) {
            if ($tileImage) {
                deleteUploadedPhoto($tileImage, 'events');
            }
            flash('danger', 'Event se nepodarilo vytvorit. Zkontrolujte unikatnost slugu.');
            redirect(BASE_URL . '/admin/events.php');
        }
    }

    if ($action === 'update_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $slugInput = trim((string)($_POST['slug'] ?? ''));
        $slug = adminSlugifyEventValue($slugInput !== '' ? $slugInput : $name);
        $iconClass = adminNormalizeEventIcon((string)($_POST['icon_class'] ?? 'fa-bolt'));
        $badgeLabel = trim((string)($_POST['badge_label'] ?? ''));
        $removeTileImage = isset($_POST['remove_tile_image']);
        $newTileImage = $specialEventsHasTileImage ? adminEventTileImageUpload() : null;
        $description = trim((string)($_POST['description'] ?? ''));
        $audience = (string)($_POST['audience'] ?? 'both');
        $sortOrder = (int)($_POST['sort_order'] ?? 100);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($audience, ['coach', 'athlete', 'both'], true)) {
            $audience = 'both';
        }

        if ($eventId <= 0 || $name === '' || $slug === '') {
            flash('danger', 'Event se nepodarilo ulozit.');
            redirect(BASE_URL . '/admin/events.php');
        }

        $currentTileImage = null;
        if ($specialEventsHasTileImage) {
            $currentEventStmt = $pdo->prepare('SELECT tile_image FROM special_events WHERE id = ? LIMIT 1');
            $currentEventStmt->execute([$eventId]);
            $currentTileImage = $currentEventStmt->fetchColumn();
            $currentTileImage = is_string($currentTileImage) ? $currentTileImage : null;
        }

        if ($specialEventsHasTileImage && !empty($_FILES['tile_image']) && (int)($_FILES['tile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && $newTileImage === null) {
            flash('danger', 'Obrazek karty se nepodarilo nahrat.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }

        $tileImageToSave = $currentTileImage;
        if ($specialEventsHasTileImage && $removeTileImage) {
            $tileImageToSave = null;
        }
        if ($specialEventsHasTileImage && $newTileImage) {
            $tileImageToSave = $newTileImage;
        }

        try {
            if ($specialEventsHasTileImage) {
                $stmt = $pdo->prepare(
                    'UPDATE special_events
                     SET slug = ?, name = ?, icon_class = ?, description = ?, badge_label = ?, tile_image = ?, audience = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                     WHERE id = ?'
                );
                $stmt->execute([
                    $slug,
                    $name,
                    $iconClass,
                    $description !== '' ? $description : null,
                    $badgeLabel !== '' ? $badgeLabel : null,
                    $tileImageToSave,
                    $audience,
                    $sortOrder,
                    $isActive,
                    $eventId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE special_events
                     SET slug = ?, name = ?, icon_class = ?, description = ?, badge_label = ?, audience = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                     WHERE id = ?'
                );
                $stmt->execute([
                    $slug,
                    $name,
                    $iconClass,
                    $description !== '' ? $description : null,
                    $badgeLabel !== '' ? $badgeLabel : null,
                    $audience,
                    $sortOrder,
                    $isActive,
                    $eventId,
                ]);
            }

            if ($specialEventsHasTileImage && $currentTileImage && $currentTileImage !== $tileImageToSave) {
                deleteUploadedPhoto($currentTileImage, 'events');
            }

            flash('success', 'Event byl ulozen.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        } catch (Throwable $e) {
            if ($newTileImage) {
                deleteUploadedPhoto($newTileImage, 'events');
            }
            flash('danger', 'Event se nepodarilo ulozit. Zkontrolujte unikatnost slugu.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }
    }

    if ($action === 'delete_event') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        if ($eventId <= 0) {
            flash('danger', 'Event se nepodarilo smazat.');
            redirect(BASE_URL . '/admin/events.php');
        }

        if ($specialEventsHasTileImage) {
            $imageStmt = $pdo->prepare('SELECT tile_image FROM special_events WHERE id = ? LIMIT 1');
            $imageStmt->execute([$eventId]);
            $tileImage = $imageStmt->fetchColumn();
            if (is_string($tileImage) && $tileImage !== '') {
                deleteUploadedPhoto($tileImage, 'events');
            }
        }

        $pdo->prepare('DELETE FROM special_events WHERE id = ?')->execute([$eventId]);
        flash('success', 'Event byl smazan.');
        redirect(BASE_URL . '/admin/events.php');
    }

    if ($action === 'add_tab') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $tabKeyInput = trim((string)($_POST['tab_key'] ?? ''));
        $tabKey = adminSlugifyEventValue($tabKeyInput !== '' ? $tabKeyInput : $title);
        $contentHtml = trim((string)($_POST['content_html'] ?? ''));
        $audience = (string)($_POST['audience'] ?? 'both');
        $sortOrder = (int)($_POST['sort_order'] ?? 100);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($audience, ['coach', 'athlete', 'both'], true)) {
            $audience = 'both';
        }

        if ($eventId <= 0 || $title === '' || $tabKey === '') {
            flash('danger', 'Zalozku se nepodarilo vytvorit.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO special_event_tabs (event_id, tab_key, title, content_html, audience, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $eventId,
                $tabKey,
                $title,
                $contentHtml !== '' ? $contentHtml : null,
                $audience,
                $sortOrder,
                $isActive,
            ]);

            flash('success', 'Zalozka byla vytvorena.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        } catch (Throwable $e) {
            flash('danger', 'Zalozku se nepodarilo vytvorit. Overte unikatni key v ramci eventu a audience.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }
    }

    if ($action === 'update_tab') {
        $tabId = (int)($_POST['tab_id'] ?? 0);
        $eventId = (int)($_POST['event_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $tabKeyInput = trim((string)($_POST['tab_key'] ?? ''));
        $tabKey = adminSlugifyEventValue($tabKeyInput !== '' ? $tabKeyInput : $title);
        $contentHtml = trim((string)($_POST['content_html'] ?? ''));
        $audience = (string)($_POST['audience'] ?? 'both');
        $sortOrder = (int)($_POST['sort_order'] ?? 100);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($audience, ['coach', 'athlete', 'both'], true)) {
            $audience = 'both';
        }

        if ($tabId <= 0 || $eventId <= 0 || $title === '' || $tabKey === '') {
            flash('danger', 'Zalozku se nepodarilo ulozit.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }

        try {
            $stmt = $pdo->prepare(
                'UPDATE special_event_tabs
                 SET tab_key = ?, title = ?, content_html = ?, audience = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                 WHERE id = ? AND event_id = ?'
            );
            $stmt->execute([
                $tabKey,
                $title,
                $contentHtml !== '' ? $contentHtml : null,
                $audience,
                $sortOrder,
                $isActive,
                $tabId,
                $eventId,
            ]);

            flash('success', 'Zalozka byla ulozena.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        } catch (Throwable $e) {
            flash('danger', 'Zalozku se nepodarilo ulozit. Overte unikatni key v ramci eventu a audience.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }
    }

    if ($action === 'add_upcoming_item' || $action === 'update_upcoming_item') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);
        $tabId = (int)($_POST['tab_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $eventDate = adminNormalizeEventDate((string)($_POST['event_date'] ?? ''));
        $targetUrl = adminNormalizeEventTargetUrl((string)($_POST['target_url'] ?? ''));
        $sortOrder = (int)($_POST['sort_order'] ?? 100);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($eventId <= 0 || $title === '' || $eventDate === '') {
            flash('danger', 'Událost se nepodařilo uložit. Zkontrolujte název a datum.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }

        if (!adminSpecialEventsHasUpcomingItemsTable($pdo)) {
            flash('danger', 'Nadcházející události zatím nejsou připravené v databázi. Spusťte migraci special events.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }

        if ($specialEventsHasUpcomingTabColumn && $tabId > 0) {
            $tabCheckStmt = $pdo->prepare('SELECT id FROM special_event_tabs WHERE id = ? AND event_id = ? LIMIT 1');
            $tabCheckStmt->execute([$tabId, $eventId]);
            if (!$tabCheckStmt->fetch()) {
                $tabId = 0;
            }
        } else {
            $tabId = 0;
        }

        try {
            if ($action === 'add_upcoming_item') {
                if ($specialEventsHasUpcomingTabColumn) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO special_event_upcoming_items (event_id, tab_id, title, event_date, target_url, sort_order, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $eventId,
                        $tabId > 0 ? $tabId : null,
                        $title,
                        $eventDate,
                        $targetUrl !== '' ? $targetUrl : null,
                        $sortOrder,
                        $isActive,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO special_event_upcoming_items (event_id, title, event_date, target_url, sort_order, is_active)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $eventId,
                        $title,
                        $eventDate,
                        $targetUrl !== '' ? $targetUrl : null,
                        $sortOrder,
                        $isActive,
                    ]);
                }
                flash('success', 'Nadcházející událost byla přidána.');
            } else {
                if ($itemId <= 0) {
                    flash('danger', 'Událost se nepodařilo uložit.');
                    redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
                }

                if ($specialEventsHasUpcomingTabColumn) {
                    $stmt = $pdo->prepare(
                        'UPDATE special_event_upcoming_items
                         SET tab_id = ?, title = ?, event_date = ?, target_url = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                         WHERE id = ? AND event_id = ?'
                    );
                    $stmt->execute([
                        $tabId > 0 ? $tabId : null,
                        $title,
                        $eventDate,
                        $targetUrl !== '' ? $targetUrl : null,
                        $sortOrder,
                        $isActive,
                        $itemId,
                        $eventId,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE special_event_upcoming_items
                         SET title = ?, event_date = ?, target_url = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                         WHERE id = ? AND event_id = ?'
                    );
                    $stmt->execute([
                        $title,
                        $eventDate,
                        $targetUrl !== '' ? $targetUrl : null,
                        $sortOrder,
                        $isActive,
                        $itemId,
                        $eventId,
                    ]);
                }
                flash('success', 'Nadcházející událost byla uložena.');
            }
        } catch (Throwable $e) {
            flash('danger', 'Nadcházející událost se nepodařilo uložit.');
        }

        redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
    }

    if ($action === 'delete_upcoming_item') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);

        if ($eventId <= 0 || $itemId <= 0) {
            flash('danger', 'Nadcházející událost se nepodařilo smazat.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }

        if (!adminSpecialEventsHasUpcomingItemsTable($pdo)) {
            flash('danger', 'Nadcházející události zatím nejsou připravené v databázi. Spusťte migraci special events.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }

        $pdo->prepare('DELETE FROM special_event_upcoming_items WHERE id = ? AND event_id = ?')->execute([$itemId, $eventId]);
        flash('success', 'Nadcházející událost byla smazána.');
        redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
    }

    if ($action === 'delete_tab') {
        $tabId = (int)($_POST['tab_id'] ?? 0);
        $eventId = (int)($_POST['event_id'] ?? 0);

        if ($tabId <= 0 || $eventId <= 0) {
            flash('danger', 'Zalozku se nepodarilo smazat.');
            redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
        }

        $pdo->prepare('DELETE FROM special_event_tabs WHERE id = ? AND event_id = ?')->execute([$tabId, $eventId]);
        flash('success', 'Zalozka byla smazana.');
        redirect(BASE_URL . '/admin/events.php?event_id=' . $eventId);
    }
}

$events = $pdo->query(
    $specialEventsHasTileImage
        ? "SELECT id, slug, name, icon_class, description, badge_label, tile_image, audience, sort_order, is_active, updated_at
           FROM special_events
           ORDER BY sort_order ASC, name ASC"
        : "SELECT id, slug, name, icon_class, description, badge_label, audience, sort_order, is_active, updated_at
           FROM special_events
           ORDER BY sort_order ASC, name ASC"
)->fetchAll();

$selectedEventId = (int)($_GET['event_id'] ?? 0);
if ($selectedEventId <= 0 && !empty($events)) {
    $selectedEventId = (int)$events[0]['id'];
}

$selectedEvent = null;
$tabs = [];
$upcomingItems = [];
if ($selectedEventId > 0) {
    $selectedEventStmt = $pdo->prepare(
          $specialEventsHasTileImage
                ? 'SELECT id, slug, name, icon_class, description, badge_label, tile_image, audience, sort_order, is_active
                    FROM special_events
                    WHERE id = ? LIMIT 1'
                : 'SELECT id, slug, name, icon_class, description, badge_label, audience, sort_order, is_active
                    FROM special_events
                    WHERE id = ? LIMIT 1'
    );
    $selectedEventStmt->execute([$selectedEventId]);
    $selectedEvent = $selectedEventStmt->fetch();

    if ($selectedEvent) {
        $tabsStmt = $pdo->prepare(
            'SELECT id, event_id, tab_key, title, content_html, audience, sort_order, is_active, updated_at
             FROM special_event_tabs
             WHERE event_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $tabsStmt->execute([$selectedEventId]);
        $tabs = $tabsStmt->fetchAll();

        if (adminSpecialEventsHasUpcomingItemsTable($pdo)) {
            $upcomingSql = $specialEventsHasUpcomingTabColumn
                ? 'SELECT id, event_id, tab_id, title, event_date, target_url, sort_order, is_active
                   FROM special_event_upcoming_items
                   WHERE event_id = ?
                   ORDER BY event_date ASC, id ASC'
                : 'SELECT id, event_id, NULL AS tab_id, title, event_date, target_url, sort_order, is_active
                   FROM special_event_upcoming_items
                   WHERE event_id = ?
                   ORDER BY event_date ASC, id ASC';

            $upcomingStmt = $pdo->prepare($upcomingSql);
            $upcomingStmt->execute([$selectedEventId]);
            $upcomingItems = $upcomingStmt->fetchAll();
        }
    }
}

$coachIntroText = getAppSetting('events_intro_text_coach', $defaultCoachIntroText);
$athleteIntroText = getAppSetting('events_intro_text_athlete', $defaultAthleteIntroText);
$eventsFormsEmail = getAppSetting('events_forms_email_to', $defaultFormsEmail);

renderAdminHeader('Sprava Events');
?>

<style>
.events-admin-shell {
    max-width: 1360px;
    margin: 0 auto;
}

.events-header {
    border: 1px solid #dbe6f5;
    border-radius: 14px;
    background: linear-gradient(130deg, #ffffff 0%, #f3f8ff 55%, #ecf4ff 100%);
    padding: 1rem 1.1rem;
}

.events-header h2 {
    letter-spacing: .01em;
}

.events-left-col {
    position: sticky;
    top: 1rem;
}

.event-list-row {
    border: 1px solid #dfe8f4;
    border-radius: 12px;
    background: #fbfdff;
    padding: .75rem .85rem;
    transition: all .18s ease;
}

.event-list-row:hover {
    border-color: #9fc4ff;
    background: #f2f7ff;
}

.event-list-row.is-selected {
    border-color: #6ea9ff;
    background: #eaf3ff;
    box-shadow: inset 0 0 0 1px #bcd9ff;
}

.events-nav {
    border-bottom: 1px solid #e1e8f2;
}

.events-nav .nav-link {
    color: #4a607d;
    border: none;
    border-bottom: 3px solid transparent;
    border-radius: 0;
    font-weight: 600;
    padding-inline: .95rem;
}

.events-nav .nav-link.active {
    color: #0d6efd;
    border-bottom-color: #0d6efd;
    background: transparent;
}

.tab-editor-card {
    border: 1px solid #deebf8;
    border-radius: 12px;
}

.rich-hint {
    font-size: .875rem;
    color: #586f8e;
}

.tab-live-preview {
    border: 1px solid #dce7f4;
    border-radius: 10px;
    padding: 1rem;
    background: #ffffff;
    line-height: 1.75 !important;
    font-size: 1.02rem;
}

.tab-live-preview h1,
.tab-live-preview h2,
.tab-live-preview h3,
.tab-live-preview h4,
.tab-live-preview h5,
.tab-live-preview h6 {
    margin-top: 1.35rem !important;
    margin-bottom: .95rem !important;
    font-weight: 700;
}

.tab-live-preview > :first-child {
    margin-top: 0 !important;
}

.tab-live-preview p,
.tab-live-preview ul,
.tab-live-preview ol,
.tab-live-preview blockquote,
.tab-live-preview pre,
.tab-live-preview table,
.tab-live-preview form {
    margin-top: 0 !important;
    margin-bottom: 1.12rem !important;
}

.tab-live-preview ul,
.tab-live-preview ol {
    padding-left: 1.35rem;
}

.tab-live-preview li {
    margin-bottom: .72rem !important;
}

.tab-live-preview li:last-child {
    margin-bottom: 0;
}

.tab-live-preview table {
    width: 100%;
}

.tab-live-preview table th,
.tab-live-preview table td {
    border: 1px solid #e4ecf6;
    padding: .45rem .6rem;
}

.tab-live-preview table th {
    background: #f5f9ff;
}

.event-tile-image-preview {
    width: 100%;
    max-width: 360px;
    height: 180px;
    object-fit: cover;
    border-radius: 14px;
    border: 1px solid #dbe6f5;
    background: #f8fafc;
}

@media (max-width: 1199.98px) {
    .events-left-col {
        position: static;
    }
}
</style>

<div class="events-admin-shell pb-4">
    <div class="events-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <h2 class="mb-0 fw-bold"><i class="fas fa-flag-checkered me-2 text-warning"></i>Sprava Events</h2>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge text-bg-dark px-3 py-2"><?= count($events) ?> eventu</span>
            <?php if ($selectedEvent): ?>
                <span class="badge text-bg-light border text-dark px-3 py-2">Aktualne: <?= h((string)$selectedEvent['name']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4 align-items-start">
        <div class="col-12 col-xl-4">
            <div class="events-left-col d-flex flex-column gap-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold">Seznam eventu</div>
                    <div class="card-body d-flex flex-column gap-2">
                        <?php if (empty($events)): ?>
                            <div class="text-muted small">Zatim neni vytvoren zadny event.</div>
                        <?php else: ?>
                            <?php foreach ($events as $event): ?>
                                <?php
                                    $isSelected = ((int)$event['id'] === (int)$selectedEventId);
                                    $iconClass = (string)($event['icon_class'] ?? 'fa-bolt');
                                    if (!preg_match('/^fa-[a-z0-9-]+$/', $iconClass)) {
                                        $iconClass = 'fa-bolt';
                                    }
                                ?>
                                <a class="event-list-row text-decoration-none <?= $isSelected ? 'is-selected' : '' ?>" href="<?= BASE_URL ?>/admin/events.php?event_id=<?= (int)$event['id'] ?>">
                                    <div class="d-flex justify-content-between align-items-center gap-2">
                                        <div class="fw-semibold text-dark text-truncate">
                                            <i class="fas <?= h($iconClass) ?> me-2 text-warning"></i><?= h((string)$event['name']) ?>
                                        </div>
                                        <span class="badge <?= ((int)$event['is_active'] === 1) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                            <?= ((int)$event['is_active'] === 1) ? 'Aktivni' : 'Skryto' ?>
                                        </span>
                                    </div>
                                    <div class="small text-muted mt-1">/<?= h((string)$event['slug']) ?> · <?= h((string)$event['audience']) ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-dark text-white fw-semibold">Novy event</div>
                    <div class="card-body">
                        <form method="post" class="row g-3" enctype="multipart/form-data">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="add_event">
                            <div class="col-12">
                                <label class="form-label fw-semibold">Nazev</label>
                                <input type="text" name="name" class="form-control" maxlength="140" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Slug</label>
                                <input type="text" name="slug" class="form-control" maxlength="120" placeholder="napr. hyrox">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-semibold">Ikona (FA)</label>
                                <input type="text" name="icon_class" class="form-control" maxlength="80" value="fa-bolt">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-semibold">Poradi</label>
                                <input type="number" name="sort_order" class="form-control" value="100">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-semibold">Audience</label>
                                <select name="audience" class="form-select">
                                    <option value="both">Obe role</option>
                                    <option value="coach">Jen trener</option>
                                    <option value="athlete">Jen sportovec</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-semibold">Stitek</label>
                                <input type="text" name="badge_label" class="form-control" maxlength="80" placeholder="napr. Novinka">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Text na kartě eventu (hlavní stránka)</label>
                                <textarea name="description" class="form-control" rows="2" maxlength="500" placeholder="Tento text se zobrazi na karte eventu."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Obrazek karty</label>
                                <input type="file" name="tile_image" class="form-control" accept="image/*">
                                <div class="form-text">Doporuceny je sirsi obrazek, ktery se dobre orezne do karty.</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="add_event_active" checked>
                                    <label class="form-check-label" for="add_event_active">Aktivni</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary w-100" type="submit">Vytvorit event</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold">Hromadny import eventu (CSV)</div>
                    <div class="card-body">
                        <form method="post" class="row g-3" enctype="multipart/form-data">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="import_events_csv">
                            <div class="col-12">
                                <label class="form-label fw-semibold">CSV soubor</label>
                                <input type="file" name="events_csv" class="form-control" accept=".csv,text/csv" required>
                                <div class="form-text">
                                    Povinne sloupce: slug,name. Volitelne: icon_class,description,badge_label,tile_image,audience,sort_order,is_active. Oddelovac muze byt carka nebo strednik.
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-outline-dark w-100" type="submit">Importovat CSV</button>
                            </div>
                            <div class="col-12">
                                <a class="btn btn-link p-0" href="<?= BASE_URL ?>/scripts/csv/events_import_template.csv" download>
                                    <i class="fas fa-file-download me-1"></i>Stahnout vzor CSV pro eventy
                                </a>
                            </div>
                            <div class="col-12">
                                <a class="btn btn-link p-0" href="<?= BASE_URL ?>/admin/events.php?action=export_events_csv">
                                    <i class="fas fa-download me-1"></i>Exportovat eventy do CSV
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold">Text na hlavni strance Events</div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_events_intro">
                            <div class="col-12">
                                <label class="form-label fw-semibold">Text pro trenery</label>
                                <textarea name="coach_intro_text" class="form-control" rows="3" maxlength="1200"><?= h((string)$coachIntroText) ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Text pro sportovce</label>
                                <textarea name="athlete_intro_text" class="form-control" rows="3" maxlength="1200"><?= h((string)$athleteIntroText) ?></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-outline-primary w-100" type="submit">Ulozit texty</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold">Formulare z event zalozek</div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_events_forms_email">
                            <div class="col-12">
                                <label class="form-label fw-semibold">Cilovy e-mail pro odeslane formulare</label>
                                <input type="email" name="events_forms_email_to" class="form-control" maxlength="255" value="<?= h((string)$eventsFormsEmail) ?>" placeholder="napr. info@domena.cz">
                                <div class="form-text">
                                    Pokud pole nechas prazdne, pouzije se vychozi admin e-mail.
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-outline-primary w-100" type="submit">Ulozit e-mail</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <?php if (!$selectedEvent): ?>
                <div class="alert alert-info">Vyber event ze seznamu nebo vytvor novy.</div>
            <?php else: ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body pb-0">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <div>
                                <h4 class="mb-1 fw-bold">
                                    <i class="fas <?= h((string)$selectedEvent['icon_class']) ?> text-warning me-2"></i><?= h((string)$selectedEvent['name']) ?>
                                </h4>
                                <div class="text-muted small">Slug: /<?= h((string)$selectedEvent['slug']) ?> · Audience: <?= h((string)$selectedEvent['audience']) ?></div>
                            </div>
                            <span class="badge <?= ((int)$selectedEvent['is_active'] === 1) ? 'text-bg-success' : 'text-bg-secondary' ?> px-3 py-2">
                                <?= ((int)$selectedEvent['is_active'] === 1) ? 'Aktivni' : 'Skryty' ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-header bg-white pt-0 border-0">
                        <ul class="nav events-nav" id="eventsAdminMainTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="event-settings-tab" data-bs-toggle="tab" data-bs-target="#event-settings-pane" type="button" role="tab" aria-controls="event-settings-pane" aria-selected="true">Nastaveni eventu</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="event-tabs-tab" data-bs-toggle="tab" data-bs-target="#event-tabs-pane" type="button" role="tab" aria-controls="event-tabs-pane" aria-selected="false">Obsah a zalozky (<?= count($tabs) ?>)</button>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body tab-content">
                        <div class="tab-pane fade show active" id="event-settings-pane" role="tabpanel" aria-labelledby="event-settings-tab">
                            <form method="post" class="row g-3" enctype="multipart/form-data">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update_event">
                                <input type="hidden" name="event_id" value="<?= (int)$selectedEvent['id'] ?>">

                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Nazev</label>
                                    <input type="text" name="name" class="form-control" maxlength="140" required value="<?= h((string)$selectedEvent['name']) ?>">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Slug</label>
                                    <input type="text" name="slug" class="form-control" maxlength="120" required value="<?= h((string)$selectedEvent['slug']) ?>">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Ikona (FA)</label>
                                    <input type="text" name="icon_class" class="form-control" maxlength="80" value="<?= h((string)$selectedEvent['icon_class']) ?>">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Poradi</label>
                                    <input type="number" name="sort_order" class="form-control" value="<?= (int)$selectedEvent['sort_order'] ?>">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Audience</label>
                                    <select name="audience" class="form-select">
                                        <option value="both" <?= ((string)$selectedEvent['audience'] === 'both') ? 'selected' : '' ?>>Obe role</option>
                                        <option value="coach" <?= ((string)$selectedEvent['audience'] === 'coach') ? 'selected' : '' ?>>Jen trener</option>
                                        <option value="athlete" <?= ((string)$selectedEvent['audience'] === 'athlete') ? 'selected' : '' ?>>Jen sportovec</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-semibold">Stitek</label>
                                    <input type="text" name="badge_label" class="form-control" maxlength="80" value="<?= h((string)($selectedEvent['badge_label'] ?? '')) ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Text na kartě eventu (hlavní stránka)</label>
                                    <textarea name="description" class="form-control" rows="2" maxlength="500" placeholder="Tento text se zobrazi na karte eventu."><?= h((string)($selectedEvent['description'] ?? '')) ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Obrazek karty</label>
                                    <input type="file" name="tile_image" class="form-control" accept="image/*">
                                    <div class="form-text">Nahranim noveho obrazku puvodni nahradis. Pokud chces obrazek odstranit, zaskrtni volbu niz.</div>
                                </div>
                                <?php if (trim((string)($selectedEvent['tile_image'] ?? '')) !== ''): ?>
                                    <div class="col-12">
                                        <div class="mb-2 fw-semibold">Aktualni obrazek</div>
                                        <img src="<?= h(photoUrl((string)$selectedEvent['tile_image'], 'events')) ?>" alt="<?= h((string)$selectedEvent['name']) ?>" class="event-tile-image-preview">
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" name="remove_tile_image" id="remove_tile_image">
                                            <label class="form-check-label" for="remove_tile_image">Smazat obrazek karty</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="edit_event_active" <?= ((int)$selectedEvent['is_active'] === 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="edit_event_active">Aktivni</label>
                                    </div>
                                </div>
                                <div class="col-12 d-flex gap-2 flex-wrap">
                                    <button class="btn btn-primary" type="submit">Ulozit event</button>
                                </div>
                            </form>

                            <hr>

                            <form method="post" onsubmit="return confirm('Opravdu chcete smazat cely event vcetne vsech zalozek?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_event">
                                <input type="hidden" name="event_id" value="<?= (int)$selectedEvent['id'] ?>">
                                <button class="btn btn-outline-danger btn-sm" type="submit">Smazat event</button>
                            </form>
                        </div>

                        <div class="tab-pane fade" id="event-tabs-pane" role="tabpanel" aria-labelledby="event-tabs-tab">
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                <div class="small text-muted">Zalozky si pridavas postupne podle potreby. Kazda nova zalozka rovnou otevre editor obsahu.</div>
                                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#eventAddTabCollapse" aria-expanded="false" aria-controls="eventAddTabCollapse">
                                    <i class="fas fa-plus me-1"></i>Pridat zalozku
                                </button>
                            </div>

                            <div class="collapse mb-3" id="eventAddTabCollapse">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-dark text-white fw-semibold">Nova zalozka</div>
                                    <div class="card-body">
                                        <form method="post" class="row g-3">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="add_tab">
                                            <input type="hidden" name="event_id" value="<?= (int)$selectedEvent['id'] ?>">

                                            <div class="col-12 col-md-6">
                                                <label class="form-label fw-semibold">Nazev zalozky</label>
                                                <input type="text" name="title" class="form-control" maxlength="140" required>
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <label class="form-label fw-semibold">Key zalozky</label>
                                                <input type="text" name="tab_key" class="form-control" maxlength="120" placeholder="napr. evropa-2026">
                                                <div class="form-text">Např. evropa-2026. Key musí být unikátní v rámci eventu.</div>
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <label class="form-label fw-semibold">Audience</label>
                                                <select name="audience" class="form-select">
                                                    <option value="both">Obe role</option>
                                                    <option value="coach">Jen trener</option>
                                                    <option value="athlete">Jen sportovec</option>
                                                </select>
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <label class="form-label fw-semibold">Poradi</label>
                                                <input type="number" name="sort_order" class="form-control" value="100">
                                            </div>
                                            <div class="col-12 col-md-4 d-flex align-items-end">
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="is_active" id="add_tab_active" checked>
                                                    <label class="form-check-label" for="add_tab_active">Aktivni</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label fw-semibold">Obsah zalozky</label>
                                                <div class="rich-hint mb-2">
                                                    Text, fotky, videa, tlacitka s odkazy i formularove prvky. Po ulozeni je obsah hned publikovany dle audience.
                                                </div>
                                                <textarea name="content_html" class="form-control js-rich-editor" rows="9" placeholder="<h3>Nadpis</h3><p>Obsah...</p>"></textarea>
                                            </div>
                                            <div class="col-12 d-flex gap-2 flex-wrap">
                                                <button class="btn btn-primary" type="submit">Pridat zalozku</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <?php if (empty($tabs)): ?>
                                <div class="alert alert-light border mb-0">Tento event zatim nema zadne zalozky.</div>
                            <?php else: ?>
                                <div class="accordion" id="eventTabsAccordion">
                                    <?php foreach ($tabs as $tab): ?>
                                        <?php $tabId = (int)$tab['id']; ?>
                                        <div class="accordion-item tab-editor-card mb-3">
                                            <h2 class="accordion-header" id="tabHeading<?= $tabId ?>">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#tabCollapse<?= $tabId ?>" aria-expanded="false" aria-controls="tabCollapse<?= $tabId ?>">
                                                    <span class="me-2 fw-semibold"><?= h((string)$tab['title']) ?></span>
                                                    <span class="badge text-bg-light border ms-2">key: <?= h((string)$tab['tab_key']) ?></span>
                                                    <span class="badge <?= ((int)$tab['is_active'] === 1) ? 'text-bg-success' : 'text-bg-secondary' ?> ms-2"><?= ((int)$tab['is_active'] === 1) ? 'Aktivni' : 'Skryta' ?></span>
                                                </button>
                                            </h2>
                                            <div id="tabCollapse<?= $tabId ?>" class="accordion-collapse collapse" aria-labelledby="tabHeading<?= $tabId ?>" data-bs-parent="#eventTabsAccordion">
                                                <div class="accordion-body">
                                                    <form method="post" class="row g-3">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="update_tab">
                                                        <input type="hidden" name="event_id" value="<?= (int)$selectedEvent['id'] ?>">
                                                        <input type="hidden" name="tab_id" value="<?= $tabId ?>">

                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label fw-semibold">Nazev</label>
                                                            <input type="text" name="title" class="form-control" maxlength="140" required value="<?= h((string)$tab['title']) ?>">
                                                        </div>
                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label fw-semibold">Key</label>
                                                            <input type="text" name="tab_key" class="form-control" maxlength="120" required value="<?= h((string)$tab['tab_key']) ?>">
                                                        </div>
                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label fw-semibold">Audience</label>
                                                            <select name="audience" class="form-select">
                                                                <option value="both" <?= ((string)$tab['audience'] === 'both') ? 'selected' : '' ?>>Obe role</option>
                                                                <option value="coach" <?= ((string)$tab['audience'] === 'coach') ? 'selected' : '' ?>>Jen trener</option>
                                                                <option value="athlete" <?= ((string)$tab['audience'] === 'athlete') ? 'selected' : '' ?>>Jen sportovec</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-12 col-md-4">
                                                            <label class="form-label fw-semibold">Poradi</label>
                                                            <input type="number" name="sort_order" class="form-control" value="<?= (int)$tab['sort_order'] ?>">
                                                        </div>
                                                        <div class="col-12 col-md-4 d-flex align-items-end">
                                                            <div class="form-check mb-2">
                                                                <input class="form-check-input" type="checkbox" name="is_active" id="tab_active_<?= $tabId ?>" <?= ((int)$tab['is_active'] === 1) ? 'checked' : '' ?>>
                                                                <label class="form-check-label" for="tab_active_<?= $tabId ?>">Aktivni</label>
                                                            </div>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label fw-semibold">Obsah zalozky</label>
                                                            <div class="rich-hint mb-2">
                                                                Muzete vkladat odkazy, obrazky, videa, tabulky, iframe embed i vlastni HTML (napr. formular).
                                                            </div>
                                                            <textarea name="content_html" class="form-control js-rich-editor" rows="7"><?= h((string)($tab['content_html'] ?? '')) ?></textarea>
                                                        </div>
                                                        <div class="col-12 d-flex gap-2 flex-wrap">
                                                            <button class="btn btn-primary btn-sm" type="submit">Ulozit zalozku</button>
                                                        </div>
                                                    </form>

                                                    <form method="post" class="mt-3" onsubmit="return confirm('Opravdu smazat tuto zalozku?');">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="delete_tab">
                                                        <input type="hidden" name="event_id" value="<?= (int)$selectedEvent['id'] ?>">
                                                        <input type="hidden" name="tab_id" value="<?= $tabId ?>">
                                                        <button class="btn btn-outline-danger btn-sm" type="submit">Smazat</button>
                                                    </form>

                                                    <?php if (trim((string)($tab['content_html'] ?? '')) !== ''): ?>
                                                        <div class="mt-3">
                                                            <div class="small fw-semibold text-muted mb-2">Nahled po sanitizaci</div>
                                                            <div class="tab-live-preview">
                                                                <?= sanitizeSpecialEventHtml((string)$tab['content_html']) ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="card border-0 shadow-sm mt-4">
                                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <span>Nadcházející události</span>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-light border mb-4">
                                        Každou položku přiřaď do konkrétní záložky. Zobrazí se jen v té záložce na frontendu.
                                    </div>

                                    <form method="post" class="row g-3 mb-4">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="add_upcoming_item">
                                        <input type="hidden" name="event_id" value="<?= (int)$selectedEvent['id'] ?>">
                                        <?php if ($specialEventsHasUpcomingTabColumn): ?>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label fw-semibold">Záložka</label>
                                            <select name="tab_id" class="form-select">
                                                <option value="0">Bez přiřazení</option>
                                                <?php foreach ($tabs as $tabOption): ?>
                                                    <option value="<?= (int)$tabOption['id'] ?>"><?= h((string)$tabOption['title']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php endif; ?>
                                        <div class="col-12 col-md-5">
                                            <label class="form-label fw-semibold">Název události</label>
                                            <input type="text" name="title" class="form-control" maxlength="180" required>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label fw-semibold">Datum</label>
                                            <input type="date" name="event_date" class="form-control" required>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label fw-semibold">Odkaz</label>
                                            <input type="url" name="target_url" class="form-control" maxlength="500" placeholder="https://... nebo /...">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label fw-semibold">Pořadí</label>
                                            <input type="number" name="sort_order" class="form-control" value="100">
                                        </div>
                                        <div class="col-12 col-md-4 d-flex align-items-end">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" name="is_active" id="add_upcoming_item_active" checked>
                                                <label class="form-check-label" for="add_upcoming_item_active">Aktivní</label>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-4 d-flex align-items-end">
                                            <button class="btn btn-primary w-100" type="submit">Přidat položku</button>
                                        </div>
                                    </form>

                                    <div class="card border-0 shadow-sm mb-4">
                                        <div class="card-body">
                                            <div class="fw-semibold mb-2">Hromadny import nadchazejicich udalosti (CSV)</div>
                                            <form method="post" class="row g-3" enctype="multipart/form-data">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="import_upcoming_items_csv">
                                                <div class="col-12">
                                                    <input type="file" name="upcoming_items_csv" class="form-control" accept=".csv,text/csv" required>
                                                    <div class="form-text">
                                                        Povinne sloupce: event_slug,title,event_date. Volitelne: tab_key,target_url,sort_order,is_active. Vazba ke spravnemu eventu probiha pres event_slug.
                                                    </div>
                                                </div>
                                                <div class="col-12 col-md-4">
                                                    <button class="btn btn-outline-dark w-100" type="submit">Importovat CSV</button>
                                                </div>
                                                <div class="col-12">
                                                    <a class="btn btn-link p-0" href="<?= BASE_URL ?>/scripts/csv/upcoming_items_import_template.csv" download>
                                                        <i class="fas fa-file-download me-1"></i>Stahnout vzor CSV pro nadchazejici udalosti
                                                    </a>
                                                </div>
                                                <div class="col-12">
                                                    <a class="btn btn-link p-0" href="<?= BASE_URL ?>/admin/events.php?action=export_upcoming_items_csv&event_id=<?= (int)$selectedEvent['id'] ?>">
                                                        <i class="fas fa-download me-1"></i>Exportovat nadchazejici udalosti tohoto eventu
                                                    </a>
                                                </div>
                                                <div class="col-12">
                                                    <a class="btn btn-link p-0" href="<?= BASE_URL ?>/admin/events.php?action=export_upcoming_items_csv">
                                                        <i class="fas fa-download me-1"></i>Exportovat vsechny nadchazejici udalosti
                                                    </a>
                                                </div>
                                            </form>
                                        </div>
                                    </div>

                                    <?php if (empty($upcomingItems)): ?>
                                        <div class="alert alert-light border mb-0">V seznamu zatím nejsou žádné položky.</div>
                                    <?php else: ?>
                                        <div class="d-grid gap-3">
                                            <?php foreach ($upcomingItems as $item): ?>
                                                <form method="post" class="row g-3 align-items-end border rounded-4 p-3 bg-light">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="event_id" value="<?= (int)$selectedEvent['id'] ?>">
                                                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                    <?php if ($specialEventsHasUpcomingTabColumn): ?>
                                                    <div class="col-12 col-md-3">
                                                        <label class="form-label fw-semibold">Záložka</label>
                                                        <select name="tab_id" class="form-select">
                                                            <option value="0" <?= ((int)($item['tab_id'] ?? 0) === 0) ? 'selected' : '' ?>>Bez přiřazení</option>
                                                            <?php foreach ($tabs as $tabOption): ?>
                                                                <option value="<?= (int)$tabOption['id'] ?>" <?= ((int)($item['tab_id'] ?? 0) === (int)$tabOption['id']) ? 'selected' : '' ?>><?= h((string)$tabOption['title']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="col-12 col-md-5">
                                                        <label class="form-label fw-semibold">Název události</label>
                                                        <input type="text" name="title" class="form-control" maxlength="180" required value="<?= h((string)$item['title']) ?>">
                                                    </div>
                                                    <div class="col-12 col-md-3">
                                                        <label class="form-label fw-semibold">Datum</label>
                                                        <input type="date" name="event_date" class="form-control" required value="<?= h((string)$item['event_date']) ?>">
                                                    </div>
                                                    <div class="col-12 col-md-4">
                                                        <label class="form-label fw-semibold">Odkaz</label>
                                                        <input type="url" name="target_url" class="form-control" maxlength="500" value="<?= h((string)($item['target_url'] ?? '')) ?>">
                                                    </div>
                                                    <div class="col-12 col-md-2">
                                                        <label class="form-label fw-semibold">Pořadí</label>
                                                        <input type="number" name="sort_order" class="form-control" value="<?= (int)$item['sort_order'] ?>">
                                                    </div>
                                                    <div class="col-12 col-md-2 d-flex align-items-end">
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input" type="checkbox" name="is_active" id="upcoming_item_active_<?= (int)$item['id'] ?>" <?= ((int)$item['is_active'] === 1) ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="upcoming_item_active_<?= (int)$item['id'] ?>">Aktivní</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-12 col-md-4 d-flex gap-2 flex-wrap">
                                                        <button class="btn btn-primary" type="submit" name="action" value="update_upcoming_item">Uložit</button>
                                                        <button class="btn btn-outline-danger" type="submit" name="action" value="delete_upcoming_item" formnovalidate onclick="return confirm('Opravdu smazat tuto položku?');">Smazat</button>
                                                    </div>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof tinymce === 'undefined') {
        return;
    }

    const eventsMediaUploadUrl = <?= json_encode(((defined('BASE_URL') && BASE_URL !== '') ? BASE_URL : '') . '/admin/api/events_media_upload.php', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const eventsCsrfToken = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const uploadMedia = async function (file, mediaType) {
        const formData = new FormData();
        formData.append('csrf_token', eventsCsrfToken);
        formData.append('media_type', mediaType);
        formData.append('file', file);

        const response = await fetch(eventsMediaUploadUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (e) {
            payload = null;
        }

        if (!response.ok || !payload || payload.success !== true || !payload.url) {
            throw new Error((payload && payload.error) ? payload.error : 'Upload selhal.');
        }

        return payload;
    };

    const openFilePicker = function (accept, cb) {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = accept;
        input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', function () {
            const file = (input.files && input.files.length > 0) ? input.files[0] : null;
            document.body.removeChild(input);
            if (file) {
                cb(file);
            }
        });
        input.click();
    };

    tinymce.init({
        selector: 'textarea.js-rich-editor',
        menubar: 'file edit view insert format tools table help',
        branding: false,
        height: 360,
        plugins: [
            'anchor', 'autolink', 'charmap', 'codesample', 'code', 'fullscreen', 'help',
            'image', 'insertdatetime', 'link', 'lists', 'media', 'preview', 'searchreplace',
            'table', 'visualblocks', 'wordcount', 'advlist'
        ],
        toolbar: 'undo redo | formatselect | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media table | eventsuploadimage eventsuploadvideo | blocksnippets | code fullscreen preview',
        content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; }',
        extended_valid_elements: 'form[action|method|id|class|target|enctype],input[type|name|value|placeholder|required|checked|class|id],button[type|class|id],label[for|class|id],select[name|class|id],option[value|selected],textarea[name|rows|cols|placeholder|class|id],iframe[src|width|height|frameborder|allowfullscreen|allow|title|loading|referrerpolicy],video[src|controls|width|height|poster|preload|class],source[src|type]',
        verify_html: false,
        link_default_target: '_blank',
        link_assume_external_targets: true,
        images_upload_handler: function (blobInfo, progress) {
            return new Promise(function (resolve, reject) {
                uploadMedia(blobInfo.blob(), 'image')
                    .then(function (payload) {
                        progress(100);
                        resolve(payload.url);
                    })
                    .catch(function (err) {
                        reject(err instanceof Error ? err.message : 'Upload obrazku selhal.');
                    });
            });
        },
        file_picker_types: 'image media',
        file_picker_callback: function (callback, value, meta) {
            if (meta.filetype === 'image') {
                openFilePicker('image/*', function (file) {
                    uploadMedia(file, 'image')
                        .then(function (payload) {
                            callback(payload.url, { alt: file.name || 'Obrazek' });
                        })
                        .catch(function (err) {
                            alert(err instanceof Error ? err.message : 'Upload obrazku selhal.');
                        });
                });
                return;
            }

            if (meta.filetype === 'media') {
                openFilePicker('video/mp4,video/webm,video/ogg,video/quicktime', function (file) {
                    uploadMedia(file, 'video')
                        .then(function (payload) {
                            callback(payload.url);
                        })
                        .catch(function (err) {
                            alert(err instanceof Error ? err.message : 'Upload videa selhal.');
                        });
                });
            }
        },
        setup: function (editor) {
            editor.ui.registry.addButton('eventsuploadimage', {
                text: 'Nahrat obrazek',
                tooltip: 'Nahrat obrazek z pocitace',
                onAction: function () {
                    openFilePicker('image/*', function (file) {
                        const notice = editor.notificationManager.open({
                            text: 'Nahravam obrazek... ',
                            type: 'info',
                            closeButton: false
                        });

                        uploadMedia(file, 'image')
                            .then(function (payload) {
                                notice.close();
                                editor.insertContent('<img src="' + payload.url + '" alt="' + (file.name || 'Obrazek') + '" style="max-width:100%;height:auto;">');
                            })
                            .catch(function (err) {
                                notice.close();
                                editor.notificationManager.open({
                                    text: err instanceof Error ? err.message : 'Upload obrazku selhal.',
                                    type: 'error'
                                });
                            });
                    });
                }
            });

            editor.ui.registry.addButton('eventsuploadvideo', {
                text: 'Nahrat video',
                tooltip: 'Nahrat video z pocitace',
                onAction: function () {
                    openFilePicker('video/mp4,video/webm,video/ogg,video/quicktime', function (file) {
                        const notice = editor.notificationManager.open({
                            text: 'Nahravam video... ',
                            type: 'info',
                            closeButton: false
                        });

                        uploadMedia(file, 'video')
                            .then(function (payload) {
                                notice.close();
                                editor.insertContent('<video controls style="max-width:100%;height:auto;"><source src="' + payload.url + '" type="' + (payload.mime || 'video/mp4') + '">Vas prohlizec nepodporuje video.</video>');
                            })
                            .catch(function (err) {
                                notice.close();
                                editor.notificationManager.open({
                                    text: err instanceof Error ? err.message : 'Upload videa selhal.',
                                    type: 'error'
                                });
                            });
                    });
                }
            });

            editor.ui.registry.addMenuButton('blocksnippets', {
                text: 'Snippety',
                fetch: function (callback) {
                    callback([
                        {
                            type: 'menuitem',
                            text: 'Tlacitko odkaz',
                            onAction: function () {
                                editor.insertContent('<p><a class="btn btn-primary" href="https://" target="_blank" rel="noopener">Zjistit vice</a></p>');
                            }
                        },
                        {
                            type: 'menuitem',
                            text: 'Obrazek s popisem',
                            onAction: function () {
                                editor.insertContent('<figure><img src="https://" alt="Obrazek" style="max-width:100%;height:auto;"><figcaption>Popisek obrazku</figcaption></figure>');
                            }
                        },
                        {
                            type: 'menuitem',
                            text: 'Video (YouTube iframe)',
                            onAction: function () {
                                editor.insertContent('<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:10px;"><iframe src="https://www.youtube.com/embed/VIDEO_ID" title="Video" style="position:absolute;top:0;left:0;width:100%;height:100%;" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>');
                            }
                        },
                        {
                            type: 'menuitem',
                            text: 'Kontaktni formular',
                            onAction: function () {
                                editor.insertContent('<form action="/event_form_submit.php" method="post" class="p-3 border rounded"><input type="hidden" name="_event_form_subject" value="Nova prihlaska"><div class="mb-3"><label class="form-label">Jmeno</label><input class="form-control" type="text" name="Jmeno" required></div><div class="mb-3"><label class="form-label">E-mail</label><input class="form-control" type="email" name="Email" required></div><div class="mb-3"><label class="form-label">Telefon</label><input class="form-control" type="text" name="Telefon"></div><div class="mb-3"><label class="form-label">Zprava</label><textarea class="form-control" name="Zprava" rows="4" required></textarea></div><button class="btn btn-dark" type="submit">Odeslat prihlasku</button></form>');
                            }
                        }
                    ]);
                }
            });
        }
    });
});
</script>

<?php renderAdminFooter();
