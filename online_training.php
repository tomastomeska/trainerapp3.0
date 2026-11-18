<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_training.php';
require_once __DIR__ . '/includes/calculators.php';

if (isLoggedIn()) {
    requireLogin();
    $pdo = getDB();
    $coachId = (int)getCurrentCoachId();
    if (isset($_GET['id'])) {
        require_once __DIR__ . '/online_training_detail.php';
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'send') {
            try {
                onlineTrainingSend($pdo, (int)$_POST['training_id'], $coachId);
                flash('success', 'Online trénink byl odeslán sportovci.');
            } catch (Throwable $e) { flash('danger', $e->getMessage()); }
        } elseif ($action === 'copy') {
            $athleteId = (int)($_POST['athlete_id'] ?? 0);
            try {
                $newTrainingId = onlineTrainingCopy($pdo, $coachId, $athleteId, (int)($_POST['training_id'] ?? 0), ($_POST['copy_attachments'] ?? '') === '1');
                redirect(BASE_URL . '/online_training.php?id=' . $newTrainingId);
            } catch (Throwable $e) {
                flash('danger', $e->getMessage());
            }
        } elseif ($action === 'delete_draft') {
            try {
                $deletedTraining = onlineTrainingDeleteDraft($pdo, $coachId, (int)($_POST['training_id'] ?? 0));
                $message = 'Neodeslaný online trénink #' . str_pad((string)$deletedTraining['sequence_number'], 3, '0', STR_PAD_LEFT) . ' byl smazán.';
                if ($deletedTraining['cleanup_failed']) {
                    $message .= ' Některé soubory příloh se nepodařilo odstranit; chyba byla zaznamenána.';
                }
                flash('success', $message);
            } catch (Throwable $e) {
                flash('danger', $e->getMessage());
            }
        }
        $returnUrl = in_array($action, ['copy', 'delete_draft'], true) && (int)($_POST['athlete_id'] ?? 0) > 0
            ? BASE_URL . '/online_training.php?athlete_id=' . (int)$_POST['athlete_id']
            : BASE_URL . '/online_training.php';
        redirect($returnUrl);
    }
    $selectedAthleteId = (int)($_GET['athlete_id'] ?? 0);
    $sql = 'SELECT ot.*, a.first_name, a.last_name, a.email AS athlete_email, ws.name AS set_name FROM online_trainings ot JOIN athletes a ON a.id = ot.athlete_id JOIN workout_sets ws ON ws.id = ot.training_set_id WHERE ot.trainer_id = ?';
    $params = [$coachId];
    if ($selectedAthleteId > 0) {
        $sql .= ' AND ot.athlete_id = ?';
        $params[] = $selectedAthleteId;
    }
    $sql .= ' ORDER BY a.last_name, a.first_name, COALESCE(ot.sent_at, ot.created_at) DESC, ot.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $trainings = $stmt->fetchAll();
    $trainingGroups = [];
    foreach ($trainings as $training) {
        $trainingGroups[(int)$training['athlete_id']][] = $training;
    }
    require_once __DIR__ . '/includes/header.php';
    renderHeader('Online tréninky', false, true);
    ?>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4"><h2 class="mb-0"><i class="fas fa-laptop me-2 text-warning"></i>Online tréninky</h2><div class="d-flex gap-2"><a class="btn btn-outline-dark" href="<?= BASE_URL ?>/online_training_manual.php"><i class="fas fa-circle-question me-1"></i>Návod</a><a class="btn btn-warning fw-bold" href="<?= BASE_URL ?>/online_training_new.php"><i class="fas fa-plus me-1"></i>Nový online trénink</a></div></div>
    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2"><span>Pro odesílání je nutné platné předplatné. Výsledky nejsou použity jako poslední výkon v klasickém tréninku. <a href="<?= BASE_URL ?>/online_subscriptions.php">Správa online předplatných</a></span><a class="alert-link fw-bold text-nowrap" href="<?= BASE_URL ?>/online_training_manual.php"><i class="fas fa-book-open me-1"></i>Podrobný návod pro trenéry</a></div>
    <?php if (!$trainings): ?><div class="alert alert-secondary">Zatím nebyl vytvořen žádný online trénink.</div><?php elseif ($selectedAthleteId === 0): ?>
    <div class="row g-3"><?php foreach ($trainingGroups as $athleteTrainings): ?><?php $athlete = $athleteTrainings[0]; $openCount = count(array_filter($athleteTrainings, static fn(array $row): bool => in_array($row['status'], ['sent', 'in_progress'], true))); $pendingReviewCount = count(array_filter($athleteTrainings, static fn(array $row): bool => $row['status'] === 'completed' && (empty($row['trainer_reviewed_at']) || trim((string)($row['coach_reaction'] ?? '')) === ''))); ?><div class="col-md-6 col-xl-4"><a class="card h-100 shadow-sm border-start border-warning border-4 text-decoration-none text-dark" href="<?= BASE_URL ?>/online_training.php?athlete_id=<?= (int)$athlete['athlete_id'] ?>"><div class="card-body"><div class="d-flex justify-content-between align-items-start"><div><h5 class="mb-1"><?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?></h5><div class="small text-muted"><?= h((string)$athlete['athlete_email']) ?></div></div><?php if ($pendingReviewCount > 0): ?><span class="badge bg-warning text-dark"><?= $pendingReviewCount ?></span><?php endif; ?></div><div class="mt-3 small"><span class="badge bg-success me-1"><?= count(array_filter($athleteTrainings, static fn(array $row): bool => $row['status'] === 'completed')) ?> dokončeno</span><?php if ($openCount > 0): ?><span class="badge bg-primary"><?= $openCount ?> aktivní</span><?php endif; ?></div><div class="mt-3 fw-semibold">Zobrazit online tréninky <i class="fas fa-arrow-right ms-1"></i></div></div></a></div><?php endforeach; ?></div>
    <?php else: ?>
    <?php $selectedAthlete = $trainings[0]; ?>
    <div class="d-flex align-items-center gap-2 mb-3"><a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/online_training.php"><i class="fas fa-arrow-left me-1"></i>Sportovci</a><strong><?= h($selectedAthlete['first_name'] . ' ' . $selectedAthlete['last_name']) ?></strong></div>
    <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th>Číslo</th><th>Sada</th><th>Odesláno</th><th>Stav a průběh</th><th>Účtování</th><th>Akce</th></tr></thead>
    <tbody>
    <?php foreach ($trainings as $training): ?>
    <?php
    $trainingCompleted = $training['status'] === 'completed';
    $trainingReviewed = !empty($training['trainer_reviewed_at']);
    $trainingReacted = trim((string)($training['coach_reaction'] ?? '')) !== '';
    $progressBadgeClass = static fn(bool $done): string => $done ? 'bg-success' : 'bg-secondary';
    ?>
    <tr>
    <td><strong class="text-warning">ONLINE #<?= str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT) ?></strong></td>
    <td><?= h($training['set_name']) ?></td>
    <td><?= !empty($training['sent_at']) ? h(formatDateTime($training['sent_at'])) : 'draft' ?></td>
    <td>
    <?php if (!$trainingCompleted): ?><span class="badge bg-<?= h(onlineTrainingStatusClass($training['status'])) ?>"><?= h(onlineTrainingStatusLabel($training['status'])) ?></span><?php endif; ?>
    <div class="d-flex flex-wrap gap-1 mt-1">
    <span class="badge <?= $progressBadgeClass($trainingCompleted) ?>">Dokončeno</span>
    <span class="badge <?= $progressBadgeClass($trainingReviewed) ?>">Zkontrolováno</span>
    <span class="badge <?= $progressBadgeClass($trainingReacted) ?>">Reagováno</span>
    </div>
    <?php if ($trainingCompleted && !$trainingReviewed): ?><a class="btn btn-sm btn-outline-primary mt-1" href="<?= BASE_URL ?>/online_training.php?id=<?= (int)$training['id'] ?>">Prohlédnout</a><?php endif; ?>
    </td>
    <td><?= $training['billing_type'] === 'subscription' ? 'Předplatné' : ($training['billing_type'] === 'single' ? number_format((float)$training['price'], 2, ',', ' ') . ' Kč' : 'Zdarma') ?></td>
    <td class="text-nowrap">
        <a class="btn btn-sm btn-outline-dark" href="<?= BASE_URL ?>/online_training.php?id=<?= (int)$training['id'] ?>">Detail</a>
        <form method="post" class="d-inline" onsubmit="<?= $training['status'] === 'created' ? "this.elements.copy_attachments.value = '0'; return confirm('Jde o neodeslaný trénink. Z důvodu ochrany před převzetím příloh z dřívější kopie bude nový trénink vytvořen bez příloh. Pokračovat?');" : "this.elements.copy_attachments.value = confirm('Zkopírovat také všechny přílohy (obrázky, videa a odkazy)?\\n\\nOK = Ano, zkopírovat přílohy\\nZrušit = Ne, vytvořit kopii bez příloh') ? '1' : '0';" ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="copy">
            <input type="hidden" name="training_id" value="<?= (int)$training['id'] ?>">
            <input type="hidden" name="athlete_id" value="<?= (int)$training['athlete_id'] ?>">
            <input type="hidden" name="copy_attachments" value="0">
            <button type="submit" class="btn btn-sm btn-outline-warning"><i class="fas fa-copy me-1"></i>Kopírovat</button>
        </form>
        <?php if ($training['status'] === 'created'): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tento neodeslaný online trénink včetně jeho příloh? Tuto akci nelze vrátit.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_draft">
            <input type="hidden" name="training_id" value="<?= (int)$training['id'] ?>">
            <input type="hidden" name="athlete_id" value="<?= (int)$training['athlete_id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Smazat</button>
        </form>
        <?php endif; ?>
    </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <?php renderFooter(); exit;
}

