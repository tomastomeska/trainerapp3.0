<?php
// admin/coach_add.php - pridani noveho trenera
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo   = getDB();
$error = null;

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

function coachUsernameFromNameEmail(string $name, string $email): string {
    $base = '';
    if ($email !== '' && str_contains($email, '@')) {
        $base = explode('@', $email, 2)[0];
    }
    if ($base === '') {
        $base = $name;
    }
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9_.\-]+/', '.', $base) ?? '';
    $base = trim($base, '.-_');
    if ($base === '') {
        $base = 'trener';
    }
    if (strlen($base) < 3) {
        $base .= '_coach';
    }
    return substr($base, 0, 50);
}

function coachEmailAlreadyUsed(PDO $pdo, string $email): bool {
    $stmtCoach = $pdo->prepare('SELECT id FROM coaches WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmtCoach->execute([$email]);
    if ($stmtCoach->fetch()) {
        return true;
    }

    $stmtAthlete = $pdo->prepare('SELECT id FROM athletes WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmtAthlete->execute([$email]);
    return (bool)$stmtAthlete->fetch();
}

$sourceTicketId = intParam($_GET, 'from_ticket_id');
$prefillName = trim((string)($_GET['name'] ?? ''));
$prefillEmail = trim((string)($_GET['email'] ?? ''));
$prefillUsername = trim((string)($_GET['username'] ?? ''));
if ($prefillUsername === '') {
    $prefillUsername = coachUsernameFromNameEmail($prefillName, $prefillEmail);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatny bezpecnostni token.';
    } else {
        $username  = trim($_POST['username'] ?? '');
        $name      = trim($_POST['name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $makeupDeadlineDaysRaw = trim((string)($_POST['makeup_booking_deadline_days'] ?? ''));
        $makeupDeadlineDays = 14;
        $sourceTicketId = intParam($_POST, 'source_ticket_id');

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
            $error = 'Zadejte uzivatelske jmeno.';
        } elseif (!preg_match('/^[a-z0-9_.\-]{3,50}$/i', $username)) {
            $error = 'Uzivatelske jmeno smi obsahovat jen pismena, cislice, tecku, pomlcku a podtrzitko (3-50 znaku).';
        } elseif (strlen($password) < 6) {
            $error = 'Heslo musi mit alespon 6 znaku.';
        } elseif ($password !== $password2) {
            $error = 'Hesla se neshoduji.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Neplatna e-mailova adresa.';
        } elseif ($email !== '' && coachEmailAlreadyUsed($pdo, $email)) {
            $error = 'Tento e-mail je již v systému registrovaný.';
        } else {
            // Unikatnost uzivatelskeho jmena
            $stmt = $pdo->prepare('SELECT id FROM coaches WHERE username = ?');
            $stmt->execute([$username]);

            if ($stmt->fetch()) {
                $error = 'Toto uzivatelske jmeno je jiz obsazeno.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($hasMakeupDeadlineColumn) {
                    $pdo->prepare(
                        'INSERT INTO coaches (username, password, name, email, is_active, force_password_change, makeup_booking_deadline_days) VALUES (?, ?, ?, ?, ?, 1, ?)'
                    )->execute([$username, $hash, $name ?: null, $email ?: null, $isActive, $makeupDeadlineDays]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO coaches (username, password, name, email, is_active, force_password_change) VALUES (?, ?, ?, ?, ?, 1)'
                    )->execute([$username, $hash, $name ?: null, $email ?: null, $isActive]);
                }
                $newCoachId = (int)$pdo->lastInsertId();

                if ($sourceTicketId > 0) {
                    $admin = getCurrentAdmin();
                    $adminName = (string)($admin['name'] ?? $admin['username'] ?? 'Administrátor');
                    $noteLine = '[' . date('d.m.Y H:i') . ' | Schváleno | ' . $adminName . "]\n"
                        . 'Žádost schválena. Vytvořen trenér ID #' . $newCoachId . ' (username: ' . $username . ').';

                    $ticketStmt = $pdo->prepare('SELECT admin_note FROM support_tickets WHERE id = ? LIMIT 1');
                    $ticketStmt->execute([$sourceTicketId]);
                    $ticket = $ticketStmt->fetch();
                    if ($ticket) {
                        $existing = trim((string)($ticket['admin_note'] ?? ''));
                        $merged = $existing === '' ? $noteLine : ($existing . "\n\n" . $noteLine);
                        $upd = $pdo->prepare('UPDATE support_tickets SET status = "resolved", admin_note = ?, updated_at = NOW() WHERE id = ?');
                        $upd->execute([$merged, $sourceTicketId]);
                    }
                }

                $pdo->prepare(
                    'INSERT INTO coach_meals (
                        coach_id,
                        global_meal_id,
                        name,
                        description,
                        grams,
                        meal_type,
                        fat_per_100g,
                        sugars_per_100g,
                        protein_per_100g,
                        fiber_per_100g,
                        salt_per_100g,
                        photo
                     )
                     SELECT ?,
                            g.id,
                            g.name,
                            g.description,
                            g.grams,
                            g.meal_type,
                            g.fat_per_100g,
                            g.sugars_per_100g,
                            g.protein_per_100g,
                            g.fiber_per_100g,
                            g.salt_per_100g,
                            g.photo
                     FROM global_meals g'
                )->execute([$newCoachId]);

                // Odeslani prihlasovacich udaju e-mailem (pokud je zadany e-mail)
                $mailInfo = null;
                if ($email !== '') {
                    $isHttps  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
                    $scheme   = $isHttps ? 'https' : 'http';
                    $host     = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
                    $loginUrl = $scheme . '://' . $host . BASE_URL . '/login.php';

                    $sent = sendCoachWelcomeEmail($email, $username, $password, $loginUrl);

                    if ($sent) {
                        $mailInfo = ' Přihlašovací údaje byly odeslány na e-mail.';
                    } else {
                        // E-mail se nepodařilo odeslat – zobrazit přihlašovací údaje adminovi
                        $mailInfo = ' E-mail se nepodařilo odeslat. Předejte trenérovi údaje ručně:'
                            . ' Jméno: <strong>' . h($username) . '</strong>'
                            . ' | Heslo: <strong>' . h($password) . '</strong>'
                            . ' | URL: <a href="' . h($loginUrl) . '" target="_blank">' . h($loginUrl) . '</a>';
                    }
                }

                flash('success', 'Trenér ' . h($username) . ' byl úspěšně přidán.' . ($mailInfo ?? ''));
                redirect(BASE_URL . '/admin/coaches.php');
            }
        }
    }
}

