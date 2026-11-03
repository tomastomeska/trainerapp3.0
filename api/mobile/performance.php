<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/performance_api.php';
$type = $_GET['account_type'] ?? 'athlete';
if ($type === 'coach') {
    $actor = mobileRequireCoach();
} elseif ($type === 'athlete') {
    $actor = mobileRequireAthlete();
} else mobileJson(['success' => false, 'error' => 'Neplatný typ účtu.'], 422);
performanceApiRespond($type, (int)$actor['id'], false);
