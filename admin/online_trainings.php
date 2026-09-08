<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
$pdo = getDB();
$tables = [
    'online_training_subscriptions',
    'workout_set_attachments',
    'online_trainings',
    'online_training_exercises',
    'online_training_series',
    'online_training_results',
    'online_training_attachments',
    'online_training_billing',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/online_trainings.php');
    }

    if (($_POST['action'] ?? '') === 'run_migration') {
        $oldSecret = $_GET['secret'] ?? null;
        $_GET['secret'] = getCronSecret();
        ob_start();
        require __DIR__ . '/../scripts/migrate_online_trainings.php';
        $migrationOutput = trim((string)ob_get_clean());
        if ($oldSecret === null) unset($_GET['secret']); else $_GET['secret'] = $oldSecret;
        $migrationResult = json_decode($migrationOutput, true);
        if (is_array($migrationResult) && !empty($migrationResult['success'])) {
            flash('success', 'Migrace Online tréninků proběhla úspěšně.');
        } else {
            $message = is_array($migrationResult) ? (string)($migrationResult['error'] ?? 'Neznámá chyba migrace.') : 'Migrace vrátila neočekávanou odpověď.';
            flash('danger', 'Migraci se nepodařilo dokončit: ' . $message);
        }
        redirect(BASE_URL . '/admin/online_trainings.php');
    }
}

$statusRows = [];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        $exists = $stmt !== false && (bool)$stmt->fetchColumn();
        $count = null;
        if ($exists) $count = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        $statusRows[] = ['table' => $table, 'exists' => $exists, 'count' => $count];
    } catch (Throwable $e) {
        $statusRows[] = ['table' => $table, 'exists' => false, 'count' => null];
    }
}
$rateColumnStmt = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'online_training_rate'");
$rateColumnExists = $rateColumnStmt !== false && (bool)$rateColumnStmt->fetch();
$ready = !in_array(false, array_column($statusRows, 'exists'), true) && $rateColumnExists;
$diagnostics = [
    'trainings' => null,
    'series' => null,
    'results' => null,
    'orphan_results' => null,
];
if ($ready) {
    try {
        $diagnostics['trainings'] = (int)$pdo->query('SELECT COUNT(*) FROM online_trainings')->fetchColumn();
        $diagnostics['series'] = (int)$pdo->query('SELECT COUNT(*) FROM online_training_series')->fetchColumn();
        $diagnostics['results'] = (int)$pdo->query('SELECT COUNT(*) FROM online_training_results')->fetchColumn();
        $diagnostics['orphan_results'] = (int)$pdo->query('SELECT COUNT(*) FROM online_training_results r LEFT JOIN online_trainings ot ON ot.id = r.online_training_id LEFT JOIN online_training_series s ON s.id = r.online_training_series_id WHERE ot.id IS NULL OR s.id IS NULL')->fetchColumn();
    } catch (Throwable $e) {
        $diagnostics = ['trainings' => null, 'series' => null, 'results' => null, 'orphan_results' => null];
    }
}

renderAdminHeader('Online tréninky');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1"><i class="fas fa-laptop me-2 text-warning"></i>Online tréninky</h1>
        <p class="text-muted mb-0">Kontrola databázové připravenosti modulu Online tréninky.</p>
    </div>
    <?php if (!$ready): ?>
    <form method="post" onsubmit="return confirm('Spustit migraci Online tréninků a vytvořit chybějící tabulky?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="run_migration">
        <button class="btn btn-warning fw-bold"><i class="fas fa-database me-1"></i>Spustit migraci nyní</button>
    </form>
    <?php else: ?>
    <span class="badge bg-success fs-6"><i class="fas fa-check me-1"></i>Schéma je připravené</span>
    <?php endif; ?>
</div>

<?php if ($ready): ?>
<div class="alert alert-success"><i class="fas fa-check-circle me-1"></i>Databáze je kompletní. Migraci není potřeba znovu spouštět.</div>
<?php else: ?>
<div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-1"></i>Databáze není kompletní. Spusťte migraci, která doplní chybějící tabulky nebo sloupce.</div>
<?php endif; ?>
<div class="alert alert-secondary small">
    Po migraci lze technickou kontrolu schématu spustit přes <code>scripts/test_online_trainings.php</code>. Test nic nevytváří ani nemění.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold">Stav databázových tabulek</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Objekt</th><th>Stav</th><th class="text-end">Počet řádků</th></tr></thead>
        <tbody><?php foreach ($statusRows as $row): ?><tr><td><code><?= h($row['table']) ?></code></td><td><?php if ($row['exists']): ?><span class="badge bg-success">Existuje</span><?php else: ?><span class="badge bg-danger">Chybí</span><?php endif; ?></td><td class="text-end"><?= $row['count'] === null ? '–' : number_format($row['count'], 0, ',', ' ') ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
</div>
<div class="card border-0 shadow-sm mb-4"><div class="card-body d-flex justify-content-between align-items-center"><code>athletes.online_training_rate</code><?php if ($rateColumnExists): ?><span class="badge bg-success">Existuje</span><?php else: ?><span class="badge bg-danger">Chybí</span><?php endif; ?></div></div>

<?php if ($ready): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold">Databázová diagnostika výsledků</div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><tbody>
        <tr><td>Online tréninky</td><td class="text-end fw-semibold"><?= $diagnostics['trainings'] === null ? '–' : number_format($diagnostics['trainings'], 0, ',', ' ') ?></td></tr>
        <tr><td>Předepsané série</td><td class="text-end fw-semibold"><?= $diagnostics['series'] === null ? '–' : number_format($diagnostics['series'], 0, ',', ' ') ?></td></tr>
        <tr><td>Uložené výsledky sérií</td><td class="text-end fw-semibold"><?= $diagnostics['results'] === null ? '–' : number_format($diagnostics['results'], 0, ',', ' ') ?></td></tr>
        <tr><td>Osiřelé výsledky bez vazby</td><td class="text-end"><span class="badge <?= $diagnostics['orphan_results'] === 0 ? 'bg-success' : 'bg-danger' ?>"><?= $diagnostics['orphan_results'] === null ? '–' : number_format($diagnostics['orphan_results'], 0, ',', ' ') ?></span></td></tr>
    </tbody></table></div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><h5><i class="fas fa-user-shield me-2"></i>Oprávnění</h5><p class="text-muted mb-0">Sportovec vidí pouze vlastní online tréninky. Trenér vidí pouze online tréninky svých sportovců.</p></div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><h5><i class="fas fa-wallet me-2"></i>Účtování</h5><p class="text-muted mb-0">Jednorázové položky a nákupy balíčků se ukládají s datem odeslání nebo nákupu.</p></div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><h5><i class="fas fa-database me-2"></i>Snapshot</h5><p class="text-muted mb-0">Online cviky a výsledky jsou oddělené od klasických tréninkových session.</p></div></div></div>
</div>
<?php renderAdminFooter(); ?>
