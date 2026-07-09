<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$athlete   = getCurrentAthlete();
$coachId   = (int)($athlete['coach_id'] ?? 0);
$pdo       = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_zpravy.php');
    }

    $action = (string)($_POST['action'] ?? '');

    // Označit přijatou zprávu jako přečtenou
    if ($action === 'mark_read') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        if ($notificationId > 0) {
            $pdo->prepare('UPDATE athlete_notifications SET read_at = NOW() WHERE id = ? AND athlete_id = ? AND read_at IS NULL')
                ->execute([$notificationId, $athleteId]);
        }
        redirect(BASE_URL . '/athlete_zpravy.php');
    }

    // Napsat novou zprávu trenérovi
    if ($action === 'send_message') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body    = trim((string)($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            flash('danger', 'Vyplňte prosím předmět i text zprávy.');
            redirect(BASE_URL . '/athlete_zpravy.php?tab=sent');
        }

        if ($coachId > 0) {
            $athleteName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
            $fullSubject = "[Zpráva od sportovce] {$athleteName}: {$subject}";
            createAthleteToCoachMessage($athleteId, $coachId, $fullSubject, $body);
            flash('success', 'Zpráva byla odeslána trenérovi.');
        } else {
            flash('danger', 'Nepodařilo se odeslat zprávu – trenér nenalezen.');
        }
        redirect(BASE_URL . '/athlete_zpravy.php?tab=sent');
    }

    redirect(BASE_URL . '/athlete_zpravy.php');
}

$tab = in_array($_GET['tab'] ?? '', ['sent']) ? 'sent' : 'inbox';

// Přijaté zprávy (od trenéra)
$inboxStmt = $pdo->prepare(
    'SELECT id, subject, body, read_at, created_at
     FROM athlete_notifications
     WHERE athlete_id = ?
     ORDER BY created_at DESC, id DESC'
);
$inboxStmt->execute([$athleteId]);
$inbox = $inboxStmt->fetchAll();

// Odeslané zprávy trenérovi
$sentStmt = $pdo->prepare(
    'SELECT m.id, m.subject, m.body, m.sent_at,
            r.read_at AS coach_read_at
     FROM admin_messages m
     LEFT JOIN admin_message_recipients r ON r.message_id = m.id AND r.coach_id = ?
     WHERE m.from_athlete_id = ?
     ORDER BY m.sent_at DESC'
);
$sentStmt->execute([$coachId, $athleteId]);
$sent = $sentStmt->fetchAll();

$unreadCount = count(array_filter($inbox, fn($m) => empty($m['read_at'])));

renderAthleteHeader('Zprávy', false, true);
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-envelope me-2 text-warning"></i>Zprávy</h2>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <button class="btn btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#composeModal">
            <i class="fas fa-pen me-1"></i>Napsat trenérovi
        </button>
    </div>
</div>

<!-- Záložky -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'inbox' ? 'active' : '' ?>" href="<?= BASE_URL ?>/athlete_zpravy.php?tab=inbox">
            <i class="fas fa-inbox me-1"></i>Přijaté
            <?php if ($unreadCount > 0): ?>
            <span class="badge bg-danger ms-1"><?= $unreadCount ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'sent' ? 'active' : '' ?>" href="<?= BASE_URL ?>/athlete_zpravy.php?tab=sent">
            <i class="fas fa-paper-plane me-1"></i>Odeslané
        </a>
    </li>
</ul>

<?php if ($tab === 'inbox'): ?>

<?php if (empty($inbox)): ?>
<div class="alert alert-info">Zatím nemáte žádné přijaté zprávy.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-dark">
            <tr>
                <th>Předmět a zpráva</th>
                <th style="width:160px">Datum</th>
                <th style="width:110px">Stav</th>
                <th style="width:160px"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($inbox as $m): ?>
            <tr class="<?= empty($m['read_at']) ? 'table-warning' : '' ?>">
                <td>
                    <div class="fw-semibold"><?= h((string)$m['subject']) ?></div>
                    <div class="small text-muted mt-1" style="white-space:pre-wrap"><?= h((string)$m['body']) ?></div>
                </td>
                <td class="text-nowrap small"><?= formatDateTime((string)$m['created_at']) ?></td>
                <td>
                    <?php if (empty($m['read_at'])): ?>
                    <span class="badge bg-danger">Nepřečteno</span>
                    <?php else: ?>
                    <span class="badge bg-success">Přečteno</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (empty($m['read_at'])): ?>
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="mark_read">
                        <input type="hidden" name="notification_id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Označit přečtené</button>
                    </form>
                    <?php else: ?>
                    <span class="text-muted small">–</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php else: /* tab=sent */ ?>

<?php if (empty($sent)): ?>
<div class="alert alert-info">Zatím jste trenérovi žádnou zprávu nepsali.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-dark">
            <tr>
                <th>Předmět a zpráva</th>
                <th style="width:160px">Odesláno</th>
                <th style="width:130px">Trenér viděl</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sent as $m): ?>
            <tr>
                <td>
                    <div class="fw-semibold"><?= h((string)$m['subject']) ?></div>
                    <div class="small text-muted mt-1" style="white-space:pre-wrap"><?= h((string)$m['body']) ?></div>
                </td>
                <td class="text-nowrap small"><?= formatDateTime((string)$m['sent_at']) ?></td>
                <td>
                    <?php if (!empty($m['coach_read_at'])): ?>
                    <span class="badge bg-success">Přečteno</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">Čeká na přečtení</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Modal: napsat zprávu trenérovi -->
<div class="modal fade" id="composeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send_message">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-pen me-2 text-warning"></i>Napsat trenérovi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Předmět <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" maxlength="200" required placeholder="Např. Dotaz k tréninku">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Zpráva <span class="text-danger">*</span></label>
                        <textarea name="body" class="form-control" rows="5" required maxlength="4000" placeholder="Napište svou zprávu..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn btn-warning fw-bold"><i class="fas fa-paper-plane me-1"></i>Odeslat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php renderAthleteFooter(); ?>
