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
$questionnaireError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $questionnaireError = 'Neplatný bezpečnostní token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_goal') {
            $goalType = trim((string)($_POST['goal_type'] ?? ''));
            $customGoalName = trim((string)($_POST['custom_goal_name'] ?? ''));
            $targetDate = trim((string)($_POST['target_date'] ?? ''));

            if (!isset($goalOptions[$goalType])) {
                $questionnaireError = 'Vyberte platný cíl.';
            } elseif ($goalType === 'custom' && $customGoalName === '') {
                $questionnaireError = 'U vlastního cíle zadejte jeho název.';
            } elseif ($targetDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
                $questionnaireError = 'Zadejte platné datum cíle.';
            } elseif (mycoachStoreActiveGoal($pdo, $myCoachUserId, $goalType, $customGoalName, $targetDate !== '' ? $targetDate : null)) {
                flash('success', 'Aktivní cíl pro MyCoach byl uložen.');
                redirect(BASE_URL . '/athlete_mycoach.php');
            } else {
                $questionnaireError = 'Cíl se nepodařilo uložit.';
            }
        }

        if ($action === 'save_questionnaire') {
            $activeGoal = mycoachFetchActiveGoal($pdo, $myCoachUserId);
            if (!$activeGoal) {
                $questionnaireError = 'Nejdřív nastav aktivní cíl.';
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

$activeGoal = mycoachFetchActiveGoal($pdo, $myCoachUserId);
$latestQuestionnaire = mycoachFetchLatestQuestionnaire($pdo, $myCoachUserId);
$questionnaireCompleted = $latestQuestionnaire && !empty($latestQuestionnaire['completed_at']);
$timeline = mycoachFetchDailyTimeline($pdo, $myCoachUserId, 45);
$latestTrainerSessionPreview = mycoachFetchLatestTrainerSessionPreview($pdo, $athleteId, 10);
$questionnaireCount = 0;
$planCount = 0;
$workoutCount = 0;
try {
    $planStmt = $pdo->prepare('SELECT COUNT(*) FROM mycoach_training_plans WHERE user_id = ? AND (status IS NULL OR LOWER(status) NOT IN ("completed", "archived", "done", "finished", "cancelled", "canceled"))');
    $planStmt->execute([$myCoachUserId]);
    $planCount = (int)$planStmt->fetchColumn();

    $workoutStmt = $pdo->prepare('SELECT COUNT(*) FROM mycoach_workouts WHERE user_id = ?');
    $workoutStmt->execute([$myCoachUserId]);
    $workoutCount = (int)$workoutStmt->fetchColumn();

    $questionnaireStmt = $pdo->prepare('SELECT COUNT(*) FROM mycoach_questionnaires WHERE user_id = ?');
    $questionnaireStmt->execute([$myCoachUserId]);
    $questionnaireCount = (int)$questionnaireStmt->fetchColumn();
} catch (Throwable $e) {
    $planCount = 0;
    $workoutCount = 0;
    $questionnaireCount = 0;
}

$questionnaireOptions = mycoachQuestionnaireOptions();
$sportTypesSelected = [];
$healthLimitsSelected = [];
$equipmentSelected = [];
if ($latestQuestionnaire) {
    $sportTypesSelected = json_decode((string)($latestQuestionnaire['sport_types_json'] ?? '[]'), true) ?: [];
    $healthLimitsSelected = json_decode((string)($latestQuestionnaire['health_limits_json'] ?? '[]'), true) ?: [];
    $equipmentSelected = json_decode((string)($latestQuestionnaire['equipment_json'] ?? '[]'), true) ?: [];
}

$latestReadinessMetric = mycoachFetchLatestMetricValue($pdo, $myCoachUserId, 'readiness_score');
$latestDailyEntry = !empty($timeline) ? end($timeline) : null;
$readinessPreviewEntry = $latestDailyEntry ?: null;
$readinessPreviewDate = $latestDailyEntry['entry_date'] ?? null;
if (!$readinessPreviewEntry && $latestTrainerSessionPreview) {
    $readinessPreviewEntry = [
        'entry_date' => $latestTrainerSessionPreview['entry_date'] ?? date('Y-m-d'),
        'training_duration_minutes' => $latestTrainerSessionPreview['training_duration_minutes'] ?? null,
        'rpe_score' => 6,
        'workout_type' => 'strength',
        'workout_name' => $latestTrainerSessionPreview['workout_name'] ?? 'Trénink od trenéra',
    ];
    $readinessPreviewDate = $readinessPreviewEntry['entry_date'];
}

$latestReadinessScore = $readinessPreviewEntry
    ? mycoachCalculateReadinessScore($readinessPreviewEntry)
    : ($latestReadinessMetric && isset($latestReadinessMetric['metric_value']) ? (int)round((float)$latestReadinessMetric['metric_value']) : null);
$latestAcwr = mycoachCalculateAcwr($timeline);
$achievementBadges = mycoachBuildAchievementBadges($timeline, $latestReadinessScore, $latestAcwr['ratio'] ?? null);
$athleteInsight = mycoachBuildInsight($timeline, $activeGoal, $latestReadinessScore, $latestAcwr);
$latestRecommendation = mycoachBuildRecommendation($latestReadinessScore, $readinessPreviewEntry, $latestQuestionnaire, $timeline, $activeGoal);
$readinessGuidance = $latestReadinessScore !== null ? mycoachBuildReadinessGuidance($latestReadinessScore, $readinessPreviewEntry, $latestQuestionnaire, $timeline, $activeGoal) : null;
$trainingAssessment = mycoachBuildTrainingAssessment($timeline, $readinessPreviewEntry, $activeGoal, $latestReadinessScore);
$trainingAcwrRatio = $trainingAssessment['acwr']['ratio'] ?? null;
$recommendationSourceText = $readinessPreviewDate ? 'pro ' . formatDate((string)$readinessPreviewDate) : 'bez dat';

renderAthleteHeader('MyCoach dotazník', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-1"><i class="fas fa-clipboard-list me-2 text-warning"></i>Úvodní dotazník</h2>
        <div class="text-muted">MyCoach pro sportovce</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm fw-semibold">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <a href="<?= BASE_URL ?>/athlete_mycoach_graphs.php" class="btn btn-outline-secondary btn-sm fw-semibold">
            <i class="fas fa-chart-line me-1"></i>Grafy
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

<ul class="nav nav-pills mb-4 flex-wrap gap-2">
    <li class="nav-item"><span class="nav-link active"><i class="fas fa-house me-1"></i>Přehled</span></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/athlete_mycoach_questionnaire.php"><i class="fas fa-clipboard-list me-1"></i>Dotazník <?php if (!$questionnaireCompleted): ?><span class="badge rounded-pill bg-danger ms-1" style="font-size:.65rem">!</span><?php endif; ?></a></li>
    <li class="nav-item"><a class="nav-link <?= $latestQuestionnaire ? '' : 'disabled' ?>" href="<?= $latestQuestionnaire ? BASE_URL . '/athlete_mycoach_daily.php' : '#' ?>" aria-disabled="<?= $latestQuestionnaire ? 'false' : 'true' ?>"><i class="fas fa-dumbbell me-1"></i>Denní záznam</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/athlete_mycoach_graphs.php"><i class="fas fa-chart-line me-1"></i>Grafy</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <p class="mb-0">Přehled ukazuje stav účtu, aktivní cíl, připravenost a trend. Dotazník je jen v samostatné kartě.</p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 border-start border-4 border-<?= h($athleteInsight['variant']) ?>">
    <div class="card-body">
        <div class="text-muted small text-uppercase fw-bold">AI insight</div>
        <div class="fs-5 fw-bold"><?= h($athleteInsight['title']) ?></div>
        <div class="text-muted mb-2"><?= h($athleteInsight['text']) ?></div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="text-muted small"><?= h($athleteInsight['summary']) ?></div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="<?= BASE_URL ?>/athlete_mycoach_daily.php" class="btn btn-sm btn-success fw-semibold <?= $latestQuestionnaire ? '' : 'disabled' ?>" aria-disabled="<?= $latestQuestionnaire ? 'false' : 'true' ?>">
                    <i class="fas fa-dumbbell me-1"></i>Denní záznam
                </a>
                <a href="<?= BASE_URL ?>/athlete_mycoach_graphs.php" class="btn btn-sm btn-outline-secondary fw-semibold">
                    <i class="fas fa-chart-line me-1"></i>Grafy
                </a>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 border-start border-4 border-<?= h($trainingAssessment['status']['variant']) ?>">
    <div class="card-body">
        <div class="text-muted small text-uppercase fw-bold">Tréninkové hodnocení</div>
        <div class="fs-5 fw-bold"><?= h($trainingAssessment['status']['label']) ?></div>
        <div class="text-muted mb-2"><?= h($trainingAssessment['status']['text']) ?></div>
        <div class="small text-muted">ACWR <?= $trainingAcwrRatio !== null ? h(number_format((float)$trainingAcwrRatio, 2, ',', '')) : 'bez dat' ?> · dní bez regenerace <?= (int)$trainingAssessment['days_without_recovery'] ?></div>
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
                <div class="text-muted small text-uppercase fw-bold">Readiness</div>
                <div class="fs-3 fw-bold text-success"><?= $latestReadinessScore !== null ? (int)$latestReadinessScore . ' / 100' : 'zatím bez dat' ?></div>
                <div class="text-muted small"><?= $readinessPreviewDate ? 'počítáno pro ' . h(formatDate((string)$readinessPreviewDate)) : 'Vyplň denní záznam a hodnota se objeví zde.' ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-8">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-<?= h($latestRecommendation['variant']) ?>">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Doporučení (<?= h($recommendationSourceText) ?>)</div>
                <div class="fs-5 fw-bold"><?= h($latestRecommendation['title']) ?></div>
                <div class="text-muted"><?= h($latestRecommendation['text']) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($readinessGuidance): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-4 border-<?= h($readinessGuidance['variant']) ?>">
    <div class="card-body">
        <div class="text-muted small text-uppercase fw-bold">Co readiness znamená</div>
        <div class="fs-5 fw-bold"><?= h($readinessGuidance['label']) ?></div>
        <div class="text-muted mb-2"><?= h($readinessGuidance['detail']) ?></div>
        <div class="small text-muted">Dnešní režim: <?= h($readinessGuidance['trainability']) ?> · doporučený strop <?= (int)$readinessGuidance['session_cap_minutes'] ?> min · <?= h($readinessGuidance['intensity_hint']) ?></div>
        <?php if (!empty($readinessGuidance['reasons'])): ?>
        <div class="small text-muted mt-1">Zohledněno: <?= h(implode(' · ', $readinessGuidance['reasons'])) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">ACWR</div>
                <?php if ($latestAcwr): ?>
                <div class="fs-3 fw-bold text-<?= h($latestAcwr['variant']) ?>"><?= h(number_format((float)$latestAcwr['ratio'], 2, ',', '')) ?></div>
                <div class="text-muted small"><?= h($latestAcwr['label']) ?> · akutní <?= (int)$latestAcwr['acute_minutes'] ?> min / chronická <?= (int)$latestAcwr['chronic_weekly_minutes'] ?> min</div>
                <?php else: ?>
                <div class="fs-5 fw-bold text-muted">zatím bez dat</div>
                <div class="text-muted small">ACWR se zobrazí po několika denních záznamech.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold mb-2">Odznaky</div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($achievementBadges): ?>
                        <?php foreach ($achievementBadges as $badge): ?>
                        <span class="badge bg-<?= h($badge['variant']) ?> px-3 py-2">
                            <i class="fas <?= h($badge['icon']) ?> me-1"></i><?= h($badge['title']) ?>
                        </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <span class="text-muted">Zatím žádné odznaky. Začni vyplňovat denní záznamy.</span>
                    <?php endif; ?>
                </div>
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
<?php if (!$activeGoal): ?>
<div class="alert alert-warning shadow-sm">Nejdřív nastav aktivní cíl, pak dotazník uložíš a MyCoach vytvoří první plán.</div>
<?php elseif ($latestQuestionnaire): ?>
<div class="alert alert-success shadow-sm">Dotazník je hotový. Další práce už běží přes denní záznam a grafy.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="text-muted small text-uppercase fw-bold">Dotazník</div>
                <h3 class="h5 mb-2">Samostatná karta pro onboarding</h3>
                <p class="mb-0 text-muted">Tady už není žádný formulář. Přehled slouží jako stavová obrazovka a rychlý vstup do dotazníku, denního záznamu a grafů.</p>
            </div>
            <div class="text-end">
                <?php if ($questionnaireCompleted): ?>
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle px-3 py-2">Dotazník vyplněn</span>
                <?php else: ?>
                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle px-3 py-2">Dotazník chybí</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <a href="<?= BASE_URL ?>/athlete_mycoach_questionnaire.php" class="btn btn-warning fw-semibold">
                <i class="fas fa-clipboard-list me-1"></i>Otevřít dotazník
            </a>
            <?php if ($latestQuestionnaire): ?>
            <a href="<?= BASE_URL ?>/athlete_mycoach_daily.php" class="btn btn-outline-success fw-semibold">
                <i class="fas fa-dumbbell me-1"></i>Pokračovat v denním záznamu
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php renderAthleteFooter();
