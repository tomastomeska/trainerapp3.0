<?php
// admin/header.php – sdílená hlavička pro admin panel
function renderAdminHeader(string $title = ''): void {
	$admin   = getCurrentAdmin();
	$flash   = getFlash();
	$appName = APP_NAME . ' Admin';
	$fullTitle = $title ? "$title – $appName" : $appName;
	?>
<!DOCTYPE html>
<html lang="cs">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= h($fullTitle) ?></title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
	<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
	<style>
		.admin-badge { background: linear-gradient(135deg,#7c3aed,#4f46e5); }
		.sidebar { background:#1e1e2e; }
		.sidebar .nav-link { color: #a0aec0; border-radius: 6px; margin-bottom: 2px; }
		.sidebar .nav-link:hover, .sidebar .nav-link.active { background:#312e81; color:#fff; }
		.sidebar .nav-link i { width: 20px; }
		body { background: #f1f5f9; }
	</style>
</head>
<body>

<nav class="navbar navbar-dark shadow-sm sticky-top" style="background:#1e1e2e">
	<div class="container-fluid px-4">
		<a class="navbar-brand fw-bold" href="<?= BASE_URL ?>/admin/dashboard.php">
			<i class="fas fa-shield-halved me-2 text-violet" style="color:#a78bfa"></i>
			<span style="color:#a78bfa">Super</span><span class="text-white">Admin</span>
			<span class="badge ms-2 px-2 py-1 small admin-badge">TrainerApp</span>
		</a>
		<?php if ($admin): ?>
		<div class="d-flex align-items-center gap-3">
			<span class="text-secondary small">
				<i class="fas fa-user-shield me-1" style="color:#a78bfa"></i>
				<?= h($admin['name'] ?: $admin['username']) ?>
			</span>
			<a href="<?= BASE_URL ?>/logout_admin.php" class="btn btn-sm btn-outline-danger">
				<i class="fas fa-sign-out-alt me-1"></i>Odhlásit
			</a>
		</div>
		<?php endif; ?>
	</div>
</nav>

<div class="container-fluid">
<div class="row flex-nowrap">
	<!-- Sidebar -->
	<div class="col-auto p-0" style="width:220px">
		<div class="sidebar p-3">
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
				<a href="<?= BASE_URL ?>/admin/email_notifications.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'email_notifications.php' ? 'active' : '' ?>">
					<i class="fas fa-envelope me-2"></i>E-mailové notifikace
				</a>
				<a href="<?= BASE_URL ?>/admin/zpravy.php"
				   class="nav-link <?= in_array(basename($_SERVER['PHP_SELF']), ['zpravy.php','zprava_nova.php','zprava_detail.php']) ? 'active' : '' ?>">
					<i class="fas fa-comments me-2"></i>Zprávy trenérům
				</a>
				<a href="<?= BASE_URL ?>/admin/settings.php"
				   class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : '' ?>">
					<i class="fas fa-sliders me-2"></i>Nastavení
				</a>
			</div>
		</div>
	</div>

	<!-- Hlavní obsah -->
	<div class="col p-4" style="min-width:0;">
<?php if ($flash): ?>
<div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show" role="alert">
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
</body>
</html>
<?php
}
