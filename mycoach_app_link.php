<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';
requireLogin();
$pdo      = getDB();
$userType = 'coach';
$userId   = (int)getCurrentCoachId();
$appUrl   = BASE_URL . '/mycoach_app.php';
require_once __DIR__ . '/includes/mycoach_app_link_body.php';
require_once __DIR__ . '/includes/footer.php';
