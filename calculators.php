<?php
// calculators.php – aplikace Kalkulačky (dashboard + jednotlivé kalkulačky)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/calculators.php';

$isAthleteView = !isLoggedIn();
if ($isAthleteView) {
    requireAthleteLogin();
    $currentUserId = (int)getCurrentAthleteId();
    $homeUrl = BASE_URL . '/athlete_dashboard.php';
} else {
    requireLogin();
    $currentUserId = (int)getCurrentCoachId();
    $homeUrl = BASE_URL . '/dashboard.php';
}

if (!calculatorAppVisibleForUser($isAthleteView ? 'athlete' : 'coach', $currentUserId)) {
    redirect($homeUrl);
}

$calculators = calculatorDefinitions();
$activeCalc = (string)($_GET['calc'] ?? '');
if ($activeCalc !== '' && (!isset($calculators[$activeCalc]) || !calculatorIsEnabled($activeCalc))) {
    flash('warning', 'Tato kalkulačka momentálně není dostupná.');
    redirect(BASE_URL . '/calculators.php');
}
if ($activeCalc !== '' && !empty($calculators[$activeCalc]['url'])) {
    redirect(BASE_URL . '/' . $calculators[$activeCalc]['url']);
}

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/calculators.php?calc=bmi');
    }

    $postAction = (string)($_POST['action'] ?? '');
    $redirectUrl = BASE_URL . '/calculators.php?calc=bmi';

    if ($isAthleteView && $postAction === 'save_share_setting') {
        $share = ($_POST['share_with_coach'] ?? '') === '1';
        if (calculatorSaveShareSetting($pdo, $currentUserId, $share)) {
            flash('success', $share ? 'Výsledky kalkulaček nyní sdílíte se svým trenérem.' : 'Sdílení výsledků kalkulaček s trenérem bylo vypnuto.');
        } else {
            flash('danger', 'Nastavení sdílení se nepodařilo uložit.');
        }
        $returnTo = (string)($_POST['return_to'] ?? '');
        redirect($returnTo === 'profile' ? BASE_URL . '/athlete_dashboard.php#calculator-profile-card' : BASE_URL . '/calculators.php');
    }

    if ($isAthleteView && $postAction === 'delete_result') {
        if (calculatorDeleteResult($pdo, intParam($_POST, 'result_id'), $currentUserId)) {
            flash('success', 'Výsledek byl smazán z historie.');
        } else {
            flash('warning', 'Výsledek se nepodařilo najít.');
        }
        redirect($redirectUrl . '#bmi-history');
    }

    if ($isAthleteView && $postAction === 'save_bmi_result' && calculatorIsEnabled('bmi')) {
        $weightInput = str_replace(',', '.', trim((string)($_POST['weight_kg'] ?? '')));
        $heightInput = str_replace(',', '.', trim((string)($_POST['height_cm'] ?? '')));
        $weightKg = is_numeric($weightInput) ? round((float)$weightInput, 1) : 0.0;
        $heightCm = is_numeric($heightInput) ? round((float)$heightInput, 1) : 0.0;
        if ($weightKg < 20 || $weightKg > 400 || $heightCm < 80 || $heightCm > 250) {
            flash('danger', 'Pro uložení výsledku zadejte platnou výšku (80–250 cm) a hmotnost (20–400 kg).');
            redirect($redirectUrl);
        }
        $bmi = (float)calculatorBmiValue($weightKg, $heightCm);
        $category = calculatorBmiCategories()[calculatorBmiCategoryIndex($bmi)];
        $resultId = calculatorSaveResult($pdo, $currentUserId, 'bmi', [
            'weight_kg' => $weightKg,
            'height_cm' => $heightCm,
            'category_tone' => $category['tone'],
        ], $bmi, $category['label']);
        if ($resultId > 0) {
            calculatorSaveAthleteHeight($pdo, $currentUserId, $heightCm);
            flash('success', 'Výsledek BMI ' . number_format($bmi, 2, ',', '') . ' (' . $category['label'] . ') byl uložen do vašeho profilu.');
            redirect($redirectUrl . '&saved=' . $resultId . '#bmi-history');
        }
        flash('danger', 'Výsledek se nepodařilo uložit.');
        redirect($redirectUrl);
    }

    if ($isAthleteView && $postAction === 'save_bmi_weight' && calculatorIsEnabled('bmi')) {
        $weightInput = str_replace(',', '.', trim((string)($_POST['weight_kg'] ?? '')));
        $weightKg = is_numeric($weightInput) ? (float)$weightInput : 0.0;
        // Ručně zadanou výšku zachováme i po uložení váhy.
        $heightInput = str_replace(',', '.', trim((string)($_POST['height_cm'] ?? '')));
        if (is_numeric($heightInput) && (float)$heightInput >= 80 && (float)$heightInput <= 250) {
            $redirectUrl .= '&height=' . rawurlencode(number_format((float)$heightInput, 1, '.', ''));
        }
        // Stejné uložení jako formulář vážení na profilu sportovce.
        if ($weightKg < 20 || $weightKg > 400) {
            flash('danger', 'Zadejte platnou hmotnost v kg.');
        } elseif (addAthleteWeightLog($currentUserId, date('Y-m-d'), $weightKg, 'athlete_link', null, null) > 0) {
            flash('success', 'Hmotnost ' . number_format($weightKg, 1, ',', '') . ' kg byla uložena jako vaše dnešní aktuální hmotnost.');
        } else {
            flash('warning', 'Pro dnešek už máte hmotnost zaznamenanou. Upravit ji můžete na svém profilu.');
        }
    }
    redirect($redirectUrl);
}

