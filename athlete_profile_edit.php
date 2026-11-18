<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';
require_once __DIR__ . '/includes/push_settings.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();
$stmt = $pdo->prepare('SELECT id, coach_id, first_name, last_name, birth_date, phone_contact, email FROM athletes WHERE id = ? LIMIT 1');
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
    } elseif (($_POST['action'] ?? '') === 'request_account_deletion') {
        $deleteReason = trim((string)($_POST['delete_reason'] ?? ''));
        $deleteReason = $deleteReason !== ''
            ? mb_substr($deleteReason, 0, 1000)
            : 'Uživatel požádal o smazání účtu a přidružených dat bez doplňujícího textu.';
        $description = "Žádost o smazání účtu a přidružených dat.\n\n"
            . "Typ účtu: sportovec\n"
            . "Jméno: " . trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']) . "\n"
            . "E-mail: " . (string)($athlete['email'] ?? '') . "\n"
            . "ID účtu: " . (string)$athleteId . "\n"
            . "Důvod: " . $deleteReason . "\n";
        $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        try {
            $ticketStmt = $pdo->prepare(
                'INSERT INTO support_tickets (
                    reporter_type, coach_id, athlete_id, reporter_name, reporter_email,
                    subject, issue_type, description, page_url,
                    ip_address, user_agent, status, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new", NOW(), NOW())'
            );
            $ticketStmt->execute([
                'athlete',
                (int)$athlete['coach_id'],
                $athleteId,
                trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']),
                (string)($athlete['email'] ?? ''),
                'Žádost o smazání účtu',
                'Smazání účtu',
                $description,
                BASE_URL . '/athlete_profile_edit.php',
                mb_substr($ip, 0, 45),
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
            ]);
            flash('success', 'Žádost o smazání účtu byla odeslána. Správce ji zpracuje a údaje budou odstraněny v souladu s pravidly ochrany dat.');
            redirect(BASE_URL . '/athlete_profile_edit.php');
        } catch (PDOException $e) {
            error_log('Athlete account deletion request failed: ' . $e->getMessage());
            $error = 'Žádost o smazání účtu se nepodařilo odeslat. Zkuste to prosím znovu.';
        }
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
                    <input type="hidden" name="action" value="update_profile">
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
        <div class="card border-danger shadow-sm mt-4">
            <div class="card-body">
                <h3 class="h6 text-danger"><i class="fas fa-user-slash me-2"></i>Smazání účtu</h3>
                <p class="small text-muted">Systém odstraní profil, přístupová data a související záznamy v rozsahu aplikace. Zákonné účetní a evidenční záznamy mohou být uchovány podle právních povinností.</p>
                <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#athleteAccountDeletionForm" aria-expanded="false" aria-controls="athleteAccountDeletionForm">
                    Požádat o smazání účtu
                </button>
                <div class="collapse mt-3" id="athleteAccountDeletionForm">
                    <form method="post" onsubmit="return confirm('Opravdu chcete požádat o smazání svého účtu a přidružených dat? Tato akce je nevratná.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="request_account_deletion">
                        <label for="delete_reason" class="form-label fw-semibold">Důvod žádosti (volitelně)</label>
                        <textarea id="delete_reason" name="delete_reason" class="form-control mb-3" rows="2" maxlength="1000" placeholder="Volitelný důvod žádosti"></textarea>
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-user-slash me-1"></i>Odeslat žádost</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="mt-4"><?php renderPushSettings(); ?></div>
        <?php renderRememberedDevices($pdo, 'athlete', $athleteId); ?>
    </div>
</div>

<?php renderAthleteFooter(); ?>
