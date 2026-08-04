<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$pdo = getDB();
$athleteId = (int)getCurrentAthleteId();
$athlete = getCurrentAthlete();
$athleteDisplayName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
if ($athleteDisplayName === '') {
    $athleteDisplayName = trim((string)($athlete['email'] ?? ''));
}

if (!function_exists('athleteMyCoachUnlocked')) {
    function athleteMyCoachUnlocked(PDO $pdo, int $athleteId): bool {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'mycoach_enabled'");
            if ($columnStmt === false || !$columnStmt->fetch()) {
                return false;
            }

            $valueStmt = $pdo->prepare('SELECT mycoach_enabled FROM athletes WHERE id = ? LIMIT 1');
            $valueStmt->execute([$athleteId]);
            return ((int)$valueStmt->fetchColumn()) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!athleteMyCoachUnlocked($pdo, $athleteId)) {
    flash('warning', 'MyCoach je pro váš účet zatím uzamčený.');
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$myCoachUser = mycoachResolveUser($pdo, 'athlete', 0, $athleteId, $athleteDisplayName);
if (!$myCoachUser) {
    flash('danger', 'MyCoach profil se nepodařilo načíst.');
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$myCoachUserId = (int)$myCoachUser['id'];
$entryDate = isset($_GET['date']) ? trim((string)$_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
    $entryDate = date('Y-m-d');
}

$previousDate = date('Y-m-d', strtotime($entryDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($entryDate . ' +1 day'));
$todayDate = date('Y-m-d');

$trainerSessions = mycoachFetchDailyTrainerSessions($pdo, $athleteId, $entryDate);
$latestQuestionnaire = mycoachFetchLatestQuestionnaire($pdo, $myCoachUserId);
$activeGoal = mycoachFetchActiveGoal($pdo, $myCoachUserId);
$timeline = mycoachFetchDailyTimeline($pdo, $myCoachUserId, 45);
$workoutTypeOptions = mycoachWorkoutTypeOptions();
$existingDailyStmt = $pdo->prepare('SELECT * FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date = ? ORDER BY created_at DESC, id DESC LIMIT 1');
$existingDailyStmt->execute([$myCoachUserId, $entryDate]);
$existingDaily = $existingDailyStmt->fetch() ?: null;

$existingDailyRowsStmt = $pdo->prepare('SELECT * FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date = ? ORDER BY created_at DESC, id DESC');
$existingDailyRowsStmt->execute([$myCoachUserId, $entryDate]);
$existingDailyRows = $existingDailyRowsStmt->fetchAll() ?: [];

$editEntryId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editingDailyRow = null;
if ($editEntryId > 0) {
        $editRowStmt = $pdo->prepare(
                'SELECT *
                 FROM mycoach_daily_questionnaires
                 WHERE id = ?
                     AND user_id = ?
                     AND entry_date = ?
                     AND workout_id IS NULL
                 LIMIT 1'
        );
        $editRowStmt->execute([$editEntryId, $myCoachUserId, $entryDate]);
        $editingDailyRow = $editRowStmt->fetch() ?: null;
}

$formSource = $editingDailyRow ?: $existingDaily;
$hasAthleteInputToday = false;
foreach ($existingDailyRows as $dailyRow) {
    if (mycoachDailyEntryHasAthleteInput($dailyRow)) {
        $hasAthleteInputToday = true;
        break;
    }
}

$bubbleStart = date('Y-m-d', strtotime($entryDate . ' -7 days'));
$bubbleEnd = date('Y-m-d', strtotime($entryDate . ' +7 days'));
$recentDaysStmt = $pdo->prepare('SELECT entry_date, COUNT(*) AS entry_count FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date BETWEEN ? AND ? GROUP BY entry_date ORDER BY entry_date ASC');
$recentDaysStmt->execute([$myCoachUserId, $bubbleStart, $bubbleEnd]);
$recentDays = $recentDaysStmt->fetchAll() ?: [];
$recentDayMap = [];
foreach ($recentDays as $recentDay) {
    $recentDayMap[(string)$recentDay['entry_date']] = (int)$recentDay['entry_count'];
}

$recentDayRowsStmt = $pdo->prepare('SELECT entry_date, workout_meta_json, feeling_score, energy_score, motivation_score, sleep_hours, muscle_pain_score, joint_pain_score, rpe_score, training_duration_minutes, calories_burned, avg_heart_rate, max_heart_rate, athlete_note FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date ASC, id ASC');
$recentDayRowsStmt->execute([$myCoachUserId, $bubbleStart, $bubbleEnd]);
$recentDayRows = $recentDayRowsStmt->fetchAll() ?: [];
$recentDayAthleteInputMap = [];
foreach ($recentDayRows as $recentDayRow) {
    $dayKey = (string)($recentDayRow['entry_date'] ?? '');
    if ($dayKey === '') {
        continue;
    }
    if (!isset($recentDayAthleteInputMap[$dayKey])) {
        $recentDayAthleteInputMap[$dayKey] = false;
    }
    if (!$recentDayAthleteInputMap[$dayKey] && mycoachDailyEntryHasAthleteInput($recentDayRow)) {
        $recentDayAthleteInputMap[$dayKey] = true;
    }
}
$trainerDayStatsStmt = $pdo->prepare('SELECT DATE(COALESCE(started_at, completed_at)) AS entry_date, COUNT(*) AS session_count FROM training_sessions WHERE athlete_id = ? AND deleted_by_coach_at IS NULL AND completed_at IS NOT NULL AND DATE(COALESCE(started_at, completed_at)) BETWEEN ? AND ? GROUP BY DATE(COALESCE(started_at, completed_at))');
$trainerDayStatsStmt->execute([$athleteId, $bubbleStart, $bubbleEnd]);
$trainerDayMap = [];
foreach (($trainerDayStatsStmt->fetchAll() ?: []) as $trainerDay) {
    $trainerDayMap[(string)$trainerDay['entry_date']] = (int)$trainerDay['session_count'];
}
$bubbleDays = [];
for ($dayOffset = -7; $dayOffset <= 7; $dayOffset++) {
    $bubbleDate = date('Y-m-d', strtotime($entryDate . ' ' . ($dayOffset >= 0 ? '+' : '') . $dayOffset . ' days'));
    $bubbleDays[] = [
        'date' => $bubbleDate,
        'entry_count' => $recentDayMap[$bubbleDate] ?? 0,
        'has_athlete_input' => $recentDayAthleteInputMap[$bubbleDate] ?? false,
        'trainer_count' => $trainerDayMap[$bubbleDate] ?? 0,
        'is_selected' => $bubbleDate === $entryDate,
        'is_today' => $bubbleDate === $todayDate,
    ];
}

$autoTrainerWorkoutId = 0;
$trainerSessionsById = [];
foreach ($trainerSessions as $session) {
    $sessionId = (int)($session['session_id'] ?? 0);
    if ($sessionId > 0) {
        $autoTrainerWorkoutId = $sessionId;
        $trainerSessionsById[$sessionId] = $session;
    }
}

$selectedWorkoutId = (int)($_POST['workout_id'] ?? ($formSource['workout_id'] ?? $autoTrainerWorkoutId));
$selectedWorkoutId = $selectedWorkoutId > 0 ? $selectedWorkoutId : 0;
$selectedTrainerSession = $selectedWorkoutId > 0 && isset($trainerSessionsById[$selectedWorkoutId]) ? $trainerSessionsById[$selectedWorkoutId] : null;
$trainerDurationDefault = null;
if ($selectedTrainerSession && !empty($selectedTrainerSession['started_at']) && !empty($selectedTrainerSession['completed_at'])) {
    try {
        $startedAt = new DateTimeImmutable((string)$selectedTrainerSession['started_at']);
        $completedAt = new DateTimeImmutable((string)$selectedTrainerSession['completed_at']);
        $trainerDurationDefault = max(0, (int)round(($completedAt->getTimestamp() - $startedAt->getTimestamp()) / 60));
    } catch (Throwable $e) {
        $trainerDurationDefault = null;
    }
}

$readinessSource = [
    'feeling_score' => $_POST['feeling_score'] ?? ($formSource['feeling_score'] ?? null),
    'energy_score' => $_POST['energy_score'] ?? ($formSource['energy_score'] ?? null),
    'motivation_score' => $_POST['motivation_score'] ?? ($formSource['motivation_score'] ?? null),
    'sleep_hours' => $_POST['sleep_hours'] ?? ($formSource['sleep_hours'] ?? null),
    'muscle_pain_score' => $_POST['muscle_pain_score'] ?? ($formSource['muscle_pain_score'] ?? null),
    'joint_pain_score' => $_POST['joint_pain_score'] ?? ($formSource['joint_pain_score'] ?? null),
    'rpe_score' => $_POST['rpe_score'] ?? ($formSource['rpe_score'] ?? null),
    'training_duration_minutes' => $_POST['training_duration_minutes'] ?? ($formSource['training_duration_minutes'] ?? $trainerDurationDefault),
    'calories_burned' => $_POST['calories_burned'] ?? ($formSource['calories_burned'] ?? null),
    'avg_heart_rate' => $_POST['avg_heart_rate'] ?? ($formSource['avg_heart_rate'] ?? null),
    'max_heart_rate' => $_POST['max_heart_rate'] ?? ($formSource['max_heart_rate'] ?? null),
];
$hasExplicitReadinessData = mycoachHasReadinessInputs($readinessSource)
    || !empty($trainerSessions)
    || $formSource !== null;
$projectedReadiness = null;
$readinessContextEntry = $readinessSource;
$readinessScore = null;
$readinessComputedForDate = $entryDate;
$readinessComputedFromText = 'uložených dat';
if ($hasExplicitReadinessData) {
    $readinessScore = mycoachCalculateReadinessScore($readinessSource);
} else {
    $projectedReadiness = mycoachProjectReadinessForDate($timeline, $entryDate);
    if ($projectedReadiness) {
        $readinessScore = (int)$projectedReadiness['score'];
        if (!empty($projectedReadiness['entry']) && is_array($projectedReadiness['entry'])) {
            $readinessContextEntry = $projectedReadiness['entry'];
        }
        $readinessComputedFromText = 'projekce z ' . formatDate((string)$projectedReadiness['source_date']);
    }
}

$recommendation = mycoachBuildRecommendation($readinessScore, $readinessContextEntry, $latestQuestionnaire, $timeline, $activeGoal);
$readinessGuidance = $readinessScore !== null
    ? mycoachBuildReadinessGuidance($readinessScore, $readinessContextEntry, $latestQuestionnaire, $timeline, $activeGoal)
    : null;
$selectedWorkoutType = trim((string)($_POST['workout_type'] ?? ($formSource['workout_type'] ?? '')));
if ($selectedWorkoutType === '') {
    $selectedWorkoutType = 'run';
}
$selectedWorkoutMeta = [];
if (!empty($formSource['workout_meta_json'])) {
    $decodedMeta = json_decode((string)$formSource['workout_meta_json'], true);
    if (is_array($decodedMeta)) {
        $selectedWorkoutMeta = $decodedMeta;
    }
}
if (!empty($_POST['workout_meta']) && is_array($_POST['workout_meta'])) {
    $selectedWorkoutMeta = $_POST['workout_meta'];
}

$dailyError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $dailyError = 'Neplatný bezpečnostní token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_daily') {
            $editingEntryIdPost = isset($_POST['entry_id']) && $_POST['entry_id'] !== '' ? (int)$_POST['entry_id'] : 0;
            $isEditingManualEntry = false;
            if ($editingEntryIdPost > 0) {
                $editableStmt = $pdo->prepare(
                    'SELECT id
                     FROM mycoach_daily_questionnaires
                     WHERE id = ?
                       AND user_id = ?
                       AND entry_date = ?
                       AND workout_id IS NULL
                     LIMIT 1'
                );
                $editableStmt->execute([$editingEntryIdPost, $myCoachUserId, $entryDate]);
                $isEditingManualEntry = (bool)$editableStmt->fetchColumn();
                if (!$isEditingManualEntry) {
                    $dailyError = 'Lze upravit pouze ručně přidaný trénink bez navázané session od trenéra.';
                }
            }

            if ($dailyError === null) {
            $payload = [
                'id' => $isEditingManualEntry ? $editingEntryIdPost : null,
                'entry_date' => $entryDate,
                'workout_id' => $isEditingManualEntry ? null : ($_POST['workout_id'] ?? ($autoTrainerWorkoutId > 0 ? $autoTrainerWorkoutId : null)),
                'workout_type' => $_POST['workout_type'] ?? ($formSource['workout_type'] ?? null),
                'workout_meta' => $_POST['workout_meta'] ?? $selectedWorkoutMeta,
                'feeling_score' => $_POST['feeling_score'] ?? null,
                'rpe_score' => $_POST['rpe_score'] ?? null,
                'sleep_hours' => $_POST['sleep_hours'] ?? null,
                'muscle_pain_score' => $_POST['muscle_pain_score'] ?? null,
                'joint_pain_score' => $_POST['joint_pain_score'] ?? null,
                'motivation_score' => $_POST['motivation_score'] ?? null,
                'energy_score' => $_POST['energy_score'] ?? null,
                'training_duration_minutes' => $_POST['training_duration_minutes'] ?? null,
                'calories_burned' => $_POST['calories_burned'] ?? null,
                'avg_heart_rate' => $_POST['avg_heart_rate'] ?? null,
                'max_heart_rate' => $_POST['max_heart_rate'] ?? null,
                'athlete_note' => $_POST['athlete_note'] ?? null,
            ];

            if (!mycoachStoreDailyQuestionnaire($pdo, $myCoachUserId, $payload)) {
                $dailyError = 'Denní záznam se nepodařilo uložit.';
            } else {
                $savedReadinessScore = mycoachCalculateReadinessScore($payload);
                mycoachStoreReadinessMetric($pdo, $myCoachUserId, $entryDate, $savedReadinessScore);
                if ($isEditingManualEntry) {
                    $successMessage = 'Ručně přidaný trénink byl upraven.';
                } elseif (!empty($payload['workout_id'])) {
                    $successMessage = 'Denní záznam byl uložený i s tréninkem od trenéra.';
                } else {
                    $successMessage = 'Ručně přidaný trénink byl uložen.';
                }
                flash('success', $successMessage);
                redirect(BASE_URL . '/athlete_mycoach_daily.php?date=' . urlencode($entryDate));
            }
            }
        } elseif ($action === 'delete_daily') {
            $deleteEntryId = isset($_POST['entry_id']) && $_POST['entry_id'] !== '' ? (int)$_POST['entry_id'] : 0;
            if ($deleteEntryId <= 0) {
                $dailyError = 'Nebyl vybrán záznam ke smazání.';
            } else {
                $deleteCheckStmt = $pdo->prepare(
                    'SELECT id
                     FROM mycoach_daily_questionnaires
                     WHERE id = ?
                       AND user_id = ?
                       AND entry_date = ?
                       AND workout_id IS NULL
                     LIMIT 1'
                );
                $deleteCheckStmt->execute([$deleteEntryId, $myCoachUserId, $entryDate]);
                if (!$deleteCheckStmt->fetchColumn()) {
                    $dailyError = 'Smazat lze jen ručně přidaný trénink bez navázané session od trenéra.';
                } else {
                    $deleteStmt = $pdo->prepare('DELETE FROM mycoach_daily_questionnaires WHERE id = ? AND user_id = ? LIMIT 1');
                    if (!$deleteStmt->execute([$deleteEntryId, $myCoachUserId])) {
                        $dailyError = 'Záznam se nepodařilo smazat.';
                    } else {
                        $latestRowForDateStmt = $pdo->prepare('SELECT * FROM mycoach_daily_questionnaires WHERE user_id = ? AND entry_date = ? ORDER BY created_at DESC, id DESC LIMIT 1');
                        $latestRowForDateStmt->execute([$myCoachUserId, $entryDate]);
                        $latestRowForDate = $latestRowForDateStmt->fetch() ?: null;
                        if ($latestRowForDate) {
                            mycoachStoreReadinessMetric($pdo, $myCoachUserId, $entryDate, mycoachCalculateReadinessScore($latestRowForDate));
                        } else {
                            $deleteMetricStmt = $pdo->prepare('DELETE FROM mycoach_metrics WHERE user_id = ? AND metric_type = "readiness_score" AND metric_date = ?');
                            $deleteMetricStmt->execute([$myCoachUserId, $entryDate]);
                        }
                        flash('success', 'Ručně přidaný trénink byl smazán.');
                        redirect(BASE_URL . '/athlete_mycoach_daily.php?date=' . urlencode($entryDate));
                    }
                }
            }
        }
    }
}

$feelingValue = (string)($_POST['feeling_score'] ?? ($formSource['feeling_score'] ?? ''));
$rpeValue = (string)($_POST['rpe_score'] ?? ($formSource['rpe_score'] ?? ''));
$sleepValue = (string)($_POST['sleep_hours'] ?? ($formSource['sleep_hours'] ?? ''));
$musclePainValue = (string)($_POST['muscle_pain_score'] ?? ($formSource['muscle_pain_score'] ?? ''));
$jointPainValue = (string)($_POST['joint_pain_score'] ?? ($formSource['joint_pain_score'] ?? ''));
$motivationValue = (string)($_POST['motivation_score'] ?? ($formSource['motivation_score'] ?? ''));
$energyValue = (string)($_POST['energy_score'] ?? ($formSource['energy_score'] ?? ''));
$durationValue = (string)($_POST['training_duration_minutes'] ?? ($formSource['training_duration_minutes'] ?? ($trainerDurationDefault ?? '')));
$caloriesValue = (string)($_POST['calories_burned'] ?? ($formSource['calories_burned'] ?? ''));
$avgHrValue = (string)($_POST['avg_heart_rate'] ?? ($formSource['avg_heart_rate'] ?? ''));
$maxHrValue = (string)($_POST['max_heart_rate'] ?? ($formSource['max_heart_rate'] ?? ''));
$athleteNoteValue = (string)($_POST['athlete_note'] ?? ($formSource['athlete_note'] ?? ''));
$isFormInitiallyOpen = $editingDailyRow !== null || $hasAthleteInputToday || ($_SERVER['REQUEST_METHOD'] === 'POST' && $dailyError);
$sleepMissingForReadiness = trim($sleepValue) === '';

renderAthleteHeader('MyCoach denní záznam', false, true);
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/mycoach-theme.css?v=20260804">
<script>document.body.classList.add('mycoach-theme', 'mycoach-theme--athlete');</script>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 mc-topbar">
    <div>
        <h2 class="mb-1"><i class="fas fa-book-open me-2 text-warning"></i>Denní záznam</h2>
        <div class="text-muted">Dokončené tréninky s trenérem se propisují do dnešního logu automaticky.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap mc-actions">
        <a href="<?= BASE_URL ?>/athlete_mycoach_daily.php?date=<?= urlencode($previousDate) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-chevron-left me-1"></i>Předchozí den</a>
        <a href="<?= BASE_URL ?>/athlete_mycoach_daily.php?date=<?= urlencode($todayDate) ?>" class="btn btn-outline-secondary btn-sm">Dnes</a>
        <a href="<?= BASE_URL ?>/athlete_mycoach_daily.php?date=<?= urlencode($nextDate) ?>" class="btn btn-outline-secondary btn-sm">Další den<i class="fas fa-chevron-right ms-1"></i></a>
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-house me-1"></i>Domů</a>
        <a href="<?= BASE_URL ?>/athlete_mycoach.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Zpět do MyCoach</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex justify-content-between align-items-end flex-wrap gap-3">
        <form method="get" class="d-flex align-items-end gap-2 flex-wrap mb-0">
            <div>
                <label class="form-label fw-semibold mb-1">Vybraný den</label>
                <input type="date" name="date" class="form-control" value="<?= h($entryDate) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Otevřít den</button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="text-muted small text-uppercase fw-bold mb-2">Dny kolem vybraného data</div>
        <div class="d-flex gap-2 overflow-auto pb-2">
            <?php foreach ($bubbleDays as $bubbleDay): ?>
            <?php
                $hasAthleteInputs = !empty($bubbleDay['has_athlete_input']);
                $hasTrainerEntries = $bubbleDay['trainer_count'] > 0;
                $bubbleClass = 'btn-outline-secondary';
                if ($bubbleDay['is_selected']) {
                    $bubbleClass = 'btn-primary';
                } elseif ($hasAthleteInputs && $hasTrainerEntries) {
                    $bubbleClass = 'btn-success';
                } elseif ($hasAthleteInputs) {
                    $bubbleClass = 'btn-info';
                } elseif ($hasTrainerEntries) {
                    $bubbleClass = 'btn-warning';
                }
            ?>
            <a href="<?= BASE_URL ?>/athlete_mycoach_daily.php?date=<?= urlencode($bubbleDay['date']) ?>" class="btn <?= $bubbleClass ?> rounded-circle d-flex flex-column align-items-center justify-content-center flex-shrink-0" style="width:68px;height:68px;line-height:1;">
                <span class="small"><?= h(date('d', strtotime($bubbleDay['date']))) ?></span>
                <span style="font-size:.72rem;"><?= h(mb_substr((string)formatDate($bubbleDay['date']), 3, 3, 'UTF-8')) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="small text-muted mt-2">Modrá = vybraný den, zelená = ručně doplněná MyCoach data + trenérská aktivita, tyrkysová = ručně doplněná MyCoach data, žlutá = pouze trenérská session (bez doplnění sportovce).</div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Datum</div>
                <div class="fs-4 fw-bold"><?= h(formatDate($entryDate)) ?></div>
                <div class="text-muted small">Denní záznam pro <?= h($athleteDisplayName) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Dnešní tréninky od trenéra</div>
                <div class="fs-3 fw-bold text-primary"><?= count($trainerSessions) ?></div>
                <div class="text-muted small">dokončené session propojené s tímto dnem</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Stav záznamu</div>
                <div class="fs-5 fw-bold"><?= $existingDaily ? 'Uložený' : 'Nový' ?></div>
                <div class="text-muted small">MyCoach denní questionnaire</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Readiness</div>
                <div class="fs-3 fw-bold text-success"><?= $readinessScore !== null ? (int)$readinessScore . ' / 100' : 'zatím bez dat' ?></div>
                <div class="text-muted small">odhad připravenosti pro <?= h(formatDate($readinessComputedForDate)) ?> na základě <?= h($readinessComputedFromText) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-<?= h($recommendation['variant']) ?>">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">Doporučení pro <?= h(formatDate($entryDate)) ?></div>
                <div class="fs-5 fw-bold"><?= h($recommendation['title']) ?></div>
                <div class="text-muted"><?= h($recommendation['text']) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($readinessGuidance): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-4 border-<?= h($readinessGuidance['variant']) ?>">
    <div class="card-body">
        <div class="text-muted small text-uppercase fw-bold">Co readiness znamená</div>
        <div class="fs-5 fw-bold"><?= h($readinessGuidance['label']) ?></div>
        <div class="text-muted mb-2"><?= h($readinessGuidance['detail']) ?></div>
        <div class="small text-muted">Dnešní režim: <?= h($readinessGuidance['trainability']) ?> · doporučený strop <?= (int)$readinessGuidance['session_cap_minutes'] ?> min · <?= h($readinessGuidance['intensity_hint']) ?></div>
        <?php if (!empty($readinessGuidance['reasons'])): ?>
        <div class="small text-muted mt-1">Zohledněno: <?= h(implode(' · ', $readinessGuidance['reasons'])) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($dailyError): ?>
<div class="alert alert-danger shadow-sm"><?= h($dailyError) ?></div>
<?php endif; ?>

<?php if ($sleepMissingForReadiness): ?>
<div class="alert alert-warning shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <strong>Pro přesnější Readiness doplň spánek.</strong>
        MyCoach počítá připravenost lépe, když vyplníš délku spánku z noci před dnešním dnem. Není to povinné, ale pro odhad regenerace je to důležité.
    </div>
    <button type="button" class="btn btn-sm btn-outline-dark" id="focusSleepReminderBtn">
        <i class="fas fa-bed me-1"></i>Doplnit spánek
    </button>
</div>
<?php endif; ?>

<?php if ($autoTrainerWorkoutId > 0): ?>
<div class="alert alert-success shadow-sm">
    Dokončený trénink od trenéra je k dnešnímu záznamu připojen automaticky.
</div>
<?php else: ?>
<div class="alert alert-info shadow-sm">
    Až trenér ukončí session, připojí se do denního záznamu automaticky.
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-primary text-white fw-bold">
        <i class="fas fa-dumbbell me-2"></i>Tréninky s trenérem pro dnešek
    </div>
    <div class="card-body">
        <?php if ($trainerSessions): ?>
            <div class="row g-3">
                <?php foreach ($trainerSessions as $session): ?>
                <div class="col-12 col-xl-6">
                    <div class="border rounded-4 p-3 h-100 <?= $selectedWorkoutId === (int)$session['session_id'] ? 'border-primary bg-primary-subtle' : '' ?>">
                        <div class="d-flex justify-content-between gap-3 flex-wrap">
                            <div>
                                <div class="fw-bold fs-5"><?= h((string)($session['set_name'] ?? 'Trénink')) ?></div>
                                <div class="text-muted small">Trenér: <?= h((string)($session['coach_name'] ?? $session['coach_username'] ?? '')) ?></div>
                            </div>
                            <div class="text-end small text-muted">
                                <div><?= !empty($session['started_at']) ? h(formatDateTime((string)$session['started_at'])) : 'bez startu' ?></div>
                                <div><?= !empty($session['completed_at']) ? 'dokončeno ' . h(formatDateTime((string)$session['completed_at'])) : 'nedokončeno' ?></div>
                            </div>
                        </div>
                        <div class="mt-2 small">
                            <?php if (!empty($session['location'])): ?><div>Místo: <?= h((string)$session['location']) ?></div><?php endif; ?>
                            <?php if (!empty($session['notes'])): ?><div>Poznámka: <?= h((string)$session['notes']) ?></div><?php endif; ?>
                            <?php if (!empty($session['started_at']) && !empty($session['completed_at'])): ?>
                            <div>Délka podle session: <?= h((string)max(0, (int)round((strtotime((string)$session['completed_at']) - strtotime((string)$session['started_at'])) / 60))) ?> min</div>
                            <?php endif; ?>
                        </div>
                        <div class="mt-3 d-flex gap-2 flex-wrap">
                            <a href="<?= BASE_URL ?>/athlete_training_detail.php?id=<?= (int)$session['session_id'] ?>" class="btn btn-outline-secondary btn-sm">Detail tréninku</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">Pro dnešek zatím není dokončený trénink od trenéra. Záznam můžeš i tak uložit a doplnit vlastní progres.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="fas fa-plus-circle me-2 text-primary"></i>Přidat trénink / doplnit data</span>
        <button type="button" class="btn btn-sm btn-primary" id="toggleDailyFormBtn" aria-expanded="<?= $isFormInitiallyOpen ? 'true' : 'false' ?>" aria-controls="dailyFormCollapse">
            <?= $isFormInitiallyOpen ? 'Skrýt formulář' : 'Přidat trénink' ?>
        </button>
    </div>
    <div id="dailyFormCollapse" class="<?= $isFormInitiallyOpen ? '' : 'd-none' ?>">
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_daily">
            <input type="hidden" name="date" value="<?= h($entryDate) ?>">
            <input type="hidden" name="entry_id" value="<?= $editingDailyRow ? (int)$editingDailyRow['id'] : '' ?>">
            <input type="hidden" name="workout_id" value="<?= $editingDailyRow ? '' : ($selectedWorkoutId > 0 ? (int)$selectedWorkoutId : '') ?>">
            <?php if ($editingDailyRow): ?>
            <div class="col-12">
                <div class="alert alert-warning mb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span>Upravuješ ručně přidaný záznam z <?= h(formatDateTime((string)($editingDailyRow['created_at'] ?? ''))) ?>.</span>
                    <a href="<?= BASE_URL ?>/athlete_mycoach_daily.php?date=<?= urlencode($entryDate) ?>" class="btn btn-sm btn-outline-dark">Zrušit úpravu</a>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-12">
                <label class="form-label fw-semibold">Přiřazený trénink</label>
                <div class="form-control bg-light">
                    <?php if ($selectedTrainerSession): ?>
                        <?= h((string)($selectedTrainerSession['set_name'] ?? 'Trénink')) ?>
                    <?php else: ?>
                        Bez trenérského tréninku, vyplňuješ jen vlastní denní progres
                    <?php endif; ?>
                </div>
                <div class="form-text">Pole se plní automaticky podle dokončené session trenéra. Ty pak můžeš doplnit reálnou délku, kalorie, tepy nebo vlastní poznámku.</div>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Typ tréninku</label>
                <select name="workout_type" id="workoutTypeSelect" class="form-select" required>
                    <?php foreach ($workoutTypeOptions as $typeKey => $typeDefinition): ?>
                    <option value="<?= h($typeKey) ?>" <?= $selectedWorkoutType === $typeKey ? 'selected' : '' ?>><?= h((string)$typeDefinition['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-8 d-flex align-items-end">
                <div class="text-muted small">Vyplň jen to, co víš. Pole jsou nepovinná, takže můžeš uložit i stručný záznam bez tepů, spánku nebo přesných metrik.</div>
            </div>

            <div class="col-12">
                <div id="workoutTypePanels">
                    <?php foreach ($workoutTypeOptions as $typeKey => $typeDefinition): ?>
                    <div class="workout-type-panel border rounded-4 p-3 mb-3 <?= $selectedWorkoutType === $typeKey ? '' : 'd-none' ?>" data-workout-type-panel="<?= h($typeKey) ?>">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                            <div>
                                <div class="fw-bold fs-5"><i class="fas <?= h((string)$typeDefinition['icon']) ?> me-2 text-primary"></i><?= h((string)$typeDefinition['label']) ?></div>
                                <div class="text-muted small"><?= h((string)$typeDefinition['description']) ?></div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <?php foreach ($typeDefinition['fields'] as $fieldKey => $fieldDefinition): ?>
                            <div class="col-12 col-md-6 col-xl-4">
                                <label class="form-label fw-semibold"><?= h((string)$fieldDefinition['label']) ?></label>
                                <?php if (($fieldDefinition['type'] ?? 'text') === 'number'): ?>
                                <input
                                    type="number"
                                    name="workout_meta[<?= h($fieldKey) ?>]"
                                    class="form-control"
                                    value="<?= h((string)($selectedWorkoutMeta[$fieldKey] ?? '')) ?>"
                                    <?= isset($fieldDefinition['step']) ? 'step="' . h((string)$fieldDefinition['step']) . '"' : '' ?>
                                    <?= isset($fieldDefinition['min']) ? 'min="' . h((string)$fieldDefinition['min']) . '"' : '' ?>
                                    <?= isset($fieldDefinition['max']) ? 'max="' . h((string)$fieldDefinition['max']) . '"' : '' ?>
                                >
                                <?php else: ?>
                                <input
                                    type="text"
                                    name="workout_meta[<?= h($fieldKey) ?>]"
                                    class="form-control"
                                    value="<?= h((string)($selectedWorkoutMeta[$fieldKey] ?? '')) ?>"
                                    placeholder="<?= h((string)($fieldDefinition['placeholder'] ?? '')) ?>"
                                >
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Jak ses cítil?</label>
                <input type="number" min="1" max="10" name="feeling_score" class="form-control" value="<?= h($feelingValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">RPE</label>
                <input type="number" min="1" max="10" name="rpe_score" class="form-control" value="<?= h($rpeValue) ?>">
                <div class="form-text">RPE = subjektivní vnímaná náročnost tréninku na škále 1-10.</div>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Spánek (hod)</label>
                <input type="number" step="0.1" min="0" max="24" name="sleep_hours" id="sleepHoursInput" class="form-control <?= $sleepMissingForReadiness ? 'border-warning' : '' ?>" value="<?= h($sleepValue) ?>">
                <div class="form-text">Vyplň délku spánku z noci před dnešním dnem. Pro Readiness je to jeden z nejdůležitějších vstupů.</div>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Svalová bolest</label>
                <input type="number" min="0" max="10" name="muscle_pain_score" class="form-control" value="<?= h($musclePainValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Bolest kloubů</label>
                <input type="number" min="0" max="10" name="joint_pain_score" class="form-control" value="<?= h($jointPainValue) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Motivace</label>
                <select name="motivation_score" class="form-select">
                    <option value="">Nevím</option>
                    <?php for ($motivationLevel = 1; $motivationLevel <= 10; $motivationLevel++): ?>
                    <option value="<?= $motivationLevel ?>" <?= $motivationValue !== '' && (int)$motivationValue === $motivationLevel ? 'selected' : '' ?>><?= $motivationLevel ?> - <?= $motivationLevel <= 3 ? 'nízká' : ($motivationLevel <= 6 ? 'střední' : ($motivationLevel <= 8 ? 'vysoká' : 'extrémní')) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Energie</label>
                <input type="number" min="1" max="10" name="energy_score" class="form-control" value="<?= h($energyValue) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Délka tréninku (min)</label>
                <input type="number" min="0" max="1440" name="training_duration_minutes" class="form-control" value="<?= h($durationValue) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Spálené kalorie</label>
                <input type="number" min="0" max="20000" name="calories_burned" class="form-control" value="<?= h($caloriesValue) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Prům. tep</label>
                <input type="number" min="0" max="300" name="avg_heart_rate" class="form-control" value="<?= h($avgHrValue) ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Max tep</label>
                <input type="number" min="0" max="300" name="max_heart_rate" class="form-control" value="<?= h($maxHrValue) ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Moje poznámka k tréninku</label>
                <textarea name="athlete_note" class="form-control" rows="3" placeholder="Jak ses cítil, co bylo jinak, co ukázaly hodinky..."><?= h($athleteNoteValue) ?></textarea>
            </div>

            <div class="col-12 d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-success fw-semibold"><i class="fas fa-save me-1"></i>Uložit denní záznam</button>
                <a href="<?= BASE_URL ?>/athlete_mycoach.php" class="btn btn-outline-secondary">Zpět</a>
            </div>
        </form>
    </div>
    </div>
</div>

<div class="alert alert-info border-0 shadow-sm mt-4 mb-0">
    <?php if ($latestQuestionnaire): ?>
        Poslední onboarding dotazník je uložený a denní záznam už nad ním může stavět.
    <?php else: ?>
        Nejprve je vhodné vyplnit úvodní dotazník v MyCoach.
    <?php endif; ?>
</div>

<?php if ($existingDailyRows): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-light fw-semibold">
        <i class="fas fa-layer-group me-2"></i>Záznamy pro tento den
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($existingDailyRows as $row): ?>
            <div class="col-12 col-lg-6">
                <div class="border rounded-4 p-3 h-100">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <div class="fw-bold"><?= h(formatDateTime((string)($row['created_at'] ?? ''))) ?></div>
                        <?php $rowReadiness = mycoachCalculateReadinessScore($row); ?>
                        <span class="badge bg-<?= h(mycoachBuildRecommendation($rowReadiness, $row, $latestQuestionnaire, $timeline, $activeGoal)['variant']) ?>"><?= h((string)($row['workout_type'] ?? 'záznam')) ?></span>
                    </div>
                    <div class="small mt-2">
                        <?php if (empty($row['workout_id'])): ?>
                        <span class="badge bg-info text-dark">Ručně přidaný trénink</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Trénink od trenéra</span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted mt-2">
                        <?php if (!empty($row['training_duration_minutes'])): ?>Délka: <?= (int)$row['training_duration_minutes'] ?> min<?php endif; ?>
                        <?php if (!empty($row['calories_burned'])): ?> · Kalorie: <?= (int)$row['calories_burned'] ?> kcal<?php endif; ?>
                        <?php if (!empty($row['rpe_score'])): ?> · RPE: <?= (int)$row['rpe_score'] ?><?php endif; ?>
                        <?php if (!empty($row['energy_score'])): ?> · Energie: <?= (int)$row['energy_score'] ?>/10<?php endif; ?>
                        · Readiness <?= (int)$rowReadiness ?>/100
                    </div>
                    <?php if (!empty($row['athlete_note'])): ?>
                    <div class="small mt-2" style="white-space: pre-wrap;"><?= h((string)$row['athlete_note']) ?></div>
                    <?php endif; ?>
                    <?php if (empty($row['workout_id'])): ?>
                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <a href="<?= BASE_URL ?>/athlete_mycoach_daily.php?date=<?= urlencode($entryDate) ?>&edit_id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-pen me-1"></i>Upravit
                        </a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu chceš tento ručně přidaný trénink smazat?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_daily">
                            <input type="hidden" name="entry_id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="mt-3 small text-muted">
                        <i class="fas fa-lock me-1"></i>Spravuje trenér. Tento záznam je navázaný na trenérskou session, proto ho zde nelze upravit ani smazat.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('workoutTypeSelect');
    const panels = document.querySelectorAll('[data-workout-type-panel]');
    const toggleBtn = document.getElementById('toggleDailyFormBtn');
    const formCollapse = document.getElementById('dailyFormCollapse');
    const sleepReminderBtn = document.getElementById('focusSleepReminderBtn');
    const sleepHoursInput = document.getElementById('sleepHoursInput');

    function syncPanels() {
        const activeType = typeSelect ? typeSelect.value : '';
        panels.forEach(function (panel) {
            panel.classList.toggle('d-none', panel.dataset.workoutTypePanel !== activeType);
        });
    }

    function syncToggleButton() {
        if (!toggleBtn || !formCollapse) {
            return;
        }
        const expanded = !formCollapse.classList.contains('d-none');
        toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggleBtn.textContent = expanded ? 'Skrýt formulář' : 'Přidat trénink';
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', syncPanels);
        syncPanels();
    }

    if (toggleBtn && formCollapse) {
        toggleBtn.addEventListener('click', function () {
            formCollapse.classList.toggle('d-none');
            syncToggleButton();
        });
        syncToggleButton();
    }

    if (sleepReminderBtn && sleepHoursInput && formCollapse) {
        sleepReminderBtn.addEventListener('click', function () {
            formCollapse.classList.remove('d-none');
            syncToggleButton();
            sleepHoursInput.focus();
            sleepHoursInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }
});
</script>

<?php renderAthleteFooter();
