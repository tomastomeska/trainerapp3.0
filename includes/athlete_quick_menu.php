<?php
/**
 * @var PDO $pdo
 * @var int $athleteId
 * @var int $unreadInboxCount
 * @var int $onlineTrainingCount
 * @var bool $athleteSpecialTrainingEnabled
 * @var bool $mycoachAppUserCanAccess
 * @var bool $healthQuestionnaireAccessEnabled
 * @var string $healthTileClass
 * @var string $healthTileValue
 */
require_once __DIR__ . '/dashboard_quick_menu.php';
require_once __DIR__ . '/surveys.php';
?>
<?php ob_start(); ?>
<div class="dashboard-quick-tiles mb-3">
    <a data-shortcut="messages" href="<?= BASE_URL ?>/athlete_zpravy.php" class="quick-tile quick-tile-danger">
        <span class="quick-tile__label"><i class="fas fa-envelope me-1"></i>Zprávy</span>
        <span class="quick-tile__value"><?= (int)$unreadInboxCount ?></span>
    </a>
    <a data-shortcut="mealplans" href="<?= BASE_URL ?>/athlete_mealplans.php" class="quick-tile quick-tile-success">
        <span class="quick-tile__label"><i class="fas fa-utensils me-1"></i>Jídelníčky</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a data-shortcut="food" href="<?= BASE_URL ?>/athlete_food_diary.php" class="quick-tile quick-tile-warning">
        <span class="quick-tile__label"><i class="fas fa-bowl-food me-1"></i>Strava</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a data-shortcut="graphs" href="<?= BASE_URL ?>/athlete_graphs.php" class="quick-tile quick-tile-info">
        <span class="quick-tile__label"><i class="fas fa-chart-line me-1"></i>Grafy</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a data-shortcut="calendar" href="<?= BASE_URL ?>/athlete_calendar.php" class="quick-tile quick-tile-warning">
        <span class="quick-tile__label"><i class="fas fa-calendar-alt me-1"></i>Kalendář</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a data-shortcut="password" href="<?= BASE_URL ?>/athlete_change_password.php" class="quick-tile quick-tile-muted">
        <span class="quick-tile__label"><i class="fas fa-key me-1"></i>Heslo</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a data-shortcut="gallery" href="<?= BASE_URL ?>/athlete_gallery.php" class="quick-tile quick-tile-info">
        <span class="quick-tile__label"><i class="fas fa-images me-1"></i>Galerie</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a data-shortcut="files" href="<?= BASE_URL ?>/athlete_files.php" class="quick-tile quick-tile-info">
        <span class="quick-tile__label"><i class="fas fa-folder-open me-1"></i>Soubory</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a data-shortcut="videos" href="<?= BASE_URL ?>/athlete_videos.php" class="quick-tile quick-tile-danger">
        <span class="quick-tile__label"><i class="fas fa-video me-1"></i>Videa</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <?php if ($athleteSpecialTrainingEnabled): ?>
    <a data-shortcut="events" href="<?= BASE_URL ?>/athlete_special_training.php" class="quick-tile quick-tile-info">
        <span class="quick-tile__label d-flex align-items-center flex-wrap gap-1"><i class="fas fa-flag-checkered me-1"></i>Events</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <?php else: ?>
    <div data-shortcut="events" class="quick-tile quick-tile-muted">
        <span class="quick-tile__label d-flex align-items-center flex-wrap gap-1"><i class="fas fa-lock me-1"></i>Events <span class="badge rounded-pill bg-secondary">Uzamčeno</span></span>
        <span class="quick-tile__value"><i class="fas fa-ban"></i></span>
    </div>
    <?php endif; ?>
    <a data-shortcut="online" href="<?= BASE_URL ?>/online_training.php" class="quick-tile quick-tile-warning">
        <span class="quick-tile__label"><i class="fas fa-laptop me-1"></i>Online tréninky</span>
        <span class="quick-tile__value"><?= (int)$onlineTrainingCount ?></span>
    </a>
    <a data-shortcut="calculators" href="<?= BASE_URL ?>/calculators.php" class="quick-tile quick-tile-muted">
        <span class="quick-tile__label d-flex align-items-center flex-wrap gap-1"><i class="fas fa-calculator me-1"></i>Kalkulačky <span class="badge rounded-pill bg-secondary">Ve vývoji</span></span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <?php if (mycoachAppEnabled() && $mycoachAppUserCanAccess): ?>
    <a data-shortcut="mycoach" href="<?= BASE_URL ?>/athlete_mycoach_app.php" class="quick-tile quick-tile-warning">
    <?php else: ?>
    <a data-shortcut="mycoach" href="#" class="quick-tile quick-tile-warning" data-bs-toggle="modal" data-bs-target="#modalMyCoachComingSoon">
    <?php endif; ?>
        <span class="quick-tile__label d-flex align-items-center flex-wrap gap-1"><i class="fas fa-brain me-1"></i>MyCoach <span class="badge rounded-pill <?= $mycoachAppUserCanAccess ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $mycoachAppUserCanAccess ? 'Pro' : 'Brzy' ?></span></span>
        <span class="quick-tile__value"><i class="fas fa-<?= $mycoachAppUserCanAccess ? 'star' : 'rocket' ?>"></i></span>
    </a>
    <?php if ($healthQuestionnaireAccessEnabled): ?>
    <a data-shortcut="health" href="<?= BASE_URL ?>/athlete_health_questionnaire.php" class="quick-tile <?= h($healthTileClass) ?>">
        <span class="quick-tile__label d-flex align-items-center flex-wrap gap-1"><i class="fas fa-heart-pulse me-1"></i>Z. dotazník</span>
        <span class="quick-tile__value"><?= $healthTileValue ?></span>
    </a>
    <?php else: ?>
    <div data-shortcut="health" class="quick-tile quick-tile-muted">
        <span class="quick-tile__label d-flex align-items-center flex-wrap gap-1"><i class="fas fa-lock me-1"></i>Z. dotazník <span class="badge rounded-pill bg-secondary">Vypnuto</span></span>
        <span class="quick-tile__value"><i class="fas fa-ban"></i></span>
    </div>
    <?php endif; ?>
    <a data-shortcut="manual" href="<?= BASE_URL ?>/athlete_manual.php" class="quick-tile quick-tile-success">
        <span class="quick-tile__label"><i class="fas fa-circle-question me-1"></i>Návod</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <?php $athleteSurveyPending = surveySchemaAvailable($pdo) && count(array_filter(surveyFetchForUser($pdo, 'athlete', $athleteId), static fn(array $survey): bool => empty($survey['response_id']))) > 0; ?>
    <a data-shortcut="surveys" href="<?= BASE_URL ?>/athlete_surveys.php" class="quick-tile quick-tile-info <?= $athleteSurveyPending ? 'survey-pending' : '' ?>">
        <span class="quick-tile__label"><i class="fas fa-square-poll-vertical me-1"></i>Ankety</span>
        <span class="quick-tile__value"><?= $athleteSurveyPending ? '<span class="badge rounded-pill bg-danger">!</span>' : '<i class="fas fa-chevron-right"></i>' ?></span>
    </a>
    <a data-shortcut="terms" href="<?= BASE_URL ?>/athlete_terms.php" class="quick-tile quick-tile-warning">
        <span class="quick-tile__label"><i class="fas fa-file-contract me-1"></i>Podmínky</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
</div>
<?php renderDashboardQuickMenu('athlete', (int)$athleteId, ob_get_clean()); ?>
