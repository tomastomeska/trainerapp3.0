<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();

$athleteStmt = $pdo->prepare('SELECT first_name, last_name FROM athletes WHERE id = ? LIMIT 1');
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    session_destroy();
    redirect(BASE_URL . '/login.php');
}

$weightDataStmt = $pdo->prepare(
    'SELECT measured_at, weight_kg
     FROM athlete_weight_logs
     WHERE athlete_id = ?
     ORDER BY measured_at ASC, id ASC'
);
$weightDataStmt->execute([$athleteId]);
$weightData = $weightDataStmt->fetchAll();

$exerciseStmt = $pdo->prepare(
    'SELECT DISTINCT e.id, e.name
     FROM exercises e
     JOIN session_series ss ON ss.exercise_id = e.id
     JOIN training_sessions ts ON ts.id = ss.session_id
     WHERE ts.athlete_id = ?
       AND ts.completed_at IS NOT NULL
       AND ts.deleted_by_coach_at IS NULL
     ORDER BY e.name ASC'
);
$exerciseStmt->execute([$athleteId]);
$exercises = $exerciseStmt->fetchAll();

$selectedExerciseId = (int)($_GET['exercise_id'] ?? 0);
if ($selectedExerciseId <= 0 && !empty($exercises)) {
    $selectedExerciseId = (int)$exercises[0]['id'];
}

$chartData = [];
if ($selectedExerciseId > 0) {
    $dataStmt = $pdo->prepare(
        'SELECT ts.completed_at AS session_date,
                ws.name AS set_name,
                MAX(COALESCE(ss.weight, 0) + COALESCE(ss.equipment_weight, 0)) AS max_weight,
                SUM((COALESCE(ss.weight, 0) + COALESCE(ss.equipment_weight, 0)) * ss.reps) AS total_volume,
                MAX(ss.reps) AS max_reps,
                SUM(ss.reps) AS total_reps
         FROM session_series ss
         JOIN training_sessions ts ON ts.id = ss.session_id
         JOIN workout_sets ws ON ws.id = ts.workout_set_id
         WHERE ts.athlete_id = ?
           AND ss.exercise_id = ?
           AND ts.completed_at IS NOT NULL
           AND ts.deleted_by_coach_at IS NULL
         GROUP BY ts.id
         ORDER BY ts.completed_at ASC'
    );
    $dataStmt->execute([$athleteId, $selectedExerciseId]);
    $chartData = $dataStmt->fetchAll();
}

renderAthleteHeader('Grafy', true, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-chart-line me-2 text-warning"></i>Grafy výkonu a váhy</h2>
    <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-house me-1"></i>Domů</a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white">
        <i class="fas fa-weight-scale me-2 text-warning"></i>Vývoj tělesné hmotnosti
    </div>
    <div class="card-body">
        <?php if (empty($weightData)): ?>
        <div class="alert alert-info mb-0">Zatím nemáte zadané žádné váhové záznamy.</div>
        <?php else: ?>
        <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
            <div>
                <label for="weightRangeFilter" class="form-label fw-semibold mb-1">Období</label>
                <select id="weightRangeFilter" class="form-select form-select-sm">
                    <option value="week">Týden</option>
                    <option value="month" selected>1 měsíc</option>
                    <option value="quarter">Čtvrtletí</option>
                    <option value="year">Rok</option>
                    <option value="all">Vše</option>
                </select>
            </div>
            <div class="ms-md-auto">
                <span class="text-muted fw-semibold me-2">Trend:</span>
                <span id="weightTrendBadge" class="badge bg-secondary">-</span>
                <span id="weightTrendValue" class="ms-2 text-muted"></span>
            </div>
        </div>
        <canvas id="bodyWeightChart" style="max-height:320px"></canvas>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($exercises)): ?>
