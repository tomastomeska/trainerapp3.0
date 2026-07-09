<?php
// admin/end_impersonate.php – návrat zpět do admin profilu
require_once __DIR__ . '/../includes/admin_auth.php';

if (empty($_SESSION['impersonating_admin_id'])) {
    // Není co ukončit
    redirect(BASE_URL . '/admin/coaches.php');
}

// Odstranit trenérskou identitu
unset($_SESSION['coach_id']);
unset($_SESSION['coach_name']);
unset($_SESSION['login_msg_shown']);

// Obnovit admin identitu (superadmin_id je stále nastaveno)
$adminName = $_SESSION['impersonating_admin_name'] ?? 'Admin';
unset($_SESSION['impersonating_admin_id']);
unset($_SESSION['impersonating_admin_name']);

flash('success', 'Přepnuto zpět do admin profilu.');
redirect(BASE_URL . '/admin/coaches.php');
