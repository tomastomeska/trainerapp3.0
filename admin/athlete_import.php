<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/athlete_import.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$coaches = $pdo->query('SELECT id, name, username, is_active FROM coaches ORDER BY is_active DESC, name ASC, username ASC')->fetchAll();
$coachId = intParam($_POST, 'coach_id', intParam($_GET, 'coach_id'));
$coach = null;
$errors = [];
$preview = [];
$confirmationToken = '';
$action = (string)($_POST['action'] ?? '');

if (isset($_SESSION['athlete_import']) && (int)$_SESSION['athlete_import']['expires_at'] < time()) {
    unset($_SESSION['athlete_import']);
}

foreach ($coaches as $candidate) {
    if ((int)$candidate['id'] === $coachId) {
        $coach = $candidate;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Neplatný bezpečnostní token. Obnovte stránku a zkuste to znovu.';
    } elseif ($action === 'confirm') {
        $pending = $_SESSION['athlete_import'] ?? null;
        if (!$pending || (int)$pending['admin_id'] !== (int)$_SESSION['superadmin_id']
            || !hash_equals($pending['token'], (string)($_POST['confirmation_token'] ?? ''))) {
            $errors[] = 'Náhled importu vypršel nebo byl nahrazen jiným souborem. Nahrajte soubor znovu.';
        } else {
            $coachId = (int)$pending['coach_id'];
            try {
                $result = athleteImportSave($pdo, $coachId, $pending['rows']);
                unset($_SESSION['athlete_import']);
                flash('success', 'Import dokončen. Přidáno sportovců: ' . $result['inserted'] . ', přeskočeno duplicit: ' . $result['skipped'] . '. Existující údaje nebyly změněny. Přihlašovací přístupy nebyly aktivovány.');
                redirect(BASE_URL . '/admin/coach_athletes.php?coach_id=' . $coachId);
            } catch (Throwable $e) {
                error_log('athlete_import save failed (coach ' . $coachId . '): ' . $e->getMessage());
                $errors[] = 'Import se nepodařilo uložit. Žádný sportovec ani složka nebyli přidáni. Zkontrolujte dostupnost databáze a její schéma; podrobnosti jsou v chybovém logu.';
                $preview = $pending['rows'];
                $confirmationToken = $pending['token'];
                $coach = null;
                foreach ($coaches as $candidate) {
                    if ((int)$candidate['id'] === $coachId) {
                        $coach = $candidate;
                        break;
                    }
                }
            }
        }
    } elseif (!in_array($action, ['template', 'preview'], true)) {
        $errors[] = 'Neplatná akce importu.';
    } elseif (!$coach) {
        $errors[] = 'Vyberte platného trenéra.';
    } elseif ($action === 'template') {
        $path = tempnam(sys_get_temp_dir(), 'athlete_template_');
        if ($path === false) {
            error_log('athlete_import: cannot create template temporary file');
            $errors[] = 'Nelze vytvořit dočasný soubor pro šablonu. Kontaktujte správce serveru.';
        } else {
            try {
                athleteImportWriteTemplate($path, $coachId, (string)($coach['name'] ?: $coach['username']));
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="sportovci_trener_' . $coachId . '.xlsx"');
                header('Content-Length: ' . filesize($path));
                header('Cache-Control: private, no-store');
                if (readfile($path) === false) {
                    error_log('athlete_import: failed to stream template');
                }
            } catch (Throwable $e) {
                error_log('athlete_import template failed: ' . $e->getMessage());
                $errors[] = 'Šablonu se nepodařilo vytvořit. Ověřte, že server má zapnutá PHP rozšíření zip, dom a mbstring a zapisovatelný dočasný adresář.';
            } finally {
                unlink($path);
            }
            if (!$errors) {
                exit;
            }
        }
    } else {
        unset($_SESSION['athlete_import']);
        $file = $_FILES['workbook'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'Soubor se nepodařilo nahrát. Vyberte soubor .xlsx do 5 MB; ověřte také limit nahrávání na serveru.';
        } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            $errors[] = 'Vyberte excelový soubor .xlsx vytvořený z této šablony (nikoli .xls nebo CSV).';
        } else {
            try {
                $parsed = athleteImportReadWorkbook($file['tmp_name'], $coachId);
                $errors = $parsed['errors'];
                $preview = athleteImportPreview($pdo, $coachId, $parsed['rows']);
                if (!$errors) {
                    $confirmationToken = bin2hex(random_bytes(32));
                    $_SESSION['athlete_import'] = [
                        'admin_id' => (int)$_SESSION['superadmin_id'], 'coach_id' => $coachId,
                        'rows' => $preview, 'token' => $confirmationToken, 'expires_at' => time() + 1800,
                    ];
                }
            } catch (RuntimeException $e) {
                if ($e instanceof PDOException) {
                    error_log('athlete_import preview database failed: ' . $e->getMessage());
                    $errors[] = 'Kontrola sportovců v databázi selhala. Import nebyl proveden. Zkuste to znovu nebo kontaktujte správce.';
                } else {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

$duplicates = count(array_filter($preview, static fn(array $row): bool => $row['duplicate']));
renderAdminHeader('Hromadný import sportovců');
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= BASE_URL ?>/admin/athletes.php" class="btn btn-outline-secondary btn-sm" aria-label="Zpět na sportovce"><i class="fas fa-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="fas fa-file-excel me-2 text-success"></i>Hromadný import sportovců</h4>
</div>

<div class="alert alert-info">
    Vyberte trenéra, stáhněte českou excelovou šablonu a předejte mu ji k vyplnění. Poté ji zde nahrajte, zkontrolujte náhled a potvrďte import.
    <strong>Do potvrzení se žádní sportovci nevytvářejí.</strong>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger" role="alert">
    <strong>Import nebyl proveden. Opravte následující chyby a soubor nahrajte znovu:</strong>
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= ATHLETE_IMPORT_MAX_BYTES ?>">
            <div class="mb-3">
                <label for="coach_id" class="form-label fw-semibold">1. Vyberte trenéra</label>
                <select id="coach_id" name="coach_id" class="form-select" required>
                    <option value="">– vyberte trenéra –</option>
                    <?php foreach ($coaches as $item): ?>
                    <option value="<?= (int)$item['id'] ?>" <?= (int)$item['id'] === $coachId ? 'selected' : '' ?>>
                        <?= h((string)($item['name'] ?: $item['username'])) ?> (@<?= h($item['username']) ?>)<?= $item['is_active'] ? '' : ' – neaktivní' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <button type="submit" name="action" value="template" class="btn btn-outline-success">
                    <i class="fas fa-download me-2"></i>2. Stáhnout českou šablonu (.xlsx)
                </button>
                <p class="form-text mb-0">Šablona je určená pouze pro zvoleného trenéra. Povinné: jméno, příjmení a datum narození. Obsahuje také pohlaví, telefon, e-mail, obě sazby a poznámky.</p>
            </div>
            <div class="mb-3">
                <label for="workbook" class="form-label fw-semibold">3. Nahrajte vyplněnou šablonu</label>
                <input type="file" id="workbook" name="workbook" class="form-control" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                <div class="form-text">Maximálně 1000 sportovců, 5 MB. Údaje vyplňujte na listu Sportovci od řádku 6; hlavičku neměňte. Datum: dd.mm.rrrr. Sazby: např. 750 nebo 450,50, bez Kč. Nepoužívejte vzorce.</div>
            </div>
            <button type="submit" name="action" value="preview" class="btn btn-primary"><i class="fas fa-magnifying-glass me-2"></i>Zkontrolovat a zobrazit náhled</button>
        </form>
    </div>
</div>

<div class="alert alert-secondary">
    Duplicity podle jména, příjmení a data narození u stejného trenéra přeskočíme, existující údaje nezměníme.
    Každému novému sportovci vytvoříme složku v galerii a videích.
    <strong>Přihlašovací přístupy se neaktivují a e-maily se neposílají.</strong>
    Při chybě se neuloží nic.
</div>

<?php if ($preview): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h5 class="fw-bold">4. Náhled pro trenéra: <?= h((string)($coach['name'] ?? '') ?: (string)($coach['username'] ?? '')) ?> (ID <?= $coachId ?>)</h5>
        <p>Platné řádky: <strong><?= count($preview) ?></strong>. K přidání: <strong><?= count($preview) - $duplicates ?></strong>. Duplicity k přeskočení: <strong><?= $duplicates ?></strong>.</p>
        <?php if ($errors): ?><p class="text-danger">Zobrazeny jsou pouze platné řádky. Dokud neopravíte všechny chyby, nelze import potvrdit.</p><?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Řádek</th><th>Stav</th><?php foreach (athleteImportHeaders() as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach ($preview as $row): ?>
                    <tr class="<?= $row['duplicate'] ? 'table-warning' : '' ?>">
                        <td><?= (int)$row['row'] ?></td>
                        <td><?= $row['duplicate'] ? 'Přeskočit – duplicita' : 'Přidat' ?></td>
                        <td><?= h($row['first_name']) ?></td><td><?= h($row['last_name']) ?></td>
                        <td><?= h(formatDate($row['birth_date'])) ?></td><td><?= h(athleteImportGenders()[$row['gender']]) ?></td>
                        <td><?= h($row['phone_contact']) ?></td><td><?= h($row['email']) ?></td>
                        <td><?= h($row['training_rate']) ?></td><td><?= h($row['paired_training_rate']) ?></td><td><?= h($row['notes']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($confirmationToken !== '' && count($preview) > $duplicates): ?>
        <form method="post" class="mt-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="confirmation_token" value="<?= h($confirmationToken) ?>">
            <input type="hidden" name="coach_id" value="<?= $coachId ?>">
            <p class="small text-muted">Potvrzení platí 30 minut a týká se pouze tohoto náhledu a výše uvedeného trenéra. Duplicity znovu ověříme při ukládání.</p>
            <button type="submit" class="btn btn-success"><i class="fas fa-users me-2"></i>Potvrdit import pro tohoto trenéra</button>
        </form>
        <?php elseif (!$errors): ?>
        <p class="text-muted mb-0">Všichni sportovci již existují, není co importovat.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php renderAdminFooter(); ?>
