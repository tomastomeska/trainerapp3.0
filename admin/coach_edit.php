<?php
// admin/coach_edit.php – editace trenéra
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo      = getDB();
$coachId  = intParam($_GET, 'id');
$error    = null;
ensurePasswordAuditColumns($pdo);

$stmtMakeupDeadlineCol = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'makeup_booking_deadline_days'");
$hasMakeupDeadlineColumn = $stmtMakeupDeadlineCol !== false && (bool)$stmtMakeupDeadlineCol->fetch();
if (!$hasMakeupDeadlineColumn) {
    try {
        $pdo->exec('ALTER TABLE coaches ADD COLUMN makeup_booking_deadline_days INT NOT NULL DEFAULT 14 AFTER bank_account');
        $hasMakeupDeadlineColumn = true;
    } catch (Throwable $e) {
        $hasMakeupDeadlineColumn = false;
    }
}

$stmt = $pdo->prepare('SELECT * FROM coaches WHERE id = ?');
$stmt->execute([$coachId]);
$coach = $stmt->fetch();

if (!$coach) {
    flash('danger', 'Trenér nenalezen.');
    redirect(BASE_URL . '/admin/coaches.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $username  = trim($_POST['username']  ?? '');
        $name      = trim($_POST['name']      ?? '');
        $email     = trim($_POST['email']     ?? '');
        $password  = $_POST['password']  ?? '';
        $password2 = $_POST['password2'] ?? '';
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $makeupDeadlineDaysRaw = trim((string)($_POST['makeup_booking_deadline_days'] ?? ''));
        $makeupDeadlineDays = 14;
        $generateTemporaryPassword = $action === 'generate_password';

        if ($hasMakeupDeadlineColumn) {
            if ($makeupDeadlineDaysRaw !== '') {
                if (!ctype_digit($makeupDeadlineDaysRaw)) {
                    $error = 'Lhuta pro nahradni termin musi byt cele cislo ve dnech.';
                } else {
                    $makeupDeadlineDays = (int)$makeupDeadlineDaysRaw;
                    if ($makeupDeadlineDays < 1 || $makeupDeadlineDays > 365) {
                        $error = 'Lhuta pro nahradni termin muze byt 1 az 365 dni.';
                    }
                }
            }
        }

        if ($error !== null) {
            // Validation error already set.
        } elseif ($username === '') {
            $error = 'Zadejte uživatelské jméno.';
        } elseif (!preg_match('/^[a-z0-9_.\-]{3,50}$/i', $username)) {
            $error = 'Uživatelské jméno smí obsahovat jen písmena, číslice, tečku, pomlčku a podtržítko (3–50 znaků).';
        } elseif (!$generateTemporaryPassword && $password !== '' && strlen($password) < 6) {
            $error = 'Heslo musí mít alespoň 6 znaků.';
        } elseif (!$generateTemporaryPassword && $password !== '' && $password !== $password2) {
            $error = 'Hesla se neshodují.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Neplatná e-mailová adresa.';
        } else {
            // Unikátnost uživatelského jména (kromě tohoto trenéra)
            $stmtU = $pdo->prepare('SELECT id FROM coaches WHERE username = ? AND id != ?');
            $stmtU->execute([$username, $coachId]);
            if ($stmtU->fetch()) {
                $error = 'Toto uživatelské jméno je již obsazeno.';
            } else {
                if ($generateTemporaryPassword) {
                    $tempPassword = generateRandomPassword(12);
                    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                    if ($hasMakeupDeadlineColumn) {
                        $pdo->prepare(
                            'UPDATE coaches SET username=?, name=?, email=?, is_active=?, makeup_booking_deadline_days=?, password=?, password_changed_at = NOW(), force_password_change = 1 WHERE id=?'
                        )->execute([$username, $name ?: null, $email ?: null, $isActive, $makeupDeadlineDays, $hash, $coachId]);
                    } else {
                        $pdo->prepare(
                            'UPDATE coaches SET username=?, name=?, email=?, is_active=?, password=?, password_changed_at = NOW(), force_password_change = 1 WHERE id=?'
                        )->execute([$username, $name ?: null, $email ?: null, $isActive, $hash, $coachId]);
                    }

                    $host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $loginUrl = $scheme . '://' . $host . BASE_URL . '/login.php';
                    $sent = false;
                    if ($email !== '') {
                        $sent = sendCoachWelcomeEmail($email, $username, $tempPassword, $loginUrl);
                    }

                    $message = 'Dočasné heslo bylo vygenerováno.';
                    if ($sent) {
                        $message .= ' E-mail byl odeslán.';
                    } elseif ($email !== '') {
                        $message .= ' E-mail se nepodařilo odeslat.';
                    }
                    $message .= ' Dočasné heslo: ' . $tempPassword;
                    flash('success', $message);
                    redirect(BASE_URL . '/admin/coach_edit.php?id=' . $coachId);
                } elseif ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($hasMakeupDeadlineColumn) {
                        $pdo->prepare(
                            'UPDATE coaches SET username=?, name=?, email=?, is_active=?, makeup_booking_deadline_days=?, password=?, password_changed_at = NOW() WHERE id=?'
                        )->execute([$username, $name ?: null, $email ?: null, $isActive, $makeupDeadlineDays, $hash, $coachId]);
                    } else {
                        $pdo->prepare(
                            'UPDATE coaches SET username=?, name=?, email=?, is_active=?, password=?, password_changed_at = NOW() WHERE id=?'
                        )->execute([$username, $name ?: null, $email ?: null, $isActive, $hash, $coachId]);
                    }
                } else {
                    if ($hasMakeupDeadlineColumn) {
                        $pdo->prepare(
                            'UPDATE coaches SET username=?, name=?, email=?, is_active=?, makeup_booking_deadline_days=? WHERE id=?'
                        )->execute([$username, $name ?: null, $email ?: null, $isActive, $makeupDeadlineDays, $coachId]);
                    } else {
                        $pdo->prepare(
                            'UPDATE coaches SET username=?, name=?, email=?, is_active=? WHERE id=?'
                        )->execute([$username, $name ?: null, $email ?: null, $isActive, $coachId]);
                    }
                }
                flash('success', 'Trenér byl aktualizován.');
                redirect(BASE_URL . '/admin/coaches.php');
            }
        }
    }
}

