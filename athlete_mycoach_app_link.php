<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';
requireAthleteLogin();
$pdo      = getDB();
$userType = 'athlete';
$userId   = (int)getCurrentAthleteId();
$appUrl   = BASE_URL . '/athlete_mycoach_app.php';
require_once __DIR__ . '/includes/mycoach_app_link_body.php';
require_once __DIR__ . '/includes/footer.php';
