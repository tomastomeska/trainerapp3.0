<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo = getDB();
$mealTypes = mealTypeOptions();
$selectedMealTypeFilter = trim((string)($_GET['meal_type'] ?? ''));

if ($selectedMealTypeFilter !== '' && $selectedMealTypeFilter !== '__none' && !isset($mealTypes[$selectedMealTypeFilter])) {
    $selectedMealTypeFilter = '';
}

$parseNutritionInput = static function (string $raw, string $label): ?float {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $normalized = str_replace(',', '.', $raw);
    if (!is_numeric($normalized)) {
        flash('danger', $label . ' musí být číslo v gramech.');
        redirect(BASE_URL . '/meals.php');
    }

    $value = (float)$normalized;
    if ($value < 0 || $value > 1000) {
        flash('danger', $label . ' musí být v rozsahu 0 až 1000 g.');
        redirect(BASE_URL . '/meals.php');
    }

    return round($value, 2);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/meals.php');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $mealTypeRaw = trim((string)($_POST['meal_type'] ?? ''));
        $mealType = $mealTypeRaw !== '' ? $mealTypeRaw : null;
        $gramsRaw = trim((string)($_POST['grams'] ?? ''));
        $grams = null;
        $fatPer100g = $parseNutritionInput((string)($_POST['fat_per_100g'] ?? ''), 'Tuky');
        $sugarsPer100g = $parseNutritionInput((string)($_POST['sugars_per_100g'] ?? ''), 'Cukry');
        $proteinPer100g = $parseNutritionInput((string)($_POST['protein_per_100g'] ?? ''), 'Bílkoviny');
        $fiberPer100g = $parseNutritionInput((string)($_POST['fiber_per_100g'] ?? ''), 'Vláknina');
        $saltPer100g = $parseNutritionInput((string)($_POST['salt_per_100g'] ?? ''), 'Sůl');

        if ($name === '') {
            flash('danger', 'Název jídla je povinný.');
            redirect(BASE_URL . '/meals.php');
        }

        if ($mealType !== null && !isset($mealTypes[$mealType])) {
            flash('danger', 'Vyberte platný typ jídla.');
            redirect(BASE_URL . '/meals.php');
        }

        if ($gramsRaw !== '') {
            if (!ctype_digit($gramsRaw)) {
                flash('danger', 'Gramáž musí být celé číslo v gramech.');
                redirect(BASE_URL . '/meals.php');
            }
            $grams = (int)$gramsRaw;
            if ($grams <= 0 || $grams > 5000) {
                flash('danger', 'Gramáž musí být v rozsahu 1 až 5000 g.');
                redirect(BASE_URL . '/meals.php');
            }
        }

        if ($action === 'create') {
            $photo = saveUploadedPhoto('photo', 'meals');

            $stmt = $pdo->prepare(
                'INSERT INTO coach_meals (
                    coach_id,
                    name,
                    description,
                    grams,
                    meal_type,
                    fat_per_100g,
                    sugars_per_100g,
                    protein_per_100g,
                    fiber_per_100g,
                    salt_per_100g,
                    photo
                 )
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $coachId,
                $name,
                $description !== '' ? $description : null,
                $grams,
                $mealType,
                $fatPer100g,
                $sugarsPer100g,
                $proteinPer100g,
                $fiberPer100g,
                $saltPer100g,
                $photo,
            ]);
            flash('success', 'Jídlo bylo přidáno do databáze.');
            redirect(BASE_URL . '/meals.php');
        }

        $mealId = intParam($_POST, 'meal_id');
        $currentMealStmt = $pdo->prepare('SELECT photo FROM coach_meals WHERE id = ? AND coach_id = ?');
        $currentMealStmt->execute([$mealId, $coachId]);
        $currentMeal = $currentMealStmt->fetch();

        if (!$currentMeal) {
            flash('danger', 'Jídlo nebylo nalezeno.');
            redirect(BASE_URL . '/meals.php');
        }

        $newPhoto = saveUploadedPhoto('photo', 'meals');
        $photo = $newPhoto ?: ($currentMeal['photo'] ?? null);
        $removePhoto = (int)($_POST['remove_photo'] ?? 0) === 1;

        if ($removePhoto) {
            $photo = null;
        }

        $stmt = $pdo->prepare(
            'UPDATE coach_meals
             SET name = ?,
                 description = ?,
                 grams = ?,
                 meal_type = ?,
                 fat_per_100g = ?,
                 sugars_per_100g = ?,
                 protein_per_100g = ?,
                 fiber_per_100g = ?,
                 salt_per_100g = ?,
                 photo = ?
             WHERE id = ? AND coach_id = ?'
        );
        $stmt->execute([
            $name,
            $description !== '' ? $description : null,
            $grams,
            $mealType,
            $fatPer100g,
            $sugarsPer100g,
            $proteinPer100g,
            $fiberPer100g,
            $saltPer100g,
            $photo,
            $mealId,
            $coachId,
        ]);

        if ($newPhoto && !empty($currentMeal['photo']) && $currentMeal['photo'] !== $newPhoto) {
            deleteUploadedPhoto((string)$currentMeal['photo'], 'meals');
        }
        if ($removePhoto && !empty($currentMeal['photo']) && !$newPhoto) {
            deleteUploadedPhoto((string)$currentMeal['photo'], 'meals');
        }

        flash('success', 'Jídlo bylo upraveno.');
        redirect(BASE_URL . '/meals.php');
    }

    if ($action === 'delete') {
        $mealId = intParam($_POST, 'meal_id');
        $photoStmt = $pdo->prepare('SELECT photo FROM coach_meals WHERE id = ? AND coach_id = ?');
        $photoStmt->execute([$mealId, $coachId]);
        $mealRow = $photoStmt->fetch();

        $delete = $pdo->prepare('DELETE FROM coach_meals WHERE id = ? AND coach_id = ?');
        $delete->execute([$mealId, $coachId]);

        if ($mealRow && !empty($mealRow['photo'])) {
            deleteUploadedPhoto((string)$mealRow['photo'], 'meals');
        }

        flash('success', 'Jídlo bylo odstraněno.');
        redirect(BASE_URL . '/meals.php');
    }
}

