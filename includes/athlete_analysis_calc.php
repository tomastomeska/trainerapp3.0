<?php
// includes/athlete_analysis_calc.php – výpočetní vrstva modulu Analýza sportovce (bez přístupu k DB).
// Všechny výsledky jsou orientační odhady, nikoliv diagnóza.
require_once __DIR__ . '/calculators.php';

if (!function_exists('analysisGoalOptions')) {
    /** @return array<string, string> */
    function analysisGoalOptions(): array {
        return [
            'fat_loss' => 'Zhubnout – prioritou je redukce tělesného tuku',
            'fat_loss_keep_muscle' => 'Zhubnout a zachovat svalovou hmotu',
            'recomp' => 'Zhubnout a současně nabírat svalovou hmotu',
            'muscle_gain' => 'Nabrat svalovou hmotu – prioritou je hypertrofie',
            'weight_gain' => 'Přibrat na váze',
            'maintain' => 'Udržovat současnou hmotnost',
            'performance' => 'Zlepšit fyzickou kondici a výkonnost',
            'endurance' => 'Zlepšit vytrvalost',
            'strength' => 'Zlepšit sílu',
            'body_composition' => 'Zlepšit celkovou tělesnou kompozici',
        ];
    }

    /** @return array<string, string> */
    function analysisGoalShortLabels(): array {
        return [
            'fat_loss' => 'Redukce tuku',
            'fat_loss_keep_muscle' => 'Redukce tuku se zachováním svalů',
            'recomp' => 'Rekompozice',
            'muscle_gain' => 'Nabírání svalové hmoty',
            'weight_gain' => 'Přibírání na váze',
            'maintain' => 'Udržování hmotnosti',
            'performance' => 'Kondice a výkonnost',
            'endurance' => 'Vytrvalost',
            'strength' => 'Síla',
            'body_composition' => 'Tělesná kompozice',
        ];
    }

    /** @return array<string, string> */
    function analysisSecondaryGoalOptions(): array {
        return [
            'none' => 'Bez vedlejšího cíle',
            'keep_muscle' => 'Zachovat svalovou hmotu',
            'gain_muscle' => 'Zvýšit svalovou hmotu',
            'minimize_fat_gain' => 'Minimalizovat nárůst tělesného tuku',
            'lose_fat' => 'Snížit podíl tělesného tuku',
            'performance' => 'Zlepšit výkonnost',
            'endurance' => 'Zlepšit vytrvalost',
            'strength' => 'Zlepšit sílu',
        ];
    }

    /** @return array<string, string> */
    function analysisJobActivityOptions(): array {
        return [
            'sedentary' => 'Převážně sedavé zaměstnání',
            'sedentary_moving' => 'Sedavé zaměstnání s občasným pohybem',
            'partly_active' => 'Částečně aktivní zaměstnání',
            'active' => 'Fyzicky aktivní zaměstnání',
            'very_active' => 'Velmi fyzicky náročné zaměstnání',
        ];
    }

    /** @return array<string, string> */
    function analysisSessionsOptions(): array {
        return ['0' => '0', '1-2' => '1–2', '3-4' => '3–4', '5-6' => '5–6', '7+' => '7 a více'];
    }

    /** @return array<string, string> */
    function analysisDurationOptions(): array {
        return [
            'lt30' => 'Do 30 minut',
            '30-45' => '30–45 minut',
            '45-60' => '45–60 minut',
            '60-90' => '60–90 minut',
            'gt90' => 'Více než 90 minut',
        ];
    }

    /** @return array<string, string> */
    function analysisActivityTypeOptions(): array {
        return [
            'strength' => 'Posilování',
            'circuit' => 'Kruhový trénink',
            'hiit' => 'HIIT',
            'tabata' => 'Tabata',
            'trx' => 'TRX',
            'bodypump' => 'BodyPump',
            'run_outdoor' => 'Běh venku',
            'run_treadmill' => 'Běh na pásu',
            'walk' => 'Chůze',
            'cycling' => 'Cyklistika',
            'swimming' => 'Plavání',
            'functional' => 'Funkční trénink',
            'hyrox' => 'HYROX',
            'yoga' => 'Jóga',
            'pilates' => 'Pilates',
            'other' => 'Ostatní',
        ];
    }

    /** @return array<string, string> */
    function analysisStepsOptions(): array {
        return [
            'lt3000' => 'Do 3 000',
            '3000-5000' => '3 000–5 000',
            '5000-8000' => '5 000–8 000',
            '8000-12000' => '8 000–12 000',
            'gt12000' => 'Více než 12 000',
        ];
    }

    /** @return array<string, string> */
    function analysisExperienceOptions(): array {
        return [
            'beginner' => 'Začátečník (méně než 1 rok)',
            'intermediate' => 'Mírně pokročilý (1–3 roky)',
            'advanced' => 'Pokročilý (více než 3 roky)',
        ];
    }

    /** @return array<string, string> */
    function analysisTrainingSlotOptions(): array {
        return [
            'morning' => 'Ráno (cca 5–8 h)',
            'forenoon' => 'Dopoledne (cca 8–12 h)',
            'afternoon' => 'Odpoledne (cca 12–17 h)',
            'evening' => 'Večer (po 17 h)',
            'varies' => 'Různě',
        ];
    }

    /** @return array<string, string> */
    function analysisMealsCountOptions(): array {
        return ['3' => '3 jídla denně', '4' => '4 jídla denně', '5' => '5 jídel denně', '6' => '6 jídel denně', 'custom' => 'Individuální režim'];
    }

    /** @return array<string, string> */
    function analysisDietOptions(): array {
        return [
            'none' => 'Bez omezení',
            'vegetarian' => 'Vegetariánská strava',
            'vegan' => 'Veganská strava',
            'gluten_free' => 'Bezlepková strava',
            'lactose_free' => 'Bezlaktózová strava',
            'other' => 'Jiná stravovací omezení',
        ];
    }

    /** @return array<string, string> */
    function analysisPrepTimeOptions(): array {
        return ['lt15' => 'Do 15 minut', '15-30' => '15–30 minut', '30-60' => '30–60 minut', 'gt60' => 'Více než hodinu'];
    }

    /** @return array<string, string> */
    function analysisCookingOptions(): array {
        return ['quick' => 'Preferuji rychlá jídla', 'cook' => 'Rád/a vařím', 'mix' => 'Kombinace obojího'];
    }

    /** @return array<string, string> */
    function analysisSpecialConditionOptions(): array {
        return [
            'pregnancy' => 'Těhotenství',
            'breastfeeding' => 'Kojení',
            'eating_disorder' => 'Porucha příjmu potravy (současná nebo v minulosti)',
            'medical' => 'Závažné zdravotní omezení (např. diabetes, onemocnění ledvin, srdce)',
        ];
    }

    /**
     * Mezní hodnoty pro validaci obvodů (cm).
     *
     * @return array<string, array{label:string, min:float, max:float}>
     */
    function analysisCircumferenceFields(): array {
        return [
            'waist_cm' => ['label' => 'Obvod pasu', 'min' => 40, 'max' => 220],
            'hip_cm' => ['label' => 'Obvod boků', 'min' => 50, 'max' => 220],
            'neck_cm' => ['label' => 'Obvod krku', 'min' => 20, 'max' => 70],
            'chest_cm' => ['label' => 'Obvod hrudníku', 'min' => 50, 'max' => 220],
            'arm_cm' => ['label' => 'Obvod paže', 'min' => 15, 'max' => 70],
            'thigh_cm' => ['label' => 'Obvod stehna', 'min' => 25, 'max' => 120],
        ];
    }
}

if (!function_exists('calculateBMR')) {
    /**
     * Bazální metabolismus podle Mifflin-St Jeor (kcal/den).
     */
    function calculateBMR(string $sex, float $weightKg, float $heightCm, int $age): ?float {
        if (!in_array($sex, ['male', 'female'], true) || $weightKg <= 0 || $heightCm <= 0 || $age <= 0) {
            return null;
        }
        $bmr = 10 * $weightKg + 6.25 * $heightCm - 5 * $age + ($sex === 'male' ? 5 : -161);
        return round($bmr, 1);
    }
}

if (!function_exists('analysisActivityFactors')) {
    /** @return list<array{factor:float, label:string}> */
    function analysisActivityFactors(): array {
        return [
            ['factor' => 1.2, 'label' => 'Sedavý režim'],
            ['factor' => 1.375, 'label' => 'Lehká aktivita'],
            ['factor' => 1.55, 'label' => 'Střední aktivita'],
            ['factor' => 1.725, 'label' => 'Vysoká aktivita'],
            ['factor' => 1.9, 'label' => 'Velmi vysoká aktivita'],
        ];
    }

    /** Průměrný počet tréninků týdně pro zvolený interval. */
    function analysisSessionsAverage(string $sessionsKey): float {
        return ['0' => 0.0, '1-2' => 1.5, '3-4' => 3.5, '5-6' => 5.5, '7+' => 7.5][$sessionsKey] ?? 0.0;
    }

    /** Průměrná délka tréninku v minutách. */
    function analysisDurationAverageMinutes(string $durationKey): int {
        return ['lt30' => 25, '30-45' => 40, '45-60' => 55, '60-90' => 75, 'gt90' => 105][$durationKey] ?? 55;
    }

    /** Převod ručně zadaného počtu kroků na interval. */
    function analysisStepsBucket(?int $steps): ?string {
        if ($steps === null || $steps <= 0) {
            return null;
        }
        if ($steps < 3000) {
            return 'lt3000';
        }
        if ($steps < 5000) {
            return '3000-5000';
        }
        if ($steps < 8000) {
            return '5000-8000';
        }
        if ($steps <= 12000) {
            return '8000-12000';
        }
        return 'gt12000';
    }

    /**
     * Jeden souhrnný koeficient aktivity z práce, tréninků a kroků.
     * Složky se nesčítají jako samostatné koeficienty – vytvoří jedno skóre, které se převede na jednu z 5 úrovní.
     *
     * @return array{level:int, factor:float, label:string, score:float, training_hours_week:float, explanation:string}
     */
    function analysisActivityLevel(string $job, string $sessionsKey, string $durationKey, ?string $stepsKey): array {
        $trainScore = ['0' => 0.0, '1-2' => 0.75, '3-4' => 1.5, '5-6' => 2.5, '7+' => 3.25][$sessionsKey] ?? 0.0;
        $durationMod = ['lt30' => -0.5, '30-45' => -0.25, '45-60' => 0.0, '60-90' => 0.25, 'gt90' => 0.5][$durationKey] ?? 0.0;
        if ($trainScore === 0.0) {
            $durationMod = 0.0;
        }
        $jobScore = ['sedentary' => 0.0, 'sedentary_moving' => 0.25, 'partly_active' => 0.75, 'active' => 1.25, 'very_active' => 2.0][$job] ?? 0.0;
        $stepsScore = ['lt3000' => -0.25, '3000-5000' => 0.0, '5000-8000' => 0.25, '8000-12000' => 0.5, 'gt12000' => 0.75][$stepsKey ?? ''] ?? 0.0;

        $score = max(0.0, $trainScore + $durationMod + $jobScore + $stepsScore);
        if ($score < 0.75) {
            $level = 0;
        } elseif ($score < 1.6) {
            $level = 1;
        } elseif ($score < 2.5) {
            $level = 2;
        } elseif ($score < 3.4) {
            $level = 3;
        } else {
            $level = 4;
        }
        $factors = analysisActivityFactors();
        $hours = round(analysisSessionsAverage($sessionsKey) * analysisDurationAverageMinutes($durationKey) / 60, 1);

        $parts = [];
        $parts[] = (analysisJobActivityOptions()[$job] ?? 'Neuvedená práce');
        $parts[] = 'tréninky ' . (analysisSessionsOptions()[$sessionsKey] ?? '0') . '× týdně' . ($trainScore > 0 ? ' (' . mb_strtolower(analysisDurationOptions()[$durationKey] ?? '') . ')' : '');
        if ($stepsKey !== null && isset(analysisStepsOptions()[$stepsKey])) {
            $parts[] = analysisStepsOptions()[$stepsKey] . ' kroků denně';
        }

        return [
            'level' => $level,
            'factor' => $factors[$level]['factor'],
            'label' => $factors[$level]['label'],
            'score' => round($score, 2),
            'training_hours_week' => $hours,
            'explanation' => implode(', ', $parts) . ' → jeden souhrnný koeficient ' . number_format($factors[$level]['factor'], 3, ',', '') . '.',
        ];
    }
}

