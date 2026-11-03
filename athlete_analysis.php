<?php
// athlete_analysis.php – Analýza sportovce (vstupní průvodce, výsledky, historie, porovnání, trenérské funkce)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/athlete_analysis.php';

$isAthleteView = !isLoggedIn();
if ($isAthleteView) {
    requireAthleteLogin();
    $viewerType = 'athlete';
    $viewerId = (int)getCurrentAthleteId();
    $athleteId = $viewerId;
    $coachId = 0;
    $homeUrl = BASE_URL . '/athlete_dashboard.php';
    if (!calculatorAppVisibleForUser('athlete', $athleteId)) {
        redirect($homeUrl);
    }
} else {
    requireLogin();
    $viewerType = 'coach';
    $coachId = (int)getCurrentCoachId();
    $viewerId = $coachId;
    $athleteId = intParam($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET, 'athlete_id');
    $homeUrl = BASE_URL . '/dashboard.php';
}

if (!calculatorIsEnabled('analysis')) {
    flash('warning', 'Analýza sportovce je momentálně vypnutá.');
    redirect($isAthleteView ? BASE_URL . '/calculators.php' : $homeUrl);
}

$pdo = getDB();
if (!analysisEnsureSchema($pdo)) {
    flash('danger', 'Modul analýzy se nepodařilo inicializovat.');
    redirect($homeUrl);
}

$athlete = null;
if ($athleteId > 0) {
    $stmt = $isAthleteView
        ? $pdo->prepare('SELECT id, first_name, last_name, birth_date, gender, coach_id FROM athletes WHERE id = ? LIMIT 1')
        : $pdo->prepare('SELECT id, first_name, last_name, birth_date, gender, coach_id FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
    $stmt->execute($isAthleteView ? [$athleteId] : [$athleteId, $coachId]);
    $athlete = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$athlete) {
        flash('danger', 'Sportovec nenalezen.');
        redirect($homeUrl);
    }
}

/** URL na tuto stránku se zachováním kontextu sportovce (u trenéra). */
$pageUrl = static function (array $params = []) use ($isAthleteView, $athleteId): string {
    if (!$isAthleteView && $athleteId > 0) {
        $params = ['athlete_id' => $athleteId] + $params;
    }
    $query = http_build_query(array_filter($params, static fn($v) => $v !== null && $v !== ''));
    return BASE_URL . '/athlete_analysis.php' . ($query !== '' ? '?' . $query : '');
};
$printUrl = static function (int $id) use ($isAthleteView, $athleteId): string {
    return BASE_URL . '/athlete_analysis_print.php?id=' . $id . ($isAthleteView ? '' : '&athlete_id=' . $athleteId) . '&print=1';
};

$view = (string)($_GET['view'] ?? '');
$wizardStep = max(1, min(5, intParam($_GET, 'step') ?: 1));
$wizardInputs = null;
$wizardErrors = [];
$wizardStepsWithErrors = [];
$sweatResult = null;
$today = date('Y-m-d');

$visibleFinals = static function () use ($pdo, $athleteId, $isAthleteView): array {
    $rows = analysisFetchFinals($pdo, $athleteId, 200);
    return $isAthleteView ? $rows : analysisFilterForCoach($pdo, $athleteId, $rows);
};
$fetchVisible = static function (int $id) use ($pdo, $athleteId, $isAthleteView): ?array {
    $row = analysisFetch($pdo, $id, $athleteId);
    if ($row && !$isAthleteView && !analysisCoachCanViewRow($pdo, $row)) {
        return null;
    }
    return $row;
};

/** Uloží hmotnost z analýzy jako vážení (pokud pro daný den ještě neexistuje). */
$saveWeightLog = static function (array $inputs) use ($athleteId, $isAthleteView, $coachId): bool {
    if (empty($inputs['save_weight']) || empty($inputs['weight_kg'])) {
        return false;
    }
    try {
        return addAthleteWeightLog($athleteId, (string)($inputs['weigh_date'] ?? date('Y-m-d')), (float)$inputs['weight_kg'], $isAthleteView ? 'athlete_link' : 'coach', $isAthleteView ? null : $coachId) > 0;
    } catch (Throwable $e) {
        error_log('analysis save weight: ' . $e->getMessage());
        return false;
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $athleteId > 0) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect($pageUrl());
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'wizard') {
        $step = max(1, min(5, intParam($_POST, 'step') ?: 1));
        $nav = (string)($_POST['nav'] ?? 'next');
        $draft = analysisGetDraft($pdo, $athleteId, $viewerType);
        $base = $draft ? $draft['inputs'] : analysisPrefill($pdo, $athleteId)['inputs'];
        $posted = [];
        foreach (analysisStepFields()[$step] as $field) {
            if (in_array($field, ['special', 'activity_types'], true)) {
                $posted[$field] = is_array($_POST[$field] ?? null) ? $_POST[$field] : [];
            } elseif ($field === 'save_weight') {
                $posted[$field] = ($_POST[$field] ?? '') === '1' ? '1' : '0';
            } else {
                $posted[$field] = $_POST[$field] ?? null;
            }
        }
        $merged = array_merge($base, $posted);
        $requireSteps = $nav === 'next' ? [$step] : ($nav === 'finish' ? [1, 2, 3, 4, 5] : []);
        [$clean, $errors] = analysisValidateInput($merged, $requireSteps, $today);

        if ($errors && in_array($nav, ['next', 'finish'], true)) {
            $stepFields = analysisStepFields();
            foreach (array_keys($errors) as $errField) {
                foreach ($stepFields as $n => $fields) {
                    if (in_array($errField, $fields, true)) {
                        $wizardStepsWithErrors[$n] = true;
                    }
                }
            }
            ksort($wizardStepsWithErrors);
            analysisSaveDraft($pdo, $athleteId, $viewerType, $viewerId, $clean, $step);
            $view = 'wizard';
            $wizardStep = isset($wizardStepsWithErrors[$step]) ? $step : (int)array_key_first($wizardStepsWithErrors);
            $wizardInputs = $clean;
            $wizardErrors = $errors;
        } elseif ($nav === 'finish') {
            $results = analysisBuild($clean, [], $today);
            $id = analysisFinalize($pdo, $athleteId, $viewerType, $viewerId, $clean, $results);
            if ($id > 0) {
                $weightSaved = $saveWeightLog($clean);
                flash('success', 'Analýza byla dokončena a uložena do profilu' . ($weightSaved ? ' – hmotnost byla zapsána jako nové vážení.' : '.'));
                redirect($pageUrl(['view' => 'result', 'id' => $id]));
            }
            flash('danger', 'Analýzu se nepodařilo uložit.');
            redirect($pageUrl(['view' => 'wizard', 'step' => 5]));
        } else {
            $nextStep = $nav === 'back' ? max(1, $step - 1) : ($nav === 'next' ? min(5, $step + 1) : $step);
            if (preg_match('/^goto([1-5])$/', $nav, $gotoMatch)) {
                $nextStep = (int)$gotoMatch[1];
            }
            analysisSaveDraft($pdo, $athleteId, $viewerType, $viewerId, $clean, $nextStep);
            if ($nav === 'save') {
                flash('success', 'Rozpracovaný dotazník byl uložen. Můžete se k němu kdykoliv vrátit.');
                redirect($pageUrl());
            }
            redirect($pageUrl(['view' => 'wizard', 'step' => $nextStep]));
        }
    }

    if ($action === 'discard_draft') {
        analysisDeleteDraft($pdo, $athleteId, $viewerType);
        flash('success', 'Rozpracovaný dotazník byl zahozen.');
        redirect($pageUrl());
    }

    if ($action === 'delete_analysis' && $isAthleteView) {
        if (analysisDelete($pdo, intParam($_POST, 'id'), $athleteId)) {
            flash('success', 'Analýza byla smazána z historie.');
        } else {
            flash('warning', 'Analýzu se nepodařilo najít.');
        }
        redirect($pageUrl() . '#history');
    }

    if ($action === 'coach_review' && !$isAthleteView) {
        $id = intParam($_POST, 'id');
        if ($fetchVisible($id) === null) {
            flash('danger', 'Analýzu nelze upravit.');
            redirect($pageUrl());
        }
        $targets = [];
        foreach (['kcal', 'protein_g', 'fat_g'] as $key) {
            $targets[$key] = intParam($_POST, 'target_' . $key);
        }
        if (($_POST['reset_targets'] ?? '') === '1') {
            $targets = [];
        }
        if (analysisSaveCoachReview($pdo, $id, $athleteId, $targets, (string)($_POST['coach_note'] ?? ''))) {
            flash('success', 'Poznámka a doporučené cíle byly uloženy.');
        } else {
            flash('danger', 'Změny se nepodařilo uložit.');
        }
        redirect($pageUrl(['view' => 'result', 'id' => $id]) . '#coach-review');
    }

    if ($action === 'recompute_weight') {
        $last = $visibleFinals()[0] ?? null;
        $weightStats = getAthleteWeightStats($athleteId);
        if ($last && $weightStats['current_weight'] !== null) {
            $inputs = $last['inputs'];
            $inputs['weight_kg'] = (float)$weightStats['current_weight'];
            $inputs['weigh_date'] = substr((string)$weightStats['current_date'], 0, 10);
            $inputs['save_weight'] = false;
            $age = calculateAge($athlete['birth_date'] ?? null);
            if ($age !== null && $age > 0) {
                $inputs['age'] = $age;
            }
            [$clean] = analysisValidateInput($inputs, [], $today);
            $id = analysisFinalize($pdo, $athleteId, $viewerType, $viewerId, $clean, analysisBuild($clean, [], $today));
            if ($id > 0) {
                flash('success', 'Analýza byla přepočítána s aktuální hmotností a uložena jako nový záznam. Původní výsledky zůstaly v historii.');
                redirect($pageUrl(['view' => 'result', 'id' => $id]));
            }
        }
        flash('warning', 'Přepočet se nepodařilo provést.');
        redirect($pageUrl());
    }

    if ($action === 'sweat_rate') {
        $id = intParam($_POST, 'id');
        $num = static fn(string $k): float => (float)str_replace(',', '.', trim((string)($_POST[$k] ?? '0')));
        $minutes = $num('sweat_minutes');
        $sweat = calculateSweatRate($num('sweat_pre'), $num('sweat_post'), $num('sweat_drank_l'), $minutes / 60, $num('sweat_urine_l'));
        if ($fetchVisible($id) === null || $sweat === null || $minutes < 10 || $minutes > 600 || $num('sweat_pre') < 30 || $num('sweat_post') < 30) {
            flash('warning', 'Zkontrolujte zadané hodnoty testu pocení (hmotnost před a po, vypité tekutiny, délka tréninku 10–600 min).');
        } else {
            analysisUpdateInputs($pdo, $id, $athleteId, [
                'sweat_rate_l_h' => min(4.0, $sweat['rate_l_per_h']),
                'sweat_test' => $sweat + ['date' => $today, 'minutes' => (int)$minutes],
            ]);
            flash($sweat['warning'] ? 'warning' : 'success', 'Rychlost pocení: ' . number_format($sweat['rate_l_per_h'], 2, ',', '') . ' l/h (ztráta ' . number_format($sweat['loss_pct'], 1, ',', '') . ' % hmotnosti). Doporučení tekutin bylo přepočítáno.' . ($sweat['warning'] ? ' ' . $sweat['warning'] : ''));
        }
        redirect($pageUrl(['view' => 'result', 'id' => $id]) . '#card-hydration');
    }

    redirect($pageUrl());
}

