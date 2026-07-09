<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$athleteId = intParam($_GET, 'id');
$error     = null;

$pdo  = getDB();
$stmt = $pdo->prepare('SELECT * FROM athletes WHERE id = ? AND coach_id = ?');
$stmt->execute([$athleteId, $coachId]);
$athlete = $stmt->fetch();

if (!$athlete) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

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
            $newPhoto = saveUploadedPhoto('photo', 'athletes');
            if ($newPhoto !== null) {
                deleteUploadedPhoto($athlete['photo'] ?? null, 'athletes');
                $stmt = $pdo->prepare(
                    'UPDATE athletes SET first_name=?, last_name=?, birth_date=?, phone_contact=?, email=?, notes=?, photo=?
                     WHERE id=? AND coach_id=?'
                );
                $stmt->execute([
                    $firstName, $lastName, $birthDate, $phone ?: null, $email ?: null, $notes ?: null,
                    $newPhoto, $athleteId, $coachId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE athletes SET first_name=?, last_name=?, birth_date=?, phone_contact=?, email=?, notes=?
                     WHERE id=? AND coach_id=?'
                );
                $stmt->execute([
                    $firstName, $lastName, $birthDate, $phone ?: null, $email ?: null, $notes ?: null,
                    $athleteId, $coachId,
                ]);
            }
            flash('success', 'Údaje sportovce byly aktualizovány.');
            redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
        }
    }
}

// Pro zobrazení ve formuláři – při chybě POST data, jinak DB data
$d = ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) ? $_POST : $athlete;

renderHeader('Upravit sportovce');
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="d-flex align-items-center mb-4">
            <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $athleteId ?>"
               class="btn btn-outline-secondary btn-sm me-3">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h2 class="mb-0">
                <i class="fas fa-user-edit me-2 text-warning"></i>
                Upravit sportovce
            </h2>
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
                                   value="<?= h($d['first_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Příjmení <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control"
                                   value="<?= h($d['last_name'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Datum narození <span class="text-danger">*</span></label>
                            <input type="date" name="birth_date" class="form-control"
                                   value="<?= h($d['birth_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">Tel. kontakt</label>
                            <input type="tel" name="phone_contact" class="form-control"
                                   value="<?= h($d['phone_contact'] ?? '') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label fw-semibold">E-mail</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= h($d['email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Poznámky</label>
                        <textarea name="notes" class="form-control" rows="3"><?= h($d['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Fotografie</label>
                        <?php $currentPhoto = photoUrl($athlete['photo'] ?? null, 'athletes'); ?>
                        <?php if ($currentPhoto): ?>
                        <div class="mb-2">
                            <img src="<?= h($currentPhoto) ?>" alt="Fotografie"
                                 class="rounded" style="height:80px;object-fit:cover;">
                            <small class="text-muted ms-2">Aktuální fotografie</small>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                        <div class="form-text">Nahráním nové fotografie se předchozí nahradí.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning fw-bold px-4">
                            <i class="fas fa-save me-1"></i>Uložit změny
                        </button>
                        <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $athleteId ?>"
                           class="btn btn-outline-secondary">Zrušit</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
