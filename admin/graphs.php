<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$athleteId = intParam($_GET, 'athlete_id');
$pdo       = getDB();

// Ověření sportovce
$stmt = $pdo->prepare('SELECT * FROM athletes WHERE id = ? AND coach_id = ?');
$stmt->execute([$athleteId, $coachId]);
$athlete = $stmt->fetch();

if (!$athlete) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

// Načtení cviků pro výběr filtru
$stmtEx = $pdo->prepare(
    'SELECT DISTINCT e.id, e.name
     FROM exercises e
     JOIN session_series ss ON ss.exercise_id = e.id
     JOIN training_sessions ts ON ss.session_id = ts.id
         WHERE ts.athlete_id = ?
             AND ts.completed_at IS NOT NULL
             AND ts.deleted_by_coach_at IS NULL
     ORDER BY e.name'
);
$stmtEx->execute([$athleteId]);
$exercises = $stmtEx->fetchAll();

// Vybraný cvik pro graf
$selectedExId = intParam($_GET, 'exercise_id');
if (!$selectedExId && !empty($exercises)) {
    $selectedExId = $exercises[0]['id'];
}

// Data pro graf (max váha a průměrný objem na session)
$chartData = [];
if ($selectedExId) {
    $stmtData = $pdo->prepare(
        'SELECT ts.completed_at AS session_date,
                ws.name AS set_name,
                MAX(ss.weight) AS max_weight,
                SUM(ss.weight * ss.reps) AS total_volume,
                MAX(ss.reps) AS max_reps,
                SUM(ss.reps) AS total_reps,
                COUNT(ss.id) AS series_count
         FROM session_series ss
         JOIN training_sessions ts ON ss.session_id = ts.id
         JOIN workout_sets ws ON ts.workout_set_id = ws.id
                 WHERE ts.athlete_id = ?
                     AND ss.exercise_id = ?
                     AND ts.completed_at IS NOT NULL
                     AND ts.deleted_by_coach_at IS NULL
         GROUP BY ts.id
         ORDER BY ts.completed_at ASC'
    );
    $stmtData->execute([$athleteId, $selectedExId]);
    $chartData = $stmtData->fetchAll();
}

// Název vybraného cviku
$selectedExName = '';
foreach ($exercises as $ex) {
    if ($ex['id'] == $selectedExId) {
        $selectedExName = $ex['name'];
        break;
    }
}

renderHeader('Grafy – ' . h($athlete['first_name'] . ' ' . $athlete['last_name']), true);
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $athleteId ?>"
       class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0 fw-bold">
        <i class="fas fa-chart-line me-2 text-warning"></i>
        <?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?> – Pokrok
    </h2>
    <?php if (!empty($chartData)): ?>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm ms-auto">
        <i class="fas fa-print me-1"></i>Tisk / PDF
    </button>
    <?php endif; ?>
</div>

