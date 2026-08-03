<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$pdo = getDB();
$athleteId = (int)getCurrentAthleteId();
$athlete = getCurrentAthlete();
$athleteDisplayName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
if ($athleteDisplayName === '') {
    $athleteDisplayName = trim((string)($athlete['email'] ?? ''));
}

if (!function_exists('athleteMyCoachUnlocked')) {
    function athleteMyCoachUnlocked(PDO $pdo, int $athleteId): bool {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'mycoach_enabled'");
            if ($columnStmt === false || !$columnStmt->fetch()) {
                return false;
            }

            $valueStmt = $pdo->prepare('SELECT mycoach_enabled FROM athletes WHERE id = ? LIMIT 1');
            $valueStmt->execute([$athleteId]);
            return ((int)$valueStmt->fetchColumn()) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!athleteMyCoachUnlocked($pdo, $athleteId)) {
    flash('warning', 'MyCoach je pro váš účet zatím uzamčený.');
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$myCoachUser = mycoachResolveUser($pdo, 'athlete', 0, $athleteId, $athleteDisplayName);
if (!$myCoachUser) {
    flash('danger', 'MyCoach profil se nepodařilo načíst.');
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$myCoachUserId = (int)$myCoachUser['id'];
$entryDate = isset($_GET['date']) ? trim((string)$_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
    $entryDate = date('Y-m-d');
}

$trainerSessions = mycoachFetchDailyTrainerSessions($pdo, $athleteId, $entryDate);
$latestQuestionnaire = mycoachFetchLatestQuestionnaire($pdo, $myCoachUserId);
$workoutTypeOptions = mycoachWorkoutTypeOptions();
$existingDailyStmt = $pdo->prepare('SELECT * FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date = ? ORDER BY created_at DESC, id DESC LIMIT 1');
$existingDailyStmt->execute([$myCoachUserId, $entryDate]);
$existingDaily = $existingDailyStmt->fetch() ?: null;

$existingDailyRowsStmt = $pdo->prepare('SELECT * FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date = ? ORDER BY created_at DESC, id DESC');
$existingDailyRowsStmt->execute([$myCoachUserId, $entryDate]);
$existingDailyRows = $existingDailyRowsStmt->fetchAll() ?: [];

$autoTrainerWorkoutId = 0;
foreach ($trainerSessions as $session) {
    $sessionId = (int)($session['session_id'] ?? 0);
    if ($sessionId > 0) {
        $autoTrainerWorkoutId = $sessionId;
    }
}

$readinessSource = [
    'feeling_score' => $_POST['feeling_score'] ?? ($existingDaily['feeling_score'] ?? null),
    'energy_score' => $_POST['energy_score'] ?? ($existingDaily['energy_score'] ?? null),
    'motivation_score' => $_POST['motivation_score'] ?? ($existingDaily['motivation_score'] ?? null),
    'sleep_hours' => $_POST['sleep_hours'] ?? ($existingDaily['sleep_hours'] ?? null),
    'muscle_pain_score' => $_POST['muscle_pain_score'] ?? ($existingDaily['muscle_pain_score'] ?? null),
    'joint_pain_score' => $_POST['joint_pain_score'] ?? ($existingDaily['joint_pain_score'] ?? null),
    'rpe_score' => $_POST['rpe_score'] ?? ($existingDaily['rpe_score'] ?? null),
];
$readinessScore = mycoachCalculateReadinessScore($readinessSource);
$recommendation = mycoachBuildRecommendation($readinessScore, $readinessSource);
$selectedWorkoutType = trim((string)($_POST['workout_type'] ?? ($existingDaily['workout_type'] ?? '')));
if ($selectedWorkoutType === '') {
    $selectedWorkoutType = 'run';
}
$selectedWorkoutMeta = [];
if (!empty($existingDaily['workout_meta_json'])) {
    $decodedMeta = json_decode((string)$existingDaily['workout_meta_json'], true);
    if (is_array($decodedMeta)) {
        $selectedWorkoutMeta = $decodedMeta;
    }
}
if (!empty($_POST['workout_meta']) && is_array($_POST['workout_meta'])) {
    $selectedWorkoutMeta = $_POST['workout_meta'];
}

$dailyError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $dailyError = 'Neplatný bezpečnostní token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_daily') {
            $payload = [
                'entry_date' => $entryDate,
                'workout_id' => $existingDaily['workout_id'] ?? ($autoTrainerWorkoutId > 0 ? $autoTrainerWorkoutId : null),
                'workout_type' => $_POST['workout_type'] ?? ($existingDaily['workout_type'] ?? null),
                'workout_meta' => $_POST['workout_meta'] ?? $selectedWorkoutMeta,
                'feeling_score' => $_POST['feeling_score'] ?? null,
                'rpe_score' => $_POST['rpe_score'] ?? null,
                'sleep_hours' => $_POST['sleep_hours'] ?? null,
                'muscle_pain_score' => $_POST['muscle_pain_score'] ?? null,
                'joint_pain_score' => $_POST['joint_pain_score'] ?? null,
                'motivation_score' => $_POST['motivation_score'] ?? null,
                'energy_score' => $_POST['energy_score'] ?? null,
                'training_duration_minutes' => $_POST['training_duration_minutes'] ?? null,
                'avg_heart_rate' => $_POST['avg_heart_rate'] ?? null,
                'max_heart_rate' => $_POST['max_heart_rate'] ?? null,
            ];

            if (!mycoachStoreDailyQuestionnaire($pdo, $myCoachUserId, $payload)) {
                $dailyError = 'Denní záznam se nepodařilo uložit.';
            } else {
                mycoachStoreReadinessMetric($pdo, $myCoachUserId, $entryDate, $readinessScore);
                flash('success', 'Denní záznam byl uložený i s tréninkem od trenéra.');
                redirect(BASE_URL . '/athlete_mycoach_daily.php?date=' . urlencode($entryDate));
            }
        }
    }
}