requireAthleteLogin();
$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();
if (isset($_GET['id'])) {
    require_once __DIR__ . '/online_training_detail.php'; exit;
}
$currentAthlete = getCurrentAthlete();
$activeOnlineSubscription = $currentAthlete
    ? onlineTrainingActiveSubscription($pdo, (int)$currentAthlete['coach_id'], $athleteId)
    : null;
$athletePaymentsEnabled = false;
if ($currentAthlete) {
    require_once __DIR__ . '/includes/payment_access.php';
    paymentAccessEnsureSchema($pdo);
    $athletePaymentsEnabled = paymentAccessIsEnabled($pdo, $athleteId, (int)$currentAthlete['coach_id']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['training_id'] ?? 0);
    if ($action === 'start') $pdo->prepare("UPDATE online_trainings SET status = 'in_progress', started_at = COALESCE(started_at, NOW()) WHERE id = ? AND athlete_id = ? AND status = 'sent'")->execute([$id, $athleteId]);
    redirect(BASE_URL . '/online_training.php?id=' . $id);
}
$stmt = $pdo->prepare('SELECT ot.*, c.name AS coach_name FROM online_trainings ot JOIN coaches c ON c.id = ot.trainer_id WHERE ot.athlete_id = ? AND ot.status <> \'created\' ORDER BY COALESCE(ot.sent_at, ot.created_at) DESC, ot.id DESC');
$stmt->execute([$athleteId]);
$trainings = $stmt->fetchAll();
require_once __DIR__ . '/includes/athlete_header.php';
renderAthleteHeader('Online tréninky', false, true);
?><div class="d-flex justify-content-between align-items-center mb-4"><h2><i class="fas fa-laptop me-2 text-warning"></i>Online tréninky</h2></div>
<?php if (!$activeOnlineSubscription): ?>
<div class="alert alert-secondary mb-3 d-md-none">
    <strong>Online tréninky:</strong> Nemáte platné předplatné.
    <?php if ($athletePaymentsEnabled): ?><a href="<?= BASE_URL ?>/athlete_payments.php" class="ms-2">Platby</a><?php endif; ?>
