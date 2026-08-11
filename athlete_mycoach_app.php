<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAthleteLogin();

$pdo       = getDB();
$athleteId = (int)getCurrentAthleteId();
$athlete   = getCurrentAthlete();
$displayName = trim((string)($athlete['name'] ?? $athlete['username'] ?? ''));

// ── App musí být live ─────────────────────────────────────────
if (!mycoachAppIsLive()) {
    flash('info', 'Aplikace MyCoach je aktuálně ve vývoji. Brzy spustíme!');
    redirect(BASE_URL . '/athlete_dashboard.php');
}

// ── Akce: spuštění trialu ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'start_trial') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_mycoach_app.php');
    }
    if (mycoachAppStartTrial($pdo, 'athlete', $athleteId)) {
        flash('success', '3denní zkušební přístup byl spuštěn. Vítejte v MyCoach!');
    } else {
        flash('warning', 'Zkušební přístup nelze spustit – byl již jednou použit.');
    }
    redirect(BASE_URL . '/athlete_mycoach_app.php');
}

// ── Stav přístupu ─────────────────────────────────────────────
$accessStatus = mycoachAppCheckStatus($pdo, 'athlete', $athleteId);
$canAccess    = mycoachAppCanAccess($pdo, 'athlete', $athleteId);
$accessRow    = mycoachAppGetAccess($pdo, 'athlete', $athleteId);

require_once __DIR__ . '/includes/header.php';
renderHeader('MyCoach');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-app.css">
<body class="mycoach-app-theme">

