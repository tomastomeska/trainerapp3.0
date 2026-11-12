<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/coach_account_lifecycle.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$migrationError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'migrate') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/coach_account_migration.php');
    }

    try {
        coachAccountEnsureSchema($pdo);
        flash('success', 'Databázová migrace byla úspěšně provedena nebo již byla aktuální.');
    } catch (Throwable $e) {
        error_log('Coach account lifecycle migration failed from admin: ' . $e->getMessage());
        flash('danger', 'Migraci se nepodařilo provést: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/admin/coach_account_migration.php');
}

$requiredColumns = [
    'inactivity_deletion_notified_at',
    'inactivity_deletion_due_at',
    'block_reason',
];
$existingColumns = [];
try {
    foreach ($requiredColumns as $column) {
        $stmt = $pdo->query('SHOW COLUMNS FROM coaches LIKE ' . $pdo->quote($column));
        if ($stmt !== false && $stmt->fetch()) {
            $existingColumns[] = $column;
        }
    }
    $backupTableStmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote('coach_account_backups'));
    $backupTableReady = $backupTableStmt !== false && (bool)$backupTableStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('Coach account lifecycle schema check failed: ' . $e->getMessage());
    $migrationError = $e->getMessage();
    $backupTableReady = false;
}

$schemaReady = count($existingColumns) === count($requiredColumns) && $backupTableReady;
renderAdminHeader('Migrace účtů trenérů');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="fas fa-database me-2 text-primary"></i>Automatická správa neaktivních účtů</h4>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <p>Jednorázová migrace připraví databázi pro upozornění na neaktivitu, blokování s důvodem a roční archiv záloh.</p>
        <?php if ($migrationError !== null): ?>
        <div class="alert alert-danger">Kontrola databáze selhala: <?= h($migrationError) ?></div>
        <?php else: ?>
        <div class="alert alert-<?= $schemaReady ? 'success' : 'warning' ?>">
            <?= $schemaReady ? 'Databázová struktura je připravena.' : 'Databázovou migraci je potřeba spustit.' ?>
        </div>
        <?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="migrate">
            <button type="submit" class="btn btn-primary fw-bold">
                <i class="fas fa-play me-1"></i>Spustit / ověřit migraci
            </button>
        </form>
    </div>
</div>

<div class="alert alert-info">
    Zpracování se spouští v rámci již používaného <code>cron_email_notifications.php</code>, takže není potřeba přidávat další cron.
    Frekvence upozornění a mazání odpovídá intervalu tohoto existujícího cronu.
</div>

<?php renderAdminFooter(); ?>
