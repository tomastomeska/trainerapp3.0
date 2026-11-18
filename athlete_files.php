<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();
$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

$athleteStmt = $pdo->prepare('SELECT id, coach_id FROM athletes WHERE id = ? LIMIT 1');
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$categories = [
    'medical' => 'Zdravotní dokumentace',
    'body_measurements' => 'Tělesná měření',
    'sports_tests' => 'Sportovní testy',
    'laboratory_results' => 'Laboratorní výsledky',
    'nutrition' => 'Výživová dokumentace',
    'training' => 'Tréninková dokumentace',
    'physiotherapy' => 'Fyzioterapie a rehabilitace',
    'injuries' => 'Úrazy a omezení',
    'competition' => 'Závodní dokumentace',
    'other' => 'Ostatní',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_files.php');
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'upload') {
        $category = (string)($_POST['document_category'] ?? 'other');
        $displayName = mb_substr(trim((string)($_POST['display_name'] ?? '')), 0, 255, 'UTF-8');
        $sharedWithCoach = isset($_POST['shared_with_coach']) ? 1 : 0;
        $uploadFiles = $_FILES['files'] ?? [];
        $uploads = [];
        foreach ((array)($uploadFiles['name'] ?? []) as $index => $originalName) {
            if ($originalName === '') {
                continue;
            }
            $uploads[] = [
                'name' => (string)$originalName,
                'tmp_name' => (string)($uploadFiles['tmp_name'][$index] ?? ''),
                'error' => (int)($uploadFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int)($uploadFiles['size'][$index] ?? 0),
            ];
        }

        if (!isset($categories[$category])) {
            $errors[] = 'Vyberte platný typ souboru.';
        }
        if (empty($uploads)) {
            $errors[] = 'Vyberte soubor k nahrání.';
        } elseif (count($uploads) > 1 && $displayName === '') {
            $errors[] = 'Pro více souborů zadejte společný název dokumentové sady.';
        }
        foreach ($uploads as $upload) {
            if ($upload['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Soubor „' . h($upload['name']) . '“ se nepodařilo nahrát.';
            } elseif ($upload['size'] > 200 * 1024 * 1024) {
                $errors[] = 'Soubor „' . h($upload['name']) . '“ překračuje maximální velikost 200 MB.';
            }
        }

        if (empty($errors)) {
            $uploadDir = __DIR__ . '/uploads/athlete_files/athlete_' . $athleteId . '/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $errors[] = 'Nepodařilo se připravit úložiště souborů.';
            } else {
                $batchId = count($uploads) > 1 ? bin2hex(random_bytes(16)) : null;
                $storedFiles = [];
                try {
                    $pdo->beginTransaction();
                    $insert = $pdo->prepare('INSERT INTO athlete_files (athlete_id, coach_id, file_path, original_name, display_name, upload_batch, file_size, mime_type, document_category, shared_with_coach) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    foreach ($uploads as $upload) {
                        $storedName = bin2hex(random_bytes(24));
                        if (!move_uploaded_file($upload['tmp_name'], $uploadDir . $storedName)) {
                            throw new RuntimeException('Soubor „' . $upload['name'] . '“ se nepodařilo uložit.');
                        }
                        $storedFiles[] = $storedName;
                        $insert->execute([
                            $athleteId,
                            (int)$athlete['coach_id'],
                            $storedName,
                            mb_substr($upload['name'], 0, 255, 'UTF-8'),
                            $displayName ?: null,
                            $batchId,
                            $upload['size'],
                            mime_content_type($uploadDir . $storedName) ?: null,
                            $category,
                            $sharedWithCoach,
                        ]);
                    }
                    $pdo->commit();
                    if ($sharedWithCoach === 1) {
                        $athleteNameStmt = $pdo->prepare('SELECT first_name, last_name FROM athletes WHERE id = ? LIMIT 1');
                        $athleteNameStmt->execute([$athleteId]);
                        $athleteName = $athleteNameStmt->fetch();
                        $fullAthleteName = trim((string)($athleteName['first_name'] ?? '') . ' ' . (string)($athleteName['last_name'] ?? ''));
                        $documentName = $displayName ?: (string)$uploads[0]['name'];
                        $categoryName = $categories[$category] ?? $categories['other'];
                        $documentDescription = count($uploads) > 1
                            ? count($uploads) . ' souborů v sadě „' . $documentName . '“'
                            : 'soubor „' . $documentName . '“';
                        createCoachSystemMessage(
                            (int)$athlete['coach_id'],
                            'Nový soubor: ' . ($fullAthleteName !== '' ? $fullAthleteName : 'Sportovec') . ' - ' . $categoryName,
                            ($fullAthleteName !== '' ? $fullAthleteName : 'Sportovec') . ' vám zpřístupnil(a) ' . $documentDescription . '.',
                            true
                        );
                    }
                    flash('success', count($uploads) > 1 ? 'Sada souborů byla nahrána.' : 'Soubor byl nahrán.');
                    redirect(BASE_URL . '/athlete_files.php');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    foreach ($storedFiles as $storedFile) {
                        @unlink($uploadDir . $storedFile);
                    }
                    $errors[] = $e->getMessage();
                }
            }
        }
    }

    if ($action === 'toggle_sharing') {
        $fileId = intParam($_POST, 'file_id');
        $sharedWithCoach = isset($_POST['shared_with_coach']) ? 1 : 0;
        $batchStmt = $pdo->prepare('SELECT upload_batch FROM athlete_files WHERE id = ? AND athlete_id = ?');
        $batchStmt->execute([$fileId, $athleteId]);
        $batchId = (string)($batchStmt->fetchColumn() ?: '');
        $sql = 'UPDATE athlete_files SET shared_with_coach = ?, coach_viewed_at = CASE WHEN ? = 1 THEN NULL ELSE coach_viewed_at END WHERE athlete_id = ?';
        $params = [$sharedWithCoach, $sharedWithCoach, $athleteId];
        if ($batchId !== '') {
            $sql .= ' AND upload_batch = ?';
            $params[] = $batchId;
        } else {
            $sql .= ' AND id = ?';
            $params[] = $fileId;
        }
        $pdo->prepare($sql)->execute($params);
        flash('success', $sharedWithCoach ? 'Soubor je nyní zpřístupněn trenérovi.' : 'Přístup trenéra k souboru byl odebrán.');
        redirect(BASE_URL . '/athlete_files.php');
    }

    if ($action === 'delete') {
        $fileId = intParam($_POST, 'file_id');
        $fileStmt = $pdo->prepare('SELECT file_path, upload_batch FROM athlete_files WHERE id = ? AND athlete_id = ?');
        $fileStmt->execute([$fileId, $athleteId]);
        $file = $fileStmt->fetch();
        if ($file) {
            $filesToDeleteStmt = $pdo->prepare('SELECT id, file_path FROM athlete_files WHERE athlete_id = ? AND upload_batch = ?');
            $filesToDelete = [$file];
            if (!empty($file['upload_batch'])) {
                $filesToDeleteStmt->execute([$athleteId, $file['upload_batch']]);
                $filesToDelete = $filesToDeleteStmt->fetchAll();
            }
            foreach ($filesToDelete as $fileToDelete) {
                $fullPath = __DIR__ . '/uploads/athlete_files/athlete_' . $athleteId . '/' . $fileToDelete['file_path'];
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }
            if (!empty($file['upload_batch'])) {
                $pdo->prepare('DELETE FROM athlete_files WHERE athlete_id = ? AND upload_batch = ?')->execute([$athleteId, $file['upload_batch']]);
            } else {
                $pdo->prepare('DELETE FROM athlete_files WHERE id = ? AND athlete_id = ?')->execute([$fileId, $athleteId]);
            }
            flash('success', count($filesToDelete) > 1 ? 'Sada souborů byla smazána.' : 'Soubor byl smazán.');
        }
        redirect(BASE_URL . '/athlete_files.php');
    }
}

