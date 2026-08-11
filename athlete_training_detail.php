<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$athlete = getCurrentAthlete();
$athleteDisplayName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
if ($athleteDisplayName === '') {
    $athleteDisplayName = trim((string)($athlete['email'] ?? ''));
}
$sessionId = intParam($_GET, 'id');
$pdo       = getDB();

// Načti session – pouze vlastní tréninky sportovce
$stmt = $pdo->prepare(
    'SELECT ts.*, ws.name AS set_name
     FROM training_sessions ts
     JOIN workout_sets ws ON ws.id = ts.workout_set_id
     WHERE ts.id = ?
       AND ts.athlete_id = ?
       AND ts.deleted_by_coach_at IS NULL
     LIMIT 1'
);
$stmt->execute([$sessionId, $athleteId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Trénink nebyl nalezen.');
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$myCoachUser = mycoachResolveUser($pdo, 'athlete', 0, $athleteId, $athleteDisplayName);
$myCoachUserId = $myCoachUser ? (int)$myCoachUser['id'] : 0;
$sessionEntryDate = !empty($session['completed_at'])
    ? date('Y-m-d', strtotime((string)$session['completed_at']))
    : date('Y-m-d', strtotime((string)($session['started_at'] ?? 'now')));
$workoutTypeOptions = mycoachWorkoutTypeOptions();
$latestQuestionnaire = $myCoachUserId > 0 ? mycoachFetchLatestQuestionnaire($pdo, $myCoachUserId) : null;
$activeGoal = $myCoachUserId > 0 ? mycoachFetchActiveGoal($pdo, $myCoachUserId) : null;
$timeline = $myCoachUserId > 0 ? mycoachFetchDailyTimeline($pdo, $myCoachUserId, 45) : [];
$existingMyCoachEntry = null;
if ($myCoachUserId > 0) {
    $existingEntryStmt = $pdo->prepare('SELECT * FROM mycoach_daily_questionnaires WHERE user_id = ? AND workout_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $existingEntryStmt->execute([$myCoachUserId, $sessionId]);
    $existingMyCoachEntry = $existingEntryStmt->fetch() ?: null;
}

$trainerDurationMinutes = null;
if (!empty($session['started_at']) && !empty($session['completed_at'])) {
    try {
        $startedAt = new DateTimeImmutable((string)$session['started_at']);
        $completedAt = new DateTimeImmutable((string)$session['completed_at']);
        $trainerDurationMinutes = max(0, (int)round(($completedAt->getTimestamp() - $startedAt->getTimestamp()) / 60));
    } catch (Throwable $e) {
        $trainerDurationMinutes = null;
    }
}

$myCoachError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_mycoach_session_data') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $myCoachError = 'Neplatný bezpečnostní token.';
    } elseif ($myCoachUserId <= 0) {
        $myCoachError = 'MyCoach profil se nepodařilo načíst.';
    } else {
        $payload = [
            'id' => $_POST['mycoach_entry_id'] ?? ($existingMyCoachEntry['id'] ?? null),
            'entry_date' => $sessionEntryDate,
            'workout_id' => $sessionId,
            'workout_type' => $_POST['workout_type'] ?? ($existingMyCoachEntry['workout_type'] ?? 'strength'),
            'workout_meta' => $_POST['workout_meta'] ?? [],
            'feeling_score' => $_POST['feeling_score'] ?? null,
            'rpe_score' => $_POST['rpe_score'] ?? null,
            'sleep_hours' => $_POST['sleep_hours'] ?? null,
            'muscle_pain_score' => $_POST['muscle_pain_score'] ?? null,
            'joint_pain_score' => $_POST['joint_pain_score'] ?? null,
            'motivation_score' => $_POST['motivation_score'] ?? null,
            'energy_score' => $_POST['energy_score'] ?? null,
            'training_duration_minutes' => $_POST['training_duration_minutes'] ?? null,
            'calories_burned' => $_POST['calories_burned'] ?? null,
            'avg_heart_rate' => $_POST['avg_heart_rate'] ?? null,
            'max_heart_rate' => $_POST['max_heart_rate'] ?? null,
            'athlete_note' => $_POST['athlete_note'] ?? null,
        ];
        if (!mycoachStoreDailyQuestionnaire($pdo, $myCoachUserId, $payload)) {
            $myCoachError = 'MyCoach data se nepodařilo uložit.';
        } else {
            $readinessSource = [
                'feeling_score' => $_POST['feeling_score'] ?? null,
                'energy_score' => $_POST['energy_score'] ?? null,
                'motivation_score' => $_POST['motivation_score'] ?? null,
                'sleep_hours' => $_POST['sleep_hours'] ?? null,
                'muscle_pain_score' => $_POST['muscle_pain_score'] ?? null,
                'joint_pain_score' => $_POST['joint_pain_score'] ?? null,
                'rpe_score' => $_POST['rpe_score'] ?? null,
                'training_duration_minutes' => $_POST['training_duration_minutes'] ?? null,
                'calories_burned' => $_POST['calories_burned'] ?? null,
                'avg_heart_rate' => $_POST['avg_heart_rate'] ?? null,
                'max_heart_rate' => $_POST['max_heart_rate'] ?? null,
            ];
            mycoachStoreReadinessMetric($pdo, $myCoachUserId, $sessionEntryDate, mycoachCalculateReadinessScore($readinessSource));
            flash('success', 'MyCoach data k tomuto tréninku byla uložena.');
            redirect(BASE_URL . '/athlete_training_detail.php?id=' . $sessionId);
        }
    }
}

$existingWorkoutMeta = [];
if (!empty($existingMyCoachEntry['workout_meta_json'])) {
    $decodedWorkoutMeta = json_decode((string)$existingMyCoachEntry['workout_meta_json'], true);
    if (is_array($decodedWorkoutMeta)) {
        $existingWorkoutMeta = $decodedWorkoutMeta;
    }
}
$myCoachWorkoutType = trim((string)($_POST['workout_type'] ?? ($existingMyCoachEntry['workout_type'] ?? 'strength')));
$myCoachWorkoutMeta = !empty($_POST['workout_meta']) && is_array($_POST['workout_meta']) ? $_POST['workout_meta'] : $existingWorkoutMeta;
$detailDurationValue = (string)($_POST['training_duration_minutes'] ?? ($existingMyCoachEntry['training_duration_minutes'] ?? ($trainerDurationMinutes ?? '')));
$detailCaloriesValue = (string)($_POST['calories_burned'] ?? ($existingMyCoachEntry['calories_burned'] ?? ''));
$detailAvgHrValue = (string)($_POST['avg_heart_rate'] ?? ($existingMyCoachEntry['avg_heart_rate'] ?? ''));
$detailMaxHrValue = (string)($_POST['max_heart_rate'] ?? ($existingMyCoachEntry['max_heart_rate'] ?? ''));
$detailFeelingValue = (string)($_POST['feeling_score'] ?? ($existingMyCoachEntry['feeling_score'] ?? ''));
$detailRpeValue = (string)($_POST['rpe_score'] ?? ($existingMyCoachEntry['rpe_score'] ?? ''));
$detailSleepValue = (string)($_POST['sleep_hours'] ?? ($existingMyCoachEntry['sleep_hours'] ?? ''));
$detailMusclePainValue = (string)($_POST['muscle_pain_score'] ?? ($existingMyCoachEntry['muscle_pain_score'] ?? ''));
$detailJointPainValue = (string)($_POST['joint_pain_score'] ?? ($existingMyCoachEntry['joint_pain_score'] ?? ''));
$detailMotivationValue = (string)($_POST['motivation_score'] ?? ($existingMyCoachEntry['motivation_score'] ?? ''));
$detailEnergyValue = (string)($_POST['energy_score'] ?? ($existingMyCoachEntry['energy_score'] ?? ''));
$detailAthleteNoteValue = (string)($_POST['athlete_note'] ?? ($existingMyCoachEntry['athlete_note'] ?? ''));
$detailReadinessSource = [
    'feeling_score' => $detailFeelingValue !== '' ? $detailFeelingValue : null,
    'energy_score' => $detailEnergyValue !== '' ? $detailEnergyValue : null,
    'motivation_score' => $detailMotivationValue !== '' ? $detailMotivationValue : null,
    'sleep_hours' => $detailSleepValue !== '' ? $detailSleepValue : null,
    'muscle_pain_score' => $detailMusclePainValue !== '' ? $detailMusclePainValue : null,
    'joint_pain_score' => $detailJointPainValue !== '' ? $detailJointPainValue : null,
    'rpe_score' => $detailRpeValue !== '' ? $detailRpeValue : null,
    'training_duration_minutes' => $detailDurationValue !== '' ? $detailDurationValue : $trainerDurationMinutes,
    'calories_burned' => $detailCaloriesValue !== '' ? $detailCaloriesValue : null,
    'avg_heart_rate' => $detailAvgHrValue !== '' ? $detailAvgHrValue : null,
    'max_heart_rate' => $detailMaxHrValue !== '' ? $detailMaxHrValue : null,
];
$detailReadinessScore = mycoachCalculateReadinessScore($detailReadinessSource);
$detailReadinessGuidance = mycoachBuildReadinessGuidance($detailReadinessScore, $detailReadinessSource, $latestQuestionnaire, $timeline, $activeGoal);

// Cviky a série
$exercises = getSessionExercises($sessionId, (int)$session['workout_set_id']);

$seriesByExercise = [];
foreach ($exercises as $ex) {
    $seriesByExercise[$ex['exercise_id']] = getSeriesForExercise($sessionId, (int)$ex['exercise_id']);
}

// Fotky
$photos = getTrainingSessionPhotos($sessionId);

renderAthleteHeader('Detail tréninku');
?>

<div class="d-flex align-items-center mb-3 gap-3 flex-wrap">
    <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Zpět
    </a>
    <div>
        <h2 class="mb-0 fw-bold">
            <i class="fas fa-clipboard-list me-2 text-warning"></i><?= h((string)$session['set_name']) ?>
        </h2>
        <span class="text-muted"><?= formatDateTime((string)($session['completed_at'] ?? $session['started_at'])) ?></span>
        <?php if (!empty($session['location'])): ?>
        <span class="ms-2 text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= h((string)$session['location']) ?></span>
        <?php endif; ?>
    </div>
    <div class="ms-auto">
        <?php if (!empty($session['completed_at'])): ?>
        <span class="badge bg-success fs-6"><i class="fas fa-check me-1"></i>Dokončeno</span>
        <?php else: ?>
        <span class="badge bg-warning text-dark fs-6">Naplánováno / probíhá</span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($session['coach_note'])): ?>
