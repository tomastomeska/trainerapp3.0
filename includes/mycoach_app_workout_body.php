<?php
/** @var \PDO $pdo @var string $userType @var int $userId @var string $appUrl */

$workoutId = (int)($_GET['id'] ?? 0);
if ($workoutId <= 0) { flash('danger','Neplatný trénink.'); redirect($appUrl); }
if (!mycoachAppIsLive() && !mycoachAppCanAccess($pdo, $userType, $userId)) {
    flash('warning','Přístup k MyCoach není aktivní.'); redirect($appUrl);
}

try {
    $wStmt = $pdo->prepare('SELECT w.*, s.title AS section_title FROM mycoach_app_workouts w LEFT JOIN mycoach_app_sections s ON s.id=w.section_id WHERE w.id=? AND w.is_active=1 LIMIT 1');
    $wStmt->execute([$workoutId]);
    $workout = $wStmt->fetch();
} catch (Throwable $e) { $workout = null; }

if (!$workout) { flash('danger','Trénink nenalezen.'); redirect($appUrl); }

try {
    $weStmt = $pdo->prepare('SELECT we.*, e.name AS ex_name, e.description AS ex_desc, e.thumbnail AS ex_thumb, e.video_url AS ex_video, e.muscle_groups, e.equipment FROM mycoach_app_workout_exercises we LEFT JOIN mycoach_app_exercises e ON e.id=we.exercise_id WHERE we.workout_id=? ORDER BY we.sort_order ASC, we.id ASC');
    $weStmt->execute([$workoutId]);
    $exercises = $weStmt->fetchAll();
} catch (Throwable $e) { $exercises = []; }

$sectionUrl = null;
if (!empty($workout['section_id'])) {
    $sectionUrl = ($userType === 'coach')
        ? BASE_URL . '/mycoach_app_section.php?id=' . (int)$workout['section_id']
        : BASE_URL . '/athlete_mycoach_app_section.php?id=' . (int)$workout['section_id'];
}

$diffLabels = ['beginner'=>'Začátečník','intermediate'=>'Středně pokročilý','advanced'=>'Pokročilý'];
$diffColors = ['beginner'=>'#3be07a','intermediate'=>'var(--mca-orange)','advanced'=>'#e03b3b'];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-app.css?v=20260813">

