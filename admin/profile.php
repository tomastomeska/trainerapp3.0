<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo     = getDB();
$error   = null;

// Akce z formuláře
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $action = $_POST['action'] ?? '';
        $mustChangePassword = !empty($_SESSION['coach_force_password_change']);

        if ($mustChangePassword && $action !== 'change_password') {
            $error = 'Při prvním přihlášení je nutné nejdříve změnit heslo.';
        }

        if ($error === null && $action === 'update_profile') {
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($name === '') {
                $error = 'Jméno nesmí být prázdné.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Zadejte platný e-mail.';
            } else {
                $pdo->prepare('UPDATE coaches SET name = ?, email = ? WHERE id = ?')
                    ->execute([$name, $email ?: null, $coachId]);
                $_SESSION['coach_name'] = $name;
                flash('success', 'Profil byl aktualizován.');
                redirect(BASE_URL . '/profile.php');
            }
        }

        if ($error === null && $action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword     = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $error = 'Vyplňte všechna pole pro změnu hesla.';
            } elseif (strlen($newPassword) < 6) {
                $error = 'Nové heslo musí mít alespoň 6 znaků.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Nová hesla se neshodují.';
            } else {
                $stmt = $pdo->prepare('SELECT password FROM coaches WHERE id = ?');
                $stmt->execute([$coachId]);
                $coachAuth = $stmt->fetch();

                if (!$coachAuth || !password_verify($currentPassword, $coachAuth['password'])) {
                    $error = 'Aktuální heslo není správné.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE coaches SET password = ?, force_password_change = 0 WHERE id = ?')
                        ->execute([$newHash, $coachId]);
                    $_SESSION['coach_force_password_change'] = 0;

                    flash('success', 'Heslo bylo úspěšně změněno.');
                    redirect(BASE_URL . '/profile.php');
                }
            }
        }
    }
}

$stmtCoach = $pdo->prepare('SELECT id, username, name, email, created_at FROM coaches WHERE id = ?');
$stmtCoach->execute([$coachId]);
$coach = $stmtCoach->fetch();

$stats = [];

$stmtAthletes = $pdo->prepare('SELECT COUNT(*) FROM athletes WHERE coach_id = ?');
$stmtAthletes->execute([$coachId]);
$stats['athletes'] = (int)$stmtAthletes->fetchColumn();

$stmtExercises = $pdo->prepare('SELECT COUNT(*) FROM exercises WHERE coach_id = ?');
$stmtExercises->execute([$coachId]);
$stats['exercises'] = (int)$stmtExercises->fetchColumn();

$stmtSets = $pdo->prepare('SELECT COUNT(*) FROM workout_sets WHERE coach_id = ?');
$stmtSets->execute([$coachId]);
$stats['sets'] = (int)$stmtSets->fetchColumn();

$stmtSessions = $pdo->prepare(
    'SELECT COUNT(*)
     FROM training_sessions ts
     JOIN athletes a ON ts.athlete_id = a.id
    WHERE a.coach_id = ?
      AND ts.completed_at IS NOT NULL
      AND ts.deleted_by_coach_at IS NULL'
);
$stmtSessions->execute([$coachId]);
$stats['sessions'] = (int)$stmtSessions->fetchColumn();

$stmtLatest = $pdo->prepare(
    'SELECT first_name, last_name, created_at
     FROM athletes
     WHERE coach_id = ?
     ORDER BY created_at DESC
     LIMIT 5'
);
$stmtLatest->execute([$coachId]);
$latestAthletes = $stmtLatest->fetchAll();

renderHeader('Můj profil');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <h2 class="mb-0"><i class="fas fa-user-circle me-2 text-warning"></i>Můj profil</h2>
</div>

<div class="dashboard-quick-tiles mb-3">
    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="quick-tile quick-tile-muted">
        <span class="quick-tile__label"><i class="fas fa-house me-1"></i>Domů</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/admin/zpravy.php" class="quick-tile quick-tile-danger">
        <span class="quick-tile__label"><i class="fas fa-envelope me-1"></i>Zprávy</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/admin/meals.php" class="quick-tile quick-tile-success">
        <span class="quick-tile__label"><i class="fas fa-utensils me-1"></i>Jídelníčky</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/admin/graphs.php" class="quick-tile quick-tile-info">
        <span class="quick-tile__label"><i class="fas fa-chart-line me-1"></i>Grafy</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/calendar.php" class="quick-tile quick-tile-warning">
        <span class="quick-tile__label"><i class="fas fa-calendar-alt me-1"></i>Kalendář</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
    <a href="<?= BASE_URL ?>/admin/change_password.php" class="quick-tile quick-tile-muted">
        <span class="quick-tile__label"><i class="fas fa-key me-1"></i>Heslo</span>
        <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= $stats['athletes'] ?></div>
            <div class="text-muted">Sportovců</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= $stats['sessions'] ?></div>
            <div class="text-muted">Dokončených tréninků</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= $stats['exercises'] ?></div>
            <div class="text-muted">Cviků</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="display-6 fw-bold text-warning"><?= $stats['sets'] ?></div>
            <div class="text-muted">Sad</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-id-card me-2"></i>Základní údaje
            </div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Uživatelské jméno</label>
                        <input type="text" class="form-control" value="<?= h($coach['username']) ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Jméno trenéra</label>
                        <input type="text" name="name" class="form-control" value="<?= h($coach['name'] ?? '') ?>" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">E-mail</label>
                        <input type="email" name="email" class="form-control" value="<?= h($coach['email'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="fas fa-save me-1"></i>Uložit profil
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-key me-2"></i>Změna hesla
            </div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Aktuální heslo</label>
                        <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nové heslo</label>
                        <input type="password" name="new_password" class="form-control" autocomplete="new-password" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Potvrzení nového hesla</label>
                        <input type="password" name="confirm_password" class="form-control" autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="fas fa-save me-1"></i>Změnit heslo
                    </button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-users me-2"></i>Poslední přidaní sportovci
            </div>
            <div class="card-body p-0">
                <?php if (empty($latestAthletes)): ?>
                <div class="p-3 text-muted">Zatím žádní sportovci.</div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($latestAthletes as $a): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= h($a['first_name'] . ' ' . $a['last_name']) ?></span>
                        <small class="text-muted"><?= formatDate($a['created_at']) ?></small>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
