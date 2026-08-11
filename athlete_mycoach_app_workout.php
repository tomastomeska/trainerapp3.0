<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAthleteLogin();
$pdo = getDB(); $userId = (int)getCurrentAthleteId(); $userType = 'athlete';
$appUrl = BASE_URL . '/athlete_mycoach_app.php';
require_once __DIR__ . '/includes/header.php';
renderHeader('MyCoach – trénink');
?>
<body class="mycoach-app-theme">
<?php require __DIR__ . '/includes/mycoach_app_workout_body.php'; ?>
<?php renderFooter(); ?>