if (!function_exists('calculateTDEE')) {
    /** Celkový denní energetický výdej = BMR × koeficient aktivity. */
    function calculateTDEE(float $bmr, float $activityFactor): ?float {
        if ($bmr <= 0 || $activityFactor < 1.0 || $activityFactor > 2.5) {
            return null;
        }
        return round($bmr * $activityFactor, 1);
    }
}

if (!function_exists('calculateBodyFat')) {
    /**
     * Procento tělesného tuku metodou US Navy (cm). Bez všech potřebných obvodů vrací null.
     */
    function calculateBodyFat(string $sex, float $heightCm, ?float $neckCm, ?float $waistCm, ?float $hipCm = null): ?float {
        if ($heightCm <= 0 || $neckCm === null || $waistCm === null || $neckCm <= 0 || $waistCm <= 0) {
            return null;
        }
        if ($sex === 'male') {
            $diff = $waistCm - $neckCm;
            if ($diff <= 0) {
                return null;
            }
            $bf = 495 / (1.0324 - 0.19077 * log10($diff) + 0.15456 * log10($heightCm)) - 450;
        } elseif ($sex === 'female') {
            if ($hipCm === null || $hipCm <= 0) {
                return null;
            }
            $diff = $waistCm + $hipCm - $neckCm;
            if ($diff <= 0) {
                return null;
            }
            $bf = 495 / (1.29579 - 0.35004 * log10($diff) + 0.22100 * log10($heightCm)) - 450;
        } else {
            return null;
        }
        if (!is_finite($bf) || $bf < 2 || $bf > 65) {
            return null;
        }
        return round($bf, 1);
    }
}

if (!function_exists('calculateFatMass')) {
    function calculateFatMass(float $weightKg, ?float $bodyFatPct): ?float {
        if ($bodyFatPct === null || $weightKg <= 0) {
            return null;
        }
        return round($weightKg * $bodyFatPct / 100, 1);
    }
}

if (!function_exists('calculateLeanBodyMass')) {
    function calculateLeanBodyMass(float $weightKg, ?float $fatMassKg): ?float {
        if ($fatMassKg === null || $weightKg <= 0) {
            return null;
        }
        return round($weightKg - $fatMassKg, 1);
    }
}

if (!function_exists('calculateWHR')) {
    function calculateWHR(?float $waistCm, ?float $hipCm): ?float {
        if ($waistCm === null || $hipCm === null || $waistCm <= 0 || $hipCm <= 0) {
            return null;
        }
        return round($waistCm / $hipCm, 2);
    }
}

if (!function_exists('analysisWhrInterpretation')) {
    /**
     * Orientační interpretace WHR podle WHO.
     *
     * @return array{label:string, tone:string}
     */
    function analysisWhrInterpretation(string $sex, float $whr): array {
        $limits = $sex === 'female' ? [0.80, 0.85] : [0.90, 1.0];
        if ($whr < $limits[0]) {
            return ['label' => 'Nízké riziko (rozložení tuku příznivé)', 'tone' => 'success'];
        }
        if ($whr < $limits[1]) {
            return ['label' => 'Mírně zvýšené riziko', 'tone' => 'warning'];
        }
        return ['label' => 'Zvýšené riziko (tuk ukládaný v oblasti břicha)', 'tone' => 'danger'];
    }
}

if (!function_exists('analysisBodyFatCategory')) {
    /**
     * Orientační kategorie podílu tuku (ACE).
     *
     * @return array{label:string, tone:string}
     */
    function analysisBodyFatCategory(string $sex, float $bodyFatPct): array {
        $bounds = $sex === 'female'
            ? [[14, 'Esenciální tuk / velmi nízký', 'info'], [21, 'Sportovci', 'success'], [25, 'Fitness', 'success'], [32, 'Průměr', 'warning'], [null, 'Vysoký podíl tuku', 'danger']]
            : [[6, 'Esenciální tuk / velmi nízký', 'info'], [14, 'Sportovci', 'success'], [18, 'Fitness', 'success'], [25, 'Průměr', 'warning'], [null, 'Vysoký podíl tuku', 'danger']];
        foreach ($bounds as [$max, $label, $tone]) {
            if ($max === null || $bodyFatPct < $max) {
                return ['label' => $label, 'tone' => $tone];
            }
        }
        return ['label' => '', 'tone' => 'secondary'];
    }

    /** Je podíl tuku vysoký (horní kategorie)? */
    function analysisBodyFatIsHigh(string $sex, ?float $bodyFatPct, ?float $bmi): bool {
        if ($bodyFatPct !== null) {
            return $bodyFatPct >= ($sex === 'female' ? 32 : 25);
        }
        return $bmi !== null && $bmi >= 30;
    }

    /** Je sportovec štíhlý (nízký tuk / nízké BMI)? */
    function analysisBodyFatIsLean(string $sex, ?float $bodyFatPct, ?float $bmi): bool {
        if ($bodyFatPct !== null) {
            return $bodyFatPct < ($sex === 'female' ? 21 : 14);
        }
        return $bmi !== null && $bmi < 22;
    }
}

if (!function_exists('calculateCaloriesByGoal')) {
    /**
     * Doporučený denní kalorický příjem podle cíle.
     *
     * @param array{secondary?:string, sex?:string, bmi?:?float, body_fat_pct?:?float, minor?:bool, special?:list<string>, experience?:string, sessions_key?:string} $ctx
     * @return array{blocked:bool, kcal:?int, tdee:float, adjust_pct:float, adjust_kcal:int, mode:string, mode_label:string, range_pct:array{0:float,1:float}, floor:?int, floor_applied:bool, notes:list<string>}
     */
    function calculateCaloriesByGoal(float $tdee, string $goal, array $ctx = []): array {
        $secondary = (string)($ctx['secondary'] ?? 'none');
        $sex = (string)($ctx['sex'] ?? 'male');
        $bmi = $ctx['bmi'] ?? null;
        $bf = $ctx['body_fat_pct'] ?? null;
        $experience = (string)($ctx['experience'] ?? 'intermediate');
        $high = analysisBodyFatIsHigh($sex, $bf, $bmi);
        $lean = analysisBodyFatIsLean($sex, $bf, $bmi);
        $notes = [];

        $result = [
            'blocked' => false,
            'kcal' => null,
            'tdee' => round($tdee, 1),
            'adjust_pct' => 0.0,
            'adjust_kcal' => 0,
            'mode' => 'maintenance',
            'mode_label' => 'Udržovací příjem',
            'range_pct' => [0.0, 0.0],
            'floor' => null,
            'floor_applied' => false,
            'notes' => [],
        ];

        if (!empty($ctx['special'])) {
            $result['blocked'] = true;
            $result['notes'] = ['Vzhledem k uvedenému zdravotnímu stavu (např. těhotenství, kojení, porucha příjmu potravy nebo závažné onemocnění) systém nestanovuje kalorický cíl ani makroživiny. Je nutné individuální posouzení lékařem nebo nutričním terapeutem.'];
            return $result;
        }

        switch ($goal) {
            case 'fat_loss':
                $pct = $high ? -0.20 : ($lean ? -0.10 : -0.15);
                $range = [-0.20, -0.10];
                if (in_array($secondary, ['keep_muscle', 'gain_muscle'], true)) {
                    $pct = max($pct, -0.15);
                    $notes[] = 'Kvůli vedlejšímu cíli (svalová hmota) je deficit omezen na nejvýše 15 %.';
                }
                break;
            case 'fat_loss_keep_muscle':
                $pct = $high ? -0.15 : ($lean ? -0.10 : -0.12);
                $range = [-0.15, -0.10];
                break;
            case 'recomp':
                $pct = $high ? -0.07 : ($lean ? 0.0 : -0.05);
                $range = [-0.10, 0.0];
                $notes[] = 'Rekompozice: příjem blízko TDEE, bez velkého deficitu – rozhoduje silový trénink a dostatek bílkovin.';
                break;
            case 'muscle_gain':
                $pct = $experience === 'beginner' ? 0.10 : ($experience === 'advanced' ? 0.05 : 0.075);
                $range = [0.05, 0.10];
                if ($secondary === 'minimize_fat_gain' || $secondary === 'lose_fat' || $high) {
                    $pct = 0.05;
                    $notes[] = 'Nadbytek je nastaven na spodní hranici, aby se minimalizoval nárůst tuku.';
                }
                break;
            case 'weight_gain':
                $pct = ($bmi !== null && $bmi < 18.5) ? 0.15 : 0.12;
                $range = [0.10, 0.15];
                if ($secondary === 'minimize_fat_gain') {
                    $pct = 0.10;
                }
                break;
            case 'strength':
                $pct = ($secondary === 'lose_fat' || $high) ? 0.0 : 0.05;
                $range = [0.0, 0.05];
                break;
            case 'body_composition':
                $pct = $high ? -0.10 : ($lean ? 0.0 : -0.05);
                $range = [-0.10, 0.0];
                break;
            case 'performance':
            case 'endurance':
                $pct = ($secondary === 'lose_fat' && !$lean) ? -0.05 : 0.0;
                $range = [-0.05, 0.05];
                $notes[] = 'U výkonnostních cílů je prioritou dostatečná energetická dostupnost – výrazný deficit by zhoršil výkon i regeneraci.';
                break;
            case 'maintain':
            default:
                $pct = ($secondary === 'lose_fat' && !$lean) ? -0.05 : 0.0;
                $range = [-0.05, 0.05];
                break;
        }

        if (!empty($ctx['minor']) && $pct !== 0.0) {
            $pct = 0.0;
            $notes[] = 'U nezletilých se nepoužívají redukční ani nabírací algoritmy pro dospělé – doporučen je udržovací příjem a konzultace s pediatrem či nutričním terapeutem.';
        }

        $kcal = $tdee * (1 + $pct);
        if ($pct < 0) {
            // Ochrana před nebezpečně nízkým příjmem: max. 25 % deficit, max. 750 kcal a spodní hranice podle pohlaví.
            $floor = (int)max($sex === 'female' ? 1400 : 1700, round($tdee * 0.75));
            $kcal = max($kcal, $tdee - 750);
            if ($kcal < $floor) {
                $kcal = min($floor, $tdee);
                $result['floor_applied'] = true;
                $notes[] = 'Příjem byl navýšen na bezpečnou spodní hranici – systém nedoporučuje nebezpečně nízký energetický příjem.';
            }
            $result['floor'] = $floor;
        }

        $kcal = (int)(round($kcal / 10) * 10);
        $adjust = (int)round($kcal - $tdee);
        $result['kcal'] = $kcal;
        $result['adjust_kcal'] = $adjust;
        $result['adjust_pct'] = $tdee > 0 ? round($adjust / $tdee * 100, 1) : 0.0;
        $result['range_pct'] = [$range[0] * 100, $range[1] * 100];
        if ($adjust <= -25) {
            $result['mode'] = 'deficit';
            $result['mode_label'] = 'Kalorický deficit';
        } elseif ($adjust >= 25) {
            $result['mode'] = 'surplus';
            $result['mode_label'] = 'Kalorický nadbytek';
        }
        $result['notes'] = $notes;
        return $result;
    }
}

