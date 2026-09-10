<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_migration') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
    } else {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM workout_sets LIKE 'is_global'")->fetch()) {
                $pdo->exec('ALTER TABLE workout_sets ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER coach_id');
            }
            if (!$pdo->query("SHOW COLUMNS FROM workout_sets LIKE 'description'")->fetch()) {
                $pdo->exec('ALTER TABLE workout_sets ADD COLUMN description TEXT NULL AFTER name');
            }
            $pdo->exec('ALTER TABLE workout_sets MODIFY coach_id INT NULL');
            $pdo->exec('UPDATE workout_sets SET is_global = 0 WHERE is_global IS NULL');
            flash('success', 'Migrace globálních tréninkových sad proběhla v pořádku.');
        } catch (Throwable $e) {
            flash('danger', 'Migraci se nepodařilo spustit: ' . $e->getMessage());
        }
    }
    redirect(BASE_URL . '/admin/global_workout_sets.php');
}

if (!(workoutSetsHasColumn('is_global') && workoutSetsHasColumn('description'))) {
    renderAdminHeader('Globální sady');
    ?>
    <div class="alert alert-warning mt-4 mb-3">Pro správu globálních sad je potřeba doplnit databázové sloupce.</div>
    <form method="post" onsubmit="return confirm('Spustit migraci globálních tréninkových sad?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="run_migration">
        <button class="btn btn-warning fw-bold"><i class="fas fa-database me-1"></i>Spustit migraci nyní</button>
    </form>
    <?php
    renderAdminFooter();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
    } else {
        $action = (string)($_POST['action'] ?? '');
        $setId = intParam($_POST, 'set_id');
        if ($action === 'delete') {
            $usage = $pdo->prepare('SELECT COUNT(*) FROM training_sessions WHERE workout_set_id = ?');
            $usage->execute([$setId]);
            if ((int)$usage->fetchColumn() > 0) {
                flash('danger', 'Použitou globální sadu nelze smazat.');
            } else {
                $pdo->prepare('DELETE FROM workout_sets WHERE id = ? AND is_global = 1')->execute([$setId]);
                flash('success', 'Globální sada byla smazána.');
            }
        } else {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $exerciseIds = array_values(array_filter(array_map('intval', (array)($_POST['exercises'] ?? []))));
            if ($name === '' || $description === '' || empty($exerciseIds)) {
                flash('danger', 'Vyplňte název, vysvětlení a vyberte alespoň jeden cvik.');
            } else {
                $placeholders = implode(',', array_fill(0, count($exerciseIds), '?'));
                $check = $pdo->prepare("SELECT COUNT(*) FROM exercises WHERE id IN ($placeholders) AND is_global = 1");
                $check->execute($exerciseIds);
                if ((int)$check->fetchColumn() !== count($exerciseIds)) {
                    flash('danger', 'Globální sada může obsahovat pouze globální cviky.');
                } else {
                    $pdo->beginTransaction();
                    try {
                        if ($action === 'update') {
                            $exists = $pdo->prepare('SELECT id FROM workout_sets WHERE id = ? AND is_global = 1');
                            $exists->execute([$setId]);
                            if (!$exists->fetch()) throw new RuntimeException('Globální sada nebyla nalezena.');
                            $pdo->prepare('UPDATE workout_sets SET name = ?, description = ? WHERE id = ? AND is_global = 1')->execute([$name, $description, $setId]);
                            $pdo->prepare('DELETE FROM workout_set_exercises WHERE workout_set_id = ?')->execute([$setId]);
                        } else {
                            $pdo->prepare('INSERT INTO workout_sets (coach_id, name, description, is_global) VALUES (NULL, ?, ?, 1)')->execute([$name, $description]);
                            $setId = (int)$pdo->lastInsertId();
                        }
                        $insert = $pdo->prepare('INSERT INTO workout_set_exercises (workout_set_id, exercise_id, exercise_order) VALUES (?, ?, ?)');
                        foreach ($exerciseIds as $order => $exerciseId) $insert->execute([$setId, $exerciseId, $order + 1]);
                        $pdo->commit();
                        flash('success', $action === 'update' ? 'Globální sada byla upravena.' : 'Globální sada byla vytvořena.');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        flash('danger', $e->getMessage());
                    }
                }
            }
        }
    }
    redirect(BASE_URL . '/admin/global_workout_sets.php');
}

