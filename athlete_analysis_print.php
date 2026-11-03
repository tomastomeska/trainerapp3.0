<?php
// athlete_analysis_print.php – tiskový výstup (A4 / PDF) jedné analýzy sportovce
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/athlete_analysis.php';

$isAthleteView = !isLoggedIn();
$pdo = getDB();

if ($isAthleteView) {
    requireAthleteLogin();
    $athleteId = (int)getCurrentAthleteId();
    if (!calculatorAppVisibleForUser('athlete', $athleteId)) {
        redirect(BASE_URL . '/athlete_dashboard.php');
    }
    $backUrl = BASE_URL . '/athlete_analysis.php';
} else {
    requireLogin();
    $coachId = (int)getCurrentCoachId();
    $athleteId = intParam($_GET, 'athlete_id');
    if ($athleteId <= 0 || !analysisCoachOwnsAthlete($pdo, $coachId, $athleteId)) {
        flash('danger', 'Sportovec nenalezen.');
        redirect(BASE_URL . '/dashboard.php');
    }
    $backUrl = BASE_URL . '/athlete_analysis.php?athlete_id=' . $athleteId;
}

$analysis = analysisEnsureSchema($pdo) ? analysisFetch($pdo, intParam($_GET, 'id'), $athleteId) : null;
if (!$analysis || $analysis['status'] !== 'final' || (!$isAthleteView && !analysisCoachCanViewRow($pdo, $analysis))) {
    flash('warning', 'Analýza nebyla nalezena nebo ji sportovec nesdílí.');
    redirect($backUrl);
}
$backUrl .= (str_contains($backUrl, '?') ? '&' : '?') . 'view=result&id=' . (int)$analysis['id'];

$athleteStmt = $pdo->prepare(
    'SELECT a.id, a.first_name, a.last_name, a.birth_date, c.name AS coach_name, c.username AS coach_username
     FROM athletes a LEFT JOIN coaches c ON c.id = a.coach_id WHERE a.id = ? LIMIT 1'
);
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$athleteName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));

$r = $analysis['results'];
$in = $analysis['inputs'];
$b = $r['body'] ?? [];
$met = $r['metabolism'] ?? [];
$cal = $r['calories'] ?? null;
$mac = $r['macros'] ?? null;
$hyd = $r['hydration'] ?? null;
$mp = $r['meal_plan'] ?? null;
$dt = $r['day_types'] ?? null;
$tl = $r['timeline'] ?? null;
$tr = $r['training'] ?? [];
$coachApplied = $r['coach_applied'] ?? [];
$fmt = static fn($value, int $decimals = 0): string => ($value === null || $value === '' || !is_numeric($value)) ? '–' : number_format((float)$value, $decimals, ',', ' ');
$opt = static fn(array $options, $key): string => is_scalar($key) && isset($options[(string)$key]) ? $options[(string)$key] : '–';
$yn = static fn($v): string => $v === true ? 'Ano' : ($v === false ? 'Ne' : '–');
$documentTitle = 'Analýza sportovce';

