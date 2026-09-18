<?php

$pdo = getDB();
$surveyUserRole = $surveyUserRole ?? 'athlete';
$surveyUserId = (int)($surveyUserId ?? 0);
$surveyBackUrl = $surveyBackUrl ?? BASE_URL . '/athlete_dashboard.php';
$surveyId = (int)($_GET['id'] ?? 0);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_survey') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $surveyId = (int)($_POST['survey_id'] ?? 0);
        $survey = surveyFetchById($pdo, $surveyId);
        $response = $survey ? surveyFetchResponse($pdo, $surveyId, $surveyUserRole, $surveyUserId) : null;

        if (!$survey || $survey['status'] !== 'published' || ($survey['expires_at'] !== null && strtotime((string)$survey['expires_at']) < time())) {
            $error = 'Toto šetření už není aktivní.';
        } elseif (!surveyAudienceMatches((string)$survey['audience'], $surveyUserRole)) {
            $error = 'K tomuto šetření nemáte přístup.';
        } elseif ($response) {
            $error = 'Toto šetření už máte vyplněné.';
        } else {
            $answers = [];
            foreach ($survey['questions'] as $question) {
                $questionId = (int)$question['id'];
                $rawValue = $_POST['answer'][$questionId] ?? '';
                $values = is_array($rawValue) ? array_values(array_filter(array_map('intval', $rawValue))) : [trim((string)$rawValue)];
                if ($values === [] || $values === ['']) {
                    $error = 'Vyplňte všechny otázky.';
                    break;
                }

                if (in_array($question['question_type'], ['single_choice', 'multiple_choice'], true)) {
                    $optionIds = array_map(static fn(array $option): int => (int)$option['id'], $question['options']);
                    $selectedIds = array_values(array_unique(array_map('intval', $values)));
                    if ($question['question_type'] === 'single_choice' && count($selectedIds) !== 1) {
                        $error = 'Vyberte právě jednu možnost.';
                        break;
                    }
                    if (array_diff($selectedIds, $optionIds) !== []) {
                        $error = 'Vyberte platnou možnost.';
                        break;
                    }
                    foreach ($selectedIds as $selectedId) {
                        $answers[] = [$questionId, $selectedId, null];
                    }
                } else {
                    $answers[] = [$questionId, null, mb_substr((string)$values[0], 0, 10000, 'UTF-8')];
                }
            }

            $answeredQuestionIds = array_unique(array_map(static fn(array $answer): int => (int)$answer[0], $answers));
            if ($error === null && count($answeredQuestionIds) !== count($survey['questions'])) {
                $error = 'Šetření neobsahuje správně vyplněné otázky.';
            }

            if ($error === null) {
                try {
                    $pdo->beginTransaction();
                    $responseStmt = $pdo->prepare('INSERT INTO survey_responses (survey_id, user_type, user_id) VALUES (?, ?, ?)');
                    $responseStmt->execute([$surveyId, $surveyUserRole, $surveyUserId]);
                    $responseId = (int)$pdo->lastInsertId();
                    $answerStmt = $pdo->prepare('INSERT INTO survey_answers (response_id, question_id, option_id, answer_text) VALUES (?, ?, ?, ?)');
                    foreach ($answers as [$questionId, $optionId, $answerText]) {
                        $answerStmt->execute([$responseId, $questionId, $optionId, $answerText]);
                    }
                    $pdo->commit();
                    flash('success', 'Děkujeme, odpověď byla uložena.');
                    redirect($surveyBackUrl . '?survey_done=1');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Survey response save error: ' . $e->getMessage());
                    $error = str_contains(strtolower($e->getMessage()), 'duplicate')
                        ? 'Tato databáze ještě nepodporuje více odpovědí u jedné otázky. Spusťte prosím migraci: ' . BASE_URL . '/scripts/migrate_surveys.php'
                        : 'Odpověď se nepodařilo uložit. Zkuste to prosím znovu.';
                }
            }
        }
    }
}

$surveys = surveyFetchForUser($pdo, $surveyUserRole, $surveyUserId, true);
$selectedSurvey = $surveyId > 0 ? surveyFetchById($pdo, $surveyId) : null;
$selectedResponse = $selectedSurvey ? surveyFetchResponse($pdo, $surveyId, $surveyUserRole, $surveyUserId) : null;
$selectedSurveyClosed = $selectedSurvey && ($selectedSurvey['status'] === 'ended' || ($selectedSurvey['expires_at'] !== null && strtotime((string)$selectedSurvey['expires_at']) < time()));
$selectedSurveyRequiresSubmit = $selectedSurvey && (
    $selectedSurvey['survey_type'] !== 'poll'
    || count(array_filter($selectedSurvey['questions'], static fn(array $question): bool => $question['question_type'] === 'textarea')) > 0
    || count(array_filter($selectedSurvey['questions'], static fn(array $question): bool => $question['question_type'] === 'multiple_choice')) > 0
);
$isAthlete = $surveyUserRole === 'athlete';

