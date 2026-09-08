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
        if (isset($_POST['online_training_rate']) && $_POST['online_training_rate'] !== '') {
            $rate = max(0, (float)str_replace(',', '.', (string)$_POST['online_training_rate']));
            $pdo->prepare('UPDATE athletes SET online_training_rate = ? WHERE id = ? AND coach_id = ?')->execute([$rate, $athleteId, $coachId]);
        }
        redirect(BASE_URL . '/online_training.php?id=' . $trainingId);
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$athletes = $pdo->prepare('SELECT id, first_name, last_name, online_training_rate FROM athletes WHERE coach_id = ? ORDER BY first_name, last_name'); $athletes->execute([$coachId]); $athletes = $athletes->fetchAll();
$setsSql = 'SELECT ws.id, ws.name, COUNT(wse.id) exercise_count FROM workout_sets ws LEFT JOIN workout_set_exercises wse ON wse.workout_set_id = ws.id WHERE ws.coach_id = ?' . (workoutSetArchivingEnabled() ? ' AND ws.is_active = 1' : '') . ' GROUP BY ws.id ORDER BY ws.name';
$sets = $pdo->prepare($setsSql); $sets->execute([$coachId]); $sets = $sets->fetchAll();
require_once __DIR__ . '/includes/header.php'; renderHeader('Nový online trénink', false, true);
?><div class="d-flex align-items-center gap-3 mb-4"><a href="<?= BASE_URL ?>/online_training.php" class="btn btn-outline-secondary btn-sm">Zpět</a><h2 class="mb-0"><i class="fas fa-laptop me-2 text-warning"></i>Nový online trénink</h2></div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><div class="row g-3"><div class="col-md-6"><label class="form-label fw-bold">Sportovec</label><select name="athlete_id" class="form-select" required><option value="">Vyberte sportovce</option><?php foreach ($athletes as $athlete): ?><option value="<?= (int)$athlete['id'] ?>"><?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?><?= $athlete['online_training_rate'] !== null ? ' - sazba ' . h((string)$athlete['online_training_rate']) . ' Kč' : '' ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label fw-bold">Tréninková sada</label><select name="training_set_id" class="form-select" required><option value="">Vyberte sadu</option><?php foreach ($sets as $set): ?><option value="<?= (int)$set['id'] ?>"><?= h($set['name']) ?> (<?= (int)$set['exercise_count'] ?> cviků)</option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Název online tréninku</label><input name="title" class="form-control" placeholder="Výchozí: název sady"></div><div class="col-md-6"><label class="form-label">Sazba sportovce (Kč)</label><input name="online_training_rate" type="number" min="0" step="0.01" class="form-control" placeholder="Prázdné = zdarma"></div><div class="col-12"><label class="form-label">Poznámka trenéra</label><textarea name="coach_note" class="form-control" rows="4"></textarea></div></div><button class="btn btn-warning fw-bold mt-4"><i class="fas fa-arrow-right me-1"></i>Vytvořit draft</button></div></form><?php renderFooter();