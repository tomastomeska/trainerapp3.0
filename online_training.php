<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/online_training.php';

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
                onlineTrainingSend($pdo, (int)$_POST['training_id'], $coachId, (string)($_POST['billing_type'] ?? 'free'), (int)($_POST['subscription_id'] ?? 0) ?: null, (int)($_POST['subscription_total'] ?? 0) ?: null, (float)($_POST['subscription_price'] ?? 0));
                flash('success', 'Online trénink byl odeslán sportovci.');
            } catch (Throwable $e) { flash('danger', $e->getMessage()); }
        }
        redirect(BASE_URL . '/online_training.php');
    }
    $stmt = $pdo->prepare('SELECT ot.*, a.first_name, a.last_name, ws.name AS set_name FROM online_trainings ot JOIN athletes a ON a.id = ot.athlete_id JOIN workout_sets ws ON ws.id = ot.training_set_id WHERE ot.trainer_id = ? ORDER BY COALESCE(ot.sent_at, ot.created_at) DESC, ot.id DESC');
    $stmt->execute([$coachId]);
    $trainings = $stmt->fetchAll();
    require_once __DIR__ . '/includes/header.php';
    renderHeader('Online tréninky', false, true);
    ?>
    <div class="d-flex justify-content-between align-items-center mb-4"><h2 class="mb-0"><i class="fas fa-laptop me-2 text-warning"></i>Online tréninky</h2><a class="btn btn-warning fw-bold" href="<?= BASE_URL ?>/online_training_new.php"><i class="fas fa-plus me-1"></i>Nový online trénink</a></div>
    <div class="alert alert-info">Online tréninky se účtují při odeslání. Jejich výsledky nejsou použity jako poslední výkon v klasickém tréninku.</div>
    <?php if (!$trainings): ?><div class="alert alert-secondary">Zatím nebyl vytvořen žádný online trénink.</div><?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Číslo</th><th>Sportovec</th><th>Sada</th><th>Odesláno</th><th>Stav</th><th>Účtování</th><th></th></tr></thead><tbody>
    <?php foreach ($trainings as $training): ?><tr><td><strong class="text-warning">ONLINE #<?= str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT) ?></strong></td><td><?= h($training['first_name'] . ' ' . $training['last_name']) ?></td><td><?= h($training['set_name']) ?></td><td><?= !empty($training['sent_at']) ? h(formatDateTime($training['sent_at'])) : 'draft' ?></td><td><span class="badge bg-<?= h(onlineTrainingStatusClass($training['status'])) ?>"><?= h(onlineTrainingStatusLabel($training['status'])) ?></span></td><td><?= $training['billing_type'] === 'subscription' ? 'Předplatné' : ($training['billing_type'] === 'single' ? number_format((float)$training['price'], 2, ',', ' ') . ' Kč' : 'Zdarma') ?></td><td><a class="btn btn-sm btn-outline-dark" href="<?= BASE_URL ?>/online_training.php?id=<?= (int)$training['id'] ?>">Detail</a></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php renderFooter(); exit;
}

requireAthleteLogin();
$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['training_id'] ?? 0);
    if ($action === 'start') $pdo->prepare("UPDATE online_trainings SET status = 'in_progress', started_at = COALESCE(started_at, NOW()) WHERE id = ? AND athlete_id = ? AND status = 'sent'")->execute([$id, $athleteId]);
    redirect(BASE_URL . '/online_training.php?id=' . $id);
}
if (isset($_GET['id'])) {
    require_once __DIR__ . '/online_training_detail.php'; exit;
}
$stmt = $pdo->prepare('SELECT ot.*, c.name AS coach_name FROM online_trainings ot JOIN coaches c ON c.id = ot.trainer_id WHERE ot.athlete_id = ? AND ot.status <> \'created\' ORDER BY COALESCE(ot.sent_at, ot.created_at) DESC, ot.id DESC');
$stmt->execute([$athleteId]);
$trainings = $stmt->fetchAll();
require_once __DIR__ . '/includes/athlete_header.php';
renderAthleteHeader('Online tréninky', false, true);
?><div class="d-flex justify-content-between align-items-center mb-4"><h2><i class="fas fa-laptop me-2 text-warning"></i>Online tréninky</h2></div>
<div class="row g-3"><?php foreach ($trainings as $training): ?><div class="col-12 col-lg-6"><div class="card shadow-sm border-start border-warning border-4"><div class="card-body"><div class="d-flex justify-content-between"><strong class="text-warning">ONLINE #<?= str_pad((string)$training['sequence_number'], 3, '0', STR_PAD_LEFT) ?></strong><span class="badge bg-<?= h(onlineTrainingStatusClass($training['status'])) ?>"><?= h(onlineTrainingStatusLabel($training['status'])) ?></span></div><h5 class="mt-2"><?= h($training['title']) ?></h5><div class="text-muted">Trenér: <?= h($training['coach_name']) ?> · <?= h(formatDateTime($training['sent_at'])) ?></div><a class="btn btn-outline-dark btn-sm mt-3" href="<?= BASE_URL ?>/online_training.php?id=<?= (int)$training['id'] ?>">Otevřít</a></div></div></div><?php endforeach; ?></div><?php renderAthleteFooter();