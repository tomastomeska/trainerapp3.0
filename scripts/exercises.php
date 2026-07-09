<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo     = getDB();
$error   = null;

function normalizeExerciseSportTypeLegacy(string $selectedSportType): string {
    return 'standard';
}

function exerciseCategoryOptions(): array {
    return [
        'all' => 'Vše',
        'chest' => 'Hrudník (Chest)',
        'back' => 'Záda (Back)',
        'shoulders' => 'Ramena (Shoulders)',
        'biceps' => 'Biceps',
        'triceps' => 'Triceps',
        'forearms' => 'Předloktí',
        'quadriceps' => 'Quadricepsy',
        'hamstrings' => 'Hamstringy',
        'glutes' => 'Hýždě',
        'calves' => 'Lýtka',
        'core' => 'Core (Břicho + hluboký stabilizační systém)',
        'uncategorized' => 'Bez zařazení',
    ];
}

function sanitizeExerciseCategories(mixed $raw): array {
    $allowed = array_keys(exerciseCategoryOptions());
    $allowed = array_values(array_filter($allowed, static fn($key) => $key !== 'all'));
    if (!is_array($raw)) {
        return [];
    }

    $normalized = [];
    foreach ($raw as $item) {
        $item = trim((string)$item);
        if ($item !== '' && in_array($item, $allowed, true)) {
            $normalized[$item] = true;
        }
    }

    return array_keys($normalized);
}

function decodeExerciseCategories(?string $raw): array {
    if ($raw === null || trim($raw) === '') {
        return ['uncategorized'];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['uncategorized'];
    }

    $categories = sanitizeExerciseCategories($decoded);
    return empty($categories) ? ['uncategorized'] : $categories;
}

function buildExerciseCategoryCounts(array $items, array $categoryOptions): array {
    $counts = [];
    foreach ($categoryOptions as $categoryKey => $_label) {
        $counts[$categoryKey] = 0;
    }

    foreach ($items as $item) {
        $keys = isset($item['category_keys']) && is_array($item['category_keys'])
            ? array_values(array_unique($item['category_keys']))
            : ['uncategorized'];

        $counts['all']++;
        foreach ($keys as $key) {
            if ($key !== 'all' && array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }
    }

    return $counts;
}

$exerciseCategoryOptions = exerciseCategoryOptions();

// Přidání cviku – formulář odesílá multipart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $sportType = 'standard';
        $muscleCategories = sanitizeExerciseCategories($_POST['muscle_categories'] ?? []);
        if ($name === '') {
            $error = 'Zadejte název cviku.';
        } elseif (empty($muscleCategories)) {
            $error = 'Vyberte alespoň jednu svalovou kategorii.';
        } else {
            $photo = saveUploadedPhoto('photo', 'exercises');
            $pdo->prepare('INSERT INTO exercises (coach_id, name, photo, sport_type, muscle_categories) VALUES (?, ?, ?, ?, ?)')
                ->execute([$coachId, $name, $photo, $sportType, json_encode($muscleCategories, JSON_UNESCAPED_UNICODE)]);
            flash('success', "Cvik \"$name\" byl přidán.");
            redirect(BASE_URL . '/exercises.php');
        }
    }
}

// Smazání cviku
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $exId = intParam($_POST, 'exercise_id');
        // Ověř vlastnictví
        $stmt = $pdo->prepare('SELECT id FROM exercises WHERE id = ? AND coach_id = ?');
        $stmt->execute([$exId, $coachId]);
        if ($stmt->fetch()) {
            // Zkontroluj, zda cvik není použit v sadě nebo ve snapshotu tréninku
            $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM workout_set_exercises WHERE exercise_id = ?');
            $stmt2->execute([$exId]);
            $setUsage = (int)$stmt2->fetchColumn();

            $stmt3 = $pdo->prepare('SELECT COUNT(*) FROM training_session_exercises WHERE exercise_id = ?');
            $stmt3->execute([$exId]);
            $sessionUsage = (int)$stmt3->fetchColumn();

            if ($setUsage > 0 || $sessionUsage > 0) {
                $error = 'Tento cvik nelze smazat, protože je použit v sadě nebo v historii tréninků.';
            } else {
                $pdo->prepare('DELETE FROM exercises WHERE id = ? AND coach_id = ?')
                    ->execute([$exId, $coachId]);
                flash('success', 'Cvik byl smazán.');
                redirect(BASE_URL . '/exercises.php');
            }
        }
    }
}

