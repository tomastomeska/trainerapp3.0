<?php
// video_upload.php – nahrat videa do videosekce
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo = getDB();

$prefillAthleteId = intParam($_GET, 'athlete_id');
$prefillFolderId = intParam($_GET, 'folder_id');

$athletesStmt = $pdo->prepare('SELECT id, first_name, last_name FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name');
$athletesStmt->execute([$coachId]);
$athletes = $athletesStmt->fetchAll();
$athleteIds = array_map('intval', array_column($athletes, 'id'));
if ($prefillAthleteId > 0 && !in_array($prefillAthleteId, $athleteIds, true)) {
    $prefillAthleteId = 0;
}

$customFoldersStmt = $pdo->prepare("SELECT id, name FROM video_folders WHERE coach_id = ? AND folder_type = 'custom' ORDER BY name ASC");
$customFoldersStmt->execute([$coachId]);
$customFolders = $customFoldersStmt->fetchAll();
$customFolderIds = array_map('intval', array_column($customFolders, 'id'));
if ($prefillFolderId > 0 && !in_array($prefillFolderId, $customFolderIds, true)) {
    $prefillFolderId = 0;
}

$errors = [];
$maxVideoSizeMb = 1024;
$maxVideoSizeBytes = $maxVideoSizeMb * 1024 * 1024;
$compressionMinSizeBytes = 25 * 1024 * 1024;

function videoUploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'Soubor prekrocil limit upload_max_filesize na serveru (php.ini).',
        UPLOAD_ERR_FORM_SIZE => 'Soubor prekrocil maximalni velikost povolenou formularen.',
        UPLOAD_ERR_PARTIAL => 'Soubor byl nahran jen castecne.',
        UPLOAD_ERR_NO_FILE => 'Nebyl vybran zadny soubor.',
        UPLOAD_ERR_NO_TMP_DIR => 'Na serveru chybi docasna slozka pro upload.',
        UPLOAD_ERR_CANT_WRITE => 'Server nema pravo zapisovat nahrany soubor na disk.',
        UPLOAD_ERR_EXTENSION => 'Upload byl zastaven rozsireni PHP.',
        default => 'Neznama chyba pri nahravani souboru.',
    };
}

