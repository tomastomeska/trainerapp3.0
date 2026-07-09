<?php
// videos.php – prehled videosekce trenera
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect(BASE_URL . '/videos.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_folder') {
        $name = trim($_POST['folder_name'] ?? '');
        if ($name === '') {
            flash('danger', 'Nazev slozky nesmi byt prazdny.');
        } else {
            $name = mb_substr($name, 0, 200, 'UTF-8');
            $pdo->prepare("INSERT INTO video_folders (coach_id, name, folder_type, sort_order) VALUES (?, ?, 'custom', 0)")
                ->execute([$coachId, $name]);
            flash('success', 'Slozka byla vytvorena.');
        }
        redirect(BASE_URL . '/videos.php');
    }

    if ($action === 'rename_folder') {
        $folderId = intParam($_POST, 'folder_id');
        $name = trim($_POST['folder_name'] ?? '');
        if ($name === '') {
            flash('danger', 'Nazev slozky nesmi byt prazdny.');
        } else {
            $name = mb_substr($name, 0, 200, 'UTF-8');
            $pdo->prepare("UPDATE video_folders SET name = ? WHERE id = ? AND coach_id = ? AND folder_type = 'custom'")
                ->execute([$name, $folderId, $coachId]);
            flash('success', 'Slozka byla prejmenovana.');
        }
        redirect(BASE_URL . '/videos.php');
    }

    if ($action === 'delete_folder') {
        $folderId = intParam($_POST, 'folder_id');
        $checkFolder = $pdo->prepare("SELECT id FROM video_folders WHERE id = ? AND coach_id = ? AND folder_type = 'custom'");
        $checkFolder->execute([$folderId, $coachId]);
        if ($checkFolder->fetch()) {
            $pdo->prepare('UPDATE video_files SET folder_id = NULL WHERE coach_id = ? AND folder_id = ?')
                ->execute([$coachId, $folderId]);
            $pdo->prepare("DELETE FROM video_folders WHERE id = ? AND coach_id = ? AND folder_type = 'custom'")
                ->execute([$folderId, $coachId]);
            flash('success', 'Slozka byla smazana. Videa zustala v Moje videa.');
        }
        redirect(BASE_URL . '/videos.php');
    }
}

$mineCountStmt = $pdo->prepare('SELECT COUNT(*) FROM video_files WHERE coach_id = ?');
$mineCountStmt->execute([$coachId]);
$mineCount = (int)$mineCountStmt->fetchColumn();

$customFoldersStmt = $pdo->prepare(
    "SELECT f.id, f.name, COUNT(vf.id) AS file_count
     FROM video_folders f
     LEFT JOIN video_files vf ON vf.folder_id = f.id
     WHERE f.coach_id = ?
       AND f.folder_type = 'custom'
     GROUP BY f.id, f.name
     ORDER BY f.name ASC"
);
$customFoldersStmt->execute([$coachId]);
$customFolders = $customFoldersStmt->fetchAll();

$athleteFoldersStmt = $pdo->prepare(
    "SELECT
        f.id,
        f.athlete_id,
        a.first_name,
        a.last_name,
        a.photo AS athlete_photo,
        (
            SELECT COUNT(*)
            FROM video_files vf
            WHERE vf.coach_id = f.coach_id
              AND (
                  vf.visibility = 'all_athletes'
                  OR (
                      vf.visibility = 'specific_athletes'
                      AND EXISTS (
                          SELECT 1
                          FROM video_file_athletes vfa
                          WHERE vfa.file_id = vf.id
                            AND vfa.athlete_id = f.athlete_id
                      )
                  )
              )
        ) AS shared_count
     FROM video_folders f
     INNER JOIN athletes a ON a.id = f.athlete_id
     WHERE f.coach_id = ?
       AND f.folder_type = 'athlete'
     ORDER BY a.first_name ASC, a.last_name ASC"
);
$athleteFoldersStmt->execute([$coachId]);
$athleteFolders = $athleteFoldersStmt->fetchAll();

