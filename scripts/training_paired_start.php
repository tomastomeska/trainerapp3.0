<?php
// training_paired_start.php – Výběr sportovců a sad pro párový trénink
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo     = getDB();
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/training_paired_start.php');
    }

    $rawAthleteIds = array_map('intval', (array)($_POST['athlete_ids'] ?? []));
    $rawSetIds     = array_map('intval', (array)($_POST['set_ids']     ?? []));

    // Odfiltruj prázdné (neúplné) sloty
    $pairs = [];
    for ($i = 0; $i < count($rawAthleteIds); $i++) {
        $aId = $rawAthleteIds[$i] ?? 0;
        $sId = $rawSetIds[$i]     ?? 0;
        if ($aId > 0 && $sId > 0) {
            $pairs[] = ['athlete_id' => $aId, 'set_id' => $sId];
        }
    }

    if (count($pairs) < 2) {
        $errors[] = 'Vyberte alespoň dva sportovce a každému přiřaďte sadu.';
    }

    // Nesmí být duplicitní sportovec
    if (empty($errors)) {
        $uniqueAthletes = array_unique(array_column($pairs, 'athlete_id'));
        if (count($uniqueAthletes) < count($pairs)) {
            $errors[] = 'Každý sportovec může být v tréninku pouze jednou.';
        }
    }

    // Ověř vlastnictví každého sportovce + sady, zkontroluj nedokončené tréninky
    if (empty($errors)) {
        foreach ($pairs as $pair) {
            $stmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ?');
            $stmt->execute([$pair['athlete_id'], $coachId]);
            if (!$stmt->fetch()) {
                $errors[] = 'Sportovec nenalezen.';
                break;
            }

            $stmt = $pdo->prepare('SELECT id FROM workout_sets WHERE id = ? AND coach_id = ?');
            $stmt->execute([$pair['set_id'], $coachId]);
            if (!$stmt->fetch()) {
                $errors[] = 'Sada nenalezena.';
                break;
            }

            // Ověř, zda sportovec nemá jiný nedokončený trénink
            $stmt = $pdo->prepare(
                'SELECT id, paired_session_id
                 FROM training_sessions
                 WHERE athlete_id = ? AND completed_at IS NULL AND deleted_by_coach_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute([$pair['athlete_id']]);
            $existing = $stmt->fetch();
            if ($existing) {
                // Načti jméno sportovce pro srozumitelnou chybovou hlášku
                $stmtA = $pdo->prepare('SELECT first_name, last_name FROM athletes WHERE id = ?');
                $stmtA->execute([$pair['athlete_id']]);
                $a = $stmtA->fetch();
                $errors[] = h($a['first_name'] . ' ' . $a['last_name']) . ' má již rozdělaný trénink. Nejprve ho dokončete.';
                break;
            }
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            // Vytvoř skupinu párového tréninku
            $pdo->prepare('INSERT INTO paired_sessions (coach_id) VALUES (?)')->execute([$coachId]);
            $pairedId = (int)$pdo->lastInsertId();

            foreach ($pairs as $pair) {
                // Vytvoř session
                $pdo->prepare(
                    'INSERT INTO training_sessions (athlete_id, workout_set_id, paired_session_id)
                     VALUES (?, ?, ?)'
                )->execute([$pair['athlete_id'], $pair['set_id'], $pairedId]);
                $sessionId = (int)$pdo->lastInsertId();

                // Snapshot cviků
                $pdo->prepare(
                    'INSERT INTO training_session_exercises (session_id, exercise_id, exercise_order, exercise_name, sport_type)
                     SELECT ?, wse.exercise_id, wse.exercise_order, e.name, e.sport_type
                     FROM workout_set_exercises wse
                     JOIN exercises e ON e.id = wse.exercise_id
                     WHERE wse.workout_set_id = ?
                     ORDER BY wse.exercise_order ASC'
                )->execute([$sessionId, $pair['set_id']]);
            }

            $pdo->commit();
            redirect(BASE_URL . '/training_paired_session.php?id=' . $pairedId);

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Chyba při zahájení tréninku: ' . $e->getMessage();
        }
    }
}

// Načtení sportovců a sad pro formulář
$stmtA = $pdo->prepare(
    'SELECT id, first_name, last_name FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name'
);
$stmtA->execute([$coachId]);
$athletes = $stmtA->fetchAll();

$stmtS = $pdo->prepare(
    'SELECT ws.id, ws.name, COUNT(wse.id) AS exercise_count
     FROM workout_sets ws
     LEFT JOIN workout_set_exercises wse ON ws.id = wse.workout_set_id
     WHERE ws.coach_id = ?
     GROUP BY ws.id
     ORDER BY ws.name'
);
$stmtS->execute([$coachId]);
$sets = $stmtS->fetchAll();

renderHeader('Párový trénink');
?>

<div class="d-flex align-items-center mb-4 gap-3 page-header">
    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0 fw-bold">
        <i class="fas fa-people-group me-2 text-warning"></i>Párový trénink
    </h2>
</div>

<?php foreach ($errors as $err): ?>
<div class="alert alert-danger"><?= $err ?></div>
<?php endforeach; ?>

<?php if (count($athletes) < 2): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i>
    Párový trénink vyžaduje alespoň <strong>dva sportovce</strong>. Nejprve
    <a href="<?= BASE_URL ?>/athlete_add.php" class="alert-link">přidejte sportovce</a>.
