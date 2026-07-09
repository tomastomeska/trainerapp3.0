<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$sessionId = intParam($_GET, 'id');
$pdo       = getDB();

// Načtení session + ověření, že patří trenérovi
$stmt = $pdo->prepare(
    'SELECT ts.*, a.first_name, a.last_name, a.id AS athlete_id,
            ws.name AS set_name
     FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
    WHERE ts.id = ? AND a.coach_id = ? AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Trénink nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$stmtAvailableExercises = $pdo->prepare(
    'SELECT id, name, sport_type
     FROM exercises
     WHERE coach_id = ? OR is_global = 1
     ORDER BY name ASC'
);
$stmtAvailableExercises->execute([$coachId]);
$availableExercises = $stmtAvailableExercises->fetchAll();

// Pokud je trénink dokončený, přesměruj na detail
if ($session['completed_at']) {
    redirect(BASE_URL . '/training_detail.php?id=' . $sessionId);
}

// Načtení cviků v session snapshotu (fallback pro starší data)
$exercises = getSessionExercises($sessionId, (int)$session['workout_set_id']);

// Načtení existujících sérií pro každý cvik
$seriesByExercise = [];
$lastCompletedByExercise = [];
foreach ($exercises as $ex) {
    $seriesByExercise[$ex['exercise_id']] = getSeriesForExercise($sessionId, $ex['exercise_id']);
    $lastCompletedByExercise[$ex['exercise_id']] = getLastCompletedSeriesForExercise(
        (int)$session['athlete_id'],
        (int)$ex['exercise_id'],
        $sessionId
    );
}

renderHeader('Aktivní trénink');
?>

<div class="d-flex align-items-center mb-3 gap-3 page-header">
    <div>
        <h2 class="mb-0 fw-bold">
            <i class="fas fa-stopwatch me-2 text-warning"></i>
            <?= h($session['first_name'] . ' ' . $session['last_name']) ?>
        </h2>
        <div class="text-muted">
            <span class="badge bg-warning text-dark me-2 fs-6"><?= h($session['set_name']) ?></span>
            Zahájeno: <?= formatDateTime($session['started_at']) ?>
        </div>
    </div>
    <div class="ms-auto d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <?php if (!empty($availableExercises)): ?>
        <div class="d-flex gap-2 align-items-center">
            <select id="add-exercise-select" class="form-select form-select-sm" style="min-width:260px">
                <option value="">Přidat cvik do tréninku...</option>
                <?php foreach ($availableExercises as $availableExercise): ?>
                <option value="<?= (int)$availableExercise['id'] ?>">
                    <?= h($availableExercise['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-warning btn-sm fw-bold" id="add-exercise-btn" onclick="addExerciseToSession(<?= $sessionId ?>)">
                <i class="fas fa-plus me-1"></i>Přidat cvik
            </button>
        </div>
        <?php endif; ?>
        <button class="btn btn-success btn-lg fw-bold training-finish-btn" data-bs-toggle="modal" data-bs-target="#completeModal">
            <i class="fas fa-flag-checkered me-2"></i>Ukončit trénink
        </button>
    </div>
</div>

<?php if (empty($exercises)): ?>
<div class="alert alert-warning">Sada neobsahuje žádné cviky.</div>
<?php else: ?>

<!-- Cviky -->
<div class="small text-muted mb-2">
    <i class="fas fa-grip-vertical me-1"></i>Pořadí cviků můžete změnit přetažením celé karty.
</div>
<div id="exercise-list" class="exercise-sort-list" data-session-id="<?= (int)$sessionId ?>">
<?php foreach ($exercises as $idx => $ex): ?>
<?php $series = $seriesByExercise[$ex['exercise_id']] ?? []; ?>
<div class="card border-0 shadow-sm mb-4 exercise-sort-item"
     id="exercise-card-<?= $ex['exercise_id'] ?>"
     data-exercise-id="<?= (int)$ex['exercise_id'] ?>"
     data-is-timed="<?= !empty($ex['is_timed']) ? '1' : '0' ?>"
     draggable="true">
    <div class="card-header d-flex align-items-center bg-dark text-white">
        <span class="badge bg-warning text-dark me-2 fs-5"><?= $ex['exercise_order'] ?></span>
        <span class="fw-bold fs-5"><?= h($ex['exercise_name']) ?></span>
        <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
            <?php if (!empty($availableExercises)): ?>
            <select id="replace-exercise-select-<?= (int)$ex['exercise_id'] ?>" class="form-select form-select-sm" style="min-width:220px">
                <option value="">Nahradit cvik...</option>
                <?php foreach ($availableExercises as $availableExercise): ?>
                <?php if ((int)$availableExercise['id'] === (int)$ex['exercise_id']) { continue; } ?>
                <option value="<?= (int)$availableExercise['id'] ?>"><?= h($availableExercise['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button"
                    class="btn btn-outline-light btn-sm"
                    onclick="replaceExerciseInSession(this, <?= (int)$sessionId ?>, <?= (int)$ex['exercise_id'] ?>)">
                <i class="fas fa-right-left me-1"></i>Nahradit
            </button>
            <?php endif; ?>
            <button type="button"
                    class="btn btn-outline-danger btn-sm"
                    onclick="removeExerciseFromSession(this, <?= (int)$sessionId ?>, <?= (int)$ex['exercise_id'] ?>, <?= htmlspecialchars(json_encode((string)$ex['exercise_name'], JSON_HEX_QUOT | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8') ?>)">
                <i class="fas fa-trash me-1"></i>Odebrat
            </button>
        </div>
        <?php $lastCompleted = $lastCompletedByExercise[$ex['exercise_id']] ?? null; ?>
        <span class="badge bg-secondary" id="series-count-<?= $ex['exercise_id'] ?>">
            <?= count($series) ?> séri<?= count($series) === 1 ? 'e' : 'í' ?>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($ex['is_timed'])): ?>
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle text-center" id="series-table-<?= $ex['exercise_id'] ?>">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px">#</th>
                        <th>Čas</th>
                        <th>Váha náčiní&nbsp;<small class="text-muted">(kg)</small></th>
                        <th style="width:90px"></th>
                    </tr>
                </thead>
                <tbody id="series-body-<?= $ex['exercise_id'] ?>">
                    <?php foreach ($series as $s): ?>
                    <tr id="series-row-<?= $s['id'] ?>">
                        <td class="fw-bold text-muted"><?= $s['series_order'] ?></td>
                        <td class="fw-bold"><?= !empty($s['duration_seconds']) ? formatSeriesDuration((int)$s['duration_seconds']) : '–' ?></td>
                        <td class="fw-bold"><?php $timedLoad = (float)$s['weight'] + (float)($s['equipment_weight'] ?? 0); ?><?= $timedLoad > 0 ? number_format($timedLoad, 1, ',', '') . ' kg' : '–' ?></td>
                        <td>
                            <button class="btn btn-outline-secondary btn-sm me-1"
                                    onclick="editSeriesPrompt(<?= (int)$s['id'] ?>, <?= (int)$ex['exercise_id'] ?>, 1, 0, <?= (float)($s['equipment_weight'] ?? 0) ?>, 0, 0, <?= (int)($s['duration_seconds'] ?? 0) ?>)"
                                    title="Upravit sérii">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="btn btn-outline-danger btn-sm"
                                    onclick="deleteSeries(<?= $s['id'] ?>, <?= $ex['exercise_id'] ?>)"
                                    title="Smazat sérii">
                                <i class="fas fa-times"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle text-center" id="series-table-<?= $ex['exercise_id'] ?>">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px">#</th>
                        <th>Váha&nbsp;<small class="text-muted">(kg)</small></th>
                        <th>Opakování</th>
                        <th>Dopomoc</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody id="series-body-<?= $ex['exercise_id'] ?>">
                    <?php foreach ($series as $s): ?>
                    <tr id="series-row-<?= $s['id'] ?>">
                        <td class="fw-bold text-muted"><?= $s['series_order'] ?></td>
                        <td class="fw-bold"><?= ((float)$s['weight'] + (float)($s['equipment_weight'] ?? 0)) > 0 ? number_format((float)$s['weight'] + (float)($s['equipment_weight'] ?? 0), 1, ',', '') : '–' ?></td>
                        <td><?= $s['reps'] ?: '–' ?></td>
                        <td>
                            <?php if ($s['assistance_reps'] > 0): ?>
                            <span class="badge bg-warning text-dark"><?= $s['assistance_reps'] ?></span>
                            <?php else: ?>
                            –
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-outline-secondary btn-sm me-1"
                                    onclick="editSeriesPrompt(<?= (int)$s['id'] ?>, <?= (int)$ex['exercise_id'] ?>, 0, <?= (float)$s['weight'] ?>, <?= (float)($s['equipment_weight'] ?? 0) ?>, <?= (int)$s['reps'] ?>, <?= (int)$s['assistance_reps'] ?>, 0)"
                                    title="Upravit sérii">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="btn btn-outline-danger btn-sm"
                                    onclick="deleteSeries(<?= $s['id'] ?>, <?= $ex['exercise_id'] ?>)"
                                    title="Smazat sérii">
                                <i class="fas fa-times"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="p-3 border-top bg-light">
            <?php if ($lastCompleted): ?>
            <div class="previous-exercise-session mb-3">
                <div class="previous-exercise-session__head">
                    <div class="previous-exercise-session__title">
                        <i class="fas fa-history me-2"></i>
                        Poslední dokončený trénink tohoto cviku
                    </div>
                    <div class="small text-muted">
                        <?= formatDateTime($lastCompleted['session']['completed_at']) ?>
                        <span class="mx-1">|</span>
                        <?= h($lastCompleted['session']['set_name']) ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 align-middle text-center previous-exercise-session__table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Váha (kg)</th>
                                <th>Opakování</th>
                                <th>Dopomoc</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lastCompleted['series'] as $prev): ?>
                            <tr>
                                <td class="fw-bold text-muted"><?= (int)$prev['series_order'] ?></td>
                                <td><?= number_format((float)$prev['weight'], 1, ',', '') ?></td>
                                <td><?= (int)$prev['reps'] ?></td>
                                <td><?= (int)$prev['assistance_reps'] > 0 ? (int)$prev['assistance_reps'] : '–' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <?php if (empty($ex['is_timed'])): ?>
            <!-- Formulář pro přidání série (inline) -->
            <div class="add-series-row" id="add-series-form-<?= $ex['exercise_id'] ?>">
                <div>
                    <label class="form-label small fw-semibold mb-1">Váha (kg)</label>
                    <input type="number" step="0.5" min="0" max="999"
                           class="form-control form-control-sm series-weight"
                           id="weight-<?= $ex['exercise_id'] ?>"
                           placeholder="80" style="width:90px">
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">Opakování</label>
                    <input type="number" step="1" min="0" max="999"
                           class="form-control form-control-sm series-reps"
                           id="reps-<?= $ex['exercise_id'] ?>"
                           placeholder="10" style="width:90px">
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">Dopomoc</label>
                    <input type="number" step="1" min="0" max="999"
                           class="form-control form-control-sm series-assist"
                           id="assist-<?= $ex['exercise_id'] ?>"
                           placeholder="0" style="width:80px">
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">Váha náčiní (kg)</label>
                    <input type="number" step="0.5" min="0" max="999"
                           class="form-control form-control-sm series-equipment-weight"
                           id="equipment-weight-<?= $ex['exercise_id'] ?>"
                           placeholder="10" style="width:120px">
                    <div class="form-text small">Bude přičteno k celkové váze.</div>
                </div>
                <div class="mb-0" style="padding-top:22px">
                    <button type="button"
                            class="btn btn-warning fw-bold"
                            onclick="addSeries(<?= $ex['exercise_id'] ?>, <?= $sessionId ?>)">
                        <i class="fas fa-plus me-1"></i>Přidat sérii
                    </button>
                </div>
            </div>
            <?php else: ?>
            <div class="add-series-row" id="add-series-form-<?= $ex['exercise_id'] ?>">
                <div>
                    <label class="form-label small fw-semibold mb-1">Čas</label>
                    <div class="d-flex align-items-center gap-1">
                        <select class="form-select form-select-sm" id="time-min-<?= $ex['exercise_id'] ?>" style="width:85px">
                            <?php for ($m = 0; $m <= 60; $m++): ?>
                            <option value="<?= $m ?>"><?= sprintf('%02d', $m) ?> min</option>
                            <?php endfor; ?>
                        </select>
                        <select class="form-select form-select-sm" id="time-sec-<?= $ex['exercise_id'] ?>" style="width:85px">
                            <?php for ($s = 0; $s <= 60; $s++): ?>
                            <option value="<?= $s ?>"><?= sprintf('%02d', $s) ?> s</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">Váha náčiní (kg)</label>
                    <input type="number" step="0.5" min="0" max="999"
                           class="form-control form-control-sm series-equipment-weight"
                           id="equipment-weight-<?= $ex['exercise_id'] ?>"
                           placeholder="10" style="width:120px">
                    <div class="form-text small">Volitelné, bude přičteno k celkové váze.</div>
                </div>
                <div class="mb-0" style="padding-top:22px">
                    <button type="button"
                            class="btn btn-warning fw-bold"
                            onclick="addSeries(<?= $ex['exercise_id'] ?>, <?= $sessionId ?>)">
                        <i class="fas fa-plus me-1"></i>Přidat sérii
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Tlačítko ukončit trénink dole -->
<div class="text-center my-4">
    <button class="btn btn-success btn-lg fw-bold px-5"
            data-bs-toggle="modal" data-bs-target="#completeModal">
        <i class="fas fa-flag-checkered me-2"></i>Ukončit trénink
    </button>
</div>

<?php endif; ?>

<!-- Modal: Ukončit trénink -->
<div class="modal fade" id="completeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= BASE_URL ?>/training_complete.php" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-flag-checkered me-2"></i>Ukončit trénink
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Chcete ukončit trénink?</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-map-marker-alt me-1"></i>Místo tréninku
                            <small class="text-muted">(volitelné)</small>
                        </label>
                        <input type="text" name="location" class="form-control"
                               placeholder="např. FitStudio Praha, Home gym...">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Poznámka <small class="text-muted">(volitelné)</small></label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Celkové hodnocení tréninku..."></textarea>
                    </div>
                    <div class="mb-2 mt-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-camera me-1"></i>Fotografie z tréninku
                            <small class="text-muted">(volitelné)</small>
                        </label>
                        <input type="file"
                               name="training_photo"
                               class="form-control"
                               accept="image/*"
                               capture="environment"
                               onchange="previewTrainingPhoto(this)">
                        <div class="form-text">
                            Mobil/tablet nabídne fotoaparát, na počítači výběr souboru. Podporováno JPG, PNG, GIF, WEBP (max 8 MB).
                        </div>
                        <img id="training-photo-preview" alt="Náhled fotky"
                             class="img-fluid rounded border mt-2 d-none"
                             style="max-height:220px; object-fit:cover;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-arrow-left me-1"></i>Zpět k tréninku
                    </button>
                    <button type="submit" class="btn btn-success fw-bold">
                        <i class="fas fa-check me-1"></i>Uložit a ukončit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
async function saveExerciseOrder(sessionId, orderedExerciseIds) {
    try {
        const response = await fetch('<?= BASE_URL ?>/api/reorder_session_exercises.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_id: sessionId,
                exercise_ids: orderedExerciseIds,
                csrf_token: '<?= csrfToken() ?>'
            })
        });
        const data = await response.json();
        if (!data.success) {
            alert('Chyba při ukládání pořadí cviků: ' + (data.error || 'Neznámá chyba'));
            return false;
        }
        return true;
    } catch (error) {
        alert('Chyba připojení k serveru.');
        return false;
    }
}

