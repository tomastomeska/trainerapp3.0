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

    if (($_POST['action'] ?? '') === 'delete_online_training') {
        $trainingId = (int)($_POST['training_id'] ?? 0);
        try {
            $trainingStmt = $pdo->prepare('SELECT id, athlete_id, sequence_number FROM online_trainings WHERE id = ? LIMIT 1');
            $trainingStmt->execute([$trainingId]);
            $training = $trainingStmt->fetch();
            if (!$training) throw new RuntimeException('Online trénink nebyl nalezen.');

            $filesStmt = $pdo->prepare('SELECT file_path FROM online_training_attachments WHERE online_training_id = ? AND file_path IS NOT NULL');
            $filesStmt->execute([$trainingId]);
            $filePaths = array_column($filesStmt->fetchAll(), 'file_path');

            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM online_training_billing WHERE online_training_id = ?')->execute([$trainingId]);
            $pdo->prepare('DELETE FROM online_trainings WHERE id = ?')->execute([$trainingId]);
            $pdo->commit();

            foreach ($filePaths as $filePath) {
                $relativePath = ltrim(str_replace('\\', '/', (string)$filePath), '/');
                if (str_starts_with($relativePath, 'online_trainings/')) {
                    $fullPath = dirname(__DIR__) . '/uploads/' . $relativePath;
                    if (is_file($fullPath)) @unlink($fullPath);
                }
            }
            flash('success', 'Online trénink #' . str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT) . ' byl včetně výsledků, příloh a účetních položek trvale smazán.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('danger', 'Online trénink se nepodařilo smazat: ' . $e->getMessage());
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
$billingMonthColumnStmt = $pdo->query("SHOW COLUMNS FROM online_training_billing LIKE 'billing_month'");
$billingMonthColumnExists = $billingMonthColumnStmt !== false && (bool)$billingMonthColumnStmt->fetch();
$ready = !in_array(false, array_column($statusRows, 'exists'), true) && $rateColumnExists && $billingMonthColumnExists;
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

$selectedCoachId = (int)($_GET['coach_id'] ?? 0);
$selectedAthleteId = (int)($_GET['athlete_id'] ?? 0);
$selectedStatus = (string)($_GET['status'] ?? '');
$coaches = [];
$athletes = [];
$onlineTrainingRows = [];
if ($ready) {
    $coaches = $pdo->query('SELECT id, name, username FROM coaches WHERE is_active = 1 ORDER BY name, username')->fetchAll();
    $athletesStmt = $pdo->prepare('SELECT id, first_name, last_name, coach_id FROM athletes WHERE (? = 0 OR coach_id = ?) ORDER BY last_name, first_name');
    $athletesStmt->execute([$selectedCoachId, $selectedCoachId]);
    $athletes = $athletesStmt->fetchAll();
    $trainingSql = 'SELECT ot.*, c.name AS coach_name, c.username AS coach_username, a.first_name, a.last_name,
        (SELECT COUNT(*) FROM online_training_results r WHERE r.online_training_id = ot.id) AS result_count,
        (SELECT COUNT(*) FROM online_training_attachments ata WHERE ata.online_training_id = ot.id) AS attachment_count,
        (SELECT COUNT(*) FROM online_training_billing b WHERE b.online_training_id = ot.id) AS billing_count
        FROM online_trainings ot JOIN coaches c ON c.id = ot.trainer_id JOIN athletes a ON a.id = ot.athlete_id WHERE 1=1';
    $trainingParams = [];
    if ($selectedCoachId > 0) { $trainingSql .= ' AND ot.trainer_id = ?'; $trainingParams[] = $selectedCoachId; }
    if ($selectedAthleteId > 0) { $trainingSql .= ' AND ot.athlete_id = ?'; $trainingParams[] = $selectedAthleteId; }
    if (in_array($selectedStatus, ['created', 'sent', 'in_progress', 'completed'], true)) { $trainingSql .= ' AND ot.status = ?'; $trainingParams[] = $selectedStatus; }
    $trainingSql .= ' ORDER BY COALESCE(ot.sent_at, ot.created_at) DESC, ot.id DESC LIMIT 250';
    $trainingStmt = $pdo->prepare($trainingSql);
    $trainingStmt->execute($trainingParams);
    $onlineTrainingRows = $trainingStmt->fetchAll();
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
    <div class="d-flex gap-2"><a class="btn btn-outline-primary" href="<?= BASE_URL ?>/admin/zprava_nova.php?template=online_trainings"><i class="fas fa-paper-plane me-1"></i>Připravit zprávu trenérům</a><span class="badge bg-success fs-6 align-self-center"><i class="fas fa-check me-1"></i>Schéma je připravené</span></div>
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
<div class="card border-0 shadow-sm mb-4"><div class="card-body d-flex justify-content-between align-items-center"><code>online_training_billing.billing_month</code><?php if ($billingMonthColumnExists): ?><span class="badge bg-success">Existuje</span><?php else: ?><span class="badge bg-danger">Chybí</span><?php endif; ?></div></div>

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

<?php if ($ready): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold"><i class="fas fa-list-check me-2"></i>Správa online tréninků</div>
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-3"><label class="form-label small">Trenér</label><select name="coach_id" class="form-select"><option value="0">Všichni trenéři</option><?php foreach ($coaches as $coach): ?><option value="<?= (int)$coach['id'] ?>" <?= $selectedCoachId === (int)$coach['id'] ? 'selected' : '' ?>><?= h((string)($coach['name'] ?: $coach['username'])) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small">Sportovec</label><select name="athlete_id" class="form-select"><option value="0">Všichni sportovci</option><?php foreach ($athletes as $athlete): ?><option value="<?= (int)$athlete['id'] ?>" <?= $selectedAthleteId === (int)$athlete['id'] ? 'selected' : '' ?>><?= h($athlete['last_name'] . ' ' . $athlete['first_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small">Stav</label><select name="status" class="form-select"><option value="">Všechny stavy</option><?php foreach (['created' => 'Vytvořeno', 'sent' => 'Odesláno', 'in_progress' => 'Rozpracováno', 'completed' => 'Dokončeno'] as $statusKey => $statusLabel): ?><option value="<?= h($statusKey) ?>" <?= $selectedStatus === $statusKey ? 'selected' : '' ?>><?= h($statusLabel) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><button class="btn btn-outline-dark w-100"><i class="fas fa-filter me-1"></i>Filtrovat</button></div>
        </form>
        <?php if (!$onlineTrainingRows): ?><div class="text-muted">Tomuto filtru neodpovídají žádné online tréninky.</div><?php else: ?><div class="table-responsive"><table class="table table-hover table-sm align-middle mb-0"><thead class="table-light"><tr><th>Číslo</th><th>Trenér</th><th>Sportovec</th><th>Název</th><th>Stav</th><th>Odesláno</th><th>Výsledky</th><th>Přílohy</th><th>Účtování</th><th></th></tr></thead><tbody><?php foreach ($onlineTrainingRows as $row): ?><tr><td><strong>ONLINE #<?= str_pad((string)$row['sequence_number'], 3, '0', STR_PAD_LEFT) ?></strong></td><td><?= h((string)($row['coach_name'] ?: $row['coach_username'])) ?></td><td><?= h($row['first_name'] . ' ' . $row['last_name']) ?></td><td><?= h($row['title']) ?></td><td><span class="badge <?= $row['status'] === 'completed' ? 'bg-success' : ($row['status'] === 'in_progress' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= h($row['status']) ?></span></td><td><?= $row['sent_at'] ? h(formatDateTime((string)$row['sent_at'])) : '–' ?></td><td><?= (int)$row['result_count'] ?></td><td><?= (int)$row['attachment_count'] ?></td><td><?= (int)$row['billing_count'] ?></td><td><form method="post" onsubmit="return confirm('TRVALÉ SMAZÁNÍ: smazat ONLINE #<?= str_pad((string)$row['sequence_number'], 3, '0', STR_PAD_LEFT) ?> včetně výsledků, příloh, souborů a účetních položek? Tato akce je nevratná.');"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="delete_online_training"><input type="hidden" name="training_id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Trvale smazat"><i class="fas fa-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><h5><i class="fas fa-user-shield me-2"></i>Oprávnění</h5><p class="text-muted mb-0">Sportovec vidí pouze vlastní online tréninky. Trenér vidí pouze online tréninky svých sportovců.</p></div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><h5><i class="fas fa-wallet me-2"></i>Účtování</h5><p class="text-muted mb-0">Jednorázové položky a nákupy balíčků se ukládají s datem odeslání nebo nákupu.</p></div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body"><h5><i class="fas fa-database me-2"></i>Snapshot</h5><p class="text-muted mb-0">Online cviky a výsledky jsou oddělené od klasických tréninkových session.</p></div></div></div>
</div>
<?php renderAdminFooter(); ?>
