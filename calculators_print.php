<?php
// calculators_print.php – tiskový výstup Kalkulaček (protokol výpočtu / kompletní zpráva)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/calculators.php';

$isAthleteView = !isLoggedIn();
$pdo = getDB();
$athleteId = 0;

if ($isAthleteView) {
    requireAthleteLogin();
    $athleteId = (int)getCurrentAthleteId();
    if (!calculatorAppVisibleForUser('athlete', $athleteId)) {
        redirect(BASE_URL . '/athlete_dashboard.php');
    }
} else {
    requireLogin();
    $coachId = (int)getCurrentCoachId();
    $athleteId = intParam($_GET, 'athlete_id');
    if ($athleteId > 0) {
        $ownerStmt = $pdo->prepare('SELECT id FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
        $ownerStmt->execute([$athleteId, $coachId]);
        if (!$ownerStmt->fetchColumn()) {
            flash('danger', 'Sportovec nenalezen.');
            redirect(BASE_URL . '/dashboard.php');
        }
        if (!calculatorGetAthleteSettings($pdo, $athleteId)['share_with_coach']) {
            flash('warning', 'Sportovec výsledky kalkulaček se svým trenérem nesdílí.');
            redirect(BASE_URL . '/athlete_detail.php?id=' . $athleteId);
        }
    } elseif (!calculatorAppVisibleForUser('coach', $coachId)) {
        redirect(BASE_URL . '/dashboard.php');
    }
}

$type = (string)($_GET['type'] ?? 'result') === 'report' ? 'report' : 'result';
$backUrl = $isAthleteView
    ? BASE_URL . '/calculators.php' . ($type === 'result' ? '?calc=bmi' : '')
    : ($athleteId > 0 ? BASE_URL . '/athlete_detail.php?id=' . $athleteId . '#calculator-results' : BASE_URL . '/calculators.php?calc=bmi');

$athlete = null;
if ($athleteId > 0) {
    $athleteStmt = $pdo->prepare(
        'SELECT a.id, a.first_name, a.last_name, a.birth_date, c.name AS coach_name, c.username AS coach_username
         FROM athletes a LEFT JOIN coaches c ON c.id = a.coach_id
         WHERE a.id = ? LIMIT 1'
    );
    $athleteStmt->execute([$athleteId]);
    $athlete = $athleteStmt->fetch() ?: null;
}

$categories = calculatorBmiCategories();
$calculators = calculatorDefinitions();
$fmt = static fn(?float $value, int $decimals = 1): string => $value === null ? '–' : number_format($value, $decimals, ',', ' ');
$fail = static function (string $message) use ($backUrl): void {
    flash('warning', $message);
    redirect($backUrl);
};

$result = null;
$reportResults = [];
$heightInfo = ['height_cm' => null, 'source' => null];
$weightStats = null;
$trend = [];

if ($type === 'result') {
    $resultId = intParam($_GET, 'id');
    if ($resultId > 0) {
        $result = $athleteId > 0 ? calculatorFetchResult($pdo, $resultId, $athleteId) : null;
        if ($result === null) {
            $fail('Výsledek nebyl nalezen.');
        }
        if (($result['calculator_key'] ?? '') === 'analysis' && !empty($result['inputs']['analysis_id'])) {
            redirect(BASE_URL . '/athlete_analysis_print.php?id=' . (int)$result['inputs']['analysis_id'] . ($isAthleteView ? '' : '&athlete_id=' . $athleteId) . '&print=1');
        }
    } else {
        // Neuložený výpočet – hodnoty přímo z kalkulačky.
        $weightKg = (float)str_replace(',', '.', (string)($_GET['weight_kg'] ?? '0'));
        $heightCm = (float)str_replace(',', '.', (string)($_GET['height_cm'] ?? '0'));
        if ($weightKg < 20 || $weightKg > 400 || $heightCm < 80 || $heightCm > 250) {
            $fail('Pro tisk zadejte platnou výšku a hmotnost.');
        }
        $bmi = (float)calculatorBmiValue($weightKg, $heightCm);
        $category = $categories[calculatorBmiCategoryIndex($bmi)];
        $result = [
            'id' => 0,
            'calculator_key' => 'bmi',
            'inputs' => ['weight_kg' => round($weightKg, 1), 'height_cm' => round($heightCm, 1), 'category_tone' => $category['tone']],
            'result_value' => $bmi,
            'result_label' => $category['label'],
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }
} else {
    if ($athleteId <= 0 || $athlete === null) {
        $fail('Kompletní zpráva je dostupná jen pro profil sportovce.');
    }
    $reportResults = calculatorFetchResults($pdo, $athleteId, null, 500);
    $heightInfo = calculatorFetchAthleteHeightCm($pdo, $athleteId);
    $weightStats = getAthleteWeightStats($athleteId);
    $trend = calculatorBmiTrend($athleteId, $heightInfo['height_cm'], 120);
}

if (!$isAthleteView && $athleteId > 0) {
    calculatorMarkSeenByCoach($pdo, $athleteId);
}

$athleteName = $athlete ? trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']) : '';
$athleteAge = $athlete ? calculateAge($athlete['birth_date'] ?? null) : null;
$documentTitle = $type === 'report' ? 'Kompletní zpráva z kalkulaček' : 'Protokol výpočtu BMI';

$renderGauge = static function (float $bmi) use ($categories): string {
    $html = '<div class="gauge"><div class="gauge__bar">';
    foreach ($categories as $category) {
        $html .= '<span class="tone-' . h($category['tone']) . '"></span>';
    }
    $html .= '</div><div class="gauge__marker" style="left:' . number_format(calculatorBmiGaugePercent($bmi), 2, '.', '') . '%"></div>';
    $html .= '<div class="gauge__scale"><span>15</span><span>18,5</span><span>25</span><span>30</span><span>35</span><span>40</span><span>45+</span></div></div>';
    return $html;
};

$renderCategoryTable = static function (?float $bmi) use ($categories): string {
    $active = $bmi !== null ? calculatorBmiCategoryIndex($bmi) : -1;
    $html = '<table class="cat-table"><thead><tr><th></th><th>Kategorie</th><th>BMI</th></tr></thead><tbody>';
    foreach ($categories as $index => $category) {
        $html .= '<tr' . ($index === $active ? ' class="is-active"' : '') . '><td><span class="dot tone-' . h($category['tone']) . '"></span></td><td>' . h($category['label']) . '</td><td>' . h($category['range']) . '</td></tr>';
    }
    return $html . '</tbody></table>';
};
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($documentTitle . ($athleteName !== '' ? ' – ' . $athleteName : '')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
@page { size: A4; margin: 14mm 12mm; }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; background: #e9ebee; font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; color: #1d2128; font-size: 13px; line-height: 1.45; }
.toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: .6rem; justify-content: center; padding: .8rem; background: #16181d; }
.toolbar a, .toolbar button { display: inline-flex; align-items: center; gap: .45rem; border: 1px solid rgba(201,206,214,.35); border-radius: 10px; padding: .5rem 1rem; font-weight: 700; font-size: .9rem; color: #e9ecf1; background: transparent; text-decoration: none; cursor: pointer; }
.toolbar button.primary { border: none; color: #1b1505; background: linear-gradient(120deg, #fbe7a1, #d4a73c 55%, #9c7420); }
.sheet { width: 210mm; max-width: 100%; min-height: 297mm; margin: 1.2rem auto; padding: 14mm 12mm; background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.15); }
.doc-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 14px 18px; border-radius: 12px; color: #e9ecf1;
    background: linear-gradient(115deg, rgba(255,255,255,.07) 0%, rgba(255,255,255,0) 40%), linear-gradient(150deg, #23272f 0%, #16181d 60%, #0e1013 100%); }
.doc-brand { font-size: .72rem; letter-spacing: .26em; text-transform: uppercase; color: #9aa2ae; font-weight: 700; }
.doc-title { font-size: 1.45rem; font-weight: 800; margin-top: 2px; color: #f3e2b0; }
.doc-meta { text-align: right; font-size: .78rem; color: #c9ced6; }
.gold-rule { height: 3px; margin: 10px 0 16px; border-radius: 3px; background: linear-gradient(90deg, #9c7420, #fbe7a1, #d4a73c, #9c7420); }
h2 { font-size: .8rem; letter-spacing: .18em; text-transform: uppercase; color: #6c737f; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #e3e6ea; }
.info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.info { border: 1px solid #e3e6ea; border-radius: 10px; padding: 8px 10px; background: #fafbfc; }
.info__label { font-size: .68rem; letter-spacing: .12em; text-transform: uppercase; color: #8a919c; font-weight: 700; }
.info__value { font-size: .98rem; font-weight: 700; margin-top: 1px; }
.result-box { display: flex; align-items: center; gap: 24px; padding: 14px 18px; border: 1px solid #e6d7ab; border-radius: 12px; background: linear-gradient(140deg, #fffaf0, #fff); }
.result-box__value { font-size: 3rem; font-weight: 900; line-height: 1; color: #8a6418; letter-spacing: -.02em; }
.result-box__unit { font-size: .8rem; color: #6c737f; }
.result-box__label { font-size: 1.15rem; font-weight: 800; }
.gauge { position: relative; margin: 18px 0 4px; }
.gauge__bar { display: flex; height: 12px; border-radius: 999px; overflow: hidden; }
.gauge__bar span { flex: 1; }
.gauge__marker { position: absolute; top: -6px; width: 4px; height: 24px; margin-left: -2px; border-radius: 3px; background: #16181d; box-shadow: 0 0 0 2px #fff; }
.gauge__scale { display: flex; justify-content: space-between; font-size: .68rem; color: #8a919c; margin-top: 4px; }
.tone-info { background: #4ea8de; } .tone-success { background: #2ecc71; } .tone-warning { background: #f1c40f; }
.tone-orange { background: #e67e22; } .tone-danger { background: #e74c3c; } .tone-dark { background: #8e1b2c; }
table { width: 100%; border-collapse: collapse; }
.data-table th, .cat-table th { text-align: left; font-size: .68rem; letter-spacing: .1em; text-transform: uppercase; color: #8a919c; padding: 5px 8px; border-bottom: 1.5px solid #d6dadf; }
.data-table td, .cat-table td { padding: 6px 8px; border-bottom: 1px solid #eceef1; }
.data-table td.num { font-weight: 800; }
.cat-table tr.is-active td { background: #fff3cf; font-weight: 700; }
.dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; }
.two-col { display: grid; grid-template-columns: 1.1fr 1fr; gap: 18px; align-items: start; }
.formula { font-family: "Cambria Math", Georgia, serif; font-size: 1.05rem; }
.note { margin-top: 14px; padding: 9px 12px; border-left: 4px solid #4ea8de; background: #f2f7fc; border-radius: 6px; font-size: .82rem; }
.muted { color: #6c737f; }
.doc-foot { margin-top: 22px; padding-top: 8px; border-top: 1px solid #e3e6ea; display: flex; justify-content: space-between; font-size: .72rem; color: #8a919c; }
.section { break-inside: avoid; page-break-inside: avoid; }
@media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
}
@media (max-width: 640px) { .info-grid, .two-col { grid-template-columns: 1fr; } .result-box { flex-direction: column; align-items: flex-start; } }
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
            Vytvořeno <?= h(date('j. n. Y H:i')) ?><br>
            <?php if ($athleteName !== ''): ?><?= h($athleteName) ?> (ID: <?= (int)$athleteId ?>)<?php endif; ?>
        </div>
    </header>
    <div class="gold-rule"></div>

    <?php if ($athlete): ?>
    <section class="section">
        <h2>Sportovec</h2>
        <div class="info-grid">
            <div class="info"><div class="info__label">Jméno</div><div class="info__value"><?= h($athleteName) ?> <span class="muted">(ID: <?= (int)$athleteId ?>)</span></div></div>
            <div class="info"><div class="info__label">Datum narození</div><div class="info__value"><?= !empty($athlete['birth_date']) ? h(date('j. n. Y', strtotime((string)$athlete['birth_date']))) . ($athleteAge !== null ? ' <span class="muted">(' . (int)$athleteAge . ' let)</span>' : '') : '–' ?></div></div>
            <div class="info"><div class="info__label">Trenér</div><div class="info__value"><?= h((string)($athlete['coach_name'] ?: $athlete['coach_username'] ?: '–')) ?></div></div>
            <?php if ($type === 'report'): ?>
            <div class="info"><div class="info__label">Výška</div><div class="info__value"><?= $heightInfo['height_cm'] !== null ? h($fmt($heightInfo['height_cm'])) . ' cm' : '–' ?></div></div>
            <div class="info"><div class="info__label">Poslední hmotnost</div><div class="info__value"><?= $weightStats && $weightStats['current_weight'] !== null ? h($fmt((float)$weightStats['current_weight'])) . ' kg <span class="muted">(' . h(date('j. n. Y', strtotime((string)$weightStats['current_date']))) . ')</span>' : '–' ?></div></div>
            <div class="info"><div class="info__label">Uložené výsledky</div><div class="info__value"><?= count($reportResults) ?></div></div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

<?php if ($type === 'result' && $result !== null): ?>
    <?php $bmi = (float)$result['result_value']; $inputs = $result['inputs']; ?>
    <section class="section">
        <h2>Vstupní údaje</h2>
        <div class="info-grid">
            <div class="info"><div class="info__label">Hmotnost</div><div class="info__value"><?= h($fmt((float)($inputs['weight_kg'] ?? 0))) ?> kg</div></div>
            <div class="info"><div class="info__label">Výška</div><div class="info__value"><?= h($fmt((float)($inputs['height_cm'] ?? 0))) ?> cm</div></div>
            <div class="info"><div class="info__label"><?= (int)$result['id'] > 0 ? 'Uloženo' : 'Spočítáno' ?></div><div class="info__value"><?= h(date('j. n. Y H:i', strtotime((string)$result['created_at']))) ?></div></div>
        </div>
    </section>

    <section class="section">
        <h2>Výsledek</h2>
        <div class="result-box">
            <div>
                <div class="result-box__value"><?= h($fmt($bmi, 2)) ?></div>
                <div class="result-box__unit">kg/m²</div>
            </div>
            <div>
                <div class="muted">Body Mass Index</div>
                <div class="result-box__label"><?= h((string)$result['result_label']) ?></div>
            </div>
        </div>
        <?= $renderGauge($bmi) ?>
    </section>

    <section class="section two-col">
        <div>
            <h2>Vyhodnocení BMI u dospělých</h2>
            <?= $renderCategoryTable($bmi) ?>
        </div>
        <div>
            <h2>Výpočet</h2>
            <?php $heightM = (float)($inputs['height_cm'] ?? 0) / 100; ?>
            <div class="formula">BMI = hmotnost (kg) / výška (m)²</div>
            <div class="formula mt" style="margin-top:8px">
                BMI = <?= h($fmt((float)($inputs['weight_kg'] ?? 0))) ?> / (<?= h(number_format($heightM, 2, ',', '')) ?> × <?= h(number_format($heightM, 2, ',', '')) ?>) = <strong><?= h($fmt($bmi, 2)) ?></strong>
            </div>
        </div>
    </section>

<?php elseif ($type === 'report'): ?>
    <?php foreach ($calculators as $calcKey => $calc): ?>
        <?php
        $calcRows = array_values(array_filter($reportResults, static fn(array $row): bool => $row['calculator_key'] === $calcKey));
        $latest = $calcRows[0] ?? null;
        ?>
        <section class="section">
            <h2><?= h($calc['title'] . ' – ' . $calc['subtitle']) ?></h2>
            <?php if ($latest === null): ?>
                <p class="muted">Zatím nebyl uložen žádný výsledek.</p>
            <?php else: ?>
                <div class="result-box">
                    <div>
                        <div class="result-box__value"><?= h($fmt($latest['result_value'], 2)) ?></div>
                        <div class="result-box__unit">kg/m²</div>
                    </div>
                    <div>
                        <div class="muted">Poslední výsledek · <?= h(date('j. n. Y', strtotime((string)$latest['created_at']))) ?></div>
                        <div class="result-box__label"><?= h((string)$latest['result_label']) ?></div>
                        <div class="muted"><?= h(calculatorResultSummary($latest)) ?></div>
                    </div>
                </div>
                <?php if ($calcKey === 'bmi'): ?><?= $renderGauge((float)$latest['result_value']) ?><?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($calcKey === 'bmi' && count($trend) >= 2): ?>
        <section class="section">
            <h2>Vývoj BMI z historie vážení</h2>
            <?= calculatorTrendSvg($trend, ['theme' => 'light', 'band' => [18.5, 25.0], 'height' => 230]) ?>
            <div class="muted" style="font-size:.75rem">Přepočteno při výšce <?= h($fmt($heightInfo['height_cm'])) ?> cm · zelené pásmo = normální hmotnost (18,5–24,9)</div>
        </section>
        <?php endif; ?>

        <?php if ($calcRows): ?>
        <section>
            <h2>Historie výsledků – <?= h($calc['title']) ?></h2>
            <table class="data-table">
                <thead><tr><th>Datum</th><th>Vstupy</th><th>Výsledek</th><th>Kategorie</th></tr></thead>
                <tbody>
                <?php foreach ($calcRows as $row): ?>
                    <tr>
                        <td><?= h(date('j. n. Y H:i', strtotime((string)$row['created_at']))) ?></td>
                        <td><?= h(calculatorResultSummary($row)) ?></td>
                        <td class="num"><?= h($fmt($row['result_value'], 2)) ?></td>
                        <td><span class="dot tone-<?= h((string)($row['inputs']['category_tone'] ?? 'info')) ?>"></span> <?= h((string)$row['result_label']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php endif; ?>

        <?php if ($calcKey === 'bmi'): ?>
        <section class="section">
            <h2>Vyhodnocení BMI u dospělých</h2>
            <?= $renderCategoryTable($latest !== null ? (float)$latest['result_value'] : null) ?>
        </section>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

    <div class="note section">
        <strong>Upozornění pro sportovce:</strong> BMI nerozlišuje svalovou a tukovou hmotu. U velmi svalnatých sportovců proto může nadhodnocovat množství tělesného tuku. Hodnoty platí pro dospělé a slouží jako orientační ukazatel, nikoli jako lékařská diagnóza.
    </div>

    <footer class="doc-foot">
        <span>TrainerApp · Kalkulačky</span>
        <span><?= h($documentTitle) ?> · <?= h(date('j. n. Y')) ?></span>
    </footer>
</main>

<?php if (($_GET['print'] ?? '') === '1'): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
<?php endif; ?>
</body>
</html>
