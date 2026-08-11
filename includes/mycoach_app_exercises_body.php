<?php
/** @var \PDO $pdo @var string $userType @var int $userId @var string $appUrl */

if (!mycoachAppIsLive() || !mycoachAppCanAccess($pdo, $userType, $userId)) {
    flash('warning', 'Přístup k MyCoach není aktivní.');
    redirect($appUrl);
}

$search   = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['cat'] ?? ''));
$difficulty = trim((string)($_GET['diff'] ?? ''));

// Načtení cviků
$exercises  = [];
$categories = [];
try {
    $where  = ['e.is_active = 1'];
    $params = [];

    if ($search !== '') {
        $where[]  = '(e.name LIKE ? OR e.muscle_groups LIKE ? OR e.category LIKE ? OR e.equipment LIKE ?)';
        $like     = '%' . $search . '%';
        $params   = array_merge($params, [$like, $like, $like, $like]);
    }
    if ($category !== '') {
        $where[]  = 'e.category = ?';
        $params[] = $category;
    }
    if (in_array($difficulty, ['beginner','intermediate','advanced'], true)) {
        $where[]  = 'e.difficulty = ?';
        $params[] = $difficulty;
    }

    $sql = 'SELECT * FROM mycoach_app_exercises'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY e.sort_order ASC, e.name ASC LIMIT 300';
    // alias e pro WHERE, rewrite:
    $sql = str_replace('mycoach_app_exercises', 'mycoach_app_exercises e', $sql);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exercises = $stmt->fetchAll();

    $catStmt = $pdo->query('SELECT DISTINCT category FROM mycoach_app_exercises WHERE is_active=1 AND category IS NOT NULL AND category != "" ORDER BY category ASC');
    $categories = $catStmt ? array_column($catStmt->fetchAll(), 'category') : [];
} catch (Throwable $e) { $exercises = []; $categories = []; }

$diffOpts = [''=>'Vše','beginner'=>'Začátečník','intermediate'=>'Střední','advanced'=>'Pokročilý'];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-app.css">

<div class="mca-hero mb-3">
  <div class="container-fluid px-3">
    <div class="mca-breadcrumb mb-2" style="color:rgba(240,240,240,.6);">
      <a href="<?= h($appUrl) ?>" style="color:rgba(247,148,29,.8);"><i class="fas fa-brain me-1"></i>MyCoach</a>
      <span class="mca-breadcrumb-sep">/</span>
      <span>Encyklopedie cviků</span>
    </div>
    <h1 class="mca-hero-title" style="font-size:1.35rem;"><i class="fas fa-person-running"></i> Encyklopedie cviků</h1>
    <div class="mca-hero-sub"><?= count($exercises) ?> cviků</div>
  </div>
</div>

<div class="container-fluid px-3 pb-4">
  <!-- Filtr -->
  <form method="get" class="mb-3 d-flex gap-2 flex-wrap align-items-end">
    <div class="mca-search-wrap flex-grow-1" style="min-width:200px;max-width:340px;">
      <i class="fas fa-search mca-search-icon"></i>
      <input type="text" name="q" value="<?= h($search) ?>" class="form-control" placeholder="Hledat cvik…">
    </div>
    <?php if (!empty($categories)): ?>
    <select name="cat" class="form-select form-select-sm" style="background:var(--mca-dark-3);color:var(--mca-text);border-color:var(--mca-metal-2);border-radius:50px;max-width:160px;">
      <option value="">Vše (kategorie)</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= h($c) ?>" <?= $category === $c ? 'selected' : '' ?>><?= h($c) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="diff" class="form-select form-select-sm" style="background:var(--mca-dark-3);color:var(--mca-text);border-color:var(--mca-metal-2);border-radius:50px;max-width:150px;">
      <?php foreach ($diffOpts as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $difficulty === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="mca-btn-primary" style="padding:.45rem 1.2rem;font-size:.85rem;">Hledat</button>
    <?php if ($search !== '' || $category !== '' || $difficulty !== ''): ?>
      <a href="?" class="mca-btn-outline" style="padding:.45rem .9rem;font-size:.85rem;">Reset</a>
    <?php endif; ?>
  </form>

  <?php if (empty($exercises)): ?>
    <div class="text-center py-5" style="color:var(--mca-text-muted);">
      <i class="fas fa-search fa-2x mb-2 d-block"></i>
      <?= $search !== '' ? 'Žádný cvik nebyl nalezen pro „' . h($search) . '".' : 'Databáze cviků je zatím prázdná.' ?>
    </div>
  <?php else: ?>
    <div class="d-flex flex-column gap-2">
      <?php foreach ($exercises as $ex):
        $diffColors  = ['beginner'=>'#3be07a','intermediate'=>'var(--mca-orange)','advanced'=>'#e03b3b'];
        $diffLabels  = ['beginner'=>'Začátečník','intermediate'=>'Střední','advanced'=>'Pokročilý'];
        $exUrl       = ($userType === 'coach')
            ? BASE_URL . '/mycoach_app_exercise.php?id=' . (int)$ex['id']
            : BASE_URL . '/athlete_mycoach_app_exercise.php?id=' . (int)$ex['id'];
      ?>
      <a href="<?= h($exUrl) ?>" class="mca-exercise-card">
        <div class="mca-exercise-thumb">
          <?php if (!empty($ex['thumbnail'])): ?>
            <img src="<?= h(BASE_URL . '/' . ltrim($ex['thumbnail'],'/')) ?>" alt="">
          <?php else: ?>
            <i class="fas fa-person-running"></i>
          <?php endif; ?>
        </div>
        <div class="flex-grow-1">
          <div class="fw-bold"><?= h($ex['name']) ?></div>
          <div style="font-size:.8rem;color:var(--mca-text-muted);margin:.15rem 0 .4rem;">
            <?php if ($ex['category']): ?><span><?= h($ex['category']) ?></span><?php endif; ?>
            <?php if ($ex['muscle_groups']): ?>&nbsp;· <span><?= h($ex['muscle_groups']) ?></span><?php endif; ?>
            <?php if ($ex['equipment']): ?>&nbsp;· <span><?= h($ex['equipment']) ?></span><?php endif; ?>
          </div>
          <span class="mca-tile-badge" style="color:<?= $diffColors[$ex['difficulty']] ?? 'var(--mca-orange)' ?>;">
            <?= $diffLabels[$ex['difficulty']] ?? $ex['difficulty'] ?>
          </span>
          <?php if (!empty($ex['video_url'])): ?>
            <span class="mca-tile-badge ms-1"><i class="fas fa-play me-1"></i>Video</span>
          <?php endif; ?>
        </div>
        <div style="color:var(--mca-metal-3);align-self:center;">
          <i class="fas fa-chevron-right"></i>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
