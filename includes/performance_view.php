<?php
declare(strict_types=1);

function performanceLabels(): array {
    return [
        'method' => 'Metoda', 'base_ml' => 'Základ (ml)', 'training_ml' => 'Navíc k aktivitě (ml)',
        'total_ml' => 'Celkový objem / plán (ml)', 'per_hour_ml' => 'Během aktivity (ml/h)',
        'sweat_rate_l_h' => 'Naměřené pocení (l/h)', 'recorded_fluids' => 'Zapsané nápoje ve Stravě',
        'total_ml_recorded' => 'Zapsaný objem (ml)', 'alcohol_ml' => 'Alkoholické nápoje (ml)', 'non_alcohol_ml' => 'Nealkoholické nápoje (ml)',
        'unclassified_ml' => 'Nápoje bez určení obsahu alkoholu (ml)', 'warning' => 'Upozornění', 'category' => 'Kategorie',
        'segment_key' => 'Segment', 'seconds' => 'Sekundy',
        'epley_kg' => 'Epley — odhad 1RM (kg)', 'brzycki_kg' => 'Brzycki — odhad 1RM (kg)',
        'percentages_kg' => 'Procenta z Epleyho odhadu (kg)', 'segments' => 'Mezičasy (sekundy)',
        'total_seconds' => 'Celkový čas (s)', 'run_seconds' => 'Běh celkem (s)',
        'acute_7d' => 'Zátěž za 7 dní (AU)', 'total_28d' => 'Zátěž za 28 dní (AU)',
        'known_activities' => 'Úplně hodnocené aktivity', 'missing_activities' => 'Neúplně hodnocené aktivity',
        'coverage_pct' => 'Pokrytí evidovaných aktivit (%)', 'days' => 'Denní vývoj (AU)',
        'score' => 'Připravenost (0–100)', 'status' => 'Vyhodnocení', 'missing' => 'Chybějící údaje',
        'values' => 'Použité hodnocení', 'warnings' => 'Upozornění', 'weight_kg' => 'Hmotnost / celková zátěž (kg)',
        'minutes' => 'Délka (min)', 'environment' => 'Prostředí', 'name' => 'Cvik', 'reps' => 'Opakování',
        'sleep_hours' => 'Spánek (h)', 'energy_score' => 'Energie (1–10)', 'motivation_score' => 'Motivace (1–10)',
        'feeling_score' => 'Celkový pocit (1–10)', 'muscle_pain_score' => 'Bolest svalů (0–10)', 'joint_pain_score' => 'Bolest kloubů (0–10)',
        'date' => 'Datum', 'source' => 'Zdroj', 'exercise_id' => 'ID cviku', 'division' => 'Divize', 'gender' => 'Kategorie',
        'standards' => 'Použité standardy', 'discipline_name' => 'Disciplína', 'discipline_key' => 'Kód disciplíny',
        'version_label' => 'Verze', 'distance_or_reps' => 'Vzdálenost / opakování', 'weight_label' => 'Zátěž', 'valid_from' => 'Platnost od',
        'valid_to' => 'Platnost do', 'activities' => 'Použité aktivity', 'key' => 'Identifikátor', 'label' => 'Název',
        'rpe' => 'RPE', 'duration_source' => 'Původ délky', 'linked_to' => 'Propojeno s', 'timer_minutes' => 'Čas otevřeného online tréninku (min)',
        'rpe_source' => 'Původ RPE', 'type' => 'Typ zdroje',
        'day_id' => 'ID dne ve Stravě', 'load' => 'Zátěž (AU)', 'known' => 'Úplné', 'missing_count' => 'Neúplné',
        'rate_l_h' => 'Pocení (l/h)', 'loss_l' => 'Ztráta (l)', 'weight_change_pct' => 'Změna hmotnosti (%)',
        'result' => 'Měření', 'kg' => 'Hmotnost (kg)', 'version' => 'Verze', 'id' => 'ID',
    ] + performanceHyroxSegments();
}

function performanceRenderTree(array $data, string $context = ''): void {
    $labels = performanceLabels();
    $list = array_is_list($data);
    echo '<dl class="perf-values">';
    foreach ($data as $key => $value) {
        $label = $context === 'percentages_kg' ? $key . ' %' : (is_int($key) ? (string)($list ? $key + 1 : $key) : ($labels[$key] ?? $key));
        echo '<div><dt>' . h($label) . '</dt><dd>';
        if (is_array($value)) {
            if ($value) performanceRenderTree($value, (string)$key);
            else echo '—';
        } elseif ($value === null) echo 'Nezadáno';
        elseif (is_bool($value)) echo $value ? 'Ano' : 'Ne';
        else echo h(is_float($value) ? number_format($value, 2, ',', ' ') : (string)$value);
        echo '</dd></div>';
    }
    echo '</dl>';
}

function performanceRenderReport(array $report): void {
    echo '<section class="calc-metal calc-panel mb-4 perf-report"><h2>' . h(performanceModules()[$report['module']]) . '</h2>';
    echo '<p>Datum: ' . h($report['date']) . ' · Algoritmus: ' . h($report['version']) . (isset($report['id']) ? ' · ' . h($report['kind'] ?? 'Zpráva') . ' #' . (int)$report['id'] : ' · Neuložený náhled') . '</p>';
    performanceRenderTree($report['results']);
    if (!empty($report['coach_note'])) echo '<aside class="calc-note"><strong>Poznámka trenéra:</strong> ' . nl2br(h($report['coach_note'])) . '</aside>';
    echo '<details class="mt-3"><summary>Vstupy a původ dat</summary>';
    performanceRenderTree(['values' => $report['inputs'], 'source' => $report['sources']]);
    echo '</details></section>';
}
