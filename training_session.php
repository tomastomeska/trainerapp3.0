<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$sessionId = intParam($_GET, 'id');
$pdo       = getDB();
$trainingVenues = getTrainingVenuesForCoach((int)$coachId);

// Načtení session + ověření, že patří trenérovi
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

renderHeader('Aktivní trénink', false, true);
?>

<div class="d-flex align-items-center mb-3 gap-3 page-header">
    <div>
        <h2 class="mb-0 fw-bold">
            <i class="fas fa-stopwatch me-2 text-warning"></i>
            <?= h($session['first_name'] . ' ' . $session['last_name']) ?>
        </h2>
        <div class="text-muted">
            <span class="badge bg-warning text-dark me-2 fs-6"><?= h($session['set_name']) ?></span>
            <?php if ($session['latest_weight_kg'] !== null): ?>
            <span class="badge bg-info text-dark me-2 fs-6">
                Poslední váha: <?= number_format((float)$session['latest_weight_kg'], 1, ',', '') ?> kg
                <?php if (!empty($session['latest_weight_measured_at'])): ?>
                    (<?= formatDate((string)$session['latest_weight_measured_at']) ?>)
                <?php endif; ?>
            </span>
            <?php endif; ?>
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
                    <?php
                    $exerciseTypeLabels = [
                        'standard' => 'Cvik',
                    ];
                    $label = $exerciseTypeLabels[$availableExercise['sport_type'] ?? 'standard'] ?? 'Cvik';
                    ?>
                    (<?= h($label) ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-warning btn-sm fw-bold" id="add-exercise-btn" onclick="addExerciseToSession(<?= $sessionId ?>)">
                <i class="fas fa-plus me-1"></i>Přidat cvik
            </button>
        </div>
        <?php endif; ?>
        <?php if (!$session['completed_at']): ?>
        <button class="btn btn-success btn-lg fw-bold training-finish-btn" data-bs-toggle="modal" data-bs-target="#completeModal">
            <i class="fas fa-flag-checkered me-2"></i>Ukončit trénink
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($exercises)): ?>
<div class="alert alert-warning">Sada zatím neobsahuje žádné cviky. Přidejte první cvik pomocí pole nahoře.</div>
<?php else: ?>

<!-- Cviky -->
<div class="small text-muted mb-2">
    <i class="fas fa-grip-vertical me-1"></i>Pořadí cviků můžete změnit přetažením celé karty.
