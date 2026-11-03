<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/performance_api.php';
if (!empty($_SESSION['coach_force_password_change']) || !empty($_SESSION['athlete_force_password_change'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo performanceJson(['success' => false, 'error' => 'Nejprve změňte své heslo v aplikaci.']);
    exit;
}
if (isLoggedIn()) performanceApiRespond('coach', (int)getCurrentCoachId(), true);
if (athleteIsLoggedIn()) performanceApiRespond('athlete', (int)getCurrentAthleteId(), true);
http_response_code(401);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo performanceJson(['success' => false, 'error' => 'Přihlášení je vyžadováno.']);
