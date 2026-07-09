<?php
// progress_report.php – Zpráva o pokroku sportovce za vybrané období
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo     = getDB();
$coach   = getCurrentCoach();

// Načíst seznam sportovců trenéra
$stmtAthletes = $pdo->prepare(
    'SELECT id, first_name, last_name, email, photo
     FROM athletes WHERE coach_id = ? ORDER BY last_name, first_name'
);
$stmtAthletes->execute([$coachId]);
$athletes = $stmtAthletes->fetchAll();

$athlete    = null;
$report     = null;
$error      = null;
$emailSent  = false;

$athleteId = intParam($_GET, 'athlete_id');
$dateFrom  = preg_replace('/[^0-9\-]/', '', $_GET['date_from'] ?? '');
$dateTo    = preg_replace('/[^0-9\-]/', '', $_GET['date_to']   ?? '');

// ── Odeslání e-mailu ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $athleteId = intParam($_POST, 'athlete_id');
        $dateFrom  = preg_replace('/[^0-9\-]/', '', $_POST['date_from'] ?? '');
        $dateTo    = preg_replace('/[^0-9\-]/', '', $_POST['date_to']   ?? '');

        $stmtA = $pdo->prepare('SELECT * FROM athletes WHERE id = ? AND coach_id = ?');
        $stmtA->execute([$athleteId, $coachId]);
        $athlete = $stmtA->fetch();

        if (!$athlete || !$athlete['email']) {
            $error = 'Sportovec nemá zadán e-mail.';
        } else {
            // Sestavit zprávu
            $stmtS = $pdo->prepare(
                'SELECT ts.*, ws.name AS set_name
                 FROM training_sessions ts
                 JOIN workout_sets ws ON ts.workout_set_id = ws.id
                                 WHERE ts.athlete_id = ?
                                     AND ts.completed_at IS NOT NULL
                                     AND ts.deleted_by_coach_at IS NULL
                   AND DATE(ts.completed_at) BETWEEN ? AND ?
                 ORDER BY ts.completed_at ASC'
            );
            $stmtS->execute([$athleteId, $dateFrom, $dateTo]);
            $sessions = $stmtS->fetchAll();

            $exerciseStats = [];
            foreach ($sessions as $sess) {
                $exList = getSessionExercises((int)$sess['id'], (int)$sess['workout_set_id']);
                foreach ($exList as $ex) {
                    $series = getSeriesForExercise($sess['id'], $ex['exercise_id']);
                    if (empty($series)) continue;
                    $eid = $ex['exercise_id'];
                    if (!isset($exerciseStats[$eid])) {
                        $exerciseStats[$eid] = [
                            'name'         => $ex['exercise_name'],
                            'sessions'     => 0,
                            'max_weight'   => 0.0,
                            'total_reps'   => 0,
                            'total_volume' => 0.0,
                        ];
                    }
                    $exerciseStats[$eid]['sessions']++;
                    foreach ($series as $s) {
                        if ($s['weight'] > $exerciseStats[$eid]['max_weight']) {
                            $exerciseStats[$eid]['max_weight'] = $s['weight'];
                        }
                        $exerciseStats[$eid]['total_reps']   += $s['reps'];
                        $exerciseStats[$eid]['total_volume'] += $s['weight'] * $s['reps'];
                    }
                }
            }

            $athleteName = $athlete['first_name'] . ' ' . $athlete['last_name'];
            $subject = 'Zpráva o pokroku – ' . formatDate($dateFrom) . ' až ' . formatDate($dateTo);

            $body  = "Ahoj {$athlete['first_name']},\n\n";
            $body .= "posílám ti zprávu o tvém pokroku za období {$dateFrom} – {$dateTo}.\n\n";
            $body .= "Celkem tréninků: " . count($sessions) . "\n\n";
            $body .= str_repeat("─", 45) . "\n\n";

            foreach ($exerciseStats as $stat) {
                $body .= strtoupper($stat['name']) . "\n";
                $body .= sprintf("  Max. váha:    %s kg\n", number_format($stat['max_weight'], 1, ',', ''));
                $body .= sprintf("  Celkem opak.: %d\n", $stat['total_reps']);
                $body .= sprintf("  Celkový objem:%s kg\n", number_format($stat['total_volume'], 1, ',', ''));
                $body .= sprintf("  Tréninků s tímto cvikem: %d\n\n", $stat['sessions']);
            }

            $body .= str_repeat("─", 45) . "\n";
            $body .= "\nS pozdravem,\n";
            $body .= ($coach['name'] ?: $coach['username']) . " – Trenér\n";
            $body .= "\n---\nZpráva vygenerována aplikací " . APP_NAME . "\n";

            $headers = implode("\r\n", [
                'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
                'Reply-To: ' . MAIL_FROM,
                'X-Mailer: PHP/' . phpversion(),
                'Content-Type: text/plain; charset=UTF-8',
            ]);

            $sent = mail(
                $athlete['email'],
                '=?UTF-8?B?' . base64_encode($subject) . '?=',
                $body,
                $headers
            );

            if ($sent) {
                flash('success', "Zpráva o pokroku byla odeslána na {$athlete['email']}.");
            } else {
                flash('danger', 'E-mail se nepodařilo odeslat. Zkontrolujte nastavení PHP mail().');
            }
            redirect(BASE_URL . '/progress_report.php?athlete_id=' . $athleteId
                . '&date_from=' . $dateFrom . '&date_to=' . $dateTo);
        }
    }
}