function iniSizeToBytes(string $value): int
{
    $raw = trim($value);
    if ($raw === '') {
        return 0;
    }

    $unit = strtolower(substr($raw, -1));
    $number = (float)$raw;

    return match ($unit) {
        'g' => (int)round($number * 1024 * 1024 * 1024),
        'm' => (int)round($number * 1024 * 1024),
        'k' => (int)round($number * 1024),
        default => (int)round($number),
    };
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024 * 1024) {
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

function detectFfmpegBinary(): ?string
{
    static $resolved = false;
    static $binary = null;

    if ($resolved) {
        return $binary;
    }
    $resolved = true;

    $envOverride = trim((string)(getenv('FFMPEG_BINARY') ?: ''));
    if ($envOverride !== '') {
        $normalized = str_replace('\\', '/', $envOverride);
        if (is_file($normalized)) {
            $binary = $normalized;
            return $binary;
        }
    }

    $winUser = getenv('USERNAME') ?: get_current_user();
    $candidates = [
        // Winget alias cesta
        'C:/Users/' . $winUser . '/AppData/Local/Microsoft/WinGet/Links/ffmpeg.exe',
        // Typicka winget instalace pro Gyan build
        'C:/Users/' . $winUser . '/AppData/Local/Microsoft/WinGet/Packages/Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe/ffmpeg-8.1.2-full_build/bin/ffmpeg.exe',
        // Caste systemove umisteni
        'C:/ffmpeg/bin/ffmpeg.exe',
        'C:/Program Files/ffmpeg/bin/ffmpeg.exe',
        'C:/Program Files (x86)/ffmpeg/bin/ffmpeg.exe',
    ];

    foreach ($candidates as $candidatePath) {
        $normalized = str_replace('\\', '/', $candidatePath);
        if (is_file($normalized)) {
            $binary = $normalized;
            return $binary;
        }
    }

    // Apache casto bezi pod jinym uzivatelem (napr. SYSTEM), proto prohledame vsechny user WinGet slozky.
    $globPatterns = [
        'C:/Users/*/AppData/Local/Microsoft/WinGet/Links/ffmpeg.exe',
        'C:/Users/*/AppData/Local/Microsoft/WinGet/Packages/Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe/ffmpeg-*/bin/ffmpeg.exe',
    ];
    foreach ($globPatterns as $pattern) {
        $matches = glob($pattern) ?: [];
        foreach ($matches as $match) {
            $normalized = str_replace('\\', '/', (string)$match);
            if (is_file($normalized)) {
                $binary = $normalized;
                return $binary;
            }
        }
    }

    if (!function_exists('exec')) {
        return null;
    }

    $output = [];
    $code = 1;
    @exec('where ffmpeg 2>NUL', $output, $code);
    if ($code === 0 && !empty($output[0])) {
        $candidate = trim((string)$output[0]);
        if ($candidate !== '' && is_file($candidate)) {
            $binary = $candidate;
        }
    }

    return $binary;
}

function tryCompressVideo(string $ffmpegBinary, string $uploadDir, string $sourceName): array
{
    $sourcePath = $uploadDir . $sourceName;
    if (!is_file($sourcePath)) {
        return ['ok' => false];
    }

    $sourceSize = filesize($sourcePath);
    if ($sourceSize === false || $sourceSize <= 0) {
        return ['ok' => false];
    }

    $base = pathinfo($sourceName, PATHINFO_FILENAME);
    $compressedName = $base . '_cmp.mp4';
    $compressedPath = $uploadDir . $compressedName;
    if (is_file($compressedPath)) {
        @unlink($compressedPath);
    }

    $cmd = escapeshellarg($ffmpegBinary)
        . ' -y -i ' . escapeshellarg($sourcePath)
        . ' -movflags +faststart -c:v libx264 -preset veryfast -crf 29 -c:a aac -b:a 128k '
        . escapeshellarg($compressedPath)
        . ' 2>&1';

    $output = [];
    $code = 1;
    @exec($cmd, $output, $code);

    if ($code !== 0 || !is_file($compressedPath)) {
        if (is_file($compressedPath)) {
            @unlink($compressedPath);
        }
        return ['ok' => false];
    }

    $compressedSize = filesize($compressedPath);
    if ($compressedSize === false || $compressedSize <= 0) {
        @unlink($compressedPath);
        return ['ok' => false];
    }

    // Kompresi ponechame jen pokud usetri aspon 5 % velikosti.
    if ($compressedSize >= (int)round($sourceSize * 0.95)) {
        @unlink($compressedPath);
        return ['ok' => false];
    }

    @unlink($sourcePath);
    return [
        'ok' => true,
        'file_name' => $compressedName,
        'file_size' => (int)$compressedSize,
        'mime_type' => 'video/mp4',
    ];
}

$phpUploadBytes = iniSizeToBytes((string)ini_get('upload_max_filesize'));
$phpPostBytes = iniSizeToBytes((string)ini_get('post_max_size'));
$serverEffectiveLimit = min($phpUploadBytes > 0 ? $phpUploadBytes : PHP_INT_MAX, $phpPostBytes > 0 ? $phpPostBytes : PHP_INT_MAX);
$ffmpegBinary = detectFfmpegBinary();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect(BASE_URL . '/video_upload.php');
    }

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($serverEffectiveLimit > 0 && $contentLength > $serverEffectiveLimit) {
        $errors[] = 'Pozadavek je vetsi nez limit serveru (' . formatBytes($serverEffectiveLimit) . '). Zvyste upload_max_filesize a post_max_size v php.ini.';
    }

    $description = trim($_POST['description'] ?? '');
    $visibility = in_array($_POST['visibility'] ?? '', ['private', 'all_athletes', 'specific_athletes'], true)
        ? $_POST['visibility']
        : 'private';
    $folderId = intParam($_POST, 'folder_id');

    $specificIds = array_values(array_intersect(
        array_map('intval', array_filter($_POST['specific_athletes'] ?? [])),
        $athleteIds
    ));

    if ($folderId > 0 && !in_array($folderId, $customFolderIds, true)) {
        $folderId = 0;
    }

    if ($visibility === 'specific_athletes' && empty($specificIds)) {
        $errors[] = 'Pro sdileni s vybranymi sportovci zvolte alespon jednoho sportovce.';
    }

    $allowed = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'];

    $uploadDir = __DIR__ . '/uploads/movie/coach_' . $coachId . '/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $errors[] = 'Nepodarilo se vytvorit slozku pro videa: ' . $uploadDir;
        }
    }
    if (empty($errors) && !is_writable($uploadDir)) {
        $errors[] = 'Slozka pro videa neni zapisovatelna: ' . $uploadDir;
    }

    $uploadedCount = 0;
    $files = $_FILES['files'] ?? [];

    if (!is_array($files['name'] ?? null)) {
        $files = [
            'name' => [$files['name'] ?? ''],
            'type' => [$files['type'] ?? ''],
            'tmp_name' => [$files['tmp_name'] ?? ''],
            'error' => [$files['error'] ?? UPLOAD_ERR_NO_FILE],
            'size' => [$files['size'] ?? 0],
        ];
    }

    if (empty($errors)) {
        foreach ($files['name'] as $i => $origName) {
            $uploadError = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_OK) {
                if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = h((string)($origName ?: 'Soubor')) . ': ' . videoUploadErrorMessage($uploadError);
                }
                continue;
            }

            if (!$origName) {
                continue;
            }

            $size = (int)($files['size'][$i] ?? 0);
            if ($size > $maxVideoSizeBytes) {
                $errors[] = h($origName) . ': max ' . $maxVideoSizeMb . ' MB.';
                continue;
            }

            $ext = strtolower(pathinfo((string)$origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                $errors[] = h($origName) . ': nepovoleny format .' . $ext . '.';
                continue;
            }

            $newName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $tmpFile = $files['tmp_name'][$i] ?? '';
            if (!is_string($tmpFile) || !move_uploaded_file($tmpFile, $uploadDir . $newName)) {
                $errors[] = h($origName) . ': nepodarilo se ulozit.';
                continue;
            }

            $mime = mime_content_type($uploadDir . $newName) ?: '';
            if (!str_starts_with((string)$mime, 'video/')) {
                @unlink($uploadDir . $newName);
                $errors[] = h($origName) . ': soubor neni rozpoznan jako video.';
                continue;
            }

            $storedName = $newName;
            $storedSize = (int)(filesize($uploadDir . $newName) ?: $size);
            $storedMime = (string)$mime;

            if ($ffmpegBinary !== null && $storedSize >= $compressionMinSizeBytes) {
                $compressed = tryCompressVideo($ffmpegBinary, $uploadDir, $newName);
                if (!empty($compressed['ok'])) {
                    $storedName = (string)$compressed['file_name'];
                    $storedSize = (int)$compressed['file_size'];
                    $storedMime = (string)$compressed['mime_type'];
                }
            }

            $ins = $pdo->prepare('INSERT INTO video_files (coach_id, folder_id, file_path, original_name, file_size, mime_type, description, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([
                $coachId,
                $folderId > 0 ? $folderId : null,
                $storedName,
                (string)$origName,
                $storedSize,
                $storedMime,
                $description !== '' ? $description : null,
                $visibility,
            ]);
            $newFileId = (int)$pdo->lastInsertId();

            if ($visibility === 'specific_athletes' && !empty($specificIds)) {
                $insVis = $pdo->prepare('INSERT IGNORE INTO video_file_athletes (file_id, athlete_id) VALUES (?, ?)');
                foreach ($specificIds as $aid) {
                    $insVis->execute([$newFileId, $aid]);
                }
            }

            if ($visibility === 'all_athletes') {
                foreach ($athletes as $a) {
                    notifyAthleteVideo((int)$a['id'], (int)$coachId, (string)$origName, $pdo);
                }
            } elseif ($visibility === 'specific_athletes' && !empty($specificIds)) {
                foreach ($specificIds as $aid) {
                    notifyAthleteVideo((int)$aid, (int)$coachId, (string)$origName, $pdo);
                }
            }

            $uploadedCount++;
        }

        if ($uploadedCount === 0 && empty($errors)) {
            $errors[] = 'Nebylo nahrano zadne video. Zkontrolujte velikost souboru a nastaveni serveru.';
        }
    }

    if ($uploadedCount > 0) {
        flash('success', 'Nahrano ' . $uploadedCount . ' videi.' . (!empty($errors) ? ' Nektere se nezdarily.' : ''));
        if ($folderId > 0) {
            redirect(BASE_URL . '/video_folder.php?id=' . $folderId);
        }
        redirect(BASE_URL . '/video_folder.php?mine=1');
    }
}

