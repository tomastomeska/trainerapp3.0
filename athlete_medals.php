<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';
require_once __DIR__ . '/includes/health_questionnaire.php';
require_once __DIR__ . '/includes/medals.php';

requireAthleteLogin();

$pdo = getDB();
$athleteId = (int)getCurrentAthleteId();
healthQuestionnaireEnsureSchema($pdo);
$athleteMedals = athleteMedalsLoad($pdo, $athleteId);
$earnedMedalCount = count(array_filter(
    $athleteMedals,
    static fn(array $medal): bool => $medal['earned_at'] !== null
));

renderAthleteHeader('Moje ocenění', false, true, true);
?>
<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
    <div>
        <h2 class="mb-1"><i class="fas fa-medal me-2 text-warning"></i>Moje ocenění</h2>
        <div class="text-muted"><?= $earnedMedalCount ?> z <?= count($athleteMedals) ?> medailí získáno</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/athlete_dashboard.php">
        <i class="fas fa-arrow-left me-1"></i>Zpět na profil
    </a>
</div>

<div class="medal-grid">
    <?php foreach ($athleteMedals as $medal): ?>
        <?php renderAthleteMedalCard($medal); ?>
    <?php endforeach; ?>
</div>
<?php renderAthleteFooter(); ?>
