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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect(BASE_URL . '/video_upload.php');
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
        mkdir($uploadDir, 0755, true);
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
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !$origName) {
                continue;
            }

            $size = (int)($files['size'][$i] ?? 0);
            if ($size > 500 * 1024 * 1024) {
                $errors[] = h($origName) . ': max 500 MB.';
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

            $ins = $pdo->prepare('INSERT INTO video_files (coach_id, folder_id, file_path, original_name, file_size, mime_type, description, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([
                $coachId,
                $folderId > 0 ? $folderId : null,
                $newName,
                (string)$origName,
                $size,
                (string)$mime,
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
                <div class="form-text">Povolene formaty: MP4, MOV, AVI, MKV, WEBM, M4V. Max 500 MB na video.</div>
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
