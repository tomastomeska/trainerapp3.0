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
$timeline = mycoachFetchDailyTimeline($pdo, $myCoachUserId, 45);
$readinessMetric = mycoachFetchLatestMetricValue($pdo, $myCoachUserId, 'readiness_score');
$latestAcwr = mycoachCalculateAcwr($timeline);
$recommendation = mycoachBuildRecommendation($readinessMetric && isset($readinessMetric['metric_value']) ? (int)round((float)$readinessMetric['metric_value']) : null, null);
$achievementBadges = mycoachBuildAchievementBadges($timeline, $readinessMetric && isset($readinessMetric['metric_value']) ? (int)round((float)$readinessMetric['metric_value']) : null, $latestAcwr['ratio'] ?? null);
$labels = array_map(static function (array $row): string {
    return formatDate((string)$row['entry_date']);
}, $timeline);
$readinessValues = array_map(static function (array $row): float {
    return isset($row['readiness_score']) ? (float)$row['readiness_score'] : 0.0;
}, $timeline);
$energyValues = array_map(static function (array $row): float {
    return isset($row['energy_score']) ? (float)$row['energy_score'] : 0.0;
}, $timeline);
$feelingValues = array_map(static function (array $row): float {
    return isset($row['feeling_score']) ? (float)$row['feeling_score'] : 0.0;
}, $timeline);

renderHeader('MyCoach grafy', true, true);
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-theme.css?v=20260804">
<script>document.body.classList.add('mycoach-theme', 'mycoach-theme--coach');</script>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 mc-topbar">
    <div>
        <h2 class="mb-1"><?php renderMyCoachAppLogoInline(); ?><i class="fas fa-chart-line me-2 text-warning"></i>MyCoach grafy</h2>
        <div class="text-muted">Vývoj readiness a denních hodnot</div>
    </div>
    <div class="d-flex gap-2 flex-wrap mc-actions">
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm fw-semibold"><i class="fas fa-house me-1"></i>Domů</a>
        <a href="<?= BASE_URL ?>/mycoach.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Zpět do MyCoach</a>
        <a href="<?= BASE_URL ?>/mycoach_athletes.php" class="btn btn-outline-light btn-sm fw-semibold"><i class="fas fa-users me-1"></i>Sportovci</a>
        <a href="<?= BASE_URL ?>/mycoach_export.php" class="btn btn-outline-primary btn-sm fw-semibold"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
    </div>
</div>

<ul class="nav nav-pills mb-4 flex-wrap gap-2 mc-pills">
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach.php"><i class="fas fa-house me-1"></i>Přehled</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_questionnaire.php"><i class="fas fa-clipboard-list me-1"></i>Dotazník</a></li>
    <li class="nav-item"><span class="nav-link active"><i class="fas fa-chart-line me-1"></i>Grafy</span></li>
    <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/mycoach_athletes.php"><i class="fas fa-users me-1"></i>Sportovci</a></li>
</ul>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Poslední readiness</div>
                <div class="fs-3 fw-bold text-success"><?= $readinessMetric && isset($readinessMetric['metric_value']) ? (int)round((float)$readinessMetric['metric_value']) . ' / 100' : 'zatím bez dat' ?></div>
                <div class="text-muted small"><?= $readinessMetric && !empty($readinessMetric['metric_date']) ? 'z ' . h(formatDate((string)$readinessMetric['metric_date'])) : 'Vyplň denní záznam.' ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-8">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-<?= h($recommendation['variant']) ?>">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Dnešní doporučení</div>
                <div class="fs-5 fw-bold"><?= h($recommendation['title']) ?></div>
                <div class="text-muted"><?= h($recommendation['text']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">ACWR</div>
                <?php if ($latestAcwr): ?>
                <div class="fs-3 fw-bold text-<?= h($latestAcwr['variant']) ?>"><?= h(number_format((float)$latestAcwr['ratio'], 2, ',', '')) ?></div>
                <div class="text-muted small"><?= h($latestAcwr['label']) ?></div>
                <?php else: ?>
                <div class="fs-5 fw-bold text-muted">zatím bez dat</div>
                <div class="text-muted small">Pro výpočet potřebuje MyCoach víc denních záznamů.</div>
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
                        <span class="badge bg-<?= h($badge['variant']) ?> px-3 py-2"><i class="fas <?= h($badge['icon']) ?> me-1"></i><?= h($badge['title']) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <span class="text-muted">Zatím žádné odznaky.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($timeline)): ?>
