<?php
// admin/exercises.php – správa globálních cviků
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

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

$exerciseCategoryOptions = exerciseCategoryOptions();

// ------- POST akce -------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/exercises.php');
    }

    $action = $_POST['action'] ?? '';

    // Přidat nový globální cvik
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $isTimed = !empty($_POST['is_timed']) ? 1 : 0;
        $muscleCategories = sanitizeExerciseCategories($_POST['muscle_categories'] ?? []);
        if ($name === '') {
            flash('danger', 'Zadejte název cviku.');
            redirect(BASE_URL . '/admin/exercises.php');
        }
        if (empty($muscleCategories)) {
            flash('danger', 'Vyberte alespoň jednu svalovou kategorii.');
            redirect(BASE_URL . '/admin/exercises.php');
        }
        // Zkontroluj duplicitu
        $dup = $pdo->prepare('SELECT id FROM exercises WHERE name = ? AND is_global = 1');
        $dup->execute([$name]);
        if ($dup->fetch()) {
            flash('warning', 'Globální cvik s tímto názvem již existuje.');
            redirect(BASE_URL . '/admin/exercises.php');
        }
        $photo = null;
        if (!empty($_FILES['photo']['tmp_name'])) {
            $photo = saveUploadedPhoto('photo', 'exercises');
            if ($photo === null) {
                flash('danger', 'Nepodařilo se nahrát fotografii.');
                redirect(BASE_URL . '/admin/exercises.php');
            }
        }
        $pdo->prepare('INSERT INTO exercises (coach_id, name, photo, is_global, is_timed, muscle_categories) VALUES (NULL, ?, ?, 1, ?, ?)')
            ->execute([$name, $photo, $isTimed, json_encode($muscleCategories, JSON_UNESCAPED_UNICODE)]);
        flash('success', 'Globální cvik byl přidán.');
        redirect(BASE_URL . '/admin/exercises.php');
    }

    // Přejmenovat cvik
    if ($action === 'rename') {
        $id   = intParam($_POST, 'exercise_id');
        $name = trim($_POST['new_name'] ?? '');
        $isTimed = !empty($_POST['is_timed']) ? 1 : 0;
        $muscleCategories = sanitizeExerciseCategories($_POST['muscle_categories'] ?? []);
        if (empty($muscleCategories)) {
            $muscleCategories = ['uncategorized'];
        }
        if ($id <= 0 || $name === '') {
            flash('danger', 'Neplatná data.');
            redirect(BASE_URL . '/admin/exercises.php');
        }
        // Zkontroluj, že cvik je globální
        $chk = $pdo->prepare('SELECT id, photo FROM exercises WHERE id = ? AND is_global = 1');
        $chk->execute([$id]);
        $ex = $chk->fetch();
        if (!$ex) {
            flash('danger', 'Cvik nenalezen.');
            redirect(BASE_URL . '/admin/exercises.php');
        }
        $photo = $ex['photo'];
        if (!empty($_FILES['photo']['tmp_name'])) {
            $newPhoto = saveUploadedPhoto('photo', 'exercises');
            if ($newPhoto !== null) {
                if ($photo) deleteUploadedPhoto($photo, 'exercises');
                $photo = $newPhoto;
            }
        }
        $pdo->prepare('UPDATE exercises SET name = ?, photo = ?, is_timed = ?, muscle_categories = ? WHERE id = ? AND is_global = 1')
            ->execute([$name, $photo, $isTimed, json_encode($muscleCategories, JSON_UNESCAPED_UNICODE), $id]);
        flash('success', 'Cvik byl upraven.');
        redirect(BASE_URL . '/admin/exercises.php');
    }

    // Smazat cvik
    if ($action === 'delete') {
        $id = intParam($_POST, 'exercise_id');
        $chk = $pdo->prepare(
            'SELECT e.id, e.photo,
                    COUNT(wse.id) AS used
             FROM exercises e
             LEFT JOIN workout_set_exercises wse ON wse.exercise_id = e.id
             WHERE e.id = ? AND e.is_global = 1
             GROUP BY e.id'
        );
        $chk->execute([$id]);
        $ex = $chk->fetch();
        if (!$ex) {
            flash('danger', 'Cvik nenalezen.');
            redirect(BASE_URL . '/admin/exercises.php');
        }
        if ($ex['used'] > 0) {
            flash('danger', 'Cvik nelze smazat – je použit v sadách trenérů.');
            redirect(BASE_URL . '/admin/exercises.php');
        }
        if ($ex['photo']) deleteUploadedPhoto($ex['photo'], 'exercises');
        $pdo->prepare('DELETE FROM exercises WHERE id = ? AND is_global = 1')->execute([$id]);
        flash('success', 'Cvik byl smazán.');
        redirect(BASE_URL . '/admin/exercises.php');
    }
}