$exercises = $pdo->query('SELECT id, name FROM exercises WHERE is_global = 1 ORDER BY name')->fetchAll();
$sets = $pdo->query('SELECT ws.*, COUNT(wse.id) AS exercise_count, GROUP_CONCAT(e.name ORDER BY wse.exercise_order SEPARATOR ", ") AS exercise_names FROM workout_sets ws LEFT JOIN workout_set_exercises wse ON wse.workout_set_id = ws.id LEFT JOIN exercises e ON e.id = wse.exercise_id WHERE ws.is_global = 1 GROUP BY ws.id ORDER BY ws.name')->fetchAll();
$editId = intParam($_GET, 'edit');
$editSet = null;
$editExerciseIds = [];
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT * FROM workout_sets WHERE id = ? AND is_global = 1');
    $editStmt->execute([$editId]);
    $editSet = $editStmt->fetch();
    if ($editSet) {
        $items = $pdo->prepare('SELECT exercise_id FROM workout_set_exercises WHERE workout_set_id = ? ORDER BY exercise_order');
        $items->execute([$editId]);
        $editExerciseIds = array_map('intval', $items->fetchAll(PDO::FETCH_COLUMN));
    }
}
renderAdminHeader('Globální sady');
?>
<div class="d-flex justify-content-between align-items-center mb-4"><h2 class="mb-0"><i class="fas fa-layer-group me-2 text-warning"></i>Globální sady</h2></div>
<div class="alert alert-info">Globální sady mohou používat všichni trenéři. Popis se jim zobrazí při výběru sady pro online trénink.</div>
<div class="row g-4"><div class="col-lg-5"><div class="card shadow-sm"><div class="card-body"><h5><?= $editSet ? 'Upravit globální sadu' : 'Nová globální sada' ?></h5><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="<?= $editSet ? 'update' : 'create' ?>"><?php if ($editSet): ?><input type="hidden" name="set_id" value="<?= (int)$editSet['id'] ?>"><?php endif; ?><div class="mb-3"><label class="form-label">Název</label><input class="form-control" name="name" required value="<?= h($editSet['name'] ?? '') ?>"></div><div class="mb-3"><label class="form-label">K čemu je sada vhodná</label><textarea class="form-control" name="description" rows="4" required placeholder="Např. pro začátečníky, partie, cíl a doporučená frekvence."><?= h($editSet['description'] ?? '') ?></textarea></div><label class="form-label">Globální cviky</label><div class="border rounded p-2 mb-3" style="max-height:260px;overflow:auto"><?php foreach ($exercises as $exercise): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="exercises[]" value="<?= (int)$exercise['id'] ?>" id="exercise-<?= (int)$exercise['id'] ?>" <?= in_array((int)$exercise['id'], $editExerciseIds, true) ? 'checked' : '' ?>><label class="form-check-label" for="exercise-<?= (int)$exercise['id'] ?>"><?= h($exercise['name']) ?></label></div><?php endforeach; ?></div><button class="btn btn-warning fw-bold">Uložit sadu</button><?php if ($editSet): ?><a class="btn btn-secondary" href="<?= BASE_URL ?>/admin/global_workout_sets.php">Zrušit</a><?php endif; ?></form></div></div></div><div class="col-lg-7"><?php if (!$sets): ?><div class="alert alert-secondary">Zatím nejsou vytvořeny žádné globální sady.</div><?php endif; ?><?php foreach ($sets as $set): ?><div class="card shadow-sm mb-3"><div class="card-body"><div class="d-flex justify-content-between gap-2"><div><h5 class="mb-1"><?= h($set['name']) ?></h5><p class="mb-2 text-muted"><?= nl2br(h((string)$set['description'])) ?></p></div><span class="badge bg-primary align-self-start">Globální</span></div><div class="small mb-3"><strong><?= (int)$set['exercise_count'] ?> cviků:</strong> <?= h((string)$set['exercise_names']) ?></div><a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/admin/global_workout_sets.php?edit=<?= (int)$set['id'] ?>">Upravit</a><form class="d-inline" method="post" onsubmit="return confirm('Smazat globální sadu?')"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="set_id" value="<?= (int)$set['id'] ?>"><button class="btn btn-outline-danger btn-sm">Smazat</button></form></div></div><?php endforeach; ?></div></div>
<?php renderAdminFooter();