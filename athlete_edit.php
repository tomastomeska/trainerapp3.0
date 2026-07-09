<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId   = getCurrentCoachId();
$athleteId = intParam($_GET, 'id');
$error     = null;

$defaultReturnUrl = BASE_URL . '/athlete_detail.php?id=' . $athleteId;
$returnToRaw = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
$returnTo = $defaultReturnUrl;
if ($returnToRaw !== '' && str_starts_with($returnToRaw, BASE_URL . '/')) {
    $returnTo = $returnToRaw;
}

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
        $trainingRateRaw = trim($_POST['training_rate'] ?? '');
        $pairedTrainingRateRaw = trim($_POST['paired_training_rate'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        $trainingRate = null;
        $pairedTrainingRate = null;

        if ($trainingRateRaw !== '') {
            $normalizedRate = str_replace(',', '.', $trainingRateRaw);
            if (!is_numeric($normalizedRate) || (float)$normalizedRate < 0) {
                $error = 'Zadejte platnou sazbu za trénink.';
            } else {
                $trainingRate = number_format((float)$normalizedRate, 2, '.', '');
            }
        }

        if ($error === null && $pairedTrainingRateRaw !== '') {
            $normalizedPairedRate = str_replace(',', '.', $pairedTrainingRateRaw);
            if (!is_numeric($normalizedPairedRate) || (float)$normalizedPairedRate < 0) {
                $error = 'Zadejte platnou sazbu za párový trénink.';
            } else {
                $pairedTrainingRate = number_format((float)$normalizedPairedRate, 2, '.', '');
            }
        }

        if ($error === null && ($firstName === '' || $lastName === '')) {
            $error = 'Vyplňte jméno a příjmení.';
        } elseif ($error === null && $birthDate === '') {
            $error = 'Zadejte datum narození.';
        } elseif ($error === null && !DateTime::createFromFormat('Y-m-d', $birthDate)) {
            $error = 'Zadejte platné datum narození.';
        } elseif ($error === null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Zadejte platnou e-mailovou adresu.';
        } else {
            $newPhoto = saveUploadedPhoto('photo', 'athletes');
            if ($newPhoto !== null) {
                deleteUploadedPhoto($athlete['photo'] ?? null, 'athletes');
                $stmt = $pdo->prepare(
                    'UPDATE athletes SET first_name=?, last_name=?, birth_date=?, phone_contact=?, email=?, training_rate=?, paired_training_rate=?, notes=?, photo=?
                     WHERE id=? AND coach_id=?'
                );
                $stmt->execute([
                    $firstName, $lastName, $birthDate, $phone ?: null, $email ?: null, $trainingRate, $pairedTrainingRate, $notes ?: null,
                    $newPhoto, $athleteId, $coachId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE athletes SET first_name=?, last_name=?, birth_date=?, phone_contact=?, email=?, training_rate=?, paired_training_rate=?, notes=?
                     WHERE id=? AND coach_id=?'
                );
                $stmt->execute([
                    $firstName, $lastName, $birthDate, $phone ?: null, $email ?: null, $trainingRate, $pairedTrainingRate, $notes ?: null,
                    $athleteId, $coachId,
                ]);
            }
            flash('success', 'Údaje sportovce byly aktualizovány.');
            redirect($returnTo);
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
            <a href="<?= h($returnTo) ?>"
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
                    <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
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
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sazba za trénink</label>
                        <div class="input-group">
                            <input type="number" name="training_rate" class="form-control"
                                   value="<?= h($d['training_rate'] ?? '') ?>"
                                   min="0" step="0.01" placeholder="Např. 750">
                            <span class="input-group-text">Kč</span>
                        </div>
                        <div class="form-text">Částka se používá na stránce Platby pro měsíční výpočet.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sazba za párový trénink</label>
                        <div class="input-group">
                            <input type="number" name="paired_training_rate" class="form-control"
                                   value="<?= h($d['paired_training_rate'] ?? '') ?>"
                                   min="0" step="0.01" placeholder="Např. 450">
                            <span class="input-group-text">Kč</span>
                        </div>
                        <div class="form-text">Volitelné. Pokud je prázdné, používá se základní sazba za trénink.</div>
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
                        <a href="<?= h($returnTo) ?>"
                           class="btn btn-outline-secondary">Zrušit</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
