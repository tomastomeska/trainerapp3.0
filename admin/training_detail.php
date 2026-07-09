<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$sessionId = intParam($_GET, 'id');
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

// Načtení cviků v session snapshotu (fallback pro starší data)
$exercises = getSessionExercises($sessionId, (int)$session['workout_set_id']);

// Načtení sérií
$seriesByExercise = [];
$totalSeries      = 0;
foreach ($exercises as $ex) {
    $s = getSeriesForExercise($sessionId, $ex['exercise_id']);
    $seriesByExercise[$ex['exercise_id']] = $s;
    $totalSeries += count($s);
}

$athleteName = h($session['first_name'] . ' ' . $session['last_name']);

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
                    $totalVolume += $s['weight'] * $s['reps'];
                }
            }
            ?>
            <div class="display-6 fw-bold text-warning"><?= number_format($totalVolume, 0, ',', '&nbsp;') ?></div>
            <div class="text-muted">Celkový objem (kg×rep)</div>
        </div>
    </div>
</div>

<!-- Cviky a série -->
<?php foreach ($exercises as $ex): ?>
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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($series as $s): ?>
                    <tr>
                        <td class="fw-bold text-muted"><?= $s['series_order'] ?></td>
                        <?php if (!empty($ex['is_timed'])): ?>
                        <td class="fw-bold"><?= !empty($s['duration_seconds']) ? formatSeriesDuration((int)$s['duration_seconds']) : '–' ?></td>
                        <?php $seriesTimedLoad = (float)$s['weight'] + (float)($s['equipment_weight'] ?? 0); ?>
                        <td class="fw-bold"><?= $seriesTimedLoad > 0 ? number_format($seriesTimedLoad, 1, ',', '') . ' kg' : '–' ?></td>
                        <?php else: ?>
                        <td class="fw-bold"><?= number_format((float)$s['weight'] + (float)($s['equipment_weight'] ?? 0), 1, ',', '') ?> kg</td>
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
    </div>
</div>
<?php endforeach; ?>

<?php if ($session['notes']): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white"><i class="fas fa-sticky-note me-2"></i>Poznámka</div>
    <div class="card-body"><?= h($session['notes']) ?></div>
</div>
<?php endif; ?>

<?php if (!empty($session['training_photo'])): ?>
<div class="card border-0 shadow-sm mb-4" id="training-photo">
    <div class="card-header bg-dark text-white d-flex align-items-center">
        <span><i class="fas fa-camera me-2"></i>Fotografie z tréninku</span>
        <div class="ms-auto d-flex gap-2">
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
        </div>
    </div>
    <div class="card-body text-center">
        <img src="<?= h(photoUrl($session['training_photo'], 'trainings')) ?>"
             alt="Fotografie z tréninku"
             class="img-fluid rounded"
             style="max-height:500px; object-fit:contain;">
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
            <input type="hidden" name="action" value="update">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <input type="file" name="training_photo" id="addPhotoInput"
                       accept="image/*" capture="environment"
                       class="d-none"
                       onchange="previewAddPhoto(this)">
                <label for="addPhotoInput" class="btn btn-outline-warning mb-0">
                    <i class="fas fa-camera me-1"></i>Nahrát / Vyfotit
                </label>
                <span id="addPhotoName" class="text-muted small fst-italic">Soubor nevybrán</span>
                <button type="submit" class="btn btn-outline-success">
                    <i class="fas fa-save me-1"></i>Uložit fotografii
                </button>
            </div>
            <img id="add-photo-preview" class="img-fluid rounded border mt-3 d-none"
                 alt="Náhled" style="max-height:180px; object-fit:cover;">
        </form>
    </div>
</div>
<script>
function previewAddPhoto(input) {
    const preview  = document.getElementById('add-photo-preview');
    const nameSpan = document.getElementById('addPhotoName');
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (nameSpan) nameSpan.textContent = file.name;
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
</script>
<?php endif; ?>

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
