<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dashboard_layout.php';
require_once __DIR__ . '/includes/dashboard_quick_menu.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Nastavení menu vyžaduje odeslání formuláře.');
}
$role = $_POST['user_type'] ?? '';
if (!is_string($role) || !in_array($role, ['coach', 'athlete'], true)) {
    http_response_code(400);
    exit('Neplatný typ uživatele.');
}
if ($role === 'athlete') {
    requireAthleteLogin();
    $userId = (int)getCurrentAthleteId();
    $url = BASE_URL . '/athlete_dashboard.php';
} else {
    requireLogin();
    $userId = (int)getCurrentCoachId();
    $url = BASE_URL . '/dashboard.php';
}
$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !verifyCsrf($token)) {
    flash('danger', 'Neplatný bezpečnostní token. Zkuste úpravu menu znovu.');
} else {
    try {
        if (isset($_POST['reset'])) {
            $stmt = getDB()->prepare('DELETE FROM dashboard_quick_menu_preferences WHERE user_type = ? AND user_id = ?');
            $stmt->execute([$role, $userId]);
        } else {
            $items = $_POST['items'] ?? [];
            if (!is_array($items) || !array_is_list($items)) {
                throw new InvalidArgumentException('Invalid menu selection.');
            }
            dashboardQuickMenuSave(getDB(), $role, $userId, $items);
        }
        flash('success', isset($_POST['reset']) ? 'Výchozí rychlé menu bylo obnoveno.' : 'Rychlé menu bylo uloženo.');
    } catch (InvalidArgumentException $e) {
        flash('danger', 'Neplatný výběr tlačítek rychlého menu.');
    } catch (PDOException $e) {
        error_log('Dashboard quick menu save: ' . $e->getMessage());
        flash('danger', 'Nastavení menu se nepodařilo uložit. Zkuste to znovu nebo kontaktujte správce.');
    }
}
header('Location: ' . $url, true, 303);
exit;
