<?php
// CLI integration test. Uses a disposable database on a local MySQL/MariaDB server.
// DASHBOARD_TEST_DSN defaults to mysql:host=127.0.0.1;port=33317;charset=utf8mb4.
// Optional credentials: DASHBOARD_TEST_USER, DASHBOARD_TEST_PASSWORD.
// Also verifies beta-only desktop sidebar markup for both roles.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/dashboard_layout.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/athlete_header.php';

define('BASE_URL', '/trainerapp-test');
define('APP_NAME', 'Dashboard test');
$_SESSION = ['csrf_token' => 'test-csrf-token', 'login_msg_shown' => true];
$testRole = 'coach';
$checks = 0;

function getDB(): PDO {
    return $GLOBALS['testPdo'];
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '">';
}

function getFlash(): ?array {
    return null;
}

function getCurrentCoach(): ?array {
    return $GLOBALS['testRole'] === 'coach'
        ? ['id' => 101, 'name' => 'Test Coach', 'username' => 'test']
        : null;
}

function getCurrentAthlete(): ?array {
    return ['id' => 101, 'coach_id' => 101, 'first_name' => 'Test', 'last_name' => 'Athlete'];
}

function athleteIsLoggedIn(): bool {
    return $GLOBALS['testRole'] === 'athlete';
}

function isLoggedIn(): bool {
    return $GLOBALS['testRole'] === 'coach';
}

function getCurrentAthleteId(): int {
    return 101;
}

function mycoachAppEnabled(): bool {
    return false;
}

$check = static function (bool $passed, string $message) use (&$checks): void {
    if (!$passed) {
        throw new RuntimeException($message);
    }
    $checks++;
};