// ── Generovat zprávu (GET) ────────────────────────────────────────────────────
if ($athleteId > 0 && $dateFrom && $dateTo) {
    $stmtA = $pdo->prepare('SELECT * FROM athletes WHERE id = ? AND coach_id = ?');
    $stmtA->execute([$athleteId, $coachId]);
    $athlete = $stmtA->fetch();

    if ($athlete) {
        $stmtS = $pdo->prepare(
            'SELECT ts.*, ws.name AS set_name
             FROM training_sessions ts
             JOIN workout_sets ws ON ts.workout_set_id = ws.id
                         WHERE ts.athlete_id = ?
                             AND ts.completed_at IS NOT NULL
                             AND ts.deleted_by_coach_at IS NULL
               AND DATE(ts.completed_at) BETWEEN ? AND ?
             ORDER BY ts.completed_at ASC'
        );
        $stmtS->execute([$athleteId, $dateFrom, $dateTo]);
        $sessions = $stmtS->fetchAll();

        $exerciseStats = [];
        foreach ($sessions as $sess) {
            $exList = getSessionExercises((int)$sess['id'], (int)$sess['workout_set_id']);
            foreach ($exList as $ex) {
                $series = getSeriesForExercise($sess['id'], $ex['exercise_id']);
                if (empty($series)) continue;
                $eid = $ex['exercise_id'];
                if (!isset($exerciseStats[$eid])) {
                    $exerciseStats[$eid] = [
                        'name'           => $ex['exercise_name'],
                        'sessions'       => 0,
                        'max_weight'     => 0.0,
                        'total_reps'     => 0,
                        'total_volume'   => 0.0,
                        'first_max'      => null,
                        'last_max'       => null,
                        'first_date'     => null,
                        'last_date'      => null,
                    ];
                }
                $exerciseStats[$eid]['sessions']++;
                $sessDate = $sess['completed_at'];
                $sessMaxThisSess = 0.0;
                foreach ($series as $s) {
                    if ($s['weight'] > $exerciseStats[$eid]['max_weight']) {
                        $exerciseStats[$eid]['max_weight'] = $s['weight'];
                    }
                    if ($s['weight'] > $sessMaxThisSess) {
                        $sessMaxThisSess = $s['weight'];
                    }
                    $exerciseStats[$eid]['total_reps']   += $s['reps'];
                    $exerciseStats[$eid]['total_volume'] += $s['weight'] * $s['reps'];
                }
                if ($exerciseStats[$eid]['first_date'] === null) {
                    $exerciseStats[$eid]['first_date'] = $sessDate;
                    $exerciseStats[$eid]['first_max']  = $sessMaxThisSess;
                }
                $exerciseStats[$eid]['last_date'] = $sessDate;
                $exerciseStats[$eid]['last_max']  = $sessMaxThisSess;
            }
        }

        $report = [
            'sessions'       => $sessions,
            'exercise_stats' => $exerciseStats,
            'total_sessions' => count($sessions),
        ];
    }
}

renderHeader('Zpráva o pokroku');
?>

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
    body { font-size: 12px; }
}
</style>

<div class="d-flex align-items-center mb-4 gap-3 no-print">
    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0"><i class="fas fa-file-alt me-2 text-success"></i>Zpráva o pokroku</h2>
</div>

