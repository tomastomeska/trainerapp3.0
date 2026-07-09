<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
	header('Location: ' . BASE_URL . '/dashboard.php');
	exit;
}

if (athleteIsLoggedIn()) {
	header('Location: ' . BASE_URL . '/athlete_dashboard.php');
	exit;
}

header('Location: ' . BASE_URL . '/login.php');
exit;