renderHeader('Videa', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-video me-2 text-danger"></i>Videa</h2>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNewFolder">
            <i class="fas fa-folder-plus me-1"></i>Nova slozka
        </button>
        <a href="<?= BASE_URL ?>/video_upload.php" class="btn btn-danger btn-sm fw-bold">
            <i class="fas fa-cloud-upload-alt me-1"></i>Nahrat video
        </a>
    </div>
</div>

<div class="alert alert-light border mb-4">
    <i class="fas fa-circle-info me-2 text-muted"></i>
    Videa nahravate do sve videosekce. U kazdeho videa nastavite, kterym sportovcum bude dostupne.
</div>

<h5 class="fw-bold text-muted mb-3"><i class="fas fa-user-shield me-2"></i>Moje videa</h5>
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-lg-4 col-xl-3">
        <a href="<?= BASE_URL ?>/video_folder.php?mine=1" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 text-center p-3 video-folder-card">
                <div class="folder-icon mb-2"><i class="fas fa-film text-danger" style="font-size:2.4rem"></i></div>
                <div class="fw-semibold small text-dark">Moje videa</div>
                <div class="text-muted" style="font-size:.75rem"><?= $mineCount ?> videi</div>
            </div>
        </a>
    </div>
</div>

<h5 class="fw-bold text-muted mb-3"><i class="fas fa-folder me-2"></i>Moje slozky</h5>
<?php if (empty($customFolders)): ?>
<div class="alert alert-light border mb-4">
    <i class="fas fa-folder-open me-2 text-muted"></i>
    Zatim nemate zadne vlastni slozky pro videa.
</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <?php foreach ($customFolders as $f): ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <div class="card border-0 shadow-sm h-100 text-center p-3 video-folder-card position-relative">
            <a href="<?= BASE_URL ?>/video_folder.php?id=<?= $f['id'] ?>" class="text-decoration-none stretched-link">
                <div class="folder-icon mb-2"><i class="fas fa-folder text-primary" style="font-size:2.4rem"></i></div>
                <div class="fw-semibold small text-dark"><?= h($f['name']) ?></div>
                <div class="text-muted" style="font-size:.75rem"><?= (int)$f['file_count'] ?> videi</div>
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
    Zatim nemate zadne sportovce. Po vytvoreni sportovce se jeho slozka ve videosekci vytvori automaticky.
</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <?php foreach ($athleteFolders as $f): ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <a href="<?= BASE_URL ?>/video_folder.php?id=<?= $f['id'] ?>" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 text-center p-3 video-folder-card athlete-folder">
                <?php if ($f['athlete_photo']): ?>
                <img src="<?= h(photoUrl($f['athlete_photo'], 'athletes')) ?>" alt=""
                     class="rounded-circle mb-2 mx-auto d-block"
                     style="width:52px;height:52px;object-fit:cover;border:2px solid #dc3545">
                <?php else: ?>
                <div class="folder-icon mb-2"><i class="fas fa-folder text-danger" style="font-size:2.4rem"></i></div>
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
.video-folder-card {
    cursor: pointer;
    transition: transform .15s, box-shadow .15s;
}
.video-folder-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 .5rem 1rem rgba(0,0,0,.15) !important;
}
.athlete-folder { border-top: 3px solid #dc3545 !important; }
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

<form method="post" id="deleteFolderForm" class="d-none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="delete_folder">
    <input type="hidden" name="folder_id" id="deleteFolderId">
</form>

<script>
function openRenameModal(folderId, folderName) {
    const idInput = document.getElementById('renameFolderId');
    const nameInput = document.getElementById('renameFolderName');
    if (!idInput || !nameInput) return;
    idInput.value = folderId;
    nameInput.value = folderName || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRenameFolder')).show();
}

function confirmDeleteFolder(folderId, folderName) {
    const label = folderName || 'tuto slozku';
    if (!confirm('Opravdu smazat slozku "' + label + '"? Videa zustanou v Moje videa.')) return;
    const idInput = document.getElementById('deleteFolderId');
    const form = document.getElementById('deleteFolderForm');
    if (!idInput || !form) return;
    idInput.value = folderId;
    form.submit();
}
</script>

<?php renderFooter(); ?>
