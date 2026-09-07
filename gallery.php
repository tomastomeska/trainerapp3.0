<?php
// gallery.php – prehled galerie trenera
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo     = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect(BASE_URL . '/gallery.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_folder') {
        $name = trim($_POST['folder_name'] ?? '');
        if ($name === '') {
            flash('danger', 'Nazev slozky nesmi byt prazdny.');
        } else {
            $name = mb_substr($name, 0, 200, 'UTF-8');
            $pdo->prepare("INSERT INTO gallery_folders (coach_id, name, folder_type, sort_order) VALUES (?, ?, 'custom', 0)")
                ->execute([$coachId, $name]);
            flash('success', 'Slozka byla vytvorena.');
        }
        redirect(BASE_URL . '/gallery.php');
    }

    if ($action === 'rename_folder') {
        $folderId = intParam($_POST, 'folder_id');
        $name     = trim($_POST['folder_name'] ?? '');
        if ($name === '') {
            flash('danger', 'Nazev slozky nesmi byt prazdny.');
        } else {
            $name = mb_substr($name, 0, 200, 'UTF-8');
            $pdo->prepare("UPDATE gallery_folders SET name = ? WHERE id = ? AND coach_id = ? AND folder_type = 'custom'")
                ->execute([$name, $folderId, $coachId]);
            flash('success', 'Slozka byla prejmenovana.');
        }
        redirect(BASE_URL . '/gallery.php');
    }

    if ($action === 'delete_folder') {
        $folderId = intParam($_POST, 'folder_id');
        $checkFolder = $pdo->prepare("SELECT id FROM gallery_folders WHERE id = ? AND coach_id = ? AND folder_type = 'custom'");
        $checkFolder->execute([$folderId, $coachId]);
        if ($checkFolder->fetch()) {
            $pdo->prepare("UPDATE gallery_files SET folder_id = NULL WHERE coach_id = ? AND folder_id = ?")
                ->execute([$coachId, $folderId]);
            $pdo->prepare("DELETE FROM gallery_folders WHERE id = ? AND coach_id = ? AND folder_type = 'custom'")
                ->execute([$folderId, $coachId]);
            flash('success', 'Slozka byla smazana. Soubory zustaly v Moje soubory.');
        }
        redirect(BASE_URL . '/gallery.php');
    }
}

$mineCountStmt = $pdo->prepare("SELECT COUNT(*) FROM gallery_files WHERE coach_id = ?");
$mineCountStmt->execute([$coachId]);
$mineCount = (int)$mineCountStmt->fetchColumn();