<div class="alert alert-info shadow-sm">Zatím nemáš uložená denní data pro grafy.</div>
<?php else: ?>
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white fw-bold"><i class="fas fa-chart-line me-2 text-warning"></i>Readiness v čase</div>
            <div class="card-body"><canvas id="readinessChart" style="max-height:340px"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-bold"><i class="fas fa-bolt me-2 text-warning"></i>Energie a pocit</div>
            <div class="card-body"><canvas id="feelingChart" style="max-height:300px"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white fw-bold"><i class="fas fa-bed me-2 text-warning"></i>Readiness trend</div>
            <div class="card-body"><canvas id="sleepChart" style="max-height:300px"></canvas></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white fw-bold"><i class="fas fa-table me-2"></i>Historie záznamů</div>
    <div class="table-responsive">
        <table class="table table-striped table-bordered mb-0 align-middle text-center">
            <thead class="table-light">
                <tr>
                    <th>Datum</th>
                    <th>Workout</th>
                    <th>Readiness</th>
                    <th>Pocit</th>
                    <th>Energie</th>
                    <th>Spánek</th>
                    <th>RPE</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($timeline) as $row): ?>
                <tr>
                    <td><?= h(formatDate((string)$row['entry_date'])) ?></td>
                    <td><?= h(mycoachWorkoutDisplayName($row)) ?></td>
                    <td><?= isset($row['readiness_score']) && $row['readiness_score'] !== null ? h((string)round((float)$row['readiness_score'])) : '—' ?></td>
                    <td><?= isset($row['feeling_score']) && $row['feeling_score'] !== null ? h((string)$row['feeling_score']) : '—' ?></td>
                    <td><?= isset($row['energy_score']) && $row['energy_score'] !== null ? h((string)$row['energy_score']) : '—' ?></td>
                    <td><?= isset($row['sleep_hours']) && $row['sleep_hours'] !== null ? h(number_format((float)$row['sleep_hours'], 1, ',', '')) : '—' ?></td>
                    <td><?= isset($row['rpe_score']) && $row['rpe_score'] !== null ? h((string)$row['rpe_score']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const readinessValues = <?= json_encode($readinessValues, JSON_UNESCAPED_UNICODE) ?>;
const energyValues = <?= json_encode($energyValues, JSON_UNESCAPED_UNICODE) ?>;
const feelingValues = <?= json_encode($feelingValues, JSON_UNESCAPED_UNICODE) ?>;

new Chart(document.getElementById('readinessChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [{
            label: 'Readiness / 100',
            data: readinessValues,
            borderColor: '#22c55e',
            backgroundColor: 'rgba(34, 197, 94, 0.15)',
            borderWidth: 3,
            tension: 0.25,
            fill: true
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, suggestedMax: 100 } } }
});

new Chart(document.getElementById('feelingChart'), {
    type: 'bar',
    data: {
        labels,
        datasets: [
            { label: 'Energie', data: energyValues, backgroundColor: 'rgba(245, 158, 11, 0.75)' },
            { label: 'Pocit', data: feelingValues, backgroundColor: 'rgba(34, 197, 94, 0.65)' }
        ]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 10 } } }
});

new Chart(document.getElementById('sleepChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [{
            label: 'Readiness',
            data: readinessValues,
            borderColor: '#60a5fa',
            backgroundColor: 'rgba(96, 165, 250, 0.15)',
            borderWidth: 3,
            tension: 0.25,
            fill: true
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, suggestedMax: 100 } } }
});
</script>
<?php endif; ?>

<?php renderFooter();