<!-- Formulář výběru -->
<div class="card border-0 shadow-sm mb-4 no-print">
    <div class="card-header bg-dark text-white">
        <i class="fas fa-filter me-2"></i>Vyberte sportovce a časové období
    </div>
    <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Sportovec <span class="text-danger">*</span></label>
                <select name="athlete_id" class="form-select" required>
                    <option value="">– vyberte sportovce –</option>
                    <?php foreach ($athletes as $a): ?>
                    <option value="<?= $a['id'] ?>"
                        <?= ($a['id'] == $athleteId) ? 'selected' : '' ?>>
                        <?= h($a['last_name'] . ' ' . $a['first_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Datum od <span class="text-danger">*</span></label>
                <input type="date" name="date_from" class="form-control" required
                       value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Datum do <span class="text-danger">*</span></label>
                <input type="date" name="date_to" class="form-control" required
                       value="<?= h($dateTo) ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-success fw-bold w-100">
                    <i class="fas fa-search me-1"></i>Zobrazit
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($report !== null): ?>

<!-- Záhlaví zprávy (viditelné i při tisku) -->
<div class="d-flex justify-content-between align-items-start mb-3 no-print">
    <div></div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-print me-1"></i>Tisknout
        </button>
        <?php if ($athlete['email']): ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="email">
            <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
            <input type="hidden" name="date_from" value="<?= h($dateFrom) ?>">
            <input type="hidden" name="date_to" value="<?= h($dateTo) ?>">
            <button type="submit" class="btn btn-success btn-sm">
                <i class="fas fa-envelope me-1"></i>Odeslat e-mailem
            </button>
        </form>
        <?php else: ?>
        <button class="btn btn-outline-secondary btn-sm" disabled
                title="Sportovec nemá zadán e-mail">
            <i class="fas fa-envelope me-1"></i>Odeslat e-mailem
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Zpráva -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white">
        <div class="d-flex justify-content-between align-items-center">
            <span>
                <i class="fas fa-user me-2"></i>
                <?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?>
            </span>
            <span class="opacity-75">
                <i class="fas fa-calendar me-1"></i>
                <?= formatDate($dateFrom) ?> – <?= formatDate($dateTo) ?>
            </span>
        </div>
    </div>
    <div class="card-body">

        <!-- Shrnutí -->
        <div class="row g-3 mb-4">
            <div class="col-sm-4">
                <div class="border rounded p-3 text-center bg-light">
                    <div class="fs-2 fw-bold text-success"><?= $report['total_sessions'] ?></div>
                    <div class="text-muted small">Tréninků celkem</div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="border rounded p-3 text-center bg-light">
                    <div class="fs-2 fw-bold text-warning"><?= count($report['exercise_stats']) ?></div>
                    <div class="text-muted small">Různých cviků</div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="border rounded p-3 text-center bg-light">
                    <?php
                        $totalVol = array_sum(array_column($report['exercise_stats'], 'total_volume'));
                    ?>
                    <div class="fs-2 fw-bold text-info"><?= number_format($totalVol, 0, ',', ' ') ?></div>
                    <div class="text-muted small">Celkový objem (kg)</div>
                </div>
            </div>
        </div>

        <?php if (empty($report['sessions'])): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-1"></i>
            V tomto období nebyly nalezeny žádné dokončené tréninky.
        </div>
        <?php else: ?>

        <!-- Přehled tréninků -->
        <h6 class="fw-bold mb-2"><i class="fas fa-calendar-check me-1 text-success"></i>Tréninky v období</h6>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Datum</th>
                        <th>Sada</th>
                        <th>Místo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['sessions'] as $i => $s): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= formatDateTime($s['completed_at']) ?></td>
                        <td><?= h($s['set_name']) ?></td>
                        <td><?= $s['location'] ? h($s['location']) : '–' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Statistiky cviků -->
        <h6 class="fw-bold mb-2"><i class="fas fa-chart-bar me-1 text-success"></i>Statistiky cviků</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Cvik</th>
                        <th class="text-center">Tréninků</th>
                        <th class="text-end">Max. váha (kg)</th>
                        <th class="text-end">Celkem opak.</th>
                        <th class="text-end">Celk. objem (kg)</th>
                        <th class="text-end">Progres váhy</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['exercise_stats'] as $stat): ?>
                    <?php
                        $progress = null;
                        if ($stat['first_max'] !== null && $stat['last_max'] !== null
                            && $stat['first_date'] !== $stat['last_date']) {
                            $progress = $stat['last_max'] - $stat['first_max'];
                        }
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= h($stat['name']) ?></td>
                        <td class="text-center"><?= $stat['sessions'] ?></td>
                        <td class="text-end"><?= number_format($stat['max_weight'], 1, ',', '') ?></td>
                        <td class="text-end"><?= $stat['total_reps'] ?></td>
                        <td class="text-end"><?= number_format($stat['total_volume'], 1, ',', '') ?></td>
                        <td class="text-end">
                            <?php if ($progress === null): ?>
                            <span class="text-muted">–</span>
                            <?php elseif ($progress > 0): ?>
                            <span class="text-success fw-bold">
                                <i class="fas fa-arrow-up me-1"></i>+<?= number_format($progress, 1, ',', '') ?> kg
                            </span>
                            <?php elseif ($progress < 0): ?>
                            <span class="text-danger fw-bold">
                                <i class="fas fa-arrow-down me-1"></i><?= number_format($progress, 1, ',', '') ?> kg
                            </span>
                            <?php else: ?>
                            <span class="text-muted">
                                <i class="fas fa-minus me-1"></i>0 kg
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>
    </div>
    <div class="card-footer text-muted small">
        Zpráva vygenerována dne <?= date('d.m.Y H:i') ?> –
        Trenér: <?= h($coach['name'] ?: $coach['username']) ?>
    </div>
</div>

<?php elseif ($athleteId > 0 && $dateFrom && $dateTo && $athlete === null): ?>
<div class="alert alert-danger">Sportovec nenalezen.</div>
<?php endif; ?>

<?php renderFooter(); ?>