// Výchozí data pro BMI
$bmiHeightCm = null;
$bmiHeightSource = null;
$bmiWeightKg = null;
$bmiWeightDate = null;
$bmiWeightAgeDays = null;
$bmiHasTodayWeight = false;

if ($activeCalc === 'bmi' && $isAthleteView) {
    $heightInfo = calculatorFetchAthleteHeightCm($pdo, $currentUserId);
    $bmiHeightCm = $heightInfo['height_cm'];
    $bmiHeightSource = $heightInfo['source'];
    $heightQuery = str_replace(',', '.', trim((string)($_GET['height'] ?? '')));
    if ($bmiHeightCm === null && is_numeric($heightQuery) && (float)$heightQuery >= 80 && (float)$heightQuery <= 250) {
        $bmiHeightCm = (float)$heightQuery;
    }

    $weightStats = getAthleteWeightStats($currentUserId);
    if ($weightStats['current_weight'] !== null) {
        $bmiWeightKg = (float)$weightStats['current_weight'];
        $bmiWeightDate = (string)$weightStats['current_date'];
        $weightTs = strtotime(substr($bmiWeightDate, 0, 10));
        if ($weightTs !== false) {
            $bmiWeightAgeDays = (int)floor((strtotime(date('Y-m-d')) - $weightTs) / 86400);
            $bmiHasTodayWeight = $bmiWeightAgeDays === 0;
        }
    }
}

$bmiInitial = ($bmiHeightCm !== null && $bmiWeightKg !== null) ? calculatorBmiValue($bmiWeightKg, $bmiHeightCm) : null;
$bmiCategories = calculatorBmiCategories();

$calcSettings = $isAthleteView ? calculatorGetAthleteSettings($pdo, $currentUserId) : null;
$latestResults = [];
$resultsCount = 0;
if ($isAthleteView && $activeCalc === '') {
    $dashboardResults = calculatorFetchResults($pdo, $currentUserId, null, 500);
    $resultsCount = count($dashboardResults);
    foreach ($dashboardResults as $resultRow) {
        $latestResults[(string)$resultRow['calculator_key']] ??= $resultRow;
    }
}
$bmiHistory = $isAthleteView && $activeCalc === 'bmi' ? calculatorFetchResults($pdo, $currentUserId, 'bmi', 100) : [];
$bmiTrend = $isAthleteView && $activeCalc === 'bmi' ? calculatorBmiTrend($currentUserId, $bmiHeightCm) : [];
$savedResultId = intParam($_GET, 'saved');
$printUrl = BASE_URL . '/calculators_print.php';