$fmt = static fn($value, int $decimals = 0): string => ($value === null || $value === '' || !is_numeric($value)) ? '–' : number_format((float)$value, $decimals, ',', ' ');
$goalShort = analysisGoalShortLabels();
$metricDefs = analysisMetricDefinitions();
$stepTitles = analysisStepTitles();

$coachOverview = [];
$finals = [];
$latest = null;
$draft = null;
$current = null;
$prefill = ['inputs' => [], 'sources' => [], 'weight_stats' => ['current_weight' => null, 'current_date' => null]];
$weightNotice = null;
$series = [];
$chartMetric = 'weight_kg';
$compareA = null;
$compareB = null;
$diaryTotals = [];
$mealPlanTotals = [];
$upcoming = [];

if ($athleteId === 0) {
    $view = 'coach_overview';
    $coachOverview = analysisCoachOverview($pdo, $coachId);
} else {
    $finals = $visibleFinals();
    $latest = $finals[0] ?? null;
    $draft = analysisGetDraft($pdo, $athleteId, $viewerType);
    $prefill = analysisPrefill($pdo, $athleteId);
    if (!$isAthleteView && $_SERVER['REQUEST_METHOD'] === 'GET') {
        analysisMarkSeenByCoach($pdo, $athleteId);
    }

    if ($view === 'result') {
        $current = $fetchVisible(intParam($_GET, 'id'));
        if (!$current || $current['status'] !== 'final') {
            flash('warning', 'Analýza nebyla nalezena.');
            redirect($pageUrl());
        }
        $upcoming = analysisUpcomingTrainings($pdo, $athleteId, 7);
    } elseif ($view === 'wizard') {
        if ($wizardInputs === null) {
            $wizardInputs = $draft ? $draft['inputs'] : $prefill['inputs'];
            if (!isset($_GET['step']) && $draft) {
                $wizardStep = max(1, min(5, (int)$draft['current_step']));
            }
        }
    } else {
        $view = 'overview';
    }

    // Upozornění na novější vážení, než ze kterého vychází poslední analýza.
    $ws = $prefill['weight_stats'];
    if ($latest && $ws['current_weight'] !== null) {
        $lastWeight = (float)($latest['inputs']['weight_kg'] ?? 0);
        $lastWeighDate = (string)($latest['inputs']['weigh_date'] ?? $latest['date']);
        $currentWeighDate = substr((string)$ws['current_date'], 0, 10);
        if ($currentWeighDate > $lastWeighDate && abs((float)$ws['current_weight'] - $lastWeight) >= 0.2) {
            $liveInputs = $latest['inputs'];
            $liveInputs['weight_kg'] = (float)$ws['current_weight'];
            $live = analysisBuild($liveInputs, [], $today);
            $weightNotice = [
                'old_weight' => $lastWeight,
                'new_weight' => (float)$ws['current_weight'],
                'date' => $currentWeighDate,
                'old' => $latest['results'],
                'live' => $live,
            ];
        }
    }

    if ($view === 'overview') {
        $series = analysisSeries($finals);
        $chartMetric = isset($metricDefs[$_GET['metric'] ?? '']) ? (string)$_GET['metric'] : 'weight_kg';
        if (count($finals) >= 2) {
            $byId = array_column($finals, null, 'id');
            $compareA = $byId[intParam($_GET, 'a')] ?? $finals[count($finals) - 1];
            $compareB = $byId[intParam($_GET, 'b')] ?? $finals[0];
            if (strcmp($compareA['date'] . sprintf('%010d', $compareA['id']), $compareB['date'] . sprintf('%010d', $compareB['id'])) > 0) {
                [$compareA, $compareB] = [$compareB, $compareA];
            }
        }
        if ($latest) {
            $diaryTotals = analysisFoodDiaryTotals($pdo, $athleteId, date('Y-m-d', strtotime('-6 days')), $today);
            $mealPlanTotals = analysisMealPlanTotals($pdo, $athleteId);
        }
    }
}

