<?php
$isCli = PHP_SAPI === 'cli';
if ($isCli) {
    require_once __DIR__ . '/../config/database.php';
} else {
    require_once __DIR__ . '/../includes/admin_auth.php';
    requireAdminLogin();
}
require_once __DIR__ . '/../includes/intake_questionnaire.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    intakeEnsureCoachQuestionsTable($pdo);
    intakeEnsureDefaultQuestionsTable($pdo);
    intakeEnsureCoachSettingsTable($pdo);

    echo json_encode(['success' => true, 'message' => 'Vstupní dotazníky byly připraveny.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Intake questionnaire migration: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
