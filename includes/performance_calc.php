<?php
declare(strict_types=1);

const PERFORMANCE_VERSION = '1.0';

function performanceModules(): array {
    return [
        'hydration' => 'Hydratace',
        'strength' => 'Maximální síla (1RM)',
        'hyrox' => 'HYROX',
        'load' => 'Tréninková zátěž',
        'recovery' => 'Regenerace',
    ];
}

function performanceNumber(array $input, string $key, float $min, float $max, bool $required = true): ?float {
    $raw = $input[$key] ?? null;
    if ($raw === null || $raw === '') {
        if (!$required) return null;
        throw new InvalidArgumentException('Chybí údaj: ' . $key);
    }
    if (!is_scalar($raw) || is_bool($raw) || !is_numeric(str_replace(',', '.', (string)$raw))) {
        throw new InvalidArgumentException('Neplatné číslo: ' . $key);
    }
    $n = (float)str_replace(',', '.', (string)$raw);
    if (!is_finite($n) || $n < $min || $n > $max) {
        throw new InvalidArgumentException($key . ': povolený rozsah ' . $min . ' až ' . $max . '.');
    }
    return $n;
}

function performanceDate(string $raw): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$date || $date->format('Y-m-d') !== $raw) throw new InvalidArgumentException('Neplatné datum.');
    return $raw;
}

function performanceHydration(array $in): array {
    $weight = performanceNumber($in, 'weight_kg', 20, 400);
    $minutes = performanceNumber($in, 'minutes', 0, 600);
    $rate = performanceNumber($in, 'sweat_rate_l_h', 0.1, 4, false);
    $heat = (string)($in['environment'] ?? 'normal');
    if (!in_array($heat, ['normal', 'hot'], true)) throw new InvalidArgumentException('Neplatné prostředí.');
    $base = (int)(round($weight * 35 / 50) * 50);
    $hourly = $rate !== null ? min(1000, $rate * 1000) : ($heat === 'hot' ? 800 : 600);
    $training = (int)(round($hourly * $minutes / 60 / 50) * 50);
    return [
        'method' => '35 ml/kg + plán tekutin během aktivity',
        'base_ml' => $base, 'training_ml' => $training, 'total_ml' => $base + $training,
        'per_hour_ml' => $hourly, 'sweat_rate_l_h' => $rate,
        'warnings' => [
            'Orientační plán pro zdravého dospělého, nikoli povinný objem k vypití. Část vody přijímáte jídlem.',
            'Nepijte násilně nad žízeň a nepřibírejte při výkonu na hmotnosti. U dlouhého výkonu řešte i sodík.',
            'Naměřené pocení není automaticky bezpečná rychlost pití; plán omezuje doplnění na 1 l/h.',
            'Při omezení tekutin, onemocnění srdce či ledvin, v těhotenství a u nezletilých konzultujte individuální plán.',
        ],
    ];
}

function performanceSweat(array $in): array {
    $pre = performanceNumber($in, 'pre_kg', 20, 400);
    $post = performanceNumber($in, 'post_kg', 20, 400);
    $drink = performanceNumber($in, 'drink_ml', 0, 15000);
    $urine = performanceNumber($in, 'urine_ml', 0, 5000);
    $minutes = performanceNumber($in, 'minutes', 10, 600);
    $loss = $pre - $post + ($drink - $urine) / 1000;
    $rate = $loss / ($minutes / 60);
    if ($loss <= 0 || $rate < .1 || $rate > 4) throw new InvalidArgumentException('Výsledek pocení je mimo použitelný rozsah; ověřte měření a jednotky.');
    return [
        'loss_l' => round($loss, 3), 'rate_l_h' => round($rate, 3),
        'weight_change_pct' => round(($post - $pre) / $pre * 100, 2),
        'warning' => $post > $pre ? 'Hmotnost vzrostla. Nepijte nad svoje ztráty.' : ($pre - $post > $pre * .02 ? 'Úbytek hmotnosti přes 2 %; zkontrolujte individuální plán.' : null),
    ];
}

function performanceStrength(array $in): array {
    $kg = performanceNumber($in, 'weight_kg', 0.5, 600);
    $reps = performanceNumber($in, 'reps', 1, 12);
    if (floor($reps) !== $reps) throw new InvalidArgumentException('Opakování musí být celé číslo.');
    $epley = $reps === 1.0 ? $kg : $kg * (1 + $reps / 30);
    $brzycki = $reps === 1.0 ? $kg : $kg * 36 / (37 - $reps);
    $percentages = [];
    foreach ([50, 60, 70, 75, 80, 85, 90, 95] as $pct) $percentages[$pct] = round($epley * $pct / 100, 1);
    return [
        'method' => 'Epley / Brzycki', 'epley_kg' => round($epley, 2), 'brzycki_kg' => round($brzycki, 2),
        'percentages_kg' => $percentages,
        'warnings' => [
            'Odhad pro technicky správnou sérii bez dopomoci. Není pokynem k pokusu o skutečné maximum.',
            'Zátěž musí být celková a srovnatelná (včetně osy); stroje, jednoručky a vlastní hmotnost neslučujte.',
            $reps > 10 ? 'Nad 10 opakování je odhad méně spolehlivý.' : 'Výsledek závisí na únavě a rezervě opakování.',
        ],
    ];
}

function performanceHyroxSegments(): array {
    $stations = ['SkiErg', 'Sled push', 'Sled pull', 'Burpee broad jumps', 'Row', 'Farmers carry', 'Sandbag lunges', 'Wall balls'];
    $segments = [];
    foreach ($stations as $i => $name) {
        $segments['run_' . ($i + 1)] = 'Běh ' . ($i + 1);
        $segments['station_' . ($i + 1)] = $name;
    }
    $segments['roxzone'] = 'Přechody / Roxzone celkem';
    $segments['penalties'] = 'Penalizace celkem';
    return $segments;
}

