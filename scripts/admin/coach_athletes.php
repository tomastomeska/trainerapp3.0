<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$coachId = intParam($_GET, 'coach_id');

if ($coachId <= 0) {
    flash('danger', 'Neplatný trenér.');
    redirect(BASE_URL . '/admin/coaches.php');
}

$coachStmt = $pdo->prepare('SELECT id, name, username, email, is_active, created_at, last_login FROM coaches WHERE id = ? LIMIT 1');
$coachStmt->execute([$coachId]);
$coach = $coachStmt->fetch();

if (!$coach) {
    flash('danger', 'Trenér nebyl nalezen.');
    redirect(BASE_URL . '/admin/coaches.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/coach_athletes.php?coach_id=' . $coachId);
    }

    $athleteId = intParam($_POST, 'athlete_id');
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));

    if ($athleteId <= 0) {
        $errors[] = 'Neplatný příjemce zprávy.';
    }
    if ($subject === '') {
        $errors[] = 'Předmět zprávy nesmí být prázdný.';
    }
    if ($body === '') {
        $errors[] = 'Text zprávy nesmí být prázdný.';
    }

    $athleteCheck = null;
    if (empty($errors)) {
        $athleteCheckStmt = $pdo->prepare('SELECT id, first_name, last_name FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
        $athleteCheckStmt->execute([$athleteId, $coachId]);
        $athleteCheck = $athleteCheckStmt->fetch();

        if (!$athleteCheck) {
            $errors[] = 'Sportovec nepatří vybranému trenérovi.';
        }
    }

    if (empty($errors)) {
        $subjectForAthlete = '[Admin] ' . $subject;
        $insertStmt = $pdo->prepare('INSERT INTO athlete_notifications (athlete_id, subject, body) VALUES (?, ?, ?)');
        $insertStmt->execute([$athleteId, $subjectForAthlete, $body]);

        $athleteName = trim((string)$athleteCheck['first_name'] . ' ' . (string)$athleteCheck['last_name']);
        flash('success', 'Zpráva od admina byla odeslána sportovci ' . h($athleteName) . '.');
        redirect(BASE_URL . '/admin/coach_athletes.php?coach_id=' . $coachId);
    }
}

$athletesStmt = $pdo->prepare(
    'SELECT a.*,
            (SELECT COUNT(*)
             FROM training_sessions ts
             WHERE ts.athlete_id = a.id
               AND ts.completed_at IS NOT NULL
               AND ts.deleted_by_coach_at IS NULL) AS completed_sessions,
            (SELECT COUNT(*)
             FROM training_sessions ts
             WHERE ts.athlete_id = a.id
               AND ts.deleted_by_coach_at IS NOT NULL) AS deleted_sessions,
            (SELECT MAX(ts.completed_at)
             FROM training_sessions ts
             WHERE ts.athlete_id = a.id
               AND ts.completed_at IS NOT NULL
               AND ts.deleted_by_coach_at IS NULL) AS last_completed_at
     FROM athletes a
     WHERE a.coach_id = ?
     ORDER BY a.last_name ASC, a.first_name ASC, a.id ASC'
);
$athletesStmt->execute([$coachId]);
$athletes = $athletesStmt->fetchAll();

$getField = static function (array $row, string $key) {
    return array_key_exists($key, $row) ? $row[$key] : null;
};

