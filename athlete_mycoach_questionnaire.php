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
$goalOptions = mycoachGoalOptions();
$questionnaireOptions = mycoachQuestionnaireOptions();
$profileSnapshot = mycoachFetchAthleteProfileSnapshot($pdo, $athleteId);
$activeGoal = mycoachFetchActiveGoal($pdo, $myCoachUserId);
$latestQuestionnaire = mycoachFetchLatestQuestionnaire($pdo, $myCoachUserId);
$selectedGoalType = trim((string)($_POST['goal_type'] ?? ($activeGoal['goal_type'] ?? ($latestQuestionnaire['goal_snapshot_type'] ?? 'fitness'))));
$selectedGoalName = trim((string)($_POST['custom_goal_name'] ?? ($activeGoal['custom_goal_name'] ?? '')));
$selectedTargetDate = trim((string)($_POST['target_date'] ?? ($activeGoal['target_date'] ?? '')));
$agePrefill = $latestQuestionnaire['age_years'] ?? ($profileSnapshot['age_years'] ?? '');
$genderPrefill = (string)($latestQuestionnaire['gender'] ?? ($profileSnapshot['gender'] ?? ''));
$weightPrefill = $latestQuestionnaire['weight_kg'] ?? ($profileSnapshot['current_weight_kg'] ?? '');
$trainerNamePrefill = trim((string)($latestQuestionnaire['trainer_name'] ?? ($profileSnapshot['coach_name'] ?? ($profileSnapshot['coach_username'] ?? ''))));
$hasTrainerPrefill = $latestQuestionnaire ? (int)($latestQuestionnaire['has_trainer'] ?? 1) : 1;
$questionnaireDraftKey = 'mycoach_questionnaire_draft_athlete_' . $myCoachUserId;
$questionnaireError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $questionnaireError = 'Neplatný bezpečnostní token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_questionnaire') {
            $selectedGoalType = trim((string)($_POST['goal_type'] ?? $selectedGoalType));
            $selectedGoalName = trim((string)($_POST['custom_goal_name'] ?? $selectedGoalName));
            $selectedTargetDate = trim((string)($_POST['target_date'] ?? $selectedTargetDate));

            if (!isset($goalOptions[$selectedGoalType])) {
                $questionnaireError = 'Vyberte účel plánu.';
            } elseif ($selectedGoalType === 'custom' && $selectedGoalName === '') {
                $questionnaireError = 'U vlastního cíle zadejte jeho název.';
            } elseif ($selectedTargetDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedTargetDate)) {
                $questionnaireError = 'Zadejte platné datum cíle.';
            } else {
                $storeGoalOk = mycoachStoreActiveGoal($pdo, $myCoachUserId, $selectedGoalType, $selectedGoalName !== '' ? $selectedGoalName : null, $selectedTargetDate !== '' ? $selectedTargetDate : null);
                if (!$storeGoalOk) {
                    $questionnaireError = 'Účel plánu se nepodařilo uložit.';
                } else {
                    $activeGoal = mycoachFetchActiveGoal($pdo, $myCoachUserId);
                    if (!$activeGoal) {
                        $questionnaireError = 'Účel plánu se nepodařilo načíst.';
                    } else {
                $payload = [
                    'age_years' => $_POST['age_years'] ?? null,
                    'gender' => $_POST['gender'] ?? null,
                    'height_cm' => $_POST['height_cm'] ?? null,
                    'weight_kg' => $_POST['weight_kg'] ?? null,
                    'performance_level' => $_POST['performance_level'] ?? null,
                    'sport_years' => $_POST['sport_years'] ?? null,
                    'sport_frequency_per_week' => $_POST['sport_frequency_per_week'] ?? null,
                    'sport_types' => $_POST['sport_types'] ?? [],
                    'sports_text' => $_POST['sports_text'] ?? null,
                    'health_limits' => $_POST['health_limits'] ?? [],
                    'weekly_training_hours_target' => $_POST['weekly_training_hours_target'] ?? null,
                    'rest_days_per_week' => $_POST['rest_days_per_week'] ?? null,
                    'equipment' => $_POST['equipment'] ?? [],
                    'has_trainer' => isset($_POST['has_trainer']) ? 1 : 0,
                    'trainer_name' => $_POST['trainer_name'] ?? null,
                    'goal_snapshot_type' => (string)$activeGoal['goal_type'],
                    'goal_snapshot_name' => mycoachGoalLabel((string)$activeGoal['goal_type'], (string)($activeGoal['custom_goal_name'] ?? '')),
                    'hyrox_registered' => isset($_POST['hyrox_registered']) ? 1 : 0,
                    'hyrox_race_date' => $_POST['hyrox_race_date'] ?? null,
                    'hyrox_race_name' => $_POST['hyrox_race_name'] ?? null,
                    'hyrox_race_place' => $_POST['hyrox_race_place'] ?? null,
                    'hyrox_category' => $_POST['hyrox_category'] ?? null,
                    'hyrox_gender_category' => $_POST['hyrox_gender_category'] ?? null,
                ];

                if (!mycoachStoreQuestionnaire($pdo, $myCoachUserId, $payload)) {
                    $questionnaireError = 'Dotazník se nepodařilo uložit.';
                } else {
                    $goalId = (int)($activeGoal['id'] ?? 0);
                    $savedPlan = mycoachEnsureStarterPlan($pdo, $myCoachUserId, $payload, $activeGoal, 'athlete');
                    $savedRace = mycoachStoreHyroxRace($pdo, $myCoachUserId, $goalId > 0 ? $goalId : null, $payload);
                    if ($savedPlan && $savedRace) {
                        flash('success', 'Dotazník byl uložen a první plán byl připraven.');
                        redirect(BASE_URL . '/athlete_mycoach.php');
                    }

                    $questionnaireError = 'Dotazník byl uložen, ale plán se nepodařilo založit.';
                }
                    }
                }
            }
        }
    }
}