function initExerciseSort() {
    const list = document.getElementById('exercise-list');
    if (!list) return;

    const sessionId = parseInt(list.dataset.sessionId || '0', 10);
    if (!sessionId) return;

    let draggedCard = null;
    let isSaving = false;
    const initialOrder = Array.from(list.querySelectorAll('.exercise-sort-item'))
        .map(card => parseInt(card.dataset.exerciseId || '0', 10));

    const getDragAfterElement = (container, y) => {
        const draggableElements = [...container.querySelectorAll('.exercise-sort-item:not(.dragging)')];
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset, element: child };
            }
            return closest;
        }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
    };

    list.querySelectorAll('.exercise-sort-item').forEach(card => {
        card.addEventListener('dragstart', () => {
            if (isSaving) return;
            draggedCard = card;
            card.classList.add('dragging', 'opacity-75');
        });

        card.addEventListener('dragend', async () => {
            card.classList.remove('dragging', 'opacity-75');
            if (!draggedCard || isSaving) {
                draggedCard = null;
                return;
            }

            const newOrder = Array.from(list.querySelectorAll('.exercise-sort-item'))
                .map(el => parseInt(el.dataset.exerciseId || '0', 10))
                .filter(Boolean);
            draggedCard = null;

            const changed = newOrder.length === initialOrder.length && newOrder.some((id, idx) => id !== initialOrder[idx]);
            if (!changed) {
                return;
            }

            isSaving = true;
            const ok = await saveExerciseOrder(sessionId, newOrder);
            if (ok) {
                window.location.reload();
                return;
            }
            window.location.reload();
        });
    });

    list.addEventListener('dragover', event => {
        event.preventDefault();
        if (!draggedCard || isSaving) return;
        const afterElement = getDragAfterElement(list, event.clientY);
        if (!afterElement) {
            list.appendChild(draggedCard);
            return;
        }
        if (afterElement !== draggedCard) {
            list.insertBefore(draggedCard, afterElement);
        }
    });
}

