<?php
// includes/calculators.php – registr a výpočty aplikace Kalkulačky
require_once __DIR__ . '/functions.php';

if (!function_exists('calculatorDefinitions')) {
    /**
     * @return array<string, array{title:string, subtitle:string, icon:string, setting_key:string, url?:string, group?:string, default_enabled?:bool}>
     */
    function calculatorDefinitions(): array {
        return [
            'bmi' => [
                'title' => 'BMI',
                'subtitle' => 'Body Mass Index',
                'icon' => 'fa-weight-scale',
                'setting_key' => 'calculator_bmi_enabled',
            ],
            'analysis' => [
                'title' => 'Analýza sportovce',
                'subtitle' => 'Metabolismus, výživa a trénink',
                'icon' => 'fa-dna',
                'setting_key' => 'calculator_analysis_enabled',
                'url' => 'athlete_analysis.php',
            ],
            'performance' => [
                'title' => 'Výkonnost a regenerace', 'subtitle' => 'Pět funkcí a Coach Intelligence',
                'icon' => 'fa-bolt', 'setting_key' => 'calculator_performance_enabled',
                'url' => 'performance.php', 'default_enabled' => false,
            ],
            'performance_hydration' => [
                'title' => 'Hydratace', 'subtitle' => 'Výkonnost a regenerace', 'icon' => 'fa-droplet',
                'setting_key' => 'calculator_performance_hydration_enabled', 'url' => 'performance.php?module=hydration', 'group' => 'performance',
            ],
            'performance_strength' => [
                'title' => 'Maximální síla (1RM)', 'subtitle' => 'Výkonnost a regenerace', 'icon' => 'fa-dumbbell',
                'setting_key' => 'calculator_performance_strength_enabled', 'url' => 'performance.php?module=strength', 'group' => 'performance',
            ],
            'performance_hyrox' => [
                'title' => 'HYROX', 'subtitle' => 'Výkonnost a regenerace', 'icon' => 'fa-stopwatch',
                'setting_key' => 'calculator_performance_hyrox_enabled', 'url' => 'performance.php?module=hyrox', 'group' => 'performance',
            ],
            'performance_load' => [
                'title' => 'Tréninková zátěž', 'subtitle' => 'Výkonnost a regenerace', 'icon' => 'fa-chart-line',
                'setting_key' => 'calculator_performance_load_enabled', 'url' => 'performance.php?module=load', 'group' => 'performance',
            ],
            'performance_recovery' => [
                'title' => 'Regenerace', 'subtitle' => 'Výkonnost a regenerace', 'icon' => 'fa-bed',
                'setting_key' => 'calculator_performance_recovery_enabled', 'url' => 'performance.php?module=recovery', 'group' => 'performance',
            ],
        ];
    }
}

if (!function_exists('calculatorIsEnabled')) {
    function calculatorIsEnabled(string $key): bool {
        $definitions = calculatorDefinitions();
        if (!isset($definitions[$key])) {
            return false;
        }
        return trim(getAppSetting($definitions[$key]['setting_key'], ($definitions[$key]['default_enabled'] ?? true) ? '1' : '0')) !== '0';
    }
}

if (!function_exists('calculatorBmiValue')) {
    function calculatorBmiValue(float $weightKg, float $heightCm): ?float {
        if ($weightKg <= 0 || $heightCm <= 0) {
            return null;
        }
        $heightM = $heightCm / 100;
        return round($weightKg / ($heightM * $heightM), 2);
    }
}

