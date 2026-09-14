<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';
require_once __DIR__ . '/includes/health_questionnaire.php';

requireAthleteLogin();

$pdo = getDB();
$athleteId = (int)getCurrentAthleteId();
healthQuestionnaireEnsureSchema($pdo);

$athleteStmt = $pdo->prepare(
    'SELECT a.id, a.coach_id, a.first_name, a.last_name, a.gender, c.name AS coach_name, c.username AS coach_username
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id
     WHERE a.id = ?
     LIMIT 1'
);
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();

if (!$athlete) {
    flash('danger', 'Sportovec nenalezen.');
    redirect(BASE_URL . '/login.php');
}

if (!healthQuestionnaireAthleteAccessEnabled()) {
    flash('warning', 'Zdravotní dotazník je v administraci dočasně deaktivovaný.');
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$coachName = trim((string)($athlete['coach_name'] ?: $athlete['coach_username']));
$athleteFullName = trim((string)($athlete['first_name'] . ' ' . $athlete['last_name']));

$latestWeightKg = null;
try {
    $latestWeightStmt = $pdo->prepare(
        'SELECT weight_kg
         FROM athlete_weight_logs
         WHERE athlete_id = ?
         ORDER BY measured_at DESC, id DESC
         LIMIT 1'
    );
    $latestWeightStmt->execute([$athleteId]);
    $latestWeightValue = $latestWeightStmt->fetchColumn();
    if ($latestWeightValue !== false && $latestWeightValue !== null) {
        $latestWeightKg = (float)$latestWeightValue;
    }
} catch (Throwable $e) {
    $latestWeightKg = null;
}

$questions = healthQuestionnaireFetchQuestionsForAthlete($pdo, $athleteId, true);
$steps = healthQuestionnaireGroupBySteps($questions);
$latestSubmission = healthQuestionnaireFetchLatestSubmission($pdo, $athleteId);
$status = healthQuestionnaireFetchStatus($pdo, $athleteId);
$isEditMode = ((int)($_GET['edit'] ?? 0)) === 1;

$formAnswers = is_array($latestSubmission['answers'] ?? null) ? $latestSubmission['answers'] : [];
if (!isset($formAnswers['fill_date']) || trim((string)$formAnswers['fill_date']) === '') {
    $formAnswers['fill_date'] = date('Y-m-d');
}
if ((!isset($formAnswers['weight_kg']) || trim((string)$formAnswers['weight_kg']) === '') && $latestWeightKg !== null) {
    $formAnswers['weight_kg'] = number_format($latestWeightKg, 1, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_health_questionnaire.php');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_health_questionnaire') {
        $questionnaireErrorRedirect = $latestSubmission !== null
            ? (BASE_URL . '/athlete_health_questionnaire.php?edit=1#health-form')
            : (BASE_URL . '/athlete_health_questionnaire.php#health-form');

        $submittedAnswers = healthQuestionnaireCollectAnswersFromPost($questions, $_POST);
        $validationErrors = healthQuestionnaireValidateAnswers($questions, $submittedAnswers);

        if (!empty($validationErrors)) {
            flash('danger', implode('<br>', array_map('h', $validationErrors)), true);
            $_SESSION['health_questionnaire_form_answers'] = $submittedAnswers;
            redirect($questionnaireErrorRedirect);
        }

        $evaluation = healthQuestionnaireEvaluateAlerts($questions, $submittedAnswers);
        $saved = healthQuestionnaireSaveSubmission(
            $pdo,
            $athleteId,
            (int)$athlete['coach_id'],
            $submittedAnswers,
            $evaluation
        );

        if ($saved) {
            $alertCount = (int)($evaluation['alert_count'] ?? 0);
            $messageSubject = 'Zdravotní dotazník sportovce: ' . $athleteFullName;
            $messageBody = $alertCount > 0
                ? ('Sportovec ' . $athleteFullName . ' vyplnil zdravotní dotazník. Počet upozornění: ' . $alertCount . '.')
                : ('Sportovec ' . $athleteFullName . ' vyplnil zdravotní dotazník. Dotazník je bez rizikových omezení.');
            createCoachSystemMessage((int)$athlete['coach_id'], $messageSubject, $messageBody, true);

            flash('success', 'Zdravotní dotazník byl uložen. Trenér byl informován.');
            redirect(BASE_URL . '/athlete_health_questionnaire.php');
        }

        flash('danger', 'Dotazník se nepodařilo uložit, zkuste to prosím znovu.');
        $_SESSION['health_questionnaire_form_answers'] = $submittedAnswers;
        redirect($questionnaireErrorRedirect);
    }

    if ($action === 'submit_health_update' || $action === 'update_health_update') {
        $isUpdateEdit = $action === 'update_health_update';
        $updateId = (int)($_POST['update_id'] ?? 0);
        $changeCategory = trim((string)($_POST['change_category'] ?? ''));
        $changeDetails = trim((string)($_POST['change_details'] ?? ''));
        $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
        $severity = trim((string)($_POST['severity'] ?? 'warning'));

        $allowedCategories = [
            'omezeni',
            'zraneni',
            'leky',
            'alergie',
            'stav',
            'jine',
        ];

        if ($changeCategory === '' || !in_array($changeCategory, $allowedCategories, true)) {
            flash('danger', 'Vyberte typ změny zdravotního stavu.');
            redirect(BASE_URL . '/athlete_health_questionnaire.php#health-updates');
        }

        if ($changeDetails === '') {
            flash('danger', 'Popište prosím změnu zdravotního stavu.');
            redirect(BASE_URL . '/athlete_health_questionnaire.php#health-updates');
        }

        if ($effectiveDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate) !== 1) {
            flash('danger', 'Datum účinnosti změny není ve správném formátu.');
            redirect(BASE_URL . '/athlete_health_questionnaire.php#health-updates');
        }

        if ($isUpdateEdit && $updateId <= 0) {
            flash('danger', 'Vybrané hlášení pro úpravu nebylo nalezeno.');
            redirect(BASE_URL . '/athlete_health_questionnaire.php#health-updates');
        }

        if ($isUpdateEdit) {
            $saved = healthQuestionnaireUpdateAthleteUpdate(
                $pdo,
                $updateId,
                $athleteId,
                (int)$athlete['coach_id'],
                $changeCategory,
                mb_substr($changeDetails, 0, 3000, 'UTF-8'),
                $effectiveDate !== '' ? $effectiveDate : null,
                $severity
            );
        } else {
            $saved = healthQuestionnaireCreateUpdate(
                $pdo,
                $athleteId,
                (int)$athlete['coach_id'],
                $changeCategory,
                mb_substr($changeDetails, 0, 3000, 'UTF-8'),
                $effectiveDate !== '' ? $effectiveDate : null,
                $severity
            );
        }

        if ($saved) {
            $categoryLabels = [
                'omezeni' => 'omezení',
                'zraneni' => 'zranění',
                'leky' => 'léky',
                'alergie' => 'alergie',
                'stav' => 'zdravotní stav',
                'jine' => 'jiné',
            ];
            $label = $categoryLabels[$changeCategory] ?? $changeCategory;
            $subject = $isUpdateEdit
                ? ('Aktualizace zdravotního hlášení: ' . $athleteFullName)
                : ('Změna zdravotního stavu: ' . $athleteFullName);
            $body = $isUpdateEdit
                ? ('Sportovec upravil hlášení (' . $label . '). Detail: ' . mb_substr($changeDetails, 0, 400, 'UTF-8'))
                : ('Sportovec nahlásil změnu (' . $label . '). Detail: ' . mb_substr($changeDetails, 0, 400, 'UTF-8'));
            createCoachSystemMessage((int)$athlete['coach_id'], $subject, $body, true);

            flash('success', $isUpdateEdit
                ? 'Změna zdravotního stavu byla upravena a trenér byl znovu informován.'
                : 'Změna zdravotního stavu byla uložena a trenér byl informován.');
            redirect(BASE_URL . '/athlete_health_questionnaire.php#health-updates');
        }

        flash('danger', $isUpdateEdit ? 'Změnu se nepodařilo upravit.' : 'Změnu se nepodařilo uložit.');
        redirect(BASE_URL . '/athlete_health_questionnaire.php#health-updates');
    }

    if ($action === 'delete_health_update') {
        $updateId = (int)($_POST['update_id'] ?? 0);
        if ($updateId <= 0) {
            flash('danger', 'Vybraná změna nebyla nalezena.');
            redirect(BASE_URL . '/athlete_health_questionnaire.php#health-updates');
        }

        $deleted = healthQuestionnaireDeleteAthleteUpdate(
            $pdo,
            $updateId,
            $athleteId,
            (int)$athlete['coach_id']
        );

        if ($deleted) {
            createCoachSystemMessage(
                (int)$athlete['coach_id'],
                'Smazání zdravotního hlášení: ' . $athleteFullName,
                'Sportovec odstranil jedno dříve odeslané hlášení změny zdravotního stavu.',
                true
            );
            flash('success', 'Hlášení bylo smazáno. Trenér byl informován.');
            redirect(BASE_URL . '/athlete_health_questionnaire.php#health-updates');
        }

        flash('danger', 'Hlášení se nepodařilo smazat.');
        redirect(BASE_URL . '/athlete_health_questionnaire.php#health-updates');
    }
}

