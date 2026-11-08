<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$requiredTables = ['admin_online_training_rates', 'admin_online_training_invoices', 'admin_online_training_settings'];
$billingSchemaReady = true;
$billingSchemaProblems = [];
$tableStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
);
foreach ($requiredTables as $table) {
    $tableStmt->execute([$table]);
    if (!(int)$tableStmt->fetchColumn()) {
        $billingSchemaReady = false;
        $billingSchemaProblems[] = 'Chybí tabulka ' . $table . '.';
    }
}
if ($billingSchemaReady) {
    $requiredColumns = [
        'admin_online_training_rates' => ['rate_1_50', 'rate_51_150', 'rate_151_500', 'rate_501_plus', 'is_customized'],
        'admin_online_training_invoices' => ['rate_tier', 'bank_account_at_issue', 'payment_note_at_issue', 'cancelled_at', 'viewed_at'],
        'admin_online_training_settings' => ['bank_account', 'default_rate_1_50', 'default_rate_51_150', 'default_rate_151_500', 'default_rate_501_plus'],
    ];
    $columnStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnStmt->execute([$table, $column]);
            if (!(int)$columnStmt->fetchColumn()) {
                $billingSchemaReady = false;
                $billingSchemaProblems[] = 'Chybí sloupec ' . $table . '.' . $column . '.';
            }
        }
    }
    $statusTypeStmt = $pdo->prepare(
        'SELECT column_type FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $statusTypeStmt->execute(['admin_online_training_invoices', 'status']);
    if (strpos((string)$statusTypeStmt->fetchColumn(), 'cancelled') === false) {
        $billingSchemaReady = false;
        $billingSchemaProblems[] = 'Stav výzvy zatím nepodporuje hodnotu cancelled.';
    }
}
$month = (string)($_GET['month'] ?? date('Y-m'));
$parsedMonth = DateTimeImmutable::createFromFormat('!Y-m', $month);
if (!$parsedMonth || $parsedMonth->format('Y-m') !== $month) {
    $parsedMonth = new DateTimeImmutable('first day of this month');
    $month = $parsedMonth->format('Y-m');
}
$monthStart = $parsedMonth->format('Y-m-01');
$monthEnd = $parsedMonth->modify('+1 month')->format('Y-m-01');
$returnUrl = BASE_URL . '/admin/online_training_billing.php?month=' . rawurlencode($month);
$rateTiers = [
    'rate_1_50' => ['field' => 'rate_1_50', 'label' => '1–50 tréninků', 'rate_label' => '1–50'],
    'rate_51_150' => ['field' => 'rate_51_150', 'label' => '51–150 tréninků', 'rate_label' => '51–150'],
    'rate_151_500' => ['field' => 'rate_151_500', 'label' => '151–500 tréninků', 'rate_label' => '151–500'],
    'rate_501_plus' => ['field' => 'rate_501_plus', 'label' => '501 a více tréninků', 'rate_label' => '501+'],
];
$getRateTier = static function (int $count) use ($rateTiers): ?array {
    if ($count <= 0) {
        return null;
    }
    if ($count <= 50) {
        return $rateTiers['rate_1_50'];
    }
    if ($count <= 150) {
        return $rateTiers['rate_51_150'];
    }
    if ($count <= 500) {
        return $rateTiers['rate_151_500'];
    }
    return $rateTiers['rate_501_plus'];
};
$settingsStmt = $billingSchemaReady
    ? $pdo->query('SELECT * FROM admin_online_training_settings WHERE id = 1')
    : false;
