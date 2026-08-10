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

if (!mycoachAccessEnabledForCoach($pdo, $coachId)) {
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
$questionnaireOptions = mycoachQuestionnaireOptions();
$goalInfo = null;

$goalPrefsReady = false;
try {
    $goalPrefsReady = (bool)$pdo->query("SHOW TABLES LIKE 'mycoach_goal_preferences'")->fetch();
} catch (Throwable $e) {
    $goalPrefsReady = false;
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

$latestQuestionnaire = mycoachFetchLatestQuestionnaire($pdo, $myCoachUserId);
$questionnaireDraftKey = 'mycoach_questionnaire_draft_coach_' . $myCoachUserId;
$selectedGoalType = trim((string)($activeGoal['goal_type'] ?? ($latestQuestionnaire['goal_snapshot_type'] ?? 'fitness')));
$questionnaireError = null;

$goalTypeDefault = trim((string)($_POST['goal_type'] ?? ($activeGoal['goal_type'] ?? 'fitness')));
$goalTargetDateDefault = trim((string)($_POST['target_date'] ?? ''));
$goalCustomNameDefault = trim((string)($_POST['custom_goal_name'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $questionnaireError = 'Neplatný bezpečnostní token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_goal' || $action === 'add_goal') {
            $goalType = trim((string)($_POST['goal_type'] ?? ''));
            $customGoalName = trim((string)($_POST['custom_goal_name'] ?? ''));
            $targetDate = trim((string)($_POST['target_date'] ?? ''));

            if (!isset($goalOptions[$goalType])) {
                $questionnaireError = 'Vyberte platný cíl.';
            } elseif ($goalType === 'custom' && $customGoalName === '') {
                $questionnaireError = 'U vlastního cíle zadejte jeho název.';
            } elseif ($targetDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
                $questionnaireError = 'Zadejte platné datum cíle.';
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
                        redirect(BASE_URL . '/mycoach_questionnaire.php');
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
                    redirect(BASE_URL . '/mycoach_questionnaire.php');
                } catch (Throwable $e) {
                    $questionnaireError = 'Cíl se nepodařilo uložit.';
                }
            }
        }

        if ($action === 'extend_goal') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $targetDate = trim((string)($_POST['target_date'] ?? ''));
            if ($goalId <= 0) {
                $questionnaireError = 'Nebyl vybrán cíl k prodloužení.';
            } elseif ($targetDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
                $questionnaireError = 'Zadejte platné nové datum cíle.';
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
                        redirect(BASE_URL . '/mycoach_questionnaire.php');
                    }
                    $questionnaireError = 'Cíl se nepodařilo prodloužit.';
                } catch (Throwable $e) {
                    $questionnaireError = 'Cíl se nepodařilo prodloužit.';
                }
            }
        }

        if ($action === 'end_goal_early') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            if ($goalId <= 0) {
                $questionnaireError = 'Nebyl vybrán cíl k ukončení.';
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
                        redirect(BASE_URL . '/mycoach_questionnaire.php');
                    }
                    $questionnaireError = 'Cíl se nepodařilo ukončit.';
                } catch (Throwable $e) {
                    $questionnaireError = 'Cíl se nepodařilo ukončit.';
                }
            }
        }

        if ($action === 'set_primary_goal') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            if (!$goalPrefsReady) {
                $questionnaireError = 'Primární cíl teď nelze nastavit.';
            } elseif ($goalId <= 0) {
                $questionnaireError = 'Nebyl vybrán cíl pro nastavení primárního.';
            } else {
                try {
                    $goalCheckStmt = $pdo->prepare('SELECT id FROM mycoach_goals WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1');
                    $goalCheckStmt->execute([$goalId, $myCoachUserId]);
                    if (!$goalCheckStmt->fetchColumn()) {
                        $questionnaireError = 'Vybraný cíl není aktivní.';
                    } else {
                        $setPrimaryStmt = $pdo->prepare(
                            'INSERT INTO mycoach_goal_preferences (user_id, primary_goal_id)
                             VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE primary_goal_id = VALUES(primary_goal_id), updated_at = NOW()'
                        );
                        $setPrimaryStmt->execute([$myCoachUserId, $goalId]);
                        flash('success', 'Primární cíl byl nastaven.');
                        redirect(BASE_URL . '/mycoach_questionnaire.php');
                    }
                } catch (Throwable $e) {
                    $questionnaireError = 'Primární cíl se nepodařilo uložit.';
                }
            }
        }

        if ($action === 'save_questionnaire') {
            if (!$activeGoal) {
                $questionnaireError = 'Nejdřív nastav alespoň jeden aktivní cíl.';
            } else {
                $rawHealthLimits = $_POST['health_limits'] ?? [];
                $healthLimitsInput = is_array($rawHealthLimits) ? $rawHealthLimits : [$rawHealthLimits];
                $otherSelected = in_array('other', array_map(static fn($item) => trim((string)$item), $healthLimitsInput), true);
                $healthLimitsOtherReason = trim((string)($_POST['health_limits_other_reason'] ?? ''));

                if ($otherSelected && $healthLimitsOtherReason === '') {
                    $questionnaireError = 'Při volbě „Jiné" v části Zdravotní omezení doplň důvod.';
                }
            }

            if (!$questionnaireError) {
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
                    'health_limits_other_reason' => $_POST['health_limits_other_reason'] ?? null,
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
                    $savedPlan = mycoachEnsureStarterPlan($pdo, $myCoachUserId, $payload, $activeGoal, 'coach');
                    $savedRace = mycoachStoreHyroxRace($pdo, $myCoachUserId, $goalId > 0 ? $goalId : null, $payload);
                    if ($savedPlan && $savedRace) {
                        flash('success', 'Dotazník byl uložen a první plán byl připraven.');
                        redirect(BASE_URL . '/mycoach.php');
                    }

                    $questionnaireError = 'Dotazník byl uložen, ale plán se nepodařilo založit.';
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
$healthLimitsOtherReasonValue = trim((string)($latestQuestionnaire['health_limits_other_reason'] ?? ''));

renderHeader('MyCoach dotazník', false, true);
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-theme.css?v=20260804">
<script>document.body.classList.add('mycoach-theme', 'mycoach-theme--coach');</script>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 mc-topbar">
    <div>
        <h2 class="mb-1"><?php renderMyCoachAppLogoInline(); ?><i class="fas fa-clipboard-list me-2 text-warning"></i>Úvodní dotazník</h2>
        <div class="text-muted">MyCoach pro trenéra</div>
    </div>
    <div class="d-flex gap-2 flex-wrap mc-actions">
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm fw-semibold">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <a href="<?= BASE_URL ?>/mycoach.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Zpět do MyCoach
        </a>
    </div>
</div>

<ul class="nav nav-pills mb-4 flex-wrap gap-2 mc-pills">
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach.php"><i class="fas fa-house me-1"></i>Přehled</a></li>
    <li class="nav-item"><span class="nav-link active"><i class="fas fa-clipboard-list me-1"></i>Dotazník</span></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_graphs.php"><i class="fas fa-chart-line me-1"></i>Grafy</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_athletes.php"><i class="fas fa-users me-1"></i>Sportovci</a></li>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <p class="mb-2">Vyplněním dotazníku uložíš vstupní údaje, vytvoříš první aktivní plán a nastavíš základ pro další doporučení.</p>
        <p class="mb-0 text-muted">Cíle spravuješ níže v kartách. Dotazník se vždy naváže na primární cíl označený hvězdičkou.</p>
    </div>
</div>

<?php if ($goalInfo): ?>
<div class="alert alert-info shadow-sm"><?= h($goalInfo) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4 mc-goals-board">
    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="fas fa-bullseye me-2 text-primary"></i>Aktivní cíle</span>
        <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis px-3 py-2"><?= (int)count($activeGoals) ?> aktivních</span>
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
                <div class="text-muted small text-uppercase fw-bold">Primární cíl</div>
                <div class="fs-5 fw-bold"><?= $activeGoal ? h(mycoachGoalLabel((string)$activeGoal['goal_type'], (string)($activeGoal['custom_goal_name'] ?? ''))) : 'Zatím nenastaven' ?></div>
                <div class="text-muted small"><?= $activeGoal && !empty($activeGoal['target_date']) ? 'Cíl do ' . h(formatDate((string)$activeGoal['target_date'])) : 'Nejdřív nastav cíl v MyCoach.' ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Dotazníky</div>
                <div class="fs-3 fw-bold text-primary"><?= (int)$questionnaireCount ?></div>
                <div class="text-muted small">uložený onboarding snapshot</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Profil</div>
                <div class="fs-5 fw-bold"><?= h($coachDisplayName !== '' ? $coachDisplayName : 'Trenér') ?></div>
                <div class="text-muted small">MyCoach user: #<?= (int)$myCoachUserId ?></div>
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
<div class="alert alert-success shadow-sm">Dotazník je hotový. Další práce už běží přes přehled a grafy.</div>
<?php endif; ?>

<style>
.mc-q-progress {
    height: 10px;
    background: #e9ecef;
    border-radius: 999px;
    overflow: hidden;
}
.mc-q-progress > span {
    display: block;
    height: 100%;
    width: 25%;
    background: linear-gradient(90deg, #0ea5e9 0%, #22c55e 100%);
    transition: width .2s ease;
}
.mc-step-kicker {
    font-size: .8rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 700;
}
.mc-step-title {
    font-weight: 800;
    margin-bottom: .25rem;
}
</style>

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
                    <option value="<?= h($goalKey) ?>" <?= $goalTypeDefault === $goalKey ? 'selected' : '' ?>><?= h($goalLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Datum cíle</label>
                <input type="date" name="target_date" class="form-control" value="<?= h($goalTargetDateDefault) ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Vlastní název cíle</label>
                <input type="text" name="custom_goal_name" class="form-control" value="<?= h($goalCustomNameDefault) ?>" placeholder="Vyplňte jen pro vlastní cíl">
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap align-items-center">
                <button type="submit" class="btn btn-primary fw-semibold">
                    <i class="fas fa-save me-1"></i>Přidat cíl
                </button>
                <span class="text-muted small">Primární cíl nastav hvězdičkou v kartě.</span>
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

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3" id="mycoachQuestionnaireForm" data-draft-key="<?= h($questionnaireDraftKey) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_questionnaire">

            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <div>
                        <div class="mc-step-kicker" id="mycoachStepLabel">Krok 1 z 4</div>
                        <div class="mc-step-title" id="mycoachStepTitle">Základní údaje</div>
                    </div>
                    <div class="small text-muted">Vyplnění zabere cca 2 minuty</div>
                </div>
                <div class="mc-q-progress mb-2"><span id="mycoachProgressBar"></span></div>
            </div>

            <div class="col-12 mc-wizard-step" data-step="1">
                <div class="alert alert-info mb-0">
                    Dotazník se uloží k primárnímu cíli: <strong><?= $activeGoal ? h(mycoachGoalLabel((string)$activeGoal['goal_type'], (string)($activeGoal['custom_goal_name'] ?? ''))) : 'není vybrán' ?></strong>
                    <?php if ($activeGoal && !empty($activeGoal['target_date'])): ?> (cíl do <?= h(formatDate((string)$activeGoal['target_date'])) ?>).<?php endif; ?>
                </div>
            </div>

            <div class="col-12 col-md-3 mc-wizard-step" data-step="1">
                <label class="form-label fw-semibold">Věk</label>
                <input type="number" name="age_years" class="form-control" min="5" max="100" value="<?= h((string)($latestQuestionnaire['age_years'] ?? '')) ?>">
            </div>
            <div class="col-12 col-md-3 mc-wizard-step" data-step="1">
                <label class="form-label fw-semibold">Pohlaví</label>
                <select name="gender" class="form-select">
                    <option value="">Vyberte</option>
                    <?php foreach ($questionnaireOptions['genders'] as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ((string)($latestQuestionnaire['gender'] ?? '') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3 mc-wizard-step" data-step="1">
                <label class="form-label fw-semibold">Výška (cm)</label>
                <input type="number" name="height_cm" class="form-control" min="80" max="250" value="<?= h((string)($latestQuestionnaire['height_cm'] ?? '')) ?>">
            </div>
            <div class="col-12 col-md-3 mc-wizard-step" data-step="1">
                <label class="form-label fw-semibold">Hmotnost (kg)</label>
                <input type="number" step="0.1" name="weight_kg" class="form-control" min="20" max="300" value="<?= h((string)($latestQuestionnaire['weight_kg'] ?? '')) ?>">
            </div>

            <div class="col-12 col-md-4 mc-wizard-step" data-step="1">
                <label class="form-label fw-semibold">Výkonnost</label>
                <select name="performance_level" class="form-select">
                    <option value="">Vyberte</option>
                    <?php foreach ($questionnaireOptions['performance_levels'] as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ((string)($latestQuestionnaire['performance_level'] ?? '') === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 mc-wizard-step" data-step="1">
                <label class="form-label fw-semibold">Jak dlouho sportuješ?</label>
                <input type="text" name="sport_years" class="form-control" placeholder="např. 3 roky" value="<?= h((string)($latestQuestionnaire['sport_years'] ?? '')) ?>">
            </div>
            <div class="col-12 col-md-4 mc-wizard-step" data-step="1">
                <label class="form-label fw-semibold">Kolikrát týdně sportuješ?</label>
                <input type="number" name="sport_frequency_per_week" class="form-control" min="0" max="21" value="<?= h((string)($latestQuestionnaire['sport_frequency_per_week'] ?? '')) ?>">
            </div>

            <div class="col-12 mc-wizard-step" data-step="1">
                <label class="form-label fw-semibold">Jaké sporty provozuješ?</label>
                <textarea name="sports_text" class="form-control" rows="2" placeholder="Např. běh, posilovna, HYROX"><?= h((string)($latestQuestionnaire['sports_text'] ?? '')) ?></textarea>
            </div>

            <div class="col-12 mc-wizard-step" data-step="2">
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

            <div class="col-12 mc-wizard-step" data-step="2">
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
                <div class="mt-2 <?= in_array('other', $healthLimitsSelected, true) ? '' : 'd-none' ?>" data-health-limits-other-wrap>
                    <label class="form-label fw-semibold" for="health_limits_other_reason">
                        Pokud Jiné, uveďte důvod <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" id="health_limits_other_reason" name="health_limits_other_reason" placeholder="Doplňte zdravotní omezení" value="<?= h($healthLimitsOtherReasonValue) ?>" <?= in_array('other', $healthLimitsSelected, true) ? 'required' : '' ?>>
                </div>
            </div>

            <div class="col-12 col-md-6 mc-wizard-step" data-step="3">
                <label class="form-label fw-semibold">Kolik času můžeš týdně trénovat?</label>
                <select name="weekly_training_hours_target" class="form-select">
                    <option value="">Vyberte</option>
                    <?php foreach ($questionnaireOptions['weekly_hours'] as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ((string)($latestQuestionnaire['weekly_training_hours_target'] ?? '') === (string)$key) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6 mc-wizard-step" data-step="3">
                <label class="form-label fw-semibold">Kolik dní v týdnu chceš odpočívat?</label>
                <select name="rest_days_per_week" class="form-select">
                    <option value="">Vyberte</option>
                    <?php foreach ($questionnaireOptions['rest_days'] as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= ((string)($latestQuestionnaire['rest_days_per_week'] ?? '') === (string)$key) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 mc-wizard-step" data-step="3">
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

            <div class="col-12 col-md-4 mc-wizard-step" data-step="3">
                <label class="form-label fw-semibold">Máš trenéra?</label>
                <select name="has_trainer" class="form-select">
                    <option value="0" <?= empty($latestQuestionnaire['has_trainer']) ? 'selected' : '' ?>>NE</option>
                    <option value="1" <?= !empty($latestQuestionnaire['has_trainer']) ? 'selected' : '' ?>>ANO</option>
                </select>
            </div>
            <div class="col-12 col-md-8 mc-wizard-step" data-step="3">
                <label class="form-label fw-semibold">Jméno trenéra</label>
                <input type="text" name="trainer_name" class="form-control" value="<?= h((string)($latestQuestionnaire['trainer_name'] ?? '')) ?>" placeholder="Jméno trenéra">
            </div>

            <div class="col-12 mc-wizard-step" data-step="4">
                <div class="border rounded-4 p-3 bg-light <?= $selectedGoalType === 'hyrox' ? '' : 'opacity-75' ?>" id="mycoachHyroxPanel" data-goal-type="<?= h($selectedGoalType) ?>">
                    <div class="fw-bold mb-2"><i class="fas fa-flag-checkered me-2 text-warning"></i>HYROX doplňující otázky</div>
                    <div class="text-muted small mb-3" data-hyrox-hint <?= $selectedGoalType === 'hyrox' ? 'hidden' : '' ?>>Tato část se aktivuje po nastavení cíle HYROX.</div>
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

            <div class="col-12 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary" id="mycoachPrevBtn">
                    <i class="fas fa-arrow-left me-1"></i>Zpět
                </button>
                <button type="button" class="btn btn-primary" id="mycoachNextBtn">
                    Další krok <i class="fas fa-arrow-right ms-1"></i>
                </button>
            </div>

            <div class="col-12 d-flex gap-2 flex-wrap mc-wizard-step" data-step="4">
                <button type="submit" class="btn btn-success fw-semibold" id="mycoachQuestionnaireSaveBtn" <?= $activeGoal ? '' : 'disabled' ?>>
                    <i class="fas fa-save me-1"></i>Uložit dotazník a vytvořit první plán
                </button>
                <a href="<?= BASE_URL ?>/mycoach.php" class="btn btn-outline-secondary">Zpět</a>
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

    const draftKey = form.dataset.draftKey || 'mycoach_questionnaire_draft_coach';
    const goalSelect = document.getElementById('mycoachGoalType');
    const hyroxPanel = document.getElementById('mycoachHyroxPanel');
    const panelGoalType = hyroxPanel ? (hyroxPanel.dataset.goalType || '') : '';
    const hyroxHint = hyroxPanel ? hyroxPanel.querySelector('[data-hyrox-hint]') : null;
    const wizardFields = Array.from(form.querySelectorAll('.mc-wizard-step'));
    const prevBtn = document.getElementById('mycoachPrevBtn');
    const nextBtn = document.getElementById('mycoachNextBtn');
    const stepLabel = document.getElementById('mycoachStepLabel');
    const stepTitle = document.getElementById('mycoachStepTitle');
    const progressBar = document.getElementById('mycoachProgressBar');
    const totalSteps = 4;
    const stepTitles = {
        1: 'Základní údaje',
        2: 'Aktivity a omezení',
        3: 'Čas a vybavení',
        4: 'HYROX a odeslání',
    };
    const saveButton = document.getElementById('mycoachQuestionnaireSaveBtn');
    let draftTimer = null;
    let currentStep = 1;

    function updateWizardVisibility() {
        wizardFields.forEach(function (field) {
            const step = Number(field.getAttribute('data-step') || '1');
            field.classList.toggle('d-none', step !== currentStep);
        });

        if (stepLabel) {
            stepLabel.textContent = 'Krok ' + currentStep + ' z ' + totalSteps;
        }
        if (stepTitle) {
            stepTitle.textContent = stepTitles[currentStep] || 'Dotazník';
        }
        if (progressBar) {
            progressBar.style.width = ((currentStep / totalSteps) * 100) + '%';
        }
        if (prevBtn) {
            prevBtn.disabled = currentStep === 1;
        }
        if (nextBtn) {
            nextBtn.classList.toggle('d-none', currentStep === totalSteps);
        }
    }

    function validateCurrentStep() {
        const currentFields = wizardFields.filter(function (field) {
            return Number(field.getAttribute('data-step') || '1') === currentStep;
        });

        for (const fieldWrap of currentFields) {
            const requiredFields = Array.from(fieldWrap.querySelectorAll('[required]'));
            for (const field of requiredFields) {
                if (field.closest('[data-health-limits-other-wrap]') && field.closest('[data-health-limits-other-wrap]').classList.contains('d-none')) {
                    continue;
                }

                if (field.type === 'checkbox' || field.type === 'radio') {
                    if (!field.checked) {
                        field.focus();
                        return false;
                    }
                    continue;
                }

                if ((field.value || '').trim() === '') {
                    field.focus();
                    return false;
                }
            }
        }

        return true;
    }

    function updateHealthLimitsOtherReason() {
        const wrap = form.querySelector('[data-health-limits-other-wrap]');
        if (!wrap) {
            return;
        }

        const otherChecked = !!form.querySelector('input[name="health_limits[]"][value="other"]:checked');
        const input = wrap.querySelector('input, textarea');
        wrap.classList.toggle('d-none', !otherChecked);

        if (input) {
            input.required = otherChecked;
            if (!otherChecked) {
                input.value = '';
            }
        }
    }

    function setHyroxState() {
        const enabled = goalSelect ? goalSelect.value === 'hyrox' : panelGoalType === 'hyrox';
        if (hyroxPanel) {
            hyroxPanel.classList.toggle('opacity-75', !enabled);
        }
        if (hyroxHint) {
            hyroxHint.hidden = enabled;
        }

        form.querySelectorAll('[data-hyrox-toggle]').forEach(function (element) {
            element.disabled = !enabled;
        });
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
            // local only
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
    updateHealthLimitsOtherReason();
    updateWizardVisibility();

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
        updateHealthLimitsOtherReason();
    });

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            if (currentStep > 1) {
                currentStep -= 1;
                updateWizardVisibility();
            }
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            if (!validateCurrentStep()) {
                alert('Prosím vyplňte povinná pole v aktuálním kroku.');
                return;
            }
            if (currentStep < totalSteps) {
                currentStep += 1;
                updateWizardVisibility();
            }
        });
    }

    form.addEventListener('submit', function (event) {
        updateHealthLimitsOtherReason();
        if (!validateCurrentStep()) {
            event.preventDefault();
            alert('Prosím vyplňte povinná pole v aktuálním kroku.');
            return;
        }

        const otherChecked = !!form.querySelector('input[name="health_limits[]"][value="other"]:checked');
        const otherReasonField = form.querySelector('[name="health_limits_other_reason"]');
        if (otherChecked && otherReasonField && (otherReasonField.value || '').trim() === '') {
            event.preventDefault();
            otherReasonField.focus();
            alert('Při volbě „Jiné" v části Zdravotní omezení doplňte důvod.');
            return;
        }

        saveDraft();
    });
});
</script>

<?php renderFooter();
