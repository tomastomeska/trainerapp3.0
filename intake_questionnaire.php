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
        $postedAnswers = $_POST['answer'] ?? [];
        if (!is_array($postedAnswers)) throw new RuntimeException('Odpovědi mají neplatný formát.');
        $answers = [];
        foreach ($questions as $q) {
            $id = (int)$q['id'];
            $postedAnswer = $postedAnswers[$id] ?? [];
            if (!is_array($postedAnswer)) throw new RuntimeException('Odpověď má neplatný formát.');
            $yesNo = $postedAnswer['yes_no'] ?? '';
            $rawText = $postedAnswer['text'] ?? '';
            if (!is_string($yesNo) || !is_string($rawText)) throw new RuntimeException('Odpověď má neplatný formát.');
            $text = trim($rawText);
            if (mb_strlen($text) > 10000) throw new RuntimeException('Textová odpověď je příliš dlouhá.');
            if ($yesNo !== '' && !in_array($yesNo, ['yes', 'no'], true)) throw new RuntimeException('Odpověď ANO/NE není platná.');
            $rawChoices = $postedAnswer['choices'] ?? ($q['question_type'] === 'single_choice' ? '' : []);
            if ($q['question_type'] === 'single_choice') {
                if ($rawChoices !== '' && !is_string($rawChoices)) throw new RuntimeException('Vybraná možnost není platná.');
                $choices = $rawChoices === '' ? [] : [$rawChoices];
                if ($choices && !in_array($choices[0], $q['options'], true)) throw new RuntimeException('Vybraná možnost není platná.');
            } elseif ($q['question_type'] === 'multiple_choice') {
                if (!is_array($rawChoices)) throw new RuntimeException('Vybrané možnosti nejsou platné.');
                foreach ($rawChoices as $choice) {
                    if (!is_string($choice)) throw new RuntimeException('Vybraná možnost není platná.');
                }
                $choices = array_values(array_unique($rawChoices));
                if (array_diff($choices, $q['options'])) throw new RuntimeException('Vybraná možnost není platná.');
            } else {
                $choices = [];
            }
            $answers[] = [
                'question' => $q['question_text'],
                'type' => $q['question_type'],
                'yes_no' => in_array($q['question_type'], ['yes_no', 'combined'], true) ? ($yesNo !== '' ? $yesNo : null) : null,
                'choices' => $choices,
                'text' => $q['question_type'] === 'yes_no' ? '' : $text,
            ];
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
<?php foreach ($submission['answers'] as $answer): ?><div class="card mb-2"><div class="card-body"><strong><?= h((string)$answer['question']) ?></strong><?php if (!empty($answer['yes_no'])): ?><div><?= $answer['yes_no'] === 'yes' ? 'ANO' : 'NE' ?></div><?php endif; ?><?php if (!empty($answer['choices'])): ?><div><?= h(implode(', ', array_map('strval', $answer['choices']))) ?></div><?php endif; ?><?php if (($answer['text'] ?? '') !== ''): ?><div style="white-space:pre-wrap"><?= h((string)$answer['text']) ?></div><?php endif; ?><?php if (empty($answer['yes_no']) && empty($answer['choices']) && ($answer['text'] ?? '') === ''): ?><div class="text-muted fst-italic">Nevyplněno</div><?php endif; ?></div></div><?php endforeach; ?>
<?php elseif (!$isCoach): ?><div class="alert alert-info">Vstupní dotazník zatím nebyl vyplněn.</div>
<?php elseif (!$questions): ?><div class="alert alert-warning">Nejprve <a href="<?= BASE_URL ?>/intake_settings.php">nastavte otázky v profilu trenéra</a>.</div>
<?php else: ?>
<p>Vyplňte otázky společně se sportovcem. Každou otázku lze přeskočit; zůstane nevyplněná. Po uložení dotazník nelze měnit.</p>
<style>.intake-question[hidden], #intake-previous[hidden], #intake-next[hidden], #intake-submit[hidden] { display: none !important; }</style>
<form method="post" id="intake-form"><?= csrfField() ?><input type="hidden" name="athlete_id" value="<?= $athleteId ?>"><input type="hidden" name="question_signature" value="<?= h($questionSignature) ?>">
<?php foreach ($questions as $index => $question): $qid = (int)$question['id']; ?>
<div class="card mb-3 intake-question"><div class="card-body"><div class="text-muted small">Otázka <?= $index + 1 ?> / <?= count($questions) ?></div><h5><?= h($question['question_text']) ?></h5>
<?php $formAnswer = is_array($_POST['answer'][$qid] ?? null) ? $_POST['answer'][$qid] : []; ?>
<?php if (in_array($question['question_type'], ['yes_no', 'combined'], true)): ?><div class="d-flex gap-3 mb-2"><?php foreach (['yes' => 'ANO', 'no' => 'NE'] as $value => $label): ?><label><input type="radio" name="answer[<?= $qid ?>][yes_no]" value="<?= $value ?>" <?= (($formAnswer['yes_no'] ?? '') === $value) ? 'checked' : '' ?>> <?= $label ?></label><?php endforeach; ?></div><?php endif; ?>
<?php if ($question['question_type'] === 'single_choice'): ?><div class="mb-2"><?php foreach ($question['options'] as $option): ?><label class="d-block"><input type="radio" name="answer[<?= $qid ?>][choices]" value="<?= h($option) ?>" <?= (($formAnswer['choices'] ?? '') === $option) ? 'checked' : '' ?>> <?= h($option) ?></label><?php endforeach; ?></div><label class="form-label">Upřesnění (nepovinné)</label><textarea name="answer[<?= $qid ?>][text]" class="form-control" maxlength="10000"><?= h(is_string($formAnswer['text'] ?? null) ? $formAnswer['text'] : '') ?></textarea><?php endif; ?>
<?php if ($question['question_type'] === 'multiple_choice'): ?><div class="mb-2"><?php foreach ($question['options'] as $option): ?><label class="d-block"><input type="checkbox" name="answer[<?= $qid ?>][choices][]" value="<?= h($option) ?>" <?= in_array($option, is_array($formAnswer['choices'] ?? null) ? $formAnswer['choices'] : [], true) ? 'checked' : '' ?>> <?= h($option) ?></label><?php endforeach; ?></div><label class="form-label">Upřesnění (nepovinné)</label><textarea name="answer[<?= $qid ?>][text]" class="form-control" maxlength="10000"><?= h(is_string($formAnswer['text'] ?? null) ? $formAnswer['text'] : '') ?></textarea><?php endif; ?>
<?php if ($question['question_type'] === 'combined'): ?><label class="form-label">Upřesnění (nepovinné)</label><textarea name="answer[<?= $qid ?>][text]" class="form-control" maxlength="10000"><?= h(is_string($formAnswer['text'] ?? null) ? $formAnswer['text'] : '') ?></textarea><?php elseif ($question['question_type'] === 'text'): ?><label class="form-label">Odpověď (nepovinná)</label><textarea name="answer[<?= $qid ?>][text]" class="form-control" maxlength="10000"><?= h(is_string($formAnswer['text'] ?? null) ? $formAnswer['text'] : '') ?></textarea><?php endif; ?>
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
