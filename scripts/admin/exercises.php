<?php
// admin/exercises.php – správa globálních cviků
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

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
        if ($name === '') {
            flash('danger', 'Zadejte název cviku.');
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
        $pdo->prepare('INSERT INTO exercises (coach_id, name, photo, is_global) VALUES (NULL, ?, ?, 1)')
            ->execute([$name, $photo]);
        flash('success', 'Globální cvik byl přidán.');
        redirect(BASE_URL . '/admin/exercises.php');
    }

    // Přejmenovat cvik
    if ($action === 'rename') {
        $id   = intParam($_POST, 'exercise_id');
        $name = trim($_POST['new_name'] ?? '');
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
        $pdo->prepare('UPDATE exercises SET name = ?, photo = ? WHERE id = ? AND is_global = 1')
            ->execute([$name, $photo, $id]);
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

<!-- Formulář: přidat cvik -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold" style="background:#312e81;color:#fff">
        <i class="fas fa-plus me-1"></i>Přidat globální cvik
    </div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div class="col-md-6">
                <label class="form-label small mb-1">Název cviku</label>
                <input type="text" name="name" class="form-control" required placeholder="Název cviku...">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Fotografie (nepovinné)</label>
                <input type="file" name="photo" class="form-control" accept="image/*">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn w-100" style="background:#7c3aed;color:#fff">
                    <i class="fas fa-plus me-1"></i>Přidat
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Seznam globálních cviků -->
<div class="card border-0 shadow-sm">
    <div class="card-header d-flex align-items-center gap-2" style="background:#312e81;color:#fff">
        <span class="fw-semibold">Seznam globálních cviků</span>
        <span class="badge bg-light text-dark ms-1"><?= count($exercises) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($exercises)): ?>
        <div class="p-4 text-center text-muted">
            <i class="fas fa-dumbbell fa-2x mb-2 d-block opacity-25"></i>
            Zatím nejsou přidány žádné globální cviky.
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($exercises as $ex): ?>
            <div class="list-group-item" id="ex-row-<?= $ex['id'] ?>">
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
                        </span>
                        <!-- Formulář editace (skrytý) -->
                        <form class="exercise-edit d-none" method="post" enctype="multipart/form-data">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="exercise_id" value="<?= $ex['id'] ?>">
                            <div class="d-flex gap-2 flex-wrap align-items-center mt-1">
                                <input type="text" name="new_name" class="form-control form-control-sm"
                                       value="<?= h($ex['name']) ?>" style="min-width:200px">
                                <input type="file" name="photo" class="form-control form-control-sm"
                                       accept="image/*" style="max-width:200px"
                                       title="Změnit fotografii (nepovinné)">
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
</script>

<?php renderAdminFooter(); ?>