$selectedWorkoutId = (int)($_POST['workout_id'] ?? ($existingDaily['workout_id'] ?? $autoTrainerWorkoutId));
$selectedWorkoutId = $selectedWorkoutId > 0 ? $selectedWorkoutId : 0;

$selectedTrainerSession = null;
foreach ($trainerSessions as $session) {
    if ((int)($session['session_id'] ?? 0) === $selectedWorkoutId) {
        $selectedTrainerSession = $session;
        break;
    }
}

$feelingValue = (string)($_POST['feeling_score'] ?? ($existingDaily['feeling_score'] ?? ''));
$rpeValue = (string)($_POST['rpe_score'] ?? ($existingDaily['rpe_score'] ?? ''));
$sleepValue = (string)($_POST['sleep_hours'] ?? ($existingDaily['sleep_hours'] ?? ''));
$musclePainValue = (string)($_POST['muscle_pain_score'] ?? ($existingDaily['muscle_pain_score'] ?? ''));
$jointPainValue = (string)($_POST['joint_pain_score'] ?? ($existingDaily['joint_pain_score'] ?? ''));
$motivationValue = (string)($_POST['motivation_score'] ?? ($existingDaily['motivation_score'] ?? ''));
$energyValue = (string)($_POST['energy_score'] ?? ($existingDaily['energy_score'] ?? ''));
$durationValue = (string)($_POST['training_duration_minutes'] ?? ($existingDaily['training_duration_minutes'] ?? ''));
$avgHrValue = (string)($_POST['avg_heart_rate'] ?? ($existingDaily['avg_heart_rate'] ?? ''));
$maxHrValue = (string)($_POST['max_heart_rate'] ?? ($existingDaily['max_heart_rate'] ?? ''));