function notifyAthleteVideo(int $athleteId, int $coachId, string $fileName, PDO $pdo): void
{
    try {
        $coach = $pdo->prepare('SELECT name, username FROM coaches WHERE id = ?');
        $coach->execute([$coachId]);
        $coach = $coach->fetch();
        $coachName = $coach ? ((string)($coach['name'] ?: $coach['username'])) : 'Trener';

        $pdo->prepare('INSERT INTO athlete_notifications (athlete_id, subject, body) VALUES (?, ?, ?)')
            ->execute([
                $athleteId,
                'Nove video',
                'Trener ' . $coachName . ' sdilel video "' . mb_substr($fileName, 0, 100) . '".',
            ]);
    } catch (Throwable $e) {
        error_log('notifyAthleteVideo error: ' . $e->getMessage());
    }
}

renderHeader('Nahrat videa');
$defaultVisibility = $prefillAthleteId > 0 ? 'specific_athletes' : 'private';
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/videos.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0"><i class="fas fa-cloud-upload-alt me-2 text-danger"></i>Nahrat videa</h2>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
        <li><?= $e ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <div class="mb-3">
                <label class="form-label fw-semibold fs-6">Vybrat videa</label>
                <input type="file" name="files[]" id="fileInput" class="form-control" multiple required accept="video/*,.mp4,.mov,.avi,.mkv,.webm,.m4v">
                <div class="form-text">Povolene formaty: MP4, MOV, AVI, MKV, WEBM, M4V. Max <?= (int)$maxVideoSizeMb ?> MB na video.</div>
            </div>

            <div class="d-flex gap-2 mb-4 flex-wrap">
                <label class="btn btn-outline-danger" title="Natocit video primo">
                    <i class="fas fa-video me-1"></i>Natocit video
                    <input type="file" name="files[]" accept="video/*" capture="environment" class="d-none" onchange="mergeFiles(this)">
                </label>
            </div>

            <hr>

            <div class="alert alert-light border mb-3">
                <i class="fas fa-folder-open me-1 text-primary"></i>
                Videa se vzdy ukladaji do vasich videi. Viditelnost urci, komu se jeste zobrazi.
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Vlastni slozka (volitelne)</label>
                <select name="folder_id" class="form-select">
                    <option value="0">Bez slozky</option>
                    <?php foreach ($customFolders as $folder): ?>
                    <option value="<?= (int)$folder['id'] ?>" <?= $prefillFolderId === (int)$folder['id'] ? 'selected' : '' ?>>
                        <?= h($folder['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Popis <small class="text-muted">(volitelny)</small></label>
                <textarea name="description" class="form-control" rows="2" maxlength="1000" placeholder="Kratky popis videi..."></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Viditelnost</label>
                <select name="visibility" class="form-select" id="visSelect">
                    <option value="private" <?= $defaultVisibility === 'private' ? 'selected' : '' ?>>Soukrome - pouze ja</option>
                    <?php if (!empty($athletes)): ?>
                    <option value="all_athletes">Sdilet se vsemi mymi sportovci</option>
                    <option value="specific_athletes" <?= $defaultVisibility === 'specific_athletes' ? 'selected' : '' ?>>Sdilet s vybranymi sportovci</option>
                    <?php endif; ?>
                </select>
            </div>

            <?php if (!empty($athletes)): ?>
            <div id="specificAthletes" class="mb-3 <?= $defaultVisibility === 'specific_athletes' ? '' : 'd-none' ?>">
                <label class="form-label fw-semibold">Vyberte sportovce</label>
                <div class="row g-2">
                    <?php foreach ($athletes as $a): ?>
                    <div class="col-sm-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="specific_athletes[]" value="<?= $a['id'] ?>" id="ath<?= $a['id'] ?>" <?= $prefillAthleteId === (int)$a['id'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ath<?= $a['id'] ?>">
                                <?= h($a['first_name'] . ' ' . $a['last_name']) ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-danger fw-bold px-4">
                    <i class="fas fa-upload me-1"></i>Nahrat
                </button>
                <a href="<?= BASE_URL ?>/videos.php" class="btn btn-outline-secondary">Zrusit</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
document.getElementById('visSelect')?.addEventListener('change', function () {
    document.getElementById('specificAthletes')?.classList.toggle('d-none', this.value !== 'specific_athletes');
});

function mergeFiles(captureInput) {
    const main = document.getElementById('fileInput');
    const dt = new DataTransfer();
    for (const file of (main.files || [])) dt.items.add(file);
    for (const file of (captureInput.files || [])) dt.items.add(file);
    main.files = dt.files;
    captureInput.value = '';
}
</script>

<?php renderFooter(); ?>
