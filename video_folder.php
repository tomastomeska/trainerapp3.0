<?php
// video_folder.php – moje videa nebo sdilena videa konkretniho sportovce
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo = getDB();

$mine = isset($_GET['mine']);
$folderId = intParam($_GET, 'id');

$folderName = 'Moje videa';
$isAthlete = false;
$isCustom = false;
$athleteId = 0;
$uploadUrl = BASE_URL . '/video_upload.php';

$allAthleteIdsStmt = $pdo->prepare('SELECT id FROM athletes WHERE coach_id = ?');
$allAthleteIdsStmt->execute([$coachId]);
$allAthleteIds = array_map('intval', array_column($allAthleteIdsStmt->fetchAll(), 'id'));

$disableShareForAthlete = function (int $fileId, int $athleteId) use ($pdo, $coachId, $allAthleteIds): void {
    $f = $pdo->prepare('SELECT id, visibility FROM video_files WHERE id = ? AND coach_id = ?');
    $f->execute([$fileId, $coachId]);
    $f = $f->fetch();

    if (!$f) {
        return;
    }

    if ($f['visibility'] === 'specific_athletes') {
        $pdo->prepare('DELETE FROM video_file_athletes WHERE file_id = ? AND athlete_id = ?')
            ->execute([$fileId, $athleteId]);

        $remaining = $pdo->prepare('SELECT COUNT(*) FROM video_file_athletes WHERE file_id = ?');
        $remaining->execute([$fileId]);
        if ((int)$remaining->fetchColumn() === 0) {
            $pdo->prepare("UPDATE video_files SET visibility = 'private' WHERE id = ? AND coach_id = ?")
                ->execute([$fileId, $coachId]);
        }
        flash('success', 'Sdileni pro tohoto sportovce bylo vypnuto.');
        return;
    }

    if ($f['visibility'] === 'all_athletes') {
        $targetIds = array_values(array_filter($allAthleteIds, static fn($id) => $id !== $athleteId));
        if (empty($targetIds)) {
            $pdo->prepare("UPDATE video_files SET visibility = 'private' WHERE id = ? AND coach_id = ?")
                ->execute([$fileId, $coachId]);
            $pdo->prepare('DELETE FROM video_file_athletes WHERE file_id = ?')->execute([$fileId]);
        } else {
            $pdo->prepare("UPDATE video_files SET visibility = 'specific_athletes' WHERE id = ? AND coach_id = ?")
                ->execute([$fileId, $coachId]);
            $pdo->prepare('DELETE FROM video_file_athletes WHERE file_id = ?')->execute([$fileId]);
            $ins = $pdo->prepare('INSERT IGNORE INTO video_file_athletes (file_id, athlete_id) VALUES (?, ?)');
            foreach ($targetIds as $targetId) {
                $ins->execute([$fileId, $targetId]);
            }
        }
        flash('success', 'Sdileni pro tohoto sportovce bylo vypnuto.');
        return;
    }

    flash('warning', 'Video uz neni tomuto sportovci sdileno.');
};

