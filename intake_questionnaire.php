<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/intake_questionnaire.php';
$pdo = getDB();
$isCoach = isLoggedIn();
if ($isCoach) {
    requireLogin();
    $coachId = (int)getCurrentCoachId();
    $athleteId = (int)($_GET['athlete_id'] ?? $_POST['athlete_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM athletes WHERE id = ? AND coach_id = ?');
    $stmt->execute([$athleteId, $coachId]);
} else {
    requireAthleteLogin();
    $athleteId = (int)getCurrentAthleteId();
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, coach_id FROM athletes WHERE id = ?');
    $stmt->execute([$athleteId]);
}
$athlete = $stmt->fetch();
if (!$athlete) { http_response_code(404); exit('Sportovec nebyl nalezen.'); }
if (!$isCoach) $coachId = (int)$athlete['coach_id'];
$submission = intakeSubmission($pdo, $coachId, $athleteId);
$questions = $isCoach && !$submission ? intakeQuestions($pdo, $coachId) : [];
$questionSignature = hash('sha256', json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isCoach) {
    try {
        if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Neplatný bezpečnostní token.');
        if ($submission) throw new RuntimeException('Uložený vstupní dotazník nelze upravit.');
        if (!$questions) throw new RuntimeException('Trenér zatím nenastavil žádné otázky.');
        if (!hash_equals($questionSignature, (string)($_POST['question_signature'] ?? ''))) throw new RuntimeException('Otázky se mezitím změnily. Zkontrolujte odpovědi a odešlete dotazník znovu.');
        $answers = [];
        foreach ($questions as $q) {
            $id = (int)$q['id'];
            $yesNo = (string)($_POST['answer'][$id]['yes_no'] ?? '');
            $text = trim((string)($_POST['answer'][$id]['text'] ?? ''));
            if (($q['question_type'] === 'yes_no' || $q['question_type'] === 'combined') && !in_array($yesNo, ['yes', 'no'], true)) throw new RuntimeException('U každé otázky ANO/NE vyberte odpověď.');
            if ($q['question_type'] === 'text' && $text === '') throw new RuntimeException('Vyplňte všechny textové odpovědi.');
            if (mb_strlen($text) > 10000) throw new RuntimeException('Textová odpověď je příliš dlouhá.');
            $answers[] = ['question' => $q['question_text'], 'type' => $q['question_type'], 'yes_no' => $q['question_type'] === 'text' ? null : $yesNo, 'text' => $q['question_type'] === 'yes_no' ? '' : $text];
        }
        $json = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ? FOR UPDATE');
        $lock->execute([$athleteId, $coachId]);
        if (!$lock->fetch()) throw new RuntimeException('Sportovec již nepatří tomuto trenérovi.');
        $stmt = $pdo->prepare('INSERT INTO athlete_intake_submissions (coach_id, athlete_id, answers_json) VALUES (?, ?, ?)');
        $stmt->execute([$coachId, $athleteId, $json]);
        $pdo->commit();
        flash('success', 'Vstupní dotazník byl uložen. Odpovědi již nelze upravovat.');
        redirect(BASE_URL . '/intake_questionnaire.php?athlete_id=' . $athleteId);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Intake submission: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}
if ($isCoach) { require_once __DIR__ . '/includes/header.php'; renderHeader('Vstupní dotazník'); }
else { require_once __DIR__ . '/includes/athlete_header.php'; renderAthleteHeader('Vstupní dotazník'); }
?>
<div class="d-flex gap-3 align-items-center mb-4"><a href="<?= BASE_URL ?>/<?= $isCoach ? 'athlete_detail.php?id=' . $athleteId : 'athlete_dashboard.php' ?>" class="btn btn-outline-secondary">Zpět</a><h2>Vstupní dotazník</h2></div>
<p><?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?></p>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($submission): ?>
<p>Uloženo <?= h(formatDateTime($submission['submitted_at'])) ?>. Odpovědi již nelze upravovat.</p>
<?php foreach ($submission['answers'] as $answer): ?><div class="card mb-2"><div class="card-body"><strong><?= h((string)$answer['question']) ?></strong><div><?= $answer['yes_no'] === 'yes' ? 'ANO' : ($answer['yes_no'] === 'no' ? 'NE' : '') ?></div><?php if ($answer['text'] !== ''): ?><div style="white-space:pre-wrap"><?= h((string)$answer['text']) ?></div><?php endif; ?></div></div><?php endforeach; ?>
<?php elseif (!$isCoach): ?><div class="alert alert-info">Vstupní dotazník zatím nebyl vyplněn.</div>
<?php elseif (!$questions): ?><div class="alert alert-warning">Nejprve <a href="<?= BASE_URL ?>/intake_settings.php">nastavte otázky v profilu trenéra</a>.</div>
<?php else: ?>
<p>Vyplňte otázky společně se sportovcem. Po uložení dotazník nelze měnit.</p>
<style>.intake-question[hidden], #intake-previous[hidden], #intake-next[hidden], #intake-submit[hidden] { display: none !important; }</style>
<form method="post" id="intake-form"><?= csrfField() ?><input type="hidden" name="athlete_id" value="<?= $athleteId ?>"><input type="hidden" name="question_signature" value="<?= h($questionSignature) ?>">
<?php foreach ($questions as $index => $question): $qid = (int)$question['id']; ?>
<div class="card mb-3 intake-question"><div class="card-body"><div class="text-muted small">Otázka <?= $index + 1 ?> / <?= count($questions) ?></div><h5><?= h($question['question_text']) ?></h5>
<?php if ($question['question_type'] !== 'text'): ?><div class="d-flex gap-3 mb-2"><?php foreach (['yes' => 'ANO', 'no' => 'NE'] as $value => $label): ?><label><input type="radio" name="answer[<?= $qid ?>][yes_no]" value="<?= $value ?>" required <?= (($_POST['answer'][$qid]['yes_no'] ?? '') === $value) ? 'checked' : '' ?>> <?= $label ?></label><?php endforeach; ?></div><?php endif; ?>
<?php if ($question['question_type'] !== 'yes_no'): ?><label class="form-label"><?= $question['question_type'] === 'combined' ? 'Upřesnění (nepovinné)' : 'Odpověď' ?></label><textarea name="answer[<?= $qid ?>][text]" class="form-control" maxlength="10000" <?= $question['question_type'] === 'text' ? 'required' : '' ?>><?= h((string)($_POST['answer'][$qid]['text'] ?? '')) ?></textarea><?php endif; ?>
</div></div><?php endforeach; ?>
<div class="d-flex gap-2"><button type="button" class="btn btn-outline-secondary" id="intake-previous" hidden>Předchozí</button><button type="button" class="btn btn-outline-dark" id="intake-next" hidden>Další otázka</button><button id="intake-submit" class="btn btn-warning fw-bold">Uložit dotazník</button></div></form>
<script>
(function () {
    const form = document.getElementById('intake-form');
    const questions = Array.from(form.querySelectorAll('.intake-question'));
    const previous = document.getElementById('intake-previous');
    const next = document.getElementById('intake-next');
    const submit = document.getElementById('intake-submit');
    let current = 0;
    form.noValidate = true;
    function show(index) {
        current = index;
        questions.forEach((question, i) => { question.hidden = i !== index; });
        previous.hidden = index === 0;
        next.hidden = index === questions.length - 1;
        submit.hidden = index !== questions.length - 1;
    }
    function valid(index) {
        const invalid = Array.from(questions[index].querySelectorAll('input, textarea')).find(field => !field.checkValidity());
        if (!invalid) return true;
        show(index);
        invalid.reportValidity();
        return false;
    }
    previous.addEventListener('click', () => show(current - 1));
    next.addEventListener('click', () => { if (valid(current)) show(current + 1); });
    form.addEventListener('submit', event => {
        for (let i = 0; i < questions.length; i++) {
            if (!valid(i)) { event.preventDefault(); return; }
        }
        if (!confirm('Opravdu uložit dotazník? Odpovědi již nepůjde upravit.')) event.preventDefault();
    });
    show(0);
})();
</script>
<?php endif; ?>
<?php if ($isCoach) renderFooter(); else renderAthleteFooter(); ?>