$questionnaireCount = 0;
try {
    $questionnaireStmt = $pdo->prepare('SELECT COUNT(*) FROM mycoach_questionnaires WHERE user_id = ?');
    $questionnaireStmt->execute([$myCoachUserId]);
    $questionnaireCount = (int)$questionnaireStmt->fetchColumn();
} catch (Throwable $e) {
    $questionnaireCount = 0;
}

$sportTypesSelected = [];
$healthLimitsSelected = [];
$equipmentSelected = [];
if ($latestQuestionnaire) {
    $sportTypesSelected = json_decode((string)($latestQuestionnaire['sport_types_json'] ?? '[]'), true) ?: [];
    $healthLimitsSelected = json_decode((string)($latestQuestionnaire['health_limits_json'] ?? '[]'), true) ?: [];
    $equipmentSelected = json_decode((string)($latestQuestionnaire['equipment_json'] ?? '[]'), true) ?: [];
}

renderAthleteHeader('MyCoach dotazník', false, true);
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-theme.css?v=20260804">
<script>document.body.classList.add('mycoach-theme', 'mycoach-theme--athlete');</script>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 mc-topbar">
    <div>
        <h2 class="mb-1"><i class="fas fa-clipboard-list me-2 text-warning"></i>Úvodní dotazník</h2>
        <div class="text-muted">MyCoach pro sportovce</div>
    </div>
    <div class="d-flex gap-2 flex-wrap mc-actions">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm fw-semibold">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <?php if ($latestQuestionnaire): ?>
        <a href="<?= BASE_URL ?>/athlete_mycoach_daily.php" class="btn btn-success btn-sm fw-semibold">
            <i class="fas fa-dumbbell me-1"></i>Denní záznam
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/athlete_mycoach.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Zpět do MyCoach
        </a>
    </div>
</div>

<ul class="nav nav-pills mb-4 flex-wrap gap-2 mc-pills">
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/athlete_mycoach.php"><i class="fas fa-house me-1"></i>Přehled</a></li>
    <li class="nav-item"><span class="nav-link active"><i class="fas fa-clipboard-list me-1"></i>Dotazník</span></li>
    <li class="nav-item"><a class="nav-link <?= $latestQuestionnaire ? '' : 'disabled' ?>" href="<?= $latestQuestionnaire ? BASE_URL . '/athlete_mycoach_daily.php' : '#' ?>" aria-disabled="<?= $latestQuestionnaire ? 'false' : 'true' ?>"><i class="fas fa-dumbbell me-1"></i>Denní záznam</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/athlete_mycoach_graphs.php"><i class="fas fa-chart-line me-1"></i>Grafy</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <p class="mb-0">Dotazník vyplň jednou a uloží se i jako startovní plán. Formulář si navíc průběžně drží draft, takže při výpadku internetu nepřijdeš o rozpracovaná data.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Aktivní cíl</div>
                <div class="fs-5 fw-bold"><?= $activeGoal ? h(mycoachGoalLabel((string)$activeGoal['goal_type'], (string)($activeGoal['custom_goal_name'] ?? ''))) : 'Zatím nenastaven' ?></div>
                <div class="text-muted small"><?= $activeGoal && !empty($activeGoal['target_date']) ? 'Cíl do ' . h(formatDate((string)$activeGoal['target_date'])) : 'Nejdřív nastav cíl v MyCoach.' ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Profil</div>
                <div class="fs-5 fw-bold"><?= h($athleteDisplayName !== '' ? $athleteDisplayName : 'Sportovec') ?></div>
                <div class="text-muted small">MyCoach user: #<?= (int)$myCoachUserId ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Stav</div>
                <?php if ($latestQuestionnaire && !empty($latestQuestionnaire['completed_at'])): ?>
                <div class="fs-5 fw-bold text-success"><i class="fas fa-circle-check me-1"></i>Dotazník uložen</div>
                <div class="text-muted small">Můžeš přejít na denní záznam.</div>
                <?php else: ?>
                <div class="fs-5 fw-bold text-danger"><i class="fas fa-triangle-exclamation me-1"></i>Dotazník chybí</div>
                <div class="text-muted small">Vyplň ho před začátkem.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($questionnaireError): ?>