</div>
<div id="exercise-list" class="exercise-sort-list" data-session-id="<?= (int)$sessionId ?>">
<?php foreach ($exercises as $idx => $ex): ?>
<?php $series = $seriesByExercise[$ex['exercise_id']] ?? []; ?>
<?php $sportType = $ex['sport_type'] ?? 'standard'; ?>
<div class="card border-0 shadow-sm mb-4 exercise-sort-item"
     id="exercise-card-<?= $ex['exercise_id'] ?>"
     data-exercise-id="<?= (int)$ex['exercise_id'] ?>"
     data-is-timed="<?= !empty($ex['is_timed']) ? '1' : '0' ?>"
     draggable="true">
    <div class="card-header d-flex align-items-center bg-dark text-white">
        <span class="badge bg-warning text-dark me-2 fs-5"><?= $ex['exercise_order'] ?></span>
        <span class="fw-bold fs-5"><?= h($ex['exercise_name']) ?></span>
        <?php if ($sportType !== 'standard'): ?>
        <span class="badge bg-info ms-2">
            <?php
            $typeLabels = [
            ];
            echo $typeLabels[$sportType] ?? 'Speciální';
            ?>
        </span>
        <?php endif; ?>
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
        <!-- Časový formulář -->
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle text-center" id="series-table-<?= $ex['exercise_id'] ?>">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px">#</th>
                        <th>Čas</th>
                        <th>Váha náčiní&nbsp;<small class="text-muted">(kg)</small></th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody id="series-body-<?= $ex['exercise_id'] ?>">
                    <?php foreach ($series as $s): ?>
                    <tr id="series-row-<?= $s['id'] ?>"
                        data-exercise-id="<?= $ex['exercise_id'] ?>"
                        data-is-timed="1"
                        data-weight="<?= (float)$s['weight'] ?>"
                        data-equipment-weight="<?= (float)($s['equipment_weight'] ?? 0) ?>"
                        data-reps="0"
                        data-assist="0"
                        data-duration-seconds="<?= (int)($s['duration_seconds'] ?? 0) ?>">
                        <td class="fw-bold text-muted"><?= $s['series_order'] ?></td>
                        <td class="fw-bold"><?= !empty($s['duration_seconds']) ? formatSeriesDuration((int)$s['duration_seconds']) : '–' ?></td>
                        <td class="fw-bold">
                            <?php $timedLoad = (float)$s['weight'] + (float)($s['equipment_weight'] ?? 0); ?>
                            <?= $timedLoad > 0 ? number_format($timedLoad, 1, ',', '') . ' kg' : '–' ?>
                        </td>
                        <td>
                            <button class="btn btn-outline-secondary btn-sm me-1"
                                    onclick="openSeriesEditModal(this)"
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
        <!-- Standardní formulář (váha, opakování, dopomoc) -->
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
                    <tr id="series-row-<?= $s['id'] ?>"
                        data-exercise-id="<?= $ex['exercise_id'] ?>"
                        data-is-timed="0"
                        data-weight="<?= (float)$s['weight'] ?>"
                        data-equipment-weight="<?= (float)($s['equipment_weight'] ?? 0) ?>"
                        data-reps="<?= (int)$s['reps'] ?>"
                        data-assist="<?= (int)$s['assistance_reps'] ?>"
                        data-duration-seconds="0">
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
                                    onclick="openSeriesEditModal(this)"
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
                                <td><?= number_format((float)$prev['weight'] + (float)($prev['equipment_weight'] ?? 0), 1, ',', '') ?></td>
                                <td><?= (int)$prev['reps'] ?></td>
                                <td><?= (int)$prev['assistance_reps'] > 0 ? (int)$prev['assistance_reps'] : '–' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($sportType === 'standard' && empty($ex['is_timed'])): ?>
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
            <?php elseif (!empty($ex['is_timed'])): ?>
            <!-- Formulář pro časový cvik -->
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
<?php if (!$session['completed_at']): ?>
<div class="text-center my-4">
    <button class="btn btn-success btn-lg fw-bold px-5"
            data-bs-toggle="modal" data-bs-target="#completeModal">
        <i class="fas fa-flag-checkered me-2"></i>Ukončit trénink
    </button>