</div>
<?php endif; ?>
<div class="row g-3">
<?php foreach ($trainings as $training): ?>
<?php
$athleteStatusLabel = onlineTrainingAthleteStatusLabel($training['status']);
$athleteStatusClass = onlineTrainingAthleteStatusClass($training['status']);
$trainingCompleted = $training['status'] === 'completed';
$trainingReviewed = !empty($training['trainer_reviewed_at']);
$trainingReacted = trim((string)($training['coach_reaction'] ?? '')) !== '';
$progressBadgeClass = static fn(bool $done): string => $done ? 'bg-success' : 'bg-secondary';
?>
<div class="col-12 col-lg-6">
<div class="card shadow-sm border-start border-warning border-4">
<div class="card-body">
<div class="d-flex justify-content-between">
<strong class="text-warning">ONLINE #<?= str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT) ?></strong>
<?php if (!$trainingCompleted): ?><span class="badge bg-<?= h($athleteStatusClass) ?>"><?= h($athleteStatusLabel) ?></span><?php endif; ?>
</div>
<h5 class="mt-2"><?= h($training['title']) ?></h5>
<?php if ($trainingCompleted && calculatorAppVisibleForUser('athlete', $athleteId)): ?>
<?php if (calculatorIsEnabled('performance') && calculatorIsEnabled('performance_load')): ?>
<a class="btn btn-outline-dark btn-sm" href="<?= BASE_URL ?>/performance.php?module=load&amp;date=<?= h(substr($training['started_at'] ?: $training['completed_at'], 0, 10)) ?>">Potvrdit aktivní minuty a RPE</a>
<?php endif; endif; ?>
<div class="d-flex flex-wrap gap-1 mt-2">
<span class="badge <?= $progressBadgeClass($trainingCompleted) ?>">Dokončeno</span>
<span class="badge <?= $progressBadgeClass($trainingReviewed) ?>">Zkontrolováno</span>
<span class="badge <?= $progressBadgeClass($trainingReacted) ?>">Reagováno</span>
</div>
<div class="text-muted mt-2">Trenér: <?= h($training['coach_name']) ?> · Odesláno: <?= h(formatDateTime($training['sent_at'])) ?></div>
<?php if (!empty($training['completed_at'])): ?><div class="small text-success fw-semibold mt-1"><i class="fas fa-check-circle me-1"></i>Odcvičeno: <?= h(formatDateTime($training['completed_at'])) ?></div><?php endif; ?>
<a class="btn btn-outline-dark btn-sm mt-3" href="<?= BASE_URL ?>/online_training.php?id=<?= (int)$training['id'] ?>">Otevřít</a>
</div>
</div>
</div>
<?php endforeach; ?>
</div><?php renderAthleteFooter();