<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();
$athleteStmt = $pdo->prepare('SELECT id, birth_date FROM athletes WHERE id = ? LIMIT 1');
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$tableStmt = $pdo->query("SHOW TABLES LIKE 'athlete_transformation_entries'");
$schemaReady = $tableStmt !== false && (bool)$tableStmt->fetchColumn();
$photoPositions = [
    1 => ['label' => 'Zepředu', 'icon' => 'fa-person'],
    2 => ['label' => 'Z pravého boku', 'icon' => 'fa-arrow-right'],
    3 => ['label' => 'Zezadu', 'icon' => 'fa-person-walking-arrow-loop-left'],
    4 => ['label' => 'Z levého boku', 'icon' => 'fa-arrow-left'],
];
if ($schemaReady) {
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS athlete_transformation_photos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entry_id INT NOT NULL,
                athlete_id INT NOT NULL,
                position TINYINT UNSIGNED NOT NULL,
                photo_path VARCHAR(255) NOT NULL,
                photo_mime_type VARCHAR(100) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_transformation_photo_slot (entry_id, position),
                CONSTRAINT fk_transformation_photos_entry
                    FOREIGN KEY (entry_id) REFERENCES athlete_transformation_entries(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'INSERT IGNORE INTO athlete_transformation_photos (entry_id, athlete_id, position, photo_path, photo_mime_type)
             SELECT id, athlete_id, 1, photo_path, photo_mime_type FROM athlete_transformation_entries
             WHERE athlete_id = ' . (int)$athleteId . " AND TRIM(photo_path) <> ''"
        );
    } catch (Throwable $e) {
        error_log('Unable to prepare transformation photos table: ' . $e->getMessage());
        $schemaReady = false;
    }
}
$metricFields = [
    'weight_kg' => ['label' => 'Váha', 'unit' => 'kg', 'max' => 500, 'step' => '0.1'],
    'height_cm' => ['label' => 'Výška', 'unit' => 'cm', 'max' => 260, 'step' => '0.1'],
    'chest_cm' => [
        'label' => 'Hrudník přes prsa',
        'unit' => 'cm',
        'max' => 300,
        'step' => '0.1',
        'help' => 'Pásku veďte vodorovně přes nejplnější část hrudníku a přes lopatky. Měřte po běžném výdechu, bez stahování pásky.',
    ],
    'waist_cm' => [
        'label' => 'Pas',
        'unit' => 'cm',
        'max' => 300,
        'step' => '0.1',
        'help' => 'Měřte nejužší místo trupu. Pokud ho nelze určit, veďte pásku mezi spodním žebrem a horním okrajem kyčelní kosti. Nezatahujte břicho.',
    ],
    'hips_cm' => [
        'label' => 'Boky',
        'unit' => 'cm',
        'max' => 300,
        'step' => '0.1',
        'help' => 'Měřte vodorovně kolem nejširšího místa boků a hýždí, s chodidly u sebe.',
    ],
    'left_arm_cm' => [
        'label' => 'Obvod bicepsu levé paže',
        'unit' => 'cm',
        'max' => 150,
        'step' => '0.1',
        'help' => 'Paži ohněte a napněte. Pásku veďte kolmo kolem nejširšího místa přes vrchol bicepsu, bez zařezávání. Každou paži změřte zvlášť.',
    ],
    'right_arm_cm' => [
        'label' => 'Obvod bicepsu pravé paže',
        'unit' => 'cm',
        'max' => 150,
        'step' => '0.1',
        'help' => 'Paži ohněte a napněte. Pásku veďte kolmo kolem nejširšího místa přes vrchol bicepsu, bez zařezávání. Každou paži změřte zvlášť.',
    ],
    'left_thigh_cm' => [
        'label' => 'Obvod levého stehna',
        'unit' => 'cm',
        'max' => 200,
        'step' => '0.1',
        'help' => 'Měřte vodorovně kolem nejširšího místa horní části stehna. Levou a pravou nohu změřte zvlášť.',
    ],
    'right_thigh_cm' => [
        'label' => 'Obvod pravého stehna',
        'unit' => 'cm',
        'max' => 200,
        'step' => '0.1',
        'help' => 'Měřte vodorovně kolem nejširšího místa horní části stehna. Levou a pravou nohu změřte zvlášť.',
    ],
    'left_calf_cm' => [
        'label' => 'Obvod levého lýtka',
        'unit' => 'cm',
        'max' => 120,
        'step' => '0.1',
        'help' => 'Měřte vodorovně v nejširším místě lýtka. Každou nohu změřte zvlášť.',
    ],
    'right_calf_cm' => [
        'label' => 'Obvod pravého lýtka',
        'unit' => 'cm',
        'max' => 120,
        'step' => '0.1',
        'help' => 'Měřte vodorovně v nejširším místě lýtka. Každou nohu změřte zvlášť.',
    ],
    'body_fat_percent' => ['label' => 'Tělesný tuk', 'unit' => '%', 'max' => 100, 'step' => '0.1'],
];
$measurementSizeMaxima = array_unique(array_map(
    static fn(array $meta): int => (int)$meta['max'],
    array_slice($metricFields, 2, 9, true)
));
$errors = [];
$draftEntryId = 0;
$formValues = ['measured_on' => date('Y-m-d'), 'note' => ''];
foreach ($metricFields as $field => $_meta) {
    $formValues[$field] = '';
}

