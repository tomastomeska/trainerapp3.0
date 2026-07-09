<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo   = getDB();
$flash = null;
$trainingVenues = getTrainingVenues();

// ── Zpracování formuláře ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        die('Neplatný CSRF token.');
    }

    $athleteId    = (int)($_POST['athlete_id'] ?? 0);
    $workoutSetId = (int)($_POST['workout_set_id'] ?? 0);
    $trainedAt    = trim($_POST['trained_at'] ?? '');
    $location     = normalizeTrainingVenueName($_POST['location'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    $errors = [];
    if (!$athleteId)    $errors[] = 'Vyberte sportovce.';
    if (!$workoutSetId) $errors[] = 'Vyberte sadu.';
    if (!$trainedAt)    $errors[] = 'Zadejte datum tréninku.';

    // Ověříme, že sportovec patří trenérovi (konzistence dat)
    if (!$errors) {
        $chk = $pdo->prepare('SELECT id FROM athletes WHERE id = ?');
        $chk->execute([$athleteId]);
        if (!$chk->fetch()) $errors[] = 'Sportovec nenalezen.';
    }

    if (!$errors) {
        if ($location !== '') {
            rememberTrainingVenue($location, null);
        }

        // Parsujeme datum a čas
        $startedAt = date('Y-m-d H:i:s', strtotime($trainedAt . ' 10:00:00'));

        // Vložíme session
        $ins = $pdo->prepare(
            'INSERT INTO training_sessions (athlete_id, workout_set_id, location, notes, started_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $athleteId,
            $workoutSetId,
            $location ?: null,
            $notes    ?: null,
            $startedAt,
            $startedAt, // retrospektivní → ihned dokončen
        ]);
        $sessionId = (int)$pdo->lastInsertId();

        $snapshotStmt = $pdo->prepare(
            'INSERT INTO training_session_exercises (session_id, exercise_id, exercise_order, exercise_name)
             SELECT ?, wse.exercise_id, wse.exercise_order, e.name
             FROM workout_set_exercises wse
             JOIN exercises e ON e.id = wse.exercise_id
             WHERE wse.workout_set_id = ?
             ORDER BY wse.exercise_order ASC'
        );
        $snapshotStmt->execute([$sessionId, $workoutSetId]);

        // Vložíme série
        $exerciseIds = $_POST['exercise_id'] ?? [];
        $insSerie    = $pdo->prepare(
            'INSERT INTO session_series (session_id, exercise_id, series_order, weight, reps, assistance_reps)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($exerciseIds as $exId) {
            $exId       = (int)$exId;
            $weights    = $_POST['weight'][$exId]   ?? [];
            $repsArr    = $_POST['reps'][$exId]     ?? [];
            $assistArr  = $_POST['assist'][$exId]   ?? [];

            foreach ($weights as $i => $w) {
                $w    = (float)str_replace(',', '.', $w);
                $r    = (int)($repsArr[$i]   ?? 0);
                $a    = (int)($assistArr[$i] ?? 0);
                if ($w == 0 && $r == 0) continue; // přeskočíme prázdné řádky
                $insSerie->execute([$sessionId, $exId, $i + 1, $w, $r, $a]);
            }
        }

        flash('success', 'Trénink byl úspěšně uložen.');
        header('Location: ' . BASE_URL . '/admin/training_add.php');
        exit;
    }
}

// ── Načteme trenéry pro první select ─────────────────────────────────────────
$coaches = $pdo->query('SELECT id, name, username FROM coaches ORDER BY name')->fetchAll();