$d = ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) ? $_POST : $coach;

renderAdminHeader('Upravit trenéra');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0">
        <i class="fas fa-user-edit me-2" style="color:#a78bfa"></i>
        Upravit trenéra: <span style="color:#a78bfa"><?= h($coach['username']) ?></span>
    </h4>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-body p-4">
        <div class="alert alert-light border mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <div class="fw-semibold mb-1">Stav přístupu</div>
                    <div class="small text-muted">Heslo se nezobrazuje. Vidíte jen stav a datum poslední změny.</div>
                </div>
                <div class="text-end small">
                    <div>
                        <span class="text-muted">Přístup:</span>
                        <?php if (!empty($coach['password'])): ?>
                        <span class="badge bg-success">Nastaveno</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Bez hesla</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1">
                        <span class="text-muted">Vynucená změna:</span>
                        <?php if (!empty($coach['force_password_change'])): ?>
                        <span class="badge bg-warning text-dark">Ano</span>
                        <?php else: ?>
                        <span class="badge bg-light text-dark border">Ne</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-1 text-muted">Poslední změna: <?= !empty($coach['password_changed_at']) ? h(formatDateTime((string)$coach['password_changed_at'])) : '–' ?></div>
                </div>
            </div>
        </div>

        <form method="post" novalidate>
            <?= csrfField() ?>
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Jméno trenéra</label>
                    <input type="text" name="name" class="form-control"
                           value="<?= h($d['name'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">
                        Uživatelské jméno <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="username" class="form-control"
                           value="<?= h($d['username'] ?? '') ?>" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">E-mail</label>
                <input type="email" name="email" class="form-control"
                       value="<?= h($d['email'] ?? '') ?>">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">
                        Nové heslo
                        <small class="text-muted fw-normal">(nechat prázdné = beze změny)</small>
                    </label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Heslo znovu</label>
                    <input type="password" name="password2" class="form-control" autocomplete="new-password">
                </div>
            </div>
            <div class="mb-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active"
                           id="isActive" value="1"
                           <?= ($d['is_active'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="isActive">
                        Trenér je aktivní (může se přihlásit)
                    </label>
                </div>
            </div>
            <?php if ($hasMakeupDeadlineColumn): ?>
            <div class="mb-4">
                <label class="form-label fw-semibold">Lhůta pro výběr náhradního termínu (dny)</label>
                <input type="number" name="makeup_booking_deadline_days" class="form-control"
                       min="1" max="365" step="1"
                       value="<?= h((string)($d['makeup_booking_deadline_days'] ?? '14')) ?>"
                       placeholder="např. 14">
                <div class="form-text">Výchozí hodnota je 14 dní. Po zrušení termínu sportovcem musí být náhradní rezervace vytvořena do této lhůty.</div>
            </div>
            <?php endif; ?>
            <div class="d-flex gap-2">
                <button type="submit" name="action" value="save" class="btn fw-bold px-4"
                        style="background:#7c3aed;color:#fff;border:none">
                    <i class="fas fa-save me-1"></i>Uložit změny
                </button>
                <button type="submit" name="action" value="generate_password" class="btn btn-outline-primary fw-semibold" onclick="return confirm('Vygenerovat nové dočasné heslo a nastavit vynucenou změnu při přihlášení?');">
                    <i class="fas fa-key me-1"></i>Vygenerovat nové heslo
                </button>
                <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary">Zrušit</a>
            </div>
        </form>
    </div>
</div>

<!-- Info o datech trenéra -->
<div class="mt-4 card border-0 shadow-sm" style="max-width:600px">
    <div class="card-header bg-light fw-semibold">
        <i class="fas fa-info-circle me-1 text-muted"></i>Statistiky trenéra
    </div>
    <div class="card-body py-2">
        <?php
        $stats = $pdo->prepare(
            'SELECT COUNT(DISTINCT a.id) AS athletes,
                    COUNT(DISTINCT e.id) AS exercises,
                    COUNT(DISTINCT ts.id) AS sessions
             FROM coaches c
             LEFT JOIN athletes a ON a.coach_id = c.id
             LEFT JOIN exercises e ON e.coach_id = c.id
             LEFT JOIN training_sessions ts ON ts.athlete_id = a.id AND ts.completed_at IS NOT NULL
             WHERE c.id = ?'
        );
        $stats->execute([$coachId]);
        $s = $stats->fetch();
        ?>
        <div class="row text-center">
            <div class="col-4">
                <div class="fs-4 fw-bold text-warning"><?= $s['athletes'] ?></div>
                <div class="small text-muted">Sportovců</div>
            </div>
            <div class="col-4">
                <div class="fs-4 fw-bold text-secondary"><?= $s['exercises'] ?></div>
                <div class="small text-muted">Cviků</div>
            </div>
            <div class="col-4">
                <div class="fs-4 fw-bold text-info"><?= $s['sessions'] ?></div>
                <div class="small text-muted">Tréninků</div>
            </div>
        </div>
    </div>
</div>

<?php renderAdminFooter(); ?>