initExerciseSort();

async function updateSessionExercise(button, payload) {
    const originalHtml = button?.innerHTML || '';
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Ukládám...';
    }

    try {
        const response = await fetch('<?= BASE_URL ?>/api/update_session_exercise.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ...payload,
                csrf_token: '<?= csrfToken() ?>'
            })
        });
        const raw = await response.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (_parseError) {
            throw new Error('Server vrátil neplatnou odpověď: ' + raw.slice(0, 180));
        }
        if (!data.success) {
            alert('Chyba při úpravě cviku: ' + (data.error || 'Neznámá chyba'));
            return false;
        }
        return true;
    } catch (error) {
        alert('Chyba při komunikaci se serverem: ' + (error?.message || 'Neznámá chyba'));
        return false;
    } finally {
        if (button) {
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }
}

async function removeExerciseFromSession(button, sessionId, exerciseId, exerciseName) {
    if (!confirm('Odebrat cvik "' + (exerciseName || 'bez názvu') + '" z tohoto tréninku?\nSmažou se i jeho zadané série v tomto tréninku.')) {
        return;
    }

    const ok = await updateSessionExercise(button, {
        action: 'remove',
        session_id: sessionId,
        exercise_id: exerciseId
    });
    if (ok) {
        window.location.reload();
    }
}

