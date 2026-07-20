<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
ensurePasswordAuditColumns($pdo);

$coachFilter = intParam($_GET, 'coach_id');
$query = trim((string)($_GET['q'] ?? ''));
$loginFilter = (string)($_GET['login'] ?? 'all');
$sort = (string)($_GET['sort'] ?? 'surname_asc');

$sortOptions = [
    'surname_asc' => 'a.last_name ASC, a.first_name ASC, a.id ASC',
    'surname_desc' => 'a.last_name DESC, a.first_name DESC, a.id DESC',
    'name_asc' => 'a.first_name ASC, a.last_name ASC, a.id ASC',
    'name_desc' => 'a.first_name DESC, a.last_name DESC, a.id DESC',
];

if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'surname_asc';
}

$coaches = $pdo->query('SELECT id, name, username, is_active FROM coaches ORDER BY is_active DESC, name ASC, username ASC')->fetchAll();

$postAction = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($postAction, ['reset_login_access', 'revoke_login_access'], true)) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/athletes.php');
    }

    $athleteId = intParam($_POST, 'athlete_id');
    $stmt = $pdo->prepare(
        'SELECT a.id, a.first_name, a.last_name, a.email, a.login_enabled
         FROM athletes a
         WHERE a.id = ?
         LIMIT 1'
    );
    $stmt->execute([$athleteId]);
    $athlete = $stmt->fetch();

    if (!$athlete) {
        flash('danger', 'Sportovec nenalezen.');
        redirect(BASE_URL . '/admin/athletes.php');
    }

    if ($postAction === 'revoke_login_access') {
        $pdo->prepare(
            'UPDATE athletes
             SET login_enabled = 0,
                 password = NULL,
                 password_changed_at = NULL,
                 force_password_change = 1
             WHERE id = ?'
        )->execute([$athleteId]);

        flash('success', 'Přístup sportovce byl zrušen.');
        redirect(BASE_URL . '/admin/athletes.php');
    }

    $email = trim((string)($athlete['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'Sportovec nemá platný e-mail, nelze vytvořit nebo resetovat přístup.');
        redirect(BASE_URL . '/admin/athletes.php');
    }

    $tempPassword = generateRandomPassword(12);
    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $pdo->prepare(
        'UPDATE athletes
         SET password = ?,
             password_changed_at = NOW(),
             login_enabled = 1,
             force_password_change = 1
         WHERE id = ?'
    )->execute([$passwordHash, $athleteId]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $loginUrl = $host !== ''
        ? ($scheme . '://' . $host . rtrim(BASE_URL, '/') . '/login.php')
        : (BASE_URL . '/login.php');

    $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
    $sent = sendAthleteWelcomeEmail($email, $athleteName, $tempPassword, $loginUrl);
    $emailStatus = $sent ? ' E-mail byl odeslán.' : ' E-mail se nepodařilo odeslat.';

    flash(
        'success',
        'Přístup sportovce byl připraven.' . $emailStatus
        . '<br>Přihlašovací e-mail: <code>' . h($email) . '</code>'
        . '<br>Dočasné heslo: <code>' . h($tempPassword) . '</code>'
        . '<br><small class="text-muted">Sportovec bude po prvním přihlášení vyzván ke změně hesla.</small>'
    );
    redirect(BASE_URL . '/admin/athletes.php');
}

$where = [];
$params = [];

if ($coachFilter > 0) {
    $where[] = 'a.coach_id = ?';
    $params[] = $coachFilter;
}

if ($query !== '') {
    $where[] = '(a.first_name LIKE ? OR a.last_name LIKE ? OR a.email LIKE ? OR a.phone_contact LIKE ? OR a.notes LIKE ? OR c.name LIKE ? OR c.username LIKE ?)';
    $like = '%' . $query . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

if ($loginFilter === 'enabled') {
    $where[] = 'a.login_enabled = 1';
} elseif ($loginFilter === 'disabled') {
    $where[] = 'a.login_enabled = 0';
} elseif ($loginFilter === 'needs_password') {
    $where[] = 'a.login_enabled = 1 AND a.force_password_change = 1';
}

$sql =
    'SELECT a.*, c.name AS coach_name, c.username AS coach_username, c.email AS coach_email,
            (SELECT COUNT(*)
             FROM training_sessions ts
             WHERE ts.athlete_id = a.id
               AND ts.completed_at IS NOT NULL
               AND ts.deleted_by_coach_at IS NULL) AS completed_sessions,
            (SELECT MAX(ts.completed_at)
             FROM training_sessions ts
             WHERE ts.athlete_id = a.id
               AND ts.completed_at IS NOT NULL
               AND ts.deleted_by_coach_at IS NULL) AS last_completed_at,
            (SELECT MAX(ts.started_at)
             FROM training_sessions ts
             WHERE ts.athlete_id = a.id
               AND ts.deleted_by_coach_at IS NULL) AS last_started_at
     FROM athletes a
     LEFT JOIN coaches c ON c.id = a.coach_id';

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY ' . $sortOptions[$sort];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$athletes = $stmt->fetchAll();

$totalAthletes = count($athletes);
$enabledAthletes = count(array_filter($athletes, static fn(array $athlete): bool => !empty($athlete['login_enabled'])));
$emailAthletes = count(array_filter($athletes, static fn(array $athlete): bool => trim((string)($athlete['email'] ?? '')) !== ''));

renderAdminHeader('Sportovci');
?>

<style>
    .admin-athletes-table .admin-athlete-person-cell {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        min-width: 0;
    }

    .admin-athletes-table .admin-athlete-avatar {
        width: 46px;
        height: 46px;
        flex: 0 0 46px;
        border-radius: 999px;
        overflow: hidden;
        border: 2px solid #e5e7eb;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
        background: linear-gradient(135deg, #111827 0%, #334155 100%);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .admin-athletes-table .admin-athlete-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .admin-athletes-table .admin-athlete-avatar--initials {
        font-size: 0.86rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .admin-athletes-table .admin-athlete-person-main {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.22rem;
    }

    .admin-athletes-table .admin-athlete-person-name {
        font-weight: 800;
        line-height: 1.15;
        color: #0f172a;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .admin-athletes-table .admin-athlete-person-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        align-items: center;
    }

    .admin-athletes-table .admin-athlete-person-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.14rem 0.55rem;
        border-radius: 999px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #64748b;
        font-size: 0.78rem;
        line-height: 1.2;
        white-space: nowrap;
    }

    .admin-athletes-table td:first-child {
        width: 280px;
    }

</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1">
            <i class="fas fa-users me-2" style="color:#a78bfa"></i>Správa sportovců
        </h4>
        <div class="text-muted small">Přehled všech registrovaných sportovců napříč trenéry, včetně přístupů do aplikace.</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/athlete_add.php" class="btn fw-bold" style="background:#7c3aed;color:#fff;border:none">
        <i class="fas fa-user-plus me-1"></i>Přidat sportovce za trenéra
    </a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold" style="color:#7c3aed"><?= $totalAthletes ?></div>
                <div class="text-muted small">Sportovců v zobrazení</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold text-success"><?= $enabledAthletes ?></div>
                <div class="text-muted small">S aktivním přístupem</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-4">
                <div class="display-6 fw-bold text-warning"><?= $emailAthletes ?></div>
                <div class="text-muted small">S vyplněným e-mailem</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-lg-5">
                <label class="form-label fw-semibold">Vyhledávání</label>
                <input type="text" name="q" class="form-control" value="<?= h($query) ?>" placeholder="Jméno, e-mail, telefon, poznámka, trenér...">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label fw-semibold">Trenér</label>
                <select name="coach_id" class="form-select">
                    <option value="0">Všichni trenéři</option>
                    <?php foreach ($coaches as $coach): ?>
                        <option value="<?= (int)$coach['id'] ?>" <?= $coachFilter === (int)$coach['id'] ? 'selected' : '' ?>>
                            <?= h((string)($coach['name'] ?: $coach['username'])) ?><?= !empty($coach['is_active']) ? '' : ' (neaktivní)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label fw-semibold">Přístup</label>
                <select name="login" class="form-select">
                    <option value="all" <?= $loginFilter === 'all' ? 'selected' : '' ?>>Vše</option>
                    <option value="enabled" <?= $loginFilter === 'enabled' ? 'selected' : '' ?>>Aktivní</option>
                    <option value="needs_password" <?= $loginFilter === 'needs_password' ? 'selected' : '' ?>>Nutná změna</option>
                    <option value="disabled" <?= $loginFilter === 'disabled' ? 'selected' : '' ?>>Bez přístupu</option>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label fw-semibold">Řazení</label>
                <select name="sort" class="form-select">
                    <option value="surname_asc" <?= $sort === 'surname_asc' ? 'selected' : '' ?>>Příjmení A-Z</option>
                    <option value="surname_desc" <?= $sort === 'surname_desc' ? 'selected' : '' ?>>Příjmení Z-A</option>
                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Jméno A-Z</option>
                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Jméno Z-A</option>
                </select>
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-warning fw-bold flex-grow-1">
                    <i class="fas fa-filter me-1"></i>Filtrovat
                </button>
                <a href="<?= BASE_URL ?>/admin/athletes.php" class="btn btn-outline-secondary">
                    <i class="fas fa-rotate-left"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($athletes)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-user-slash fa-2x mb-3 d-block"></i>
                Nebyl nalezen žádný sportovec pro zadaný filtr.
            </div>
        <?php else: ?>
            <div class="table-responsive admin-athletes-table">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Sportovec</th>
                            <th>Trenér</th>
                            <th>Kontakt</th>
                            <th class="text-center">Věk</th>
                            <th class="text-center">Tréninků</th>
                            <th>Přístup</th>
                            <th>Heslo</th>
                            <th>Poslední aktivita</th>
                            <th class="text-end">Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($athletes as $athlete): ?>
                            <?php
                            $athleteId = (int)$athlete['id'];
                            $fullName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
                            $age = calculateAge((string)($athlete['birth_date'] ?? null));
                            $email = trim((string)($athlete['email'] ?? ''));
                            $phone = trim((string)($athlete['phone_contact'] ?? ''));
                            $notes = trim((string)($athlete['notes'] ?? ''));
                            $photoUrl = photoUrl($athlete['photo'] ?? null, 'athletes');
                            $coachName = trim((string)($athlete['coach_name'] ?? ''));
                            $coachUsername = trim((string)($athlete['coach_username'] ?? ''));
                            $loginEnabled = !empty($athlete['login_enabled']);
                            $forcePasswordChange = !empty($athlete['force_password_change']);
                            $passwordChangedAt = !empty($athlete['password_changed_at']) ? formatDateTime((string)$athlete['password_changed_at']) : '–';
                            $lastLogin = !empty($athlete['last_login']) ? formatDateTime((string)$athlete['last_login']) : 'Nikdy';
                            $lastCompletedAt = !empty($athlete['last_completed_at']) ? formatDateTime((string)$athlete['last_completed_at']) : '–';
                            $completedSessions = (int)($athlete['completed_sessions'] ?? 0);
                            $rowId = 'athleteRow' . $athleteId;
                            ?>
                            <tr>
                                <td>
                                    <div class="admin-athlete-person-cell">
                                        <?php if ($photoUrl): ?>
                                            <div class="admin-athlete-avatar">
                                                <img src="<?= h($photoUrl) ?>" alt="<?= h($fullName) ?>">
                                            </div>
                                        <?php else: ?>
                                            <div class="admin-athlete-avatar admin-athlete-avatar--initials">
                                                <?= h(mb_strtoupper(mb_substr($athlete['first_name'], 0, 1, 'UTF-8') . mb_substr($athlete['last_name'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="admin-athlete-person-main">
                                            <div class="admin-athlete-person-name" title="<?= h($fullName !== '' ? $fullName : ('Sportovec #' . $athleteId)) ?>">
                                                <?= h($fullName !== '' ? $fullName : ('Sportovec #' . $athleteId)) ?>
                                            </div>
                                            <div class="admin-athlete-person-meta">
                                                <span class="admin-athlete-person-pill">#<?= $athleteId ?></span>
                                                <span class="admin-athlete-person-pill">
                                                    <i class="far fa-calendar-alt"></i>
                                                    <?= !empty($athlete['created_at']) ? h(formatDate((string)$athlete['created_at'])) : '–' ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= $coachName !== '' ? h($coachName) : '<span class="text-muted">Bez trenéra</span>' ?></div>
                                    <?php if ($coachUsername !== ''): ?>
                                        <div class="small text-muted">@<?= h($coachUsername) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?= $email !== '' ? '<a href="mailto:' . h($email) . '">' . h($email) . '</a>' : '<span class="text-muted">Bez e-mailu</span>' ?></div>
                                    <div class="small text-muted"><?= $phone !== '' ? h($phone) : 'Bez telefonu' ?></div>
                                </td>
                                <td class="text-center"><?= $age !== null ? (int)$age . ' let' : '–' ?></td>
                                <td class="text-center"><span class="badge bg-warning text-dark"><?= $completedSessions ?></span></td>
                                <td>
                                    <?php if ($loginEnabled): ?>
                                        <span class="badge bg-success">Aktivní</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Bez přístupu</span>
                                    <?php endif; ?>
                                    <?php if ($forcePasswordChange): ?>
                                        <div class="small text-muted mt-1">Vynutit změnu hesla</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= $loginEnabled ? 'Nastaveno' : 'Nevytvořeno' ?></div>
                                    <div class="small text-muted">Změna: <?= h($passwordChangedAt) ?></div>
                                </td>
                                <td>
                                    <div class="small text-nowrap"><?= h($lastCompletedAt) ?></div>
                                    <div class="small text-muted">Poslední přihlášení: <?= h($lastLogin) ?></div>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $rowId ?>" aria-expanded="false" aria-controls="<?= $rowId ?>">
                                        <i class="fas fa-chevron-down me-1"></i>Detail
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="9" class="p-0 border-0">
                                    <div id="<?= $rowId ?>" class="collapse border-top bg-body-tertiary px-3 py-3">
                                        <div class="row g-3">
                                            <div class="col-lg-8">
                                                <div class="row g-2 small">
                                                    <div class="col-sm-6"><span class="text-muted">Datum narození:</span> <strong><?= !empty($athlete['birth_date']) ? h(formatDate((string)$athlete['birth_date'])) : '–' ?></strong></div>
                                                    <div class="col-sm-6"><span class="text-muted">Přidán:</span> <strong><?= !empty($athlete['created_at']) ? h(formatDateTime((string)$athlete['created_at'])) : '–' ?></strong></div>
                                                    <div class="col-sm-6"><span class="text-muted">Poslední dokončený trénink:</span> <strong><?= h($lastCompletedAt) ?></strong></div>
                                                    <div class="col-sm-6"><span class="text-muted">Poslední změna hesla:</span> <strong><?= h($passwordChangedAt) ?></strong></div>
                                                </div>
                                                <div class="mt-3">
                                                    <div class="text-muted small fw-semibold mb-1">Poznámky</div>
                                                    <div class="p-3 bg-white rounded border">
                                                        <?= $notes !== '' ? nl2br(h($notes)) : '<span class="text-muted">Bez poznámek</span>' ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-lg-4">
                                                <div class="d-grid gap-2">
                                                    <a href="<?= BASE_URL ?>/admin/athlete_edit.php?id=<?= $athleteId ?>" class="btn btn-outline-secondary">
                                                        <i class="fas fa-edit me-1"></i>Upravit sportovce
                                                    </a>
                                                    <form method="post" class="d-grid">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="reset_login_access">
                                                        <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                                                        <button type="submit" class="btn btn-warning fw-bold" onclick="return confirm('Vytvořit nebo resetovat přístup pro sportovce <?= h(addslashes($fullName)) ?>?');">
                                                            <i class="fas fa-key me-1"></i><?= $loginEnabled ? 'Resetovat heslo' : 'Vytvořit přístup' ?>
                                                        </button>
                                                    </form>
                                                    <?php if ($loginEnabled): ?>
                                                    <form method="post" class="d-grid">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="revoke_login_access">
                                                        <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                                                        <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Zrušit přístup sportovce <?= h(addslashes($fullName)) ?>?');">
                                                            <i class="fas fa-user-slash me-1"></i>Zrušit přístup
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>
                                                    <form method="post" action="<?= BASE_URL ?>/admin/athlete_delete.php" class="d-grid" onsubmit="return confirm('Opravdu smazat sportovce <?= h(addslashes($fullName)) ?>? Smažou se i navázané tréninky.');">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                                                        <button type="submit" class="btn btn-outline-danger">
                                                            <i class="fas fa-trash me-1"></i>Smazat sportovce
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
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

<?php renderAdminFooter(); ?>