<div class="alert alert-info mb-3">
    <strong><i class="fas fa-comment me-1"></i>Poznámka trenéra:</strong>
    <div style="white-space:pre-wrap"><?= h((string)$session['coach_note']) ?></div>
</div>
<?php endif; ?>

<?php if (!empty($session['athlete_note'])): ?>
<div class="alert alert-secondary mb-3">
    <strong><i class="fas fa-sticky-note me-1"></i>Moje poznámka:</strong>
    <div style="white-space:pre-wrap"><?= h((string)$session['athlete_note']) ?></div>
</div>
<?php endif; ?>

<?php if ($myCoachError): ?>
<div class="alert alert-danger mb-3"><?= h($myCoachError) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4 border-start border-4 border-<?= h($detailReadinessGuidance['variant']) ?>">
    <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-brain me-2 text-primary"></i>MyCoach data k tomuto tréninku</span>
        <a href="#" class="btn btn-sm btn-outline-secondary" onclick="return false;" aria-disabled="true">MyCoach je nyní jen Pro</a>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <div class="fw-bold">Readiness preview: <?= (int)$detailReadinessScore ?> / 100</div>
            <div class="text-muted small"><?= h($detailReadinessGuidance['label']) ?> · <?= h($detailReadinessGuidance['trainability']) ?> · doporučený strop <?= (int)$detailReadinessGuidance['session_cap_minutes'] ?> min</div>
        </div>
        <form method="post" class="row g-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_mycoach_session_data">
            <input type="hidden" name="mycoach_entry_id" value="<?= h((string)($existingMyCoachEntry['id'] ?? '')) ?>">
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Typ tréninku</label>
                <select name="workout_type" id="myCoachWorkoutTypeSelect" class="form-select">
                    <?php foreach ($workoutTypeOptions as $typeKey => $typeDefinition): ?>
                    <option value="<?= h($typeKey) ?>" <?= $myCoachWorkoutType === $typeKey ? 'selected' : '' ?>><?= h((string)$typeDefinition['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Reálná délka (min)</label>
                <input type="number" min="0" max="1440" name="training_duration_minutes" class="form-control" value="<?= h($detailDurationValue) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Spálené kalorie</label>
                <input type="number" min="0" max="20000" name="calories_burned" class="form-control" value="<?= h($detailCaloriesValue) ?>">
            </div>
            <div class="col-12">
                <div id="myCoachWorkoutTypePanels">
                    <?php foreach ($workoutTypeOptions as $typeKey => $typeDefinition): ?>
                    <div class="mycoach-workout-type-panel border rounded-4 p-3 <?= $myCoachWorkoutType === $typeKey ? '' : 'd-none' ?>" data-mycoach-workout-type-panel="<?= h($typeKey) ?>">
                        <div class="row g-3">
                            <?php foreach ($typeDefinition['fields'] as $fieldKey => $fieldDefinition): ?>
                            <div class="col-12 col-md-6 col-xl-4">
                                <label class="form-label fw-semibold"><?= h((string)$fieldDefinition['label']) ?></label>
                                <?php if (($fieldDefinition['type'] ?? 'text') === 'number'): ?>
                                <input type="number" name="workout_meta[<?= h($fieldKey) ?>]" class="form-control" value="<?= h((string)($myCoachWorkoutMeta[$fieldKey] ?? '')) ?>" <?= isset($fieldDefinition['step']) ? 'step="' . h((string)$fieldDefinition['step']) . '"' : '' ?> <?= isset($fieldDefinition['min']) ? 'min="' . h((string)$fieldDefinition['min']) . '"' : '' ?> <?= isset($fieldDefinition['max']) ? 'max="' . h((string)$fieldDefinition['max']) . '"' : '' ?>>
                                <?php else: ?>
                                <input type="text" name="workout_meta[<?= h($fieldKey) ?>]" class="form-control" value="<?= h((string)($myCoachWorkoutMeta[$fieldKey] ?? '')) ?>" placeholder="<?= h((string)($fieldDefinition['placeholder'] ?? '')) ?>">
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Jak ses cítil?</label>
                <input type="number" min="1" max="10" name="feeling_score" class="form-control" value="<?= h($detailFeelingValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">RPE</label>
                <input type="number" min="1" max="10" name="rpe_score" class="form-control" value="<?= h($detailRpeValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Spánek (hod)</label>
                <input type="number" step="0.1" min="0" max="24" name="sleep_hours" class="form-control" value="<?= h($detailSleepValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Energie</label>
                <input type="number" min="1" max="10" name="energy_score" class="form-control" value="<?= h($detailEnergyValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Svalová bolest</label>
                <input type="number" min="0" max="10" name="muscle_pain_score" class="form-control" value="<?= h($detailMusclePainValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Bolest kloubů</label>
                <input type="number" min="0" max="10" name="joint_pain_score" class="form-control" value="<?= h($detailJointPainValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Motivace</label>
                <input type="number" min="1" max="10" name="motivation_score" class="form-control" value="<?= h($detailMotivationValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Prům. tep</label>
                <input type="number" min="0" max="300" name="avg_heart_rate" class="form-control" value="<?= h($detailAvgHrValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Max tep</label>
                <input type="number" min="0" max="300" name="max_heart_rate" class="form-control" value="<?= h($detailMaxHrValue) ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Moje poznámka k tomuto tréninku</label>
                <textarea name="athlete_note" class="form-control" rows="3" placeholder="Tady doplň, co bylo jinak, jak se tělo chovalo, co ukázaly hodinky..."><?= h($detailAthleteNoteValue) ?></textarea>
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Uložit do MyCoach</button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($photos)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-images me-2"></i>Fotky z tréninku
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
        <?php foreach ($photos as $photo): ?>
            <a href="<?= BASE_URL ?>/uploads/<?= h((string)$photo['filename']) ?>" target="_blank">
                <img src="<?= BASE_URL ?>/uploads/<?= h((string)$photo['filename']) ?>"
                     style="height:120px;width:120px;object-fit:cover;border-radius:8px;border:2px solid #e5e7eb;">
            </a>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (empty($exercises)): ?>
<div class="alert alert-info">Tento trénink neobsahuje žádné záznamy cviků.</div>
<?php else: ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-dumbbell me-2"></i>Cviky a série
    </div>
    <div class="card-body p-0">
    <?php foreach ($exercises as $ex):
        $series = $seriesByExercise[$ex['exercise_id']] ?? [];
    ?>
    <div class="p-3 border-bottom">
        <div class="fw-bold mb-2">
            <?= (int)$ex['exercise_order'] ?>. <?= h((string)$ex['exercise_name']) ?>
        </div>
        <?php if (empty($series)): ?>
            <span class="text-muted small">Žádné série nebyly zaznamenány.</span>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="max-width:500px">
                <thead class="table-light">
                <tr>
                    <th style="width:50px">Série</th>
                    <th>Opakování</th>
                    <th>Zátěž (kg)</th>
                    <?php if (!empty(array_filter($series, fn($s) => !empty($s['note'])))): ?>
                    <th>Poznámka</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php $hasNote = !empty(array_filter($series, fn($s) => !empty($s['note']))); ?>
                <?php foreach ($series as $i => $serie): ?>
                <tr>
                    <td class="text-center fw-semibold"><?= ($i + 1) ?></td>
                    <td><?= isset($serie['reps']) && $serie['reps'] !== null ? (int)$serie['reps'] : '–' ?></td>
                    <td><?= isset($serie['weight']) && $serie['weight'] !== null && (float)$serie['weight'] > 0 ? number_format((float)$serie['weight'], 1, ',', '') : '–' ?></td>
                    <?php if ($hasNote): ?>
                    <td><?= !empty($serie['note']) ? h((string)$serie['note']) : '' ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('myCoachWorkoutTypeSelect');
    const panels = document.querySelectorAll('[data-mycoach-workout-type-panel]');

    function syncPanels() {
        const activeType = typeSelect ? typeSelect.value : '';
        panels.forEach(function (panel) {
            panel.classList.toggle('d-none', panel.dataset.mycoachWorkoutTypePanel !== activeType);
        });
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', syncPanels);
        syncPanels();
    }
});
</script>

<?php renderAthleteFooter(); ?>
