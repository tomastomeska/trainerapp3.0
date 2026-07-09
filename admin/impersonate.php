<?php
// admin/impersonate.php – přepnutí do profilu trenéra
require_once __DIR__ . '/../includes/admin_auth.php';

requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    flash('danger', 'Neplatný požadavek.');
    redirect(BASE_URL . '/admin/coaches.php');
}

$coachId = intParam($_POST, 'coach_id');
if ($coachId <= 0) {
    flash('danger', 'Neplatný trenér.');
    redirect(BASE_URL . '/admin/coaches.php');
}

$pdo  = getDB();
$stmt = $pdo->prepare('SELECT id, username, name, is_active FROM coaches WHERE id = ?');
$stmt->execute([$coachId]);
$coach = $stmt->fetch();

if (!$coach) {
    flash('danger', 'Trenér nenalezen.');
    redirect(BASE_URL . '/admin/coaches.php');
}

// Uložit identitu administrátora pro návrat
$_SESSION['impersonating_admin_id']   = $_SESSION['superadmin_id'];
$_SESSION['impersonating_admin_name'] = $_SESSION['superadmin_name'] ?? 'Admin';

// Nastavit identitu trenéra
$_SESSION['coach_id']   = $coach['id'];
$_SESSION['coach_name'] = $coach['name'] ?: $coach['username'];

// Resetovat session flag hlášky, aby modal nevyskakoval zbytečně
unset($_SESSION['login_msg_shown']);

redirect(BASE_URL . '/dashboard.php');
