<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$mycoachAccessModeKey = 'mycoach_access_mode';

if (!function_exists('adminMyCoachNormalizeMode')) {
    function adminMyCoachNormalizeMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));
        if (!in_array($normalized, ['disabled', 'all', 'selected'], true)) {
            return 'selected';
        }
        return $normalized;
    }
}

if (!function_exists('adminEnsureMyCoachAccessColumns')) {
    function adminEnsureMyCoachAccessColumns(PDO $pdo): void
    {
        try {
            $coachColumn = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'mycoach_enabled'");
            if ($coachColumn !== false && !$coachColumn->fetch()) {
                $pdo->exec('ALTER TABLE coaches ADD COLUMN mycoach_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER special_training_enabled');
            }
        } catch (Throwable $e) {
            // Ignore schema hardening failure, page will continue in best-effort mode.
        }

        try {
            $athleteColumn = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'mycoach_enabled'");
            if ($athleteColumn !== false && !$athleteColumn->fetch()) {
                $pdo->exec('ALTER TABLE athletes ADD COLUMN mycoach_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER special_training_enabled');
            }
        } catch (Throwable $e) {
            // Ignore schema hardening failure, page will continue in best-effort mode.
        }
    }
}

adminEnsureMyCoachAccessColumns($pdo);

