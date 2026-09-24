<?php
// admin/header.php – sdílená hlavička pro admin panel
function renderAdminHeader(string $title = ''): void {
	$admin   = getCurrentAdmin();
	$flash   = getFlash();
	$appName = APP_NAME . ' Admin';
	$fullTitle = $title ? "$title – $appName" : $appName;
	$supportNewCount = 0;
	$athleteChatUnreadTotal = 0;
	try {
		$pdo = getDB();
		$supportNewCount = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'new'")->fetchColumn();
	} catch (Throwable $e) {
		$supportNewCount = 0;
	}
	try {
		$pdo = getDB();
		$chatTableCheck = $pdo->query("SHOW TABLES LIKE 'admin_athlete_chat_messages'");
		if ($chatTableCheck !== false && (bool)$chatTableCheck->fetchColumn()) {
			$athleteChatUnreadTotal = (int)$pdo->query("SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE sender = 'athlete' AND admin_read_at IS NULL")->fetchColumn();
		}
	} catch (Throwable $e) {
		$athleteChatUnreadTotal = 0;
	}
	$coachChatUnreadTotal = 0;
	try {
		$pdo = getDB();
		$coachChatTableCheck = $pdo->query("SHOW TABLES LIKE 'admin_coach_chat_messages'");
		if ($coachChatTableCheck !== false && (bool)$coachChatTableCheck->fetchColumn()) {
			$coachChatUnreadTotal = (int)$pdo->query("SELECT COUNT(*) FROM admin_coach_chat_messages WHERE sender = 'coach' AND admin_read_at IS NULL")->fetchColumn();
		}
	} catch (Throwable $e) {
		$coachChatUnreadTotal = 0;
	}
	?>
<!DOCTYPE html>
<html lang="cs">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= h($fullTitle) ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
	<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="admin-layout">

<nav class="navbar navbar-dark shadow-sm sticky-top admin-topbar">
	<div class="container-fluid px-3 px-md-4">
		<button class="btn btn-sm me-2 d-md-none admin-sidebar-toggle" id="sidebarToggle">
			<i class="fas fa-bars"></i>
		</button>
		<a class="navbar-brand fw-bold admin-brand" href="<?= BASE_URL ?>/admin/dashboard.php">
			<i class="fas fa-shield-halved me-2 admin-brand-icon"></i>
			<span class="admin-brand-primary">Super</span><span class="text-white">Admin</span>
			<span class="badge ms-2 px-2 py-1 small admin-badge d-none d-sm-inline-block">TrainerApp</span>
		</a>
		<?php if ($admin): ?>
		<div class="d-flex align-items-center gap-2 gap-md-3 ms-auto">
			<span class="small d-none d-sm-inline admin-user-label">
				<i class="fas fa-user-shield me-1 admin-brand-icon"></i>
				<?= h($admin['name'] ?: $admin['username']) ?>
			</span>
			<a href="<?= BASE_URL ?>/logout_admin.php" class="btn btn-sm btn-outline-danger">
				<i class="fas fa-sign-out-alt me-1"></i><span class="d-none d-sm-inline">Odhlásit</span>
			</a>
		</div>
		<?php endif; ?>
	</div>
</nav>

<div class="container-fluid">

<div class="row" id="adminLayout">
	<!-- Sidebar -->
	<div class="col-auto p-0 sidebar-wrapper admin-sidebar-wrapper">
		<div class="sidebar p-3 admin-sidebar">
			<div class="nav flex-column">
				<a href="<?= BASE_URL ?>/admin/dashboard.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
					<i class="fas fa-gauge-high me-2"></i>Přehled
				</a>
				<a href="<?= BASE_URL ?>/admin/coaches.php"
				   class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['coaches.php','coach_add.php','coach_edit.php','coach_delete.php','coach_deleted_trainings.php','coach_athletes.php']) ? 'active' : '' ?>">
					<i class="fas fa-user-tie me-2"></i>Trenéři
				</a>
				<a href="<?= BASE_URL ?>/admin/athletes.php"
				   class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['athletes.php','athlete_add.php','athlete_edit.php','athlete_delete.php']) ? 'active' : '' ?>">
					<i class="fas fa-users me-2"></i>Sportovci
				</a>
				<a href="<?= BASE_URL ?>/admin/exercises.php"
				   class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['exercises.php','exercise_export.php','exercise_import.php']) ? 'active' : '' ?>">
					<i class="fas fa-globe me-2"></i>Globální cviky
				</a>
				<a href="<?= BASE_URL ?>/admin/global_workout_sets.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'global_workout_sets.php' ? 'active' : '' ?>">
					<i class="fas fa-layer-group me-2"></i>Globální sady
				</a>
				<a href="<?= BASE_URL ?>/admin/meals.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'meals.php' ? 'active' : '' ?>">
					<i class="fas fa-utensils me-2"></i>Globální jídla
				</a>
				<a href="<?= BASE_URL ?>/admin/training_add.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'training_add.php' ? 'active' : '' ?>">
					<i class="fas fa-calendar-plus me-2"></i>Přidat trénink
				</a>
				<a href="<?= BASE_URL ?>/admin/training_bulk.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'training_bulk.php' ? 'active' : '' ?>">
					<i class="fas fa-file-csv me-2"></i>Import tréninků CSV
				</a>
				<a href="<?= BASE_URL ?>/admin/venues.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'venues.php' ? 'active' : '' ?>">
					<i class="fas fa-map-location-dot me-2"></i>Sportoviště
				</a>
				<a href="<?= BASE_URL ?>/admin/events.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'events.php' ? 'active' : '' ?>">
					<i class="fas fa-flag-checkered me-2"></i>Events
				</a>
				<a href="<?= BASE_URL ?>/admin/online_trainings.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'online_trainings.php' ? 'active' : '' ?>">
					<i class="fas fa-laptop me-2"></i>Online tréninky
				</a>
				<a href="<?= BASE_URL ?>/admin/health_questionnaire.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'health_questionnaire.php' ? 'active' : '' ?>">
					<i class="fas fa-heart-pulse me-2"></i>Zdravotní dotazník
				</a>
				<a href="<?= BASE_URL ?>/admin/mycoach.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mycoach.php' ? 'active' : '' ?>">
					<i class="fas fa-brain me-2"></i>MyCoach administrace
				</a>
				<a href="<?= BASE_URL ?>/admin/surveys.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'surveys.php' ? 'active' : '' ?>">
					<i class="fas fa-square-poll-vertical me-2"></i>Ankety a dotazníky
				</a>
				<a href="<?= BASE_URL ?>/admin/mycoach_content.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mycoach_content.php' ? 'active' : '' ?>" style="padding-left:2.2rem;font-size:.87rem;">
					<i class="fas fa-layer-group me-2"></i>MyCoach obsah
				</a>
				<a href="<?= BASE_URL ?>/admin/manual.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'manual.php' ? 'active' : '' ?>">
					<i class="fas fa-book-open me-2"></i>Návod
				</a>
				<a href="<?= BASE_URL ?>/admin/login_message.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'login_message.php' ? 'active' : '' ?>">
					<i class="fas fa-bell me-2"></i>Hláška po přihlášení
				</a>
				<a href="<?= BASE_URL ?>/admin/infokanal.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'infokanal.php' ? 'active' : '' ?>">
					<i class="fas fa-lightbulb me-2"></i>Infokanál
				</a>
				<a href="<?= BASE_URL ?>/admin/email_notifications.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'email_notifications.php' ? 'active' : '' ?>">
					<i class="fas fa-envelope me-2"></i>E-mailové notifikace
				</a>
				<a href="<?= BASE_URL ?>/admin/podpora.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'podpora.php' ? 'active' : '' ?>">
					<i class="fas fa-life-ring me-2"></i>Podpora
					<?php if ($supportNewCount > 0): ?>
					<span class="badge bg-danger ms-1"><?= $supportNewCount ?></span>
					<?php endif; ?>
				</a>
				<a href="<?= BASE_URL ?>/admin/errorlog.php"
				   class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['errorlog.php','errorlog_user.php']) ? 'active' : '' ?>">
					<i class="fas fa-triangle-exclamation me-2"></i>Errorlog
				</a>
				<a href="<?= BASE_URL ?>/admin/zpravy.php"
				   class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['zpravy.php','zprava_nova.php','zprava_detail.php','zprava_sportovci.php','zprava_sportovci_detail.php','zprava_trener_chat.php']) ? 'active' : '' ?>">
					<i class="fas fa-comments me-2"></i>Zprávy trenérům
					<?php if ($coachChatUnreadTotal > 0): ?>
					<span class="badge bg-danger ms-1"><?= $coachChatUnreadTotal ?></span>
					<?php endif; ?>
				</a>
				<a href="<?= BASE_URL ?>/admin/zprava_sportovci.php"
				   class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['zprava_sportovci.php','zprava_sportovci_detail.php','zprava_sportovec_chat.php']) ? 'active' : '' ?>">
					<i class="fas fa-bullhorn me-2"></i>Zprávy sportovcům
					<?php if ($athleteChatUnreadTotal > 0): ?>
					<span class="badge bg-danger ms-1"><?= $athleteChatUnreadTotal ?></span>
					<?php endif; ?>
				</a>
				<a href="<?= BASE_URL ?>/admin/gallery.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'gallery.php' ? 'active' : '' ?>">
					<i class="fas fa-images me-2"></i>Galerie
				</a>
				<a href="<?= BASE_URL ?>/admin/settings.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : '' ?>">
					<i class="fas fa-sliders me-2"></i>Nastavení
				</a>
			</div>
		</div>
	</div>

	<!-- Hlavní obsah -->
	<div class="col p-2 p-md-3 flex-grow-1 admin-content">
