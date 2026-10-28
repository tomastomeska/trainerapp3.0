<?php

function dashboardLayoutEnsureSchema(PDO $pdo): void {
    require_once __DIR__ . '/dashboard_quick_menu.php';
    dashboardQuickMenuEnsureSchema($pdo);
}

function dashboardLayoutValidateUser(string $userType, int $userId): void {
    if (!in_array($userType, ['coach', 'athlete'], true) || $userId <= 0) {
        throw new InvalidArgumentException('Invalid dashboard preference user.');
    }
}

function dashboardSidebarCookieName(string $role, int $userId): string {
    dashboardLayoutValidateUser($role, $userId);
    return 'trainerapp_sidebar_' . $role . '_' . $userId;
}

function dashboardSidebarCollapsed(string $role, int $userId): bool {
    return ($_COOKIE[dashboardSidebarCookieName($role, $userId)] ?? '') === 'collapsed';
}

function dashboardSidebarBodyClass(string $role, int $userId): string {
    return 'dashboard-sidebar-layout' . (dashboardSidebarCollapsed($role, $userId) ? ' dashboard-sidebar-collapsed' : '');
}

function renderDashboardSidebarToggle(string $role, int $userId): void {
    $collapsed = dashboardSidebarCollapsed($role, $userId);
    $label = $collapsed ? 'Zobrazit menu' : 'Skrýt menu';
    ?>
    <div class="dashboard-sidebar-controls">
        <button type="button" class="btn btn-dark btn-sm" id="dashboard-sidebar-toggle" aria-controls="dashboard-sidebar-navigation" aria-expanded="<?= $collapsed ? 'false' : 'true' ?>" aria-label="<?= h($label) ?>" title="<?= h($label) ?>" data-cookie-name="<?= h(dashboardSidebarCookieName($role, $userId)) ?>" data-cookie-path="<?= h(BASE_URL !== '' ? BASE_URL : '/') ?>">
            <i class="fas fa-chevron-<?= $collapsed ? 'right' : 'left' ?>" aria-hidden="true"></i>
        </button>
    </div>
    <?php
}
