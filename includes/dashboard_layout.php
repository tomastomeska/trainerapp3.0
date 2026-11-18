<?php

function dashboardLayoutEnsureSchema(PDO $pdo): void {
    require_once __DIR__ . '/dashboard_quick_menu.php';
    dashboardQuickMenuEnsureSchema($pdo);
    coachProfileOrderEnsureSchema($pdo);
}

function coachProfileOrderEnsureSchema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS coach_profile_order_preferences (
            coach_id INT UNSIGNED NOT NULL PRIMARY KEY,
            items_json TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function coachProfileOrderCatalog(): array {
    return [
        'intake' => 'Vstupní dotazník',
        'notifications' => 'Oznámení',
        'devices' => 'Přihlášená zařízení',
        'payments' => 'Platby za tréninky',
        'stats' => 'Statistiky',
        'basic_data' => 'Základní údaje',
        'agreement' => 'Dohoda s trenérem',
        'password' => 'Změna hesla',
        'latest_athletes' => 'Poslední přidaní sportovci',
        'agreement_responses' => 'Reakce sportovců na dohodu',
        'account_deletion' => 'Žádost o smazání účtu',
    ];
}

function coachProfileOrderValidate(array $items): void {
    $keys = array_keys(coachProfileOrderCatalog());
    if (
        !array_is_list($items) ||
        count($items) !== count($keys) ||
        count(array_filter($items, 'is_string')) !== count($items) ||
        count(array_unique($items)) !== count($items) ||
        array_diff($keys, $items) ||
        array_diff($items, $keys)
    ) {
        throw new InvalidArgumentException('Invalid coach profile order.');
    }
}

function coachProfileOrderSave(PDO $pdo, int $coachId, array $items): void {
    dashboardLayoutValidateUser('coach', $coachId);
    coachProfileOrderValidate($items);
    $stmt = $pdo->prepare(
        'INSERT INTO coach_profile_order_preferences (coach_id, items_json) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE items_json = VALUES(items_json)'
    );
    $stmt->execute([$coachId, json_encode(array_values($items), JSON_THROW_ON_ERROR)]);
}

function coachProfileOrderLoad(PDO $pdo, int $coachId): array {
    dashboardLayoutValidateUser('coach', $coachId);
    $stmt = $pdo->prepare('SELECT items_json FROM coach_profile_order_preferences WHERE coach_id = ?');
    $stmt->execute([$coachId]);
    $json = $stmt->fetchColumn();
    if ($json === false) {
        return array_keys(coachProfileOrderCatalog());
    }
    try {
        $items = json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new UnexpectedValueException('Invalid stored coach profile order.', 0, $e);
    }
    if (!is_array($items)) {
        throw new UnexpectedValueException('Invalid stored coach profile order.');
    }
    coachProfileOrderValidate($items);
    return $items;
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