$parseMeasuredOn = static function (string $value): ?DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($value === ''
        || $date === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
        || $value > date('Y-m-d')
    ) {
        return null;
    }
    return $date;
};
$ageOnDate = static function (?string $birthDate, DateTimeImmutable $on): ?int {
    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)$birthDate));
    return $birth instanceof DateTimeImmutable && $birth <= $on ? $birth->diff($on)->y : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaReady && (string)($_POST['action'] ?? '') === 'upload_photo') {
    $jsonResponse = static function (int $status, array $payload) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $jsonResponse(403, ['ok' => false, 'error' => 'Neplatný bezpečnostní token. Obnovte stránku.']);
    }

    $position = (int)($_POST['position'] ?? 0);
    if (!isset($photoPositions[$position])) {
        $jsonResponse(422, ['ok' => false, 'error' => 'Neplatná pozice fotografie.']);
    }

    $upload = $_FILES['photo'] ?? null;
    $uploadError = is_array($upload) ? (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        $jsonResponse(422, ['ok' => false, 'error' => 'Fotografie překračuje maximální velikost povolenou serverem.']);
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        $jsonResponse(422, ['ok' => false, 'error' => 'Fotografii se nepodařilo nahrát.']);
    }
    if ((int)$upload['size'] > 10 * 1024 * 1024) {
        $jsonResponse(422, ['ok' => false, 'error' => 'Fotografie může mít maximálně 10 MB.']);
    }
    $temporaryPath = (string)$upload['tmp_name'];
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $allowedImageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mimeType) || !isset($allowedImageTypes[$mimeType]) || @getimagesize($temporaryPath) === false) {
        $jsonResponse(422, ['ok' => false, 'error' => 'Použijte platnou fotografii ve formátu JPG, PNG nebo WebP.']);
    }

    $entryId = (int)($_POST['entry_id'] ?? 0);
    $entryRow = null;
    if ($entryId > 0) {
        $entryStmt = $pdo->prepare('SELECT id, photo_path FROM athlete_transformation_entries WHERE id = ? AND athlete_id = ? LIMIT 1');
        $entryStmt->execute([$entryId, $athleteId]);
        $entryRow = $entryStmt->fetch();
        if (!$entryRow) {
            $jsonResponse(404, ['ok' => false, 'error' => 'Záznam nebyl nalezen.']);
        }
    }
    $measuredOnValue = trim((string)($_POST['measured_on'] ?? ''));
    $measuredOnDate = $parseMeasuredOn($measuredOnValue);
    if ($entryRow === null && $measuredOnDate === null) {
        $jsonResponse(422, ['ok' => false, 'error' => 'Nejprve zadejte platné datum, které není v budoucnosti.']);
    }

    $uploadDir = __DIR__ . '/uploads/athlete_transformations/athlete_' . $athleteId . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        $jsonResponse(500, ['ok' => false, 'error' => 'Nepodařilo se připravit úložiště fotografií.']);
    }
    $photoName = bin2hex(random_bytes(24)) . '.' . $allowedImageTypes[$mimeType];
    $photoPath = $uploadDir . $photoName;
    if (!move_uploaded_file($temporaryPath, $photoPath)) {
        $jsonResponse(500, ['ok' => false, 'error' => 'Fotografii se nepodařilo uložit.']);
    }

    $oldPhotoName = null;
    try {
        $pdo->beginTransaction();
        if ($entryRow === null) {
            $ageYears = $ageOnDate($athlete['birth_date'] ?? null, $measuredOnDate);
            $pdo->prepare(
                'INSERT INTO athlete_transformation_entries (athlete_id, photo_path, photo_mime_type, measured_on, age_years)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$athleteId, $photoName, $mimeType, $measuredOnDate->format('Y-m-d'), $ageYears]);
            $entryId = (int)$pdo->lastInsertId();
            $primaryPath = $photoName;
        } else {
            $primaryPath = (string)$entryRow['photo_path'];
        }

        $oldStmt = $pdo->prepare('SELECT photo_path FROM athlete_transformation_photos WHERE entry_id = ? AND position = ? LIMIT 1 FOR UPDATE');
        $oldStmt->execute([$entryId, $position]);
        $oldPhotoName = $oldStmt->fetchColumn() ?: null;
        $pdo->prepare(
            'INSERT INTO athlete_transformation_photos (entry_id, athlete_id, position, photo_path, photo_mime_type)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path), photo_mime_type = VALUES(photo_mime_type)'
        )->execute([$entryId, $athleteId, $position, $photoName, $mimeType]);
        if ($oldPhotoName !== null && $oldPhotoName === $primaryPath) {
            $pdo->prepare('UPDATE athlete_transformation_entries SET photo_path = ?, photo_mime_type = ? WHERE id = ?')
                ->execute([$photoName, $mimeType, $entryId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_file($photoPath)) {
            unlink($photoPath);
        }
        error_log('Unable to save transformation photo: ' . $e->getMessage());
        $jsonResponse(500, ['ok' => false, 'error' => 'Fotografii se nepodařilo uložit. Zkuste to prosím znovu.']);
    }

    if (is_string($oldPhotoName) && basename($oldPhotoName) === $oldPhotoName && $oldPhotoName !== $photoName) {
        $oldPath = $uploadDir . $oldPhotoName;
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
    $jsonResponse(200, [
        'ok' => true,
        'entry_id' => $entryId,
        'position' => $position,
        'url' => BASE_URL . '/athlete_transformation_photo.php?id=' . $entryId . '&pos=' . $position . '&v=' . substr($photoName, 0, 8),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$schemaReady) {
    flash('danger', 'Databáze proměny zatím není připravena. Požádejte správce o spuštění migrace.');
    redirect(BASE_URL . '/athlete_gallery.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_transformation.php');
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        $entryId = intParam($_POST, 'entry_id');
        $entryStmt = $pdo->prepare('SELECT photo_path FROM athlete_transformation_entries WHERE id = ? AND athlete_id = ? LIMIT 1');
        $entryStmt->execute([$entryId, $athleteId]);
        $entry = $entryStmt->fetch();
        if (!$entry) {
            flash('warning', 'Záznam nebyl nalezen.');
        } else {
            $photoNamesStmt = $pdo->prepare('SELECT photo_path FROM athlete_transformation_photos WHERE entry_id = ?');
            $photoNamesStmt->execute([$entryId]);
            $photoNames = array_unique(array_merge([(string)$entry['photo_path']], $photoNamesStmt->fetchAll(PDO::FETCH_COLUMN)));
            $pdo->prepare('DELETE FROM athlete_transformation_entries WHERE id = ? AND athlete_id = ?')
                ->execute([$entryId, $athleteId]);
            $removalFailed = false;
            foreach ($photoNames as $photoName) {
                $photoPath = __DIR__ . '/uploads/athlete_transformations/athlete_' . $athleteId . '/' . $photoName;
                if (basename($photoName) !== $photoName || (is_file($photoPath) && !unlink($photoPath))) {
                    $removalFailed = true;
                }
            }
            if ($removalFailed) {
                error_log('Unable to remove athlete transformation photo for entry ' . $entryId);
                flash('warning', 'Záznam byl smazán, ale některou fotografii se nepodařilo odstranit. Kontaktujte správce.');
            } else {
                flash('success', 'Záznam proměny byl smazán.');
            }
        }
        redirect(BASE_URL . '/athlete_transformation.php');
    }

    if ($action === 'create') {
        $draftEntryId = intParam($_POST, 'entry_id');
        $draftStmt = $pdo->prepare('SELECT id FROM athlete_transformation_entries WHERE id = ? AND athlete_id = ? LIMIT 1');
        $draftStmt->execute([$draftEntryId, $athleteId]);
        if (!$draftStmt->fetchColumn()) {
            $draftEntryId = 0;
            $errors[] = 'Nejprve přidejte alespoň jednu fotografii.';
        }
        $measuredOnInput = $_POST['measured_on'] ?? '';
        $noteInput = $_POST['note'] ?? '';
        $formValues['measured_on'] = is_scalar($measuredOnInput) ? trim((string)$measuredOnInput) : '';
        $formValues['note'] = is_scalar($noteInput) ? trim((string)$noteInput) : '';
        foreach ($metricFields as $field => $_meta) {
            $value = $_POST[$field] ?? '';
            $formValues[$field] = is_scalar($value) ? trim((string)$value) : '';
        }

        $measuredOn = DateTimeImmutable::createFromFormat('!Y-m-d', $formValues['measured_on']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($formValues['measured_on'] === ''
            || $measuredOn === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $measuredOn->format('Y-m-d') !== $formValues['measured_on']
            || $formValues['measured_on'] > date('Y-m-d')
        ) {
            $errors[] = 'Zadejte platné datum pořízení fotografie, které není v budoucnosti.';
            $measuredOn = null;
        }

        $measurements = [];
        foreach ($metricFields as $field => $meta) {
            $rawValue = str_replace(',', '.', $formValues[$field]);
            if ($rawValue === '') {
                $measurements[$field] = null;
                continue;
            }
            $allowZero = $field === 'body_fat_percent';
            if (!is_numeric($rawValue)
                || (!$allowZero && (float)$rawValue <= 0)
                || ($allowZero && (float)$rawValue < 0)
                || (float)$rawValue > $meta['max']
            ) {
                $range = $allowZero ? 'od 0 do ' : 'větší než 0 a nejvýše ';
                $errors[] = $meta['label'] . ' musí být ' . $range . $meta['max'] . ' ' . $meta['unit'] . '.';
                $measurements[$field] = null;
                continue;
            }
            $measurements[$field] = round((float)$rawValue, 2);
        }

        if (mb_strlen($formValues['note'], 'UTF-8') > 2000) {
            $errors[] = 'Poznámka může mít maximálně 2 000 znaků.';
        }

        $birthDate = trim((string)($athlete['birth_date'] ?? ''));
        $ageYears = null;
        if ($measuredOn !== null && $birthDate !== '') {
            $birthDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
            if ($birthDateObject instanceof DateTimeImmutable && $birthDateObject <= $measuredOn) {
                $ageYears = $birthDateObject->diff($measuredOn)->y;
            } elseif ($birthDateObject instanceof DateTimeImmutable) {
                $errors[] = 'Datum záznamu nemůže být před datem narození.';
            }
        }

        if ($errors === []) {
            try {
                $update = $pdo->prepare(
                    'UPDATE athlete_transformation_entries SET
                        measured_on = ?, age_years = ?,
                        weight_kg = ?, height_cm = ?, chest_cm = ?, waist_cm = ?, hips_cm = ?,
                        left_arm_cm = ?, right_arm_cm = ?, left_thigh_cm = ?, right_thigh_cm = ?,
                        left_calf_cm = ?, right_calf_cm = ?, body_fat_percent = ?, note = ?
                     WHERE id = ? AND athlete_id = ?'
                );
                $update->execute(array_merge(
                    [$formValues['measured_on'], $ageYears],
                    array_values($measurements),
                    [$formValues['note'] !== '' ? $formValues['note'] : null, $draftEntryId, $athleteId]
                ));
                flash('success', 'Fotografie a údaje byly přidány do vaší proměny.');
                redirect(BASE_URL . '/athlete_transformation.php');
            } catch (Throwable $e) {
                error_log('Unable to save athlete transformation entry: ' . $e->getMessage());
                $errors[] = 'Záznam se nepodařilo uložit. Zkuste to prosím znovu.';
            }
        }
    }
}

if (!$schemaReady) {
    renderAthleteHeader('Moje proměna v čase', false, true);
    ?>
    <div class="alert alert-warning">
        <h4 class="alert-heading"><i class="fas fa-database me-2"></i>Proměna ještě není připravena</h4>
        <p class="mb-0">Správce musí nejprve v administraci Galerie spustit migraci modulu Moje proměna v čase.</p>
    </div>
    <?php
    renderAthleteFooter();
    exit;
}

$entriesStmt = $pdo->prepare('SELECT * FROM athlete_transformation_entries WHERE athlete_id = ? ORDER BY measured_on ASC, id ASC');
$entriesStmt->execute([$athleteId]);
$entriesAsc = $entriesStmt->fetchAll();
$entriesDesc = array_reverse($entriesAsc);
$lastMeasuredValues = [];
foreach ($metricFields as $field => $_meta) {
    $lastMeasuredValues[$field] = null;
    foreach ($entriesDesc as $entry) {
        if ($entry[$field] !== null) {
            $lastMeasuredValues[$field] = (float)$entry[$field];
            break;
        }
    }
}
$entryPhotos = [];
$photosStmt = $pdo->prepare('SELECT entry_id, position, photo_path FROM athlete_transformation_photos WHERE athlete_id = ?');
$photosStmt->execute([$athleteId]);
foreach ($photosStmt->fetchAll() as $photoRow) {
    $entryPhotos[(int)$photoRow['entry_id']][(int)$photoRow['position']] = substr((string)$photoRow['photo_path'], 0, 8);
}
$photoUrl = static fn(int $entryId, int $position, array $photos): string => BASE_URL
    . '/athlete_transformation_photo.php?id=' . $entryId . '&pos=' . $position . '&v=' . ($photos[$position] ?? '');
$storyEntries = [];
foreach ($entriesAsc as $entry) {
    $entryId = (int)$entry['id'];
    $photos = [];
    foreach ($photoPositions as $position => $_slot) {
        $hasPhoto = isset($entryPhotos[$entryId][$position])
            || ($position === 1 && trim((string)$entry['photo_path']) !== '' && !isset($entryPhotos[$entryId]));
        if ($hasPhoto) {
            $photos[$position] = $photoUrl($entryId, $position, $entryPhotos[$entryId] ?? []);
        }
    }
    $storyEntries[] = [
        'date' => date('j. n. Y', strtotime($entry['measured_on'])),
        'age' => $entry['age_years'] !== null ? (int)$entry['age_years'] . ' let' : null,
        'photos' => $photos,
        'weight' => $entry['weight_kg'] !== null
            ? number_format((float)$entry['weight_kg'], 1, ',', ' ') . ' kg'
            : null,
        'waist' => $entry['waist_cm'] !== null
            ? number_format((float)$entry['waist_cm'], 1, ',', ' ') . ' cm'
            : null,
        'bodyFat' => $entry['body_fat_percent'] !== null
            ? number_format((float)$entry['body_fat_percent'], 1, ',', ' ') . ' %'
            : null,
    ];
}
$storyPhotoCounts = array_fill_keys(array_keys($photoPositions), 0);
foreach ($storyEntries as $storyEntry) {
    foreach (array_keys($storyEntry['photos']) as $position) {
        $storyPhotoCounts[$position]++;
    }
}
$storyDefaultPosition = 1;
foreach ($storyPhotoCounts as $position => $count) {
    if ($count > $storyPhotoCounts[$storyDefaultPosition]) {
        $storyDefaultPosition = $position;
    }
}
$draftPhotos = $draftEntryId > 0 ? ($entryPhotos[$draftEntryId] ?? []) : [];
$initialEntry = $entriesAsc[0] ?? null;
$entries = $entriesDesc;
$metricBaselines = [];
foreach ($metricFields as $field => $_meta) {
    $metricBaselines[$field] = null;
    foreach ($entriesAsc as $entry) {
        if ($entry[$field] !== null) {
            $metricBaselines[$field] = (float)$entry[$field];
            break;
        }
    }
}

$chartPointCounts = [];
foreach ($metricFields as $field => $_meta) {
    $chartPointCounts[$field] = count(array_filter(
        $entriesAsc,
        static fn(array $entry): bool => $entry[$field] !== null
    ));
}
$chartableFields = array_keys(array_filter(
    $chartPointCounts,
    static fn(int $pointCount): bool => $pointCount >= 2
));
$showChart = $entriesAsc !== [];
$chartLabels = array_map(static fn(array $entry): string => date('d.m.Y', strtotime($entry['measured_on'])), $entriesAsc);
$chartData = [];
foreach ($metricFields as $field => $_meta) {
    $chartData[$field] = array_map(
        static fn(array $entry): ?float => $entry[$field] !== null ? (float)$entry[$field] : null,
        $entriesAsc
    );
}
$chartDefaultField = 'weight_kg';
if (($chartPointCounts[$chartDefaultField] ?? 0) < 2 && $chartableFields !== []) {
    $chartDefaultField = $chartableFields[0];
}
$birthDateValue = trim((string)($athlete['birth_date'] ?? ''));
$daysTracked = 0;
if ($initialEntry && $entriesAsc !== []) {
    $trackedPeriod = (new DateTimeImmutable($initialEntry['measured_on']))->diff(
        new DateTimeImmutable($entriesAsc[count($entriesAsc) - 1]['measured_on'])
    );
    $daysTracked = (int)$trackedPeriod->days;
}
$diagramEntry = $entriesAsc !== [] ? $entriesAsc[count($entriesAsc) - 1] : null;
$latestMeasuredValues = [];
if ($diagramEntry !== null) {
    foreach ($metricFields as $field => $_meta) {
        $latestMeasuredValues[$field] = null;
        foreach ($entriesAsc as $entry) {
            if ((int)$entry['id'] === (int)$diagramEntry['id']) {
                break;
            }
            if ($entry[$field] !== null) {
                $latestMeasuredValues[$field] = (float)$entry[$field];
            }
        }
    }
}
$diagramItems = [
    ['label' => 'Hrudník přes prsa', 'fields' => ['chest_cm'], 'help' => $metricFields['chest_cm']['help'], 'color' => '#f4b400'],
    ['label' => 'Pas', 'fields' => ['waist_cm'], 'help' => $metricFields['waist_cm']['help'], 'color' => '#087fc1'],
    ['label' => 'Boky', 'fields' => ['hips_cm'], 'help' => $metricFields['hips_cm']['help'], 'color' => '#811db2'],
    ['label' => 'Biceps levý / pravý', 'fields' => ['left_arm_cm', 'right_arm_cm'], 'help' => $metricFields['left_arm_cm']['help'], 'color' => '#159653'],
    ['label' => 'Stehno levé / pravé', 'fields' => ['left_thigh_cm', 'right_thigh_cm'], 'help' => $metricFields['left_thigh_cm']['help'], 'color' => '#f36b0b'],
    ['label' => 'Lýtko levé / pravé', 'fields' => ['left_calf_cm', 'right_calf_cm'], 'help' => $metricFields['left_calf_cm']['help'], 'color' => '#e70920'],
];
$formatDiagramValues = static function (array $entry, array $fields): string {
    $values = [];
    $hasValue = false;
    foreach ($fields as $field) {
        $hasValue = $hasValue || $entry[$field] !== null;
        $values[] = $entry[$field] === null
            ? '—'
            : number_format((float)$entry[$field], 1, ',', ' ');
    }
    return $hasValue ? implode(' / ', $values) . ' cm' : 'Nevyplněno';
};

renderAthleteHeader('Moje proměna v čase', $showChart, true);
?>

<style>
.transformation-chart-select {
    width: min(100%, 22rem);
}
.transformation-chart-canvas {
    position: relative;
    height: 20rem;
}
.transformation-diagram {
    display: block;
    width: 100%;
    max-height: 36rem;
}
.transformation-measurement-guide {
    max-width: 62rem;
}
.transformation-photo-button {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
}
.transformation-photo-button img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.transformation-photo-grid {
    align-content: stretch;
}
.transformation-photo-grid > [class*="col-"] {
    display: flex;
}
.transformation-photo-slot {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-width: 0;
}
.transformation-photo-slot .transformation-photo-button {
    min-height: 11rem;
}
.transformation-photo-slot .transformation-photo-button img {
    min-height: 11rem;
}
.transformation-last-value::placeholder {
    color: #8a929a;
    opacity: .8;
}
.transformation-comparisons {
    display: grid;
    gap: .3rem;
    margin-top: .45rem;
}
.transformation-comparison {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: .4rem;
    border-radius: .35rem;
    padding: .2rem .4rem;
    font-size: .75rem;
}
.transformation-comparison--first {
    color: #435b80;
    background: #edf3fa;
}
.transformation-comparison--previous {
    color: #72531c;
    background: #fff6df;
}
.transformation-comparison-label {
    color: #596574;
}
.transformation-story-photo {
    display: block;
    width: 100%;
    height: min(60vh, 32rem);
    object-fit: contain;
    background: #17212b;
    transition: opacity .2s ease;
}
.transformation-story-photo.is-changing {
    opacity: .35;
}
@media (prefers-reduced-motion: reduce) {
    .transformation-story-photo {
        transition: none;
    }
}
.transformation-story-progress {
    display: flex;
    gap: .4rem;
    overflow-x: auto;
    padding: .25rem 0 .5rem;
}
.transformation-story-progress button {
    flex: 0 0 auto;
    border: 0;
    border-radius: 999px;
    padding: .25rem .7rem;
    color: #526170;
    background: #eef1f4;
    font-size: .8rem;
}
.transformation-story-progress button[aria-current="step"] {
    color: #fff;
    background: #2878c8;
}
#entryForm .form-label {
    font-size: .875rem;
    margin-bottom: .25rem;
}
#entryForm .form-text {
    font-size: .75rem;
}
@media (max-width: 767.98px) {
    .transformation-chart-select {
        width: 100%;
    }
    .transformation-chart-canvas {
        height: 17rem;
    }
    #transformationTimeline .transformation-photo-slot .transformation-photo-button,
    #transformationTimeline .transformation-photo-slot .transformation-photo-button img {
        height: min(58vh, 30rem);
        min-height: 0;
    }
    .transformation-story-photo {
        height: min(55vh, 28rem);
    }
    #transformationTimeline .card-body {
        padding: 1rem !important;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-4">
    <div>
        <a href="<?= BASE_URL ?>/athlete_gallery.php" class="btn btn-outline-secondary btn-sm mb-3">
            <i class="fas fa-arrow-left me-1"></i>Zpět do galerie
        </a>
        <h2 class="mb-1"><i class="fas fa-chart-line me-2 text-warning"></i>Moje proměna v čase</h2>
        <div class="text-muted">Fotografie a měření zachycují váš vlastní vývoj. Záznamy vidíte pouze vy.</div>
    </div>
    <?php if ($entriesAsc !== [] && $errors === []): ?>
    <button class="btn btn-warning fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#transformationEntryForm">
        <i class="fas fa-plus me-1"></i>Přidat záznam
    </button>
    <?php endif; ?>
</div>

<?php if ($errors !== []): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($entriesAsc !== []): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-muted">Záznamů v časové ose</div>
            <div class="fs-3 fw-bold"><?= count($entriesAsc) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-muted">Sledované období</div>
            <div class="fs-3 fw-bold"><?= $daysTracked ?> <span class="fs-6 fw-normal text-muted">dní</span></div>
        </div></div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="small text-muted">Poslední záznam</div>
            <div class="fs-3 fw-bold"><?= h(date('j. n. Y', strtotime($entriesAsc[count($entriesAsc) - 1]['measured_on']))) ?></div>
        </div></div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4" id="entryForm">
    <div class="card-header bg-white border-0 p-0">
        <button class="btn w-100 text-start p-3 fw-semibold d-flex justify-content-between align-items-center"
                type="button" data-bs-toggle="collapse" data-bs-target="#transformationEntryForm"
                aria-expanded="<?= $errors !== [] ? 'true' : 'false' ?>">
            <span><i class="fas fa-camera me-2 text-primary"></i>Přidat fotografii a měření</span>
            <i class="fas fa-chevron-down"></i>
        </button>
    </div>
    <div id="transformationEntryForm" class="collapse<?= $errors !== [] ? ' show' : '' ?>">
        <div class="card-body p-2 p-md-3">
            <p class="small text-muted mb-3">Nahrajte postupně 4 fotografie. Každá se uloží ihned po výběru, nic se neztratí. Datum je povinné, váha a obvody jsou dobrovolné. Věk se vypočítá z data narození ve vašem profilu.</p>
            <form method="post" id="transformationForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="entry_id" id="draftEntryId" value="<?= (int)$draftEntryId ?>">
                <div class="row g-2">
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold" for="measuredOn">Datum fotografií *</label>
                        <input class="form-control form-control-sm" type="date" id="measuredOn" name="measured_on"
                               value="<?= h($formValues['measured_on']) ?>" max="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold" for="ageYears">Věk v den záznamu</label>
                        <input class="form-control form-control-sm" type="text" id="ageYears" value="" readonly
                               data-birth-date="<?= h($birthDateValue) ?>" placeholder="<?= $birthDateValue !== '' ? 'Doplní se automaticky' : 'Doplňte datum narození v profilu' ?>">
                    </div>

                    <div class="col-12 mt-2">
                        <h6 class="fw-bold mb-0">Fotografie *</h6>
                        <div class="form-text">Na mobilu můžete rovnou vyfotit nebo vybrat z galerie. JPG, PNG nebo WebP.</div>
                    </div>
                    <?php foreach ($photoPositions as $position => $slot): ?>
                    <div class="col-6 col-md-3">
                        <div class="tp-slot border rounded p-2 text-center h-100" data-position="<?= $position ?>">
                            <div class="small fw-semibold mb-1"><?= $position ?>. <?= h($slot['label']) ?></div>
                            <div class="tp-preview bg-light rounded d-flex align-items-center justify-content-center mb-2" style="height:9rem;overflow:hidden">
                                <?php if (isset($draftPhotos[$position])): ?>
                                <img src="<?= h($photoUrl($draftEntryId, $position, $draftPhotos)) ?>" alt="<?= h($slot['label']) ?>" style="max-width:100%;max-height:100%;object-fit:contain">
                                <?php else: ?>
                                <i class="fas fa-image fa-2x text-muted"></i>
                                <?php endif; ?>
                            </div>
                            <div class="tp-status small mb-1 <?= isset($draftPhotos[$position]) ? 'text-success' : 'text-muted' ?>"><?= isset($draftPhotos[$position]) ? 'Uloženo' : 'Zatím nenahráno' ?></div>
                            <div class="d-flex gap-1 justify-content-center flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-primary tp-camera d-md-none"><i class="fas fa-camera me-1"></i>Vyfotit</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary tp-pick"><i class="fas fa-folder-open me-1"></i><span class="d-md-none">Galerie</span><span class="d-none d-md-inline">Vybrat soubor</span></button>
                            </div>
                            <input type="file" class="d-none tp-input" accept="image/jpeg,image/png,image/webp">
                            <input type="file" class="d-none tp-input-camera" accept="image/*" capture="environment">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="col-12"><div id="tpMessage" class="small text-danger" aria-live="polite"></div></div>

                    <div class="col-12 mt-2">
                        <h6 class="fw-bold mb-0">Základní údaje</h6>
                        <div class="form-text">Šedé číslo v poli je poslední změřená hodnota pouze jako nápověda. Pole zůstává prázdné a údaj se uloží jen po vašem zadání.</div>
                    </div>
                    <?php foreach (array_slice($metricFields, 0, 2, true) as $field => $meta): ?>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="<?= h($field) ?>"><?= h($meta['label']) ?> (<?= h($meta['unit']) ?>)</label>
                        <input class="form-control form-control-sm transformation-last-value" type="number" inputmode="decimal" list="<?= $field === 'weight_kg' ? 'transformationWeightOptions' : 'transformationHeightOptions' ?>"
                               id="<?= h($field) ?>" name="<?= h($field) ?>"
                               min="0.1" max="<?= h((string)$meta['max']) ?>" step="<?= h($meta['step']) ?>"
                               placeholder="<?= $lastMeasuredValues[$field] !== null ? h(number_format($lastMeasuredValues[$field], 1, ',', ' ')) : '' ?>"
                               value="<?= h($formValues[$field]) ?>">
                    </div>
                    <?php endforeach; ?>

                    <div class="col-12 mt-2">
                        <h6 class="fw-bold mb-0">Tělesné míry</h6>
                        <div class="form-text">Šedé číslo je poslední změřená hodnota pouze jako nápověda; pole zůstává prázdné, dokud údaj sami nezadáte.</div>
                    </div>
                    <?php foreach (array_slice($metricFields, 2, 9, true) as $field => $meta): ?>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="<?= h($field) ?>"><?= h($meta['label']) ?> (<?= h($meta['unit']) ?>)</label>
                        <input class="form-control form-control-sm transformation-last-value" type="number" inputmode="decimal"
                               list="transformationSizeOptions<?= (int)$meta['max'] ?>"
                               id="<?= h($field) ?>" name="<?= h($field) ?>"
                               min="0.1" max="<?= h((string)$meta['max']) ?>" step="<?= h($meta['step']) ?>"
                               placeholder="<?= $lastMeasuredValues[$field] !== null ? h(number_format($lastMeasuredValues[$field], 1, ',', ' ')) : '' ?>"
                               value="<?= h($formValues[$field]) ?>">
                        <?php if (!empty($meta['help'])): ?><details class="form-text"><summary>Jak měřit</summary><?= h($meta['help']) ?></details><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="col-6 col-md-4">
                        <?php $meta = $metricFields['body_fat_percent']; ?>
                        <label class="form-label" for="body_fat_percent"><?= h($meta['label']) ?> (<?= h($meta['unit']) ?>)</label>
                        <input class="form-control form-control-sm transformation-last-value" type="number" inputmode="decimal" list="transformationBodyFatOptions"
                               id="body_fat_percent" name="body_fat_percent"
                               min="0" max="<?= h((string)$meta['max']) ?>" step="<?= h($meta['step']) ?>"
                               placeholder="<?= $lastMeasuredValues['body_fat_percent'] !== null ? h(number_format($lastMeasuredValues['body_fat_percent'], 1, ',', ' ')) : '' ?>"
                               value="<?= h($formValues['body_fat_percent']) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="transformationNote">Poznámka</label>
                        <textarea class="form-control form-control-sm" id="transformationNote" name="note" rows="2" maxlength="2000"
                                  placeholder="Například jak se cítíte nebo jaký byl váš cíl"><?= h($formValues['note']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-warning fw-semibold" type="submit">
                            <i class="fas fa-save me-1"></i>Uložit do časové osy
                        </button>
                    </div>
                </div>
                <datalist id="transformationWeightOptions">
                    <?php for ($value = 30; $value <= 200; $value++): ?><option value="<?= $value ?>"></option><?php endfor; ?>
                </datalist>
                <datalist id="transformationHeightOptions">
                    <?php for ($value = 100; $value <= 230; $value++): ?><option value="<?= $value ?>"></option><?php endfor; ?>
                </datalist>
                <?php foreach ($measurementSizeMaxima as $maximum): ?>
                <datalist id="transformationSizeOptions<?= $maximum ?>">
                    <?php for ($value = 10; $value <= $maximum; $value++): ?><option value="<?= $value ?>"></option><?php endfor; ?>
                </datalist>
                <?php endforeach; ?>
                <datalist id="transformationBodyFatOptions">
                    <?php for ($value = 0; $value <= 100; $value++): ?><option value="<?= $value ?>"></option><?php endfor; ?>
                </datalist>
            </form>
        </div>
    </div>
</div>

<?php if ($showChart): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-md-end gap-3 flex-wrap mb-3">
            <div>
                <h5 class="fw-bold mb-1"><i class="fas fa-chart-area me-2 text-primary"></i>Vývoj měření</h5>
                <div class="small text-muted">Vyberte údaj, jehož vývoj chcete zobrazit.</div>
            </div>
            <div class="transformation-chart-select">
                <label for="transformationMetricSelect" class="form-label small fw-semibold mb-1">Zobrazit</label>
                <select id="transformationMetricSelect" class="form-select form-select-sm">
                    <?php foreach ($metricFields as $field => $meta): ?>
                    <option value="<?= h($field) ?>" <?= $field === $chartDefaultField ? 'selected' : '' ?>>
                        <?= h($meta['label']) ?> (<?= h($meta['unit']) ?>)<?= $chartPointCounts[$field] < 2 ? ' · zatím bez trendu' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div id="transformationChartHint" class="small text-muted mb-2" aria-live="polite"></div>
        <div class="transformation-chart-canvas"><canvas id="transformationChart" aria-label="Graf vývoje vybraného tělesného měření"></canvas></div>
    </div>
</div>
<script>
(() => {
    const labels = <?= json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const measurements = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const metricOptions = <?= json_encode(
        array_map(
            static fn(array $meta): array => ['label' => $meta['label'], 'unit' => $meta['unit']],
            $metricFields
        ),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;
    const pointCounts = <?= json_encode($chartPointCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const select = document.getElementById('transformationMetricSelect');
    const hint = document.getElementById('transformationChartHint');
    const canvas = document.getElementById('transformationChart');
    if (!select || !hint || !canvas || typeof Chart === 'undefined') return;

    const chart = new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: '',
                data: [],
                borderColor: '#2878c8',
                backgroundColor: 'rgba(40, 120, 200, .14)',
                pointBackgroundColor: '#2878c8',
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: .25,
                spanGaps: false,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: {
                    beginAtZero: false,
                    title: { display: true, text: '' },
                },
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label(context) {
                            return context.parsed.y === null
                                ? 'Bez měření'
                                : context.dataset.label + ': ' + context.parsed.y.toLocaleString('cs-CZ') + ' ' + metricOptions[select.value].unit;
                        },
                    },
                },
            },
        },
    });

    const updateChart = () => {
        const key = select.value;
        const metric = metricOptions[key];
        const count = pointCounts[key] || 0;
        chart.data.datasets[0].label = metric.label;
        chart.data.datasets[0].data = measurements[key] || [];
        chart.options.scales.y.title.text = metric.label + ' (' + metric.unit + ')';
        chart.update();

        hint.textContent = count < 2
            ? 'Pro zobrazení vývoje ' + metric.label.toLowerCase() + ' přidejte tento údaj alespoň do dvou záznamů.'
            : 'Zobrazeno ' + count + ' měření z ' + labels.length + ' záznamů.';
    };

    select.addEventListener('change', updateChart);
    updateChart();
})();
</script>
<?php endif; ?>

