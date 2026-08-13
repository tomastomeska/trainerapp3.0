<?php
/** @var \PDO $pdo @var string $userType @var int $userId @var string $appUrl */

$exerciseId = (int)($_GET['id'] ?? 0);
$exercisesUrl = ($userType === 'coach')
    ? BASE_URL . '/mycoach_app_exercises.php'
    : BASE_URL . '/athlete_mycoach_app_exercises.php';

if ($exerciseId <= 0) { flash('danger','Neplatný cvik.'); redirect($exercisesUrl); }
if (!mycoachAppIsLive() && !mycoachAppCanAccess($pdo, $userType, $userId)) {
    flash('warning','Přístup k MyCoach není aktivní.'); redirect($appUrl);
}

try {
    $stmt = $pdo->prepare('SELECT * FROM mycoach_app_exercises WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$exerciseId]);
    $ex = $stmt->fetch();
} catch (Throwable $e) { $ex = null; }

if (!$ex) { flash('danger','Cvik nenalezen.'); redirect($exercisesUrl); }

$embedUrl  = null;
if (!empty($ex['video_url'])) {
    $fakeVideo = ['video_type'=>'youtube','video_url'=>$ex['video_url']];
    if (str_contains((string)$ex['video_url'],'vimeo')) $fakeVideo['video_type']='vimeo';
    $embedUrl = mycoachAppVideoEmbedUrl($fakeVideo);
}

$diffLabels = ['beginner'=>'Začátečník','intermediate'=>'Středně pokročilý','advanced'=>'Pokročilý'];
$diffColors = ['beginner'=>'#3be07a','intermediate'=>'var(--mca-orange)','advanced'=>'#e03b3b'];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-app.css?v=20260813">

<div class="mca-hero mb-3">
  <div class="container-fluid px-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <div class="mca-breadcrumb mb-2" style="color:rgba(240,240,240,.6);">
          <a href="<?= h($appUrl) ?>" style="color:rgba(247,148,29,.8);"><i class="fas fa-brain me-1"></i>MyCoach</a>
          <span class="mca-breadcrumb-sep">/</span>
          <a href="<?= h($exercisesUrl) ?>" style="color:rgba(247,148,29,.8);">Cviky</a>
          <span class="mca-breadcrumb-sep">/</span>
          <span><?= h($ex['name']) ?></span>
        </div>
        <h1 class="mca-hero-title" style="font-size:1.35rem;">
          <i class="fas fa-person-running"></i> <?= h($ex['name']) ?>
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

<div class="container-fluid px-3 pb-4" style="max-width:900px;">
  <div class="row g-4">
    <!-- Levý sloupec: meta + video -->
    <div class="col-md-5">
      <?php if (!empty($ex['thumbnail'])): ?>
        <img src="<?= h(BASE_URL.'/'.ltrim($ex['thumbnail'],'/')) ?>" alt=""
             style="width:100%;border-radius:var(--mca-radius);margin-bottom:1rem;object-fit:cover;max-height:220px;">
      <?php endif; ?>

      <?php if ($embedUrl): ?>
        <div class="mca-video-wrap mb-3">
          <iframe src="<?= h($embedUrl) ?>" allowfullscreen allow="autoplay; encrypted-media"
                  title="<?= h($ex['name']) ?>"></iframe>
        </div>
      <?php endif; ?>

      <div class="d-flex flex-column gap-2 mb-3" style="font-size:.85rem;">
        <?php if ($ex['category']): ?>
        <div style="color:var(--mca-text-muted);">
          <i class="fas fa-tag me-1" style="color:var(--mca-orange);"></i>
          Kategorie: <strong style="color:var(--mca-text);"><?= h($ex['category']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($ex['muscle_groups']): ?>
        <div style="color:var(--mca-text-muted);">
          <i class="fas fa-bullseye me-1" style="color:var(--mca-orange);"></i>
          Svaly: <strong style="color:var(--mca-text);"><?= h($ex['muscle_groups']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($ex['equipment']): ?>
        <div style="color:var(--mca-text-muted);">
          <i class="fas fa-toolbox me-1" style="color:var(--mca-orange);"></i>
          Vybavení: <strong style="color:var(--mca-text);"><?= h($ex['equipment']) ?></strong>
        </div>
        <?php endif; ?>
        <div>
          <span class="mca-tile-badge" style="color:<?= $diffColors[$ex['difficulty']] ?? 'var(--mca-orange)' ?>;">
            <?= $diffLabels[$ex['difficulty']] ?? $ex['difficulty'] ?>
          </span>
        </div>
      </div>
    </div>

    <!-- Pravý sloupec: popis + instrukce -->
    <div class="col-md-7">
      <?php if (!empty($ex['description'])): ?>
        <div class="mb-3">
          <h4 class="fw-bold mb-2" style="font-size:1rem;color:var(--mca-orange);">Popis</h4>
          <p style="color:var(--mca-text-muted);font-size:.9rem;line-height:1.7;"><?= nl2br(h($ex['description'])) ?></p>
        </div>
      <?php endif; ?>

      <?php if (!empty($ex['instructions'])): ?>
        <div class="mb-3">
          <h4 class="fw-bold mb-2" style="font-size:1rem;color:var(--mca-orange);">Provedení</h4>
          <div style="color:var(--mca-text);font-size:.9rem;line-height:1.7;
                      background:var(--mca-dark-3);border-radius:var(--mca-radius-sm);padding:1rem;
                      border-left:3px solid var(--mca-orange);">
            <?= nl2br(h($ex['instructions'])) ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (empty($ex['description']) && empty($ex['instructions'])): ?>
        <p style="color:var(--mca-text-muted);">Popis a instrukce budou doplněny.</p>
      <?php endif; ?>

      <a href="<?= h($exercisesUrl) ?>" class="mca-btn-outline" style="font-size:.85rem;padding:.4rem 1rem;">
        <i class="fas fa-arrow-left me-1"></i>Zpět na encyklopedii
      </a>
    </div>
  </div>
</div>
