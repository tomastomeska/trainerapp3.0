<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$sessionId = intParam($_GET, 'id');
$editMode  = (int)($_GET['edit'] ?? 0) === 1;
$pdo       = getDB();

// Načtení session
$stmt = $pdo->prepare(
    'SELECT ts.*, a.first_name, a.last_name, a.id AS athlete_id, a.email AS athlete_email,
            ws.name AS set_name
     FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
    WHERE ts.id = ? AND a.coach_id = ? AND ts.deleted_by_coach_at IS NULL'
);
$stmt->execute([$sessionId, $coachId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Trénink nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$sessionPhotos = getTrainingSessionPhotos($sessionId);
$mainPhotoFilename = (string)($session['training_photo'] ?? '');

// Kompatibilita: starší tréninky mohly mít jen training_photo bez záznamu v galerii.
$allSessionPhotos = $sessionPhotos;
if ($mainPhotoFilename !== '') {
    $hasMainInGallery = false;
    foreach ($allSessionPhotos as $row) {
        if ((string)($row['filename'] ?? '') === $mainPhotoFilename) {
            $hasMainInGallery = true;
            break;
        }
    }
    if (!$hasMainInGallery) {
        array_unshift($allSessionPhotos, [
            'id' => 0,
            'filename' => $mainPhotoFilename,
            'sort_order' => 0,
            'created_at' => $session['completed_at'] ?? $session['started_at'] ?? null,
        ]);
    }
}

// Načtení cviků v session snapshotu (fallback pro starší data)
$exercises = getSessionExercises($sessionId, (int)$session['workout_set_id']);

$sportTypes = array_values(array_unique(array_filter(array_map(
    fn($ex) => $ex['sport_type'] ?? 'standard',
    $exercises
))));
$primarySportType = $sportTypes[0] ?? 'standard';

// Načtení sérií
$seriesByExercise = [];
$totalSeries      = 0;
foreach ($exercises as $ex) {
    $s = getSeriesForExercise($sessionId, $ex['exercise_id']);
    $seriesByExercise[$ex['exercise_id']] = $s;
    $totalSeries += count($s);
}

$athleteName = h($session['first_name'] . ' ' . $session['last_name']);

$standardExercises = array_values(array_filter(
    $exercises,
    static fn(array $exercise): bool => (($exercise['sport_type'] ?? 'standard') === 'standard')
));
$hasRunOutdoor = in_array('run_outdoor', $sportTypes, true);
$hasRunTreadmill = in_array('run_treadmill', $sportTypes, true);
$hasGolf = in_array('golf', $sportTypes, true);

$runOutdoor = null;
$runOutdoorSplits = [];
$runTreadmill = null;
$golfSession = null;
$golfHoles = [];

if ($hasRunOutdoor) {
    $runOutdoor = getRunOutdoorSessionByTrainingSession($sessionId);
    if ($runOutdoor) {
        $runOutdoorSplits = getRunOutdoorSplits((int)$runOutdoor['id']);
    }
}

if ($hasRunTreadmill) {
    $runTreadmill = getRunTreadmillSessionByTrainingSession($sessionId);
}

if ($hasGolf) {
    $golfSession = getGolfSessionByTrainingSession($sessionId);
    if ($golfSession) {
        $golfHoles = getGolfHoles((int)$golfSession['id']);
    }
}

renderHeader('Detail tréninku');
?>

<div class="d-flex align-items-center mb-3 gap-3 flex-wrap page-header">
    <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $session['athlete_id'] ?>"
       class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Zpět
    </a>
    <div>
        <h2 class="mb-0 fw-bold">
            <i class="fas fa-clipboard-list me-2 text-warning"></i>
            <?= $athleteName ?>
        </h2>
        <span class="badge bg-warning text-dark me-1"><?= h($session['set_name']) ?></span>
        <?php if ($session['paired_session_id']): ?>
        <span class="badge bg-info text-dark me-1">
            <i class="fas fa-people-group me-1"></i>Párový trénink
        </span>
        <?php endif; ?>
        <?= formatDateTime($session['completed_at'] ?? $session['started_at']) ?>
        <?php if ($session['location']): ?>
        <span class="ms-2 text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= h($session['location']) ?></span>
        <?php endif; ?>
    </div>
    <div class="ms-auto d-flex gap-2 flex-wrap training-detail-actions">
        <?php if ($session['completed_at']): ?>
            <?php if ($editMode): ?>
            <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $sessionId ?>"
               class="btn btn-outline-secondary btn-sm"
               onclick="allowEditModeLeave = true;">
                <i class="fas fa-lock me-1"></i>Ukončit editaci
            </a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $sessionId ?>&edit=1"
               class="btn btn-outline-warning btn-sm">
                <i class="fas fa-edit me-1"></i>Editovat
            </a>
            <?php endif; ?>
            <form method="post" action="<?= BASE_URL ?>/training_reopen.php" class="d-inline"
                  onsubmit="return confirm('Znovu otevřít tento trénink? Po úpravách ho bude potřeba znovu ukončit.');">
                <?= csrfField() ?>
                <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
                <button type="submit" class="btn btn-warning btn-sm fw-bold">
                    <i class="fas fa-unlock me-1"></i>Znovu otevřít
                </button>
            </form>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/export_csv.php?session_id=<?= $sessionId ?>"
           class="btn btn-outline-success btn-sm">
            <i class="fas fa-file-excel me-1"></i>Export CSV
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-print me-1"></i>Tisk / PDF
        </button>
        <?php if ($session['athlete_email'] && $session['completed_at']): ?>
        <a href="<?= BASE_URL ?>/send_email.php?session_id=<?= $sessionId ?>"
           class="btn btn-outline-primary btn-sm"
           onclick="return confirm('Odeslat souhrn tréninku na <?= h($session['athlete_email']) ?>?')">
            <i class="fas fa-envelope me-1"></i>Odeslat e-mailem
        </a>
        <?php endif; ?>
        <?php if (!$session['completed_at']): ?>
        <a href="<?= BASE_URL ?>/training_session.php?id=<?= $sessionId ?>"
           class="btn btn-warning btn-sm fw-bold">
            <i class="fas fa-play me-1"></i>Pokračovat
        </a>
        <?php endif; ?>
        <form method="post" action="<?= BASE_URL ?>/training_delete.php" class="d-inline"
              onsubmit="return confirm('Opravdu smazat tento trénink? V administraci půjde obnovit.');">
            <?= csrfField() ?>
            <input type="hidden" name="session_id" value="<?= (int)$sessionId ?>">
            <input type="hidden" name="redirect_to" value="<?= h(BASE_URL . '/athlete_detail.php?id=' . (int)$session['athlete_id']) ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm" title="Smazat trénink">
                <i class="fas fa-trash me-1"></i>Smazat
            </button>
        </form>
    </div>
</div>

<!-- Souhrn -->
<?php if ($primarySportType === 'run_outdoor' && $runOutdoor): ?>
<?php
    $durationSeconds = (int)($runOutdoor['duration_seconds'] ?? 0);
    $distanceKm = (float)($runOutdoor['distance_km'] ?? 0);
    $paceSeconds = ($durationSeconds > 0 && $distanceKm > 0) ? (int)round($durationSeconds / $distanceKm) : 0;
?>
<div class="row g-3 mb-4">
    <div class="col-sm-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= number_format($distanceKm, 2, ',', ' ') ?></div>
            <div class="text-muted">Km</div>
        </div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= gmdate('i:s', $durationSeconds) ?></div>
            <div class="text-muted">Čas</div>
        </div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= $paceSeconds > 0 ? gmdate('i:s', $paceSeconds) : '–' ?></div>
            <div class="text-muted">Tempo / km</div>
        </div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= h((string)($runOutdoor['max_speed'] ?? '–')) ?></div>
            <div class="text-muted">Max rychlost</div>
        </div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= h((string)($runOutdoor['calories_burned'] ?? '–')) ?></div>
            <div class="text-muted">Kalorie</div>
        </div>
    </div>
    <div class="col-sm-4 col-lg-2">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= count($runOutdoorSplits) ?></div>
            <div class="text-muted">Splitů</div>
        </div>
    </div>
</div>
<?php elseif ($primarySportType === 'run_treadmill' && $runTreadmill): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= number_format((float)$runTreadmill['distance_km'], 2, ',', ' ') ?></div>
            <div class="text-muted">Km</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= gmdate('H:i:s', (int)$runTreadmill['duration_seconds']) ?></div>
            <div class="text-muted">Čas</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= h((string)($runTreadmill['calories_burned'] ?? '–')) ?></div>
            <div class="text-muted">Kalorie</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= h((string)($runTreadmill['location'] ?? '–')) ?></div>
            <div class="text-muted">Místo</div>
        </div>
    </div>
</div>
<?php elseif ($primarySportType === 'golf' && $golfSession): ?>
<?php
    $totalPar = 0;
    $totalScore = 0;
    foreach ($golfHoles as $hole) {
        $totalPar += (int)$hole['par'];
        $totalScore += (int)($hole['score'] ?? 0);
    }
    $holeDiff = $totalScore > 0 ? $totalScore - $totalPar : 0;
?>
<div class="row g-3 mb-4">
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= count($golfHoles) ?></div>
            <div class="text-muted">Jamek</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= $totalPar ?></div>
            <div class="text-muted">Par celkem</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= $totalScore ?></div>
            <div class="text-muted">Skóre celkem</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= $holeDiff >= 0 ? '+' . $holeDiff : (string)$holeDiff ?></div>
            <div class="text-muted">Proti paru</div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= count($exercises) ?></div>
            <div class="text-muted">Cviků</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= $totalSeries ?></div>
            <div class="text-muted">Sérií celkem</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <?php
            $totalVolume = 0;
            foreach ($seriesByExercise as $sArr) {
                foreach ($sArr as $s) {
                    $totalVolume += ((float)$s['weight'] + (float)($s['equipment_weight'] ?? 0)) * $s['reps'];
                }
            }
            ?>
            <div class="display-6 fw-bold text-warning"><?= number_format($totalVolume, 0, ',', '&nbsp;') ?></div>
            <div class="text-muted">Celkový objem (kg×rep)</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Cviky a série / speciální sporty -->
<?php if ($hasRunOutdoor && $runOutdoor): ?>
<div class="card border-0 shadow-sm mb-4 exercise-block">
    <div class="card-header bg-dark text-white d-flex align-items-center">
        <span class="badge bg-warning text-dark me-2 fs-5">1</span>
        <span class="fw-bold fs-5">Běh venku</span>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><th style="width:40%">Doba</th><td><?= gmdate('H:i:s', (int)$runOutdoor['duration_seconds']) ?></td></tr>
                        <tr><th>Vzdálenost</th><td><?= number_format((float)$runOutdoor['distance_km'], 2, ',', ' ') ?> km</td></tr>
                        <tr><th>Tempo</th><td><?= !empty($runOutdoor['avg_pace']) ? h($runOutdoor['avg_pace']) . ' /km' : '–' ?></td></tr>
                        <tr><th>Max rychlost</th><td><?= h((string)($runOutdoor['max_speed'] ?? '–')) ?> km/h</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><th style="width:40%">Typ běhu</th><td><?= h((string)($runOutdoor['run_type'] ?? '–')) ?></td></tr>
                        <tr><th>Povrch</th><td><?= h((string)($runOutdoor['surface'] ?? '–')) ?></td></tr>
                        <tr><th>Kalorie</th><td><?= h((string)($runOutdoor['calories_burned'] ?? '–')) ?></td></tr>
                        <tr><th>Kroky</th><td><?= h((string)($runOutdoor['step_count'] ?? '–')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($runOutdoor['feeling'])): ?>
        <div class="mb-3">
            <div class="fw-semibold mb-1">Pocit</div>
            <div class="text-muted"><?= h($runOutdoor['feeling']) ?></div>
        </div>
        <?php endif; ?>

        <h6 class="fw-bold mb-2">Splity</h6>
        <?php if (empty($runOutdoorSplits)): ?>
        <div class="text-center py-3 text-muted">Žádné splity.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-bordered mb-0 align-middle text-center">
                <thead class="table-light">
                    <tr>
                        <th>Km</th>
                        <th>Čas</th>
                        <th>Tempo</th>
                        <th>Max rychlost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runOutdoorSplits as $split): ?>
                    <tr>
                        <td class="fw-bold"><?= number_format((float)$split['km_marker'], 2, ',', ' ') ?></td>
                        <td><?= h((string)$split['split_time']) ?></td>
                        <td><?= h((string)($split['pace'] ?? '–')) ?></td>
                        <td><?= h((string)($split['max_speed_at_km'] ?? '–')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($hasRunTreadmill && $runTreadmill): ?>
<div class="card border-0 shadow-sm mb-4 exercise-block">
    <div class="card-header bg-dark text-white d-flex align-items-center">
        <span class="badge bg-warning text-dark me-2 fs-5">1</span>
        <span class="fw-bold fs-5">Běh na páse</span>
    </div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tbody>
                <tr><th style="width:35%">Doba</th><td><?= gmdate('H:i:s', (int)$runTreadmill['duration_seconds']) ?></td></tr>
                <tr><th>Vzdálenost</th><td><?= number_format((float)$runTreadmill['distance_km'], 2, ',', ' ') ?> km</td></tr>
                <tr><th>Tempo</th><td><?= ((float)$runTreadmill['distance_km'] > 0) ? gmdate('i:s', (int)round(((int)$runTreadmill['duration_seconds']) / (float)$runTreadmill['distance_km'])) . ' /km' : '–' ?></td></tr>
                <tr><th>Kalorie</th><td><?= h((string)($runTreadmill['calories_burned'] ?? '–')) ?></td></tr>
                <tr><th>Místo</th><td><?= h((string)($runTreadmill['location'] ?? '–')) ?></td></tr>
                <?php if (!empty($runTreadmill['feeling'])): ?>
                <tr><th>Pocit</th><td><?= h((string)$runTreadmill['feeling']) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($hasGolf && $golfSession): ?>
<?php
    $totalPar = 0;
    $totalScore = 0;
    foreach ($golfHoles as $hole) {
        $totalPar += (int)$hole['par'];
        $totalScore += (int)($hole['score'] ?? 0);
    }
?>
<div class="card border-0 shadow-sm mb-4 exercise-block">
    <div class="card-header bg-dark text-white d-flex align-items-center">
        <span class="badge bg-warning text-dark me-2 fs-5">1</span>
        <span class="fw-bold fs-5">Golf</span>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><th style="width:40%">Hřiště</th><td><?= h((string)($golfSession['course_name'] ?? '–')) ?></td></tr>
                        <tr><th>Odpaliště</th><td><?= h((string)($golfSession['tee_name'] ?? '–')) ?></td></tr>
                        <tr><th>Typ hry</th><td><?= h((string)($golfSession['game_type'] ?? '–')) ?></td></tr>
                        <tr><th>Jamky</th><td><?= h((string)($golfSession['num_holes'] ?? '–')) ?></td></tr>
                        <tr><th>Doba</th><td><?= !empty($golfSession['duration_minutes']) ? (int)$golfSession['duration_minutes'] . ' min' : '–' ?></td></tr>
                        <tr><th>HCP před</th><td><?= $golfSession['handicap_before'] !== null ? number_format((float)$golfSession['handicap_before'], 1, ',', ' ') : '–' ?></td></tr>
                        <tr><th>HCP po</th><td><?= $golfSession['handicap_after'] !== null ? number_format((float)$golfSession['handicap_after'], 1, ',', ' ') : '–' ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><th style="width:40%">Km</th><td><?= isset($golfSession['distance_km']) ? number_format((float)$golfSession['distance_km'], 2, ',', ' ') . ' km' : '–' ?></td></tr>
                        <tr><th>Kalorie</th><td><?= h((string)($golfSession['calories_burned'] ?? '–')) ?></td></tr>
                        <tr><th>Do HCP</th><td><?= (int)($golfSession['count_for_handicap'] ?? 1) === 1 ? 'Ano' : 'Ne' ?></td></tr>
                        <tr><th>Diferenciál</th><td><?= $golfSession['score_differential'] !== null ? number_format((float)$golfSession['score_differential'], 1, ',', ' ') : '–' ?></td></tr>
                        <tr><th>Pocit</th><td><?= h((string)($golfSession['feeling'] ?? '–')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-4"><div class="alert alert-light border mb-0">Par celkem: <strong><?= $totalPar ?></strong></div></div>
            <div class="col-md-4"><div class="alert alert-light border mb-0">Skóre celkem: <strong><?= $totalScore ?></strong></div></div>
            <div class="col-md-4"><div class="alert alert-light border mb-0">Proti paru: <strong><?= $totalScore > 0 ? (($totalScore - $totalPar) >= 0 ? '+' : '') . ($totalScore - $totalPar) : '–' ?></strong></div></div>
        </div>

        <h6 class="fw-bold mb-2">Jamky</h6>
        <?php if (empty($golfHoles)): ?>
        <div class="text-center py-3 text-muted">Žádné jamky.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-bordered mb-0 align-middle text-center">
                <thead class="table-light">
                    <tr>
                        <th>Jamka</th>
                        <th>Par</th>
                        <th>Skóre</th>
                        <th>Poznámka</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($golfHoles as $hole): ?>
                    <tr>
                        <td class="fw-bold"><?= (int)$hole['hole_number'] ?></td>
                        <td><?= (int)$hole['par'] ?></td>
                        <td><?= h((string)($hole['score'] ?? '–')) ?></td>
                        <td class="text-start"><?= h((string)($hole['notes'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php foreach ($standardExercises as $ex): ?>
<?php $series = $seriesByExercise[$ex['exercise_id']] ?? []; ?>
<div class="card border-0 shadow-sm mb-4 exercise-block" id="ex-<?= $ex['exercise_id'] ?>">
    <div class="card-header bg-dark text-white d-flex align-items-center">
        <span class="badge bg-warning text-dark me-2 fs-5"><?= $ex['exercise_order'] ?></span>
        <span class="fw-bold fs-5"><?= h($ex['exercise_name']) ?></span>
        <?php if ($series && empty($ex['is_timed'])): ?>
        <?php
        $maxW  = max(array_map(fn($row) => (float)$row['weight'] + (float)($row['equipment_weight'] ?? 0), $series));
        $maxR  = max(array_column($series, 'reps'));
        ?>
        <div class="ms-auto small text-secondary">
            Max váha: <strong class="text-warning"><?= number_format($maxW, 1, ',', '') ?> kg</strong>
            &nbsp;|&nbsp; Max opak.: <strong class="text-warning"><?= $maxR ?></strong>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($series)): ?>
        <div class="text-center py-3 text-muted">Žádné série.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-bordered mb-0 align-middle text-center">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <?php if (!empty($ex['is_timed'])): ?>
                        <th>Čas</th>
                        <th>Váha náčiní (kg)</th>
                        <?php else: ?>
                        <th>Váha (kg)</th>
                        <th>Opakování</th>
                        <th>Dopomoc</th>
                        <th>Objem</th>
                        <?php endif; ?>
                        <?php if ($editMode): ?><th style="width:50px"></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="series-body-<?= $ex['exercise_id'] ?>">
                    <?php foreach ($series as $s): ?>
                    <tr id="series-row-<?= $s['id'] ?>">
                        <td class="fw-bold text-muted"><?= $s['series_order'] ?></td>
                        <?php if (!empty($ex['is_timed'])): ?>
                        <td class="fw-bold"><?= !empty($s['duration_seconds']) ? formatSeriesDuration((int)$s['duration_seconds']) : '–' ?></td>
                        <?php $seriesTimedLoad = (float)$s['weight'] + (float)($s['equipment_weight'] ?? 0); ?>
                        <td class="fw-bold"><?= $seriesTimedLoad > 0 ? number_format($seriesTimedLoad, 1, ',', '') : '–' ?></td>
                        <?php else: ?>
                        <?php $seriesWeightTotal = (float)$s['weight'] + (float)($s['equipment_weight'] ?? 0); ?>
                        <td class="fw-bold">
                            <?= number_format($seriesWeightTotal, 1, ',', '') ?> kg
                            <?php if (!empty($s['equipment_weight'])): ?>
                            <div class="small text-muted">vč. náčiní <?= number_format((float)$s['equipment_weight'], 1, ',', '') ?> kg</div>
                            <?php endif; ?>
                        </td>
                        <td><?= $s['reps'] ?></td>
                        <td>
                            <?php if ($s['assistance_reps'] > 0): ?>
                            <span class="badge bg-warning text-dark"><?= $s['assistance_reps'] ?></span>
                            <?php else: ?>
                            <span class="text-muted">–</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= number_format(((float)$s['weight'] + (float)($s['equipment_weight'] ?? 0)) * $s['reps'], 0, ',', '') ?></td>
                        <?php endif; ?>
                        <?php if ($editMode): ?>
                        <td>
                            <button class="btn btn-outline-secondary btn-sm mb-1"
                                    onclick="editSeriesPrompt(<?= (int)$s['id'] ?>, <?= (int)$ex['exercise_id'] ?>, <?= !empty($ex['is_timed']) ? 1 : 0 ?>, <?= (float)$s['weight'] ?>, <?= (float)($s['equipment_weight'] ?? 0) ?>, <?= (int)$s['reps'] ?>, <?= (int)$s['assistance_reps'] ?>, <?= (int)($s['duration_seconds'] ?? 0) ?>)"
                                    title="Upravit sérii">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="btn btn-outline-danger btn-sm"
                                    onclick="deleteSeries(<?= $s['id'] ?>, <?= $ex['exercise_id'] ?>)"
                                    title="Smazat sérii">
                                <i class="fas fa-times"></i>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if (empty($ex['is_timed'])): ?>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="4" class="text-end fw-semibold">Objem celkem</td>
                        <td class="fw-bold">
                            <?= number_format(array_sum(array_map(fn($s) => ((float)$s['weight'] + (float)($s['equipment_weight'] ?? 0)) * $s['reps'], $series)), 0, ',', '') ?>
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php endif; ?>
        <!-- Formulář pro editaci sérií -->
        <?php if ($editMode): ?>
        <div class="p-3 border-top bg-light">
            <!-- Přidání nové série -->
            <div class="add-series-row" id="add-series-form-<?= $ex['exercise_id'] ?>">
                <h6 class="fw-bold mb-2 text-muted">Přidat sérii</h6>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                    <div>
                        <label class="form-label small fw-semibold mb-1">Váha (kg)</label>
                        <input type="number" step="0.5" min="0" max="999"
                               class="form-control form-control-sm series-weight"
                               id="weight-<?= $ex['exercise_id'] ?>"
                               placeholder="80" style="width:90px">
                    </div>
                    <?php if (empty($ex['is_timed'])): ?>
                    <div>
                        <label class="form-label small fw-semibold mb-1">Opakování</label>
                        <input type="number" step="1" min="0" max="999"
                               class="form-control form-control-sm series-reps"
                               id="reps-<?= $ex['exercise_id'] ?>"
                               placeholder="10" style="width:90px">
                    </div>
                    <div>
                        <label class="form-label small fw-semibold mb-1">Dopomoc</label>
                        <input type="number" step="1" min="0" max="999"
                               class="form-control form-control-sm series-assist"
                               id="assist-<?= $ex['exercise_id'] ?>"
                               placeholder="0" style="width:80px">
                    </div>
                    <?php else: ?>
                    <div>
                        <label class="form-label small fw-semibold mb-1">Čas</label>
                        <div class="d-flex align-items-center gap-1">
                            <select class="form-select form-select-sm" id="time-min-<?= $ex['exercise_id'] ?>" style="width:85px">
                                <?php for ($m = 0; $m <= 60; $m++): ?>
                                <option value="<?= $m ?>"><?= sprintf('%02d', $m) ?> min</option>
                                <?php endfor; ?>
                            </select>
                            <select class="form-select form-select-sm" id="time-sec-<?= $ex['exercise_id'] ?>" style="width:85px">
                                <?php for ($s = 0; $s <= 60; $s++): ?>
                                <option value="<?= $s ?>"><?= sprintf('%02d', $s) ?> s</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="form-label small fw-semibold mb-1">Váha náčiní (kg)</label>
                        <input type="number" step="0.5" min="0" max="999"
                               class="form-control form-control-sm series-equipment-weight"
                               id="equipment-weight-<?= $ex['exercise_id'] ?>"
                               placeholder="10" style="width:120px">
                        <div class="form-text small">Bude přičteno k celkové váze.</div>
                    </div>
                    <div>
                        <button type="button"
                                class="btn btn-warning fw-bold"
                                onclick="addSeries(<?= $ex['exercise_id'] ?>, <?= $sessionId ?>)">
                            <i class="fas fa-plus me-1"></i>Přidat
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if ($session['notes']): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white"><i class="fas fa-sticky-note me-2"></i>Poznámka</div>
    <div class="card-body"><?= h($session['notes']) ?></div>
</div>
<?php endif; ?>

<?php if (!empty($session['training_photo']) || !empty($allSessionPhotos)): ?>
<div class="card border-0 shadow-sm mb-4" id="training-photo">
    <div class="card-header bg-dark text-white d-flex align-items-center">
        <span><i class="fas fa-camera me-2"></i>Fotografie z tréninku</span>
        <span class="badge bg-light text-dark ms-2"><?= count($allSessionPhotos) ?></span>
        <div class="ms-auto d-flex gap-2">
            <?php if (!empty($session['training_photo'])): ?>
            <!-- Změnit fotku -->
            <label class="btn btn-outline-warning btn-sm mb-0" title="Změnit fotografii">
                <i class="fas fa-exchange-alt me-1"></i>Změnit
                <form method="post" action="<?= BASE_URL ?>/training_photo_update.php"
                      enctype="multipart/form-data" class="d-none" id="changePhotoForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="file" name="training_photo" accept="image/*" capture="environment"
                           onchange="this.form.submit()">
                </form>
            </label>
            <!-- Smazat fotku -->
            <form method="post" action="<?= BASE_URL ?>/training_photo_update.php"
                  onsubmit="return confirm('Opravdu smazat fotografii tréninku?')">
                <?= csrfField() ?>
                <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-trash me-1"></i>Smazat
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <form method="post" action="<?= BASE_URL ?>/training_photo_update.php" enctype="multipart/form-data" class="mb-3">
            <?= csrfField() ?>
            <input type="hidden" name="session_id" value="<?= $sessionId ?>">
            <input type="hidden" name="action" value="add_gallery">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <input type="file" name="training_photos[]" id="addGalleryPhotosInput"
                       accept="image/*" multiple class="d-none"
                       onchange="previewAddGalleryPhotos(this)">
                <label for="addGalleryPhotosInput" class="btn btn-outline-warning mb-0">
                    <i class="fas fa-images me-1"></i>Přidat fotky z galerie
                </label>
                <span id="addGalleryPhotoCount" class="text-muted small fst-italic">Nejsou vybrány nové fotky</span>
                <button type="submit" class="btn btn-outline-success">
                    <i class="fas fa-upload me-1"></i>Nahrát vybrané
                </button>
            </div>
            <div id="add-gallery-preview" class="small text-muted mt-2 d-none"></div>
        </form>

        <?php if (!empty($allSessionPhotos)): ?>
        <div class="photo-gallery-grid">
            <?php foreach ($allSessionPhotos as $photo): ?>
            <?php
            $filename = (string)($photo['filename'] ?? '');
            $isMain = $filename !== '' && $filename === $mainPhotoFilename;
            ?>
            <div class="photo-gallery-item border rounded p-2 d-flex flex-column">
                <a href="<?= h(photoUrl($filename, 'trainings')) ?>" target="_blank" rel="noopener noreferrer" class="d-block mb-2 photo-gallery-link">
                    <img src="<?= h(photoUrl($filename, 'trainings')) ?>"
                         alt="Fotografie z tréninku"
                         class="img-fluid rounded photo-gallery-img">
                </a>
                <div class="d-flex align-items-center gap-2 mt-auto">
                    <?php if ($isMain): ?>
                    <span class="badge bg-warning text-dark">Hlavní fotka</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">Galerie</span>
                    <?php endif; ?>

                    <?php if ((int)($photo['id'] ?? 0) > 0): ?>
                    <form method="post" action="<?= BASE_URL ?>/training_photo_update.php" class="ms-auto"
                          onsubmit="return confirm('Opravdu smazat tuto fotografii?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="session_id" value="<?= $sessionId ?>">
                        <input type="hidden" name="action" value="delete_gallery">
                        <input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="fas fa-trash me-1"></i>Smazat
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php elseif ($session['completed_at']): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white"><i class="fas fa-camera me-2"></i>Fotografie z tréninku</div>
    <div class="card-body">
        <p class="text-muted mb-3">K tomuto tréninku zatím není přiřazena žádná fotografie.</p>
        <form method="post" action="<?= BASE_URL ?>/training_photo_update.php"
              enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="session_id" value="<?= $sessionId ?>">
            <input type="hidden" name="action" value="add_gallery">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <input type="file" name="training_photos[]" id="addPhotoInput"
                       accept="image/*" multiple
                       class="d-none"
                       onchange="previewAddPhoto(this)">
                <label for="addPhotoInput" class="btn btn-outline-warning mb-0">
                    <i class="fas fa-images me-1"></i>Vybrat fotky
                </label>
                <span id="addPhotoName" class="text-muted small fst-italic">Soubory nevybrány</span>
                <button type="submit" class="btn btn-outline-success">
                    <i class="fas fa-save me-1"></i>Uložit fotografie
                </button>
            </div>
            <img id="add-photo-preview" class="img-fluid rounded border mt-3 d-none"
                 alt="Náhled" style="max-height:180px; object-fit:cover;">
        </form>
    </div>
</div>
    <?php endif; ?>
<script>
const isEditMode = <?= $editMode ? 'true' : 'false' ?>;
let allowEditModeLeave = false;

window.addEventListener('beforeunload', function (event) {
    if (!isEditMode || allowEditModeLeave) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
});

function previewAddPhoto(input) {
    const preview  = document.getElementById('add-photo-preview');
    const nameSpan = document.getElementById('addPhotoName');
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (nameSpan) {
        nameSpan.textContent = input.files.length === 1
            ? file.name
            : ('Vybráno souborů: ' + input.files.length);
    }
    if (!preview) return;

    // HEIC a jiné formáty nepodporované prohlížečem nelze zobrazit jako náhled
    const previewable = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!previewable.includes(file.type.toLowerCase())) {
        preview.classList.add('d-none');
        preview.removeAttribute('src');
        return;
    }

    const reader = new FileReader();
    reader.onload = e => { preview.src = e.target.result; preview.classList.remove('d-none'); };
    reader.readAsDataURL(file);
}

function previewAddGalleryPhotos(input) {
    const countSpan = document.getElementById('addGalleryPhotoCount');
    const previewInfo = document.getElementById('add-gallery-preview');
    if (!countSpan || !previewInfo) {
        return;
    }

    const files = input.files ? Array.from(input.files) : [];
    if (files.length === 0) {
        countSpan.textContent = 'Nejsou vybrány nové fotky';
        previewInfo.classList.add('d-none');
        previewInfo.textContent = '';
        return;
    }

    countSpan.textContent = 'Vybráno souborů: ' + files.length;
    previewInfo.classList.remove('d-none');
    previewInfo.textContent = 'K nahrání je připraveno ' + files.length + ' fotografií.';
}

function parseSeriesDuration(value) {
    const raw = String(value || '').trim();
    if (!raw) return 0;
    const parts = raw.split(':').map(part => parseInt(part, 10));
    if (parts.some(Number.isNaN)) return 0;
    if (parts.length === 3) return (parts[0] * 3600) + (parts[1] * 60) + parts[2];
    if (parts.length === 2) return (parts[0] * 60) + parts[1];
    return parts[0];
}

function formatSeriesDurationJs(seconds) {
    const total = Math.max(0, parseInt(seconds, 10) || 0);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const restSeconds = total % 60;
    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(restSeconds).padStart(2, '0')}`;
    }
    return `${minutes}:${String(restSeconds).padStart(2, '0')}`;
}

function durationFromMinuteSecondInputs(minEl, secEl) {
    const minutes = parseInt(minEl?.value || '0', 10) || 0;
    const seconds = parseInt(secEl?.value || '0', 10) || 0;
    return Math.max(0, minutes * 60 + seconds);
}

// Přidání série
function addSeries(exerciseId, sessionId) {
    const isTimed = document.getElementById('time-min-' + exerciseId) !== null;
    const weightInput = document.getElementById('weight-' + exerciseId);
    const equipmentWeightInput = document.getElementById('equipment-weight-' + exerciseId);
    const repsInput   = document.getElementById('reps-' + exerciseId);
    const assistInput = document.getElementById('assist-' + exerciseId);
    const timeMinInput = document.getElementById('time-min-' + exerciseId);
    const timeSecInput = document.getElementById('time-sec-' + exerciseId);

    const weight = isTimed ? 0 : (parseFloat(weightInput.value) || 0);
    const equipmentWeight = parseFloat(equipmentWeightInput.value) || 0;
    const reps   = isTimed ? 0 : (parseInt(repsInput.value) || 0);
    const assist = isTimed ? 0 : (parseInt(assistInput.value) || 0);
    const durationSeconds = isTimed ? durationFromMinuteSecondInputs(timeMinInput, timeSecInput) : 0;

    if (isTimed && durationSeconds <= 0) {
        alert('Zadejte čas série pomocí minut a vteřin.');
        return;
    }

    if (!isTimed && weight <= 0 && reps <= 0) {
        alert('Zadej alespoň váhu nebo opakování');
        return;
    }

    const seriesOrderInput = document.querySelector('#series-body-' + exerciseId + ' tr:last-child td:first-child');
    let seriesOrder = seriesOrderInput ? parseInt(seriesOrderInput.textContent) + 1 : 1;

    const payload = {
        csrf_token: '<?= csrfToken() ?>',
        session_id: sessionId,
        exercise_id: exerciseId,
        series_order: seriesOrder,
        weight: weight,
        equipment_weight: equipmentWeight,
        reps: reps,
        assistance_reps: assist,
        duration_seconds: durationSeconds
    };
    
    fetch('<?= BASE_URL ?>/api/save_series.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Zůstaň v režimu editace, dokud uživatel ručně neukončí editaci.
            allowEditModeLeave = true;
            window.location.href = '<?= BASE_URL ?>/training_detail.php?id=' + sessionId + '&edit=1';
        } else {
            alert('Chyba: ' + (data.error || 'Neznámá chyba'));
        }
    })
    .catch(err => {
        alert('Chyba: ' + err.message);
    });
}

function editSeriesPrompt(seriesId, exerciseId, isTimed, currentWeight, currentEquipmentWeight, currentReps, currentAssist, currentDurationSeconds) {
    let weight = parseFloat(currentWeight) || 0;
    let equipmentWeight = parseFloat(currentEquipmentWeight) || 0;
    let reps = parseInt(currentReps, 10) || 0;
    let assist = parseInt(currentAssist, 10) || 0;
    let durationSeconds = parseInt(currentDurationSeconds, 10) || 0;

    if (isTimed) {
        const timeInput = prompt('Čas série (mm:ss)', durationSeconds > 0 ? formatSeriesDurationJs(durationSeconds) : '');
        if (timeInput === null) return;
        durationSeconds = parseSeriesDuration(timeInput);
        if (durationSeconds <= 0) {
            alert('Neplatný čas série.');
            return;
        }
        const equipmentInput = prompt('Váha náčiní (kg, volitelně)', equipmentWeight > 0 ? String(equipmentWeight).replace('.', ',') : '');
        if (equipmentInput === null) return;
        equipmentWeight = parseFloat(String(equipmentInput).replace(',', '.')) || 0;
        weight = 0;
        reps = 0;
        assist = 0;
    } else {
        const weightInput = prompt('Váha (kg)', String(weight).replace('.', ','));
        if (weightInput === null) return;
        weight = parseFloat(String(weightInput).replace(',', '.')) || 0;

        const repsInput = prompt('Opakování', String(reps));
        if (repsInput === null) return;
        reps = parseInt(repsInput, 10) || 0;

        const assistInput = prompt('Dopomoc', String(assist));
        if (assistInput === null) return;
        assist = parseInt(assistInput, 10) || 0;

        const equipmentInput = prompt('Váha náčiní (kg, volitelně)', equipmentWeight > 0 ? String(equipmentWeight).replace('.', ',') : '');
        if (equipmentInput === null) return;
        equipmentWeight = parseFloat(String(equipmentInput).replace(',', '.')) || 0;
        durationSeconds = 0;
    }

    fetch('<?= BASE_URL ?>/api/update_series.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf_token: '<?= csrfToken() ?>',
            series_id: seriesId,
            weight: weight,
            equipment_weight: equipmentWeight,
            reps: reps,
            assistance_reps: assist,
            duration_seconds: durationSeconds
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            allowEditModeLeave = true;
            window.location.href = '<?= BASE_URL ?>/training_detail.php?id=<?= $sessionId ?>&edit=1';
        } else {
            alert('Chyba: ' + (data.error || 'Neznámá chyba'));
        }
    })
    .catch(err => alert('Chyba: ' + err.message));
}

// Smazání série
function deleteSeries(seriesId, exerciseId) {
    if (!confirm('Opravdu smazat sérii?')) return;

    fetch('<?= BASE_URL ?>/api/delete_series.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf_token: '<?= csrfToken() ?>',
            series_id: seriesId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('series-row-' + seriesId);
            if (row) row.remove();
            // Zůstaň v režimu editace, dokud uživatel ručně neukončí editaci.
            allowEditModeLeave = true;
            window.location.href = '<?= BASE_URL ?>/training_detail.php?id=<?= $sessionId ?>&edit=1';
        } else {
            alert('Chyba: ' + (data.error || 'Neznámá chyba'));
        }
    })
    .catch(err => alert('Chyba: ' + err.message));
}
</script>

<style>
.photo-gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
    gap: 12px;
}

.photo-gallery-img {
    width: 100%;
    height: 220px;
    object-fit: cover;
    background: #f4f4f5;
}

@media (max-width: 575.98px) {
    .photo-gallery-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .photo-gallery-img {
        height: 160px;
    }
}
</style>

<style>
@media print {
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; box-sizing: border-box; }
    @page { margin: 12mm 10mm; size: A4 portrait; }

    /* Zaklad */
    html, body { font-size: 8.5pt; font-family: 'Segoe UI', Arial, sans-serif; color: #111; background: #fff; margin: 0; }
    .container, .container-fluid { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }

    /* Skryte prvky */
    .navbar, footer, .btn, .training-detail-actions,
    form[action*="photo_update"], label[for="addPhotoInput"],
    #add-photo-preview, #addPhotoName, .training-finish-btn,
    .alert { display: none !important; }

    /* Hlavicka stranky - kompaktni jeden radek */
    .page-header {
        display: flex !important;
        align-items: baseline;
        flex-wrap: wrap;
        gap: 6px;
        border-bottom: 2.5px solid #f59e0b;
        padding-bottom: 5px;
        margin-bottom: 8px !important;
    }
    .page-header h2 {
        font-size: 12pt !important;
        margin: 0;
        flex: none;
    }
    .page-header h2 i { display: none; }
    .page-header .badge {
        font-size: 7.5pt !important;
        padding: 1px 5px;
        border: 1px solid #f59e0b;
        color: #92400e !important;
        background: #fef3c7 !important;
        border-radius: 4px;
    }
    .page-header .text-muted { font-size: 7.5pt; }
    .page-header .ms-auto { display: none !important; }

    /* Souhrn: 3 boxy v jednom radku bez karet */
    .row.g-3.mb-4 {
        display: flex !important;
        gap: 0 !important;
        margin-bottom: 8px !important;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        overflow: hidden;
    }
    .row.g-3.mb-4 .col-sm-4 { flex: 1; padding: 0 !important; }
    .row.g-3.mb-4 .card {
        border: none !important;
        border-right: 1px solid #e5e7eb !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        padding: 5px 0 !important;
        margin: 0 !important;
    }
    .row.g-3.mb-4 .col-sm-4:last-child .card { border-right: none !important; }
    .row.g-3.mb-4 .display-6 { font-size: 13pt !important; margin: 0; line-height: 1.2; }
    .row.g-3.mb-4 .text-muted { font-size: 7pt; }
    .row.g-3.mb-4 .py-3 { padding: 4px 0 !important; }

    /* Cviky - ultra kompaktni */
    .exercise-block {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
        border-radius: 4px !important;
        margin-bottom: 5px !important;
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .exercise-block .card-header {
        background: #1e2937 !important;
        color: #fff !important;
        padding: 3px 8px !important;
        font-size: 8.5pt;
        display: flex !important;
        align-items: center;
        border-radius: 4px 4px 0 0 !important;
        line-height: 1.3;
    }
    .exercise-block .card-header .badge {
        background: #f59e0b !important;
        color: #111 !important;
        font-size: 8pt;
        margin-right: 5px;
        padding: 1px 5px;
        border-radius: 3px;
        flex: none;
    }
    .exercise-block .card-header .fw-bold { font-size: 8.5pt; }
    .exercise-block .card-header .ms-auto {
        font-size: 7pt;
        color: #aaa !important;
        white-space: nowrap;
    }
    .exercise-block .card-header .text-warning { color: #fbbf24 !important; }
    .exercise-block .card-header .fs-5 { font-size: 8.5pt !important; }

    /* Tabulky serií - maximalne husté */
    .table-responsive { overflow: visible !important; }
    .table { font-size: 7.5pt !important; margin: 0 !important; width: 100% !important; }
    .table thead th {
        background: #f3f4f6 !important;
        font-size: 7pt !important;
        font-weight: 700;
        padding: 2px 6px !important;
        border-color: #dee2e6 !important;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        color: #374151;
    }
    .table tbody td { padding: 2px 6px !important; border-color: #e5e7eb !important; }
    .table-striped tbody tr:nth-child(odd) td { background: #f9fafb !important; }
    .table tfoot td {
        background: #1e2937 !important;
        color: #fff !important;
        font-size: 7pt !important;
        padding: 2px 6px !important;
        border-color: #374151 !important;
    }
    .badge.bg-warning { background: #fef3c7 !important; color: #92400e !important; border: 1px solid #f59e0b; font-size: 7pt; padding: 0 4px; }

    /* Karta Poznamka */
    .card.border-0.shadow-sm:not(.exercise-block) {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
        border-radius: 4px !important;
        margin-bottom: 5px !important;
        break-inside: avoid;
    }
    .card-header.bg-dark {
        background: #1e2937 !important;
        color: #fff !important;
        padding: 3px 8px !important;
        font-size: 8pt;
        border-radius: 4px 4px 0 0 !important;
    }
    .card-body { padding: 5px 8px !important; }

    /* Fotografie */
    #training-photo { break-inside: avoid; }
    #training-photo .card-header { border-radius: 4px 4px 0 0 !important; }
    #training-photo .card-header .ms-auto { display: none !important; }
    #training-photo img { max-height: 200px !important; width: auto; }

    /* Skryt "bez fotografie" blok */
    .card.border-0.shadow-sm.mb-4:not(.exercise-block):not(#training-photo) .form-control,
    .card.border-0.shadow-sm.mb-4:not(.exercise-block):not(#training-photo) .btn { display: none !important; }
}
</style>

<?php renderFooter(); ?>