$currentMode = adminMyCoachNormalizeMode((string)getAppSetting($mycoachAccessModeKey, 'selected'));
$selectedCoachFilter = intParam($_GET, 'coach_id');
$search = trim((string)($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_mode') {
        $mode = adminMyCoachNormalizeMode((string)($_POST['mycoach_access_mode'] ?? 'selected'));
        $pdo->prepare(
            'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        )->execute([$mycoachAccessModeKey, $mode]);

        $message = 'MyCoach režim byl uložen.';
        if ($mode === 'disabled') {
            $message = 'MyCoach je nyní globálně vypnutý pro všechny.';
        } elseif ($mode === 'all') {
            $message = 'MyCoach je nyní globálně zapnutý pro všechny.';
        } elseif ($mode === 'selected') {
            $message = 'MyCoach je nyní povolen jen vybraným uživatelům.';
        }

        flash('success', $message);
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'set_coach_access') {
        $coachId = intParam($_POST, 'coach_id');
        $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        if ($coachId > 0) {
            $pdo->prepare('UPDATE coaches SET mycoach_enabled = ? WHERE id = ?')->execute([$enabled, $coachId]);
            flash('success', $enabled === 1 ? 'MyCoach byl trenérovi povolen.' : 'MyCoach byl trenérovi vypnut.');
        }
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'set_athlete_access') {
        $athleteId = intParam($_POST, 'athlete_id');
        $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        if ($athleteId > 0) {
            $pdo->prepare('UPDATE athletes SET mycoach_enabled = ? WHERE id = ?')->execute([$enabled, $athleteId]);
            flash('success', $enabled === 1 ? 'MyCoach byl sportovci povolen.' : 'MyCoach byl sportovci vypnut.');
        }

        $redirectUrl = BASE_URL . '/admin/mycoach.php';
        if ($selectedCoachFilter > 0) {
            $redirectUrl .= '?coach_id=' . $selectedCoachFilter;
        }
        redirect($redirectUrl);
    }

    if ($action === 'set_all_coaches_access') {
        $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        $pdo->prepare('UPDATE coaches SET mycoach_enabled = ?')->execute([$enabled]);
        flash('success', $enabled === 1 ? 'MyCoach byl povolen všem trenérům.' : 'MyCoach byl vypnut všem trenérům.');
        redirect(BASE_URL . '/admin/mycoach.php');
    }

    if ($action === 'set_all_athletes_access') {
        $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
        if ($selectedCoachFilter > 0) {
            $pdo->prepare('UPDATE athletes SET mycoach_enabled = ? WHERE coach_id = ?')->execute([$enabled, $selectedCoachFilter]);
            flash('success', $enabled === 1 ? 'MyCoach byl povolen všem sportovcům vybraného trenéra.' : 'MyCoach byl vypnut všem sportovcům vybraného trenéra.');
        } else {
            $pdo->prepare('UPDATE athletes SET mycoach_enabled = ?')->execute([$enabled]);
            flash('success', $enabled === 1 ? 'MyCoach byl povolen všem sportovcům.' : 'MyCoach byl vypnut všem sportovcům.');
        }

        $redirectUrl = BASE_URL . '/admin/mycoach.php';
        if ($selectedCoachFilter > 0) {
            $redirectUrl .= '?coach_id=' . $selectedCoachFilter;
        }
        redirect($redirectUrl);
    }
}

$currentMode = adminMyCoachNormalizeMode((string)getAppSetting($mycoachAccessModeKey, 'selected'));

$coachSummary = [
    'total' => 0,
    'enabled' => 0,
    'active' => 0,
];
$athleteSummary = [
    'total' => 0,
    'enabled' => 0,
];

try {
    $coachSummary = $pdo->query('SELECT COUNT(*) AS total, SUM(CASE WHEN mycoach_enabled = 1 THEN 1 ELSE 0 END) AS enabled, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active FROM coaches')->fetch() ?: $coachSummary;
} catch (Throwable $e) {
    $coachSummary = ['total' => 0, 'enabled' => 0, 'active' => 0];
}

try {
    $athleteSummary = $pdo->query('SELECT COUNT(*) AS total, SUM(CASE WHEN mycoach_enabled = 1 THEN 1 ELSE 0 END) AS enabled FROM athletes')->fetch() ?: $athleteSummary;
} catch (Throwable $e) {
    $athleteSummary = ['total' => 0, 'enabled' => 0];
}

$coachRows = [];
try {
    $coachRows = $pdo->query(
        'SELECT c.id, c.name, c.username, c.email, c.is_active, c.mycoach_enabled,
                (SELECT COUNT(*) FROM athletes a WHERE a.coach_id = c.id) AS athlete_count,
                (SELECT COUNT(*) FROM athletes a WHERE a.coach_id = c.id AND a.mycoach_enabled = 1) AS athlete_enabled_count
         FROM coaches c
         ORDER BY c.is_active DESC, c.name ASC, c.username ASC'
    )->fetchAll() ?: [];
} catch (Throwable $e) {
    $coachRows = [];
}

$coachFilterOptions = [];
foreach ($coachRows as $coachRow) {
    $coachFilterOptions[] = [
        'id' => (int)$coachRow['id'],
        'label' => trim((string)($coachRow['name'] ?? '')) !== '' ? (string)$coachRow['name'] : (string)$coachRow['username'],
    ];
}

$athleteWhere = [];
$athleteParams = [];
if ($selectedCoachFilter > 0) {
    $athleteWhere[] = 'a.coach_id = ?';
    $athleteParams[] = $selectedCoachFilter;
}
if ($search !== '') {
    $athleteWhere[] = '(a.first_name LIKE ? OR a.last_name LIKE ? OR a.email LIKE ? OR c.name LIKE ? OR c.username LIKE ?)';
    $like = '%' . $search . '%';
    $athleteParams[] = $like;
    $athleteParams[] = $like;
    $athleteParams[] = $like;
    $athleteParams[] = $like;
    $athleteParams[] = $like;
}

$athleteSql =
    'SELECT a.id, a.first_name, a.last_name, a.email, a.mycoach_enabled, a.coach_id,
            c.name AS coach_name, c.username AS coach_username
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id';
if (!empty($athleteWhere)) {
    $athleteSql .= ' WHERE ' . implode(' AND ', $athleteWhere);
}
$athleteSql .= ' ORDER BY c.name ASC, c.username ASC, a.first_name ASC, a.last_name ASC, a.id ASC LIMIT 600';

$athleteRows = [];
try {
    $stmtAthletes = $pdo->prepare($athleteSql);
    $stmtAthletes->execute($athleteParams);
    $athleteRows = $stmtAthletes->fetchAll() ?: [];
} catch (Throwable $e) {
    $athleteRows = [];
}

renderAdminHeader('MyCoach administrace');
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1">
            <i class="fas fa-brain me-2" style="color:#a78bfa"></i>MyCoach administrace
        </h4>
        <div class="text-muted small">Samostatná správa režimu modulu a ručního povolení pro vybrané uživatele.</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-house me-1"></i>Přehled
    </a>
</div>

<?php if ($currentMode !== 'selected'): ?>
<div class="alert alert-warning border-0 shadow-sm">
    <i class="fas fa-triangle-exclamation me-1"></i>
    Individuální přepínače trenérů a sportovců jsou teď evidované, ale účinné budou až v režimu <strong>Jen vybraní uživatelé</strong>.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold" style="color:#7c3aed"><?= (int)($coachSummary['total'] ?? 0) ?></div>
                <div class="text-muted small">Trenérů celkem</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold" style="color:#0ea5e9"><?= (int)($coachSummary['enabled'] ?? 0) ?></div>
                <div class="text-muted small">Trenéři s MyCoach povolením</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold" style="color:#7c3aed"><?= (int)($athleteSummary['total'] ?? 0) ?></div>
                <div class="text-muted small">Sportovců celkem</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold" style="color:#0ea5e9"><?= (int)($athleteSummary['enabled'] ?? 0) ?></div>
                <div class="text-muted small">Sportovci s MyCoach povolením</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-toggle-on me-2"></i>Globální režim MyCoach
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_mode">

            <div class="vstack gap-2 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mycoach_access_mode" id="mycoachModeDisabled" value="disabled" <?= $currentMode === 'disabled' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mycoachModeDisabled">
                        <span class="fw-semibold">Globálně vypnout</span>
                        <span class="text-muted d-block small">MyCoach nebude dostupný nikomu.</span>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mycoach_access_mode" id="mycoachModeAll" value="all" <?= $currentMode === 'all' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mycoachModeAll">
                        <span class="fw-semibold">Globálně zapnout</span>
                        <span class="text-muted d-block small">MyCoach bude dostupný všem bez ohledu na individuální stav.</span>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="mycoach_access_mode" id="mycoachModeSelected" value="selected" <?= $currentMode === 'selected' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mycoachModeSelected">
                        <span class="fw-semibold">Jen vybraní uživatelé</span>
                        <span class="text-muted d-block small">Povolení řídíte níže po uživatelích.</span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn fw-bold" style="background:#7c3aed;color:#fff;border:none">
                <i class="fas fa-save me-1"></i>Uložit režim
            </button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:#1e1e2e;color:#fff">
        <span><i class="fas fa-user-tie me-2"></i>Trenéři</span>
        <div class="d-flex gap-2">
            <form method="post" onsubmit="return confirm('Opravdu povolit MyCoach všem trenérům?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_all_coaches_access">
                <input type="hidden" name="enabled" value="1">
                <button type="submit" class="btn btn-sm btn-success fw-semibold">Povolit všem</button>
            </form>
            <form method="post" onsubmit="return confirm('Opravdu vypnout MyCoach všem trenérům?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_all_coaches_access">
                <input type="hidden" name="enabled" value="0">
                <button type="submit" class="btn btn-sm btn-outline-light fw-semibold">Vypnout všem</button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($coachRows)): ?>
        <div class="p-4 text-muted">Nenalezeni žádní trenéři.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Trenér</th>
                    <th>E-mail</th>
                    <th class="text-center">Stav účtu</th>
                    <th class="text-center">MyCoach (trenér)</th>
                    <th class="text-center">MyCoach (sportovci)</th>
                    <th class="text-end">Akce</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($coachRows as $coachRow): ?>
                <?php
                    $coachName = trim((string)($coachRow['name'] ?? ''));
                    if ($coachName === '') {
                        $coachName = (string)($coachRow['username'] ?? '');
                    }
                    $coachEnabled = ((int)($coachRow['mycoach_enabled'] ?? 0)) === 1;
                    $coachActive = ((int)($coachRow['is_active'] ?? 0)) === 1;
                    $athleteTotal = (int)($coachRow['athlete_count'] ?? 0);
                    $athleteEnabledCount = (int)($coachRow['athlete_enabled_count'] ?? 0);
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= h($coachName) ?></div>
                        <div class="small text-muted">@<?= h((string)($coachRow['username'] ?? '')) ?></div>
                    </td>
                    <td>
                        <?php if (trim((string)($coachRow['email'] ?? '')) !== ''): ?>
                            <a href="mailto:<?= h((string)$coachRow['email']) ?>"><?= h((string)$coachRow['email']) ?></a>
                        <?php else: ?>
                            <span class="text-muted">Bez e-mailu</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $coachActive ? 'bg-success' : 'bg-secondary' ?>"><?= $coachActive ? 'Aktivní' : 'Neaktivní' ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $coachEnabled ? 'bg-success' : 'bg-secondary' ?>"><?= $coachEnabled ? 'Povoleno' : 'Vypnuto' ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-info text-dark"><?= $athleteEnabledCount ?> / <?= $athleteTotal ?></span>
                    </td>
                    <td class="text-end">
                        <div class="d-flex justify-content-end gap-2">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="set_coach_access">
                                <input type="hidden" name="coach_id" value="<?= (int)$coachRow['id'] ?>">
                                <input type="hidden" name="enabled" value="<?= $coachEnabled ? '0' : '1' ?>">
                                <button type="submit" class="btn btn-sm <?= $coachEnabled ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                    <?= $coachEnabled ? 'Vypnout' : 'Povolit' ?>
                                </button>
                            </form>
                            <a href="<?= BASE_URL ?>/admin/mycoach.php?coach_id=<?= (int)$coachRow['id'] ?>" class="btn btn-sm btn-outline-primary">Sportovci</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-users me-2"></i>Sportovci
    </div>
    <div class="card-body border-bottom">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="coachFilter">Trenér</label>
                <select name="coach_id" id="coachFilter" class="form-select">
                    <option value="0">Všichni trenéři</option>
                    <?php foreach ($coachFilterOptions as $coachOption): ?>
                    <option value="<?= (int)$coachOption['id'] ?>" <?= $selectedCoachFilter === (int)$coachOption['id'] ? 'selected' : '' ?>>
                        <?= h((string)$coachOption['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-semibold" for="searchInput">Hledat sportovce</label>
                <input type="text" name="q" id="searchInput" class="form-control" value="<?= h($search) ?>" placeholder="Jméno, e-mail nebo trenér...">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary w-100">Filtrovat</button>
                <a href="<?= BASE_URL ?>/admin/mycoach.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>

        <div class="d-flex gap-2 mt-3 flex-wrap">
            <form method="post" onsubmit="return confirm('Opravdu povolit MyCoach všem sportovcům v aktuálním filtru?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_all_athletes_access">
                <input type="hidden" name="enabled" value="1">
                <button type="submit" class="btn btn-sm btn-success fw-semibold">Povolit všechny sportovce</button>
            </form>
            <form method="post" onsubmit="return confirm('Opravdu vypnout MyCoach všem sportovcům v aktuálním filtru?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_all_athletes_access">
                <input type="hidden" name="enabled" value="0">
                <button type="submit" class="btn btn-sm btn-outline-danger fw-semibold">Vypnout všechny sportovce</button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($athleteRows)): ?>
        <div class="p-4 text-muted">Žádní sportovci pro aktuální filtr.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Sportovec</th>
                    <th>Trenér</th>
                    <th>E-mail</th>
                    <th class="text-center">MyCoach</th>
                    <th class="text-end">Akce</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($athleteRows as $athleteRow): ?>
                <?php
                    $fullName = trim((string)($athleteRow['first_name'] ?? '') . ' ' . (string)($athleteRow['last_name'] ?? ''));
                    if ($fullName === '') {
                        $fullName = 'Sportovec #' . (int)$athleteRow['id'];
                    }
                    $coachName = trim((string)($athleteRow['coach_name'] ?? ''));
                    if ($coachName === '') {
                        $coachName = (string)($athleteRow['coach_username'] ?? '');
                    }
                    $enabled = ((int)($athleteRow['mycoach_enabled'] ?? 0)) === 1;
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= h($fullName) ?></div>
                        <div class="small text-muted">ID <?= (int)$athleteRow['id'] ?></div>
                    </td>
                    <td><?= h($coachName) ?></td>
                    <td>
                        <?php if (trim((string)($athleteRow['email'] ?? '')) !== ''): ?>
                        <a href="mailto:<?= h((string)$athleteRow['email']) ?>"><?= h((string)$athleteRow['email']) ?></a>
                        <?php else: ?>
                        <span class="text-muted">Bez e-mailu</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $enabled ? 'bg-success' : 'bg-secondary' ?>"><?= $enabled ? 'Povoleno' : 'Vypnuto' ?></span>
                    </td>
                    <td class="text-end">
                        <form method="post" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="set_athlete_access">
                            <input type="hidden" name="athlete_id" value="<?= (int)$athleteRow['id'] ?>">
                            <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-sm <?= $enabled ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                <?= $enabled ? 'Vypnout' : 'Povolit' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderAdminFooter();
