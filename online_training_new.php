<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_training.php';
requireLogin();
$pdo = getDB(); $coachId = (int)getCurrentCoachId();
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) $error = 'Neplatný bezpečnostní token.';
    else try {
        $athleteId = (int)($_POST['athlete_id'] ?? 0); $setId = (int)($_POST['training_set_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? '')); $note = trim((string)($_POST['coach_note'] ?? ''));
        if ($athleteId <= 0 || $setId <= 0) throw new RuntimeException('Vyberte sportovce i tréninkovou sadu.');
        $trainingId = onlineTrainingCreateFromSet($pdo, $coachId, $athleteId, $setId, $title, $note);
        redirect(BASE_URL . '/online_training.php?id=' . $trainingId);
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$athletes = $pdo->prepare('SELECT id, first_name, last_name, online_training_rate FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name'); $athletes->execute([$coachId]); $athletes = $athletes->fetchAll();
$subscriptionStmt = $pdo->prepare("SELECT athlete_id, total_trainings, remaining_trainings FROM online_training_subscriptions WHERE trainer_id = ? AND status = 'active' AND remaining_trainings > 0 ORDER BY purchased_at ASC, id ASC");
$subscriptionStmt->execute([$coachId]);
$subscriptionByAthlete = [];
foreach ($subscriptionStmt->fetchAll() as $subscription) {
    $subscriptionByAthlete[(int)$subscription['athlete_id']] = $subscription;
}
$globalSetsEnabled = workoutSetsHasColumn('is_global') && workoutSetsHasColumn('description');
$setsSql = 'SELECT ws.id, ws.name, ' . ($globalSetsEnabled ? 'ws.is_global, ws.description, ' : '0 AS is_global, NULL AS description, ') . 'COUNT(wse.id) exercise_count FROM workout_sets ws LEFT JOIN workout_set_exercises wse ON wse.workout_set_id = ws.id WHERE ' . ($globalSetsEnabled ? '(ws.coach_id = ? OR ws.is_global = 1)' : 'ws.coach_id = ?') . (workoutSetArchivingEnabled() ? ' AND ws.is_active = 1' : '') . ' GROUP BY ws.id ORDER BY ' . ($globalSetsEnabled ? 'ws.is_global DESC, ' : '') . 'ws.name';
$sets = $pdo->prepare($setsSql); $sets->execute([$coachId]); $sets = $sets->fetchAll();
require_once __DIR__ . '/includes/header.php'; renderHeader('Nový online trénink', false, true);
?><div class="d-flex align-items-center gap-3 mb-4"><a href="<?= BASE_URL ?>/online_training.php" class="btn btn-outline-secondary btn-sm">Zpět</a><h2 class="mb-0"><i class="fas fa-laptop me-2 text-warning"></i>Nový online trénink</h2></div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><div class="row g-3"><div class="col-md-6"><label class="form-label fw-bold">Sportovec</label><select id="online-athlete-id" name="athlete_id" class="form-select" required><option value="">Vyberte sportovce</option><?php foreach ($athletes as $athlete): ?><?php $subscription = $subscriptionByAthlete[(int)$athlete['id']] ?? null; ?><option value="<?= (int)$athlete['id'] ?>" data-rate="<?= h((string)($athlete['online_training_rate'] ?? '')) ?>" data-subscription-remaining="<?= (int)($subscription['remaining_trainings'] ?? 0) ?>" data-subscription-total="<?= (int)($subscription['total_trainings'] ?? 0) ?>" data-detail-url="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$athlete['id'] ?>"><?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label fw-bold">Tréninková sada</label><select id="online-training-set" name="training_set_id" class="form-select" required><option value="">Vyberte sadu</option><?php foreach ($sets as $set): ?><option value="<?= (int)$set['id'] ?>" data-description="<?= h((string)($set['description'] ?? '')) ?>"><?= $set['is_global'] ? 'Globální: ' : '' ?><?= h($set['name']) ?> (<?= (int)$set['exercise_count'] ?> cviků)</option><?php endforeach; ?></select></div><div class="col-12 d-none" id="online-set-description"><div class="alert alert-info mb-0"><strong>Pro koho je sada vhodná:</strong> <span></span></div></div><div class="col-12"><div id="online-billing-preview" class="alert alert-secondary mb-0">Vyberte sportovce. Aplikace automaticky použije jeho aktivní předplatné, jinak jednorázovou sazbu.</div></div><div class="col-12"><label class="form-label">Název online tréninku</label><input name="title" class="form-control" placeholder="Výchozí: název sady"></div><div class="col-12"><label class="form-label">Poznámka trenéra</label><textarea name="coach_note" class="form-control" rows="4"></textarea></div></div><button class="btn btn-warning fw-bold mt-4"><i class="fas fa-arrow-right me-1"></i>Vytvořit draft</button></div></form>
<script>
(function () {
    const athleteSelect = document.getElementById('online-athlete-id');
    const setSelect = document.getElementById('online-training-set');
    const setDescription = document.getElementById('online-set-description');
    const preview = document.getElementById('online-billing-preview');
    function updateBillingPreview() {
        const option = athleteSelect.options[athleteSelect.selectedIndex];
        const remaining = Number(option?.dataset.subscriptionRemaining || 0);
        const total = Number(option?.dataset.subscriptionTotal || 0);
        const rate = option?.dataset.rate || '';
        if (!option || !option.value) { preview.className = 'alert alert-secondary mb-0'; preview.textContent = 'Vyberte sportovce. Aplikace automaticky použije jeho aktivní předplatné, jinak jednorázovou sazbu.'; return; }
        if (remaining > 0) { preview.className = 'alert alert-success mb-0'; preview.innerHTML = '<strong>Automatické účtování: předplatné.</strong> Při odeslání se odečte 1 trénink, zbývá ' + remaining + '/' + total + '.'; return; }
        if (rate !== '' && Number(rate) > 0) { preview.className = 'alert alert-info mb-0'; preview.innerHTML = '<strong>Automatické účtování: jednorázová sazba.</strong> Při odeslání bude zaúčtováno ' + Number(rate).toLocaleString('cs-CZ', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Kč.'; return; }
        preview.className = 'alert alert-warning mb-0'; preview.innerHTML = '<strong>Sportovec nemá nastavenou sazbu ani aktivní předplatné.</strong> Nastavte ceník v <a href="' + option.dataset.detailUrl + '" class="alert-link">detailu sportovce</a>, nebo při odeslání výslovně zvolte Zdarma.';
    }
    athleteSelect.addEventListener('change', updateBillingPreview);
    setSelect.addEventListener('change', function () { const description = setSelect.options[setSelect.selectedIndex]?.dataset.description || ''; setDescription.classList.toggle('d-none', description === ''); setDescription.querySelector('span').textContent = description; });
    updateBillingPreview();
})();
</script><?php renderFooter();