if (!function_exists('calculatorBmiCategories')) {
    /**
     * Kategorie BMI pro dospělé (horní mez je exkluzivní).
     *
     * @return list<array{min:float, max:?float, label:string, range:string, tone:string}>
     */
    function calculatorBmiCategories(): array {
        return [
            ['min' => 0.0,  'max' => 18.5, 'label' => 'Podváha',            'range' => 'pod 18,5',  'tone' => 'info'],
            ['min' => 18.5, 'max' => 25.0, 'label' => 'Normální hmotnost',  'range' => '18,5–24,9', 'tone' => 'success'],
            ['min' => 25.0, 'max' => 30.0, 'label' => 'Nadváha',            'range' => '25–29,9',   'tone' => 'warning'],
            ['min' => 30.0, 'max' => 35.0, 'label' => 'Obezita I. stupně',  'range' => '30–34,9',   'tone' => 'orange'],
            ['min' => 35.0, 'max' => 40.0, 'label' => 'Obezita II. stupně', 'range' => '35–39,9',   'tone' => 'danger'],
            ['min' => 40.0, 'max' => null, 'label' => 'Obezita III. stupně','range' => '40 a více', 'tone' => 'dark'],
        ];
    }
}

if (!function_exists('calculatorBmiCategoryIndex')) {
    function calculatorBmiCategoryIndex(float $bmi): int {
        foreach (calculatorBmiCategories() as $index => $category) {
            if ($bmi >= $category['min'] && ($category['max'] === null || $bmi < $category['max'])) {
                return $index;
            }
        }
        return 0;
    }
}

