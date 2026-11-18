<?php
require_once __DIR__ . '/includes/auth.php';
try {
    if (isset($_SESSION['remember_selector']) || isset($_COOKIE[REMEMBER_COOKIE])) {
        rememberRevokeCurrent(getDB());
    }
} catch (Throwable $e) {
    error_log('Persistent logout failed: ' . get_class($e));
    http_response_code(503);
    exit('Odhlášení se nyní nepodařilo dokončit. Zkuste to prosím znovu.');
}
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