renderAdminHeader('Přidat retrospektivní trénink');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Zpět
    </a>
    <h2 class="mb-0 fw-bold">
        <i class="fas fa-calendar-plus me-2" style="color:#a78bfa"></i>Přidat retrospektivní trénink
    </h2>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
        <li><?= h($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" id="trainingForm">
    <?= csrfField() ?>

    <!-- ── Krok 1: Trenér → Sportovec ──────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white fw-semibold">
            <i class="fas fa-user-tie me-2" style="color:#a78bfa"></i>Trenér a sportovec
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Trenér</label>
                    <select class="form-select" id="coachSelect" required>
                        <option value="">— Vyberte trenéra —</option>
                        <?php foreach ($coaches as $c): ?>
                        <option value="<?= $c['id'] ?>">
                            <?= h($c['name'] ?: $c['username']) ?>
                            (<?= h($c['username']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Sportovec</label>
                    <select class="form-select" id="athleteSelect" name="athlete_id" required disabled>
                        <option value="">— Nejdříve vyberte trenéra —</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Krok 2: Sada ─────────────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4" id="setCard" style="display:none!important">
        <div class="card-header bg-dark text-white fw-semibold">
            <i class="fas fa-layer-group me-2" style="color:#a78bfa"></i>Sada a datum
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Sada</label>
                    <select class="form-select" id="setSelect" name="workout_set_id" required disabled>
                        <option value="">— Nejdříve vyberte sportovce —</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Datum tréninku</label>
                    <input type="date" class="form-control" name="trained_at" id="trainedAt"
                           max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Místo <span class="text-muted fw-normal">(nepovinné)</span></label>
                    <input type="text" class="form-control" name="location"
                           list="admin-training-venues"
                           placeholder="Vyberte existující místo nebo napište nové..." maxlength="300">
                    <datalist id="admin-training-venues">
                        <?php foreach ($trainingVenues as $venue): ?>
                        <option value="<?= h((string)$venue['name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="form-text">Když napíšete nové místo, uloží se i do katalogu sportovišť.</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Poznámka <span class="text-muted fw-normal">(nepovinné)</span></label>
                    <textarea class="form-control" name="notes" rows="2" maxlength="2000"
                              placeholder="Volitelná poznámka k tréninku…"></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Krok 3: Série ─────────────────────────────────────────────────────── -->
    <div id="seriesSection" style="display:none"></div>

    <!-- ── Uložit ────────────────────────────────────────────────────────────── -->
    <div id="submitRow" style="display:none" class="mb-4">
        <button type="submit" class="btn btn-warning fw-bold px-4">
            <i class="fas fa-save me-2"></i>Uložit trénink
        </button>
    </div>
</form>

<script>
const BASE_URL = '<?= BASE_URL ?>';

// ── Pomocné funkce ────────────────────────────────────────────────────────────
async function apiFetch(endpoint) {
    const r = await fetch(BASE_URL + '/admin/training_api.php?action=' + endpoint);
    if (!r.ok) throw new Error('Chyba sítě');
    return r.json();
}

function show(el)  { el.style.removeProperty('display'); }
function hide(el)  { el.style.display = 'none'; }

// ── Coach → Athletes ──────────────────────────────────────────────────────────
document.getElementById('coachSelect').addEventListener('change', async function () {
    const coachId   = this.value;
    const athSel    = document.getElementById('athleteSelect');
    const setCard   = document.getElementById('setCard');
    const seriesSec = document.getElementById('seriesSection');
    const submitRow = document.getElementById('submitRow');

    athSel.innerHTML = '<option value="">Načítám…</option>';
    athSel.disabled  = true;
    hide(setCard);
    hide(seriesSec);
    hide(submitRow);
    document.getElementById('setSelect').innerHTML = '<option value="">—</option>';
    document.getElementById('setSelect').disabled  = true;

    if (!coachId) {
        athSel.innerHTML = '<option value="">— Nejdříve vyberte trenéra —</option>';
        return;
    }

    const data = await apiFetch('athletes&coach_id=' + coachId);
    athSel.innerHTML = '<option value="">— Vyberte sportovce —</option>';
    data.forEach(a => {
        const opt = document.createElement('option');
        opt.value       = a.id;
        opt.textContent = a.first_name + ' ' + a.last_name;
        athSel.appendChild(opt);
    });
    athSel.disabled = false;
});

// ── Athlete → WorkoutSets ─────────────────────────────────────────────────────
document.getElementById('athleteSelect').addEventListener('change', async function () {
    const athleteId = this.value;
    const coachId   = document.getElementById('coachSelect').value;
    const setCard   = document.getElementById('setCard');
    const setSel    = document.getElementById('setSelect');
    const seriesSec = document.getElementById('seriesSection');
    const submitRow = document.getElementById('submitRow');

    hide(setCard);
    hide(seriesSec);
    hide(submitRow);
    setSel.innerHTML = '<option value="">—</option>';
    setSel.disabled  = true;

    if (!athleteId) return;

    const data = await apiFetch('sets&coach_id=' + coachId);
    setSel.innerHTML = '<option value="">— Vyberte sadu —</option>';
    data.forEach(s => {
        const opt = document.createElement('option');
        opt.value       = s.id;
        opt.textContent = s.name;
        setSel.appendChild(opt);
    });
    setSel.disabled = false;
    show(setCard);
});

// ── Set → Exercises (série tabulka) ───────────────────────────────────────────
document.getElementById('setSelect').addEventListener('change', async function () {
    const setId     = this.value;
    const seriesSec = document.getElementById('seriesSection');
    const submitRow = document.getElementById('submitRow');

    seriesSec.innerHTML = '';
    hide(seriesSec);
    hide(submitRow);
    if (!setId) return;

    const exercises = await apiFetch('exercises&set_id=' + setId);
    if (!exercises.length) {
        seriesSec.innerHTML = '<div class="alert alert-warning">Sada neobsahuje žádné cviky.</div>';
        show(seriesSec);
        return;
    }

    let html = '';
    exercises.forEach(ex => {
        html += `
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-dark text-white d-flex align-items-center">
                <span class="badge bg-warning text-dark me-2">${ex.exercise_order}</span>
                <span class="fw-bold">${escHtml(ex.exercise_name)}</span>
                <input type="hidden" name="exercise_id[]" value="${ex.exercise_id}">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered mb-0 align-middle text-center" id="tbl-${ex.exercise_id}">
                        <thead class="table-light">
                            <tr>
                                <th style="width:50px">#</th>
                                <th>Váha (kg)</th>
                                <th>Opakování</th>
                                <th>Dopomoc</th>
                                <th style="width:50px"></th>
                            </tr>
                        </thead>
                        <tbody id="tbody-${ex.exercise_id}">
                        </tbody>
                    </table>
                </div>
                <div class="p-2">
                    <button type="button" class="btn btn-sm btn-outline-warning"
                            onclick="addRow(${ex.exercise_id})">
                        <i class="fas fa-plus me-1"></i>Přidat sérii
                    </button>
                </div>
            </div>
        </div>`;
    });

    seriesSec.innerHTML = html;
    show(seriesSec);
    show(submitRow);

    // Přidáme 1 výchozí sérii ke každému cviku
    exercises.forEach(ex => addRow(ex.exercise_id));
});

// ── Přidání řádku série ───────────────────────────────────────────────────────
function addRow(exId) {
    const tbody = document.getElementById('tbody-' + exId);
    const rowNum = tbody.rows.length + 1;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="fw-bold text-muted">${rowNum}</td>
        <td><input type="number" class="form-control form-control-sm text-center"
                   name="weight[${exId}][]" step="0.5" min="0" placeholder="0" style="width:80px;margin:auto"></td>
        <td><input type="number" class="form-control form-control-sm text-center"
                   name="reps[${exId}][]" min="0" placeholder="0" style="width:70px;margin:auto"></td>
        <td><input type="number" class="form-control form-control-sm text-center"
                   name="assist[${exId}][]" min="0" placeholder="0" style="width:70px;margin:auto"
                   title="Počet opakování s dopomocí"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)">
                <i class="fas fa-times"></i>
            </button></td>`;
    tbody.appendChild(tr);
    renumberRows(exId);
}

function removeRow(btn) {
    const tbody = btn.closest('tbody');
    const exId  = tbody.id.replace('tbody-', '');
    btn.closest('tr').remove();
    renumberRows(exId);
}

function renumberRows(exId) {
    const rows = document.querySelectorAll('#tbody-' + exId + ' tr');
    rows.forEach((tr, i) => { tr.cells[0].textContent = i + 1; });
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php renderAdminFooter(); ?>
