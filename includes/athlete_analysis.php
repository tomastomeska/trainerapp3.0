<?php
// includes/athlete_analysis.php – databázová vrstva modulu Analýza sportovce.
require_once __DIR__ . '/athlete_analysis_calc.php';

if (!function_exists('analysisEnsureSchema')) {
    function analysisEnsureSchema(PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        if (!calculatorEnsureSchema($pdo)) {
            return $ready = false;
        }
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `athlete_analyses` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `athlete_id` INT NOT NULL,
                    `status` ENUM('draft','final') NOT NULL DEFAULT 'draft',
                    `created_by_type` ENUM('athlete','coach') NOT NULL DEFAULT 'athlete',
                    `created_by_id` INT NOT NULL,
                    `current_step` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    `inputs_json` MEDIUMTEXT NULL,
                    `results_json` MEDIUMTEXT NULL,
                    `coach_targets_json` TEXT NULL,
                    `coach_note` TEXT NULL,
                    `coach_note_updated_at` DATETIME NULL,
                    `calc_result_id` INT UNSIGNED NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `completed_at` DATETIME NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_analyses_athlete_status` (`athlete_id`, `status`, `completed_at`),
                    KEY `idx_analyses_draft` (`athlete_id`, `status`, `created_by_type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $ready = true;
        } catch (Throwable $e) {
            error_log('analysisEnsureSchema: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('analysisDecodeRow')) {
    function analysisDecodeRow(array $row): array {
        foreach (['inputs_json' => 'inputs', 'results_json' => 'results', 'coach_targets_json' => 'coach_targets'] as $col => $key) {
            $decoded = json_decode((string)($row[$col] ?? ''), true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }
        $row['id'] = (int)$row['id'];
        $row['athlete_id'] = (int)$row['athlete_id'];
        $row['current_step'] = (int)$row['current_step'];
        $row['metrics'] = $row['results'] ? analysisMetrics($row['results']) : [];
        $row['date'] = substr((string)($row['completed_at'] ?: $row['created_at']), 0, 10);
        return $row;
    }

    function analysisJson(array $data): string {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}

if (!function_exists('analysisGetDraft')) {
    function analysisGetDraft(PDO $pdo, int $athleteId, string $creatorType): ?array {
        if (!analysisEnsureSchema($pdo)) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM athlete_analyses WHERE athlete_id = ? AND status = 'draft' AND created_by_type = ? ORDER BY updated_at DESC, id DESC LIMIT 1");
            $stmt->execute([$athleteId, $creatorType === 'coach' ? 'coach' : 'athlete']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? analysisDecodeRow($row) : null;
        } catch (Throwable $e) {
            error_log('analysisGetDraft: ' . $e->getMessage());
            return null;
        }
    }

    /** Uloží rozpracovaný dotazník (jeden koncept na sportovce a typ autora). */
    function analysisSaveDraft(PDO $pdo, int $athleteId, string $creatorType, int $creatorId, array $inputs, int $step): int {
        if (!analysisEnsureSchema($pdo)) {
            return 0;
        }
        $creatorType = $creatorType === 'coach' ? 'coach' : 'athlete';
        $step = max(1, min(6, $step));
        try {
            $draft = analysisGetDraft($pdo, $athleteId, $creatorType);
            if ($draft) {
                $pdo->prepare('UPDATE athlete_analyses SET inputs_json = ?, current_step = ?, created_by_id = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([analysisJson($inputs), $step, $creatorId, $draft['id']]);
                return $draft['id'];
            }
            $pdo->prepare("INSERT INTO athlete_analyses (athlete_id, status, created_by_type, created_by_id, current_step, inputs_json, created_at, updated_at) VALUES (?, 'draft', ?, ?, ?, ?, NOW(), NOW())")
                ->execute([$athleteId, $creatorType, $creatorId, $step, analysisJson($inputs)]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('analysisSaveDraft: ' . $e->getMessage());
            return 0;
        }
    }

    function analysisDeleteDraft(PDO $pdo, int $athleteId, string $creatorType): void {
        if (!analysisEnsureSchema($pdo)) {
            return;
        }
        try {
            $pdo->prepare("DELETE FROM athlete_analyses WHERE athlete_id = ? AND status = 'draft' AND created_by_type = ?")
                ->execute([$athleteId, $creatorType === 'coach' ? 'coach' : 'athlete']);
        } catch (Throwable $e) {
            error_log('analysisDeleteDraft: ' . $e->getMessage());
        }
    }
}

if (!function_exists('analysisCalcResultPayload')) {
    /** Souhrn analýzy uložený do athlete_calculator_results (historie Kalkulaček, odznaky trenéra, tisk). */
    function analysisCalcResultPayload(int $analysisId, array $inputs, array $results): array {
        return [
            'analysis_id' => $analysisId,
            'weight_kg' => $results['body']['weight_kg'] ?? ($inputs['weight_kg'] ?? null),
            'height_cm' => $results['body']['height_cm'] ?? ($inputs['height_cm'] ?? null),
            'goal' => $inputs['goal'] ?? null,
            'bmr' => $results['metabolism']['bmr'] ?? null,
            'tdee' => $results['metabolism']['tdee'] ?? null,
            'kcal' => $results['calories']['kcal'] ?? null,
            'protein_g' => $results['macros']['protein_g'] ?? null,
            'carbs_g' => $results['macros']['carbs_g'] ?? null,
            'fat_g' => $results['macros']['fat_g'] ?? null,
        ];
    }

    function analysisCalcResultLabel(array $inputs, array $results): string {
        $goal = analysisGoalShortLabels()[$inputs['goal'] ?? ''] ?? 'Analýza';
        if (!empty($results['calories']['blocked'])) {
            return $goal . ' · bez kalorického cíle';
        }
        return $goal . (isset($results['calories']['kcal']) ? ' · ' . number_format((float)$results['calories']['kcal'], 0, ',', ' ') . ' kcal' : '');
    }
}

if (!function_exists('analysisFinalize')) {
    /**
     * Uloží dokončenou analýzu jako nový záznam (historie zůstává zachována) a zapíše souhrn do výsledků Kalkulaček.
     */
    function analysisFinalize(PDO $pdo, int $athleteId, string $creatorType, int $creatorId, array $inputs, array $results, array $coachTargets = []): int {
        if (!analysisEnsureSchema($pdo)) {
            return 0;
        }
        $creatorType = $creatorType === 'coach' ? 'coach' : 'athlete';
        try {
            $pdo->beginTransaction();
            $draft = analysisGetDraft($pdo, $athleteId, $creatorType);
            if ($draft) {
                $pdo->prepare("UPDATE athlete_analyses SET status = 'final', created_by_id = ?, current_step = 6, inputs_json = ?, results_json = ?, coach_targets_json = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?")
                    ->execute([$creatorId, analysisJson($inputs), analysisJson($results), $coachTargets ? analysisJson($coachTargets) : null, $draft['id']]);
                $id = $draft['id'];
            } else {
                $pdo->prepare("INSERT INTO athlete_analyses (athlete_id, status, created_by_type, created_by_id, current_step, inputs_json, results_json, coach_targets_json, created_at, updated_at, completed_at) VALUES (?, 'final', ?, ?, 6, ?, ?, ?, NOW(), NOW(), NOW())")
                    ->execute([$athleteId, $creatorType, $creatorId, analysisJson($inputs), analysisJson($results), $coachTargets ? analysisJson($coachTargets) : null]);
                $id = (int)$pdo->lastInsertId();
            }
            $kcal = isset($results['calories']['kcal']) ? (float)$results['calories']['kcal'] : null;
            $calcId = calculatorSaveResult($pdo, $athleteId, 'analysis', analysisCalcResultPayload($id, $inputs, $results), $kcal, analysisCalcResultLabel($inputs, $results));
            if ($calcId > 0) {
                $pdo->prepare('UPDATE athlete_analyses SET calc_result_id = ? WHERE id = ?')->execute([$calcId, $id]);
                if ($creatorType === 'coach') {
                    $pdo->prepare('UPDATE athlete_calculator_results SET coach_viewed_at = NOW() WHERE id = ?')->execute([$calcId]);
                }
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('analysisFinalize: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('analysisFetchFinals')) {
    /** Dokončené analýzy sportovce od nejnovější. */
    function analysisFetchFinals(PDO $pdo, int $athleteId, int $limit = 100): array {
        if (!analysisEnsureSchema($pdo)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM athlete_analyses WHERE athlete_id = ? AND status = 'final' ORDER BY completed_at DESC, id DESC LIMIT " . max(1, min(500, $limit)));
            $stmt->execute([$athleteId]);
            return array_map('analysisDecodeRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('analysisFetchFinals: ' . $e->getMessage());
            return [];
        }
    }

    function analysisFetch(PDO $pdo, int $id, int $athleteId): ?array {
        if (!analysisEnsureSchema($pdo) || $id <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM athlete_analyses WHERE id = ? AND athlete_id = ? AND status = 'final' LIMIT 1");
            $stmt->execute([$id, $athleteId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? analysisDecodeRow($row) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    function analysisDelete(PDO $pdo, int $id, int $athleteId): bool {
        $row = analysisFetch($pdo, $id, $athleteId);
        if (!$row) {
            return false;
        }
        try {
            $pdo->prepare('DELETE FROM athlete_analyses WHERE id = ? AND athlete_id = ?')->execute([$id, $athleteId]);
            if (!empty($row['calc_result_id'])) {
                calculatorDeleteResult($pdo, (int)$row['calc_result_id'], $athleteId);
            }
            return true;
        } catch (Throwable $e) {
            error_log('analysisDelete: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('analysisSaveCoachReview')) {
    /**
     * Uloží poznámku a upravené cíle trenéra; výsledky se přepočítají s úpravami trenéra.
     *
     * @param array{kcal?:?int, protein_g?:?int, fat_g?:?int} $targets
     */
    function analysisSaveCoachReview(PDO $pdo, int $id, int $athleteId, array $targets, string $note): bool {
        $row = analysisFetch($pdo, $id, $athleteId);
        if (!$row) {
            return false;
        }
        $clean = [];
        foreach (['kcal' => [1000, 6000], 'protein_g' => [20, 400], 'fat_g' => [15, 250]] as $key => [$min, $max]) {
            $v = isset($targets[$key]) ? (int)$targets[$key] : 0;
            if ($v >= $min && $v <= $max) {
                $clean[$key] = $v;
            }
        }
        $results = analysisBuild($row['inputs'], $clean, substr((string)$row['completed_at'], 0, 10) ?: null);
        try {
            $pdo->prepare('UPDATE athlete_analyses SET coach_targets_json = ?, results_json = ?, coach_note = ?, coach_note_updated_at = NOW() WHERE id = ? AND athlete_id = ?')
                ->execute([$clean ? analysisJson($clean) : null, analysisJson($results), mb_substr(trim($note), 0, 5000) ?: null, $id, $athleteId]);
            if (!empty($row['calc_result_id'])) {
                $pdo->prepare('UPDATE athlete_calculator_results SET inputs_json = ?, result_value = ?, result_label = ? WHERE id = ? AND athlete_id = ?')
                    ->execute([
                        analysisJson(analysisCalcResultPayload($id, $row['inputs'], $results)),
                        $results['calories']['kcal'] ?? null,
                        mb_substr(analysisCalcResultLabel($row['inputs'], $results), 0, 120),
                        (int)$row['calc_result_id'],
                        $athleteId,
                    ]);
            }
            return true;
        } catch (Throwable $e) {
            error_log('analysisSaveCoachReview: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('analysisCoachOwnsAthlete')) {
    function analysisCoachOwnsAthlete(PDO $pdo, int $coachId, int $athleteId): bool {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
            $stmt->execute([$athleteId, $coachId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Trenér vidí analýzu, pokud je to jeho sportovec a ten sdílí výsledky, nebo analýzu vytvořil trenér. */
    function analysisCoachCanViewRow(PDO $pdo, array $row): bool {
        return ($row['created_by_type'] ?? '') === 'coach' || calculatorGetAthleteSettings($pdo, (int)$row['athlete_id'])['share_with_coach'];
    }

    /** Filtr seznamu analýz pro trenéra. */
    function analysisFilterForCoach(PDO $pdo, int $athleteId, array $rows): array {
        if (calculatorGetAthleteSettings($pdo, $athleteId)['share_with_coach']) {
            return $rows;
        }
        return array_values(array_filter($rows, static fn(array $r): bool => ($r['created_by_type'] ?? '') === 'coach'));
    }
}

if (!function_exists('analysisPrefill')) {
    /**
     * Předvyplnění dotazníku z profilu sportovce a poslední analýzy (žádná duplicitní data).
     *
     * @return array{inputs: array<string, mixed>, sources: array<string, string>}
     */
    function analysisPrefill(PDO $pdo, int $athleteId): array {
        $inputs = [];
        $sources = [];
        $last = analysisFetchFinals($pdo, $athleteId, 1)[0] ?? null;
        if ($last) {
            $inputs = $last['inputs'];
            unset($inputs['save_weight']);
            $sources['last'] = 'Předvyplněno z analýzy ze dne ' . date('j. n. Y', strtotime($last['date']));
        }
        try {
            $stmt = $pdo->prepare('SELECT birth_date, gender FROM athletes WHERE id = ? LIMIT 1');
            $stmt->execute([$athleteId]);
            $athlete = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $athlete = [];
        }
        $age = calculateAge($athlete['birth_date'] ?? null);
        if ($age !== null && $age > 0) {
            $inputs['age'] = $age;
            $sources['age'] = 'z data narození v profilu';
        }
        if (in_array($athlete['gender'] ?? '', ['male', 'female'], true)) {
            $inputs['sex'] = $athlete['gender'];
            $sources['sex'] = 'z profilu';
        }
        $height = calculatorFetchAthleteHeightCm($pdo, $athleteId);
        if (!empty($height['height_cm'])) {
            $inputs['height_cm'] = (float)$height['height_cm'];
            $sources['height_cm'] = 'z profilu';
        }
        $weight = getAthleteWeightStats($athleteId);
        if ($weight['current_weight'] !== null) {
            $inputs['weight_kg'] = (float)$weight['current_weight'];
            $inputs['weigh_date'] = substr((string)$weight['current_date'], 0, 10);
            $sources['weight_kg'] = 'poslední vážení ' . date('j. n. Y', strtotime((string)$weight['current_date']));
        }
        return ['inputs' => $inputs, 'sources' => $sources, 'weight_stats' => $weight];
    }
}

if (!function_exists('analysisLatestTargets')) {
    /**
     * Aktuální cíle z poslední dokončené analýzy – pro jídelníčky, stravu a kalendář.
     *
     * @return array<string, mixed>|null
     */
    function analysisLatestTargets(PDO $pdo, int $athleteId): ?array {
        $last = analysisFetchFinals($pdo, $athleteId, 1)[0] ?? null;
        if (!$last || empty($last['results']['calories']['kcal']) || empty($last['results']['macros'])) {
            return null;
        }
        $r = $last['results'];
        return [
            'analysis_id' => $last['id'],
            'date' => $last['date'],
            'created_by_type' => $last['created_by_type'],
            'goal' => $last['inputs']['goal'] ?? null,
            'kcal' => (int)$r['calories']['kcal'],
            'protein_g' => (int)$r['macros']['protein_g'],
            'carbs_g' => (int)$r['macros']['carbs_g'],
            'fat_g' => (int)$r['macros']['fat_g'],
            'fiber_g' => (int)$r['macros']['fiber_g'],
            'hydration_ml' => (int)($r['hydration']['avg_ml'] ?? 0),
            'day_types' => $r['day_types'] ?? null,
            'coach_applied' => $r['coach_applied'] ?? [],
        ];
    }
}

if (!function_exists('analysisFoodDiaryTotals')) {
    /**
     * Skutečný příjem ze Stravy (evidence jídel) po dnech.
     *
     * @return array<string, array{kcal:float, protein:float, carbs:float, fat:float, fiber:float, items:int, hydration_ml:int}>
     */
    function analysisFoodDiaryTotals(PDO $pdo, int $athleteId, string $fromDate, string $toDate): array {
        $out = [];
        try {
            $stmt = $pdo->prepare(
                'SELECT d.date, d.hydration_ml,
                        COALESCE(SUM(i.calories), 0) AS kcal, COALESCE(SUM(i.protein), 0) AS protein,
                        COALESCE(SUM(i.carbohydrates), 0) AS carbs, COALESCE(SUM(i.fat), 0) AS fat,
                        COALESCE(SUM(i.fiber), 0) AS fiber, COUNT(i.id) AS items
                 FROM food_diary_days d
                 LEFT JOIN food_diary_meals m ON m.day_id = d.id
                 LEFT JOIN food_diary_items i ON i.meal_id = m.id
                 WHERE d.athlete_id = ? AND d.date BETWEEN ? AND ?
                 GROUP BY d.id, d.date, d.hydration_ml
                 ORDER BY d.date ASC'
            );
            $stmt->execute([$athleteId, $fromDate, $toDate]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(string)$row['date']] = [
                    'kcal' => round((float)$row['kcal']),
                    'protein' => round((float)$row['protein'], 1),
                    'carbs' => round((float)$row['carbs'], 1),
                    'fat' => round((float)$row['fat'], 1),
                    'fiber' => round((float)$row['fiber'], 1),
                    'items' => (int)$row['items'],
                    'hydration_ml' => (int)($row['hydration_ml'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            error_log('analysisFoodDiaryTotals: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('analysisMealPlanTotals')) {
    /**
     * Plánovaný příjem z přiřazených jídelníčků po dnech v týdnu.
     * Jídla v databázi mají jen bílkoviny, tuky, cukry a vlákninu na 100 g (bez kalorií a celkových sacharidů).
     *
     * @return list<array{plan_id:int, plan_name:string, days: array<string, array{protein:float, fat:float, fiber:float, sugars:float, items:int}>}>
     */
    function analysisMealPlanTotals(PDO $pdo, int $athleteId): array {
        $plans = [];
        try {
            $stmt = $pdo->prepare(
                'SELECT p.id AS plan_id, p.name AS plan_name, i.day_of_week,
                        SUM(COALESCE(i.grams, m.grams, 0) * COALESCE(m.protein_per_100g, 0) / 100) AS protein,
                        SUM(COALESCE(i.grams, m.grams, 0) * COALESCE(m.fat_per_100g, 0) / 100) AS fat,
                        SUM(COALESCE(i.grams, m.grams, 0) * COALESCE(m.fiber_per_100g, 0) / 100) AS fiber,
                        SUM(COALESCE(i.grams, m.grams, 0) * COALESCE(m.sugars_per_100g, 0) / 100) AS sugars,
                        COUNT(i.id) AS items
                 FROM athlete_meal_plans ap
                 JOIN coach_meal_plans p ON p.id = ap.meal_plan_id
                 JOIN coach_meal_plan_items i ON i.meal_plan_id = p.id
                 LEFT JOIN coach_meals m ON m.id = i.meal_id
                 WHERE ap.athlete_id = ? AND ap.removed_at IS NULL
                 GROUP BY p.id, p.name, i.day_of_week'
            );
            $stmt->execute([$athleteId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pid = (int)$row['plan_id'];
                if (!isset($plans[$pid])) {
                    $plans[$pid] = ['plan_id' => $pid, 'plan_name' => (string)$row['plan_name'], 'days' => []];
                }
                $plans[$pid]['days'][(string)$row['day_of_week']] = [
                    'protein' => round((float)$row['protein'], 1),
                    'fat' => round((float)$row['fat'], 1),
                    'fiber' => round((float)$row['fiber'], 1),
                    'sugars' => round((float)$row['sugars'], 1),
                    'items' => (int)$row['items'],
                ];
            }
        } catch (Throwable $e) {
            error_log('analysisMealPlanTotals: ' . $e->getMessage());
        }
        return array_values($plans);
    }
}

if (!function_exists('analysisUpcomingTrainings')) {
    /**
     * Naplánované tréninky z kalendáře (schválené) v následujících dnech, seskupené podle data.
     *
     * @return array<string, list<array{time:string, minutes:int, title:string}>>
     */
    function analysisUpcomingTrainings(PDO $pdo, int $athleteId, int $days = 14, ?string $fromDate = null): array {
        $from = ($fromDate ?: date('Y-m-d')) . ' 00:00:00';
        $to = date('Y-m-d 23:59:59', strtotime($from . ' +' . max(1, $days - 1) . ' days'));
        $out = [];
        try {
            $stmt = $pdo->prepare(
                "SELECT starts_at, ends_at, title_type, custom_title
                 FROM coach_calendar_events
                 WHERE (athlete_id = ? OR second_athlete_id = ?)
                   AND approval_status = 'approved'
                   AND title_type IN ('training', 'group_lesson')
                   AND starts_at BETWEEN ? AND ?
                 ORDER BY starts_at ASC"
            );
            $stmt->execute([$athleteId, $athleteId, $from, $to]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $start = strtotime((string)$row['starts_at']);
                $end = strtotime((string)$row['ends_at']);
                $out[date('Y-m-d', $start)][] = [
                    'time' => date('G:i', $start),
                    'minutes' => max(0, (int)round(($end - $start) / 60)),
                    'title' => trim((string)($row['custom_title'] ?? '')) ?: ($row['title_type'] === 'group_lesson' ? 'Skupinová lekce' : 'Trénink'),
                ];
            }
        } catch (Throwable $e) {
            error_log('analysisUpcomingTrainings: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('analysisCoachOverview')) {
    /**
     * Přehled sportovců trenéra s poslední viditelnou analýzou (porovnání sportovců).
     *
     * @return list<array<string, mixed>>
     */
    function analysisCoachOverview(PDO $pdo, int $coachId): array {
        if (!analysisEnsureSchema($pdo)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT x.*, a.id AS owner_athlete_id, a.first_name, a.last_name, COALESCE(s.share_with_coach, 1) AS share_with_coach
                 FROM athletes a
                 LEFT JOIN athlete_calculator_settings s ON s.athlete_id = a.id
                 LEFT JOIN athlete_analyses x ON x.id = (
                     SELECT x2.id FROM athlete_analyses x2
                     WHERE x2.athlete_id = a.id AND x2.status = 'final'
                       AND (COALESCE(s.share_with_coach, 1) = 1 OR x2.created_by_type = 'coach')
                     ORDER BY x2.completed_at DESC, x2.id DESC LIMIT 1
                 )
                 WHERE a.coach_id = ?
                 ORDER BY a.last_name, a.first_name"
            );
            $stmt->execute([$coachId]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $item = [
                    'athlete_id' => (int)$row['owner_athlete_id'],
                    'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                    'share' => (int)$row['share_with_coach'] === 1,
                    'analysis' => null,
                ];
                if (!empty($row['id'])) {
                    $row['athlete_id'] = $item['athlete_id'];
                    $item['analysis'] = analysisDecodeRow($row);
                }
                $out[] = $item;
            }
            return $out;
        } catch (Throwable $e) {
            error_log('analysisCoachOverview: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('analysisUpdateInputs')) {
    /** Doplní vstupy existující analýzy (např. test pocení) a přepočítá výsledky se zachováním úprav trenéra. */
    function analysisUpdateInputs(PDO $pdo, int $id, int $athleteId, array $extraInputs): ?array {
        $row = analysisFetch($pdo, $id, $athleteId);
        if (!$row) {
            return null;
        }
        $inputs = array_merge($row['inputs'], $extraInputs);
        $results = analysisBuild($inputs, $row['coach_targets'], substr((string)$row['completed_at'], 0, 10) ?: null);
        try {
            $pdo->prepare('UPDATE athlete_analyses SET inputs_json = ?, results_json = ? WHERE id = ? AND athlete_id = ?')
                ->execute([analysisJson($inputs), analysisJson($results), $id, $athleteId]);
            return $results;
        } catch (Throwable $e) {
            error_log('analysisUpdateInputs: ' . $e->getMessage());
            return null;
        }
    }

    function analysisMarkSeenByCoach(PDO $pdo, int $athleteId): void {
        if (!calculatorEnsureSchema($pdo)) {
            return;
        }
        try {
            $pdo->prepare("UPDATE athlete_calculator_results SET coach_viewed_at = NOW() WHERE athlete_id = ? AND calculator_key = 'analysis' AND coach_viewed_at IS NULL")
                ->execute([$athleteId]);
        } catch (Throwable $e) {
            error_log('analysisMarkSeenByCoach: ' . $e->getMessage());
        }
    }
}

if (!function_exists('analysisSeries')) {
    /**
     * Časové řady metrik z historie analýz (od nejstarší) pro grafy.
     *
     * @return list<array<string, mixed>>
     */
    function analysisSeries(array $finalsDesc): array {
        $points = [];
        foreach (array_reverse($finalsDesc) as $row) {
            $points[] = ['date' => $row['date']] + $row['metrics'];
        }
        return $points;
    }
}