<?php if (empty($exercises)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-chart-bar fa-3x mb-3 d-block"></i>
        Žádná data. Nejprve dokončete nějaký trénink.
    </div>
</div>
<?php else: ?>

<!-- Výběr cviku -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="d-flex gap-3 align-items-end flex-wrap">
            <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
            <div>
                <label class="form-label fw-semibold mb-1">Vyberte cvik</label>
                <select name="exercise_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($exercises as $ex): ?>
                    <option value="<?= $ex['id'] ?>" <?= $ex['id'] == $selectedExId ? 'selected' : '' ?>>
                        <?= h($ex['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($chartData)): ?>

<!-- Statistika -->
<?php
$allWeights = array_column($chartData, 'max_weight');
$maxEver    = max($allWeights);
$lastRow    = end($chartData);
$firstRow   = reset($chartData);
$improvement = $maxEver > 0 && $firstRow['max_weight'] > 0
    ? round(($lastRow['max_weight'] - $firstRow['max_weight']) / $firstRow['max_weight'] * 100, 1)
    : 0;
?>
<div class="row g-3 mb-4">
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= number_format($maxEver, 1, ',', '') ?></div>
            <div class="text-muted">Rekord váha (kg)</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= max(array_column($chartData, 'max_reps')) ?></div>
            <div class="text-muted">Max opakování</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold <?= $improvement >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= ($improvement >= 0 ? '+' : '') . $improvement ?> %
            </div>
            <div class="text-muted">Zlepšení (první vs. poslední)</div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= count($chartData) ?></div>
            <div class="text-muted">Tréninků s tímto cvikem</div>
        </div>
    </div>
</div>

<!-- Graf maximální váhy -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white">
        <i class="fas fa-chart-line me-2 text-warning"></i>
        Maximální váha – <?= h($selectedExName) ?>
    </div>
    <div class="card-body">
        <canvas id="weightChart" style="max-height:350px"></canvas>
    </div>
</div>

<!-- Graf objemu -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white">
        <i class="fas fa-chart-bar me-2 text-warning"></i>
        Celkový objem na trénink (kg × opak.) – <?= h($selectedExName) ?>
    </div>
    <div class="card-body">
        <canvas id="volumeChart" style="max-height:300px"></canvas>
    </div>
</div>

<!-- Tabulka dat -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white">
        <i class="fas fa-table me-2"></i>Detailní data
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-bordered mb-0 align-middle text-center">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Datum</th>
                        <th>Sada</th>
                        <th>Max váha (kg)</th>
                        <th>Max opak.</th>
                        <th>Počet sérií</th>
                        <th>Celk. objem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($chartData) as $i => $row): ?>
                    <tr>
                        <td class="text-muted"><?= count($chartData) - $i ?></td>
                        <td><?= formatDate($row['session_date']) ?></td>
                        <td><span class="badge bg-secondary"><?= h($row['set_name']) ?></span></td>
                        <td class="fw-bold"><?= number_format($row['max_weight'], 1, ',', '') ?> kg</td>
                        <td><?= $row['max_reps'] ?></td>
                        <td><?= $row['series_count'] ?></td>
                        <td><?= number_format($row['total_volume'], 0, ',', '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const labels  = <?= json_encode(array_map(fn($r) => formatDateJS($r['session_date']), $chartData)) ?>;
const weights = <?= json_encode(array_map(fn($r) => (float)$r['max_weight'], $chartData)) ?>;
const volumes = <?= json_encode(array_map(fn($r) => (float)$r['total_volume'], $chartData)) ?>;
const sady    = <?= json_encode(array_map(fn($r) => $r['set_name'], $chartData)) ?>;

function formatDateJS(dt) {
    const d = new Date(dt);
    return d.toLocaleDateString('cs-CZ', {day:'2-digit', month:'2-digit', year:'numeric'});
}

// Graf váhy
new Chart(document.getElementById('weightChart'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Max váha (kg)',
            data: weights,
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245,158,11,0.1)',
            borderWidth: 3,
            pointBackgroundColor: '#f59e0b',
            pointRadius: 5,
            pointHoverRadius: 8,
            tension: 0.3,
            fill: true,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => `${ctx.parsed.y.toFixed(1).replace('.',',')} kg  |  Sada: ${sady[ctx.dataIndex]}`
                }
            }
        },
        scales: {
            y: {
                beginAtZero: false,
                ticks: { callback: v => v + ' kg' }
            }
        }
    }
});

// Graf objemu
new Chart(document.getElementById('volumeChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [{
            label: 'Objem (kg×rep)',
            data: volumes,
            backgroundColor: 'rgba(59,130,246,0.7)',
            borderColor: '#3b82f6',
            borderWidth: 2,
            borderRadius: 4,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: v => v.toLocaleString('cs-CZ') }
            }
        }
    }
});
</script>

