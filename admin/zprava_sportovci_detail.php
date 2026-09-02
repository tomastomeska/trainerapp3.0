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

renderAdminHeader('Detail zprávy sportovcům');
?>
<div class="d-flex align-items-center mb-4 gap-3"><a href="<?= BASE_URL ?>/admin/zprava_sportovci.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i></a><h2 class="fw-bold mb-0"><i class="fas fa-bullhorn me-2 text-primary"></i>Detail hromadné zprávy</h2></div>
<div class="row g-4"><div class="col-lg-7"><div class="card shadow-sm"><div class="card-header d-flex justify-content-between align-items-center gap-2"><strong><?= h($broadcast['subject']) ?></strong><span class="text-muted small text-nowrap"><?= formatDateTime((string)$broadcast['created_at']) ?></span></div><div class="card-body" style="white-space:pre-wrap"><?= h($broadcast['body']) ?></div></div></div><div class="col-lg-5"><div class="card shadow-sm"><div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold"><i class="fas fa-users me-1"></i>Příjemci</span><span class="badge bg-<?= $readCount === count($recipients) ? 'success' : 'warning text-dark' ?>">Přečteno: <?= $readCount ?>/<?= count($recipients) ?></span></div><div class="card-body p-0"><?php if (empty($recipients)): ?><div class="p-3 text-muted">Žádní příjemci.</div><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($recipients as $recipient): ?><li class="list-group-item d-flex justify-content-between align-items-center gap-2"><div><strong><?= h(trim($recipient['first_name'] . ' ' . $recipient['last_name'])) ?></strong><?php if (!empty($recipient['email'])): ?><small class="text-muted d-block"><?= h($recipient['email']) ?></small><?php endif; ?></div><div class="text-end"><?php if (!empty($recipient['read_at'])): ?><span class="badge bg-success">Přečteno</span><small class="text-muted d-block"><?= formatDateTime((string)$recipient['read_at']) ?></small><?php else: ?><span class="badge bg-secondary">Nepřečteno</span><?php endif; ?></div></li><?php endforeach; ?></ul><?php endif; ?></div></div></div></div>
<?php renderAdminFooter(); ?>