<?php
// gallery_file_detail.php – detail a nastaveni souboru trenera
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo     = getDB();

$fileId = intParam($_GET, 'id');
$stmt   = $pdo->prepare("SELECT * FROM gallery_files WHERE id = ? AND coach_id = ?");
$stmt->execute([$fileId, $coachId]);
$file = $stmt->fetch();

if (!$file) {
    flash('danger', 'Soubor nebyl nalezen.');
    redirect(BASE_URL . '/gallery.php');
}

$athletes = $pdo->prepare("SELECT id, first_name, last_name FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name");
$athletes->execute([$coachId]);
$athletes = $athletes->fetchAll();
$athleteIds = array_map('intval', array_column($athletes, 'id'));

$customFolders = $pdo->prepare("SELECT id, name FROM gallery_folders WHERE coach_id = ? AND folder_type = 'custom' ORDER BY name ASC");
$customFolders->execute([$coachId]);
$customFolders = $customFolders->fetchAll();
$customFolderIds = array_map('intval', array_column($customFolders, 'id'));

$visAthletes = $pdo->prepare("SELECT athlete_id FROM gallery_file_athletes WHERE file_id = ?");
$visAthletes->execute([$fileId]);
$visAthleteIds = array_map('intval', array_column($visAthletes->fetchAll(), 'athlete_id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if (($_POST['action'] ?? '') === 'update') {
        $description = trim($_POST['description'] ?? '');
        $visibility  = in_array($_POST['visibility'] ?? '', ['private', 'all_athletes', 'specific_athletes'], true)
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
            flash('danger', 'Pro sdileni s vybranymi sportovci zvolte alespon jednoho sportovce.');
            redirect($_SERVER['REQUEST_URI']);
        }

        $pdo->prepare("UPDATE gallery_files SET description = ?, visibility = ?, folder_id = ? WHERE id = ? AND coach_id = ?")
            ->execute([$description ?: null, $visibility, $folderId > 0 ? $folderId : null, $fileId, $coachId]);

        $pdo->prepare("DELETE FROM gallery_file_athletes WHERE file_id = ?")->execute([$fileId]);
        if ($visibility === 'specific_athletes') {
            $insVis = $pdo->prepare("INSERT IGNORE INTO gallery_file_athletes (file_id, athlete_id) VALUES (?, ?)");
            foreach ($specificIds as $aid) {
                $insVis->execute([$fileId, $aid]);
            }
        }

        flash('success', 'Nastaveni souboru bylo ulozeno.');
        redirect($_SERVER['REQUEST_URI']);
    }
}

$fileRefresh = $pdo->prepare("SELECT * FROM gallery_files WHERE id = ? AND coach_id = ?");
$fileRefresh->execute([$fileId, $coachId]);
$file = $fileRefresh->fetch();

$visAthletes->execute([$fileId]);
$visAthleteIds = array_map('intval', array_column($visAthletes->fetchAll(), 'athlete_id'));

$fileSrc = BASE_URL . '/uploads/gallery/coach_' . $coachId . '/' . rawurlencode($file['file_path']);

renderHeader(h($file['original_name']));
?>