$customFolders = $pdo->prepare("
        SELECT f.id, f.name, COUNT(gf.id) AS file_count
        FROM gallery_folders f
        LEFT JOIN gallery_files gf ON gf.folder_id = f.id
        WHERE f.coach_id = ?
            AND f.folder_type = 'custom'
        GROUP BY f.id, f.name
        ORDER BY f.name ASC
");
$customFolders->execute([$coachId]);
$customFolders = $customFolders->fetchAll();

$athleteFolders = $pdo->prepare("
    SELECT
        f.id,
        f.athlete_id,
        a.first_name,
        a.last_name,
        a.photo AS athlete_photo,
        (
            SELECT COUNT(*)
            FROM gallery_files gf
            WHERE gf.coach_id = f.coach_id
              AND (
                  gf.visibility = 'all_athletes'
                  OR (
                      gf.visibility = 'specific_athletes'
                      AND EXISTS (
                          SELECT 1
                          FROM gallery_file_athletes gfa
                          WHERE gfa.file_id = gf.id
                            AND gfa.athlete_id = f.athlete_id
                      )
                  )
              )
        ) AS shared_count
    FROM gallery_folders f
    INNER JOIN athletes a ON a.id = f.athlete_id
    WHERE f.coach_id = ?
      AND f.folder_type = 'athlete'
    ORDER BY a.first_name ASC, a.last_name ASC
");
$athleteFolders->execute([$coachId]);
$athleteFolders = $athleteFolders->fetchAll();

$adminFilesStmt = $pdo->prepare("
    SELECT agf.*
    FROM admin_gallery_files agf
    WHERE agf.visibility = 'all_coaches'
       OR (
           agf.visibility = 'specific_coaches'
           AND EXISTS (
               SELECT 1
               FROM admin_gallery_file_coaches agfc
               WHERE agfc.file_id = agf.id
                 AND agfc.coach_id = ?
           )
       )
    ORDER BY agf.created_at DESC
");
$adminFilesStmt->execute([$coachId]);
$adminFiles = array_values(array_filter($adminFilesStmt->fetchAll(), static function (array $file): bool {
    $filePath = (string)($file['file_path'] ?? '');
    return $filePath !== '' && file_exists(__DIR__ . '/uploads/gallery/admin/' . $filePath);
}));

renderHeader('Galerie', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-images me-2 text-warning"></i>Galerie</h2>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNewFolder">
            <i class="fas fa-folder-plus me-1"></i>Nova slozka
        </button>
        <a href="<?= BASE_URL ?>/gallery_upload.php" class="btn btn-warning btn-sm fw-bold">
            <i class="fas fa-cloud-upload-alt me-1"></i>Nahrat soubory
        </a>
    </div>
</div>

<div class="alert alert-light border mb-4">
    <i class="fas fa-info-circle me-2 text-muted"></i>
    Soubory nahravate vzdy do sve galerie. U kazdeho souboru pak nastavite, komu se zobrazi.
</div>

<?php if ($adminFiles !== []): ?>
<h5 class="fw-bold text-muted mb-3"><i class="fas fa-user-shield me-2"></i>Od administrátora</h5>
<div class="row g-3 mb-4">
    <?php foreach ($adminFiles as $file): ?>
    <?php
    $fileUrl = BASE_URL . '/uploads/gallery/admin/' . rawurlencode((string)$file['file_path']);
    $fileIcon = match($file['file_type']) { 'image' => 'fa-image text-success', 'video' => 'fa-video text-danger', default => 'fa-file-alt text-info' };
    ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <a href="<?= h($fileUrl) ?>" target="_blank" rel="noopener" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 gallery-folder-card overflow-hidden">
                <?php if ($file['file_type'] === 'image'): ?>
                <img src="<?= h($fileUrl) ?>" alt="<?= h($file['original_name']) ?>" style="width:100%;height:120px;object-fit:cover">
                <?php else: ?>
                <div class="d-flex align-items-center justify-content-center bg-light" style="height:120px">
                    <i class="fas <?= $fileIcon ?>" style="font-size:2.5rem"></i>
                </div>
                <?php endif; ?>
                <div class="card-body p-2">
                    <div class="small fw-semibold text-dark text-truncate"><?= h($file['original_name']) ?></div>
                    <?php if (!empty($file['description'])): ?>
                    <div class="text-muted" style="font-size:.75rem"><?= h(mb_strimwidth($file['description'], 0, 60, '...')) ?></div>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:.7rem"><?= date('d.m.Y', strtotime($file['created_at'])) ?></div>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h5 class="fw-bold text-muted mb-3"><i class="fas fa-user-shield me-2"></i>Moje galerie</h5>
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-lg-4 col-xl-3">
        <a href="<?= BASE_URL ?>/gallery_folder.php?mine=1" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 text-center p-3 gallery-folder-card">
                <div class="folder-icon mb-2"><i class="fas fa-folder-open text-primary" style="font-size:2.5rem"></i></div>
                <div class="fw-semibold small text-dark">Moje soubory</div>
                <div class="text-muted" style="font-size:.75rem"><?= $mineCount ?> souboru</div>
            </div>
        </a>
    </div>
</div>

<h5 class="fw-bold text-muted mb-3"><i class="fas fa-folder me-2"></i>Moje slozky</h5>
<?php if (empty($customFolders)): ?>
<div class="alert alert-light border mb-4">
    <i class="fas fa-folder-open me-2 text-muted"></i>
    Zatim nemate zadne vlastni slozky.
</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <?php foreach ($customFolders as $f): ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <div class="card border-0 shadow-sm h-100 text-center p-3 gallery-folder-card position-relative">
            <a href="<?= BASE_URL ?>/gallery_folder.php?id=<?= $f['id'] ?>" class="text-decoration-none stretched-link">
                <div class="folder-icon mb-2"><i class="fas fa-folder text-primary" style="font-size:2.5rem"></i></div>
                <div class="fw-semibold small text-dark"><?= h($f['name']) ?></div>
                <div class="text-muted" style="font-size:.75rem"><?= (int)$f['file_count'] ?> souboru</div>
            </a>
            <div class="dropdown position-absolute top-0 end-0 mt-1 me-1" style="z-index:2">
                <button class="btn btn-sm btn-link text-muted p-0 px-1" data-bs-toggle="dropdown" onclick="event.preventDefault();event.stopPropagation()">
                    <i class="fas fa-ellipsis-v"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li>
                        <button class="dropdown-item" onclick="event.preventDefault();event.stopPropagation();openRenameModal(<?= $f['id'] ?>, <?= json_encode($f['name']) ?>)">
                            <i class="fas fa-pencil me-2 text-primary"></i>Prejmenovat
                        </button>
                    </li>
                    <li>
                        <button class="dropdown-item text-danger" onclick="event.preventDefault();event.stopPropagation();confirmDeleteFolder(<?= $f['id'] ?>, <?= json_encode($f['name']) ?>)">
                            <i class="fas fa-trash me-2"></i>Smazat
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h5 class="fw-bold text-muted mb-3"><i class="fas fa-users me-2"></i>Slozky sportovcu</h5>
<?php if (empty($athleteFolders)): ?>
<div class="alert alert-light border mb-4">
    <i class="fas fa-user-plus me-2 text-muted"></i>
    Zatim nemate zadne sportovce. Po vytvoreni sportovce se jeho slozka v galerii vytvori automaticky.
</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <?php foreach ($athleteFolders as $f): ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <a href="<?= BASE_URL ?>/gallery_folder.php?id=<?= $f['id'] ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 text-center p-3 gallery-folder-card athlete-folder">
                <?php if ($f['athlete_photo']): ?>
                <img src="<?= h(photoUrl($f['athlete_photo'], 'athletes')) ?>" alt=""
                     class="rounded-circle mb-2 mx-auto d-block"
                     style="width:52px;height:52px;object-fit:cover;border:2px solid #ffc107">
                <?php else: ?>
                <div class="folder-icon mb-2"><i class="fas fa-folder text-warning" style="font-size:2.5rem"></i></div>
                <?php endif; ?>
                <div class="fw-semibold small text-dark"><?= h($f['first_name'] . ' ' . $f['last_name']) ?></div>
                <div class="text-muted" style="font-size:.75rem"><?= (int)$f['shared_count'] ?> sdilenych</div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.gallery-folder-card {
    cursor: pointer;
    transition: transform .15s, box-shadow .15s;
}
.gallery-folder-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 .5rem 1rem rgba(0,0,0,.15) !important;
}
.athlete-folder { border-top: 3px solid #ffc107 !important; }
</style>

<div class="modal fade" id="modalNewFolder" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_folder">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-folder-plus me-2"></i>Nova slozka</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Nazev slozky</label>
                <input type="text" name="folder_name" class="form-control" maxlength="200" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrusit</button>
                <button type="submit" class="btn btn-primary">Vytvorit</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalRenameFolder" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="rename_folder">
            <input type="hidden" name="folder_id" id="renameFolderId">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pencil me-2"></i>Prejmenovat slozku</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Novy nazev</label>
                <input type="text" name="folder_name" id="renameFolderName" class="form-control" maxlength="200" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrusit</button>
                <button type="submit" class="btn btn-primary">Ulozit</button>
            </div>
        </form>
    </div>
</div>

<form method="post" id="deleteFolderForm" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="delete_folder">
    <input type="hidden" name="folder_id" id="deleteFolderId">
</form>

<script>
function openRenameModal(id, name) {
    document.getElementById('renameFolderId').value = id;
    document.getElementById('renameFolderName').value = name;
    new bootstrap.Modal(document.getElementById('modalRenameFolder')).show();
}
function confirmDeleteFolder(id, name) {
    const msg = 'Opravdu smazat slozku "' + name + '"? Souborum zustane pristup v Moje soubory.';
    if (confirm(msg)) {
        document.getElementById('deleteFolderId').value = id;
        document.getElementById('deleteFolderForm').submit();
    }
}
</script>

<?php renderFooter(); ?>