$athleteName = $athlete ? trim($athlete['first_name'] . ' ' . $athlete['last_name']) : '';
$pageTitle = 'Analýza sportovce' . ($athleteName !== '' && !$isAthleteView ? ' – ' . $athleteName : '');
if ($isAthleteView) {
    require_once __DIR__ . '/includes/athlete_header.php';
    renderAthleteHeader($pageTitle);
} else {
    require_once __DIR__ . '/includes/header.php';
    renderHeader($pageTitle);
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/calculators.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/calculators.css') ?>">

<div class="calc-app">
    <div class="calc-breadcrumb small mb-3">
        <?php if ($isAthleteView): ?>
            <a href="<?= BASE_URL ?>/calculators.php"><i class="fas fa-arrow-left me-1"></i>Kalkulačky</a>
        <?php elseif ($athleteId > 0): ?>
            <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $athleteId ?>"><i class="fas fa-arrow-left me-1"></i>Profil sportovce</a>
            <span class="text-secondary mx-2">·</span>
            <a href="<?= BASE_URL ?>/athlete_analysis.php"><i class="fas fa-users me-1"></i>Přehled sportovců</a>
        <?php else: ?>
            <a href="<?= BASE_URL ?>/calculators.php"><i class="fas fa-arrow-left me-1"></i>Kalkulačky</a>
        <?php endif; ?>
        <?php if ($view !== 'overview' && $view !== 'coach_overview'): ?>
            <span class="text-secondary mx-2">·</span><a href="<?= h($pageUrl()) ?>"><i class="fas fa-dna me-1"></i>Přehled analýz</a>
        <?php endif; ?>
    </div>

    <section class="calc-metal calc-hero mb-4">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="calc-hero__eyebrow">TrainerApp Lab</span>
            <?php if (!$isAthleteView && $athleteName !== ''): ?><span class="calc-chip"><i class="fas fa-user"></i><?= h($athleteName) ?> (ID <?= $athleteId ?>)</span><?php endif; ?>
        </div>
        <h1 class="calc-hero__title"><span class="calc-silver-text">Analýza </span><span class="calc-gold-text">sportovce</span></h1>
        <p class="calc-hero__lead">Metabolismus, tělesné složení, energie, makroživiny, pitný režim, jídla během dne a tréninková doporučení – z jednoho dotazníku a propojené s profilem.</p>
    </section>

<?php if ($view === 'coach_overview'): ?>
    <?php
    $withAnalysis = array_values(array_filter($coachOverview, static fn(array $r): bool => $r['analysis'] !== null));
    $sortKey = isset($metricDefs[$_GET['sort'] ?? '']) ? (string)$_GET['sort'] : '';
    if ($sortKey !== '') {
        usort($withAnalysis, static fn(array $a, array $b): int => ($b['analysis']['metrics'][$sortKey] ?? -INF) <=> ($a['analysis']['metrics'][$sortKey] ?? -INF));
    }
    $withoutAnalysis = array_values(array_filter($coachOverview, static fn(array $r): bool => $r['analysis'] === null));
    $sortLink = static fn(string $key, string $label): string => '<a class="text-reset text-decoration-none" href="' . h(BASE_URL . '/athlete_analysis.php?sort=' . $key) . '">' . h($label) . ($key === ($_GET['sort'] ?? '') ? ' <i class="fas fa-sort-down"></i>' : '') . '</a>';
    ?>
    <div class="calc-metal calc-panel mb-4">
        <div class="calc-panel__title">Porovnání sportovců · poslední analýzy</div>
        <?php if (!$withAnalysis): ?>
            <div class="calc-note calc-note--info"><i class="fas fa-circle-info me-1"></i>Zatím žádný sportovec nemá dokončenou analýzu, kterou můžete vidět. Analýzu můžete spustit sami u konkrétního sportovce níže.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="an-table">
                <thead><tr>
                    <th>Sportovec</th><th>Datum</th><th>Cíl</th>
                    <th class="text-end"><?= $sortLink('weight_kg', 'Hmotnost') ?></th>
                    <th class="text-end"><?= $sortLink('bmi', 'BMI') ?></th>
                    <th class="text-end"><?= $sortLink('body_fat_pct', 'Tuk %') ?></th>
                    <th class="text-end"><?= $sortLink('tdee', 'TDEE') ?></th>
                    <th class="text-end"><?= $sortLink('kcal', 'Příjem') ?></th>
                    <th class="text-end">B / S / T (g)</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($withAnalysis as $row): $a = $row['analysis']; $m = $a['metrics']; ?>
                    <tr>
                        <td><strong class="text-white"><?= h($row['name']) ?></strong> <span class="small">(ID <?= (int)$row['athlete_id'] ?>)</span><?= !$row['share'] ? ' <span class="small" title="Sportovec nesdílí – vidíte jen analýzy vytvořené vámi"><i class="fas fa-lock"></i></span>' : '' ?></td>
                        <td><?= h(date('j. n. Y', strtotime($a['date']))) ?></td>
                        <td><?= h($goalShort[$a['inputs']['goal'] ?? ''] ?? '–') ?></td>
                        <td class="num"><?= $fmt($m['weight_kg'], 1) ?></td>
                        <td class="num"><?= $fmt($m['bmi'], 1) ?></td>
                        <td class="num"><?= $fmt($m['body_fat_pct'], 1) ?></td>
                        <td class="num"><?= $fmt($m['tdee']) ?></td>
                        <td class="num"><?= $fmt($m['kcal']) ?></td>
                        <td class="num"><?= $fmt($m['protein_g']) ?> / <?= $fmt($m['carbs_g']) ?> / <?= $fmt($m['fat_g']) ?></td>
                        <td class="text-end"><a class="calc-icon-btn" title="Otevřít" href="<?= BASE_URL ?>/athlete_analysis.php?athlete_id=<?= (int)$row['athlete_id'] ?>&amp;view=result&amp;id=<?= (int)$a['id'] ?>"><i class="fas fa-arrow-right"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="calc-hint mt-2">Klikněte na záhlaví sloupce pro seřazení. Údaje jsou orientační odhady.</div>
        <?php endif; ?>
    </div>
    <?php if ($withoutAnalysis): ?>
    <div class="calc-metal calc-panel mb-4">
        <div class="calc-panel__title">Sportovci bez viditelné analýzy</div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($withoutAnalysis as $row): ?>
                <a class="calc-btn-ghost btn btn-sm" href="<?= BASE_URL ?>/athlete_analysis.php?athlete_id=<?= (int)$row['athlete_id'] ?>"><?= h($row['name']) ?> (ID <?= (int)$row['athlete_id'] ?>)<?= !$row['share'] ? ' <i class="fas fa-lock ms-1"></i>' : '' ?></a>
            <?php endforeach; ?>
        </div>
        <div class="calc-hint mt-2"><i class="fas fa-lock me-1"></i>= sportovec výsledky kalkulaček nesdílí; uvidíte jen analýzy, které vytvoříte sami.</div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($view === 'overview'): ?>
    <?php $shareOn = calculatorGetAthleteSettings($pdo, $athleteId)['share_with_coach']; ?>
    <?php if ($draft): ?>
    <div class="calc-confirm d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <span class="calc-coin calc-coin--sm"><i class="fas fa-pen"></i></span>
            <div>
                <div class="fw-bold text-white">Máte rozpracovaný dotazník</div>
                <div class="small text-secondary">Krok <?= (int)$draft['current_step'] ?> z 5 · uloženo <?= h(date('j. n. Y H:i', strtotime((string)$draft['updated_at']))) ?></div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn calc-btn-gold" href="<?= h($pageUrl(['view' => 'wizard'])) ?>"><i class="fas fa-play me-1"></i>Pokračovat</a>
            <form method="post" onsubmit="return confirm('Opravdu zahodit rozpracovaný dotazník?');">
                <?= csrfField() ?><input type="hidden" name="action" value="discard_draft"><input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                <button class="btn calc-btn-ghost" type="submit"><i class="fas fa-trash me-1"></i>Zahodit</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isAthleteView && !$shareOn): ?>
        <div class="calc-note calc-note--info mb-4"><i class="fas fa-lock me-1"></i>Sportovec výsledky kalkulaček se svým trenérem nesdílí. Vidíte pouze analýzy, které jste vytvořili vy.</div>
    <?php endif; ?>

    <?php if ($weightNotice): $wo = $weightNotice['old']; $wl = $weightNotice['live']; ?>
    <div class="calc-metal calc-panel mb-4">
        <div class="calc-panel__title"><i class="fas fa-weight-scale me-1"></i>Hmotnost se změnila</div>
        <p class="mb-3">Poslední analýza vychází z hmotnosti <strong class="text-white"><?= $fmt($weightNotice['old_weight'], 1) ?> kg</strong>, ale poslední vážení (<?= h(date('j. n. Y', strtotime($weightNotice['date']))) ?>) ukazuje <strong class="text-white"><?= $fmt($weightNotice['new_weight'], 1) ?> kg</strong>. Orientační přepočet:</p>
        <div class="row g-3 mb-3">
            <?php foreach (['BMR' => [$wo['metabolism']['bmr'] ?? null, $wl['metabolism']['bmr'] ?? null], 'TDEE' => [$wo['metabolism']['tdee'] ?? null, $wl['metabolism']['tdee'] ?? null], 'Příjem' => [$wo['calories']['kcal'] ?? null, $wl['calories']['kcal'] ?? null], 'Bílkoviny (g)' => [$wo['macros']['protein_g'] ?? null, $wl['macros']['protein_g'] ?? null]] as $label => [$old, $new]): ?>
            <div class="col-6 col-md-3"><div class="calc-stat">
                <div class="calc-stat__label"><?= h($label) ?></div>
                <div class="calc-stat__value"><?= $fmt($new) ?></div>
                <div class="small text-secondary">dříve <?= $fmt($old) ?></div>
            </div></div>
            <?php endforeach; ?>
        </div>
        <form method="post" class="d-inline">
            <?= csrfField() ?><input type="hidden" name="action" value="recompute_weight"><input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
            <button class="btn calc-btn-gold" type="submit"><i class="fas fa-rotate me-1"></i>Uložit jako novou analýzu</button>
        </form>
        <span class="calc-hint ms-2">Ostatní odpovědi z dotazníku zůstanou stejné, původní analýza zůstane v historii.</span>
    </div>
    <?php endif; ?>

    <?php if (!$latest): ?>
    <div class="calc-metal calc-panel mb-4 text-center py-5">
        <span class="calc-coin mb-3"><i class="fas fa-dna"></i></span>
        <h2 class="calc-gold-text fw-bold">Začněte první analýzou</h2>
        <p class="mx-auto" style="max-width:560px">Pět krátkých kroků: základní údaje, cíl, pohyb, stravovací návyky a preference. Údaje z profilu (věk, pohlaví, výška, poslední vážení) předvyplníme.</p>
        <a class="btn calc-btn-gold btn-lg" href="<?= h($pageUrl(['view' => 'wizard'])) ?>"><i class="fas fa-play me-1"></i><?= $draft ? 'Pokračovat v dotazníku' : 'Spustit analýzu' ?></a>
    </div>
    <?php else: $lr = $latest['results']; ?>
    <div class="calc-metal calc-panel mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <div class="calc-panel__title mb-1">Poslední analýza · <?= h(date('j. n. Y', strtotime($latest['date']))) ?></div>
                <div class="fw-bold text-white"><?= h($goalShort[$latest['inputs']['goal'] ?? ''] ?? '') ?><?= ($latest['created_by_type'] ?? '') === 'coach' ? ' <span class="calc-chip calc-chip--silver ms-1"><i class="fas fa-user-tie"></i>Vytvořil trenér</span>' : '' ?></div>
            </div>
            <div class="calc-actions">
                <a class="btn calc-btn-gold" href="<?= h($pageUrl(['view' => 'result', 'id' => $latest['id']])) ?>"><i class="fas fa-chart-pie me-1"></i>Kompletní výsledky</a>
                <a class="btn calc-btn-ghost" href="<?= h($printUrl($latest['id'])) ?>" target="_blank" rel="noopener"><i class="fas fa-print me-1"></i>Tisk</a>
                <a class="btn calc-btn-ghost" href="<?= h($pageUrl(['view' => 'wizard'])) ?>"><i class="fas fa-plus me-1"></i>Nová analýza</a>
            </div>
        </div>
        <div class="row g-3">
            <?php
            $tiles = [
                ['Hmotnost', $fmt($lr['body']['weight_kg'] ?? null, 1) . ' kg'],
                ['BMI', $fmt($lr['body']['bmi'] ?? null, 1)],
                ['Tělesný tuk', ($lr['body']['body_fat_pct'] ?? null) !== null ? $fmt($lr['body']['body_fat_pct'], 1) . ' %' : '–'],
                ['BMR', $fmt($lr['metabolism']['bmr'] ?? null) . ' kcal'],
                ['TDEE', $fmt($lr['metabolism']['tdee'] ?? null) . ' kcal'],
                ['Denní příjem', ($lr['calories']['kcal'] ?? null) !== null ? $fmt($lr['calories']['kcal']) . ' kcal' : '–'],
                ['B / S / T', !empty($lr['macros']) ? $fmt($lr['macros']['protein_g']) . ' / ' . $fmt($lr['macros']['carbs_g']) . ' / ' . $fmt($lr['macros']['fat_g']) . ' g' : '–'],
                ['Tekutiny', !empty($lr['hydration']) ? $fmt($lr['hydration']['avg_ml'] / 1000, 1) . ' l' : '–'],
            ];
            foreach ($tiles as [$label, $value]): ?>
            <div class="col-6 col-md-3"><div class="calc-stat"><div class="calc-stat__label"><?= h($label) ?></div><div class="calc-stat__value calc-silver-text"><?= h($value) ?></div></div></div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($latest['coach_note'])): ?>
        <div class="calc-note mt-3"><i class="fas fa-user-tie me-1"></i><strong>Poznámka trenéra:</strong> <?= nl2br(h((string)$latest['coach_note'])) ?></div>
        <?php endif; ?>
        <?php if ($isAthleteView): ?>
        <div class="calc-hint mt-3"><i class="fas fa-<?= $shareOn ? 'share-nodes' : 'lock' ?> me-1"></i><?= $shareOn ? 'Výsledky sdílíte se svým trenérem.' : 'Výsledky se svým trenérem nesdílíte.' ?> <a class="text-reset" href="<?= BASE_URL ?>/calculators.php#calc-settings">Změnit nastavení sdílení</a></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (count($series) >= 2): ?>
    <div class="calc-metal calc-panel mb-4" id="charts">
        <div class="calc-panel__title">Vývoj v čase</div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($metricDefs as $key => $def): ?>
                <?php $hasData = count(array_filter($series, static fn(array $p): bool => $p[$key] !== null)) >= 1; ?>
                <?php if ($hasData): ?>
                <a class="btn btn-sm <?= $key === $chartMetric ? 'calc-btn-gold' : 'calc-btn-ghost' ?>" href="<?= h($pageUrl(['metric' => $key])) ?>#charts"><?= h($def['label']) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php $chart = calculatorTrendSvg($series, ['key' => $chartMetric, 'label' => $metricDefs[$chartMetric]['label'], 'decimals' => $metricDefs[$chartMetric]['decimals'], 'height' => 240]); ?>
        <?= $chart !== '' ? $chart : '<div class="calc-hint">Pro tuto metriku zatím nejsou data.</div>' ?>
    </div>
    <?php endif; ?>

    <?php if ($compareA && $compareB): ?>
    <div class="calc-metal calc-panel mb-4" id="compare">
        <div class="calc-panel__title">Porovnání dvou období</div>
        <form method="get" class="row g-2 align-items-end mb-3">
            <?php if (!$isAthleteView): ?><input type="hidden" name="athlete_id" value="<?= $athleteId ?>"><?php endif; ?>
            <?php foreach (['a' => ['Výchozí analýza', $compareA], 'b' => ['Porovnat s', $compareB]] as $param => [$label, $selected]): ?>
            <div class="col-md-5">
                <label class="form-label"><?= h($label) ?></label>
                <select name="<?= $param ?>" class="form-select calc-input calc-input--sm">
                    <?php foreach ($finals as $f): ?>
                        <option value="<?= (int)$f['id'] ?>" <?= (int)$f['id'] === (int)$selected['id'] ? 'selected' : '' ?>><?= h(date('j. n. Y', strtotime($f['date']))) ?> · <?= $fmt($f['metrics']['weight_kg'], 1) ?> kg</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
            <div class="col-md-2"><button class="btn calc-btn-ghost w-100" type="submit">Porovnat</button></div>
        </form>
        <div class="table-responsive">
            <table class="an-table">
                <thead><tr><th>Metrika</th><th class="text-end"><?= h(date('j. n. Y', strtotime($compareA['date']))) ?></th><th class="text-end"><?= h(date('j. n. Y', strtotime($compareB['date']))) ?></th><th class="text-end">Změna</th></tr></thead>
                <tbody>
                <?php foreach ($metricDefs as $key => $def):
                    $va = $compareA['metrics'][$key] ?? null;
                    $vb = $compareB['metrics'][$key] ?? null;
                    if ($va === null && $vb === null) { continue; }
                    $delta = ($va !== null && $vb !== null) ? $vb - $va : null;
                ?>
                    <tr>
                        <td><?= h($def['label']) ?><?= $def['unit'] !== '' ? ' <span class="small">(' . h($def['unit']) . ')</span>' : '' ?></td>
                        <td class="num"><?= $fmt($va, $def['decimals']) ?></td>
                        <td class="num"><?= $fmt($vb, $def['decimals']) ?></td>
                        <td class="num <?= $delta === null || abs($delta) < 0.05 ? '' : ($delta > 0 ? 'an-delta-up' : 'an-delta-down') ?>"><?= $delta === null ? '–' : ($delta > 0 ? '+' : '') . $fmt($delta, $def['decimals']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="calc-hint mt-2">Barva vyjadřuje směr změny, ne její hodnocení – u hubnutí je pokles hmotnosti žádoucí, u nabírání naopak.</div>
    </div>
    <?php endif; ?>

    <?php if ($latest && !empty($latest['results']['macros'])):
        $t = analysisLatestTargets($pdo, $athleteId);
        $pctOf = static fn(float $v, float $target): string => $target > 0 ? (string)round($v / $target * 100) . ' %' : '–';
        $dayNamesCz = ['monday' => 'Pondělí', 'tuesday' => 'Úterý', 'wednesday' => 'Středa', 'thursday' => 'Čtvrtek', 'friday' => 'Pátek', 'saturday' => 'Sobota', 'sunday' => 'Neděle'];
    ?>
    <?php if ($t): ?>
    <div class="row g-4 mb-4" id="integrations">
        <div class="col-lg-7">
            <div class="calc-metal calc-panel">
                <div class="calc-panel__title"><i class="fas fa-utensils me-1"></i>Strava · posledních 7 dní vs. cíl</div>
                <?php $loggedDays = array_filter($diaryTotals, static fn(array $d): bool => $d['items'] > 0); ?>
                <?php if (!$loggedDays): ?>
                    <div class="calc-hint">Za posledních 7 dní nejsou v evidenci stravy žádná jídla. <?= $isAthleteView ? '<a class="text-reset" href="' . BASE_URL . '/athlete_food_diary.php">Zapsat jídlo</a>' : '' ?></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="an-table">
                        <thead><tr><th>Den</th><th class="text-end">kcal</th><th class="text-end">Bílkoviny</th><th class="text-end">Sacharidy</th><th class="text-end">Tuky</th><th class="text-end">Tekutiny</th></tr></thead>
                        <tbody>
                        <?php foreach ($loggedDays as $date => $d): ?>
                            <tr>
                                <td><?= h(date('j. n.', strtotime($date))) ?></td>
                                <td class="num"><?= $fmt($d['kcal']) ?> <span class="small fw-normal text-secondary"><?= $pctOf($d['kcal'], $t['kcal']) ?></span></td>
                                <td class="num"><?= $fmt($d['protein']) ?> g <span class="small fw-normal text-secondary"><?= $pctOf($d['protein'], $t['protein_g']) ?></span></td>
                                <td class="num"><?= $fmt($d['carbs']) ?> g <span class="small fw-normal text-secondary"><?= $pctOf($d['carbs'], $t['carbs_g']) ?></span></td>
                                <td class="num"><?= $fmt($d['fat']) ?> g <span class="small fw-normal text-secondary"><?= $pctOf($d['fat'], $t['fat_g']) ?></span></td>
                                <td class="num"><?= $d['hydration_ml'] > 0 ? $fmt($d['hydration_ml'] / 1000, 1) . ' l' : '–' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php
                        $n = count($loggedDays);
                        $avg = static fn(string $k): float => array_sum(array_column($loggedDays, $k)) / $n;
                        ?>
                            <tr class="is-hl">
                                <td><strong>Průměr</strong></td>
                                <td class="num"><?= $fmt($avg('kcal')) ?> <span class="small fw-normal"><?= $pctOf($avg('kcal'), $t['kcal']) ?></span></td>
                                <td class="num"><?= $fmt($avg('protein')) ?> g <span class="small fw-normal"><?= $pctOf($avg('protein'), $t['protein_g']) ?></span></td>
                                <td class="num"><?= $fmt($avg('carbs')) ?> g <span class="small fw-normal"><?= $pctOf($avg('carbs'), $t['carbs_g']) ?></span></td>
                                <td class="num"><?= $fmt($avg('fat')) ?> g <span class="small fw-normal"><?= $pctOf($avg('fat'), $t['fat_g']) ?></span></td>
                                <td class="num">cíl <?= $fmt($t['hydration_ml'] / 1000, 1) ?> l</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="calc-hint mt-2">Cíl: <?= $fmt($t['kcal']) ?> kcal · B <?= $t['protein_g'] ?> g · S <?= $t['carbs_g'] ?> g · T <?= $t['fat_g'] ?> g. Procenta ukazují plnění denního cíle.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="calc-metal calc-panel">
                <div class="calc-panel__title"><i class="fas fa-clipboard-list me-1"></i>Jídelníčky vs. cíl</div>
                <?php if (!$mealPlanTotals): ?>
                    <div class="calc-hint">Sportovec nemá přiřazený žádný jídelníček.</div>
                <?php else: ?>
                    <?php foreach ($mealPlanTotals as $plan): ?>
                    <div class="fw-bold text-white mb-1"><?= h($plan['plan_name']) ?></div>
                    <table class="an-table mb-3">
                        <thead><tr><th>Den</th><th class="text-end">Bílkoviny</th><th class="text-end">Tuky</th><th class="text-end">Vláknina</th></tr></thead>
                        <tbody>
                        <?php foreach ($dayNamesCz as $dayKey => $dayLabel): if (!isset($plan['days'][$dayKey])) { continue; } $d = $plan['days'][$dayKey]; ?>
                            <tr>
                                <td><?= h($dayLabel) ?></td>
                                <td class="num"><?= $fmt($d['protein']) ?> g <span class="small fw-normal text-secondary"><?= $pctOf($d['protein'], $t['protein_g']) ?></span></td>
                                <td class="num"><?= $fmt($d['fat']) ?> g <span class="small fw-normal text-secondary"><?= $pctOf($d['fat'], $t['fat_g']) ?></span></td>
                                <td class="num"><?= $fmt($d['fiber']) ?> g <span class="small fw-normal text-secondary"><?= $pctOf($d['fiber'], $t['fiber_g']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endforeach; ?>
                    <div class="calc-hint">Jídla v jídelníčcích mají zadané jen bílkoviny, tuky, cukry a vlákninu – kalorie a sacharidy proto nelze porovnat.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($finals): ?>
    <div class="calc-metal calc-panel mb-4" id="history">
        <div class="calc-panel__title">Historie analýz</div>
        <div class="table-responsive">
            <table class="calc-history">
                <thead><tr><th>Datum</th><th>Cíl</th><th>Hmotnost</th><th>BMI</th><th>Tuk</th><th>TDEE</th><th>Příjem</th><th>Vytvořil</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($finals as $f): $m = $f['metrics']; ?>
                    <tr>
                        <td><span class="calc-bmi-num"><?= h(date('j. n. Y', strtotime($f['date']))) ?></span></td>
                        <td><?= h($goalShort[$f['inputs']['goal'] ?? ''] ?? '–') ?></td>
                        <td><?= $fmt($m['weight_kg'], 1) ?> kg</td>
                        <td><?= $fmt($m['bmi'], 1) ?></td>
                        <td><?= $m['body_fat_pct'] !== null ? $fmt($m['body_fat_pct'], 1) . ' %' : '–' ?></td>
                        <td><?= $fmt($m['tdee']) ?></td>
                        <td><?= $m['kcal'] !== null ? $fmt($m['kcal']) . ' kcal' : '–' ?></td>
                        <td><?= ($f['created_by_type'] ?? '') === 'coach' ? 'Trenér' : 'Sportovec' ?><?= !empty($f['coach_note']) ? ' <i class="fas fa-comment-dots ms-1" title="Obsahuje poznámku trenéra"></i>' : '' ?></td>
                        <td>
                            <a class="calc-icon-btn" title="Zobrazit" href="<?= h($pageUrl(['view' => 'result', 'id' => $f['id']])) ?>"><i class="fas fa-eye"></i></a>
                            <a class="calc-icon-btn" title="Tisk" href="<?= h($printUrl($f['id'])) ?>" target="_blank" rel="noopener"><i class="fas fa-print"></i></a>
                            <?php if ($isAthleteView): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Opravdu smazat tuto analýzu z historie?');">
                                <?= csrfField() ?><input type="hidden" name="action" value="delete_analysis"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button class="calc-icon-btn calc-icon-btn--danger" type="submit" title="Smazat"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($view === 'wizard'):
    $in = $wizardInputs ?? [];
    $sources = $prefill['sources'];
    $val = static function (string $k) use ($in): string {
        $v = $in[$k] ?? null;
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        }
        return is_scalar($v) ? (string)$v : '';
    };
    $err = static fn(string $k): string => isset($wizardErrors[$k]) ? '<div class="an-error"><i class="fas fa-triangle-exclamation me-1"></i>' . h($wizardErrors[$k]) . '</div>' : '';
    $src = static fn(string $k): string => isset($sources[$k]) ? '<div class="calc-hint"><i class="fas fa-link me-1"></i>' . h($sources[$k]) . '</div>' : '';
    $number = static function (string $name, string $label, string $unit, string $step = '0.1', string $hint = '', bool $required = false) use ($val, $err, $src): string {
        return '<label class="form-label" for="f_' . $name . '">' . h($label) . ($required ? ' <span class="text-warning">*</span>' : '') . '</label>'
            . '<div class="input-group calc-input-group"><input type="text" inputmode="decimal" class="form-control calc-input calc-input--sm" id="f_' . $name . '" name="' . $name . '" value="' . h($val($name)) . '" data-step="' . $step . '">'
            . ($unit !== '' ? '<span class="input-group-text calc-input-unit">' . h($unit) . '</span>' : '') . '</div>'
            . ($hint !== '' ? '<div class="calc-hint">' . h($hint) . '</div>' : '') . $src($name) . $err($name);
    };
    $radios = static function (string $name, array $options, string $class = '') use ($val, $err): string {
        $html = '<div class="an-choice ' . $class . '">';
        foreach ($options as $key => $label) {
            $html .= '<label><input type="radio" name="' . $name . '" value="' . h((string)$key) . '"' . ($val($name) === (string)$key ? ' checked' : '') . '>' . h($label) . '</label>';
        }
        return $html . '</div>' . $err($name);
    };
    $checks = static function (string $name, array $options, string $class = '') use ($in): string {
        $selected = is_array($in[$name] ?? null) ? $in[$name] : [];
        $html = '<div class="an-choice ' . $class . '">';
        foreach ($options as $key => $label) {
            $html .= '<label><input type="checkbox" name="' . $name . '[]" value="' . h((string)$key) . '"' . (in_array((string)$key, $selected, true) ? ' checked' : '') . '>' . h($label) . '</label>';
        }
        return $html . '</div>';
    };
    $yesNo = static function (string $name, string $label) use ($radios): string {
        return '<div class="col-md-6"><label class="form-label">' . h($label) . '</label>' . $radios($name, ['1' => 'Ano', '0' => 'Ne'], 'an-choice--sm') . '</div>';
    };
    $time = static fn(string $name, string $label): string => '<label class="form-label" for="f_' . $name . '">' . h($label) . '</label><input type="time" class="form-control calc-input calc-input--sm" id="f_' . $name . '" name="' . $name . '" value="' . h($val($name)) . '">' . $err($name);
    $textarea = static fn(string $name, string $label, string $placeholder = ''): string => '<label class="form-label" for="f_' . $name . '">' . h($label) . '</label><textarea class="form-control calc-input calc-input--sm" rows="2" id="f_' . $name . '" name="' . $name . '" placeholder="' . h($placeholder) . '">' . h($val($name)) . '</textarea>';
?>
    <form method="post" class="calc-metal calc-panel mb-4" id="analysisWizard" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="wizard">
        <input type="hidden" name="step" value="<?= $wizardStep ?>">
        <input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
        <button type="submit" name="nav" value="<?= $wizardStep < 5 ? 'next' : 'finish' ?>" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"></button>

        <div class="an-stepper">
            <?php foreach ($stepTitles as $n => $title): ?>
                <button type="submit" name="nav" value="goto<?= $n ?>" class="an-step btn text-start <?= $n === $wizardStep ? 'is-active' : ($n < $wizardStep ? 'is-done' : '') ?> <?= isset($wizardStepsWithErrors[$n]) ? 'has-error' : '' ?>">
                    <span class="an-step__num"><?= $n < $wizardStep ? '<i class="fas fa-check"></i>' : $n ?></span><span><?= h($title) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="an-progress"><span style="width:<?= $wizardStep * 20 ?>%"></span></div>

        <?php if ($wizardErrors): ?>
            <div class="calc-note calc-note--danger mb-3"><i class="fas fa-triangle-exclamation me-1"></i>Zkontrolujte prosím označená pole<?= count($wizardStepsWithErrors) > 1 ? ' (chyby jsou v krocích ' . h(implode(', ', array_keys($wizardStepsWithErrors))) . ')' : '' ?>.</div>
        <?php endif; ?>
        <?php if (isset($sources['last']) && $wizardStep === 1): ?>
            <div class="calc-note calc-note--info mb-3"><i class="fas fa-clock-rotate-left me-1"></i><?= h($sources['last']) ?> – zkontrolujte, zda údaje stále platí.</div>
        <?php endif; ?>

        <h2 class="an-section-title calc-gold-text">Krok <?= $wizardStep ?>: <?= h($stepTitles[$wizardStep]) ?></h2>

        <?php if ($wizardStep === 1): ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Pohlaví <span class="text-warning">*</span></label>
                <?= $radios('sex', ['male' => 'Muž', 'female' => 'Žena'], 'an-choice--sm') ?>
                <?= $src('sex') ?>
            </div>
            <div class="col-md-4"><?= $number('age', 'Věk', 'let', '1', '', true) ?></div>
            <div class="col-md-4"><?= $number('height_cm', 'Výška', 'cm', '0.5', '', true) ?></div>
            <div class="col-md-4"><?= $number('weight_kg', 'Aktuální hmotnost', 'kg', '0.1', 'Ideálně ráno nalačno po toaletě.', true) ?></div>
            <div class="col-md-4">
                <label class="form-label" for="f_weigh_date">Datum vážení</label>
                <input type="date" class="form-control calc-input calc-input--sm" id="f_weigh_date" name="weigh_date" value="<?= h($val('weigh_date') ?: $today) ?>" max="<?= h($today) ?>">
                <?= $err('weigh_date') ?>
            </div>
            <div class="col-md-4"><?= $number('target_weight_kg', 'Cílová hmotnost (nepovinné)', 'kg') ?></div>
            <?php if ($isAthleteView): ?>
            <div class="col-12">
                <input type="hidden" name="save_weight" value="0">
                <label class="calc-switch">
                    <input type="checkbox" name="save_weight" value="1" <?= $val('save_weight') === '1' ? 'checked' : '' ?>>
                    <span class="calc-switch__track"></span>
                    <span><strong class="text-white">Uložit hmotnost do profilu jako vážení</strong><span class="calc-hint d-block mt-0">Po dokončení analýzy se hmotnost zapíše k datu vážení (pokud k tomuto dni ještě vážení nemáte).</span></span>
                </label>
            </div>
            <?php endif; ?>
        </div>

        <h3 class="an-section-title mt-4 calc-silver-text">Obvody těla (nepovinné)</h3>
        <div class="calc-note calc-note--info mb-3"><i class="fas fa-ruler me-1"></i>Pro odhad procenta tuku zadejte obvod <strong>krku a pasu</strong> (ženy navíc <strong>boků</strong>). Měřte krejčovským metrem na kůži, bez zatahování břicha: krk pod ohryzkem, pas v úrovni pupku, boky v nejširším místě hýždí.</div>
        <div class="row g-3">
            <?php foreach (analysisCircumferenceFields() as $key => $meta): ?>
                <div class="col-6 col-md-4"><?= $number($key, $meta['label'], 'cm', '0.5') ?></div>
            <?php endforeach; ?>
        </div>

        <h3 class="an-section-title mt-4 calc-silver-text">Zvláštní situace</h3>
        <?= $checks('special', analysisSpecialConditionOptions()) ?>
        <div class="calc-hint mt-2"><i class="fas fa-shield-heart me-1"></i>Při těchto situacích nebudeme počítat kalorický cíl ani makroživiny – doporučíme konzultaci s lékařem nebo nutričním terapeutem.</div>

        <?php elseif ($wizardStep === 2): ?>
        <label class="form-label">Hlavní cíl <span class="text-warning">*</span></label>
        <?= $radios('goal', analysisGoalOptions()) ?>
        <div class="row g-3 mt-2">
            <div class="col-md-6">
                <label class="form-label" for="f_secondary_goal">Vedlejší cíl</label>
                <select class="form-select calc-input calc-input--sm" id="f_secondary_goal" name="secondary_goal">
                    <?php foreach (analysisSecondaryGoalOptions() as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $val('secondary_goal') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="f_target_date">Do kdy chcete cíle dosáhnout? (nepovinné)</label>
                <input type="date" class="form-control calc-input calc-input--sm" id="f_target_date" name="target_date" value="<?= h($val('target_date')) ?>" min="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>">
                <div class="calc-hint">Pokud je termín příliš ambiciózní, upozorníme vás a navrhneme bezpečné tempo.</div>
                <?= $err('target_date') ?>
            </div>
        </div>

        <?php elseif ($wizardStep === 3): ?>
        <label class="form-label">Typ zaměstnání <span class="text-warning">*</span></label>
        <?= $radios('job_activity', analysisJobActivityOptions()) ?>
        <div class="row g-3 mt-1">
            <div class="col-md-6">
                <label class="form-label">Počet tréninků týdně <span class="text-warning">*</span></label>
                <?= $radios('sessions', analysisSessionsOptions(), 'an-choice--sm') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Průměrná délka tréninku</label>
                <?= $radios('duration', analysisDurationOptions(), 'an-choice--sm') ?>
            </div>
        </div>
        <label class="form-label mt-3">Typ aktivit</label>
        <?= $checks('activity_types', analysisActivityTypeOptions(), 'an-choice--sm') ?>
        <div class="row g-3 mt-1">
            <div class="col-md-8">
                <label class="form-label">Průměrný počet kroků denně</label>
                <?= $radios('steps_bucket', analysisStepsOptions(), 'an-choice--sm') ?>
            </div>
            <div class="col-md-4"><?= $number('steps_exact', 'Přesný počet kroků (nepovinné)', 'kroků', '100', 'Má přednost před výběrem rozsahu.') ?></div>
        </div>
        <label class="form-label mt-3">Tréninková zkušenost</label>
        <?= $radios('experience', analysisExperienceOptions()) ?>
        <div class="calc-hint mt-2"><i class="fas fa-circle-info me-1"></i>Aktivitu vyhodnotíme jako jeden souhrnný faktor (zaměstnání + tréninky + kroky), aby nedocházelo k dvojímu započítání.</div>

        <?php elseif ($wizardStep === 4): ?>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label" for="f_meals_now">Kolikrát denně nyní jíte?</label>
                <select class="form-select calc-input calc-input--sm" id="f_meals_now" name="meals_now">
                    <option value="">–</option>
                    <?php for ($i = 1; $i <= 7; $i++): ?><option value="<?= $i ?>" <?= $val('meals_now') === (string)$i ? 'selected' : '' ?>><?= $i ?>×</option><?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3"><?= $time('breakfast_time', 'Snídaně obvykle v') ?></div>
            <div class="col-md-3"><?= $time('lunch_time', 'Oběd obvykle v') ?></div>
            <div class="col-md-3"><?= $time('dinner_time', 'Večeře obvykle v') ?></div>
        </div>
        <label class="form-label mt-3">Kdy obvykle trénujete?</label>
        <?= $radios('training_slot', analysisTrainingSlotOptions(), 'an-choice--sm') ?>
        <div class="row g-3 mt-1">
            <div class="col-md-3"><?= $time('training_time', 'Přesný čas tréninku (nepovinné)') ?></div>
        </div>
        <div class="row g-3 mt-1">
            <?= $yesNo('regular_breakfast', 'Snídáte pravidelně?') ?>
            <?= $yesNo('eats_late', 'Jíte pozdě večer?') ?>
            <?= $yesNo('snacking', 'Máte sklony k mlsání?') ?>
            <?= $yesNo('enough_fluids', 'Pijete dostatek tekutin?') ?>
            <?= $yesNo('alcohol', 'Pijete pravidelně alkohol?') ?>
        </div>
        <label class="form-label mt-3">Stravovací omezení</label>
        <?= $radios('diet', analysisDietOptions(), 'an-choice--sm') ?>
        <div class="mt-3"><?= $textarea('allergies', 'Alergie a intolerance', 'např. ořechy, laktóza, lepek') ?></div>

        <?php else: ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Kolik času máte na přípravu jídla?</label>
                <?= $radios('prep_time', analysisPrepTimeOptions(), 'an-choice--sm') ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Vaření</label>
                <?= $radios('cooking', analysisCookingOptions(), 'an-choice--sm') ?>
            </div>
            <?= $yesNo('eat_at_work', 'Máte možnost jíst v práci?') ?>
            <?= $yesNo('meal_prep', 'Připravujete si jídlo dopředu?') ?>
        </div>
        <label class="form-label mt-3">Kolik jídel denně chcete mít v doporučení?</label>
        <?= $radios('meals_desired', analysisMealsCountOptions(), 'an-choice--sm') ?>
        <div class="row g-3 mt-1">
            <div class="col-md-6">
                <label class="form-label" for="f_custom_times">Časy jídel pro individuální režim</label>
                <input type="text" class="form-control calc-input calc-input--sm" id="f_custom_times" name="custom_times" value="<?= h(implode(', ', is_array($in['custom_times'] ?? null) ? $in['custom_times'] : [])) ?>" placeholder="např. 7:00, 12:00, 15:30, 19:00">
                <?= $err('custom_times') ?>
            </div>
            <div class="col-6 col-md-3"><?= $time('eating_window_from', 'Stravovací okno od') ?></div>
            <div class="col-6 col-md-3"><?= $time('eating_window_to', 'Stravovací okno do') ?></div>
            <div class="col-md-6"><?= $textarea('preferred_foods', 'Oblíbené potraviny') ?></div>
            <div class="col-md-6"><?= $textarea('disliked_foods', 'Potraviny, které nejíte', 'oddělte čárkou') ?></div>
        </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between gap-2 mt-4 pt-3" style="border-top:1px solid rgba(201,206,214,.14)">
            <div class="d-flex gap-2">
                <?php if ($wizardStep > 1): ?><button class="btn calc-btn-ghost" type="submit" name="nav" value="back"><i class="fas fa-arrow-left me-1"></i>Zpět</button><?php endif; ?>
                <button class="btn calc-btn-ghost" type="submit" name="nav" value="save"><i class="fas fa-floppy-disk me-1"></i>Uložit a dokončit později</button>
            </div>
            <?php if ($wizardStep < 5): ?>
                <button class="btn calc-btn-gold" type="submit" name="nav" value="next">Pokračovat<i class="fas fa-arrow-right ms-1"></i></button>
            <?php else: ?>
                <button class="btn calc-btn-gold" type="submit" name="nav" value="finish"><i class="fas fa-wand-magic-sparkles me-1"></i>Dokončit a vyhodnotit</button>
            <?php endif; ?>
        </div>
        <div class="calc-hint mt-2"><span class="text-warning">*</span> povinné údaje. Výsledky jsou orientační a nenahrazují konzultaci s lékařem nebo nutričním terapeutem.</div>
    </form>
<?php endif; ?>

<?php if ($view === 'result' && $current):
    $r = $current['results'];
    $ci = $current['inputs'];
    $b = $r['body'] ?? [];
    $met = $r['metabolism'] ?? [];
    $cal = $r['calories'] ?? null;
    $mac = $r['macros'] ?? null;
    $hyd = $r['hydration'] ?? null;
    $toneColor = ['info' => '#4ea8de', 'success' => '#2ecc71', 'warning' => '#f1c40f', 'orange' => '#e67e22', 'danger' => '#e74c3c', 'dark' => '#8e1b2c'];
    $toneDot = static fn(?string $tone): string => $tone !== null && isset($toneColor[$tone]) ? '<span class="an-dot" style="background:' . $toneColor[$tone] . '"></span>' : '';
    $coachApplied = $r['coach_applied'] ?? [];
?>
    <div class="calc-metal calc-panel mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2<?= !empty($r['warnings']) ? ' mb-3' : '' ?>">
        <div>
            <div class="calc-panel__title mb-1">Analýza ze dne <?= h(date('j. n. Y', strtotime($current['date']))) ?></div>
            <div class="fw-bold text-white"><?= h($goalShort[$ci['goal'] ?? ''] ?? '') ?><?= ($current['created_by_type'] ?? '') === 'coach' ? ' <span class="calc-chip calc-chip--silver ms-1"><i class="fas fa-user-tie"></i>Vytvořil trenér</span>' : '' ?><?= $latest && (int)$latest['id'] !== (int)$current['id'] ? ' <span class="calc-chip calc-chip--silver ms-1"><i class="fas fa-clock-rotate-left"></i>Starší analýza</span>' : '' ?></div>
        </div>
        <div class="calc-actions">
            <a class="btn calc-btn-gold" href="<?= h($printUrl($current['id'])) ?>" target="_blank" rel="noopener"><i class="fas fa-print me-1"></i>Tisk / PDF</a>
            <a class="btn calc-btn-ghost" href="<?= h($pageUrl(['view' => 'wizard'])) ?>"><i class="fas fa-plus me-1"></i>Nová analýza</a>
        </div>
    </div>

    <?php foreach ($r['warnings'] ?? [] as $msg): ?>
        <div class="calc-note mb-2"><i class="fas fa-triangle-exclamation me-1"></i><?= h($msg) ?></div>
    <?php endforeach; ?>
    </div>
    <?php if (!empty($r['special'])): ?>
        <div class="calc-note calc-note--danger mb-2"><i class="fas fa-shield-heart me-1"></i>Uvedli jste zvláštní situaci (<?= h(implode(', ', array_map(static fn($k) => analysisSpecialConditionOptions()[$k] ?? $k, $r['special']))) ?>). Kalorický cíl a makroživiny proto nepočítáme – doporučujeme konzultaci s lékařem nebo nutričním terapeutem.</div>
    <?php endif; ?>
    <?php if (!empty($current['coach_note'])): ?>
        <div class="calc-note mb-2"><i class="fas fa-user-tie me-1"></i><strong>Poznámka trenéra</strong><?= !empty($current['coach_note_updated_at']) ? ' (' . h(date('j. n. Y', strtotime((string)$current['coach_note_updated_at']))) . ')' : '' ?>: <?= nl2br(h((string)$current['coach_note'])) ?></div>
    <?php endif; ?>

    <div class="row g-4 mt-1">
        <div class="col-lg-6" id="card-body">
            <div class="calc-metal calc-panel">
                <div class="d-flex align-items-center gap-2 mb-3"><span class="an-card-letter">A</span><span class="calc-panel__title mb-0">Tělesná kompozice</span></div>
                <div class="row g-3">
                    <div class="col-6"><div class="calc-stat"><div class="calc-stat__label">BMI</div><div class="calc-stat__value"><?= $fmt($b['bmi'] ?? null, 1) ?></div><div class="small"><?= $toneDot($b['bmi_tone'] ?? null) ?><?= h((string)($b['bmi_label'] ?? '')) ?></div></div></div>
                    <div class="col-6"><div class="calc-stat"><div class="calc-stat__label">Tělesný tuk</div><div class="calc-stat__value"><?= ($b['body_fat_pct'] ?? null) !== null ? $fmt($b['body_fat_pct'], 1) . ' %' : '–' ?></div><div class="small"><?= !empty($b['body_fat_category']) ? $toneDot($b['body_fat_category']['tone'] ?? null) . h($b['body_fat_category']['label']) : 'odhad metodou US Navy' ?></div></div></div>
                    <div class="col-6"><div class="calc-stat"><div class="calc-stat__label">Tuková hmota</div><div class="calc-stat__value"><?= ($b['fat_mass_kg'] ?? null) !== null ? $fmt($b['fat_mass_kg'], 1) . ' kg' : '–' ?></div></div></div>
                    <div class="col-6"><div class="calc-stat"><div class="calc-stat__label">Beztuková hmota</div><div class="calc-stat__value"><?= ($b['lean_mass_kg'] ?? null) !== null ? $fmt($b['lean_mass_kg'], 1) . ' kg' : '–' ?></div></div></div>
                    <div class="col-12"><div class="calc-stat"><div class="calc-stat__label">WHR (pas / boky)</div><div class="calc-stat__value"><?= $fmt($b['whr'] ?? null, 2) ?></div><div class="small"><?= !empty($b['whr_interpretation']) ? $toneDot($b['whr_interpretation']['tone'] ?? null) . h($b['whr_interpretation']['label']) : 'Zadejte obvod pasu a boků.' ?></div></div></div>
                </div>
                <?php foreach ($r['missing'] ?? [] as $msg): ?>
                    <div class="calc-hint mt-2"><i class="fas fa-circle-info me-1"></i><?= h($msg) ?></div>
                <?php endforeach; ?>
                <div class="calc-hint mt-2"><i class="fas fa-dumbbell me-1"></i>BMI nerozlišuje svalovou a tukovou hmotu – u svalnatých sportovců může tuk nadhodnocovat.</div>
            </div>
        </div>

        <div class="col-lg-6" id="card-metabolism">
            <div class="calc-metal calc-panel">
                <div class="d-flex align-items-center gap-2 mb-3"><span class="an-card-letter">B</span><span class="calc-panel__title mb-0">Metabolismus</span></div>
                <div class="row g-3">
                    <div class="col-6"><div class="calc-stat"><div class="calc-stat__label">BMR</div><div class="calc-stat__value"><?= $fmt($met['bmr'] ?? null) ?> <small class="fs-6">kcal</small></div><div class="small">bazální metabolismus</div></div></div>
                    <div class="col-6"><div class="calc-stat"><div class="calc-stat__label">TDEE</div><div class="calc-stat__value"><?= $fmt($met['tdee'] ?? null) ?> <small class="fs-6">kcal</small></div><div class="small">celkový denní výdej</div></div></div>
                    <div class="col-12"><div class="calc-stat">
                        <div class="calc-stat__label">Doporučený denní příjem</div>
                        <?php if ($cal && !$cal['blocked']): ?>
                            <div class="calc-stat__value calc-gold-text" style="font-size:2.2rem"><?= $fmt($cal['kcal']) ?> kcal</div>
                            <div class="small"><?= h($cal['mode_label']) ?>: <strong class="text-white"><?= $cal['adjust_kcal'] > 0 ? '+' : '' ?><?= $fmt($cal['adjust_kcal']) ?> kcal</strong> (<?= $cal['adjust_pct'] > 0 ? '+' : '' ?><?= $fmt($cal['adjust_pct'], 1) ?> % TDEE)<?= in_array('kcal', $coachApplied, true) ? ' · <i class="fas fa-user-tie"></i> upraveno trenérem' : '' ?></div>
                        <?php else: ?>
                            <div class="calc-stat__value">–</div><div class="small">Kalorický cíl se pro vaši situaci nepočítá.</div>
                        <?php endif; ?>
                    </div></div>
                </div>
                <?php if (!empty($met['activity'])): ?>
                    <div class="calc-hint mt-2"><i class="fas fa-person-running me-1"></i>Úroveň aktivity: <strong class="text-white"><?= h((string)$met['activity']['label']) ?></strong> (faktor <?= $fmt($met['activity']['factor'], 2) ?>). <?= h((string)($met['activity']['explanation'] ?? '')) ?></div>
                <?php endif; ?>
                <?php foreach ($cal['notes'] ?? [] as $note): ?>
                    <div class="calc-hint mt-1"><i class="fas fa-circle-info me-1"></i><?= h($note) ?></div>
                <?php endforeach; ?>
                <div class="calc-hint mt-1"><i class="fas fa-circle-info me-1"></i>BMR není doporučený příjem – je to energie potřebná v naprostém klidu.</div>
            </div>
        </div>

        <div class="col-lg-6" id="card-macros">
            <div class="calc-metal calc-panel">
                <div class="d-flex align-items-center gap-2 mb-3"><span class="an-card-letter">C</span><span class="calc-panel__title mb-0">Makroživiny a tekutiny</span></div>
                <?php if ($mac): ?>
                <div class="an-macro-bar"><span style="width:<?= (float)$mac['protein_pct'] ?>%"></span><span style="width:<?= (float)$mac['carbs_pct'] ?>%"></span><span style="width:<?= (float)$mac['fat_pct'] ?>%"></span></div>
                <table class="an-table mt-2">
                    <thead><tr><th>Živina</th><th class="text-end">g / den</th><th class="text-end">kcal</th><th class="text-end">%</th><th class="text-end">g/kg</th></tr></thead>
                    <tbody>
                        <tr><td><span class="an-dot" style="background:#d4a73c"></span>Bílkoviny<?= in_array('protein_g', $coachApplied, true) ? ' <i class="fas fa-user-tie" title="Upraveno trenérem"></i>' : '' ?><div class="small"><?= h($mac['protein_range_label']) ?></div></td><td class="num"><?= $fmt($mac['protein_g']) ?></td><td class="num"><?= $fmt($mac['protein_kcal']) ?></td><td class="num"><?= $fmt($mac['protein_pct']) ?></td><td class="num"><?= $fmt($mac['protein_per_kg'], 1) ?></td></tr>
                        <tr><td><span class="an-dot" style="background:#aab2bd"></span>Sacharidy<div class="small"><?= h($mac['carbs_range_label']) ?></div></td><td class="num"><?= $fmt($mac['carbs_g']) ?></td><td class="num"><?= $fmt($mac['carbs_kcal']) ?></td><td class="num"><?= $fmt($mac['carbs_pct']) ?></td><td class="num"><?= $fmt($mac['carbs_per_kg'], 1) ?></td></tr>
                        <tr><td><span class="an-dot" style="background:#a8653a"></span>Tuky<?= in_array('fat_g', $coachApplied, true) ? ' <i class="fas fa-user-tie" title="Upraveno trenérem"></i>' : '' ?><div class="small"><?= h($mac['fat_range_label']) ?></div></td><td class="num"><?= $fmt($mac['fat_g']) ?></td><td class="num"><?= $fmt($mac['fat_kcal']) ?></td><td class="num"><?= $fmt($mac['fat_pct']) ?></td><td class="num"><?= $fmt($mac['fat_per_kg'], 1) ?></td></tr>
                        <tr><td>Vláknina<div class="small"><?= h($mac['fiber_range_label']) ?></div></td><td class="num"><?= $fmt($mac['fiber_g']) ?></td><td class="num">–</td><td class="num">–</td><td class="num">–</td></tr>
                    </tbody>
                </table>
                <div class="calc-hint mt-1">Součet z makroživin: <?= $fmt($mac['kcal_from_macros']) ?> kcal<?= $mac['kcal_check_ok'] ? ' <i class="fas fa-check text-success"></i>' : ' (odchylka ' . $fmt($mac['kcal_check_diff']) . ' kcal kvůli zaokrouhlení / minimům)' ?></div>
                <?php foreach ($mac['notes'] ?? [] as $note): ?>
                    <div class="calc-hint mt-1"><i class="fas fa-circle-info me-1"></i><?= h($note) ?></div>
                <?php endforeach; ?>
                <?php else: ?>
                    <div class="calc-hint">Makroživiny se pro vaši situaci nepočítají.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6" id="card-hydration">
            <div class="calc-metal calc-panel">
                <div class="d-flex align-items-center gap-2 mb-3"><span class="an-card-letter">C</span><span class="calc-panel__title mb-0">Pitný režim</span></div>
                <?php if ($hyd): ?>
                <div class="row g-3">
                    <div class="col-4"><div class="calc-stat"><div class="calc-stat__label">Tréninkový den</div><div class="calc-stat__value"><?= $fmt($hyd['training_day_ml'] / 1000, 1) ?> l</div></div></div>
                    <div class="col-4"><div class="calc-stat"><div class="calc-stat__label">Volný den</div><div class="calc-stat__value"><?= $fmt($hyd['rest_day_ml'] / 1000, 1) ?> l</div></div></div>
                    <div class="col-4"><div class="calc-stat"><div class="calc-stat__label">Průměr</div><div class="calc-stat__value"><?= $fmt($hyd['avg_ml'] / 1000, 1) ?> l</div></div></div>
                </div>
                <div class="calc-hint mt-2">Základ <?= $fmt($hyd['base_ml']) ?> ml (35 ml/kg) + <?= $fmt($hyd['per_training_hour_ml']) ?> ml na každou hodinu tréninku.</div>
                <?php foreach ($hyd['notes'] ?? [] as $note): ?>
                    <div class="calc-hint mt-1"><i class="fas fa-circle-info me-1"></i><?= h($note) ?></div>
                <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($ci['sweat_test'])): $st = $ci['sweat_test']; ?>
                    <div class="calc-note calc-note--info mt-3"><i class="fas fa-droplet me-1"></i>Test pocení <?= h(date('j. n. Y', strtotime((string)$st['date']))) ?>: <strong><?= $fmt($st['rate_l_per_h'], 2) ?> l/h</strong>, úbytek <?= $fmt($st['loss_pct'], 1) ?> % hmotnosti. Po tréninku doplňte cca <?= $fmt($st['rehydrate_ml']) ?> ml.</div>
                <?php endif; ?>
                <details class="mt-3">
                    <summary class="fw-bold text-white" style="cursor:pointer"><i class="fas fa-droplet me-1"></i>Spočítat rychlost pocení (volitelný test)</summary>
                    <div class="calc-hint my-2">Zvažte se před a po tréninku (bez mokrého oblečení), zapište vypité tekutiny a případné močení. Výsledek zpřesní doporučení tekutin.</div>
                    <form method="post" class="row g-2">
                        <?= csrfField() ?><input type="hidden" name="action" value="sweat_rate"><input type="hidden" name="id" value="<?= (int)$current['id'] ?>"><input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
                        <?php foreach (['sweat_pre' => ['Hmotnost před', 'kg'], 'sweat_post' => ['Hmotnost po', 'kg'], 'sweat_drank_l' => ['Vypito během', 'l'], 'sweat_urine_l' => ['Moč během', 'l'], 'sweat_minutes' => ['Délka tréninku', 'min']] as $name => [$label, $unit]): ?>
                        <div class="col-6 col-md-4">
                            <label class="form-label small mb-1"><?= h($label) ?></label>
                            <div class="input-group calc-input-group"><input type="text" inputmode="decimal" name="<?= $name ?>" class="form-control calc-input calc-input--sm" value="<?= in_array($name, ['sweat_drank_l', 'sweat_urine_l'], true) ? '0' : '' ?>"><span class="input-group-text calc-input-unit"><?= h($unit) ?></span></div>
                        </div>
                        <?php endforeach; ?>
                        <div class="col-6 col-md-4 d-flex align-items-end"><button class="btn calc-btn-ghost w-100" type="submit">Spočítat</button></div>
                    </form>
                </details>
            </div>
        </div>

        <div class="col-12" id="card-meals">
            <div class="calc-metal calc-panel">
                <div class="d-flex align-items-center gap-2 mb-3"><span class="an-card-letter">D</span><span class="calc-panel__title mb-0">Stravovací režim</span></div>
                <?php $mp = $r['meal_plan'] ?? null; ?>
                <?php if ($mp && !empty($mp['meals'])): ?>
                <div class="table-responsive">
                    <table class="an-table">
                        <thead><tr><th>Čas</th><th>Jídlo</th><th class="text-end">kcal</th><th class="text-end">Podíl</th><th class="text-end">B</th><th class="text-end">S</th><th class="text-end">T</th><th>Tip</th></tr></thead>
                        <tbody>
                        <?php foreach ($mp['meals'] as $meal): ?>
                            <tr class="<?= $meal['role'] ? 'is-hl' : '' ?>">
                                <td class="num"><?= h((string)$meal['time']) ?></td>
                                <td><strong class="text-white"><?= h((string)$meal['name']) ?></strong><?= $meal['role'] === 'pre' ? ' <span class="calc-chip ms-1">před tréninkem</span>' : ($meal['role'] === 'post' ? ' <span class="calc-chip ms-1">po tréninku</span>' : '') ?></td>
                                <td class="num"><?= $fmt($meal['kcal']) ?></td>
                                <td class="num"><?= $fmt($meal['share_pct']) ?> %</td>
                                <td class="num"><?= $fmt($meal['protein_g']) ?> g</td>
                                <td class="num"><?= $fmt($meal['carbs_g']) ?> g</td>
                                <td class="num"><?= $fmt($meal['fat_g']) ?> g</td>
                                <td class="small"><?= h((string)$meal['hint']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($mp['training_time'])): ?><div class="calc-hint mt-1"><i class="fas fa-dumbbell me-1"></i>Trénink počítán v <?= h((string)$mp['training_time']) ?>.</div><?php endif; ?>
                <?php foreach ($mp['notes'] ?? [] as $note): ?><div class="calc-hint mt-1"><i class="fas fa-circle-info me-1"></i><?= h($note) ?></div><?php endforeach; ?>
                <?php else: ?>
                    <div class="calc-hint">Rozpis jídel se pro vaši situaci nepočítá.</div>
                <?php endif; ?>

                <?php $dt = $r['day_types'] ?? null; ?>
                <?php if ($dt && !empty($dt['enabled'])): ?>
                <h3 class="an-section-title mt-4 calc-silver-text">Tréninkové a volné dny</h3>
                <div class="row g-3">
                    <div class="col-md-4"><div class="calc-stat"><div class="calc-stat__label">Tréninkový den (<?= (int)$dt['training_days'] ?>× týdně)</div><div class="calc-stat__value"><?= $fmt($dt['training']['kcal']) ?> kcal</div><div class="small">sacharidy <?= $fmt($dt['training']['carbs_g']) ?> g</div></div></div>
                    <div class="col-md-4"><div class="calc-stat"><div class="calc-stat__label">Volný den</div><div class="calc-stat__value"><?= $fmt($dt['rest']['kcal']) ?> kcal</div><div class="small">sacharidy <?= $fmt($dt['rest']['carbs_g']) ?> g</div></div></div>
                    <div class="col-md-4"><div class="calc-stat"><div class="calc-stat__label">Týdenní průměr</div><div class="calc-stat__value"><?= $fmt($dt['weekly_avg_kcal']) ?> kcal</div><div class="small">bílkoviny a tuky stejné každý den</div></div></div>
                </div>
                <?php endif; ?>

                <?php if ($upcoming): ?>
                <h3 class="an-section-title mt-4 calc-silver-text">Naplánované tréninky (7 dní)</h3>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($upcoming as $date => $items): foreach ($items as $item): ?>
                        <span class="an-tag"><i class="fas fa-calendar-day me-1"></i><?= h(date('j. n.', strtotime($date))) ?> <?= h($item['time']) ?> · <?= h($item['title']) ?><?= $item['minutes'] > 0 ? ' (' . (int)$item['minutes'] . ' min)' : '' ?><?= $dt && !empty($dt['enabled']) ? ' → ' . $fmt($dt['training']['kcal']) . ' kcal' : '' ?></span>
                    <?php endforeach; endforeach; ?>
                </div>
                <?php endif; ?>

                <h3 class="an-section-title mt-4 calc-silver-text">Jídlo podle času tréninku</h3>
                <div class="row g-3">
                    <?php foreach ($r['timing'] ?? [] as $slot => $variant): ?>
                    <div class="col-md-6 col-xl-3">
                        <dl class="an-timing <?= ($r['timing_selected'] ?? null) === $slot ? 'is-selected' : '' ?>">
                            <div class="fw-bold text-white"><?= h($variant['title']) ?><?= ($r['timing_selected'] ?? null) === $slot ? ' <i class="fas fa-star text-warning"></i>' : '' ?></div>
                            <dt>Před</dt><dd><?= h($variant['before']) ?></dd>
                            <dt>Kdy</dt><dd><?= h($variant['when']) ?></dd>
                            <dt>Během</dt><dd><?= h($variant['during']) ?></dd>
                            <dt>Po</dt><dd><?= h($variant['after']) ?></dd>
                            <dt>Další jídlo</dt><dd><?= h($variant['next_meal']) ?></dd>
                        </dl>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($r['foods'])): ?>
                <h3 class="an-section-title mt-4 calc-silver-text">Vhodné potraviny</h3>
                <div class="row g-3">
                    <?php foreach (['protein' => 'Bílkoviny', 'carbs' => 'Sacharidy', 'fat' => 'Tuky', 'vegetables' => 'Zelenina'] as $group => $label): ?>
                    <div class="col-md-6 col-xl-3">
                        <div class="small fw-bold text-uppercase mb-1" style="letter-spacing:.12em"><?= h($label) ?></div>
                        <?php foreach ($r['foods'][$group] ?? [] as $food): ?><span class="an-tag"><?= h((string)$food) ?></span><?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($r['habit_tips'])): ?>
                <h3 class="an-section-title mt-4 calc-silver-text">Tipy k vašim návykům</h3>
                <ul class="an-list"><?php foreach ($r['habit_tips'] as $tip): ?><li><?= h($tip) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6" id="card-training">
            <div class="calc-metal calc-panel">
                <div class="d-flex align-items-center gap-2 mb-3"><span class="an-card-letter">E</span><span class="calc-panel__title mb-0">Pohyb a trénink</span></div>
                <?php $tr = $r['training'] ?? []; ?>
                <div class="row g-3 mb-3">
                    <div class="col-sm-6"><div class="calc-stat"><div class="calc-stat__label">Silový trénink</div><div class="fw-bold text-white mt-1"><?= h((string)($tr['strength'] ?? '–')) ?></div></div></div>
                    <div class="col-sm-6"><div class="calc-stat"><div class="calc-stat__label">Kardio</div><div class="fw-bold text-white mt-1"><?= h((string)($tr['cardio'] ?? '–')) ?></div></div></div>
                    <div class="col-12"><div class="calc-stat"><div class="calc-stat__label">Orientační délka</div><div class="fw-bold text-white mt-1"><?= h((string)($tr['duration'] ?? '–')) ?></div></div></div>
                </div>
                <?php if (!empty($tr['types'])): ?><div class="mb-2"><?php foreach ($tr['types'] as $type): ?><span class="an-tag"><?= h($type) ?></span><?php endforeach; ?></div><?php endif; ?>
                <?php foreach (['recovery' => 'Regenerace', 'progression' => 'Progrese', 'notes' => 'Poznámky'] as $key => $label): if (empty($tr[$key])) { continue; } ?>
                    <div class="small fw-bold text-uppercase mt-3 mb-1" style="letter-spacing:.12em"><?= h($label) ?></div>
                    <ul class="an-list"><?php foreach ($tr[$key] as $item): ?><li><?= h($item) ?></li><?php endforeach; ?></ul>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-lg-6" id="card-goal">
            <div class="calc-metal calc-panel">
                <div class="d-flex align-items-center gap-2 mb-3"><span class="an-card-letter">F</span><span class="calc-panel__title mb-0">Cíl a progres</span></div>
                <?php $tl = $r['timeline'] ?? null; ?>
                <div class="row g-3">
                    <div class="col-4"><div class="calc-stat"><div class="calc-stat__label">Aktuální</div><div class="calc-stat__value"><?= $fmt($b['weight_kg'] ?? null, 1) ?> <small class="fs-6">kg</small></div></div></div>
                    <div class="col-4"><div class="calc-stat"><div class="calc-stat__label">Cílová</div><div class="calc-stat__value"><?= ($ci['target_weight_kg'] ?? null) !== null ? $fmt($ci['target_weight_kg'], 1) : '–' ?> <small class="fs-6">kg</small></div></div></div>
                    <div class="col-4"><div class="calc-stat"><div class="calc-stat__label">Rozdíl</div><div class="calc-stat__value"><?= $tl && $tl['diff_kg'] !== null ? ($tl['diff_kg'] > 0 ? '+' : '') . $fmt($tl['diff_kg'], 1) : '–' ?> <small class="fs-6">kg</small></div></div></div>
                </div>
                <?php if ($tl && $tl['has_target'] && abs((float)$tl['diff_kg']) >= 0.3): ?>
                <ul class="an-list mt-3">
                    <li>Doporučené tempo: <strong class="text-white"><?= $fmt($tl['rec_weekly_kg_min'], 2) ?>–<?= $fmt($tl['rec_weekly_kg_max'], 2) ?> kg týdně</strong> (<?= $fmt($tl['rec_pct_min'], 2) ?>–<?= $fmt($tl['rec_pct_max'], 2) ?> % hmotnosti).</li>
                    <?php if ($tl['weeks_min'] !== null): ?><li>Při doporučeném tempu: přibližně <strong class="text-white"><?= (int)$tl['weeks_min'] ?>–<?= (int)$tl['weeks_max'] ?> týdnů</strong>.</li><?php endif; ?>
                    <?php if ($tl['weeks_estimate'] !== null): ?><li>Podle nastaveného příjmu (<?= $tl['weekly_kg_estimate'] > 0 ? '+' : '' ?><?= $fmt($tl['weekly_kg_estimate'], 2) ?> kg/týden): cíl kolem <strong class="text-white"><?= h(date('j. n. Y', strtotime((string)$tl['estimated_date']))) ?></strong> (<?= (int)$tl['weeks_estimate'] ?> týdnů).</li><?php endif; ?>
                    <?php if ($tl['required_weekly_kg'] !== null): ?><li>K cílovému datu <?= h(date('j. n. Y', strtotime((string)$tl['target_date']))) ?> je potřeba <?= $fmt($tl['required_weekly_kg'], 2) ?> kg týdně (<?= $fmt($tl['required_pct'], 2) ?> % hmotnosti).</li><?php endif; ?>
                </ul>
                <?php elseif (!$tl || !$tl['has_target']): ?>
                    <div class="calc-hint mt-3">Cílovou hmotnost jste nezadali – sledujte vývoj hmotnosti, obvodů a výkonu v historii analýz.</div>
                <?php endif; ?>
                <div class="small fw-bold text-uppercase mt-3 mb-1" style="letter-spacing:.12em">Doporučený postup</div>
                <ul class="an-list">
                    <li>Važte se 2–3× týdně ráno nalačno a sledujte týdenní průměr, ne jednotlivé dny.</li>
                    <li>Obvody pasu a boků přeměřte jednou za 2–4 týdny.</li>
                    <li>Pokud se hmotnost 2–3 týdny nemění požadovaným směrem, upravte příjem o 100–200 kcal.</li>
                    <li>Novou analýzu udělejte po změně hmotnosti o 2–3 kg nebo po změně tréninkového režimu.</li>
                </ul>
            </div>
        </div>
    </div>

    <?php if (!$isAthleteView): $ct = $current['coach_targets'] ?? []; ?>
    <div class="calc-metal calc-panel mt-4" id="coach-review">
        <div class="calc-panel__title"><i class="fas fa-user-tie me-1"></i>Kontrola trenérem · poznámka a úprava cílů</div>
        <form method="post" class="row g-3">
            <?= csrfField() ?><input type="hidden" name="action" value="coach_review"><input type="hidden" name="id" value="<?= (int)$current['id'] ?>"><input type="hidden" name="athlete_id" value="<?= $athleteId ?>">
            <?php foreach (['kcal' => ['Kalorie', 'kcal', '1000–6000'], 'protein_g' => ['Bílkoviny', 'g', '20–400'], 'fat_g' => ['Tuky', 'g', '15–250']] as $key => [$label, $unit, $range]): ?>
            <div class="col-md-4">
                <label class="form-label"><?= h($label) ?> (vlastní cíl)</label>
                <div class="input-group calc-input-group"><input type="number" class="form-control calc-input calc-input--sm" name="target_<?= $key ?>" value="<?= isset($ct[$key]) ? (int)$ct[$key] : '' ?>" placeholder="automaticky"><span class="input-group-text calc-input-unit"><?= h($unit) ?></span></div>
                <div class="calc-hint">Rozsah <?= h($range) ?>, prázdné = výpočet.</div>
            </div>
            <?php endforeach; ?>
            <div class="col-12">
                <label class="form-label">Poznámka pro sportovce</label>
                <textarea class="form-control calc-input calc-input--sm" name="coach_note" rows="3"><?= h((string)($current['coach_note'] ?? '')) ?></textarea>
                <div class="calc-hint">Sacharidy se dopočítají ze zbývající energie. Sportovec uvidí poznámku i upravené cíle.</div>
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <button class="btn calc-btn-gold" type="submit"><i class="fas fa-floppy-disk me-1"></i>Uložit</button>
                <?php if ($ct): ?><button class="btn calc-btn-ghost" type="submit" name="reset_targets" value="1"><i class="fas fa-rotate-left me-1"></i>Vrátit vypočtené cíle</button><?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="calc-metal calc-panel mt-4">
        <div class="calc-panel__title"><i class="fas fa-scale-balanced me-1"></i>Důležité upozornění</div>
        <ul class="an-list"><?php foreach ($r['disclaimers'] ?? analysisDisclaimers() as $d): ?><li><?= h($d) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>
</div>
<?php
if ($isAthleteView) {
    renderAthleteFooter();
} else {
    renderFooter();
}