</div>
<?php endif; ?>

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
                        <?php
                        $currentLocation = (string)($session['location'] ?? '');
                        $knownVenueNames = array_map(static fn(array $venue): string => (string)$venue['name'], $trainingVenues);
                        $isCustomLocation = $currentLocation !== '' && !in_array($currentLocation, $knownVenueNames, true);
                        ?>
                        <select class="form-select mb-2" id="complete-location-select">
                            <option value="">- Bez místa -</option>
                            <?php foreach ($trainingVenues as $venue): ?>
                            <?php $venueName = (string)$venue['name']; ?>
                            <option value="<?= h($venueName) ?>" <?= $venueName === $currentLocation ? 'selected' : '' ?>>
                                <?= h($venueName) ?><?= !empty($venue['address']) ? ' - ' . h((string)$venue['address']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="__custom__" <?= $isCustomLocation ? 'selected' : '' ?>>Jiné místo (zadat ručně)</option>
                        </select>
                        <input type="text" name="location" class="form-control"
                               id="complete-location-input"
                               value="<?= h($currentLocation) ?>"
                               placeholder="Napište nové místo..."
                               <?= $isCustomLocation ? '' : 'readonly' ?>>
                        <div class="form-text">Vyberte sportoviště ze seznamu, nebo zvolte „Jiné místo" a napište vlastní.</div>
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
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <input type="file"
                                   id="cameraCaptureInput"
                                   class="d-none"
                                   accept="image/*"
                                   capture="environment"
                                   onchange="collectTrainingPhotos(this.files)">
                            <label for="cameraCaptureInput" class="btn btn-outline-warning btn-sm mb-0">
                                <i class="fas fa-camera me-1"></i>Vyfotit
                            </label>

                            <input type="file"
                                   id="gallerySelectInput"
                                   class="d-none"
                                   accept="image/*"
                                   multiple
                                   onchange="collectTrainingPhotos(this.files)">
                            <label for="gallerySelectInput" class="btn btn-outline-secondary btn-sm mb-0">
                                <i class="fas fa-images me-1"></i>Vybrat z galerie
                            </label>
                        </div>

                        <!-- Skutečný submit input s nasbíranými soubory -->
                        <input type="file"
                               id="trainingPhotosCollector"
                               name="training_photos[]"
                               class="d-none"
                               accept="image/*"
                               multiple>

                        <div class="form-text">
                            Na mobilu/tabletu můžete fotky postupně přidávat: vyfotit i vybrat z galerie.
                            Podporováno JPG, PNG, GIF, WEBP (max 8 MB na soubor).
                        </div>
                            <div id="training-photo-summary" class="small text-muted mt-2">Zatím nejsou vybrané žádné fotky.</div>
                            <div id="training-photo-previews" class="d-flex flex-wrap gap-2 mt-2"></div>
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

<!-- Modal: Upravit sérii -->
<div class="modal fade" id="editSeriesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Upravit sérii</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit-series-id">
                <input type="hidden" id="edit-series-exercise-id">
                <input type="hidden" id="edit-series-is-timed">

                <div class="mb-3 edit-series-standard-fields">
                    <label class="form-label small fw-semibold mb-1">Váha (kg)</label>
                    <input type="number" step="0.5" min="0" max="999" class="form-control" id="edit-series-weight">
                </div>
                <div class="mb-3 edit-series-standard-fields">
                    <label class="form-label small fw-semibold mb-1">Opakování</label>
                    <input type="number" step="1" min="0" max="999" class="form-control" id="edit-series-reps">
                </div>
                <div class="mb-3 edit-series-standard-fields">
                    <label class="form-label small fw-semibold mb-1">Dopomoc</label>
                    <input type="number" step="1" min="0" max="999" class="form-control" id="edit-series-assist">
                </div>
                <div class="mb-3 edit-series-standard-fields">
                    <label class="form-label small fw-semibold mb-1">Váha náčiní (kg)</label>
                    <input type="number" step="0.5" min="0" max="999" class="form-control" id="edit-series-equipment-weight">
                </div>

                <div class="mb-3 edit-series-timed-fields d-none">
                    <label class="form-label small fw-semibold mb-1">Čas</label>
                    <div class="d-flex align-items-center gap-1">
                        <select class="form-select" id="edit-series-duration-min">
                            <?php for ($m = 0; $m <= 60; $m++): ?>
                            <option value="<?= $m ?>"><?= sprintf('%02d', $m) ?> min</option>
                            <?php endfor; ?>
                        </select>
                        <select class="form-select" id="edit-series-duration-sec">
                            <?php for ($s = 0; $s <= 60; $s++): ?>
                            <option value="<?= $s ?>"><?= sprintf('%02d', $s) ?> s</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3 edit-series-timed-fields d-none">
                    <label class="form-label small fw-semibold mb-1">Váha náčiní (kg)</label>
                    <input type="number" step="0.5" min="0" max="999" class="form-control" id="edit-series-timed-equipment-weight">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="button" class="btn btn-warning fw-bold" onclick="saveSeriesEdit()">
                    <i class="fas fa-save me-1"></i>Uložit změny
                </button>
            </div>
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

// Přidání série přes AJAX
function parseSeriesDuration(value) {
    const raw = String(value || '').trim();
    if (!raw) return 0;
    const parts = raw.split(':').map(part => parseInt(part, 10));
    if (parts.some(Number.isNaN)) return 0;
    if (parts.length === 3) {
        return (parts[0] * 3600) + (parts[1] * 60) + parts[2];
    }
    if (parts.length === 2) {
        return (parts[0] * 60) + parts[1];
    }
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

function renderSeriesRowHtml(exerciseId, rowNumber, seriesId, isTimed, payload) {
    const editButton = `<button class="btn btn-outline-secondary btn-sm me-1"
                            onclick="openSeriesEditModal(this)"
                            title="Upravit sérii">
                        <i class="fas fa-pen"></i>
                    </button>`;
    if (isTimed) {
        const load = (payload.weight + payload.equipmentWeight) > 0 ? (payload.weight + payload.equipmentWeight).toFixed(1).replace('.', ',') + ' kg' : '–';
        return `
                <td class="fw-bold text-muted">${rowNumber}</td>
                <td class="fw-bold">${payload.durationSeconds > 0 ? formatSeriesDurationJs(payload.durationSeconds) : '–'}</td>
                <td class="fw-bold">${load}</td>
                <td>
                    ${editButton}
                    <button class="btn btn-outline-danger btn-sm"
                            onclick="deleteSeries(${seriesId}, ${exerciseId})"
                            title="Smazat sérii">
                        <i class="fas fa-times"></i>
                    </button>
                </td>`;
    }

    return `
                <td class="fw-bold text-muted">${rowNumber}</td>
                <td class="fw-bold">${(payload.weight + payload.equipmentWeight) > 0 ? (payload.weight + payload.equipmentWeight).toFixed(1).replace('.', ',') : '–'}</td>
                <td>${payload.reps || '–'}</td>
                <td>${payload.assist > 0 ? '<span class="badge bg-warning text-dark">' + payload.assist + '</span>' : '–'}</td>
                <td>
                    ${editButton}
                    <button class="btn btn-outline-danger btn-sm"
                            onclick="deleteSeries(${seriesId}, ${exerciseId})"
                            title="Smazat sérii">
                        <i class="fas fa-times"></i>
                    </button>
                </td>`;
}

function setSeriesRowData(row, exerciseId, isTimed, payload) {
    row.dataset.exerciseId = String(exerciseId);
    row.dataset.isTimed = isTimed ? '1' : '0';
    row.dataset.weight = String(payload.weight || 0);
    row.dataset.equipmentWeight = String(payload.equipmentWeight || 0);
    row.dataset.reps = String(payload.reps || 0);
    row.dataset.assist = String(payload.assist || 0);
    row.dataset.durationSeconds = String(payload.durationSeconds || 0);
}

function openSeriesEditModal(button) {
    const row = button.closest('tr');
    if (!row) return;

    const seriesId = row.id.replace('series-row-', '');
    const exerciseId = row.dataset.exerciseId || '';
    const isTimed = row.dataset.isTimed === '1';
    const weight = row.dataset.weight || '0';
    const equipmentWeight = row.dataset.equipmentWeight || '0';
    const reps = row.dataset.reps || '0';
    const assist = row.dataset.assist || '0';
    const durationSeconds = parseInt(row.dataset.durationSeconds || '0', 10) || 0;

    document.getElementById('edit-series-id').value = seriesId;
    document.getElementById('edit-series-exercise-id').value = exerciseId;
    document.getElementById('edit-series-is-timed').value = isTimed ? '1' : '0';

    document.getElementById('edit-series-weight').value = weight;
    document.getElementById('edit-series-reps').value = reps;
    document.getElementById('edit-series-assist').value = assist;
    document.getElementById('edit-series-equipment-weight').value = equipmentWeight;
    fillMinuteSecondInputs(durationSeconds, document.getElementById('edit-series-duration-min'), document.getElementById('edit-series-duration-sec'));
    document.getElementById('edit-series-timed-equipment-weight').value = equipmentWeight;

    document.querySelectorAll('.edit-series-standard-fields').forEach(el => el.classList.toggle('d-none', isTimed));
    document.querySelectorAll('.edit-series-timed-fields').forEach(el => el.classList.toggle('d-none', !isTimed));

    const modalEl = document.getElementById('editSeriesModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

async function saveSeriesEdit() {
    const seriesId = parseInt(document.getElementById('edit-series-id').value || '0', 10);
    const exerciseId = parseInt(document.getElementById('edit-series-exercise-id').value || '0', 10);
    const isTimed = document.getElementById('edit-series-is-timed').value === '1';
    const weight = parseFloat(document.getElementById('edit-series-weight').value) || 0;
    const reps = parseInt(document.getElementById('edit-series-reps').value) || 0;
    const assist = parseInt(document.getElementById('edit-series-assist').value) || 0;
    const equipmentWeight = parseFloat(isTimed ? document.getElementById('edit-series-timed-equipment-weight').value : document.getElementById('edit-series-equipment-weight').value) || 0;
    const durationSeconds = isTimed
        ? durationFromMinuteSecondInputs(document.getElementById('edit-series-duration-min'), document.getElementById('edit-series-duration-sec'))
        : 0;

    if (!seriesId) return;
    if (isTimed && durationSeconds <= 0) {
        alert('Zadejte čas série ve formátu mm:ss.');
        return;
    }

    try {
        const resp = await fetch('<?= BASE_URL ?>/api/update_series.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: '<?= csrfToken() ?>',
                series_id: seriesId,
                weight: isTimed ? 0 : weight,
                equipment_weight: equipmentWeight,
                reps: isTimed ? 0 : reps,
                assistance_reps: isTimed ? 0 : assist,
                duration_seconds: durationSeconds
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
            alert('Chyba při ukládání: ' + (data.error || 'Neznámá chyba'));
            return;
        }

        const row = document.getElementById('series-row-' + seriesId);
        if (row) {
            setSeriesRowData(row, exerciseId, isTimed, {
                weight: isTimed ? 0 : weight,
                equipmentWeight: equipmentWeight,
                reps: isTimed ? 0 : reps,
                assist: isTimed ? 0 : assist,
                durationSeconds: durationSeconds
            });
            const rowNumber = Array.from(row.parentElement.children).indexOf(row) + 1;
            row.innerHTML = renderSeriesRowHtml(exerciseId, rowNumber, seriesId, isTimed, {
                weight: isTimed ? 0 : weight,
                equipmentWeight: equipmentWeight,
                reps: isTimed ? 0 : reps,
                assist: isTimed ? 0 : assist,
                durationSeconds: durationSeconds
            });
        }

        const modalEl = document.getElementById('editSeriesModal');
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    } catch (error) {
        alert('Chyba při komunikaci se serverem: ' + (error?.message || 'Neznámá chyba'));
    }
}

async function addSeries(exerciseId, sessionId) {
    const isTimed = document.getElementById('exercise-card-' + exerciseId)?.dataset.isTimed === '1';
    const weightInput = document.getElementById('weight-' + exerciseId);
    const timeMinInput = document.getElementById('time-min-' + exerciseId);
    const timeSecInput = document.getElementById('time-sec-' + exerciseId);
    const equipmentWeightInput = document.getElementById('equipment-weight-' + exerciseId);
    const repsInput = document.getElementById('reps-' + exerciseId);
    const assistInput = document.getElementById('assist-' + exerciseId);

    const weight = isTimed ? 0 : (parseFloat(weightInput?.value) || 0);
    const equipmentWeight = parseFloat(equipmentWeightInput?.value) || 0;
    const reps = isTimed ? 0 : (parseInt(repsInput?.value) || 0);
    const assist = isTimed ? 0 : (parseInt(assistInput?.value) || 0);
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
        const raw = await resp.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (_parseError) {
            throw new Error('Server vrátil neplatnou odpověď: ' + raw.slice(0, 180));
        }
        if (data.success) {
            // Přidej řádek do tabulky
            const tr = document.createElement('tr');
            tr.id = 'series-row-' + data.id;
            tr.dataset.exerciseId = String(exerciseId);
            tr.dataset.isTimed = isTimed ? '1' : '0';
            tr.dataset.weight = String(weight);
            tr.dataset.equipmentWeight = String(equipmentWeight);
            tr.dataset.reps = String(reps);
            tr.dataset.assist = String(assist);
            tr.dataset.durationSeconds = String(durationSeconds);
            tr.innerHTML = renderSeriesRowHtml(exerciseId, rowCount + 1, data.id, isTimed, {
                weight: weight,
                equipmentWeight: equipmentWeight,
                reps: reps,
                assist: assist,
                durationSeconds: durationSeconds
            });
            tbody.appendChild(tr);

            // Reset formuláře
            if (isTimed) {
                fillMinuteSecondInputs(0, timeMinInput, timeSecInput);
                if (equipmentWeightInput) equipmentWeightInput.value = '';
                timeMinInput?.focus();
            } else {
                if (weightInput) weightInput.value = '';
                if (equipmentWeightInput) equipmentWeightInput.value = '';
                if (repsInput) repsInput.value = '';
                if (assistInput) assistInput.value = '';
                weightInput?.focus();
            }

            // Aktualizuj počítadlo
            updateSeriesCount(exerciseId);
        } else {
            alert('Chyba při ukládání: ' + (data.error || 'Neznámá chyba'));
        }
    } catch (error) {
        alert('Chyba při komunikaci se serverem: ' + (error?.message || 'Neznámá chyba'));
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus me-1"></i>Přidat sérii';
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
        const raw = await resp.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (_parseError) {
            throw new Error('Server vrátil neplatnou odpověď: ' + raw.slice(0, 180));
        }
        if (data.success) {
            document.getElementById('series-row-' + seriesId)?.remove();
            renumberSeries(exerciseId);
            updateSeriesCount(exerciseId);
        } else {
            alert('Chyba při mazání: ' + (data.error || 'Neznámá chyba'));
        }
    } catch (error) {
        alert('Chyba při komunikaci se serverem: ' + (error?.message || 'Neznámá chyba'));
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

function renderTrainingPhotoPreviews() {
    const collector = document.getElementById('trainingPhotosCollector');
    const previews = document.getElementById('training-photo-previews');
    if (!collector || !previews) return;
    previews.innerHTML = '';
    const files = collector.files ? Array.from(collector.files) : [];
    if (files.length === 0) return;
    files.forEach((file, idx) => {
        const previewable = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!previewable.includes(file.type.toLowerCase())) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            const wrapper = document.createElement('div');
            wrapper.className = 'position-relative';
            wrapper.style.display = 'inline-block';
            wrapper.style.maxWidth = '110px';
            wrapper.style.maxHeight = '110px';
            wrapper.style.marginRight = '6px';
            wrapper.style.marginBottom = '6px';

            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'img-thumbnail';
            img.style.width = '100px';
            img.style.height = '100px';
            img.style.objectFit = 'cover';
            img.alt = 'Náhled fotky';

            // Křížek pro odstranění
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-danger position-absolute top-0 end-0 translate-middle p-1';
            removeBtn.style.zIndex = '2';
            removeBtn.style.borderRadius = '50%';
            removeBtn.title = 'Odebrat fotku';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.onclick = function() {
                removeTrainingPhoto(idx);
            };

            wrapper.appendChild(img);
            wrapper.appendChild(removeBtn);
            previews.appendChild(wrapper);
        };
        reader.readAsDataURL(file);
    });
}

function collectTrainingPhotos(fileList) {
    const collector = document.getElementById('trainingPhotosCollector');
    const summary = document.getElementById('training-photo-summary');
    if (!collector || !summary || !fileList || fileList.length === 0) {
        return;
    }

    const dt = new DataTransfer();
    const existing = collector.files ? Array.from(collector.files) : [];
    existing.forEach(file => dt.items.add(file));
    Array.from(fileList).forEach(file => dt.items.add(file));
    collector.files = dt.files;

    const total = collector.files.length;
    summary.textContent = total === 1
        ? 'Vybraná 1 fotka.'
        : ('Vybráno fotek: ' + total);

    renderTrainingPhotoPreviews();

    const cameraInput = document.getElementById('cameraCaptureInput');
    const galleryInput = document.getElementById('gallerySelectInput');
    if (cameraInput) {
        cameraInput.value = '';
    }
    if (galleryInput) {
        galleryInput.value = '';
    }
}

function removeTrainingPhoto(idx) {
    const collector = document.getElementById('trainingPhotosCollector');
    const summary = document.getElementById('training-photo-summary');
    if (!collector || !summary) return;
    const files = collector.files ? Array.from(collector.files) : [];
    if (idx < 0 || idx >= files.length) return;
    files.splice(idx, 1);
    const dt = new DataTransfer();
    files.forEach(file => dt.items.add(file));
    collector.files = dt.files;
    const total = collector.files.length;
    summary.textContent = total === 0
        ? 'Zatím nejsou vybrané žádné fotky.'
        : (total === 1 ? 'Vybraná 1 fotka.' : ('Vybráno fotek: ' + total));
    renderTrainingPhotoPreviews();
}

document.addEventListener('DOMContentLoaded', function() {
    const completeForm = document.querySelector('#completeModal form[action$="training_complete.php"]');
    const collector = document.getElementById('trainingPhotosCollector');
    if (!completeForm || !collector) {
        return;
    }

    renderTrainingPhotoPreviews();

    completeForm.addEventListener('submit', function() {
        // fallback kompatibilita: pokud je k dispozici i starý single input, necháme backend zpracovat collector
    });
});

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

document.addEventListener('DOMContentLoaded', function() {
    const locationSelect = document.getElementById('complete-location-select');
    const locationInput = document.getElementById('complete-location-input');
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
</script>

<?php renderFooter(); ?>