// Přejmenování cviku
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rename') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $exId    = intParam($_POST, 'exercise_id');
        $newName = trim($_POST['new_name'] ?? '');
        $sportType = 'standard';
        $muscleCategories = sanitizeExerciseCategories($_POST['muscle_categories'] ?? []);
        if (empty($muscleCategories)) {
            $muscleCategories = ['uncategorized'];
        }
        if ($newName === '') {
            $error = 'Zadejte název cviku.';
        } else {
            $newPhoto = saveUploadedPhoto('photo', 'exercises');
            if ($newPhoto !== null) {
                // Smazat starou fotografii
                $stmtOld = $pdo->prepare('SELECT photo FROM exercises WHERE id = ? AND coach_id = ?');
                $stmtOld->execute([$exId, $coachId]);
                $oldRow = $stmtOld->fetch();
                if ($oldRow) deleteUploadedPhoto($oldRow['photo'], 'exercises');
                $pdo->prepare('UPDATE exercises SET name = ?, photo = ?, sport_type = ?, muscle_categories = ? WHERE id = ? AND coach_id = ?')
                    ->execute([$newName, $newPhoto, $sportType, json_encode($muscleCategories, JSON_UNESCAPED_UNICODE), $exId, $coachId]);
            } else {
                $pdo->prepare('UPDATE exercises SET name = ?, sport_type = ?, muscle_categories = ? WHERE id = ? AND coach_id = ?')
                    ->execute([$newName, $sportType, json_encode($muscleCategories, JSON_UNESCAPED_UNICODE), $exId, $coachId]);
            }
            flash('success', 'Cvik byl upraven.');
            redirect(BASE_URL . '/exercises.php');
        }
    }
}

// Načtení cviků – vlastní trenéra
$stmt = $pdo->prepare(
    'SELECT e.*,
            (SELECT COUNT(*) FROM workout_set_exercises wse WHERE wse.exercise_id = e.id) AS set_count,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.exercise_id = e.id) AS series_count
     FROM exercises e
     WHERE e.coach_id = ?
     ORDER BY e.name'
);
$stmt->execute([$coachId]);
$exercises = $stmt->fetchAll();
foreach ($exercises as &$exercise) {
    $exercise['category_keys'] = decodeExerciseCategories($exercise['muscle_categories'] ?? null);
}
unset($exercise);

// Globální cviky (spravuje superadmin)
$globalExercises = $pdo->query(
    'SELECT e.*,
            (SELECT COUNT(*) FROM workout_set_exercises wse WHERE wse.exercise_id = e.id) AS set_count,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.exercise_id = e.id) AS series_count
     FROM exercises e
     WHERE e.is_global = 1
     ORDER BY e.name'
)->fetchAll();
foreach ($globalExercises as &$exercise) {
    $exercise['category_keys'] = decodeExerciseCategories($exercise['muscle_categories'] ?? null);
}
unset($exercise);

$exerciseCategoryCounts = buildExerciseCategoryCounts($exercises, $exerciseCategoryOptions);
$globalExerciseCategoryCounts = buildExerciseCategoryCounts($globalExercises, $exerciseCategoryOptions);

renderHeader('Cviky');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fas fa-list me-2 text-warning"></i>Cviky</h2>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<style>
.exercise-category-tiles {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
}

.exercise-category-tile {
    border: 1px solid #d7dbe2;
    border-radius: 999px;
    background: #f8fafc;
    color: #1f2937;
    padding: .35rem .75rem;
    font-size: .82rem;
    font-weight: 600;
    line-height: 1.2;
}