renderAdminHeader('Sportovci trenéra');
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h4 class="fw-bold mb-0">
            <i class="fas fa-users me-2 text-warning"></i>
            Sportovci trenéra <?= h((string)($coach['name'] ?: $coach['username'])) ?>
        </h4>
    </div>
    <div class="small text-muted">
        Trenér: @<?= h((string)$coach['username']) ?>
        <?php if (!empty($coach['email'])): ?>
            · <a href="mailto:<?= h((string)$coach['email']) ?>"><?= h((string)$coach['email']) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
            <li><?= h($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($athletes)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-user-slash fa-2x mb-3 d-block"></i>
                Trenér zatím nemá žádné sportovce.
            </div>
        <?php else: ?>
            <div class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                <strong>Celkem sportovců: <?= count($athletes) ?></strong>
                <span class="text-muted small">Kompletní přehled + možnost poslat zprávu jako admin</span>
            </div>

            <?php foreach ($athletes as $athlete): ?>
                <?php
                $athleteId = (int)$athlete['id'];
                $fullName = trim((string)$getField($athlete, 'first_name') . ' ' . (string)$getField($athlete, 'last_name'));
                $birthDate = (string)($getField($athlete, 'birth_date') ?? '');
                $age = calculateAge($birthDate !== '' ? $birthDate : null);
                $email = (string)($getField($athlete, 'email') ?? '');
                $phone = (string)($getField($athlete, 'phone_contact') ?? '');
                $notes = (string)($getField($athlete, 'notes') ?? '');
                $trainingRate = $getField($athlete, 'training_rate');
                $pairedRate = $getField($athlete, 'paired_training_rate');
                $lastLogin = (string)($getField($athlete, 'last_login') ?? '');
                $createdAt = (string)($getField($athlete, 'created_at') ?? '');
                ?>
                <div class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <div class="fw-bold fs-5"><?= h($fullName !== '' ? $fullName : ('Sportovec #' . $athleteId)) ?></div>
                            <div class="small text-muted">ID #<?= $athleteId ?></div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge bg-warning text-dark">Dokončené tréninky: <?= (int)$athlete['completed_sessions'] ?></span>
                            <span class="badge bg-danger">Smazané: <?= (int)$athlete['deleted_sessions'] ?></span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-7">
                            <table class="table table-sm table-borderless mb-0">
                                <tr>
                                    <td class="text-muted" style="width:180px">Datum narození</td>
                                    <td><?= $birthDate !== '' ? formatDate($birthDate) : '–' ?><?= $age !== null ? ' (' . (int)$age . ' let)' : '' ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">E-mail</td>
                                    <td>
                                        <?php if ($email !== ''): ?>
                                            <a href="mailto:<?= h($email) ?>"><?= h($email) ?></a>
                                        <?php else: ?>
                                            –
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Tel. kontakt</td>
                                    <td><?= $phone !== '' ? h($phone) : '–' ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Cena tréninku</td>
                                    <td>
                                        <?php if ($trainingRate !== null && $trainingRate !== ''): ?>
                                            <?= h(number_format((float)$trainingRate, 0, ',', ' ')) ?> Kč
                                        <?php else: ?>
                                            –
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Cena párového tréninku</td>
                                    <td>
                                        <?php if ($pairedRate !== null && $pairedRate !== ''): ?>
                                            <?= h(number_format((float)$pairedRate, 0, ',', ' ')) ?> Kč
                                        <?php else: ?>
                                            –
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Poslední přihlášení</td>
                                    <td><?= $lastLogin !== '' ? formatDateTime($lastLogin) : 'Nikdy' ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Přidán</td>
                                    <td><?= $createdAt !== '' ? formatDate($createdAt) : '–' ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Poslední dokončený trénink</td>
                                    <td><?= !empty($athlete['last_completed_at']) ? formatDateTime((string)$athlete['last_completed_at']) : '–' ?></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Poznámky</td>
                                    <td><?= $notes !== '' ? nl2br(h($notes)) : '–' ?></td>
                                </tr>
                            </table>
                        </div>

                        <div class="col-md-5">
                            <div class="card bg-light border-0 h-100">
                                <div class="card-body">
                                    <div class="fw-semibold mb-2">
                                        <i class="fas fa-paper-plane me-1 text-primary"></i>
                                        Poslat zprávu jako admin
                                    </div>
                                    <form method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="send_message">
                                        <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">

                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold mb-1">Předmět</label>
                                            <input type="text"
                                                   name="subject"
                                                   class="form-control form-control-sm"
                                                   maxlength="255"
                                                   required
                                                   placeholder="Např. Informace od admina">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-semibold mb-1">Zpráva</label>
                                            <textarea name="body"
                                                      class="form-control form-control-sm"
                                                      rows="4"
                                                      maxlength="4000"
                                                      required
                                                      placeholder="Text zprávy pro sportovce..."></textarea>
                                        </div>

                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="fas fa-envelope me-1"></i>Odeslat
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php renderAdminFooter(); ?>
