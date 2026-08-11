<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

flash('info', 'MyCoach je nyní jen Pro modul. Přístup je řízen administrací.');
redirect(BASE_URL . '/dashboard.php');

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
$latestQuestionnaire = mycoachFetchLatestQuestionnaire($pdo, $myCoachUserId);
$athleteProgressRows = mycoachFetchCoachAthleteProgress($pdo, $coachId, 250);
$mycoachAccessMode = mycoachAccessMode();
$isMyCoachGlobalAll = $mycoachAccessMode === 'all';
$runningPlanCount = 0;
$withoutDailyDataCount = 0;
$disabledCount = 0;

foreach ($athleteProgressRows as $athleteProgress) {
    if ((int)($athleteProgress['active_plan_count'] ?? 0) > 0) {
        $runningPlanCount++;
    }

    if (empty($athleteProgress['latest_daily']['latest_entry_date'])) {
        $withoutDailyDataCount++;
    }

    if (!$isMyCoachGlobalAll && empty($athleteProgress['mycoach_enabled'])) {
        $disabledCount++;
    }
}

renderHeader('MyCoach sportovci', false, true);
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-theme.css?v=20260804">
<script>document.body.classList.add('mycoach-theme', 'mycoach-theme--coach');</script>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 mc-topbar">
    <div>
        <h2 class="mb-1"><?php renderMyCoachAppLogoInline(); ?><i class="fas fa-users me-2 text-warning"></i>MyCoach sportovci</h2>
        <div class="text-muted">Sledování sportovců trenéra v samostatné kartě</div>
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
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_questionnaire.php"><i class="fas fa-clipboard-list me-1"></i>Dotazník <?php if (!$latestQuestionnaire || empty($latestQuestionnaire['completed_at'])): ?><span class="badge rounded-pill bg-danger ms-1" style="font-size:.65rem">!</span><?php endif; ?></a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_graphs.php"><i class="fas fa-chart-line me-1"></i>Grafy</a></li>
    <li class="nav-item"><span class="nav-link active"><i class="fas fa-users me-1"></i>Sportovci</span></li>
</ul>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Běžící plány</div>
                <div class="fs-3 fw-bold text-success"><?= (int)$runningPlanCount ?></div>
                <div class="text-muted small">z <?= (int)count($athleteProgressRows) ?> sportovců</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Bez denních dat</div>
                <div class="fs-3 fw-bold text-warning"><?= (int)$withoutDailyDataCount ?></div>
                <div class="text-muted small">sportovců bez denního záznamu</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold"><?= $isMyCoachGlobalAll ? 'MyCoach ručně omezen' : 'MyCoach vypnutý' ?></div>
                <div class="fs-3 fw-bold text-secondary"><?= (int)$disabledCount ?></div>
                <div class="text-muted small"><?= $isMyCoachGlobalAll ? 'sportovců (globálně je vše povoleno)' : 'sportovců' ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-users me-2 text-primary"></i>Sportovci a průběh MyCoach</span>
        <span class="badge bg-primary"><?= (int)count($athleteProgressRows) ?> sportovců</span>
    </div>
    <div class="card-body">
        <?php if ($athleteProgressRows): ?>
        <div class="row g-3">
            <?php foreach ($athleteProgressRows as $athleteProgress): ?>
            <div class="col-12 col-lg-6 col-xxl-4">
                <div class="border rounded-4 p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <div class="fw-bold fs-5"><?= h((string)$athleteProgress['full_name']) ?></div>
                            <div class="small text-muted"><?= h((string)$athleteProgress['email']) ?></div>
                        </div>
                        <span class="badge bg-<?= h((string)$athleteProgress['status_variant']) ?>"><?= h((string)$athleteProgress['status_label']) ?></span>
                    </div>

                    <div class="small text-muted mb-2">
                        <?php if (!empty($athleteProgress['active_goal'])): ?>
                        Cíl: <?= h(mycoachGoalLabel((string)($athleteProgress['active_goal']['goal_type'] ?? ''), (string)($athleteProgress['active_goal']['custom_goal_name'] ?? ''))) ?>
                        <?php else: ?>
                        Cíl zatím nenastaven
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-between flex-wrap gap-2 small mb-2">
                        <div>
                            <span class="text-muted">Plánů:</span>
                            <span class="fw-semibold"><?= (int)$athleteProgress['active_plan_count'] ?></span>
                        </div>
                        <div>
                            <span class="text-muted">Readiness:</span>
                            <span class="fw-semibold"><?= isset($athleteProgress['latest_readiness']['score']) ? (int)$athleteProgress['latest_readiness']['score'] . ' / 100' : 'bez dat' ?></span>
                        </div>
                    </div>

                    <div class="small text-muted mb-3">
                        <?php if (!empty($athleteProgress['latest_readiness']['metric_date'])): ?>
                        Poslední readiness: <?= h(formatDate((string)$athleteProgress['latest_readiness']['metric_date'])) ?>
                        <?php else: ?>
                        Poslední readiness zatím chybí
                        <?php endif; ?>
                        <?php if (!empty($athleteProgress['latest_daily']['latest_entry_date'])): ?>
                        · Poslední denní záznam: <?= h(formatDate((string)$athleteProgress['latest_daily']['latest_entry_date'])) ?>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$athleteProgress['athlete_id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-user me-1"></i>Karta sportovce
                        </a>
                        <?php if ($isMyCoachGlobalAll || !empty($athleteProgress['mycoach_enabled'])): ?>
                        <a href="<?= BASE_URL ?>/progress_report.php?athlete_id=<?= (int)$athleteProgress['athlete_id'] ?>&period=<?= (int)$athleteProgress['active_plan_count'] > 0 ? 'plan' : 'last7' ?>&scope=mycoach" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-chart-line me-1"></i>Progres
                        </a>
                        <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="MyCoach není u sportovce aktivovaný">
                            <i class="fas fa-chart-line me-1"></i>Progres
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-info mb-0">Zatím nemáš přiřazené sportovce.</div>
        <?php endif; ?>
    </div>
</div>

<?php renderFooter();