<div class="d-flex align-items-center mb-4 gap-3 flex-wrap">
    <a href="<?= BASE_URL ?>/gallery_folder.php?mine=1" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <nav aria-label="breadcrumb" class="flex-grow-1">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/gallery.php">Galerie</a></li>
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/gallery_folder.php?mine=1">Moje soubory</a></li>
            <li class="breadcrumb-item active"><?= h(mb_strimwidth($file['original_name'], 0, 40, '...')) ?></li>
        </ol>
    </nav>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center p-4">
                <?php if ($file['file_type'] === 'image'): ?>
                <img src="<?= $fileSrc ?>" alt="<?= h($file['original_name']) ?>"
                     class="img-fluid rounded" style="max-height:500px;">
                <?php elseif ($file['file_type'] === 'video'): ?>
                <video controls class="w-100 rounded" style="max-height:500px">
                    <source src="<?= $fileSrc ?>" type="<?= h($file['mime_type'] ?: 'video/mp4') ?>">
                    Vas prohlizec nepodporuje prehravani videa.
                </video>
                <?php else: ?>
                <div>
                    <iframe src="<?= $fileSrc ?>" style="width:100%;height:72vh;border:0;border-radius:.5rem;background:#fff"></iframe>
                    <div class="mt-3 text-center">
                        <a href="<?= $fileSrc ?>" download="<?= h($file['original_name']) ?>" class="btn btn-outline-success">
                            <i class="fas fa-download me-2"></i>Stahnout soubor
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer text-muted small d-flex justify-content-between flex-wrap gap-1">
                <span><i class="fas fa-file me-1"></i><?= h($file['original_name']) ?></span>
                <span><?= round($file['file_size'] / 1024, 1) ?> KB</span>
                <span><?= date('d.m.Y H:i', strtotime($file['created_at'])) ?></span>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
                <i class="fas fa-cog me-2"></i>Nastaveni souboru
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="update">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Popis</label>
                        <textarea name="description" class="form-control" rows="3" maxlength="1000"><?= h($file['description'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Vlastni slozka</label>
                        <select name="folder_id" class="form-select">
                            <option value="0">Bez slozky</option>
                            <?php foreach ($customFolders as $folder): ?>
                            <option value="<?= (int)$folder['id'] ?>" <?= (int)($file['folder_id'] ?? 0) === (int)$folder['id'] ? 'selected' : '' ?>>
                                <?= h($folder['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Viditelnost</label>
                        <select name="visibility" class="form-select" id="visSelect">
                            <option value="private" <?= $file['visibility'] === 'private' ? 'selected' : '' ?>>
                                Soukromy - pouze ja
                            </option>
                            <?php if (!empty($athletes)): ?>
                            <option value="all_athletes" <?= $file['visibility'] === 'all_athletes' ? 'selected' : '' ?>>
                                Sdilet se vsemi mymi sportovci
                            </option>
                            <option value="specific_athletes" <?= $file['visibility'] === 'specific_athletes' ? 'selected' : '' ?>>
                                Sdilet s vybranymi sportovci
                            </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <?php if (!empty($athletes)): ?>
                    <div id="specificAthletes" class="mb-3 <?= $file['visibility'] === 'specific_athletes' ? '' : 'd-none' ?>">
                        <label class="form-label fw-semibold">Vybrani sportovci</label>
                        <div class="row g-2">
                            <?php foreach ($athletes as $a): ?>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="specific_athletes[]" value="<?= $a['id'] ?>"
                                           id="ath<?= $a['id'] ?>"
                                           <?= in_array((int)$a['id'], $visAthleteIds, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="ath<?= $a['id'] ?>">
                                        <?= h($a['first_name'] . ' ' . $a['last_name']) ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-1"></i>Ulozit nastaveni
                    </button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header fw-semibold">Kde se soubor zobrazi</div>
            <div class="card-body">
                <?php if ($file['visibility'] === 'private'): ?>
                <div class="text-muted small">Pouze ve vasi galerii.</div>
                <?php elseif ($file['visibility'] === 'all_athletes'): ?>
                <div class="text-muted small">Ve vasi galerii a ve slozce vsech vasich sportovcu.</div>
                <?php else: ?>
                <div class="text-muted small mb-2">Ve vasi galerii a ve slozkach vybranych sportovcu:</div>
                <?php if (empty($visAthleteIds)): ?>
                <div class="text-danger small">Neni vybran zadny sportovec.</div>
                <?php else: ?>
                <ul class="small mb-0 ps-3">
                    <?php foreach ($athletes as $a): ?>
                    <?php if (in_array((int)$a['id'], $visAthleteIds, true)): ?>
                    <li><?= h($a['first_name'] . ' ' . $a['last_name']) ?></li>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body d-flex gap-2">
                <a href="<?= $fileSrc ?>" download="<?= h($file['original_name']) ?>" class="btn btn-outline-success flex-grow-1">
                    <i class="fas fa-download me-1"></i>Stahnout
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('visSelect')?.addEventListener('change', function () {
    document.getElementById('specificAthletes')?.classList.toggle('d-none', this.value !== 'specific_athletes');
});
</script>

<?php renderFooter(); ?>
