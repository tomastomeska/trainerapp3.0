<?php
// admin/zpravy.php – přehled odeslaných zpráv trenérům
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
$pdo = getDB();

// Smazání zprávy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
	if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
		flash('danger', 'Neplatný bezpečnostní token.');
		redirect(BASE_URL . '/admin/zpravy.php');
	}
	$mid = intParam($_POST, 'message_id');
	// Smaže i přílohu
	$row = $pdo->prepare("SELECT attachment_path FROM admin_messages WHERE id = ? AND message_source = 'admin'");
	$row->execute([$mid]);
	$msg = $row->fetch();
	if ($msg && $msg['attachment_path']) {
		$full = dirname(__DIR__) . '/uploads/messages/' . basename($msg['attachment_path']);
		if (file_exists($full)) @unlink($full);
	}
	if (!$msg) {
		flash('warning', 'Lze mazat pouze zprávy odeslané administrátorem.');
		redirect(BASE_URL . '/admin/zpravy.php');
	}
	$pdo->prepare("DELETE FROM admin_messages WHERE id = ? AND message_source = 'admin'")->execute([$mid]);
	flash('success', 'Zpráva byla smazána.');
	redirect(BASE_URL . '/admin/zpravy.php');
}

// Načíst zprávy se statistikou přečtení
$messages = $pdo->query("
	SELECT m.*,
		COUNT(r.id)                                            AS recipient_count,
		SUM(r.read_at IS NOT NULL)                            AS read_count
	FROM admin_messages m
	LEFT JOIN admin_message_recipients r ON r.message_id = m.id
	WHERE m.message_source = 'admin'
	GROUP BY m.id
	ORDER BY m.sent_at DESC
")->fetchAll();

renderAdminHeader('Zprávy');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
	<div>
		<h2 class="fw-bold mb-1"><i class="fas fa-envelope-open-text me-2 text-primary"></i>Zprávy trenérům</h2>
		<span class="badge text-bg-light border">Pouze admin zprávy</span>
	</div>
	<a href="<?= BASE_URL ?>/admin/zprava_nova.php" class="btn btn-primary">
		<i class="fas fa-plus me-1"></i>Nová zpráva
	</a>
</div>

<?php if (empty($messages)): ?>
<div class="alert alert-info">Zatím nebyly odeslány žádné zprávy.</div>
<?php else: ?>
<div class="card shadow-sm">
	<div class="table-responsive">
		<table class="table table-hover mb-0">
			<thead class="table-dark">
				<tr>
					<th>Předmět</th>
					<th>Odesláno</th>
					<th>Příjemci</th>
					<th>Přečteno</th>
					<th>Příloha</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($messages as $m): ?>
			<tr>
				<td>
					<a href="<?= BASE_URL ?>/admin/zprava_detail.php?id=<?= $m['id'] ?>" class="fw-semibold text-decoration-none">
						<?= h($m['subject']) ?>
					</a>
				</td>
				<td class="text-nowrap"><?= date('d.m.Y H:i', strtotime($m['sent_at'])) ?></td>
				<td><?= (int)$m['recipient_count'] ?></td>
				<td>
					<?php
					$total = (int)$m['recipient_count'];
					$read  = (int)$m['read_count'];
					$pct   = $total > 0 ? round($read / $total * 100) : 0;
					$cls   = $pct === 100 ? 'success' : ($pct > 0 ? 'warning' : 'secondary');
					?>
					<span class="badge bg-<?= $cls ?>"><?= $read ?>/<?= $total ?></span>
				</td>
				<td>
					<?php if ($m['attachment_name']): ?>
					<i class="fas fa-paperclip text-muted" title="<?= h($m['attachment_name']) ?>"></i>
					<?= h(mb_strimwidth($m['attachment_name'], 0, 20, '…')) ?>
					<?php else: ?>
					<span class="text-muted">—</span>
					<?php endif; ?>
				</td>
				<td class="text-end">
					<a href="<?= BASE_URL ?>/admin/zprava_detail.php?id=<?= $m['id'] ?>"
					   class="btn btn-sm btn-outline-primary me-1">
						<i class="fas fa-eye"></i>
					</a>
					<form method="post" class="d-inline"
					      onsubmit="return confirm('Opravdu smazat tuto zprávu?')">
						<input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
						<input type="hidden" name="action" value="delete">
						<input type="hidden" name="message_id" value="<?= $m['id'] ?>">
						<button class="btn btn-sm btn-outline-danger">
							<i class="fas fa-trash"></i>
						</button>
					</form>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<?php renderAdminFooter(); ?>
