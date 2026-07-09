<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$athleteId = intParam($_GET, 'id');
$pdo       = getDB();

// Ověření vlastnictví
$stmt = $pdo->prepare('SELECT * FROM athletes WHERE id = ? AND coach_id = ?');
$stmt->execute([$athleteId, $coachId]);
$athlete = $stmt->fetch();
$athleteAge = calculateAge($athlete['birth_date'] ?? null);

if (!$athlete) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_weight') {
        $weightInput = str_replace(',', '.', trim((string)($_POST['weight_kg'] ?? '')));
        $measuredAt = preg_replace('/[^0-9\-]/', '', (string)($_POST['measured_at'] ?? date('Y-m-d')));
        $weightKg = is_numeric($weightInput) ? (float)$weightInput : 0.0;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $measuredAt)) {
            flash('danger', 'Zadejte platné datum vážení.');
        } elseif ($weightKg < 20 || $weightKg > 400) {
            flash('danger', 'Zadejte platnou hmotnost v kg.');
        } else {
            addAthleteWeightLog($athleteId, $measuredAt, $weightKg, 'coach', $coachId, null);
            flash('success', 'Tělesná hmotnost byla uložena.');
        }

        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }

    if ($action === 'send_weight_invite') {
        if (empty($athlete['email'])) {
            flash('danger', 'Sportovec nemá vyplněný e-mail.');
            redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
        }

        $invite = createAthleteWeightInvite($athleteId, $coachId, (string)$athlete['email'], 72);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $baseUrlAbsolute = $host !== '' ? ($scheme . '://' . $host . BASE_URL) : BASE_URL;
        $inviteUrl = rtrim($baseUrlAbsolute, '/') . '/athlete_weight_entry.php?token=' . urlencode((string)$invite['token']);

        $coach = getCurrentCoach();
        $coachName = ($coach['name'] ?? '') !== '' ? (string)$coach['name'] : (string)($coach['username'] ?? 'Váš trenér');
        $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);

        $sent = sendAthleteWeightInviteEmail(
            (string)$athlete['email'],
            $athleteName,
            $coachName,
            $inviteUrl,
            (string)$invite['expires_at']
        );

        if ($sent) {
            flash('success', 'Výzva k zadání hmotnosti byla odeslána na e-mail sportovce.');
        } else {
            flash('danger', 'E-mail s výzvou se nepodařilo odeslat.');
        }

        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }
}

// Tréninkové záznamy sportovce
$stmt = $pdo->prepare(
    'SELECT ts.*, ws.name AS set_name,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.session_id = ts.id) AS total_series
     FROM training_sessions ts
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
    WHERE ts.athlete_id = ? AND ts.deleted_by_coach_at IS NULL
     ORDER BY ts.started_at DESC'
);
$stmt->execute([$athleteId]);
$sessions = $stmt->fetchAll();

$weightHistory = getAthleteWeightHistory($athleteId, 500);
$weightStats = getAthleteWeightStats($athleteId);

// Přidání aktuální váhy a indikace vývoje
$currentWeight = $weightStats['current_weight'] ?? null;
$initialWeight = $weightStats['initial_weight'] ?? null;
$lastUpdated = $weightStats['last_updated'] ?? null;
$weightTrend = null;

if ($currentWeight !== null && $initialWeight !== null) {
    if ($currentWeight > $initialWeight) {
        $weightTrend = 'up';
    } elseif ($currentWeight < $initialWeight) {
        $weightTrend = 'down';
    }
}

$weightWarning = null;
if ($lastUpdated !== null) {
    $daysSinceUpdate = (new DateTime())->diff(new DateTime($lastUpdated))->days;
    if ($daysSinceUpdate > 7) {
        $weightWarning = 'Váha nebyla aktualizována déle než 7 dní.';
    }
}

// Historie tréninků
$stmt = $pdo->prepare(
    'SELECT ts.*, ws.name AS set_name,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.session_id = ts.id) AS total_series
     FROM training_sessions ts
     JOIN workout_sets ws ON ts.workout_set_id = ws.id
    WHERE ts.athlete_id = ? AND ts.deleted_by_coach_at IS NULL
     ORDER BY ts.started_at DESC'
);
$stmt->execute([$athleteId]);
$sessions = $stmt->fetchAll();