renderAthleteHeader('MyCoach denní záznam', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-1"><i class="fas fa-book-open me-2 text-warning"></i>Denní záznam</h2>
        <div class="text-muted">Dokončené tréninky s trenérem se propisují do dnešního logu automaticky.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-house me-1"></i>Domů</a>
        <a href="<?= BASE_URL ?>/athlete_mycoach.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Zpět do MyCoach</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Datum</div>
                <div class="fs-4 fw-bold"><?= h(formatDate($entryDate)) ?></div>
                <div class="text-muted small">Denní záznam pro <?= h($athleteDisplayName) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Dnešní tréninky od trenéra</div>
                <div class="fs-3 fw-bold text-primary"><?= count($trainerSessions) ?></div>
                <div class="text-muted small">dokončené session propojené s tímto dnem</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Stav záznamu</div>
                <div class="fs-5 fw-bold"><?= $existingDaily ? 'Uložený' : 'Nový' ?></div>
                <div class="text-muted small">MyCoach denní questionnaire</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Readiness</div>
                <div class="fs-3 fw-bold text-success"><?= (int)$readinessScore ?> / 100</div>
                <div class="text-muted small">odhad připravenosti na základě dnešních odpovědí</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-<?= h($recommendation['variant']) ?>">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Doporučení pro dnešek</div>
                <div class="fs-5 fw-bold"><?= h($recommendation['title']) ?></div>
                <div class="text-muted"><?= h($recommendation['text']) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($dailyError): ?>
<div class="alert alert-danger shadow-sm"><?= h($dailyError) ?></div>
<?php endif; ?>

<?php if ($autoTrainerWorkoutId > 0): ?>
<div class="alert alert-success shadow-sm">
    Dokončený trénink od trenéra je k dnešnímu záznamu připojen automaticky.
</div>
<?php else: ?>
<div class="alert alert-info shadow-sm">
    Až trenér ukončí session, připojí se do denního záznamu automaticky.
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-primary text-white fw-bold">
        <i class="fas fa-dumbbell me-2"></i>Tréninky s trenérem pro dnešek
    </div>
    <div class="card-body">
        <?php if ($trainerSessions): ?>
            <div class="row g-3">
                <?php foreach ($trainerSessions as $session): ?>
                <div class="col-12 col-xl-6">
                    <div class="border rounded-4 p-3 h-100 <?= $selectedWorkoutId === (int)$session['session_id'] ? 'border-primary bg-primary-subtle' : '' ?>">
                        <div class="d-flex justify-content-between gap-3 flex-wrap">
                            <div>
                                <div class="fw-bold fs-5"><?= h((string)($session['set_name'] ?? 'Trénink')) ?></div>
                                <div class="text-muted small">Trenér: <?= h((string)($session['coach_name'] ?? $session['coach_username'] ?? '')) ?></div>
                            </div>
                            <div class="text-end small text-muted">
                                <div><?= !empty($session['started_at']) ? h(formatDateTime((string)$session['started_at'])) : 'bez startu' ?></div>
                                <div><?= !empty($session['completed_at']) ? 'dokončeno ' . h(formatDateTime((string)$session['completed_at'])) : 'nedokončeno' ?></div>
                            </div>
                        </div>
                        <div class="mt-2 small">
                            <?php if (!empty($session['location'])): ?><div>Místo: <?= h((string)$session['location']) ?></div><?php endif; ?>
                            <?php if (!empty($session['notes'])): ?><div>Poznámka: <?= h((string)$session['notes']) ?></div><?php endif; ?>
                        </div>
                        <div class="mt-3 d-flex gap-2 flex-wrap">
                            <a href="<?= BASE_URL ?>/training_detail.php?id=<?= (int)$session['session_id'] ?>" class="btn btn-outline-secondary btn-sm">Detail tréninku</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">Pro dnešek zatím není dokončený trénink od trenéra. Záznam můžeš i tak uložit a doplnit vlastní progres.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_daily">
            <input type="hidden" name="date" value="<?= h($entryDate) ?>">
            <input type="hidden" name="workout_id" value="<?= $selectedWorkoutId > 0 ? (int)$selectedWorkoutId : '' ?>">
            <div class="col-12">
                <label class="form-label fw-semibold">Přiřazený trénink</label>
                <div class="form-control bg-light">
                    <?php if ($selectedTrainerSession): ?>
                        <?= h((string)($selectedTrainerSession['set_name'] ?? 'Trénink')) ?>
                    <?php else: ?>
                        Bez trenérského tréninku, vyplňuješ jen vlastní denní progres
                    <?php endif; ?>
                </div>
                <div class="form-text">Pole se plní automaticky podle dokončené session trenéra.</div>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Typ tréninku</label>
                <select name="workout_type" id="workoutTypeSelect" class="form-select" required>
                    <?php foreach ($workoutTypeOptions as $typeKey => $typeDefinition): ?>
                    <option value="<?= h($typeKey) ?>" <?= $selectedWorkoutType === $typeKey ? 'selected' : '' ?>><?= h((string)$typeDefinition['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-8 d-flex align-items-end">
                <div class="text-muted small">Vyplň jen to, co víš. Pole jsou nepovinná, takže můžeš uložit i stručný záznam bez tepů, spánku nebo přesných metrik.</div>
            </div>

            <div class="col-12">
                <div id="workoutTypePanels">
                    <?php foreach ($workoutTypeOptions as $typeKey => $typeDefinition): ?>
                    <div class="workout-type-panel border rounded-4 p-3 mb-3 <?= $selectedWorkoutType === $typeKey ? '' : 'd-none' ?>" data-workout-type-panel="<?= h($typeKey) ?>">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                            <div>
                                <div class="fw-bold fs-5"><i class="fas <?= h((string)$typeDefinition['icon']) ?> me-2 text-primary"></i><?= h((string)$typeDefinition['label']) ?></div>
                                <div class="text-muted small"><?= h((string)$typeDefinition['description']) ?></div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <?php foreach ($typeDefinition['fields'] as $fieldKey => $fieldDefinition): ?>
                            <div class="col-12 col-md-6 col-xl-4">
                                <label class="form-label fw-semibold"><?= h((string)$fieldDefinition['label']) ?></label>
                                <?php if (($fieldDefinition['type'] ?? 'text') === 'number'): ?>
                                <input
                                    type="number"
                                    name="workout_meta[<?= h($fieldKey) ?>]"
                                    class="form-control"
                                    value="<?= h((string)($selectedWorkoutMeta[$fieldKey] ?? '')) ?>"
                                    <?= isset($fieldDefinition['step']) ? 'step="' . h((string)$fieldDefinition['step']) . '"' : '' ?>
                                    <?= isset($fieldDefinition['min']) ? 'min="' . h((string)$fieldDefinition['min']) . '"' : '' ?>
                                    <?= isset($fieldDefinition['max']) ? 'max="' . h((string)$fieldDefinition['max']) . '"' : '' ?>
                                >
                                <?php else: ?>
                                <input
                                    type="text"
                                    name="workout_meta[<?= h($fieldKey) ?>]"
                                    class="form-control"
                                    value="<?= h((string)($selectedWorkoutMeta[$fieldKey] ?? '')) ?>"
                                    placeholder="<?= h((string)($fieldDefinition['placeholder'] ?? '')) ?>"
                                >
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
                <input type="number" min="1" max="10" name="feeling_score" class="form-control" value="<?= h($feelingValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">RPE</label>
                <input type="number" min="1" max="10" name="rpe_score" class="form-control" value="<?= h($rpeValue) ?>">
                <div class="form-text">RPE = subjektivní vnímaná náročnost tréninku na škále 1-10.</div>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Spánek (hod)</label>
                <input type="number" step="0.1" min="0" max="24" name="sleep_hours" class="form-control" value="<?= h($sleepValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Svalová bolest</label>
                <input type="number" min="0" max="10" name="muscle_pain_score" class="form-control" value="<?= h($musclePainValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Bolest kloubů</label>
                <input type="number" min="0" max="10" name="joint_pain_score" class="form-control" value="<?= h($jointPainValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Motivace</label>
                <select name="motivation_score" class="form-select">
                    <option value="">Nevím</option>
                    <?php for ($motivationLevel = 1; $motivationLevel <= 10; $motivationLevel++): ?>
                    <option value="<?= $motivationLevel ?>" <?= $motivationValue !== '' && (int)$motivationValue === $motivationLevel ? 'selected' : '' ?>><?= $motivationLevel ?> - <?= $motivationLevel <= 3 ? 'nízká' : ($motivationLevel <= 6 ? 'střední' : ($motivationLevel <= 8 ? 'vysoká' : 'extrémní')) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Energie</label>
                <input type="number" min="1" max="10" name="energy_score" class="form-control" value="<?= h($energyValue) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Délka tréninku (min)</label>
                <input type="number" min="0" max="1440" name="training_duration_minutes" class="form-control" value="<?= h($durationValue) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Prům. tep</label>
                <input type="number" min="0" max="300" name="avg_heart_rate" class="form-control" value="<?= h($avgHrValue) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Max tep</label>
                <input type="number" min="0" max="300" name="max_heart_rate" class="form-control" value="<?= h($maxHrValue) ?>">
            </div>

            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-success fw-semibold"><i class="fas fa-save me-1"></i>Uložit denní záznam</button>
                <a href="<?= BASE_URL ?>/athlete_mycoach.php" class="btn btn-outline-secondary">Zpět</a>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-info border-0 shadow-sm mt-4 mb-0">
    <?php if ($latestQuestionnaire): ?>
        Poslední onboarding dotazník je uložený a denní záznam už nad ním může stavět.
    <?php else: ?>
        Nejprve je vhodné vyplnit úvodní dotazník v MyCoach.
    <?php endif; ?>
</div>

<?php if ($existingDailyRows): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-light fw-semibold">
        <i class="fas fa-layer-group me-2"></i>Záznamy pro tento den
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($existingDailyRows as $row): ?>
            <div class="col-12 col-lg-6">
                <div class="border rounded-4 p-3 h-100">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <div class="fw-bold"><?= h(formatDateTime((string)($row['created_at'] ?? ''))) ?></div>
                        <span class="badge bg-<?= h(mycoachBuildRecommendation(isset($row['feeling_score']) ? (int)$row['feeling_score'] : null, $row)['variant']) ?>"><?= h((string)($row['workout_type'] ?? 'záznam')) ?></span>
                    </div>
                    <div class="small text-muted mt-2">
                        <?php if (!empty($row['training_duration_minutes'])): ?>Délka: <?= (int)$row['training_duration_minutes'] ?> min<?php endif; ?>
                        <?php if (!empty($row['rpe_score'])): ?> · RPE: <?= (int)$row['rpe_score'] ?><?php endif; ?>
                        <?php if (!empty($row['energy_score'])): ?> · Energie: <?= (int)$row['energy_score'] ?>/10<?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('workoutTypeSelect');
    const panels = document.querySelectorAll('[data-workout-type-panel]');

    function syncPanels() {
        const activeType = typeSelect ? typeSelect.value : '';
        panels.forEach(function (panel) {
            panel.classList.toggle('d-none', panel.dataset.workoutTypePanel !== activeType);
        });
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', syncPanels);
        syncPanels();
    }
});
</script>

<?php renderAthleteFooter();
