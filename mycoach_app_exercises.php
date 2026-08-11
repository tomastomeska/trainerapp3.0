<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$pdo = getDB(); $userId = (int)getCurrentCoachId(); $userType = 'coach';
$appUrl = BASE_URL . '/mycoach_app.php';
require_once __DIR__ . '/includes/header.php';
renderHeader('MyCoach – Encyklopedie cviků');
?>
<body class="mycoach-app-theme">
<?php require __DIR__ . '/includes/mycoach_app_exercises_body.php'; ?>
<?php renderFooter(); ?>