$dsn = getenv('DASHBOARD_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=33317;charset=utf8mb4';
if (!preg_match('/^mysql:host=127\.0\.0\.1(?:;|$)/', $dsn)) {
    throw new RuntimeException('Tests require an explicitly local MySQL server (host=127.0.0.1).');
}
$user = getenv('DASHBOARD_TEST_USER') ?: 'root';
$password = getenv('DASHBOARD_TEST_PASSWORD') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO($dsn, $user, $password, $options);
$database = 'trainerapp_dashboard_test_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");

try {
    $testPdo = new PDO($dsn . ';dbname=' . $database, $user, $password, $options);
    dashboardLayoutEnsureSchema($testPdo);
    dashboardLayoutEnsureSchema($testPdo);

    foreach (['coach', 'athlete'] as $testRole) {
        foreach ([false, true] as $sidebar) {
            ob_start();
            if ($testRole === 'coach') {
                renderHeader('Test', false, true, $sidebar);
            } else {
                renderAthleteHeader('Test', false, true, $sidebar);
            }
            $html = ob_get_clean();
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new DOMXPath($dom);
            $check(str_contains($html, 'dashboard-sidebar.css') === $sidebar, 'Only beta may load sidebar styles.');
            $check($xpath->query('//body[@class="dashboard-sidebar-layout"]')->length === (int)$sidebar, 'Only beta may opt into the sidebar layout.');
            $sidebars = $xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " dashboard-sidebar ")]');
            $check($sidebars->length === (int)$sidebar, 'Sidebar must be present only when requested.');
            $check($xpath->query('//button[@id="dashboard-sidebar-toggle" and @aria-controls="dashboard-sidebar-navigation" and @aria-expanded="true"]')->length === (int)$sidebar, 'Only beta must render an accessible sidebar toggle.');
            $check(str_contains($html, 'dashboard-sidebar.js') === $sidebar, 'Only beta may load the sidebar toggle script.');
            if ($sidebar) {
                $nav = $sidebars->item(0);
                if (!$nav instanceof DOMElement) {
                    throw new RuntimeException('Sidebar navigation element was not found.');
                }
                $check($nav->getAttribute('id') === 'dashboard-sidebar-navigation', 'Toggle must target the sidebar navigation.');
                $check($xpath->query('.//a[contains(@href, "calendar.php")]', $nav)->length > 0, 'Sidebar must retain role navigation.');
                $check(str_contains($nav->textContent, 'Infokanál'), 'Sidebar must label the information channel.');
            }
        }
    }

    foreach (['coach', 'athlete'] as $testRole) {
        $_COOKIE[dashboardSidebarCookieName($testRole, 101)] = 'collapsed';
        foreach (['calendar', 'profile', 'messages'] as $pageTitle) {
            ob_start();
            if ($testRole === 'coach') {
                renderHeader($pageTitle);
            } else {
                renderAthleteHeader($pageTitle);
            }
            $html = ob_get_clean();
            $check(str_contains($html, 'dashboard-sidebar-layout dashboard-sidebar-collapsed'), 'Every opted-in subpage must restore the collapsed sidebar.');
            $check(str_contains($html, 'aria-expanded="false"'), 'Arrow must reflect restored collapsed state.');
            $check(str_contains($html, 'fa-chevron-right'), 'Collapsed sidebar must have a reveal arrow.');
        }
        unset($_COOKIE[dashboardSidebarCookieName($testRole, 101)]);
    }
    $check(dashboardSidebarCookieName('coach', 101) !== dashboardSidebarCookieName('athlete', 101), 'Sidebar state must be isolated by role.');
    $_COOKIE[dashboardSidebarCookieName('coach', 101)] = 'collapsed';
    $check(!dashboardSidebarCollapsed('athlete', 101), 'Coach state must not affect athletes.');
    $check(!dashboardSidebarCollapsed('coach', 102), 'Sidebar state must be isolated by account.');
    unset($_COOKIE[dashboardSidebarCookieName('coach', 101)]);

    // Athlete MyCoach pages use the coach-style shared header.
    $testRole = 'athlete';
    ob_start();
    renderHeader('MyCoach');
    $html = ob_get_clean();
    $check(str_contains($html, '/athlete_calendar.php'), 'Athlete MyCoach must use athlete navigation.');
    $check(!str_contains($html, 'href="/trainerapp-test/exercises.php"'), 'Athlete MyCoach must not expose coach navigation.');

    foreach (['dashboard', 'athlete_dashboard'] as $name) {
        $beta = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../includes/' . $name . '_new.php'));
        $menu = $name === 'athlete_dashboard'
            ? str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../includes/athlete_quick_menu.php'))
            : $beta;
        if ($name === 'athlete_dashboard') {
            $main = file_get_contents(__DIR__ . '/../athlete_dashboard.php');
            $check(str_contains($beta, "athlete_quick_menu.php") && str_contains($main, "athlete_quick_menu.php"), 'Both athlete dashboards must use the shared quick menu.');
            $newDashboardPosition = strpos($main, "require __DIR__ . '/includes/athlete_dashboard_new.php';");
            $legacyHeaderPosition = strpos($main, "renderAthleteHeader('Profil sportovce', false, true);");
            $check(
                $newDashboardPosition !== false
                && $legacyHeaderPosition !== false
                && $newDashboardPosition < $legacyHeaderPosition,
                'Athlete dashboard must render the new template before the legacy dashboard.'
            );
            $quickMenuPosition = strpos($beta, "require __DIR__ . '/athlete_quick_menu.php';");
            $desktopPlanPosition = strpos($beta, '<div class="card border-0 shadow-sm mb-4 athlete-desktop-only">');
            $mobileNextTrainingPosition = strpos($beta, 'class="athlete-mobile-summary-card mb-3 athlete-mobile-next-training"');
            $mobileProfilePosition = strpos($beta, 'class="athlete-mobile-summary-card mb-3 athlete-mobile-profile-photo"');
            $accountDeletionPosition = strpos($beta, 'id="athleteAccountDeletionForm"');
            $check(
                $quickMenuPosition !== false
                && $desktopPlanPosition !== false
                && $quickMenuPosition < $desktopPlanPosition
                && $desktopPlanPosition < $mobileNextTrainingPosition
                && $mobileNextTrainingPosition < $mobileProfilePosition
                && $mobileProfilePosition < $accountDeletionPosition,
                'Training plan must immediately follow the quick menu on desktop and mobile.'
            );
            $detailsPosition = strpos($beta, '<div class="athlete-dashboard-details" id="athlete-dashboard-details">');
            $activityGridPosition = strpos($beta, '<div class="athlete-activity-grid mb-4 athlete-desktop-only">');
            $structuralHtml = preg_replace('/<\?.*?\?>/s', '', $beta);
            $dashboardDom = new DOMDocument();
            @$dashboardDom->loadHTML('<?xml encoding="UTF-8">' . $structuralHtml);
            $dashboardXPath = new DOMXPath($dashboardDom);
            $check(
                $detailsPosition !== false
                && $activityGridPosition !== false
                && $detailsPosition < $activityGridPosition,
                'Activity grid must follow the container-query wrapper.'
            );
            $check(
                $dashboardXPath->query('//*[@id="athlete-dashboard-details"]/div[contains(concat(" ", normalize-space(@class), " "), " athlete-activity-grid ")]')->length === 1,
                'Activity grid must be a full-width row inside the container-query wrapper.'
            );
        }
        $check(str_contains($menu, 'renderDashboardQuickMenu('), 'Beta must render a personalized quick menu: ' . $name);
        $check(str_contains($beta, ', false, true, true);'), 'Both beta views must request desktop sidebar navigation.');
        preg_match_all('/data-shortcut="([a-z]+)"/', $menu, $tileKeys);
        $role = $name === 'dashboard' ? 'coach' : 'athlete';
        $check(!array_diff(array_keys(dashboardQuickMenuCatalog($role)), $tileKeys[1]), 'Every catalog entry must have a tile.');
        $check(!array_diff($tileKeys[1], array_keys(dashboardQuickMenuCatalog($role))), 'Every tile must have a catalog entry.');
    }

    foreach (['coach', 'athlete'] as $role) {
        $defaults = array_keys(dashboardQuickMenuCatalog($role));
        $check(dashboardQuickMenuLoad($testPdo, $role, 200) === $defaults, 'All shortcuts must be visible by default.');
        $selection = ['terms', 'messages', 'calendar'];
        dashboardQuickMenuSave($testPdo, $role, 200, $selection);
        $check(dashboardQuickMenuLoad($testPdo, $role, 200) === $selection, 'Selection and order must persist.');
        $check(dashboardQuickMenuLoad($testPdo, $role, 201) === $defaults, 'Other accounts must retain default shortcuts.');
        dashboardQuickMenuSave($testPdo, $role, 202, []);
        $check(dashboardQuickMenuLoad($testPdo, $role, 202) === [], 'Empty selection must be supported.');
        foreach ([['unknown'], ['messages', 'messages'], [['nested']]] as $invalid) {
            $rejected = false;
            try {
                dashboardQuickMenuSave($testPdo, $role, 200, $invalid);
            } catch (InvalidArgumentException $e) {
                $rejected = true;
            }
            $check($rejected, 'Unknown, duplicate and nested selections must be rejected.');
        }
        ob_start();
        renderDashboardQuickMenu($role, 200, '<div class="dashboard-quick-tiles"><a data-shortcut="calendar">Calendar</a></div>');
        $html = ob_get_clean();
        $check(str_contains($html, 'id="quick-menu-all"'), 'Hidden functions must remain accessible.');
        $check(str_contains($html, 'name="csrf_token"'), 'Editor must be CSRF protected.');
        $check(strpos($html, 'value="terms"') < strpos($html, 'value="messages"'), 'Editor must reflect saved order.');
        $check(!str_contains($html, 'data-shortcut="mycoach"') && !str_contains($html, 'value="mycoach"'), 'Disabled MyCoach must not appear in the quick menu or editor.');
    }
    dashboardQuickMenuSave($testPdo, 'coach', 203, ['athletes']);
    $check(dashboardQuickMenuLoad($testPdo, 'athlete', 203) === array_keys(dashboardQuickMenuCatalog('athlete')), 'Coach and athlete shortcuts must be isolated.');
    $fresh = new PDO($dsn . ';dbname=' . $database, $user, $password, $options);
    $check(dashboardQuickMenuLoad($fresh, 'coach', 203) === ['athletes'], 'Shortcuts must persist across devices.');

    $testPdo->exec('DROP TABLE dashboard_quick_menu_preferences');
    ob_start();
    renderDashboardQuickMenu('coach', 203, '<div class="dashboard-quick-tiles"></div>');
    $html = ob_get_clean();
    $check(str_contains($html, 'Kontaktujte správce.') && str_contains($html, ' disabled'), 'Missing shortcut storage must be reported and editing disabled.');

    echo "PASS: {$checks} dashboard preference, persistence, isolation, navigation and compatibility checks." . PHP_EOL;
} finally {
    $server->exec("DROP DATABASE `{$database}`");
}
