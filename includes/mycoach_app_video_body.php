<?php
/** @var \PDO $pdo @var string $userType @var int $userId @var string $appUrl */

// ── API: uložení progress ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    $vidId    = (int)($_POST['video_id'] ?? 0);
    $watched  = max(0, (int)($_POST['watched_seconds'] ?? 0));
    $duration = isset($_POST['duration_seconds']) ? max(0, (int)$_POST['duration_seconds']) : null;
    if ($vidId > 0) {
        mycoachAppSaveVideoProgress($pdo, $userType, $userId, $vidId, $watched, $duration);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$videoId = (int)($_GET['id'] ?? 0);
if ($videoId <= 0) { flash('danger', 'Neplatné video.'); redirect($appUrl); }
if (!mycoachAppIsLive() || !mycoachAppCanAccess($pdo, $userType, $userId)) {
    flash('warning', 'Přístup není aktivní.');
    redirect($appUrl);
}

try {
    $vStmt = $pdo->prepare('SELECT * FROM mycoach_app_videos WHERE id = ? AND is_active = 1 LIMIT 1');
    $vStmt->execute([$videoId]);
    $video = $vStmt->fetch();
} catch (Throwable $e) { $video = null; }

if (!$video) { flash('danger', 'Video nebylo nalezeno.'); redirect($appUrl); }

$section = null;
if (!empty($video['section_id'])) {
    try {
        $sStmt = $pdo->prepare('SELECT * FROM mycoach_app_sections WHERE id = ? LIMIT 1');
        $sStmt->execute([(int)$video['section_id']]);
        $section = $sStmt->fetch() ?: null;
    } catch (Throwable $e) {}
}

$embedUrl   = mycoachAppVideoEmbedUrl($video);
$isUpload   = ($video['video_type'] === 'upload');
$progressRow = (mycoachAppLoadVideoProgress($pdo, $userType, $userId))[$videoId] ?? null;
$startAt     = $progressRow ? max(0, (int)($progressRow['watched_seconds'] ?? 0)) : 0;
$isCompleted = $progressRow && (int)($progressRow['is_completed'] ?? 0) === 1;
$csrf        = csrfToken();

$sectionUrl = null;
if ($section) {
    $sectionUrl = ($userType === 'coach')
        ? BASE_URL . '/mycoach_app_section.php?id=' . (int)$section['id']
        : BASE_URL . '/athlete_mycoach_app_section.php?id=' . (int)$section['id'];
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-app.css">

<!-- Breadcrumb & Hero -->
<div class="mca-hero mb-3">
  <div class="container-fluid px-3">
    <div class="mca-breadcrumb mb-2" style="color:rgba(240,240,240,.6);">
      <a href="<?= h($appUrl) ?>" style="color:rgba(247,148,29,.8);"><i class="fas fa-brain me-1"></i>MyCoach</a>
      <?php if ($section && $sectionUrl): ?>
        <span class="mca-breadcrumb-sep">/</span>
        <a href="<?= h($sectionUrl) ?>" style="color:rgba(247,148,29,.8);"><?= h($section['title']) ?></a>
      <?php endif; ?>
      <span class="mca-breadcrumb-sep">/</span>
      <span><?= h($video['title']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
      <h1 class="mca-hero-title" style="font-size:1.25rem;">
        <i class="fas fa-play-circle"></i> <?= h($video['title']) ?>
      </h1>
      <?php if ($isCompleted): ?>
        <span class="mca-watched-badge"><i class="fas fa-check-circle"></i> Zhlédnuto</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="container-fluid px-3 pb-4" style="max-width:960px;">
  <!-- Video Player -->
  <div class="mca-video-wrap mb-3">
    <?php if ($isUpload && !empty($video['video_path'])): ?>
      <video id="mcaVideoPlayer" controls
             preload="metadata"
             <?= $startAt > 5 ? 'data-start="' . $startAt . '"' : '' ?>
             style="width:100%;height:100%;background:#000;"
             playsinline>
        <source src="<?= h(BASE_URL . '/' . ltrim($video['video_path'], '/')) ?>" type="video/mp4">
        Váš prohlížeč nepodporuje přehrávání videa.
      </video>
    <?php elseif ($embedUrl): ?>
      <?php
        $iframeSrc = $embedUrl;
        // Pro upload/url typ použij <video>, jinak iframe
        if (in_array($video['video_type'], ['youtube','vimeo'])):
      ?>
      <iframe id="mcaVideoIframe"
              src="<?= h($iframeSrc) ?>"
              allowfullscreen
              allow="autoplay; encrypted-media"
              title="<?= h($video['title']) ?>"></iframe>
      <?php else: ?>
      <video id="mcaVideoPlayer" controls
             preload="metadata"
             <?= $startAt > 5 ? 'data-start="' . $startAt . '"' : '' ?>
             style="width:100%;height:100%;background:#000;"
             playsinline>
        <source src="<?= h($iframeSrc) ?>">
        Váš prohlížeč nepodporuje přehrávání videa.
      </video>
      <?php endif; ?>
    <?php else: ?>
      <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#666;flex-direction:column;gap:.5rem;">
        <i class="fas fa-video-slash fa-2x"></i>
        <span style="font-size:.85rem;">Video není k dispozici</span>
      </div>
    <?php endif; ?>
  </div>

  <!-- Progress bar (jen pro upload/url přehrávač) -->
  <?php if ($isUpload || $video['video_type'] === 'url'): ?>
  <div id="mcaProgressWrap" class="mca-progress-bar-wrap mb-1" style="display:none;">
    <div id="mcaProgressBar" class="mca-progress-bar" style="width:0%;"></div>
  </div>
  <?php endif; ?>

  <!-- Popis -->
  <?php if (!empty($video['description'])): ?>
    <p style="color:var(--mca-text-muted); font-size:.9rem; margin:.75rem 0;"><?= nl2br(h($video['description'])) ?></p>
  <?php endif; ?>

  <!-- Meta -->
  <div class="d-flex gap-3 flex-wrap mb-3" style="font-size:.82rem;color:var(--mca-text-muted);">
    <?php if (!empty($video['duration_seconds'])): ?>
      <span><i class="fas fa-clock me-1"></i><?= mycoachAppFormatDuration((int)$video['duration_seconds']) ?></span>
    <?php endif; ?>
    <?php if (!empty($video['tags'])): ?>
      <span><i class="fas fa-tag me-1"></i><?= h($video['tags']) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($sectionUrl): ?>
    <a href="<?= h($sectionUrl) ?>" class="mca-btn-outline" style="font-size:.85rem;padding:.4rem 1rem;">
      <i class="fas fa-arrow-left me-1"></i>Zpět na sekci
    </a>
  <?php else: ?>
    <a href="<?= h($appUrl) ?>" class="mca-btn-outline" style="font-size:.85rem;padding:.4rem 1rem;">
      <i class="fas fa-arrow-left me-1"></i>Zpět na MyCoach
    </a>
  <?php endif; ?>
</div>

<script>
(function() {
  const VIDEO_ID    = <?= (int)$video['id'] ?>;
  const CSRF        = <?= json_encode($csrf) ?>;
  const SAVE_URL    = <?= json_encode(BASE_URL . '/api/mycoach_save_video_progress.php') ?>;
  const START_AT    = <?= $startAt ?>;

  function saveProgress(watched, duration) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('video_id', VIDEO_ID);
    fd.append('watched_seconds', Math.floor(watched));
    if (duration > 0) fd.append('duration_seconds', Math.floor(duration));
    fetch(SAVE_URL, { method: 'POST', body: fd }).catch(() => {});
  }

  const player = document.getElementById('mcaVideoPlayer');
  if (player) {
    const progressWrap = document.getElementById('mcaProgressWrap');
    const progressBar  = document.getElementById('mcaProgressBar');
    let saveTimer = null;

    player.addEventListener('loadedmetadata', () => {
      if (START_AT > 5) player.currentTime = START_AT;
      if (progressWrap) progressWrap.style.display = 'block';
    });

    player.addEventListener('timeupdate', () => {
      const dur = player.duration || 0;
      const cur = player.currentTime || 0;
      if (dur > 0 && progressBar) {
        progressBar.style.width = Math.min(100, (cur / dur * 100)).toFixed(1) + '%';
      }
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => saveProgress(cur, dur), 5000);
    });

    player.addEventListener('pause',   () => { clearTimeout(saveTimer); saveProgress(player.currentTime, player.duration); });
    player.addEventListener('ended',   () => { clearTimeout(saveTimer); saveProgress(player.duration || 0, player.duration); });
    window.addEventListener('beforeunload', () => { saveProgress(player.currentTime, player.duration); });
  }
})();
</script>