<?php if ($diagramEntry): ?>
<div class="mb-4">
    <button class="btn btn-outline-primary fw-semibold d-flex align-items-center gap-2"
            type="button" data-bs-toggle="collapse" data-bs-target="#transformationMeasurementGuide"
            aria-expanded="false" aria-controls="transformationMeasurementGuide">
        <i class="fas fa-ruler-combined"></i>
        <span>Jak správně měřit</span>
        <i class="fas fa-chevron-down small ms-1"></i>
    </button>
    <div class="collapse mt-3" id="transformationMeasurementGuide">
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div>
                <h5 class="fw-bold mb-1"><i class="fas fa-person me-2 text-primary"></i>Nákres tělesných měr</h5>
                <div class="small text-muted">Orientační pomůcka k místům měření</div>
            </div>
            <span class="badge rounded-pill bg-light text-secondary border">Neutrální postava s vyznačenými místy měření</span>
        </div>
        <div class="row align-items-center g-4 transformation-measurement-guide">
            <div class="col-12 col-md-5 col-lg-4">
                <img src="<?= h(BASE_URL . '/assets/transformation_measurement_guide.png') ?>"
                     alt="Neutrální postava s barevně vyznačeným měřením hrudníku, pasu, boků, bicepsů, stehen a lýtek"
                     class="transformation-diagram" width="768" height="1152" loading="lazy">
            </div>
            <div class="col-12 col-md-7 col-lg-8">
                <div class="row g-2">
                    <?php foreach ($diagramItems as $index => $item): ?>
                    <div class="col-12 col-sm-6">
                        <div class="d-flex align-items-center gap-3 rounded bg-light p-3 h-100">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle text-white fw-bold flex-shrink-0"
                                  style="width:32px;height:32px;background:<?= h($item['color']) ?>"><?= $index + 1 ?></span>
                            <div class="min-w-0">
                                <div class="small text-muted"><?= h($item['label']) ?></div>
                                <div class="fw-bold"><?= h($formatDiagramValues($diagramEntry, $item['fields'])) ?></div>
                                <div class="small text-muted mt-1"><?= h($item['help']) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="small text-muted mt-3 mb-0">Obrázek ukazuje orientační místa měření. Skutečné obvody měřte krejčovským metrem podle uvedených pokynů.</p>
            </div>
        </div>
    </div>