</div>
<?php elseif (empty($sets)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-2"></i>
    Nejprve vytvořte alespoň jednu <a href="<?= BASE_URL ?>/sady.php" class="alert-link">sadu</a> s cviky.
</div>
<?php else: ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-users me-2 text-warning"></i>Výběr sportovců a sad
    </div>
    <div class="card-body">
        <p class="text-muted small mb-4">
            Každému sportovci přiřaďte sadu. Sady mohou být stejné nebo různé.
            Minimálně 2 sportovci, maximálně 4.
        </p>

        <form method="post" id="pairedForm">
            <?= csrfField() ?>

            <div id="athleteSlots">
                <!-- Slot 1 -->
                <div class="athlete-slot mb-3" data-slot="0">
                    <div class="card border-0 bg-light">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-warning text-dark fw-bold" style="min-width:28px">1</span>
                                <span class="fw-semibold text-dark">Sportovec</span>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold mb-1">Jméno</label>
                                    <select name="athlete_ids[]" class="form-select athlete-select" required>
                                        <option value="">— Vyberte sportovce —</option>
                                        <?php foreach ($athletes as $a): ?>
                                        <option value="<?= $a['id'] ?>">
                                            <?= h($a['last_name'] . ' ' . $a['first_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold mb-1">Sada</label>
                                    <select name="set_ids[]" class="form-select" required>
                                        <option value="">— Vyberte sadu —</option>
                                        <?php foreach ($sets as $s): ?>
                                        <option value="<?= $s['id'] ?>">
                                            <?= h($s['name']) ?>
                                            (<?= $s['exercise_count'] ?> <?= $s['exercise_count'] === 1 ? 'cvik' : ($s['exercise_count'] < 5 ? 'cviky' : 'cviků') ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Slot 2 -->
                <div class="athlete-slot mb-3" data-slot="1">
                    <div class="card border-0 bg-light">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-warning text-dark fw-bold" style="min-width:28px">2</span>
                                <span class="fw-semibold text-dark">Sportovec</span>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold mb-1">Jméno</label>
                                    <select name="athlete_ids[]" class="form-select athlete-select" required>
                                        <option value="">— Vyberte sportovce —</option>
                                        <?php foreach ($athletes as $a): ?>
                                        <option value="<?= $a['id'] ?>">
                                            <?= h($a['last_name'] . ' ' . $a['first_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold mb-1">Sada</label>
                                    <select name="set_ids[]" class="form-select" required>
                                        <option value="">— Vyberte sadu —</option>
                                        <?php foreach ($sets as $s): ?>
                                        <option value="<?= $s['id'] ?>">
                                            <?= h($s['name']) ?>
                                            (<?= $s['exercise_count'] ?> <?= $s['exercise_count'] === 1 ? 'cvik' : ($s['exercise_count'] < 5 ? 'cviky' : 'cviků') ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Přidat slot -->
            <div class="d-flex gap-2 mb-4">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="addSlotBtn">
                    <i class="fas fa-plus me-1"></i>Přidat dalšího sportovce
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm d-none" id="removeSlotBtn">
                    <i class="fas fa-minus me-1"></i>Odebrat posledního
                </button>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-warning fw-bold px-4">
                    <i class="fas fa-people-group me-2"></i>Zahájit párový trénink
                </button>
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary">Zrušit</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const slotsContainer = document.getElementById('athleteSlots');
    const addBtn         = document.getElementById('addSlotBtn');
    const removeBtn      = document.getElementById('removeSlotBtn');
    const MAX_SLOTS      = 4;

    const athleteOptions = `
        <option value="">— Vyberte sportovce —</option>
        <?php foreach ($athletes as $a): ?>
        <option value="<?= $a['id'] ?>"><?= h($a['last_name'] . ' ' . $a['first_name']) ?></option>
        <?php endforeach; ?>
    `;
    const setOptions = `
        <option value="">— Vyberte sadu —</option>
        <?php foreach ($sets as $s): ?>
        <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= $s['exercise_count'] ?> <?= $s['exercise_count'] === 1 ? 'cvik' : ($s['exercise_count'] < 5 ? 'cviky' : 'cviků') ?>)</option>
        <?php endforeach; ?>
    `;

    function getSlotCount() {
        return slotsContainer.querySelectorAll('.athlete-slot').length;
    }

    function updateButtons() {
        const count = getSlotCount();
        addBtn.classList.toggle('d-none', count >= MAX_SLOTS);
        removeBtn.classList.toggle('d-none', count <= 2);
    }

    addBtn.addEventListener('click', function () {
        const count = getSlotCount();
        if (count >= MAX_SLOTS) return;

        const slot = document.createElement('div');
        slot.className = 'athlete-slot mb-3';
        slot.dataset.slot = count;
        slot.innerHTML = `
            <div class="card border-0 bg-light">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-warning text-dark fw-bold" style="min-width:28px">${count + 1}</span>
                        <span class="fw-semibold text-dark">Sportovec</span>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold mb-1">Jméno</label>
                            <select name="athlete_ids[]" class="form-select" required>
                                ${athleteOptions}
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold mb-1">Sada</label>
                            <select name="set_ids[]" class="form-select" required>
                                ${setOptions}
                            </select>
                        </div>
                    </div>
                </div>
            </div>`;
        slotsContainer.appendChild(slot);
        updateButtons();
    });

    removeBtn.addEventListener('click', function () {
        const slots = slotsContainer.querySelectorAll('.athlete-slot');
        if (slots.length <= 2) return;
        slots[slots.length - 1].remove();
        updateButtons();
    });

    updateButtons();
})();
</script>

<?php endif; ?>

<?php renderFooter(); ?>