if (!function_exists('calculatorEnsureSchema')) {
    function calculatorEnsureSchema(PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS `athlete_calculator_results` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `athlete_id` INT NOT NULL,
                    `calculator_key` VARCHAR(40) NOT NULL,
                    `inputs_json` TEXT NULL,
                    `result_value` DECIMAL(10,2) NULL,
                    `result_label` VARCHAR(120) NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `coach_viewed_at` DATETIME NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_calc_results_athlete` (`athlete_id`, `calculator_key`, `created_at`),
                    KEY `idx_calc_results_unseen` (`athlete_id`, `coach_viewed_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS `athlete_calculator_settings` (
                    `athlete_id` INT NOT NULL,
                    `share_with_coach` TINYINT(1) NOT NULL DEFAULT 1,
                    `height_cm` DECIMAL(5,1) NULL,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`athlete_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $ready = true;
        } catch (Throwable $e) {
            error_log('calculatorEnsureSchema: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('calculatorGetAthleteSettings')) {
    /**
     * Sdílení je ve výchozím stavu zapnuté.
     *
     * @return array{share_with_coach: bool, height_cm: ?float}
     */
    function calculatorGetAthleteSettings(PDO $pdo, int $athleteId): array {
        $settings = ['share_with_coach' => true, 'height_cm' => null];
        if (!calculatorEnsureSchema($pdo)) {
            return $settings;
        }
        try {
            $stmt = $pdo->prepare('SELECT share_with_coach, height_cm FROM athlete_calculator_settings WHERE athlete_id = ? LIMIT 1');
            $stmt->execute([$athleteId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $settings['share_with_coach'] = (int)$row['share_with_coach'] === 1;
                $settings['height_cm'] = $row['height_cm'] !== null ? (float)$row['height_cm'] : null;
            }
        } catch (Throwable $e) {
            error_log('calculatorGetAthleteSettings: ' . $e->getMessage());
        }
        return $settings;
    }
}

if (!function_exists('calculatorSaveShareSetting')) {
    function calculatorSaveShareSetting(PDO $pdo, int $athleteId, bool $share): bool {
        if (!calculatorEnsureSchema($pdo)) {
            return false;
        }
        try {
            $pdo->prepare(
                'INSERT INTO athlete_calculator_settings (athlete_id, share_with_coach) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE share_with_coach = VALUES(share_with_coach)'
            )->execute([$athleteId, $share ? 1 : 0]);
            if ($share) {
                // Po (znovu)zapnutí sdílení uvidí trenér výsledky jako nové.
                $pdo->prepare('UPDATE athlete_calculator_results SET coach_viewed_at = NULL WHERE athlete_id = ?')->execute([$athleteId]);
            }
            return true;
        } catch (Throwable $e) {
            error_log('calculatorSaveShareSetting: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('calculatorSaveAthleteHeight')) {
    function calculatorSaveAthleteHeight(PDO $pdo, int $athleteId, float $heightCm): bool {
        if ($heightCm < 80 || $heightCm > 250 || !calculatorEnsureSchema($pdo)) {
            return false;
        }
        try {
            $pdo->prepare(
                'INSERT INTO athlete_calculator_settings (athlete_id, height_cm) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE height_cm = VALUES(height_cm)'
            )->execute([$athleteId, round($heightCm, 1)]);
            return true;
        } catch (Throwable $e) {
            error_log('calculatorSaveAthleteHeight: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('calculatorSaveResult')) {
    function calculatorSaveResult(PDO $pdo, int $athleteId, string $calculatorKey, array $inputs, ?float $value, string $label): int {
        if (!calculatorEnsureSchema($pdo)) {
            return 0;
        }
        try {
            $pdo->prepare(
                'INSERT INTO athlete_calculator_results (athlete_id, calculator_key, inputs_json, result_value, result_label, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            )->execute([$athleteId, $calculatorKey, json_encode($inputs, JSON_UNESCAPED_UNICODE), $value, mb_substr($label, 0, 120)]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('calculatorSaveResult: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('calculatorDecodeResultRow')) {
    function calculatorDecodeResultRow(array $row): array {
        $inputs = json_decode((string)($row['inputs_json'] ?? ''), true);
        $row['inputs'] = is_array($inputs) ? $inputs : [];
        $row['result_value'] = $row['result_value'] !== null ? (float)$row['result_value'] : null;
        return $row;
    }
}

if (!function_exists('calculatorFetchResults')) {
    /**
     * Uložené výsledky od nejnovějších.
     */
    function calculatorFetchResults(PDO $pdo, int $athleteId, ?string $calculatorKey = null, int $limit = 50): array {
        if (!calculatorEnsureSchema($pdo)) {
            return [];
        }
        try {
            $sql = 'SELECT * FROM athlete_calculator_results WHERE athlete_id = ?';
            $params = [$athleteId];
            if ($calculatorKey !== null) {
                $sql .= ' AND calculator_key = ?';
                $params[] = $calculatorKey;
            }
            $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit));
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return array_map('calculatorDecodeResultRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('calculatorFetchResults: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('calculatorFetchResult')) {
    function calculatorFetchResult(PDO $pdo, int $resultId, int $athleteId): ?array {
        if (!calculatorEnsureSchema($pdo)) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT * FROM athlete_calculator_results WHERE id = ? AND athlete_id = ? LIMIT 1');
            $stmt->execute([$resultId, $athleteId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? calculatorDecodeResultRow($row) : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('calculatorDeleteResult')) {
    function calculatorDeleteResult(PDO $pdo, int $resultId, int $athleteId): bool {
        if (!calculatorEnsureSchema($pdo)) {
            return false;
        }
        try {
            $stmt = $pdo->prepare('DELETE FROM athlete_calculator_results WHERE id = ? AND athlete_id = ?');
            $stmt->execute([$resultId, $athleteId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('calculatorDeleteResult: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('calculatorFetchLatestResults')) {
    /**
     * Poslední uložený výsledek každé kalkulačky.
     *
     * @return array<string, array>
     */
    function calculatorFetchLatestResults(PDO $pdo, int $athleteId): array {
        $latest = [];
        foreach (calculatorFetchResults($pdo, $athleteId, null, 200) as $row) {
            $key = (string)$row['calculator_key'];
            if (!isset($latest[$key])) {
                $latest[$key] = $row;
            }
        }
        return $latest;
    }
}

if (!function_exists('calculatorCoachUnseenCounts')) {
    /**
     * Počet nových sdílených výsledků pro sportovce trenéra.
     *
     * @return array<int, int> athlete_id => počet
     */
    function calculatorCoachUnseenCounts(PDO $pdo, int $coachId): array {
        if (!calculatorEnsureSchema($pdo)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT r.athlete_id, COUNT(*) AS cnt
                 FROM athlete_calculator_results r
                 JOIN athletes a ON a.id = r.athlete_id
                 LEFT JOIN athlete_calculator_settings s ON s.athlete_id = r.athlete_id
                 WHERE a.coach_id = ?
                   AND COALESCE(s.share_with_coach, 1) = 1
                   AND r.coach_viewed_at IS NULL
                 GROUP BY r.athlete_id'
            );
            $stmt->execute([$coachId]);
            $counts = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[(int)$row['athlete_id']] = (int)$row['cnt'];
            }
            return $counts;
        } catch (Throwable $e) {
            error_log('calculatorCoachUnseenCounts: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('calculatorMarkSeenByCoach')) {
    function calculatorMarkSeenByCoach(PDO $pdo, int $athleteId): void {
        if (!calculatorEnsureSchema($pdo)) {
            return;
        }
        try {
            $pdo->prepare('UPDATE athlete_calculator_results SET coach_viewed_at = NOW() WHERE athlete_id = ? AND coach_viewed_at IS NULL')
                ->execute([$athleteId]);
        } catch (Throwable $e) {
            error_log('calculatorMarkSeenByCoach: ' . $e->getMessage());
        }
    }
}

if (!function_exists('calculatorBmiTrend')) {
    /**
     * Vývoj BMI z historie vážení (při aktuální výšce).
     *
     * @return list<array{date:string, weight:float, bmi:float}>
     */
    function calculatorBmiTrend(int $athleteId, ?float $heightCm, int $limit = 60): array {
        if ($heightCm === null || $heightCm < 80) {
            return [];
        }
        $points = [];
        foreach (array_slice(getAthleteWeightHistory($athleteId, 2000), -$limit) as $log) {
            $weight = (float)$log['weight_kg'];
            $bmi = calculatorBmiValue($weight, $heightCm);
            if ($bmi !== null) {
                $points[] = ['date' => substr((string)$log['measured_at'], 0, 10), 'weight' => $weight, 'bmi' => $bmi];
            }
        }
        return $points;
    }
}

if (!function_exists('calculatorTrendSvg')) {
    /**
     * Jednoduchý SVG spojnicový graf (bez JS – funguje i v tisku).
     *
     * @param list<array{date:string, bmi:float}> $points
     * @param array{theme?:string, band?:array{0:float,1:float}, height?:int} $options
     */
    function calculatorTrendSvg(array $points, array $options = []): string {
        if (count($points) === 0) {
            return '';
        }
        $dark = ($options['theme'] ?? 'dark') === 'dark';
        $width = 720;
        $height = (int)($options['height'] ?? 240);
        $padL = 44; $padR = 16; $padT = 16; $padB = 30;
        $key = (string)($options['key'] ?? 'bmi');
        $seriesLabel = (string)($options['label'] ?? 'BMI');
        $decimals = (int)($options['decimals'] ?? 2);
        $points = array_values(array_filter($points, static fn(array $p): bool => isset($p[$key]) && is_numeric($p[$key])));
        if (count($points) === 0) {
            return '';
        }
        $values = array_map(static fn(array $p): float => (float)$p[$key], $points);
        $band = $options['band'] ?? null;
        $min = min($values);
        $max = max($values);
        if (is_array($band)) {
            $min = min($min, $band[0]);
            $max = max($max, $band[1]);
        }
        $pad = max(1.0, ($max - $min) * 0.1);
        $min = floor($min - $pad);
        $max = ceil($max + $pad);
        $range = max(1.0, $max - $min);
        $count = count($points);
        $x = static fn(int $i): float => $count === 1 ? $padL + ($width - $padL - $padR) / 2 : $padL + ($width - $padL - $padR) * $i / ($count - 1);
        $y = static fn(float $v): float => $padT + ($height - $padT - $padB) * (1 - ($v - $min) / $range);
        $fmtNum = static fn(float $v): string => number_format($v, 1, '.', '');

        $grid = $dark ? 'rgba(201,206,214,.14)' : '#e3e6ea';
        $text = $dark ? '#8f97a3' : '#6c757d';
        $line = $dark ? '#d4a73c' : '#9c7420';
        $id = 'calcTrend' . substr(md5(json_encode($points) . ($options['theme'] ?? '')), 0, 8);

        $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" role="img" aria-label="Vývoj ' . htmlspecialchars($seriesLabel, ENT_QUOTES, 'UTF-8') . '" style="display:block;max-width:100%;height:auto">';
        $svg .= '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . $line . '" stop-opacity=".35"/><stop offset="100%" stop-color="' . $line . '" stop-opacity="0"/></linearGradient></defs>';
        if (is_array($band)) {
            $svg .= '<rect x="' . $padL . '" y="' . $fmtNum($y($band[1])) . '" width="' . ($width - $padL - $padR) . '" height="' . $fmtNum($y($band[0]) - $y($band[1])) . '" fill="' . ($dark ? 'rgba(46,204,113,.09)' : 'rgba(46,204,113,.12)') . '"/>';
        }
        for ($i = 0; $i <= 4; $i++) {
            $value = $min + $range * $i / 4;
            $gy = $fmtNum($y($value));
            $svg .= '<line x1="' . $padL . '" x2="' . ($width - $padR) . '" y1="' . $gy . '" y2="' . $gy . '" stroke="' . $grid . '" stroke-width="1"/>';
            $svg .= '<text x="' . ($padL - 8) . '" y="' . $gy . '" fill="' . $text . '" font-size="11" text-anchor="end" dominant-baseline="middle">' . number_format($value, 1, ',', '') . '</text>';
        }

        $path = '';
        foreach ($points as $i => $p) {
            $path .= ($i === 0 ? 'M' : 'L') . $fmtNum($x($i)) . ' ' . $fmtNum($y((float)$p[$key])) . ' ';
        }
        if ($count > 1) {
            $baseY = $fmtNum($y($min));
            $svg .= '<path d="' . $path . 'L' . $fmtNum($x($count - 1)) . ' ' . $baseY . ' L' . $fmtNum($x(0)) . ' ' . $baseY . ' Z" fill="url(#' . $id . ')"/>';
            $svg .= '<path d="' . trim($path) . '" fill="none" stroke="' . $line . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
        }
        foreach ($points as $i => $p) {
            $label = date('j. n. Y', strtotime($p['date'])) . ': ' . $seriesLabel . ' ' . number_format((float)$p[$key], $decimals, ',', ' ');
            $svg .= '<circle cx="' . $fmtNum($x($i)) . '" cy="' . $fmtNum($y((float)$p[$key])) . '" r="' . ($count > 40 ? 2.5 : 4) . '" fill="' . ($dark ? '#fbe7a1' : '#ffffff') . '" stroke="' . $line . '" stroke-width="2"><title>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</title></circle>';
        }

        $labelIdx = array_unique([0, intdiv($count - 1, 2), $count - 1]);
        foreach ($labelIdx as $i) {
            $anchor = $count === 1 ? 'middle' : ($i === 0 ? 'start' : ($i === $count - 1 ? 'end' : 'middle'));
            $svg .= '<text x="' . $fmtNum($x($i)) . '" y="' . ($height - 8) . '" fill="' . $text . '" font-size="11" text-anchor="' . $anchor . '">' . date('j. n. Y', strtotime($points[$i]['date'])) . '</text>';
        }
        return $svg . '</svg>';
    }
}

if (!function_exists('calculatorResultSummary')) {
    /**
     * Textové shrnutí vstupů výsledku pro tabulky a tisk.
     */
    function calculatorResultSummary(array $row): string {
        $inputs = $row['inputs'] ?? [];
        if (($row['calculator_key'] ?? '') === 'bmi') {
            return number_format((float)($inputs['weight_kg'] ?? 0), 1, ',', '') . ' kg · ' . number_format((float)($inputs['height_cm'] ?? 0), 1, ',', '') . ' cm';
        }
        if (($row['calculator_key'] ?? '') === 'analysis') {
            $parts = [];
            if (!empty($inputs['weight_kg'])) {
                $parts[] = number_format((float)$inputs['weight_kg'], 1, ',', '') . ' kg';
            }
            if (!empty($inputs['tdee'])) {
                $parts[] = 'TDEE ' . number_format((float)$inputs['tdee'], 0, ',', ' ') . ' kcal';
            }
            if (!empty($inputs['protein_g'])) {
                $parts[] = 'B ' . (int)$inputs['protein_g'] . ' g · S ' . (int)($inputs['carbs_g'] ?? 0) . ' g · T ' . (int)($inputs['fat_g'] ?? 0) . ' g';
            }
            return implode(' · ', $parts);
        }
        return '';
    }
}

if (!function_exists('calculatorBmiGaugePercent')) {
    /**
     * Pozice na škále 15–45 rozdělené do 6 stejně širokých segmentů kategorií.
     */
    function calculatorBmiGaugePercent(float $bmi): float {
        $bounds = [15, 18.5, 25, 30, 35, 40, 45];
        if ($bmi <= $bounds[0]) {
            return 0.0;
        }
        for ($i = 0; $i < count($bounds) - 1; $i++) {
            if ($bmi < $bounds[$i + 1]) {
                return (($i + ($bmi - $bounds[$i]) / ($bounds[$i + 1] - $bounds[$i])) / (count($bounds) - 1)) * 100;
            }
        }
        return 100.0;
    }
}

if (!function_exists('calculatorFetchAthleteHeightCm')) {
    /**
     * Výška sportovce: uložená v Kalkulačkách, ze zdravotního dotazníku, případně z dotazníku MyCoach.
     *
     * @return array{height_cm: ?float, source: ?string}
     */
    function calculatorFetchAthleteHeightCm(PDO $pdo, int $athleteId): array {
        $settings = calculatorGetAthleteSettings($pdo, $athleteId);
        if ($settings['height_cm'] !== null && $settings['height_cm'] >= 80 && $settings['height_cm'] <= 250) {
            return ['height_cm' => $settings['height_cm'], 'source' => 'profil sportovce (Kalkulačky)'];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT answers_json FROM athlete_health_questionnaire_submissions
                 WHERE athlete_id = ? ORDER BY submitted_at DESC, id DESC LIMIT 1'
            );
            $stmt->execute([$athleteId]);
            $answers = json_decode((string)($stmt->fetchColumn() ?: '{}'), true);
            $raw = is_array($answers) ? str_replace(',', '.', trim((string)($answers['height_cm'] ?? ''))) : '';
            if ($raw !== '' && is_numeric($raw) && (float)$raw >= 80 && (float)$raw <= 250) {
                return ['height_cm' => (float)$raw, 'source' => 'zdravotní dotazník'];
            }
        } catch (Throwable $e) {
            // Tabulka nemusí existovat – zkusíme další zdroj.
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT q.height_cm FROM mycoach_questionnaires q
                 JOIN mycoach_users u ON u.id = q.user_id
                 WHERE u.athlete_id = ? AND q.height_cm IS NOT NULL
                 ORDER BY q.id DESC LIMIT 1'
            );
            $stmt->execute([$athleteId]);
            $height = $stmt->fetchColumn();
            if ($height !== false && (float)$height >= 80 && (float)$height <= 250) {
                return ['height_cm' => (float)$height, 'source' => 'dotazník MyCoach'];
            }
        } catch (Throwable $e) {
            // Bez výšky – uživatel ji zadá ručně.
        }

        return ['height_cm' => null, 'source' => null];
    }
}