$weightHistory = getAthleteWeightHistory($athleteId, 500);
$weightStats = getAthleteWeightStats($athleteId);

// Poslední trénink
$lastSession = null;
foreach ($sessions as $s) {
    if ($s['completed_at']) { $lastSession = $s; break; }
}

$currentMonthKey = date('Y-m');
$currentMonthSessions = [];
$olderSessionsByMonth = [];
foreach ($sessions as $s) {
    $monthKey = date('Y-m', strtotime($s['started_at']));
    if ($monthKey === $currentMonthKey) {
        $currentMonthSessions[] = $s;
    } else {
        $olderSessionsByMonth[$monthKey][] = $s;
    }
}

$currentMonthPreviewLimit = 5;
$currentMonthVisibleSessions = array_slice($currentMonthSessions, 0, $currentMonthPreviewLimit);
$currentMonthCollapsedSessions = array_slice($currentMonthSessions, $currentMonthPreviewLimit);

// Sady dostupné pro trénink (pro dropdown "Spustit trénink")
$stmtSets = $pdo->prepare(
    'SELECT ws.*, COUNT(wse.id) AS exercise_count,
            GROUP_CONCAT(e.name ORDER BY wse.exercise_order SEPARATOR ", ") AS exercise_names
     FROM workout_sets ws
     LEFT JOIN workout_set_exercises wse ON ws.id = wse.workout_set_id
     LEFT JOIN exercises e ON e.id = wse.exercise_id
     WHERE ws.coach_id = ?
     GROUP BY ws.id
     ORDER BY ws.name'
);
$stmtSets->execute([$coachId]);
$workoutSets = $stmtSets->fetchAll();

renderHeader(h($athlete['first_name'] . ' ' . $athlete['last_name']), true);
?>

