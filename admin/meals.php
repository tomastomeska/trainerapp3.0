<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$mealTypes = mealTypeOptions();

$parseNutritionInput = static function (string $raw, string $label): ?float {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $normalized = str_replace(',', '.', $raw);
    if (!is_numeric($normalized)) {
        flash('danger', $label . ' musí být číslo v gramech.');
        redirect(BASE_URL . '/admin/meals.php');
    }

    $value = (float)$normalized;
    if ($value < 0 || $value > 1000) {
        flash('danger', $label . ' musí být v rozsahu 0 až 1000 g.');
        redirect(BASE_URL . '/admin/meals.php');
    }

    return round($value, 2);
};

$syncGlobalMealToCoaches = static function (PDO $pdo, int $globalMealId): void {
    $globalStmt = $pdo->prepare(
        'SELECT id,
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
         FROM global_meals
         WHERE id = ?
         LIMIT 1'
    );
    $globalStmt->execute([$globalMealId]);
    $globalMeal = $globalStmt->fetch();

    if (!$globalMeal) {
        return;
    }

    $insertMissingStmt = $pdo->prepare(
        'INSERT INTO coach_meals (
            coach_id,
            global_meal_id,
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
         SELECT c.id, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
         FROM coaches c
         LEFT JOIN coach_meals cm ON cm.coach_id = c.id AND cm.global_meal_id = ?
         WHERE cm.id IS NULL'
    );
    $insertMissingStmt->execute([
        $globalMealId,
        (string)$globalMeal['name'],
        $globalMeal['description'] !== null ? (string)$globalMeal['description'] : null,
        $globalMeal['grams'] !== null ? (int)$globalMeal['grams'] : null,
        $globalMeal['meal_type'] !== null ? (string)$globalMeal['meal_type'] : null,
        $globalMeal['fat_per_100g'] !== null ? (float)$globalMeal['fat_per_100g'] : null,
        $globalMeal['sugars_per_100g'] !== null ? (float)$globalMeal['sugars_per_100g'] : null,
        $globalMeal['protein_per_100g'] !== null ? (float)$globalMeal['protein_per_100g'] : null,
        $globalMeal['fiber_per_100g'] !== null ? (float)$globalMeal['fiber_per_100g'] : null,
        $globalMeal['salt_per_100g'] !== null ? (float)$globalMeal['salt_per_100g'] : null,
        $globalMeal['photo'] !== null ? (string)$globalMeal['photo'] : null,
        $globalMealId,
    ]);

    $updateStmt = $pdo->prepare(
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
         WHERE global_meal_id = ?'
    );
    $updateStmt->execute([
        (string)$globalMeal['name'],
        $globalMeal['description'] !== null ? (string)$globalMeal['description'] : null,
        $globalMeal['grams'] !== null ? (int)$globalMeal['grams'] : null,
        $globalMeal['meal_type'] !== null ? (string)$globalMeal['meal_type'] : null,
        $globalMeal['fat_per_100g'] !== null ? (float)$globalMeal['fat_per_100g'] : null,
        $globalMeal['sugars_per_100g'] !== null ? (float)$globalMeal['sugars_per_100g'] : null,
        $globalMeal['protein_per_100g'] !== null ? (float)$globalMeal['protein_per_100g'] : null,
        $globalMeal['fiber_per_100g'] !== null ? (float)$globalMeal['fiber_per_100g'] : null,
        $globalMeal['salt_per_100g'] !== null ? (float)$globalMeal['salt_per_100g'] : null,
        $globalMeal['photo'] !== null ? (string)$globalMeal['photo'] : null,
        $globalMealId,
    ]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/meals.php');
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
            redirect(BASE_URL . '/admin/meals.php');
        }

        if ($mealType !== null && !isset($mealTypes[$mealType])) {
            flash('danger', 'Vyberte platný typ jídla.');
            redirect(BASE_URL . '/admin/meals.php');
        }

        if ($gramsRaw !== '') {
            if (!ctype_digit($gramsRaw)) {
                flash('danger', 'Gramáž musí být celé číslo v gramech.');
                redirect(BASE_URL . '/admin/meals.php');
            }
            $grams = (int)$gramsRaw;
            if ($grams <= 0 || $grams > 5000) {
                flash('danger', 'Gramáž musí být v rozsahu 1 až 5000 g.');
                redirect(BASE_URL . '/admin/meals.php');
            }
        }

        if ($action === 'create') {
            $photo = saveUploadedPhoto('photo', 'meals');

            $insertStmt = $pdo->prepare(
                'INSERT INTO global_meals (
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
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertStmt->execute([
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

            $globalMealId = (int)$pdo->lastInsertId();
            $syncGlobalMealToCoaches($pdo, $globalMealId);

            flash('success', 'Globální jídlo bylo vytvořeno a propsáno trenérům.');
            redirect(BASE_URL . '/admin/meals.php');
        }

        $globalMealId = intParam($_POST, 'global_meal_id');
        $currentStmt = $pdo->prepare('SELECT photo FROM global_meals WHERE id = ?');
        $currentStmt->execute([$globalMealId]);
        $current = $currentStmt->fetch();

        if (!$current) {
            flash('danger', 'Globální jídlo nebylo nalezeno.');
            redirect(BASE_URL . '/admin/meals.php');
        }

        $newPhoto = saveUploadedPhoto('photo', 'meals');
        $photo = $newPhoto ?: ($current['photo'] ?? null);
        $removePhoto = (int)($_POST['remove_photo'] ?? 0) === 1;

        if ($removePhoto) {
            $photo = null;
        }

        $updateStmt = $pdo->prepare(
            'UPDATE global_meals
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
             WHERE id = ?'
        );
        $updateStmt->execute([
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
            $globalMealId,
        ]);

        if ($newPhoto && !empty($current['photo']) && $current['photo'] !== $newPhoto) {
            deleteUploadedPhoto((string)$current['photo'], 'meals');
        }
        if ($removePhoto && !empty($current['photo']) && !$newPhoto) {
            deleteUploadedPhoto((string)$current['photo'], 'meals');
        }

        $syncGlobalMealToCoaches($pdo, $globalMealId);
        flash('success', 'Globální jídlo bylo upraveno a změny se propsaly trenérům.');
        redirect(BASE_URL . '/admin/meals.php');
    }

    if ($action === 'delete') {
        $globalMealId = intParam($_POST, 'global_meal_id');

        $usageStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM coach_meal_plan_items i
             JOIN coach_meals m ON m.id = i.meal_id
             WHERE m.global_meal_id = ?'
        );
        $usageStmt->execute([$globalMealId]);
        $usageCount = (int)$usageStmt->fetchColumn();

        if ($usageCount > 0) {
            flash('danger', 'Globální jídlo nelze smazat, protože je použito v jídelníčcích trenérů.');
            redirect(BASE_URL . '/admin/meals.php');
        }

        $photoStmt = $pdo->prepare('SELECT photo FROM global_meals WHERE id = ?');
        $photoStmt->execute([$globalMealId]);
        $row = $photoStmt->fetch();

        $pdo->prepare('DELETE FROM coach_meals WHERE global_meal_id = ?')->execute([$globalMealId]);
        $pdo->prepare('DELETE FROM global_meals WHERE id = ?')->execute([$globalMealId]);

        if ($row && !empty($row['photo'])) {
            deleteUploadedPhoto((string)$row['photo'], 'meals');
        }

        flash('success', 'Globální jídlo bylo smazáno.');
        redirect(BASE_URL . '/admin/meals.php');
    }
}

$globalMealsStmt = $pdo->query(
    'SELECT g.id,
            g.name,
            g.description,
            g.grams,
            g.meal_type,
            g.fat_per_100g,
            g.sugars_per_100g,
            g.protein_per_100g,
            g.fiber_per_100g,
            g.salt_per_100g,
            g.photo,
            g.created_at,
            COUNT(DISTINCT cm.coach_id) AS coach_count,
            COUNT(DISTINCT i.id) AS usage_count
     FROM global_meals g
     LEFT JOIN coach_meals cm ON cm.global_meal_id = g.id
     LEFT JOIN coach_meal_plan_items i ON i.meal_id = cm.id
     GROUP BY g.id
     ORDER BY CASE WHEN g.meal_type IS NULL THEN 1 ELSE 0 END,
              FIELD(g.meal_type, "breakfast", "snack", "lunch", "dinner", "second_dinner", "post_workout", "cheat_day"),
              g.name ASC, g.id ASC'
);
$globalMeals = $globalMealsStmt->fetchAll();

renderAdminHeader('Globální jídla');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="fw-bold mb-0"><i class="fas fa-utensils me-2" style="color:#a78bfa"></i>Globální jídla</h4>
    <span class="badge" style="background:#ede9fe;color:#5b21b6"><?= count($globalMeals) ?> položek</span>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center" style="background:#312e81;color:#fff">
        <span><i class="fas fa-plus me-1"></i>Přidat globální jídlo (pro všechny trenéry)</span>
        <button class="btn btn-sm btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#createMealForm" aria-expanded="false" aria-controls="createMealForm">
            <i class="fas fa-chevron-down me-1"></i>Rozkliknout formulář
        </button>
    </div>
    <div id="createMealForm" class="collapse">
    <div class="card-body border-top">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">

            <div class="col-md-6">
                <label class="form-label fw-semibold">Název jídla <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" maxlength="200" required>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Typ jídla</label>
                <select name="meal_type" class="form-select">
                    <option value="">Bez typu</option>
                    <?php foreach ($mealTypes as $typeKey => $typeLabel): ?>
                    <option value="<?= h($typeKey) ?>"><?= h($typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Výchozí gramáž (g)</label>
                <input type="number" name="grams" class="form-control" min="1" max="5000" step="1">
            </div>

            <div class="col-md-12">
                <label class="form-label fw-semibold">Nutriční informace na 100 g</label>
                <div class="row g-2">
                    <div class="col-md-4">
                        <input type="number" name="fat_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="Tuky (g)">
                    </div>
                    <div class="col-md-4">
                        <input type="number" name="sugars_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="Cukry (g)">
                    </div>
                    <div class="col-md-4">
                        <input type="number" name="protein_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="Bílkoviny (g)">
                    </div>
                    <div class="col-md-6">
                        <input type="number" name="fiber_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="Vláknina (g)">
                    </div>
                    <div class="col-md-6">
                        <input type="number" name="salt_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="Sůl (g)">
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Fotografie</label>
                <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Popis</label>
                <textarea name="description" class="form-control" rows="2" maxlength="4000"></textarea>
            </div>

            <div class="col-12 d-flex justify-content-end">
                <button type="submit" class="btn fw-bold" style="background:#7c3aed;color:#fff;border:none">
                    <i class="fas fa-save me-1"></i>Uložit a propagovat
                </button>
            </div>
        </form>
    </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header fw-semibold" style="background:#312e81;color:#fff">Seznam globálních jídel</div>
    <div class="card-body p-0">
        <?php if (empty($globalMeals)): ?>
        <div class="p-4 text-center text-muted">Zatím nejsou vytvořena žádná globální jídla.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                <tr>
                    <th>Foto</th>
                    <th>Název</th>
                    <th>Typ</th>
                    <th>Trenéři</th>
                    <th>Použití v jídelníčku</th>
                    <th style="width:140px"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($globalMeals as $meal): ?>
                <tr>
                    <td>
                        <?php if (!empty($meal['photo'])): ?>
                        <img src="<?= h(photoUrl((string)$meal['photo'], 'meals')) ?>" alt="<?= h((string)$meal['name']) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:8px;">
                        <?php else: ?>
                        <span class="text-muted">–</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= h((string)$meal['name']) ?></div>
                        <div class="small text-muted"><?= $meal['grams'] !== null ? (int)$meal['grams'] . ' g' : 'bez výchozí gramáže' ?></div>
                    </td>
                    <td><?= !empty($meal['meal_type']) ? h(mealTypeLabel((string)$meal['meal_type'])) : 'Bez typu' ?></td>
                    <td><span class="badge bg-secondary"><?= (int)$meal['coach_count'] ?></span></td>
                    <td><span class="badge bg-info text-dark"><?= (int)$meal['usage_count'] ?></span></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editGlobalMealModal<?= (int)$meal['id'] ?>">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu chcete globální jídlo smazat?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="global_meal_id" value="<?= (int)$meal['id'] ?>">
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

<?php foreach ($globalMeals as $meal): ?>
<div class="modal fade" id="editGlobalMealModal<?= (int)$meal['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="global_meal_id" value="<?= (int)$meal['id'] ?>">

                <div class="modal-header" style="background:#1e1e2e;color:#fff">
                    <h5 class="modal-title"><i class="fas fa-edit me-2" style="color:#a78bfa"></i>Upravit globální jídlo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Název jídla</label>
                        <input type="text" name="name" class="form-control" maxlength="200" required value="<?= h((string)$meal['name']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Typ jídla</label>
                        <select name="meal_type" class="form-select">
                            <option value="">Bez typu</option>
                            <?php foreach ($mealTypes as $typeKey => $typeLabel): ?>
                            <option value="<?= h($typeKey) ?>" <?= $meal['meal_type'] === $typeKey ? 'selected' : '' ?>><?= h($typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Výchozí gramáž (g)</label>
                        <input type="number" name="grams" class="form-control" min="1" max="5000" step="1" value="<?= $meal['grams'] !== null ? (int)$meal['grams'] : '' ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Nutriční informace na 100 g</label>
                        <div class="row g-2">
                            <div class="col-md-4"><input type="number" name="fat_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="Tuky (g)" value="<?= $meal['fat_per_100g'] !== null ? h((string)$meal['fat_per_100g']) : '' ?>"></div>
                            <div class="col-md-4"><input type="number" name="sugars_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="Cukry (g)" value="<?= $meal['sugars_per_100g'] !== null ? h((string)$meal['sugars_per_100g']) : '' ?>"></div>
                            <div class="col-md-4"><input type="number" name="protein_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="Bílkoviny (g)" value="<?= $meal['protein_per_100g'] !== null ? h((string)$meal['protein_per_100g']) : '' ?>"></div>
                            <div class="col-md-6"><input type="number" name="fiber_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="Vláknina (g)" value="<?= $meal['fiber_per_100g'] !== null ? h((string)$meal['fiber_per_100g']) : '' ?>"></div>
                            <div class="col-md-6"><input type="number" name="salt_per_100g" class="form-control" min="0" max="1000" step="0.01" placeholder="Sůl (g)" value="<?= $meal['salt_per_100g'] !== null ? h((string)$meal['salt_per_100g']) : '' ?>"></div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Fotografie</label>
                        <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        <?php if (!empty($meal['photo'])): ?>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="removePhoto<?= (int)$meal['id'] ?>">
                            <label class="form-check-label" for="removePhoto<?= (int)$meal['id'] ?>">Smazat aktuální fotku</label>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Popis</label>
                        <textarea name="description" class="form-control" rows="4" maxlength="4000"><?= h((string)($meal['description'] ?? '')) ?></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn" style="background:#7c3aed;color:#fff;border:none">Uložit a propagovat</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php renderAdminFooter();