<?php if ($flash): ?>
<div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show admin-flash" role="alert">
	<?= $flash['message'] ?>
	<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($athleteChatUnreadTotal > 0 && basename($_SERVER['PHP_SELF']) !== 'zprava_sportovec_chat.php'): ?>
<div class="alert alert-info alert-dismissible fade show d-flex align-items-center justify-content-between flex-wrap gap-2" role="alert">
	<span><i class="fas fa-comment-dots me-2"></i><strong>Nová zpráva v chatu:</strong> máte <?= $athleteChatUnreadTotal ?> nepřečten<?= $athleteChatUnreadTotal === 1 ? 'ou zprávu' : ($athleteChatUnreadTotal < 5 ? 'é zprávy' : 'ých zpráv') ?> od sportovc<?= $athleteChatUnreadTotal === 1 ? 'e' : 'ů' ?>.</span>
	<a href="<?= BASE_URL ?>/admin/zprava_sportovci.php" class="btn btn-sm btn-primary">Otevřít chat</a>
	<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($coachChatUnreadTotal > 0 && basename($_SERVER['PHP_SELF']) !== 'zprava_trener_chat.php'): ?>
<div class="alert alert-info alert-dismissible fade show d-flex align-items-center justify-content-between flex-wrap gap-2" role="alert">
	<span><i class="fas fa-comment-dots me-2"></i><strong>Nová zpráva v chatu:</strong> máte <?= $coachChatUnreadTotal ?> nepřečten<?= $coachChatUnreadTotal === 1 ? 'ou zprávu' : ($coachChatUnreadTotal < 5 ? 'é zprávy' : 'ých zpráv') ?> od trenér<?= $coachChatUnreadTotal === 1 ? 'a' : 'ů' ?>.</span>
	<a href="<?= BASE_URL ?>/admin/zpravy.php" class="btn btn-sm btn-primary">Otevřít chat</a>
	<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php $adminChatWidgetTotal = $athleteChatUnreadTotal + $coachChatUnreadTotal; ?>
