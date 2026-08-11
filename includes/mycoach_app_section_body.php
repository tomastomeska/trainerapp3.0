<?php
/** @var \PDO $pdo @var string $userType @var int $userId @var string $appUrl @var string $backUrl */

$sectionId = (int)($_GET['id'] ?? 0);
if ($sectionId <= 0) {
    flash('danger', 'Neplatná sekce.');
    redirect($appUrl);
}

// Ověř přístup
if (!mycoachAppIsLive() || !mycoachAppCanAccess($pdo, $userType, $userId)) {
    flash('warning', 'Přístup k MyCoach není aktivní.');
    redirect($backUrl);
}

try {
    $secStmt = $pdo->prepare('SELECT * FROM mycoach_app_sections WHERE id = ? AND is_active = 1 LIMIT 1');
    $secStmt->execute([$sectionId]);
    $section = $secStmt->fetch();
} catch (Throwable $e) {
    $section = null;
}

if (!$section) {
    flash('danger', 'Sekce nebyla nalezena.');
    redirect($appUrl);
}

$sectionTypes = mycoachAppSectionTypes();
$sType = (string)($section['section_type'] ?? 'videos');

// Načtení obsahu
$videos   = [];
$workouts = [];
if ($sType === 'videos' || $sType === 'mixed') {
    $videos = mycoachAppLoadVideos($pdo, $sectionId);
}
if ($sType === 'workout' || $sType === 'mixed') {
    try {
        $wStmt = $pdo->prepare('SELECT * FROM mycoach_app_workouts WHERE section_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC');
        $wStmt->execute([$sectionId]);
        $workouts = $wStmt->fetchAll();
    } catch (Throwable $e) { $workouts = []; }
}

