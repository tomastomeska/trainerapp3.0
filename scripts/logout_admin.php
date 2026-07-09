<?php
// logout_admin.php
require_once __DIR__ . '/includes/admin_auth.php';

// Odhlásit pouze admina, ponechat případnou coach session
unset($_SESSION['superadmin_id'], $_SESSION['superadmin_name']);
session_regenerate_id(true);

redirect(adminBaseUrl() . '/login_admin.php');