$filesStmt = $pdo->prepare("SELECT * FROM athlete_files WHERE athlete_id = ? ORDER BY FIELD(document_category, 'medical', 'body_measurements', 'sports_tests', 'laboratory_results', 'nutrition', 'training', 'physiotherapy', 'injuries', 'competition', 'other'), created_at DESC");
$filesStmt->execute([$athleteId]);
$files = $filesStmt->fetchAll();
$fileGroups = [];
foreach ($files as $file) {
    $groupKey = !empty($file['upload_batch']) ? 'batch_' . $file['upload_batch'] : 'file_' . $file['id'];
    if (!isset($fileGroups[$groupKey])) {
        $fileGroups[$groupKey] = ['primary' => $file, 'files' => []];
    }
    $fileGroups[$groupKey]['files'][] = $file;
}

renderAthleteHeader('Soubory', false, true);
?>
<style>
.athlete-file-group {
    padding: 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #fff;
}

.athlete-file-group + .athlete-file-group {
    margin-top: .75rem;
}

.athlete-file-group-header,
.athlete-file-meta,
.athlete-file-actions,
.athlete-file-links,
.athlete-file-link {
    display: flex;
    align-items: center;
}

.athlete-file-group-header,
.athlete-file-meta,
.athlete-file-actions {
    justify-content: space-between;
    gap: .75rem;
}