<?php if (!$canAccess): ?>
  <!-- ═══════════════════ PAYWALL ═══════════════════ -->
  <div class="container py-3">
    <div class="mca-breadcrumb">
      <a href="<?= BASE_URL ?>/athlete_dashboard.php"><i class="fas fa-home me-1"></i>Dashboard</a>
      <span class="mca-breadcrumb-sep">/</span>
      <span>MyCoach</span>
    </div>

    <div class="mca-paywall">
      <div class="mca-paywall-icon"><i class="fas fa-brain"></i></div>

      <?php if ($accessStatus === 'no_access'): ?>
        <h2>MyCoach</h2>
        <p>Videocvičení, tréninky, výživový průvodce a encyklopedie cviků – vše na jednom místě.</p>
        <div class="mca-trial-box">
          <strong><i class="fas fa-gift me-1"></i>Vyzkoušejte zdarma po dobu <?= mycoachAppTrialDays() ?> dnů</strong><br>
          <span>Bez závazků. Trial lze využít pouze jednou.</span>
        </div>
        <form method="post" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="action" value="start_trial">
          <button type="submit" class="mca-btn-primary">
            <i class="fas fa-rocket me-2"></i>Spustit <?= mycoachAppTrialDays() ?>denní trial zdarma
          </button>
        </form>
        <br><br>
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="mca-btn-outline">Zpět na dashboard</a>

      <?php elseif ($accessStatus === 'trial_expired'): ?>
        <h2>Trial skončil</h2>
        <p>Váš zkušební přístup vypršel. Pro pokračování kontaktujte svého trenéra nebo správce.</p>
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="mca-btn-outline">Zpět na dashboard</a>

      <?php elseif ($accessStatus === 'sub_expired'): ?>
        <h2>Předplatné vypršelo</h2>
        <p>Platnost vašeho předplatného skončila. Kontaktujte svého trenéra pro obnovení.</p>
        <?php if ($accessRow && !empty($accessRow['subscription_end'])): ?>
          <div class="mca-trial-box">
            Předplatné platilo do: <strong><?= h(formatDate((string)$accessRow['subscription_end'])) ?></strong>
          </div>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="mca-btn-outline">Zpět na dashboard</a>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <!-- ═══════════════════ APLIKACE ═══════════════════ -->
  <div class="mca-hero mb-3">
    <div class="container-fluid px-3">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h1 class="mca-hero-title"><i class="fas fa-brain"></i> MyCoach</h1>
          <div class="mca-hero-sub">Vítejte, <?= h($displayName) ?></div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <?php if ($accessStatus === 'active_trial'): ?>
            <?php
              $trialExpires = mycoachAppTrialExpiresAt($accessRow);
              $remainHours  = $trialExpires ? max(0, (int)ceil(($trialExpires->getTimestamp() - time()) / 3600)) : 0;
            ?>
            <span class="mca-trial-timer">
              <i class="fas fa-clock"></i> Trial: <?= $remainHours ?>h zbývá
            </span>
          <?php elseif ($accessStatus === 'subscribed' && !empty($accessRow['subscription_end'])): ?>
            <span class="mca-access-badge">
              <i class="fas fa-star"></i>
              Předplatné do <?= h(formatDate((string)$accessRow['subscription_end'])) ?>
            </span>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="mca-btn-outline" style="padding:.3rem .9rem;font-size:.8rem;">
            <i class="fas fa-arrow-left me-1"></i>Dashboard
          </a>
        </div>
      </div>
    </div>
  </div>

  <div class="container-fluid px-3 pb-4">
    <?php
      $sections    = mycoachAppLoadSections($pdo, 'athlete');
      $sectionTypes = mycoachAppSectionTypes();
    ?>

    <?php if (empty($sections)): ?>
      <div class="text-center py-5" style="color:var(--mca-text-muted);">
        <i class="fas fa-cube fa-2x mb-3 d-block" style="color:var(--mca-metal-3);"></i>
        <p class="mb-0">Obsah bude brzy přidán.</p>
      </div>
    <?php else: ?>
      <div class="mca-section-grid">
        <?php foreach ($sections as $sec):
          $typeInfo = $sectionTypes[$sec['section_type'] ?? 'mixed'] ?? $sectionTypes['mixed'];
          $color    = trim((string)($sec['tile_color'] ?? 'orange'));
          $itemCount = 0;
          try {
            if ($sec['section_type'] === 'videos') {
              $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM mycoach_app_videos WHERE section_id = ? AND is_active = 1');
              $cntStmt->execute([(int)$sec['id']]);
              $itemCount = (int)$cntStmt->fetchColumn();
            } elseif ($sec['section_type'] === 'workout') {
              $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM mycoach_app_workouts WHERE section_id = ? AND is_active = 1');
              $cntStmt->execute([(int)$sec['id']]);
              $itemCount = (int)$cntStmt->fetchColumn();
            }
          } catch (Throwable $e) { $itemCount = 0; }
        ?>
        <a href="<?= BASE_URL ?>/athlete_mycoach_app_section.php?id=<?= (int)$sec['id'] ?>"
           class="mca-section-tile mca-tile--<?= h($color) ?>">
          <?php if (!empty($sec['bg_image'])): ?>
            <div class="mca-tile-bg" style="background-image:url('<?= h(BASE_URL . '/' . ltrim($sec['bg_image'], '/')) ?>');"></div>
          <?php endif; ?>
          <div class="mca-tile-icon">
            <i class="fas <?= h($sec['icon_class'] ?: $typeInfo['icon']) ?>"></i>
          </div>
          <div>
            <div class="mca-tile-title"><?= h($sec['title']) ?></div>
            <?php if (!empty($sec['subtitle'])): ?>
              <div class="mca-tile-sub"><?= h($sec['subtitle']) ?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex align-items-center justify-content-between gap-2">
            <span class="mca-tile-badge">
              <i class="fas <?= h($typeInfo['icon']) ?> me-1"></i><?= h($typeInfo['label']) ?>
            </span>
            <?php if ($itemCount > 0): ?>
              <span class="mca-tile-overlay-count">
                <i class="fas fa-list"></i> <?= $itemCount ?>
              </span>
            <?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <div class="mt-4 d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_mycoach_app_exercises.php" class="mca-btn-outline">
          <i class="fas fa-person-running me-1"></i>Encyklopedie cviků
        </a>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php renderFooter(); ?>
