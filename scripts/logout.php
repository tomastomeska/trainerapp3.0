<?php
require_once __DIR__ . '/includes/auth.php';
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
