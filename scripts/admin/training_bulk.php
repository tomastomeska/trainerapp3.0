<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo    = getDB();
$errors = [];
$parsedRows = [];

function parseCsvDate(string $raw): ?string {
    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd.m.Y', 'd.m.y', 'd/m/Y', 'd/m/y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d', $ts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/training_bulk.php');
    }

    $action   = $_POST['action'] ?? '';
    $coachId  = intParam($_POST, 'coach_id');
    $athleteId = intParam($_POST, 'athlete_id');
    $workoutSetId = intParam($_POST, 'workout_set_id');

    if ($action === 'export_template') {
        if ($coachId <= 0) {
            flash('danger', 'Vyberte trenéra.');
            redirect(BASE_URL . '/admin/training_bulk.php');
        }

        $stmtCoach = $pdo->prepare('SELECT id, name, username FROM coaches WHERE id = ? LIMIT 1');
        $stmtCoach->execute([$coachId]);
        $coach = $stmtCoach->fetch(PDO::FETCH_ASSOC);

        if (!$coach) {
            flash('danger', 'Vybraný trenér nebyl nalezen.');
            redirect(BASE_URL . '/admin/training_bulk.php');
        }

        $stmtAthletes = $pdo->prepare(
            'SELECT a.id, a.first_name, a.last_name
             FROM athletes a
             WHERE a.coach_id = ?
             ORDER BY a.last_name, a.first_name'
        );
        $stmtAthletes->execute([$coachId]);
        $athletes = $stmtAthletes->fetchAll(PDO::FETCH_ASSOC);

        if (empty($athletes)) {
            flash('warning', 'Vybraný trenér nemá žádné sportovce.');
            redirect(BASE_URL . '/admin/training_bulk.php');
        }

        $stmt = $pdo->prepare(
            'SELECT ws.id AS workout_set_id,
                    ws.name AS workout_set_name,
                    wse.exercise_id,
                    wse.exercise_order,
                    e.name AS exercise_name
             FROM workout_sets ws
             JOIN workout_set_exercises wse ON wse.workout_set_id = ws.id
             JOIN exercises e ON e.id = wse.exercise_id
             WHERE ws.coach_id = ?
             ORDER BY ws.name, wse.exercise_order'
        );
        $stmt->execute([$coachId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            flash('warning', 'Vybraný trenér nemá žádné sady s cviky.');
            redirect(BASE_URL . '/admin/training_bulk.php');
        }

        $coachLabel = trim((string)($coach['name'] ?: $coach['username']));
        $today = date('Y-m-d');
        $filename = 'treningy_template_' .
            preg_replace('/\s+/', '_', $coachLabel) .
            '_' . $today . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'datum_treninku',
            'misto',
            'sportovec_id',
            'sportovec_jmeno',
            'sada_id',
            'sada_nazev',
            'cvik_id',
            'cvik_nazev',
            'serie',
            'vaha',
            'opakovani',
            'dopomoc',
            'poznamka',
        ], ';');

        foreach ($athletes as $a) {
            $athleteName = trim($a['first_name'] . ' ' . $a['last_name']);
            foreach ($rows as $r) {
                for ($series = 1; $series <= 4; $series++) {
                    fputcsv($out, [
                        $today,
                        '',
                        (int)$a['id'],
                        $athleteName,
                        $r['workout_set_id'],
                        $r['workout_set_name'],
                        $r['exercise_id'],
                        $r['exercise_name'],
                        $series,
                        '',
                        '',
                        '',
                        '',
                    ], ';');
                }
            }
        }

        fclose($out);
        exit;
    }

    if ($action === 'import_csv') {
        if ($coachId <= 0) {
            $errors[] = 'Vyberte trenéra.';
        }

        if ($athleteId > 0) {
            $stmtAth = $pdo->prepare(
                'SELECT a.id
                 FROM athletes a
                 WHERE a.id = ? AND a.coach_id = ?'
            );
            $stmtAth->execute([$athleteId, $coachId]);
            if (!$stmtAth->fetch()) {
                $errors[] = 'Vybraný sportovec nepatří pod vybraného trenéra.';
            }
        }

        if (empty($_FILES['csv']['tmp_name']) || (int)($_FILES['csv']['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $errors[] = 'Nahrajte platný CSV soubor.';
        }

        if (empty($errors)) {
            $validAthletesStmt = $pdo->prepare(
                'SELECT id
                 FROM athletes
                 WHERE coach_id = ?'
            );
            $validAthletesStmt->execute([$coachId]);
            $validAthleteRows = $validAthletesStmt->fetchAll(PDO::FETCH_ASSOC);
            $validAthletes = [];
            foreach ($validAthleteRows as $ar) {
                $validAthletes[(int)$ar['id']] = true;
            }

            $validMapStmt = $pdo->prepare(
                'SELECT ws.id AS workout_set_id, wse.exercise_id
                 FROM workout_sets ws
                 JOIN workout_set_exercises wse ON wse.workout_set_id = ws.id
                 WHERE ws.coach_id = ?'
            );
            $validMapStmt->execute([$coachId]);
            $validMapRows = $validMapStmt->fetchAll(PDO::FETCH_ASSOC);

            $validExercisesBySet = [];
            foreach ($validMapRows as $vr) {
                $sid = (int)$vr['workout_set_id'];
                $eid = (int)$vr['exercise_id'];
                $validExercisesBySet[$sid][$eid] = true;
            }

            $handle = fopen($_FILES['csv']['tmp_name'], 'r');
            if (!$handle) {
                $errors[] = 'CSV soubor se nepodařilo otevřít.';
            } else {
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }

                $header = fgetcsv($handle, 0, ';');
                if (!$header) {
                    $errors[] = 'CSV je prázdné.';
                } else {
                    $headerMap = [];
                    foreach ($header as $idx => $name) {
                        $headerMap[strtolower(trim((string)$name))] = $idx;
                    }

                    // Podpora CZ i EN názvů sloupců kvůli kompatibilitě šablon.
                    $colMap = [
                        'date' => ['datum_treninku', 'training_date'],
                        'athlete_id' => ['sportovec_id', 'athlete_id'],
                        'set_id' => ['sada_id', 'workout_set_id'],
                        'exercise_id' => ['cvik_id', 'exercise_id'],
                        'series_order' => ['serie', 'series_order'],
                        'weight' => ['vaha', 'weight'],
                        'reps' => ['opakovani', 'reps'],
                        'assist' => ['dopomoc', 'assistance_reps'],
                        'location' => ['misto', 'location'],
                        'notes' => ['poznamka', 'notes'],
                    ];

                    $findColumn = function (array $aliases) use ($headerMap): ?int {
                        foreach ($aliases as $a) {
                            if (array_key_exists($a, $headerMap)) {
                                return $headerMap[$a];
                            }
                        }
                        return null;
                    };

                    $idx = [];
                    foreach ($colMap as $key => $aliases) {
                        $idx[$key] = $findColumn($aliases);
                    }

                    $requiredLabels = [
                        'date' => 'datum_treninku',
                        'set_id' => 'sada_id',
                        'exercise_id' => 'cvik_id',
                        'series_order' => 'serie',
                        'weight' => 'vaha',
                        'reps' => 'opakovani',
                        'assist' => 'dopomoc',
                    ];
                    foreach ($requiredLabels as $k => $label) {
                        if ($idx[$k] === null) {
                            $errors[] = 'CSV neobsahuje povinný sloupec: ' . $label;
                        }
                    }

                    if ($idx['athlete_id'] === null && $athleteId <= 0) {
                        $errors[] = 'CSV neobsahuje sloupec sportovec_id a zároveň není vybrán sportovec ve formuláři.';
                    }

                    $parsedRows = [];
                    $lineNo = 1;

                    while (($row = fgetcsv($handle, 0, ';')) !== false) {
                        $lineNo++;
                        $lineIsEmpty = true;
                        foreach ($row as $cell) {
                            if (trim((string)$cell) !== '') {
                                $lineIsEmpty = false;
                                break;
                            }
                        }
                        if ($lineIsEmpty) {
                            continue;
                        }

                        $rawDate = trim((string)($row[$idx['date']] ?? ''));
                        $rawAthlete = $idx['athlete_id'] !== null
                            ? trim((string)($row[$idx['athlete_id']] ?? ''))
                            : '';
                        $rawSet  = trim((string)($row[$idx['set_id']] ?? ''));
                        $rawEx   = trim((string)($row[$idx['exercise_id']] ?? ''));

                        $date = parseCsvDate($rawDate);
                        $rowAthleteId = $idx['athlete_id'] !== null ? (int)$rawAthlete : $athleteId;
                        $setId = (int)$rawSet;
                        $exerciseId = (int)$rawEx;
                        $seriesOrder = (int)($row[$idx['series_order']] ?? 0);
                        $weight = (float)str_replace(',', '.', (string)($row[$idx['weight']] ?? '0'));
                        $reps = (int)($row[$idx['reps']] ?? 0);
                        $assist = (int)($row[$idx['assist']] ?? 0);
                        $location = $idx['location'] !== null
                            ? trim((string)($row[$idx['location']] ?? ''))
                            : '';
                        $notes = $idx['notes'] !== null
                            ? trim((string)($row[$idx['notes']] ?? ''))
                            : '';

                        if (!$date) {
                            $errors[] = 'Řádek ' . $lineNo . ': neplatné datum.';
                        }
                        if ($rowAthleteId <= 0 || empty($validAthletes[$rowAthleteId])) {
                            $errors[] = 'Řádek ' . $lineNo . ': neplatný sportovec pro vybraného trenéra.';
                        }
                        if ($setId <= 0 || empty($validExercisesBySet[$setId])) {
                            $errors[] = 'Řádek ' . $lineNo . ': neplatná sada pro vybraného trenéra.';
                        }
                        if ($exerciseId <= 0 || empty($validExercisesBySet[$setId][$exerciseId])) {
                            $errors[] = 'Řádek ' . $lineNo . ': cvik nepatří do uvedené sady.';
                        }
                        if ($seriesOrder <= 0) {
                            $errors[] = 'Řádek ' . $lineNo . ': série musí být >= 1.';
                        }
                        if ($weight < 0 || $reps < 0 || $assist < 0) {
                            $errors[] = 'Řádek ' . $lineNo . ': váha/opakování/dopomoc nesmí být záporné.';
                        }

                        if (count($errors) > 25) {
                            $errors[] = 'Příliš mnoho chyb, import přerušen.';
                            break;
                        }

                        $parsedRows[] = [
                            'date' => $date,
                            'athlete_id' => $rowAthleteId,
                            'set_id' => $setId,
                            'exercise_id' => $exerciseId,
                            'series_order' => $seriesOrder,
                            'weight' => $weight,
                            'reps' => $reps,
                            'assist' => $assist,
                            'location' => $location,
                            'notes' => $notes,
                        ];
                    }

                    if (empty($errors) && empty($parsedRows)) {
                        $errors[] = 'CSV neobsahuje žádná data k importu.';
                    }
                }
                fclose($handle);
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $sessionStmt = $pdo->prepare(
                    'INSERT INTO training_sessions (athlete_id, workout_set_id, location, notes, started_at, completed_at)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $snapshotStmt = $pdo->prepare(
                    'INSERT INTO training_session_exercises (session_id, exercise_id, exercise_order, exercise_name)
                     SELECT ?, wse.exercise_id, wse.exercise_order, e.name
                     FROM workout_set_exercises wse
                     JOIN exercises e ON e.id = wse.exercise_id
                     WHERE wse.workout_set_id = ?
                     ORDER BY wse.exercise_order ASC'
                );
                $seriesStmt = $pdo->prepare(
                    'INSERT INTO session_series (session_id, exercise_id, series_order, weight, reps, assistance_reps)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                $sessionIdsByKey = [];
                $createdSessions = 0;
                $createdSeries = 0;

                foreach ($parsedRows as $r) {
                    $sessionKey = implode('|', [
                        $r['athlete_id'],
                        $r['date'],
                        $r['set_id'],
                        $r['location'],
                        $r['notes'],
                    ]);

                    if (!isset($sessionIdsByKey[$sessionKey])) {
                        $startedAt = $r['date'] . ' 10:00:00';
                        $sessionStmt->execute([
                            $r['athlete_id'],
                            $r['set_id'],
                            $r['location'] !== '' ? $r['location'] : null,
                            $r['notes'] !== '' ? $r['notes'] : null,
                            $startedAt,
                            $startedAt,
                        ]);
                        $sessionIdsByKey[$sessionKey] = (int)$pdo->lastInsertId();
                        $snapshotStmt->execute([$sessionIdsByKey[$sessionKey], $r['set_id']]);
                        $createdSessions++;
                    }

                    $seriesStmt->execute([
                        $sessionIdsByKey[$sessionKey],
                        $r['exercise_id'],
                        $r['series_order'],
                        $r['weight'],
                        $r['reps'],
                        $r['assist'],
                    ]);
                    $createdSeries++;
                }

                $pdo->commit();
                flash('success', 'Import dokončen: ' . $createdSessions . ' tréninků, ' . $createdSeries . ' sérií.');
                redirect(BASE_URL . '/admin/training_bulk.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Import selhal: ' . $e->getMessage();
            }
        }
    }
}

$coaches = $pdo->query('SELECT id, name, username FROM coaches ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

renderAdminHeader('Hromadný import/export tréninků');
?>

<div class="d-flex align-items-center justify-content-between mb-4 gap-3 flex-wrap">
    <div>
        <h2 class="mb-0 fw-bold">
            <i class="fas fa-file-csv me-2" style="color:#a78bfa"></i>Hromadný import/export tréninků
        </h2>
        <div class="text-muted small">Vyber trenéra, stáhni CSV šablonu se všemi jeho sportovci a sadami, doplň výsledky a naimportuj zpět.</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/training_add.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-pen me-1"></i>Ruční zadání tréninku
    </a>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<form method="post" id="bulkForm" class="mb-4" enctype="multipart/form-data">
    <?= csrfField() ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-dark text-white fw-semibold">
            <i class="fas fa-filter me-2" style="color:#a78bfa"></i>Výběr trenéra (sportovec a sada volitelně)
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Trenér</label>
                    <select class="form-select" id="coachSelect" name="coach_id" required>
                        <option value="">— Vyberte trenéra —</option>
                        <?php foreach ($coaches as $c): ?>
                        <option value="<?= (int)$c['id'] ?>">
                            <?= h(($c['name'] ?: $c['username']) . ' (' . $c['username'] . ')') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Sportovec</label>
                    <select class="form-select" id="athleteSelect" name="athlete_id" disabled>
                        <option value="">— Nejdříve vyberte trenéra —</option>
                    </select>
                    <div class="form-text">Volitelné: použije se jen jako fallback pro starší CSV bez sloupce sportovec_id.</div>
                </div>
                <div class="col-md-12">
                    <label class="form-label fw-semibold">Sada</label>
                    <select class="form-select" id="setSelect" name="workout_set_id" disabled>
                        <option value="">— Nejdříve vyberte trenéra —</option>
                    </select>
                    <div class="form-text">Informační výběr: nový export zahrnuje všechny sady trenéra automaticky.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white fw-semibold">
                    <i class="fas fa-download me-2" style="color:#a78bfa"></i>1) Export šablony
                </div>
                <div class="card-body d-flex flex-column">
                    <p class="text-muted small mb-3">
                        Stáhne CSV šablonu pro vybraného trenéra se všemi jeho sportovci a všemi jeho sadami.
                        U každého cviku připraví 4 řádky (série) a předvyplní aktuální datum.
                        Doplň hlavně váhu, opakování a dopomoc.
                    </p>
                    <button type="submit" name="action" value="export_template" class="btn btn-outline-success mt-auto">
                        <i class="fas fa-file-arrow-down me-1"></i>Stáhnout CSV šablonu
                    </button>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white fw-semibold">
                    <i class="fas fa-upload me-2" style="color:#a78bfa"></i>2) Import vyplněného CSV
                </div>
                <div class="card-body d-flex flex-column">
                    <label class="form-label fw-semibold">CSV soubor</label>
                    <input type="file" class="form-control mb-3" name="csv" accept=".csv,text/csv">
                    <div class="text-muted small mb-3">
                        Nahraj CSV vytvořené z této šablony. Jeden řádek = jedna série.
                        Povinné sloupce: datum_treninku, sportovec_id, sada_id, cvik_id, serie, vaha, opakovani, dopomoc.
                        Místo a poznámku můžeš nechat prázdné.
                    </div>
                    <button type="submit" name="action" value="import_csv" class="btn btn-warning fw-bold mt-auto">
                        <i class="fas fa-file-import me-1"></i>Importovat tréninky
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
const BASE_URL = '<?= BASE_URL ?>';
const coachSelect = document.getElementById('coachSelect');
const athleteSelect = document.getElementById('athleteSelect');
const setSelect = document.getElementById('setSelect');

async function loadAthletes(coachId) {
    athleteSelect.innerHTML = '<option value="">Načítám…</option>';
    athleteSelect.disabled = true;

    if (!coachId) {
        athleteSelect.innerHTML = '<option value="">— Nejdříve vyberte trenéra —</option>';
        return;
    }

    const resp = await fetch(BASE_URL + '/admin/training_api.php?action=athletes&coach_id=' + encodeURIComponent(coachId));
    if (!resp.ok) {
        athleteSelect.innerHTML = '<option value="">— Chyba načtení —</option>';
        return;
    }

    const athletes = await resp.json();
    athleteSelect.innerHTML = '<option value="">— Vyberte sportovce —</option>';
    athletes.forEach(a => {
        const option = document.createElement('option');
        option.value = a.id;
        option.textContent = a.first_name + ' ' + a.last_name;
        athleteSelect.appendChild(option);
    });
    athleteSelect.disabled = false;
}

async function loadSets(coachId) {
    setSelect.innerHTML = '<option value="">Načítám…</option>';
    setSelect.disabled = true;

    if (!coachId) {
        setSelect.innerHTML = '<option value="">— Nejdříve vyberte trenéra —</option>';
        return;
    }

    const resp = await fetch(BASE_URL + '/admin/training_api.php?action=sets&coach_id=' + encodeURIComponent(coachId));
    if (!resp.ok) {
        setSelect.innerHTML = '<option value="">— Chyba načtení —</option>';
        return;
    }

    const sets = await resp.json();
    setSelect.innerHTML = '<option value="">— Vyberte sadu —</option>';
    sets.forEach(s => {
        const option = document.createElement('option');
        option.value = s.id;
        option.textContent = s.name;
        setSelect.appendChild(option);
    });
    setSelect.disabled = false;
}

coachSelect.addEventListener('change', () => {
    loadAthletes(coachSelect.value).catch(() => {
        athleteSelect.innerHTML = '<option value="">— Chyba načtení —</option>';
        athleteSelect.disabled = true;
    });
    loadSets(coachSelect.value).catch(() => {
        setSelect.innerHTML = '<option value="">— Chyba načtení —</option>';
        setSelect.disabled = true;
    });
});
</script>

<?php renderAdminFooter(); ?>