if (!function_exists('analysisProteinPerKg')) {
    /**
     * Bílkoviny g/kg a doporučený rozsah podle cíle a typu tréninku.
     *
     * @return array{per_kg:float, range:array{0:float,1:float}, range_label:string}
     */
    function analysisProteinPerKg(string $goal, string $secondary, bool $hasStrength, bool $minor): array {
        $map = [
            'fat_loss' => $hasStrength ? [2.0, 1.8, 2.4] : [1.6, 1.2, 1.6],
            'fat_loss_keep_muscle' => $hasStrength ? [2.2, 1.8, 2.4] : [1.8, 1.6, 2.2],
            'recomp' => [2.2, 1.8, 2.4],
            'muscle_gain' => [1.8, 1.6, 2.2],
            'weight_gain' => [1.6, 1.6, 2.2],
            'maintain' => $hasStrength ? [1.6, 1.6, 2.2] : [1.3, 1.2, 1.6],
            'performance' => $hasStrength ? [1.7, 1.6, 2.2] : [1.5, 1.2, 1.6],
            'endurance' => $hasStrength ? [1.6, 1.6, 2.2] : [1.5, 1.2, 1.6],
            'strength' => [1.8, 1.6, 2.2],
            'body_composition' => [2.0, 1.6, 2.4],
        ];
        [$perKg, $low, $highR] = $map[$goal] ?? [1.6, 1.2, 1.6];
        if ($goal === 'muscle_gain' && $secondary === 'minimize_fat_gain') {
            $perKg = 2.0;
        }
        if (in_array($secondary, ['keep_muscle', 'gain_muscle'], true)) {
            $perKg = max($perKg, 2.0);
            $highR = max($highR, 2.2);
        }
        if ($minor) {
            $perKg = min($perKg, 1.5);
            $low = 1.2;
            $highR = 1.5;
        }
        $fmt = static fn(float $v): string => number_format($v, 1, ',', '');
        return ['per_kg' => $perKg, 'range' => [$low, $highR], 'range_label' => $fmt($low) . '–' . $fmt($highR) . ' g/kg'];
    }
}

if (!function_exists('analysisCarbRangePerKg')) {
    /**
     * Orientační rozsah sacharidů g/kg podle týdenního objemu tréninku.
     *
     * @return array{0:float, 1:float, 2:string}
     */
    function analysisCarbRangePerKg(float $trainingHoursWeek): array {
        if ($trainingHoursWeek < 3) {
            return [3.0, 5.0, 'nízká až střední zátěž'];
        }
        if ($trainingHoursWeek <= 7) {
            return [5.0, 7.0, 'střední zátěž (cca 1 h denně)'];
        }
        return [6.0, 10.0, 'vysoká zátěž (1–3 h denně)'];
    }
}

if (!function_exists('calculateMacros')) {
    /**
     * Makroživiny pro daný kalorický příjem. Součet kalorií z makroživin vždy odpovídá cílovému příjmu (± zaokrouhlení).
     *
     * @param array{secondary?:string, lean_mass_kg?:?float, bmi?:?float, height_cm?:float, has_strength?:bool, training_hours_week?:float, minor?:bool, protein_g?:?int, fat_g?:?int} $ctx
     * @return array<string, mixed>
     */
    function calculateMacros(int $kcal, float $weightKg, string $goal, array $ctx = []): array {
        $secondary = (string)($ctx['secondary'] ?? 'none');
        $bmi = $ctx['bmi'] ?? null;
        $heightCm = (float)($ctx['height_cm'] ?? 0);
        $leanMass = $ctx['lean_mass_kg'] ?? null;
        $hasStrength = (bool)($ctx['has_strength'] ?? false);
        $hours = (float)($ctx['training_hours_week'] ?? 0);
        $minor = (bool)($ctx['minor'] ?? false);
        $notes = [];

        $protein = analysisProteinPerKg($goal, $secondary, $hasStrength, $minor);
        $refWeight = $weightKg;
        $basis = 'aktuální hmotnost';
        $proteinG = null;
        if ($bmi !== null && $bmi >= 30) {
            if ($leanMass !== null && $leanMass > 0) {
                $perLbm = min(2.6, $protein['per_kg'] * 1.2);
                $proteinG = (int)round($leanMass * $perLbm);
                $basis = 'beztuková hmota (' . number_format($leanMass, 1, ',', '') . ' kg × ' . number_format($perLbm, 1, ',', '') . ' g)';
                $refWeight = $leanMass / 0.75;
            } elseif ($heightCm > 0) {
                $refWeight = round(25 * pow($heightCm / 100, 2), 1);
                $basis = 'upravená hmotnost odpovídající BMI 25 (' . number_format($refWeight, 1, ',', '') . ' kg)';
            }
            $notes[] = 'Při BMI nad 30 se bílkoviny nepočítají z celkové hmotnosti, ale z ' . ($leanMass !== null ? 'beztukové hmoty' : 'upravené hmotnosti') . ', aby nebyly zbytečně nadhodnocené.';
        }
        if ($proteinG === null) {
            $proteinG = (int)round($refWeight * $protein['per_kg']);
        }

        $fatPct = match ($goal) {
            'endurance' => 0.22,
            'performance' => 0.25,
            'weight_gain', 'muscle_gain', 'fat_loss' => 0.28,
            default => 0.27,
        };
        $fatG = (int)round($kcal * $fatPct / 9);
        $fatG = max($fatG, (int)round(0.6 * $refWeight), (int)ceil($kcal * 0.20 / 9));
        $fatG = min($fatG, (int)floor($kcal * 0.35 / 9));

        $override = false;
        if (isset($ctx['protein_g']) && $ctx['protein_g'] !== null && (int)$ctx['protein_g'] > 0) {
            $proteinG = (int)$ctx['protein_g'];
            $override = true;
        }
        if (isset($ctx['fat_g']) && $ctx['fat_g'] !== null && (int)$ctx['fat_g'] > 0) {
            $fatG = (int)$ctx['fat_g'];
            $override = true;
        }

        $carbsG = (int)round(($kcal - $proteinG * 4 - $fatG * 9) / 4);
        if ($carbsG < 0) {
            $carbsG = 0;
            $notes[] = 'Bílkoviny a tuky pokrývají celý energetický příjem – upravte cíle.';
        }

        $carbRange = analysisCarbRangePerKg($hours);
        $carbsPerKg = $weightKg > 0 ? $carbsG / $weightKg : 0;
        if (!$override && in_array($goal, ['endurance', 'performance'], true) && $carbsPerKg < $carbRange[0]) {
            $notes[] = 'Sacharidy jsou pod orientačním rozsahem pro vaši tréninkovou zátěž (' . number_format($carbRange[0], 0) . '–' . number_format($carbRange[1], 0) . ' g/kg). Ve dnech s náročným tréninkem je navyšte.';
        }

        $fiberG = (int)max(25, min(38, round($kcal / 1000 * 14)));
        $kcalFromMacros = $proteinG * 4 + $carbsG * 4 + $fatG * 9;
        $diff = $kcalFromMacros - $kcal;

        $pct = static fn(int $part): float => $kcalFromMacros > 0 ? round($part / $kcalFromMacros * 100, 1) : 0.0;
        return [
            'kcal' => $kcal,
            'protein_g' => $proteinG,
            'carbs_g' => $carbsG,
            'fat_g' => $fatG,
            'fiber_g' => $fiberG,
            'protein_kcal' => $proteinG * 4,
            'carbs_kcal' => $carbsG * 4,
            'fat_kcal' => $fatG * 9,
            'protein_pct' => $pct($proteinG * 4),
            'carbs_pct' => $pct($carbsG * 4),
            'fat_pct' => $pct($fatG * 9),
            'protein_per_kg' => $weightKg > 0 ? round($proteinG / $weightKg, 2) : 0.0,
            'carbs_per_kg' => round($carbsPerKg, 2),
            'fat_per_kg' => $weightKg > 0 ? round($fatG / $weightKg, 2) : 0.0,
            'protein_basis' => $basis,
            'protein_range_label' => $protein['range_label'],
            'carbs_range_label' => number_format($carbRange[0], 0) . '–' . number_format($carbRange[1], 0) . ' g/kg (' . $carbRange[2] . ')',
            'fat_range_label' => '20–35 % energie',
            'fiber_range_label' => '25–38 g denně',
            'kcal_from_macros' => $kcalFromMacros,
            'kcal_check_diff' => $diff,
            'kcal_check_ok' => abs($diff) <= 5,
            'override' => $override,
            'notes' => $notes,
        ];
    }
}

if (!function_exists('calculateHydration')) {
    /**
     * Orientační denní příjem tekutin: 35 ml/kg + ztráty při tréninku.
     *
     * @return array{base_ml:int, training_ml:int, training_day_ml:int, rest_day_ml:int, avg_ml:int, per_training_hour_ml:int, notes:list<string>}
     */
    function calculateHydration(float $weightKg, float $trainingHoursWeek, ?float $sweatRateLPerH = null): array {
        $base = (int)(round($weightKg * 35 / 50) * 50);
        $perHour = $sweatRateLPerH !== null && $sweatRateLPerH > 0 ? (int)round(min(2.5, $sweatRateLPerH) * 1000) : 600;
        $sessionsPerWeek = max(1.0, min(7.0, $trainingHoursWeek > 0 ? ceil($trainingHoursWeek) : 1.0));
        $avgSessionHours = $trainingHoursWeek > 0 ? $trainingHoursWeek / $sessionsPerWeek : 0.0;
        $training = (int)(round($perHour * $avgSessionHours / 50) * 50);
        $avg = (int)(round(($base * 7 + $training * min(7, $sessionsPerWeek)) / 7 / 50) * 50);
        $notes = ['Část tekutin přijímáte i ze stravy (ovoce, zelenina, polévky).', 'Při horku, vysoké vlhkosti nebo dlouhém tréninku potřeba stoupá – zvažte i doplnění sodíku.'];
        if ($sweatRateLPerH === null) {
            $notes[] = 'Pro přesnější doporučení si změřte potní ztráty (vážení před a po tréninku).';
        }
        return [
            'base_ml' => $base,
            'training_ml' => $training,
            'training_day_ml' => $base + $training,
            'rest_day_ml' => $base,
            'avg_ml' => $avg,
            'per_training_hour_ml' => $perHour,
            'notes' => $notes,
        ];
    }
}

