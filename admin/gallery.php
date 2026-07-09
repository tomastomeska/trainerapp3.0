<?php
// admin/gallery.php – galerie administrátora
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();
$pdo     = getDB();
$adminId = $_SESSION['admin_id'] ?? null;

// Všichni aktivní trenéři
$coaches = $pdo->query("SELECT id, name, username FROM coaches WHERE is_active = 1 ORDER BY name")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/gallery.php');
    }

    $action = $_POST['action'] ?? '';

    // Nahrání souboru
    if ($action === 'upload') {
        $description = trim($_POST['description'] ?? '');
        $visibility  = in_array($_POST['visibility'] ?? '', ['all_coaches', 'specific_coaches'])
                       ? $_POST['visibility'] : 'all_coaches';
        $specificIds = array_map('intval', array_filter($_POST['specific_coaches'] ?? []));

        $allowed = ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mkv','webm',
                    'pdf','doc','docx','xls','xlsx','csv','txt','zip','rar','7z','ppt','pptx'];

        $uploadDir = dirname(__DIR__) . '/uploads/gallery/admin/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $uploadedCount = 0;
        $uploadedIds   = [];
        $files         = $_FILES['files'] ?? [];

        if (!is_array($files['name'])) {
            $files = ['name' => [$files['name']], 'type' => [$files['type']],
                      'tmp_name' => [$files['tmp_name']], 'error' => [$files['error']], 'size' => [$files['size']]];
        }

        foreach ($files['name'] as $i => $origName) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK || !$origName) continue;
            $size = $files['size'][$i];
            if ($size > 200 * 1024 * 1024) { $errors[] = h($origName) . ': max 200 MB.'; continue; }
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) { $errors[] = h($origName) . ': typ .' . $ext . ' není povolen.'; continue; }

            $newName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($files['tmp_name'][$i], $uploadDir . $newName)) {
                $errors[] = h($origName) . ': nepodařilo se uložit.'; continue;
            }

            $mime  = mime_content_type($uploadDir . $newName) ?: '';
            $ftype = str_starts_with($mime, 'image/') ? 'image'
                   : (str_starts_with($mime, 'video/') ? 'video' : 'document');

            $ins = $pdo->prepare("INSERT INTO admin_gallery_files (file_path, original_name, file_size, file_type, mime_type, description, visibility, uploaded_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$newName, $origName, $size, $ftype, $mime, $description ?: null, $visibility, $adminId]);
            $newFileId = (int)$pdo->lastInsertId();
            $uploadedIds[] = $newFileId;

            if ($visibility === 'specific_coaches' && !empty($specificIds)) {
                $insVis = $pdo->prepare("INSERT IGNORE INTO admin_gallery_file_coaches (file_id, coach_id) VALUES (?, ?)");
                foreach ($specificIds as $cid) {
                    $insVis->execute([$newFileId, $cid]);
                }
            }
            $uploadedCount++;
        }

        if ($uploadedCount > 0 && empty($errors)) {
            // Notifikace trenérům
            $notifyIds = $visibility === 'all_coaches'
                ? array_column($coaches, 'id')
                : $specificIds;

            foreach ($notifyIds as $cid) {
                createCoachSystemMessage(
                    $cid,
                    'Nový soubor v galerii od administrátora',
                    "Administrátor přidal nové soubory do galerie TrainerApp.\n\nPřejděte do sekce Galerie a prohlédněte si je.",
                    true
                );
            }

            flash('success', "Nahráno $uploadedCount soubor(ů). Trenéři byli upozorněni.");
            redirect(BASE_URL . '/admin/gallery.php');
        }

        if (!empty($errors) && $uploadedCount === 0) {
            // Zobrazíme chyby dole
        } elseif ($uploadedCount > 0) {
            flash('warning', "Nahráno $uploadedCount soubor(ů), ale některé selhaly.");
            redirect(BASE_URL . '/admin/gallery.php');
        }
    }

    // Smazání souboru
    if ($action === 'delete') {
        $fileId = intParam($_POST, 'file_id');
        $f = $pdo->prepare("SELECT * FROM admin_gallery_files WHERE id = ?");
        $f->execute([$fileId]);
        $f = $f->fetch();
        if ($f) {
            $full = dirname(__DIR__) . '/uploads/gallery/admin/' . $f['file_path'];
            if (file_exists($full)) @unlink($full);
            $pdo->prepare("DELETE FROM admin_gallery_files WHERE id = ?")->execute([$fileId]);
            flash('success', 'Soubor byl smazán.');
        }
        redirect(BASE_URL . '/admin/gallery.php');
    }
}