</div>
</div>
</div>
<?php endif; ?>

<ul class="nav nav-pills gap-2 mb-3" id="transformationViews" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="transformation-timeline-tab" data-bs-toggle="tab"
                data-bs-target="#transformation-timeline-pane" type="button" role="tab"
                aria-controls="transformation-timeline-pane" aria-selected="true">
            <i class="fas fa-timeline me-1"></i>Časová osa
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="transformation-story-tab" data-bs-toggle="tab"
                data-bs-target="#transformation-story-pane" type="button" role="tab"
                aria-controls="transformation-story-pane" aria-selected="false">
            <i class="fas fa-film me-1"></i>Storyboard
        </button>
    </li>
</ul>
<div class="tab-content">
    <section class="tab-pane fade show active" id="transformation-timeline-pane" role="tabpanel"
             aria-labelledby="transformation-timeline-tab" tabindex="0">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold mb-0"><i class="fas fa-timeline me-2 text-warning"></i>Časová osa</h4>
            <span class="badge rounded-pill bg-secondary"><?= count($entries) ?> <?= count($entries) === 1 ? 'záznam' : 'záznamů' ?></span>
        </div>
        <p class="small text-muted mb-3">U nejnovějšího záznamu najdete změnu každé vyplněné hodnoty od prvního i předchozího měření.</p>

        <?php if ($entries === []): ?>
        <div class="card border-0 shadow-sm text-center py-5">
            <div class="card-body">
                <i class="fas fa-camera-retro fa-3x text-warning mb-3"></i>
                <h5 class="fw-bold">Tady začíná vaše proměna</h5>
                <p class="text-muted mb-0">Přidejte první fotografii a volitelná měření. Další záznamy se zobrazí jako časová osa.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="d-flex flex-column gap-3" id="transformationTimeline">
            <?php foreach ($entries as $entry): ?>
            <?php
            $isInitialEntry = (int)$entry['id'] === (int)$initialEntry['id'];
            $isLatestEntry = (int)$entry['id'] === (int)$entriesAsc[count($entriesAsc) - 1]['id'];
            $entryContentId = 'transformationEntryContent' . (int)$entry['id'];
            ?>
            <article class="card border-0 shadow-sm overflow-hidden">
                <div class="card-header bg-white border-0 p-3">
                    <div class="d-flex justify-content-between align-items-center gap-3">
                        <div class="min-w-0">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h5 class="fw-bold mb-0"><?= h(date('j. n. Y', strtotime($entry['measured_on']))) ?></h5>
                                <?php if ($isInitialEntry): ?><span class="badge bg-warning text-dark">Výchozí záznam</span><?php endif; ?>
                                <?php if ($isLatestEntry): ?><span class="badge bg-primary">Nejnovější</span><?php endif; ?>
                            </div>
                            <?php if ($entry['age_years'] !== null): ?>
                            <div class="small text-muted mt-1">Věk: <?= (int)$entry['age_years'] ?> let</div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#<?= h($entryContentId) ?>"
                                    aria-expanded="<?= $isLatestEntry ? 'true' : 'false' ?>"
                                    aria-controls="<?= h($entryContentId) ?>">
                                <span><?= $isLatestEntry ? 'Sbalit' : 'Zobrazit' ?></span>
                                <i class="fas fa-chevron-<?= $isLatestEntry ? 'up' : 'down' ?> ms-1" aria-hidden="true"></i>
                            </button>
                            <form method="post" onsubmit="return confirm('Opravdu smazat tento záznam i fotografii?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Smazat záznam">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="collapse transformation-entry-collapse<?= $isLatestEntry ? ' show' : '' ?>" id="<?= h($entryContentId) ?>">
                    <div class="row g-0">
                        <div class="col-md-5 col-lg-4 bg-light p-2">
                            <div class="row g-2 h-100 transformation-photo-grid">
                                <?php foreach ($photoPositions as $position => $slot): ?>
                                <?php $hasPhoto = isset($entryPhotos[(int)$entry['id']][$position]) || ($position === 1 && trim((string)$entry['photo_path']) !== '' && !isset($entryPhotos[(int)$entry['id']])); ?>
                                <?php $slotUrl = $photoUrl((int)$entry['id'], $position, $entryPhotos[(int)$entry['id']] ?? []); ?>
                                <?php $slotAlt = $slot['label'] . ' – ' . date('j. n. Y', strtotime($entry['measured_on'])); ?>
                                <div class="col-6">
                                    <div class="tp-slot transformation-photo-slot text-center" data-position="<?= $position ?>" data-entry-id="<?= (int)$entry['id'] ?>" data-reload="1">
                                        <?php if ($hasPhoto): ?>
                                        <button type="button" class="btn p-0 border-0 w-100 bg-white transformation-photo-button"
                                                data-bs-toggle="modal" data-bs-target="#transformationPhotoModal"
                                                data-photo-src="<?= h($slotUrl) ?>" data-photo-alt="<?= h($slotAlt) ?>"
                                                aria-label="Otevřít fotografii v plné velikosti" style="cursor:zoom-in">
                                            <img src="<?= h($slotUrl) ?>" alt="<?= h($slotAlt) ?>" class="w-100 rounded" loading="lazy">
                                        </button>
                                        <div class="small text-muted pt-1"><?= h($slot['label']) ?></div>
                                        <?php else: ?>
                                        <div class="border rounded bg-white d-flex flex-column align-items-center justify-content-center gap-1 p-2 flex-grow-1">
                                            <div class="small text-muted"><?= h($slot['label']) ?></div>
                                            <button type="button" class="btn btn-sm btn-outline-primary tp-camera d-md-none"><i class="fas fa-camera me-1"></i>Vyfotit</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary tp-pick"><i class="fas fa-plus me-1"></i>Přidat</button>
                                            <div class="tp-status small text-muted"></div>
                                            <input type="file" class="d-none tp-input" accept="image/jpeg,image/png,image/webp">
                                            <input type="file" class="d-none tp-input-camera" accept="image/*" capture="environment">
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-7 col-lg-8">
                            <div class="card-body p-3 p-lg-4">
                                <div class="row g-2">
                                    <?php foreach ($metricFields as $field => $meta): ?>
                                    <?php if ($entry[$field] === null) continue; ?>
                                    <?php
                                    $value = (float)$entry[$field];
                                    $trendDelta = $isLatestEntry && $latestMeasuredValues[$field] !== null
                                        ? $value - (float)$latestMeasuredValues[$field]
                                        : null;
                                    $comparisonValues = [];
                                    if ($isLatestEntry) {
                                        foreach ([
                                            'first' => ['Od prvního', $metricBaselines[$field]],
                                            'previous' => ['Od předchozího', $latestMeasuredValues[$field]],
                                        ] as $comparisonType => [$comparisonLabel, $comparisonValue]) {
                                            if ($comparisonValue === null) {
                                                continue;
                                            }
                                            $delta = $value - $comparisonValue;
                                            $comparisonValues[] = [
                                                'type' => $comparisonType,
                                                'label' => $comparisonLabel,
                                                'value' => ($delta > 0 ? '+' : '') . number_format($delta, 1, ',', ' ') . ' ' . $meta['unit'],
                                            ];
                                        }
                                    }
                                    ?>
                                    <div class="col-6 col-sm-4 col-lg-3">
                                        <div class="rounded bg-light p-2 h-100">
                                            <div class="small text-muted d-flex align-items-center gap-1">
                                                <span><?= h($meta['label']) ?></span>
                                                <?php if ($trendDelta !== null && abs($trendDelta) > 0.000001): ?>
                                                <?php $trendText = $trendDelta > 0 ? 'vzrostla' : 'klesla'; ?>
                                                <span class="text-primary" title="<?= h('Oproti předchozímu měření hodnota ' . $trendText) ?>">
                                                    <i class="fas fa-arrow-<?= $trendDelta > 0 ? 'up' : 'down' ?>" aria-hidden="true"></i>
                                                    <span class="visually-hidden">Oproti předchozímu měření hodnota <?= h($trendText) ?></span>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="fw-bold"><?= h(number_format($value, 1, ',', ' ')) ?> <?= h($meta['unit']) ?></div>
                                            <?php if ($comparisonValues !== []): ?>
                                            <div class="transformation-comparisons">
                                                <?php foreach ($comparisonValues as $comparison): ?>
                                                <div class="transformation-comparison transformation-comparison--<?= h($comparison['type']) ?>">
                                                    <span class="transformation-comparison-label"><?= h($comparison['label']) ?></span>
                                                    <strong><?= h($comparison['value']) ?></strong>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (trim((string)$entry['note']) !== ''): ?>
                                <div class="mt-3 text-break" style="white-space:pre-wrap"><?= h((string)$entry['note']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <section class="tab-pane fade" id="transformation-story-pane" role="tabpanel"
             aria-labelledby="transformation-story-tab" tabindex="0">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                        <h4 class="fw-bold mb-1"><i class="fas fa-film me-2 text-primary"></i>Váš vývoj v obrazech</h4>
                        <p class="small text-muted mb-0">Procházejte fotografie stejného úhlu v čase, nebo spusťte přehrávání.</p>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold mb-1" for="transformationStoryPosition">Pohled</label>
                        <select class="form-select form-select-sm" id="transformationStoryPosition">
                            <?php foreach ($photoPositions as $position => $slot): ?>
                            <option value="<?= $position ?>" <?= $position === $storyDefaultPosition ? 'selected' : '' ?>><?= h($slot['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="transformationStoryEmpty" class="alert alert-light border d-none mb-0" role="status">
                    Pro tento úhel zatím nejsou žádné fotografie.
                </div>
                <div id="transformationStoryContent">
                    <div class="position-relative overflow-hidden rounded">
                        <img id="transformationStoryPhoto" class="transformation-story-photo" src="" alt="">
                    </div>
                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mt-3">
                        <div>
                            <div class="h5 fw-bold mb-0" id="transformationStoryDate" aria-live="polite"></div>
                            <div class="small text-muted" id="transformationStoryAge"></div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="transformationStoryPrevious" aria-label="Předchozí záznam">
                                <i class="fas fa-backward-step"></i>
                            </button>
                            <button class="btn btn-primary btn-sm" type="button" id="transformationStoryPlay" aria-pressed="false">
                                <i class="fas fa-play me-1"></i>Přehrát
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="transformationStoryNext" aria-label="Další záznam">
                                <i class="fas fa-forward-step"></i>
                            </button>
                        </div>
                    </div>
                    <div class="row g-2 mt-2" aria-live="polite">
                        <div class="col-4" id="transformationStoryWeightWrap"><div class="rounded bg-light p-2"><div class="small text-muted">Váha</div><strong id="transformationStoryWeight"></strong></div></div>
                        <div class="col-4" id="transformationStoryWaistWrap"><div class="rounded bg-light p-2"><div class="small text-muted">Pas</div><strong id="transformationStoryWaist"></strong></div></div>
                        <div class="col-4" id="transformationStoryBodyFatWrap"><div class="rounded bg-light p-2"><div class="small text-muted">Tělesný tuk</div><strong id="transformationStoryBodyFat"></strong></div></div>
                    </div>
                    <div class="small text-muted mt-2 text-end" id="transformationStoryCounter" aria-live="polite"></div>
                    <div class="transformation-story-progress" id="transformationStoryProgress" role="group" aria-label="Přejít na záznam podle data"></div>
                </div>
            </div>
        </div>
    </section>
</div>
<script>
(() => {
    document.querySelectorAll('.transformation-entry-collapse').forEach(collapse => {
        const toggle = document.querySelector('[data-bs-target="#' + collapse.id + '"]');
        if (!toggle) return;
        const label = toggle.querySelector('span');
        const icon = toggle.querySelector('i');
        collapse.addEventListener('shown.bs.collapse', () => {
            if (label) label.textContent = 'Sbalit';
            if (icon) icon.classList.replace('fa-chevron-down', 'fa-chevron-up');
        });
        collapse.addEventListener('hidden.bs.collapse', () => {
            if (label) label.textContent = 'Zobrazit';
            if (icon) icon.classList.replace('fa-chevron-up', 'fa-chevron-down');
        });
    });

    const records = <?= json_encode($storyEntries, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const positionSelect = document.getElementById('transformationStoryPosition');
    const content = document.getElementById('transformationStoryContent');
    const empty = document.getElementById('transformationStoryEmpty');
    const image = document.getElementById('transformationStoryPhoto');
    const progress = document.getElementById('transformationStoryProgress');
    const playButton = document.getElementById('transformationStoryPlay');
    const previousButton = document.getElementById('transformationStoryPrevious');
    const nextButton = document.getElementById('transformationStoryNext');
    if (!positionSelect || !content || !empty || !image || !progress || !playButton || !previousButton || !nextButton) return;

    let slides = [];
    let currentIndex = 0;
    let timer = null;
    const fields = [
        ['weight', 'transformationStoryWeight', 'transformationStoryWeightWrap'],
        ['waist', 'transformationStoryWaist', 'transformationStoryWaistWrap'],
        ['bodyFat', 'transformationStoryBodyFat', 'transformationStoryBodyFatWrap'],
    ];

    const stopPlayback = () => {
        if (timer !== null) window.clearInterval(timer);
        timer = null;
        playButton.innerHTML = '<i class="fas fa-play me-1"></i>Přehrát';
        playButton.setAttribute('aria-pressed', 'false');
    };

    const renderSlide = index => {
        if (slides.length === 0) return;
        currentIndex = (index + slides.length) % slides.length;
        const slide = slides[currentIndex];
        image.classList.add('is-changing');
        image.onload = () => image.classList.remove('is-changing');
        image.src = slide.photo;
        image.alt = 'Fotografie proměny, ' + slide.date;
        document.getElementById('transformationStoryDate').textContent = slide.date;
        document.getElementById('transformationStoryAge').textContent = slide.age ? 'Věk: ' + slide.age : '';
        fields.forEach(([key, valueId, wrapId]) => {
            const value = slide[key];
            document.getElementById(valueId).textContent = value || '';
            document.getElementById(wrapId).classList.toggle('d-none', !value);
        });
        document.getElementById('transformationStoryCounter').textContent =
            (currentIndex + 1) + ' / ' + slides.length + ' záznamů';
        Array.from(progress.children).forEach((button, buttonIndex) => {
            if (buttonIndex === currentIndex) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
        });
    };

    const loadPosition = () => {
        stopPlayback();
        const position = positionSelect.value;
        slides = records
            .filter(record => record.photos[position])
            .map(record => ({ ...record, photo: record.photos[position] }));
        progress.replaceChildren();
        slides.forEach((slide, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = slide.date;
            button.setAttribute('aria-label', 'Zobrazit záznam z ' + slide.date);
            button.addEventListener('click', () => renderSlide(index));
            progress.appendChild(button);
        });
        const hasSlides = slides.length > 0;
        content.classList.toggle('d-none', !hasSlides);
        empty.classList.toggle('d-none', hasSlides);
        previousButton.disabled = slides.length < 2;
        nextButton.disabled = slides.length < 2;
        playButton.disabled = slides.length < 2;
        if (hasSlides) renderSlide(0);
    };

    positionSelect.addEventListener('change', loadPosition);
    previousButton.addEventListener('click', () => renderSlide(currentIndex - 1));
    nextButton.addEventListener('click', () => renderSlide(currentIndex + 1));
    playButton.addEventListener('click', () => {
        if (timer !== null) {
            stopPlayback();
            return;
        }
        playButton.innerHTML = '<i class="fas fa-pause me-1"></i>Pozastavit';
        playButton.setAttribute('aria-pressed', 'true');
        timer = window.setInterval(() => renderSlide(currentIndex + 1), 2500);
    });
    loadPosition();
})();
</script>

<div class="modal fade" id="transformationPhotoModal" tabindex="-1" aria-labelledby="transformationPhotoModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0">
                <h5 class="modal-title text-white" id="transformationPhotoModalTitle">Fotografie proměny</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body d-flex align-items-center justify-content-center p-2 p-md-3">
                <img id="transformationPhotoModalImage" src="" alt=""
                     style="display:block;max-width:100%;max-height:calc(100vh - 80px);object-fit:contain">
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const photoModal = document.getElementById('transformationPhotoModal');
    if (photoModal) {
        photoModal.addEventListener('show.bs.modal', event => {
            const trigger = event.relatedTarget;
            const image = document.getElementById('transformationPhotoModalImage');
            if (!trigger || !image) return;
            image.src = trigger.dataset.photoSrc || '';
            image.alt = trigger.dataset.photoAlt || '';
            document.getElementById('transformationPhotoModalTitle').textContent =
                trigger.dataset.photoAlt || 'Fotografie proměny';
        });
        photoModal.addEventListener('hidden.bs.modal', () => {
            const image = document.getElementById('transformationPhotoModalImage');
            if (image) image.removeAttribute('src');
        });
    }

    const form = document.getElementById('transformationForm');
    const draftInput = document.getElementById('draftEntryId');
    const messageBox = document.getElementById('tpMessage');
    const csrfToken = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    const shrinkImage = async file => {
        if (!file.type.startsWith('image/') || typeof createImageBitmap !== 'function') return file;
        try {
            const bitmap = await createImageBitmap(file);
            const scale = Math.min(1, 2400 / Math.max(bitmap.width, bitmap.height));
            if (scale === 1 && file.size <= 8 * 1024 * 1024 && /^image\/(jpeg|png|webp)$/.test(file.type)) return file;
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
            return blob || file;
        } catch (e) {
            return file;
        }
    };

    const uploadPhoto = async (slot, file) => {
        const status = slot.querySelector('.tp-status');
        const setStatus = (text, cls) => {
            if (!status) return;
            status.textContent = text;
            status.className = 'tp-status small mb-1 ' + cls;
        };
        const entryId = slot.dataset.entryId || (draftInput ? draftInput.value : '');
        const dateInputEl = document.getElementById('measuredOn');
        if (messageBox) messageBox.textContent = '';
        if (!entryId && (!dateInputEl || !dateInputEl.value)) {
            if (messageBox) messageBox.textContent = 'Nejprve zadejte datum fotografií.';
            return;
        }
        setStatus('Ukládám…', 'text-muted');
        slot.querySelectorAll('button').forEach(b => b.disabled = true);
        try {
            const prepared = await shrinkImage(file);
            const data = new FormData();
            data.append('action', 'upload_photo');
            data.append('csrf_token', csrfToken);
            data.append('position', slot.dataset.position);
            if (entryId && entryId !== '0') data.append('entry_id', entryId);
            if (dateInputEl) data.append('measured_on', dateInputEl.value);
            data.append('photo', prepared, 'photo.' + (prepared.type === 'image/png' ? 'png' : prepared.type === 'image/webp' ? 'webp' : 'jpg'));
            const response = await fetch(window.location.pathname, { method: 'POST', body: data, credentials: 'same-origin' });
            const result = await response.json().catch(() => ({ ok: false, error: 'Neočekávaná odpověď serveru.' }));
            if (!result.ok) throw new Error(result.error || 'Fotografii se nepodařilo uložit.');
            if (slot.dataset.reload) {
                window.location.reload();
                return;
            }
            if (draftInput) draftInput.value = result.entry_id;
            const preview = slot.querySelector('.tp-preview');
            if (preview) {
                preview.innerHTML = '';
                const img = document.createElement('img');
                img.src = result.url;
                img.alt = '';
                img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain';
                preview.appendChild(img);
            }
            setStatus('Uloženo', 'text-success');
        } catch (error) {
            setStatus('Nepodařilo se', 'text-danger');
            if (messageBox) messageBox.textContent = error.message;
        } finally {
            slot.querySelectorAll('button').forEach(b => b.disabled = false);
        }
    };

    document.querySelectorAll('.tp-slot').forEach(slot => {
        const pick = slot.querySelector('.tp-pick');
        const camera = slot.querySelector('.tp-camera');
        const input = slot.querySelector('.tp-input');
        const cameraInput = slot.querySelector('.tp-input-camera');
        if (pick && input) pick.addEventListener('click', () => input.click());
        if (camera && cameraInput) camera.addEventListener('click', () => cameraInput.click());
        [input, cameraInput].forEach(el => {
            if (!el) return;
            el.addEventListener('change', () => {
                if (el.files && el.files[0]) uploadPhoto(slot, el.files[0]);
                el.value = '';
            });
        });
    });

    if (form) {
        form.addEventListener('submit', event => {
            if (!draftInput || !draftInput.value || draftInput.value === '0') {
                event.preventDefault();
                if (messageBox) messageBox.textContent = 'Nejprve přidejte alespoň jednu fotografii.';
            }
        });
    }

    const dateInput = document.getElementById('measuredOn');
    const ageInput = document.getElementById('ageYears');
    if (!dateInput || !ageInput) return;

    const updateAge = () => {
        const birthDate = ageInput.dataset.birthDate || '';
        const measuredOn = dateInput.value || '';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(birthDate) || !/^\d{4}-\d{2}-\d{2}$/.test(measuredOn)) {
            ageInput.value = '';
            return;
        }
        const [birthYear, birthMonth, birthDay] = birthDate.split('-').map(Number);
        const [year, month, day] = measuredOn.split('-').map(Number);
        let age = year - birthYear;
        if (month < birthMonth || (month === birthMonth && day < birthDay)) age--;
        ageInput.value = age >= 0 ? age + ' let' : '';
    };

    dateInput.addEventListener('change', updateAge);
    updateAge();
})();
</script>

<?php renderAthleteFooter(); ?>
