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
	<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/medals.css">
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
	<?php
	// ---- Datově řízené menu: skupiny s rozklikávacími podsekcemi ----
	$currentPage     = basename($_SERVER['PHP_SELF']);
	$currentPlatform = (string)($_GET['platform'] ?? '');
	$adminUrl        = BASE_URL . '/admin/';

	$adminMenu = [
		[
			'type' => 'link', 'label' => 'Přehled', 'icon' => 'fa-gauge-high',
			'href' => 'dashboard.php', 'match' => ['dashboard.php'],
		],
		[
			'type' => 'group', 'id' => 'users', 'label' => 'Uživatelé', 'icon' => 'fa-users',
			'items' => [
				['label' => 'Trenéři', 'icon' => 'fa-user-tie', 'href' => 'coaches.php', 'match' => ['coaches.php', 'coach_add.php', 'coach_edit.php', 'coach_delete.php', 'coach_deleted_trainings.php', 'coach_athletes.php', 'coach_export.php']],
				['label' => 'Zálohy trenérů', 'icon' => 'fa-box-archive', 'href' => 'coach_backups.php', 'match' => ['coach_backups.php', 'coach_backup_download.php']],
				['label' => 'Migrace účtů trenérů', 'icon' => 'fa-database', 'href' => 'coach_account_migration.php', 'match' => ['coach_account_migration.php']],
				['label' => 'Sportovci', 'icon' => 'fa-person-running', 'href' => 'athletes.php', 'match' => ['athletes.php', 'athlete_add.php', 'athlete_edit.php', 'athlete_delete.php', 'athlete_detail.php']],
				['label' => 'Import sportovců', 'icon' => 'fa-file-import', 'href' => 'athlete_import.php', 'match' => ['athlete_import.php']],
			],
		],
		[
			'type' => 'group', 'id' => 'trainings', 'label' => 'Tréninky', 'icon' => 'fa-dumbbell',
			'items' => [
				['label' => 'Přidat trénink', 'icon' => 'fa-calendar-plus', 'href' => 'training_add.php', 'match' => ['training_add.php']],
				['label' => 'Import tréninků CSV', 'icon' => 'fa-file-csv', 'href' => 'training_bulk.php', 'match' => ['training_bulk.php']],
				['label' => 'Online tréninky', 'icon' => 'fa-laptop', 'href' => 'online_trainings.php', 'match' => ['online_trainings.php']],
				['label' => 'Vyúčtování online', 'icon' => 'fa-file-invoice-dollar', 'href' => 'online_training_billing.php', 'match' => ['online_training_billing.php']],
				['label' => 'Sportoviště', 'icon' => 'fa-map-location-dot', 'href' => 'venues.php', 'match' => ['venues.php']],
				['label' => 'Events', 'icon' => 'fa-flag-checkered', 'href' => 'events.php', 'match' => ['events.php']],
			],
		],
		[
			'type' => 'group', 'id' => 'library', 'label' => 'Globální obsah', 'icon' => 'fa-layer-group',
			'items' => [
				['label' => 'Cviky', 'icon' => 'fa-globe', 'href' => 'exercises.php', 'match' => ['exercises.php', 'exercise_export.php', 'exercise_import.php']],
				['label' => 'Sady', 'icon' => 'fa-boxes-stacked', 'href' => 'global_workout_sets.php', 'match' => ['global_workout_sets.php']],
				['label' => 'Jídla', 'icon' => 'fa-utensils', 'href' => 'meals.php', 'match' => ['meals.php']],
				['label' => 'Medaile', 'icon' => 'fa-medal', 'href' => 'medals.php', 'match' => ['medals.php']],
				['label' => 'Galerie', 'icon' => 'fa-images', 'href' => 'gallery.php', 'match' => ['gallery.php']],
			],
		],
		[
			'type' => 'group', 'id' => 'mycoach', 'label' => 'MyCoach', 'icon' => 'fa-brain',
			'items' => [
				['label' => 'Administrace', 'icon' => 'fa-gears', 'href' => 'mycoach.php', 'match' => ['mycoach.php']],
				['label' => 'Obsah', 'icon' => 'fa-folder-open', 'href' => 'mycoach_content.php', 'match' => ['mycoach_content.php']],
			],
		],
		[
			'type' => 'group', 'id' => 'forms', 'label' => 'Dotazníky a ankety', 'icon' => 'fa-clipboard-question',
			'items' => [
				['label' => 'Zdravotní dotazník', 'icon' => 'fa-heart-pulse', 'href' => 'health_questionnaire.php', 'match' => ['health_questionnaire.php']],
				['label' => 'Vstupní dotazník', 'icon' => 'fa-clipboard-list', 'href' => 'intake_questionnaire.php', 'match' => ['intake_questionnaire.php']],
				['label' => 'Ankety a dotazníky', 'icon' => 'fa-square-poll-vertical', 'href' => 'surveys.php', 'match' => ['surveys.php']],
			],
		],
		[
			'type' => 'group', 'id' => 'comms', 'label' => 'Komunikace', 'icon' => 'fa-comments',
			'items' => [
				['label' => 'Zprávy trenérům', 'icon' => 'fa-user-tie', 'href' => 'zpravy.php', 'match' => ['zpravy.php', 'zprava_nova.php', 'zprava_detail.php', 'zprava_trener_chat.php'], 'badge' => $coachChatUnreadTotal],
				['label' => 'Zprávy sportovcům', 'icon' => 'fa-bullhorn', 'href' => 'zprava_sportovci.php', 'match' => ['zprava_sportovci.php', 'zprava_sportovci_detail.php', 'zprava_sportovec_chat.php'], 'badge' => $athleteChatUnreadTotal],
				['label' => 'Podpora', 'icon' => 'fa-life-ring', 'href' => 'podpora.php', 'match' => ['podpora.php'], 'badge' => $supportNewCount],
				['label' => 'Infokanál', 'icon' => 'fa-lightbulb', 'href' => 'infokanal.php', 'match' => ['infokanal.php']],
				['label' => 'Hláška po přihlášení', 'icon' => 'fa-bell', 'href' => 'login_message.php', 'match' => ['login_message.php']],
				['label' => 'E-mailové notifikace', 'icon' => 'fa-envelope', 'href' => 'email_notifications.php', 'match' => ['email_notifications.php']],
			],
		],
		[
			'type' => 'group', 'id' => 'apps', 'label' => 'Aplikace', 'icon' => 'fa-mobile-screen',
			'items' => [
				['label' => 'Android', 'icon' => 'fa-android', 'brand' => true, 'href' => 'apps.php?platform=android', 'match' => [], 'active' => $currentPage === 'apps.php' && $currentPlatform !== 'ios'],
				['label' => 'Apple', 'icon' => 'fa-apple', 'brand' => true, 'href' => 'apps.php?platform=ios', 'match' => [], 'active' => $currentPage === 'apps.php' && $currentPlatform === 'ios'],
			],
		],
		[
			'type' => 'group', 'id' => 'tools', 'label' => 'Nástroje', 'icon' => 'fa-screwdriver-wrench',
			'items' => [
				['label' => 'Kalkulačky', 'icon' => 'fa-calculator', 'href' => 'calculators.php', 'match' => ['calculators.php'], 'tag' => 'Ve vývoji'],
				['label' => 'Návod', 'icon' => 'fa-book-open', 'href' => 'manual.php', 'match' => ['manual.php']],
				['label' => 'Errorlog', 'icon' => 'fa-triangle-exclamation', 'href' => 'errorlog.php', 'match' => ['errorlog.php', 'errorlog_user.php']],
			],
		],
		[
			'type' => 'link', 'label' => 'Nastavení', 'icon' => 'fa-sliders',
			'href' => 'settings.php', 'match' => ['settings.php'],
		],
	];
	?>
	<div class="col-auto p-0 sidebar-wrapper admin-sidebar-wrapper" id="adminSidebar">
		<div class="sidebar admin-sidebar">
			<nav class="admin-nav">
				<?php foreach ($adminMenu as $menuEntry): ?>
					<?php if ($menuEntry['type'] === 'link'): ?>
						<?php $entryActive = in_array($currentPage, $menuEntry['match'], true); ?>
				<a href="<?= $adminUrl . $menuEntry['href'] ?>" class="admin-nav-link<?= $entryActive ? ' active' : '' ?>">
					<i class="fas <?= $menuEntry['icon'] ?>"></i><span class="admin-nav-label"><?= h($menuEntry['label']) ?></span>
				</a>
					<?php else: ?>
						<?php
						$groupActive = false;
						$groupBadge  = 0;
						foreach ($menuEntry['items'] as $menuItem) {
							$itemActive = $menuItem['active'] ?? in_array($currentPage, $menuItem['match'], true);
							if ($itemActive) { $groupActive = true; }
							$groupBadge += (int)($menuItem['badge'] ?? 0);
						}
						?>
				<div class="admin-nav-group<?= $groupActive ? ' is-open is-active' : '' ?>" data-group="<?= h($menuEntry['id']) ?>">
					<button type="button" class="admin-nav-toggle" aria-expanded="<?= $groupActive ? 'true' : 'false' ?>">
						<i class="fas <?= $menuEntry['icon'] ?>"></i><span class="admin-nav-label"><?= h($menuEntry['label']) ?></span>
						<?php if ($groupBadge > 0): ?><span class="admin-nav-badge"><?= $groupBadge ?></span><?php endif; ?>
						<i class="fas fa-chevron-down admin-nav-chevron"></i>
					</button>
					<div class="admin-nav-items">
						<div class="admin-nav-items-inner">
							<?php foreach ($menuEntry['items'] as $menuItem): ?>
								<?php $itemActive = $menuItem['active'] ?? in_array($currentPage, $menuItem['match'], true); ?>
							<a href="<?= $adminUrl . $menuItem['href'] ?>" class="admin-nav-sublink<?= $itemActive ? ' active' : '' ?>">
								<i class="<?= !empty($menuItem['brand']) ? 'fab' : 'fas' ?> <?= $menuItem['icon'] ?>"></i><span class="admin-nav-label"><?= h($menuItem['label']) ?></span>
								<?php if (!empty($menuItem['tag'])): ?><span class="admin-nav-tag"><?= h($menuItem['tag']) ?></span><?php endif; ?>
								<?php if (!empty($menuItem['badge'])): ?><span class="admin-nav-badge"><?= (int)$menuItem['badge'] ?></span><?php endif; ?>
							</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>
		</div>
	</div>
	<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>

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
	background: linear-gradient(135deg, #6366f1, #4338ca); color: #fff; font-size: 22px;
	box-shadow: 0 10px 24px rgba(79, 70, 229, 0.4); z-index: 1080;
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
	background: linear-gradient(135deg, #4f46e5, #312e81); color: #fff; padding: 12px 14px; font-weight: 600; display: flex; align-items: center; gap: 4px;
}
.admin-chat-panel-header #adminHubChatTitle { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.admin-chat-back-btn { border: none; background: none; color: #fff; padding: 0 8px 0 0; font-size: 16px; }
.admin-chat-panel-body { flex: 1; overflow-y: auto; padding: 12px; background: #eef0fa; }
#adminHubChatHub { padding: 0; background: #fff; }
.admin-chat-hub-item {
	display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 14px; border-bottom: 1px solid #eee; cursor: pointer; color: #111; background: #fff;
}
.admin-chat-hub-item:hover { background: #f7f7f7; }
.admin-chat-hub-item .name { font-weight: 600; font-size: 14px; }
.admin-chat-hub-item .name i { margin-right: 6px; color: #6366f1; }
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
.admin-chat-bubble-row.from-admin .admin-chat-bubble { background: #e0e7ff; }
.admin-chat-bubble-time { display: block; margin-top: 3px; font-size: 10.5px; color: #667781; text-align: right; }
.admin-chat-read-status { margin-left: 4px; font-weight: 700; letter-spacing: -1px; }
.admin-chat-read-status.is-read { color: #4f46e5; }
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
// Admin menu – rozbalovací skupiny + mobilní off-canvas sidebar
document.addEventListener('DOMContentLoaded', function () {
	// --- Rozbalovací skupiny menu (stav se pamatuje v localStorage) ---
	var STORAGE_KEY = 'adminNavOpenGroups';
	var openGroups = [];
	try { openGroups = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); } catch (e) { openGroups = []; }
	if (!Array.isArray(openGroups)) { openGroups = []; }

	document.querySelectorAll('.admin-nav-group').forEach(function (group) {
		var id = group.getAttribute('data-group');
		if (openGroups.indexOf(id) !== -1) { group.classList.add('is-open'); }
		var toggle = group.querySelector('.admin-nav-toggle');
		if (!toggle) { return; }
		toggle.addEventListener('click', function () {
			var isOpen = group.classList.toggle('is-open');
			toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			var idx = openGroups.indexOf(id);
			if (isOpen && idx === -1) { openGroups.push(id); }
			if (!isOpen && idx !== -1) { openGroups.splice(idx, 1); }
			try { localStorage.setItem(STORAGE_KEY, JSON.stringify(openGroups)); } catch (e) { /* ignore */ }
		});
	});

	// --- Mobilní sidebar: off-canvas + backdrop + zámek scrollu ---
	var sidebarToggle = document.getElementById('sidebarToggle');
	var sidebar = document.getElementById('adminSidebar');
	var backdrop = document.getElementById('adminSidebarBackdrop');

	function setSidebar(open) {
		if (!sidebar) { return; }
		sidebar.classList.toggle('sidebar-open', open);
		if (backdrop) { backdrop.classList.toggle('show', open); }
		document.body.classList.toggle('admin-nav-open', open);
		if (sidebarToggle) {
			sidebarToggle.innerHTML = open ? '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
			sidebarToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		}
	}

	if (sidebarToggle && sidebar) {
		sidebarToggle.addEventListener('click', function () {
			setSidebar(!sidebar.classList.contains('sidebar-open'));
		});
	}

	if (backdrop) {
		backdrop.addEventListener('click', function () { setSidebar(false); });
	}

	// Zavřít menu po kliknutí na odkaz (mobil)
	if (sidebar) {
		sidebar.querySelectorAll('.admin-nav-link, .admin-nav-sublink').forEach(function (link) {
			link.addEventListener('click', function () {
				if (window.innerWidth < 768) { setSidebar(false); }
			});
		});
	}

	// Reset při přechodu na desktop
	window.addEventListener('resize', function () {
		if (window.innerWidth >= 768 && sidebar && sidebar.classList.contains('sidebar-open')) {
			setSidebar(false);
		}
	});
});
</script>
</body>
</html>
<?php
}
