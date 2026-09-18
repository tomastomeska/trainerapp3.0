<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/surveys.php';
require_once __DIR__ . '/header.php';
requireAdminLogin();

$pdo = getDB();
$error = null;

function adminParseSurveyQuestions(string $raw, string $surveyType): array
{
    $questions = [];
    foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('||', $line, 2));
        $text = trim($parts[0]);
        if ($text === '') {
            continue;
        }
        $type = 'single_choice';
        if ($surveyType === 'questionnaire' && str_starts_with(strtolower($text), '[text]')) {
            $type = 'textarea';
            $text = trim(substr($text, 6));
        }
        $options = [];
        if ($type === 'single_choice') {
            $options = array_values(array_filter(array_map('trim', explode('|', $parts[1] ?? '')), static fn(string $value): bool => $value !== ''));
            if (count($options) < 2) {
                throw new InvalidArgumentException('Každá výběrová otázka musí mít alespoň dvě možnosti za znakem ||.');
            }
        }
        $questions[] = ['text' => mb_substr($text, 0, 2000, 'UTF-8'), 'type' => $type, 'options' => $options];
    }
    if (!$questions) {
        throw new InvalidArgumentException('Zadejte alespoň jednu otázku.');
    }
    return $questions;
}

function adminParseStructuredSurveyQuestions(array $rawQuestions, string $surveyType): array
{
    $questions = [];
    foreach ($rawQuestions as $rawQuestion) {
        if (!is_array($rawQuestion)) {
            continue;
        }

        $text = trim((string)($rawQuestion['text'] ?? ''));
        $type = (string)($rawQuestion['type'] ?? 'single_choice');
        if ($text === '') {
            continue;
        }
        if (!in_array($type, ['single_choice', 'multiple_choice', 'textarea'], true)) {
            throw new InvalidArgumentException('Typ otázky není platný.');
        }
        $options = [];
        if (in_array($type, ['single_choice', 'multiple_choice'], true)) {
            $rawOptions = is_array($rawQuestion['options'] ?? null) ? $rawQuestion['options'] : [];
            $options = array_values(array_filter(array_map(static fn($option): string => trim((string)$option), $rawOptions), static fn(string $option): bool => $option !== ''));
            if (count($options) < 2) {
                throw new InvalidArgumentException('Každá výběrová otázka musí mít alespoň dvě možnosti.');
            }
        }
        $questions[] = [
            'text' => mb_substr($text, 0, 2000, 'UTF-8'),
            'type' => $type,
            'options' => array_map(static fn(string $option): string => mb_substr($option, 0, 255, 'UTF-8'), $options),
        ];
    }

    if (!$questions) {
        throw new InvalidArgumentException('Přidejte alespoň jednu otázku.');
    }
    return $questions;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $surveyId = (int)($_POST['survey_id'] ?? 0);
        if ($action === 'publish' && $surveyId > 0) {
            $publishStmt = $pdo->prepare("UPDATE surveys SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id = ? AND status = 'draft'");
            $publishStmt->execute([$surveyId]);
            $published = surveyFetchById($pdo, $surveyId);
            if ($published) {
                try {
                    sendSurveyNotificationEmails($pdo, $surveyId, (string)$published['title'], (string)$published['survey_type'], (string)$published['audience']);
                    processEmailNotificationQueue(200, 'survey_notification');
                } catch (Throwable $e) {
                    error_log('Survey publish notification error: ' . $e->getMessage());
                }
            }
            flash('success', 'Šetření bylo publikováno a uživatelům byla odeslána notifikace.');
            redirect(BASE_URL . '/admin/surveys.php');
        }

        if ($action === 'end' && $surveyId > 0) {
            $stmt = $pdo->prepare("UPDATE surveys SET status = 'ended', ended_at = NOW() WHERE id = ? AND status = 'published'");
            $stmt->execute([$surveyId]);
            flash('success', 'Šetření bylo ukončeno a přesunuto do archivu.');
            redirect(BASE_URL . '/admin/surveys.php');
        }

        if ($action === 'update_draft' && $surveyId > 0) {
            try {
                $existingStmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ? AND status = 'draft' LIMIT 1");
                $existingStmt->execute([$surveyId]);
                $existing = $existingStmt->fetch();
                if (!$existing) {
                    throw new InvalidArgumentException('Upravovat lze pouze návrh šetření.');
                }
                $title = trim((string)($_POST['title'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $surveyType = (string)($_POST['survey_type'] ?? 'poll');
                $audience = (string)($_POST['audience'] ?? 'all');
                $expiresAt = trim((string)($_POST['expires_at'] ?? ''));
                if ($title === '' || !in_array($surveyType, ['poll', 'questionnaire'], true) || !in_array($audience, ['all', 'athletes', 'coaches'], true)) {
                    throw new InvalidArgumentException('Vyplňte název a platné nastavení šetření.');
                }
                $questionsInput = $_POST['questions'] ?? [];
                $questions = is_array($questionsInput) ? adminParseStructuredSurveyQuestions($questionsInput, $surveyType) : adminParseSurveyQuestions((string)$questionsInput, $surveyType);
                if (in_array('multiple_choice', array_column($questions, 'type'), true) && !surveySupportsMultipleChoice($pdo)) {
                    throw new InvalidArgumentException('Databáze ještě není připravená na výběr více možností. Spusťte nejprve migraci: ' . BASE_URL . '/scripts/migrate_surveys.php');
                }
                $expiresTimestamp = $expiresAt !== '' ? strtotime($expiresAt) : false;
                if ($expiresAt !== '' && $expiresTimestamp === false) {
                    throw new InvalidArgumentException('Platnost není platné datum.');
                }

                $pdo->beginTransaction();
                $updateStmt = $pdo->prepare('UPDATE surveys SET title = ?, description = ?, survey_type = ?, audience = ?, expires_at = ? WHERE id = ?');
                $updateStmt->execute([$title, $description !== '' ? $description : null, $surveyType, $audience, $expiresTimestamp !== false ? date('Y-m-d H:i:s', $expiresTimestamp) : null, $surveyId]);
                $pdo->prepare('DELETE FROM survey_questions WHERE survey_id = ?')->execute([$surveyId]);
                $questionStmt = $pdo->prepare('INSERT INTO survey_questions (survey_id, question_text, question_type, sort_order) VALUES (?, ?, ?, ?)');
                $optionStmt = $pdo->prepare('INSERT INTO survey_options (question_id, option_text, sort_order) VALUES (?, ?, ?)');
                foreach ($questions as $questionIndex => $question) {
                    $questionStmt->execute([$surveyId, $question['text'], $question['type'], $questionIndex]);
                    $questionId = (int)$pdo->lastInsertId();
                    foreach ($question['options'] as $optionIndex => $option) {
                        $optionStmt->execute([$questionId, $option, $optionIndex]);
                    }
                }
                $pdo->commit();
                flash('success', 'Návrh dotazníku byl upraven.');
                redirect(BASE_URL . '/admin/surveys.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Survey draft update error: ' . $e->getMessage());
                $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Návrh se nepodařilo upravit.';
            }
        }

        if ($action === 'create') {
            try {
                $title = trim((string)($_POST['title'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $surveyType = (string)($_POST['survey_type'] ?? 'poll');
                $audience = (string)($_POST['audience'] ?? 'all');
                $expiresAt = trim((string)($_POST['expires_at'] ?? ''));
                $status = isset($_POST['publish']) ? 'published' : 'draft';
                if ($title === '' || !in_array($surveyType, ['poll', 'questionnaire'], true) || !in_array($audience, ['all', 'athletes', 'coaches'], true)) {
                    throw new InvalidArgumentException('Vyplňte název a platné nastavení šetření.');
                }
                $questionsInput = $_POST['questions'] ?? [];
                $questions = is_array($questionsInput)
                    ? adminParseStructuredSurveyQuestions($questionsInput, $surveyType)
                    : adminParseSurveyQuestions((string)$questionsInput, $surveyType);
                if (in_array('multiple_choice', array_column($questions, 'type'), true) && !surveySupportsMultipleChoice($pdo)) {
                    throw new InvalidArgumentException('Databáze ještě není připravená na výběr více možností. Spusťte nejprve migraci: ' . BASE_URL . '/scripts/migrate_surveys.php');
                }
                $expiresTimestamp = $expiresAt !== '' ? strtotime($expiresAt) : false;
                $expiresSql = $expiresTimestamp !== false ? date('Y-m-d H:i:s', $expiresTimestamp) : null;
                if ($expiresAt !== '' && $expiresTimestamp === false) {
                    throw new InvalidArgumentException('Platnost není platné datum.');
                }

                $pdo->beginTransaction();
                $surveyStmt = $pdo->prepare('INSERT INTO surveys (title, description, survey_type, audience, status, starts_at, expires_at, published_at) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)');
                $surveyStmt->execute([$title, $description !== '' ? $description : null, $surveyType, $audience, $status, $expiresSql, $status === 'published' ? date('Y-m-d H:i:s') : null]);
                $newSurveyId = (int)$pdo->lastInsertId();
                $questionStmt = $pdo->prepare('INSERT INTO survey_questions (survey_id, question_text, question_type, sort_order) VALUES (?, ?, ?, ?)');
                $optionStmt = $pdo->prepare('INSERT INTO survey_options (question_id, option_text, sort_order) VALUES (?, ?, ?)');
                foreach ($questions as $questionIndex => $question) {
                    $questionStmt->execute([$newSurveyId, $question['text'], $question['type'], $questionIndex]);
                    $questionId = (int)$pdo->lastInsertId();
                    foreach ($question['options'] as $optionIndex => $option) {
                        $optionStmt->execute([$questionId, $option, $optionIndex]);
                    }
                }
                $pdo->commit();

                if ($status === 'published') {
                    try {
                        sendSurveyNotificationEmails($pdo, $newSurveyId, $title, $surveyType, $audience);
                        processEmailNotificationQueue(200, 'survey_notification');
                    } catch (Throwable $e) {
                        error_log('Survey create notification error: ' . $e->getMessage());
                    }
                }
                flash('success', $status === 'published' ? 'Šetření bylo publikováno a uživatelům byla odeslána notifikace.' : 'Návrh šetření byl uložen.');
                redirect(BASE_URL . '/admin/surveys.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Šetření se nepodařilo uložit.';
            }
        }
    }
}

renderAdminHeader('Ankety a dotazníky');
if (surveySchemaAvailable($pdo)) {
    $pdo->exec("UPDATE surveys SET status = 'ended', ended_at = COALESCE(ended_at, NOW()) WHERE status = 'published' AND expires_at IS NOT NULL AND expires_at < NOW()");
}
$surveys = surveySchemaAvailable($pdo) ? $pdo->query(
    'SELECT s.*, COUNT(DISTINCT r.id) AS response_count
     FROM surveys s LEFT JOIN survey_responses r ON r.survey_id = s.id
     GROUP BY s.id ORDER BY s.created_at DESC'
)->fetchAll() : [];
$surveyStats = [
    'total' => count($surveys),
    'published' => count(array_filter($surveys, static fn(array $survey): bool => $survey['status'] === 'published')),
    'responses' => array_sum(array_map(static fn(array $survey): int => (int)$survey['response_count'], $surveys)),
    'drafts' => count(array_filter($surveys, static fn(array $survey): bool => $survey['status'] === 'draft')),
];
$editSurveyId = (int)($_GET['edit_id'] ?? 0);
$editSurvey = null;
if ($editSurveyId > 0 && surveySchemaAvailable($pdo)) {
    $editSurvey = surveyFetchById($pdo, $editSurveyId);
    if (!$editSurvey || $editSurvey['status'] !== 'draft') {
        $editSurvey = null;
    }
}
$formQuestions = $editSurvey['questions'] ?? [[
    'text' => '',
    'question_type' => 'single_choice',
    'options' => [['option_text' => ''], ['option_text' => '']],
]];
?>
<style>
    .survey-admin-page { max-width: 1440px; margin: 0 auto; padding-bottom: 2rem; }
    .survey-hero { position: relative; overflow: hidden; border-radius: 20px; padding: 1.7rem 1.8rem; color: #fff; background: linear-gradient(120deg, #132238 0%, #1c3552 58%, #087f8c 100%); box-shadow: 0 14px 32px rgba(15, 35, 60, .18); }
    .survey-hero::after { content: ''; position: absolute; width: 230px; height: 230px; right: -55px; top: -90px; border: 1px solid rgba(255,255,255,.17); border-radius: 50%; box-shadow: 0 0 0 24px rgba(255,255,255,.04), 0 0 0 48px rgba(255,255,255,.025); }
    .survey-hero__eyebrow { font-size: .72rem; text-transform: uppercase; letter-spacing: .12em; opacity: .7; font-weight: 700; }
    .survey-hero h1 { font-family: 'Space Grotesk', 'Segoe UI', sans-serif; font-size: clamp(1.65rem, 3vw, 2.35rem); letter-spacing: -.02em; margin: .25rem 0 .45rem; }
    .survey-hero p { max-width: 650px; margin: 0; color: rgba(255,255,255,.78); }
    .survey-hero .btn { position: relative; z-index: 1; background: #ffc107; border-color: #ffc107; color: #17202b; }
    .survey-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; margin: -1.15rem 1rem 1.4rem; position: relative; z-index: 2; }
    .survey-stat { min-height: 88px; padding: .85rem 1rem; border: 1px solid #e2e8f0; border-radius: 13px; background: #fff; box-shadow: 0 8px 18px rgba(15,23,42,.07); }
    .survey-stat__label { color: #64748b; font-size: .76rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
    .survey-stat__value { display: block; color: #17202b; font-size: 1.65rem; font-weight: 800; line-height: 1.1; margin-top: .25rem; }
    .survey-workspace { display: grid; grid-template-columns: minmax(360px, .85fr) minmax(0, 1.5fr); gap: 1rem; align-items: start; }
    .survey-panel { border: 1px solid #e3eaf2; border-radius: 16px; background: #fff; box-shadow: 0 6px 18px rgba(15,23,42,.055); overflow: hidden; }
    .survey-panel__head { display: flex; justify-content: space-between; align-items: flex-start; gap: .75rem; padding: 1.05rem 1.2rem; border-bottom: 1px solid #e9eef5; background: #fbfdff; }
    .survey-panel__head h2 { font-size: 1.08rem; margin: 0; color: #17202b; }
    .survey-panel__head p { margin: .22rem 0 0; color: #718096; font-size: .82rem; }
    .survey-panel__body { padding: 1.2rem; }
    .survey-form-label { color: #334155; font-size: .78rem; font-weight: 750; margin-bottom: .35rem; }
    .survey-form-control { border-color: #d6e0eb; border-radius: 9px; padding: .62rem .75rem; }
    .survey-form-control:focus { border-color: #0b8790; box-shadow: 0 0 0 .2rem rgba(8,127,140,.12); }
    .survey-editor-hint { margin-top: .75rem; padding: .75rem .85rem; border-radius: 10px; background: #f0f8f8; color: #477078; font-size: .78rem; line-height: 1.55; }
    .survey-editor-hint strong { color: #17636b; }
    .survey-builder { display: grid; gap: .75rem; }
    .survey-question { padding: .9rem; border: 1px solid #dce6ef; border-radius: 12px; background: #fbfdff; }
    .survey-question__head { display: flex; justify-content: space-between; align-items: center; gap: .6rem; margin-bottom: .65rem; }
    .survey-question__number { display: inline-flex; align-items: center; justify-content: center; width: 25px; height: 25px; border-radius: 50%; color: #fff; background: #168b91; font-size: .75rem; font-weight: 800; }
    .survey-question__title { color: #44566a; font-size: .78rem; font-weight: 750; }
    .survey-question__remove, .survey-option__remove { border: 0; color: #8a9aaa; background: transparent; padding: .2rem .35rem; }
    .survey-question__remove:hover, .survey-option__remove:hover { color: #dc3545; }
    .survey-options { display: grid; gap: .45rem; margin-top: .6rem; }
    .survey-option { display: flex; align-items: center; gap: .4rem; }
    .survey-option input { flex: 1; }
    .survey-add-option { border: 0; padding: 0; color: #168b91; background: transparent; font-size: .78rem; font-weight: 700; }
    .survey-add-option:hover { color: #0c6268; }
    .survey-add-question { border: 1px dashed #a9c5ce; color: #17636b; background: #f3fbfb; }
    .survey-add-question:hover { border-color: #168b91; color: #0c6268; background: #e8f7f7; }
    .survey-list { display: grid; gap: .7rem; }
    .survey-item { padding: 1rem 1.1rem; border: 1px solid #e4ebf3; border-radius: 13px; background: #fff; transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease; }
    .survey-item:hover { transform: translateY(-1px); border-color: #b9cddd; box-shadow: 0 8px 18px rgba(15,23,42,.07); }
    .survey-item__top { display: flex; justify-content: space-between; gap: .9rem; align-items: flex-start; }
    .survey-item__title { min-width: 0; color: #17202b; font-size: 1rem; font-weight: 750; overflow-wrap: anywhere; }
    .survey-item__meta { display: flex; flex-wrap: wrap; gap: .4rem .7rem; margin-top: .55rem; color: #6b7b8d; font-size: .78rem; }
    .survey-item__meta span { display: inline-flex; align-items: center; gap: .3rem; }
    .survey-item__actions { display: flex; align-items: center; gap: .45rem; flex-shrink: 0; }
    .survey-item__actions .btn { white-space: nowrap; }
    .survey-results { margin-top: .85rem; padding-top: .8rem; border-top: 1px solid #edf1f5; }
    .survey-results summary { cursor: pointer; color: #17636b; font-size: .8rem; font-weight: 700; }
    .survey-result-row { margin-top: .65rem; }
    .survey-result-row__label { display: flex; justify-content: space-between; gap: .5rem; color: #526579; font-size: .75rem; }
    .survey-result-row .progress { height: 6px; margin-top: .25rem; background: #edf2f7; }
    .survey-result-row .progress-bar { background: #168b91; border-radius: 99px; }
    .survey-empty { padding: 2.7rem 1rem; text-align: center; color: #7b8b9c; }
    .survey-empty i { color: #b7c5d3; font-size: 2rem; margin-bottom: .65rem; }
    @media (max-width: 1050px) { .survey-workspace { grid-template-columns: 1fr; } }
    @media (max-width: 680px) { .survey-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-left: .25rem; margin-right: .25rem; } .survey-hero { padding: 1.25rem; } .survey-panel__body { padding: .9rem; } .survey-item__top { flex-direction: column; } .survey-item__actions { width: 100%; } }
</style>
<div class="survey-admin-page">
    <section class="survey-hero mb-4">
        <div class="d-flex justify-content-between align-items-end gap-3 flex-wrap position-relative" style="z-index:1">
            <div>
                <div class="survey-hero__eyebrow"><i class="fas fa-chart-pie me-1"></i> Komunikace s uživateli</div>
                <h1>Ankety a dotazníky</h1>
                <p>Vytvořte otázky, oslovte správné publikum a sledujte odpovědi na jednom místě.</p>
            </div>
            <a href="<?= BASE_URL ?>/scripts/migrate_surveys.php" target="_blank" class="btn btn-sm fw-bold"><i class="fas fa-database me-1"></i>Aktivovat databázi</a>
        </div>
    </section>
    <section class="survey-stats" aria-label="Přehled šetření">
        <div class="survey-stat"><span class="survey-stat__label">Celkem</span><strong class="survey-stat__value"><?= $surveyStats['total'] ?></strong></div>
        <div class="survey-stat"><span class="survey-stat__label">Publikováno</span><strong class="survey-stat__value" style="color:#168b91"><?= $surveyStats['published'] ?></strong></div>
        <div class="survey-stat"><span class="survey-stat__label">Odpovědi</span><strong class="survey-stat__value" style="color:#2563a8"><?= $surveyStats['responses'] ?></strong></div>
        <div class="survey-stat"><span class="survey-stat__label">Návrhy</span><strong class="survey-stat__value" style="color:#c47b09"><?= $surveyStats['drafts'] ?></strong></div>
    </section>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if (!surveySchemaAvailable($pdo)): ?>
<div class="alert alert-warning border-0 shadow-sm"><i class="fas fa-circle-info me-2"></i>Nejprve klikněte na „Aktivovat databázi“ a poté stránku obnovte.</div>
<?php else: ?>
<div class="survey-workspace">
<section class="survey-panel">
    <div class="survey-panel__head"><div><h2><i class="fas fa-pen-to-square me-2" style="color:#168b91"></i>Nové šetření</h2><p>Publikujte anketu nebo připravte dotazník na později.</p></div><span class="badge bg-light text-dark border">01</span></div>
    <div class="survey-panel__body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="<?= $editSurvey ? 'update_draft' : 'create' ?>"><?php if ($editSurvey): ?><input type="hidden" name="survey_id" value="<?= (int)$editSurvey['id'] ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-12"><label class="form-label survey-form-label">Název šetření</label><input class="form-control survey-form-control" name="title" required maxlength="255" placeholder="Např. Tréninkové preference" value="<?= h((string)($editSurvey['title'] ?? '')) ?>"></div>
                <div class="col-sm-6"><label class="form-label survey-form-label">Typ</label><select class="form-select survey-form-control" name="survey_type"><option value="poll"<?= ($editSurvey['survey_type'] ?? '') === 'poll' ? ' selected' : '' ?>>Anketa</option><option value="questionnaire"<?= ($editSurvey['survey_type'] ?? '') === 'questionnaire' ? ' selected' : '' ?>>Dotazník · více otázek</option></select></div>
                <div class="col-sm-6"><label class="form-label survey-form-label">Cílová skupina</label><select class="form-select survey-form-control" name="audience"><option value="all"<?= ($editSurvey['audience'] ?? '') === 'all' ? ' selected' : '' ?>>Všichni uživatelé</option><option value="athletes"<?= ($editSurvey['audience'] ?? '') === 'athletes' ? ' selected' : '' ?>>Pouze sportovci</option><option value="coaches"<?= ($editSurvey['audience'] ?? '') === 'coaches' ? ' selected' : '' ?>>Pouze trenéři</option></select></div>
                <div class="col-12"><label class="form-label survey-form-label">Úvodní text <span class="text-muted fw-normal">(volitelné)</span></label><textarea class="form-control survey-form-control" name="description" rows="3" placeholder="Krátce vysvětlete, proč se uživatelé ptáte."><?= h((string)($editSurvey['description'] ?? '')) ?></textarea></div>
                <div class="col-12"><label class="form-label survey-form-label">Platnost do <span class="text-muted fw-normal">(volitelné)</span></label><input class="form-control survey-form-control" type="datetime-local" name="expires_at" value="<?= $editSurvey && $editSurvey['expires_at'] ? h(date('Y-m-d\TH:i', strtotime((string)$editSurvey['expires_at']))) : '' ?>"></div>
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2"><label class="form-label survey-form-label mb-0">Otázky</label><span class="small text-muted">Přidejte jednu nebo více otázek</span></div>
                    <div id="surveyQuestionBuilder" class="survey-builder">
                        <?php foreach ($formQuestions as $questionIndex => $question): $questionType = (string)($question['question_type'] ?? $question['type'] ?? 'single_choice'); $questionOptions = $question['options'] ?? []; ?>
                        <div class="survey-question" data-question>
                            <div class="survey-question__head"><div class="d-flex align-items-center gap-2"><span class="survey-question__number" data-question-number><?= $questionIndex + 1 ?></span><span class="survey-question__title">Otázka</span></div><button type="button" class="survey-question__remove" data-remove-question title="Odstranit otázku"><i class="fas fa-trash-can"></i></button></div>
                            <input class="form-control survey-form-control" name="questions[<?= $questionIndex ?>][text]" required maxlength="2000" placeholder="Např. Jaký typ tréninku preferujete?" value="<?= h((string)($question['question_text'] ?? $question['text'] ?? '')) ?>">
                            <div class="row g-2 mt-1"><div class="col-sm-8"><label class="form-label survey-form-label">Typ odpovědi</label><select class="form-select form-select-sm survey-form-control" name="questions[<?= $questionIndex ?>][type]" data-question-type><option value="single_choice"<?= $questionType === 'single_choice' ? ' selected' : '' ?>>Výběr jedné možnosti</option><option value="multiple_choice"<?= $questionType === 'multiple_choice' ? ' selected' : '' ?>>Výběr více možností</option><option value="textarea"<?= $questionType === 'textarea' ? ' selected' : '' ?>>Volná textová odpověď</option></select></div></div>
                            <div class="survey-options" data-options>
                                <?php foreach ($questionOptions as $option): ?><div class="survey-option"><input class="form-control form-control-sm survey-form-control" name="questions[<?= $questionIndex ?>][options][]" required maxlength="255" placeholder="Možnost" value="<?= h((string)($option['option_text'] ?? $option)) ?>"><button type="button" class="survey-option__remove" data-remove-option title="Odstranit možnost"><i class="fas fa-xmark"></i></button></div><?php endforeach; ?>
                                <?php if (!$questionOptions): ?><div class="survey-option"><input class="form-control form-control-sm survey-form-control" name="questions[<?= $questionIndex ?>][options][]" required maxlength="255" placeholder="Možnost 1"><button type="button" class="survey-option__remove" data-remove-option title="Odstranit možnost"><i class="fas fa-xmark"></i></button></div><div class="survey-option"><input class="form-control form-control-sm survey-form-control" name="questions[<?= $questionIndex ?>][options][]" required maxlength="255" placeholder="Možnost 2"><button type="button" class="survey-option__remove" data-remove-option title="Odstranit možnost"><i class="fas fa-xmark"></i></button></div><?php endif; ?>
                                <button type="button" class="survey-add-option align-self-start" data-add-option><i class="fas fa-plus me-1"></i>Přidat možnost</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-sm survey-add-question mt-2 w-100" id="addSurveyQuestion"><i class="fas fa-plus me-1"></i>Přidat další otázku</button>
                    <div class="survey-editor-hint"><strong>Tip:</strong> U otázky můžete povolit jednu nebo více možností, případně volnou odpověď. Platí to pro ankety i dotazníky.</div>
                </div>
                <div class="col-12"><label class="d-flex align-items-center gap-2 small fw-semibold"><input class="form-check-input mt-0" type="checkbox" name="publish" checked> Publikovat ihned a odeslat e-mail</label></div>
            </div>
            <button class="btn btn-warning mt-4 fw-bold px-3"><i class="fas fa-<?= $editSurvey ? 'floppy-disk' : 'paper-plane' ?> me-1"></i><?= $editSurvey ? 'Uložit úpravy' : 'Uložit šetření' ?></button>
        </form>
    </div>
</section>
<section class="survey-panel">
    <div class="survey-panel__head"><div><h2><i class="fas fa-list-check me-2" style="color:#2563a8"></i>Vaše šetření</h2><p>Správa publikovaných anket, návrhů a archivovaných výsledků.</p></div><span class="badge bg-light text-dark border">02</span></div>
    <div class="survey-panel__body"><div class="survey-list">
<?php foreach ($surveys as $survey): ?>
<article class="survey-item">
    <div class="survey-item__top">
        <div><div class="survey-item__title"><?= h($survey['title']) ?></div><div class="survey-item__meta"><span><i class="fas <?= $survey['survey_type'] === 'poll' ? 'fa-square-poll-vertical' : 'fa-clipboard-question' ?>"></i><?= $survey['survey_type'] === 'poll' ? 'Anketa' : 'Dotazník' ?></span><span><i class="fas fa-users"></i><?= $survey['audience'] === 'all' ? 'Všichni' : ($survey['audience'] === 'athletes' ? 'Sportovci' : 'Trenéři') ?></span><span><i class="fas fa-comments"></i><?= (int)$survey['response_count'] ?> odpovědí</span><?php if ($survey['expires_at']): ?><span><i class="far fa-clock"></i>do <?= h(formatDate($survey['expires_at'])) ?></span><?php endif; ?></div></div>
        <div class="survey-item__actions"><span class="badge bg-<?= $survey['status'] === 'published' ? 'success' : ($survey['status'] === 'ended' ? 'secondary' : 'warning text-dark') ?>"><?= $survey['status'] === 'published' ? 'Živé' : ($survey['status'] === 'ended' ? 'Archiv' : 'Návrh') ?></span><?php if ($survey['status'] === 'draft'): ?><a class="btn btn-sm btn-outline-primary" href="?edit_id=<?= (int)$survey['id'] ?>" title="Upravit"><i class="fas fa-pen"></i></a><form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="survey_id" value="<?= (int)$survey['id'] ?>"><button class="btn btn-sm btn-outline-success" title="Publikovat"><i class="fas fa-play"></i></button></form><?php elseif ($survey['status'] === 'published'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="end"><input type="hidden" name="survey_id" value="<?= (int)$survey['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Ukončit"><i class="fas fa-stop"></i></button></form><?php endif; ?></div>
    </div>
    <?php $detail = surveyFetchById($pdo, (int)$survey['id']); $totalResponses = (int)$survey['response_count']; ?>
    <details class="survey-results"><summary>Zobrazit výsledky a odpovědi</summary>
    <div class="pt-2">
        <div class="fw-bold small text-uppercase text-muted mb-2">Souhrn výsledků</div>
        <?php foreach (($detail['questions'] ?? []) as $question): ?>
        <div class="mb-3">
            <div class="small fw-semibold mb-2"><?= h($question['question_text']) ?></div>
            <?php if (in_array($question['question_type'], ['single_choice', 'multiple_choice'], true)): ?>
                <?php foreach (surveyQuestionStats($pdo, (int)$question['id']) as $stat): $percentage = $totalResponses > 0 ? round(((int)$stat['answer_count'] / $totalResponses) * 100) : 0; ?>
                <div class="survey-result-row">
                    <div class="survey-result-row__label"><span><?= h($stat['option_text']) ?></span><strong><?= $percentage ?>% · <?= (int)$stat['answer_count'] ?></strong></div>
                    <div class="progress"><div class="progress-bar" style="width:<?= $percentage ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php $answerStmt = $pdo->prepare('SELECT sa.answer_text FROM survey_answers sa JOIN survey_responses sr ON sr.id = sa.response_id WHERE sa.question_id = ? ORDER BY sr.submitted_at DESC LIMIT 10'); $answerStmt->execute([(int)$question['id']]); ?>
                <div class="small text-muted">
                    <?php foreach ($answerStmt->fetchAll() as $answer): ?><div class="border-start border-3 border-info ps-2 mb-2"><?= nl2br(h((string)$answer['answer_text'])) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php $respondents = surveyFetchRespondents($pdo, (int)$survey['id']); ?>
        <div class="fw-bold small text-uppercase text-muted mt-4 mb-2">Respondenti</div>
        <?php if (!$respondents): ?>
        <div class="text-muted small">Zatím nikdo neodpověděl.</div>
        <?php else: ?>
            <?php foreach ($respondents as $respondent): ?>
            <div class="border rounded p-3 mb-2 bg-light">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">
                    <div>
                        <div class="fw-semibold"><i class="fas <?= $respondent['user_type'] === 'athlete' ? 'fa-person-running' : 'fa-user-tie' ?> me-1 text-info"></i><?= h((string)$respondent['respondent_name']) ?></div>
                        <div class="small text-muted"><?= $respondent['user_type'] === 'athlete' ? 'Sportovec' : 'Trenér' ?><?= $respondent['respondent_email'] ? ' · ' . h((string)$respondent['respondent_email']) : '' ?></div>
                    </div>
                    <span class="small text-muted"><?= h(formatDateTime((string)$respondent['submitted_at'])) ?></span>
                </div>
                <?php foreach (($detail['questions'] ?? []) as $question): $answers = $respondent['answers_by_question'][(int)$question['id']] ?? []; ?>
                <div class="mb-2">
                    <div class="small text-muted"><?= h((string)$question['question_text']) ?></div>
                    <?php if (!$answers): ?>
                    <div class="small">Bez odpovědi</div>
                    <?php elseif (in_array($question['question_type'], ['single_choice', 'multiple_choice'], true)): ?>
                    <div class="small fw-semibold"><?= h(implode(', ', array_values(array_filter(array_map(static fn(array $answer): string => (string)($answer['option_text'] ?? ''), $answers))))) ?></div>
                    <?php else: ?>
                    <div class="small fw-semibold" style="white-space: pre-wrap;"><?= h((string)($answers[0]['answer_text'] ?? '')) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    </details>
</article>
<?php endforeach; ?>
</div><?php if (!$surveys): ?><div class="survey-empty"><i class="fas fa-inbox d-block"></i><strong>Zatím tu nejsou žádná šetření</strong><div class="small mt-1">Vytvořte první anketu ve formuláři vlevo.</div></div><?php endif; ?></div>
</section>
</div>
<?php endif; ?>
<?php if (surveySchemaAvailable($pdo)): ?>
<script>
(() => {
    const builder = document.getElementById('surveyQuestionBuilder');
    const addQuestionButton = document.getElementById('addSurveyQuestion');
    const surveyType = document.querySelector('select[name="survey_type"]');
    if (!builder || !addQuestionButton || !surveyType) return;

    let questionIndex = builder.querySelectorAll('[data-question]').length;

    const updateQuestionNumbers = () => {
        builder.querySelectorAll('[data-question]').forEach((question, index) => {
            const number = question.querySelector('[data-question-number]');
            if (number) number.textContent = String(index + 1);
            const removeButton = question.querySelector('[data-remove-question]');
            if (removeButton) removeButton.hidden = builder.querySelectorAll('[data-question]').length === 1;
        });
    };

    const syncQuestionType = (question) => {
        const typeSelect = question.querySelector('[data-question-type]');
        const options = question.querySelector('[data-options]');
        if (!typeSelect || !options) return;
        const isChoice = ['single_choice', 'multiple_choice'].includes(typeSelect.value);
        options.hidden = !isChoice;
        options.querySelectorAll('input').forEach((input) => { input.required = isChoice; });
        typeSelect.closest('.col-sm-6').hidden = surveyType.value === 'poll';
    };

    const syncAllQuestionTypes = () => builder.querySelectorAll('[data-question]').forEach(syncQuestionType);

    const addOption = (question) => {
        const options = question.querySelector('[data-options]');
        const addButton = options.querySelector('[data-add-option]');
        const index = question.querySelector('[data-question-type]').name.match(/questions\[(\d+)\]/)?.[1] ?? '0';
        const option = document.createElement('div');
        option.className = 'survey-option';
        option.innerHTML = `<input class="form-control form-control-sm survey-form-control" name="questions[${index}][options][]" required maxlength="255" placeholder="Nová možnost"><button type="button" class="survey-option__remove" data-remove-option title="Odstranit možnost"><i class="fas fa-xmark"></i></button>`;
        options.insertBefore(option, addButton);
    };

    addQuestionButton.addEventListener('click', () => {
        const question = document.createElement('div');
        question.className = 'survey-question';
        question.dataset.question = '';
        question.innerHTML = `<div class="survey-question__head"><div class="d-flex align-items-center gap-2"><span class="survey-question__number" data-question-number></span><span class="survey-question__title">Otázka</span></div><button type="button" class="survey-question__remove" data-remove-question title="Odstranit otázku"><i class="fas fa-trash-can"></i></button></div><input class="form-control survey-form-control" name="questions[${questionIndex}][text]" required maxlength="2000" placeholder="Např. Co byste chtěli zlepšit?"><div class="row g-2 mt-1"><div class="col-sm-8"><label class="form-label survey-form-label">Typ odpovědi</label><select class="form-select form-select-sm survey-form-control" name="questions[${questionIndex}][type]" data-question-type><option value="single_choice">Výběr jedné možnosti</option><option value="multiple_choice">Výběr více možností</option><option value="textarea">Volná textová odpověď</option></select></div></div><div class="survey-options" data-options><div class="survey-option"><input class="form-control form-control-sm survey-form-control" name="questions[${questionIndex}][options][]" required maxlength="255" placeholder="Možnost 1"><button type="button" class="survey-option__remove" data-remove-option title="Odstranit možnost"><i class="fas fa-xmark"></i></button></div><div class="survey-option"><input class="form-control form-control-sm survey-form-control" name="questions[${questionIndex}][options][]" required maxlength="255" placeholder="Možnost 2"><button type="button" class="survey-option__remove" data-remove-option title="Odstranit možnost"><i class="fas fa-xmark"></i></button></div><button type="button" class="survey-add-option align-self-start" data-add-option><i class="fas fa-plus me-1"></i>Přidat možnost</button></div>`;
        builder.appendChild(question);
        questionIndex += 1;
        syncAllQuestionTypes();
        updateQuestionNumbers();
        question.querySelector('input[name*="[text]"]').focus();
    });

    builder.addEventListener('click', (event) => {
        const addButton = event.target.closest('[data-add-option]');
        const removeOptionButton = event.target.closest('[data-remove-option]');
        const removeQuestionButton = event.target.closest('[data-remove-question]');
        const question = event.target.closest('[data-question]');
        if (addButton && question) addOption(question);
        if (removeOptionButton && question) {
            const options = question.querySelectorAll('.survey-option');
            if (options.length > 2) removeOptionButton.closest('.survey-option').remove();
        }
        if (removeQuestionButton && question && builder.querySelectorAll('[data-question]').length > 1) {
            question.remove();
            updateQuestionNumbers();
        }
    });

    builder.addEventListener('change', (event) => {
        if (event.target.matches('[data-question-type]')) syncQuestionType(event.target.closest('[data-question]'));
    });
    surveyType.addEventListener('change', syncAllQuestionTypes);
    syncAllQuestionTypes();
    updateQuestionNumbers();
})();
</script>
<?php endif; ?>
    </div>
<?php renderAdminFooter(); ?>