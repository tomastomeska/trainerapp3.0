<?php
// athlete_videos.php – videosekce pro prihlaseneho sportovce (prohlizeni)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();
$athleteId = getCurrentAthleteId();
$pdo = getDB();

$athlete = $pdo->prepare('SELECT a.*, c.id AS coach_id FROM athletes a JOIN coaches c ON c.id = a.coach_id WHERE a.id = ?');
$athlete->execute([$athleteId]);
$athlete = $athlete->fetch();
if (!$athlete) {
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$coachId = (int)$athlete['coach_id'];

$files = $pdo->prepare(
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
$files->execute([$coachId, $athleteId]);
$sharedFiles = $files->fetchAll();

$sharedFiles = array_values(array_filter($sharedFiles, static function (array $f) use ($coachId): bool {
    $full = __DIR__ . '/uploads/videos/coach_' . $coachId . '/' . ($f['file_path'] ?? '');
    return is_string($f['file_path'] ?? null) && $f['file_path'] !== '' && is_file($full);
}));

function buildVideoSignature(array $files): string
{
    $parts = [];
    foreach ($files as $f) {
        $parts[] = implode('|', [
            (string)($f['id'] ?? ''),
            (string)($f['file_path'] ?? ''),
            (string)($f['created_at'] ?? ''),
            (string)($f['visibility'] ?? ''),
        ]);
    }
    return sha1(implode(';', $parts));
}

function renderSharedVideosSection(array $sharedFiles): string
{
    ob_start();
    if (empty($sharedFiles)): ?>
<div class="alert alert-light border text-center py-5">
    <i class="fas fa-video fa-3x text-muted mb-3 d-block"></i>
    <p class="text-muted mb-0">Zatim zde nejsou zadna sdilena videa.</p>
</div>
<?php else: ?>
<div class="mb-5">
    <h5 class="fw-bold mb-3">
        <i class="fas fa-user-check me-2 text-danger"></i>Od trenera
        <span class="badge bg-secondary ms-2" style="font-size:.75rem"><?= count($sharedFiles) ?></span>
    </h5>
    <div class="row g-3">
        <?php foreach ($sharedFiles as $f): ?>
        <?php $videoSrc = BASE_URL . '/video_stream.php?id=' . (int)$f['id']; ?>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100 athlete-video-card">
                <button type="button" class="btn p-0 border-0 d-block w-100 text-start" onclick="openVideoPreview('<?= h($videoSrc) ?>', '<?= h(addslashes($f['original_name'])) ?>', '<?= h($f['mime_type'] ?: 'video/mp4') ?>')">
                    <div class="video-thumb-wrap position-relative overflow-hidden" style="height:130px;background:#111;border-radius:.4rem .4rem 0 0">
                        <video class="video-hover-preview" preload="metadata" muted playsinline disablePictureInPicture controlsList="nodownload" oncontextmenu="return false;" data-preview>
                            <source src="<?= h($videoSrc) ?>" type="<?= h($f['mime_type'] ?: 'video/mp4') ?>">
                        </video>
                        <div class="video-overlay"><i class="fas fa-play-circle"></i></div>
                    </div>
                </button>
                <div class="card-body p-2">
                    <div class="small fw-semibold text-truncate"><?= h($f['original_name']) ?></div>
                    <?php if ($f['description']): ?>
                    <div class="text-muted" style="font-size:.75rem"><?= h(mb_strimwidth($f['description'], 0, 60, '...')) ?></div>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:.7rem"><?= date('d.m.Y', strtotime($f['created_at'])) ?></div>
                    <button type="button" class="btn btn-sm btn-outline-danger w-100 mt-1" style="font-size:.75rem" onclick="openVideoPreview('<?= h($videoSrc) ?>', '<?= h(addslashes($f['original_name'])) ?>', '<?= h($f['mime_type'] ?: 'video/mp4') ?>')">
                        <i class="fas fa-play me-1"></i>Prehrat
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php
    endif;
    return (string)ob_get_clean();
}

$sharedSignature = buildVideoSignature($sharedFiles);
$sharedHtml = renderSharedVideosSection($sharedFiles);

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'signature' => $sharedSignature,
        'html' => $sharedHtml,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

renderAthleteHeader('Videa', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-video me-2 text-danger"></i>Videa</h2>
    <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-house me-1"></i>Domu
    </a>
</div>

<div id="athleteVideosSharedSection"><?= $sharedHtml ?></div>

<div class="modal fade" id="videoPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-md-down modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-truncate" id="videoPreviewTitle">Nahled videa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-2 p-md-3" id="videoPreviewBody" style="min-height:50vh;background:#0b0b0f"></div>
        </div>
    </div>
</div>

<style>
.athlete-video-card { transition: transform .15s, box-shadow .15s; }
.athlete-video-card:hover { transform: translateY(-2px); box-shadow: 0 .4rem .9rem rgba(0,0,0,.15) !important; }

.video-thumb-wrap video {
    width: 100%;
    height: 130px;
    object-fit: cover;
    display: block;
    transition: transform .25s ease;
}
.athlete-video-card:hover .video-thumb-wrap video { transform: scale(1.04); }

.video-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255,255,255,.92);
    font-size: 2rem;
    background: linear-gradient(180deg, rgba(0,0,0,.08) 0%, rgba(0,0,0,.42) 100%);
    pointer-events: none;
}
</style>

<script>
let videoSignature = <?= json_encode($sharedSignature) ?>;

function openVideoPreview(src, name, mime) {
    const title = document.getElementById('videoPreviewTitle');
    const body = document.getElementById('videoPreviewBody');
    if (!title || !body) return;

    title.textContent = name || 'Nahled videa';
    body.innerHTML = '' +
        '<video controls autoplay playsinline disablePictureInPicture controlsList="nodownload noplaybackrate" oncontextmenu="return false;" style="width:100%;max-height:75vh;border-radius:.6rem;background:#000">' +
        '  <source src="' + src + '" type="' + (mime || 'video/mp4') + '">' +
        '</video>';

    const modalEl = document.getElementById('videoPreviewModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

(function () {
    const isHoverDevice = window.matchMedia('(hover: hover)').matches;
    if (!isHoverDevice) return;

    document.addEventListener('mouseenter', function (e) {
        const card = e.target.closest('.athlete-video-card');
        if (!card) return;
        const video = card.querySelector('[data-preview]');
        if (!video) return;

        video.currentTime = 0;
        const p = video.play();
        if (p && typeof p.then === 'function') p.catch(function () {});

        setTimeout(function () {
            if (!video.paused) {
                video.pause();
                video.currentTime = 0;
            }
        }, 2500);
    }, true);

    document.addEventListener('mouseleave', function (e) {
        const card = e.target.closest('.athlete-video-card');
        if (!card) return;
        const video = card.querySelector('[data-preview]');
        if (!video) return;

        video.pause();
        video.currentTime = 0;
    }, true);
})();

async function pollAthleteVideos() {
    try {
        const response = await fetch('<?= BASE_URL ?>/athlete_videos.php?ajax=1&t=' + Date.now(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) return;

        const data = await response.json();
        if (!data || !data.ok || !data.signature) return;

        if (data.signature !== videoSignature) {
            const section = document.getElementById('athleteVideosSharedSection');
            if (!section) return;
            section.innerHTML = data.html || '';
            videoSignature = data.signature;
        }
    } catch (e) {
        // Silent fail; next poll retries.
    }
}

setInterval(pollAthleteVideos, 10000);
</script>

<?php renderAthleteFooter(); ?>
