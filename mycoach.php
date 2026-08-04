<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$pdo = getDB();
$coachId = (int)getCurrentCoachId();
$coach = getCurrentCoach();
$coachDisplayName = trim((string)($coach['name'] ?? ''));
if ($coachDisplayName === '') {
    $coachDisplayName = trim((string)($coach['username'] ?? ''));
}

if (!function_exists('coachMyCoachUnlocked')) {
    function coachMyCoachUnlocked(PDO $pdo, int $coachId): bool {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'mycoach_enabled'");
            if ($columnStmt === false || !$columnStmt->fetch()) {
                return false;
            }

            $valueStmt = $pdo->prepare('SELECT mycoach_enabled FROM coaches WHERE id = ? LIMIT 1');
            $valueStmt->execute([$coachId]);
            return ((int)$valueStmt->fetchColumn()) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!coachMyCoachUnlocked($pdo, $coachId)) {
    flash('warning', 'MyCoach je pro váš účet zatím uzamčený.');
    redirect(BASE_URL . '/dashboard.php');
}

$myCoachUser = mycoachResolveUser($pdo, 'coach', $coachId, 0, $coachDisplayName);
if (!$myCoachUser) {
    flash('danger', 'MyCoach profil se nepodařilo načíst.');
    redirect(BASE_URL . '/dashboard.php');
}

$myCoachUserId = (int)$myCoachUser['id'];
$goalOptions = mycoachGoalOptions();
$goalError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $goalError = 'Neplatný bezpečnostní token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_goal') {
            $goalType = trim((string)($_POST['goal_type'] ?? ''));
            $customGoalName = trim((string)($_POST['custom_goal_name'] ?? ''));
            $targetDate = trim((string)($_POST['target_date'] ?? ''));

            if (!isset($goalOptions[$goalType])) {
                $goalError = 'Vyberte platný cíl.';
            } elseif ($goalType === 'custom' && $customGoalName === '') {
                $goalError = 'U vlastního cíle zadejte jeho název.';
            } elseif ($targetDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
                $goalError = 'Zadejte platné datum cíle.';
            } elseif (mycoachStoreActiveGoal($pdo, $myCoachUserId, $goalType, $customGoalName, $targetDate !== '' ? $targetDate : null)) {
                flash('success', 'Aktivní cíl pro MyCoach byl uložen.');
                redirect(BASE_URL . '/mycoach.php');
            } else {
                $goalError = 'Cíl se nepodařilo uložit.';
            }
        }
    }
}

$activeGoal = mycoachFetchActiveGoal($pdo, $myCoachUserId);
$latestQuestionnaire = mycoachFetchLatestQuestionnaire($pdo, $myCoachUserId);
$latestReadinessMetric = mycoachFetchLatestMetricValue($pdo, $myCoachUserId, 'readiness_score');
$latestReadinessScore = $latestReadinessMetric && isset($latestReadinessMetric['metric_value'])
    ? (int)round((float)$latestReadinessMetric['metric_value'])
    : null;
$timeline = mycoachFetchDailyTimeline($pdo, $myCoachUserId, 45);
$latestAcwr = mycoachCalculateAcwr($timeline);
$achievementBadges = mycoachBuildAchievementBadges($timeline, $latestReadinessScore, $latestAcwr['ratio'] ?? null);
$coachInsight = mycoachBuildInsight($timeline, $activeGoal, $latestReadinessScore, $latestAcwr);
$latestDailyEntry = !empty($timeline) ? end($timeline) : null;
$latestRecommendation = mycoachBuildRecommendation($latestReadinessScore, $latestDailyEntry ?: null);
$trainingAssessment = mycoachBuildTrainingAssessment($timeline, $latestDailyEntry ?: null, $activeGoal, $latestReadinessScore);
$trainingAcwrRatio = $trainingAssessment['acwr']['ratio'] ?? null;
$athleteProgressRows = mycoachFetchCoachAthleteProgress($pdo, $coachId, 250);
$athletePlanRunningCount = 0;
foreach ($athleteProgressRows as $athleteProgressRow) {
    if ((int)($athleteProgressRow['active_plan_count'] ?? 0) > 0) {
        $athletePlanRunningCount++;
    }
}
$planCount = 0;
$workoutCount = 0;
$questionnaireCount = 0;
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

