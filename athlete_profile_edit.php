<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';
require_once __DIR__ . '/includes/push_settings.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();
$stmt = $pdo->prepare('SELECT id, first_name, last_name, birth_date, phone_contact, email FROM athletes WHERE id = ? LIMIT 1');
$stmt->execute([$athleteId]);
$athlete = $stmt->fetch();

if (!$athlete) {
    session_destroy();
    redirect(BASE_URL . '/login.php');
}

$error = null;
$formData = $athlete;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $birthDate = trim((string)($_POST['birth_date'] ?? ''));
        $phone = trim((string)($_POST['phone_contact'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $formData = [
            'birth_date' => $birthDate,
            'phone_contact' => $phone,
            'email' => $email,
        ];

        $birthDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        $birthDateErrors = DateTimeImmutable::getLastErrors();
        if ($birthDate === ''
            || $birthDateObject === false
            || ($birthDateErrors !== false && ($birthDateErrors['warning_count'] > 0 || $birthDateErrors['error_count'] > 0))
            || $birthDateObject->format('Y-m-d') !== $birthDate
            || $birthDate > date('Y-m-d')
        ) {
            $error = 'Zadejte platné datum narození, které není v budoucnosti.';
        } elseif (strlen($phone) > 20) {
            $error = 'Telefonní kontakt může mít maximálně 20 znaků.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Zadejte platnou e-mailovou adresu.';
        } else {
            $updateStmt = $pdo->prepare(
                'UPDATE athletes
                 SET birth_date = ?, phone_contact = ?, email = ?
                 WHERE id = ?'
            );
            $updateStmt->execute([
                $birthDate,
                $phone !== '' ? $phone : null,
                $email !== '' ? $email : null,
                $athleteId,
            ]);
            flash('success', 'Údaje profilu byly aktualizovány.');
            redirect(BASE_URL . '/athlete_dashboard.php#athlete-dashboard-details');
        }
    }
}

renderAthleteHeader('Upravit můj profil', false, true);
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="d-flex align-items-center mb-4">
            <a href="<?= BASE_URL ?>/athlete_dashboard.php#athlete-dashboard-details" class="btn btn-outline-secondary btn-sm me-3" aria-label="Zpět na dashboard">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h2 class="mb-0"><i class="fas fa-user-edit me-2 text-warning"></i>Upravit můj profil</h2>
        </div>

        <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <p class="text-muted">Upravujete údaje profilu pro <?= h(trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])) ?>.</p>
                <form method="post">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label for="birth_date" class="form-label fw-semibold">Datum narození</label>
                        <input type="date" id="birth_date" name="birth_date" class="form-control" value="<?= h((string)($formData['birth_date'] ?? '')) ?>" max="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone_contact" class="form-label fw-semibold">Telefonní kontakt</label>
                        <input type="tel" id="phone_contact" name="phone_contact" class="form-control" value="<?= h((string)($formData['phone_contact'] ?? '')) ?>" maxlength="20" autocomplete="tel" placeholder="+420 123 456 789">
                    </div>
                    <div class="mb-4">
                        <label for="email" class="form-label fw-semibold">E-mail</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?= h((string)($formData['email'] ?? '')) ?>" maxlength="255" autocomplete="email">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning fw-semibold"><i class="fas fa-save me-1"></i>Uložit změny</button>
                        <a href="<?= BASE_URL ?>/athlete_dashboard.php#athlete-dashboard-details" class="btn btn-outline-secondary">Zrušit</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="mt-4"><?php renderPushSettings(); ?></div>
        <?php renderRememberedDevices($pdo, 'athlete', $athleteId); ?>
    </div>
</div>

<?php renderAthleteFooter(); ?>