// ------- Načtení dat -------
$exercises = $pdo->query(
    'SELECT e.*,
            COUNT(DISTINCT wse.workout_set_id) AS set_count,
            COUNT(DISTINCT ss.id)              AS series_count
     FROM exercises e
     LEFT JOIN workout_set_exercises wse ON wse.exercise_id = e.id
     LEFT JOIN session_series ss         ON ss.exercise_id  = e.id
     WHERE e.is_global = 1
     GROUP BY e.id
     ORDER BY e.name'
)->fetchAll();
foreach ($exercises as &$exercise) {
    $exercise['category_keys'] = decodeExerciseCategories($exercise['muscle_categories'] ?? null);
}
unset($exercise);

renderAdminHeader('Globální cviky');
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="fas fa-globe me-2" style="color:#7c3aed"></i>Globální cviky</h4>
        <p class="text-muted small mb-0">Cviky viditelné všem trenérům pro použití v sadách</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/admin/exercise_export.php?format=csv" class="btn btn-outline-success btn-sm">
            <i class="fas fa-file-csv me-1"></i>Export CSV
        </a>
        <a href="<?= BASE_URL ?>/admin/exercise_export.php?format=sql" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-file-code me-1"></i>Export SQL
        </a>
        <a href="<?= BASE_URL ?>/admin/exercise_import.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-file-import me-1"></i>Import CSV
        </a>
    </div>
</div>

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

.exercise-category-checkboxes {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: .45rem .85rem;
}
</style>

<!-- Formulář: přidat cvik -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center" style="background:#312e81;color:#fff">
        <span><i class="fas fa-plus me-1"></i>Přidat globální cvik</span>
        <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#createGlobalExerciseForm" aria-expanded="false" aria-controls="createGlobalExerciseForm">
            <i class="fas fa-chevron-down me-1"></i>Rozkliknout formulář
        </button>
    </div>
    <div id="createGlobalExerciseForm" class="collapse">
    <div class="card-body border-top">
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div class="col-md-6">
                <label class="form-label small mb-1">Název cviku</label>
                <input type="text" name="name" class="form-control" required placeholder="Název cviku...">
            </div>
            <div class="col-md-2">
                <div class="form-check mt-4 pt-2">
                    <input class="form-check-input" type="checkbox" name="is_timed" value="1" id="global-exercise-timed">
                    <label class="form-check-label fw-semibold" for="global-exercise-timed">Časový</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Fotografie (nepovinné)</label>
                <input type="file" name="photo" class="form-control" accept="image/*">
            </div>
            <div class="col-12">
                <label class="form-label small mb-1 fw-semibold">Svalové kategorie <span class="text-danger">*</span></label>
                <div class="exercise-category-checkboxes">
                    <?php foreach ($exerciseCategoryOptions as $categoryKey => $categoryLabel): ?>
                        <?php if ($categoryKey === 'all') continue; ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="muscle_categories[]" value="<?= h($categoryKey) ?>" id="add-cat-<?= h($categoryKey) ?>">
                            <label class="form-check-label small" for="add-cat-<?= h($categoryKey) ?>"><?= h($categoryLabel) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-text">Při vytváření nového cviku je výběr alespoň jedné kategorie povinný.</div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn w-100" style="background:#7c3aed;color:#fff">
                    <i class="fas fa-plus me-1"></i>Přidat
                </button>
            </div>
        </form>
    </div>
    </div>
</div>

