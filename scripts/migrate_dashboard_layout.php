<?php
// Idempotent: creates the quick menu preference table. Run once after deploying: php scripts/migrate_dashboard_layout.php.
if (PHP_SAPI === 'cli') {
    require_once __DIR__ . '/../config/database.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    requireAdminLogin();
}
require_once __DIR__ . '/../includes/dashboard_layout.php';

header('Content-Type: application/json; charset=utf-8');

try {
    dashboardLayoutEnsureSchema(getDB());
    echo json_encode(['success' => true, 'message' => 'Nastavení rychlého menu je připraveno.'], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('Dashboard layout migration: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Migraci vzhledu dashboardu se nepodařilo provést. Podrobnosti jsou v logu.'], JSON_UNESCAPED_UNICODE);
    if (PHP_SAPI === 'cli') {
        exit(1);
    }
}