if (isset($_SESSION['health_questionnaire_form_answers']) && is_array($_SESSION['health_questionnaire_form_answers'])) {
    $formAnswers = $_SESSION['health_questionnaire_form_answers'];
    unset($_SESSION['health_questionnaire_form_answers']);
}

$totalSteps = max(1, count($steps));
$hasExistingQuestionnaire = $latestSubmission !== null;
$athleteHealthUpdates = healthQuestionnaireFetchUpdatesForAthlete($pdo, $athleteId, (int)$athlete['coach_id'], 20);
$editUpdateId = (int)($_GET['edit_update'] ?? 0);
$editingHealthUpdate = null;
if ($editUpdateId > 0) {
    foreach ($athleteHealthUpdates as $updateRow) {
        if ((int)($updateRow['id'] ?? 0) === $editUpdateId) {
            $editingHealthUpdate = $updateRow;
            break;
        }
    }
}

$updateFormCategory = $editingHealthUpdate['change_category'] ?? '';
$updateFormDetails = $editingHealthUpdate['change_details'] ?? '';
$updateFormEffectiveDate = $editingHealthUpdate['effective_date'] ?? '';
$updateFormSeverity = $editingHealthUpdate['severity'] ?? 'warning';
$isEditingHealthUpdate = is_array($editingHealthUpdate);