// Progress přehrávání
$progressMap = [];
if (!empty($videos)) {
    $progressMap = mycoachAppLoadVideoProgress($pdo, $userType, $userId);
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-app.css">

<!-- Hero -->
<div class="mca-hero mb-3">
  <div class="container-fluid px-3">
    <div class="mca-breadcrumb mb-2" style="color:rgba(240,240,240,.6);">
      <a href="<?= h($appUrl) ?>" style="color:rgba(247,148,29,.8);">
        <i class="fas fa-brain me-1"></i>MyCoach
      </a>
      <span class="mca-breadcrumb-sep">/</span>
      <span><?= h($section['title']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
      <div class="mca-tile-icon" style="width:48px;height:48px;">
        <i class="fas <?= h($section['icon_class'] ?? ($sectionTypes[$sType]['icon'] ?? 'fa-cube')) ?>"></i>
      </div>
      <div>
        <h1 class="mca-hero-title" style="font-size:1.35rem;"><?= h($section['title']) ?></h1>
        <?php if (!empty($section['subtitle'])): ?>
          <div class="mca-hero-sub"><?= h($section['subtitle']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="container-fluid px-3 pb-4">
  <?php if (!empty($section['description'])): ?>
    <p style="color:var(--mca-text-muted); font-size:.9rem; max-width:780px;" class="mb-3">
      <?= nl2br(h($section['description'])) ?>
    </p>
  <?php endif; ?>

  <?php if ($sType === 'article'): ?>
    <!-- Článek – zobrazíme description jako HTML obsah -->
    <div style="color:var(--mca-text); max-width:780px; line-height:1.7;">
      <?= !empty($section['description']) ? sanitizeSpecialEventHtml($section['description']) : '<p style="color:var(--mca-text-muted);">Obsah brzy.</p>' ?>
    </div>

  <?php elseif (!empty($videos)): ?>
    <!-- VIDEO SEZNAM -->
    <div class="d-flex flex-column gap-2">
      <?php foreach ($videos as $vid):
        $prog = $progressMap[(int)$vid['id']] ?? null;
        $isCompleted = $prog && (int)($prog['is_completed'] ?? 0) === 1;
        $watchedSec  = $prog ? (int)($prog['watched_seconds'] ?? 0) : 0;
        $durSec      = (int)($vid['duration_seconds'] ?? 0);
        $pct = ($durSec > 0 && $watchedSec > 0) ? min(100, round($watchedSec / $durSec * 100)) : 0;
        $videoUrl = BASE_URL . '/mycoach_app_video.php?id=' . (int)$vid['id'];
        if ($userType === 'athlete') {
            $videoUrl = BASE_URL . '/athlete_mycoach_app_video.php?id=' . (int)$vid['id'];
        }
      ?>
      <a href="<?= h($videoUrl) ?>" class="mca-video-card">
        <div class="mca-video-thumb">
          <?php if (!empty($vid['thumbnail'])): ?>
            <img src="<?= h(BASE_URL . '/' . ltrim($vid['thumbnail'], '/')) ?>" alt="">
          <?php else: ?>
            <i class="fas fa-play-circle"></i>
          <?php endif; ?>
        </div>
        <div class="flex-grow-1 min-w-0">
          <div class="mca-video-title"><?= h($vid['title']) ?></div>
          <div class="mca-video-meta d-flex align-items-center gap-2 flex-wrap mt-1">
            <?php if ($durSec > 0): ?>
              <span><i class="fas fa-clock me-1"></i><?= mycoachAppFormatDuration($durSec) ?></span>
            <?php endif; ?>
            <?php if (!empty($vid['tags'])): ?>
              <span><i class="fas fa-tag me-1"></i><?= h($vid['tags']) ?></span>
            <?php endif; ?>
            <?php if ($isCompleted): ?>
              <span class="mca-watched-badge"><i class="fas fa-check-circle"></i> Zhlédnuto</span>
            <?php elseif ($pct > 0): ?>
              <span style="color:var(--mca-orange); font-size:.75rem;"><i class="fas fa-hourglass-half me-1"></i><?= $pct ?>%</span>
            <?php endif; ?>
          </div>
          <?php if ($pct > 0 && !$isCompleted): ?>
            <div class="mca-progress-bar-wrap mt-1" style="max-width:200px;">
              <div class="mca-progress-bar" style="width:<?= $pct ?>%;"></div>
            </div>
          <?php endif; ?>
        </div>
        <div class="align-self-center" style="color:var(--mca-metal-3);">
          <i class="fas fa-chevron-right"></i>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

  <?php elseif (!empty($workouts)): ?>
    <!-- TRÉNINKY -->
    <div class="d-flex flex-column gap-3">
      <?php foreach ($workouts as $wo):
        $diffIcons = ['beginner'=>'fa-seedling','intermediate'=>'fa-dumbbell','advanced'=>'fa-fire'];
        $diffColors = ['beginner'=>'#3be07a','intermediate'=>'var(--mca-orange)','advanced'=>'#e03b3b'];
        $diff = (string)($wo['difficulty'] ?? 'intermediate');
        $workoutUrl = BASE_URL . '/mycoach_app_workout.php?id=' . (int)$wo['id'];
        if ($userType === 'athlete') {
            $workoutUrl = BASE_URL . '/athlete_mycoach_app_workout.php?id=' . (int)$wo['id'];
        }
        // Počet cviků
        $exCount = 0;
        try {
          $exCntStmt = $pdo->prepare('SELECT COUNT(*) FROM mycoach_app_workout_exercises WHERE workout_id = ?');
          $exCntStmt->execute([(int)$wo['id']]);
          $exCount = (int)$exCntStmt->fetchColumn();
        } catch (Throwable $e) {}
      ?>
      <div class="mca-workout-card">
        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
          <div class="flex-grow-1">
            <div class="mca-workout-title"><?= h($wo['title']) ?></div>
            <?php if (!empty($wo['description'])): ?>
              <p style="color:var(--mca-text-muted);font-size:.85rem;margin:.25rem 0 .5rem;"><?= h(mb_substr($wo['description'],0,160,'UTF-8')) ?></p>
            <?php endif; ?>
            <div class="d-flex gap-2 flex-wrap align-items-center">
              <?php if ($wo['duration_minutes']): ?>
                <span class="mca-tile-badge"><i class="fas fa-clock me-1"></i><?= (int)$wo['duration_minutes'] ?> min</span>
              <?php endif; ?>
              <span class="mca-tile-badge" style="color:<?= $diffColors[$diff] ?? 'var(--mca-orange)' ?>;">
                <i class="fas <?= $diffIcons[$diff] ?? 'fa-dumbbell' ?> me-1"></i><?= ['beginner'=>'Začátečník','intermediate'=>'Středně pokročilý','advanced'=>'Pokročilý'][$diff] ?? $diff ?>
              </span>
              <?php if ($exCount > 0): ?>
                <span class="mca-tile-badge"><i class="fas fa-list-ol me-1"></i><?= $exCount ?> cviků</span>
              <?php endif; ?>
            </div>
          </div>
          <a href="<?= h($workoutUrl) ?>" class="mca-btn-primary" style="white-space:nowrap;padding:.5rem 1.2rem;font-size:.85rem;">
            <i class="fas fa-play me-1"></i>Spustit
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

  <?php else: ?>
    <div class="text-center py-4" style="color:var(--mca-text-muted);">
      <i class="fas fa-box-open fa-2x mb-2 d-block"></i>
      Obsah sekce bude brzy přidán.
    </div>
  <?php endif; ?>
</div>
