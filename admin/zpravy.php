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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_chat_migration') {
	if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
		flash('danger', 'Neplatný bezpečnostní token.');
		redirect(BASE_URL . '/admin/zpravy.php');
	}

	$oldSecret = $_GET['secret'] ?? null;
	$_GET['secret'] = getCronSecret();
	ob_start();
	require __DIR__ . '/../scripts/migrate_admin_athlete_chat.php';
	$migrationOutput = trim((string)ob_get_clean());
	if ($oldSecret === null) { unset($_GET['secret']); } else { $_GET['secret'] = $oldSecret; }
	$migrationResult = json_decode($migrationOutput, true);
	if (is_array($migrationResult) && !empty($migrationResult['success'])) {
		flash('success', 'Migrace individuálního chatu proběhla úspěšně.');
	} else {
		$message = is_array($migrationResult) ? (string)($migrationResult['error'] ?? 'Neznámá chyba migrace.') : 'Migrace vrátila neočekávanou odpověď.';
		flash('danger', 'Migraci se nepodařilo dokončit: ' . $message);
	}
	redirect(BASE_URL . '/admin/zpravy.php');
}

$coachChatTableCheck = $pdo->query("SHOW TABLES LIKE 'admin_coach_chat_messages'");
$coachChatTableExists = $coachChatTableCheck !== false && (bool)$coachChatTableCheck->fetchColumn();

$coachChatList = [];
if ($coachChatTableExists) {
	$coachChatList = $pdo->query(
		"SELECT c.id, c.name, c.username,
				(SELECT body FROM admin_coach_chat_messages WHERE coach_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_body,
				(SELECT created_at FROM admin_coach_chat_messages WHERE coach_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_at,
				(SELECT COUNT(*) FROM admin_coach_chat_messages WHERE coach_id = c.id AND sender = 'coach' AND admin_read_at IS NULL) AS unread_count
		 FROM coaches c
		 WHERE c.is_active = 1
		 ORDER BY (last_at IS NULL) ASC, last_at DESC, c.name ASC"
	)->fetchAll();
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

<div class="card shadow-sm mb-4">
	<div class="card-header fw-semibold"><i class="fas fa-comments me-2"></i>Individuální chat s trenérem</div>
	<?php if (!$coachChatTableExists): ?>
	<div class="card-body">
		<div class="alert alert-warning mb-3 mb-md-0">
			Individuální chat ještě není nasazen na této instanci (chybí tabulka <code>admin_coach_chat_messages</code>).
		</div>
		<form method="post">
			<?= csrfField() ?>
			<input type="hidden" name="action" value="run_chat_migration">
			<button type="submit" class="btn btn-primary"><i class="fas fa-database me-1"></i>Spustit migraci</button>
		</form>
	</div>
	<?php elseif (empty($coachChatList)): ?>
	<div class="card-body text-muted">Žádný aktivní trenér.</div>
	<?php else: ?>
	<div class="list-group list-group-flush">
		<?php foreach ($coachChatList as $row): $cname = ($row['name'] ?: $row['username']); $unreadN = (int)$row['unread_count']; ?>
		<a href="<?= BASE_URL ?>/admin/zprava_trener_chat.php?coach_id=<?= (int)$row['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
			<div>
				<div class="fw-semibold"><?= h($cname) ?></div>
				<?php if (!empty($row['last_body'])): ?>
				<div class="small text-muted text-truncate" style="max-width:420px"><?= h(mb_strimwidth((string)$row['last_body'], 0, 90, '…')) ?></div>
				<?php else: ?>
				<div class="small text-muted">Zatím žádná zpráva.</div>
				<?php endif; ?>
			</div>
			<div class="text-end">
				<?php if ($unreadN > 0): ?>
				<span class="badge bg-danger rounded-pill mb-1"><?= $unreadN ?></span><br>
				<?php endif; ?>
				<?php if (!empty($row['last_at'])): ?>
				<span class="small text-muted"><?= formatDateTime((string)$row['last_at']) ?></span>
				<?php endif; ?>
			</div>
		</a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
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
