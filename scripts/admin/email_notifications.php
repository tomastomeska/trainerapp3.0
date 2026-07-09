<?php
// admin/email_notifications.php – správa e-mailových notifikací
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo     = getDB();
$error   = null;
$success = null;
$testResult = null;
$cronResult = null;

// ── Zpracování formulářů ─────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } elseif (isset($_POST['action'])) {

        // Test SMTP
        if ($_POST['action'] === 'test_smtp') {
            $testTo = trim($_POST['test_email'] ?? '');
            if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                $error = 'Zadejte platnou e-mailovou adresu pro test.';
            } else {
                $res = sendTestEmail($testTo);
                if ($res === 'ok') {
                    $testResult = ['ok' => true, 'email' => $testTo];
                } else {
                    $testResult = ['ok' => false, 'error' => $res];
                }
            }
        }

        // Ruční spuštění narozeninových notifikací
        if ($_POST['action'] === 'run_birthday') {
            $cronResult = processBirthdayNotifications();
        }
    }
}

// ── Načtení dat ──────────────────────────────────────────────────────────────

// Posledních 30 odeslaných narozeninových notifikací
$recentNotifs = $pdo->query(
    "SELECT bn.*, a.first_name, a.last_name, a.birth_date,
            c.name AS coach_name, c.username AS coach_username, c.email AS coach_email
     FROM birthday_notifications bn
     JOIN athletes a ON a.id = bn.athlete_id
     JOIN coaches  c ON c.id = a.coach_id
     ORDER BY bn.sent_at DESC
     LIMIT 30"
)->fetchAll();

// Nadcházející narozeniny (příštích 14 dní)
$upcomingBirthdays = $pdo->query(
    "SELECT a.first_name, a.last_name, a.birth_date,
            c.name AS coach_name, c.username AS coach_username, c.email AS coach_email,
            DATEDIFF(
                DATE(CONCAT(YEAR(CURDATE()), '-', DATE_FORMAT(a.birth_date, '%m-%d'))),
                CURDATE()
            ) AS days_left
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id
     WHERE a.birth_date IS NOT NULL
       AND DATEDIFF(
               DATE(CONCAT(YEAR(CURDATE()), '-', DATE_FORMAT(a.birth_date, '%m-%d'))),
               CURDATE()
           ) BETWEEN 0 AND 14
     ORDER BY days_left ASC
     LIMIT 20"
)->fetchAll();

// Cron URL
$cronSecret = getCronSecret();
$isHttps    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443');
$scheme     = $isHttps ? 'https' : 'http';
$host       = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$cronUrl    = $scheme . '://' . $host . BASE_URL . '/cron_birthday.php?secret=' . $cronSecret;

// SMTP info
$smtpHost = defined('SMTP_HOST') ? SMTP_HOST : '–';
$smtpPort = defined('SMTP_PORT') ? SMTP_PORT : '–';
$smtpUser = defined('SMTP_USER') ? SMTP_USER : '–';
$smtpFrom = defined('SMTP_FROM') ? SMTP_FROM : '–';

