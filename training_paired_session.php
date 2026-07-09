<?php
// training_paired_session.php – Aktivní párový trénink (split view)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId  = getCurrentCoachId();
$pairedId = intParam($_GET, 'id');
$pdo      = getDB();
$trainingVenues = getTrainingVenuesForCoach((int)$coachId);

// Ověření párového tréninku
$stmt = $pdo->prepare('SELECT id, started_at FROM paired_sessions WHERE id = ? AND coach_id = ?');
$stmt->execute([$pairedId, $coachId]);
$paired = $stmt->fetch();

if (!$paired) {
    flash('danger', 'Párový trénink nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

// Načtení všech session v tomto párovém tréninku
$stmt = $pdo->prepare(
    'SELECT ts.*, a.first_name, a.last_name, a.id AS athlete_id,
            ws.name AS set_name,
            (SELECT aw.weight_kg
             FROM athlete_weight_logs aw
             WHERE aw.athlete_id = a.id
             ORDER BY aw.measured_at DESC, aw.id DESC
             LIMIT 1) AS latest_weight_kg,
            (SELECT aw.measured_at
             FROM athlete_weight_logs aw
             WHERE aw.athlete_id = a.id
             ORDER BY aw.measured_at DESC, aw.id DESC
             LIMIT 1) AS latest_weight_measured_at
     FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
     WHERE ts.paired_session_id = ? AND a.coach_id = ?
       AND ts.deleted_by_coach_at IS NULL
     ORDER BY ts.id ASC'
);
$stmt->execute([$pairedId, $coachId]);
$sessions = $stmt->fetchAll();

if (empty($sessions)) {
    flash('danger', 'Párový trénink nenalezen.');
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

// Pokud jsou všechny session dokončené, přesměruj na detail prvního
$allCompleted = true;
foreach ($sessions as $s) {
    if (!$s['completed_at']) { $allCompleted = false; break; }
}
if ($allCompleted) {
    redirect(BASE_URL . '/training_detail.php?id=' . $sessions[0]['id']);
}

// Načtení cviků + sérií + posledních tréninků pro každou session
$sessionData = [];
foreach ($sessions as $s) {
    $sid       = (int)$s['id'];
    $exercises = getSessionExercises($sid, (int)$s['workout_set_id']);
    $seriesByEx  = [];
    $lastCompByEx = [];
    foreach ($exercises as $ex) {
        $eid = (int)$ex['exercise_id'];
        $seriesByEx[$eid]   = getSeriesForExercise($sid, $eid);
        $lastCompByEx[$eid] = getLastCompletedSeriesForExercise((int)$s['athlete_id'], $eid, $sid);
    }
    $sessionData[] = [
        'session'    => $s,
        'exercises'  => $exercises,
        'series'     => $seriesByEx,
        'lastComp'   => $lastCompByEx,
    ];
}

renderHeader('Párový trénink', false, true);
?>

<!-- Hlavička -->
<div class="d-flex align-items-center mb-3 gap-3 page-header flex-wrap">
    <div>
        <h2 class="mb-0 fw-bold">
            <i class="fas fa-people-group me-2 text-warning"></i>Párový trénink
        </h2>
        <div class="text-muted small">Zahájeno: <?= formatDateTime($paired['started_at']) ?></div>
    </div>
    <div class="ms-auto">
        <button class="btn btn-success btn-lg fw-bold training-finish-btn"
                data-bs-toggle="modal" data-bs-target="#completeModal">
            <i class="fas fa-flag-checkered me-2"></i>Ukončit trénink
        </button>
    </div>
</div>

<!-- Mobilní záložky (skryté na md+) -->
<ul class="nav nav-tabs mb-3 d-md-none" id="pairedTabs" role="tablist">
    <?php foreach ($sessionData as $idx => $sd): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold <?= $idx === 0 ? 'active' : '' ?>"
                type="button"
                data-paired-tab="<?= $idx ?>">
            <?= h($sd['session']['first_name'] . ' ' . $sd['session']['last_name']) ?>
        </button>
    </li>
    <?php endforeach; ?>
</ul>

<!-- Split view -->
<div class="row g-3" id="pairedSplitView">
    <?php foreach ($sessionData as $idx => $sd):
        $sid = (int)$sd['session']['id'];
    ?>
    <div class="col-md-6 paired-athlete-col" data-col-idx="<?= $idx ?>">

        <!-- Záhlaví sportovce -->
        <div class="paired-athlete-header mb-3 d-flex align-items-center gap-2">
            <span class="badge bg-warning text-dark fs-5 paired-athlete-num"><?= $idx + 1 ?></span>
            <div>
                <div class="fw-bold fs-5"><?= h($sd['session']['first_name'] . ' ' . $sd['session']['last_name']) ?></div>
                <div class="small text-muted">
                    <i class="fas fa-layer-group me-1"></i><?= h($sd['session']['set_name']) ?>
                </div>
                <?php if ($sd['session']['latest_weight_kg'] !== null): ?>
                <div class="small text-muted">
                    <i class="fas fa-weight-scale me-1"></i>
                    Poslední váha: <?= number_format((float)$sd['session']['latest_weight_kg'], 1, ',', '') ?> kg
                    <?php if (!empty($sd['session']['latest_weight_measured_at'])): ?>
                        (<?= formatDate((string)$sd['session']['latest_weight_measured_at']) ?>)
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($availableExercises)): ?>
            <div class="ms-auto d-flex gap-2 align-items-center">
                <select id="add-exercise-select-<?= $sid ?>" class="form-select form-select-sm" style="min-width:220px">
                    <option value="">Přidat cvik...</option>
                    <?php foreach ($availableExercises as $availableExercise): ?>
                    <option value="<?= (int)$availableExercise['id'] ?>"><?= h($availableExercise['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button"
                        class="btn btn-outline-warning btn-sm fw-bold"
                        id="add-exercise-btn-<?= $sid ?>"
                        onclick="addExerciseToPairedSession(this, <?= $sid ?>)">
                    <i class="fas fa-plus me-1"></i>Přidat
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Cviky -->
        <?php if (empty($sd['exercises'])): ?>
        <div class="alert alert-warning py-2">Sada neobsahuje žádné cviky.</div>
        <?php else: ?>

        <div class="small text-muted mb-2">
            <i class="fas fa-grip-vertical me-1"></i>Pořadí cviků můžete změnit přetažením karty.
        </div>
        <div id="exercise-list-<?= $sid ?>" class="exercise-sort-list" data-session-id="<?= $sid ?>">
        <?php foreach ($sd['exercises'] as $ex):
            $eid      = (int)$ex['exercise_id'];
            $key      = $sid . '-' . $eid;
            $series   = $sd['series'][$eid]  ?? [];
            $lastComp = $sd['lastComp'][$eid] ?? null;
            $sportType = $ex['sport_type'] ?? 'standard';
            $typeLabels = [
            ];
        ?>
           <div class="card border-0 shadow-sm mb-3 exercise-sort-item"
               id="exercise-card-<?= $key ?>"
               data-exercise-id="<?= $eid ?>"
               draggable="true">
            <div class="card-header d-flex align-items-center bg-dark text-white py-2 gap-2">
                <span class="badge bg-warning text-dark"><?= $ex['exercise_order'] ?></span>
                <span class="fw-bold"><?= h($ex['exercise_name']) ?></span>
                <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
                    <?php if (!empty($availableExercises)): ?>
                    <select id="replace-exercise-select-<?= $key ?>" class="form-select form-select-sm" style="min-width:180px">
                        <option value="">Nahradit...</option>
                        <?php foreach ($availableExercises as $availableExercise): ?>
                        <?php if ((int)$availableExercise['id'] === $eid) { continue; } ?>
                        <option value="<?= (int)$availableExercise['id'] ?>"><?= h($availableExercise['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button"
                            class="btn btn-outline-light btn-sm"
                            onclick="replaceExerciseInPairedSession(this, <?= $sid ?>, <?= $eid ?>)">
                        <i class="fas fa-right-left me-1"></i>Nahradit
                    </button>
                    <?php endif; ?>
                    <button type="button"
                            class="btn btn-outline-danger btn-sm"
                            onclick="removeExerciseFromPairedSession(this, <?= $sid ?>, <?= $eid ?>, <?= htmlspecialchars(json_encode((string)$ex['exercise_name'], JSON_HEX_QUOT | JSON_HEX_APOS), ENT_QUOTES, 'UTF-8') ?>)">
                        <i class="fas fa-trash me-1"></i>Odebrat
                    </button>
                </div>
                <?php if ($sportType !== 'standard'): ?>
                <span class="badge bg-info text-dark ms-1 small"><?= h($typeLabels[$sportType] ?? 'Speciální sport') ?></span>
                <?php endif; ?>
                <span class="badge bg-secondary small" id="series-count-<?= $key ?>">
                    <?php if ($sportType === 'standard'): ?>
                    <?= count($series) ?> séri<?= count($series) === 1 ? 'e' : 'í' ?>
                    <?php else: ?>
                    speciální
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if ($sportType === 'standard'): ?>
                <!-- Tabulka sérií -->
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0 align-middle text-center"
                           id="series-table-<?= $key ?>">
                        <thead class="table-light">
                            <tr>
                                <th style="width:28px">#</th>
                                <th>Váha</th>
                                <th>Opak.</th>
                                <th>Dop.</th>
                                <th style="width:36px"></th>
                            </tr>
                        </thead>
                        <tbody id="series-body-<?= $key ?>">
                            <?php foreach ($series as $sr): ?>
                            <tr id="series-row-<?= $key ?>-<?= $sr['id'] ?>">
                                <td class="fw-bold text-muted"><?= $sr['series_order'] ?></td>
                                <td class="fw-bold"><?= $sr['weight'] > 0 ? number_format($sr['weight'], 1, ',', '') : '–' ?></td>
                                <td><?= $sr['reps'] ?: '–' ?></td>
                                <td>
                                    <?php if ($sr['assistance_reps'] > 0): ?>
                                    <span class="badge bg-warning text-dark"><?= $sr['assistance_reps'] ?></span>
                                    <?php else: ?>–<?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-outline-danger btn-sm px-1 py-0"
                                            onclick="deletePairedSeries(<?= $sr['id'] ?>, <?= $sid ?>, <?= $eid ?>)"
                                            title="Smazat">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Poslední trénink cviku -->
                <?php if ($lastComp): ?>
                <div class="px-2 pt-2">
                    <div class="previous-exercise-session">
                        <div class="previous-exercise-session__head">
                            <div class="previous-exercise-session__title small">
                                <i class="fas fa-history me-1"></i>Poslední trénink tohoto cviku
                            </div>
                            <div class="small text-muted">
                                <?= formatDate($lastComp['session']['completed_at']) ?>
                                | <?= h($lastComp['session']['set_name']) ?>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            <?php foreach ($lastComp['series'] as $prev): ?>
                            <span class="badge bg-light text-dark border">
                                <?= $prev['series_order'] ?>. <?= number_format((float)$prev['weight'], 1, ',', '') ?> kg
                                × <?= (int)$prev['reps'] ?>
                                <?php if ((int)$prev['assistance_reps'] > 0): ?>
                                <span class="text-warning">(+<?= (int)$prev['assistance_reps'] ?>)</span>
                                <?php endif; ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Formulář pro přidání série -->
                <div class="p-2 border-top">
                    <div class="d-flex gap-1 align-items-end flex-wrap">
                        <div>
                            <label class="form-label small mb-0">kg</label>
                            <input type="number" step="0.5" min="0" max="999"
                                   class="form-control form-control-sm"
                                   id="weight-<?= $key ?>"
                                   placeholder="80" style="width:68px">
                        </div>
                        <div>
                            <label class="form-label small mb-0">Opak.</label>
                            <input type="number" step="1" min="0" max="999"
                                   class="form-control form-control-sm"
                                   id="reps-<?= $key ?>"
                                   placeholder="10" style="width:62px">
                        </div>
                        <div>
                            <label class="form-label small mb-0">Dop.</label>
                            <input type="number" step="1" min="0" max="999"
                                   class="form-control form-control-sm"
                                   id="assist-<?= $key ?>"
                                   placeholder="0" style="width:56px">
                        </div>
                        <div>
                            <button type="button"
                                    class="btn btn-warning btn-sm fw-bold"
                                    onclick="addPairedSeries(<?= $sid ?>, <?= $eid ?>)">
                                <i class="fas fa-plus me-1"></i>Přidat
                            </button>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="p-3">
                    <div class="alert alert-info mb-2">
                        <i class="fas fa-circle-info me-1"></i>
                        Tento cvik má speciální typ, který není v této verzi podporován.
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endforeach; ?>
</div>

<!-- Tlačítko ukončit dole -->
<div class="text-center my-4">
    <button class="btn btn-success btn-lg fw-bold px-5"
            data-bs-toggle="modal" data-bs-target="#completeModal">
        <i class="fas fa-flag-checkered me-2"></i>Ukončit párový trénink
    </button>
</div>

<!-- Modal: Ukončit trénink -->
<div class="modal fade" id="completeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= BASE_URL ?>/training_paired_complete.php">
                <?= csrfField() ?>
                <input type="hidden" name="paired_session_id" value="<?= $pairedId ?>">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-flag-checkered me-2"></i>Ukončit párový trénink
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-map-marker-alt me-1"></i>Místo tréninku
                            <small class="text-muted">(volitelné, společné pro oba)</small>
                        </label>
                        <?php $knownVenueNames = array_map(static fn(array $venue): string => (string)$venue['name'], $trainingVenues); ?>
                        <select class="form-select mb-2" id="paired-location-select">
                            <option value="">- Bez místa -</option>
                            <?php foreach ($trainingVenues as $venue): ?>
                            <?php $venueName = (string)$venue['name']; ?>
                            <option value="<?= h($venueName) ?>">
                                <?= h($venueName) ?><?= !empty($venue['address']) ? ' - ' . h((string)$venue['address']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="__custom__">Jiné místo (zadat ručně)</option>
                        </select>
                        <input type="text" name="location" class="form-control"
                               id="paired-location-input"
                               placeholder="Napište nové místo..."
                               readonly>
                        <div class="form-text">Vyberte sportoviště ze seznamu, nebo zvolte „Jiné místo" a napište vlastní.</div>
                    </div>
                    <hr>
                    <?php foreach ($sessionData as $sd): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-user me-1 text-warning"></i>
                            Poznámka – <?= h($sd['session']['first_name'] . ' ' . $sd['session']['last_name']) ?>
                            <small class="text-muted">(volitelné)</small>
                        </label>
                        <textarea name="notes_<?= $sd['session']['id'] ?>"
                                  class="form-control" rows="2"
                                  placeholder="Hodnocení tréninku..."></textarea>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-arrow-left me-1"></i>Zpět k tréninku
                    </button>
                    <button type="submit" class="btn btn-success fw-bold">
                        <i class="fas fa-check me-1"></i>Uložit a ukončit oba
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';

async function saveExerciseOrder(sessionId, orderedExerciseIds) {
    try {
        const response = await fetch(BASE_URL + '/api/reorder_session_exercises.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
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

function initPairedExerciseSortLists() {
    document.querySelectorAll('.exercise-sort-list').forEach(list => {
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
    });
}

async function updatePairedSessionExercise(button, payload) {
    const originalHtml = button?.innerHTML || '';
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Ukládám...';
    }

    try {
        const response = await fetch(BASE_URL + '/api/update_session_exercise.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
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

async function addExerciseToPairedSession(button, sessionId) {
    const select = document.getElementById('add-exercise-select-' + sessionId);
    const exerciseId = parseInt(select?.value || '0', 10);
    if (!exerciseId) {
        alert('Vyberte cvik, který chcete přidat.');
        return;
    }

    const originalHtml = button?.innerHTML || '';
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Přidávám...';
    }

    try {
        const resp = await fetch(BASE_URL + '/api/add_session_exercise.php', {
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
        if (button) {
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }
}

async function removeExerciseFromPairedSession(button, sessionId, exerciseId, exerciseName) {
    if (!confirm('Odebrat cvik "' + (exerciseName || 'bez názvu') + '" z tohoto tréninku?\nSmažou se i jeho zadané série v tomto tréninku.')) {
        return;
    }

    const ok = await updatePairedSessionExercise(button, {
        action: 'remove',
        session_id: sessionId,
        exercise_id: exerciseId
    });
    if (ok) {
        window.location.reload();
    }
}

async function replaceExerciseInPairedSession(button, sessionId, exerciseId) {
    const key = sessionId + '-' + exerciseId;
    const select = document.getElementById('replace-exercise-select-' + key);
    const newExerciseId = parseInt(select?.value || '0', 10);
    if (!newExerciseId) {
        alert('Vyberte nejdřív cvik, kterým chcete nahradit aktuální cvik.');
        return;
    }

    if (!confirm('Nahradit tento cvik vybraným cvikem?\nPůvodní série tohoto cviku v aktuálním tréninku budou smazány.')) {
        return;
    }

    const ok = await updatePairedSessionExercise(button, {
        action: 'replace',
        session_id: sessionId,
        exercise_id: exerciseId,
        new_exercise_id: newExerciseId
    });
    if (ok) {
        window.location.reload();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initPairedExerciseSortLists();

    const locationSelect = document.getElementById('paired-location-select');
    const locationInput = document.getElementById('paired-location-input');
    if (!locationSelect || !locationInput) {
        return;
    }

    const syncLocationInput = function() {
        const value = locationSelect.value;
        if (value === '__custom__') {
            locationInput.readOnly = false;
            locationInput.focus();
            return;
        }

        locationInput.readOnly = true;
        locationInput.value = value;
    };

    locationSelect.addEventListener('change', syncLocationInput);
    syncLocationInput();
});

async function addPairedSeries(sessionId, exerciseId) {
    const key    = sessionId + '-' + exerciseId;
    const weight = parseFloat(document.getElementById('weight-' + key).value) || 0;
    const reps   = parseInt(document.getElementById('reps-' + key).value)     || 0;
    const assist = parseInt(document.getElementById('assist-' + key).value)   || 0;

    const tbody    = document.getElementById('series-body-' + key);
    const rowCount = tbody.querySelectorAll('tr').length;

    const btn = event.currentTarget;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const resp = await fetch(BASE_URL + '/api/save_series.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: '<?= csrfToken() ?>',
                session_id:      sessionId,
                exercise_id:     exerciseId,
                series_order:    rowCount + 1,
                weight:          weight,
                reps:            reps,
                assistance_reps: assist
            })
        });
        const data = await resp.json();

        if (data.success) {
            const tr = document.createElement('tr');
            tr.id = 'series-row-' + key + '-' + data.id;
            tr.innerHTML = `
                <td class="fw-bold text-muted">${rowCount + 1}</td>
                <td class="fw-bold">${weight > 0 ? weight.toFixed(1).replace('.', ',') : '–'}</td>
                <td>${reps || '–'}</td>
                <td>${assist > 0 ? '<span class="badge bg-warning text-dark">' + assist + '</span>' : '–'}</td>
                <td>
                    <button class="btn btn-outline-danger btn-sm px-1 py-0"
                            onclick="deletePairedSeries(${data.id}, ${sessionId}, ${exerciseId})"
                            title="Smazat">
                        <i class="fas fa-times"></i>
                    </button>
                </td>`;
            tbody.appendChild(tr);

            document.getElementById('weight-' + key).value  = '';
            document.getElementById('reps-' + key).value    = '';
            document.getElementById('assist-' + key).value  = '';
            document.getElementById('weight-' + key).focus();
            updatePairedCount(key);
        } else {
            alert('Chyba při ukládání: ' + (data.error || 'Neznámá chyba'));
        }
    } catch (e) {
        alert('Chyba připojení k serveru.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus me-1"></i>Přidat';
    }
}

async function deletePairedSeries(seriesId, sessionId, exerciseId) {
    if (!confirm('Smazat tuto sérii?')) return;
    const key = sessionId + '-' + exerciseId;
    try {
        const resp = await fetch(BASE_URL + '/api/delete_series.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: '<?= csrfToken() ?>',
                series_id: seriesId
            })
        });
        const data = await resp.json();
        if (data.success) {
            document.getElementById('series-row-' + key + '-' + seriesId)?.remove();
            // Přečísluj
            document.querySelectorAll('#series-body-' + key + ' tr').forEach((row, i) => {
                row.cells[0].textContent = i + 1;
            });
            updatePairedCount(key);
        } else {
            alert('Chyba při mazání: ' + (data.error || 'Neznámá chyba'));
        }
    } catch (e) {
        alert('Chyba připojení k serveru.');
    }
}

function updatePairedCount(key) {
    const count = document.querySelectorAll('#series-body-' + key + ' tr').length;
    const badge = document.getElementById('series-count-' + key);
    if (badge) badge.textContent = count + ' séri' + (count === 1 ? 'e' : 'í');
}

// Mobilní záložky
(function () {
    const tabs = document.querySelectorAll('[data-paired-tab]');
    const cols = document.querySelectorAll('.paired-athlete-col');

    function applyTabs() {
        if (window.innerWidth >= 768) {
            cols.forEach(c => { c.style.display = ''; });
            return;
        }
        const activeIdx = parseInt(
            document.querySelector('[data-paired-tab].active')?.dataset.pairedTab ?? '0'
        );
        cols.forEach((c, i) => { c.style.display = i === activeIdx ? '' : 'none'; });
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            applyTabs();
        });
    });

    applyTabs();
    window.addEventListener('resize', applyTabs);
})();
</script>

<?php renderFooter(); ?>