async function replaceExerciseInSession(button, sessionId, exerciseId) {
    const select = document.getElementById('replace-exercise-select-' + exerciseId);
    const newExerciseId = parseInt(select?.value || '0', 10);
    if (!newExerciseId) {
        alert('Vyberte nejdřív cvik, kterým chcete nahradit aktuální cvik.');
        return;
    }

    if (!confirm('Nahradit tento cvik vybraným cvikem?\nPůvodní série tohoto cviku v aktuálním tréninku budou smazány.')) {
        return;
    }

    const ok = await updateSessionExercise(button, {
        action: 'replace',
        session_id: sessionId,
        exercise_id: exerciseId,
        new_exercise_id: newExerciseId
    });
    if (ok) {
        window.location.reload();
    }
}

async function addExerciseToSession(sessionId) {
    const select = document.getElementById('add-exercise-select');
    const button = document.getElementById('add-exercise-btn');
    if (!select || !button) return;

    const exerciseId = parseInt(select.value || '0', 10);
    if (!exerciseId) {
        alert('Vyberte cvik, který chcete do tréninku přidat.');
        return;
    }

    button.disabled = true;
    const originalHtml = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Přidávám...';

    try {
        const resp = await fetch('<?= BASE_URL ?>/api/add_session_exercise.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                session_id: sessionId,
                exercise_id: exerciseId,
                csrf_token: '<?= csrfToken() ?>'
            })
        });
        const raw = await resp.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (_parseError) {
            throw new Error('Server vrátil neplatnou odpověď: ' + raw.slice(0, 180));
        }
        if (!data.success) {
            alert('Chyba při přidání cviku: ' + (data.error || 'Neznámá chyba'));
            return;
        }

        window.location.reload();
    } catch (error) {
        alert('Chyba při komunikaci se serverem: ' + (error?.message || 'Neznámá chyba'));
    } finally {
        button.disabled = false;
        button.innerHTML = originalHtml;
    }
}