$mealTypeCounts = array_fill_keys(array_keys($mealTypes), 0);
$mealTypeCounts['__none'] = 0;

$countsStmt = $pdo->prepare(
    'SELECT meal_type, COUNT(*) AS cnt
     FROM coach_meals
     WHERE coach_id = ?
     GROUP BY meal_type'
);
$countsStmt->execute([$coachId]);

foreach ($countsStmt->fetchAll() as $countRow) {
    $typeKey = $countRow['meal_type'] !== null ? (string)$countRow['meal_type'] : '__none';
    if (array_key_exists($typeKey, $mealTypeCounts)) {
        $mealTypeCounts[$typeKey] = (int)$countRow['cnt'];
    }
}

$totalMeals = array_sum($mealTypeCounts);
$meals = [];

if ($selectedMealTypeFilter !== '') {
    $sql = 'SELECT id,
                   name,
                   description,
                   grams,
                   meal_type,
                   fat_per_100g,
                   sugars_per_100g,
                   protein_per_100g,
                   fiber_per_100g,
                   salt_per_100g,
                   photo,
                   created_at
            FROM coach_meals
            WHERE coach_id = ?';
    $params = [$coachId];

    if ($selectedMealTypeFilter === '__none') {
        $sql .= ' AND meal_type IS NULL';
    } else {
        $sql .= ' AND meal_type = ?';
        $params[] = $selectedMealTypeFilter;
    }

    $sql .= ' ORDER BY name ASC, id ASC';

    $mealsStmt = $pdo->prepare($sql);
    $mealsStmt->execute($params);
    $meals = $mealsStmt->fetchAll();
}

renderHeader('Databáze jídel');
?>

<style>
.meal-type-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.meal-type-filter-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border-radius: 999px;
    border: 1px solid #d7dde7;
    background: #f8fafc;
    color: #1f2937;
    font-weight: 600;
    font-size: 0.92rem;
    padding: 0.42rem 0.78rem;
    text-decoration: none;
    line-height: 1;
    transition: all 0.18s ease;
}

.meal-type-filter-btn:hover {
    background: #fff;
    border-color: #f4c84f;
    color: #111827;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(17, 24, 39, 0.08);
}

.meal-type-filter-btn.is-active {
    background: #f6b90a;
    border-color: #f6b90a;
    color: #111827;
    box-shadow: 0 6px 16px rgba(246, 185, 10, 0.28);
}

.meal-type-filter-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.4rem;
    height: 1.4rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 0 0.35rem;
    background: #5b6472;
    color: #fff;
}