if ($isAthlete) {
    require_once __DIR__ . '/athlete_header.php';
    renderAthleteHeader('Ankety a dotazníky');
} else {
    require_once __DIR__ . '/header.php';
    renderHeader('Ankety a dotazníky');
}
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-square-poll-vertical me-2 text-warning"></i>Ankety a dotazníky</h2>
    <a href="<?= h($surveyBackUrl) ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Zpět</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if (!surveySchemaAvailable($pdo)): ?>
<div class="alert alert-warning">Ankety zatím nejsou technicky aktivované. Kontaktujte administrátora.</div>
<?php elseif ($selectedSurvey): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between gap-3 flex-wrap">
            <div>
                <span class="badge bg-<?= $selectedSurvey['survey_type'] === 'poll' ? 'warning text-dark' : 'info' ?> mb-2"><?= $selectedSurvey['survey_type'] === 'poll' ? 'Anketa' : 'Dotazník' ?></span>
                <h3><?= h($selectedSurvey['title']) ?></h3>
                <?php if ($selectedSurvey['description']): ?><p class="text-muted"><?= nl2br(h($selectedSurvey['description'])) ?></p><?php endif; ?>
            </div>
            <?php if ($selectedSurvey['expires_at']): ?><div class="text-muted small">Platnost do <?= h(formatDate($selectedSurvey['expires_at'])) ?></div><?php endif; ?>
        </div>
        <?php if ($selectedResponse): ?>
        <div class="alert alert-success mb-0"><i class="fas fa-lock me-1"></i>Odpověď byla odeslána <?= h(formatDate($selectedResponse['submitted_at'])) ?>. Děkujeme.</div>
        <div class="mt-3">
            <h5>Vaše odpovědi</h5>
            <?php $answersByQuestion = []; foreach (($selectedResponse['answers'] ?? []) as $answer) { $answersByQuestion[(int)$answer['question_id']][] = $answer; } ?>
            <?php foreach ($selectedSurvey['questions'] as $question): $answers = $answersByQuestion[(int)$question['id']] ?? []; ?>
            <div class="border rounded p-3 mb-2 bg-light">
                <div class="small text-muted mb-1"><?= h($question['question_text']) ?></div>
                <?php if (!$answers): ?><div class="text-muted">Bez odpovědi</div>
                <?php elseif (in_array($question['question_type'], ['single_choice', 'multiple_choice'], true)): ?>
                    <?php $selectedOptions = []; foreach ($question['options'] as $option) { foreach ($answers as $answer) { if ((int)$option['id'] === (int)$answer['option_id']) { $selectedOptions[] = (string)$option['option_text']; break; } } } ?>
                    <div class="fw-semibold"><i class="fas fa-check text-success me-1"></i><?= h(implode(', ', $selectedOptions)) ?></div>
                <?php else: ?>
                    <div class="fw-semibold" style="white-space: pre-wrap;"><?= h((string)($answers[0]['answer_text'] ?? '')) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($selectedSurvey['survey_type'] === 'poll'): $totalVotes = surveyCountCompleted($pdo, (int)$selectedSurvey['id']); ?>
        <div class="mt-3"><h5>Průběžné výsledky</h5>
            <?php foreach ($selectedSurvey['questions'] as $question): $stats = surveyQuestionStats($pdo, (int)$question['id']); ?>
            <div class="mb-3"><div class="fw-semibold mb-2"><?= h($question['question_text']) ?></div>
                <?php foreach ($stats as $stat): $percentage = $totalVotes > 0 ? round(((int)$stat['answer_count'] / $totalVotes) * 100) : 0; ?>
                <div class="small d-flex justify-content-between"><span><?= h($stat['option_text']) ?></span><strong><?= $percentage ?> %</strong></div>
                <div class="progress mb-2" style="height:8px"><div class="progress-bar bg-warning" style="width:<?= $percentage ?>%"></div></div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php elseif ($selectedSurveyClosed): ?>
        <div class="alert alert-secondary mb-0"><i class="fas fa-lock me-1"></i>Toto šetření je ukončené a již nelze odeslat odpověď.</div>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="submit_survey">
            <input type="hidden" name="survey_id" value="<?= (int)$selectedSurvey['id'] ?>">
            <?php foreach ($selectedSurvey['questions'] as $index => $question): ?>
            <fieldset class="border rounded p-3 mb-3">
                <legend class="float-none w-auto px-2 fs-6 fw-bold"><?= $index + 1 ?>. <?= h($question['question_text']) ?></legend>
                <?php if (in_array($question['question_type'], ['single_choice', 'multiple_choice'], true)): foreach ($question['options'] as $option): ?>
                <label class="d-block mb-2"><input class="form-check-input me-2" type="<?= $question['question_type'] === 'multiple_choice' ? 'checkbox' : 'radio' ?>"<?= $question['question_type'] === 'single_choice' ? ' required' : '' ?> name="answer[<?= (int)$question['id'] ?>]<?= $question['question_type'] === 'multiple_choice' ? '[]' : '' ?>" value="<?= (int)$option['id'] ?>"<?= $question['question_type'] === 'single_choice' && !$selectedSurveyRequiresSubmit ? ' onchange="this.form.submit()"' : '' ?>> <?= h($option['option_text']) ?></label>
                <?php endforeach; else: ?>
                <textarea class="form-control" required name="answer[<?= (int)$question['id'] ?>]" rows="4"></textarea>
                <?php endif; ?>
            </fieldset>
            <?php endforeach; ?>
            <?php if ($selectedSurveyRequiresSubmit): ?><button class="btn btn-warning fw-semibold" type="submit"><i class="fas fa-paper-plane me-1"></i>Odeslat odpověď</button><?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($surveys as $survey): ?>
    <?php $isEnded = $survey['status'] === 'ended' || ($survey['expires_at'] !== null && strtotime((string)$survey['expires_at']) < time()); ?>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100 <?= !$survey['response_id'] && !$isEnded ? 'survey-pending' : '' ?>">
            <div class="card-body">
                <span class="badge bg-<?= $isEnded ? 'secondary' : ($survey['survey_type'] === 'poll' ? 'warning text-dark' : 'info') ?> mb-2"><?= $isEnded ? 'Archiv' : ($survey['survey_type'] === 'poll' ? 'Anketa' : 'Dotazník') ?></span>
                <h5><?= h($survey['title']) ?></h5>
                <?php if ($survey['description']): ?><p class="text-muted small"><?= h($survey['description']) ?></p><?php endif; ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <span class="small text-muted"><?= $survey['response_id'] ? 'Vyplněno' : ($isEnded ? 'Ukončeno' : 'Čeká na vyplnění') ?></span>
                    <a href="?id=<?= (int)$survey['id'] ?>" class="btn btn-sm <?= $survey['response_id'] || $isEnded ? 'btn-outline-secondary' : 'btn-warning' ?>">Zobrazit</a>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<style>.survey-pending{animation:surveyPulse 1.6s ease-in-out infinite}@keyframes surveyPulse{0%,100%{box-shadow:0 .125rem .25rem rgba(0,0,0,.075)}50%{box-shadow:0 0 0 .28rem rgba(255,193,7,.22)}}</style>