$billingSettings = $settingsStmt ? ($settingsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
$configuredBankAccount = (string)($billingSettings['bank_account'] ?? '');
$paymentAccountReady = accountForSpd(strtoupper((string)preg_replace('/\s+/', '', $configuredBankAccount))) !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$billingSchemaReady) {
    flash('danger', 'Nejdříve připravte databázi vyúčtování.');
    redirect(BASE_URL . '/admin/online_training_billing.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect($returnUrl);
    }

    $action = (string)($_POST['action'] ?? '');
    $coachId = (int)($_POST['coach_id'] ?? 0);
    try {
        if ($action === 'save_bank_account') {
            $rawAccount = strtoupper((string)preg_replace('/\s+/', '', trim((string)($_POST['bank_account'] ?? ''))));
            if ($rawAccount !== '' && accountForSpd($rawAccount) === null) {
                throw new RuntimeException('Zadejte platné české číslo účtu (např. 123456789/0100) nebo IBAN.');
            }
            $pdo->prepare(
                'INSERT INTO admin_online_training_settings (id, bank_account)
                 VALUES (1, ?)
                 ON DUPLICATE KEY UPDATE bank_account = VALUES(bank_account)'
            )->execute([$rawAccount !== '' ? $rawAccount : null]);
            flash('success', $rawAccount === '' ? 'Číslo účtu bylo odebráno.' : 'Číslo účtu pro QR platby bylo uloženo.');
        } elseif ($action === 'save_default_rates') {
            $rawRates = is_array($_POST['rates'] ?? null) ? $_POST['rates'] : [];
            $rates = [];
            foreach ($rateTiers as $field => $tier) {
                $rawRate = trim((string)($rawRates[$field] ?? ''));
                if (!preg_match('/^\d{1,8}(?:[.,]\d{1,2})?$/', $rawRate)) {
                    throw new RuntimeException('Zadejte platnou výchozí sazbu pro pásmo ' . $tier['label'] . ', nejvýše na dvě desetinná místa.');
                }
                $rates[$field] = number_format((float)str_replace(',', '.', $rawRate), 2, '.', '');
            }
            $pdo->prepare(
                'UPDATE admin_online_training_settings
                 SET default_rate_1_50 = ?, default_rate_51_150 = ?, default_rate_151_500 = ?, default_rate_501_plus = ?
                 WHERE id = 1'
            )->execute(array_values($rates));
            flash('success', 'Výchozí sazby pro trenéry bez individuálního nastavení byly uloženy.');
        } elseif ($action === 'save_rates') {
            $rawRates = is_array($_POST['rates'] ?? null) ? $_POST['rates'] : [];
            $rates = [];
            foreach ($rateTiers as $field => $tier) {
                $rawRate = trim((string)($rawRates[$field] ?? ''));
                if (!preg_match('/^\d{1,8}(?:[.,]\d{1,2})?$/', $rawRate)) {
                    throw new RuntimeException('Zadejte platnou sazbu pro pásmo ' . $tier['label'] . ', nejvýše na dvě desetinná místa.');
                }
                $rates[$field] = number_format((float)str_replace(',', '.', $rawRate), 2, '.', '');
            }
            if ($coachId <= 0) throw new RuntimeException('Vyberte trenéra.');
            $coachStmt = $pdo->prepare('SELECT id FROM coaches WHERE id = ? LIMIT 1');
            $coachStmt->execute([$coachId]);
            if (!$coachStmt->fetchColumn()) throw new RuntimeException('Trenér nebyl nalezen.');
            $pdo->prepare(
                'INSERT INTO admin_online_training_rates
                    (coach_id, rate_per_training, rate_1_50, rate_51_150, rate_151_500, rate_501_plus, is_customized)
                 VALUES (?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    rate_per_training = VALUES(rate_per_training),
                    rate_1_50 = VALUES(rate_1_50),
                    rate_51_150 = VALUES(rate_51_150),
                    rate_151_500 = VALUES(rate_151_500),
                    rate_501_plus = VALUES(rate_501_plus),
                    is_customized = 1'
            )->execute([
                $coachId,
                $rates['rate_1_50'],
                $rates['rate_1_50'],
                $rates['rate_51_150'],
                $rates['rate_151_500'],
                $rates['rate_501_plus'],
            ]);
            flash('success', 'Sazby za všechna pásma online tréninků byly uloženy.');
        } elseif ($action === 'use_default_rates') {
            if ($coachId <= 0) throw new RuntimeException('Vyberte trenéra.');
            $pdo->prepare('UPDATE admin_online_training_rates SET is_customized = 0 WHERE coach_id = ?')
                ->execute([$coachId]);
            flash('success', 'Trenér nyní používá výchozí sazby.');
        } elseif ($action === 'issue_invoice') {
            if ($coachId <= 0) throw new RuntimeException('Vyberte trenéra.');
            $pdo->beginTransaction();
            $coachStmt = $pdo->prepare('SELECT id, name, username FROM coaches WHERE id = ? FOR UPDATE');
            $coachStmt->execute([$coachId]);
            $coach = $coachStmt->fetch();
            if (!$coach) throw new RuntimeException('Trenér nebyl nalezen.');
            $accountStmt = $pdo->query('SELECT bank_account FROM admin_online_training_settings WHERE id = 1 FOR UPDATE');
            $accountRaw = strtoupper((string)preg_replace('/\s+/', '', trim((string)($accountStmt->fetchColumn() ?: ''))));
            $accountForPayment = accountForSpd($accountRaw);
            if ($accountForPayment === null) throw new RuntimeException('Nejprve nastavte platné číslo účtu pro QR platby.');
            $existingStmt = $pdo->prepare('SELECT id, status FROM admin_online_training_invoices WHERE coach_id = ? AND billing_month = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $existingStmt->execute([$coachId, $monthStart]);
            $existingInvoice = $existingStmt->fetch();
            if ($existingInvoice && $existingInvoice['status'] !== 'cancelled') {
                throw new RuntimeException('Za tento měsíc již existuje aktivní nebo uhrazená výzva.');
            }

            $rateStmt = $pdo->prepare(
                'SELECT
                    CASE WHEN COALESCE(r.is_customized, 0) = 1 THEN r.rate_1_50 ELSE s.default_rate_1_50 END AS rate_1_50,
                    CASE WHEN COALESCE(r.is_customized, 0) = 1 THEN r.rate_51_150 ELSE s.default_rate_51_150 END AS rate_51_150,
                    CASE WHEN COALESCE(r.is_customized, 0) = 1 THEN r.rate_151_500 ELSE s.default_rate_151_500 END AS rate_151_500,
                    CASE WHEN COALESCE(r.is_customized, 0) = 1 THEN r.rate_501_plus ELSE s.default_rate_501_plus END AS rate_501_plus
                 FROM admin_online_training_settings s
                 LEFT JOIN admin_online_training_rates r ON r.coach_id = ?
                 WHERE s.id = 1
                 FOR UPDATE'
            );
            $rateStmt->execute([$coachId]);
            $rates = $rateStmt->fetch(PDO::FETCH_ASSOC);

            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM online_trainings
                 WHERE trainer_id = ? AND sent_at >= ? AND sent_at < ?'
            );
            $countStmt->execute([$coachId, $monthStart, $monthEnd]);
            $trainingCount = (int)$countStmt->fetchColumn();
            if ($trainingCount === 0) throw new RuntimeException('Trenér v tomto měsíci neodeslal žádný online trénink.');

            $rateTier = $getRateTier($trainingCount);
            $unitRate = (float)($rates[$rateTier['field']] ?? 0);
            if ($unitRate <= 0) {
                throw new RuntimeException('Nastavte trenérovi sazbu vyšší než 0 Kč pro pásmo ' . $rateTier['label'] . '.');
            }
            $amount = round($trainingCount * $unitRate, 2);
            $monthLabel = $parsedMonth->format('m/Y');
            $invoiceSubject = 'Nová výzva k úhradě online tréninků – ' . $monthLabel;
            $coachName = trim((string)($coach['name'] ?? '')) !== '' ? trim((string)$coach['name']) : trim((string)$coach['username']);
            $paymentNote = substr(paymentAsciiText($monthLabel . ' ' . $coachName), 0, 60);
            if ($existingInvoice) {
                $pdo->prepare(
                    "UPDATE admin_online_training_invoices
                     SET sent_trainings_count = ?, rate_per_training = ?, rate_tier = ?, amount = ?, bank_account_at_issue = ?,
                         payment_note_at_issue = ?, status = 'pending', issued_at = NOW(), paid_at = NULL, cancelled_at = NULL
                     WHERE id = ? AND coach_id = ? AND status = 'cancelled'"
                )->execute([
                    $trainingCount,
                    number_format($unitRate, 2, '.', ''),
                    $rateTier['rate_label'],
                    number_format($amount, 2, '.', ''),
                    $accountRaw,
                    $paymentNote,
                    (int)$existingInvoice['id'],
                    $coachId,
                ]);
            } else {
                $pdo->prepare(
                    "INSERT INTO admin_online_training_invoices
                        (coach_id, billing_month, sent_trainings_count, rate_per_training, rate_tier, amount, bank_account_at_issue, payment_note_at_issue, status, issued_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())"
                )->execute([$coachId, $monthStart, $trainingCount, number_format($unitRate, 2, '.', ''), $rateTier['rate_label'], number_format($amount, 2, '.', ''), $accountRaw, $paymentNote]);
            }
            $qrUrl = buildPaymentQrUrl($accountForPayment, $amount, $paymentNote);
            $invoiceBody = "Byla vám vystavena výzva k úhradě za online tréninky za období {$monthLabel}.\n\n"
                . "Počet odeslaných tréninků: {$trainingCount}\n"
                . 'Použité pásmo: ' . $rateTier['label'] . "\n"
                . 'Sazba za trénink: ' . number_format($unitRate, 2, ',', ' ') . " Kč\n"
                . 'Celkem k úhradě: ' . number_format($amount, 2, ',', ' ') . " Kč\n\n"
                . 'Číslo účtu: ' . $accountRaw . "\n"
                . 'Zpráva pro příjemce: ' . $paymentNote . "\n"
                . 'QR platba: ' . $qrUrl . "\n\n"
                . 'Výzvu a účtenku najdete také na stránce Platby: ' . BASE_URL . '/payments.php#admin-online-training-invoices';
            createCoachSystemMessage($coachId, $invoiceSubject, $invoiceBody, false);
            $pdo->commit();
            flash('success', 'Výzva k úhradě ' . number_format($amount, 2, ',', ' ') . ' Kč byla vystavena a trenér byl upozorněn interní zprávou.');
        } elseif ($action === 'cancel_invoice') {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $pdo->beginTransaction();
            $invoiceStmt = $pdo->prepare(
                "SELECT i.billing_month, i.amount, i.status, c.name, c.username
                 FROM admin_online_training_invoices i
                 JOIN coaches c ON c.id = i.coach_id
                 WHERE i.id = ? AND i.coach_id = ?
                 FOR UPDATE"
            );
            $invoiceStmt->execute([$invoiceId, $coachId]);
            $invoice = $invoiceStmt->fetch();
            if (!$invoice || $invoice['status'] !== 'pending') {
                throw new RuntimeException('Lze zrušit pouze existující neuhrazenou výzvu.');
            }
            $pdo->prepare(
                "UPDATE admin_online_training_invoices SET status = 'cancelled', cancelled_at = NOW()
                 WHERE id = ? AND coach_id = ? AND status = 'pending'"
            )->execute([$invoiceId, $coachId]);
            $monthLabel = date('m/Y', strtotime((string)$invoice['billing_month']));
            createCoachSystemMessage(
                $coachId,
                'Zrušení výzvy k úhradě online tréninků – ' . $monthLabel,
                "Administrátor zrušil výzvu k úhradě online tréninků za období {$monthLabel}.\n"
                    . 'Původní částka: ' . number_format((float)$invoice['amount'], 2, ',', ' ') . " Kč.\n"
                    . 'Pokud byla platba již zadána, kontaktujte prosím administrátora.',
                false
            );
            $pdo->commit();
            flash('success', 'Výzva byla zrušena a trenér o tom dostal interní zprávu.');
        } elseif ($action === 'mark_paid') {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $update = $pdo->prepare(
                "UPDATE admin_online_training_invoices
                 SET status = 'paid', paid_at = NOW()
                 WHERE id = ? AND coach_id = ? AND status = 'pending'"
            );
            $update->execute([$invoiceId, $coachId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('Výzva nebyla nalezena nebo již byla uhrazena.');
            flash('success', 'Výzva byla označena jako uhrazená.');
        } else {
            throw new RuntimeException('Neznámá akce.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Admin online training billing (' . $action . '): ' . $e->getMessage());
        flash('danger', 'Výzvu se nepodařilo zpracovat: ' . $e->getMessage());
    }
    redirect($returnUrl);
}

$rows = [];
if ($billingSchemaReady) {
    $coachesStmt = $pdo->prepare(
        "SELECT c.id, c.name, c.username, c.is_active,
            CASE WHEN COALESCE(r.is_customized, 0) = 1 THEN r.rate_1_50 ELSE s.default_rate_1_50 END AS rate_1_50,
            CASE WHEN COALESCE(r.is_customized, 0) = 1 THEN r.rate_51_150 ELSE s.default_rate_51_150 END AS rate_51_150,
            CASE WHEN COALESCE(r.is_customized, 0) = 1 THEN r.rate_151_500 ELSE s.default_rate_151_500 END AS rate_151_500,
            CASE WHEN COALESCE(r.is_customized, 0) = 1 THEN r.rate_501_plus ELSE s.default_rate_501_plus END AS rate_501_plus,
            COALESCE(t.sent_count, 0) AS sent_count,
            COALESCE(r.is_customized, 0) AS has_custom_rates,
            i.id AS invoice_id, i.sent_trainings_count AS invoice_training_count,
            i.rate_per_training AS invoice_rate, i.rate_tier AS invoice_tier, i.amount AS invoice_amount,
            i.status AS invoice_status, i.issued_at, i.paid_at, i.cancelled_at
     FROM coaches c
     LEFT JOIN admin_online_training_rates r ON r.coach_id = c.id
     LEFT JOIN admin_online_training_settings s ON s.id = 1
     LEFT JOIN (
         SELECT trainer_id, COUNT(*) AS sent_count
         FROM online_trainings
         WHERE sent_at >= ? AND sent_at < ?
         GROUP BY trainer_id
     ) t ON t.trainer_id = c.id
     LEFT JOIN admin_online_training_invoices i
       ON i.id = (
           SELECT latest.id FROM admin_online_training_invoices latest
           WHERE latest.coach_id = c.id AND latest.billing_month = ?
           ORDER BY latest.id DESC LIMIT 1
       )
         ORDER BY c.is_active DESC, c.name, c.username"
    );
    $coachesStmt->execute([$monthStart, $monthEnd, $monthStart]);
    $rows = $coachesStmt->fetchAll();
}
$sentTotal = array_sum(array_map(static fn(array $row): int => (int)$row['sent_count'], $rows));
$invoiceTotal = array_sum(array_map(
    static fn(array $row): float => ($row['invoice_status'] ?? null) === 'cancelled' ? 0.0 : (float)($row['invoice_amount'] ?? 0),
    $rows
));
$previousMonth = $parsedMonth->modify('-1 month')->format('Y-m');
$nextMonth = $parsedMonth->modify('+1 month')->format('Y-m');

renderAdminHeader('Vyúčtování online tréninků');
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1"><i class="fas fa-laptop me-2 text-warning"></i>Vyúčtování online tréninků</h1>
        <p class="text-muted mb-0">Výzvy se po vystavení zobrazí trenérovi na stránce Platby a přijde mu o nich interní zpráva.</p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex align-items-end justify-content-between gap-3 flex-wrap">
        <form method="get" class="d-flex align-items-end gap-2 flex-wrap">
            <div><label for="billing-month" class="form-label">Měsíc odeslání</label><input id="billing-month" type="month" name="month" class="form-control" value="<?= h($month) ?>"></div>
            <button class="btn btn-outline-dark"><i class="fas fa-filter me-1"></i>Zobrazit</button>
        </form>
        <div class="btn-group">
            <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/admin/online_training_billing.php?month=<?= h($previousMonth) ?>">Předchozí měsíc</a>
            <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/admin/online_training_billing.php?month=<?= h($nextMonth) ?>">Další měsíc</a>
        </div>
    </div>
</div>

<?php if (!$billingSchemaReady): ?>
<div class="alert alert-warning">
Databáze vyúčtování online tréninků není připravená na QR platby, rušení výzev a sazby podle pásem. Spusťte přípravu databáze:
    <?php if ($billingSchemaProblems): ?><ul class="mb-2 mt-2"><?php foreach ($billingSchemaProblems as $problem): ?><li><?= h($problem) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <a class="btn btn-sm btn-warning ms-2" href="<?= BASE_URL ?>/scripts/migrate_admin_online_training_billing.php">Připravit databázi</a>
</div>
<?php else: ?>
<div class="card border-primary shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5"><i class="fas fa-qrcode me-2 text-primary"></i>Číslo účtu pro výzvy trenérům</h2>
        <p class="small text-muted">Toto číslo účtu bude vloženo do QR platby každé nově vystavené výzvy. Již vystavené výzvy používají uložené číslo účtu.</p>
        <form method="post" class="row g-2 align-items-end">
            <?= csrfField() ?><input type="hidden" name="action" value="save_bank_account">
            <div class="col-md-6"><label for="online-billing-bank-account" class="form-label">Číslo účtu nebo IBAN</label><input id="online-billing-bank-account" name="bank_account" class="form-control" value="<?= h($configuredBankAccount) ?>" placeholder="123456789/0100 nebo CZ..." autocomplete="off"></div>
            <div class="col-auto"><button class="btn btn-outline-primary"><i class="fas fa-save me-1"></i>Uložit účet</button></div>
            <div class="col-12 small <?= $paymentAccountReady ? 'text-success' : 'text-danger' ?>"><?= $paymentAccountReady ? 'Účet je platný a QR platby lze vystavovat.' : 'Nastavte platný účet před vystavením výzev.' ?></div>
        </form>
    </div>
</div>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5"><i class="fas fa-users me-2 text-primary"></i>Výchozí sazby pro trenéry</h2>
        <p class="small text-muted">Použijí se pro trenéry bez individuálně uložených sazeb. Individuální sazby trenéra mají přednost.</p>
        <form method="post" class="d-flex flex-wrap align-items-end gap-3">
            <?= csrfField() ?><input type="hidden" name="action" value="save_default_rates">
            <div class="row row-cols-2 row-cols-lg-4 g-2 flex-grow-1">
                <?php foreach ($rateTiers as $field => $tier): ?>
                <?php $defaultField = 'default_' . $field; ?>
                <div class="col">
                    <label class="form-label small mb-1" for="default-<?= h($field) ?>"><?= h($tier['label']) ?></label>
                    <div class="input-group input-group-sm">
                        <input id="default-<?= h($field) ?>" name="rates[<?= h($field) ?>]" type="number" min="0" max="99999999.99" step="0.01" class="form-control" value="<?= h(number_format((float)($billingSettings[$defaultField] ?? 0), 2, '.', '')) ?>" required>
                        <span class="input-group-text">Kč</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-sm btn-outline-primary"><i class="fas fa-save me-1"></i>Uložit výchozí sazby</button>
        </form>
    </div>
</div>
<div class="row g-3 mb-4">
    <div class="col-sm-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted">Odeslané online tréninky v měsíci</div><div class="display-6 fw-bold"><?= number_format($sentTotal, 0, ',', ' ') ?></div></div></div></div>
    <div class="col-sm-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted">Vystavené výzvy za měsíc</div><div class="display-6 fw-bold"><?= number_format($invoiceTotal, 2, ',', ' ') ?> Kč</div></div></div></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white fw-bold"><?= h($parsedMonth->format('m/Y')) ?> · trenérské statistiky a vyúčtování</div>
    <?php if (!$rows): ?>
    <div class="card-body text-muted">V systému nejsou žádní trenéři.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Trenér</th><th class="text-end">Odeslané tréninky</th><th>Sazby za trénink podle pásma</th><th>Výzva za měsíc</th><th>Stav / akce</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
        <?php
        $sentCount = (int)$row['sent_count'];
        $currentRateTier = $getRateTier($sentCount);
        $currentRate = $currentRateTier !== null ? (float)$row[$currentRateTier['field']] : 0.0;
        $estimatedAmount = round($sentCount * $currentRate, 2);
        ?>
        <tr>
            <td><?= h((string)($row['name'] ?: $row['username'])) ?><?= !(int)$row['is_active'] ? ' <span class="badge bg-secondary">Neaktivní</span>' : '' ?></td>
            <td class="text-end fw-semibold"><?= number_format($sentCount, 0, ',', ' ') ?></td>
            <td>
                <?php if ($row['invoice_id']): ?>
                <div class="small text-muted mb-1">Ve výzvě: <?= number_format((float)$row['invoice_rate'], 2, ',', ' ') ?> Kč za trénink (<?= $row['invoice_tier'] === 'legacy' ? 'původní sazba' : 'pásmo ' . h((string)$row['invoice_tier']) ?>)</div>
                <?php endif; ?>
                <form method="post" class="d-grid gap-2" style="width:340px;max-width:100%">
                    <?= csrfField() ?><input type="hidden" name="action" value="save_rates"><input type="hidden" name="coach_id" value="<?= (int)$row['id'] ?>">
                    <div class="row row-cols-2 g-2">
                        <?php foreach ($rateTiers as $field => $tier): ?>
                        <div class="col">
                            <label class="form-label small mb-1" for="rate-<?= (int)$row['id'] ?>-<?= h($field) ?>"><?= h($tier['rate_label']) ?></label>
                            <div class="input-group input-group-sm">
                                <input id="rate-<?= (int)$row['id'] ?>-<?= h($field) ?>" aria-label="Sazba za trénink, pásmo <?= h($tier['label']) ?>" name="rates[<?= h($field) ?>]" type="number" min="0" max="99999999.99" step="0.01" class="form-control" value="<?= h(number_format((float)$row[$field], 2, '.', '')) ?>" required>
                                <span class="input-group-text">Kč</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-sm btn-outline-primary justify-self-start">Uložit sazby</button>
                </form>
                <?php if ((int)$row['has_custom_rates'] === 1): ?>
                <form method="post" class="mt-1">
                    <?= csrfField() ?><input type="hidden" name="action" value="use_default_rates"><input type="hidden" name="coach_id" value="<?= (int)$row['id'] ?>">
                    <button class="btn btn-sm btn-link p-0 text-muted">Použít výchozí sazby</button>
                </form>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($row['invoice_id']): ?>
                <strong><?= number_format((float)$row['invoice_amount'], 2, ',', ' ') ?> Kč</strong>
                <div class="small text-muted"><?= (int)$row['invoice_training_count'] ?> × <?= number_format((float)$row['invoice_rate'], 2, ',', ' ') ?> Kč</div>
                <?php else: ?>
                <?= number_format($estimatedAmount, 2, ',', ' ') ?> Kč <span class="small text-muted">(zatím nevystaveno<?= $currentRateTier !== null ? ', pásmo ' . h($currentRateTier['rate_label']) : '' ?>)</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($row['invoice_id'] && $row['invoice_status'] === 'paid'): ?>
                <span class="badge bg-success">Uhrazeno</span><div class="small text-muted"><?= h(formatDateTime((string)$row['paid_at'])) ?></div>
                <?php elseif ($row['invoice_id'] && $row['invoice_status'] === 'cancelled'): ?>
                <span class="badge bg-secondary">Zrušeno</span>
                <?php if ($sentCount > 0 && $currentRate > 0 && $paymentAccountReady): ?>
                <form method="post" class="mt-2" onsubmit="return confirm('Vystavit novou výzvu za toto období?');">
                    <?= csrfField() ?><input type="hidden" name="action" value="issue_invoice"><input type="hidden" name="coach_id" value="<?= (int)$row['id'] ?>">
                    <button class="btn btn-sm btn-warning fw-bold">Vystavit znovu</button>
                </form>
                <?php endif; ?>
                <?php elseif ($row['invoice_id']): ?>
                <span class="badge bg-warning text-dark">Čeká na úhradu</span>
                <form method="post" class="mt-2" onsubmit="return confirm('Označit interní výzvu jako uhrazenou?');">
                    <?= csrfField() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="coach_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="invoice_id" value="<?= (int)$row['invoice_id'] ?>">
                    <button class="btn btn-sm btn-outline-success">Označit uhrazeno</button>
                </form>
                <form method="post" class="mt-2" onsubmit="return confirm('Opravdu zrušit tuto výzvu? Trenér dostane interní zprávu o zrušení.');">
                    <?= csrfField() ?><input type="hidden" name="action" value="cancel_invoice"><input type="hidden" name="coach_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="invoice_id" value="<?= (int)$row['invoice_id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Zrušit výzvu</button>
                </form>
                <?php elseif ($sentCount > 0 && $currentRate > 0): ?>
                <form method="post" onsubmit="return confirm('Vystavit interní výzvu k úhradě <?= h(number_format($estimatedAmount, 2, ',', ' ')) ?> Kč za <?= $sentCount ?> online tréninků<?= $currentRateTier !== null ? ' (pásmo ' . h($currentRateTier['rate_label']) . ')' : '' ?>?');">
                    <?= csrfField() ?><input type="hidden" name="action" value="issue_invoice"><input type="hidden" name="coach_id" value="<?= (int)$row['id'] ?>">
                    <button class="btn btn-sm btn-warning fw-bold" <?= !$paymentAccountReady ? 'disabled title="Nejprve nastavte číslo účtu pro QR platby."' : '' ?>>Vystavit výzvu</button>
                </form>
                <?php elseif ($sentCount === 0): ?>
                <span class="text-muted small">Bez odeslaných tréninků</span>
                <?php else: ?>
                <span class="text-muted small">Nastavte sazbu pro pásmo <?= $currentRateTier !== null ? h($currentRateTier['rate_label']) : '' ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<div class="alert alert-info mt-4 mb-0">
    <i class="fas fa-circle-info me-1"></i>Počítají se tréninky podle data odeslání, včetně těch, které byly následně dokončeny. Pro celý měsíční počet se použije sazba dosaženého pásma. Při vystavení se sazba, počet tréninků a částka uloží jako neměnný měsíční záznam. Trenér dostane interní zprávu s odkazem na výzvu v sekci Platby.
</div>
<?php endif; ?>

<?php renderAdminFooter(); ?>