<div class="alert alert-info">Zatím nejsou dostupná data pro grafy.</div>
<?php else: ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="d-flex gap-3 align-items-end flex-wrap">
            <div>
                <label class="form-label fw-semibold mb-1">Cvik</label>
                <select name="exercise_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($exercises as $exercise): ?>
                    <option value="<?= (int)$exercise['id'] ?>" <?= (int)$exercise['id'] === $selectedExerciseId ? 'selected' : '' ?>>
                        <?= h((string)$exercise['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if (empty($chartData)): ?>
<div class="alert alert-warning">Pro vybraný cvik nejsou zatím dokončené tréninky.</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">Maximální váha</div>
            <div class="card-body"><canvas id="maxWeightChart" style="max-height:320px"></canvas></div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">Celkový objem</div>
            <div class="card-body"><canvas id="volumeChart" style="max-height:320px"></canvas></div>
        </div>
    </div>
</div>

<script>
const chartRows = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;
const labels = chartRows.map(r => {
    const dt = new Date(r.session_date.replace(' ', 'T'));
    const dd = String(dt.getDate()).padStart(2, '0');
    const mm = String(dt.getMonth() + 1).padStart(2, '0');
    const yy = dt.getFullYear();
    return `${dd}.${mm}.${yy}`;
});

new Chart(document.getElementById('maxWeightChart'), {
    type: 'line',
    data: {
        labels,
        datasets: [{
            label: 'Max váha (kg)',
            data: chartRows.map(r => Number(r.max_weight || 0)),
            borderColor: '#f3b300',
            backgroundColor: 'rgba(243, 179, 0, 0.25)',
            borderWidth: 3,
            tension: 0.25,
            fill: true
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

new Chart(document.getElementById('volumeChart'), {
    type: 'bar',
    data: {
        labels,
        datasets: [{
            label: 'Objem (kg x opak.)',
            data: chartRows.map(r => Number(r.total_volume || 0)),
            backgroundColor: 'rgba(14, 165, 233, 0.75)',
            borderColor: '#0284c7',
            borderWidth: 1
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($weightData)): ?>
<script>
const bodyWeightRows = <?= json_encode($weightData, JSON_UNESCAPED_UNICODE) ?>;
const rangeFilter = document.getElementById('weightRangeFilter');
const trendBadge = document.getElementById('weightTrendBadge');
const trendValue = document.getElementById('weightTrendValue');

function formatWeightDate(dateStr) {
    const dt = new Date(`${dateStr}T00:00:00`);
    const dd = String(dt.getDate()).padStart(2, '0');
    const mm = String(dt.getMonth() + 1).padStart(2, '0');
    const yy = dt.getFullYear();
    return `${dd}.${mm}.${yy}`;
}

function parseWeightDate(dateStr) {
    return new Date(`${dateStr}T00:00:00`);
}

function addMonths(dateObj, months) {
    const next = new Date(dateObj);
    next.setMonth(next.getMonth() + months);
    return next;
}

function getFilteredWeightRows(rangeKey) {
    if (!Array.isArray(bodyWeightRows) || bodyWeightRows.length === 0 || rangeKey === 'all') {
        return bodyWeightRows;
    }

    const lastRow = bodyWeightRows[bodyWeightRows.length - 1];
    const lastDate = parseWeightDate(lastRow.measured_at);
    let startDate = new Date(lastDate);

    if (rangeKey === 'week') {
        startDate.setDate(startDate.getDate() - 7);
    } else if (rangeKey === 'month') {
        startDate.setDate(startDate.getDate() - 30);
    } else if (rangeKey === 'quarter') {
        startDate = addMonths(startDate, -4);
    } else if (rangeKey === 'year') {
        startDate = addMonths(startDate, -12);
    }

    return bodyWeightRows.filter((row) => parseWeightDate(row.measured_at) >= startDate);
}

function updateWeightTrend(rows) {
    if (!trendBadge || !trendValue || !Array.isArray(rows) || rows.length < 2) {
        if (trendBadge) trendBadge.textContent = 'Nedostatek dat';
        if (trendBadge) trendBadge.className = 'badge bg-secondary';
        if (trendValue) trendValue.textContent = '';
        return;
    }

    const firstWeight = Number(rows[0].weight_kg || 0);
    const lastWeight = Number(rows[rows.length - 1].weight_kg || 0);
    const diff = lastWeight - firstWeight;
    const absDiff = Math.abs(diff);

    if (absDiff <= 1.5) {
        trendBadge.textContent = 'Stabilní váha';
        trendBadge.className = 'badge bg-secondary';
    } else if (diff <= -1.51) {
        trendBadge.textContent = 'Hubnutí';
        trendBadge.className = 'badge bg-success';
    } else {
        trendBadge.textContent = 'Přibírání na váze';
        trendBadge.className = 'badge bg-danger';
    }

    const sign = diff > 0 ? '+' : '';
    trendValue.textContent = `${sign}${diff.toFixed(1).replace('.', ',')} kg`;
}

const bodyWeightChart = new Chart(document.getElementById('bodyWeightChart'), {
    type: 'line',
    data: {
        labels: [],
        datasets: [{
            label: 'Tělesná hmotnost (kg)',
            data: [],
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.2)',
            borderWidth: 3,
            tension: 0.25,
            fill: true,
            pointRadius: 3,
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

function applyWeightRange(rangeKey) {
    const filtered = getFilteredWeightRows(rangeKey);
    bodyWeightChart.data.labels = filtered.map((row) => formatWeightDate(row.measured_at));
    bodyWeightChart.data.datasets[0].data = filtered.map((row) => Number(row.weight_kg || 0));
    bodyWeightChart.update();
    updateWeightTrend(filtered);
}

if (rangeFilter) {
    rangeFilter.addEventListener('change', (event) => {
        applyWeightRange(event.target.value);
    });
}

applyWeightRange(rangeFilter ? rangeFilter.value : 'month');
</script>
<?php endif; ?>

<?php renderAthleteFooter();