<!-- Seznam globálních cviků -->
<div class="card border-0 shadow-sm">
    <div class="card-header d-flex align-items-center gap-2" style="background:#312e81;color:#fff">
        <span class="fw-semibold">Seznam globálních cviků</span>
        <span class="badge bg-light text-dark ms-1"><?= count($exercises) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="p-3 border-bottom bg-light-subtle">
            <div class="exercise-category-tiles" data-filter-group="global-exercises-list">
                <?php foreach ($exerciseCategoryOptions as $categoryKey => $categoryLabel): ?>
                <button type="button"
                        class="exercise-category-tile js-exercise-category-filter <?= $categoryKey === 'all' ? 'active' : '' ?>"
                        data-target-list="global-exercises-list"
                        data-category="<?= h($categoryKey) ?>">
                    <?= h($categoryLabel) ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (empty($exercises)): ?>
        <div class="p-4 text-center text-muted">
            <i class="fas fa-dumbbell fa-2x mb-2 d-block opacity-25"></i>
            Zatím nejsou přidány žádné globální cviky.
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush" id="global-exercises-list">
            <?php foreach ($exercises as $ex): ?>
            <div class="list-group-item" id="ex-row-<?= $ex['id'] ?>" data-categories="<?= h(implode(',', $ex['category_keys'])) ?>">
                <div class="d-flex align-items-center gap-3">
                    <!-- Foto -->
                    <?php $photo = photoUrl($ex['photo'] ?? null, 'exercises'); ?>
                    <div class="flex-shrink-0">
                        <?php if ($photo): ?>
                        <img src="<?= h($photo) ?>" alt="<?= h($ex['name']) ?>"
                             class="rounded" style="width:48px;height:48px;object-fit:cover">
                        <?php else: ?>
                        <div class="rounded d-flex align-items-center justify-content-center"
                             style="width:48px;height:48px;background:#e8e4ff">
                            <i class="fas fa-dumbbell" style="color:#7c3aed"></i>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Název (zobrazení) -->
                    <div class="flex-grow-1">
                        <span class="exercise-name fw-semibold">
                            <?= h($ex['name']) ?>
                            <span class="badge ms-1" style="background:#e8e4ff;color:#7c3aed;font-size:.7em">globální</span>
                            <?php if (!empty($ex['is_timed'])): ?>
                            <span class="badge bg-warning text-dark ms-1" style="font-size:.7em">časový</span>
                            <?php endif; ?>
                            <?php foreach ($ex['category_keys'] as $categoryKey): ?>
                                <?php $categoryLabel = $exerciseCategoryOptions[$categoryKey] ?? $exerciseCategoryOptions['uncategorized']; ?>
                                <span class="badge bg-info-subtle text-dark border ms-1" style="font-size:.7em"><?= h($categoryLabel) ?></span>
                            <?php endforeach; ?>
                        </span>
                        <!-- Formulář editace (skrytý) -->
                        <form class="exercise-edit d-none" method="post" enctype="multipart/form-data">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="exercise_id" value="<?= $ex['id'] ?>">
                            <div class="d-flex gap-2 flex-wrap align-items-center mt-1">
                                <input type="text" name="new_name" class="form-control form-control-sm"
                                       value="<?= h($ex['name']) ?>" style="min-width:200px">
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input" type="checkbox" name="is_timed" value="1" id="global-edit-timed-<?= $ex['id'] ?>" <?= !empty($ex['is_timed']) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="global-edit-timed-<?= $ex['id'] ?>">Časový</label>
                                </div>
                                <input type="file" name="photo" class="form-control form-control-sm"
                                       accept="image/*" style="max-width:200px"
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
                            </div>
                        </form>
                    </div>

                    <!-- Statistiky -->
                    <div class="text-muted small d-flex gap-2 me-2">
                        <?php if ($ex['set_count'] > 0): ?>
                        <span class="badge bg-light text-dark border"><?= $ex['set_count'] ?> sad</span>
                        <?php endif; ?>
                        <?php if ($ex['series_count'] > 0): ?>
                        <span class="badge bg-light text-dark border"><?= $ex['series_count'] ?> sérií</span>
                        <?php endif; ?>
                    </div>

                    <!-- Akce -->
                    <div class="d-flex gap-1">
                        <button class="btn btn-outline-secondary btn-sm"
                                onclick="editExercise(<?= $ex['id'] ?>)"
                                title="Upravit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if ($ex['set_count'] == 0): ?>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Smazat cvik \'' + <?= json_encode($ex['name']) ?> + '\'?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="exercise_id" value="<?= $ex['id'] ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Smazat">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php else: ?>
                        <button class="btn btn-outline-secondary btn-sm" disabled
                                title="Nelze smazat – cvik je použit v sadách">
                            <i class="fas fa-lock"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
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

function applyExerciseCategoryFilter(listId, category) {
    const list = document.getElementById(listId);
    if (!list) return;

    list.querySelectorAll('.list-group-item').forEach(function(item) {
        const raw = item.getAttribute('data-categories') || '';
        const categories = raw.split(',').map(function(value) { return value.trim(); }).filter(Boolean);
        const showItem = category === 'all' || categories.includes(category);
        item.style.display = showItem ? '' : 'none';
    });
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
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initExerciseCategoryFilters();
    applyExerciseCategoryFilter('global-exercises-list', 'all');
});
</script>

<?php renderAdminFooter(); ?>
