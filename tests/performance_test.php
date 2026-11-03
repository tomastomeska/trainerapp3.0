<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/performance_calc.php';
require_once __DIR__ . '/../includes/coach_intelligence.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
$passed = 0;
function pcheck(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    $GLOBALS['passed']++;
}
function pnear(float $value, float $expected, string $name): void { pcheck(abs($value - $expected) < .001, $name); }
function preject(callable $fn, string $name): void {
    try { $fn(); } catch (InvalidArgumentException | DomainException $e) { pcheck(true, $name); return; }
    throw new RuntimeException('FAIL: expected rejection: ' . $name);
}
$h = performanceHydration(['weight_kg' => 80, 'minutes' => 60]);
pcheck($h['base_ml'] === 2800 && $h['training_ml'] === 600 && $h['total_ml'] === 3400, 'hydration formula');
pcheck(performanceHydration(['weight_kg' => 80, 'minutes' => 60, 'environment' => 'hot'])['total_ml'] === 3600, 'hot environment');
pnear(performanceHydration(['weight_kg' => 80, 'minutes' => 60, 'sweat_rate_l_h' => 2])['per_hour_ml'], 1000, 'measured rate capped');
pcheck(performanceHydration(['weight_kg' => 80, 'minutes' => 0])['training_ml'] === 0, 'rest day');
$s = performanceSweat(['pre_kg' => 80, 'post_kg' => 79, 'drink_ml' => 500, 'urine_ml' => 100, 'minutes' => 60]);
pnear($s['rate_l_h'], 1.4, 'sweat mass balance');
preject(fn() => performanceSweat(['pre_kg' => 80, 'post_kg' => 81, 'drink_ml' => 0, 'urine_ml' => 0, 'minutes' => 60]), 'negative sweat');
foreach ([1 => [100, 100], 5 => [116.67, 112.5], 12 => [140, 144]] as $reps => [$e, $b]) {
    $r = performanceStrength(['weight_kg' => 100, 'reps' => $reps]);
    pnear($r['epley_kg'], $e, 'Epley ' . $reps); pnear($r['brzycki_kg'], $b, 'Brzycki ' . $reps);
    pcheck(count($r['percentages_kg']) === 8, 'percentage shape');
}
foreach ([0, 13, 1.5] as $reps) preject(fn() => performanceStrength(['weight_kg' => 100, 'reps' => $reps]), 'invalid reps');
pnear(performanceNumber(['x' => '1,5'], 'x', 0, 2), 1.5, 'decimal comma');
foreach ([[], 'abc', INF, -1, 21, true] as $value) preject(fn() => performanceNumber(['x' => $value], 'x', 0, 20), 'invalid number');
pcheck(performanceNumber([], 'x', 0, 10, false) === null, 'unknown not zero');
foreach (['2025-02-29', '2026-2-01', '2026-01-01extra'] as $date) preject(fn() => performanceDate($date), 'date rejection');
pcheck(performanceDate('2024-02-29') === '2024-02-29', 'leap date');
$segments = array_fill_keys(array_keys(performanceHyroxSegments()), 60);
$r = performanceHyrox(['segments' => $segments]);
pcheck(count($r['segments']) === 18 && $r['total_seconds'] === 1080 && $r['run_seconds'] === 480, 'HYROX exact shape/sum');
preject(fn() => performanceHyrox(['segments' => array_diff_key($segments, ['run_1' => 1])]), 'missing segment');
preject(fn() => performanceHyrox(['segments' => ['run_1' => 1.5] + $segments]), 'fractional seconds');
$activities = [
    ['key' => 'a', 'date' => '2026-02-28', 'minutes' => 60, 'rpe' => 5],
    ['key' => 'a', 'date' => '2026-02-28', 'minutes' => 60, 'rpe' => 5],
    ['key' => 'b', 'date' => '2026-02-22', 'minutes' => 30, 'rpe' => 6],
    ['key' => 'c', 'date' => '2026-02-01', 'minutes' => 10, 'rpe' => 3],
    ['key' => 'd', 'date' => '2026-01-31', 'minutes' => 100, 'rpe' => 10],
    ['key' => 'e', 'date' => '2026-02-28', 'minutes' => 60, 'rpe' => null],
    ['key' => 'f', 'date' => '2026-02-28', 'minutes' => null, 'rpe' => 5],
    ['key' => 'g', 'date' => '2026-02-28', 'minutes' => 60, 'rpe' => 5, 'linked_to' => 'a'],
];
$l = performanceLoad($activities, '2026-02-28');
pnear($l['acute_7d'], 480, '7 calendar days inclusive');
pnear($l['total_28d'], 510, '28 calendar days inclusive and dedup');
pcheck(count($l['days']) === 28 && $l['known_activities'] === 3 && $l['missing_activities'] === 2 && $l['coverage_pct'] === 60, 'coverage unknowns');
pcheck(performanceLoad([], '2026-02-28')['coverage_pct'] === null, 'no activities not 100 percent');
$daily = ['sleep_hours' => 8, 'energy_score' => 8, 'motivation_score' => 8, 'feeling_score' => 8, 'muscle_pain_score' => 0, 'joint_pain_score' => 0];
$r = performanceRecovery($daily);
pcheck($r['score'] === 82 && $r['missing'] === [], 'readiness deterministic score');
foreach (array_keys($daily) as $key) {
    $d = $daily; unset($d[$key]);
    pcheck(performanceRecovery($d)['score'] === null, 'missing ' . $key . ' not replaced');
}
preject(fn() => performanceRecovery(['energy_score' => 1.5]), 'integer scores');
pcheck(str_contains(performanceRecovery(['joint_pain_score' => 5] + $daily)['status'], 'Bolest'), 'pain overrides positive score');
pcheck(str_contains(performanceRecovery(['joint_pain_score' => 5])['status'], 'Bolest'), 'pain warning with incomplete data and intelligence off');
$ctx = ['date' => '2026-02-28', 'settings' => ['intelligence_enabled' => false], 'daily' => $daily, 'weight' => null, 'fluids' => null, 'activities' => $activities, 'latest_results' => [], 'available_modules' => array_keys(performanceModules())];
pcheck(performanceIntelligence($ctx, $l, $r) === [], 'intelligence opt in');
$ctx['settings']['intelligence_enabled'] = true;
$rules = performanceIntelligence($ctx, $l, $r);
pcheck(in_array('missing-weight', array_column($rules, 'code'), true), 'weight explanation');
$ctx['daily']['joint_pain_score'] = 7;
$ctx['latest_results']['strength'] = ['id' => 1];
$rules = performanceIntelligence($ctx, $l, performanceRecovery($ctx['daily']));
pcheck($rules[0]['code'] === 'joint-pain' && in_array('strength-recovery', array_column($rules, 'code'), true), 'safety priority and cross-calculator rule');
$ctx['available_modules'] = []; $ctx['daily'] = []; $ctx['activities'] = []; $ctx['latest_results'] = [];
$rules = performanceIntelligence($ctx, performanceLoad([], $ctx['date']), performanceRecovery([]));
pcheck(array_column($rules, 'code') === ['no-available-data'], 'disabled or unshared data not inferred');
$baseline = ['sleep_hours' => 7, 'energy_score' => 5, 'motivation_score' => 5, 'feeling_score' => 5, 'muscle_pain_score' => 0, 'joint_pain_score' => 0];
pcheck(performanceRecovery(['sleep_hours' => 6] + $baseline)['score'] === 45 && performanceRecovery(['sleep_hours' => 6] + $baseline)['status'] === 'Opatrný kontrolovaný den', 'readiness lower category boundary');
pcheck(performanceRecovery(['sleep_hours' => 5.75] + $baseline)['score'] === 44, 'readiness below lower boundary');
pcheck(performanceRecovery(['feeling_score' => 10] + $baseline)['score'] === 65 && performanceRecovery(['feeling_score' => 10] + $baseline)['status'] === 'Příznivé vlastní hodnocení', 'readiness upper category boundary');
pcheck(performanceRecovery(['feeling_score' => 10, 'sleep_hours' => 6.75] + $baseline)['score'] === 64, 'readiness below upper boundary');
echo "OK: $passed performance calculation assertions\n";