if (!$mine) {
    $stmt = $pdo->prepare('SELECT f.*, a.first_name, a.last_name FROM video_folders f LEFT JOIN athletes a ON a.id = f.athlete_id WHERE f.id = ? AND f.coach_id = ?');
    $stmt->execute([$folderId, $coachId]);
    $folder = $stmt->fetch();

    if (!$folder) {
        flash('danger', 'Slozka nebyla nalezena.');
        redirect(BASE_URL . '/videos.php');
    }

    if (($folder['folder_type'] ?? '') === 'athlete') {
        $isAthlete = true;
        $athleteId = (int)$folder['athlete_id'];
        $folderName = trim((string)($folder['first_name'] ?? '') . ' ' . (string)($folder['last_name'] ?? ''));
        $uploadUrl = BASE_URL . '/video_upload.php?athlete_id=' . $athleteId;
    } else {
        $isCustom = true;
        $folderName = (string)($folder['name'] ?? 'Slozka');
        $uploadUrl = BASE_URL . '/video_upload.php?folder_id=' . (int)$folder['id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if (($_POST['action'] ?? '') === 'delete_file') {
        $fileId = intParam($_POST, 'file_id');
        if ($isAthlete && $athleteId > 0) {
            $disableShareForAthlete($fileId, $athleteId);
        } else {
            $f = $pdo->prepare('SELECT * FROM video_files WHERE id = ? AND coach_id = ?');
            $f->execute([$fileId, $coachId]);
            $f = $f->fetch();
            if ($f) {
                $full = __DIR__ . '/uploads/videos/coach_' . $coachId . '/' . $f['file_path'];
                if (file_exists($full)) {
                    @unlink($full);
                }
                $pdo->prepare('DELETE FROM video_files WHERE id = ? AND coach_id = ?')->execute([$fileId, $coachId]);
                flash('success', 'Video bylo smazano.');
            }
        }

        redirect($_SERVER['REQUEST_URI']);
    }

    if (($_POST['action'] ?? '') === 'disable_share_for_athlete' && $isAthlete && $athleteId > 0) {
        $fileId = intParam($_POST, 'file_id');
        $disableShareForAthlete($fileId, $athleteId);
        redirect($_SERVER['REQUEST_URI']);
    }
}

if ($mine) {
    $stmt = $pdo->prepare('SELECT * FROM video_files WHERE coach_id = ? ORDER BY created_at DESC');
    $stmt->execute([$coachId]);
} elseif ($isCustom) {
    $stmt = $pdo->prepare('SELECT * FROM video_files WHERE coach_id = ? AND folder_id = ? ORDER BY created_at DESC');
    $stmt->execute([$coachId, $folderId]);
} else {
    $stmt = $pdo->prepare(
        "SELECT vf.*
         FROM video_files vf
         WHERE vf.coach_id = ?
           AND (
               vf.visibility = 'all_athletes'
               OR (
                   vf.visibility = 'specific_athletes'
                   AND EXISTS (
                       SELECT 1
                       FROM video_file_athletes vfa
                       WHERE vfa.file_id = vf.id
                         AND vfa.athlete_id = ?
                   )
               )
           )
         ORDER BY vf.created_at DESC"
    );
    $stmt->execute([$coachId, $athleteId]);
}

$files = array_values(array_filter($stmt->fetchAll(), static function (array $f) use ($coachId): bool {
    $path = __DIR__ . '/uploads/videos/coach_' . $coachId . '/' . ($f['file_path'] ?? '');
    return is_string($f['file_path'] ?? null) && $f['file_path'] !== '' && is_file($path);
}));

renderHeader($folderName);
?>

<div class="d-flex align-items-center mb-4 gap-3 flex-wrap">
    <a href="<?= BASE_URL ?>/videos.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0 flex-grow-1">
        <i class="fas <?= $isAthlete ? 'fa-user text-danger' : ($isCustom ? 'fa-folder text-primary' : 'fa-film text-danger') ?> me-2"></i>
        <?= h($folderName) ?>
    </h2>
    <a href="<?= $uploadUrl ?>" class="btn btn-danger btn-sm fw-bold">
        <i class="fas fa-cloud-upload-alt me-1"></i>Nahrat video
    </a>
</div>

<?php if ($isAthlete): ?>
<div class="alert alert-light border mb-4">
    <i class="fas fa-share-alt me-2 text-danger"></i>
    Zde vidite videa, ktera jsou tomuto sportovci sdilena.
</div>
<?php endif; ?>

<?php if ($isCustom): ?>
<div class="alert alert-light border mb-4">
    <i class="fas fa-folder-open me-2 text-primary"></i>
    Zde vidite videa zarazena do teto vlastni slozky.
</div>
<?php endif; ?>

<?php if (empty($files)): ?>
<div class="alert alert-light border text-center py-5">
    <i class="fas fa-video-slash fa-3x text-muted mb-3 d-block"></i>
    <p class="text-muted mb-2">Zatim zde nejsou zadna videa.</p>
    <a href="<?= $uploadUrl ?>" class="btn btn-danger btn-sm">
        <i class="fas fa-cloud-upload-alt me-1"></i>Nahrat prvni video
    </a>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($files as $f): ?>
    <?php $videoSrc = BASE_URL . '/video_stream.php?id=' . (int)$f['id']; ?>
    <div class="col-12 col-sm-6 col-md-4 col-lg-3 col-xl-2">
        <div class="card border-0 shadow-sm h-100 position-relative video-file-card">
            <a href="<?= BASE_URL ?>/video_detail.php?id=<?= $f['id'] ?>" class="stretched-link text-decoration-none">
                <div class="video-thumb-wrap position-relative overflow-hidden rounded-top" style="height:130px;background:#111">
                    <video class="video-hover-preview" preload="metadata" muted playsinline disablePictureInPicture controlsList="nodownload" oncontextmenu="return false;" data-preview>
                        <source src="<?= h($videoSrc) ?>" type="<?= h($f['mime_type'] ?: 'video/mp4') ?>">
                    </video>
                    <div class="video-overlay"><i class="fas fa-play-circle"></i></div>
                </div>
                <div class="card-body p-2">
                    <div class="fw-semibold small text-dark text-truncate"><?= h($f['original_name']) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <span class="badge <?= match($f['visibility']) {
                            'all_athletes' => 'bg-success',
                            'specific_athletes' => 'bg-warning text-dark',
                            default => 'bg-secondary',
                        } ?>" style="font-size:.65rem">
                            <?= match($f['visibility']) {
                                'all_athletes' => 'Vsichni sportovci',
                                'specific_athletes' => 'Vybrani',
                                default => 'Soukrome',
                            } ?>
                        </span>
                        <span class="text-muted" style="font-size:.7rem"><?= date('d.m.Y', strtotime($f['created_at'])) ?></span>
                    </div>
                </div>
            </a>

            <?php if ($isAthlete): ?>
            <form method="post" class="position-absolute top-0 start-0 m-1" style="z-index:2" onsubmit="return confirm('Vypnout sdileni tohoto videa pro tohoto sportovce?')">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="disable_share_for_athlete">
                <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                <button class="btn btn-sm btn-warning px-2 py-1" style="font-size:.66rem;line-height:1" title="Vypnout sdileni pro tohoto sportovce">
                    <i class="fas fa-user-slash me-1"></i>Vypnout
                </button>
            </form>
            <?php else: ?>
            <form method="post" class="position-absolute top-0 end-0 m-1" style="z-index:2" onsubmit="return confirm('Smazat video <?= h(addslashes($f['original_name'])) ?>?')">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete_file">
                <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                <button class="btn btn-sm btn-danger p-0" style="width:22px;height:22px;font-size:.65rem;line-height:1" title="Smazat"><i class="fas fa-times"></i></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.video-file-card { transition: transform .15s, box-shadow .15s; }
