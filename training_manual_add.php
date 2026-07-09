<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo = getDB();
$trainingVenues = getTrainingVenuesForCoach((int)$coachId);
$golfCourses = [];
$teesByCourse = [];
$errors = [];

$athleteId = intParam($_GET, 'athlete_id');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $athleteId = intParam($_POST, 'athlete_id');
}

$stmtAthlete = $pdo->prepare(
    'SELECT id, first_name, last_name
     FROM athletes
     WHERE id = ? AND coach_id = ?'
);
$stmtAthlete->execute([$athleteId, $coachId]);
$athlete = $stmtAthlete->fetch(PDO::FETCH_ASSOC);

if (!$athlete) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$stmtSets = $pdo->prepare(
    'SELECT ws.id, ws.name
     FROM workout_sets ws
     WHERE ws.coach_id = ?
     ORDER BY ws.name'
);
$stmtSets->execute([$coachId]);
$workoutSets = $stmtSets->fetchAll(PDO::FETCH_ASSOC);

$stmtSetExercises = $pdo->prepare(
    'SELECT wse.workout_set_id, wse.exercise_id, wse.exercise_order, e.name AS exercise_name
     FROM workout_set_exercises wse
     JOIN workout_sets ws ON ws.id = wse.workout_set_id
     JOIN exercises e ON e.id = wse.exercise_id
     WHERE ws.coach_id = ?
     ORDER BY ws.name, wse.exercise_order'
);
$stmtSetExercises->execute([$coachId]);
$setExerciseRows = $stmtSetExercises->fetchAll(PDO::FETCH_ASSOC);

