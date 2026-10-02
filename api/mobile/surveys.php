<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/surveys.php';

function mobileSurveyUser(): array
{
    $token = mobileBearerToken();
    if ($token === '') {
        mobileJson(['success' => false, 'error' => 'Chybí přístupový token.'], 401);
    }

    $pdo = getDB();
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT account_type
         FROM mobile_api_tokens
         WHERE token_hash = ?
           AND revoked_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $type = (string)$stmt->fetchColumn();
    if ($type === 'coach') {
        $coach = mobileRequireCoach();
        return ['role' => 'coach', 'id' => (int)$coach['id']];
    }
    if ($type === 'athlete') {
        $athlete = mobileRequireAthlete();
        return ['role' => 'athlete', 'id' => (int)$athlete['id']];
    }

    mobileJson(['success' => false, 'error' => 'Neplatný nebo prošlý přístupový token.'], 401);
}

function mobileSurveyClosed(array $survey): bool
{
    if ((string)($survey['status'] ?? '') === 'ended') {
        return true;
    }
    $expires = $survey['expires_at'] ?? null;
    return $expires !== null && $expires !== '' && strtotime((string)$expires) < time();
}

function mobileSurveyRequiresSubmit(array $survey): bool
{
    if ((string)($survey['survey_type'] ?? '') !== 'poll') {
        return true;
    }
    foreach ($survey['questions'] ?? [] as $question) {
        $type = (string)($question['question_type'] ?? '');
        if ($type === 'textarea' || $type === 'text' || $type === 'multiple_choice') {
            return true;
        }
    }
    return false;
}

function mobileSurveyAccessible(array $survey, string $role): bool
{
    $status = (string)($survey['status'] ?? '');
    if (!in_array($status, ['published', 'ended'], true)) {
        return false;
    }
    return surveyAudienceMatches((string)($survey['audience'] ?? ''), $role);
}

function mobileSurveyListItem(array $survey): array
{
    return [
        'id' => (int)$survey['id'],
        'title' => (string)$survey['title'],
        'description' => (string)($survey['description'] ?? ''),
        'survey_type' => (string)$survey['survey_type'],
        'expires_at' => $survey['expires_at'] !== null && $survey['expires_at'] !== '' ? (string)$survey['expires_at'] : '',
        'closed' => mobileSurveyClosed($survey),
        'answered' => !empty($survey['response_id']),
        'submitted_at' => !empty($survey['submitted_at']) ? (string)$survey['submitted_at'] : '',
    ];
}

function mobileSurveyDetail(PDO $pdo, array $survey, string $role, int $userId): array
{
    $response = surveyFetchResponse($pdo, (int)$survey['id'], $role, $userId);
    $questions = [];
    foreach ($survey['questions'] ?? [] as $question) {
        $options = [];
        foreach ($question['options'] ?? [] as $option) {
            $options[] = [
                'id' => (int)$option['id'],
                'option_text' => (string)$option['option_text'],
            ];
        }
        $questions[] = [
            'id' => (int)$question['id'],
            'question_text' => (string)$question['question_text'],
            'question_type' => (string)$question['question_type'],
            'options' => $options,
        ];
    }

    $answers = [];
    if ($response) {
        foreach ($response['answers'] ?? [] as $answer) {
            $answers[] = [
                'question_id' => (int)$answer['question_id'],
                'option_id' => $answer['option_id'] !== null ? (int)$answer['option_id'] : 0,
                'answer_text' => (string)($answer['answer_text'] ?? ''),
            ];
        }
    }

    $results = [];
    if ($response && (string)$survey['survey_type'] === 'poll') {
        $total = surveyCountCompleted($pdo, (int)$survey['id']);
        foreach ($survey['questions'] ?? [] as $question) {
            $options = [];
            foreach (surveyQuestionStats($pdo, (int)$question['id']) as $stat) {
                $count = (int)$stat['answer_count'];
                $options[] = [
                    'option_text' => (string)$stat['option_text'],
                    'count' => $count,
                    'percentage' => $total > 0 ? (int)round(($count / $total) * 100) : 0,
                ];
            }
            $results[] = [
                'question_id' => (int)$question['id'],
                'question_text' => (string)$question['question_text'],
                'options' => $options,
            ];
        }
    }

    return [
        'id' => (int)$survey['id'],
        'title' => (string)$survey['title'],
        'description' => (string)($survey['description'] ?? ''),
        'survey_type' => (string)$survey['survey_type'],
        'expires_at' => $survey['expires_at'] !== null && $survey['expires_at'] !== '' ? (string)$survey['expires_at'] : '',
        'closed' => mobileSurveyClosed($survey),
        'requires_submit' => mobileSurveyRequiresSubmit($survey),
        'submitted_at' => $response ? (string)$response['submitted_at'] : '',
        'questions' => $questions,
        'answers' => $answers,
        'results' => $results,
    ];
}

$user = mobileSurveyUser();
$pdo = getDB();
$input = mobileInput();
$action = (string)($_REQUEST['action'] ?? $input['action'] ?? '');
$role = (string)$user['role'];
$userId = (int)$user['id'];

