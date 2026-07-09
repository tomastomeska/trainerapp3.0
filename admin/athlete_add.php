<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $birthDate = trim($_POST['birth_date'] ?? '');
        $phone     = trim($_POST['phone_contact'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');

        if ($firstName === '' || $lastName === '') {
            $error = 'Vyplňte jméno a příjmení.';
        } elseif ($birthDate === '') {
            $error = 'Zadejte datum narození.';
        } elseif (!DateTime::createFromFormat('Y-m-d', $birthDate)) {
            $error = 'Zadejte platné datum narození.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Zadejte platnou e-mailovou adresu.';
        } else {
            $pdo  = getDB();
            $photo = saveUploadedPhoto('photo', 'athletes');
            $stmt = $pdo->prepare(
                'INSERT INTO athletes (coach_id, first_name, last_name, birth_date, phone_contact, email, notes, photo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $coachId,
                $firstName,
                $lastName,
                $birthDate,
                $phone ?: null,
                $email ?: null,
                $notes ?: null,
                $photo,
            ]);
            $newAthleteId = (int)$pdo->lastInsertId();
            try {
                $pdo->prepare("INSERT INTO gallery_folders (coach_id, name, folder_type, athlete_id) VALUES (?, ?, 'athlete', ?)")
                    ->execute([$coachId, $firstName . ' ' . $lastName, $newAthleteId]);
            } catch (Throwable $e) {
                error_log('gallery_folder auto-create error (admin/athlete_add): ' . $e->getMessage());
            }
            flash('success', "Sportovec {$firstName} {$lastName} byl přidán.");
            redirect(BASE_URL . '/dashboard.php');
        }
    }
}

renderHeader('Přidat sportovce');
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="d-flex align-items-center mb-4">
            <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm me-3">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h2 class="mb-0"><i class="fas fa-user-plus me-2 text-warning"></i>Přidat sportovce</h2>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="post" enctype="multipart/form-data" novalidate>
                    <?= csrfField() ?>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Jméno <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control"
                                   value="<?= h($_POST['first_name'] ?? '') ?>" required autofocus>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Příjmení <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control"
                                   value="<?= h($_POST['last_name'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Datum narození <span class="text-danger">*</span></label>
                            <input type="date" name="birth_date" class="form-control"
                                   value="<?= h($_POST['birth_date'] ?? '') ?>"
                                   max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Tel. kontakt</label>
                            <input type="tel" name="phone_contact" class="form-control"
                                   value="<?= h($_POST['phone_contact'] ?? '') ?>"
                                   placeholder="+420 123 456 789">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">E-mail</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= h($_POST['email'] ?? '') ?>"
                                   placeholder="sportovec@example.com">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Poznámky</label>
                        <textarea name="notes" class="form-control" rows="3"
                                  placeholder="Volitelné poznámky o sportovci..."><?= h($_POST['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Fotografie <span class="text-muted fw-normal">(nepovinné)</span></label>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning fw-bold px-4">
                            <i class="fas fa-save me-1"></i>Uložit
                        </button>
                        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary">Zrušit</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
