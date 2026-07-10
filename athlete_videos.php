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
<?php
    $first = $sharedFiles[0];
    $firstSrc = BASE_URL . '/video_stream.php?id=' . (int)$first['id'];
    $firstMime = (string)($first['mime_type'] ?: 'video/mp4');
?>
<div class="mb-5">
    <h5 class="fw-bold mb-3">
        <i class="fas fa-user-check me-2 text-danger"></i>Od trenera
        <span class="badge bg-secondary ms-2" style="font-size:.75rem"><?= count($sharedFiles) ?></span>
    </h5>

    <div class="video-player-shell card border-0 shadow-sm mx-auto mb-4">
        <div class="video-stage">
            <video id="athleteMainVideo" controls playsinline disablePictureInPicture controlsList="nodownload noplaybackrate" oncontextmenu="return false;" class="w-100" preload="metadata">
                <source id="athleteMainVideoSource" src="<?= h($firstSrc) ?>" type="<?= h($firstMime) ?>">
                Vas prohlizec nepodporuje prehravani videa.
            </video>
        </div>
        <div class="p-3 p-md-4 border-top">
            <div id="athleteMainVideoTitle" class="fw-semibold fs-5 text-break"><?= h($first['original_name']) ?></div>
            <div class="small text-muted mt-1" id="athleteMainVideoMeta">
                <?= date('d.m.Y', strtotime($first['created_at'])) ?>
                <?php if (!empty($first['description'])): ?>
                | <?= h(mb_strimwidth((string)$first['description'], 0, 150, '...')) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3" id="athleteVideoPlaylist">
        <?php foreach ($sharedFiles as $idx => $f): ?>
        <?php
            $videoSrc = BASE_URL . '/video_stream.php?id=' . (int)$f['id'];
            $videoMime = (string)($f['mime_type'] ?: 'video/mp4');
            $meta = date('d.m.Y', strtotime($f['created_at']));
            if (!empty($f['description'])) {
                $meta .= ' | ' . mb_strimwidth((string)$f['description'], 0, 100, '...');
            }
        ?>
        <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
            <button type="button"
                    class="video-playlist-item w-100 text-start<?= $idx === 0 ? ' is-active' : '' ?>"
                    data-video-select="1"
                    data-src="<?= h($videoSrc) ?>"
                    data-mime="<?= h($videoMime) ?>"
                    data-title="<?= h($f['original_name']) ?>"
                    data-meta="<?= h($meta) ?>">
                <div class="video-thumb-wrap position-relative overflow-hidden">
                    <video class="playlist-preview" preload="metadata" muted playsinline disablePictureInPicture controlsList="nodownload" oncontextmenu="return false;" data-preview>
                        <source src="<?= h($videoSrc) ?>" type="<?= h($videoMime) ?>">
                    </video>
                    <div class="video-overlay"><i class="fas fa-play-circle"></i></div>
                </div>
                <div class="p-2">
                    <div class="small fw-semibold text-truncate"><?= h($f['original_name']) ?></div>
                    <div class="text-muted" style="font-size:.72rem"><?= date('d.m.Y', strtotime($f['created_at'])) ?></div>
                </div>
            </button>
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

<style>
.video-player-shell {
    max-width: 920px;
    border-radius: 14px;
    overflow: hidden;
}

.video-stage {
    background: radial-gradient(circle at 10% 10%, #2b3035 0, #121417 60%, #0b0c0e 100%);
    padding: 10px;
}

.video-stage video {
    border-radius: 10px;
    max-height: min(72vh, 620px);
    background: #000;
}

.video-playlist-item {
    border: 1px solid #d9dee5;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
    padding: 0;
    transition: transform .15s, box-shadow .15s, border-color .15s;
}

.video-playlist-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 .4rem .9rem rgba(0,0,0,.12);
    border-color: #9bbcff;
}

.video-playlist-item.is-active {
    border-color: #0d6efd;
    box-shadow: 0 .35rem .9rem rgba(13,110,253,.18);
}

.video-thumb-wrap video {
    width: 100%;
    height: 130px;
    object-fit: cover;
    display: block;
    transition: transform .25s ease;
}
.video-playlist-item:hover .video-thumb-wrap video { transform: scale(1.04); }

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

@media (max-width: 576px) {
    .video-stage { padding: 6px; }
    .video-stage video { border-radius: 8px; }
}
</style>

<script>
let videoSignature = <?= json_encode($sharedSignature) ?>;

function selectPlaylistVideo(button) {
    if (!button) return;

    const section = document.getElementById('athleteVideosSharedSection');
    const player = section?.querySelector('#athleteMainVideo');
    const source = section?.querySelector('#athleteMainVideoSource');
    const title = section?.querySelector('#athleteMainVideoTitle');
    const meta = section?.querySelector('#athleteMainVideoMeta');
    if (!player || !source || !title || !meta) return;

    const src = button.dataset.src || '';
    const mime = button.dataset.mime || 'video/mp4';
    const label = button.dataset.title || 'Video';
    const metaText = button.dataset.meta || '';
    if (!src) return;

    section.querySelectorAll('.video-playlist-item.is-active').forEach(function (item) {
        item.classList.remove('is-active');
    });
    button.classList.add('is-active');

    source.src = src;
    source.type = mime;
    title.textContent = label;
    meta.textContent = metaText;
    player.load();

    const playPromise = player.play();
    if (playPromise && typeof playPromise.then === 'function') {
        playPromise.catch(function () {});
    }
}

document.addEventListener('click', function (e) {
    const button = e.target.closest('[data-video-select="1"]');
    if (!button) return;
    selectPlaylistVideo(button);
});

(function () {
    const isHoverDevice = window.matchMedia('(hover: hover)').matches;
    if (!isHoverDevice) return;

    document.addEventListener('mouseenter', function (e) {
        const card = e.target.closest('.video-playlist-item');
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
        const card = e.target.closest('.video-playlist-item');
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
