<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin(false);

$athleteId = (int)getCurrentAthleteId();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newPassword2 = (string)($_POST['new_password_confirm'] ?? '');

        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT password FROM athletes WHERE id = ? LIMIT 1');
        $stmt->execute([$athleteId]);
        $row = $stmt->fetch();

        if (!$row || empty($row['password']) || !password_verify($currentPassword, (string)$row['password'])) {
            $error = 'Aktuální heslo není správně.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Nové heslo musí mít alespoň 8 znaků.';
        } elseif ($newPassword !== $newPassword2) {
            $error = 'Nová hesla se neshodují.';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $upd = $pdo->prepare('UPDATE athletes SET password = ?, force_password_change = 0 WHERE id = ?');
            $upd->execute([$hash, $athleteId]);

            $_SESSION['athlete_force_password_change'] = 0;
            flash('success', 'Heslo bylo úspěšně změněno.');
            redirect(BASE_URL . '/athlete_dashboard.php');
        }
    }
}

renderAthleteHeader('Změna hesla', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-key me-2 text-warning"></i>Změna hesla</h2>
    <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-house me-1"></i>Domů
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-key me-2"></i>Změna hesla
            </div>
            <div class="card-body">
                <p class="text-muted">Při prvním přihlášení je potřeba nastavit nové heslo.</p>

                <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Aktuální heslo</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nové heslo</label>
                        <input type="password" name="new_password" class="form-control" minlength="8" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Potvrzení nového hesla</label>
                        <input type="password" name="new_password_confirm" class="form-control" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-warning fw-bold w-100">Uložit nové heslo</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php renderAthleteFooter();