// Načíst soubory admina se statistikami
$adminFiles = $pdo->query("
    SELECT agf.*,
           (SELECT COUNT(*) FROM admin_gallery_file_coaches agfc WHERE agfc.file_id = agf.id) AS coach_count
    FROM admin_gallery_files agf
    ORDER BY agf.created_at DESC
")->fetchAll();

renderAdminHeader('Galerie');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="fw-bold mb-0"><i class="fas fa-images me-2 text-primary"></i>Galerie administrátora</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUpload">
        <i class="fas fa-cloud-upload-alt me-1"></i>Nahrát soubory
    </button>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if (empty($adminFiles)): ?>
<div class="alert alert-light border text-center py-5">
    <i class="fas fa-images fa-3x text-muted mb-3 d-block"></i>
    <p class="text-muted">Zatím nejsou žádné soubory v galerii administrátora.</p>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUpload">
        <i class="fas fa-cloud-upload-alt me-1"></i>Nahrát první soubor
    </button>
</div>
<?php else: ?>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-dark">
                <tr>
                    <th style="width:50px">Typ</th>
                    <th>Název</th>
                    <th>Popis</th>
                    <th>Viditelnost</th>
                    <th>Velikost</th>
                    <th>Datum</th>
                    <th style="width:100px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($adminFiles as $f): ?>
            <?php
            $ico = match($f['file_type']) { 'image' => 'fa-image text-success', 'video' => 'fa-video text-danger', default => 'fa-file-alt text-info' };
            $src = BASE_URL . '/uploads/gallery/admin/' . rawurlencode($f['file_path']);
            ?>
            <tr>
                <td class="text-center"><i class="fas <?= $ico ?> fa-lg"></i></td>
                <td>
                    <a href="<?= $src ?>" target="_blank" class="fw-semibold text-decoration-none">
                        <?= h($f['original_name']) ?>
                    </a>
                </td>
                <td class="text-muted small"><?= h(mb_strimwidth($f['description'] ?? '', 0, 60, '…')) ?: '—' ?></td>
                <td>
                    <?php if ($f['visibility'] === 'all_coaches'): ?>
                    <span class="badge bg-success">Všichni trenéři</span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark"><?= $f['coach_count'] ?> trenér(ů)</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted small"><?= round($f['file_size'] / 1024, 1) ?> KB</td>
                <td class="text-muted small text-nowrap"><?= date('d.m.Y H:i', strtotime($f['created_at'])) ?></td>
                <td class="text-end">
                    <a href="<?= $src ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Otevřít">
                        <i class="fas fa-eye"></i>
                    </a>
                    <form method="post" class="d-inline"
                          onsubmit="return confirm('Opravdu smazat soubor <?= h(addslashes($f['original_name'])) ?>?')">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Smazat">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Nahrát soubory -->
<div class="modal fade" id="modalUpload" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="post" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="upload">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cloud-upload-alt me-2"></i>Nahrát soubory do galerie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Soubory <small class="text-muted">(max 200 MB každý)</small></label>
                    <input type="file" name="files[]" class="form-control" multiple required
                           accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.rar,.7z,.ppt,.pptx">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Popis <small class="text-muted">(volitelný)</small></label>
                    <textarea name="description" class="form-control" rows="2" maxlength="1000"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Viditelnost</label>
                    <select name="visibility" class="form-select" id="visSelect">
                        <option value="all_coaches">👥 Všichni trenéři</option>
                        <option value="specific_coaches">👤 Vybraní trenéři</option>
                    </select>
                </div>
                <div id="specificCoaches" class="mb-3 d-none">
                    <label class="form-label fw-semibold">Vyberte trenéry</label>
                    <div class="row g-2" style="max-height:200px;overflow-y:auto">
                        <?php foreach ($coaches as $c): ?>
                        <div class="col-sm-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="specific_coaches[]" value="<?= $c['id'] ?>"
                                       id="coach<?= $c['id'] ?>">
                                <label class="form-check-label" for="coach<?= $c['id'] ?>">
                                    <?= h($c['name'] ?: $c['username']) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="alert alert-info small mb-0">
                    <i class="fas fa-bell me-1"></i>
                    Po nahrání budou všichni dotčení trenéři automaticky notifikováni systémovou zprávou.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="submit" class="btn btn-primary fw-bold">
                    <i class="fas fa-upload me-1"></i>Nahrát a notifikovat
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('visSelect')?.addEventListener('change', function () {
    document.getElementById('specificCoaches')?.classList.toggle('d-none', this.value !== 'specific_coaches');
});
</script>

<?php renderAdminFooter(); ?>