.video-file-card:hover { transform: translateY(-2px); box-shadow: 0 .4rem .8rem rgba(0,0,0,.14) !important; }

.video-thumb-wrap video {
    width: 100%;
    height: 130px;
    object-fit: cover;
    display: block;
    transition: transform .25s ease;
}
.video-file-card:hover .video-thumb-wrap video { transform: scale(1.04); }

.video-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255,255,255,.9);
    font-size: 2rem;
    background: linear-gradient(180deg, rgba(0,0,0,.1) 0%, rgba(0,0,0,.35) 100%);
    pointer-events: none;
}
</style>

<script>
(function () {
    const isHoverDevice = window.matchMedia('(hover: hover)').matches;
    if (!isHoverDevice) return;

    document.querySelectorAll('[data-preview]').forEach(function (video) {
        let lastPlayToken = 0;

        video.closest('.video-file-card')?.addEventListener('mouseenter', function () {
            const token = ++lastPlayToken;
            video.currentTime = 0;
            const p = video.play();
            if (p && typeof p.then === 'function') {
                p.catch(function () {});
            }
            setTimeout(function () {
                if (token === lastPlayToken && !video.paused) {
                    video.pause();
                    video.currentTime = 0;
                }
            }, 2800);
        });

        video.closest('.video-file-card')?.addEventListener('mouseleave', function () {
            lastPlayToken++;
            video.pause();
            video.currentTime = 0;
        });
    });
})();
</script>

<?php renderFooter(); ?>