$setExercisesMap = [];
// Získat sport_type pro každý cvik
$exerciseTypes = [];
$stmtTypes = $pdo->query('SELECT id, sport_type FROM exercises');
foreach ($stmtTypes->fetchAll(PDO::FETCH_ASSOC) as $typeRow) {
    $resolvedType = $typeRow['sport_type'] ?? 'standard';
    if (in_array($resolvedType, ['golf', 'run_outdoor', 'run_treadmill'], true)) {
        $resolvedType = 'standard';
    }
    $exerciseTypes[(int)$typeRow['id']] = $resolvedType;
}
foreach ($setExerciseRows as $row) {
    $setId = (int)$row['workout_set_id'];
    if (!isset($setExercisesMap[$setId])) {
        $setExercisesMap[$setId] = [];
    }
    $eid = (int)$row['exercise_id'];
    $setExercisesMap[$setId][] = [
        'exercise_id' => $eid,
        'exercise_order' => (int)$row['exercise_order'],
        'exercise_name' => (string)$row['exercise_name'],
        'sport_type' => $exerciseTypes[$eid] ?? 'standard',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
    }

    $workoutSetId = intParam($_POST, 'workout_set_id');
    $trainedAt = trim((string)($_POST['trained_at'] ?? ''));
    $location = normalizeTrainingVenueName($_POST['location'] ?? '');
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($workoutSetId <= 0) {
        $errors[] = 'Vyberte sadu.';
    }
    if ($trainedAt === '') {
        $errors[] = 'Zadejte datum tréninku.';
    }

    $stmtSetCheck = $pdo->prepare('SELECT id FROM workout_sets WHERE id = ? AND coach_id = ?');
    $stmtSetCheck->execute([$workoutSetId, $coachId]);
    if (!$stmtSetCheck->fetch(PDO::FETCH_ASSOC)) {
        $errors[] = 'Vybraná sada nepatří pod váš profil.';
    }

    $exerciseIds = array_map('intval', (array)($_POST['exercise_id'] ?? []));
    if (empty($exerciseIds)) {
        $errors[] = 'Vybraná sada neobsahuje žádné cviky.';
    }

    if (empty($errors)) {
        if ($location !== '') {
            rememberTrainingVenue($location, $coachId);
        }

        $timestamp = strtotime($trainedAt . ' 10:00:00');
        if ($timestamp === false) {
            $errors[] = 'Neplatné datum tréninku.';
        } else {
            $startedAt = date('Y-m-d H:i:s', $timestamp);

            try {
                $pdo->beginTransaction();

                $insSession = $pdo->prepare(
                    'INSERT INTO training_sessions (athlete_id, workout_set_id, location, notes, started_at, completed_at)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $insSession->execute([
                    $athleteId,
                    $workoutSetId,
                    $location ?: null,
                    $notes ?: null,
                    $startedAt,
                    $startedAt,
                ]);
                $sessionId = (int)$pdo->lastInsertId();

                $snapshotStmt = $pdo->prepare(
                    'INSERT INTO training_session_exercises (session_id, exercise_id, exercise_order, exercise_name, sport_type, is_timed)
                     SELECT ?, wse.exercise_id, wse.exercise_order, e.name, e.sport_type, e.is_timed
                     FROM workout_set_exercises wse
                     JOIN exercises e ON e.id = wse.exercise_id
                     WHERE wse.workout_set_id = ?
                     ORDER BY wse.exercise_order ASC'
                );
                $snapshotStmt->execute([$sessionId, $workoutSetId]);

                // Zjistit sport_type z prvního cviku v sadě
                $stmtSportType = $pdo->prepare(
                    'SELECT e.sport_type FROM workout_set_exercises wse
                     JOIN exercises e ON e.id = wse.exercise_id
                     WHERE wse.workout_set_id = ?
                     ORDER BY wse.exercise_order ASC LIMIT 1'
                );
                $stmtSportType->execute([$workoutSetId]);
                $sportTypeRow = $stmtSportType->fetch();
                $sportType = $sportTypeRow ? ($sportTypeRow['sport_type'] ?? 'standard') : 'standard';
                if ($sportType === 'golf') {
                    $sportType = 'standard';
                }

                // Zpracovat speciální data podle sport_type
                if ($sportType === 'golf') {
                    createGolfSession($sessionId);
                    $golfSession = getGolfSessionByTrainingSession($sessionId);
                    
                    $courseId = intParam($_POST, 'golf_course_id', 0);
                    $teeId = intParam($_POST, 'golf_tee_id', 0);
                    $numHoles = intParam($_POST, 'golf_num_holes', 18);
                    $gameType = (string)($_POST['golf_game_type'] ?? 'training');
                    $handicapBefore = $_POST['golf_handicap_before'] !== '' ? (float)$_POST['golf_handicap_before'] : null;
                    $courseRating = $_POST['golf_course_rating'] !== '' ? (float)$_POST['golf_course_rating'] : null;
                    $slopeRating = $_POST['golf_slope_rating'] !== '' ? (int)$_POST['golf_slope_rating'] : null;
                    
                    $selectedTee = $teeId > 0 ? getGolfCourseTeeById($teeId) : null;
                    if ($selectedTee) {
                        $courseId = (int)$selectedTee['course_id'];
                        $courseRating = (float)$selectedTee['course_rating'];
                        $slopeRating = (int)$selectedTee['slope_rating'];
                    }
                    
                    $allowedGameTypes = ['training', 'tournament', 'friendly'];
                    if (!in_array($gameType, $allowedGameTypes, true)) {
                        $gameType = 'training';
                    }
                    
                    updateGolfSession(
                        (int)$golfSession['id'],
                        'Nezadano',
                        $numHoles,
                        $gameType,
                        null,
                        null,
                        null,
                        null,
                        null,
                        null,
                        null,
                        $courseId > 0 ? $courseId : null,
                        $teeId > 0 ? $teeId : null,
                        null
                    );
                    
                    $holeNumbers = $_POST['golf_hole_number'] ?? [];
                    $holePars = $_POST['golf_hole_par'] ?? [];
                    $holeScores = $_POST['golf_hole_score'] ?? [];
                    
                    $holes = [];
                    $rows = max(count($holeNumbers), count($holePars), count($holeScores));
                    for ($i = 0; $i < $rows; $i++) {
                        $holeNumber = (int)($holeNumbers[$i] ?? 0);
                        $par = (int)($holePars[$i] ?? 0);
                        $scoreRaw = trim((string)($holeScores[$i] ?? ''));
                        $score = $scoreRaw === '' ? null : (int)$scoreRaw;
                        
                        if ($holeNumber <= 0 || $par <= 0) {
                            continue;
                        }
                        
                        $holes[] = [
                            'hole_number' => $holeNumber,
                            'par' => $par,
                            'score' => $score,
                            'notes' => null,
                        ];
                    }
                    
                    saveGolfHoles((int)$golfSession['id'], $holes);
                    
                } elseif ($sportType === 'run_outdoor') {
                    createRunOutdoorSession($sessionId);
                    $runSession = getRunOutdoorSessionByTrainingSession($sessionId);
                    
                    $durationMinutes = intParam($_POST, 'run_duration_minutes', 0);
                    $durationSeconds = intParam($_POST, 'run_duration_seconds', 0);
                    $paceMinutes = intParam($_POST, 'run_pace_minutes', 0);
                    $paceSeconds = intParam($_POST, 'run_pace_seconds', 0);
                    $surface = (string)($_POST['run_outdoor_surface'] ?? 'asphalt');
                    $weather = trim((string)($_POST['run_outdoor_weather'] ?? ''));
                    $caloriesBurned = $_POST['run_calories_burned'] !== '' ? (int)$_POST['run_calories_burned'] : null;
                    
                    $allowedSurfaces = ['asphalt', 'trail', 'mixed'];
                    if (!in_array($surface, $allowedSurfaces, true)) {
                        $surface = 'asphalt';
                    }
                    
                    $durationTotalSeconds = $durationMinutes * 60 + $durationSeconds;
                    $paceTotalSeconds = $paceMinutes * 60 + $paceSeconds;
                    $distanceKm = $paceTotalSeconds > 0 ? round($durationTotalSeconds / $paceTotalSeconds, 2) : 0;
                    updateRunOutdoorSession(
                        (int)$runSession['id'],
                        $durationTotalSeconds,
                        $distanceKm,
                        'free',
                        $surface,
                        $weather !== '' ? $weather : null,
                        null,
                        $caloriesBurned,
                        null,
                        null,
                        null,
                        null
                    );

                    $splitKm = $_POST['run_split_km'] ?? [];
                    $splitTime = $_POST['run_split_time'] ?? [];
                    $splitPace = $_POST['run_split_pace'] ?? [];
                    $splits = [];
                    $rows = max(count($splitKm), count($splitTime));
                    for ($i = 0; $i < $rows; $i++) {
                        $km = isset($splitKm[$i]) && $splitKm[$i] !== '' ? (float)$splitKm[$i] : 0;
                        $time = trim((string)($splitTime[$i] ?? ''));
                        $pace = trim((string)($splitPace[$i] ?? ''));

                        if ($km <= 0 || $time === '') {
                            continue;
                        }
                        if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
                            continue;
                        }
                        if ($pace !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $pace)) {
                            $pace = null;
                        }

                        $splits[] = [
                            'km_marker' => $km,
                            'split_time' => $time,
                            'pace' => $pace ?: null,
                        ];
                    }

                    saveRunOutdoorSplits((int)$runSession['id'], $splits);
                    
                } elseif ($sportType === 'run_treadmill') {
                    $durationMinutes = intParam($_POST, 'treadmill_duration_minutes', 0);
                    $durationSeconds = intParam($_POST, 'treadmill_duration_seconds', 0);
                    $paceMinutes = intParam($_POST, 'treadmill_pace_minutes', 0);
                    $paceSeconds = intParam($_POST, 'treadmill_pace_seconds', 0);
                    $caloriesBurned = $_POST['treadmill_calories_burned'] !== '' ? (int)$_POST['treadmill_calories_burned'] : null;
                    
                    $durationTotalSeconds = $durationMinutes * 60 + $durationSeconds;
                    $paceTotalSeconds = $paceMinutes * 60 + $paceSeconds;
                    
                    if ($paceTotalSeconds > 0) {
                        $distanceKm = $durationTotalSeconds / $paceTotalSeconds;
                    } else {
                        $distanceKm = 0;
                    }
                    
                    createRunTreadmillSession($sessionId, $durationTotalSeconds, $distanceKm);
                    $runSession = getRunTreadmillSessionByTrainingSession($sessionId);
                    if ($runSession) {
                        updateRunTreadmillSession(
                            (int)$runSession['id'],
                            $durationTotalSeconds,
                            $distanceKm,
                            $caloriesBurned,
                            $location !== '' ? $location : null,
                            null
                        );

                        $splitKm = $_POST['treadmill_split_km'] ?? [];
                        $splitTime = $_POST['treadmill_split_time'] ?? [];
                        $splitPace = $_POST['treadmill_split_pace'] ?? [];
                        $splits = [];
                        $rows = max(count($splitKm), count($splitTime));
                        for ($i = 0; $i < $rows; $i++) {
                            $km = isset($splitKm[$i]) && $splitKm[$i] !== '' ? (float)$splitKm[$i] : 0;
                            $time = trim((string)($splitTime[$i] ?? ''));
                            $pace = trim((string)($splitPace[$i] ?? ''));

                            if ($km <= 0 || $time === '') {
                                continue;
                            }
                            if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
                                continue;
                            }
                            if ($pace !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $pace)) {
                                $pace = null;
                            }

                            $splits[] = [
                                'km_marker' => $km,
                                'split_time' => $time,
                                'pace' => $pace ?: null,
                            ];
                        }

                        saveRunTreadmillSplits((int)$runSession['id'], $splits);
                    }
                }

                // Zpracovat fotografie
                try {
                    if (!empty($_FILES['training_photos']['name'][0])) {
                        $savedPhotos = saveTrainingPhotosFromInput('training_photos');
                        if (!empty($savedPhotos)) {
                            addTrainingSessionPhotos($sessionId, $savedPhotos);
                        }
                    }
                } catch (Throwable $photoError) {
                    error_log('Photo upload error: ' . $photoError->getMessage());
                    // Pokud se fotky nepodaří nahrát, nechť se session i tak uloží
                }

                $insSeries = $pdo->prepare(
                    'INSERT INTO session_series (session_id, exercise_id, series_order, weight, reps, assistance_reps)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                $createdSeries = 0;
                foreach ($exerciseIds as $exerciseId) {
                    $weights = $_POST['weight'][$exerciseId] ?? [];
                    $repsArr = $_POST['reps'][$exerciseId] ?? [];
                    $assistArr = $_POST['assist'][$exerciseId] ?? [];

                    $count = max(count($weights), count($repsArr), count($assistArr));
                    for ($i = 0; $i < $count; $i++) {
                        $weight = (float)str_replace(',', '.', (string)($weights[$i] ?? '0'));
                        $reps = (int)($repsArr[$i] ?? 0);
                        $assist = (int)($assistArr[$i] ?? 0);

                        if ($weight < 0 || $reps < 0 || $assist < 0) {
                            continue;
                        }
                        if ($weight == 0.0 && $reps === 0 && $assist === 0) {
                            continue;
                        }

                        $insSeries->execute([
                            $sessionId,
                            $exerciseId,
                            $i + 1,
                            $weight,
                            $reps,
                            $assist,
                        ]);
                        $createdSeries++;
                    }
                }

                $pdo->commit();
                flash('success', 'Minulý trénink byl uložen (' . $createdSeries . ' sérií).');
                redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Uložení tréninku selhalo: ' . $e->getMessage();
            }
        }
    }
}