renderHeader('MyCoach', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-1"><i class="fas fa-brain me-2 text-warning"></i>MyCoach</h2>
        <div class="text-muted">Osobní tréninkový asistent</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm fw-semibold">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <a href="<?= BASE_URL ?>/mycoach_graphs.php" class="btn btn-outline-light btn-sm fw-semibold">
            <i class="fas fa-chart-line me-1"></i>Grafy
        </a>
        <a href="<?= BASE_URL ?>/mycoach_athletes.php" class="btn btn-outline-light btn-sm fw-semibold">
            <i class="fas fa-users me-1"></i>Sportovci
        </a>
        <a href="<?= BASE_URL ?>/mycoach_questionnaire.php" class="btn btn-primary btn-sm fw-semibold">
            <i class="fas fa-clipboard-list me-1"></i>Úvodní dotazník
        </a>
    </div>
</div>

<ul class="nav nav-pills mb-4 flex-wrap gap-2">
    <li class="nav-item"><span class="nav-link active"><i class="fas fa-house me-1"></i>Přehled</span></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_questionnaire.php"><i class="fas fa-clipboard-list me-1"></i>Dotazník <?php if (!$latestQuestionnaire || empty($latestQuestionnaire['completed_at'])): ?><span class="badge rounded-pill bg-danger ms-1" style="font-size:.65rem">!</span><?php endif; ?></a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_graphs.php"><i class="fas fa-chart-line me-1"></i>Grafy</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_athletes.php"><i class="fas fa-users me-1"></i>Sportovci</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <p class="mb-2 fw-semibold">MyCoach už sleduje cíl, připravenost, zátěž i denní trend a umí z toho navrhnout další krok.</p>
        <p class="text-muted mb-0">Každý trenér i sportovec má v MyCoach oddělená data. Podpora více souběžných příprav a tréninků je připravená v databázi.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Aktivní cíl</div>
                <div class="fs-5 fw-bold"><?= $activeGoal ? h(mycoachGoalLabel((string)$activeGoal['goal_type'], (string)($activeGoal['custom_goal_name'] ?? ''))) : 'Zatím nenastaven' ?></div>
                <div class="text-muted small"><?= $activeGoal && !empty($activeGoal['target_date']) ? 'Cíl do ' . h(formatDate((string)$activeGoal['target_date'])) : 'Založte první cíl a MyCoach začne sledovat plán.' ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Plány</div>
                <div class="fs-3 fw-bold text-primary"><?= (int)$planCount ?></div>
                <div class="text-muted small">uložených plánů</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Aktivita</div>
                <div class="fs-5 fw-bold"><?= (int)$workoutCount ?> tréninků</div>
                <div class="text-muted small">denní a plánová aktivita v MyCoach</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Readiness</div>
                <div class="fs-3 fw-bold text-success"><?= $latestReadinessScore !== null ? (int)$latestReadinessScore . ' / 100' : 'zatím bez dat' ?></div>
                <div class="text-muted small"><?= h($latestRecommendation['title']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Sportovci v MyCoach</div>
                <div class="fs-3 fw-bold text-success"><?= (int)$athletePlanRunningCount ?></div>
                <div class="text-muted small">běžících plánů z <?= (int)count($athleteProgressRows) ?> sportovců</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4 border-start border-4 border-<?= h($latestRecommendation['variant']) ?>">
    <div class="card-body">
        <div class="text-muted small text-uppercase fw-bold">Dnešní doporučení</div>
        <div class="fs-5 fw-bold"><?= h($latestRecommendation['title']) ?></div>
        <div class="text-muted"><?= h($latestRecommendation['text']) ?></div>
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

<div class="card border-0 shadow-sm mb-4 border-start border-4 border-<?= h($coachInsight['variant']) ?>">
    <div class="card-body">
        <div class="text-muted small text-uppercase fw-bold">AI insight</div>
        <div class="fs-5 fw-bold"><?= h($coachInsight['title']) ?></div>
        <div class="text-muted mb-2"><?= h($coachInsight['text']) ?></div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="text-muted small"><?= h($coachInsight['summary']) ?></div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="<?= BASE_URL ?>/mycoach_questionnaire.php" class="btn btn-sm btn-outline-primary fw-semibold">
                    <i class="fas fa-clipboard-list me-1"></i>Dotazník
                </a>
                <a href="<?= BASE_URL ?>/mycoach_graphs.php" class="btn btn-sm btn-outline-secondary fw-semibold">
                    <i class="fas fa-chart-line me-1"></i>Grafy
                </a>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="text-muted small text-uppercase fw-bold mb-1">Sportovci v MyCoach</div>
            <div class="fw-semibold">Detailní přehled sportovců je přesunutý do samostatné karty.</div>
            <div class="small text-muted">Uvidíš tam status plánů, readiness, poslední denní záznam i rychlé odkazy.</div>
        </div>
        <a href="<?= BASE_URL ?>/mycoach_athletes.php" class="btn btn-primary fw-semibold">
            <i class="fas fa-users me-1"></i>Otevřít kartu Sportovci
        </a>
    </div>
</div>

<?php if ($goalError): ?>
<div class="alert alert-danger shadow-sm"><?= h($goalError) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-primary text-white fw-bold">
        <i class="fas fa-bullseye me-2"></i>Nastavit aktivní cíl
    </div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_goal">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Cíl</label>
                <select name="goal_type" class="form-select" required>
                    <?php foreach ($goalOptions as $goalKey => $goalLabel): ?>
                    <option value="<?= h($goalKey) ?>" <?= ($activeGoal && (string)$activeGoal['goal_type'] === $goalKey) ? 'selected' : '' ?>><?= h($goalLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Datum cíle</label>
                <input type="date" name="target_date" class="form-control" value="<?= h((string)($activeGoal['target_date'] ?? '')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Vlastní název cíle</label>
                <input type="text" name="custom_goal_name" class="form-control" value="<?= h((string)($activeGoal['custom_goal_name'] ?? '')) ?>" placeholder="Vyplňte jen pro vlastní cíl">
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary fw-semibold">
                    <i class="fas fa-save me-1"></i>Uložit cíl
                </button>
                <span class="text-muted small align-self-center">Aktivní cíl lze kdykoliv změnit. Předchozí se uloží jako neaktivní.</span>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-info border-0 shadow-sm">
    <strong>Stav modulu:</strong> Aktivní je profil, cíl, dotazník, denní log, trendový insight, grafy i export. Z dotazníku se po uložení vytváří první plán.
</div>

<?php renderFooter();