if (!surveySchemaAvailable($pdo)) {
    mobileJson(['success' => true, 'available' => false, 'surveys' => []]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submit') {
    $surveyId = (int)($input['survey_id'] ?? 0);
    $survey = surveyFetchById($pdo, $surveyId);
    if (!$survey || !mobileSurveyAccessible($survey, $role)) {
        mobileJson(['success' => false, 'error' => 'K tomuto šetření nemáte přístup.'], 404);
    }
    if ((string)$survey['status'] !== 'published' || mobileSurveyClosed($survey)) {
        mobileJson(['success' => false, 'error' => 'Toto šetření už není aktivní.'], 409);
    }
    if (surveyFetchResponse($pdo, $surveyId, $role, $userId)) {
        mobileJson(['success' => false, 'error' => 'Toto šetření už máte vyplněné.'], 409);
    }

    $posted = $input['answers'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    $answers = [];
    $error = null;
    foreach ($survey['questions'] as $question) {
        $questionId = (int)$question['id'];
        $raw = $posted[(string)$questionId] ?? null;
        if (!is_array($raw)) {
            $error = 'Vyplňte všechny otázky.';
            break;
        }
        $type = (string)$question['question_type'];
        if (in_array($type, ['single_choice', 'multiple_choice'], true)) {
            $selected = isset($raw['option_ids']) && is_array($raw['option_ids'])
                ? array_values(array_unique(array_map('intval', $raw['option_ids'])))
                : [];
            $selected = array_values(array_filter($selected, static fn(int $id): bool => $id > 0));
            if ($selected === []) {
                $error = 'Vyplňte všechny otázky.';
                break;
            }
            if ($type === 'single_choice' && count($selected) !== 1) {
                $error = 'Vyberte právě jednu možnost.';
                break;
            }
            $optionIds = array_map(static fn(array $option): int => (int)$option['id'], $question['options'] ?? []);
            if (array_diff($selected, $optionIds) !== []) {
                $error = 'Vyberte platnou možnost.';
                break;
            }
            foreach ($selected as $selectedId) {
                $answers[] = [$questionId, $selectedId, null];
            }
        } else {
            $text = trim((string)($raw['text'] ?? ''));
            if ($text === '') {
                $error = 'Vyplňte všechny otázky.';
                break;
            }
            $answers[] = [$questionId, null, mb_substr($text, 0, 10000, 'UTF-8')];
        }
    }

    $answeredQuestionIds = array_unique(array_map(static fn(array $answer): int => (int)$answer[0], $answers));
    if ($error === null && count($answeredQuestionIds) !== count($survey['questions'])) {
        $error = 'Šetření neobsahuje správně vyplněné otázky.';
    }
    if ($error !== null) {
        mobileJson(['success' => false, 'error' => $error], 400);
    }

    try {
        $pdo->beginTransaction();
        $responseStmt = $pdo->prepare('INSERT INTO survey_responses (survey_id, user_type, user_id) VALUES (?, ?, ?)');
        $responseStmt->execute([$surveyId, $role, $userId]);
        $responseId = (int)$pdo->lastInsertId();
        $answerStmt = $pdo->prepare('INSERT INTO survey_answers (response_id, question_id, option_id, answer_text) VALUES (?, ?, ?, ?)');
        foreach ($answers as [$questionId, $optionId, $answerText]) {
            $answerStmt->execute([$responseId, $questionId, $optionId, $answerText]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Mobile survey response save error: ' . $e->getMessage());
        $message = str_contains(strtolower($e->getMessage()), 'duplicate')
            ? 'Toto šetření už máte vyplněné.'
            : 'Odpověď se nepodařilo uložit. Zkuste to prosím znovu.';
        mobileJson(['success' => false, 'error' => $message], 409);
    }

    $saved = surveyFetchById($pdo, $surveyId);
    mobileJson(['success' => true, 'survey' => mobileSurveyDetail($pdo, $saved ?: $survey, $role, $userId)]);
}

$surveyId = (int)($_GET['id'] ?? $input['id'] ?? 0);
if ($surveyId > 0) {
    $survey = surveyFetchById($pdo, $surveyId);
    if (!$survey || !mobileSurveyAccessible($survey, $role)) {
        mobileJson(['success' => false, 'error' => 'K tomuto šetření nemáte přístup.'], 404);
    }
    mobileJson(['success' => true, 'available' => true, 'survey' => mobileSurveyDetail($pdo, $survey, $role, $userId)]);
}

$items = [];
$source = surveyFetchForUser($pdo, $role, $userId, true);
foreach ($source as $survey) {
    $items[] = mobileSurveyListItem($survey);
}
$revisionParts = [];
foreach ($source as $survey) {
    $revisionParts[] = (int)$survey['id'] . '|' . (string)$survey['title'] . '|' . (string)$survey['status'] . '|' . (string)($survey['description'] ?? '') . '|' . (string)($survey['expires_at'] ?? '');
    try {
        $questions = $pdo->prepare('SELECT id, question_text, question_type FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC');
        $questions->execute([(int)$survey['id']]);
        foreach ($questions->fetchAll() as $question) {
            $revisionParts[] = 'q' . (int)$question['id'] . '|' . (string)$question['question_text'] . '|' . (string)$question['question_type'];
        }
    } catch (Throwable $e) {
    }
}
mobileJson([
    'success' => true,
    'available' => true,
    'revision' => md5(implode("\n", $revisionParts)),
    'surveys' => $items,
]);
