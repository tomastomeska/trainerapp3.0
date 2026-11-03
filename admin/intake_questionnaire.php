<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/intake_questionnaire.php';
require_once __DIR__ . '/header.php';
requireAdminLogin();

$pdo = getDB();
intakeEnsureDefaultQuestionsTable($pdo);
$error = null;
$types = intakeQuestionTypes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Neplatný bezpečnostní token.');
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['question_id'] ?? 0);
        if ($action === 'save') {
            $text = trim((string)($_POST['question_text'] ?? ''));
            $type = (string)($_POST['question_type'] ?? '');
            $order = filter_var($_POST['sort_order'] ?? '', FILTER_VALIDATE_INT);
            if ($text === '' || mb_strlen($text) > 2000 || !isset($types[$type]) || $order === false || $order < 0 || $order > 100000) {
                throw new RuntimeException('Vyplňte otázku, typ a platné pořadí.');
            }
            $options = intakeParseOptions((string)($_POST['options'] ?? ''), $type);
            $optionsJson = $options ? json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE default_intake_questions SET question_text = ?, question_type = ?, options_json = ?, sort_order = ? WHERE id = ? AND is_active = 1');
                $stmt->execute([$text, $type, $optionsJson, $order, $id]);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT id FROM default_intake_questions WHERE id = ? AND is_active = 1');
                    $check->execute([$id]);
                    if (!$check->fetch()) throw new RuntimeException('Otázka nebyla nalezena.');
                }
            } else {
                $stmt = $pdo->prepare('INSERT INTO default_intake_questions (question_text, question_type, options_json, sort_order) VALUES (?, ?, ?, ?)');
                $stmt->execute([$text, $type, $optionsJson, $order]);
            }
        } elseif ($action === 'remove' && $id > 0) {
            $stmt = $pdo->prepare('UPDATE default_intake_questions SET is_active = 0 WHERE id = ? AND is_active = 1');
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Otázka nebyla nalezena.');
        } else {
            throw new RuntimeException('Neznámá akce.');
        }
        flash('success', 'Výchozí vstupní dotazník byl uložen.');
        redirect(BASE_URL . '/admin/intake_questionnaire.php');
    } catch (Throwable $e) {
        error_log('Default intake questionnaire: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$questions = $pdo->query('SELECT id, question_text, question_type, options_json, sort_order FROM default_intake_questions WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
foreach ($questions as &$question) {
    $question['options'] = intakeDecodeOptions($question['options_json'] ?? null);
}
unset($question);
renderAdminHeader('Výchozí vstupní dotazník');
?>
<div class="d-flex align-items-center gap-3 mb-4"><a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary">Zpět</a><h2>Výchozí vstupní dotazník</h2></div>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<p>Tyto otázky se zkopírují trenérovi, když zatím nemá vlastní nastavení. Pozdější úpravy této šablony nemění dotazníky trenérů, kteří už nastavení mají. Otázky lze při vyplňování přeskočit.</p>
<?php foreach ($questions as $question): ?>
<div class="card shadow-sm mb-3"><div class="card-body">
    <form method="post" class="row g-2 align-items-end"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
        <div class="col-md-6"><label class="form-label">Otázka</label><textarea class="form-control" name="question_text" maxlength="2000" required><?= h($question['question_text']) ?></textarea></div>
        <div class="col-md-3"><label class="form-label">Typ odpovědi</label><select name="question_type" class="form-select intake-type"><?php foreach ($types as $key => $label): ?><option value="<?= h($key) ?>" <?= $question['question_type'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label">Pořadí</label><input class="form-control" type="number" min="0" max="100000" name="sort_order" value="<?= (int)$question['sort_order'] ?>" required></div>
        <div class="col-md-10 intake-options" <?= in_array($question['question_type'], ['single_choice', 'multiple_choice'], true) ? '' : 'hidden' ?>><label class="form-label">Možnosti (každou napište na samostatný řádek)</label><textarea class="form-control" name="options" rows="3"><?= h(implode("\n", $question['options'])) ?></textarea></div>
        <div class="col-md-2"><button class="btn btn-outline-dark">Uložit</button></div>
    </form>
    <form method="post" class="mt-2" onsubmit="return confirm('Odebrat otázku z výchozích dotazníků? Již vytvořená nastavení trenérů zůstanou beze změny.');"><?= csrfField() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>"><button class="btn btn-sm btn-outline-danger">Odebrat otázku</button></form>
</div></div>
<?php endforeach; ?>
<div class="card border-warning shadow-sm"><div class="card-body"><h5>Nová výchozí otázka</h5><form method="post" class="row g-2 align-items-end"><?= csrfField() ?><input type="hidden" name="action" value="save">
    <div class="col-md-6"><label class="form-label">Otázka</label><textarea class="form-control" name="question_text" maxlength="2000" required></textarea></div>
    <div class="col-md-3"><label class="form-label">Typ odpovědi</label><select name="question_type" class="form-select intake-type"><?php foreach ($types as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-1"><label class="form-label">Pořadí</label><input class="form-control" type="number" min="0" max="100000" name="sort_order" value="<?= count($questions) * 10 + 10 ?>" required></div>
    <div class="col-md-10 intake-options" hidden><label class="form-label">Možnosti (každou napište na samostatný řádek)</label><textarea class="form-control" name="options" rows="3"></textarea></div>
    <div class="col-md-2"><button class="btn btn-warning">Přidat otázku</button></div>
</form></div></div>
<script>
document.querySelectorAll('form').forEach(form => {
    const type = form.querySelector('.intake-type');
    const options = form.querySelector('.intake-options');
    if (!type || !options) return;
    const update = () => { options.hidden = !['single_choice', 'multiple_choice'].includes(type.value); };
    type.addEventListener('change', update);
    update();
});
</script>
<?php renderAdminFooter(); ?>