renderAdminHeader('E-mailové notifikace');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-envelope me-2" style="color:#a78bfa"></i>E-mailové notifikace
    </h4>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-1"></i><?= h($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Karta: SMTP nastavení ── -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
                <i class="fas fa-server me-2"></i>SMTP konfigurace
            </div>
            <div class="card-body p-4">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted" style="width:140px">Server</td>
                            <td><code><?= h((string)$smtpHost) ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Port</td>
                            <td><code><?= h((string)$smtpPort) ?></code> (STARTTLS)</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Uživatel</td>
                            <td><code><?= h((string)$smtpUser) ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Odesílatel</td>
                            <td><code><?= h((string)$smtpFrom) ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Šifrování</td>
                            <td><span class="badge bg-success">STARTTLS</span></td>
                        </tr>
                    </tbody>
                </table>
                <div class="alert alert-info mt-3 mb-0 small">
                    <i class="fas fa-info-circle me-1"></i>
                    SMTP nastavení se mění v souboru <code>config/env.php</code>.
                </div>
            </div>
        </div>
    </div>

    <!-- ── Karta: Test SMTP ── -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
                <i class="fas fa-paper-plane me-2"></i>Test odeslání e-mailu
            </div>
            <div class="card-body p-4">
                <?php if ($testResult !== null): ?>
                    <?php if ($testResult['ok']): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-1"></i>
                        Test e-mail byl úspěšně odeslán na <strong><?= h($testResult['email']) ?></strong>.
                        Zkontrolujte doručenou poštu (nebo spam).
                    </div>
                    <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Chyba při odesílání:</strong><br>
                        <code><?= h($testResult['error']) ?></code>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <p class="text-muted small mb-3">
                    Odesílá jednoduchý testovací e-mail, kterým ověříte, zda SMTP funguje správně.
                    Tuto funkci použijte také k ověření, proč trenérovi nepřichází uvítací e-mail.
                </p>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="test_smtp">
                    <div class="input-group">
                        <input type="email" name="test_email" class="form-control"
                               placeholder="Cílová e-mailová adresa" required
                               value="<?= h($_POST['test_email'] ?? $smtpUser) ?>">
                        <button type="submit" class="btn fw-semibold" style="background:#7c3aed;color:#fff;border:none">
                            <i class="fas fa-paper-plane me-1"></i>Odeslat test
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Karta: Typy notifikací ── -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
                <i class="fas fa-bell me-2"></i>Typy e-mailových notifikací
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Typ notifikace</th>
                                <th>Kdy se odesílá</th>
                                <th>Příjemce</th>
                                <th>Stav</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <i class="fas fa-dumbbell me-2 text-purple" style="color:#7c3aed"></i>
                                    <strong>Souhrn tréninku</strong>
                                </td>
                                <td>Po dokončení tréninku trenérem</td>
                                <td>Sportovec (e-mail v kartě sportovce)</td>
                                <td><span class="badge bg-success">Aktivní</span></td>
                            </tr>
                            <tr>
                                <td>
                                    <i class="fas fa-user-plus me-2" style="color:#7c3aed"></i>
                                    <strong>Vítejte v TrainerApp</strong>
                                </td>
                                <td>Při vytvoření trenérského účtu adminem</td>
                                <td>Nový trenér (e-mail v kartě trenéra)</td>
                                <td><span class="badge bg-success">Aktivní</span></td>
                            </tr>
                            <tr>
                                <td>
                                    <i class="fas fa-cake-candles me-2" style="color:#f59e0b"></i>
                                    <strong>Upozornění na narozeniny</strong>
                                </td>
                                <td>4 dny před narozeninami sportovce</td>
                                <td>Trenér sportovce</td>
                                <td><span class="badge bg-warning text-dark">Vyžaduje cron</span></td>
                            </tr>
                            <tr>
                                <td>
                                    <i class="fas fa-birthday-cake me-2" style="color:#ef4444"></i>
                                    <strong>Narozeniny dnes!</strong>
                                </td>
                                <td>V den narozenin sportovce</td>
                                <td>Trenér sportovce</td>
                                <td><span class="badge bg-warning text-dark">Vyžaduje cron</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Karta: Narozeninové notifikace – cron ── -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
                <i class="fas fa-cake-candles me-2"></i>Narozeninové notifikace – automatizace
            </div>
            <div class="card-body p-4">

                <?php if ($cronResult !== null): ?>
                    <?php
                    $sentCount = count(array_filter($cronResult, function ($r) { return $r['sent']; }));
                    $total     = count($cronResult);
                    ?>
                    <div class="alert alert-<?= $total === 0 ? 'info' : ($sentCount === $total ? 'success' : 'warning') ?> mb-4">
                        <?php if ($total === 0): ?>
                            <i class="fas fa-info-circle me-1"></i>
                            Žádné notifikace k odeslání (žádné narozeniny dnes ani za 4 dny, nebo již odeslány).
                        <?php else: ?>
                            <i class="fas fa-check-circle me-1"></i>
                            Zpracováno: <strong><?= $total ?></strong> notifikací,
                            odesláno: <strong><?= $sentCount ?></strong>,
                            chyby: <strong><?= $total - $sentCount ?></strong>.
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($cronResult)): ?>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr><th>Sportovec</th><th>Typ</th><th>Trenér (e-mail)</th><th>Výsledek</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($cronResult as $r): ?>
                                <tr>
                                    <td><?= h($r['athlete']) ?><?= isset($r['age']) ? ' <span class="text-muted small">(' . $r['age'] . ' let)</span>' : '' ?></td>
                                    <td>
                                        <?php if ($r['type'] === 'birthday'): ?>
                                            <span class="badge" style="background:#f59e0b">Narozeniny dnes</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Za 4 dny</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($r['coach_email']) ?></td>
                                    <td>
                                        <?php if ($r['sent']): ?>
                                            <i class="fas fa-check-circle text-success"></i> Odesláno
                                        <?php else: ?>
                                            <i class="fas fa-times-circle text-danger"></i> Chyba
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <h6 class="fw-bold mb-3">Nastavení cronu</h6>
                <p class="text-muted small mb-2">
                    Narozeninové e-maily se odesílají automaticky jednou denně přes cron.
                    Nastavte cron u vašeho hostingu (Wedos → Správa cronů):
                </p>

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Příkaz (CLI cron – doporučeno):</label>
                    <div class="input-group">
                        <input type="text" class="form-control form-control-sm font-monospace"
                               id="cronCmd"
                               value="php <?= h(str_replace('\\', '/', realpath(__DIR__ . '/../cron_birthday.php'))) ?>"
                               readonly>
                        <button class="btn btn-outline-secondary btn-sm" type="button"
                                onclick="copyText('cronCmd')">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <div class="form-text">Spouštět každý den – ideálně v 6:00 ráno.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold small">URL cron (HTTP – alternativa):</label>
                    <div class="input-group">
                        <input type="text" class="form-control form-control-sm font-monospace"
                               id="cronUrl"
                               value="<?= h($cronUrl) ?>"
                               readonly>
                        <button class="btn btn-outline-secondary btn-sm" type="button"
                                onclick="copyText('cronUrl')">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <div class="form-text text-warning">
                        <i class="fas fa-shield-alt me-1"></i>
                        URL obsahuje secret token – nikomu ji nezveřejňujte!
                    </div>
                </div>

                <hr>
                <h6 class="fw-bold mb-3">Ruční spuštění</h6>
                <p class="text-muted small mb-3">
                    Kliknutím spustíte notifikace ihned. Duplicity jsou hlídány – pokud byl email pro
                    daného sportovce letos již odeslán, odesílat se nebude znovu.
                </p>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="run_birthday">
                    <button type="submit" class="btn fw-semibold"
                            style="background:#7c3aed;color:#fff;border:none"
                            onclick="return confirm('Spustit narozeninové notifikace nyní?')">
                        <i class="fas fa-play me-1"></i>Spustit notifikace nyní
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Karta: Nadcházející narozeniny ── -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
                <i class="fas fa-calendar-days me-2"></i>Nadcházející narozeniny (14 dní)
            </div>
            <div class="card-body p-0">
                <?php if (empty($upcomingBirthdays)): ?>
                <div class="p-4 text-muted text-center small">
                    <i class="fas fa-calendar-xmark fa-2x mb-2 d-block"></i>
                    Žádné narozeniny v příštích 14 dnech.
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($upcomingBirthdays as $b): ?>
                    <?php
                        $days    = (int)$b['days_left'];
                        $bdFmt   = (new DateTime($b['birth_date']))->format('d.m.');
                        $coach   = $b['coach_name'] ?: $b['coach_username'];
                        $hasEmail = !empty($b['coach_email']);
                        if ($days === 0) {
                            $dayLabel = '<span class="badge" style="background:#ef4444">Dnes!</span>';
                        } elseif ($days <= 4) {
                            $dayLabel = '<span class="badge bg-warning text-dark">Za ' . $days . ' dní</span>';
                        } else {
                            $dayLabel = '<span class="badge bg-secondary">Za ' . $days . ' dní</span>';
                        }
                    ?>
                    <div class="list-group-item px-4 py-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong><?= h($b['first_name'] . ' ' . $b['last_name']) ?></strong>
                                <span class="text-muted small ms-1">(<?= h($bdFmt) ?>)</span><br>
                                <span class="text-muted small">
                                    <i class="fas fa-user-tie me-1"></i><?= h($coach) ?>
                                    <?php if (!$hasEmail): ?>
                                    <span class="badge bg-danger ms-1" title="Trenér nemá e-mail – notifikace nepůjde odeslat">bez e-mailu</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div><?= $dayLabel ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Karta: Log odeslaných notifikací ── -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
                <i class="fas fa-history me-2"></i>Log odeslaných narozeninových notifikací
                <span class="badge bg-secondary ms-2"><?= count($recentNotifs) ?></span>
            </div>
            <?php if (empty($recentNotifs)): ?>
            <div class="card-body text-muted text-center py-4 small">
                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                Zatím nebyly odeslány žádné narozeninové notifikace.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Odesláno</th>
                            <th>Typ</th>
                            <th>Sportovec</th>
                            <th>Trenér</th>
                            <th>E-mail trenéra</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentNotifs as $n): ?>
                    <tr>
                        <td class="text-muted small"><?= h(formatDateTime($n['sent_at'])) ?></td>
                        <td>
                            <?php if ($n['notification_type'] === 'birthday'): ?>
                                <span class="badge" style="background:#f59e0b">
                                    <i class="fas fa-birthday-cake me-1"></i>Narozeniny
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    <i class="fas fa-bell me-1"></i>Upozornění (4 dny)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= h($n['first_name'] . ' ' . $n['last_name']) ?>
                            <?php if (!empty($n['birth_date'])): ?>
                            <span class="text-muted small">(<?= h((new DateTime($n['birth_date']))->format('d.m.Y')) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($n['coach_name'] ?: $n['coach_username']) ?></td>
                        <td class="text-muted small"><?= h($n['coach_email'] ?? '–') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.row -->

<script>
function copyText(id) {
    var el = document.getElementById(id);
    el.select();
    el.setSelectionRange(0, 99999);
    try {
        document.execCommand('copy');
        el.blur();
    } catch (e) {}
}
</script>

<?php renderAdminFooter(); ?>