<div class="alert alert-danger shadow-sm"><?= h($questionnaireError) ?></div>
<?php endif; ?>
<?php if ($latestQuestionnaire): ?>
<div class="alert alert-success border shadow-sm">
    Poslední dotazník je uložený od <?= h(formatDateTime((string)($latestQuestionnaire['completed_at'] ?? $latestQuestionnaire['updated_at'] ?? ''))) ?>.
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3" id="mycoachQuestionnaireForm" data-draft-key="<?= h($questionnaireDraftKey) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_questionnaire">

            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Účel plánu</label>
                <select name="goal_type" class="form-select" id="mycoachGoalType" required>
                    <?php foreach ($goalOptions as $goalKey => $goalLabel): ?>
                    <option value="<?= h($goalKey) ?>" <?= $selectedGoalType === $goalKey ? 'selected' : '' ?>><?= h($goalLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Vlastní název plánu</label>
                <input type="text" name="custom_goal_name" class="form-control" value="<?= h($selectedGoalName) ?>" placeholder="Jen pro vlastní cíl">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Datum cíle</label>
                <input type="date" name="target_date" class="form-control" value="<?= h($selectedTargetDate) ?>">
            </div>

            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Věk</label>
                <input type="number" name="age_years" class="form-control" min="5" max="100" value="<?= h((string)($latestQuestionnaire['age_years'] ?? $agePrefill ?? '')) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Pohlaví</label>
                <select name="gender" class="form-select">
                    <option value="">Vyberte</option>
                    <?php foreach ($questionnaireOptions['genders'] as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ((string)($latestQuestionnaire['gender'] ?? $genderPrefill) === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Výška (cm)</label>
                <input type="number" name="height_cm" class="form-control" min="80" max="250" value="<?= h((string)($latestQuestionnaire['height_cm'] ?? '')) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Hmotnost (kg)</label>
                <input type="number" step="0.1" name="weight_kg" class="form-control" min="20" max="300" value="<?= h((string)($latestQuestionnaire['weight_kg'] ?? $weightPrefill ?? '')) ?>">
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Výkonnost</label>
                <select name="performance_level" class="form-select">
                    <option value="">Vyberte</option>
                    <?php foreach ($questionnaireOptions['performance_levels'] as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ((string)($latestQuestionnaire['performance_level'] ?? '') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Jak dlouho sportuješ?</label>
                <input type="text" name="sport_years" class="form-control" placeholder="např. 3 roky" value="<?= h((string)($latestQuestionnaire['sport_years'] ?? '')) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Kolikrát týdně sportuješ?</label>
                <input type="number" name="sport_frequency_per_week" class="form-control" min="0" max="21" value="<?= h((string)($latestQuestionnaire['sport_frequency_per_week'] ?? '')) ?>">
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold">Jaké sporty provozuješ?</label>
                <textarea name="sports_text" class="form-control" rows="2" placeholder="Např. běh, posilovna, HYROX"><?= h((string)($latestQuestionnaire['sports_text'] ?? '')) ?></textarea>
            </div>

            <div class="col-12">
                <div class="fw-semibold mb-2">Současné aktivity</div>
                <div class="row g-2">
                    <?php foreach ($questionnaireOptions['sport_activities'] as $key => $label): ?>
                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                        <label class="form-check border rounded-3 p-2 h-100 d-flex align-items-center gap-2">
                            <input class="form-check-input m-0" type="checkbox" name="sport_types[]" value="<?= h($key) ?>" <?= in_array($key, $sportTypesSelected, true) ? 'checked' : '' ?>>
                            <span><?= h($label) ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12">
                <div class="fw-semibold mb-2">Zdravotní omezení</div>
                <div class="row g-2">
                    <?php foreach ($questionnaireOptions['health_limits'] as $key => $label): ?>
                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                        <label class="form-check border rounded-3 p-2 h-100 d-flex align-items-center gap-2">
                            <input class="form-check-input m-0" type="checkbox" name="health_limits[]" value="<?= h($key) ?>" <?= in_array($key, $healthLimitsSelected, true) ? 'checked' : '' ?>>
                            <span><?= h($label) ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Kolik času můžeš týdně trénovat?</label>
                <select name="weekly_training_hours_target" class="form-select">
                    <option value="">Vyberte</option>
                    <?php foreach ($questionnaireOptions['weekly_hours'] as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ((string)($latestQuestionnaire['weekly_training_hours_target'] ?? '') === (string)$key) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Kolik dní v týdnu chceš odpočívat?</label>
                <select name="rest_days_per_week" class="form-select">
                    <option value="">Vyberte</option>
                    <?php foreach ($questionnaireOptions['rest_days'] as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ((string)($latestQuestionnaire['rest_days_per_week'] ?? '') === (string)$key) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <div class="fw-semibold mb-2">Jaké vybavení máš?</div>
                <div class="row g-2">
                    <?php foreach ($questionnaireOptions['equipment'] as $key => $label): ?>
                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                        <label class="form-check border rounded-3 p-2 h-100 d-flex align-items-center gap-2">
                            <input class="form-check-input m-0" type="checkbox" name="equipment[]" value="<?= h($key) ?>" <?= in_array($key, $equipmentSelected, true) ? 'checked' : '' ?>>
                            <span><?= h($label) ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Máš trenéra?</label>
                <select name="has_trainer" class="form-select">
                    <option value="0" <?= (int)($latestQuestionnaire['has_trainer'] ?? $hasTrainerPrefill) === 0 ? 'selected' : '' ?>>NE</option>
                    <option value="1" <?= (int)($latestQuestionnaire['has_trainer'] ?? $hasTrainerPrefill) === 1 ? 'selected' : '' ?>>ANO</option>
                </select>
            </div>
            <div class="col-12 col-md-8">
                <label class="form-label fw-semibold">Jméno trenéra</label>
                <input type="text" name="trainer_name" class="form-control" value="<?= h((string)($latestQuestionnaire['trainer_name'] ?? $trainerNamePrefill)) ?>" placeholder="Jméno trenéra">
            </div>

            <div class="col-12">
                <div class="border rounded-4 p-3 bg-light <?= $selectedGoalType === 'hyrox' ? '' : 'opacity-75' ?>" id="mycoachHyroxPanel">
                    <div class="fw-bold mb-2"><i class="fas fa-flag-checkered me-2 text-warning"></i>HYROX doplňující otázky</div>
                    <div class="text-muted small mb-3" data-hyrox-hint <?= $selectedGoalType === 'hyrox' ? 'hidden' : '' ?>>Tato část se aktivuje po výběru cíle HYROX.</div>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Máš registraci?</label>
                            <select name="hyrox_registered" class="form-select" data-hyrox-toggle <?= $selectedGoalType === 'hyrox' ? '' : 'disabled' ?>>
                                <option value="0" <?= empty($latestQuestionnaire['hyrox_registered']) ? 'selected' : '' ?>>NE</option>
                                <option value="1" <?= !empty($latestQuestionnaire['hyrox_registered']) ? 'selected' : '' ?>>ANO</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Datum závodu</label>
                            <input type="date" name="hyrox_race_date" class="form-control" data-hyrox-toggle value="<?= h((string)($latestQuestionnaire['hyrox_race_date'] ?? '')) ?>" <?= $selectedGoalType === 'hyrox' ? '' : 'disabled' ?>>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Počet dní do závodu</label>
                            <input type="text" class="form-control" value="<?= isset($latestQuestionnaire['days_to_race']) && $latestQuestionnaire['days_to_race'] !== null ? h((string)$latestQuestionnaire['days_to_race']) : '–' ?>" readonly>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Název závodu</label>
                            <input type="text" name="hyrox_race_name" class="form-control" data-hyrox-toggle value="<?= h((string)($latestQuestionnaire['hyrox_race_name'] ?? '')) ?>" <?= $selectedGoalType === 'hyrox' ? '' : 'disabled' ?>>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Místo závodu</label>
                            <input type="text" name="hyrox_race_place" class="form-control" data-hyrox-toggle value="<?= h((string)($latestQuestionnaire['hyrox_race_place'] ?? '')) ?>" <?= $selectedGoalType === 'hyrox' ? '' : 'disabled' ?>>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Kategorie</label>
                            <select name="hyrox_category" class="form-select" data-hyrox-toggle <?= $selectedGoalType === 'hyrox' ? '' : 'disabled' ?>>
                                <option value="">Vyberte</option>
                                <?php foreach ($questionnaireOptions['hyrox_categories'] as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= ((string)($latestQuestionnaire['hyrox_category'] ?? '') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Pohlaví kategorie</label>
                            <select name="hyrox_gender_category" class="form-select" data-hyrox-toggle <?= $selectedGoalType === 'hyrox' ? '' : 'disabled' ?>>
                                <option value="">Vyberte</option>
                                <?php foreach ($questionnaireOptions['hyrox_gender_categories'] as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= ((string)($latestQuestionnaire['hyrox_gender_category'] ?? '') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-success fw-semibold" id="mycoachQuestionnaireSaveBtn">
                    <i class="fas fa-save me-1"></i>Uložit dotazník a vytvořit první plán
                </button>
                <a href="<?= BASE_URL ?>/athlete_mycoach.php" class="btn btn-outline-secondary">Zpět</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('mycoachQuestionnaireForm');
    if (!form) {
        return;
    }

    const draftKey = form.dataset.draftKey || 'mycoach_questionnaire_draft';
    const goalSelect = document.getElementById('mycoachGoalType');
    const hyroxPanel = document.getElementById('mycoachHyroxPanel');
    const saveButton = document.getElementById('mycoachQuestionnaireSaveBtn');
    const hyroxHint = hyroxPanel ? hyroxPanel.querySelector('[data-hyrox-hint]') : null;
    let draftTimer = null;

    function setHyroxState() {
        const enabled = goalSelect && goalSelect.value === 'hyrox';
        if (hyroxPanel) {
            hyroxPanel.classList.toggle('opacity-75', !enabled);
        }
        if (hyroxHint) {
            hyroxHint.hidden = enabled;
        }

        form.querySelectorAll('[data-hyrox-toggle]').forEach(function (element) {
            element.disabled = !enabled;
        });

        if (saveButton) {
            saveButton.disabled = false;
        }
    }

    function collectDraft() {
        const draft = {};
        for (const element of form.elements) {
            if (!element.name || element.name === 'csrf_token' || element.name === 'action') {
                continue;
            }

            if (element.type === 'checkbox') {
                if (!element.checked) {
                    continue;
                }
                if (!draft[element.name]) {
                    draft[element.name] = [];
                }
                draft[element.name].push(element.value);
                continue;
            }

            if (element.type === 'radio' && !element.checked) {
                continue;
            }

            draft[element.name] = element.value;
        }

        return draft;
    }

    function saveDraft() {
        try {
            localStorage.setItem(draftKey, JSON.stringify(collectDraft()));
        } catch (error) {
            // local fallback only
        }
    }

    function restoreDraft() {
        try {
            const raw = localStorage.getItem(draftKey);
            if (!raw) {
                return;
            }

            const draft = JSON.parse(raw);
            Object.entries(draft).forEach(function ([name, value]) {
                const fields = form.querySelectorAll('[name="' + name.replace(/"/g, '\\"') + '"]');
                fields.forEach(function (field) {
                    if (field.type === 'checkbox') {
                        field.checked = Array.isArray(value) ? value.includes(field.value) : value === field.value;
                    } else if (field.type !== 'hidden' && field.type !== 'submit') {
                        field.value = value;
                    }
                });
            });
        } catch (error) {
            // ignore broken draft
        }
    }

    restoreDraft();
    setHyroxState();

    if (goalSelect) {
        goalSelect.addEventListener('change', function () {
            setHyroxState();
            saveDraft();
        });
    }

    form.addEventListener('input', function () {
        window.clearTimeout(draftTimer);
        draftTimer = window.setTimeout(saveDraft, 250);
    });

    form.addEventListener('change', function () {
        window.clearTimeout(draftTimer);
        draftTimer = window.setTimeout(saveDraft, 100);
    });

    form.addEventListener('submit', function () {
        saveDraft();
    });
});
</script>

<?php renderAthleteFooter();
