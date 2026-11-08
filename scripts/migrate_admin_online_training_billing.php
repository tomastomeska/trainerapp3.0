<?php
require_once __DIR__ . '/../includes/admin_auth.php';
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/../admin/header.php';
    renderAdminHeader('Nastavení vyúčtování online tréninků');
    ?>
    <div class="card border-warning shadow-sm">
        <div class="card-body">
            <h1 class="h4">Připravit vyúčtování online tréninků</h1>
            <p>Připraví se interní vyúčtování online tréninků včetně QR plateb, upozornění na nové výzvy a podpory jejich rušení. Stávající výzvy zůstanou zachované.</p>
            <form method="post" onsubmit="return confirm('Vytvořit tabulky pro interní vyúčtování online tréninků?');">
                <?= csrfField() ?>
                <button class="btn btn-warning fw-bold">Připravit databázi</button>
                <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/admin/online_training_billing.php">Zpět</a>
            </form>
        </div>
    </div>
    <?php
    renderAdminFooter();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Neplatný bezpečnostní token. Vraťte se do administrace a spusťte migraci znovu.');
}

try {
    $pdo = getDB();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_online_training_rates (
            coach_id INT NOT NULL PRIMARY KEY,
            rate_per_training DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_customized TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_admin_online_rate_coach
                FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_online_training_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            coach_id INT NOT NULL,
            billing_month DATE NOT NULL,
            sent_trainings_count INT NOT NULL,
            rate_per_training DECIMAL(10,2) NOT NULL,
            rate_tier VARCHAR(20) NOT NULL DEFAULT 'legacy',
            amount DECIMAL(10,2) NOT NULL,
            status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
            issued_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_admin_online_training_invoice_month (coach_id, billing_month),
            KEY idx_admin_online_training_invoice_month (billing_month, status),
            CONSTRAINT fk_admin_online_invoice_coach
                FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_online_training_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            bank_account VARCHAR(64) NULL,
            default_rate_1_50 DECIMAL(10,2) NOT NULL DEFAULT 0,
            default_rate_51_150 DECIMAL(10,2) NOT NULL DEFAULT 0,
            default_rate_151_500 DECIMAL(10,2) NOT NULL DEFAULT 0,
            default_rate_501_plus DECIMAL(10,2) NOT NULL DEFAULT 0,
            rate_tiers_initialized TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $settingsColumns = $pdo->query('SHOW COLUMNS FROM admin_online_training_settings');
    $existingSettingsColumns = array_column($settingsColumns->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('rate_tiers_initialized', $existingSettingsColumns, true)) {
        $pdo->exec('ALTER TABLE admin_online_training_settings ADD COLUMN rate_tiers_initialized TINYINT(1) NOT NULL DEFAULT 0 AFTER bank_account');
    }
    foreach (['default_rate_1_50', 'default_rate_51_150', 'default_rate_151_500', 'default_rate_501_plus'] as $column) {
        if (!in_array($column, $existingSettingsColumns, true)) {
            $pdo->exec("ALTER TABLE admin_online_training_settings ADD COLUMN `{$column}` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER bank_account");
        }
    }
    $rateColumns = $pdo->query('SHOW COLUMNS FROM admin_online_training_rates');
    $existingRateColumns = array_column($rateColumns->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $tierColumns = ['rate_1_50', 'rate_51_150', 'rate_151_500', 'rate_501_plus'];
    foreach ($tierColumns as $column) {
        if (!in_array($column, $existingRateColumns, true)) {
            $pdo->exec("ALTER TABLE admin_online_training_rates ADD COLUMN `{$column}` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER rate_per_training");
        }
    }
    if (!in_array('is_customized', $existingRateColumns, true)) {
        $pdo->exec('ALTER TABLE admin_online_training_rates ADD COLUMN is_customized TINYINT(1) NOT NULL DEFAULT 0 AFTER rate_501_plus');
    }
    $pdo->exec('INSERT IGNORE INTO admin_online_training_settings (id) VALUES (1)');
    $ratesInitializedStmt = $pdo->query('SELECT rate_tiers_initialized FROM admin_online_training_settings WHERE id = 1');
    if ((int)$ratesInitializedStmt->fetchColumn() === 0) {
        $pdo->exec('UPDATE admin_online_training_rates SET rate_1_50 = 0, rate_51_150 = 0, rate_151_500 = 0, rate_501_plus = 0');
        $pdo->exec('UPDATE admin_online_training_settings SET rate_tiers_initialized = 1 WHERE id = 1');
    }
    $invoiceColumns = $pdo->query("SHOW COLUMNS FROM admin_online_training_invoices");
    $existingColumns = array_column($invoiceColumns->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('rate_tier', $existingColumns, true)) {
        $pdo->exec("ALTER TABLE admin_online_training_invoices ADD COLUMN rate_tier VARCHAR(20) NOT NULL DEFAULT 'legacy' AFTER rate_per_training");
    }
    if (!in_array('bank_account_at_issue', $existingColumns, true)) {
        $pdo->exec('ALTER TABLE admin_online_training_invoices ADD COLUMN bank_account_at_issue VARCHAR(64) NULL AFTER amount');
    }
    if (!in_array('cancelled_at', $existingColumns, true)) {
        $pdo->exec('ALTER TABLE admin_online_training_invoices ADD COLUMN cancelled_at DATETIME NULL AFTER paid_at');
    }
    if (!in_array('viewed_at', $existingColumns, true)) {
        $pdo->exec('ALTER TABLE admin_online_training_invoices ADD COLUMN viewed_at DATETIME NULL AFTER cancelled_at');
    }
    if (!in_array('payment_note_at_issue', $existingColumns, true)) {
        $pdo->exec('ALTER TABLE admin_online_training_invoices ADD COLUMN payment_note_at_issue VARCHAR(60) NULL AFTER bank_account_at_issue');
    }
    $pdo->exec(
        "ALTER TABLE admin_online_training_invoices
         MODIFY status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending'"
    );
    flash('success', 'Databáze vyúčtování online tréninků je připravená.');
} catch (Throwable $e) {
    error_log('Admin online training billing migration: ' . $e->getMessage());
    flash('danger', 'Databázi vyúčtování se nepodařilo připravit: ' . $e->getMessage());
}
redirect(BASE_URL . '/admin/online_training_billing.php');