function parseSeriesDuration(value) {
    const raw = String(value || '').trim();
    if (!raw) return 0;
    const parts = raw.split(':').map(part => parseInt(part, 10));
    if (parts.some(Number.isNaN)) return 0;
    if (parts.length === 3) return (parts[0] * 3600) + (parts[1] * 60) + parts[2];
    if (parts.length === 2) return (parts[0] * 60) + parts[1];
    return parts[0];
}

function formatSeriesDurationJs(seconds) {
    const total = Math.max(0, parseInt(seconds, 10) || 0);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const restSeconds = total % 60;
    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(restSeconds).padStart(2, '0')}`;
    }
    return `${minutes}:${String(restSeconds).padStart(2, '0')}`;
}

function durationFromMinuteSecondInputs(minEl, secEl) {
    const minutes = parseInt(minEl?.value || '0', 10) || 0;
    const seconds = parseInt(secEl?.value || '0', 10) || 0;
    return Math.max(0, minutes * 60 + seconds);
}

function fillMinuteSecondInputs(totalSeconds, minEl, secEl) {
    const safe = Math.max(0, parseInt(totalSeconds, 10) || 0);
    const minutes = Math.min(60, Math.floor(safe / 60));
    const seconds = Math.min(60, safe % 60);
    if (minEl) minEl.value = String(minutes);
    if (secEl) secEl.value = String(seconds);
}

// Přidání série přes AJAX
async function addSeries(exerciseId, sessionId) {
    const isTimed = document.getElementById('exercise-card-' + exerciseId)?.dataset.isTimed === '1';
    const weightInput = document.getElementById('weight-' + exerciseId);
    const repsInput = document.getElementById('reps-' + exerciseId);
    const assistInput = document.getElementById('assist-' + exerciseId);
    const equipmentWeightInput = document.getElementById('equipment-weight-' + exerciseId);
    const timeMinInput = document.getElementById('time-min-' + exerciseId);
    const timeSecInput = document.getElementById('time-sec-' + exerciseId);

    const weight  = isTimed ? 0 : (parseFloat(weightInput?.value) || 0);
    const reps    = isTimed ? 0 : (parseInt(repsInput?.value) || 0);
    const assist  = isTimed ? 0 : (parseInt(assistInput?.value) || 0);
    const equipmentWeight = parseFloat(equipmentWeightInput?.value) || 0;
    const durationSeconds = isTimed ? durationFromMinuteSecondInputs(timeMinInput, timeSecInput) : 0;

    if (isTimed && durationSeconds <= 0) {
        alert('Zadejte čas série ve formátu mm:ss.');
        return;
    }

    const tbody   = document.getElementById('series-body-' + exerciseId);
    const rowCount = tbody.querySelectorAll('tr').length;

    const btn = event.currentTarget;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Ukládám...';

    try {
        const resp = await fetch('<?= BASE_URL ?>/api/save_series.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: '<?= csrfToken() ?>',
                session_id:      sessionId,
                exercise_id:     exerciseId,
                series_order:    rowCount + 1,
                weight:          weight,
                equipment_weight: equipmentWeight,
                reps:            reps,
                assistance_reps: assist,
                duration_seconds: durationSeconds
            })
        });
        const data = await resp.json();
        if (data.success) {
            // Přidej řádek do tabulky
            const tr = document.createElement('tr');
            tr.id = 'series-row-' + data.id;
            if (isTimed) {
                tr.innerHTML = `
                    <td class="fw-bold text-muted">${rowCount + 1}</td>
                    <td class="fw-bold">${durationSeconds > 0 ? formatSeriesDurationJs(durationSeconds) : '–'}</td>
                    <td class="fw-bold">${equipmentWeight > 0 ? equipmentWeight.toFixed(1).replace('.', ',') + ' kg' : '–'}</td>
                    <td>
                        <button class="btn btn-outline-secondary btn-sm me-1"
                                onclick="editSeriesPrompt(${data.id}, ${exerciseId}, 1, 0, ${equipmentWeight}, 0, 0, ${durationSeconds})"
                                title="Upravit sérii">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm"
                                onclick="deleteSeries(${data.id}, ${exerciseId})"
                                title="Smazat sérii">
                            <i class="fas fa-times"></i>
                        </button>
                    </td>`;
            } else {
                tr.innerHTML = `
                    <td class="fw-bold text-muted">${rowCount + 1}</td>
                    <td class="fw-bold">${(weight + equipmentWeight) > 0 ? (weight + equipmentWeight).toFixed(1).replace('.', ',') : '–'}</td>
                    <td>${reps || '–'}</td>
                    <td>${assist > 0 ? '<span class="badge bg-warning text-dark">' + assist + '</span>' : '–'}</td>
                    <td>
                        <button class="btn btn-outline-secondary btn-sm me-1"
                                onclick="editSeriesPrompt(${data.id}, ${exerciseId}, 0, ${weight}, ${equipmentWeight}, ${reps}, ${assist}, 0)"
                                title="Upravit sérii">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm"
                                onclick="deleteSeries(${data.id}, ${exerciseId})"
                                title="Smazat sérii">
                            <i class="fas fa-times"></i>
                        </button>
                    </td>`;
            }
            tbody.appendChild(tr);

            // Reset formuláře
            if (isTimed) {
                fillMinuteSecondInputs(0, timeMinInput, timeSecInput);
                if (equipmentWeightInput) equipmentWeightInput.value = '';
                timeMinInput?.focus();
            } else {
                if (weightInput) weightInput.value = '';
                if (repsInput) repsInput.value = '';
                if (assistInput) assistInput.value = '';
                if (equipmentWeightInput) equipmentWeightInput.value = '';
                weightInput?.focus();
            }

            // Aktualizuj počítadlo
            updateSeriesCount(exerciseId);
        } else {
            alert('Chyba při ukládání: ' + (data.error || 'Neznámá chyba'));
        }
    } catch (e) {
        alert('Chyba připojení k serveru.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus me-1"></i>Přidat sérii';
    }
}

async function editSeriesPrompt(seriesId, exerciseId, isTimed, currentWeight, currentEquipmentWeight, currentReps, currentAssist, currentDurationSeconds) {
    let weight = parseFloat(currentWeight) || 0;
    let equipmentWeight = parseFloat(currentEquipmentWeight) || 0;
    let reps = parseInt(currentReps, 10) || 0;
    let assist = parseInt(currentAssist, 10) || 0;
    let durationSeconds = parseInt(currentDurationSeconds, 10) || 0;

    if (isTimed) {
        const timeInput = prompt('Čas série (mm:ss)', durationSeconds > 0 ? formatSeriesDurationJs(durationSeconds) : '');
        if (timeInput === null) return;
        durationSeconds = parseSeriesDuration(timeInput);
        if (durationSeconds <= 0) {
            alert('Neplatný čas série.');
            return;
        }
        const equipmentInput = prompt('Váha náčiní (kg, volitelně)', equipmentWeight > 0 ? String(equipmentWeight).replace('.', ',') : '');
        if (equipmentInput === null) return;
        equipmentWeight = parseFloat(String(equipmentInput).replace(',', '.')) || 0;
        weight = 0;
        reps = 0;
        assist = 0;
    } else {
        const weightInput = prompt('Váha (kg)', String(weight).replace('.', ','));
        if (weightInput === null) return;
        weight = parseFloat(String(weightInput).replace(',', '.')) || 0;

        const repsInput = prompt('Opakování', String(reps));
        if (repsInput === null) return;
        reps = parseInt(repsInput, 10) || 0;

        const assistInput = prompt('Dopomoc', String(assist));
        if (assistInput === null) return;
        assist = parseInt(assistInput, 10) || 0;

        const equipmentInput = prompt('Váha náčiní (kg, volitelně)', equipmentWeight > 0 ? String(equipmentWeight).replace('.', ',') : '');
        if (equipmentInput === null) return;
        equipmentWeight = parseFloat(String(equipmentInput).replace(',', '.')) || 0;
        durationSeconds = 0;
    }

    try {
        const resp = await fetch('<?= BASE_URL ?>/api/update_series.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: '<?= csrfToken() ?>',
                series_id: seriesId,
                weight: weight,
                equipment_weight: equipmentWeight,
                reps: reps,
                assistance_reps: assist,
                duration_seconds: durationSeconds
            })
        });
        const data = await resp.json();
        if (!data.success) {
            alert('Chyba při úpravě: ' + (data.error || 'Neznámá chyba'));
            return;
        }
        window.location.reload();
    } catch (e) {
        alert('Chyba připojení k serveru.');
    }
}

// Smazání série přes AJAX
async function deleteSeries(seriesId, exerciseId) {
    if (!confirm('Smazat tuto sérii?')) return;

    try {
        const resp = await fetch('<?= BASE_URL ?>/api/delete_series.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: '<?= csrfToken() ?>',
                series_id: seriesId
            })
        });
        const data = await resp.json();
        if (data.success) {
            document.getElementById('series-row-' + seriesId)?.remove();
            renumberSeries(exerciseId);
            updateSeriesCount(exerciseId);
        } else {
            alert('Chyba při mazání: ' + (data.error || 'Neznámá chyba'));
        }
    } catch (e) {
        alert('Chyba připojení k serveru.');
    }
}

// Přečíslování sérií po smazání
function renumberSeries(exerciseId) {
    const rows = document.querySelectorAll('#series-body-' + exerciseId + ' tr');
    rows.forEach((row, i) => {
        row.cells[0].textContent = i + 1;
    });
}

// Aktualizace počítadla sérií v hlavičce
function updateSeriesCount(exerciseId) {
    const count = document.querySelectorAll('#series-body-' + exerciseId + ' tr').length;
    const badge = document.getElementById('series-count-' + exerciseId);
    if (badge) {
        badge.textContent = count + ' séri' + (count === 1 ? 'e' : 'í');
    }
}

function previewTrainingPhoto(input) {
    const preview = document.getElementById('training-photo-preview');
    const file = input.files && input.files[0] ? input.files[0] : null;
    if (!preview || !file) {
        if (preview) {
            preview.classList.add('d-none');
            preview.removeAttribute('src');
        }
        return;
    }

    // HEIC a jiné formáty nepodporované prohlížečem nelze zobrazit jako náhled
    const previewable = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!previewable.includes(file.type.toLowerCase())) {
        preview.classList.add('d-none');
        preview.removeAttribute('src');
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        preview.src = e.target.result;
        preview.classList.remove('d-none');
    };
    reader.readAsDataURL(file);
}

// Klávesa Enter v poli dopomoci = přidá sérii
document.querySelectorAll('.series-assist').forEach(function(el) {
    el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const exerciseId = this.id.replace('assist-', '');
            addSeries(parseInt(exerciseId), <?= $sessionId ?>);
        }
    });
});
</script>

<?php renderFooter(); ?>