<div class="d-flex align-items-center mb-4 gap-3 page-header">
    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="mb-0 fw-bold"><?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?></h2>
    <div class="ms-auto d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/training_manual_add.php?athlete_id=<?= $athleteId ?>"
           class="btn btn-outline-warning btn-sm">
            <i class="fas fa-calendar-plus me-1"></i>Přidat minulý trénink
        </a>
        <a href="<?= BASE_URL ?>/graphs.php?athlete_id=<?= $athleteId ?>"
           class="btn btn-outline-info btn-sm">
            <i class="fas fa-chart-line me-1"></i>Grafy
        </a>
        <a href="<?= BASE_URL ?>/progress_report.php?athlete_id=<?= $athleteId ?>"
           class="btn btn-outline-success btn-sm">
            <i class="fas fa-file-alt me-1"></i>Zpráva o pokroku
        </a>
        <a href="<?= BASE_URL ?>/athlete_edit.php?id=<?= $athleteId ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-edit me-1"></i>Upravit
        </a>
        <form method="post" action="<?= BASE_URL ?>/athlete_delete.php" class="d-inline"
              onsubmit="return confirm('Opravdu smazat tohoto sportovce? Smažou se i všechny tréninky!')">
            <?= csrfField() ?>
            <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="fas fa-trash"></i>
            </button>
        </form>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Info karta -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-user me-2"></i>Informace
            </div>
            <div class="card-body">
                <?php $athletePhoto = photoUrl($athlete['photo'] ?? null, 'athletes'); ?>
                <div class="text-center mb-3">
                    <?php if ($athlete['photo']): ?>
                    <img src="<?= h($athletePhoto) ?>" alt="<?= h($athlete['first_name']) ?>"
                         class="rounded-circle" style="width:100px;height:100px;object-fit:cover;border:3px solid #ffc107;">
                    <?php else: ?>
                    <?php $initials = strtoupper(mb_substr($athlete['first_name'], 0, 1, 'UTF-8') . mb_substr($athlete['last_name'], 0, 1, 'UTF-8')); ?>
                    <div class="avatar-initials" title="<?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?>">
                        <?= $initials ?>
                    </div>
                    <?php endif; ?>
                </div>
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted fw-semibold" style="width:40%">Věk</td>
                        <td><?= $athleteAge !== null ? h((string)$athleteAge) . ' let' : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Datum narození</td>
                        <td><?= !empty($athlete['birth_date']) ? formatDate($athlete['birth_date']) : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">E-mail</td>
                        <td><?= $athlete['email'] ? '<a href="mailto:'.h($athlete['email']).'">'.h($athlete['email']).'</a>' : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Tel. kontakt</td>
                        <td><?= $athlete['phone_contact'] ? h($athlete['phone_contact']) : '–' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Tréninků</td>
                        <td><span class="badge bg-warning text-dark"><?= count(array_filter($sessions, fn($s) => $s['completed_at'])) ?>×</span></td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Počáteční váha</td>
                        <td>
                            <?php if ($weightStats['first_weight'] !== null): ?>
                                <?= number_format((float)$weightStats['first_weight'], 1, ',', '') ?> kg
                                <small class="text-muted d-block"><?= formatDate((string)$weightStats['first_date']) ?></small>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Aktuální váha</td>
                        <td>
                            <?php if ($weightStats['current_weight'] !== null): ?>
                                <?= number_format((float)$weightStats['current_weight'], 1, ',', '') ?> kg
                                <small class="text-muted d-block"><?= formatDate((string)$weightStats['current_date']) ?></small>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Pokrok od startu</td>
                        <td>
                            <?php if ($weightStats['change_kg'] !== null): ?>
                                <?php $delta = (float)$weightStats['change_kg']; ?>
                                <span class="badge <?= $delta <= 0 ? 'bg-success' : 'bg-danger' ?>">
                                    <?= $delta > 0 ? '+' : '' ?><?= number_format($delta, 1, ',', '') ?> kg
                                </span>
                                <?php if ($weightStats['change_percent'] !== null): ?>
                                    <small class="text-muted d-block"><?= $weightStats['change_percent'] > 0 ? '+' : '' ?><?= number_format((float)$weightStats['change_percent'], 1, ',', '') ?> %</small>
                                <?php endif; ?>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted fw-semibold">Přidán</td>
                        <td><?= formatDate($athlete['created_at']) ?></td>
                    </tr>
                    <?php if ($athlete['notes']): ?>
                    <tr>
                        <td class="text-muted fw-semibold">Poznámky</td>
                        <td><?= h($athlete['notes']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Spustit trénink -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-warning text-dark">
                <i class="fas fa-play me-2"></i>Spustit nový trénink
            </div>
            <div class="card-body">
                <?php if ($lastSession): ?>
                <div class="alert alert-light border mb-3 py-2">
                    <small class="text-muted">Poslední trénink:</small><br>
                    <strong><?= h($lastSession['set_name']) ?></strong>
                    – <?= formatDateTime($lastSession['completed_at']) ?>
                    <?php if ($lastSession['location']): ?>
                    <span class="text-muted ms-2"><i class="fas fa-map-marker-alt"></i> <?= h($lastSession['location']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (empty($workoutSets)): ?>
                <div class="alert alert-warning py-2 mb-0">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    Nejprve vytvořte <a href="<?= BASE_URL ?>/sady.php" class="alert-link">sady</a> a
                    <a href="<?= BASE_URL ?>/exercises.php" class="alert-link">cviky</a>.
                </div>
                <?php else: ?>
                <form action="<?= BASE_URL ?>/training_start.php" method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="workout_set_id">Vyberte sadu</label>
                        <select name="workout_set_id" id="workout_set_id" class="form-select" required>
                            <option value="">– vyberte sadu –</option>
                            <?php foreach ($workoutSets as $ws): ?>
                            <option value="<?= $ws['id'] ?>">
                                <?= h($ws['name']) ?>
                                (<?= $ws['exercise_count'] ?> <?= $ws['exercise_count'] === 1 ? 'cvik' : ($ws['exercise_count'] < 5 ? 'cviky' : 'cviků') ?>)
                                <?php if (!empty($ws['exercise_names'])): ?>
                                    – <?= h($ws['exercise_names']) ?>
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="fas fa-play me-1"></i>Zahájit trénink
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-weight-scale me-2"></i>Tělesná hmotnost
            </div>
            <div class="card-body">
                <form method="post" class="mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_weight">
                    <div class="mb-2">
                        <label class="form-label fw-semibold" for="measured_at">Datum vážení</label>
                        <input type="date" id="measured_at" name="measured_at" class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="weight_kg">Aktuální váha (kg)</label>
                        <input type="number" id="weight_kg" name="weight_kg" class="form-control"
                               min="20" max="400" step="0.1"
                               value="<?= $weightStats['current_weight'] !== null ? h((string)$weightStats['current_weight']) : '' ?>"
                               placeholder="např. 78.4" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                        <i class="fas fa-save me-1"></i>Uložit váhu
                    </button>
                </form>

                <?php if (!empty($athlete['email'])): ?>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="send_weight_invite">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="fas fa-envelope me-1"></i>Odeslat výzvu k zadání váhy
                    </button>
                    <small class="text-muted d-block mt-2">Sportovec obdrží e-mail s bezpečným odkazem pro jednorázové zadání.</small>
                </form>
                <?php else: ?>
                <div class="alert alert-warning py-2 mb-0">
                    Sportovec nemá vyplněný e-mail, výzvu nelze odeslat.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fas fa-chart-line me-2"></i>Vývoj hmotnosti</span>
                <span class="badge bg-light text-dark"><?= (int)$weightStats['entries'] ?> záznamů</span>
            </div>
            <div class="card-body">
                <?php if (empty($weightHistory)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-chart-area fa-2x mb-2 d-block"></i>
                    Zatím nejsou evidované žádné záznamy hmotnosti.
                </div>
                <?php else: ?>
                <canvas id="athleteWeightChart" style="max-height:340px"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Historie tréninků -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fas fa-history me-2"></i>Historie tréninků</span>
        <?php if (!empty($sessions)): ?>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-light btn-sm" id="toggleSelectTrainings">
                <i class="fas fa-check-square me-1"></i>Označit vše
            </button>
            <button type="submit" form="bulkDeleteTrainingsForm" class="btn btn-danger btn-sm fw-bold" id="bulkDeleteBtn" disabled>
                <i class="fas fa-trash me-1"></i>Smazat vybrané
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($sessions)): ?>
        <div class="text-center py-4 text-muted">
            <i class="fas fa-inbox fa-2x mb-2"></i><br>Zatím žádné tréninky.
        </div>
        <?php else: ?>
        <form method="post" action="<?= BASE_URL ?>/training_delete.php" id="bulkDeleteTrainingsForm" onsubmit="return confirmBulkDeleteTrainings();">
            <?= csrfField() ?>
            <input type="hidden" name="redirect_to" value="<?= h(BASE_URL . '/athlete_detail.php?id=' . $athleteId) ?>">

            <div class="p-3 border-bottom bg-light">
                <strong>Aktuální měsíc (<?= date('m/Y') ?>)</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle table-sessions" id="currentMonthTrainingsTable">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:42px;">
                                <input class="form-check-input" type="checkbox" id="checkAllCurrent">
                            </th>
                            <th>Datum</th>
                            <th>Sada</th>
                            <th>Místo</th>
                            <th class="text-center">Sérií</th>
                            <th>Stav</th>
                            <th class="text-center" title="Fotografie"><i class="fas fa-camera"></i></th>
                            <th class="text-end">Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($currentMonthSessions)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">V aktuálním měsíci zatím nejsou žádné tréninky.</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($currentMonthVisibleSessions as $s): ?>
                        <tr>
                            <td class="text-center">
                                <input class="form-check-input training-bulk-check" type="checkbox" name="session_ids[]" value="<?= (int)$s['id'] ?>">
                            </td>
                            <td>
                                <strong><?= formatDate($s['started_at']) ?></strong>
                                <br><small class="text-muted"><?= date('H:i', strtotime($s['started_at'])) ?></small>
                            </td>
                            <td><span class="badge bg-secondary fs-6"><?= h($s['set_name']) ?></span></td>
                            <td class="text-muted"><?= $s['location'] ? h($s['location']) : '–' ?></td>
                            <td class="text-center"><?= $s['total_series'] ?></td>
                            <td>
                                <?php if ($s['completed_at']): ?>
                                <span class="badge bg-success">Dokončeno</span>
                                <?php else: ?>
                                <span class="badge bg-warning text-dark">Probíhá</span>
                                <?php endif; ?>
                                <?php if ($s['paired_session_id']): ?>
                                <span class="badge bg-info text-dark mt-1">
                                    <i class="fas fa-people-group me-1"></i>Párový
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if (!empty($s['training_photo'])): ?>
                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>#training-photo"
                                   title="Zobrazit fotografii">
                                    <img src="<?= h(photoUrl($s['training_photo'], 'trainings')) ?>"
                                         alt="foto"
                                         style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:2px solid #ffc107">
                                </a>
                                <?php else: ?>
                                <span class="text-muted">–</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (!$s['completed_at']): ?>
                                <a href="<?= BASE_URL ?>/training_session.php?id=<?= $s['id'] ?>"
                                   class="btn btn-warning btn-sm">
                                    <i class="fas fa-play me-1"></i>Pokračovat
                                </a>
                                <?php else: ?>
                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>"
                                   class="btn btn-outline-dark btn-sm">
                                    <i class="fas fa-eye me-1"></i>Detail
                                </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        onclick="deleteSingleTraining(<?= (int)$s['id'] ?>)" title="Smazat trénink">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($currentMonthCollapsedSessions)): ?>
                    <tbody id="scriptsCurrentMonthTrainingsCollapse" class="collapse">
                        <?php foreach ($currentMonthCollapsedSessions as $s): ?>
                        <tr>
                            <td>
                                <strong><?= formatDate($s['started_at']) ?></strong>
                                <br><small class="text-muted"><?= date('H:i', strtotime($s['started_at'])) ?></small>
                            </td>
                            <td><span class="badge bg-secondary fs-6"><?= h($s['set_name']) ?></span></td>
                            <td class="text-muted"><?= $s['location'] ? h($s['location']) : '–' ?></td>
                            <td class="text-center"><?= $s['total_series'] ?></td>
                            <td>
                                <?php if ($s['completed_at']): ?>
                                <span class="badge bg-success">Dokončeno</span>
                                <?php else: ?>
                                <span class="badge bg-warning text-dark">Probíhá</span>
                                <?php endif; ?>
                                <?php if ($s['paired_session_id']): ?>
                                <span class="badge bg-info text-dark mt-1">
                                    <i class="fas fa-people-group me-1"></i>Párový
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if (!empty($s['training_photo'])): ?>
                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>#training-photo"
                                   title="Zobrazit fotografii">
                                    <img src="<?= h(photoUrl($s['training_photo'], 'trainings')) ?>"
                                         alt="foto"
                                         style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:2px solid #ffc107">
                                </a>
                                <?php else: ?>
                                <span class="text-muted">–</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (!$s['completed_at']): ?>
                                <a href="<?= BASE_URL ?>/training_session.php?id=<?= $s['id'] ?>"
                                   class="btn btn-warning btn-sm">
                                    <i class="fas fa-play me-1"></i>Pokračovat
                                </a>
                                <?php else: ?>
                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>"
                                   class="btn btn-outline-dark btn-sm">
                                    <i class="fas fa-eye me-1"></i>Detail
                                </a>
                                <?php endif; ?>
                                <form method="post" action="<?= BASE_URL ?>/training_delete.php" class="d-inline"
                                      onsubmit="return confirm('Opravdu smazat tento trénink? V administraci půjde obnovit.');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                                    <input type="hidden" name="redirect_to" value="<?= h(BASE_URL . '/athlete_detail.php?id=' . $athleteId) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Smazat trénink">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php endif; ?>
                </table>
            </div>

            <?php if (!empty($currentMonthCollapsedSessions)): ?>
            <div class="border-top p-3 text-center bg-light">
                <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#scriptsCurrentMonthTrainingsCollapse" aria-expanded="false" aria-controls="scriptsCurrentMonthTrainingsCollapse">
                    <i class="fas fa-chevron-down me-1"></i>
                    Zobrazit starší tréninky (<?= count($currentMonthCollapsedSessions) ?>)
                </button>
            </div>
            <?php endif; ?>

            <?php if (!empty($olderSessionsByMonth)): ?>
            <div class="accordion" id="olderMonthsAccordion">
                <?php $monthIdx = 0; ?>
                <?php foreach ($olderSessionsByMonth as $monthKey => $monthSessions): ?>
                <?php
                $monthIdx++;
                $accId = 'month-' . $monthIdx;
                $monthLabel = date('m/Y', strtotime($monthSessions[0]['started_at']));
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading-<?= $accId ?>">
                        <button class="accordion-button collapsed" type="button"
                                data-bs-toggle="collapse" data-bs-target="#collapse-<?= $accId ?>"
                                aria-expanded="false" aria-controls="collapse-<?= $accId ?>">
                            <?= h($monthLabel) ?>
                            <span class="badge bg-secondary ms-2"><?= count($monthSessions) ?></span>
                        </button>
                    </h2>
                    <div id="collapse-<?= $accId ?>" class="accordion-collapse collapse"
                         aria-labelledby="heading-<?= $accId ?>" data-bs-parent="#olderMonthsAccordion">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle table-sessions">
                                    <tbody>
                                        <?php foreach ($monthSessions as $s): ?>
                                        <tr>
                                            <td class="text-center" style="width:42px;">
                                                <input class="form-check-input training-bulk-check" type="checkbox" name="session_ids[]" value="<?= (int)$s['id'] ?>">
                                            </td>
                                            <td>
                                                <strong><?= formatDate($s['started_at']) ?></strong>
                                                <br><small class="text-muted"><?= date('H:i', strtotime($s['started_at'])) ?></small>
                                            </td>
                                            <td><span class="badge bg-secondary fs-6"><?= h($s['set_name']) ?></span></td>
                                            <td class="text-muted"><?= $s['location'] ? h($s['location']) : '–' ?></td>
                                            <td class="text-center"><?= $s['total_series'] ?></td>
                                            <td>
                                                <?php if ($s['completed_at']): ?>
                                                <span class="badge bg-success">Dokončeno</span>
                                                <?php else: ?>
                                                <span class="badge bg-warning text-dark">Probíhá</span>
                                                <?php endif; ?>
                                                <?php if ($s['paired_session_id']): ?>
                                                <span class="badge bg-info text-dark mt-1">
                                                    <i class="fas fa-people-group me-1"></i>Párový
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if (!empty($s['training_photo'])): ?>
                                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>#training-photo"
                                                   title="Zobrazit fotografii">
                                                    <img src="<?= h(photoUrl($s['training_photo'], 'trainings')) ?>"
                                                         alt="foto"
                                                         style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:2px solid #ffc107">
                                                </a>
                                                <?php else: ?>
                                                <span class="text-muted">–</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if (!$s['completed_at']): ?>
                                                <a href="<?= BASE_URL ?>/training_session.php?id=<?= $s['id'] ?>"
                                                   class="btn btn-warning btn-sm">
                                                    <i class="fas fa-play me-1"></i>Pokračovat
                                                </a>
                                                <?php else: ?>
                                                <a href="<?= BASE_URL ?>/training_detail.php?id=<?= $s['id'] ?>"
                                                   class="btn btn-outline-dark btn-sm">
                                                    <i class="fas fa-eye me-1"></i>Detail
                                                </a>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-outline-danger btn-sm"
                                                        onclick="deleteSingleTraining(<?= (int)$s['id'] ?>)" title="Smazat trénink">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
let forceSingleDeleteSubmit = false;
const weightHistoryData = <?= json_encode(array_map(
    static fn(array $row): array => [
        'label' => formatDate((string)$row['measured_at']),
        'weight' => (float)$row['weight_kg'],
    ],
    array_reverse($weightHistory)
), JSON_UNESCAPED_UNICODE) ?>;

function getTrainingChecks() {
    return Array.from(document.querySelectorAll('.training-bulk-check'));
}

function updateBulkDeleteButtonState() {
    const deleteBtn = document.getElementById('bulkDeleteBtn');
    if (!deleteBtn) return;
    const selected = getTrainingChecks().filter((el) => el.checked).length;
    deleteBtn.disabled = selected === 0;
    deleteBtn.innerHTML = selected > 0
        ? '<i class="fas fa-trash me-1"></i>Smazat vybrané (' + selected + ')'
        : '<i class="fas fa-trash me-1"></i>Smazat vybrané';
}

function deleteSingleTraining(sessionId) {
    const checks = getTrainingChecks();
    checks.forEach((el) => {
        el.checked = el.value === String(sessionId);
    });
    updateBulkDeleteButtonState();

    if (!confirm('Opravdu smazat tento trénink? V administraci půjde obnovit.')) {
        return;
    }

    forceSingleDeleteSubmit = true;
    document.getElementById('bulkDeleteTrainingsForm').submit();
}

function confirmBulkDeleteTrainings() {
    if (forceSingleDeleteSubmit) {
        forceSingleDeleteSubmit = false;
        return true;
    }
    const selected = getTrainingChecks().filter((el) => el.checked).length;
    if (selected === 0) {
        alert('Vyberte alespoň jeden trénink.');
        return false;
    }
    return confirm('Opravdu smazat vybrané tréninky (' + selected + '×)? V administraci půjdou obnovit.');
}

document.addEventListener('DOMContentLoaded', function () {
    const weightCanvas = document.getElementById('athleteWeightChart');
    if (weightCanvas && Array.isArray(weightHistoryData) && weightHistoryData.length > 0 && window.Chart) {
        new Chart(weightCanvas, {
            type: 'line',
            data: {
                labels: weightHistoryData.map((p) => p.label),
                datasets: [{
                    label: 'Hmotnost (kg)',
                    data: weightHistoryData.map((p) => p.weight),
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13,110,253,0.15)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: (value) => value + ' kg',
                        },
                    },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ' ' + ctx.parsed.y + ' kg',
                        },
                    },
                },
            },
        });
    }

    const checks = getTrainingChecks();
    const toggleAllBtn = document.getElementById('toggleSelectTrainings');
    const checkAllCurrent = document.getElementById('checkAllCurrent');

    checks.forEach((el) => {
        el.addEventListener('change', updateBulkDeleteButtonState);
    });

    if (toggleAllBtn) {
        toggleAllBtn.addEventListener('click', function () {
            const hasUnchecked = checks.some((el) => !el.checked);
            checks.forEach((el) => {
                el.checked = hasUnchecked;
            });
            this.innerHTML = hasUnchecked
                ? '<i class="fas fa-minus-square me-1"></i>Odebrat výběr'
                : '<i class="fas fa-check-square me-1"></i>Označit vše';
            if (checkAllCurrent) {
                const currentChecks = Array.from(document.querySelectorAll('#currentMonthTrainingsTable .training-bulk-check'));
                checkAllCurrent.checked = currentChecks.length > 0 && currentChecks.every((cb) => cb.checked);
            }
            updateBulkDeleteButtonState();
        });
    }

    if (checkAllCurrent) {
        checkAllCurrent.addEventListener('change', function () {
            const currentRows = Array.from(document.querySelectorAll('#currentMonthTrainingsTable .training-bulk-check'));
            currentRows.forEach((cb) => {
                cb.checked = this.checked;
            });
            updateBulkDeleteButtonState();
        });
    }

    updateBulkDeleteButtonState();
});
</script>

<?php renderFooter(); ?>
