<?php
// admin/coach_edit.php – editace trenéra
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo      = getDB();
$coachId  = intParam($_GET, 'id');
$error    = null;

$stmt = $pdo->prepare('SELECT * FROM coaches WHERE id = ?');
$stmt->execute([$coachId]);
$coach = $stmt->fetch();

if (!$coach) {
    flash('danger', 'Trenér nenalezen.');
    redirect(BASE_URL . '/admin/coaches.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $username  = trim($_POST['username']  ?? '');
        $name      = trim($_POST['name']      ?? '');
        $email     = trim($_POST['email']     ?? '');
        $password  = $_POST['password']  ?? '';
        $password2 = $_POST['password2'] ?? '';
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        if ($username === '') {
            $error = 'Zadejte uživatelské jméno.';
        } elseif (!preg_match('/^[a-z0-9_.\-]{3,50}$/i', $username)) {
            $error = 'Uživatelské jméno smí obsahovat jen písmena, číslice, tečku, pomlčku a podtržítko (3–50 znaků).';
        } elseif ($password !== '' && strlen($password) < 6) {
            $error = 'Heslo musí mít alespoň 6 znaků.';
        } elseif ($password !== '' && $password !== $password2) {
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
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare(
                        'UPDATE coaches SET username=?, name=?, email=?, is_active=?, password=? WHERE id=?'
                    )->execute([$username, $name ?: null, $email ?: null, $isActive, $hash, $coachId]);
                } else {
                    $pdo->prepare(
                        'UPDATE coaches SET username=?, name=?, email=?, is_active=? WHERE id=?'
                    )->execute([$username, $name ?: null, $email ?: null, $isActive, $coachId]);
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
            <div class="d-flex gap-2">
                <button type="submit" class="btn fw-bold px-4"
                        style="background:#7c3aed;color:#fff;border:none">
                    <i class="fas fa-save me-1"></i>Uložit změny
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
