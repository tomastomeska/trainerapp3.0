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
$goalInfo = null;

$goalPrefsReady = false;
try {
    $goalPrefsReady = (bool)$pdo->query("SHOW TABLES LIKE 'mycoach_goal_preferences'")->fetch();
} catch (Throwable $e) {
    $goalPrefsReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $goalError = 'Neplatný bezpečnostní token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_goal' || $action === 'add_goal') {
            $goalType = trim((string)($_POST['goal_type'] ?? ''));
            $customGoalName = trim((string)($_POST['custom_goal_name'] ?? ''));
            $targetDate = trim((string)($_POST['target_date'] ?? ''));

            if (!isset($goalOptions[$goalType])) {
                $goalError = 'Vyberte platný cíl.';
            } elseif ($goalType === 'custom' && $customGoalName === '') {
                $goalError = 'U vlastního cíle zadejte jeho název.';
            } elseif ($targetDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
                $goalError = 'Zadejte platné datum cíle.';
            } else {
                try {
                    $existingSimilarGoal = mycoachFindSimilarGoal(
                        $pdo,
                        $myCoachUserId,
                        $goalType,
                        $goalType === 'custom' ? $customGoalName : null,
                        $targetDate !== '' ? $targetDate : null,
                        true
                    );
                    if ($existingSimilarGoal) {
                        flash('info', 'Stejný aktivní cíl už existuje, nový duplicitní cíl nebyl přidán.');
                        redirect(BASE_URL . '/mycoach.php');
                    }

                    $insertGoalStmt = $pdo->prepare(
                        'INSERT INTO mycoach_goals (user_id, goal_type, custom_goal_name, target_date, is_active, started_at)
                         VALUES (?, ?, ?, ?, 1, NOW())'
                    );
                    $insertGoalStmt->execute([
                        $myCoachUserId,
                        $goalType,
                        $goalType === 'custom' ? $customGoalName : null,
                        $targetDate !== '' ? $targetDate : null,
                    ]);
                    $newGoalId = (int)$pdo->lastInsertId();

                    if ($goalPrefsReady && $newGoalId > 0) {
                        $setPrimaryStmt = $pdo->prepare(
                            'INSERT INTO mycoach_goal_preferences (user_id, primary_goal_id)
                             VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE primary_goal_id = VALUES(primary_goal_id), updated_at = NOW()'
                        );
                        $setPrimaryStmt->execute([$myCoachUserId, $newGoalId]);
                    }

                    flash('success', 'Nový aktivní cíl byl přidán.');
                    redirect(BASE_URL . '/mycoach.php');
                } catch (Throwable $e) {
                    $goalError = 'Cíl se nepodařilo uložit.';
                }
            }
        }

        if ($action === 'extend_goal') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $targetDate = trim((string)($_POST['target_date'] ?? ''));
            if ($goalId <= 0) {
                $goalError = 'Nebyl vybrán cíl k prodloužení.';
            } elseif ($targetDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
                $goalError = 'Zadejte platné nové datum cíle.';
            } else {
                try {
                    $extendGoalStmt = $pdo->prepare(
                        'UPDATE mycoach_goals
                         SET target_date = ?, updated_at = NOW()
                         WHERE id = ? AND user_id = ? AND is_active = 1'
                    );
                    $extendGoalStmt->execute([$targetDate, $goalId, $myCoachUserId]);
                    if ((int)$extendGoalStmt->rowCount() > 0) {
                        flash('success', 'Cíl byl prodloužen do ' . formatDate($targetDate) . '.');
                        redirect(BASE_URL . '/mycoach.php');
                    }
                    $goalError = 'Cíl se nepodařilo prodloužit.';
                } catch (Throwable $e) {
                    $goalError = 'Cíl se nepodařilo prodloužit.';
                }
            }
        }

        if ($action === 'end_goal_early') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            if ($goalId <= 0) {
                $goalError = 'Nebyl vybrán cíl k ukončení.';
            } else {
                try {
                    $endGoalStmt = $pdo->prepare(
                        'UPDATE mycoach_goals
                         SET is_active = 0, ended_at = NOW(), updated_at = NOW()
                         WHERE id = ? AND user_id = ? AND is_active = 1'
                    );
                    $endGoalStmt->execute([$goalId, $myCoachUserId]);
                    if ((int)$endGoalStmt->rowCount() > 0) {
                        flash('success', 'Cíl byl ukončen a přesunut do archivu.');
                        redirect(BASE_URL . '/mycoach.php');
                    }
                    $goalError = 'Cíl se nepodařilo ukončit.';
                } catch (Throwable $e) {
                    $goalError = 'Cíl se nepodařilo ukončit.';
                }
            }
        }

        if ($action === 'set_primary_goal') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            if (!$goalPrefsReady) {
                $goalError = 'Primární cíl teď nelze nastavit.';
            } elseif ($goalId <= 0) {
                $goalError = 'Nebyl vybrán cíl pro nastavení primárního.';
            } else {
                try {
                    $goalCheckStmt = $pdo->prepare('SELECT id FROM mycoach_goals WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1');
                    $goalCheckStmt->execute([$goalId, $myCoachUserId]);
                    if (!$goalCheckStmt->fetchColumn()) {
                        $goalError = 'Vybraný cíl není aktivní.';
                    } else {
                        $setPrimaryStmt = $pdo->prepare(
                            'INSERT INTO mycoach_goal_preferences (user_id, primary_goal_id)
                             VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE primary_goal_id = VALUES(primary_goal_id), updated_at = NOW()'
                        );
                        $setPrimaryStmt->execute([$myCoachUserId, $goalId]);
                        flash('success', 'Primární cíl byl nastaven.');
                        redirect(BASE_URL . '/mycoach.php');
                    }
                } catch (Throwable $e) {
                    $goalError = 'Primární cíl se nepodařilo uložit.';
                }
            }
        }
    }
}

