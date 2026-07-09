<?php
// admin/header.php – sdílená hlavička pro admin panel
function renderAdminHeader(string $title = ''): void {
	$admin   = getCurrentAdmin();
	$flash   = getFlash();
	$appName = APP_NAME . ' Admin';
	$fullTitle = $title ? "$title – $appName" : $appName;
	$supportNewCount = 0;
	try {
		$pdo = getDB();
		$supportNewCount = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'new'")->fetchColumn();
	} catch (Throwable $e) {
		$supportNewCount = 0;
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
				<a href="<?= BASE_URL ?>/admin/exercises.php"
				   class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['exercises.php','exercise_export.php','exercise_import.php']) ? 'active' : '' ?>">
					<i class="fas fa-globe me-2"></i>Globální cviky
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
				   class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['zpravy.php','zprava_nova.php','zprava_detail.php']) ? 'active' : '' ?>">
					<i class="fas fa-comments me-2"></i>Zprávy trenérům
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
