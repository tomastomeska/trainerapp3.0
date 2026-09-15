<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
$pdo = getDB();
$broadcastId = intParam($_GET, 'id');

$broadcastStmt = $pdo->prepare('SELECT * FROM admin_athlete_broadcasts WHERE id = ?');
$broadcastStmt->execute([$broadcastId]);
$broadcast = $broadcastStmt->fetch();
if (!$broadcast) {
    flash('danger', 'Hromadná zpráva nebyla nalezena.');
    redirect(BASE_URL . '/admin/zprava_sportovci.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_unread') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/zprava_sportovci_detail.php?id=' . $broadcastId);
    }

    $unreadStmt = $pdo->prepare(
        'SELECT a.first_name, a.last_name, a.email
         FROM admin_athlete_broadcast_recipients r
         JOIN athletes a ON a.id = r.athlete_id
         JOIN athlete_notifications n ON n.id = r.notification_id
         WHERE r.broadcast_id = ? AND n.read_at IS NULL'
    );
    $unreadStmt->execute([$broadcastId]);
    $unreadRecipients = $unreadStmt->fetchAll();
    $queuedCount = 0;
    $withoutEmailCount = 0;

    foreach ($unreadRecipients as $recipient) {
        $email = trim((string)($recipient['email'] ?? ''));
        if ($email === '') {
            $withoutEmailCount++;
            continue;
        }
        $athleteName = trim((string)$recipient['first_name'] . ' ' . (string)$recipient['last_name']);
        if (sendAthleteMessageNotificationEmail(
            $email,
            $athleteName !== '' ? $athleteName : 'sportovče',
            (string)$broadcast['subject'],
            (string)$broadcast['body']
        )) {
            $queuedCount++;
        }
    }

    if ($queuedCount > 0) {
        processEmailNotificationQueue(200, 'athlete_message_notification');
    }

    $message = 'Znovuodeslání: ' . $queuedCount . ' e-mailů bylo zařazeno ke zpracování.';
    if ($withoutEmailCount > 0) {
        $message .= ' Sportovci bez e-mailu: ' . $withoutEmailCount . '.';
    }
    flash($queuedCount > 0 ? 'success' : 'warning', $message);
    redirect(BASE_URL . '/admin/zprava_sportovci_detail.php?id=' . $broadcastId);
}

$recipientsStmt = $pdo->prepare(
    'SELECT a.first_name, a.last_name, a.email, n.read_at
     FROM admin_athlete_broadcast_recipients r
     JOIN athletes a ON a.id = r.athlete_id
     JOIN athlete_notifications n ON n.id = r.notification_id
     WHERE r.broadcast_id = ?
     ORDER BY a.first_name ASC, a.last_name ASC, a.id ASC'
);
$recipientsStmt->execute([$broadcastId]);
$recipients = $recipientsStmt->fetchAll();
$readCount = count(array_filter($recipients, static fn(array $recipient): bool => !empty($recipient['read_at'])));
$unreadCount = count($recipients) - $readCount;

renderAdminHeader('Detail zprávy sportovcům');
?>
<div class="d-flex align-items-center mb-4 gap-3"><a href="<?= BASE_URL ?>/admin/zprava_sportovci.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i></a><h2 class="fw-bold mb-0"><i class="fas fa-bullhorn me-2 text-primary"></i>Detail hromadné zprávy</h2></div>
<div class="row g-4"><div class="col-lg-7"><div class="card shadow-sm"><div class="card-header d-flex justify-content-between align-items-center gap-2"><strong><?= h($broadcast['subject']) ?></strong><span class="text-muted small text-nowrap"><?= formatDateTime((string)$broadcast['created_at']) ?></span></div><div class="card-body" style="white-space:pre-wrap"><?= h($broadcast['body']) ?></div><?php if (!empty($broadcast['attachment_name'])): ?><div class="card-footer"><i class="fas fa-paperclip me-1 text-muted"></i><strong>Příloha:</strong> <a href="<?= BASE_URL ?>/uploads/messages/<?= rawurlencode((string)$broadcast['attachment_path']) ?>" target="_blank"><?= h((string)$broadcast['attachment_name']) ?></a></div><?php endif; ?></div></div><div class="col-lg-5"><div class="card shadow-sm"><div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold"><i class="fas fa-users me-1"></i>Příjemci</span><span class="badge bg-<?= $readCount === count($recipients) ? 'success' : 'warning text-dark' ?>">Přečteno: <?= $readCount ?>/<?= count($recipients) ?></span></div><div class="card-body p-0"><?php if ($unreadCount > 0): ?><form method="post" class="p-3 border-bottom" onsubmit="return confirm('Znovu odeslat e-mail všem <?= $unreadCount ?> nepřečteným sportovcům?')"><?= csrfField() ?><input type="hidden" name="action" value="resend_unread"><button type="submit" class="btn btn-outline-primary w-100"><i class="fas fa-rotate-right me-1"></i>Znovu poslat nepřečteným (<?= $unreadCount ?>)</button></form><?php endif; ?><?php if (empty($recipients)): ?><div class="p-3 text-muted">Žádní příjemci.</div><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($recipients as $recipient): ?><li class="list-group-item d-flex justify-content-between align-items-center gap-2"><div><strong><?= h(trim($recipient['first_name'] . ' ' . $recipient['last_name'])) ?></strong><?php if (!empty($recipient['email'])): ?><small class="text-muted d-block"><?= h($recipient['email']) ?></small><?php endif; ?></div><div class="text-end"><?php if (!empty($recipient['read_at'])): ?><span class="badge bg-success">Přečteno</span><small class="text-muted d-block"><?= formatDateTime((string)$recipient['read_at']) ?></small><?php else: ?><span class="badge bg-secondary">Nepřečteno</span><?php endif; ?></div></li><?php endforeach; ?></ul><?php endif; ?></div></div></div></div>
<div class="modal fade" id="messageAttachmentModal" tabindex="-1" aria-labelledby="messageAttachmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="height:calc(100vh - 2rem)">
        <div class="modal-content h-100 d-flex flex-column overflow-hidden">
            <div class="modal-header">
                <h5 class="modal-title text-truncate" id="messageAttachmentModalLabel">Příloha zprávy</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body p-0 flex-grow-1 overflow-hidden" style="min-height:0">
                <iframe id="messageAttachmentFrame" title="Náhled přílohy zprávy" class="w-100 h-100 border-0 d-block"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.card-footer a[target="_blank"]').forEach(function (link) {
    link.addEventListener('click', function (event) {
        event.preventDefault();
        document.getElementById('messageAttachmentModalLabel').textContent = link.textContent.trim() || 'Příloha zprávy';
        document.getElementById('messageAttachmentFrame').src = link.href;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('messageAttachmentModal')).show();
    });
});
document.getElementById('messageAttachmentModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('messageAttachmentFrame').removeAttribute('src');
});
</script>

<?php renderAdminFooter(); ?>