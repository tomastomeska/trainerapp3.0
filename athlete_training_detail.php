<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$sessionId = intParam($_GET, 'id');
$pdo       = getDB();

// Načti session – pouze vlastní tréninky sportovce
$stmt = $pdo->prepare(
    'SELECT ts.*, ws.name AS set_name
     FROM training_sessions ts
     JOIN workout_sets ws ON ws.id = ts.workout_set_id
     WHERE ts.id = ?
       AND ts.athlete_id = ?
       AND ts.deleted_by_coach_at IS NULL
     LIMIT 1'
);
$stmt->execute([$sessionId, $athleteId]);
$session = $stmt->fetch();

if (!$session) {
    flash('danger', 'Trénink nebyl nalezen.');
    redirect(BASE_URL . '/athlete_dashboard.php');
}

// Cviky a série
$exercises = getSessionExercises($sessionId, (int)$session['workout_set_id']);

$seriesByExercise = [];
foreach ($exercises as $ex) {
    $seriesByExercise[$ex['exercise_id']] = getSeriesForExercise($sessionId, (int)$ex['exercise_id']);
}

// Fotky
$photos = getTrainingSessionPhotos($sessionId);

renderAthleteHeader('Detail tréninku');
?>

<div class="d-flex align-items-center mb-3 gap-3 flex-wrap">
    <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Zpět
    </a>
    <div>
        <h2 class="mb-0 fw-bold">
            <i class="fas fa-clipboard-list me-2 text-warning"></i><?= h((string)$session['set_name']) ?>
        </h2>
        <span class="text-muted"><?= formatDateTime((string)($session['completed_at'] ?? $session['started_at'])) ?></span>
        <?php if (!empty($session['location'])): ?>
        <span class="ms-2 text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= h((string)$session['location']) ?></span>
        <?php endif; ?>
    </div>
    <div class="ms-auto">
        <?php if (!empty($session['completed_at'])): ?>
        <span class="badge bg-success fs-6"><i class="fas fa-check me-1"></i>Dokončeno</span>
        <?php else: ?>
        <span class="badge bg-warning text-dark fs-6">Naplánováno / probíhá</span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($session['coach_note'])): ?>
<div class="alert alert-info mb-3">
    <strong><i class="fas fa-comment me-1"></i>Poznámka trenéra:</strong>
    <div style="white-space:pre-wrap"><?= h((string)$session['coach_note']) ?></div>
</div>
<?php endif; ?>

<?php if (!empty($session['athlete_note'])): ?>
<div class="alert alert-secondary mb-3">
    <strong><i class="fas fa-sticky-note me-1"></i>Moje poznámka:</strong>
    <div style="white-space:pre-wrap"><?= h((string)$session['athlete_note']) ?></div>
</div>
<?php endif; ?>

<?php if (!empty($photos)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-images me-2"></i>Fotky z tréninku
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
        <?php foreach ($photos as $photo): ?>
            <a href="<?= BASE_URL ?>/uploads/<?= h((string)$photo['filename']) ?>" target="_blank">
                <img src="<?= BASE_URL ?>/uploads/<?= h((string)$photo['filename']) ?>"
                     style="height:120px;width:120px;object-fit:cover;border-radius:8px;border:2px solid #e5e7eb;">
            </a>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (empty($exercises)): ?>
<div class="alert alert-info">Tento trénink neobsahuje žádné záznamy cviků.</div>
<?php else: ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-dumbbell me-2"></i>Cviky a série
    </div>
    <div class="card-body p-0">
    <?php foreach ($exercises as $ex):
        $series = $seriesByExercise[$ex['exercise_id']] ?? [];
    ?>
    <div class="p-3 border-bottom">
        <div class="fw-bold mb-2">
            <?= (int)$ex['exercise_order'] ?>. <?= h((string)$ex['exercise_name']) ?>
        </div>
        <?php if (empty($series)): ?>
            <span class="text-muted small">Žádné série nebyly zaznamenány.</span>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="max-width:500px">
                <thead class="table-light">
                <tr>
                    <th style="width:50px">Série</th>
                    <th>Opakování</th>
                    <th>Zátěž (kg)</th>
                    <?php if (!empty(array_filter($series, fn($s) => !empty($s['note'])))): ?>
                    <th>Poznámka</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php $hasNote = !empty(array_filter($series, fn($s) => !empty($s['note']))); ?>
                <?php foreach ($series as $i => $serie): ?>
                <tr>
                    <td class="text-center fw-semibold"><?= ($i + 1) ?></td>
                    <td><?= isset($serie['reps']) && $serie['reps'] !== null ? (int)$serie['reps'] : '–' ?></td>
                    <td><?= isset($serie['weight']) && $serie['weight'] !== null && (float)$serie['weight'] > 0 ? number_format((float)$serie['weight'], 1, ',', '') : '–' ?></td>
                    <?php if ($hasNote): ?>
                    <td><?= !empty($serie['note']) ? h((string)$serie['note']) : '' ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php renderAthleteFooter(); ?>