.exercise-category-tile.active {
    background: #111827;
    border-color: #111827;
    color: #fff;
}

.exercise-category-tile-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.4rem;
    height: 1.4rem;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 700;
    padding: 0 .35rem;
    background: #5b6472;
    color: #fff;
}

.exercise-category-tile.active .exercise-category-tile-count {
    background: #1f2937;
}

.exercise-category-checkboxes {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: .45rem .85rem;
}
</style>

<div class="row g-4">
    <!-- Přidat cvik -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-warning text-dark fw-bold">
                <i class="fas fa-plus me-2"></i>Přidat cvik
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Název cviku</label>
                        <input type="text" name="name" class="form-control"
                               placeholder="např. Benchpress, Dřep, Mrtvý tah..."
                               required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Typ cviku</label>
                        <select name="sport_type" class="form-select">
                            <option value="standard" selected>Standardní cvik (váha, opakování)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Fotografie <span class="text-muted fw-normal">(nepovinné)</span></label>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Svalové kategorie <span class="text-danger">*</span></label>
                        <div class="exercise-category-checkboxes">
                            <?php foreach ($exerciseCategoryOptions as $categoryKey => $categoryLabel): ?>
                                <?php if ($categoryKey === 'all') continue; ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="muscle_categories[]" value="<?= h($categoryKey) ?>" id="add-cat-<?= h($categoryKey) ?>">
                                    <label class="form-check-label small" for="add-cat-<?= h($categoryKey) ?>"><?= h($categoryLabel) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Při vytváření cviku je výběr alespoň jedné kategorie povinný.</div>
                    </div>
                    <button type="submit" class="btn btn-warning fw-bold w-100">
                        <i class="fas fa-plus me-1"></i>Přidat
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Seznam cviků -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-list me-2"></i>Seznam cviků
                <span class="badge bg-secondary ms-2"><?= count($exercises) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="p-3 border-bottom bg-light-subtle">
                    <div class="exercise-category-tiles" data-filter-group="exercises-list">
                        <?php foreach ($exerciseCategoryOptions as $categoryKey => $categoryLabel): ?>
                        <button type="button"
                                class="exercise-category-tile js-exercise-category-filter"
                                data-target-list="exercises-list"
                                data-category="<?= h($categoryKey) ?>">
                            <?= h($categoryLabel) ?>
                            <span class="exercise-category-tile-count"><?= (int)($exerciseCategoryCounts[$categoryKey] ?? 0) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if (empty($exercises)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                    Zatím žádné cviky. Přidejte první cvik vlevo.
                </div>
                <?php else: ?>
                <div class="alert alert-info m-3" id="exercises-list-hint">Nejprve klikněte na kategorii nahoře, pak se vypíše seznam cviků.</div>
                <div class="list-group list-group-flush" id="exercises-list">
                    <?php foreach ($exercises as $ex): ?>
                    <div class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                         id="ex-row-<?= $ex['id'] ?>"
                         data-categories="<?= h(implode(',', $ex['category_keys'])) ?>">
                        <?php $exPhoto = photoUrl($ex['photo'] ?? null, 'exercises'); ?>
                        <div class="flex-shrink-0">
                            <?php if ($exPhoto): ?>
                            <img src="<?= h($exPhoto) ?>" alt="<?= h($ex['name']) ?>"
                                 class="rounded" style="width:48px;height:48px;object-fit:cover;">
                            <?php else: ?>
                            <div class="rounded bg-light d-flex align-items-center justify-content-center"
                                 style="width:48px;height:48px;">
                                <i class="fas fa-dumbbell text-muted"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <span class="exercise-name fw-semibold"><?= h($ex['name']) ?></span>
                            <?php
                                $typeLabels = [
                                    'standard' => ['label' => 'Cvik', 'color' => 'secondary'],
                                ];
                                $typeInfo = $typeLabels[$ex['sport_type']] ?? $typeLabels['standard'];
                            ?>
                            <span class="badge bg-<?= $typeInfo['color'] ?> ms-2 small"><?= $typeInfo['label'] ?></span>
                            <?php foreach ($ex['category_keys'] as $categoryKey): ?>
                                <?php $categoryLabel = $exerciseCategoryOptions[$categoryKey] ?? $exerciseCategoryOptions['uncategorized']; ?>
                                <span class="badge bg-info-subtle text-dark border ms-1 small"><?= h($categoryLabel) ?></span>
                            <?php endforeach; ?>
                            <span class="exercise-edit d-none">
                                <form method="post" enctype="multipart/form-data"
                                      class="d-flex flex-wrap gap-2 align-items-center mt-1">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="exercise_id" value="<?= $ex['id'] ?>">
                                    <input type="text" name="new_name" class="form-control form-control-sm"
                                           value="<?= h($ex['name']) ?>" style="min-width:180px">
                                    <select name="sport_type" class="form-select form-select-sm" style="max-width:200px">
                                        <option value="standard" <?= $ex['sport_type'] === 'standard' ? 'selected' : '' ?>>Standardní</option>
                                    </select>
                                    <input type="file" name="photo" class="form-control form-control-sm"
                                           accept="image/*" style="max-width:100%;flex:1;min-width:0"
                                           title="Změnit fotografii (nepovinné)">
                                    <div class="w-100 exercise-category-checkboxes mt-1">
                                        <?php foreach ($exerciseCategoryOptions as $categoryKey => $categoryLabel): ?>
                                            <?php if ($categoryKey === 'all') continue; ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="muscle_categories[]" value="<?= h($categoryKey) ?>" id="edit-cat-<?= $ex['id'] ?>-<?= h($categoryKey) ?>" <?= in_array($categoryKey, $ex['category_keys'], true) ? 'checked' : '' ?>>
                                                <label class="form-check-label small" for="edit-cat-<?= $ex['id'] ?>-<?= h($categoryKey) ?>"><?= h($categoryLabel) ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm"
                                            onclick="cancelEdit(<?= $ex['id'] ?>)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                            </span>
                        </div>
                        <div class="text-muted small me-2">
                            <?php if ($ex['set_count'] > 0): ?>
                            <span class="badge bg-light text-dark border">
                                <?= $ex['set_count'] ?> <?= $ex['set_count'] === 1 ? 'sada' : 'sad' ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($ex['series_count'] > 0): ?>
                            <span class="badge bg-light text-dark border ms-1">
                                <?= $ex['series_count'] ?> sérií
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1 flex-shrink-0">
                            <button class="btn btn-outline-secondary btn-sm"
                                    onclick="editExercise(<?= $ex['id'] ?>)"
                                    title="Přejmenovat">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($ex['set_count'] == 0): ?>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Smazat cvik \'<?= h(addslashes($ex['name'])) ?>\'?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="exercise_id" value="<?= $ex['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Smazat">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <button class="btn btn-outline-secondary btn-sm" disabled
                                    title="Nelze smazat – cvik je použit v sadě">
                                <i class="fas fa-lock"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function editExercise(id) {
    document.querySelector('#ex-row-' + id + ' .exercise-name').classList.add('d-none');
    document.querySelector('#ex-row-' + id + ' .exercise-edit').classList.remove('d-none');
}
function cancelEdit(id) {
    document.querySelector('#ex-row-' + id + ' .exercise-name').classList.remove('d-none');
    document.querySelector('#ex-row-' + id + ' .exercise-edit').classList.add('d-none');
}
</script>



<?php if (!empty($globalExercises)): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header d-flex align-items-center gap-2" style="background:#312e81;color:#fff">
        <i class="fas fa-globe me-1"></i>
        <span class="fw-bold">Globální cviky</span>
        <span class="badge bg-light text-dark ms-1"><?= count($globalExercises) ?></span>
        <span class="ms-auto small opacity-75">Spravuje superadministrátor &ndash; lze použít v sadách</span>
    </div>
    <div class="card-body p-0">
        <div class="p-3 border-bottom bg-light-subtle">
            <div class="exercise-category-tiles" data-filter-group="global-exercises-list">
                <?php foreach ($exerciseCategoryOptions as $categoryKey => $categoryLabel): ?>
                <button type="button"
                        class="exercise-category-tile js-exercise-category-filter"
                        data-target-list="global-exercises-list"
                        data-category="<?= h($categoryKey) ?>">
                    <?= h($categoryLabel) ?>
                    <span class="exercise-category-tile-count"><?= (int)($globalExerciseCategoryCounts[$categoryKey] ?? 0) ?></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="alert alert-info m-3" id="global-exercises-list-hint">Nejprve klikněte na kategorii nahoře, pak se vypíše seznam globálních cviků.</div>
        <div class="list-group list-group-flush" id="global-exercises-list">
            <?php foreach ($globalExercises as $ex): ?>
            <div class="list-group-item d-flex align-items-center gap-3" data-categories="<?= h(implode(',', $ex['category_keys'])) ?>">
                <?php $exPhoto = photoUrl($ex['photo'] ?? null, 'exercises'); ?>
                <div class="flex-shrink-0">
                    <?php if ($exPhoto): ?>
                    <img src="<?= h($exPhoto) ?>" alt="<?= h($ex['name']) ?>"
                         class="rounded" style="width:40px;height:40px;object-fit:cover;">
                    <?php else: ?>
                    <div class="rounded d-flex align-items-center justify-content-center"
                         style="width:40px;height:40px;background:#e8e4ff">
                        <i class="fas fa-globe" style="color:#7c3aed"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="flex-grow-1 fw-semibold">
                    <?= h($ex['name']) ?>
                    <?php foreach ($ex['category_keys'] as $categoryKey): ?>
                        <?php $categoryLabel = $exerciseCategoryOptions[$categoryKey] ?? $exerciseCategoryOptions['uncategorized']; ?>
                        <span class="badge bg-info-subtle text-dark border ms-1 small"><?= h($categoryLabel) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="text-muted small d-flex gap-2">
                    <?php if ($ex['set_count'] > 0): ?>
                    <span class="badge bg-light text-dark border"><?= $ex['set_count'] ?> sad</span>
                    <?php endif; ?>
                    <?php if ($ex['series_count'] > 0): ?>
                    <span class="badge bg-light text-dark border"><?= $ex['series_count'] ?> sérií</span>
                    <?php endif; ?>
                    <span class="badge" style="background:#e8e4ff;color:#7c3aed">globální</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function applyExerciseCategoryFilter(listId, category) {
    const list = document.getElementById(listId);
    if (!list) return;

    list.querySelectorAll('.list-group-item').forEach(function(item) {
        const raw = item.getAttribute('data-categories') || '';
        const categories = raw.split(',').map(function(value) { return value.trim(); }).filter(Boolean);
        const showItem = category === 'all' || categories.includes(category);
        item.hidden = !showItem;
        item.classList.toggle('d-none', !showItem);
    });
}

function updateExerciseCategoryHint(listId, category) {
    const hint = document.getElementById(listId + '-hint');
    if (!hint) return;
    hint.classList.toggle('d-none', category !== '');
}

function initExerciseCategoryFilters() {
    document.querySelectorAll('.js-exercise-category-filter').forEach(function(button) {
        button.addEventListener('click', function() {
            const targetList = button.getAttribute('data-target-list');
            const category = button.getAttribute('data-category') || 'all';

            document.querySelectorAll('.js-exercise-category-filter[data-target-list="' + targetList + '"]')
                .forEach(function(peer) {
                    peer.classList.toggle('active', peer === button);
                });

            applyExerciseCategoryFilter(targetList, category);
            updateExerciseCategoryHint(targetList, category);
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initExerciseCategoryFilters();
    applyExerciseCategoryFilter('exercises-list', '');
    applyExerciseCategoryFilter('global-exercises-list', '');
    updateExerciseCategoryHint('exercises-list', '');
    updateExerciseCategoryHint('global-exercises-list', '');
});
</script>

<?php renderFooter(); ?>