<?php
$svgWeight = buildPrintSvg($chartData, 'max_weight', 'kg', '#f59e0b');
$svgVolume = buildPrintSvg($chartData, 'total_volume', '', '#3b82f6');
?>
<div class="print-only-section d-none">
    <div class="print-header">
        <div class="print-header-left">
            <span class="print-logo">&#x1F4AA; TrainerApp</span>
        </div>
        <div class="print-header-right">
            Vytisknuto: <?= date('d.m.Y H:i') ?>
        </div>
    </div>

    <h1 class="print-title">
        <?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?> &ndash; Pokrok
    </h1>
    <p class="print-subtitle">Cvik: <strong><?= h($selectedExName) ?></strong></p>

    <div class="print-stats">
        <div class="print-stat">
            <div class="print-stat-value"><?= number_format($maxEver, 1, ',', '') ?> kg</div>
            <div class="print-stat-label">Rekord váha</div>
        </div>
        <div class="print-stat">
            <div class="print-stat-value"><?= max(array_column($chartData, 'max_reps')) ?></div>
            <div class="print-stat-label">Max opakování</div>
        </div>
        <div class="print-stat <?= $improvement >= 0 ? 'positive' : 'negative' ?>">
            <div class="print-stat-value"><?= ($improvement >= 0 ? '+' : '') . $improvement ?>&nbsp;%</div>
            <div class="print-stat-label">Zlepšení</div>
        </div>
        <div class="print-stat">
            <div class="print-stat-value"><?= count($chartData) ?></div>
            <div class="print-stat-label">Tréninků s cvikem</div>
        </div>
    </div>

    <div class="print-chart-block">
        <div class="print-chart-title">Max. váha &ndash; <?= h($selectedExName) ?> (kg)</div>
        <?= $svgWeight ?>
    </div>

    <div class="print-chart-block">
        <div class="print-chart-title">Celkový objem &ndash; <?= h($selectedExName) ?> (kg&times;opak.)</div>
        <?= $svgVolume ?>
    </div>

    <div class="print-chart-block">
        <div class="print-chart-title">Detailní data</div>
        <table class="print-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Datum</th>
                    <th>Sada</th>
                    <th>Max váha (kg)</th>
                    <th>Max opak.</th>
                    <th>Sérií</th>
                    <th>Objem</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($chartData) as $i => $row): ?>
                <tr>
                    <td><?= count($chartData) - $i ?></td>
                    <td><?= formatDate($row['session_date']) ?></td>
                    <td><?= h($row['set_name']) ?></td>
                    <td><strong><?= number_format($row['max_weight'], 1, ',', '') ?></strong></td>
                    <td><?= $row['max_reps'] ?></td>
                    <td><?= $row['series_count'] ?></td>
                    <td><?= number_format($row['total_volume'], 0, ',', '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<div class="alert alert-info">Pro tento cvik zatím nejsou žádná data.</div>
<?php endif; ?>
<?php endif; ?>

<style>
@media print {
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { margin: 10mm 10mm; size: A4 portrait; }

    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 9pt; color: #111; background: #fff; }
    .container, .container-fluid { max-width: 100% !important; padding: 0 !important; }

    /* Skryje vse krome print sekce */
    .navbar, footer, .btn, .card, canvas,
    .row.g-3, .alert, h2, .d-flex.align-items-center.mb-4,
    select, form, script { display: none !important; }

    .print-only-section {
        display: block !important;
        font-family: 'Segoe UI', Arial, sans-serif;
        font-size: 9pt;
        color: #111;
    }

    .print-header {
        display: flex !important;
        justify-content: space-between;
        align-items: center;
        border-bottom: 2px solid #f59e0b;
        padding-bottom: 4px;
        margin-bottom: 10px;
        font-size: 7.5pt;
        color: #6b7280;
    }
    .print-logo { font-weight: 700; font-size: 10pt; color: #111; }

    .print-title { font-size: 14pt; font-weight: 700; margin: 0 0 2px; }
    .print-subtitle { font-size: 9pt; color: #374151; margin: 0 0 10px; }

    .print-stats {
        display: flex !important;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        overflow: hidden;
        margin-bottom: 12px;
    }
    .print-stat { flex: 1; text-align: center; padding: 6px 4px; border-right: 1px solid #e5e7eb; }
    .print-stat:last-child { border-right: none; }
    .print-stat-value { font-size: 15pt; font-weight: 700; color: #f59e0b; line-height: 1.1; }
    .print-stat.positive .print-stat-value { color: #16a34a; }
    .print-stat.negative .print-stat-value { color: #dc2626; }
    .print-stat-label { font-size: 7pt; color: #6b7280; margin-top: 1px; }

    .print-chart-block { margin-bottom: 10px; break-inside: avoid; }
    .print-chart-title {
        font-size: 8.5pt;
        font-weight: 700;
        background: #1e2937 !important;
        color: #fff !important;
        padding: 4px 8px;
        border-radius: 4px 4px 0 0;
    }
    .print-chart-block svg { display: block; width: 100%; }

    .print-table { width: 100%; border-collapse: collapse; font-size: 8pt; }
    .print-table th {
        background: #f3f4f6 !important;
        font-weight: 600;
        padding: 3px 8px;
        border: 1px solid #dee2e6;
        text-align: center;
    }
    .print-table td { padding: 2px 8px; border: 1px solid #dee2e6; text-align: center; }
    .print-table tbody tr:nth-child(odd) td { background: #f9fafb !important; }
}
</style>

<?php
// Pomocná funkce pro JS – není potřeba v PHP, jen pro formátování JS data
function formatDateJS(string $dt): string {
    return date('d.m.Y', strtotime($dt));
}

// \u2500\u2500 PHP: SVG sloupcový graf pro tisk \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
function buildPrintSvg(array $data, string $metric, string $unit, string $color): string {
    if (empty($data)) return '';
    $vals   = array_map(fn($r) => (float)$r[$metric], $data);
    $labels = array_map(fn($r) => date('d.m', strtotime($r['session_date'])), $data);
    $maxVal = max($vals) ?: 1;
    $n      = count($vals);
    $W      = 680; $H = 140; $padL = 40; $padB = 28; $padT = 10; $padR = 10;
    $chartW = $W - $padL - $padR;
    $chartH = $H - $padB - $padT;
    $barW   = max(4, (int)floor($chartW / $n * 0.6));
    $gap    = $chartW / $n;

    $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$W} {$H}' width='{$W}' height='{$H}' style='max-width:100%'>";
    // Mřížka
    for ($i = 0; $i <= 4; $i++) {
        $y = $padT + $chartH - (int)round($chartH * $i / 4);
        $lbl = number_format($maxVal * $i / 4, 1, ',', '');
        $svg .= "<line x1='{$padL}' y1='{$y}' x2='".($W-$padR)."' y2='{$y}' stroke='#e5e7eb' stroke-width='1'/>";
        $svg .= "<text x='".($padL-3)."' y='".($y+3)."' text-anchor='end' font-size='7' fill='#6b7280'>{$lbl}</text>";
    }
    // Sloupce + popisky
    foreach ($vals as $idx => $v) {
        $bh  = (int)round($chartH * $v / $maxVal);
        $bx  = (int)round($padL + $gap * $idx + ($gap - $barW) / 2);
        $by  = $padT + $chartH - $bh;
        $lx  = (int)round($padL + $gap * ($idx + 0.5));
        $ly  = $H - $padB + 10;
        $svg .= "<rect x='{$bx}' y='{$by}' width='{$barW}' height='{$bh}' fill='{$color}' rx='2'/>";
        // Hodnota nad sloupcem
        if ($bh > 12) {
            $vy = $by + 9;
            $vStr = number_format($v, ($unit === 'kg') ? 1 : 0, ',', '');
            $svg .= "<text x='".($bx + $barW/2)."' y='{$vy}' text-anchor='middle' font-size='6.5' fill='#111' font-weight='bold'>{$vStr}</text>";
        }
        $svg .= "<text x='{$lx}' y='{$ly}' text-anchor='middle' font-size='6.5' fill='#374151'>{$labels[$idx]}</text>";
    }
    // Osa Y
    $svg .= "<line x1='{$padL}' y1='{$padT}' x2='{$padL}' y2='".($padT+$chartH)."' stroke='#9ca3af' stroke-width='1'/>";
    $svg .= "<line x1='{$padL}' y1='".($padT+$chartH)."' x2='".($W-$padR)."' y2='".($padT+$chartH)."' stroke='#9ca3af' stroke-width='1'/>";
    $svg .= '</svg>';
    return $svg;
}
?>

<?php renderFooter(); ?>