.athlete-file-group-header {
    padding-bottom: .75rem;
    border-bottom: 1px solid #e2e8f0;
    color: #273449;
    font-weight: 700;
}

.athlete-file-group-title {
    min-width: 0;
    overflow-wrap: anywhere;
}

.athlete-file-links {
    flex-direction: column;
    align-items: stretch;
    gap: .5rem;
    padding: .75rem 0;
}

.athlete-file-link {
    justify-content: space-between;
    gap: .75rem;
    min-width: 0;
    padding: .55rem .7rem;
    border: 1px solid #e2e8f0;
    border-radius: 9px;
    color: #765900;
    text-decoration: none;
}

.athlete-file-link:hover {
    border-color: #e8c34f;
    background: #fffaf0;
    color: #5f4900;
}

.athlete-file-meta {
    flex-wrap: wrap;
    justify-content: flex-start;
    color: #64748b;
    font-size: .85rem;
}

.athlete-file-actions {
    margin-top: .75rem;
    padding-top: .75rem;
    border-top: 1px solid #e2e8f0;
}

@media (max-width: 575.98px) {
    .athlete-file-group {
        padding: .8rem;
    }

    .athlete-file-actions {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center mb-4">
    <div class="col-lg-7">
        <section class="card athlete-page-card shadow-sm">
            <div class="card-header">
                <h2><i class="fas fa-cloud-arrow-up me-2 text-warning"></i>Nahrát soubor</h2>
            </div>
            <div class="card-body p-3 p-md-4">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="upload">
                    <div id="athleteFileInputs" class="mb-3 text-start">
                        <label class="form-label fw-semibold" for="athleteFile">Vybrat soubor</label>
                        <input type="file" name="files[]" id="athleteFile" class="form-control" required>
                    </div>
                    <button type="button" id="addAnotherFile" class="btn athlete-page-action mb-3"><i class="fas fa-plus"></i>Přidat další soubor</button>
                    <div class="text-start">
                        <label class="form-label fw-semibold">Typ souboru</label>
                        <select name="document_category" class="form-select mb-3" required>
                            <?php foreach ($categories as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?>
                        </select>
                        <label class="form-label fw-semibold">Název dokumentu nebo sady <small class="text-muted">(u více souborů povinný)</small></label>
                        <input type="text" name="display_name" class="form-control mb-3" maxlength="255" placeholder="Např. Krevní výsledky - září 2026">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="shared_with_coach" id="shareWithCoach">
                            <label class="form-check-label" for="shareWithCoach">Zpřístupnit soubor trenérovi</label>
                        </div>
                    </div>
                    <button type="submit" class="btn athlete-page-action athlete-page-action--primary mt-4"><i class="fas fa-upload"></i>Nahrát</button>
                </form>
            </div>
        </section>
    </div>
</div>

<?php if (empty($files)): ?>
<div class="alert alert-light border text-center py-4"><i class="fas fa-file-upload fa-2x text-muted mb-2 d-block"></i>Zatím zde nemáte žádné nahrané soubory.</div>
<?php else: ?>
<section class="card athlete-page-card shadow-sm mb-4">
    <div class="card-header">
        <h2><i class="fas fa-folder-open me-2 text-warning"></i>Moje soubory <span class="badge rounded-pill text-bg-warning ms-1"><?= count($fileGroups) ?></span></h2>
    </div>
    <div class="card-body p-2 p-md-3">
        <?php foreach ($fileGroups as $group): $file = $group['primary']; ?>
        <article class="athlete-file-group">
            <div class="athlete-file-group-header">
                <div class="athlete-file-group-title"><i class="fas fa-file-lines me-2 text-warning"></i><?= h($file['display_name'] ?: $file['original_name']) ?></div>
                <?php if (count($group['files']) > 1): ?><span class="badge rounded-pill text-bg-warning"><?= count($group['files']) ?> souborů</span><?php endif; ?>
            </div>
            <div class="athlete-file-links">
                <?php foreach ($group['files'] as $groupFile): ?>
                <a class="athlete-file-link" href="<?= BASE_URL ?>/athlete_file_download.php?id=<?= (int)$groupFile['id'] ?>">
                    <span class="text-break"><i class="fas fa-download me-2"></i><?= h($groupFile['original_name']) ?></span>
                    <small class="text-muted text-nowrap"><?= round((int)$groupFile['file_size'] / 1024, 1) ?> KB</small>
                </a>
                <?php endforeach; ?>
            </div>
            <div class="athlete-file-meta">
                <span><i class="fas fa-folder me-1"></i><?= h($categories[$file['document_category']] ?? $categories['other']) ?></span>
                <span><i class="fas fa-calendar me-1"></i><?= h(date('d.m.Y', strtotime($file['created_at']))) ?></span>
            </div>
            <div class="athlete-file-actions">
                <form method="post" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="toggle_sharing">
                    <input type="hidden" name="file_id" value="<?= (int)$file['id'] ?>">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="shared_with_coach" id="shareWithCoach<?= (int)$file['id'] ?>" <?= (int)$file['shared_with_coach'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label class="form-check-label" for="shareWithCoach<?= (int)$file['id'] ?>">Zpřístupnit trenérovi</label>
                    </div>
                </form>
                <form method="post" class="m-0" onsubmit="return confirm('Opravdu smazat <?= count($group['files']) > 1 ? 'celou sadu (' . count($group['files']) . ' souborů)' : 'tento soubor' ?>? Tato akce je nenávratná a soubory nebude možné obnovit.')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="file_id" value="<?= (int)$file['id'] ?>">
                    <button class="btn athlete-page-action" type="submit" title="Smazat"><i class="fas fa-trash"></i>Smazat</button>
                </form>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<script>
document.getElementById('addAnotherFile').addEventListener('click', function () {
    const container = document.getElementById('athleteFileInputs');
    const index = container.querySelectorAll('input[type="file"]').length + 1;
    const wrapper = document.createElement('div');
    wrapper.className = 'input-group mt-2';
    wrapper.innerHTML = '<input type="file" name="files[]" class="form-control" required aria-label="Další soubor ' + index + '"><button type="button" class="btn athlete-page-action" title="Odebrat soubor"><i class="fas fa-times"></i></button>';
    wrapper.querySelector('button').addEventListener('click', function () { wrapper.remove(); });
    container.appendChild(wrapper);
});
</script>
<?php renderAthleteFooter(); ?>