renderHeader('Přidat minulý trénink');
?>

<div class="d-flex align-items-center mb-4 gap-3 flex-wrap page-header">
    <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$athleteId ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Zpět
    </a>
    <h2 class="mb-0 fw-bold">
        <i class="fas fa-calendar-plus me-2 text-warning"></i>Přidat minulý trénink
    </h2>
</div>

<div class="alert alert-light border">
    <strong>Sportovec:</strong> <?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= h($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (empty($workoutSets)): ?>
<div class="alert alert-warning">
    Nemáte žádné sady. Nejdříve vytvořte sadu v sekci
    <a href="<?= BASE_URL ?>/sady.php" class="alert-link">Sady</a>.
</div>
<?php else: ?>
<form method="post" id="manualTrainingForm">
    <?= csrfField() ?>
    <input type="hidden" name="athlete_id" value="<?= (int)$athleteId ?>">

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white fw-semibold">
            <i class="fas fa-layer-group me-2"></i>Sada a datum
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Sada</label>
                    <select class="form-select" name="workout_set_id" id="setSelect" required>
                        <option value="">— Vyberte sadu —</option>
                        <?php foreach ($workoutSets as $ws): ?>
                        <option value="<?= (int)$ws['id'] ?>"><?= h($ws['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Datum tréninku</label>
                    <input type="date" class="form-control" name="trained_at" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Místo <span class="text-muted fw-normal">(nepovinné)</span></label>
                    <input type="text" class="form-control" name="location" list="training-venues" maxlength="300"
                           placeholder="Vyberte místo nebo napište nové...">
                    <datalist id="training-venues">
                        <?php foreach ($trainingVenues as $venue): ?>
                        <option value="<?= h((string)$venue['name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Poznámka <span class="text-muted fw-normal">(nepovinné)</span></label>
                    <textarea class="form-control" name="notes" rows="2" maxlength="2000" placeholder="Volitelná poznámka..."></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Fotografie <span class="text-muted fw-normal">(nepovinné)</span></label>
                    <input type="file" class="form-control" id="trainingPhotosInput" name="training_photos[]" multiple accept="image/*">
                    <small class="d-block mt-1 text-muted">Můžete nahrát více fotek. Maximální velikost souboru: 8 MB.</small>
                </div>
                <div class="col-12">
                    <div id="trainingPhotoPreviews"></div>
                </div>
            </div>
        </div>
    </div>

    <div id="seriesSection" style="display:none"></div>

    <div id="submitRow" style="display:none" class="mb-4">
        <button type="submit" class="btn btn-warning fw-bold px-4">
            <i class="fas fa-save me-2"></i>Uložit minulý trénink
        </button>
    </div>
</form>
<?php endif; ?>

<script>
const setExercisesMap = <?= json_encode($setExercisesMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const golfCoursesData = <?= json_encode($golfCourses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const teesDataByCourse = <?= json_encode($teesByCourse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function escHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function addSeriesRow(exerciseId) {
    const body = document.getElementById('tbody-' + exerciseId);
    if (!body) return;

    const next = body.querySelectorAll('tr').length + 1;
    const row = document.createElement('tr');
    row.innerHTML = `
        <td class="text-center">${next}</td>
        <td><input type="number" class="form-control form-control-sm" name="weight[${exerciseId}][]" step="0.01" min="0" placeholder="0"></td>
        <td><input type="number" class="form-control form-control-sm" name="reps[${exerciseId}][]" min="0" placeholder="0"></td>
        <td><input type="number" class="form-control form-control-sm" name="assist[${exerciseId}][]" min="0" placeholder="0"></td>
        <td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSeriesRow(this)"><i class="fas fa-times"></i></button></td>
    `;
    body.appendChild(row);
}

function removeSeriesRow(button) {
    const row = button.closest('tr');
    const body = row ? row.parentElement : null;
    if (!row || !body) return;

    row.remove();
    Array.from(body.querySelectorAll('tr')).forEach((tr, index) => {
        const firstCell = tr.querySelector('td');
        if (firstCell) firstCell.textContent = index + 1;
    });
}

function renderSetExercises(setId) {
    const section = document.getElementById('seriesSection');
    const submitRow = document.getElementById('submitRow');
    const exercises = setExercisesMap[String(setId)] || [];

    if (!exercises.length) {
        section.innerHTML = '<div class="alert alert-warning">Vybraná sada neobsahuje žádné cviky.</div>';
        section.style.display = '';
        submitRow.style.display = 'none';
        return;
    }

    let html = '';
    exercises.forEach((ex) => {
        html += `<div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-dark text-white d-flex align-items-center">
                <span class="badge bg-warning text-dark me-2">${ex.exercise_order}</span>
                <span class="fw-bold">${escHtml(ex.exercise_name)}</span>
                <input type="hidden" name="exercise_id[]" value="${ex.exercise_id}">
            </div>
            <div class="card-body p-0">`;
        if (ex.sport_type === 'golf') {
            html += renderGolfForm();
        } else if (ex.sport_type === 'run_treadmill') {
            html += renderTreadmillForm();
        } else if (ex.sport_type === 'run_outdoor') {
            html += renderRunOutdoorForm();
        } else {
            html += renderStandardExerciseForm(ex.exercise_id);
        }
        html += `</div></div>`;
    });

    section.innerHTML = html;
    section.style.display = '';
    submitRow.style.display = '';

    exercises.forEach((ex) => {
        if (ex.sport_type === 'standard') {
            for (let i = 0; i < 4; i++) {
                addSeriesRow(ex.exercise_id);
            }
        } else if (ex.sport_type === 'golf') {
            setupGolfCourseSelect();
            for (let i = 0; i < 18; i++) {
                addGolfHole();
            }
        }
    });
}

function renderGolfForm() {
    let html = `<div class="p-3">
        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label class="form-label form-label-sm fw-semibold">Hřiště</label>
                <select name="golf_course_id" class="form-select form-select-sm">
                    <option value="">-- Vyberte hřiště --</option>`;
                    golfCoursesData.forEach(course => {
                        html += `<option value="${course.id}">${escHtml(course.name)}</option>`;
                    });
                html += `</select>
            </div>
            <div class="col-md-6">
                <label class="form-label form-label-sm fw-semibold">Odpaliště</label>
                <select name="golf_tee_id" class="form-select form-select-sm">
                    <option value="">-- Vyberte odpaliště --</option>
                </select>
            </div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Počet jamek</label>
                <input type="number" name="golf_num_holes" class="form-control form-control-sm" value="18" min="1" max="36">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Typ hry</label>
                <select name="golf_game_type" class="form-select form-select-sm">
                    <option value="training">Trénink</option>
                    <option value="tournament">Závod</option>
                    <option value="friendly">Přátelský</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">HCP</label>
                <input type="number" name="golf_handicap_before" class="form-control form-control-sm" placeholder="0" step="0.1">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Course Rating</label>
                <input type="number" name="golf_course_rating" class="form-control form-control-sm" placeholder="72" step="0.1">
            </div>
        </div>
        <input type="hidden" name="golf_slope_rating" value="113">
        <div class="mb-3">
            <label class="form-label form-label-sm fw-semibold">Jamky – Par a skóre</label>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr><th style="width:40px">Jamka</th><th style="width:50px">Par</th><th style="width:50px">Skóre</th></tr>
                    </thead>
                    <tbody id="golf-holes-body"></tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="p-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addGolfHole()">
            <i class="fas fa-plus me-1"></i>Přidat jamku
        </button>
    </div>`;
    return html;
}

function renderTreadmillForm() {
    return `<div class="p-3">
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Doba min</label>
                <input type="number" name="treadmill_duration_minutes" class="form-control form-control-sm" min="0" placeholder="0">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Doba sek</label>
                <input type="number" name="treadmill_duration_seconds" class="form-control form-control-sm" min="0" max="59" placeholder="0">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Tempo min/km</label>
                <input type="number" name="treadmill_pace_minutes" class="form-control form-control-sm" min="0" placeholder="0">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Tempo sek</label>
                <input type="number" name="treadmill_pace_seconds" class="form-control form-control-sm" min="0" max="59" placeholder="0">
            </div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Kalorie</label>
                <input type="number" name="treadmill_calories_burned" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-md-8">
                <div class="run-inline-note mt-4">Vzdálenost se dopočítá z času a průměrného tempa.</div>
            </div>
        </div>

        <div class="special-splits">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label form-label-sm fw-semibold mb-0">Splity (volitelně)</label>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addSpecialSplitRow(this, 'treadmill')">
                    <i class="fas fa-plus me-1"></i>Přidat mezičas
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Km</th>
                            <th>Čas</th>
                            <th>Tempo</th>
                            <th style="width:50px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><input type="number" step="0.01" min="0" name="treadmill_split_km[]" class="form-control form-control-sm"></td>
                            <td><input type="text" name="treadmill_split_time[]" class="form-control form-control-sm" placeholder="05:15"></td>
                            <td><input type="text" name="treadmill_split_pace[]" class="form-control form-control-sm" placeholder="05:15"></td>
                            <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSpecialSplitRow(this)"><i class="fas fa-times"></i></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>`;
}

function renderRunOutdoorForm() {
    return `<div class="p-3">
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Doba min</label>
                <input type="number" name="run_duration_minutes" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Doba sek</label>
                <input type="number" name="run_duration_seconds" class="form-control form-control-sm" min="0" max="59">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Tempo min/km</label>
                <input type="number" name="run_pace_minutes" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm fw-semibold">Tempo sek</label>
                <input type="number" name="run_pace_seconds" class="form-control form-control-sm" min="0" max="59">
            </div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Povrch</label>
                <select name="run_outdoor_surface" class="form-select form-select-sm">
                    <option value="asphalt">Asfalt</option>
                    <option value="trail">Terén</option>
                    <option value="mixed">Mix</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Kalorie</label>
                <input type="number" name="run_calories_burned" class="form-control form-control-sm" min="0">
            </div>
            <div class="col-md-4">
                <label class="form-label form-label-sm fw-semibold">Počasí</label>
                <input type="text" name="run_outdoor_weather" class="form-control form-control-sm" placeholder="slunečno, déšť...">
            </div>
        </div>

        <div class="special-splits">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label form-label-sm fw-semibold mb-0">Splity (volitelně)</label>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addSpecialSplitRow(this, 'run')">
                    <i class="fas fa-plus me-1"></i>Přidat mezičas
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Km</th>
                            <th>Čas</th>
                            <th>Tempo</th>
                            <th style="width:50px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><input type="number" step="0.01" min="0" name="run_split_km[]" class="form-control form-control-sm"></td>
                            <td><input type="text" name="run_split_time[]" class="form-control form-control-sm" placeholder="05:15"></td>
                            <td><input type="text" name="run_split_pace[]" class="form-control form-control-sm" placeholder="05:15"></td>
                            <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSpecialSplitRow(this)"><i class="fas fa-times"></i></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>`;
}

function renderStandardExerciseForm(exerciseId) {
    return `<div class="table-responsive">
        <table class="table table-bordered mb-0 align-middle text-center">
            <thead class="table-light">
                <tr>
                    <th style="width:50px">#</th>
                    <th>Váha (kg)</th>
                    <th>Opakování</th>
                    <th>Dopomoc</th>
                    <th style="width:50px"></th>
                </tr>
            </thead>
            <tbody id="tbody-${exerciseId}"></tbody>
        </table>
    </div>
    <div class="p-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addSeriesRow(${exerciseId})">
            <i class="fas fa-plus me-1"></i>Přidat sérii
        </button>
    </div>`;
}

function addGolfHole() {
    const body = document.getElementById('golf-holes-body');
    if (!body) return;
    
    const next = body.querySelectorAll('tr').length + 1;
    const row = document.createElement('tr');
    row.innerHTML = `
        <td class="text-center">${next}</td>
        <input type="hidden" name="golf_hole_number[]" value="${next}">
        <td><input type="number" class="form-control form-control-sm" name="golf_hole_par[]" min="1" placeholder="3" style="width:100%"></td>
        <td><input type="number" class="form-control form-control-sm" name="golf_hole_score[]" placeholder="–" style="width:100%"></td>
    `;
    body.appendChild(row);
}

function addSpecialSplitRow(button, prefix) {
    const container = button.closest('.special-splits');
    if (!container) return;

    const tbody = container.querySelector('tbody');
    if (!tbody) return;

    const row = document.createElement('tr');
    row.innerHTML = `
        <td><input type="number" step="0.01" min="0" name="${prefix}_split_km[]" class="form-control form-control-sm"></td>
        <td><input type="text" name="${prefix}_split_time[]" class="form-control form-control-sm" placeholder="05:15"></td>
        <td><input type="text" name="${prefix}_split_pace[]" class="form-control form-control-sm" placeholder="05:15"></td>
        <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSpecialSplitRow(this)"><i class="fas fa-times"></i></button></td>
    `;
    tbody.appendChild(row);
}

function removeSpecialSplitRow(button) {
    const row = button.closest('tr');
    const tbody = row?.parentElement;
    if (!row || !tbody) return;

    if (tbody.querySelectorAll('tr').length > 1) {
        row.remove();
    }
}

function setupGolfCourseSelect() {
    const courseSelect = document.querySelector('select[name="golf_course_id"]');
    const teeSelect = document.querySelector('select[name="golf_tee_id"]');
    if (!courseSelect || !teeSelect) return;
    
    courseSelect.addEventListener('change', function() {
        const courseId = this.value;
        teeSelect.innerHTML = '<option value="">-- Vyberte odpaliště --</option>';
        if (courseId && teesDataByCourse[courseId]) {
            teesDataByCourse[courseId].forEach(tee => {
                const opt = document.createElement('option');
                opt.value = tee.id;
                opt.textContent = escHtml(tee.tee_name) + ' (' + escHtml(tee.gender) + ')';
                teeSelect.appendChild(opt);
            });
        }
    });
}

// ── Fotografie ─────────────────────────────────────────────────
let trainingPhotosCollector = new DataTransfer();

document.getElementById('trainingPhotosInput')?.addEventListener('change', function(e) {
    const files = e.target.files;
    if (files.length > 0) {
        collectTrainingPhotos(files);
        renderTrainingPhotoPreviews();
    }
});

function collectTrainingPhotos(fileList) {
    for (let i = 0; i < fileList.length; i++) {
        trainingPhotosCollector.items.add(fileList[i]);
    }
    document.getElementById('trainingPhotosInput').files = trainingPhotosCollector.files;
}

function renderTrainingPhotoPreviews() {
    const container = document.getElementById('trainingPhotoPreviews');
    if (!container) return;
    
    const files = trainingPhotosCollector.files;
    if (files.length === 0) {
        container.innerHTML = '';
        return;
    }
    
    let html = '<div class="row g-2 mt-2">';
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const reader = new FileReader();
        reader.onload = function(e) {
            const thumbnail = document.getElementById('photo-thumb-' + i);
            if (thumbnail) {
                thumbnail.style.backgroundImage = 'url(' + e.target.result + ')';
            }
        };
        reader.readAsDataURL(file);
        
        html += `<div class="col-6 col-sm-4 col-md-3 col-lg-2">
            <div class="position-relative" style="padding-bottom:100%;overflow:hidden;border:1px solid #dee2e6;border-radius:6px;">
                <div id="photo-thumb-${i}" style="position:absolute;top:0;left:0;width:100%;height:100%;background-size:cover;background-position:center;"></div>
                <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1" onclick="removeTrainingPhoto(${i})" style="font-size:.75rem;padding:.25rem .5rem;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>`;
    }
    html += '</div>';
    container.innerHTML = html;
}

function removeTrainingPhoto(index) {
    const newCollector = new DataTransfer();
    const files = trainingPhotosCollector.files;
    let j = 0;
    for (let i = 0; i < files.length; i++) {
        if (i !== index) {
            newCollector.items.add(files[i]);
        }
    }
    trainingPhotosCollector = newCollector;
    document.getElementById('trainingPhotosInput').files = trainingPhotosCollector.files;
    renderTrainingPhotoPreviews();
}


document.getElementById('setSelect')?.addEventListener('change', function () {
    if (!this.value) {
        const section = document.getElementById('seriesSection');
        const submitRow = document.getElementById('submitRow');
        section.style.display = 'none';
        section.innerHTML = '';
        submitRow.style.display = 'none';
        return;
    }

    renderSetExercises(this.value);
});
</script>

<?php renderFooter(); ?>
