<?php
// admin/calculators.php – administrace aplikace Kalkulačky (ve vývoji)
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/calculators.php';
require_once __DIR__ . '/../includes/performance_schema.php';
require_once __DIR__ . '/../includes/performance_deployment.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
header('Cache-Control: no-store');

$pdo     = getDB();
$error   = null;
$success = null;

$calculatorAccessKey = 'calculator_app_enabled_for_all';
$calculatorPilotAthleteId = 6;
$currentCalculatorAccessEnabled = trim(getAppSetting($calculatorAccessKey, '0')) === '1';
$calculatorList = calculatorDefinitions();
$calculatorStates = [];
foreach ($calculatorList as $calcKey => $calcDef) {
    $calculatorStates[$calcKey] = calculatorIsEnabled($calcKey);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
        $error = 'Neplatný bezpečnostní token.';
    } elseif (($_POST['action'] ?? '') === 'migrate_performance') {
        try {
            performanceDeploymentAuthorize($pdo, $_SESSION['performance_backup'] ?? [], $_POST);
            $check = performanceSchemaCheck($pdo);
            if ($check['missing']) throw new InvalidArgumentException('Chybí součásti původního schématu: ' . implode(', ', $check['missing']));
            unset($_SESSION['performance_backup']);
            performanceMigrate($pdo);
            $success = 'Databázové tabulky modulu byly připraveny a ověřeny. Stávající data a přepínače zůstaly zachovány. Modul můžete nyní zapnout níže.';
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('performance admin migration: ' . $e->getMessage());
            $error = 'Migrace se nedokončila. Modul nezapínejte. Zkontrolujte chybový log a databázová práva CREATE, ALTER a INDEX. DDL není transakční; část tabulek již mohla vzniknout. Před opakováním vytvořte novou zálohu.';
        }
    } elseif (($_POST['action'] ?? '') === 'toggle_calculator') {
        $calcKey = (string)($_POST['calculator_key'] ?? '');
        if (!isset($calculatorList[$calcKey])) {
            $error = 'Neznámá kalkulačka.';
        } elseif ($calcKey === 'performance' && (string)($_POST['calculator_state'] ?? '0') === '1' && !performanceSchemaReady($pdo)) {
            $error = 'Nejprve připravte databázi v sekci Nasazení modulu. Bez dokončené migrace nelze modul zapnout.';
        } else {
            $isEnabled = (string)($_POST['calculator_state'] ?? '0') === '1';
            $pdo->prepare(
                'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            )->execute([$calculatorList[$calcKey]['setting_key'], $isEnabled ? '1' : '0']);
            $calculatorStates[$calcKey] = $isEnabled;
            $success = 'Kalkulačka ' . $calculatorList[$calcKey]['title'] . ' byla ' . ($isEnabled ? 'zapnuta.' : 'vypnuta.');
        }
    } elseif (($_POST['action'] ?? '') === 'save_calculator_access') {
        $isEnabled = (string)($_POST['calculator_enabled'] ?? '0') === '1';

        $pdo->prepare(
            'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        )->execute([$calculatorAccessKey, $isEnabled ? '1' : '0']);

        $currentCalculatorAccessEnabled = $isEnabled;
        $success = $isEnabled
            ? 'Přístup ke Kalkulačkám byl povolen všem uživatelům.'
            : 'Přístup ke Kalkulačkám zůstává povolen pouze sportovci s ID ' . $calculatorPilotAthleteId . '.';
    }
}

$performanceDeployment = null;
try {
    $performanceDeployment = [
        'target' => performanceDeploymentTarget($pdo),
        'check' => performanceSchemaCheck($pdo),
        'ready' => performanceSchemaReady($pdo),
    ];
} catch (Throwable $e) {
    error_log('performance admin deployment audit: ' . $e->getMessage());
    $error = $error ?? 'Kontrola databáze modulu selhala. Migraci nespouštějte a ověřte chybový log.';
}
$backupReceipt = $_SESSION['performance_backup'] ?? [];
$backupValid = $performanceDeployment !== null
    && ($backupReceipt['fingerprint'] ?? '') === $performanceDeployment['target']['fingerprint']
    && is_int($backupReceipt['created_at'] ?? null)
    && $backupReceipt['created_at'] <= time() && $backupReceipt['created_at'] >= time() - 1800;

$pilotAthleteName = null;
try {
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM athletes WHERE id = ?');
    $stmt->execute([$calculatorPilotAthleteId]);
    $row = $stmt->fetch();
    if ($row) {
        $pilotAthleteName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
    }
} catch (Throwable $e) {
    $pilotAthleteName = null;
}

renderAdminHeader('Kalkulačky');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-calculator me-2" style="color:#a78bfa"></i>Kalkulačky
        <span class="badge bg-secondary ms-2" style="font-size:.7rem;vertical-align:middle">Ve vývoji</span>
    </h4>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-1"></i><?= h($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle me-1"></i><?= h($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div id="performance-deployment" class="card border-0 shadow-sm mb-4" style="max-width:760px">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">Nasazení modulu Výkonnost a regenerace</div>
    <div class="card-body">
        <?php if ($performanceDeployment !== null): ?>
        <p>Aktuální databáze: <strong><?= h($performanceDeployment['target']['database']) ?></strong>
            · <?= $performanceDeployment['ready'] ? 'schéma modulu připraveno' : 'migrace nutná' ?>.</p>
        <?php if ($performanceDeployment['check']['missing']): ?>
        <div class="alert alert-danger">Nejprve je nutné doplnit původní schéma TrainerApp. Tato migrace nevytváří náhradní profily:
            <ul class="mb-0"><?php foreach ($performanceDeployment['check']['missing'] as $missing): ?><li><?= h($missing) ?></li><?php endforeach; ?></ul>
        </div>
        <?php else: ?>
        <p>Migrace přidá šest tabulek, vazební klíč hodnocení a výchozí vypnutý přepínač. Nezakládá druhé sportovce ani tréninky.</p>
        <div class="alert alert-warning">
            <strong>Nejprve pozastavte zápisy do aplikace a stáhněte zálohu.</strong>
            Export zahrnuje strukturu a všechna data osmi dotčených tabulek, včetně nastavení aplikace a denních dotazníků.
            Jde o zálohu pro návrat této migrace, <strong>nikoli úplnou zálohu celé databáze nebo nahraných souborů</strong>.
            Úplnou provozní zálohu pořizuje správce hostingu. Obnovu provádí správce databáze se zastavenými zápisy.
            Soubor obsahuje osobní údaje: uložte ho bezpečně mimo veřejný web a nikomu jej neposílejte.
        </div>
        <form method="post" action="<?= h(adminBaseUrl()) ?>/admin/performance_backup.php" class="mb-3">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-outline-primary">1. Vytvořit a stáhnout zálohu dotčených tabulek</button>
        </form>
        <p class="small text-muted">Záloha používá stejné připojení jako aplikace, nevyžaduje zadání hesla. Po stažení obnovte tuto stránku. Zálohy se neukládají do veřejného adresáře serveru. U velké databáze nebo tabulek s triggery použijte správce hostingu a CLI postup.</p>
        <?php if ($backupValid): ?>
        <div class="alert alert-success">
            Soubor byl vytvořen <?= h(date('j. n. Y H:i:s', $backupReceipt['created_at'])) ?>.
            Velikost <?= number_format((int)$backupReceipt['bytes'], 0, ',', ' ') ?> B.
            <div class="small text-break">SHA-256: <?= h($backupReceipt['sha256']) ?></div>
            <strong>Ověřte, že prohlížeč soubor skutečně uložil.</strong>
        </div>
        <?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="migrate_performance">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="backup_saved" value="1" id="backup-saved" required <?= $backupValid ? '' : 'disabled' ?>>
                <label class="form-check-label" for="backup-saved">Zálohu jsem stáhl/a a bezpečně uložil/a.</label>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="maintenance_confirmed" value="1" id="maintenance-confirmed" required <?= $backupValid ? '' : 'disabled' ?>>
                <label class="form-check-label" for="maintenance-confirmed">Zápisy uživatelů do aplikace jsou po dobu zálohy a migrace pozastavené.</label>
            </div>
            <label class="form-label" for="database-confirmation">Pro potvrzení napište přesný název databáze uvedený výše</label>
            <input id="database-confirmation" name="database_confirmation" class="form-control mb-3" autocomplete="off" required <?= $backupValid ? '' : 'disabled' ?>>
            <button type="submit" class="btn btn-warning fw-bold" <?= $backupValid ? '' : 'disabled' ?>>2. Vytvořit / doplnit tabulky modulu</button>
            <p class="small text-muted mt-2 mb-0">Oprávnění platí 30 minut pro tuto databázi a administrátorskou relaci. Migrace sama modul nezapíná a nepovoluje sdílení dat.</p>
        </form>
        <?php endif; ?>
        <?php else: ?><div class="alert alert-danger mb-0">Stav databáze nelze ověřit. Nasazení je zablokované.</div><?php endif; ?>
    </div>
</div>

<div id="calculator-access" class="card border-0 shadow-sm" style="max-width:760px">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-user-lock me-2"></i>Přístup
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            Kalkulačky jsou zatím ve vývoji.
            Ve výchozím stavu je odkaz v hlavičce a rychlém menu zobrazen a přístupný pouze sportovci s ID <?= (int)$calculatorPilotAthleteId ?><?= $pilotAthleteName ? ' (' . h($pilotAthleteName) . ')' : '' ?>.
            Zde můžete jejich zobrazení povolit všem sportovcům a trenérům.
        </p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_calculator_access">
            <input type="hidden" name="calculator_enabled" value="0">

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="calculatorAccessSwitch" name="calculator_enabled" value="1" <?= $currentCalculatorAccessEnabled ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="calculatorAccessSwitch">
                    Zobrazit Kalkulačky všem sportovcům a trenérům
                </label>
            </div>

            <div class="small text-muted mb-3">
                Aktuální stav:
                <?php if ($currentCalculatorAccessEnabled): ?>
                    <span class="badge bg-success">Zobrazeno všem</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Pouze sportovec s ID <?= (int)$calculatorPilotAthleteId ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn fw-bold" style="background:#7c3aed;color:#fff;border:none">
                <i class="fas fa-save me-1"></i>Uložit nastavení
            </button>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4" style="max-width:760px">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-screwdriver-wrench me-2"></i>Správa kalkulaček
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">Vypnutá kalkulačka se na dashboardu Kalkulaček zobrazí jako nedostupná a nelze ji otevřít.</p>
        <div class="alert alert-info">
            Výkonnost a regenerace: <?= !empty($performanceDeployment['ready']) ? 'databáze je připravena' : 'před zapnutím připravte databázi v sekci Nasazení modulu výše' ?>.
            Hlavní přepínač vypíná celý modul; podřízené přepínače jeho jednotlivé funkce.
            Sdílení výkonnosti a regenerace trenérovi povoluje samostatně sportovec. Administrace tímto nepovoluje přístup k osobním výsledkům.
        </div>
        <div class="list-group">
            <?php foreach ($calculatorList as $calcKey => $calcDef): ?>
            <?php $calcEnabled = !empty($calculatorStates[$calcKey]); ?>
            <div class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-3 py-3">
                <div class="d-flex align-items-center gap-3">
                    <span class="d-inline-grid rounded-circle" style="width:44px;height:44px;place-items:center;background:linear-gradient(135deg,#fbe7a1,#d4a73c 60%,#9c7420);color:#2a1e04">
                        <i class="fas <?= h($calcDef['icon']) ?>"></i>
                    </span>
                    <div>
                        <div class="fw-bold"><?= h($calcDef['title']) ?> <span class="text-muted fw-normal">– <?= h($calcDef['subtitle']) ?></span></div>
                        <?php if ($calcEnabled): ?>
                            <span class="badge bg-success">Zapnuto</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Vypnuto</span>
                        <?php endif; ?>
                    </div>
                </div>
                <form method="post" class="m-0">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_calculator">
                    <input type="hidden" name="calculator_key" value="<?= h($calcKey) ?>">
                    <input type="hidden" name="calculator_state" value="<?= $calcEnabled ? '0' : '1' ?>">
                    <?php if ($calcEnabled): ?>
                        <button type="submit" class="btn btn-outline-danger btn-sm fw-bold"><i class="fas fa-power-off me-1"></i>Vypnout</button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-success btn-sm fw-bold"><i class="fas fa-power-off me-1"></i>Zapnout</button>
                    <?php endif; ?>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
echo '</div></div></div>';
echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
echo '</body></html>';
?>
