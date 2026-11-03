<?php
declare(strict_types=1);

function performanceIntelligence(array $context, array $load, array $recovery): array {
    if (empty($context['settings']['intelligence_enabled'])) return [];
    $date = $context['date'];
    $rules = [];
    $add = static function (string $code, int $priority, string $text, array $sources) use (&$rules): void {
        $rules[] = ['code' => $code, 'priority' => $priority, 'text' => $text, 'sources' => $sources, 'version' => '1.0'];
    };
    $daily = $context['daily'];
    if (!$context['available_modules']) {
        $add('no-available-data', 10, 'Nejsou povolené nebo sdílené žádné oblasti pro vyhodnocení. Dostupnost ovládá administrátor a sdílení sportovec.', []);
        return $rules;
    }
    $pain = $daily['joint_pain_score'] ?? null;
    if ($pain !== null && $pain >= 5) $add('joint-pain', 100, 'Nezvyšujte intenzitu. Bolestivou aktivitu vynechte; novou nebo přetrvávající bolest řešte s odborníkem.', ['daily:' . $date]);
    if (($daily['sleep_hours'] ?? null) !== null && $daily['sleep_hours'] < 6) $add('short-sleep', 80, 'Spánek byl kratší než 6 hodin. Upřednostněte odpočinek a domluvte lehčí režim s trenérem.', ['daily:' . $date]);
    $available = $context['available_modules'];
    if (in_array('recovery', $available, true) && $recovery['score'] === null) $add('missing-readiness', 60, 'Doplňte hodnocení vybraného dne. Bez něj nelze spolehlivě hodnotit připravenost.', ['daily:' . $date]);
    elseif ($recovery['score'] !== null && $recovery['score'] < 45) $add('low-readiness', 85, 'Hodnocení vybraného dne je nepříznivé. Zvažte volno nebo nenáročný pohyb místo těžkého tréninku.', ['daily:' . $date]);
    if (in_array('load', $available, true) && $load['missing_activities'] > 0) $add('missing-rpe', 55, 'Část aktivit nemá potvrzenou délku nebo RPE. Zátěž je neúplná; doplňte hodnocení.', array_column(array_filter($context['activities'], static fn($a) => $a['rpe'] === null || $a['minutes'] === null), 'key'));
    if (in_array('hydration', $available, true) && $context['weight'] === null) $add('missing-weight', 40, 'Pro individuální hydrataci doplňte váhu na profilu.', []);
    elseif ($context['weight'] !== null && $context['weight']['date'] < (new DateTimeImmutable($date))->modify('-30 days')->format('Y-m-d')) $add('old-weight', 40, 'Poslední vážení je starší než 30 dní. Před novým výpočtem ověřte aktuální hmotnost.', [$context['weight']['source']]);
    $today = $load['days'][$date]['load'] ?? 0;
    if (in_array('load', $available, true) && $today >= 600) $add('high-session-load', 65, 'Ve vybraný den je zaznamenaná vyšší zátěž (alespoň 600 AU). Zohledněte ji v odpočinku a plánování dalšího dne; samotné číslo není zdravotní hranice.', ['load:' . $date]);
    $fluids = $context['fluids'];
    if ($fluids !== null && $fluids['alcohol_ml'] > 0) $add('alcohol', 60, 'Alkohol se nepočítá jako plnění hydratačního cíle a může zhoršit regeneraci.', ['food_diary_days:' . $fluids['day_id']]);
    if (isset($context['latest_results']['strength']) && (($recovery['score'] !== null && $recovery['score'] < 45) || ($pain !== null && $pain >= 5))) $add('strength-recovery', 90, 'Uložené 1RM je jen historický odhad. Při nepříznivém hodnocení nebo bolesti z něj neodvozujte pokus o maximum.', ['performance_results:' . $context['latest_results']['strength']['id'], 'daily:' . $date]);
    $hyrox = $context['latest_results']['hyrox'] ?? null;
    if ($hyrox && !empty($hyrox['results']['segments'])) {
        $stations = array_filter($hyrox['results']['segments'], static fn($k) => str_starts_with($k, 'station_'), ARRAY_FILTER_USE_KEY);
        arsort($stations);
        $slowest = array_key_first($stations);
        if ($slowest !== null) $add('hyrox-split', 30, 'V posledním uloženém HYROX výsledku zabírá nejvíce času stanoviště ' . performanceHyroxSegments()[$slowest] . '. Proberte techniku a rozložení tempa s trenérem; čas sám neprokazuje slabinu.', [$hyrox['source'] ?? 'performance_results:' . $hyrox['id']]);
    }
    $hydration = $context['latest_results']['hydration'] ?? null;
    if ($hydration && $hydration['date'] === $date && $fluids !== null && $fluids['non_alcohol_ml'] !== null) $add('hydration-plan', 25, 'Záznam nealkoholických nápojů: ' . $fluids['non_alcohol_ml'] . ' ml; uložený orientační plán: ' . $hydration['results']['total_ml'] . ' ml. Evidence může být neúplná a část vody je v jídle. Rozdíl není pokyn k rychlému dopití.', ['performance_results:' . $hydration['id'], 'food_diary_days:' . $fluids['day_id']]);
    if (!$rules) $add('no-red-flags', 10, 'V dostupných údajích se neaktivovalo žádné upozornění. Nejde o potvrzení zdravotní způsobilosti; respektujte plán trenéra a vlastní stav.', ['daily:' . $date]);
    usort($rules, static fn($a, $b) => $b['priority'] <=> $a['priority']);
    return $rules;
}
