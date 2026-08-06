<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/health_questionnaire.php';

requireAdminLogin();

$pdo = getDB();
healthQuestionnaireEnsureSchema($pdo);

if (!function_exists('adminHealthQuestionnaireOptionsToText')) {
    function adminHealthQuestionnaireOptionsToText(mixed $options): string
    {
        if (!is_array($options)) {
            return '';
        }

        $lines = [];
        foreach ($options as $key => $label) {
            if (is_string($key) && !is_int($key)) {
                $lines[] = $key . '|' . $label;
            } else {
                $lines[] = (string)$label;
            }
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('adminHealthQuestionnaireTextToOptions')) {
    function adminHealthQuestionnaireTextToOptions(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $result = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (strpos($line, '|') !== false) {
                [$value, $label] = array_pad(explode('|', $line, 2), 2, '');
                $value = trim($value);
                $label = trim($label);
                if ($value === '') {
                    continue;
                }
                $result[$value] = $label !== '' ? $label : $value;
                continue;
            }

            $result[] = $line;
        }

        return $result;
    }
}

if (!function_exists('adminHealthQuestionnaireSlug')) {
    function adminHealthQuestionnaireSlug(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $value = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
        if ($value === false) {
            $value = $raw;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = trim($value, '_');
        return mb_substr($value, 0, 80, 'UTF-8');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/health_questionnaire.php');
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_question' || $action === 'create_question') {
        $questionId = (int)($_POST['question_id'] ?? 0);
        $stepIndex = max(1, min(8, (int)($_POST['step_index'] ?? 1)));
        $sectionTitle = trim((string)($_POST['section_title'] ?? ''));
        $questionKey = adminHealthQuestionnaireSlug((string)($_POST['question_key'] ?? ''));
        $questionLabel = trim((string)($_POST['question_label'] ?? ''));
        $inputType = trim((string)($_POST['input_type'] ?? 'text'));
        $placeholder = trim((string)($_POST['placeholder'] ?? ''));
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $showWhenQuestionKey = adminHealthQuestionnaireSlug((string)($_POST['show_when_question_key'] ?? ''));
        $showWhenValue = trim((string)($_POST['show_when_value'] ?? ''));
        $alertMode = trim((string)($_POST['alert_mode'] ?? 'none'));
        $alertText = trim((string)($_POST['alert_text'] ?? ''));
        $sortOrder = (int)($_POST['sort_order'] ?? 100);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $options = adminHealthQuestionnaireTextToOptions((string)($_POST['options_text'] ?? ''));
        $alertValues = adminHealthQuestionnaireTextToOptions((string)($_POST['alert_values_text'] ?? ''));

        $allowedInputTypes = array_keys(healthQuestionnaireInputTypeOptions());
        $allowedAlertModes = array_keys(healthQuestionnaireAlertModeOptions());

        if ($sectionTitle === '' || $questionLabel === '') {
            flash('danger', 'Název kroku a otázka jsou povinné.');
            redirect(BASE_URL . '/admin/health_questionnaire.php');
        }

        if ($questionKey === '') {
            flash('danger', 'Klíč otázky je povinný.');
            redirect(BASE_URL . '/admin/health_questionnaire.php');
        }

        if (!in_array($inputType, $allowedInputTypes, true)) {
            flash('danger', 'Neplatný typ vstupu.');
            redirect(BASE_URL . '/admin/health_questionnaire.php');
        }

        if (!in_array($alertMode, $allowedAlertModes, true)) {
            flash('danger', 'Neplatný typ upozornění.');
            redirect(BASE_URL . '/admin/health_questionnaire.php');
        }

        $optionsJson = !empty($options) ? json_encode($options, JSON_UNESCAPED_UNICODE) : null;
        $alertValuesJson = !empty($alertValues) ? json_encode($alertValues, JSON_UNESCAPED_UNICODE) : null;

        try {
            if ($action === 'create_question') {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO athlete_health_questionnaire_questions
                    (step_index, section_title, question_key, question_label, input_type, options_json, placeholder, is_required, show_when_question_key, show_when_value, alert_mode, alert_values_json, alert_text, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insertStmt->execute([
                    $stepIndex,
                    $sectionTitle,
                    $questionKey,
                    $questionLabel,
                    $inputType,
                    $optionsJson,
                    $placeholder !== '' ? $placeholder : null,
                    $isRequired,
                    $showWhenQuestionKey !== '' ? $showWhenQuestionKey : null,
                    $showWhenValue !== '' ? $showWhenValue : null,
                    $alertMode,
                    $alertValuesJson,
                    $alertText !== '' ? $alertText : null,
                    $sortOrder,
                    $isActive,
                ]);
                flash('success', 'Otázka byla přidána.');
                redirect(BASE_URL . '/admin/health_questionnaire.php');
            }

            if ($questionId <= 0) {
                flash('danger', 'Otázka nebyla nalezena.');
                redirect(BASE_URL . '/admin/health_questionnaire.php');
            }

            $updateStmt = $pdo->prepare(
                'UPDATE athlete_health_questionnaire_questions
                 SET step_index = ?,
                     section_title = ?,
                     question_key = ?,
                     question_label = ?,
                     input_type = ?,
                     options_json = ?,
                     placeholder = ?,
                     is_required = ?,
                     show_when_question_key = ?,
                     show_when_value = ?,
                     alert_mode = ?,
                     alert_values_json = ?,
                     alert_text = ?,
                     sort_order = ?,
                     is_active = ?
                 WHERE id = ?'
            );
            $updateStmt->execute([
                $stepIndex,
                $sectionTitle,
                $questionKey,
                $questionLabel,
                $inputType,
                $optionsJson,
                $placeholder !== '' ? $placeholder : null,
                $isRequired,
                $showWhenQuestionKey !== '' ? $showWhenQuestionKey : null,
                $showWhenValue !== '' ? $showWhenValue : null,
                $alertMode,
                $alertValuesJson,
                $alertText !== '' ? $alertText : null,
                $sortOrder,
                $isActive,
                $questionId,
            ]);
            flash('success', 'Otázka byla upravena.');
            redirect(BASE_URL . '/admin/health_questionnaire.php');
        } catch (Throwable $e) {
            flash('danger', 'Uložení se nepodařilo: ' . $e->getMessage());
            redirect(BASE_URL . '/admin/health_questionnaire.php');
        }
    }

    if ($action === 'delete_question') {
        $questionId = (int)($_POST['question_id'] ?? 0);
        if ($questionId > 0) {
            $delStmt = $pdo->prepare('DELETE FROM athlete_health_questionnaire_questions WHERE id = ?');
            $delStmt->execute([$questionId]);
            flash('success', 'Otázka byla smazána.');
        } else {
            flash('danger', 'Otázka nebyla nalezena.');
        }
        redirect(BASE_URL . '/admin/health_questionnaire.php');
    }
}

$questions = healthQuestionnaireFetchQuestions($pdo, false);
$inputTypeOptions = healthQuestionnaireInputTypeOptions();
$alertModeOptions = healthQuestionnaireAlertModeOptions();

$questionsByStep = [];
$statsTotal = count($questions);
$statsActive = 0;
$statsWithAlerts = 0;

foreach ($questions as $question) {
    $stepIndex = (int)($question['step_index'] ?? 1);
    if (!isset($questionsByStep[$stepIndex])) {
        $questionsByStep[$stepIndex] = [
            'title' => (string)($question['section_title'] ?? ('Krok ' . $stepIndex)),
            'items' => [],
        ];
    }
    $questionsByStep[$stepIndex]['items'][] = $question;

    if ((int)($question['is_active'] ?? 0) === 1) {
        $statsActive++;
    }
    if ((string)($question['alert_mode'] ?? 'none') !== 'none') {
        $statsWithAlerts++;
    }
}

ksort($questionsByStep);

if (!function_exists('adminHealthQuestionnaireStepName')) {
    function adminHealthQuestionnaireStepName(array $stepData, int $stepIndex): string
    {
        $title = trim((string)($stepData['title'] ?? ''));
        if ($title === '') {
            return 'Krok ' . $stepIndex;
        }
        return 'Krok ' . $stepIndex . ': ' . $title;
    }
}

renderAdminHeader('Zdravotní dotazník');
?>

<style>
    .hq-sticky-tools {
        position: sticky;
        top: 86px;
    }

    .hq-step-link {
        display: block;
        border: 1px solid #e5e7eb;
        border-radius: .6rem;
        padding: .55rem .65rem;
        text-decoration: none;
        color: #111827;
        background: #fff;
        transition: all .15s ease;
    }

    .hq-step-link:hover {
        border-color: #f59e0b;
        background: #fff9eb;
        color: #111827;
    }

    .hq-question-card {
        border: 1px solid #e5e7eb;
        border-radius: .75rem;
        background: #fff;
    }

    .hq-question-head {
        padding: .75rem .9rem;
        display: flex;
        justify-content: space-between;
        align-items: start;
        gap: .75rem;
        border-bottom: 1px solid #f3f4f6;
    }

    .hq-question-title {
        font-weight: 700;
        line-height: 1.35;
    }

    .hq-question-meta {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        margin-top: .35rem;
    }

    .hq-question-body {
        padding: .85rem .9rem;
        background: #fcfcfd;
    }

    .hq-mini-label {
        font-size: .76rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #6b7280;
        font-weight: 700;
    }
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-heart-pulse me-2 text-danger"></i>Zdravotní dotazník</h2>
    <div class="small text-muted">Přehled otázek, logika zobrazení a pravidla upozornění</div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="hq-mini-label">Otázek celkem</div>
                <div class="fs-3 fw-bold"><?= (int)$statsTotal ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="hq-mini-label">Aktivních</div>
                <div class="fs-3 fw-bold text-success"><?= (int)$statsActive ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="hq-mini-label">S upozorněním</div>
                <div class="fs-3 fw-bold text-warning"><?= (int)$statsWithAlerts ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="hq-mini-label">Kroků</div>
                <div class="fs-3 fw-bold text-primary"><?= (int)count($questionsByStep) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-3">
        <div class="hq-sticky-tools d-grid gap-3">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold">Rychlá navigace</div>
                <div class="card-body d-grid gap-2">
                    <?php if (empty($questionsByStep)): ?>
                    <div class="text-muted small">Zatím bez kroků.</div>
                    <?php else: ?>
                        <?php foreach ($questionsByStep as $stepIndex => $stepData): ?>
                        <a href="#step-<?= (int)$stepIndex ?>" class="hq-step-link">
                            <div class="fw-semibold">Krok <?= (int)$stepIndex ?></div>
                            <div class="small text-muted"><?= h((string)$stepData['title']) ?></div>
                            <div class="small text-muted">Otázek: <?= (int)count($stepData['items']) ?></div>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white fw-semibold">Přidat novou otázku</div>
                <div class="card-body">
                    <form method="post" class="row g-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_question">

                        <div class="col-6">
                            <label class="form-label small fw-semibold">Krok</label>
                            <input type="number" class="form-control form-control-sm" name="step_index" min="1" max="8" value="1" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Pořadí</label>
                            <input type="number" class="form-control form-control-sm" name="sort_order" value="100" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Název sekce</label>
                            <input type="text" class="form-control form-control-sm" name="section_title" required placeholder="Např. Zdravotní stav">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Text otázky</label>
                            <input type="text" class="form-control form-control-sm" name="question_label" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Klíč</label>
                            <input type="text" class="form-control form-control-sm" name="question_key" required placeholder="napr. health_limitation">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Typ vstupu</label>
                            <select class="form-select form-select-sm" name="input_type" required>
                                <?php foreach ($inputTypeOptions as $key => $label): ?>
                                <option value="<?= h($key) ?>"><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <details class="col-12 mt-2">
                            <summary class="small fw-semibold" style="cursor:pointer">Rozšířené nastavení</summary>
                            <div class="row g-2 mt-1">
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Možnosti (řádky, volitelně value|label)</label>
                                    <textarea class="form-control form-control-sm" name="options_text" rows="3"></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Placeholder</label>
                                    <input type="text" class="form-control form-control-sm" name="placeholder">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Zobrazit když (key)</label>
                                    <input type="text" class="form-control form-control-sm" name="show_when_question_key">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Zobrazit když (hodnota)</label>
                                    <input type="text" class="form-control form-control-sm" name="show_when_value">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Upozornění</label>
                                    <select class="form-select form-select-sm" name="alert_mode">
                                        <?php foreach ($alertModeOptions as $key => $label): ?>
                                        <option value="<?= h($key) ?>"><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Alert hodnoty</label>
                                    <textarea class="form-control form-control-sm" name="alert_values_text" rows="2"></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold">Text upozornění</label>
                                    <textarea class="form-control form-control-sm" name="alert_text" rows="2"></textarea>
                                </div>
                                <div class="col-12 d-flex gap-3 mt-1">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_required" id="new_required">
                                        <label class="form-check-label small" for="new_required">Povinné</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="new_active" checked>
                                        <label class="form-check-label small" for="new_active">Aktivní</label>
                                    </div>
                                </div>
                            </div>
                        </details>

                        <div class="col-12 mt-3">
                            <button type="submit" class="btn btn-success btn-sm w-100 fw-semibold">
                                <i class="fas fa-plus me-1"></i>Přidat otázku
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-9">
        <?php if (empty($questionsByStep)): ?>
        <div class="alert alert-info">Zatím nejsou vytvořené žádné otázky.</div>
        <?php else: ?>
            <div class="d-grid gap-3">
                <?php foreach ($questionsByStep as $stepIndex => $stepData): ?>
                <section class="card border-0 shadow-sm" id="step-<?= (int)$stepIndex ?>">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="fw-semibold"><?= h(adminHealthQuestionnaireStepName($stepData, (int)$stepIndex)) ?></div>
                        <span class="badge bg-secondary"><?= (int)count($stepData['items']) ?> otázek</span>
                    </div>
                    <div class="card-body d-grid gap-2">
                        <?php foreach ($stepData['items'] as $question): ?>
                        <?php
                        $id = (int)$question['id'];
                        $optionsText = adminHealthQuestionnaireOptionsToText((array)($question['options'] ?? []));
                        $alertValuesText = adminHealthQuestionnaireOptionsToText((array)($question['alert_values'] ?? []));
                        $collapseId = 'q-edit-' . $id;
                        ?>
                        <article class="hq-question-card">
                            <div class="hq-question-head">
                                <div>
                                    <div class="hq-question-title"><?= h((string)$question['question_label']) ?></div>
                                    <div class="hq-question-meta">
                                        <span class="badge bg-light text-dark border">Klíč: <?= h((string)$question['question_key']) ?></span>
                                        <span class="badge bg-light text-dark border">Typ: <?= h((string)($inputTypeOptions[(string)$question['input_type']] ?? (string)$question['input_type'])) ?></span>
                                        <span class="badge bg-light text-dark border">Pořadí: <?= (int)$question['sort_order'] ?></span>
                                        <?php if ((int)$question['is_required'] === 1): ?>
                                        <span class="badge bg-primary">Povinné</span>
                                        <?php endif; ?>
                                        <?php if ((int)$question['is_active'] === 1): ?>
                                        <span class="badge bg-success">Aktivní</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Neaktivní</span>
                                        <?php endif; ?>
                                        <?php if ((string)$question['alert_mode'] !== 'none'): ?>
                                        <span class="badge bg-warning text-dark">Upozornění</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($collapseId) ?>" aria-expanded="false" aria-controls="<?= h($collapseId) ?>">
                                    <i class="fas fa-pen me-1"></i>Upravit
                                </button>
                            </div>

                            <div id="<?= h($collapseId) ?>" class="collapse">
                                <div class="hq-question-body">
                                    <form method="post" class="row g-2">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="save_question">
                                        <input type="hidden" name="question_id" value="<?= $id ?>">

                                        <div class="col-md-2">
                                            <label class="form-label small fw-semibold">Krok</label>
                                            <input type="number" class="form-control form-control-sm" name="step_index" min="1" max="8" value="<?= (int)$question['step_index'] ?>" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-semibold">Sekce</label>
                                            <input type="text" class="form-control form-control-sm" name="section_title" value="<?= h((string)$question['section_title']) ?>" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-semibold">Klíč</label>
                                            <input type="text" class="form-control form-control-sm" name="question_key" value="<?= h((string)$question['question_key']) ?>" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small fw-semibold">Typ</label>
                                            <select class="form-select form-select-sm" name="input_type" required>
                                                <?php foreach ($inputTypeOptions as $typeKey => $typeLabel): ?>
                                                <option value="<?= h($typeKey) ?>" <?= (string)$question['input_type'] === $typeKey ? 'selected' : '' ?>><?= h($typeLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small fw-semibold">Pořadí</label>
                                            <input type="number" class="form-control form-control-sm" name="sort_order" value="<?= (int)$question['sort_order'] ?>" required>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label small fw-semibold">Otázka</label>
                                            <input type="text" class="form-control form-control-sm" name="question_label" value="<?= h((string)$question['question_label']) ?>" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Možnosti</label>
                                            <textarea class="form-control form-control-sm" name="options_text" rows="3"><?= h($optionsText) ?></textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Placeholder</label>
                                            <input type="text" class="form-control form-control-sm" name="placeholder" value="<?= h((string)($question['placeholder'] ?? '')) ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Podmínka zobrazení</label>
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <input type="text" class="form-control form-control-sm" name="show_when_question_key" value="<?= h((string)($question['show_when_question_key'] ?? '')) ?>" placeholder="question_key">
                                                </div>
                                                <div class="col-6">
                                                    <input type="text" class="form-control form-control-sm" name="show_when_value" value="<?= h((string)($question['show_when_value'] ?? '')) ?>" placeholder="hodnota">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Upozornění</label>
                                            <select class="form-select form-select-sm mb-2" name="alert_mode">
                                                <?php foreach ($alertModeOptions as $modeKey => $modeLabel): ?>
                                                <option value="<?= h($modeKey) ?>" <?= (string)$question['alert_mode'] === $modeKey ? 'selected' : '' ?>><?= h($modeLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <textarea class="form-control form-control-sm mb-2" name="alert_values_text" rows="2" placeholder="Alert hodnoty"><?= h($alertValuesText) ?></textarea>
                                            <textarea class="form-control form-control-sm" name="alert_text" rows="2" placeholder="Text upozornění"><?= h((string)($question['alert_text'] ?? '')) ?></textarea>
                                        </div>

                                        <div class="col-12 d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
                                            <div class="d-flex gap-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="is_required" id="required_<?= $id ?>" <?= (int)$question['is_required'] === 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label small" for="required_<?= $id ?>">Povinné</label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="is_active" id="active_<?= $id ?>" <?= (int)$question['is_active'] === 1 ? 'checked' : '' ?>>
                                                    <label class="form-check-label small" for="active_<?= $id ?>">Aktivní</label>
                                                </div>
                                            </div>

                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-save me-1"></i>Uložit
                                                </button>
                                            </div>
                                        </div>
                                    </form>

                                    <form method="post" class="mt-2" onsubmit="return confirm('Opravdu smazat tuto otázku?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_question">
                                        <input type="hidden" name="question_id" value="<?= $id ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash me-1"></i>Smazat otázku
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php renderAdminFooter();