<script>
document.querySelectorAll('form input[name^="answer["]').forEach((input) => {
    input.addEventListener('change', () => {
        const form = input.form;
        if (!form) return;
        form.querySelectorAll('input[type="checkbox"][name^="answer["]').forEach((checkbox) => {
            const groupName = checkbox.name;
            const group = form.querySelectorAll(`input[type="checkbox"][name="${CSS.escape(groupName)}"]`);
            const groupHasSelection = Array.from(group).some((item) => item.checked);
            group.forEach((item) => item.setCustomValidity(groupHasSelection ? '' : 'Vyberte alespoň jednu možnost.'));
        });
    });
});

document.querySelectorAll('form').forEach((form) => {
    if (!form.querySelector('input[name^="answer["]')) return;
    form.addEventListener('submit', (event) => {
        const groups = new Map();
        form.querySelectorAll('input[type="checkbox"][name^="answer["]').forEach((checkbox) => {
            if (!groups.has(checkbox.name)) groups.set(checkbox.name, []);
            groups.get(checkbox.name).push(checkbox);
        });
        for (const checkboxes of groups.values()) {
            const valid = checkboxes.some((checkbox) => checkbox.checked);
            checkboxes.forEach((checkbox) => checkbox.setCustomValidity(valid ? '' : 'Vyberte alespoň jednu možnost.'));
        }
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });
});
</script>
<?php $isAthlete ? renderAthleteFooter() : renderFooter(); ?>