$activeGoals = [];
$archivedGoals = [];
try {
    $activeGoalsStmt = $pdo->prepare(
        'SELECT id, goal_type, custom_goal_name, target_date, started_at, ended_at, created_at, updated_at
         FROM mycoach_goals
         WHERE user_id = ? AND is_active = 1
         ORDER BY
            CASE WHEN target_date IS NULL THEN 1 ELSE 0 END ASC,
            target_date ASC,
            started_at ASC,
            id DESC'
    );
    $activeGoalsStmt->execute([$myCoachUserId]);
    $activeGoals = $activeGoalsStmt->fetchAll() ?: [];

    $archivedGoalsStmt = $pdo->prepare(
        'SELECT id, goal_type, custom_goal_name, target_date, started_at, ended_at, created_at, updated_at
         FROM mycoach_goals
         WHERE user_id = ? AND is_active = 0
         ORDER BY ended_at DESC, updated_at DESC, id DESC
         LIMIT 8'
    );
    $archivedGoalsStmt->execute([$myCoachUserId]);
    $archivedGoals = $archivedGoalsStmt->fetchAll() ?: [];

    $activeGoals = mycoachCollapseGoalsForDisplay($activeGoals);
    $archivedGoals = mycoachCollapseGoalsForDisplay($archivedGoals);
} catch (Throwable $e) {
    $activeGoals = [];
    $archivedGoals = [];
}

$primaryGoalId = 0;
if ($goalPrefsReady) {
    try {
        $primaryStmt = $pdo->prepare('SELECT primary_goal_id FROM mycoach_goal_preferences WHERE user_id = ? LIMIT 1');
        $primaryStmt->execute([$myCoachUserId]);
        $primaryGoalId = (int)$primaryStmt->fetchColumn();
    } catch (Throwable $e) {
        $primaryGoalId = 0;
    }
}

$activeGoal = null;
foreach ($activeGoals as $goalRow) {
    if ((int)$goalRow['id'] === $primaryGoalId) {
        $activeGoal = $goalRow;
        break;
    }
}
if (!$activeGoal && !empty($activeGoals)) {
    $activeGoal = $activeGoals[0];
    $primaryGoalId = (int)$activeGoal['id'];
}

$activeGoalCount = count($activeGoals);
$latestQuestionnaire = mycoachFetchLatestQuestionnaire($pdo, $myCoachUserId);
$questionnaireHealthProfile = mycoachQuestionnaireHealthProfile($latestQuestionnaire);
$latestReadinessMetric = mycoachFetchLatestMetricValue($pdo, $myCoachUserId, 'readiness_score');
$latestReadinessScore = $latestReadinessMetric && isset($latestReadinessMetric['metric_value'])
    ? (int)round((float)$latestReadinessMetric['metric_value'])
    : null;
$timeline = mycoachFetchDailyTimeline($pdo, $myCoachUserId, 45);
$latestAcwr = mycoachCalculateAcwr($timeline);
$achievementBadges = mycoachBuildAchievementBadges($timeline, $latestReadinessScore, $latestAcwr['ratio'] ?? null);
$coachInsight = mycoachBuildInsight($timeline, $activeGoal, $latestReadinessScore, $latestAcwr);
$latestDailyEntry = !empty($timeline) ? end($timeline) : null;
$latestRecommendation = mycoachBuildRecommendation($latestReadinessScore, $latestDailyEntry ?: null, $latestQuestionnaire, $timeline, $activeGoal);
$readinessGuidance = $latestReadinessScore !== null
    ? mycoachBuildReadinessGuidance($latestReadinessScore, $latestDailyEntry ?: null, $latestQuestionnaire, $timeline, $activeGoal)
    : null;
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

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-theme.css?v=20260804">
<script>document.body.classList.add('mycoach-theme', 'mycoach-theme--coach');</script>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 mc-topbar">
    <div>
        <h2 class="mb-1"><?php renderMyCoachAppLogoInline(); ?><i class="fas fa-brain me-2 text-warning"></i>MyCoach</h2>
        <div class="text-muted">Osobní tréninkový asistent</div>
    </div>
    <div class="d-flex gap-2 flex-wrap mc-actions">
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

