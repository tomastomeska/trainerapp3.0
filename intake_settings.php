<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/intake_questionnaire.php';
require_once __DIR__ . '/includes/header.php';
requireLogin();
$pdo = getDB();
$coachId = (int)getCurrentCoachId();
$error = null;
$types = ['yes_no' => 'ANO / NE', 'text' => 'Vlastní text', 'combined' => 'ANO / NE + volitelný text'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Neplatný bezpečnostní token.');
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['question_id'] ?? 0);
        if ($action === 'save') {
            $text = trim((string)($_POST['question_text'] ?? ''));
            $type = (string)($_POST['question_type'] ?? '');
            $order = filter_var($_POST['sort_order'] ?? '', FILTER_VALIDATE_INT);
            if ($text === '' || mb_strlen($text) > 2000 || !isset($types[$type]) || $order === false || $order < 0 || $order > 100000) throw new RuntimeException('Vyplňte otázku, typ a platné pořadí.');
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE coach_intake_questions SET question_text = ?, question_type = ?, sort_order = ? WHERE id = ? AND coach_id = ? AND is_active = 1');
                $stmt->execute([$text, $type, $order, $id, $coachId]);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT id FROM coach_intake_questions WHERE id = ? AND coach_id = ? AND is_active = 1');
                    $check->execute([$id, $coachId]);
                    if (!$check->fetch()) throw new RuntimeException('Otázka nebyla nalezena.');
                }
            } else {
                $stmt = $pdo->prepare('INSERT INTO coach_intake_questions (coach_id, question_text, question_type, sort_order) VALUES (?, ?, ?, ?)');
                $stmt->execute([$coachId, $text, $type, $order]);
            }
        } elseif ($action === 'remove' && $id > 0) {
            $stmt = $pdo->prepare('UPDATE coach_intake_questions SET is_active = 0 WHERE id = ? AND coach_id = ? AND is_active = 1');
            $stmt->execute([$id, $coachId]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Otázka nebyla nalezena.');
        } else {
            throw new RuntimeException('Neznámá akce.');
        }
        flash('success', 'Nastavení vstupního dotazníku bylo uloženo.');
        redirect(BASE_URL . '/intake_settings.php');
    } catch (Throwable $e) {
        error_log('Intake settings: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}
$questions = intakeQuestions($pdo, $coachId);
renderHeader('Vstupní dotazník', false, true);
?>
<div class="d-flex align-items-center gap-3 mb-4"><a href="<?= BASE_URL ?>/profile.php" class="btn btn-outline-secondary">Zpět</a><h2>Vstupní dotazník</h2></div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<p>Otázky se použijí při vytváření sportovce. Změny neovlivní již uložené dotazníky. Zdravotní údaje patří do samostatného zdravotního dotazníku.</p>
<?php foreach ($questions as $question): ?>
<div class="card shadow-sm mb-3"><div class="card-body">
    <form method="post" class="row g-2 align-items-end"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
        <div class="col-md-6"><label class="form-label">Otázka</label><textarea class="form-control" name="question_text" maxlength="2000" required><?= h($question['question_text']) ?></textarea></div>
        <div class="col-md-3"><label class="form-label">Typ odpovědi</label><select name="question_type" class="form-select"><?php foreach ($types as $key => $label): ?><option value="<?= h($key) ?>" <?= $question['question_type'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label">Pořadí</label><input class="form-control" type="number" min="0" max="100000" name="sort_order" value="<?= (int)$question['sort_order'] ?>" required></div>
        <div class="col-md-2"><button class="btn btn-outline-dark">Uložit</button></div>
    </form>
    <form method="post" class="mt-2" onsubmit="return confirm('Odebrat otázku z budoucích dotazníků? Uložené odpovědi zůstanou zachovány.');"><?= csrfField() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>"><button class="btn btn-sm btn-outline-danger">Odebrat otázku</button></form>
</div></div>
<?php endforeach; ?>
<div class="card border-warning shadow-sm"><div class="card-body"><h5>Nová otázka</h5><form method="post" class="row g-2 align-items-end"><?= csrfField() ?><input type="hidden" name="action" value="save">
<div class="col-md-6"><label class="form-label">Otázka</label><textarea class="form-control" name="question_text" maxlength="2000" required></textarea></div>
<div class="col-md-3"><label class="form-label">Typ odpovědi</label><select name="question_type" class="form-select"><?php foreach ($types as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
<div class="col-md-1"><label class="form-label">Pořadí</label><input class="form-control" type="number" min="0" max="100000" name="sort_order" value="<?= count($questions) * 10 + 10 ?>" required></div>
<div class="col-md-2"><button class="btn btn-warning">Přidat</button></div></form></div></div>
<?php renderFooter(); ?>