renderAdminHeader('Pridat trenera');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0">
        <i class="fas fa-user-plus me-2" style="color:#a78bfa"></i>Pridat trenera
    </h4>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-body p-4">
        <form method="post" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="source_ticket_id" value="<?= (int)$sourceTicketId ?>">
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Jmeno trenera</label>
                    <input type="text" name="name" class="form-control"
                           value="<?= h($_POST['name'] ?? ($prefillName ?? '')) ?>"
                           placeholder="Jan Novak">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">
                        Uzivatelske jmeno <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="username" class="form-control"
                           value="<?= h($_POST['username'] ?? ($prefillUsername ?? '')) ?>"
                           required autofocus autocomplete="off">
                    <div class="form-text">3-50 znaku: pismena, cislice, . - _</div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">E-mail</label>
                <input type="email" name="email" class="form-control"
                       value="<?= h($_POST['email'] ?? ($prefillEmail ?? '')) ?>"
                       placeholder="trener@example.com">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">
                        Heslo <span class="text-danger">*</span>
                        <small class="text-muted fw-normal">(min. 6 znaku)</small>
                    </label>
                    <input type="password" name="password" class="form-control"
                           required autocomplete="new-password">
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">
                        Heslo znovu <span class="text-danger">*</span>
                    </label>
                    <input type="password" name="password2" class="form-control"
                           required autocomplete="new-password">
                </div>
            </div>
            <div class="mb-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active"
                           id="isActive" value="1"
                           <?= (!isset($_POST['is_active']) || $_POST['is_active']) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="isActive">
                        Trener je aktivni (muze se prihlasit)
                    </label>
                </div>
            </div>
            <?php if ($hasMakeupDeadlineColumn): ?>
            <div class="mb-4">
                <label class="form-label fw-semibold">Lhuta pro vyber nahradniho terminu (dny)</label>
                <input type="number" name="makeup_booking_deadline_days" class="form-control"
                       min="1" max="365" step="1"
                       value="<?= h($_POST['makeup_booking_deadline_days'] ?? '14') ?>"
                       placeholder="napr. 14">
                <div class="form-text">Vychozi hodnota je 14 dni. Sportovec musi do teto lhuty vytvorit nahradni rezervaci po zruseni terminu.</div>
            </div>
            <?php endif; ?>
            <div class="d-flex gap-2">
                <button type="submit" class="btn fw-bold px-4"
                        style="background:#7c3aed;color:#fff;border:none">
                    <i class="fas fa-save me-1"></i>Vytvorit trenera
                </button>
                <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary">Zrusit</a>
            </div>
        </form>
    </div>
</div>

<?php renderAdminFooter(); ?>