<button type="button" class="admin-chat-fab" id="adminHubChatFab" title="Zprávy">
	<i class="fas fa-comment-dots"></i>
	<span class="admin-chat-fab-badge<?= $adminChatWidgetTotal > 0 ? '' : ' d-none' ?>" id="adminHubChatBadge"><?= $adminChatWidgetTotal ?></span>
</button>
<div class="admin-chat-panel d-none" id="adminHubChatPanel">
	<div class="admin-chat-panel-header">
		<button type="button" class="admin-chat-back-btn d-none" id="adminHubChatBackBtn" aria-label="Zpět"><i class="fas fa-arrow-left"></i></button>
		<span id="adminHubChatTitle">Zprávy</span>
		<button type="button" class="btn-close btn-close-white" id="adminHubChatCloseBtn" aria-label="Zavřít"></button>
	</div>
	<div class="admin-chat-panel-body" id="adminHubChatHub">
		<div class="text-muted text-center small py-3">Načítám…</div>
	</div>
	<div class="admin-chat-panel-body d-none" id="adminHubChatBody"></div>
	<form class="admin-chat-panel-footer d-none" id="adminHubChatForm">
		<input type="text" class="form-control" id="adminHubChatInput" maxlength="4000" placeholder="Napište zprávu…" autocomplete="off" required>
		<button type="submit" class="btn btn-primary" id="adminHubChatSendBtn"><i class="fas fa-paper-plane"></i></button>
	</form>
</div>