if (!function_exists('calculateSweatRate')) {
    /**
     * Potní ztráty: (hmotnost před − po + vypité tekutiny − moč) / hodiny. 1 kg ≈ 1 l.
     *
     * @return array{loss_l:float, rate_l_per_h:float, loss_pct:float, rehydrate_ml:int, warning:?string}|null
     */
    function calculateSweatRate(float $preKg, float $postKg, float $drankL, float $durationHours, float $urineL = 0.0): ?array {
        if ($preKg <= 0 || $postKg <= 0 || $durationHours <= 0 || $drankL < 0 || $urineL < 0) {
            return null;
        }
        $loss = $preKg - $postKg + $drankL - $urineL;
        if ($loss < 0) {
            return null;
        }
        $lossPct = round(($preKg - $postKg) / $preKg * 100, 1);
        $warning = null;
        if ($lossPct > 2) {
            $warning = 'Úbytek hmotnosti přesáhl 2 % – při tréninku pijte více, výkon i regenerace mohou být omezeny.';
        } elseif ($postKg > $preKg) {
            $warning = 'Po tréninku vážíte více než před ním – pravděpodobně jste vypili více, než bylo potřeba.';
        }
        return [
            'loss_l' => round($loss, 2),
            'rate_l_per_h' => round($loss / $durationHours, 2),
            'loss_pct' => $lossPct,
            'rehydrate_ml' => (int)(round(max(0, $preKg - $postKg) * 1500 / 50) * 50),
            'warning' => $warning,
        ];
    }
}

if (!function_exists('analysisRecommendedRate')) {
    /**
     * Doporučené tempo změny hmotnosti (% tělesné hmotnosti týdně).
     *
     * @return array{direction:string, min_pct:float, max_pct:float}
     */
    function analysisRecommendedRate(string $goal): array {
        return match ($goal) {
            'fat_loss' => ['direction' => 'loss', 'min_pct' => 0.5, 'max_pct' => 1.0],
            'fat_loss_keep_muscle' => ['direction' => 'loss', 'min_pct' => 0.25, 'max_pct' => 0.7],
            'body_composition' => ['direction' => 'loss', 'min_pct' => 0.25, 'max_pct' => 0.5],
            'recomp' => ['direction' => 'stable', 'min_pct' => 0.0, 'max_pct' => 0.25],
            'muscle_gain' => ['direction' => 'gain', 'min_pct' => 0.25, 'max_pct' => 0.5],
            'weight_gain' => ['direction' => 'gain', 'min_pct' => 0.25, 'max_pct' => 0.5],
            'strength' => ['direction' => 'stable', 'min_pct' => 0.0, 'max_pct' => 0.25],
            default => ['direction' => 'stable', 'min_pct' => 0.0, 'max_pct' => 0.25],
        };
    }
}

if (!function_exists('calculateTargetTimeline')) {
    /**
     * Odhad časové osy k cílové hmotnosti. Výsledek je pouze orientační – nikdy jej neprezentovat jako garanci.
     *
     * @return array<string, mixed>
     */
    function calculateTargetTimeline(float $weightKg, ?float $targetKg, string $goal, int $adjustKcal, ?string $targetDate = null, ?string $today = null): array {
        $rate = analysisRecommendedRate($goal);
        $today = $today ?: date('Y-m-d');
        $weeklyKgEstimate = round($adjustKcal * 7 / 7700, 2);
        $out = [
            'has_target' => false,
            'diff_kg' => null,
            'direction' => $rate['direction'],
            'weekly_kg_estimate' => $weeklyKgEstimate,
            'weekly_pct_estimate' => $weightKg > 0 ? round(abs($weeklyKgEstimate) / $weightKg * 100, 2) : 0.0,
            'rec_weekly_kg_min' => round($weightKg * $rate['min_pct'] / 100, 2),
            'rec_weekly_kg_max' => round($weightKg * $rate['max_pct'] / 100, 2),
            'rec_pct_min' => $rate['min_pct'],
            'rec_pct_max' => $rate['max_pct'],
            'weeks_estimate' => null,
            'weeks_min' => null,
            'weeks_max' => null,
            'estimated_date' => null,
            'target_date' => $targetDate,
            'weeks_to_target_date' => null,
            'required_weekly_kg' => null,
            'required_pct' => null,
            'warnings' => [],
        ];
        if ($targetKg === null || $targetKg <= 0) {
            return $out;
        }
        $diff = round($targetKg - $weightKg, 1);
        $out['has_target'] = true;
        $out['diff_kg'] = $diff;
        $abs = abs($diff);

        if ($abs < 0.3) {
            $out['warnings'][] = 'Cílová hmotnost odpovídá současné – soustřeďte se na kompozici a výkon.';
            return $out;
        }
        $wantsLoss = $diff < 0;
        if ($wantsLoss && in_array($goal, ['muscle_gain', 'weight_gain'], true)) {
            $out['warnings'][] = 'Cílová hmotnost je nižší než současná, ale zvolený cíl je nabírání. Zkontrolujte prosím zadání.';
        } elseif (!$wantsLoss && in_array($goal, ['fat_loss', 'fat_loss_keep_muscle'], true)) {
            $out['warnings'][] = 'Cílová hmotnost je vyšší než současná, ale zvolený cíl je hubnutí. Zkontrolujte prosím zadání.';
        }

        $minKg = max(0.05, $out['rec_weekly_kg_min']);
        $maxKg = max(0.1, $out['rec_weekly_kg_max']);
        $out['weeks_min'] = (int)ceil($abs / $maxKg);
        $out['weeks_max'] = (int)ceil($abs / $minKg);
        if ($weeklyKgEstimate != 0.0 && (($wantsLoss && $weeklyKgEstimate < 0) || (!$wantsLoss && $weeklyKgEstimate > 0))) {
            $weeks = (int)ceil($abs / abs($weeklyKgEstimate));
            $out['weeks_estimate'] = $weeks;
            $out['estimated_date'] = date('Y-m-d', strtotime($today . ' +' . ($weeks * 7) . ' days'));
        }

        if ($targetDate) {
            $days = (int)floor((strtotime($targetDate) - strtotime($today)) / 86400);
            if ($days <= 0) {
                $out['warnings'][] = 'Cílové datum už proběhlo – zvolte prosím datum v budoucnosti.';
            } else {
                $weeksT = $days / 7;
                $required = $abs / $weeksT;
                $out['weeks_to_target_date'] = round($weeksT, 1);
                $out['required_weekly_kg'] = round($required, 2);
                $out['required_pct'] = $weightKg > 0 ? round($required / $weightKg * 100, 2) : null;
                if ($out['required_pct'] !== null && $out['required_pct'] > max(1.0, $rate['max_pct'])) {
                    $out['warnings'][] = 'Požadované tempo (' . number_format($required, 2, ',', '') . ' kg týdně) je rychlejší, než je dlouhodobě bezpečné. Doporučujeme posunout cílové datum přibližně na ' . date('j. n. Y', strtotime($today . ' +' . ($out['weeks_min'] * 7) . ' days')) . ' nebo později.';
                }
            }
        }
        return $out;
    }
}

