<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo     = getDB();
$error   = null;

$agreementForm = [
    'title' => '',
    'body' => '',
    'approve_label' => 'Schvaluji',
    'reject_label' => 'Zamítám',
];

function sanitizeAgreementHtml(string $rawHtml): string
{
    $html = trim($rawHtml);
    if ($html === '') {
        return '';
    }

    $html = preg_replace('#<\s*(script|style)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';

    // Word často vkládá tučné písmo přes span style="font-weight:*".
    $html = preg_replace_callback(
        '#<span\b([^>]*)>(.*?)</span>#is',
        static function (array $m): string {
            $attrs = (string)($m[1] ?? '');
            $content = (string)($m[2] ?? '');
            if (preg_match('/font-weight\s*:\s*(bold|[7-9]00)/i', $attrs) === 1) {
                return '<strong>' . $content . '</strong>';
            }
            return $content;
        },
        $html
    ) ?? '';

    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><h3><h4><h5>';
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/<(\w+)(\s+[^>]*)>/u', '<$1>', $html) ?? '';
    $html = str_ireplace(['<b>', '</b>'], ['<strong>', '</strong>'], $html);

    return trim($html);
}

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

        if ($error === null && $action === 'save_athlete_agreement') {
            $agreementForm['title'] = trim((string)($_POST['agreement_title'] ?? ''));
            $agreementForm['body'] = sanitizeAgreementHtml((string)($_POST['agreement_body_html'] ?? $_POST['agreement_body'] ?? ''));
            $agreementForm['approve_label'] = trim((string)($_POST['agreement_approve_label'] ?? ''));
            $agreementForm['reject_label'] = trim((string)($_POST['agreement_reject_label'] ?? ''));
            $agreementAttachmentPath = null;
            $agreementAttachmentName = null;

            $currentAgreementStmt = $pdo->prepare(
                'SELECT id, version, title, body, approve_label, reject_label, attachment_path, attachment_name
                 FROM coach_athlete_agreements
                 WHERE coach_id = ? AND is_active = 1
                 ORDER BY version DESC, id DESC
                 LIMIT 1'
            );
            $currentAgreementStmt->execute([$coachId]);
            $currentAgreement = $currentAgreementStmt->fetch() ?: null;

            $plainBody = trim(html_entity_decode(strip_tags($agreementForm['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (!empty($_FILES['agreement_attachment']['name'])) {
                $uploadErr = (int)($_FILES['agreement_attachment']['error'] ?? UPLOAD_ERR_NO_FILE);
                $uploadSize = (int)($_FILES['agreement_attachment']['size'] ?? 0);
                $uploadTmp = (string)($_FILES['agreement_attachment']['tmp_name'] ?? '');
                $uploadOriginal = trim((string)($_FILES['agreement_attachment']['name'] ?? ''));

                if ($uploadErr !== UPLOAD_ERR_OK) {
                    $error = 'Přílohu se nepodařilo nahrát (kód chyby ' . $uploadErr . ').';
                } elseif ($uploadSize <= 0 || $uploadSize > 25 * 1024 * 1024) {
                    $error = 'Příloha může mít maximálně 25 MB.';
                } else {
                    $ext = strtolower((string)pathinfo($uploadOriginal, PATHINFO_EXTENSION));
                    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'zip', 'rar', '7z'];
                    if (!in_array($ext, $allowed, true)) {
                        $error = 'Tento typ přílohy není povolen.';
                    } else {
                        $uploadDir = __DIR__ . '/uploads/agreements/';
                        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                            $error = 'Nepodařilo se vytvořit složku pro přílohy.';
                        } else {
                            $agreementAttachmentPath = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                            if (!move_uploaded_file($uploadTmp, $uploadDir . $agreementAttachmentPath)) {
                                $error = 'Přílohu se nepodařilo uložit.';
                                $agreementAttachmentPath = null;
                            } else {
                                $agreementAttachmentName = mb_substr($uploadOriginal, 0, 255, 'UTF-8');
                            }
                        }
                    }
                }
            }

            if ($error === null && ($agreementForm['title'] === '' || $plainBody === '')) {
                $error = 'Vyplňte název i text dohody s trenérem.';
            } elseif ($error === null && ($agreementForm['approve_label'] === '' || $agreementForm['reject_label'] === '')) {
                $error = 'Vyplňte text obou akčních tlačítek.';
            } elseif ($error === null && mb_strtolower($agreementForm['approve_label'], 'UTF-8') === mb_strtolower($agreementForm['reject_label'], 'UTF-8')) {
                $error = 'Akční tlačítka musí mít odlišné texty.';
            } elseif ($error === null) {
                $agreementForm['title'] = mb_substr($agreementForm['title'], 0, 255, 'UTF-8');
                $agreementForm['body'] = mb_substr($agreementForm['body'], 0, 12000, 'UTF-8');
                $agreementForm['approve_label'] = mb_substr($agreementForm['approve_label'], 0, 80, 'UTF-8');
                $agreementForm['reject_label'] = mb_substr($agreementForm['reject_label'], 0, 80, 'UTF-8');

                if ($currentAgreement !== null && $agreementAttachmentPath === null) {
                    $agreementAttachmentPath = (string)($currentAgreement['attachment_path'] ?? '');
                    $agreementAttachmentName = (string)($currentAgreement['attachment_name'] ?? '');
                }

                $hasTextChanges = $currentAgreement === null
                    || $agreementForm['title'] !== (string)($currentAgreement['title'] ?? '')
                    || $agreementForm['body'] !== (string)($currentAgreement['body'] ?? '')
                    || $agreementForm['approve_label'] !== (string)($currentAgreement['approve_label'] ?? '')
                    || $agreementForm['reject_label'] !== (string)($currentAgreement['reject_label'] ?? '');
                $hasAttachmentChanges = $agreementAttachmentPath !== (string)($currentAgreement['attachment_path'] ?? '')
                    || $agreementAttachmentName !== (string)($currentAgreement['attachment_name'] ?? '');

                if ($currentAgreement !== null && !$hasTextChanges && !$hasAttachmentChanges) {
                    flash('info', 'Nedošlo ke změně textu ani přílohy. Verze dohody zůstává beze změny.');
                    redirect(BASE_URL . '/profile.php');
                }

                try {
                    $pdo->beginTransaction();

                    $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM coach_athlete_agreements WHERE coach_id = ?');
                    $versionStmt->execute([$coachId]);
                    $nextVersion = ((int)$versionStmt->fetchColumn()) + 1;

                    if ($currentAgreement !== null) {
                        $pdo->prepare('UPDATE coach_athlete_agreements SET is_active = 0 WHERE id = ? AND coach_id = ?')
                            ->execute([(int)$currentAgreement['id'], $coachId]);
                    }

                    $insertAgreement = $pdo->prepare(
                        'INSERT INTO coach_athlete_agreements (coach_id, version, title, body, approve_label, reject_label, attachment_path, attachment_name, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
                    );
                    $insertAgreement->execute([
                        $coachId,
                        $nextVersion,
                        $agreementForm['title'],
                        $agreementForm['body'],
                        $agreementForm['approve_label'],
                        $agreementForm['reject_label'],
                        $agreementAttachmentPath,
                        $agreementAttachmentName,
                    ]);

                    $athletesStmt = $pdo->prepare('SELECT id FROM athletes WHERE coach_id = ?');
                    $athletesStmt->execute([$coachId]);
                    $athleteIds = array_map(static fn(array $row): int => (int)$row['id'], $athletesStmt->fetchAll());

                    $notificationStmt = $pdo->prepare(
                        'INSERT INTO athlete_notifications (athlete_id, subject, body) VALUES (?, ?, ?)'
                    );

                    $subject = 'Nová dohoda s trenérem';
                    $body = "Trenér zveřejnil novou dohodu. Otevřete sekci Podmínky > Dohoda s trenérem a zvolte jednu z akcí.";
                    foreach ($athleteIds as $athleteId) {
                        $notificationStmt->execute([$athleteId, $subject, $body]);
                    }

                    $pdo->commit();

                    $suffix = $nextVersion > 1 ? ' (nová verze v' . $nextVersion . ')' : '';
                    flash('success', 'Dohoda byla uložena a odeslána ' . count($athleteIds) . ' sportovcům.' . $suffix);
                    redirect(BASE_URL . '/profile.php');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Dohodu se nepodařilo uložit. Zkuste to prosím znovu.';
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

$activeAgreementStmt = $pdo->prepare(
    'SELECT id, version, title, body, approve_label, reject_label, attachment_path, attachment_name, created_at
     FROM coach_athlete_agreements
     WHERE coach_id = ? AND is_active = 1
     ORDER BY version DESC, id DESC
     LIMIT 1'
);
$activeAgreementStmt->execute([$coachId]);
$activeAgreement = $activeAgreementStmt->fetch() ?: null;

if ($activeAgreement !== null) {
    if ($agreementForm['title'] === '') {
        $agreementForm['title'] = (string)($activeAgreement['title'] ?? '');
    }
    if ($agreementForm['body'] === '') {
        $agreementForm['body'] = (string)($activeAgreement['body'] ?? '');
    }
    if ($agreementForm['approve_label'] === 'Schvaluji') {
        $agreementForm['approve_label'] = (string)($activeAgreement['approve_label'] ?? 'Schvaluji');
    }
    if ($agreementForm['reject_label'] === 'Zamítám') {
        $agreementForm['reject_label'] = (string)($activeAgreement['reject_label'] ?? 'Zamítám');
    }
}

$agreementResponses = [];
$agreementStats = ['approved' => 0, 'rejected' => 0, 'pending' => 0, 'total' => 0];
if ($activeAgreement !== null) {
    $responsesStmt = $pdo->prepare(
        "SELECT a.id,
                a.first_name,
                a.last_name,
                                r.response AS current_response,
                                r.responded_at AS current_responded_at,
                                (
                                        SELECT ca2.version
                                        FROM coach_athlete_agreement_responses r2
                                        JOIN coach_athlete_agreements ca2 ON ca2.id = r2.agreement_id
                                        WHERE ca2.coach_id = ?
                                            AND r2.athlete_id = a.id
                                        ORDER BY ca2.version DESC, ca2.id DESC, r2.responded_at DESC
                                        LIMIT 1
                                ) AS last_version,
                                (
                                        SELECT r2.response
                                        FROM coach_athlete_agreement_responses r2
                                        JOIN coach_athlete_agreements ca2 ON ca2.id = r2.agreement_id
                                        WHERE ca2.coach_id = ?
                                            AND r2.athlete_id = a.id
                                        ORDER BY ca2.version DESC, ca2.id DESC, r2.responded_at DESC
                                        LIMIT 1
                                ) AS last_response,
                                (
                                        SELECT r2.responded_at
                                        FROM coach_athlete_agreement_responses r2
                                        JOIN coach_athlete_agreements ca2 ON ca2.id = r2.agreement_id
                                        WHERE ca2.coach_id = ?
                                            AND r2.athlete_id = a.id
                                        ORDER BY ca2.version DESC, ca2.id DESC, r2.responded_at DESC
                                        LIMIT 1
                                ) AS last_responded_at
         FROM athletes a
         LEFT JOIN coach_athlete_agreement_responses r
           ON r.athlete_id = a.id
          AND r.agreement_id = ?
         WHERE a.coach_id = ?
         ORDER BY a.first_name ASC, a.last_name ASC, a.id ASC"
    );
        $responsesStmt->execute([$coachId, $coachId, $coachId, (int)$activeAgreement['id'], $coachId]);
    $agreementResponses = $responsesStmt->fetchAll();

    $agreementStats['total'] = count($agreementResponses);
    foreach ($agreementResponses as $responseRow) {
        $responseValue = (string)($responseRow['current_response'] ?? '');
        if ($responseValue === 'approved') {
            $agreementStats['approved']++;
        } elseif ($responseValue === 'rejected') {
            $agreementStats['rejected']++;
        } else {
            $agreementStats['pending']++;
        }
    }
}

renderHeader('Můj profil');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <h2 class="mb-0"><i class="fas fa-user-circle me-2 text-warning"></i>Můj profil</h2>
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
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-id-card me-2"></i>Základní údaje
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" novalidate>
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

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-file-signature me-2"></i>Dohoda s trenérem
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_athlete_agreement">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Název dohody</label>
                        <input type="text" name="agreement_title" class="form-control" maxlength="255" required value="<?= h($agreementForm['title']) ?>" placeholder="Např. Dohoda o storno podmínkách">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Text dohody</label>
                        <div class="btn-group btn-group-sm mb-2" role="group" aria-label="Formátování dohody">
                            <button type="button" class="btn btn-outline-secondary" data-agreement-cmd="bold" title="Tučné"><i class="fas fa-bold"></i></button>
                            <button type="button" class="btn btn-outline-secondary" data-agreement-cmd="insertUnorderedList" title="Odrážky"><i class="fas fa-list-ul"></i></button>
                            <button type="button" class="btn btn-outline-secondary" data-agreement-cmd="insertOrderedList" title="Číslování"><i class="fas fa-list-ol"></i></button>
                        </div>
                        <div id="agreementEditor" class="form-control" contenteditable="true" style="min-height:220px;white-space:normal;overflow:auto"><?= $agreementForm['body'] ?></div>
                        <input type="hidden" name="agreement_body_html" id="agreementBodyHtml" value="<?= h($agreementForm['body']) ?>">
                        <div class="form-text">Můžete vložit text z Wordu. Zachová se běžné formátování (např. tučné).</div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Text tlačítka schválení</label>
                            <input type="text" name="agreement_approve_label" class="form-control" maxlength="80" required value="<?= h($agreementForm['approve_label']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Text tlačítka zamítnutí</label>
                            <input type="text" name="agreement_reject_label" class="form-control" maxlength="80" required value="<?= h($agreementForm['reject_label']) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Příloha ke stažení (volitelné)</label>
                        <input type="file" name="agreement_attachment" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png,.webp,.zip,.rar,.7z">
                        <?php if (!empty($activeAgreement['attachment_name'])): ?>
                        <div class="form-text mt-2">
                            Aktuální příloha: <a href="<?= BASE_URL ?>/agreement_attachment.php?agreement_id=<?= (int)$activeAgreement['id'] ?>" target="_blank" rel="noopener noreferrer"><?= h((string)$activeAgreement['attachment_name']) ?></a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="fas fa-paper-plane me-1"></i>Uložit a odeslat sportovcům
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

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="fas fa-clipboard-check me-2"></i>Reakce sportovců na dohodu</span>
                <?php if ($activeAgreement): ?>
                <small class="text-white-50">Aktivní verze v<?= (int)$activeAgreement['version'] ?> od <?= formatDateTime((string)$activeAgreement['created_at']) ?></small>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (!$activeAgreement): ?>
                <div class="p-3 text-muted">Zatím nemáte vytvořenou aktivní dohodu.</div>
                <?php elseif (empty($agreementResponses)): ?>
                <div class="p-3 text-muted">Nemáte žádné sportovce.</div>
                <?php else: ?>
                <div class="p-3 border-bottom">
                    <span class="badge bg-success me-1">Schváleno: <?= (int)$agreementStats['approved'] ?></span>
                    <span class="badge bg-danger me-1">Zamítnuto: <?= (int)$agreementStats['rejected'] ?></span>
                    <span class="badge bg-secondary">Čeká: <?= (int)$agreementStats['pending'] ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Sportovec</th>
                            <th>Stav</th>
                            <th>Reagoval</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($agreementResponses as $row): ?>
                        <?php
                        $status = (string)($row['current_response'] ?? '');
                        $statusBadge = 'bg-secondary';
                        $statusLabel = 'Bez reakce';
                        $statusNote = '';
                        if ($status === 'approved') {
                            $statusBadge = 'bg-success';
                            $statusLabel = 'Schváleno';
                        } elseif ($status === 'rejected') {
                            $statusBadge = 'bg-danger';
                            $statusLabel = 'Zamítnuto';
                        } elseif (!empty($row['last_version'])) {
                            $statusBadge = 'bg-warning text-dark';
                            $statusLabel = 'Čeká (nová verze)';
                            $lastResponse = (string)($row['last_response'] ?? '');
                            $lastResponseLabel = $lastResponse === 'rejected' ? 'Zamítnuto' : 'Schváleno';
                            $statusNote = $lastResponseLabel . ' u verze v' . (int)$row['last_version'];
                        }
                        ?>
                        <tr>
                            <td>
                                <?= h(trim((string)$row['first_name'] . ' ' . (string)$row['last_name'])) ?>
                                <?php if ($statusNote !== ''): ?>
                                <div class="small text-muted mt-1"><?= h($statusNote) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= $statusBadge ?>"><?= $statusLabel ?></span></td>
                            <td class="text-nowrap"><?= !empty($row['current_responded_at']) ? formatDateTime((string)$row['current_responded_at']) : '–' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php renderFooter(); ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const editor = document.getElementById('agreementEditor');
    const hidden = document.getElementById('agreementBodyHtml');
    if (!editor || !hidden) {
        return;
    }

    const syncAgreementHtml = function () {
        hidden.value = editor.innerHTML;
    };

    syncAgreementHtml();
    editor.addEventListener('input', syncAgreementHtml);
    editor.closest('form')?.addEventListener('submit', syncAgreementHtml);

    document.querySelectorAll('[data-agreement-cmd]').forEach((btn) => {
        btn.addEventListener('click', function () {
            const cmd = this.getAttribute('data-agreement-cmd');
            if (!cmd) {
                return;
            }
            editor.focus();
            document.execCommand(cmd, false);
            syncAgreementHtml();
        });
    });
});
</script>
