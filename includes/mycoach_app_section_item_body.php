<?php
/** @var \PDO $pdo @var string $userType @var int $userId @var string $appUrl */

$itemId = (int)($_GET['id'] ?? 0);
if ($itemId <= 0) { flash('danger', 'Neplatná položka.'); redirect($appUrl); }

if (!mycoachAppIsLive() && !mycoachAppCanAccess($pdo, $userType, $userId)) {
    flash('warning', 'Přístup k MyCoach není aktivní.');
    redirect($appUrl);
}

try {
    $stmt = $pdo->prepare('SELECT * FROM mycoach_app_section_items WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
} catch (Throwable $e) { $item = null; }

if (!$item) { flash('danger', 'Položka nebyla nalezena.'); redirect($appUrl); }

$section = null;
try {
    $sStmt = $pdo->prepare('SELECT * FROM mycoach_app_sections WHERE id = ? LIMIT 1');
    $sStmt->execute([(int)$item['section_id']]);
    $section = $sStmt->fetch() ?: null;
} catch (Throwable $e) {}

$sectionUrl = null;
if ($section) {
    $sectionUrl = ($userType === 'coach')
        ? BASE_URL . '/mycoach_app_section.php?id=' . (int)$section['id']
        : BASE_URL . '/athlete_mycoach_app_section.php?id=' . (int)$section['id'];
}

$itemType = (string)($item['item_type'] ?? '');
$isVideo  = in_array($itemType, ['video_youtube','video_vimeo','video_upload'], true);
$embedUrl = '';

if ($itemType === 'video_youtube' && !empty($item['url'])) {
    $vid = '';
    if (preg_match('/(?:v=|youtu\.be\/)([A-Za-z0-9_-]{11})/', $item['url'], $m)) {
        $vid = $m[1];
    } elseif (preg_match('/^[A-Za-z0-9_-]{11}$/', trim($item['url']))) {
        $vid = trim($item['url']);
    }
    if ($vid !== '') $embedUrl = 'https://www.youtube.com/embed/' . $vid . '?rel=0&autoplay=0';
} elseif ($itemType === 'video_vimeo' && !empty($item['url'])) {
    if (preg_match('/vimeo\.com\/(\d+)/', $item['url'], $m)) {
        $embedUrl = 'https://player.vimeo.com/video/' . $m[1];
    }
} elseif ($itemType === 'video_upload' && !empty($item['file_path'])) {
    $embedUrl = BASE_URL . '/' . ltrim($item['file_path'], '/');
}

$csrf = csrfToken();
$startAt = 0;
if ($isVideo) {
    // Načti progress (sdílíme mycoach_app_video_progress přes legacy video, nebo skip)
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-app.css?v=20260813">

<!-- Hero -->
<div class="mca-hero mb-3">
  <div class="container-fluid px-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <div class="mca-breadcrumb mb-2" style="color:rgba(240,240,240,.6);">
          <a href="<?= h($appUrl) ?>" style="color:rgba(247,148,29,.8);"><i class="fas fa-brain me-1"></i>MyCoach</a>
          <?php if ($section && $sectionUrl): ?>
            <span class="mca-breadcrumb-sep">/</span>
            <a href="<?= h($sectionUrl) ?>" style="color:rgba(247,148,29,.8);"><?= h($section['title']) ?></a>
          <?php endif; ?>
          <span class="mca-breadcrumb-sep">/</span>
          <span><?= h($item['title']) ?></span>
        </div>
        <h1 class="mca-hero-title" style="font-size:1.2rem;">
          <i class="fas <?= ['video_youtube'=>'fa-youtube','video_vimeo'=>'fa-vimeo','video_upload'=>'fa-play-circle','link'=>'fa-link','article'=>'fa-file-lines','image'=>'fa-image'][$itemType] ?? 'fa-cube' ?> me-2"></i>
          <?= h($item['title']) ?>
        </h1>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/dashboard.php" class="mca-btn-outline" style="padding:.35rem .9rem;font-size:.8rem;">
          <i class="fas fa-house me-1"></i>Domů
        </a>
        <a href="<?= BASE_URL ?>/logout.php" class="mca-btn-outline" style="padding:.35rem .9rem;font-size:.8rem; border-color:rgba(220,53,69,.45); color:#ff9aa2;">
          <i class="fas fa-sign-out-alt me-1"></i>Odhlásit
        </a>
      </div>
    </div>
  </div>
</div>

<div class="container-fluid px-3 pb-4" style="max-width:960px;">

  <?php if ($isVideo): ?>
    <!-- Video přehrávač -->
    <div class="mca-video-wrap mb-3">
      <?php if ($itemType === 'video_upload' && $embedUrl !== ''): ?>
        <video controls preload="metadata" style="width:100%;height:100%;background:#000;" playsinline>
          <source src="<?= h($embedUrl) ?>" type="video/mp4">
          Váš prohlížeč nepodporuje přehrávání videa.
        </video>
      <?php elseif (in_array($itemType, ['video_youtube','video_vimeo'], true) && $embedUrl !== ''): ?>
        <iframe src="<?= h($embedUrl) ?>" allowfullscreen allow="autoplay; encrypted-media"
                title="<?= h($item['title']) ?>"></iframe>
      <?php else: ?>
        <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#666;flex-direction:column;gap:.5rem;">
          <i class="fas fa-video-slash fa-2x"></i><span style="font-size:.85rem;">Video není dostupné</span>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif ($itemType === 'article'): ?>
    <!-- Článek -->
    <div style="color:var(--mca-text);max-width:780px;line-height:1.75;font-size:.97rem;" class="mb-4">
      <?php if (!empty($item['content'])): ?>
        <?= sanitizeSpecialEventHtml($item['content']) ?>
      <?php elseif (!empty($item['description'])): ?>
        <?= nl2br(h($item['description'])) ?>
      <?php else: ?>
        <p style="color:var(--mca-text-muted);">Obsah brzy.</p>
      <?php endif; ?>
    </div>

  <?php elseif ($itemType === 'image'): ?>
    <!-- Obrázek -->
    <?php
      $imgSrc = !empty($item['file_path']) ? BASE_URL . '/' . ltrim($item['file_path'], '/') : (string)($item['url'] ?? '');
    ?>
    <?php if ($imgSrc !== ''): ?>
      <img src="<?= h($imgSrc) ?>" alt="<?= h($item['title']) ?>"
           style="max-width:100%;border-radius:10px;display:block;margin-bottom:1rem;">
    <?php endif; ?>
  <?php endif; ?>

  <!-- Popis -->
  <?php if (!empty($item['description']) && $itemType !== 'article'): ?>
    <p style="color:var(--mca-text-muted);font-size:.9rem;margin:.75rem 0;"><?= nl2br(h($item['description'])) ?></p>
  <?php endif; ?>

  <!-- Meta: délka videa -->
  <?php if ($isVideo && !empty($item['duration_seconds'])): ?>
    <div class="mb-3" style="font-size:.82rem;color:var(--mca-text-muted);">
      <i class="fas fa-clock me-1"></i><?= mycoachAppFormatDuration((int)$item['duration_seconds']) ?>
    </div>
  <?php endif; ?>

  <?php if ($sectionUrl): ?>
    <a href="<?= h($sectionUrl) ?>" class="mca-btn-outline" style="font-size:.85rem;padding:.4rem 1rem;">
      <i class="fas fa-arrow-left me-1"></i>Zpět na sekci
    </a>
  <?php endif; ?>

</div>