if (!function_exists('analysisTimeToMinutes')) {
    function analysisTimeToMinutes(?string $time): ?int {
        if ($time === null || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }
        return $h * 60 + $i;
    }

    function analysisMinutesToTime(int $minutes): string {
        $minutes = (($minutes % 1440) + 1440) % 1440;
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Výchozí čas tréninku podle zvoleného časového okna. */
    function analysisDefaultTrainingTime(string $slot): ?string {
        return ['morning' => '6:30', 'forenoon' => '10:00', 'afternoon' => '15:00', 'evening' => '18:00'][$slot] ?? null;
    }
}

if (!function_exists('analysisMealTemplates')) {
    /**
     * Šablony jídel: název, podíl kalorií, výchozí čas a typ.
     *
     * @return list<array{name:string, share:float, time:string, type:string}>
     */
    function analysisMealTemplates(int $count): array {
        $templates = [
            3 => [['Snídaně', 0.30, '7:00', 'breakfast'], ['Oběd', 0.40, '12:30', 'lunch'], ['Večeře', 0.30, '18:30', 'dinner']],
            4 => [['Snídaně', 0.25, '7:00', 'breakfast'], ['Oběd', 0.35, '12:00', 'lunch'], ['Odpolední svačina', 0.15, '15:30', 'snack_pm'], ['Večeře', 0.25, '19:00', 'dinner']],
            5 => [['Snídaně', 0.25, '7:00', 'breakfast'], ['Dopolední svačina', 0.10, '10:00', 'snack_am'], ['Oběd', 0.30, '12:30', 'lunch'], ['Odpolední svačina', 0.10, '15:30', 'snack_pm'], ['Večeře', 0.25, '19:00', 'dinner']],
            6 => [['Snídaně', 0.20, '7:00', 'breakfast'], ['Dopolední svačina', 0.10, '10:00', 'snack_am'], ['Oběd', 0.25, '12:30', 'lunch'], ['Odpolední svačina', 0.10, '15:30', 'snack_pm'], ['Večeře', 0.25, '18:30', 'dinner'], ['Druhá večeře', 0.10, '21:00', 'snack_late']],
        ];
        $rows = $templates[max(3, min(6, $count))];
        return array_map(static fn(array $r): array => ['name' => $r[0], 'share' => $r[1], 'time' => $r[2], 'type' => $r[3]], $rows);
    }

    /** @return array<string, string> */
    function analysisMealTypeHints(): array {
        return [
            'breakfast' => 'Bílkoviny, sacharidy, tuky a vláknina (např. vejce/skyr, pečivo či vločky, ovoce, ořechy).',
            'snack_am' => 'Lehčí jídlo – ovoce nebo jiný zdroj sacharidů, případně bílkoviny.',
            'lunch' => 'Plnohodnotné jídlo – bílkoviny, příloha, zelenina a přiměřené množství tuků.',
            'snack_pm' => 'Podle času tréninku – před cvičením snadno stravitelné sacharidy a případně bílkoviny.',
            'dinner' => 'Bílkoviny, sacharidy podle denního příjmu a zátěže, zelenina a tuky. Sacharidy večer automaticky neomezujeme.',
            'snack_late' => 'Menší jídlo s bílkovinami (např. tvaroh, skyr, cottage).',
        ];
    }
}

if (!function_exists('analysisMealPlan')) {
    /**
     * Rozložení kalorií a makroživin do jídel podle počtu jídel, časů a času tréninku.
     *
     * @param array<string, int> $macros protein_g, carbs_g, fat_g
     * @param array{breakfast?:?string, lunch?:?string, dinner?:?string} $knownTimes
     * @param list<string> $customTimes
     * @return array{meals:list<array<string, mixed>>, training_time:?string, pre_index:?int, post_index:?int, notes:list<string>}
     */
    function analysisMealPlan(int $kcal, array $macros, int $count, array $knownTimes = [], ?string $trainingTime = null, array $customTimes = []): array {
        $customTimes = array_values(array_filter($customTimes, static fn($t) => analysisTimeToMinutes((string)$t) !== null));
        if ($customTimes) {
            $count = max(2, min(8, count($customTimes)));
        }
        if ($customTimes && ($count < 3 || $count > 6)) {
            $share = 1 / $count;
            $meals = [];
            foreach ($customTimes as $i => $t) {
                $meals[] = ['name' => ($i + 1) . '. jídlo', 'share' => $share, 'time' => $t, 'type' => $i === 0 ? 'breakfast' : 'lunch'];
            }
        } else {
            $meals = analysisMealTemplates($count);
            foreach ($meals as $i => &$meal) {
                if ($customTimes && isset($customTimes[$i])) {
                    $meal['time'] = $customTimes[$i];
                } elseif (isset($knownTimes[$meal['type']]) && analysisTimeToMinutes((string)$knownTimes[$meal['type']]) !== null) {
                    $meal['time'] = (string)$knownTimes[$meal['type']];
                }
            }
            unset($meal);
        }
        usort($meals, static fn($a, $b) => analysisTimeToMinutes($a['time']) <=> analysisTimeToMinutes($b['time']));

        $notes = [];
        $trainMin = analysisTimeToMinutes($trainingTime);
        $preIdx = null;
        $postIdx = null;
        if ($trainMin !== null) {
            foreach ($meals as $i => $meal) {
                $m = analysisTimeToMinutes($meal['time']);
                if ($m <= $trainMin - 30) {
                    $preIdx = $i;
                }
                if ($postIdx === null && $m >= $trainMin + 30) {
                    $postIdx = $i;
                }
            }
            if ($preIdx !== null && $trainMin - analysisTimeToMinutes($meals[$preIdx]['time']) > 240) {
                $notes[] = 'Mezi posledním jídlem a tréninkem je více než 4 hodiny – zvažte malou svačinu 30–90 minut před tréninkem.';
            }
            if ($postIdx === null) {
                $notes[] = 'Po tréninku nenásleduje žádné plánované jídlo – zařaďte jídlo s bílkovinami a sacharidy do 1–2 hodin.';
            }
        }

        // Váhy pro sacharidy a tuky: kolem tréninku více sacharidů, před tréninkem méně tuku.
        $carbW = [];
        $fatW = [];
        foreach ($meals as $i => $meal) {
            $carbW[$i] = $meal['share'] * (($i === $preIdx || $i === $postIdx) ? 1.25 : 1.0);
            $fatW[$i] = $meal['share'] * ($i === $preIdx ? 0.6 : 1.0);
        }
        $sumShare = array_sum(array_column($meals, 'share')) ?: 1;
        $sumCarb = array_sum($carbW) ?: 1;
        $sumFat = array_sum($fatW) ?: 1;

        $p = (int)($macros['protein_g'] ?? 0);
        $c = (int)($macros['carbs_g'] ?? 0);
        $f = (int)($macros['fat_g'] ?? 0);
        $hints = analysisMealTypeHints();
        $out = [];
        $acc = ['p' => 0, 'c' => 0, 'f' => 0];
        $last = count($meals) - 1;
        foreach ($meals as $i => $meal) {
            if ($i === $last) {
                $mp = $p - $acc['p'];
                $mc = $c - $acc['c'];
                $mf = $f - $acc['f'];
            } else {
                $mp = (int)round($p * $meal['share'] / $sumShare);
                $mc = (int)round($c * $carbW[$i] / $sumCarb);
                $mf = (int)round($f * $fatW[$i] / $sumFat);
            }
            $acc['p'] += $mp;
            $acc['c'] += $mc;
            $acc['f'] += $mf;
            $role = $i === $preIdx ? 'pre' : ($i === $postIdx ? 'post' : null);
            $hint = $hints[$meal['type']] ?? '';
            if ($role === 'pre') {
                $hint = 'Jídlo před tréninkem: snadno stravitelné sacharidy, střední porce bílkovin, méně tuku a vlákniny.';
            } elseif ($role === 'post') {
                $hint = 'Jídlo po tréninku: dostatek bílkovin (20–40 g) a sacharidů pro regeneraci.';
            }
            $out[] = [
                'name' => $meal['name'],
                'time' => $meal['time'],
                'type' => $meal['type'],
                'role' => $role,
                'protein_g' => $mp,
                'carbs_g' => $mc,
                'fat_g' => $mf,
                'kcal' => $mp * 4 + $mc * 4 + $mf * 9,
                'share_pct' => round(($mp * 4 + $mc * 4 + $mf * 9) / max(1, $kcal) * 100),
                'hint' => $hint,
            ];
        }
        return ['meals' => $out, 'training_time' => $trainMin !== null ? analysisMinutesToTime($trainMin) : null, 'pre_index' => $preIdx, 'post_index' => $postIdx, 'notes' => $notes];
    }
}

if (!function_exists('analysisTrainingTimingVariants')) {
    /**
     * Čtyři varianty stravování podle času tréninku (ranní, dopolední, odpolední, večerní).
     *
     * @return array<string, array{title:string, before:string, when:string, during:string, after:string, next_meal:string}>
     */
    function analysisTrainingTimingVariants(int $durationMin, float $weightKg, bool $endurance): array {
        $preCarbs = (int)round($weightKg * 1.0);
        $postProtein = (int)max(20, min(40, round($weightKg * 0.3)));
        $postCarbs = (int)round($weightKg * ($endurance ? 1.0 : 0.6));
        $during = $durationMin >= 90 || ($endurance && $durationMin >= 75)
            ? 'Při výkonu nad 60–90 minut doplňujte 30–60 g sacharidů za hodinu (iontový nápoj, gel, banán) a tekutiny se sodíkem.'
            : 'Při tréninku do 60–75 minut obvykle stačí voda; při velkém pocení iontový nápoj.';
        return [
            'morning' => [
                'title' => 'Ranní trénink',
                'before' => 'Malá, snadno stravitelná svačina – banán, rohlík s medem, jogurt nebo smoothie (cca ' . (int)round($preCarbs * 0.5) . ' g sacharidů).',
                'when' => '30–60 minut před tréninkem. Pokud trénujete nalačno, zvolte nízkou intenzitu a dobře se najezte večer předem.',
                'during' => $during,
                'after' => 'Plnohodnotná snídaně do 1 hodiny – cca ' . $postProtein . ' g bílkovin a ' . $postCarbs . ' g sacharidů (např. vejce, vločky, pečivo, ovoce).',
                'next_meal' => 'Dopolední svačina nebo oběd podle běžného režimu (3–4 hodiny po snídani).',
            ],
            'forenoon' => [
                'title' => 'Dopolední trénink',
                'before' => 'Vydatná snídaně s bílkovinami a sacharidy (cca ' . $preCarbs . ' g sacharidů), případně malá svačina před tréninkem.',
                'when' => 'Snídaně 2–3 hodiny před tréninkem, svačina 30–90 minut před výkonem podle tolerance.',
                'during' => $during,
                'after' => 'Oběd nebo svačina do 1–2 hodin – cca ' . $postProtein . ' g bílkovin a ' . $postCarbs . ' g sacharidů.',
                'next_meal' => 'Oběd jako plnohodnotné jídlo, odpoledne běžná svačina.',
            ],
            'afternoon' => [
                'title' => 'Odpolední trénink',
                'before' => 'Oběd jako hlavní jídlo 2–4 hodiny před tréninkem, odpolední svačina se sacharidy (ovoce, pečivo, jogurt).',
                'when' => 'Oběd 2–4 hodiny a svačina 30–90 minut před výkonem.',
                'during' => $during,
                'after' => 'Svačina nebo večeře do 1–2 hodin – cca ' . $postProtein . ' g bílkovin a ' . $postCarbs . ' g sacharidů.',
                'next_meal' => 'Večeře jako plnohodnotné jídlo včetně sacharidů.',
            ],
            'evening' => [
                'title' => 'Večerní trénink',
                'before' => 'Odpolední svačina se sacharidy a bílkovinami (cca ' . (int)round($preCarbs * 0.7) . ' g sacharidů), lehčí na tuky a vlákninu.',
                'when' => 'Oběd 4–5 hodin předem, svačina 1–2 hodiny před tréninkem.',
                'during' => $during,
                'after' => 'Večeře po tréninku – cca ' . $postProtein . ' g bílkovin a ' . $postCarbs . ' g sacharidů. Sacharidy večer neomezujte, podporují regeneraci i spánek.',
                'next_meal' => 'Další den běžná snídaně; při velmi pozdním tréninku stačí menší večeře s bílkovinami.',
            ],
        ];
    }
}

if (!function_exists('analysisFoodSuggestions')) {
    /**
     * Návrhy potravin podle skupin, filtrované podle stravovacího omezení, alergií a nechutí.
     *
     * @return array<string, list<string>>
     */
    function analysisFoodSuggestions(string $diet, string $allergies = '', string $disliked = ''): array {
        $foods = [
            'protein' => [
                ['Kuřecí prsa', ['maso']], ['Krůtí maso', ['maso']], ['Libové hovězí', ['maso']], ['Losos', ['ryby', 'maso']], ['Tuňák', ['ryby', 'maso']],
                ['Vejce', ['vejce']], ['Skyr / řecký jogurt', ['mléko', 'laktóza']], ['Tvaroh', ['mléko', 'laktóza']], ['Cottage', ['mléko', 'laktóza']],
                ['Tofu', ['sója']], ['Tempeh', ['sója']], ['Luštěniny (čočka, cizrna, fazole)', []], ['Seitan', ['lepek']], ['Rostlinný protein', []],
            ],
            'carbs' => [
                ['Ovesné vločky', ['lepek']], ['Rýže', []], ['Brambory / batáty', []], ['Celozrnné pečivo', ['lepek']], ['Těstoviny', ['lepek']],
                ['Pohanka', []], ['Quinoa', []], ['Jáhly', []], ['Ovoce (banán, jablko, bobuloviny)', []], ['Rýžové chlebíčky', []],
            ],
            'fat' => [
                ['Olivový olej', []], ['Avokádo', []], ['Ořechy', ['ořech']], ['Arašídové máslo', ['ořech', 'arašíd']], ['Semínka (chia, lněná, dýňová)', []],
                ['Tučné ryby', ['ryby', 'maso']], ['Hořká čokoláda', ['mléko']],
            ],
            'vegetables' => [
                ['Brokolice', []], ['Paprika', []], ['Rajčata', []], ['Listová zelenina / špenát', []], ['Mrkev', []], ['Okurka', []], ['Cuketa', []], ['Zelené fazolky', []],
            ],
        ];
        $exclude = [];
        if ($diet === 'vegetarian') {
            $exclude = ['maso', 'ryby'];
        } elseif ($diet === 'vegan') {
            $exclude = ['maso', 'ryby', 'vejce', 'mléko', 'laktóza'];
        } elseif ($diet === 'gluten_free') {
            $exclude = ['lepek'];
        } elseif ($diet === 'lactose_free') {
            $exclude = ['laktóza'];
        }
        $keywordMap = [
            'ořech' => ['ořech'], 'orech' => ['ořech'], 'arašíd' => ['arašíd'], 'arasid' => ['arašíd'], 'burák' => ['arašíd'],
            'lepek' => ['lepek'], 'gluten' => ['lepek'], 'laktóz' => ['laktóza'], 'laktoz' => ['laktóza'], 'mlék' => ['mléko', 'laktóza'], 'mlek' => ['mléko', 'laktóza'],
            'vejce' => ['vejce'], 'vajíč' => ['vejce'], 'ryb' => ['ryby'], 'sój' => ['sója'], 'soj' => ['sója'],
        ];
        $allergyText = mb_strtolower($allergies);
        foreach ($keywordMap as $kw => $tags) {
            if ($allergyText !== '' && mb_strpos($allergyText, $kw) !== false) {
                $exclude = array_merge($exclude, $tags);
            }
        }
        $dislikedWords = array_filter(array_map('trim', preg_split('/[,;\n]+/u', mb_strtolower($disliked)) ?: []), static fn($w) => mb_strlen($w) >= 3);
        $out = [];
        foreach ($foods as $group => $items) {
            $out[$group] = [];
            foreach ($items as [$name, $tags]) {
                if (array_intersect($tags, $exclude)) {
                    continue;
                }
                $lname = mb_strtolower($name);
                foreach ($dislikedWords as $w) {
                    if (mb_strpos($lname, mb_substr($w, 0, max(3, mb_strlen($w) - 2))) !== false) {
                        continue 2;
                    }
                }
                $out[$group][] = $name;
            }
        }
        return $out;
    }
}

if (!function_exists('analysisTrainingRecommendations')) {
    /**
     * Obecné doporučení frekvence a typu pohybu podle cíle, zkušeností a současné aktivity.
     * Nejde o tréninkový plán a nepřepisuje plán vytvořený trenérem.
     *
     * @param list<string> $activityTypes
     * @return array{strength:string, cardio:string, types:list<string>, duration:string, recovery:list<string>, progression:list<string>, notes:list<string>}
     */
    function analysisTrainingRecommendations(string $goal, string $experience, string $sessionsKey, string $durationKey, array $activityTypes, string $stepsKey = ''): array {
        $beginner = $experience === 'beginner';
        $advanced = $experience === 'advanced';
        $current = analysisSessionsAverage($sessionsKey);
        $notes = [];
        $walking = in_array($stepsKey, ['lt3000', '3000-5000'], true)
            ? 'Postupně navyšujte denní počet kroků (cíl 7 000–10 000).'
            : 'Udržujte pravidelnou chůzi (7 000–10 000 kroků denně).';

        switch ($goal) {
            case 'fat_loss':
            case 'fat_loss_keep_muscle':
            case 'body_composition':
                $strength = $beginner ? '2–3× týdně silový trénink celého těla' : '3× týdně silový trénink';
                $cardio = '2× týdně aerobní aktivita (např. svižná chůze, kolo, plavání, 30–45 min)';
                $types = ['Silový trénink (vícekloubové cviky)', 'Aerobní aktivita střední intenzity', 'Pravidelná chůze', $beginner ? 'Kruhový trénink' : 'Intervaly (HIIT) max. 1× týdně'];
                $duration = '45–60 minut silový trénink, 30–45 minut aerobní aktivita';
                $progression = ['Udržujte nebo postupně navyšujte zátěž v silovém tréninku – je to hlavní signál pro zachování svalů.', $walking];
                break;
            case 'recomp':
                $strength = $beginner ? '3× týdně silový trénink celého těla' : '3–4× týdně silový trénink';
                $cardio = '1–2× týdně lehčí aerobní aktivita';
                $types = ['Silový trénink s progresivním přetížením', 'Lehká aerobní aktivita', 'Pravidelná chůze'];
                $duration = '50–75 minut';
                $progression = ['Postupně navyšujte váhu nebo počet opakování.', 'Rekompozice je pomalejší – sledujte obvody a sílu, nejen hmotnost.'];
                break;
            case 'muscle_gain':
            case 'weight_gain':
                $strength = $beginner ? '3× týdně silový trénink celého těla' : ($advanced ? '4–5× týdně silový trénink (dělený)' : '3–5× týdně silový trénink podle regenerace');
                $cardio = '1–2× týdně přiměřená aerobní aktivita (20–30 min) pro zdraví srdce';
                $types = ['Silový trénink zaměřený na hypertrofii', 'Vícekloubové i izolované cviky', 'Lehká aerobní aktivita'];
                $duration = '60–75 minut';
                $progression = ['Postupné navyšování tréninkové zátěže (progresivní přetížení).', 'Každou svalovou skupinu procvičte 2× týdně s dostatečnou regenerací.'];
                break;
            case 'strength':
                $strength = $beginner ? '3× týdně silový trénink celého těla' : '3–4× týdně silový trénink';
                $cardio = '1–2× týdně lehká aerobní aktivita';
                $types = ['Základní silové cviky (dřep, mrtvý tah, tlaky, přítahy)', 'Doplňkové cviky', 'Mobilita'];
                $duration = '60–90 minut včetně delších pauz mezi sériemi';
                $progression = ['Plánujte postupné navyšování zátěže a odlehčovací týdny.', 'Technika má přednost před maximální vahou.'];
                break;
            case 'endurance':
            case 'performance':
                $strength = '2× týdně silový trénink jako doplněk';
                $cardio = $beginner ? '3× týdně vytrvalostní trénink' : ($advanced ? '4–6× týdně vytrvalostní trénink' : '3–5× týdně vytrvalostní trénink');
                $types = ['Vytrvalostní trénink (převážně nízká intenzita)', '1–2× týdně intervaly / tempový trénink', 'Silový a stabilizační trénink', 'Mobilita'];
                $duration = 'Podle typu tréninku 30–120 minut; dlouhý trénink 1× týdně';
                $progression = ['Postupné navyšování objemu (max. cca 10 % týdně).', 'Zajistěte dostatečnou energetickou dostupnost.'];
                break;
            case 'maintain':
            default:
                $strength = '2–3× týdně silový trénink';
                $cardio = '2–3× týdně aerobní aktivita';
                $types = ['Silový trénink', 'Aerobní aktivita', 'Chůze', 'Mobilita / jóga'];
                $duration = '45–60 minut';
                $progression = [$walking, 'Kombinujte aktivity, které vás baví – dlouhodobá pravidelnost je klíčová.'];
                break;
        }

        $recovery = ['Alespoň 1–2 dny v týdnu bez náročného tréninku.', 'Spánek 7–9 hodin denně.', 'Mezi tréninky stejné svalové partie ponechte cca 48 hodin.'];
        if ($current >= 6) {
            $recovery[] = 'Trénujete velmi často – sledujte únavu a zařaďte lehčí týden každé 4–6 týdny.';
        }
        if ($current === 0.0) {
            $notes[] = 'Nyní netrénujete – začněte 2× týdně a postupně přidávejte.';
        }
        $hasStrength = (bool)array_intersect($activityTypes, ['strength', 'circuit', 'trx', 'bodypump', 'functional', 'hyrox']);
        if (!$hasStrength && !in_array($goal, ['endurance'], true)) {
            $notes[] = 'Ve vašich aktivitách chybí silový trénink – pro zachování a růst svalové hmoty jej doporučujeme zařadit.';
        }
        $notes[] = 'Jde o obecné doporučení frekvence a typu pohybu, ne o kompletní tréninkový plán. Plán vytvořený trenérem má vždy přednost.';

        return ['strength' => $strength, 'cardio' => $cardio, 'types' => $types, 'duration' => $duration, 'recovery' => $recovery, 'progression' => $progression, 'notes' => $notes];
    }
}

if (!function_exists('analysisDayTypes')) {
    /**
     * Rozdílný příjem pro tréninkové a odpočinkové dny. Týdenní průměr zůstává shodný s denním cílem.
     *
     * @return array{enabled:bool, training_days:int, training:array{kcal:int, carbs_g:int}, rest:array{kcal:int, carbs_g:int}, weekly_avg_kcal:int}
     */
    function analysisDayTypes(int $kcal, int $carbsG, int $trainingDays): array {
        $n = max(0, min(7, $trainingDays));
        if ($n === 0 || $n === 7 || $carbsG <= 0) {
            return ['enabled' => false, 'training_days' => $n, 'training' => ['kcal' => $kcal, 'carbs_g' => $carbsG], 'rest' => ['kcal' => $kcal, 'carbs_g' => $carbsG], 'weekly_avg_kcal' => $kcal];
        }
        $plus = (int)round($carbsG * 0.15);
        $minus = (int)round($plus * $n / (7 - $n));
        $minus = min($minus, (int)round($carbsG * 0.35));
        $plus = (int)round($minus * (7 - $n) / $n);
        $train = ['kcal' => $kcal + $plus * 4, 'carbs_g' => $carbsG + $plus];
        $rest = ['kcal' => $kcal - $minus * 4, 'carbs_g' => $carbsG - $minus];
        $avg = (int)round(($train['kcal'] * $n + $rest['kcal'] * (7 - $n)) / 7);
        return ['enabled' => true, 'training_days' => $n, 'training' => $train, 'rest' => $rest, 'weekly_avg_kcal' => $avg];
    }
}

if (!function_exists('analysisDisclaimers')) {
    /** @return list<string> */
    function analysisDisclaimers(): array {
        return [
            'Výsledky jsou orientační a slouží pro výživová a sportovní doporučení, nikoliv pro diagnostiku nebo léčbu.',
            'BMR (bazální metabolismus) není doporučený kalorický příjem.',
            'TDEE je odhad celkového výdeje, nikoliv přesné měření.',
            'BMI nerozlišuje svalovou a tukovou hmotu – u svalnatých sportovců může množství tuku nadhodnocovat.',
            'Procento tuku vypočítané z obvodů (metoda US Navy) je pouze odhad.',
            'Doporučení nenahrazuje individuální konzultaci s lékařem, nutričním terapeutem nebo jiným kvalifikovaným odborníkem.',
        ];
    }
}

if (!function_exists('analysisStepFields')) {
    /** Pole formuláře rozdělená do 5 kroků průvodce. */
    function analysisStepFields(): array {
        return [
            1 => ['sex', 'age', 'height_cm', 'weight_kg', 'target_weight_kg', 'weigh_date', 'waist_cm', 'hip_cm', 'neck_cm', 'chest_cm', 'arm_cm', 'thigh_cm', 'special', 'save_weight'],
            2 => ['goal', 'secondary_goal', 'target_date'],
            3 => ['job_activity', 'sessions', 'duration', 'activity_types', 'steps_bucket', 'steps_exact', 'experience'],
            4 => ['meals_now', 'breakfast_time', 'lunch_time', 'dinner_time', 'training_slot', 'training_time', 'regular_breakfast', 'eats_late', 'snacking', 'enough_fluids', 'alcohol', 'diet', 'allergies'],
            5 => ['prep_time', 'cooking', 'eat_at_work', 'meal_prep', 'meals_desired', 'custom_times', 'eating_window_from', 'eating_window_to', 'preferred_foods', 'disliked_foods'],
        ];
    }

    /** @return array<string, string> */
    function analysisStepTitles(): array {
        return [1 => 'Základní údaje', 2 => 'Cíl', 3 => 'Pohyb a trénink', 4 => 'Stravovací návyky', 5 => 'Preference a režim'];
    }
}

if (!function_exists('analysisValidateInput')) {
    /**
     * Normalizace a validace vstupů. $requireSteps určuje, které kroky musí být úplné (např. [1] nebo [1,2,3,4,5]).
     *
     * @param array<string, mixed> $raw
     * @param list<int> $requireSteps
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    function analysisValidateInput(array $raw, array $requireSteps = [1, 2, 3, 4, 5], ?string $today = null): array {
        $today = $today ?: date('Y-m-d');
        $errors = [];
        $num = static function ($v): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            $v = str_replace([' ', ','], ['', '.'], (string)$v);
            return is_numeric($v) ? (float)$v : null;
        };
        $pick = static fn($v, array $options, ?string $default = null): ?string => (is_scalar($v) && array_key_exists((string)$v, $options)) ? (string)$v : $default;
        $time = static fn($v): ?string => (is_string($v) && analysisTimeToMinutes($v) !== null) ? analysisMinutesToTime((int)analysisTimeToMinutes($v)) : null;
        $date = static function ($v): ?string {
            if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return null;
            }
            [$y, $m, $d] = array_map('intval', explode('-', $v));
            return checkdate($m, $d, $y) ? $v : null;
        };
        $text = static fn($v, int $max = 500): string => mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max);
        $bool = static fn($v): ?bool => ($v === '1' || $v === 1 || $v === true || $v === 'yes') ? true : (($v === '0' || $v === 0 || $v === false || $v === 'no') ? false : null);

        $in = [];
        $in['sex'] = $pick($raw['sex'] ?? null, ['male' => 1, 'female' => 1]);
        $age = $num($raw['age'] ?? null);
        $in['age'] = $age !== null ? (int)round($age) : null;
        $in['height_cm'] = $num($raw['height_cm'] ?? null);
        $in['weight_kg'] = $num($raw['weight_kg'] ?? null);
        $in['target_weight_kg'] = $num($raw['target_weight_kg'] ?? null);
        $in['weigh_date'] = $date($raw['weigh_date'] ?? null) ?? $today;
        foreach (analysisCircumferenceFields() as $key => $meta) {
            $in[$key] = $num($raw[$key] ?? null);
            if ($in[$key] !== null && ($in[$key] < $meta['min'] || $in[$key] > $meta['max'])) {
                $errors[$key] = $meta['label'] . ' musí být mezi ' . (int)$meta['min'] . ' a ' . (int)$meta['max'] . ' cm.';
                $in[$key] = null;
            }
        }
        $special = is_array($raw['special'] ?? null) ? $raw['special'] : [];
        $in['special'] = array_values(array_intersect(array_keys(analysisSpecialConditionOptions()), array_map('strval', $special)));
        $in['save_weight'] = (bool)$bool($raw['save_weight'] ?? null);

        $in['goal'] = $pick($raw['goal'] ?? null, analysisGoalOptions());
        $in['secondary_goal'] = $pick($raw['secondary_goal'] ?? null, analysisSecondaryGoalOptions(), 'none');
        $in['target_date'] = $date($raw['target_date'] ?? null);

        $in['job_activity'] = $pick($raw['job_activity'] ?? null, analysisJobActivityOptions());
        $in['sessions'] = $pick($raw['sessions'] ?? null, analysisSessionsOptions());
        $in['duration'] = $pick($raw['duration'] ?? null, analysisDurationOptions(), '45-60');
        $types = is_array($raw['activity_types'] ?? null) ? $raw['activity_types'] : [];
        $in['activity_types'] = array_values(array_intersect(array_keys(analysisActivityTypeOptions()), array_map('strval', $types)));
        $steps = $num($raw['steps_exact'] ?? null);
        $in['steps_exact'] = ($steps !== null && $steps > 0 && $steps <= 60000) ? (int)$steps : null;
        $in['steps_bucket'] = analysisStepsBucket($in['steps_exact']) ?? $pick($raw['steps_bucket'] ?? null, analysisStepsOptions());
        $in['experience'] = $pick($raw['experience'] ?? null, analysisExperienceOptions(), 'intermediate');

        $in['meals_now'] = $pick($raw['meals_now'] ?? null, ['1' => 1, '2' => 1, '3' => 1, '4' => 1, '5' => 1, '6' => 1, '7' => 1]);
        foreach (['breakfast_time', 'lunch_time', 'dinner_time', 'training_time', 'eating_window_from', 'eating_window_to'] as $k) {
            $in[$k] = $time($raw[$k] ?? null);
        }
        $in['training_slot'] = $pick($raw['training_slot'] ?? null, analysisTrainingSlotOptions(), 'varies');
        foreach (['regular_breakfast', 'eats_late', 'snacking', 'enough_fluids', 'alcohol', 'eat_at_work', 'meal_prep'] as $k) {
            $in[$k] = $bool($raw[$k] ?? null);
        }
        $in['diet'] = $pick($raw['diet'] ?? null, analysisDietOptions(), 'none');
        $in['allergies'] = $text($raw['allergies'] ?? '');

        $in['prep_time'] = $pick($raw['prep_time'] ?? null, analysisPrepTimeOptions());
        $in['cooking'] = $pick($raw['cooking'] ?? null, analysisCookingOptions());
        $in['meals_desired'] = $pick($raw['meals_desired'] ?? null, analysisMealsCountOptions(), '4');
        $customRaw = is_array($raw['custom_times'] ?? null) ? implode(',', $raw['custom_times']) : (string)($raw['custom_times'] ?? '');
        $custom = [];
        foreach (preg_split('/[,;\s]+/', $customRaw) ?: [] as $t) {
            $t = $time(str_replace('.', ':', $t));
            if ($t !== null) {
                $custom[] = $t;
            }
        }
        $custom = array_values(array_unique($custom));
        usort($custom, static fn($a, $b) => analysisTimeToMinutes($a) <=> analysisTimeToMinutes($b));
        $in['custom_times'] = array_slice($custom, 0, 8);
        $in['preferred_foods'] = $text($raw['preferred_foods'] ?? '');
        $in['disliked_foods'] = $text($raw['disliked_foods'] ?? '');

        $sweat = $num($raw['sweat_rate_l_h'] ?? null);
        $in['sweat_rate_l_h'] = ($sweat !== null && $sweat > 0 && $sweat <= 4) ? round($sweat, 2) : null;

        // Povinná pole podle kroků.
        if (in_array(1, $requireSteps, true)) {
            if ($in['sex'] === null) {
                $errors['sex'] = 'Vyberte pohlaví (pro výpočet BMR je nutné).';
            }
            if ($in['age'] === null || $in['age'] < 10 || $in['age'] > 100) {
                $errors['age'] = 'Zadejte věk 10–100 let.';
            }
            if ($in['height_cm'] === null || $in['height_cm'] < 120 || $in['height_cm'] > 230) {
                $errors['height_cm'] = 'Zadejte výšku 120–230 cm.';
            }
            if ($in['weight_kg'] === null || $in['weight_kg'] < 30 || $in['weight_kg'] > 300) {
                $errors['weight_kg'] = 'Zadejte hmotnost 30–300 kg.';
            }
            if ($in['target_weight_kg'] !== null && ($in['target_weight_kg'] < 30 || $in['target_weight_kg'] > 300)) {
                $errors['target_weight_kg'] = 'Cílová hmotnost musí být 30–300 kg.';
            }
            if ($in['weigh_date'] > $today) {
                $errors['weigh_date'] = 'Datum vážení nemůže být v budoucnosti.';
            }
            if ($in['waist_cm'] !== null && $in['neck_cm'] !== null && $in['waist_cm'] <= $in['neck_cm']) {
                $errors['waist_cm'] = 'Obvod pasu musí být větší než obvod krku.';
            }
        }
        if (in_array(2, $requireSteps, true)) {
            if ($in['goal'] === null) {
                $errors['goal'] = 'Vyberte hlavní cíl.';
            }
            if ($in['target_date'] !== null && $in['target_date'] <= $today) {
                $errors['target_date'] = 'Cílové datum musí být v budoucnosti.';
            }
        }
        if (in_array(3, $requireSteps, true)) {
            if ($in['job_activity'] === null) {
                $errors['job_activity'] = 'Vyberte typ zaměstnání.';
            }
            if ($in['sessions'] === null) {
                $errors['sessions'] = 'Vyberte počet tréninků týdně.';
            }
        }
        if (in_array(4, $requireSteps, true) && $in['training_slot'] !== 'varies' && $in['training_time'] === null) {
            $in['training_time'] = analysisDefaultTrainingTime($in['training_slot']);
        }
        if (in_array(5, $requireSteps, true) && $in['meals_desired'] === 'custom' && count($in['custom_times']) < 2) {
            $errors['custom_times'] = 'Pro individuální režim zadejte alespoň 2 časy jídel (např. 7:00, 12:00, 18:30).';
        }
        return [$in, $errors];
    }
}

if (!function_exists('analysisBuild')) {
    /**
     * Sestaví kompletní výsledek analýzy z validovaných vstupů.
     *
     * @param array<string, mixed> $in výstup analysisValidateInput()
     * @param array<string, mixed> $coachTargets kcal, protein_g, fat_g, carbs_g (volitelné úpravy trenéra)
     * @return array<string, mixed>
     */
    function analysisBuild(array $in, array $coachTargets = [], ?string $today = null): array {
        $today = $today ?: date('Y-m-d');
        $sex = (string)($in['sex'] ?? '');
        $age = (int)($in['age'] ?? 0);
        $height = (float)($in['height_cm'] ?? 0);
        $weight = (float)($in['weight_kg'] ?? 0);
        $goal = (string)($in['goal'] ?? 'maintain');
        $secondary = (string)($in['secondary_goal'] ?? 'none');
        $minor = $age > 0 && $age < 18;
        $missing = [];
        $warnings = [];

        $bmi = calculatorBmiValue($weight, $height);
        $bmiCat = $bmi !== null ? calculatorBmiCategories()[calculatorBmiCategoryIndex($bmi)] : null;
        $bf = calculateBodyFat($sex, $height, $in['neck_cm'] ?? null, $in['waist_cm'] ?? null, $in['hip_cm'] ?? null);
        if ($bf === null) {
            $missing[] = $sex === 'female'
                ? 'Procento tuku nelze odhadnout – chybí obvod krku, pasu nebo boků.'
                : 'Procento tuku nelze odhadnout – chybí obvod krku nebo pasu.';
        }
        $fatMass = calculateFatMass($weight, $bf);
        $lean = calculateLeanBodyMass($weight, $fatMass);
        $whr = calculateWHR($in['waist_cm'] ?? null, $in['hip_cm'] ?? null);
        if ($whr === null) {
            $missing[] = 'WHR nelze vypočítat – chybí obvod pasu nebo boků.';
        }

        $bmr = calculateBMR($sex, $weight, $height, $age);
        $activity = analysisActivityLevel((string)($in['job_activity'] ?? 'sedentary'), (string)($in['sessions'] ?? '0'), (string)($in['duration'] ?? '45-60'), $in['steps_bucket'] ?? null);
        $tdee = $bmr !== null ? calculateTDEE($bmr, $activity['factor']) : null;
        if ($minor) {
            $warnings[] = 'Sportovec je nezletilý – výpočty BMR a TDEE pro dospělé jsou jen orientační a nepoužívají se redukční ani nabírací algoritmy.';
        }

        $hasStrength = (bool)array_intersect($in['activity_types'] ?? [], ['strength', 'circuit', 'trx', 'bodypump', 'functional', 'hyrox'])
            || in_array($goal, ['muscle_gain', 'strength', 'recomp', 'fat_loss_keep_muscle'], true);
        $calories = null;
        $macros = null;
        $mealPlan = null;
        $dayTypes = null;
        $timeline = null;
        $coachApplied = [];
        if ($tdee !== null) {
            $calories = calculateCaloriesByGoal($tdee, $goal, [
                'secondary' => $secondary, 'sex' => $sex, 'bmi' => $bmi, 'body_fat_pct' => $bf, 'minor' => $minor,
                'special' => $in['special'] ?? [], 'experience' => $in['experience'] ?? 'intermediate',
            ]);
            if (!$calories['blocked']) {
                $kcal = (int)$calories['kcal'];
                $ck = isset($coachTargets['kcal']) ? (int)$coachTargets['kcal'] : 0;
                if ($ck >= 1000 && $ck <= 6000) {
                    $kcal = $ck;
                    $coachApplied[] = 'kcal';
                    $calories['kcal'] = $kcal;
                    $calories['adjust_kcal'] = (int)round($kcal - $tdee);
                    $calories['adjust_pct'] = round(($kcal - $tdee) / $tdee * 100, 1);
                    $calories['mode'] = $calories['adjust_kcal'] <= -25 ? 'deficit' : ($calories['adjust_kcal'] >= 25 ? 'surplus' : 'maintenance');
                    $calories['mode_label'] = ['deficit' => 'Kalorický deficit', 'surplus' => 'Kalorický nadbytek', 'maintenance' => 'Udržovací příjem'][$calories['mode']];
                    $calories['notes'][] = 'Kalorický cíl upravil trenér.';
                }
                $pg = isset($coachTargets['protein_g']) && (int)$coachTargets['protein_g'] > 0 ? (int)$coachTargets['protein_g'] : null;
                $fg = isset($coachTargets['fat_g']) && (int)$coachTargets['fat_g'] > 0 ? (int)$coachTargets['fat_g'] : null;
                if ($pg !== null) {
                    $coachApplied[] = 'protein_g';
                }
                if ($fg !== null) {
                    $coachApplied[] = 'fat_g';
                }
                $macros = calculateMacros($kcal, $weight, $goal, [
                    'secondary' => $secondary, 'lean_mass_kg' => $lean, 'bmi' => $bmi, 'height_cm' => $height, 'has_strength' => $hasStrength,
                    'training_hours_week' => $activity['training_hours_week'], 'minor' => $minor, 'protein_g' => $pg, 'fat_g' => $fg,
                ]);

                $count = ($in['meals_desired'] ?? '4') === 'custom' ? count($in['custom_times'] ?? []) : (int)($in['meals_desired'] ?? 4);
                $trainingTime = $in['training_time'] ?? (($in['training_slot'] ?? 'varies') !== 'varies' ? analysisDefaultTrainingTime((string)$in['training_slot']) : null);
                $mealPlan = analysisMealPlan($kcal, $macros, max(3, $count), [
                    'breakfast' => $in['breakfast_time'] ?? null, 'lunch' => $in['lunch_time'] ?? null, 'dinner' => $in['dinner_time'] ?? null,
                ], $trainingTime, ($in['meals_desired'] ?? '') === 'custom' ? ($in['custom_times'] ?? []) : []);
                $dayTypes = analysisDayTypes($kcal, $macros['carbs_g'], (int)round(analysisSessionsAverage((string)($in['sessions'] ?? '0'))));
            }
            $timeline = calculateTargetTimeline($weight, $in['target_weight_kg'] ?? null, $goal, (int)($calories['adjust_kcal'] ?? 0), $in['target_date'] ?? null, $today);
        } else {
            $missing[] = 'BMR a TDEE nelze vypočítat – chybí pohlaví, věk, výška nebo hmotnost.';
        }

        $hydration = $weight > 0 ? calculateHydration($weight, $activity['training_hours_week'], $in['sweat_rate_l_h'] ?? null) : null;
        $durationMin = analysisDurationAverageMinutes((string)($in['duration'] ?? '45-60'));
        $endurance = $goal === 'endurance' || (bool)array_intersect($in['activity_types'] ?? [], ['run_outdoor', 'run_treadmill', 'cycling', 'swimming', 'hyrox']);

        $habitTips = [];
        if (($in['regular_breakfast'] ?? null) === false) {
            $habitTips[] = 'Nesnídáte pravidelně – nevadí, pokud zvládnete denní příjem v ostatních jídlech. Před ranním tréninkem ale zvažte alespoň malou svačinu.';
        }
        if (($in['enough_fluids'] ?? null) === false) {
            $habitTips[] = 'Uvádíte nedostatečný pitný režim – mějte u sebe láhev a pijte průběžně během dne.';
        }
        if (($in['alcohol'] ?? null) === true) {
            $habitTips[] = 'Alkohol přináší energii (7 kcal/g) a zhoršuje regeneraci – započítejte jej do denního příjmu a omezte v tréninkové dny.';
        }
        if (($in['snacking'] ?? null) === true) {
            $habitTips[] = 'Častější mlsání: naplánujte svačiny s bílkovinami a vlákninou, lépe zasytí.';
        }
        if (($in['eats_late'] ?? null) === true) {
            $habitTips[] = 'Jíte pozdě večer – to samo o sobě nevadí; rozhodující je celkový denní příjem. Poslední větší jídlo ideálně 2–3 h před spaním kvůli spánku.';
        }
        if (($in['meal_prep'] ?? null) === true) {
            $habitTips[] = 'Připravujete jídlo dopředu – využijte to: 2× týdně uvařte bílkovinu a přílohu na více dní.';
        } elseif (($in['eat_at_work'] ?? null) === false || ($in['prep_time'] ?? '') === 'lt15') {
            $habitTips[] = 'Máte málo času – volte rychlé varianty (skyr, tvaroh, pečivo, ovoce, hotové luštěniny, tuňák).';
        }

        return [
            'version' => 1,
            'computed_at' => $today,
            'minor' => $minor,
            'special' => $in['special'] ?? [],
            'missing' => $missing,
            'warnings' => array_values(array_merge($warnings, $timeline['warnings'] ?? [])),
            'body' => [
                'weight_kg' => $weight,
                'height_cm' => $height,
                'bmi' => $bmi,
                'bmi_label' => $bmiCat['label'] ?? null,
                'bmi_tone' => $bmiCat['tone'] ?? null,
                'body_fat_pct' => $bf,
                'body_fat_category' => $bf !== null ? analysisBodyFatCategory($sex, $bf) : null,
                'fat_mass_kg' => $fatMass,
                'lean_mass_kg' => $lean,
                'whr' => $whr,
                'whr_interpretation' => $whr !== null && $sex !== '' ? analysisWhrInterpretation($sex, $whr) : null,
                'waist_cm' => $in['waist_cm'] ?? null,
                'hip_cm' => $in['hip_cm'] ?? null,
            ],
            'metabolism' => [
                'bmr' => $bmr,
                'tdee' => $tdee,
                'activity' => $activity,
            ],
            'calories' => $calories,
            'macros' => $macros,
            'hydration' => $hydration,
            'meal_plan' => $mealPlan,
            'timing' => analysisTrainingTimingVariants($durationMin, $weight, $endurance),
            'timing_selected' => in_array($in['training_slot'] ?? '', ['morning', 'forenoon', 'afternoon', 'evening'], true) ? $in['training_slot'] : null,
            'foods' => analysisFoodSuggestions((string)($in['diet'] ?? 'none'), (string)($in['allergies'] ?? ''), (string)($in['disliked_foods'] ?? '')),
            'habit_tips' => $habitTips,
            'training' => analysisTrainingRecommendations($goal, (string)($in['experience'] ?? 'intermediate'), (string)($in['sessions'] ?? '0'), (string)($in['duration'] ?? '45-60'), $in['activity_types'] ?? [], (string)($in['steps_bucket'] ?? '')),
            'day_types' => $dayTypes,
            'timeline' => $timeline,
            'coach_applied' => $coachApplied,
            'disclaimers' => analysisDisclaimers(),
        ];
    }
}

if (!function_exists('analysisMetrics')) {
    /**
     * Ploché metriky pro historii, grafy a porovnání období.
     *
     * @return array<string, ?float>
     */
    function analysisMetrics(array $results): array {
        $b = $results['body'] ?? [];
        $m = $results['metabolism'] ?? [];
        $mac = $results['macros'] ?? [];
        $f = static fn($v): ?float => is_numeric($v) ? (float)$v : null;
        return [
            'weight_kg' => $f($b['weight_kg'] ?? null),
            'bmi' => $f($b['bmi'] ?? null),
            'body_fat_pct' => $f($b['body_fat_pct'] ?? null),
            'fat_mass_kg' => $f($b['fat_mass_kg'] ?? null),
            'lean_mass_kg' => $f($b['lean_mass_kg'] ?? null),
            'waist_cm' => $f($b['waist_cm'] ?? null),
            'hip_cm' => $f($b['hip_cm'] ?? null),
            'bmr' => $f($m['bmr'] ?? null),
            'tdee' => $f($m['tdee'] ?? null),
            'kcal' => $f($results['calories']['kcal'] ?? null),
            'protein_g' => $f($mac['protein_g'] ?? null),
            'carbs_g' => $f($mac['carbs_g'] ?? null),
            'fat_g' => $f($mac['fat_g'] ?? null),
        ];
    }

    /** @return array<string, array{label:string, unit:string, decimals:int}> */
    function analysisMetricDefinitions(): array {
        return [
            'weight_kg' => ['label' => 'Hmotnost', 'unit' => 'kg', 'decimals' => 1],
            'bmi' => ['label' => 'BMI', 'unit' => '', 'decimals' => 1],
            'body_fat_pct' => ['label' => 'Tělesný tuk', 'unit' => '%', 'decimals' => 1],
            'fat_mass_kg' => ['label' => 'Tuková hmota', 'unit' => 'kg', 'decimals' => 1],
            'lean_mass_kg' => ['label' => 'Beztuková hmota', 'unit' => 'kg', 'decimals' => 1],
            'waist_cm' => ['label' => 'Obvod pasu', 'unit' => 'cm', 'decimals' => 1],
            'hip_cm' => ['label' => 'Obvod boků', 'unit' => 'cm', 'decimals' => 1],
            'bmr' => ['label' => 'BMR', 'unit' => 'kcal', 'decimals' => 0],
            'tdee' => ['label' => 'TDEE', 'unit' => 'kcal', 'decimals' => 0],
            'kcal' => ['label' => 'Kalorický příjem', 'unit' => 'kcal', 'decimals' => 0],
            'protein_g' => ['label' => 'Bílkoviny', 'unit' => 'g', 'decimals' => 0],
            'carbs_g' => ['label' => 'Sacharidy', 'unit' => 'g', 'decimals' => 0],
            'fat_g' => ['label' => 'Tuky', 'unit' => 'g', 'decimals' => 0],
        ];
    }
}