$fmt = static fn(?float $value, int $decimals = 1): string => $value === null ? '' : number_format($value, $decimals, ',', ' ');

if ($isAthleteView) {
    require_once __DIR__ . '/includes/athlete_header.php';
    renderAthleteHeader('Kalkulačky');
} else {
    require_once __DIR__ . '/includes/header.php';
    renderHeader('Kalkulačky');
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/calculators.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/calculators.css') ?>">

<div class="calc-app">
<?php if ($activeCalc === ''): ?>
    <section class="calc-metal calc-hero mb-4">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="calc-hero__eyebrow">TrainerApp Lab</span>
            <span class="calc-chip calc-chip--silver"><i class="fas fa-flask"></i>Ve vývoji</span>
        </div>
        <h1 class="calc-hero__title"><span class="calc-silver-text">Kalku</span><span class="calc-gold-text">lačky</span></h1>
        <p class="calc-hero__lead">Přesné výpočty pro trénink, výživu a tělesné složení – na jednom místě a s vašimi daty z TrainerApp.</p>
    </section>

    <div class="calc-grid">
        <?php foreach ($calculators as $calcKey => $calc): ?>
            <?php if (!empty($calc['group'])) continue; ?>
            <?php $enabled = calculatorIsEnabled($calcKey); ?>
            <?php if ($enabled): ?>
            <a href="<?= BASE_URL ?>/<?= !empty($calc['url']) ? h($calc['url']) : 'calculators.php?calc=' . h($calcKey) ?>" class="calc-metal calc-tile">
            <?php else: ?>
            <div class="calc-metal calc-tile calc-tile--locked" aria-disabled="true">
            <?php endif; ?>
                <div>
                    <div class="d-flex justify-content-between align-items-start">
                        <span class="calc-coin"><i class="fas <?= h($calc['icon']) ?>"></i></span>
                        <?php if (!$enabled): ?><span class="calc-chip calc-chip--silver"><i class="fas fa-lock"></i>Nedostupné</span><?php endif; ?>
                    </div>
                    <div class="calc-tile__title calc-gold-text"><?= h($calc['title']) ?></div>
                    <div class="calc-tile__subtitle"><?= h($calc['subtitle']) ?></div>
                </div>
                <div class="calc-tile__cta">
                    <span><?= $enabled ? 'Spočítat' : 'Dočasně vypnuto' ?></span>
                    <span class="calc-tile__arrow"><i class="fas fa-<?= $enabled ? 'arrow-right' : 'lock' ?>"></i></span>
                </div>
            <?= $enabled ? '</a>' : '</div>' ?>
        <?php endforeach; ?>

        <div class="calc-metal calc-tile calc-tile--locked" aria-disabled="true">
            <div>
                <span class="calc-coin calc-coin--silver"><i class="fas fa-plus"></i></span>
                <div class="calc-tile__title calc-silver-text">Další kalkulačky</div>
                <div class="calc-tile__subtitle">Připravujeme další výpočty</div>
            </div>
            <div class="calc-tile__cta">
                <span>Již brzy</span>
                <span class="calc-tile__arrow"><i class="fas fa-hourglass-half"></i></span>
            </div>
        </div>
    </div>

    <?php if ($isAthleteView && $calcSettings !== null): ?>
    <div class="row g-4 mt-1">
        <div class="col-12 col-lg-7">
            <div class="calc-metal calc-panel">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div class="calc-panel__title mb-3"><i class="fas fa-id-badge me-2"></i>Můj profil v Kalkulačkách</div>
                    <a href="<?= h($printUrl) ?>?type=report&amp;print=1" target="_blank" rel="noopener" class="btn calc-btn-ghost btn-sm"><i class="fas fa-print me-1"></i>Tisk kompletní zprávy</a>
                </div>
                <div class="row g-3">
                    <?php $latestBmi = $latestResults['bmi'] ?? null; ?>
                    <div class="col-6 col-md-4">
                        <div class="calc-stat">
                            <div class="calc-stat__label">Poslední BMI</div>
                            <div class="calc-stat__value calc-gold-text"><?= $latestBmi ? h($fmt($latestBmi['result_value'], 2)) : '–' ?></div>
                            <div class="small" style="color:var(--calc-silver)"><?= $latestBmi ? h((string)$latestBmi['result_label']) . ' · ' . h(date('j. n. Y', strtotime((string)$latestBmi['created_at']))) : 'Zatím neuloženo' ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="calc-stat">
                            <div class="calc-stat__label">Výška</div>
                            <div class="calc-stat__value calc-silver-text"><?= $calcSettings['height_cm'] !== null ? h($fmt($calcSettings['height_cm'])) . ' cm' : '–' ?></div>
                            <div class="small" style="color:var(--calc-silver)"><?= $calcSettings['height_cm'] !== null ? 'Uloženo v profilu' : 'Uloží se s prvním výsledkem' ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="calc-stat">
                            <div class="calc-stat__label">Uložené výsledky</div>
                            <div class="calc-stat__value calc-silver-text"><?= (int)$resultsCount ?></div>
                            <div class="small" style="color:var(--calc-silver)">Historie je u každé kalkulačky</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="calc-metal calc-panel" id="calc-settings">
                <div class="calc-panel__title"><i class="fas fa-user-shield me-2"></i>Sdílení s trenérem</div>
                <form method="post" action="<?= BASE_URL ?>/calculators.php" id="calcShareForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_share_setting">
                    <input type="hidden" name="share_with_coach" value="0">
                    <label class="calc-switch">
                        <input type="checkbox" name="share_with_coach" value="1" <?= $calcSettings['share_with_coach'] ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span class="calc-switch__track"></span>
                        <span>
                            <span class="d-block fw-bold text-white">Sdílet výsledky kalkulaček s trenérem</span>
                            <span class="calc-hint d-block mt-0"><?= $calcSettings['share_with_coach'] ? 'Trenér vidí vaše uložené výsledky a historii.' : 'Výsledky vidíte pouze vy.' ?></span>
                        </span>
                    </label>
                    <noscript><button type="submit" class="btn calc-btn-ghost btn-sm mt-3">Uložit</button></noscript>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($activeCalc === 'bmi'): ?>
    <div class="calc-breadcrumb mb-3">
        <a href="<?= BASE_URL ?>/calculators.php"><i class="fas fa-arrow-left me-1"></i>Kalkulačky</a>
    </div>

    <section class="calc-metal calc-hero mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="calc-coin"><i class="fas fa-weight-scale"></i></span>
            <div>
                <span class="calc-hero__eyebrow">Body Mass Index</span>
                <h1 class="calc-hero__title mb-0"><span class="calc-gold-text">BMI</span> <span class="calc-silver-text">kalkulačka</span></h1>
            </div>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-12 col-lg-5">
            <div class="calc-metal calc-panel">
                <div class="calc-panel__title"><i class="fas fa-sliders me-2"></i>Vstupní údaje</div>

                <form id="bmiForm" method="post" action="<?= BASE_URL ?>/calculators.php?calc=bmi" novalidate>
                    <?= csrfField() ?>

                    <div class="mb-3">
                        <label class="form-label" for="bmiHeight">Výška</label>
                        <div class="input-group calc-input-group">
                            <input type="number" id="bmiHeight" name="height_cm" class="form-control calc-input" min="80" max="250" step="0.1" inputmode="decimal" value="<?= h($bmiHeightCm !== null ? rtrim(rtrim(number_format($bmiHeightCm, 1, '.', ''), '0'), '.') : '') ?>" placeholder="179">
                            <span class="input-group-text calc-input-unit">cm</span>
                        </div>
                        <?php if ($isAthleteView): ?>
                            <div class="calc-hint">
                                <?php if ($bmiHeightSource !== null): ?>
                                    <i class="fas fa-circle-check me-1 text-success"></i>Převzato z: <?= h($bmiHeightSource) ?>
                                <?php else: ?>
                                    <i class="fas fa-circle-info me-1"></i>Výšku zatím v aplikaci nemáte – zadejte ji ručně. Při uložení výsledku se uloží do vašeho profilu.
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="bmiWeight">Hmotnost</label>
                        <div class="input-group calc-input-group">
                            <input type="number" id="bmiWeight" name="weight_kg" class="form-control calc-input" min="20" max="400" step="0.1" inputmode="decimal" value="<?= h($bmiWeightKg !== null ? number_format($bmiWeightKg, 1, '.', '') : '') ?>" placeholder="80">
                            <span class="input-group-text calc-input-unit">kg</span>
                        </div>
                    </div>

                    <?php if ($isAthleteView): ?>
                        <?php if ($bmiWeightKg !== null): ?>
                            <div class="calc-note mb-3">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                Použita vaše poslední zaznamenaná hmotnost <strong><?= h($fmt($bmiWeightKg)) ?> kg</strong>
                                z <strong><?= h(date('j. n. Y', strtotime($bmiWeightDate))) ?></strong><?php
                                if ($bmiWeightAgeDays !== null && $bmiWeightAgeDays > 0): ?> (před <?= (int)$bmiWeightAgeDays ?> <?= $bmiWeightAgeDays === 1 ? 'dnem' : 'dny' ?>)<?php endif; ?>.
                                <?php if (!$bmiHasTodayWeight): ?>Nemusí být aktuální – pokud se vaše hmotnost změnila, zadejte aktuální hodnotu.<?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="calc-note mb-3">
                                <i class="fas fa-triangle-exclamation me-1"></i>Zatím nemáte zaznamenanou žádnou hmotnost. Zadejte aktuální hmotnost.
                            </div>
                        <?php endif; ?>

                        <?php if (!$bmiHasTodayWeight): ?>
                            <div id="bmiSaveWrap" class="calc-confirm d-none" role="alertdialog" aria-labelledby="bmiSaveQuestion">
                                <div class="d-flex align-items-start gap-3">
                                    <span class="calc-coin calc-coin--sm"><i class="fas fa-floppy-disk"></i></span>
                                    <div>
                                        <div class="fw-bold text-white" id="bmiSaveQuestion">Uložit <span id="bmiSaveWeight"></span> kg jako vaši dnešní aktuální hmotnost?</div>
                                        <div class="calc-hint mt-1">Hmotnost se zapíše do historie stejně, jako byste ji zadali na svém profilu.</div>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-3">
                                    <button type="submit" id="bmiSaveYes" name="action" value="save_bmi_weight" class="btn calc-btn-gold flex-fill"><i class="fas fa-check me-1"></i>Ano, uložit</button>
                                    <button type="button" id="bmiSaveNo" class="btn calc-btn-ghost flex-fill"><i class="fas fa-xmark me-1"></i>Ne, jen spočítat</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <div class="calc-metal calc-panel">
                <div class="calc-panel__title"><i class="fas fa-chart-simple me-2"></i>Výsledek</div>

                <div id="bmiEmpty" class="text-center py-4 <?= $bmiInitial !== null ? 'd-none' : '' ?>">
                    <i class="fas fa-calculator fa-2x mb-2" style="color:var(--calc-silver-soft)"></i>
                    <div style="color:var(--calc-silver)">Zadejte výšku a hmotnost – výsledek se spočítá okamžitě.</div>
                </div>

                <div id="bmiResult" class="<?= $bmiInitial === null ? 'd-none' : '' ?>">
                    <div class="d-flex flex-wrap align-items-end gap-3">
                        <div class="calc-result__value calc-gold-text" id="bmiValue"><?= h($fmt($bmiInitial, 2)) ?></div>
                        <div class="pb-2">
                            <div class="small" style="color:var(--calc-silver-soft)">kg/m²</div>
                            <div class="calc-result__label" id="bmiLabel"><?= $bmiInitial !== null ? h($bmiCategories[calculatorBmiCategoryIndex($bmiInitial)]['label']) : '' ?></div>
                        </div>
                    </div>

                    <div class="calc-gauge">
                        <div class="calc-gauge__bar">
                            <?php foreach ($bmiCategories as $category): ?><span class="calc-tone-<?= h($category['tone']) ?>"></span><?php endforeach; ?>
                        </div>
                        <div class="calc-gauge__marker" id="bmiMarker" style="left:0%"></div>
                        <div class="calc-gauge__scale"><span>15</span><span>18,5</span><span>25</span><span>30</span><span>35</span><span>40</span><span>45+</span></div>
                    </div>

                    <div class="calc-actions mt-3">
                        <?php if ($isAthleteView): ?>
                        <button type="submit" form="bmiForm" name="action" value="save_bmi_result" id="bmiSaveResult" class="btn calc-btn-gold"><i class="fas fa-bookmark me-1"></i>Uložit výsledek</button>
                        <?php endif; ?>
                        <a href="#" id="bmiPrint" target="_blank" rel="noopener" class="btn calc-btn-ghost"><i class="fas fa-print me-1"></i>Tisk protokolu</a>
                    </div>
                    <?php if ($isAthleteView): ?>
                    <div class="calc-hint mt-2"><i class="fas fa-circle-info me-1"></i>Uložený výsledek se zapíše do historie ve vašem profilu<?= $calcSettings !== null && $calcSettings['share_with_coach'] ? ' a uvidí ho i váš trenér' : '' ?>.</div>
                    <?php endif; ?>
                </div>

                <table class="calc-cat-table mt-3" id="bmiTable">
                    <?php foreach ($bmiCategories as $index => $category): ?>
                    <tr data-index="<?= (int)$index ?>" class="<?= $bmiInitial !== null && calculatorBmiCategoryIndex($bmiInitial) === $index ? 'is-active' : '' ?>">
                        <td><span class="calc-cat-dot calc-tone-<?= h($category['tone']) ?>"></span></td>
                        <td><?= h($category['label']) ?></td>
                        <td><?= h($category['range']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <div class="calc-note calc-note--info mt-3">
                    <i class="fas fa-dumbbell me-1"></i>
                    <strong>Upozornění pro sportovce:</strong> BMI nerozlišuje svalovou a tukovou hmotu. U velmi svalnatých sportovců proto může nadhodnocovat množství tělesného tuku. Hodnoty platí pro dospělé.
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="calc-metal calc-panel">
                <div class="calc-panel__title"><i class="fas fa-square-root-variable me-2"></i>Jak se BMI počítá</div>
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-md-6">
                        <div class="calc-formula">
                            BMI =
                            <span class="frac"><span>hmotnost (kg)</span><span>výška (m)²</span></span>
                        </div>
                    </div>
                    <div class="col-12 col-md-6" style="color:var(--calc-silver)">
                        <div class="small text-uppercase fw-bold mb-1" style="letter-spacing:.15em;color:var(--calc-silver-soft)">Příklad</div>
                        Hmotnost 103 kg, výška 179 cm = 1,79 m<br>
                        <span class="calc-formula" style="font-size:1rem">BMI = 103 / (1,79 × 1,79) = <strong class="calc-gold-text">32,15</strong></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isAthleteView): ?>
        <div class="col-12">
            <div class="calc-metal calc-panel">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div class="calc-panel__title"><i class="fas fa-chart-line me-2"></i>Vývoj BMI</div>
                    <span class="calc-hint mt-0">Z historie vážení při výšce <?= $bmiHeightCm !== null ? h($fmt($bmiHeightCm)) . ' cm' : '–' ?> · zelené pásmo = normální hmotnost</span>
                </div>
                <?php if (count($bmiTrend) >= 2): ?>
                    <?= calculatorTrendSvg($bmiTrend, ['theme' => 'dark', 'band' => [18.5, 25.0]]) ?>
                <?php else: ?>
                    <div class="text-center py-3" style="color:var(--calc-silver)">
                        <i class="fas fa-chart-line fa-2x mb-2" style="color:var(--calc-silver-soft)"></i>
                        <div>Graf se zobrazí, jakmile budete mít uloženou výšku a alespoň dvě vážení.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12" id="bmi-history">
            <div class="calc-metal calc-panel">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div class="calc-panel__title"><i class="fas fa-clock-rotate-left me-2"></i>Historie uložených výsledků</div>
                    <a href="<?= h($printUrl) ?>?type=report&amp;print=1" target="_blank" rel="noopener" class="btn calc-btn-ghost btn-sm"><i class="fas fa-print me-1"></i>Tisk kompletní zprávy</a>
                </div>
                <?php if (!$bmiHistory): ?>
                    <div class="py-2" style="color:var(--calc-silver)">Zatím nemáte uložený žádný výsledek. Spočítejte BMI a klikněte na <strong>Uložit výsledek</strong>.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="calc-history">
                            <thead><tr><th>Datum</th><th>Hmotnost · výška</th><th>BMI</th><th>Kategorie</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($bmiHistory as $row): ?>
                                <tr class="<?= (int)$row['id'] === $savedResultId ? 'is-new' : '' ?>">
                                    <td><?= h(date('j. n. Y H:i', strtotime((string)$row['created_at']))) ?></td>
                                    <td><?= h(calculatorResultSummary($row)) ?></td>
                                    <td class="calc-bmi-num"><?= h($fmt($row['result_value'], 2)) ?></td>
                                    <td><span class="calc-cat-dot calc-tone-<?= h((string)($row['inputs']['category_tone'] ?? 'info')) ?> me-2"></span><?= h((string)$row['result_label']) ?></td>
                                    <td>
                                        <a href="<?= h($printUrl) ?>?type=result&amp;id=<?= (int)$row['id'] ?>&amp;print=1" target="_blank" rel="noopener" class="calc-icon-btn" title="Tisk protokolu"><i class="fas fa-print"></i></a>
                                        <form method="post" action="<?= BASE_URL ?>/calculators.php?calc=bmi" class="d-inline" onsubmit="return confirm('Smazat tento výsledek z historie?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_result">
                                            <input type="hidden" name="result_id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="calc-icon-btn calc-icon-btn--danger" title="Smazat"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<?php if ($activeCalc === 'bmi'): ?>
<script>
(function () {
    const categories = <?= json_encode(array_map(static fn(array $c): array => ['min' => $c['min'], 'max' => $c['max'], 'label' => $c['label']], $bmiCategories), JSON_UNESCAPED_UNICODE) ?>;
    const savedWeight = <?= json_encode($bmiWeightKg) ?>;
    const heightInput = document.getElementById('bmiHeight');
    const weightInput = document.getElementById('bmiWeight');
    const resultBox = document.getElementById('bmiResult');
    const emptyBox = document.getElementById('bmiEmpty');
    const valueEl = document.getElementById('bmiValue');
    const labelEl = document.getElementById('bmiLabel');
    const marker = document.getElementById('bmiMarker');
    const rows = document.querySelectorAll('#bmiTable tr');
    const saveWrap = document.getElementById('bmiSaveWrap');
    const saveWeightEl = document.getElementById('bmiSaveWeight');
    const saveResultBtn = document.getElementById('bmiSaveResult');
    const printLink = document.getElementById('bmiPrint');
    const printBase = <?= json_encode($printUrl) ?>;
    // Pozice markeru: 6 stejně širokých segmentů s hranicemi 15/18,5/25/30/35/40/45
    const bounds = [15, 18.5, 25, 30, 35, 40, 45];

    const parse = (input) => {
        const value = parseFloat(String(input.value).replace(',', '.'));
        return Number.isFinite(value) ? value : 0;
    };

    function markerPercent(bmi) {
        if (bmi <= bounds[0]) return 0;
        if (bmi >= bounds[bounds.length - 1]) return 100;
        for (let i = 0; i < bounds.length - 1; i++) {
            if (bmi < bounds[i + 1]) {
                return ((i + (bmi - bounds[i]) / (bounds[i + 1] - bounds[i])) / (bounds.length - 1)) * 100;
            }
        }
        return 100;
    }

    // Dotaz na uložení váhy se zobrazí až po dopsání hodnoty (pauza / opuštění pole),
    // jen pokud se liší od uložené váhy a uživatel ji pro tuto hodnotu neodmítl.
    let promptTimer = null;
    let declinedWeight = null;
    const roundWeight = (w) => Math.round(w * 10) / 10;

    function hideSavePrompt() {
        if (saveWrap) saveWrap.classList.add('d-none');
    }

    function showSavePromptIfNeeded(valid, weight) {
        if (!saveWrap) return;
        const rounded = roundWeight(weight);
        const changed = savedWeight === null || Math.abs(rounded - savedWeight) >= 0.05;
        if (!valid || !changed || declinedWeight === rounded) {
            hideSavePrompt();
            return;
        }
        saveWeightEl.textContent = rounded.toFixed(1).replace('.', ',');
        saveWrap.classList.remove('d-none');
    }

    function scheduleSavePrompt(valid, weight, immediate) {
        if (!saveWrap) return;
        const isVisible = !saveWrap.classList.contains('d-none');
        if (isVisible && valid && saveWeightEl.textContent === roundWeight(weight).toFixed(1).replace('.', ',')) {
            return;
        }
        clearTimeout(promptTimer);
        hideSavePrompt();
        promptTimer = setTimeout(() => showSavePromptIfNeeded(valid, weight), immediate ? 0 : 1200);
    }

    function updatePrintLink(valid, height, weight) {
        if (!printLink) return;
        printLink.classList.toggle('disabled', !valid);
        printLink.href = valid
            ? printBase + '?type=result&calc=bmi&print=1&height_cm=' + encodeURIComponent(height) + '&weight_kg=' + encodeURIComponent(weight)
            : '#';
    }

    function update() {
        const height = parse(heightInput);
        const weight = parse(weightInput);
        const valid = height >= 80 && height <= 250 && weight >= 20 && weight <= 400;

        scheduleSavePrompt(valid, weight);
        updatePrintLink(valid, height, weight);
        if (saveResultBtn) saveResultBtn.disabled = !valid;

        if (!valid) {
            resultBox.classList.add('d-none');
            emptyBox.classList.remove('d-none');
            rows.forEach((row) => row.classList.remove('is-active'));
            return;
        }

        const bmi = weight / Math.pow(height / 100, 2);
        const index = categories.findIndex((c) => bmi >= c.min && (c.max === null || bmi < c.max));
        valueEl.textContent = bmi.toFixed(2).replace('.', ',');
        labelEl.textContent = index >= 0 ? categories[index].label : '';
        marker.style.left = markerPercent(bmi) + '%';
        rows.forEach((row) => row.classList.toggle('is-active', Number(row.dataset.index) === index));
        resultBox.classList.remove('d-none');
        emptyBox.classList.add('d-none');
    }

    heightInput.addEventListener('input', update);
    weightInput.addEventListener('input', update);
    const promptNow = () => {
        const height = parse(heightInput);
        const weight = parse(weightInput);
        scheduleSavePrompt(height >= 80 && height <= 250 && weight >= 20 && weight <= 400, weight, true);
    };
    weightInput.addEventListener('change', promptNow);
    heightInput.addEventListener('change', promptNow);
    document.getElementById('bmiForm').addEventListener('submit', function (event) {
        const yes = document.getElementById('bmiSaveYes');
        if (saveResultBtn && event.submitter === saveResultBtn) {
            const height = parse(heightInput);
            const weight = parse(weightInput);
            if (!(height >= 80 && height <= 250 && weight >= 20 && weight <= 400)) {
                event.preventDefault();
                return;
            }
            weightInput.value = roundWeight(weight).toFixed(1);
            setTimeout(() => { saveResultBtn.disabled = true; }, 0);
            return;
        }
        if (!saveWrap || saveWrap.classList.contains('d-none') || event.submitter !== yes) {
            event.preventDefault();
            if (event.submitter !== yes) promptNow();
            return;
        }
        weightInput.value = roundWeight(parse(weightInput)).toFixed(1);
        setTimeout(() => { yes.disabled = true; }, 0);
    });
    const noBtn = document.getElementById('bmiSaveNo');
    if (noBtn) {
        noBtn.addEventListener('click', function () {
            declinedWeight = roundWeight(parse(weightInput));
            hideSavePrompt();
        });
    }
    update();
})();
</script>
<?php endif; ?>
<?php
if ($isAthleteView) {
    renderAthleteFooter();
} else {
    renderFooter();
}