renderAthleteHeader('Zdravotní dotazník', false, true);
?>

<style>
.health-q-wrapper {
    max-width: 980px;
    margin: 0 auto;
}
.health-q-progress {
    height: 10px;
    background: #e9ecef;
    border-radius: 999px;
    overflow: hidden;
}
.health-q-progress > span {
    display: block;
    height: 100%;
    width: 12.5%;
    background: linear-gradient(90deg, #0ea5e9 0%, #22c55e 100%);
    transition: width .2s ease;
}
.health-step {
    display: none;
}
.health-step.is-active {
    display: block;
}
.health-step-title {
    font-weight: 800;
    margin-bottom: .25rem;
}
.health-step-kicker {
    font-size: .8rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 700;
}
.health-option-grid {
    display: grid;
    gap: .5rem;
}
.health-status-ok {
    border-left: 4px solid #16a34a;
}
.health-status-warning {
    border-left: 4px solid #d97706;
}
.health-status-missing {
    border-left: 4px solid #dc2626;
}
@media (max-width: 767.98px) {
    .health-q-actions {
        display: grid;
        gap: .5rem;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-notes-medical me-2 text-warning"></i>Zdravotní dotazník</h2>
</div>

<div class="health-q-wrapper">
    <?php
    $statusClass = 'health-status-ok';
    if (($status['state'] ?? 'ok') === 'missing') {
        $statusClass = 'health-status-missing';
    } elseif (($status['state'] ?? 'ok') === 'warning') {
        $statusClass = 'health-status-warning';
    }

    if (($status['state'] ?? 'ok') === 'missing') {
        $athleteStatusText = 'Zdravotní dotazník zatím není vyplněn.';
    } elseif (!empty($status['needs_refresh'])) {
        $athleteStatusText = 'Dotazník byl doplněn o nové otázky. Pro jistotu ho prosím aktualizujte.';
    } else {
        $athleteStatusText = 'Zdravotní dotazník máte uložený. Případné omezení řeší trenér při plánování tréninku.';
    }
    ?>
    <div class="alert alert-light shadow-sm <?= $statusClass ?>">
        <div class="fw-semibold mb-1"><?= h((string)$athleteStatusText) ?></div>
        <?php if (!empty($status['filled_at'])): ?>
        <div class="small text-muted">Poslední vyplnění: <?= h(formatDateTime((string)$status['filled_at'])) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($hasExistingQuestionnaire && !$isEditMode): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <?php if (!empty($status['needs_refresh'])): ?>
                <div class="fw-semibold text-warning">Dotazník je potřeba aktualizovat</div>
                <div class="small text-muted">Administrátor upravil otázky, doplňte prosím nové údaje.</div>
                <?php else: ?>
                <div class="fw-semibold">Dotazník je vyplněný</div>
                <div class="small text-muted">Pokud se něco změnilo, můžete ho aktualizovat.</div>
                <?php endif; ?>
            </div>
            <a href="<?= BASE_URL ?>/athlete_health_questionnaire.php?edit=1#health-form" class="btn <?= !empty($status['needs_refresh']) ? 'btn-warning' : 'btn-primary' ?>">
                <i class="fas fa-pen-to-square me-1"></i><?= !empty($status['needs_refresh']) ? 'Doplnit nové otázky' : 'Aktualizovat dotazník' ?>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$hasExistingQuestionnaire || $isEditMode): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2" id="health-form">
            <span class="fw-semibold"><?= $hasExistingQuestionnaire ? 'Aktualizace zdravotního dotazníku' : 'Vyplnění zdravotního dotazníku' ?></span>
            <?php if ($hasExistingQuestionnaire && $isEditMode): ?>
            <a href="<?= BASE_URL ?>/athlete_health_questionnaire.php" class="btn btn-sm btn-outline-secondary">Zrušit úpravu</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <div>
                    <div class="health-step-kicker" id="healthStepLabel">Krok 1 z <?= (int)$totalSteps ?></div>
                    <div class="health-step-title" id="healthStepTitle"><?= h((string)($steps[0]['section_title'] ?? 'Dotazník')) ?></div>
                </div>
                <div class="small text-muted">Vyplnění zabere cca 2 minuty</div>
            </div>
            <div class="health-q-progress mb-4"><span id="healthProgressBar"></span></div>

            <form method="post" id="healthQuestionnaireForm" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_health_questionnaire">

                <?php foreach ($steps as $stepIndex => $step): ?>
                <section class="health-step <?= $stepIndex === 0 ? 'is-active' : '' ?>" data-step-index="<?= (int)$stepIndex ?>" data-step-title="<?= h((string)$step['section_title']) ?>">
                    <div class="row g-3">
                        <?php foreach ($step['questions'] as $question): ?>
                            <?php
                            $questionKey = (string)$question['question_key'];
                            $inputType = (string)$question['input_type'];
                            $required = (int)$question['is_required'] === 1;
                            $showWhenKey = trim((string)($question['show_when_question_key'] ?? ''));
                            $showWhenValue = trim((string)($question['show_when_value'] ?? ''));
                            $fieldValue = $formAnswers[$questionKey] ?? ($inputType === 'multi' ? [] : '');
                            $hasOtherOption = $inputType === 'multi' && healthQuestionnaireQuestionHasOtherOption($question);
                            $otherReasonKey = $questionKey . '_other_reason';
                            $otherReasonValue = trim((string)($formAnswers[$otherReasonKey] ?? ''));
                            $otherSelected = false;
                            if ($hasOtherOption && is_array($fieldValue)) {
                                foreach ($fieldValue as $selectedValue) {
                                    if (healthQuestionnaireIsOtherOptionValue((string)$selectedValue)) {
                                        $otherSelected = true;
                                        break;
                                    }
                                }
                            }
                            ?>
                            <div class="col-12" data-question-wrap
                                 data-show-when-key="<?= h($showWhenKey) ?>"
                                 data-show-when-value="<?= h($showWhenValue) ?>">
                                <label class="form-label fw-semibold" for="q_<?= h($questionKey) ?>">
                                    <?= h((string)$question['question_label']) ?>
                                    <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                                </label>

                                <?php if ($inputType === 'yes_no'): ?>
                                    <select class="form-select" id="q_<?= h($questionKey) ?>" name="<?= h($questionKey) ?>" <?= $required ? 'required' : '' ?>>
                                        <option value="">Vyberte</option>
                                        <option value="ano" <?= (string)$fieldValue === 'ano' ? 'selected' : '' ?>>Ano</option>
                                        <option value="ne" <?= (string)$fieldValue === 'ne' ? 'selected' : '' ?>>Ne</option>
                                    </select>
                                <?php elseif ($inputType === 'single'): ?>
                                    <?php if (empty((array)($question['options'] ?? []))): ?>
                                        <textarea class="form-control" rows="2" id="q_<?= h($questionKey) ?>" name="<?= h($questionKey) ?>" placeholder="Doplňte odpověď" <?= $required ? 'required' : '' ?>><?= h((string)$fieldValue) ?></textarea>
                                        <div class="form-text text-warning">U této otázky chybí nabídka možností, odpověď prosím napište ručně.</div>
                                    <?php else: ?>
                                        <select class="form-select" id="q_<?= h($questionKey) ?>" name="<?= h($questionKey) ?>" <?= $required ? 'required' : '' ?>>
                                            <option value="">Vyberte</option>
                                            <?php foreach ((array)($question['options'] ?? []) as $optionKey => $optionLabel): ?>
                                                <?php
                                                $value = is_string($optionKey) ? $optionKey : (string)$optionLabel;
                                                $label = is_string($optionLabel) ? $optionLabel : (string)$optionKey;
                                                ?>
                                                <option value="<?= h($value) ?>" <?= (string)$fieldValue === (string)$value ? 'selected' : '' ?>><?= h($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                <?php elseif ($inputType === 'multi'): ?>
                                    <?php if (empty((array)($question['options'] ?? []))): ?>
                                        <textarea class="form-control" rows="2" id="q_<?= h($questionKey) ?>" name="<?= h($questionKey) ?>" placeholder="Doplňte odpověď (oddělte více položek čárkou)" <?= $required ? 'required' : '' ?>><?= h(is_array($fieldValue) ? implode(', ', $fieldValue) : (string)$fieldValue) ?></textarea>
                                        <div class="form-text text-warning">U této otázky chybí nabídka možností, odpověď prosím napište ručně.</div>
                                    <?php else: ?>
                                        <div class="health-option-grid">
                                            <?php foreach ((array)($question['options'] ?? []) as $optionKey => $optionLabel): ?>
                                                <?php
                                                $value = is_string($optionKey) ? $optionKey : (string)$optionLabel;
                                                $label = is_string($optionLabel) ? $optionLabel : (string)$optionKey;
                                                $selected = is_array($fieldValue) && in_array((string)$value, $fieldValue, true);
                                                ?>
                                                <label class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="<?= h($questionKey) ?>[]" value="<?= h($value) ?>" <?= $selected ? 'checked' : '' ?>>
                                                    <span class="form-check-label"><?= h($label) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($hasOtherOption): ?>
                                            <div class="mt-2 <?= $otherSelected ? '' : 'd-none' ?>" data-other-reason-wrap data-other-reason-for="<?= h($questionKey) ?>">
                                                <label class="form-label fw-semibold" for="q_<?= h($otherReasonKey) ?>">
                                                    Pokud Jiné/Jiný, uveďte důvod <span class="text-danger">*</span>
                                                </label>
                                                <input class="form-control" type="text" id="q_<?= h($otherReasonKey) ?>" name="<?= h($otherReasonKey) ?>" value="<?= h($otherReasonValue) ?>" placeholder="Doplňte důvod" <?= $otherSelected ? 'required' : '' ?>>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php elseif ($inputType === 'textarea'): ?>
                                    <textarea class="form-control" rows="3" id="q_<?= h($questionKey) ?>" name="<?= h($questionKey) ?>" placeholder="<?= h((string)($question['placeholder'] ?? '')) ?>" <?= $required ? 'required' : '' ?>><?= h((string)$fieldValue) ?></textarea>
                                <?php elseif ($inputType === 'number'): ?>
                                    <input class="form-control" type="number" id="q_<?= h($questionKey) ?>" name="<?= h($questionKey) ?>" value="<?= h((string)$fieldValue) ?>" placeholder="<?= h((string)($question['placeholder'] ?? '')) ?>" step="0.1" <?= $required ? 'required' : '' ?>>
                                <?php elseif ($inputType === 'date'): ?>
                                    <input class="form-control" type="date" id="q_<?= h($questionKey) ?>" name="<?= h($questionKey) ?>" value="<?= h((string)$fieldValue) ?>" <?= $required ? 'required' : '' ?>>
                                <?php elseif ($inputType === 'consent'): ?>
                                    <input type="hidden" name="<?= h($questionKey) ?>" value="ne">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" id="q_<?= h($questionKey) ?>" name="<?= h($questionKey) ?>" value="ano" <?= (string)$fieldValue === 'ano' ? 'checked' : '' ?> <?= $required ? 'required' : '' ?>>
                                        <span class="form-check-label">Souhlasím</span>
                                    </label>
                                <?php else: ?>
                                    <input class="form-control" type="text" id="q_<?= h($questionKey) ?>" name="<?= h($questionKey) ?>" value="<?= h((string)$fieldValue) ?>" placeholder="<?= h((string)($question['placeholder'] ?? '')) ?>" <?= $required ? 'required' : '' ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>

                <div class="d-flex justify-content-between mt-4 health-q-actions">
                    <button type="button" class="btn btn-outline-secondary" id="healthPrevBtn" disabled>
                        <i class="fas fa-arrow-left me-1"></i>Zpět
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary" id="healthNextBtn">
                            Další krok <i class="fas fa-arrow-right ms-1"></i>
                        </button>
                        <button type="submit" class="btn btn-success d-none" id="healthSubmitBtn">
                            <i class="fas fa-save me-1"></i>Uložit dotazník
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm" id="health-updates">
        <div class="card-header bg-dark text-white fw-semibold">
            <i class="fas fa-bell me-2"></i>Nahlásit změnu zdravotního stavu trenérovi
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Kdykoliv dojde ke změně (nové omezení, zranění, léky, alergie), napište ji sem. Trenér dostane okamžitě upozornění.
            </p>
            <?php if ($isEditingHealthUpdate): ?>
            <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    Upravujete hlášení z <strong><?= h(formatDateTime((string)($editingHealthUpdate['created_at'] ?? ''))) ?></strong>.
                </div>
                <a href="<?= BASE_URL ?>/athlete_health_questionnaire.php#health-updates" class="btn btn-sm btn-outline-dark">Zrušit úpravu</a>
            </div>
            <?php endif; ?>

            <form method="post" class="row g-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="<?= $isEditingHealthUpdate ? 'update_health_update' : 'submit_health_update' ?>">
                <?php if ($isEditingHealthUpdate): ?>
                <input type="hidden" name="update_id" value="<?= (int)($editingHealthUpdate['id'] ?? 0) ?>">
                <?php endif; ?>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="change_category">Typ změny</label>
                    <select class="form-select" id="change_category" name="change_category" required>
                        <option value="">Vyberte</option>
                        <option value="omezeni" <?= $updateFormCategory === 'omezeni' ? 'selected' : '' ?>>Omezení</option>
                        <option value="zraneni" <?= $updateFormCategory === 'zraneni' ? 'selected' : '' ?>>Zranění</option>
                        <option value="leky" <?= $updateFormCategory === 'leky' ? 'selected' : '' ?>>Léky</option>
                        <option value="alergie" <?= $updateFormCategory === 'alergie' ? 'selected' : '' ?>>Alergie</option>
                        <option value="stav" <?= $updateFormCategory === 'stav' ? 'selected' : '' ?>>Změna stavu</option>
                        <option value="jine" <?= $updateFormCategory === 'jine' ? 'selected' : '' ?>>Jiné</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="effective_date">Platí od (volitelné)</label>
                    <input type="date" class="form-control" id="effective_date" name="effective_date" value="<?= h((string)$updateFormEffectiveDate) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="severity">Důležitost</label>
                    <select class="form-select" id="severity" name="severity">
                        <option value="warning" <?= $updateFormSeverity === 'warning' ? 'selected' : '' ?>>Střední</option>
                        <option value="critical" <?= $updateFormSeverity === 'critical' ? 'selected' : '' ?>>Vysoká</option>
                        <option value="info" <?= $updateFormSeverity === 'info' ? 'selected' : '' ?>>Informace</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="change_details">Popis změny</label>
                    <textarea class="form-control" id="change_details" name="change_details" rows="4" maxlength="3000" required placeholder="Např. Nově bolest v pravém koleni při běhu, od 2. 8. 2026."><?= h((string)$updateFormDetails) ?></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-warning fw-semibold">
                        <i class="fas <?= $isEditingHealthUpdate ? 'fa-floppy-disk' : 'fa-paper-plane' ?> me-1"></i><?= $isEditingHealthUpdate ? 'Uložit úpravu a informovat trenéra' : 'Odeslat změnu trenérovi' ?>
                    </button>
                </div>
            </form>

            <hr class="my-4">

            <h3 class="h6 fw-bold mb-3">Moje odeslané změny</h3>
            <?php
            $categoryLabels = [
                'omezeni' => 'Omezení',
                'zraneni' => 'Zranění',
                'leky' => 'Léky',
                'alergie' => 'Alergie',
                'stav' => 'Změna stavu',
                'jine' => 'Jiné',
            ];
            $severityBadges = [
                'info' => 'bg-info text-dark',
                'warning' => 'bg-warning text-dark',
                'critical' => 'bg-danger',
            ];
            $severityLabels = [
                'info' => 'Informace',
                'warning' => 'Střední',
                'critical' => 'Vysoká',
            ];
            ?>
            <?php if (empty($athleteHealthUpdates)): ?>
            <div class="text-muted small">Zatím nemáte žádná odeslaná hlášení.</div>
            <?php else: ?>
                <?php foreach ($athleteHealthUpdates as $updateRow): ?>
                <?php
                $rowCategory = (string)($updateRow['change_category'] ?? '');
                $rowSeverity = (string)($updateRow['severity'] ?? 'warning');
                $seen = !empty($updateRow['coach_seen_at']);
                ?>
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <div>
                            <div class="fw-semibold mb-1"><?= h($categoryLabels[$rowCategory] ?? $rowCategory) ?></div>
                            <div class="small text-muted">Odesláno: <?= h(formatDateTime((string)($updateRow['created_at'] ?? ''))) ?></div>
                            <?php if (!empty($updateRow['effective_date'])): ?>
                            <div class="small text-muted">Platí od: <?= h(formatDate((string)$updateRow['effective_date'])) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge <?= h($severityBadges[$rowSeverity] ?? 'bg-secondary') ?>"><?= h($severityLabels[$rowSeverity] ?? $rowSeverity) ?></span>
                            <?php if ($seen): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Přečteno trenérem</span>
                            <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Čeká na přečtení</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-2"><?= nl2br(h((string)($updateRow['change_details'] ?? ''))) ?></div>

                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <a href="<?= BASE_URL ?>/athlete_health_questionnaire.php?edit_update=<?= (int)($updateRow['id'] ?? 0) ?>#health-updates" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-pen me-1"></i>Upravit
                        </a>
                        <form method="post" onsubmit="return confirm('Opravdu chcete toto hlášení smazat? Trenér bude informován.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_health_update">
                            <input type="hidden" name="update_id" value="<?= (int)($updateRow['id'] ?? 0) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash me-1"></i>Smazat
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('healthQuestionnaireForm');
    if (!form) {
        return;
    }

    const steps = Array.from(form.querySelectorAll('.health-step'));
    const prevBtn = document.getElementById('healthPrevBtn');
    const nextBtn = document.getElementById('healthNextBtn');
    const submitBtn = document.getElementById('healthSubmitBtn');
    const stepLabel = document.getElementById('healthStepLabel');
    const stepTitle = document.getElementById('healthStepTitle');
    const progressBar = document.getElementById('healthProgressBar');

    let currentStep = 0;

    function isOtherOptionValue(value) {
        const normalized = String(value || '').trim().toLowerCase();
        return ['jiné', 'jine', 'jiný', 'jiny', 'other'].includes(normalized);
    }

    function updateOtherReasonVisibility() {
        const otherReasonWraps = form.querySelectorAll('[data-other-reason-wrap]');
        otherReasonWraps.forEach((wrap) => {
            const questionKey = wrap.getAttribute('data-other-reason-for') || '';
            const reasonInput = wrap.querySelector('input, textarea');
            if (!questionKey || !reasonInput) {
                return;
            }

            const selectedValues = Array.from(form.querySelectorAll('[name="' + questionKey + '[]"]:checked')).map((item) => item.value);
            const otherSelected = selectedValues.some((value) => isOtherOptionValue(value));

            wrap.classList.toggle('d-none', !otherSelected);
            reasonInput.required = otherSelected;
            if (!otherSelected) {
                reasonInput.value = '';
            }
        });
    }

    function updateConditionalVisibility() {
        const wrappers = form.querySelectorAll('[data-question-wrap]');
        wrappers.forEach((wrapper) => {
            const dependsOn = wrapper.getAttribute('data-show-when-key') || '';
            const expected = wrapper.getAttribute('data-show-when-value') || '';
            if (!dependsOn) {
                wrapper.classList.remove('d-none');
                return;
            }

            const controls = form.querySelectorAll('[name="' + dependsOn + '"]');
            const checkedControls = form.querySelectorAll('[name="' + dependsOn + '[]"]:checked');
            let isVisible = false;

            if (checkedControls.length > 0) {
                checkedControls.forEach((checkbox) => {
                    if (checkbox.value === expected) {
                        isVisible = true;
                    }
                });
            } else {
                controls.forEach((control) => {
                    if (control.type === 'checkbox' && control.checked && control.value === expected) {
                        isVisible = true;
                    }
                    if (control.tagName === 'SELECT' && control.value === expected) {
                        isVisible = true;
                    }
                    if (control.type !== 'checkbox' && control.tagName !== 'SELECT' && control.value === expected) {
                        isVisible = true;
                    }
                });
            }

            wrapper.classList.toggle('d-none', !isVisible);
            if (!isVisible) {
                const inputElements = wrapper.querySelectorAll('input, select, textarea');
                inputElements.forEach((element) => {
                    if (element.type === 'checkbox' || element.type === 'radio') {
                        element.checked = false;
                    } else if (element.type !== 'hidden') {
                        element.value = '';
                    }
                });
            }
        });

        updateOtherReasonVisibility();
    }

    function updateWizard() {
        steps.forEach((step, index) => {
            step.classList.toggle('is-active', index === currentStep);
        });

        const current = steps[currentStep];
        const total = steps.length;
        const percent = ((currentStep + 1) / total) * 100;

        stepLabel.textContent = 'Krok ' + (currentStep + 1) + ' z ' + total;
        stepTitle.textContent = current.getAttribute('data-step-title') || 'Dotazník';
        progressBar.style.width = percent + '%';

        prevBtn.disabled = currentStep === 0;
        const isLastStep = currentStep === total - 1;
        nextBtn.classList.toggle('d-none', isLastStep);
        submitBtn.classList.toggle('d-none', !isLastStep);

        updateConditionalVisibility();
    }

    function validateCurrentStep() {
        const current = steps[currentStep];
        const requiredFields = Array.from(current.querySelectorAll('[required]')).filter((field) => {
            const wrapper = field.closest('[data-question-wrap]');
            const otherReasonWrap = field.closest('[data-other-reason-wrap]');
            if (otherReasonWrap && otherReasonWrap.classList.contains('d-none')) {
                return false;
            }
            return !wrapper || !wrapper.classList.contains('d-none');
        });

        for (const field of requiredFields) {
            if (field.type === 'checkbox') {
                if (!field.checked) {
                    field.focus();
                    return false;
                }
                continue;
            }
            if ((field.value || '').trim() === '') {
                field.focus();
                return false;
            }
        }

        return true;
    }

    prevBtn.addEventListener('click', () => {
        if (currentStep > 0) {
            currentStep -= 1;
            updateWizard();
        }
    });

    nextBtn.addEventListener('click', () => {
        if (!validateCurrentStep()) {
            alert('Prosím vyplňte povinné otázky v aktuálním kroku.');
            return;
        }
        if (currentStep < steps.length - 1) {
            currentStep += 1;
            updateWizard();
        }
    });

    form.addEventListener('change', updateConditionalVisibility);
    form.addEventListener('submit', (event) => {
        updateConditionalVisibility();
        if (!validateCurrentStep()) {
            event.preventDefault();
            alert('Prosím vyplňte povinné otázky v aktuálním kroku.');
        }
    });
    updateWizard();
})();
</script>

<?php renderAthleteFooter();
