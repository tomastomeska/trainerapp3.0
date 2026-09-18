<?php

function surveySchemaAvailable(PDO $pdo): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'surveys'");
        $available = $stmt !== false && (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

function surveyAudienceMatches(string $audience, string $role): bool
{
    return $audience === 'all'
        || ($audience === 'athletes' && $role === 'athlete')
        || ($audience === 'coaches' && $role === 'coach');
}

function surveyFetchForUser(PDO $pdo, string $role, int $userId, bool $includeArchived = false): array
{
    if (!surveySchemaAvailable($pdo)) {
        return [];
    }

    $audience = $role === 'athlete' ? 'athletes' : 'coaches';
    $statusSql = $includeArchived ? "s.status IN ('published', 'ended')" : "s.status = 'published' AND (s.expires_at IS NULL OR s.expires_at >= NOW())";
    $stmt = $pdo->prepare(
        "SELECT s.*, r.id AS response_id, r.submitted_at
         FROM surveys s
         LEFT JOIN survey_responses r ON r.survey_id = s.id AND r.user_type = ? AND r.user_id = ?
         WHERE {$statusSql} AND s.audience IN ('all', ?)
         ORDER BY CASE WHEN r.id IS NULL THEN 0 ELSE 1 END, s.expires_at IS NULL, s.expires_at ASC, s.created_at DESC"
    );
    $stmt->execute([$role, $userId, $audience]);
    return $stmt->fetchAll();
}

function surveyFetchById(PDO $pdo, int $surveyId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM surveys WHERE id = ? LIMIT 1');
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch();
    if (!$survey) {
        return null;
    }

    $questionStmt = $pdo->prepare('SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC');
    $questionStmt->execute([$surveyId]);
    $survey['questions'] = $questionStmt->fetchAll();

    foreach ($survey['questions'] as &$question) {
        $optionStmt = $pdo->prepare('SELECT * FROM survey_options WHERE question_id = ? ORDER BY sort_order ASC, id ASC');
        $optionStmt->execute([(int)$question['id']]);
        $question['options'] = $optionStmt->fetchAll();
    }

    return $survey;
}

function surveyFetchResponse(PDO $pdo, int $surveyId, string $role, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM survey_responses WHERE survey_id = ? AND user_type = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$surveyId, $role, $userId]);
    $response = $stmt->fetch();
    if (!$response) {
        return null;
    }

    $answerStmt = $pdo->prepare('SELECT * FROM survey_answers WHERE response_id = ?');
    $answerStmt->execute([(int)$response['id']]);
    $response['answers'] = $answerStmt->fetchAll();
    return $response;
}

function surveyCountCompleted(PDO $pdo, int $surveyId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM survey_responses WHERE survey_id = ?');
    $stmt->execute([$surveyId]);
    return (int)$stmt->fetchColumn();
}

function surveyQuestionStats(PDO $pdo, int $questionId): array
{
    $stmt = $pdo->prepare(
        'SELECT o.id, o.option_text, COUNT(a.id) AS answer_count
         FROM survey_options o
         LEFT JOIN survey_answers a ON a.option_id = o.id
         WHERE o.question_id = ?
         GROUP BY o.id, o.option_text, o.sort_order
         ORDER BY o.sort_order ASC, o.id ASC'
    );
    $stmt->execute([$questionId]);
    return $stmt->fetchAll();
}

function surveySupportsMultipleChoice(PDO $pdo): bool
{
    try {
        $column = $pdo->query("SHOW COLUMNS FROM survey_questions LIKE 'question_type'")->fetch();
        return is_array($column) && strpos((string)($column['Type'] ?? ''), 'multiple_choice') !== false;
    } catch (Throwable $e) {
        return false;
    }
}