// Předchozí analýza pro srovnání v tisku.
$finals = analysisFetchFinals($pdo, $athleteId, 200);
if (!$isAthleteView) {
    $finals = analysisFilterForCoach($pdo, $athleteId, $finals);
}
$previous = null;
foreach ($finals as $i => $row) {
    if ((int)$row['id'] === (int)$analysis['id']) {
        $previous = $finals[$i + 1] ?? null;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($documentTitle . ($athleteName !== '' ? ' – ' . $athleteName : '') . ' – ' . date('j. n. Y', strtotime($analysis['date']))) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
@page { size: A4; margin: 12mm 11mm; }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; background: #e9ebee; font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; color: #1d2128; font-size: 12px; line-height: 1.42; }
.toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: .6rem; justify-content: center; padding: .8rem; background: #16181d; }
.toolbar a, .toolbar button { display: inline-flex; align-items: center; gap: .45rem; border: 1px solid rgba(201,206,214,.35); border-radius: 10px; padding: .5rem 1rem; font-weight: 700; font-size: .9rem; color: #e9ecf1; background: transparent; text-decoration: none; cursor: pointer; }
.toolbar button.primary { border: none; color: #1b1505; background: linear-gradient(120deg, #fbe7a1, #d4a73c 55%, #9c7420); }
.sheet { width: 210mm; max-width: 100%; min-height: 297mm; margin: 1.2rem auto; padding: 12mm 11mm; background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.15); }
.doc-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 14px 18px; border-radius: 12px; color: #e9ecf1;
    background: linear-gradient(115deg, rgba(255,255,255,.07) 0%, rgba(255,255,255,0) 40%), linear-gradient(150deg, #23272f 0%, #16181d 60%, #0e1013 100%); }
.doc-brand { font-size: .7rem; letter-spacing: .26em; text-transform: uppercase; color: #9aa2ae; font-weight: 700; }
.doc-title { font-size: 1.45rem; font-weight: 800; margin-top: 2px; color: #f3e2b0; }
.doc-meta { text-align: right; font-size: .76rem; color: #c9ced6; }
.gold-rule { height: 3px; margin: 10px 0 12px; border-radius: 3px; background: linear-gradient(90deg, #9c7420, #fbe7a1, #d4a73c, #9c7420); }
h2 { display: flex; align-items: center; gap: 8px; font-size: .78rem; letter-spacing: .16em; text-transform: uppercase; color: #4b525d; margin: 16px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #e3e6ea; }
h2 .letter { display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 6px; font-size: .75rem; letter-spacing: 0; color: #1b1505; background: linear-gradient(120deg, #fbe7a1, #d4a73c); }
h3 { font-size: .74rem; letter-spacing: .1em; text-transform: uppercase; color: #6c737f; margin: 10px 0 5px; }
.grid { display: grid; gap: 7px; }
.g3 { grid-template-columns: repeat(3, 1fr); } .g4 { grid-template-columns: repeat(4, 1fr); } .g2 { grid-template-columns: repeat(2, 1fr); }
.info { border: 1px solid #e3e6ea; border-radius: 9px; padding: 6px 9px; background: #fafbfc; }
.info.hl { border-color: #e6d7ab; background: linear-gradient(140deg, #fffaf0, #fff); }
.info__label { font-size: .64rem; letter-spacing: .1em; text-transform: uppercase; color: #8a919c; font-weight: 700; }
.info__value { font-size: 1.02rem; font-weight: 800; margin-top: 1px; }
.info.hl .info__value { color: #8a6418; font-size: 1.3rem; }
.info__sub { font-size: .72rem; color: #6c737f; }
table { width: 100%; border-collapse: collapse; }
.data-table th { text-align: left; font-size: .64rem; letter-spacing: .08em; text-transform: uppercase; color: #8a919c; padding: 4px 6px; border-bottom: 1.5px solid #d6dadf; }
.data-table td { padding: 4px 6px; border-bottom: 1px solid #eceef1; vertical-align: top; }
.data-table td.num, .data-table th.num { text-align: right; white-space: nowrap; }
.data-table td.num { font-weight: 700; }
.data-table tr.hl td { background: #fff7e0; }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: start; }
ul { margin: 0; padding-left: 16px; } li { margin-bottom: 2px; }
.note { margin-top: 8px; padding: 7px 10px; border-left: 4px solid #4ea8de; background: #f2f7fc; border-radius: 6px; font-size: .78rem; }
.note.warn { border-left-color: #d4a73c; background: #fff8e6; }
.note.danger { border-left-color: #e74c3c; background: #fdecea; }
.muted { color: #6c737f; }
.small { font-size: .74rem; }
.dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 3px; }
.tone-info { background: #4ea8de; } .tone-success { background: #2ecc71; } .tone-warning { background: #f1c40f; }
.tone-orange { background: #e67e22; } .tone-danger { background: #e74c3c; } .tone-dark { background: #8e1b2c; }
.bar { display: flex; height: 9px; border-radius: 999px; overflow: hidden; margin: 4px 0 6px; }
.bar span:nth-child(1) { background: #d4a73c; } .bar span:nth-child(2) { background: #aab2bd; } .bar span:nth-child(3) { background: #a8653a; }
.timing { border: 1px solid #e3e6ea; border-radius: 9px; padding: 6px 8px; font-size: .74rem; }
.timing.sel { border-color: #d4a73c; background: #fffaf0; }
.timing b { display: block; font-size: .8rem; margin-bottom: 2px; }
.tag { display: inline-block; padding: 1px 7px; border-radius: 999px; border: 1px solid #dfe3e8; margin: 0 3px 3px 0; font-size: .72rem; }
.sign { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 26px; font-size: .74rem; color: #6c737f; }
.sign div { border-top: 1px solid #b9bfc7; padding-top: 4px; }
.doc-foot { margin-top: 16px; padding-top: 6px; border-top: 1px solid #e3e6ea; display: flex; justify-content: space-between; font-size: .7rem; color: #8a919c; }
.section { break-inside: avoid; page-break-inside: avoid; }
.page-break { break-before: page; page-break-before: always; }
@media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
}
@media (max-width: 640px) { .g3, .g4, .two-col { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body>
<div class="toolbar">
    <a href="<?= h($backUrl) ?>"><i class="fas fa-arrow-left"></i>Zpět</a>
    <button type="button" class="primary" onclick="window.print()"><i class="fas fa-print"></i>Tisknout / uložit PDF</button>
</div>

<main class="sheet">
    <header class="doc-head">
        <div>
            <div class="doc-brand">TrainerApp · Kalkulačky</div>
            <div class="doc-title"><?= h($documentTitle) ?></div>
        </div>
        <div class="doc-meta">
            Analýza ze dne <?= h(date('j. n. Y', strtotime($analysis['date']))) ?><br>
            <?= h($athleteName) ?> (ID: <?= (int)$athleteId ?>)<br>
            Vytištěno <?= h(date('j. n. Y H:i')) ?>
        </div>
    </header>
    <div class="gold-rule"></div>

    <section class="section">
        <h2>Sportovec a vstupní údaje</h2>
        <div class="grid g4">
            <div class="info"><div class="info__label">Jméno</div><div class="info__value"><?= h($athleteName) ?> <span class="muted small">(ID <?= (int)$athleteId ?>)</span></div></div>
            <div class="info"><div class="info__label">Pohlaví / věk</div><div class="info__value"><?= ($in['sex'] ?? '') === 'female' ? 'Žena' : (($in['sex'] ?? '') === 'male' ? 'Muž' : '–') ?> · <?= $fmt($in['age'] ?? null) ?> let</div></div>
            <div class="info"><div class="info__label">Výška / hmotnost</div><div class="info__value"><?= $fmt($in['height_cm'] ?? null, 1) ?> cm · <?= $fmt($in['weight_kg'] ?? null, 1) ?> kg</div><div class="info__sub">vážení <?= !empty($in['weigh_date']) ? h(date('j. n. Y', strtotime((string)$in['weigh_date']))) : '–' ?></div></div>
            <div class="info"><div class="info__label">Trenér</div><div class="info__value"><?= h((string)(($athlete['coach_name'] ?? '') ?: ($athlete['coach_username'] ?? '') ?: '–')) ?></div><div class="info__sub"><?= ($analysis['created_by_type'] ?? '') === 'coach' ? 'analýzu vytvořil trenér' : 'analýzu vyplnil sportovec' ?></div></div>
        </div>
        <table class="data-table" style="margin-top:7px">
            <tbody>
                <tr><td class="muted" style="width:22%">Hlavní cíl</td><td><strong><?= h($opt(analysisGoalOptions(), $in['goal'] ?? null)) ?></strong></td><td class="muted" style="width:22%">Vedlejší cíl</td><td><?= h($opt(analysisSecondaryGoalOptions(), $in['secondary_goal'] ?? null)) ?></td></tr>
                <tr><td class="muted">Cílová hmotnost</td><td><?= ($in['target_weight_kg'] ?? null) !== null ? $fmt($in['target_weight_kg'], 1) . ' kg' : '–' ?><?= !empty($in['target_date']) ? ' do ' . h(date('j. n. Y', strtotime((string)$in['target_date']))) : '' ?></td><td class="muted">Zaměstnání</td><td><?= h($opt(analysisJobActivityOptions(), $in['job_activity'] ?? null)) ?></td></tr>
                <tr><td class="muted">Tréninky týdně</td><td><?= h($opt(analysisSessionsOptions(), $in['sessions'] ?? null)) ?> × <?= h($opt(analysisDurationOptions(), $in['duration'] ?? null)) ?></td><td class="muted">Kroky denně</td><td><?= !empty($in['steps_exact']) ? $fmt($in['steps_exact']) : h($opt(analysisStepsOptions(), $in['steps_bucket'] ?? null)) ?></td></tr>
                <tr><td class="muted">Aktivity</td><td><?= h(implode(', ', array_map(static fn($k) => analysisActivityTypeOptions()[$k] ?? $k, $in['activity_types'] ?? [])) ?: '–') ?></td><td class="muted">Zkušenost</td><td><?= h($opt(analysisExperienceOptions(), $in['experience'] ?? null)) ?></td></tr>
                <tr><td class="muted">Čas tréninku</td><td><?= h($opt(analysisTrainingSlotOptions(), $in['training_slot'] ?? null)) ?><?= !empty($in['training_time']) ? ' (' . h((string)$in['training_time']) . ')' : '' ?></td><td class="muted">Strava</td><td><?= h($opt(analysisDietOptions(), $in['diet'] ?? null)) ?><?= !empty($in['allergies']) ? ' · alergie: ' . h((string)$in['allergies']) : '' ?></td></tr>
                <tr><td class="muted">Obvody (cm)</td><td colspan="3"><?php
                    $circ = [];
                    foreach (analysisCircumferenceFields() as $key => $meta) {
                        if (($in[$key] ?? null) !== null) {
                            $circ[] = $meta['label'] . ' ' . $fmt($in[$key], 1);
                        }
                    }
                    echo h($circ ? implode(' · ', $circ) : 'nezadány');
                ?></td></tr>
                <tr><td class="muted">Návyky</td><td colspan="3" class="small">Pravidelná snídaně: <?= $yn($in['regular_breakfast'] ?? null) ?> · Pozdní jídlo: <?= $yn($in['eats_late'] ?? null) ?> · Mlsání: <?= $yn($in['snacking'] ?? null) ?> · Dostatek tekutin: <?= $yn($in['enough_fluids'] ?? null) ?> · Alkohol: <?= $yn($in['alcohol'] ?? null) ?> · Příprava jídla: <?= h($opt(analysisPrepTimeOptions(), $in['prep_time'] ?? null)) ?></td></tr>
            </tbody>
        </table>
    </section>

    <?php foreach ($r['warnings'] ?? [] as $msg): ?><div class="note warn section"><?= h($msg) ?></div><?php endforeach; ?>
    <?php if (!empty($r['special'])): ?>
        <div class="note danger section"><strong>Zvláštní situace:</strong> <?= h(implode(', ', array_map(static fn($k) => analysisSpecialConditionOptions()[$k] ?? $k, $r['special']))) ?>. Kalorický cíl a makroživiny se nepočítají – doporučujeme konzultaci s lékařem nebo nutričním terapeutem.</div>
    <?php endif; ?>
    <?php if (!empty($analysis['coach_note'])): ?>
        <div class="note warn section"><strong>Poznámka trenéra:</strong> <?= nl2br(h((string)$analysis['coach_note'])) ?></div>
    <?php endif; ?>

    <div class="two-col">
        <section class="section">
            <h2><span class="letter">A</span>Tělesná kompozice</h2>
            <div class="grid g2">
                <div class="info"><div class="info__label">BMI</div><div class="info__value"><?= $fmt($b['bmi'] ?? null, 1) ?></div><div class="info__sub"><?php if (!empty($b['bmi_tone'])): ?><span class="dot tone-<?= h((string)$b['bmi_tone']) ?>"></span><?php endif; ?><?= h((string)($b['bmi_label'] ?? '')) ?></div></div>
                <div class="info"><div class="info__label">Tělesný tuk</div><div class="info__value"><?= ($b['body_fat_pct'] ?? null) !== null ? $fmt($b['body_fat_pct'], 1) . ' %' : '–' ?></div><div class="info__sub"><?php if (!empty($b['body_fat_category'])): ?><span class="dot tone-<?= h((string)$b['body_fat_category']['tone']) ?>"></span><?= h($b['body_fat_category']['label']) ?><?php else: ?>US Navy – chybí obvody<?php endif; ?></div></div>
                <div class="info"><div class="info__label">Tuková hmota</div><div class="info__value"><?= ($b['fat_mass_kg'] ?? null) !== null ? $fmt($b['fat_mass_kg'], 1) . ' kg' : '–' ?></div></div>
                <div class="info"><div class="info__label">Beztuková hmota</div><div class="info__value"><?= ($b['lean_mass_kg'] ?? null) !== null ? $fmt($b['lean_mass_kg'], 1) . ' kg' : '–' ?></div></div>
                <div class="info" style="grid-column:1/-1"><div class="info__label">WHR (pas / boky)</div><div class="info__value"><?= $fmt($b['whr'] ?? null, 2) ?></div><div class="info__sub"><?php if (!empty($b['whr_interpretation'])): ?><span class="dot tone-<?= h((string)$b['whr_interpretation']['tone']) ?>"></span><?= h($b['whr_interpretation']['label']) ?><?php else: ?>chybí obvod pasu nebo boků<?php endif; ?></div></div>
            </div>
        </section>
        <section class="section">
            <h2><span class="letter">B</span>Metabolismus</h2>
            <div class="grid g2">
                <div class="info"><div class="info__label">BMR</div><div class="info__value"><?= $fmt($met['bmr'] ?? null) ?> kcal</div><div class="info__sub">Mifflin-St Jeor</div></div>
                <div class="info"><div class="info__label">TDEE</div><div class="info__value"><?= $fmt($met['tdee'] ?? null) ?> kcal</div><div class="info__sub">faktor aktivity <?= $fmt($met['activity']['factor'] ?? null, 2) ?></div></div>
                <div class="info hl" style="grid-column:1/-1">
                    <div class="info__label">Doporučený denní příjem</div>
                    <?php if ($cal && !$cal['blocked']): ?>
                        <div class="info__value"><?= $fmt($cal['kcal']) ?> kcal</div>
                        <div class="info__sub"><?= h($cal['mode_label']) ?>: <?= $cal['adjust_kcal'] > 0 ? '+' : '' ?><?= $fmt($cal['adjust_kcal']) ?> kcal (<?= $cal['adjust_pct'] > 0 ? '+' : '' ?><?= $fmt($cal['adjust_pct'], 1) ?> % TDEE)<?= in_array('kcal', $coachApplied, true) ? ' · upraveno trenérem' : '' ?></div>
                    <?php else: ?>
                        <div class="info__value">–</div><div class="info__sub">nepočítá se</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($met['activity'])): ?><div class="small muted" style="margin-top:5px">Aktivita: <?= h((string)$met['activity']['label']) ?>. <?= h((string)($met['activity']['explanation'] ?? '')) ?></div><?php endif; ?>
        </section>
    </div>

    <div class="two-col">
        <section class="section">
            <h2><span class="letter">C</span>Makroživiny</h2>
            <?php if ($mac): ?>
            <div class="bar"><span style="width:<?= (float)$mac['protein_pct'] ?>%"></span><span style="width:<?= (float)$mac['carbs_pct'] ?>%"></span><span style="width:<?= (float)$mac['fat_pct'] ?>%"></span></div>
            <table class="data-table">
                <thead><tr><th>Živina</th><th class="num">g</th><th class="num">kcal</th><th class="num">%</th><th class="num">g/kg</th></tr></thead>
                <tbody>
                    <tr><td><span class="dot" style="background:#d4a73c"></span>Bílkoviny<?= in_array('protein_g', $coachApplied, true) ? ' *' : '' ?></td><td class="num"><?= $fmt($mac['protein_g']) ?></td><td class="num"><?= $fmt($mac['protein_kcal']) ?></td><td class="num"><?= $fmt($mac['protein_pct']) ?></td><td class="num"><?= $fmt($mac['protein_per_kg'], 1) ?></td></tr>
                    <tr><td><span class="dot" style="background:#aab2bd"></span>Sacharidy</td><td class="num"><?= $fmt($mac['carbs_g']) ?></td><td class="num"><?= $fmt($mac['carbs_kcal']) ?></td><td class="num"><?= $fmt($mac['carbs_pct']) ?></td><td class="num"><?= $fmt($mac['carbs_per_kg'], 1) ?></td></tr>
                    <tr><td><span class="dot" style="background:#a8653a"></span>Tuky<?= in_array('fat_g', $coachApplied, true) ? ' *' : '' ?></td><td class="num"><?= $fmt($mac['fat_g']) ?></td><td class="num"><?= $fmt($mac['fat_kcal']) ?></td><td class="num"><?= $fmt($mac['fat_pct']) ?></td><td class="num"><?= $fmt($mac['fat_per_kg'], 1) ?></td></tr>
                    <tr><td>Vláknina</td><td class="num"><?= $fmt($mac['fiber_g']) ?></td><td class="num">–</td><td class="num">–</td><td class="num">–</td></tr>
                </tbody>
            </table>
            <div class="small muted" style="margin-top:4px">Součet <?= $fmt($mac['kcal_from_macros']) ?> kcal<?= ($coachApplied && array_intersect(['protein_g', 'fat_g'], $coachApplied)) ? ' · * upraveno trenérem' : '' ?></div>
            <?php else: ?>
                <div class="muted">Makroživiny se nepočítají.</div>
            <?php endif; ?>
        </section>
        <section class="section">
            <h2><span class="letter">C</span>Pitný režim</h2>
            <?php if ($hyd): ?>
            <div class="grid g3">
                <div class="info"><div class="info__label">Tréninkový den</div><div class="info__value"><?= $fmt($hyd['training_day_ml'] / 1000, 1) ?> l</div></div>
                <div class="info"><div class="info__label">Volný den</div><div class="info__value"><?= $fmt($hyd['rest_day_ml'] / 1000, 1) ?> l</div></div>
                <div class="info"><div class="info__label">Průměr</div><div class="info__value"><?= $fmt($hyd['avg_ml'] / 1000, 1) ?> l</div></div>
            </div>
            <div class="small muted" style="margin-top:5px">35 ml/kg + <?= $fmt($hyd['per_training_hour_ml']) ?> ml na hodinu tréninku.<?= !empty($in['sweat_test']) ? ' Test pocení: ' . $fmt($in['sweat_test']['rate_l_per_h'], 2) . ' l/h.' : '' ?></div>
            <?php endif; ?>
            <?php if ($dt && !empty($dt['enabled'])): ?>
            <h3>Tréninkové a volné dny</h3>
            <table class="data-table">
                <tbody>
                    <tr><td>Tréninkový den (<?= (int)$dt['training_days'] ?>× týdně)</td><td class="num"><?= $fmt($dt['training']['kcal']) ?> kcal</td><td class="num">S <?= $fmt($dt['training']['carbs_g']) ?> g</td></tr>
                    <tr><td>Volný den</td><td class="num"><?= $fmt($dt['rest']['kcal']) ?> kcal</td><td class="num">S <?= $fmt($dt['rest']['carbs_g']) ?> g</td></tr>
                    <tr><td>Týdenní průměr</td><td class="num"><?= $fmt($dt['weekly_avg_kcal']) ?> kcal</td><td></td></tr>
                </tbody>
            </table>
            <?php endif; ?>
        </section>
    </div>

    <section class="section">
        <h2><span class="letter">D</span>Stravovací režim</h2>
        <?php if ($mp && !empty($mp['meals'])): ?>
        <table class="data-table">
            <thead><tr><th>Čas</th><th>Jídlo</th><th class="num">kcal</th><th class="num">Podíl</th><th class="num">B</th><th class="num">S</th><th class="num">T</th><th>Doporučení</th></tr></thead>
            <tbody>
            <?php foreach ($mp['meals'] as $meal): ?>
                <tr class="<?= $meal['role'] ? 'hl' : '' ?>">
                    <td class="num"><?= h((string)$meal['time']) ?></td>
                    <td><strong><?= h((string)$meal['name']) ?></strong><?= $meal['role'] === 'pre' ? '<br><span class="small muted">před tréninkem</span>' : ($meal['role'] === 'post' ? '<br><span class="small muted">po tréninku</span>' : '') ?></td>
                    <td class="num"><?= $fmt($meal['kcal']) ?></td>
                    <td class="num"><?= $fmt($meal['share_pct']) ?> %</td>
                    <td class="num"><?= $fmt($meal['protein_g']) ?></td>
                    <td class="num"><?= $fmt($meal['carbs_g']) ?></td>
                    <td class="num"><?= $fmt($meal['fat_g']) ?></td>
                    <td class="small"><?= h((string)$meal['hint']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php foreach ($mp['notes'] ?? [] as $note): ?><div class="small muted"><?= h($note) ?></div><?php endforeach; ?>
        <?php else: ?>
            <div class="muted">Rozpis jídel se nepočítá.</div>
        <?php endif; ?>
    </section>

    <section class="section">
        <h3>Jídlo podle času tréninku</h3>
        <div class="grid g4">
            <?php foreach ($r['timing'] ?? [] as $slot => $variant): ?>
            <div class="timing <?= ($r['timing_selected'] ?? null) === $slot ? 'sel' : '' ?>">
                <b><?= h($variant['title']) ?></b>
                <div><strong>Před:</strong> <?= h($variant['before']) ?></div>
                <div><strong>Kdy:</strong> <?= h($variant['when']) ?></div>
                <div><strong>Po:</strong> <?= h($variant['after']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php $timingList = $r['timing'] ?? []; $firstTiming = $timingList ? reset($timingList) : null; ?>
        <?php if ($firstTiming): ?><div class="small muted" style="margin-top:4px"><strong>Během tréninku:</strong> <?= h($firstTiming['during']) ?></div><?php endif; ?>
    </section>

    <?php if (!empty($r['foods']) || !empty($r['habit_tips'])): ?>
    <div class="two-col">
        <section class="section">
            <h3>Vhodné potraviny</h3>
            <?php foreach (['protein' => 'Bílkoviny', 'carbs' => 'Sacharidy', 'fat' => 'Tuky', 'vegetables' => 'Zelenina'] as $group => $label): ?>
                <div class="small" style="margin-bottom:3px"><strong><?= h($label) ?>:</strong> <?= h(implode(', ', $r['foods'][$group] ?? [])) ?></div>
            <?php endforeach; ?>
        </section>
        <section class="section">
            <?php if (!empty($r['habit_tips'])): ?>
            <h3>Tipy k návykům</h3>
            <ul class="small"><?php foreach ($r['habit_tips'] as $tip): ?><li><?= h($tip) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>

    <div class="two-col">
        <section class="section">
            <h2><span class="letter">E</span>Pohyb a trénink</h2>
            <table class="data-table">
                <tbody>
                    <tr><td class="muted">Silový trénink</td><td><?= h((string)($tr['strength'] ?? '–')) ?></td></tr>
                    <tr><td class="muted">Kardio</td><td><?= h((string)($tr['cardio'] ?? '–')) ?></td></tr>
                    <tr><td class="muted">Délka</td><td><?= h((string)($tr['duration'] ?? '–')) ?></td></tr>
                    <?php if (!empty($tr['types'])): ?><tr><td class="muted">Aktivity</td><td><?= h(implode(', ', $tr['types'])) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php foreach (['recovery' => 'Regenerace', 'progression' => 'Progrese', 'notes' => 'Poznámky'] as $key => $label): if (empty($tr[$key])) { continue; } ?>
                <h3><?= h($label) ?></h3>
                <ul class="small"><?php foreach ($tr[$key] as $item): ?><li><?= h($item) ?></li><?php endforeach; ?></ul>
            <?php endforeach; ?>
        </section>
        <section class="section">
            <h2><span class="letter">F</span>Cíl a progres</h2>
            <div class="grid g3">
                <div class="info"><div class="info__label">Aktuální</div><div class="info__value"><?= $fmt($b['weight_kg'] ?? null, 1) ?> kg</div></div>
                <div class="info"><div class="info__label">Cílová</div><div class="info__value"><?= ($in['target_weight_kg'] ?? null) !== null ? $fmt($in['target_weight_kg'], 1) . ' kg' : '–' ?></div></div>
                <div class="info"><div class="info__label">Rozdíl</div><div class="info__value"><?= $tl && $tl['diff_kg'] !== null ? ($tl['diff_kg'] > 0 ? '+' : '') . $fmt($tl['diff_kg'], 1) . ' kg' : '–' ?></div></div>
            </div>
            <?php if ($tl && $tl['has_target'] && abs((float)$tl['diff_kg']) >= 0.3): ?>
            <ul class="small" style="margin-top:6px">
                <li>Doporučené tempo <?= $fmt($tl['rec_weekly_kg_min'], 2) ?>–<?= $fmt($tl['rec_weekly_kg_max'], 2) ?> kg týdně.</li>
                <?php if ($tl['weeks_min'] !== null): ?><li>Odhad: <?= (int)$tl['weeks_min'] ?>–<?= (int)$tl['weeks_max'] ?> týdnů.</li><?php endif; ?>
                <?php if ($tl['weeks_estimate'] !== null): ?><li>Podle nastaveného příjmu cíl kolem <?= h(date('j. n. Y', strtotime((string)$tl['estimated_date']))) ?>.</li><?php endif; ?>
                <?php if ($tl['required_weekly_kg'] !== null): ?><li>K datu <?= h(date('j. n. Y', strtotime((string)$tl['target_date']))) ?> je potřeba <?= $fmt($tl['required_weekly_kg'], 2) ?> kg týdně.</li><?php endif; ?>
            </ul>
            <?php endif; ?>
            <h3>Doporučený postup</h3>
            <ul class="small">
                <li>Važte se 2–3× týdně ráno nalačno a sledujte týdenní průměr.</li>
                <li>Obvody přeměřte jednou za 2–4 týdny.</li>
                <li>Bez změny 2–3 týdny: upravte příjem o 100–200 kcal.</li>
                <li>Novou analýzu po změně hmotnosti o 2–3 kg nebo změně režimu.</li>
            </ul>
            <?php if ($previous): $pm = $previous['metrics']; $cm = $analysis['metrics']; ?>
            <h3>Změna od <?= h(date('j. n. Y', strtotime($previous['date']))) ?></h3>
            <table class="data-table">
                <tbody>
                <?php foreach (analysisMetricDefinitions() as $key => $def):
                    if (!in_array($key, ['weight_kg', 'bmi', 'body_fat_pct', 'lean_mass_kg', 'waist_cm', 'kcal'], true) || $pm[$key] === null || $cm[$key] === null) { continue; }
                    $delta = $cm[$key] - $pm[$key]; ?>
                    <tr><td><?= h($def['label']) ?></td><td class="num"><?= $fmt($pm[$key], $def['decimals']) ?> → <?= $fmt($cm[$key], $def['decimals']) ?> <?= h($def['unit']) ?></td><td class="num"><?= $delta > 0 ? '+' : '' ?><?= $fmt($delta, $def['decimals']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>
    </div>

    <section class="section">
        <h2>Důležité upozornění</h2>
        <ul class="small"><?php foreach ($r['disclaimers'] ?? analysisDisclaimers() as $d): ?><li><?= h($d) ?></li><?php endforeach; ?></ul>
    </section>

    <div class="sign section">
        <div>Sportovec: <?= h($athleteName) ?></div>
        <div>Trenér: <?= h((string)(($athlete['coach_name'] ?? '') ?: ($athlete['coach_username'] ?? ''))) ?></div>
    </div>

    <footer class="doc-foot">
        <span>TrainerApp · Analýza sportovce</span>
        <span><?= h($athleteName) ?> (ID <?= (int)$athleteId ?>) · <?= h(date('j. n. Y', strtotime($analysis['date']))) ?></span>
    </footer>
</main>

<?php if (($_GET['print'] ?? '') === '1'): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
<?php endif; ?>
</body>
</html>
