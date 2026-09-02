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
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-folder-open me-2 text-primary"></i>Soubory</h2>
    <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-house me-1"></i>Domů</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center mb-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4 text-center">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="upload">
                    <div id="athleteFileInputs" class="mb-3 text-start">
                        <label class="form-label fw-semibold" for="athleteFile">Vybrat soubor</label>
                        <input type="file" name="files[]" id="athleteFile" class="form-control" required>
                    </div>
                    <button type="button" id="addAnotherFile" class="btn btn-outline-primary btn-sm mb-3"><i class="fas fa-plus me-1"></i>Přidat další soubor</button>
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
                    <button type="submit" class="btn btn-success mt-4"><i class="fas fa-upload me-1"></i>Nahrát</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (empty($files)): ?>
<div class="alert alert-light border text-center py-4"><i class="fas fa-file-upload fa-2x text-muted mb-2 d-block"></i>Zatím zde nemáte žádné nahrané soubory.</div>
<?php else: ?>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Soubor</th><th>Typ</th><th>Nahráno</th><th>Trenér</th><th class="text-end">Akce</th></tr></thead>
    <tbody><?php foreach ($fileGroups as $group): $file = $group['primary']; ?><tr>
        <td><i class="fas fa-file me-2 text-primary"></i><strong><?= h($file['display_name'] ?: $file['original_name']) ?></strong><?php if (count($group['files']) > 1): ?><span class="badge bg-secondary ms-1"><?= count($group['files']) ?> souborů</span><?php endif; ?><?php foreach ($group['files'] as $groupFile): ?><small class="text-muted d-block ms-4"><a href="<?= BASE_URL ?>/athlete_file_download.php?id=<?= (int)$groupFile['id'] ?>"><?= h($groupFile['original_name']) ?></a> (<?= round((int)$groupFile['file_size'] / 1024, 1) ?> KB)</small><?php endforeach; ?></td>
        <td><?= h($categories[$file['document_category']] ?? $categories['other']) ?></td>
        <td><?= h(date('d.m.Y', strtotime($file['created_at']))) ?></td>
        <td><form method="post" class="m-0"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="toggle_sharing"><input type="hidden" name="file_id" value="<?= (int)$file['id'] ?>"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="shared_with_coach" <?= (int)$file['shared_with_coach'] === 1 ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="Zpřístupnit trenérovi"></div></form></td>
        <td class="text-end"><form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat <?= count($group['files']) > 1 ? 'celou sadu (' . count($group['files']) . ' souborů)' : 'tento soubor' ?>? Tato akce je nenávratná a soubory nebude možné obnovit.')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="file_id" value="<?= (int)$file['id'] ?>"><button class="btn btn-outline-danger btn-sm" title="Smazat"><i class="fas fa-trash"></i></button></form></td>
    </tr><?php endforeach; ?></tbody>
</table></div></div>
<?php endif; ?>
<script>
document.getElementById('addAnotherFile').addEventListener('click', function () {
    const container = document.getElementById('athleteFileInputs');
    const index = container.querySelectorAll('input[type="file"]').length + 1;
    const wrapper = document.createElement('div');
    wrapper.className = 'input-group mt-2';
    wrapper.innerHTML = '<input type="file" name="files[]" class="form-control" required aria-label="Další soubor ' + index + '"><button type="button" class="btn btn-outline-danger" title="Odebrat soubor"><i class="fas fa-times"></i></button>';
    wrapper.querySelector('button').addEventListener('click', function () { wrapper.remove(); });
    container.appendChild(wrapper);
});
</script>
<?php renderAthleteFooter(); ?>