<ul class="nav nav-pills mb-4 flex-wrap gap-2 mc-pills">
    <li class="nav-item"><span class="nav-link active"><i class="fas fa-house me-1"></i>Přehled</span></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_questionnaire.php"><i class="fas fa-clipboard-list me-1"></i>Dotazník <?php if (!$latestQuestionnaire || empty($latestQuestionnaire['completed_at'])): ?><span class="badge rounded-pill bg-danger ms-1" style="font-size:.65rem">!</span><?php endif; ?></a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_graphs.php"><i class="fas fa-chart-line me-1"></i>Grafy</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_athletes.php"><i class="fas fa-users me-1"></i>Sportovci</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <p class="mb-2 fw-semibold">MyCoach už sleduje cíl, připravenost, zátěž i denní trend a umí z toho navrhnout další krok.</p>
        <p class="text-muted mb-0">Můžeš mít více aktivních cílů současně. Denní data se sdílí napříč cíli, ale doporučení se řídí primárním cílem (hvězdička).</p>
    </div>
</div>

<?php if ($goalInfo): ?>
<div class="alert alert-info shadow-sm"><?= h($goalInfo) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4 mc-goals-board">
    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="fas fa-bullseye me-2 text-primary"></i>Aktivní cíle</span>
        <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis px-3 py-2"><?= (int)$activeGoalCount ?> aktivních</span>
    </div>
    <div class="card-body">
        <?php if ($activeGoals): ?>
        <div class="row g-3">
            <?php foreach ($activeGoals as $goalRow): ?>
            <?php
                $goalLabel = mycoachGoalLabel((string)$goalRow['goal_type'], (string)($goalRow['custom_goal_name'] ?? ''));
                $goalDateRaw = (string)($goalRow['target_date'] ?? '');
                $goalId = (int)$goalRow['id'];
                $isPrimaryGoal = $goalId === (int)$primaryGoalId;

                $goalStartDate = !empty($goalRow['started_at']) ? date('Y-m-d', strtotime((string)$goalRow['started_at'])) : date('Y-m-d');
                $goalTotalDays = null;
                $goalElapsedDays = null;
                $goalProgressPercent = 0;
                $goalDaysLeft = null;
                if ($goalDateRaw !== '') {
                    $goalStartTs = strtotime($goalStartDate);
                    $goalTargetTs = strtotime($goalDateRaw);
                    $todayTs = strtotime(date('Y-m-d'));
                    if ($goalStartTs !== false && $goalTargetTs !== false && $todayTs !== false) {
                        $goalTotalDays = max(1, (int)floor(($goalTargetTs - $goalStartTs) / 86400));
                        $goalElapsedDays = max(0, min($goalTotalDays, (int)floor(($todayTs - $goalStartTs) / 86400)));
                        $goalProgressPercent = (int)max(0, min(100, round(($goalElapsedDays / $goalTotalDays) * 100)));
                        $goalDaysLeft = (int)floor(($goalTargetTs - $todayTs) / 86400);
                    }
                }

                $goalStage = 'active';
                if ($goalDaysLeft !== null) {
                    if ($goalDaysLeft <= 0) {
                        $goalStage = 'finish';
                    } elseif ($goalDaysLeft <= 6) {
                        $goalStage = 'taper';
                    } elseif ($goalProgressPercent <= 8) {
                        $goalStage = 'start';
                    }
                }

                $goalBadgeClass = 'bg-secondary-subtle text-secondary-emphasis';
                $goalBadgeText = 'bez data cíle';
                if ($goalDaysLeft !== null) {
                    if ($goalDaysLeft <= 0) {
                        $goalBadgeClass = 'bg-danger-subtle text-danger-emphasis';
                        $goalBadgeText = $goalDaysLeft === 0 ? 'Dnes je den cíle' : 'Po termínu';
                    } elseif ($goalDaysLeft <= 6) {
                        $goalBadgeClass = 'bg-warning-subtle text-warning-emphasis';
                        $goalBadgeText = 'Taper: ' . $goalDaysLeft . ' dní';
                    } else {
                        $goalBadgeClass = 'bg-success-subtle text-success-emphasis';
                        $goalBadgeText = $goalDaysLeft . ' dní do cíle';
                    }
                }
            ?>
            <div class="col-12 col-xl-6">
                <div class="mc-goal-card h-100">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <div>
                            <div class="small text-uppercase fw-bold text-muted">Cíl #<?= $goalId ?></div>
                            <div class="fs-5 fw-bold"><?= h($goalLabel) ?></div>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <span class="badge rounded-pill <?= h($goalBadgeClass) ?> px-3 py-2"><?= h($goalBadgeText) ?></span>
                            <form method="post" class="m-0">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="set_primary_goal">
                                <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                                <button type="submit" class="btn btn-sm <?= $isPrimaryGoal ? 'btn-warning' : 'btn-outline-secondary' ?>" title="<?= $isPrimaryGoal ? 'Primární cíl' : 'Nastavit jako primární cíl' ?>">
                                    <i class="fas fa-star"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="small text-muted mt-2">
                        Start: <?= h(formatDate($goalStartDate)) ?>
                        <?php if ($goalDateRaw !== ''): ?> · cíl: <?= h(formatDate($goalDateRaw)) ?><?php endif; ?>
                    </div>

                    <div class="mc-goal-progress-wrap mt-3">
                        <div class="mc-goal-progress">
                            <div class="mc-goal-progress__fill" style="width: <?= (int)$goalProgressPercent ?>%;"></div>
                        </div>
                        <div class="mc-goal-progress__labels">
                            <span class="<?= in_array($goalStage, ['start','active','taper','finish'], true) ? 'is-active' : '' ?>">Start</span>
                            <span class="<?= in_array($goalStage, ['taper','finish'], true) ? 'is-active' : '' ?>">Taper</span>
                            <span class="<?= $goalStage === 'finish' ? 'is-active' : '' ?>">Finish</span>
                            <span>Archiv</span>
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-12 col-lg-8">
                            <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="extend_goal">
                                <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                                <input type="date" name="target_date" class="form-control form-control-sm" value="<?= h($goalDateRaw) ?>" required>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Prodloužit</button>
                            </form>
                        </div>
                        <div class="col-12 col-lg-4 d-flex justify-content-lg-end">
                            <form method="post" onsubmit="return confirm('Opravdu chceš cíl ukončit a přesunout do archivu?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="end_goal_early">
                                <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">Ukončit cíl</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-warning mb-0">Zatím nemáš aktivní cíl. Přidej první cíl níže.</div>
        <?php endif; ?>
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
        <?php if (($questionnaireHealthProfile['count'] ?? 0) > 0): ?>
        <div class="small text-muted mt-2">
            MyCoach počítá se zdravotními omezeními z dotazníku:
            <?= h(implode(' · ', (array)($questionnaireHealthProfile['labels'] ?? []))) ?>
        </div>
        <?php endif; ?>
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
        <i class="fas fa-plus-circle me-2"></i>Přidat nový aktivní cíl
    </div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_goal">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Cíl</label>
                <select name="goal_type" class="form-select" required>
                    <?php foreach ($goalOptions as $goalKey => $goalLabel): ?>
                    <option value="<?= h($goalKey) ?>"><?= h($goalLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Datum cíle</label>
                <input type="date" name="target_date" class="form-control" value="">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Vlastní název cíle</label>
                <input type="text" name="custom_goal_name" class="form-control" value="" placeholder="Vyplňte jen pro vlastní cíl">
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary fw-semibold">
                    <i class="fas fa-save me-1"></i>Přidat cíl
                </button>
                <span class="text-muted small align-self-center">Aktivních cílů může být více současně. Primární cíl přepni hvězdičkou v kartě.</span>
            </div>
        </form>
    </div>
</div>

<?php if ($archivedGoals): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-light fw-bold">
        <i class="fas fa-box-archive me-2 text-secondary"></i>Archiv cílů
    </div>
    <div class="card-body">
        <div class="row g-2">
            <?php foreach ($archivedGoals as $archivedGoal): ?>
            <div class="col-12 col-lg-6">
                <div class="border rounded-4 p-3 h-100">
                    <div class="fw-semibold"><?= h(mycoachGoalLabel((string)$archivedGoal['goal_type'], (string)($archivedGoal['custom_goal_name'] ?? ''))) ?></div>
                    <div class="small text-muted">
                        <?php if (!empty($archivedGoal['target_date'])): ?>Cíl: <?= h(formatDate((string)$archivedGoal['target_date'])) ?><?php else: ?>Cíl bez data<?php endif; ?>
                        <?php if (!empty($archivedGoal['ended_at'])): ?> · ukončeno <?= h(formatDate((string)$archivedGoal['ended_at'])) ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="alert alert-info border-0 shadow-sm">
    <strong>Stav modulu:</strong> Aktivní je profil, cíl, dotazník, denní log, trendový insight, grafy i export. Z dotazníku se po uložení vytváří první plán.
</div>

<?php renderFooter();