function performanceHyrox(array $in): array {
    $times = $in['segments'] ?? null;
    if (!is_array($times)) throw new InvalidArgumentException('Chybí mezičasy.');
    $out = [];
    foreach (performanceHyroxSegments() as $key => $label) {
        $seconds = performanceNumber($times, $key, in_array($key, ['roxzone', 'penalties'], true) ? 0 : 1, 14400);
        if ($seconds !== floor($seconds)) throw new InvalidArgumentException('Mezičasy zadávejte v celých sekundách.');
        $out[$key] = (int)$seconds;
    }
    return [
        'method' => 'Součet segmentů v sekundách', 'segments' => $out, 'total_seconds' => array_sum($out),
        'run_seconds' => array_sum(array_filter($out, static fn($key) => str_starts_with($key, 'run_'), ARRAY_FILTER_USE_KEY)),
        'warnings' => ['Odhad není garance závodního času. Kategorie a standard jsou součástí uloženého výsledku.', 'Zátěže a platnost standardů musí potvrdit trenér; databázový standard nemusí být aktuálním soutěžním pravidlem.'],
    ];
}

function performanceLoad(array $activities, string $date): array {
    $date = performanceDate($date);
    $start = (new DateTimeImmutable($date))->modify('-27 days')->format('Y-m-d');
    $acuteStart = (new DateTimeImmutable($date))->modify('-6 days')->format('Y-m-d');
    $days = [];
    for ($i = 27; $i >= 0; $i--) $days[(new DateTimeImmutable($date))->modify("-$i days")->format('Y-m-d')] = ['load' => 0.0, 'known' => 0, 'missing' => 0];
    $seen = [];
    foreach ($activities as $a) {
        if (!empty($a['linked_to'])) continue;
        $key = $a['key'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $d = $a['date'];
        if ($d < $start || $d > $date) continue;
        $minutes = $a['minutes'] ?? null;
        $rpe = $a['rpe'] ?? null;
        if ($minutes === null || $minutes <= 0 || $minutes > 600 || $rpe === null || $rpe < 1 || $rpe > 10) {
            $days[$d]['missing']++;
        } else {
            $days[$d]['known']++;
            $days[$d]['load'] += $minutes * $rpe;
        }
    }
    $acute = $total = $known = $missing = 0;
    foreach ($days as $d => &$row) {
        $row['load'] = round($row['load'], 1);
        $total += $row['load'];
        if ($d >= $acuteStart) $acute += $row['load'];
        $known += $row['known'];
        $missing += $row['missing'];
    }
    unset($row);
    return [
        'method' => 'session-RPE: minuty × RPE (AU)',
        'acute_7d' => round($acute, 1), 'total_28d' => round($total, 1),
        'known_activities' => $known, 'missing_activities' => $missing,
        'coverage_pct' => $known + $missing > 0 ? (int)round($known / ($known + $missing) * 100) : null,
        'days' => $days,
        'warnings' => ['Součty zahrnují jen zapsané aktivity s délkou a RPE. Prázdný den neznamená potvrzené volno.', 'AU není energie ani diagnóza. Starší vlastní skóre MyCoach s ním není přímo srovnatelné.'],
    ];
}

function performanceRecovery(array $in): array {
    $fields = [
        'sleep_hours' => [0, 24], 'energy_score' => [1, 10], 'motivation_score' => [1, 10],
        'feeling_score' => [1, 10], 'muscle_pain_score' => [0, 10], 'joint_pain_score' => [0, 10],
    ];
    $values = [];
    foreach ($fields as $key => [$min, $max]) {
        $values[$key] = performanceNumber($in, $key, $min, $max, false);
        if ($key !== 'sleep_hours' && $values[$key] !== null && floor($values[$key]) !== $values[$key]) throw new InvalidArgumentException('Hodnocení musí být celé číslo: ' . $key);
    }
    $missing = array_keys(array_filter($values, static fn($v) => $v === null));
    $warnings = ['Orientační vlastní hodnocení, nikoli lékařská diagnóza ani potvrzení bezpečnosti výkonu.'];
    $jointPain = $values['joint_pain_score'] !== null && $values['joint_pain_score'] >= 5;
    if ($jointPain) $warnings[] = 'Při výrazné, nové nebo přetrvávající bolesti přerušte bolestivou aktivitu a konzultujte odborníka.';
    if ($missing) {
        return ['method' => 'TrainerApp readiness 1.0', 'score' => null, 'status' => $jointPain ? 'Bolest kloubů — nezvyšujte zátěž; hodnocení je neúplné' : 'Nedostatek údajů', 'missing' => $missing, 'values' => $values, 'warnings' => $warnings];
    }
    $score = 50 + ($values['energy_score'] - 5) * 4 + ($values['motivation_score'] - 5) * 2
        + ($values['feeling_score'] - 5) * 3 + max(-20, min(10, ($values['sleep_hours'] - 7) * 5))
        - $values['muscle_pain_score'] * 2 - $values['joint_pain_score'] * 3;
    $score = max(0, min(100, (int)round($score)));
    $status = $score < 45 ? 'Regenerace / lehký den' : ($score < 65 ? 'Opatrný kontrolovaný den' : 'Příznivé vlastní hodnocení');
    if ($jointPain) {
        $status = 'Bolest kloubů — nezvyšujte zátěž';
    }
    return ['method' => 'TrainerApp readiness 1.0', 'score' => $score, 'status' => $status, 'missing' => [], 'values' => $values, 'warnings' => $warnings];
}
