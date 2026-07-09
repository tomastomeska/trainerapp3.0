<?php
// video_detail.php – detail a nastaveni videa trenera
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo = getDB();

$fileId = intParam($_GET, 'id');
$stmt = $pdo->prepare('SELECT * FROM video_files WHERE id = ? AND coach_id = ?');
$stmt->execute([$fileId, $coachId]);
$file = $stmt->fetch();

if (!$file) {
    flash('danger', 'Video nebylo nalezeno.');
    redirect(BASE_URL . '/videos.php');
}

$athletesStmt = $pdo->prepare('SELECT id, first_name, last_name FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name');
$athletesStmt->execute([$coachId]);
$athletes = $athletesStmt->fetchAll();
$athleteIds = array_map('intval', array_column($athletes, 'id'));

$customFoldersStmt = $pdo->prepare("SELECT id, name FROM video_folders WHERE coach_id = ? AND folder_type = 'custom' ORDER BY name ASC");
$customFoldersStmt->execute([$coachId]);
$customFolders = $customFoldersStmt->fetchAll();
$customFolderIds = array_map('intval', array_column($customFolders, 'id'));

$visAthletes = $pdo->prepare('SELECT athlete_id FROM video_file_athletes WHERE file_id = ?');
$visAthletes->execute([$fileId]);
$visAthleteIds = array_map('intval', array_column($visAthletes->fetchAll(), 'athlete_id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if (($_POST['action'] ?? '') === 'update') {
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
            flash('danger', 'Pro sdileni s vybranymi sportovci zvolte alespon jednoho sportovce.');
            redirect($_SERVER['REQUEST_URI']);
        }

        $pdo->prepare('UPDATE video_files SET description = ?, visibility = ?, folder_id = ? WHERE id = ? AND coach_id = ?')
            ->execute([$description !== '' ? $description : null, $visibility, $folderId > 0 ? $folderId : null, $fileId, $coachId]);

        $pdo->prepare('DELETE FROM video_file_athletes WHERE file_id = ?')->execute([$fileId]);
        if ($visibility === 'specific_athletes') {
            $ins = $pdo->prepare('INSERT IGNORE INTO video_file_athletes (file_id, athlete_id) VALUES (?, ?)');
            foreach ($specificIds as $aid) {
                $ins->execute([$fileId, $aid]);
            }
        }

        flash('success', 'Nastaveni videa bylo ulozeno.');
        redirect($_SERVER['REQUEST_URI']);
    }
}

$fileRefresh = $pdo->prepare('SELECT * FROM video_files WHERE id = ? AND coach_id = ?');
$fileRefresh->execute([$fileId, $coachId]);
$file = $fileRefresh->fetch();

$visAthletes->execute([$fileId]);
$visAthleteIds = array_map('intval', array_column($visAthletes->fetchAll(), 'athlete_id'));

$videoSrc = BASE_URL . '/video_stream.php?id=' . (int)$fileId;

renderHeader(h($file['original_name']));
?>

<div class="d-flex align-items-center mb-4 gap-3 flex-wrap">
    <a href="<?= BASE_URL ?>/video_folder.php?mine=1" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <nav aria-label="breadcrumb" class="flex-grow-1">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/videos.php">Videa</a></li>
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/video_folder.php?mine=1">Moje videa</a></li>
            <li class="breadcrumb-item active"><?= h(mb_strimwidth($file['original_name'], 0, 40, '...')) ?></li>
        </ol>
    </nav>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm overflow-hidden">
            <div class="video-stage position-relative">
                <video id="coachVideoPlayer" controls playsinline disablePictureInPicture controlsList="nodownload noplaybackrate" oncontextmenu="return false;" class="w-100">
                    <source src="<?= h($videoSrc) ?>" type="<?= h($file['mime_type'] ?: 'video/mp4') ?>">
                    Vas prohlizec nepodporuje prehravani videa.
                </video>
                <div class="video-glow"></div>
            </div>
            <div class="card-footer text-muted small d-flex justify-content-between flex-wrap gap-1">
                <span><i class="fas fa-file-video me-1"></i><?= h($file['original_name']) ?></span>
                <span><?= round(((float)$file['file_size']) / (1024 * 1024), 1) ?> MB</span>
                <span><?= date('d.m.Y H:i', strtotime($file['created_at'])) ?></span>
            </div>
        </div>

        <div class="alert alert-warning border mt-3 mb-0 small">
            <i class="fas fa-shield-halved me-1"></i>
            Stahovani je na prehravaci blokovane. Data videa jsou streamovana jen prihlasenym uzivatelum s opravnenim.
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
                <i class="fas fa-cog me-2"></i>Nastaveni videa
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
                            <option value="private" <?= $file['visibility'] === 'private' ? 'selected' : '' ?>>Soukrome - pouze ja</option>
                            <?php if (!empty($athletes)): ?>
                            <option value="all_athletes" <?= $file['visibility'] === 'all_athletes' ? 'selected' : '' ?>>Sdilet se vsemi mymi sportovci</option>
                            <option value="specific_athletes" <?= $file['visibility'] === 'specific_athletes' ? 'selected' : '' ?>>Sdilet s vybranymi sportovci</option>
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
                                    <input class="form-check-input" type="checkbox" name="specific_athletes[]" value="<?= $a['id'] ?>" id="ath<?= $a['id'] ?>" <?= in_array((int)$a['id'], $visAthleteIds, true) ? 'checked' : '' ?>>
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
            <div class="card-header fw-semibold">Kde se video zobrazi</div>
            <div class="card-body">
                <?php if ($file['visibility'] === 'private'): ?>
                <div class="text-muted small">Pouze ve vasi videosekci.</div>
                <?php elseif ($file['visibility'] === 'all_athletes'): ?>
                <div class="text-muted small">Ve vasich videich a ve slozce vsech vasich sportovcu.</div>
                <?php else: ?>
                <div class="text-muted small mb-2">Ve vasich videich a ve slozkach vybranych sportovcu:</div>
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
    </div>
</div>

<style>
.video-stage {
    background: radial-gradient(circle at 10% 10%, #2b3035 0, #121417 60%, #0b0c0e 100%);
    padding: 10px;
}
.video-stage video {
    border-radius: 10px;
    max-height: 72vh;
    background: #000;
}
.video-glow {
    pointer-events: none;
    position: absolute;
    inset: 0;
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 8px;
}
</style>

<script>
document.getElementById('visSelect')?.addEventListener('change', function () {
    document.getElementById('specificAthletes')?.classList.toggle('d-none', this.value !== 'specific_athletes');
});
</script>

<?php renderFooter(); ?>