.meal-type-filter-btn.is-active .meal-type-filter-count {
    background: #1f2937;
    color: #fff;
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-utensils me-2 text-warning"></i>Databáze jídel</h2>
    <a href="<?= BASE_URL ?>/meal_plans.php" class="btn btn-outline-primary btn-sm fw-semibold">
        <i class="fas fa-layer-group me-1"></i>Přejít na jídelníčky
    </a>
</div>

<div class="row g-4">
    <div class="col-xl-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white fw-semibold">Přidat nové jídlo</div>
            <div class="card-body">
                <form method="post" class="row g-3" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create">

                    <div class="col-12">
                        <label class="form-label fw-semibold">Název jídla <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" maxlength="200" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Typ jídla</label>
                        <select name="meal_type" class="form-select">
                            <option value="">Bez typu</option>
                            <?php foreach ($mealTypes as $typeKey => $typeLabel): ?>
                            <option value="<?= h($typeKey) ?>"><?= h($typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Nutriční informace na 100 g</label>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">Tuky (g)</label>
                                <input type="number" name="fat_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">Cukry (g)</label>
                                <input type="number" name="sugars_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">Bílkoviny (g)</label>
                                <input type="number" name="protein_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Vláknina (g)</label>
                                <input type="number" name="fiber_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Sůl (g)</label>
                                <input type="number" name="salt_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="0.00">
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Fotografie jídla</label>
                        <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Gramáž (g)</label>
                        <input type="number" name="grams" class="form-control" min="1" max="5000" step="1" placeholder="Např. 150">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Popis jídla</label>
                        <textarea name="description" class="form-control" rows="4" maxlength="4000" placeholder="Volitelný popis, suroviny nebo doporučení..."></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-warning fw-bold w-100">
                            <i class="fas fa-save me-1"></i>Uložit jídlo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Moje jídla</span>
                <span class="badge bg-warning text-dark"><?= $totalMeals ?></span>
            </div>
            <div class="card-body">
                <?php if ($totalMeals > 0): ?>
                <div class="meal-type-filters mb-3">
                    <?php foreach ($mealTypes as $typeKey => $typeLabel): ?>
                    <a href="<?= BASE_URL ?>/meals.php?meal_type=<?= h($typeKey) ?>" class="meal-type-filter-btn<?= $selectedMealTypeFilter === $typeKey ? ' is-active' : '' ?>">
                        <?= h($typeLabel) ?> <span class="meal-type-filter-count"><?= (int)$mealTypeCounts[$typeKey] ?></span>
                    </a>
                    <?php endforeach; ?>
                    <a href="<?= BASE_URL ?>/meals.php?meal_type=__none" class="meal-type-filter-btn<?= $selectedMealTypeFilter === '__none' ? ' is-active' : '' ?>">
                        Bez typu <span class="meal-type-filter-count"><?= (int)$mealTypeCounts['__none'] ?></span>
                    </a>
                </div>
                <?php endif; ?>

                <?php if ($totalMeals === 0): ?>
                <div class="text-muted text-center py-4">Zatím nemáte uložená žádná jídla.</div>
                <?php elseif ($selectedMealTypeFilter === ''): ?>
                <div class="alert alert-info mb-0">Nejprve klikněte na typ jídla nahoře, pak se vypíše seznam.</div>
                <?php elseif (empty($meals)): ?>
                <div class="text-muted text-center py-4">Pro vybraný typ zatím nemáte žádná jídla.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Foto</th>
                            <th>Název</th>
                            <th>Typ</th>
                            <th>Gramáž</th>
                            <th>Nutrice / 100 g</th>
                            <th>Popis</th>
                            <th style="width:150px"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($meals as $meal): ?>
                        <tr>
                            <td>
                                <?php if (!empty($meal['photo'])): ?>
                                <img src="<?= h(photoUrl((string)$meal['photo'], 'meals')) ?>" alt="<?= h((string)$meal['name']) ?>" style="width:52px;height:52px;object-fit:cover;border-radius:8px;">
                                <?php else: ?>
                                <span class="text-muted">–</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold"><?= h((string)$meal['name']) ?></td>
                            <td>
                                <?php if (!empty($meal['meal_type'])): ?>
                                <span class="badge bg-secondary"><?= h(mealTypeLabel((string)$meal['meal_type'])) ?></span>
                                <?php else: ?>
                                <span class="text-muted">Bez typu</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $meal['grams'] !== null ? (int)$meal['grams'] . ' g' : '–' ?></td>
                            <td class="small text-muted">
                                <div>Tuky: <?= $meal['fat_per_100g'] !== null ? number_format((float)$meal['fat_per_100g'], 2, ',', '') . ' g' : '–' ?></div>
                                <div>Cukry: <?= $meal['sugars_per_100g'] !== null ? number_format((float)$meal['sugars_per_100g'], 2, ',', '') . ' g' : '–' ?></div>
                                <div>Bílkoviny: <?= $meal['protein_per_100g'] !== null ? number_format((float)$meal['protein_per_100g'], 2, ',', '') . ' g' : '–' ?></div>
                                <div>Vláknina: <?= $meal['fiber_per_100g'] !== null ? number_format((float)$meal['fiber_per_100g'], 2, ',', '') . ' g' : '–' ?></div>
                                <div>Sůl: <?= $meal['salt_per_100g'] !== null ? number_format((float)$meal['salt_per_100g'], 2, ',', '') . ' g' : '–' ?></div>
                            </td>
                            <td class="small text-muted" style="white-space:pre-wrap"><?= !empty($meal['description']) ? h((string)$meal['description']) : '–' ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editMealModal<?= (int)$meal['id'] ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Opravdu chcete jídlo smazat?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="meal_id" value="<?= (int)$meal['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-trash"></i>
                                    </button>
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

<?php foreach ($meals as $meal): ?>
<div class="modal fade" id="editMealModal<?= (int)$meal['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="meal_id" value="<?= (int)$meal['id'] ?>">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-edit me-2 text-warning"></i>Upravit jídlo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Název jídla <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= h((string)$meal['name']) ?>" maxlength="200" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Typ jídla</label>
                        <select name="meal_type" class="form-select">
                            <option value="">Bez typu</option>
                            <?php foreach ($mealTypes as $typeKey => $typeLabel): ?>
                            <option value="<?= h($typeKey) ?>" <?= $meal['meal_type'] === $typeKey ? 'selected' : '' ?>><?= h($typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Gramáž (g)</label>
                        <input type="number" name="grams" class="form-control" min="1" max="5000" step="1" value="<?= $meal['grams'] !== null ? (int)$meal['grams'] : '' ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Nutriční informace na 100 g</label>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">Tuky (g)</label>
                                <input type="number" name="fat_per_100g" class="form-control" min="0" max="1000" step="0.01" value="<?= $meal['fat_per_100g'] !== null ? h((string)$meal['fat_per_100g']) : '' ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">Cukry (g)</label>
                                <input type="number" name="sugars_per_100g" class="form-control" min="0" max="1000" step="0.01" value="<?= $meal['sugars_per_100g'] !== null ? h((string)$meal['sugars_per_100g']) : '' ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted mb-1">Bílkoviny (g)</label>
                                <input type="number" name="protein_per_100g" class="form-control" min="0" max="1000" step="0.01" value="<?= $meal['protein_per_100g'] !== null ? h((string)$meal['protein_per_100g']) : '' ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Vláknina (g)</label>
                                <input type="number" name="fiber_per_100g" class="form-control" min="0" max="1000" step="0.01" value="<?= $meal['fiber_per_100g'] !== null ? h((string)$meal['fiber_per_100g']) : '' ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Sůl (g)</label>
                                <input type="number" name="salt_per_100g" class="form-control" min="0" max="1000" step="0.01" value="<?= $meal['salt_per_100g'] !== null ? h((string)$meal['salt_per_100g']) : '' ?>">
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Fotografie jídla</label>
                        <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <?php if (!empty($meal['photo'])): ?>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="removePhoto<?= (int)$meal['id'] ?>">
                            <label class="form-check-label" for="removePhoto<?= (int)$meal['id'] ?>">Smazat aktuální fotku</label>
                        </div>
                        <img src="<?= h(photoUrl((string)$meal['photo'], 'meals')) ?>" alt="<?= h((string)$meal['name']) ?>" class="mt-2" style="max-width:120px;border-radius:8px;">
                        <?php endif; ?>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Popis jídla</label>
                        <textarea name="description" class="form-control" rows="5" maxlength="4000"><?= h((string)($meal['description'] ?? '')) ?></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn btn-warning fw-bold">Uložit změny</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php renderFooter();