<style>
.admin-chat-fab {
	position: fixed; right: 18px; bottom: 18px; width: 54px; height: 54px; border: none; border-radius: 50%;
	background: linear-gradient(135deg, #25d366, #128c7e); color: #fff; font-size: 22px;
	box-shadow: 0 10px 24px rgba(37, 211, 102, 0.35); z-index: 1080;
}
.admin-chat-fab-badge {
	position: absolute; top: -4px; right: -4px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px;
	background: #dc3545; color: #fff; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;
}
.admin-chat-panel {
	position: fixed; right: 18px; bottom: 90px; width: 340px; max-width: calc(100vw - 24px); height: 460px; max-height: calc(100vh - 120px);
	background: #fff; border-radius: 14px; box-shadow: 0 16px 40px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden; z-index: 1085;
}
.admin-chat-panel-header {
	background: linear-gradient(135deg, #128c7e, #075e54); color: #fff; padding: 12px 14px; font-weight: 600; display: flex; align-items: center; gap: 4px;
}
.admin-chat-panel-header #adminHubChatTitle { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.admin-chat-back-btn { border: none; background: none; color: #fff; padding: 0 8px 0 0; font-size: 16px; }
.admin-chat-panel-body { flex: 1; overflow-y: auto; padding: 12px; background: #ece5dd; }
#adminHubChatHub { padding: 0; background: #fff; }
.admin-chat-hub-item {
	display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 14px; border-bottom: 1px solid #eee; cursor: pointer; color: #111; background: #fff;
}
.admin-chat-hub-item:hover { background: #f7f7f7; }
.admin-chat-hub-item .name { font-weight: 600; font-size: 14px; }
.admin-chat-hub-item .name i { margin-right: 6px; color: #128c7e; }
.admin-chat-hub-item .name .fa-person-running { color: #f39c12; }
.admin-chat-hub-item .preview { font-size: 12px; color: #667781; margin-top: 2px; max-width: 210px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.admin-chat-hub-badge {
	display: inline-block; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: #dc3545; color: #fff;
	font-size: 10.5px; font-weight: 700; line-height: 18px; text-align: center;
}
.admin-chat-bubble-row { display: flex; margin-bottom: 8px; }
.admin-chat-bubble-row.from-admin { justify-content: flex-end; }
.admin-chat-bubble {
	max-width: 78%; padding: 8px 11px; border-radius: 10px; font-size: 14px; line-height: 1.4; white-space: pre-wrap; word-wrap: break-word;
	box-shadow: 0 1px 1px rgba(0,0,0,0.08);
}
.admin-chat-bubble-row.from-user .admin-chat-bubble { background: #fff; }
.admin-chat-bubble-row.from-admin .admin-chat-bubble { background: #dcf8c6; }
.admin-chat-bubble-time { display: block; margin-top: 3px; font-size: 10.5px; color: #667781; text-align: right; }
.admin-chat-read-status { margin-left: 4px; font-weight: 700; letter-spacing: -1px; }
.admin-chat-read-status.is-read { color: #128c7e; }
.admin-chat-panel-footer { display: flex; gap: 8px; padding: 10px; background: #f0f0f0; border-top: 1px solid #ddd; }
@media (max-width: 768px) {
	.admin-chat-panel { right: 12px; bottom: 82px; width: calc(100vw - 24px); }
	.admin-chat-fab { right: 12px; bottom: 12px; }
}
</style>

<script>
(function () {
	const fab = document.getElementById('adminHubChatFab');
	const panel = document.getElementById('adminHubChatPanel');
	const badge = document.getElementById('adminHubChatBadge');
	const hub = document.getElementById('adminHubChatHub');
	const body = document.getElementById('adminHubChatBody');
	const form = document.getElementById('adminHubChatForm');
	const input = document.getElementById('adminHubChatInput');
	const closeBtn = document.getElementById('adminHubChatCloseBtn');
	const backBtn = document.getElementById('adminHubChatBackBtn');
	const title = document.getElementById('adminHubChatTitle');

	if (!fab || !panel || !hub || !body || !form || !input || !closeBtn) { return; }

	const apiUrl = <?= json_encode(BASE_URL . '/admin/api/chat.php') ?>;
	const csrfToken = <?= json_encode(csrfToken()) ?>;
	let isOpen = false;
	let currentThread = null;
	let lastId = 0;
	let fastPollTimer = null;
	let slowPollTimer = null;

	const escapeHtml = (str) => String(str).replace(/[&<>"']/g, (c) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
	}[c]));

	const updateBadge = (count) => {
		if (count > 0) {
			badge.textContent = count > 99 ? '99+' : String(count);
			badge.classList.remove('d-none');
		} else {
			badge.classList.add('d-none');
		}
	};

	const renderMessage = (m) => {
		const row = document.createElement('div');
		const isMine = m.sender === 'admin';
		row.className = 'admin-chat-bubble-row from-' + (isMine ? 'admin' : 'user');
		let html = '<div class="admin-chat-bubble">' + escapeHtml(m.body).replace(/\n/g, '<br>');
		if (m.attachment_url) {
			html += '<div class="mt-1"><a href="' + m.attachment_url + '" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> ' + escapeHtml(m.attachment_name || 'Příloha') + '</a></div>';
		}
		html += '<span class="admin-chat-bubble-time">' + escapeHtml(m.created_at)
			+ (isMine ? '<span class="admin-chat-read-status' + (m.read_at ? ' is-read' : '') + '" title="' + (m.read_at ? 'Přečteno' : 'Odesláno') + '">' + (m.read_at ? '✓✓' : '✓') + '</span>' : '')
			+ '</span></div>';
		row.innerHTML = html;
		body.appendChild(row);
		if (Number(m.id) > lastId) { lastId = Number(m.id); }
	};

	const scrollToBottom = () => { body.scrollTop = body.scrollHeight; };

	const showHub = () => {
		currentThread = null;
		if (fastPollTimer) { clearInterval(fastPollTimer); fastPollTimer = null; }
		title.textContent = 'Zprávy';
		backBtn.classList.add('d-none');
		hub.classList.remove('d-none');
		body.classList.add('d-none');
		form.classList.add('d-none');
		loadHub();
	};

	const openThread = (type, id, name) => {
		currentThread = { type, id, label: name };
		lastId = 0;
		title.innerHTML = '<i class="' + (type === 'coach' ? 'fas fa-user-tie' : 'fas fa-person-running') + ' me-2"></i>' + escapeHtml(name);
		backBtn.classList.remove('d-none');
		hub.classList.add('d-none');
		body.classList.remove('d-none');
		form.classList.remove('d-none');
		loadThread();
	};

	const loadHub = async () => {
		hub.innerHTML = '<div class="text-muted text-center small py-3">Načítám…</div>';
		try {
			const res = await fetch(apiUrl + '?action=list', { credentials: 'same-origin' });
			const data = await res.json();
			if (!data || !data.ok || data.items.length === 0) {
				hub.innerHTML = '<div class="text-muted text-center small py-3">Zatím nejsou dostupné žádné konverzace.</div>';
				updateBadge(0);
				return;
			}
			let total = 0;
			hub.innerHTML = data.items.map((it) => {
				total += it.unread_count || 0;
				const icon = it.type === 'coach' ? 'fas fa-user-tie' : 'fas fa-person-running';
				return '<div class="admin-chat-hub-item" data-type="' + it.type + '" data-id="' + it.id + '" data-name="' + escapeHtml(it.name) + '">'
					+ '<div><div class="name"><i class="' + icon + '"></i>' + escapeHtml(it.name) + '</div>'
					+ (it.last_body ? '<div class="preview">' + escapeHtml(it.last_body) + '</div>' : '<div class="preview text-muted">Zatím žádná zpráva</div>')
					+ '</div>'
					+ (it.unread_count > 0 ? '<span class="admin-chat-hub-badge">' + it.unread_count + '</span>' : '')
					+ '</div>';
			}).join('');
			updateBadge(total);
			hub.querySelectorAll('.admin-chat-hub-item').forEach((el) => {
				el.addEventListener('click', () => {
					openThread(el.getAttribute('data-type'), el.getAttribute('data-id'), el.getAttribute('data-name'));
				});
			});
		} catch (e) {
			hub.innerHTML = '<div class="text-danger text-center small py-3">Seznam se nepodařilo načíst.</div>';
		}
	};

	const pollNewMessages = async () => {
		if (!currentThread) { return; }
		try {
			const res = await fetch(apiUrl + '?action=poll&type=' + currentThread.type + '&target_id=' + currentThread.id + '&since_id=' + lastId, { credentials: 'same-origin' });
			const data = await res.json();
			if (data && data.ok && data.messages.length > 0) {
				data.messages.forEach(renderMessage);
				scrollToBottom();
			}
		} catch (e) { /* tichy fail */ }
	};

	const loadThread = async () => {
		if (!currentThread) { return; }
		body.innerHTML = '<div class="text-muted text-center small py-3">Načítám zprávy…</div>';
		try {
			const res = await fetch(apiUrl + '?action=thread&type=' + currentThread.type + '&target_id=' + currentThread.id, { credentials: 'same-origin' });
			const data = await res.json();
			body.innerHTML = '';
			if (data && data.ok) {
				if (data.messages.length === 0) {
					body.innerHTML = '<div class="text-muted text-center small py-3">Zatím žádné zprávy.</div>';
				} else {
					data.messages.forEach(renderMessage);
				}
				scrollToBottom();
			}
			if (fastPollTimer) { clearInterval(fastPollTimer); }
			fastPollTimer = setInterval(pollNewMessages, 5000);
			input.focus();
		} catch (e) {
			body.innerHTML = '<div class="text-danger text-center small py-3">Zprávy se nepodařilo načíst.</div>';
		}
	};

	const pollUnreadForBadgeOnly = () => {
		fetch(apiUrl + '?action=unread_count', { credentials: 'same-origin' })
			.then((r) => r.json())
			.then((data) => { if (data && data.ok) { updateBadge(data.unread_count); } })
			.catch(() => {});
	};

	const openPanel = () => {
		isOpen = true;
		panel.classList.remove('d-none');
		if (slowPollTimer) { clearInterval(slowPollTimer); slowPollTimer = null; }
		showHub();
	};

	const closePanel = () => {
		isOpen = false;
		panel.classList.add('d-none');
		if (fastPollTimer) { clearInterval(fastPollTimer); fastPollTimer = null; }
		slowPollTimer = setInterval(pollUnreadForBadgeOnly, 20000);
	};

	fab.addEventListener('click', () => { isOpen ? closePanel() : openPanel(); });
	closeBtn.addEventListener('click', closePanel);
	backBtn.addEventListener('click', showHub);

	form.addEventListener('submit', async function (e) {
		e.preventDefault();
		const text = input.value.trim();
		if (text === '' || !currentThread) { return; }

		const sendBtn = document.getElementById('adminHubChatSendBtn');
		sendBtn.disabled = true;
		try {
			const formData = new FormData();
			formData.append('action', 'send');
			formData.append('type', currentThread.type);
			formData.append('target_id', currentThread.id);
			formData.append('body', text);
			formData.append('csrf_token', csrfToken);

			const res = await fetch(apiUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
			const data = await res.json();
			if (data && data.ok) {
				input.value = '';
				await pollNewMessages();
			}
		} catch (e) { /* tichy fail */ } finally {
			sendBtn.disabled = false;
			input.focus();
		}
	});

	slowPollTimer = setInterval(pollUnreadForBadgeOnly, 20000);
})();
</script>
<?php
}

function renderAdminFooter(): void {
	?>
	</div><!-- /col -->
</div><!-- /row -->
</div><!-- /container-fluid -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script>
// Admin Sidebar Mobile Toggle
document.addEventListener('DOMContentLoaded', function() {
	const sidebarToggle = document.getElementById('sidebarToggle');
	const sidebarWrapper = document.querySelector('.sidebar-wrapper');
	let sidebarOpen = false;

	if (sidebarToggle && sidebarWrapper) {
		sidebarToggle.addEventListener('click', function() {
			sidebarOpen = !sidebarOpen;
			if (sidebarOpen) {
				sidebarWrapper.classList.add('sidebar-open');
				sidebarToggle.innerHTML = '<i class="fas fa-times"></i>';
			} else {
				sidebarWrapper.classList.remove('sidebar-open');
				sidebarToggle.innerHTML = '<i class="fas fa-bars"></i>';
			}
		});

		// Zavřít sidebar při kliknutí na odkaz na mobilu
		const navLinks = sidebarWrapper.querySelectorAll('.nav-link');
		navLinks.forEach(link => {
			link.addEventListener('click', function() {
				if (window.innerWidth < 768) {
					sidebarOpen = false;
					sidebarWrapper.classList.remove('sidebar-open');
					sidebarToggle.innerHTML = '<i class="fas fa-bars"></i>';
				}
			});
		});

		// Zavřít sidebar při změně velikosti okna (z mobilu na desktop)
		window.addEventListener('resize', function() {
			if (window.innerWidth >= 768 && sidebarOpen) {
				sidebarOpen = false;
				sidebarWrapper.classList.remove('sidebar-open');
				sidebarToggle.innerHTML = '<i class="fas fa-bars"></i>';
			}
		});
	}
});
</script>
</body>
</html>
<?php
}
