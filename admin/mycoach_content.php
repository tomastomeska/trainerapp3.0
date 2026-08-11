<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

// ── Migrace tabulek za běhu (pokud ještě neproběhla) ─────────
foreach ([
    'mycoach_app_sections','mycoach_app_videos','mycoach_app_exercises',
    'mycoach_app_workouts','mycoach_app_workout_exercises','mycoach_app_foods',
] as $tbl) {
    $r = $pdo->query("SHOW TABLES LIKE '$tbl'");
    if (!$r || !$r->fetch()) {
        flash('warning', "Tabulka $tbl chybí – spusťte scripts/migrate_mycoach_app.php");
        break;
    }
}

$sectionTypes   = mycoachAppSectionTypes();
$tileColors     = ['orange','yellow','red','blue','green','teal','purple'];
$difficultyOpts = ['beginner'=>'Začátečník','intermediate'=>'Středně pokročilý','advanced'=>'Pokročilý'];

// ── POST akce ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný token.');
        redirect(BASE_URL . '/admin/mycoach_content.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    // ── Sekce ──────────────────────────────────────────────
    if ($action === 'save_section') {
        $id       = (int)($_POST['section_id'] ?? 0);
        $title    = trim((string)($_POST['title'] ?? ''));
        $subtitle = trim((string)($_POST['subtitle'] ?? ''));
        $icon     = preg_match('/^fa-[a-z0-9-]+$/', trim((string)($_POST['icon_class'] ?? ''))) ? trim((string)$_POST['icon_class']) : 'fa-play-circle';
        $color    = in_array(trim((string)($_POST['tile_color'] ?? '')), $tileColors, true) ? trim((string)$_POST['tile_color']) : 'orange';
        $type     = isset($sectionTypes[trim((string)($_POST['section_type'] ?? ''))]) ? trim((string)$_POST['section_type']) : 'videos';
        $desc     = trim((string)($_POST['description'] ?? ''));
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $audience = in_array(trim((string)($_POST['audience'] ?? 'all')), ['all','coach','athlete'], true) ? trim((string)$_POST['audience']) : 'all';

        if ($title === '') { flash('danger', 'Název sekce je povinný.'); redirect(BASE_URL . '/admin/mycoach_content.php'); }

        if ($id > 0) {
            $pdo->prepare('UPDATE mycoach_app_sections SET title=?,subtitle=?,icon_class=?,tile_color=?,section_type=?,description=?,sort_order=?,is_active=?,audience=? WHERE id=?')
                ->execute([$title,$subtitle,$icon,$color,$type,$desc,$sort,$active,$audience,$id]);
            flash('success', 'Sekce byla uložena.');
        } else {
            $pdo->prepare('INSERT INTO mycoach_app_sections (title,subtitle,icon_class,tile_color,section_type,description,sort_order,is_active,audience) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$title,$subtitle,$icon,$color,$type,$desc,$sort,$active,$audience]);
            flash('success', 'Sekce byla přidána.');
        }
        redirect(BASE_URL . '/admin/mycoach_content.php#sections');
    }

    if ($action === 'delete_section') {
        $id = (int)($_POST['section_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_sections WHERE id=?')->execute([$id]); flash('success','Sekce smazána.'); }
        redirect(BASE_URL . '/admin/mycoach_content.php#sections');
    }

    // ── Videa ──────────────────────────────────────────────
    if ($action === 'save_video') {
        $id       = (int)($_POST['video_id'] ?? 0);
        $secId    = (int)($_POST['section_id'] ?? 0) ?: null;
        $title    = trim((string)($_POST['title'] ?? ''));
        $desc     = trim((string)($_POST['description'] ?? ''));
        $vtype    = in_array(trim((string)($_POST['video_type'] ?? '')), ['upload','youtube','vimeo','url'], true) ? trim((string)$_POST['video_type']) : 'youtube';
        $vurl     = trim((string)($_POST['video_url'] ?? ''));
        $vpath    = trim((string)($_POST['video_path'] ?? ''));
        $thumb    = trim((string)($_POST['thumbnail'] ?? ''));
        $dur      = is_numeric($_POST['duration_seconds'] ?? '') ? (int)$_POST['duration_seconds'] : null;
        $tags     = trim((string)($_POST['tags'] ?? ''));
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $active   = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') { flash('danger', 'Název videa je povinný.'); redirect(BASE_URL . '/admin/mycoach_content.php#videos'); }

        if ($id > 0) {
            $pdo->prepare('UPDATE mycoach_app_videos SET section_id=?,title=?,description=?,video_type=?,video_url=?,video_path=?,thumbnail=?,duration_seconds=?,tags=?,sort_order=?,is_active=? WHERE id=?')
                ->execute([$secId,$title,$desc,$vtype,$vurl,$vpath,$thumb,$dur,$tags,$sort,$active,$id]);
            flash('success', 'Video bylo uloženo.');
        } else {
            $pdo->prepare('INSERT INTO mycoach_app_videos (section_id,title,description,video_type,video_url,video_path,thumbnail,duration_seconds,tags,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$secId,$title,$desc,$vtype,$vurl,$vpath,$thumb,$dur,$tags,$sort,$active]);
            flash('success', 'Video bylo přidáno.');
        }
        redirect(BASE_URL . '/admin/mycoach_content.php#videos');
    }

    if ($action === 'delete_video') {
        $id = (int)($_POST['video_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_videos WHERE id=?')->execute([$id]); flash('success','Video smazáno.'); }
        redirect(BASE_URL . '/admin/mycoach_content.php#videos');
    }

    // ── Cviky ──────────────────────────────────────────────
    if ($action === 'save_exercise') {
        $id     = (int)($_POST['exercise_id'] ?? 0);
        $name   = trim((string)($_POST['name'] ?? ''));
        $slug   = mycoachAppExerciseSlugify($name);
        $cat    = trim((string)($_POST['category'] ?? ''));
        $desc   = trim((string)($_POST['description'] ?? ''));
        $instr  = trim((string)($_POST['instructions'] ?? ''));
        $muscles= trim((string)($_POST['muscle_groups'] ?? ''));
        $equip  = trim((string)($_POST['equipment'] ?? ''));
        $diff   = in_array(trim((string)($_POST['difficulty'] ?? '')), ['beginner','intermediate','advanced'], true) ? trim((string)$_POST['difficulty']) : 'intermediate';
        $vurl   = trim((string)($_POST['video_url'] ?? ''));
        $thumb  = trim((string)($_POST['thumbnail'] ?? ''));
        $sort   = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') { flash('danger', 'Název cviku je povinný.'); redirect(BASE_URL . '/admin/mycoach_content.php#exercises'); }

        if ($id > 0) {
            $pdo->prepare('UPDATE mycoach_app_exercises SET name=?,category=?,description=?,instructions=?,muscle_groups=?,equipment=?,difficulty=?,video_url=?,thumbnail=?,sort_order=?,is_active=? WHERE id=?')
                ->execute([$name,$cat,$desc,$instr,$muscles,$equip,$diff,$vurl,$thumb,$sort,$active,$id]);
            flash('success', 'Cvik byl uložen.');
        } else {
            // Unikátní slug při duplicitách
            $baseSlug = $slug; $i = 1;
            while (true) {
                $chk = $pdo->prepare('SELECT id FROM mycoach_app_exercises WHERE slug=? LIMIT 1');
                $chk->execute([$slug]);
                if (!$chk->fetch()) break;
                $slug = $baseSlug . '-' . $i++;
            }
            $pdo->prepare('INSERT INTO mycoach_app_exercises (name,slug,category,description,instructions,muscle_groups,equipment,difficulty,video_url,thumbnail,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$name,$slug,$cat,$desc,$instr,$muscles,$equip,$diff,$vurl,$thumb,$sort,$active]);
            flash('success', 'Cvik byl přidán.');
        }
        redirect(BASE_URL . '/admin/mycoach_content.php#exercises');
    }

    if ($action === 'delete_exercise') {
        $id = (int)($_POST['exercise_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_exercises WHERE id=?')->execute([$id]); flash('success','Cvik smazán.'); }
        redirect(BASE_URL . '/admin/mycoach_content.php#exercises');
    }

    // ── Tréninky ───────────────────────────────────────────
    if ($action === 'save_workout') {
        $id    = (int)($_POST['workout_id'] ?? 0);
        $secId = (int)($_POST['section_id'] ?? 0) ?: null;
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $diff  = in_array(trim((string)($_POST['difficulty'] ?? '')), ['beginner','intermediate','advanced'], true) ? trim((string)$_POST['difficulty']) : 'intermediate';
        $dur   = is_numeric($_POST['duration_minutes'] ?? '') ? (int)$_POST['duration_minutes'] : null;
        $thumb = trim((string)($_POST['thumbnail'] ?? ''));
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $active= isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') { flash('danger', 'Název tréninku je povinný.'); redirect(BASE_URL . '/admin/mycoach_content.php#workouts'); }

        if ($id > 0) {
            $pdo->prepare('UPDATE mycoach_app_workouts SET section_id=?,title=?,description=?,difficulty=?,duration_minutes=?,thumbnail=?,sort_order=?,is_active=? WHERE id=?')
                ->execute([$secId,$title,$desc,$diff,$dur,$thumb,$sort,$active,$id]);
        } else {
            $pdo->prepare('INSERT INTO mycoach_app_workouts (section_id,title,description,difficulty,duration_minutes,thumbnail,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$secId,$title,$desc,$diff,$dur,$thumb,$sort,$active]);
        }
        flash('success', 'Trénink byl uložen.');
        redirect(BASE_URL . '/admin/mycoach_content.php#workouts');
    }

    if ($action === 'delete_workout') {
        $id = (int)($_POST['workout_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_workouts WHERE id=?')->execute([$id]); flash('success','Trénink smazán.'); }
        redirect(BASE_URL . '/admin/mycoach_content.php#workouts');
    }

    // ── Cviky v tréninku ───────────────────────────────────
    if ($action === 'save_workout_exercise') {
        $wid   = (int)($_POST['workout_id'] ?? 0);
        $exid  = (int)($_POST['exercise_id'] ?? 0);
        $sets  = is_numeric($_POST['sets'] ?? '') ? (int)$_POST['sets'] : null;
        $reps  = is_numeric($_POST['reps'] ?? '') ? (int)$_POST['reps'] : null;
        $durS  = is_numeric($_POST['duration_seconds'] ?? '') ? (int)$_POST['duration_seconds'] : null;
        $rest  = is_numeric($_POST['rest_seconds'] ?? '') ? (int)$_POST['rest_seconds'] : null;
        $notes = trim((string)($_POST['notes'] ?? ''));
        $sort  = (int)($_POST['sort_order'] ?? 0);

        if ($wid > 0 && $exid > 0) {
            $pdo->prepare('INSERT INTO mycoach_app_workout_exercises (workout_id,exercise_id,sets,reps,duration_seconds,rest_seconds,notes,sort_order) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$wid,$exid,$sets,$reps,$durS,$rest,$notes,$sort]);
            flash('success', 'Cvik byl přidán do tréninku.');
        }
        redirect(BASE_URL . '/admin/mycoach_content.php?edit_workout=' . $wid . '#workouts');
    }

    if ($action === 'delete_workout_exercise') {
        $id  = (int)($_POST['workout_exercise_id'] ?? 0);
        $wid = (int)($_POST['workout_id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM mycoach_app_workout_exercises WHERE id=?')->execute([$id]); flash('success','Cvik odebrán.'); }
        redirect(BASE_URL . '/admin/mycoach_content.php?edit_workout=' . $wid . '#workouts');
    }
}

// ── Načtení dat ───────────────────────────────────────────────
$sections  = [];
$videos    = [];
$exercises = [];
$workouts  = [];
try { $sections  = $pdo->query('SELECT * FROM mycoach_app_sections ORDER BY sort_order ASC, id ASC')->fetchAll() ?: []; } catch(Throwable $e){}
try { $videos    = $pdo->query('SELECT v.*, s.title AS section_title FROM mycoach_app_videos v LEFT JOIN mycoach_app_sections s ON s.id=v.section_id ORDER BY v.sort_order ASC, v.id ASC')->fetchAll() ?: []; } catch(Throwable $e){}
try { $exercises = $pdo->query('SELECT * FROM mycoach_app_exercises ORDER BY sort_order ASC, name ASC')->fetchAll() ?: []; } catch(Throwable $e){}
try { $workouts  = $pdo->query('SELECT w.*, s.title AS section_title FROM mycoach_app_workouts w LEFT JOIN mycoach_app_sections s ON s.id=w.section_id ORDER BY w.sort_order ASC, w.id ASC')->fetchAll() ?: []; } catch(Throwable $e){}

// Detail tréninku pro edit
$editWorkoutId = (int)($_GET['edit_workout'] ?? 0);
$editWorkout   = null;
$workoutExercises = [];
if ($editWorkoutId > 0) {
    try {
        $editWorkout = $pdo->prepare('SELECT * FROM mycoach_app_workouts WHERE id=? LIMIT 1');
        $editWorkout->execute([$editWorkoutId]);
        $editWorkout = $editWorkout->fetch() ?: null;
        if ($editWorkout) {
            $weStmt = $pdo->prepare('SELECT we.*, e.name AS ex_name FROM mycoach_app_workout_exercises we LEFT JOIN mycoach_app_exercises e ON e.id=we.exercise_id WHERE we.workout_id=? ORDER BY we.sort_order ASC, we.id ASC');
            $weStmt->execute([$editWorkoutId]);
            $workoutExercises = $weStmt->fetchAll() ?: [];
        }
    } catch(Throwable $e){ $editWorkout = null; }
}

renderAdminHeader('MyCoach – správa obsahu');

function mcAdminSectionOptions(array $sections, ?int $selected = null): string {
    $out = '<option value="">– Bez sekce –</option>';
    foreach ($sections as $s) {
        $sel = ($selected !== null && (int)$s['id'] === $selected) ? ' selected' : '';
        $out .= '<option value="' . (int)$s['id'] . '"' . $sel . '>' . h($s['title']) . '</option>';
    }
    return $out;
}
function mcAdminExerciseOptions(array $exercises, ?int $selected = null): string {
    $out = '<option value="">– Vyberte cvik –</option>';
    foreach ($exercises as $e) {
        $sel = ($selected !== null && (int)$e['id'] === $selected) ? ' selected' : '';
        $out .= '<option value="' . (int)$e['id'] . '"' . $sel . '>' . h($e['name']) . '</option>';
    }
    return $out;
}
?>

<style>
.mcc-card { border:0; box-shadow:0 1px 8px rgba(0,0,0,.08); border-radius:12px; margin-bottom:1.5rem; }
.mcc-card .card-header { border-radius:12px 12px 0 0; font-weight:700; }
.mcc-tab-nav .nav-link { font-weight:600; font-size:.9rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="fas fa-layer-group me-2 text-warning"></i>MyCoach App – správa obsahu</h4>
        <div class="text-muted small">Sekce, videa, cviky, tréninky</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/mycoach.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>MyCoach admin
    </a>
</div>

<!-- Flash zprávy -->
<?php
$_flash = getFlash();
if ($_flash): ?>
<div class="alert alert-<?= h($_flash['type']) ?> alert-dismissible fade show py-2">
  <?= !empty($_flash['html']) ? $_flash['message'] : h($_flash['message']) ?>
  <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mcc-tab-nav mb-4" id="mccTabs">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabSections"><i class="fas fa-grid-2 me-1"></i>Sekce</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabVideos"><i class="fas fa-play-circle me-1"></i>Videa</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabExercises"><i class="fas fa-person-running me-1"></i>Cviky</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabWorkouts"><i class="fas fa-dumbbell me-1"></i>Tréninky</a></li>
</ul>

<div class="tab-content">

<!-- ══════════ SEKCE ══════════ -->
<div class="tab-pane fade show active" id="tabSections">
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card mcc-card">
        <div class="card-header" style="background:#1a1a2e;color:#f7941d;">
          <i class="fas fa-plus me-1"></i>Přidat / upravit sekci
        </div>
        <div class="card-body" id="sectionFormWrap">
          <form method="post" id="sectionForm">
            <?= csrfField() ?><input type="hidden" name="action" value="save_section">
            <input type="hidden" name="section_id" id="sfId" value="0">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Název *</label>
              <input type="text" name="title" id="sfTitle" class="form-control form-control-sm" required>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Podnázev</label>
              <input type="text" name="subtitle" id="sfSubtitle" class="form-control form-control-sm">
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Typ sekce</label>
                <select name="section_type" id="sfType" class="form-select form-select-sm">
                  <?php foreach ($sectionTypes as $k=>$t): ?>
                  <option value="<?= h($k) ?>"><?= h($t['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Barva dlaždice</label>
                <select name="tile_color" id="sfColor" class="form-select form-select-sm">
                  <?php foreach ($tileColors as $c): ?>
                  <option value="<?= $c ?>"><?= ucfirst($c) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-7">
                <label class="form-label small fw-semibold">FontAwesome ikona</label>
                <input type="text" name="icon_class" id="sfIcon" class="form-control form-control-sm" placeholder="fa-play-circle">
              </div>
              <div class="col-5">
                <label class="form-label small fw-semibold">Pořadí</label>
                <input type="number" name="sort_order" id="sfSort" class="form-control form-control-sm" value="0">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Cílová skupina</label>
              <select name="audience" id="sfAudience" class="form-select form-select-sm">
                <option value="all">Všichni</option>
                <option value="coach">Jen trenéři</option>
                <option value="athlete">Jen sportovci</option>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Popis</label>
              <textarea name="description" id="sfDesc" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="mb-3 form-check">
              <input type="checkbox" name="is_active" id="sfActive" class="form-check-input" value="1" checked>
              <label class="form-check-label small" for="sfActive">Aktivní</label>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-warning btn-sm fw-semibold flex-grow-1">Uložit sekci</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="sectionFormReset()">Reset</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card mcc-card">
        <div class="card-header bg-dark text-white">Sekce (<?= count($sections) ?>)</div>
        <div class="card-body p-0">
          <?php if (empty($sections)): ?>
            <p class="p-3 text-muted">Zatím žádné sekce.</p>
          <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr>
              <th>#</th><th>Název</th><th>Typ</th><th class="text-center">Skupina</th><th class="text-center">Aktivní</th><th class="text-end">Akce</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sections as $sec): ?>
            <tr>
              <td class="text-muted small"><?= (int)$sec['sort_order'] ?></td>
              <td>
                <div class="fw-semibold"><?= h($sec['title']) ?></div>
                <?php if ($sec['subtitle']): ?><div class="text-muted small"><?= h($sec['subtitle']) ?></div><?php endif; ?>
              </td>
              <td><span class="badge bg-secondary"><?= h($sectionTypes[$sec['section_type']]['label'] ?? $sec['section_type']) ?></span></td>
              <td class="text-center small"><?= h($sec['audience']) ?></td>
              <td class="text-center">
                <span class="badge <?= $sec['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $sec['is_active'] ? 'Ano' : 'Ne' ?></span>
              </td>
              <td class="text-end">
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm"
                        onclick="sectionFormFill(<?= json_encode($sec) ?>)">
                  <i class="fas fa-pen"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirm('Smazat sekci?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_section">
                  <input type="hidden" name="section_id" value="<?= (int)$sec['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ VIDEA ══════════ -->
<div class="tab-pane fade" id="tabVideos">
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card mcc-card">
        <div class="card-header" style="background:#1a1a2e;color:#f7941d;">
          <i class="fas fa-plus me-1"></i>Přidat / upravit video
        </div>
        <div class="card-body">
          <form method="post" id="videoForm">
            <?= csrfField() ?><input type="hidden" name="action" value="save_video">
            <input type="hidden" name="video_id" id="vfId" value="0">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Název *</label>
              <input type="text" name="title" id="vfTitle" class="form-control form-control-sm" required>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Sekce</label>
              <select name="section_id" id="vfSection" class="form-select form-select-sm">
                <?= mcAdminSectionOptions($sections) ?>
              </select>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Typ videa</label>
              <select name="video_type" id="vfType" class="form-select form-select-sm" onchange="videoTypeChange(this)">
                <option value="youtube">YouTube</option>
                <option value="vimeo">Vimeo</option>
                <option value="upload">Upload (soubor)</option>
                <option value="url">Přímá URL</option>
              </select>
            </div>
            <div class="mb-2" id="vfUrlWrap">
              <label class="form-label small fw-semibold" id="vfUrlLabel">YouTube URL nebo ID</label>
              <input type="text" name="video_url" id="vfUrl" class="form-control form-control-sm" placeholder="https://youtube.com/watch?v=...">
            </div>
            <div class="mb-2 d-none" id="vfPathWrap">
              <label class="form-label small fw-semibold">Cesta k souboru (uploads/...)</label>
              <input type="text" name="video_path" id="vfPath" class="form-control form-control-sm" placeholder="uploads/movie/...">
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Náhledový obrázek (URL/cesta)</label>
              <input type="text" name="thumbnail" id="vfThumb" class="form-control form-control-sm">
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Délka (sekundy)</label>
                <input type="number" name="duration_seconds" id="vfDur" class="form-control form-control-sm" min="0">
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Pořadí</label>
                <input type="number" name="sort_order" id="vfSort" class="form-control form-control-sm" value="0">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Tagy (čárkou)</label>
              <input type="text" name="tags" id="vfTags" class="form-control form-control-sm">
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Popis</label>
              <textarea name="description" id="vfDesc" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="mb-3 form-check">
              <input type="checkbox" name="is_active" id="vfActive" class="form-check-input" value="1" checked>
              <label class="form-check-label small" for="vfActive">Aktivní</label>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-warning btn-sm fw-semibold flex-grow-1">Uložit video</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="videoFormReset()">Reset</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card mcc-card">
        <div class="card-header bg-dark text-white">Videa (<?= count($videos) ?>)</div>
        <div class="card-body p-0">
          <?php if (empty($videos)): ?>
            <p class="p-3 text-muted">Zatím žádná videa.</p>
          <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr>
              <th>#</th><th>Název</th><th>Sekce</th><th>Typ</th><th class="text-center">Délka</th><th class="text-end">Akce</th>
            </tr></thead>
            <tbody>
            <?php foreach ($videos as $vid): ?>
            <tr>
              <td class="text-muted small"><?= (int)$vid['sort_order'] ?></td>
              <td>
                <div class="fw-semibold"><?= h($vid['title']) ?></div>
                <?php if ($vid['tags']): ?><div class="text-muted small"><?= h($vid['tags']) ?></div><?php endif; ?>
              </td>
              <td class="small text-muted"><?= h($vid['section_title'] ?? '–') ?></td>
              <td><span class="badge bg-info text-dark small"><?= h($vid['video_type']) ?></span></td>
              <td class="text-center small"><?= $vid['duration_seconds'] ? mycoachAppFormatDuration((int)$vid['duration_seconds']) : '–' ?></td>
              <td class="text-end">
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm"
                        onclick="videoFormFill(<?= json_encode($vid) ?>)">
                  <i class="fas fa-pen"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirm('Smazat video?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_video">
                  <input type="hidden" name="video_id" value="<?= (int)$vid['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ CVIKY ══════════ -->
<div class="tab-pane fade" id="tabExercises">
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card mcc-card">
        <div class="card-header" style="background:#1a1a2e;color:#f7941d;">
          <i class="fas fa-plus me-1"></i>Přidat / upravit cvik
        </div>
        <div class="card-body">
          <form method="post" id="exerciseForm">
            <?= csrfField() ?><input type="hidden" name="action" value="save_exercise">
            <input type="hidden" name="exercise_id" id="efId" value="0">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Název *</label>
              <input type="text" name="name" id="efName" class="form-control form-control-sm" required>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Kategorie</label>
                <input type="text" name="category" id="efCat" class="form-control form-control-sm" placeholder="např. Síla, Kardio">
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Obtížnost</label>
                <select name="difficulty" id="efDiff" class="form-select form-select-sm">
                  <?php foreach ($difficultyOpts as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Svalové partie</label>
              <input type="text" name="muscle_groups" id="efMuscles" class="form-control form-control-sm" placeholder="např. Nohy, Záda">
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Vybavení</label>
              <input type="text" name="equipment" id="efEquip" class="form-control form-control-sm" placeholder="např. Činka, Kettlebell">
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Video URL</label>
              <input type="text" name="video_url" id="efVideo" class="form-control form-control-sm" placeholder="YouTube/Vimeo URL">
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Náhled (URL/cesta)</label>
              <input type="text" name="thumbnail" id="efThumb" class="form-control form-control-sm">
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Popis</label>
              <textarea name="description" id="efDesc" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Postup / instrukce</label>
              <textarea name="instructions" id="efInstr" class="form-control form-control-sm" rows="3"></textarea>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Pořadí</label>
                <input type="number" name="sort_order" id="efSort" class="form-control form-control-sm" value="0">
              </div>
            </div>
            <div class="mb-3 form-check">
              <input type="checkbox" name="is_active" id="efActive" class="form-check-input" value="1" checked>
              <label class="form-check-label small" for="efActive">Aktivní</label>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-warning btn-sm fw-semibold flex-grow-1">Uložit cvik</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exerciseFormReset()">Reset</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card mcc-card">
        <div class="card-header bg-dark text-white">Encyklopedie cviků (<?= count($exercises) ?>)</div>
        <div class="card-body p-0">
          <?php if (empty($exercises)): ?>
            <p class="p-3 text-muted">Zatím žádné cviky.</p>
          <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr>
              <th>Cvik</th><th>Kategorie</th><th>Obtížnost</th><th>Svalové partie</th><th class="text-center">Aktivní</th><th class="text-end">Akce</th>
            </tr></thead>
            <tbody>
            <?php foreach ($exercises as $ex): ?>
            <tr>
              <td class="fw-semibold"><?= h($ex['name']) ?></td>
              <td class="small text-muted"><?= h($ex['category'] ?? '–') ?></td>
              <td><span class="badge <?= ['beginner'=>'bg-success','intermediate'=>'bg-warning text-dark','advanced'=>'bg-danger'][$ex['difficulty']] ?? 'bg-secondary' ?> small">
                <?= $difficultyOpts[$ex['difficulty']] ?? $ex['difficulty'] ?></span>
              </td>
              <td class="small text-muted"><?= h($ex['muscle_groups'] ?? '–') ?></td>
              <td class="text-center"><span class="badge <?= $ex['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $ex['is_active'] ? 'Ano' : 'Ne' ?></span></td>
              <td class="text-end">
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm"
                        onclick="exerciseFormFill(<?= json_encode($ex) ?>)">
                  <i class="fas fa-pen"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirm('Smazat cvik?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_exercise">
                  <input type="hidden" name="exercise_id" value="<?= (int)$ex['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ TRÉNINKY ══════════ -->
<div class="tab-pane fade" id="tabWorkouts">
  <?php if ($editWorkout): ?>
  <!-- Detail tréninku – přidání cviků -->
  <div class="card mcc-card mb-3">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
      <span><i class="fas fa-list-ol me-1"></i>Cviky v tréninku: <strong><?= h($editWorkout['title']) ?></strong></span>
      <a href="<?= BASE_URL ?>/admin/mycoach_content.php#workouts" class="btn btn-sm btn-outline-light">
        <i class="fas fa-arrow-left me-1"></i>Zpět
      </a>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-5">
          <form method="post">
            <?= csrfField() ?><input type="hidden" name="action" value="save_workout_exercise">
            <input type="hidden" name="workout_id" value="<?= (int)$editWorkout['id'] ?>">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Cvik *</label>
              <select name="exercise_id" class="form-select form-select-sm" required>
                <?= mcAdminExerciseOptions($exercises) ?>
              </select>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-3"><label class="form-label small fw-semibold">Série</label><input type="number" name="sets" class="form-control form-control-sm" min="1" max="99"></div>
              <div class="col-3"><label class="form-label small fw-semibold">Opak.</label><input type="number" name="reps" class="form-control form-control-sm" min="1" max="999"></div>
              <div class="col-3"><label class="form-label small fw-semibold">Čas (s)</label><input type="number" name="duration_seconds" class="form-control form-control-sm" min="0"></div>
              <div class="col-3"><label class="form-label small fw-semibold">Pauza (s)</label><input type="number" name="rest_seconds" class="form-control form-control-sm" min="0"></div>
            </div>
            <div class="mb-2"><label class="form-label small fw-semibold">Poznámka</label><input type="text" name="notes" class="form-control form-control-sm"></div>
            <div class="mb-2"><label class="form-label small fw-semibold">Pořadí</label><input type="number" name="sort_order" class="form-control form-control-sm" value="<?= count($workoutExercises) * 10 ?>"></div>
            <button type="submit" class="btn btn-warning btn-sm fw-semibold">Přidat cvik</button>
          </form>
        </div>
        <div class="col-md-7">
          <?php if (empty($workoutExercises)): ?>
            <p class="text-muted">Žádné cviky v tréninku.</p>
          <?php else: ?>
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Cvik</th><th>Série×Opak.</th><th>Čas</th><th>Pauza</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($workoutExercises as $we): ?>
            <tr>
              <td class="text-muted small"><?= (int)$we['sort_order'] ?></td>
              <td class="fw-semibold"><?= h($we['ex_name'] ?? '?') ?></td>
              <td class="small"><?= $we['sets'] ? $we['sets'] . '×' . $we['reps'] : '–' ?></td>
              <td class="small"><?= $we['duration_seconds'] ? $we['duration_seconds'] . 's' : '–' ?></td>
              <td class="small"><?= $we['rest_seconds'] ? $we['rest_seconds'] . 's' : '–' ?></td>
              <td>
                <form method="post" class="d-inline" onsubmit="return confirm('Odebrat cvik?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_workout_exercise">
                  <input type="hidden" name="workout_exercise_id" value="<?= (int)$we['id'] ?>">
                  <input type="hidden" name="workout_id" value="<?= (int)$editWorkout['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-times"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card mcc-card">
        <div class="card-header" style="background:#1a1a2e;color:#f7941d;"><i class="fas fa-plus me-1"></i>Přidat / upravit trénink</div>
        <div class="card-body">
          <form method="post" id="workoutForm">
            <?= csrfField() ?><input type="hidden" name="action" value="save_workout">
            <input type="hidden" name="workout_id" id="wfId" value="0">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Název *</label>
              <input type="text" name="title" id="wfTitle" class="form-control form-control-sm" required>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Sekce</label>
              <select name="section_id" id="wfSection" class="form-select form-select-sm"><?= mcAdminSectionOptions($sections) ?></select>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Obtížnost</label>
                <select name="difficulty" id="wfDiff" class="form-select form-select-sm">
                  <?php foreach ($difficultyOpts as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold">Délka (min)</label>
                <input type="number" name="duration_minutes" id="wfDur" class="form-control form-control-sm" min="0">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Popis</label>
              <textarea name="description" id="wfDesc" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-semibold">Pořadí</label>
                <input type="number" name="sort_order" id="wfSort" class="form-control form-control-sm" value="0">
              </div>
            </div>
            <div class="mb-3 form-check">
              <input type="checkbox" name="is_active" id="wfActive" class="form-check-input" value="1" checked>
              <label class="form-check-label small" for="wfActive">Aktivní</label>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-warning btn-sm fw-semibold flex-grow-1">Uložit trénink</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="workoutFormReset()">Reset</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card mcc-card">
        <div class="card-header bg-dark text-white">Tréninky (<?= count($workouts) ?>)</div>
        <div class="card-body p-0">
          <?php if (empty($workouts)): ?>
            <p class="p-3 text-muted">Zatím žádné tréninky.</p>
          <?php else: ?>
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Název</th><th>Sekce</th><th>Obtížnost</th><th class="text-center">Délka</th><th class="text-end">Akce</th></tr></thead>
            <tbody>
            <?php foreach ($workouts as $wo): ?>
            <tr>
              <td class="fw-semibold"><?= h($wo['title']) ?></td>
              <td class="small text-muted"><?= h($wo['section_title'] ?? '–') ?></td>
              <td><span class="badge <?= ['beginner'=>'bg-success','intermediate'=>'bg-warning text-dark','advanced'=>'bg-danger'][$wo['difficulty']] ?? 'bg-secondary' ?> small"><?= $difficultyOpts[$wo['difficulty']] ?? $wo['difficulty'] ?></span></td>
              <td class="text-center small"><?= $wo['duration_minutes'] ? $wo['duration_minutes'] . ' min' : '–' ?></td>
              <td class="text-end">
                <a href="<?= BASE_URL ?>/admin/mycoach_content.php?edit_workout=<?= (int)$wo['id'] ?>#workouts"
                   class="btn btn-xs btn-outline-info btn-sm" title="Cviky tréninku">
                  <i class="fas fa-list-ol"></i>
                </a>
                <button type="button" class="btn btn-xs btn-outline-primary btn-sm"
                        onclick="workoutFormFill(<?= json_encode($wo) ?>)">
                  <i class="fas fa-pen"></i>
                </button>
                <form method="post" class="d-inline" onsubmit="return confirm('Smazat trénink?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_workout">
                  <input type="hidden" name="workout_id" value="<?= (int)$wo['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

</div><!-- /tab-content -->

<script>
// ── Sekce formulář ──────────────────────────────────────────────
function sectionFormReset() {
  document.getElementById('sfId').value='0';
  ['sfTitle','sfSubtitle','sfIcon','sfDesc'].forEach(id=>{document.getElementById(id).value='';});
  document.getElementById('sfSort').value='0';
  document.getElementById('sfActive').checked=true;
  document.getElementById('sfType').value='videos';
  document.getElementById('sfColor').value='orange';
  document.getElementById('sfAudience').value='all';
}
function sectionFormFill(sec) {
  document.getElementById('sfId').value=sec.id;
  document.getElementById('sfTitle').value=sec.title||'';
  document.getElementById('sfSubtitle').value=sec.subtitle||'';
  document.getElementById('sfIcon').value=sec.icon_class||'';
  document.getElementById('sfColor').value=sec.tile_color||'orange';
  document.getElementById('sfType').value=sec.section_type||'videos';
  document.getElementById('sfAudience').value=sec.audience||'all';
  document.getElementById('sfDesc').value=sec.description||'';
  document.getElementById('sfSort').value=sec.sort_order||0;
  document.getElementById('sfActive').checked=sec.is_active=='1'||sec.is_active===1;
  document.getElementById('sectionFormWrap').scrollIntoView({behavior:'smooth'});
}

// ── Videa formulář ──────────────────────────────────────────────
function videoFormReset() {
  document.getElementById('vfId').value='0';
  ['vfTitle','vfUrl','vfPath','vfThumb','vfTags','vfDesc'].forEach(id=>{document.getElementById(id).value='';});
  document.getElementById('vfDur').value='';
  document.getElementById('vfSort').value='0';
  document.getElementById('vfActive').checked=true;
  document.getElementById('vfType').value='youtube';
  videoTypeChange(document.getElementById('vfType'));
}
function videoTypeChange(sel) {
  const isUpload = sel.value==='upload';
  document.getElementById('vfUrlWrap').classList.toggle('d-none', isUpload);
  document.getElementById('vfPathWrap').classList.toggle('d-none', !isUpload);
  const labels={'youtube':'YouTube URL nebo ID','vimeo':'Vimeo URL nebo ID','url':'Přímá URL videa'};
  document.getElementById('vfUrlLabel').textContent=labels[sel.value]||'URL';
}
function videoFormFill(vid) {
  document.getElementById('vfId').value=vid.id;
  document.getElementById('vfTitle').value=vid.title||'';
  document.getElementById('vfSection').value=vid.section_id||'';
  document.getElementById('vfType').value=vid.video_type||'youtube';
  videoTypeChange(document.getElementById('vfType'));
  document.getElementById('vfUrl').value=vid.video_url||'';
  document.getElementById('vfPath').value=vid.video_path||'';
  document.getElementById('vfThumb').value=vid.thumbnail||'';
  document.getElementById('vfDur').value=vid.duration_seconds||'';
  document.getElementById('vfTags').value=vid.tags||'';
  document.getElementById('vfDesc').value=vid.description||'';
  document.getElementById('vfSort').value=vid.sort_order||0;
  document.getElementById('vfActive').checked=vid.is_active=='1'||vid.is_active===1;
}

// ── Cviky formulář ──────────────────────────────────────────────
function exerciseFormReset() {
  document.getElementById('efId').value='0';
  ['efName','efCat','efMuscles','efEquip','efVideo','efThumb','efDesc','efInstr'].forEach(id=>{document.getElementById(id).value='';});
  document.getElementById('efDiff').value='intermediate';
  document.getElementById('efSort').value='0';
  document.getElementById('efActive').checked=true;
}
function exerciseFormFill(ex) {
  document.getElementById('efId').value=ex.id;
  document.getElementById('efName').value=ex.name||'';
  document.getElementById('efCat').value=ex.category||'';
  document.getElementById('efDiff').value=ex.difficulty||'intermediate';
  document.getElementById('efMuscles').value=ex.muscle_groups||'';
  document.getElementById('efEquip').value=ex.equipment||'';
  document.getElementById('efVideo').value=ex.video_url||'';
  document.getElementById('efThumb').value=ex.thumbnail||'';
  document.getElementById('efDesc').value=ex.description||'';
  document.getElementById('efInstr').value=ex.instructions||'';
  document.getElementById('efSort').value=ex.sort_order||0;
  document.getElementById('efActive').checked=ex.is_active=='1'||ex.is_active===1;
}

// ── Tréninky formulář ────────────────────────────────────────────
function workoutFormReset() {
  document.getElementById('wfId').value='0';
  ['wfTitle','wfDesc'].forEach(id=>{document.getElementById(id).value='';});
  document.getElementById('wfDur').value='';
  document.getElementById('wfSort').value='0';
  document.getElementById('wfActive').checked=true;
  document.getElementById('wfDiff').value='intermediate';
  document.getElementById('wfSection').value='';
}
function workoutFormFill(wo) {
  document.getElementById('wfId').value=wo.id;
  document.getElementById('wfTitle').value=wo.title||'';
  document.getElementById('wfSection').value=wo.section_id||'';
  document.getElementById('wfDiff').value=wo.difficulty||'intermediate';
  document.getElementById('wfDur').value=wo.duration_minutes||'';
  document.getElementById('wfDesc').value=wo.description||'';
  document.getElementById('wfSort').value=wo.sort_order||0;
  document.getElementById('wfActive').checked=wo.is_active=='1'||wo.is_active===1;
}

// Aktivace správného tabu dle URL hash
(function(){
  const map={'#sections':'tabSections','#videos':'tabVideos','#exercises':'tabExercises','#workouts':'tabWorkouts'};
  const tab = map[location.hash];
  if (tab) {
    const el = document.querySelector('[href="#' + tab + '"]');
    if (el) new bootstrap.Tab(el).show();
  }
})();
</script>

<?php renderAdminFooter(); ?>