<div class="mca-hero mb-3">
  <div class="container-fluid px-3">
    <div class="mca-breadcrumb mb-2" style="color:rgba(240,240,240,.6);">
      <a href="<?= h($appUrl) ?>" style="color:rgba(247,148,29,.8);"><i class="fas fa-brain me-1"></i>MyCoach</a>
      <?php if ($sectionUrl): ?>
        <span class="mca-breadcrumb-sep">/</span>
        <a href="<?= h($sectionUrl) ?>" style="color:rgba(247,148,29,.8);"><?= h($workout['section_title'] ?? 'Sekce') ?></a>
      <?php endif; ?>
      <span class="mca-breadcrumb-sep">/</span>
      <span><?= h($workout['title']) ?></span>
    </div>
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
      <div>
        <h1 class="mca-hero-title" style="font-size:1.35rem;">
          <i class="fas fa-dumbbell"></i> <?= h($workout['title']) ?>
        </h1>
        <div class="d-flex gap-2 mt-1 flex-wrap">
          <?php if ($workout['duration_minutes']): ?>
            <span class="mca-tile-badge"><i class="fas fa-clock me-1"></i><?= (int)$workout['duration_minutes'] ?> min</span>
          <?php endif; ?>
          <span class="mca-tile-badge" style="color:<?= $diffColors[$workout['difficulty']] ?? 'var(--mca-orange)' ?>;">
            <?= $diffLabels[$workout['difficulty']] ?? $workout['difficulty'] ?>
          </span>
          <span class="mca-tile-badge"><i class="fas fa-list-ol me-1"></i><?= count($exercises) ?> cviků</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="container-fluid px-3 pb-5" style="max-width:860px;">
  <?php if (!empty($workout['description'])): ?>
    <p style="color:var(--mca-text-muted);font-size:.9rem;margin-bottom:1.5rem;"><?= nl2br(h($workout['description'])) ?></p>
  <?php endif; ?>

  <?php if (empty($exercises)): ?>
    <div class="text-center py-4" style="color:var(--mca-text-muted);">
      <i class="fas fa-box-open fa-2x mb-2 d-block"></i>
      Cviky tréninku budou doplněny.
    </div>
  <?php else: ?>
    <!-- Ovládací lišta -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <span style="font-size:.85rem;color:var(--mca-text-muted);">Kliknutím na cvik označíte jako hotový</span>
      <button id="btnResetWorkout" class="mca-btn-outline" style="padding:.3rem .9rem;font-size:.8rem;">
        <i class="fas fa-rotate-left me-1"></i>Reset
      </button>
    </div>

    <!-- Progresobar tréninku -->
    <div class="mca-progress-bar-wrap mb-3" style="height:8px;">
      <div class="mca-progress-bar" id="workoutProgressBar" style="width:0%;transition:width .4s;"></div>
    </div>
    <div class="d-flex justify-content-between mb-3" style="font-size:.8rem;color:var(--mca-text-muted);">
      <span id="workoutDoneCount">0 / <?= count($exercises) ?> hotových</span>
      <span id="workoutPct">0 %</span>
    </div>

    <div class="d-flex flex-column gap-2" id="workoutExerciseList">
      <?php foreach ($exercises as $i => $we):
        $exUrl = ($userType === 'coach')
            ? BASE_URL . '/mycoach_app_exercise.php?id=' . (int)$we['exercise_id']
            : BASE_URL . '/athlete_mycoach_app_exercise.php?id=' . (int)$we['exercise_id'];
      ?>
      <div class="mca-workout-exercise-row" data-ex-index="<?= $i ?>">
        <div class="mca-ex-num"><?= $i + 1 ?></div>
        <div class="flex-grow-1">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="<?= h($exUrl) ?>" style="color:var(--mca-text);font-weight:600;font-size:.92rem;text-decoration:none;">
              <?= h($we['ex_name'] ?? '–') ?>
            </a>
            <?php if ($we['muscle_groups']): ?>
              <span style="font-size:.75rem;color:var(--mca-text-muted);"><?= h($we['muscle_groups']) ?></span>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-2 mt-1 flex-wrap" style="font-size:.8rem;color:var(--mca-text-muted);">
            <?php if ($we['sets'] && $we['reps']): ?>
              <span><i class="fas fa-redo me-1"></i><?= (int)$we['sets'] ?> × <?= (int)$we['reps'] ?> opakování</span>
            <?php elseif ($we['sets']): ?>
              <span><i class="fas fa-layer-group me-1"></i><?= (int)$we['sets'] ?> sérií</span>
            <?php endif; ?>
            <?php if ($we['duration_seconds']): ?>
              <span><i class="fas fa-stopwatch me-1"></i><?= mycoachAppFormatDuration((int)$we['duration_seconds']) ?></span>
            <?php endif; ?>
            <?php if ($we['rest_seconds']): ?>
              <span><i class="fas fa-pause me-1"></i>Pauza <?= (int)$we['rest_seconds'] ?>s</span>
            <?php endif; ?>
            <?php if (!empty($we['notes'])): ?>
              <span><i class="fas fa-note-sticky me-1"></i><?= h($we['notes']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <button type="button"
                class="btn-ex-done"
                title="Označit jako hotový"
                style="background:none;border:2px solid var(--mca-metal-3);width:32px;height:32px;border-radius:50%;cursor:pointer;color:var(--mca-metal-3);flex-shrink:0;transition:all .2s;display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-check" style="font-size:.8rem;"></i>
        </button>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Hotovo banner -->
    <div id="workoutDoneBanner" class="text-center py-4 mt-4 d-none"
         style="background:rgba(59,224,122,.08);border:1px solid rgba(59,224,122,.25);border-radius:var(--mca-radius);">
      <div style="font-size:2.5rem;">🏆</div>
      <h4 class="mt-2 mb-1" style="color:#3be07a;">Trénink dokončen!</h4>
      <p style="color:var(--mca-text-muted);font-size:.9rem;">Skvělá práce. Nezapomeň na strečink a hydrataci.</p>
    </div>
  <?php endif; ?>

  <div class="mt-3">
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
</div>

<script>
(function(){
  const total = <?= count($exercises) ?>;
  if (total === 0) return;

  const doneBtns    = document.querySelectorAll('.btn-ex-done');
  const progressBar = document.getElementById('workoutProgressBar');
  const doneCount   = document.getElementById('workoutDoneCount');
  const donePct     = document.getElementById('workoutPct');
  const doneBanner  = document.getElementById('workoutDoneBanner');
  const resetBtn    = document.getElementById('btnResetWorkout');

  const STATE_KEY = 'mca_wo_<?= $workoutId ?>_' + window.location.pathname;
  let completed = new Set(JSON.parse(localStorage.getItem(STATE_KEY) || '[]'));

  function updateUI() {
    const count = completed.size;
    const pct   = total > 0 ? Math.round(count / total * 100) : 0;
    progressBar.style.width = pct + '%';
    doneCount.textContent   = count + ' / ' + total + ' hotových';
    donePct.textContent     = pct + ' %';
    doneBanner.classList.toggle('d-none', count < total);
    doneBtns.forEach((btn, i) => {
      const done = completed.has(i);
      btn.style.borderColor    = done ? '#3be07a' : 'var(--mca-metal-3)';
      btn.style.backgroundColor= done ? 'rgba(59,224,122,.15)' : 'transparent';
      btn.style.color          = done ? '#3be07a' : 'var(--mca-metal-3)';
      btn.closest('.mca-workout-exercise-row').style.opacity = done ? '.6' : '1';
    });
  }

  doneBtns.forEach((btn, i) => {
    btn.addEventListener('click', () => {
      if (completed.has(i)) completed.delete(i); else completed.add(i);
      localStorage.setItem(STATE_KEY, JSON.stringify([...completed]));
      updateUI();
    });
  });

  resetBtn && resetBtn.addEventListener('click', () => {
    completed.clear();
    localStorage.removeItem(STATE_KEY);
    updateUI();
  });

  updateUI();
})();
</script>
