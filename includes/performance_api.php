<?php
declare(strict_types=1);
require_once __DIR__ . '/performance.php';

function performanceApiData(PDO $pdo, array $viewer, array $query): array {
    foreach ($query as $value) if (!is_scalar($value)) throw new InvalidArgumentException('Neplatné parametry přehledu.');
    $date = performanceDate((string)($query['date'] ?? date('Y-m-d')));
    if (isset($query['id'])) return ['report' => performanceReport($pdo, $viewer, (int)$query['id'])];
    if (isset($query['attempt_id'])) return ['report' => performanceAttemptReport($pdo, $viewer, (int)$query['attempt_id'])];
    $context = performanceContext($pdo, $viewer, $date);
    $load = performanceCan($viewer, 'load') ? performanceLoad($context['activities'], $date) : null;
    $recovery = performanceCan($viewer, 'recovery') ? performanceRecovery($context['daily']) : null;
    $data = [
        'athlete_id' => $viewer['athlete_id'], 'date' => $date, 'version' => PERFORMANCE_VERSION,
        'context' => $context, 'load' => $load, 'recovery' => $recovery,
        'history' => performanceHistory($pdo, $viewer),
        'intelligence' => performanceIntelligence($context, $load ?? performanceLoad([], $date), $recovery ?? performanceRecovery([])),
    ];
    if ($recovery !== null) $data['recovery_trend'] = performanceRecoveryTrend($pdo, $viewer, $date);
    if (performanceCan($viewer, 'strength')) $data['strength_series'] = performanceStrengthSeries($pdo, $viewer, $date);
    if (performanceCan($viewer, 'hydration')) $data['sweat_tests'] = performanceRows($pdo, 'SELECT id, entry_date, inputs_json, results_json FROM athlete_sweat_tests WHERE athlete_id = ? ORDER BY entry_date DESC, id DESC LIMIT 100', [$viewer['athlete_id']]);
    if (performanceCan($viewer, 'hyrox')) {
        try {
            $data['hyrox_standards'] = performanceStandards($pdo, $date, (string)($query['division'] ?? 'open'), (string)($query['gender'] ?? 'male'));
            if (!$data['hyrox_standards']) $data['hyrox_standard_error'] = 'Platné úplné standardy nejsou dostupné. HYROX výpočet je zablokovaný.';
        } catch (InvalidArgumentException $e) {
            $data['hyrox_standards'] = [];
            $data['hyrox_standard_error'] = $e->getMessage();
        }
        $data['hyrox_attempts'] = performanceRows($pdo, 'SELECT id, entry_date, category, standard_json, total_seconds, activity_key FROM athlete_hyrox_attempts WHERE athlete_id = ? ORDER BY entry_date DESC, id DESC LIMIT 100', [$viewer['athlete_id']]);
        foreach ($data['hyrox_attempts'] as &$attempt) $attempt['splits'] = performanceRows($pdo, 'SELECT segment_key, seconds FROM athlete_hyrox_splits WHERE attempt_id = ? ORDER BY segment_key', [$attempt['id']]);
        unset($attempt);
    }
    return $data;
}

function performanceApiRespond(string $type, int $actorId, bool $csrfRequired): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, ['GET', 'POST'], true)) {
            header('Allow: GET, POST');
            http_response_code(405);
            echo performanceJson(['success' => false, 'error' => 'Povolené metody: GET, POST.']);
            exit;
        }
        $input = $_GET;
        if ($method === 'POST') {
            if (str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
                $input = json_decode((string)file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Očekáván JSON objekt.');
            } else $input = $_POST;
            if ($csrfRequired && !verifyCsrf(is_string($input['csrf_token'] ?? null) ? $input['csrf_token'] : '')) throw new DomainException('Neplatný bezpečnostní token.');
        }
        performanceValidateInput($input);
        $pdo = getDB();
        $athleteId = $type === 'athlete' ? $actorId : intParam($input, 'athlete_id');
        $viewer = performanceViewer($pdo, $type, $actorId, $athleteId);
        $data = $method === 'GET' ? performanceApiData($pdo, $viewer, $input) : performanceAction($pdo, $viewer, $input);
        echo performanceJson(['success' => true, 'data' => $data]);
    } catch (DomainException $e) {
        http_response_code(403);
        echo performanceJson(['success' => false, 'error' => $e->getMessage()]);
    } catch (InvalidArgumentException | JsonException $e) {
        http_response_code(422);
        echo performanceJson(['success' => false, 'error' => $e instanceof JsonException ? 'Neplatný JSON.' : $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('performance API: ' . $e->getMessage());
        http_response_code(503);
        echo performanceJson(['success' => false, 'error' => 'Data nejsou dostupná. Ověřte migraci nebo kontaktujte administrátora.']);
    }
    exit;
}
