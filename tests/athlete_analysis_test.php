<?php
// CLI testy výpočetní vrstvy modulu Analýza sportovce.
// Spuštění: php tests/athlete_analysis_test.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/athlete_analysis_calc.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function check(bool $cond, string $name): void {
    if ($cond) {
        $GLOBALS['__pass']++;
        return;
    }
    $GLOBALS['__fail']++;
    echo "FAIL: {$name}\n";
}

function near(?float $actual, float $expected, float $tol, string $name): void {
    check($actual !== null && abs($actual - $expected) <= $tol, $name . ' (očekáváno ' . $expected . ', získáno ' . var_export($actual, true) . ')');
}

function macroSumOk(array $m): bool {
    return $m['protein_g'] * 4 + $m['carbs_g'] * 4 + $m['fat_g'] * 9 === $m['kcal_from_macros'] && abs($m['kcal_from_macros'] - $m['kcal']) <= 5;
}

function scenario(array $raw): array {
    [$in, $errors] = analysisValidateInput($raw, [1, 2, 3, 4, 5], '2025-01-15');
    check($errors === [], 'validace bez chyb: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
    return [$in, analysisBuild($in, [], '2025-01-15')];
}

$today = '2025-01-15';

// ---------- calculateBMR ----------
near(calculateBMR('male', 103, 179, 43), 1938.8, 0.1, 'BMR muž 103/179/43');
near(calculateBMR('female', 70, 168, 35), 1414.0, 0.1, 'BMR žena 70/168/35');
check(calculateBMR('other', 70, 168, 35) === null, 'BMR bez pohlaví = null');
check(calculateBMR('male', 0, 179, 43) === null, 'BMR bez hmotnosti = null');

// ---------- calculateTDEE + koeficient aktivity ----------
near(calculateTDEE(2000, 1.55), 3100.0, 0.01, 'TDEE 2000 × 1,55');
check(calculateTDEE(2000, 3.0) === null, 'TDEE nesmyslný koeficient = null');
$a = analysisActivityLevel('sedentary', '0', '45-60', 'lt3000');
check($a['factor'] === 1.2, 'aktivita: sedavý bez tréninku = 1,2');
$a = analysisActivityLevel('sedentary', '3-4', '45-60', null);
check($a['factor'] === 1.375, 'aktivita: sedavá práce + 3–4 tréninky = 1,375');
$a = analysisActivityLevel('very_active', '7+', 'gt90', 'gt12000');
check($a['factor'] === 1.9, 'aktivita: maximum = 1,9 (koeficienty se nesčítají)');
$a = analysisActivityLevel('sedentary_moving', '5-6', '45-60', null);
check($a['factor'] === 1.725, 'aktivita: aktivní sportovec 5–6× = 1,725');

// ---------- calculateBodyFat ----------
near(calculateBodyFat('male', 180, 38, 90), 19.8, 0.2, 'US Navy muž 180/38/90');
$bfF = calculateBodyFat('female', 168, 33, 75, 100);
check($bfF !== null && $bfF > 20 && $bfF < 35, 'US Navy žena v rozumném rozsahu');
check(calculateBodyFat('female', 168, 33, 75, null) === null, 'US Navy žena bez boků = null');
check(calculateBodyFat('male', 180, null, 90) === null, 'US Navy bez krku = null');
check(calculateBodyFat('male', 180, 40, 38) === null, 'US Navy pas < krk = null');

// ---------- calculateFatMass / calculateLeanBodyMass / calculateWHR ----------
near(calculateFatMass(100, 25), 25.0, 0.01, 'Tuková hmota 100 kg × 25 %');
near(calculateLeanBodyMass(100, 25), 75.0, 0.01, 'Beztuková hmota');
check(calculateFatMass(100, null) === null, 'Tuková hmota bez % tuku = null');
near(calculateWHR(90, 100), 0.9, 0.001, 'WHR 90/100');
check(calculateWHR(null, 100) === null, 'WHR bez pasu = null');

// ---------- calculateCaloriesByGoal ----------
$c = calculateCaloriesByGoal(2500, 'maintain', ['sex' => 'male', 'bmi' => 24]);
check($c['kcal'] === 2500 && $c['mode'] === 'maintenance', 'udržování = TDEE');
$c = calculateCaloriesByGoal(2500, 'fat_loss', ['sex' => 'male', 'bmi' => 26]);
check($c['kcal'] === 2130 && $c['mode'] === 'deficit', 'hubnutí: deficit 15 % (2500 → 2130)');
$c = calculateCaloriesByGoal(1600, 'fat_loss', ['sex' => 'female', 'bmi' => 35]);
check($c['kcal'] >= 1400 && $c['floor_applied'], 'hubnutí: nízké TDEE drží spodní hranici 1400 kcal');
$c = calculateCaloriesByGoal(4000, 'fat_loss', ['sex' => 'male', 'bmi' => 38]);
check($c['adjust_kcal'] >= -750, 'hubnutí: deficit max. 750 kcal');
$c = calculateCaloriesByGoal(2500, 'muscle_gain', ['sex' => 'male', 'bmi' => 23, 'experience' => 'beginner']);
check($c['kcal'] === 2750 && $c['mode'] === 'surplus', 'nabírání začátečník +10 %');
$c = calculateCaloriesByGoal(2500, 'fat_loss', ['sex' => 'male', 'bmi' => 30, 'minor' => true]);
check($c['kcal'] === 2500, 'nezletilý: bez deficitu');
$c = calculateCaloriesByGoal(2500, 'fat_loss', ['sex' => 'female', 'special' => ['pregnancy']]);
check($c['blocked'] && $c['kcal'] === null, 'těhotenství: kalorický cíl blokován');

// ---------- calculateMacros ----------
$m = calculateMacros(2400, 80, 'muscle_gain', ['bmi' => 24, 'height_cm' => 182, 'has_strength' => true, 'training_hours_week' => 4]);
check(macroSumOk($m), 'makra: součet kcal odpovídá cíli');
check($m['protein_g'] === 144, 'makra: bílkoviny 1,8 g/kg × 80 = 144 g');
check($m['fat_pct'] >= 20 && $m['fat_pct'] <= 35, 'makra: tuky 20–35 %');
check($m['fiber_g'] >= 25 && $m['fiber_g'] <= 38, 'makra: vláknina 25–38 g');
$m = calculateMacros(2100, 103, 'fat_loss', ['bmi' => 32.1, 'height_cm' => 179, 'has_strength' => true]);
check($m['protein_g'] < 103 * 2.0, 'makra: při BMI ≥ 30 bílkoviny z upravené hmotnosti');
check(macroSumOk($m), 'makra obezita: součet kcal');
$m = calculateMacros(2000, 70, 'maintain', ['protein_g' => 150, 'fat_g' => 60]);
check($m['protein_g'] === 150 && $m['fat_g'] === 60 && $m['override'] && macroSumOk($m), 'makra: úprava trenérem');

// ---------- calculateHydration / calculateSweatRate ----------
$h = calculateHydration(80, 4.0);
check($h['base_ml'] === 2800, 'tekutiny: 35 ml × 80 kg = 2800 ml');
check($h['training_day_ml'] > $h['rest_day_ml'], 'tekutiny: tréninkový den více');
$s = calculateSweatRate(80.0, 79.0, 0.5, 1.0);
check($s !== null && abs($s['rate_l_per_h'] - 1.5) < 0.001, 'pocení: (80 − 79 + 0,5) / 1 h = 1,5 l/h');
$s = calculateSweatRate(70.0, 68.4, 0.0, 1.0);
check($s !== null && $s['warning'] !== null, 'pocení: ztráta > 2 % = varování');
check(calculateSweatRate(70, 70, 0, 0) === null, 'pocení: nulová délka = null');

// ---------- calculateTargetTimeline ----------
$t = calculateTargetTimeline(100, 90, 'fat_loss', -500, null, $today);
check($t['has_target'] && $t['diff_kg'] === -10.0, 'časová osa: rozdíl −10 kg');
check($t['weeks_estimate'] === 23, 'časová osa: −500 kcal/den ≈ 0,45 kg/týden → 23 týdnů');
$t = calculateTargetTimeline(100, 80, 'fat_loss', -500, '2025-02-15', $today);
check(count($t['warnings']) > 0, 'časová osa: příliš rychlé tempo = varování');
$t = calculateTargetTimeline(70, 80, 'fat_loss', -300, null, $today);
check(count($t['warnings']) > 0, 'časová osa: cíl vyšší než hmotnost při hubnutí = varování');
$t = calculateTargetTimeline(80, null, 'maintain', 0, null, $today);
check(!$t['has_target'], 'časová osa: bez cílové hmotnosti');

// ---------- Rozdělení jídel a tréninkové dny ----------
$plan = analysisMealPlan(2500, ['protein_g' => 160, 'carbs_g' => 280, 'fat_g' => 80], 5, ['breakfast' => '7:00', 'lunch' => '12:30', 'dinner' => '19:00'], '17:00');
check(count($plan['meals']) === 5, 'jídla: 5 jídel');
check(array_sum(array_column($plan['meals'], 'protein_g')) === 160 && array_sum(array_column($plan['meals'], 'carbs_g')) === 280 && array_sum(array_column($plan['meals'], 'fat_g')) === 80, 'jídla: součet maker sedí');
check($plan['pre_index'] !== null && $plan['post_index'] !== null, 'jídla: rozpoznáno jídlo před a po tréninku');
$dinner = $plan['meals'][4];
check($dinner['role'] === 'post' && $dinner['carbs_g'] > 0, 'jídla: večeře po tréninku obsahuje sacharidy (večer se neomezují)');
$d = analysisDayTypes(2500, 300, 4);
check($d['enabled'] && $d['training']['kcal'] > $d['rest']['kcal'] && abs($d['weekly_avg_kcal'] - 2500) <= 10, 'tréninkové/odpočinkové dny: týdenní průměr zachován');
$foods = analysisFoodSuggestions('vegan', 'ořechy', 'brokolice');
check(!in_array('Kuřecí prsa', $foods['protein'], true) && !in_array('Ořechy', $foods['fat'], true) && !in_array('Brokolice', $foods['vegetables'], true), 'potraviny: filtr vegan + alergie + nechutě');
check(count(analysisTrainingTimingVariants(60, 80, false)) === 4, 'časování: 4 varianty');

// ---------- Validace ----------
[, $err] = analysisValidateInput(['sex' => 'male', 'age' => '43', 'height_cm' => '179', 'weight_kg' => '5'], [1], $today);
check(isset($err['weight_kg']), 'validace: nesmyslná hmotnost');
[, $err] = analysisValidateInput(['sex' => 'male', 'age' => '43', 'height_cm' => '179', 'weight_kg' => '80', 'waist_cm' => '500'], [1], $today);
check(isset($err['waist_cm']), 'validace: nesmyslný obvod pasu');
[$in] = analysisValidateInput(['weight_kg' => '80,5'], [], $today);
check($in['weight_kg'] === 80.5, 'validace: desetinná čárka');

// ---------- 8 scénářů ze specifikace ----------
// 1) Muž, 43 let, 103 kg, 179 cm, sedavé zaměstnání, 3 tréninky týdně, hubnutí.
[$in, $r] = scenario(['sex' => 'male', 'age' => 43, 'height_cm' => 179, 'weight_kg' => 103, 'target_weight_kg' => 90, 'goal' => 'fat_loss', 'job_activity' => 'sedentary', 'sessions' => '3-4', 'duration' => '45-60', 'activity_types' => ['strength']]);
near($r['body']['bmi'], 32.15, 0.01, 'S1 BMI 32,15');
near($r['metabolism']['bmr'], 1938.8, 0.1, 'S1 BMR');
check($r['metabolism']['activity']['factor'] === 1.375, 'S1 koeficient 1,375');
check($r['calories']['mode'] === 'deficit' && $r['calories']['kcal'] < $r['metabolism']['tdee'] && $r['calories']['kcal'] >= 1700, 'S1 bezpečný deficit');
check(macroSumOk($r['macros']), 'S1 součet maker');
check($r['timeline']['weeks_estimate'] !== null, 'S1 odhad doby dosažení');
check($r['body']['body_fat_pct'] === null && count($r['missing']) > 0, 'S1 bez obvodů se tuk nezobrazuje');

// 2) Žena, 35 let, 70 kg, 168 cm, 4 tréninky, růst svalů.
[$in, $r2] = scenario(['sex' => 'female', 'age' => 35, 'height_cm' => 168, 'weight_kg' => 70, 'goal' => 'muscle_gain', 'job_activity' => 'sedentary_moving', 'sessions' => '3-4', 'duration' => '60-90', 'activity_types' => ['strength'], 'waist_cm' => 72, 'hip_cm' => 98, 'neck_cm' => 32]);
check($r2['calories']['mode'] === 'surplus', 'S2 nadbytek');
check($r2['macros']['protein_per_kg'] >= 1.6 && $r2['macros']['protein_per_kg'] <= 2.2, 'S2 bílkoviny 1,6–2,2 g/kg');
check($r2['body']['body_fat_pct'] !== null && $r2['body']['whr'] !== null, 'S2 tuk a WHR spočítány');
check(macroSumOk($r2['macros']), 'S2 součet maker');

// 3) Muž, 25 let, 80 kg, 185 cm, 5 tréninků, nabírání svalů.
[$in, $r3] = scenario(['sex' => 'male', 'age' => 25, 'height_cm' => 185, 'weight_kg' => 80, 'goal' => 'muscle_gain', 'job_activity' => 'partly_active', 'sessions' => '5-6', 'duration' => '60-90', 'activity_types' => ['strength'], 'experience' => 'intermediate']);
check($r3['metabolism']['activity']['factor'] >= 1.55, 'S3 vysoká aktivita');
check($r3['calories']['kcal'] > $r3['metabolism']['tdee'], 'S3 nadbytek');
check($r3['calories']['kcal'] > $r2['calories']['kcal'], 'S3 individuální – vyšší příjem než S2');

// 4) Žena, 45 let, 85 kg, 170 cm, nízká aktivita, redukce tuku.
[$in, $r4] = scenario(['sex' => 'female', 'age' => 45, 'height_cm' => 170, 'weight_kg' => 85, 'goal' => 'fat_loss', 'job_activity' => 'sedentary', 'sessions' => '0', 'steps_bucket' => '3000-5000']);
check($r4['metabolism']['activity']['factor'] === 1.2, 'S4 sedavý režim');
check($r4['calories']['kcal'] >= 1400, 'S4 nad bezpečnou spodní hranicí');
check($r4['calories']['mode'] === 'deficit', 'S4 deficit');

// 5) Muž, 30 let, 75 kg, 180 cm, vytrvalost 6× týdně, výkonnost.
[$in, $r5] = scenario(['sex' => 'male', 'age' => 30, 'height_cm' => 180, 'weight_kg' => 75, 'goal' => 'performance', 'job_activity' => 'sedentary_moving', 'sessions' => '5-6', 'duration' => '60-90', 'activity_types' => ['run_outdoor', 'cycling'], 'training_slot' => 'evening']);
check($r5['calories']['kcal'] >= $r5['metabolism']['tdee'] - 10, 'S5 bez deficitu (energetická dostupnost)');
check($r5['macros']['carbs_per_kg'] >= 5.0, 'S5 vyšší sacharidy (≥ 5 g/kg)');
check($r5['timing_selected'] === 'evening', 'S5 večerní trénink');

// 6) Současně redukce tuku a nabírání svalů (rekompozice).
[$in, $r6] = scenario(['sex' => 'male', 'age' => 35, 'height_cm' => 178, 'weight_kg' => 88, 'goal' => 'recomp', 'job_activity' => 'sedentary', 'sessions' => '3-4', 'activity_types' => ['strength']]);
check(abs($r6['calories']['adjust_pct']) <= 10.5, 'S6 rekompozice blízko TDEE');
check($r6['macros']['protein_per_kg'] >= 1.8, 'S6 vysoké bílkoviny');

// 7) Chybějící obvodové míry.
[$in, $r7] = scenario(['sex' => 'female', 'age' => 28, 'height_cm' => 165, 'weight_kg' => 60, 'goal' => 'maintain', 'job_activity' => 'sedentary', 'sessions' => '1-2', 'neck_cm' => 31, 'waist_cm' => 68]);
check($r7['body']['body_fat_pct'] === null && $r7['body']['whr'] === null && $r7['body']['fat_mass_kg'] === null, 'S7 tuk/WHR nezobrazeny bez boků');
check(count($r7['missing']) >= 2 && $r7['calories']['kcal'] !== null, 'S7 chybějící údaje hlášeny, ostatní výpočty běží');

// 8) Změna hmotnosti a aktualizace analýzy (historie zůstává – nový záznam).
[$in8] = analysisValidateInput(['sex' => 'male', 'age' => 43, 'height_cm' => 179, 'weight_kg' => 103, 'goal' => 'fat_loss', 'job_activity' => 'sedentary', 'sessions' => '3-4'], [1, 2, 3], $today);
$before = analysisBuild($in8, [], $today);
$in8['weight_kg'] = 98.0;
$after = analysisBuild($in8, [], $today);
near($before['metabolism']['bmr'] - $after['metabolism']['bmr'], 50.0, 0.2, 'S8 BMR klesne o 10 × 5 kg');
check($after['metabolism']['tdee'] < $before['metabolism']['tdee'], 'S8 TDEE přepočítáno');
check(analysisMetrics($before)['weight_kg'] === 103.0 && analysisMetrics($after)['weight_kg'] === 98.0, 'S8 původní výsledek zůstává nezměněn');

// Speciální stav – žádný kalorický cíl.
[$in, $rs] = scenario(['sex' => 'female', 'age' => 30, 'height_cm' => 165, 'weight_kg' => 65, 'goal' => 'fat_loss', 'job_activity' => 'sedentary', 'sessions' => '1-2', 'special' => ['breastfeeding']]);
check($rs['calories']['blocked'] && $rs['macros'] === null, 'Kojení: bez kalorického cíle a maker');

// Nezletilý.
[$in, $rm] = scenario(['sex' => 'male', 'age' => 16, 'height_cm' => 175, 'weight_kg' => 80, 'goal' => 'fat_loss', 'job_activity' => 'sedentary', 'sessions' => '3-4']);
check($rm['minor'] && abs($rm['calories']['adjust_kcal']) <= 5 && $rm['calories']['mode'] === 'maintenance', 'Nezletilý: udržovací příjem');

echo "\nProšlo: {$GLOBALS['__pass']}, selhalo: {$GLOBALS['__fail']}\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
