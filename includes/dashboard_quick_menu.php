<?php

function dashboardQuickMenuCatalog(string $role): array {
    $common = [
        'messages' => 'Zprávy', 'calendar' => 'Kalendář', 'gallery' => 'Galerie',
        'events' => 'Events', 'online' => 'Online tréninky', 'mycoach' => 'MyCoach',
        'manual' => 'Návod', 'surveys' => 'Ankety', 'terms' => 'Podmínky',
        'mealplans' => 'Jídelníčky',
    ];
    if ($role === 'coach') {
        return [
            'athletes' => 'Sportovci', 'messages' => $common['messages'],
            'calendar' => $common['calendar'], 'info' => 'Infokanál',
            'exercises' => 'Cviky', 'sets' => 'Sady', 'payments' => 'Platby',
            'mealplans' => $common['mealplans'], 'gallery' => $common['gallery'],
            'profile' => 'Můj profil', 'events' => $common['events'], 'online' => $common['online'],
            'mycoach' => $common['mycoach'], 'surveys' => $common['surveys'],
            'manual' => $common['manual'], 'terms' => $common['terms'],
        ];
    }
    if ($role === 'athlete') {
        return [
            'messages' => $common['messages'], 'mealplans' => $common['mealplans'],
            'food' => 'Strava', 'graphs' => 'Grafy', 'calendar' => $common['calendar'],
            'password' => 'Heslo', 'gallery' => $common['gallery'], 'files' => 'Soubory',
            'videos' => 'Videa', 'events' => $common['events'], 'online' => $common['online'],
            'mycoach' => $common['mycoach'], 'health' => 'Zdravotní dotazník',
            'manual' => $common['manual'], 'surveys' => $common['surveys'], 'terms' => $common['terms'],
        ];
    }
    throw new InvalidArgumentException('Invalid quick menu role.');
}

function dashboardQuickMenuEnsureSchema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS dashboard_quick_menu_preferences (
            user_type ENUM('coach','athlete') NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            items_json TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_type, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function dashboardQuickMenuValidate(string $role, array $items): void {
    $catalog = dashboardQuickMenuCatalog($role);
    if (count($items) > count($catalog) || count(array_filter($items, 'is_string')) !== count($items)) {
        throw new InvalidArgumentException('Invalid quick menu items.');
    }
    if (count(array_unique($items)) !== count($items) || array_diff($items, array_keys($catalog))) {
        throw new InvalidArgumentException('Unknown or duplicate quick menu items.');
    }
}

function dashboardQuickMenuSave(PDO $pdo, string $role, int $userId, array $items): void {
    dashboardLayoutValidateUser($role, $userId);
    dashboardQuickMenuValidate($role, $items);
    $stmt = $pdo->prepare(
        'INSERT INTO dashboard_quick_menu_preferences (user_type, user_id, items_json) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE items_json = VALUES(items_json)'
    );
    $stmt->execute([$role, $userId, json_encode(array_values($items), JSON_THROW_ON_ERROR)]);
}

function dashboardQuickMenuLoad(PDO $pdo, string $role, int $userId): array {
    dashboardLayoutValidateUser($role, $userId);
    $stmt = $pdo->prepare('SELECT items_json FROM dashboard_quick_menu_preferences WHERE user_type = ? AND user_id = ?');
    $stmt->execute([$role, $userId]);
    $json = $stmt->fetchColumn();
    if ($json === false) {
        return array_keys(dashboardQuickMenuCatalog($role));
    }
    $items = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($items) || !array_is_list($items)) {
        throw new UnexpectedValueException('Invalid stored quick menu.');
    }
    dashboardQuickMenuValidate($role, $items);
    return $items;
}

function renderDashboardQuickMenu(string $role, int $userId, string $tiles): void {
    $catalog = dashboardQuickMenuCatalog($role);
    $available = true;
    try {
        $selected = dashboardQuickMenuLoad(getDB(), $role, $userId);
    } catch (PDOException | JsonException | UnexpectedValueException | InvalidArgumentException $e) {
        error_log('Dashboard quick menu: ' . $e->getMessage());
        $available = false;
        $selected = array_keys($catalog);
    }
    $ordered = array_merge($selected, array_diff(array_keys($catalog), $selected));
    ?>
    <section id="personal-quick-menu" class="personal-quick-menu card border-0 shadow-sm mb-3">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h2 class="small text-uppercase fw-bold text-muted mb-0">Rychlé menu</h2>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#quick-menu-all" aria-controls="quick-menu-all" aria-expanded="false">Všechny funkce</button>
                    <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#quick-menu-editor" aria-controls="quick-menu-editor" aria-expanded="false"<?= !$available ? ' disabled' : '' ?>>Upravit menu</button>
                </div>
            </div>
            <?php if (!$available): ?>
            <div class="alert alert-warning">Nastavení rychlého menu není dostupné. Zobrazeny jsou všechny funkce. Kontaktujte správce.</div>
            <?php endif; ?>
            <style>
                <?php foreach ($catalog as $key => $label): ?>
                #quick-menu-selected [data-shortcut="<?= h($key) ?>"] {
                    <?php $position = array_search($key, $selected, true); ?>
                    <?= $position === false ? 'display: none !important;' : 'order: ' . (int)$position . ';' ?>
                }
                <?php endforeach; ?>
            </style>
            <div id="quick-menu-selected"><?= $tiles ?></div>
            <?php if (!$selected): ?>
            <p class="text-muted mb-0">Rychlé menu je prázdné. Tlačítka přidáte přes „Upravit menu“ nebo otevřete „Všechny funkce“.</p>
            <?php endif; ?>
            <div class="collapse mt-3" id="quick-menu-all">
                <h3 class="h6">Všechny funkce</h3>
                <?= $tiles ?>
            </div>
            <div class="collapse mt-3" id="quick-menu-editor">
                <form method="post" action="<?= h(BASE_URL . '/dashboard_quick_menu.php') ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_type" value="<?= h($role) ?>">
                    <p class="small text-muted">Zaškrtněte požadovaná tlačítka. Šipkami upravíte jejich pořadí. Nastavení platí pro váš účet na všech zařízeních.</p>
                    <ul class="list-unstyled quick-menu-editor-list">
                        <?php foreach ($ordered as $key): ?>
                        <li class="d-flex align-items-center gap-2 border rounded p-2 mb-2" data-menu-row>
                            <label class="d-flex align-items-center gap-2 flex-grow-1">
                                <input class="form-check-input m-0" type="checkbox" name="items[]" value="<?= h($key) ?>"<?= in_array($key, $selected, true) ? ' checked' : '' ?>>
                                <?= h($catalog[$key]) ?>
                            </label>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-menu-move="-1" aria-label="<?= h('Posunout nahoru: ' . $catalog[$key]) ?>"><i class="fas fa-arrow-up" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-menu-move="1" aria-label="<?= h('Posunout dolů: ' . $catalog[$key]) ?>"><i class="fas fa-arrow-down" aria-hidden="true"></i></button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary"<?= !$available ? ' disabled' : '' ?>>Uložit menu</button>
                        <button type="submit" name="reset" value="1" class="btn btn-outline-secondary"<?= !$available ? ' disabled' : '' ?>>Obnovit výchozí menu</button>
                    </div>
                    <div class="visually-hidden" data-menu-announcement aria-live="polite"></div>
                </form>
            </div>
        </div>
    </section>
    <script src="<?= BASE_URL ?>/assets/js/dashboard-quick-menu.js?v=1"></script>
    <?php
}
