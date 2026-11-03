<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/performance.php';
require_once __DIR__ . '/includes/performance_view.php';
header('Cache-Control: no-store');

$isCoach = isLoggedIn();
if ($isCoach) requireLogin(); else requireAthleteLogin();
$type = $isCoach ? 'coach' : 'athlete';
$actorId = (int)($isCoach ? getCurrentCoachId() : getCurrentAthleteId());
$athleteId = $isCoach ? intParam($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET, 'athlete_id') : $actorId;
$pdo = getDB();
$viewer = null;
$context = null;
$report = null;
$error = null;
$notice = null;
$module = is_string($_GET['module'] ?? null) ? $_GET['module'] : 'hydration';
$date = date('Y-m-d');
$url = static function (array $params = []) use ($isCoach, $athleteId): string {
    if ($isCoach && $athleteId > 0) $params['athlete_id'] = $athleteId;
    return BASE_URL . '/performance.php' . ($params ? '?' . http_build_query($params) : '');
};
try {
    performanceValidateInput($_GET);
    performanceValidateInput($_POST);
    if (!isset(performanceModules()[$module])) throw new InvalidArgumentException('Neznámá kalkulačka.');
    if (!calculatorAppVisibleForUser($type, $actorId) || !calculatorIsEnabled('performance')) throw new DomainException('Výkonnost a regenerace není pro tento účet zapnutá.');
    if (!performanceSchemaReady($pdo)) throw new RuntimeException('Je nutná jednorázová migrace scripts\\migrate_performance.php. Kontaktujte administrátora.');
    $date = performanceDate((string)($_POST['date'] ?? $_GET['date'] ?? $date));
    if ($date > date('Y-m-d')) throw new InvalidArgumentException('Vyberte dnešní nebo minulý den.');
    if ($athleteId > 0) {
        $viewer = performanceViewer($pdo, $type, $actorId, $athleteId);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) throw new DomainException('Neplatný bezpečnostní token. Obnovte stránku.');
            $response = performanceAction($pdo, $viewer, $_POST);
            if (isset($response['report']) && !isset($response['report']['id'])) {
                $report = $response['report'];
                $module = $report['module'];
                $notice = $response['message'];
            } else {
                flash('success', $response['message']);
                redirect($url(['date' => $date, 'module' => (string)($_POST['module'] ?? $module)] + (isset($response['report']['id']) ? ['id' => $response['report']['id']] : [])));
            }
        }
        $context = performanceContext($pdo, $viewer, $date);
        if (isset($_GET['id'])) {
            $report = performanceReport($pdo, $viewer, (int)$_GET['id']);
            $module = $report['module'];
        }
    } elseif (!$isCoach) throw new DomainException('Neplatný profil.');
} catch (InvalidArgumentException | DomainException $e) {
    $error = $e->getMessage();
    http_response_code($e instanceof DomainException ? 403 : 422);
} catch (Throwable $e) {
    error_log('performance page: ' . $e->getMessage());
    $error = 'Modul se nepodařilo načíst. Ověřte migraci scripts\\migrate_performance.php nebo kontaktujte administrátora.';
    http_response_code(503);
}
if ($isCoach) {
    require_once __DIR__ . '/includes/header.php';
    renderHeader('Výkonnost a regenerace');
} else {
    require_once __DIR__ . '/includes/athlete_header.php';
    renderAthleteHeader('Výkonnost a regenerace');
}
$hidden = static function (string $action, ?string $key = null) use ($athleteId, $date): void {
    echo csrfField() . '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="athlete_id" value="' . $athleteId . '"><input type="hidden" name="date" value="' . h($date) . '"><input type="hidden" name="request_key" value="' . bin2hex(random_bytes(16)) . '">';
    if ($key !== null) echo '<input type="hidden" name="module" value="' . h($key) . '">';
};
$number = static function (string $key, string $label, $value, float $min, float $max, string $step = 'any', bool $required = true): void {
    echo '<label class="perf-field">' . h($label) . '<input class="form-control calc-input" type="number" name="' . h($key) . '" value="' . h($value !== null ? (string)$value : '') . '" min="' . $min . '" max="' . $max . '" step="' . h($step) . '"' . ($required ? ' required' : '') . '></label>';
};
$settings = $viewer['settings'] ?? [];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/calculators.css?v=<?= (int)filemtime(__DIR__ . '/assets/css/calculators.css') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/performance.css?v=<?= (int)filemtime(__DIR__ . '/assets/css/performance.css') ?>">
<div class="calc-app perf-app">
    <a class="d-inline-block mb-3" href="<?= BASE_URL ?>/calculators.php">← Kalkulačky</a>
    <section class="calc-metal calc-hero mb-4">
        <div class="calc-hero__eyebrow">TrainerApp Lab</div>
        <h1 class="calc-hero__title">Výkonnost <span class="calc-gold-text">a regenerace</span></h1>
        <p>Hydratace · 1RM · HYROX · Zátěž · Regenerace</p>
        <p class="mb-0">Společná data. Vysvětlitelná doporučení. Bez přepisování plánu trenéra.</p>
    </section>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert alert-info" role="status"><?= h($notice) ?></div><?php endif; ?>
    <?php if (!$viewer && !$error && $isCoach): ?>
        <section class="calc-metal calc-panel">
            <h2>Vyberte svého sportovce</h2>
            <p>Zobrazí se pouze oblasti, které vám sportovec výslovně sdílí.</p>
            <div class="perf-grid">
                <?php foreach (performanceRows($pdo, 'SELECT id, first_name, last_name FROM athletes WHERE coach_id = ? ORDER BY last_name, first_name LIMIT 500', [$actorId]) as $a): ?>
                <a class="btn calc-btn-ghost" href="<?= h(BASE_URL . '/performance.php?athlete_id=' . (int)$a['id']) ?>"><?= h($a['first_name'] . ' ' . $a['last_name']) ?> (ID <?= (int)$a['id'] ?>)</a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($context && $viewer): ?>
    <section class="calc-metal calc-panel mb-4">
        <div class="d-flex justify-content-between gap-3 flex-wrap">
            <h2><?= h($viewer['athlete']['first_name'] . ' ' . $viewer['athlete']['last_name']) ?> (ID <?= $athleteId ?>)</h2>
            <form method="get" class="d-flex gap-2">
                <?php if ($isCoach): ?><input type="hidden" name="athlete_id" value="<?= $athleteId ?>"><?php endif; ?>
                <input type="hidden" name="module" value="<?= h($module) ?>">
                <input aria-label="Datum přehledu" type="date" class="form-control" name="date" max="<?= date('Y-m-d') ?>" value="<?= h($date) ?>" required>
                <button class="btn calc-btn-gold">Zobrazit</button>
            </form>
        </div>
        <?php foreach ($context['warnings'] as $warning): ?><p class="calc-note"><?= h($warning) ?></p><?php endforeach; ?>
        <nav class="perf-grid" aria-label="Kalkulačky výkonnosti">
            <?php foreach (performanceModules() as $key => $label): ?>
            <?php if (performanceCan($viewer, $key)): ?>
                <a class="btn <?= $module === $key ? 'calc-btn-gold' : 'calc-btn-ghost' ?>" href="<?= h($url(['module' => $key, 'date' => $date])) ?>"><?= h($label) ?></a>
            <?php else: ?><span class="btn calc-btn-ghost disabled"><?= h($label) ?> · vypnuto / nesdíleno</span><?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </section>

    <?php if ($report): ?>
        <?php performanceRenderReport($report); ?>
        <?php if (isset($report['id'])): ?>
        <a class="btn btn-dark mb-4" target="_blank" rel="noopener" href="<?= h(BASE_URL . '/performance_print.php?' . http_build_query(['id' => $report['id'], 'athlete_id' => $athleteId])) ?>">Tisk / uložit PDF</a>
        <?php if ($isCoach): ?>
        <form method="post" class="calc-metal calc-panel mb-4">
            <?php $hidden('review', $report['module']); ?><input type="hidden" name="id" value="<?= $report['id'] ?>">
            <label class="perf-field">Poznámka trenéra<textarea name="coach_note" class="form-control calc-input" maxlength="4000"><?= h($report['coach_note'] ?? '') ?></textarea></label>
            <button class="btn calc-btn-gold mt-3">Uložit poznámku</button>
        </form>
        <?php endif; endif; ?>
    <?php endif; ?>

    <?php if (performanceCan($viewer, $module)): ?>
    <section class="calc-metal calc-panel mb-4">
    <h2><?= h(performanceModules()[$module]) ?></h2>
    <?php if ($module === 'hydration'): ?>
        <?php if ($context['weight']): ?><p>Váha: <?= h((string)$context['weight']['kg']) ?> kg · <?= h($context['weight']['date']) ?>. Starší váhu před výpočtem ověřte.</p><?php endif; ?>
        <?php if ($context['sweat']): ?><p>Poslední test pocení: <?= h((string)$context['sweat']['result']['rate_l_h']) ?> l/h · <?= h($context['sweat']['date']) ?>. Použijte jen pro srovnatelné podmínky.</p><?php endif; ?>
        <form method="post">
            <?php $hidden('calculate', 'hydration'); ?>
            <div class="perf-grid">
                <?php $number('weight_kg', 'Aktuální hmotnost (kg)', $context['weight']['kg'] ?? null, 20, 400); ?>
                <?php $number('minutes', 'Délka aktivity (min)', 0, 0, 600); ?>
                <?php $number('sweat_rate_l_h', 'Pocení (l/h), volitelné', null, .1, 4, 'any', false); ?>
                <label class="perf-field">Prostředí<select class="form-select calc-input" name="environment"><option value="normal">Běžné</option><option value="hot">Horko</option></select></label>
            </div>
            <button class="btn calc-btn-ghost mt-3" name="action" value="calculate">Spočítat náhled</button>
            <button class="btn calc-btn-gold mt-3" name="action" value="save">Spočítat a uložit</button>
        </form>
        <?php if ($context['fluids']): ?>
        <p class="mt-3">Strava: <?= $context['fluids']['total_ml'] !== null ? (int)$context['fluids']['total_ml'] . ' ml zaznamenáno' : 'příjem nezadán' ?>.
            Nealkoholické: <?= $context['fluids']['non_alcohol_ml'] !== null ? (int)$context['fluids']['non_alcohol_ml'] . ' ml' : 'typy dávek nejsou známé' ?>. Alkohol: <?= (int)$context['fluids']['alcohol_ml'] ?> ml.</p>
        <?php endif; ?>
        <?php if (!$isCoach): ?>
        <a class="btn calc-btn-ghost mt-3" href="<?= h(BASE_URL . '/athlete_food_diary.php?date=' . $date) ?>">Zapsat nápoj ve Stravě</a>
        <details class="mt-4"><summary>Test pocení (samostatné měření, ne zápis váhy ani nápojů)</summary>
            <form method="post" class="mt-3">
                <?php $hidden('sweat', 'hydration'); ?>
                <div class="perf-grid">
                    <?php $number('pre_kg', 'Hmotnost před (kg)', null, 20, 400); $number('post_kg', 'Hmotnost po (kg)', null, 20, 400);
                    $number('drink_ml', 'Vypito během (ml)', 0, 0, 15000); $number('urine_ml', 'Moč během (ml)', 0, 0, 5000); $number('minutes', 'Trvání testu (min)', 60, 10, 600); ?>
                </div>
                <button class="btn calc-btn-gold mt-3">Uložit test</button>
            </form>
        </details>
        <?php endif; ?>
        <details class="mt-4"><summary>Historie testů pocení</summary>
            <?php foreach (performanceRows($pdo, 'SELECT id, entry_date, results_json FROM athlete_sweat_tests WHERE athlete_id = ? ORDER BY entry_date DESC, id DESC LIMIT 30', [$athleteId]) as $test): ?>
            <div class="my-3"><strong><?= h($test['entry_date']) ?></strong>
                <?php performanceRenderTree(json_decode($test['results_json'], true, 512, JSON_THROW_ON_ERROR)); ?>
                <?php if (!$isCoach): ?><form method="post" data-confirm="Smazat test pocení?"><?php $hidden('delete_sweat', 'hydration'); ?><input type="hidden" name="id" value="<?= (int)$test['id'] ?>"><button class="btn calc-btn-ghost btn-sm">Smazat měření</button></form><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </details>
        <?php if ($context['upcoming']): ?><h3 class="h5 mt-4">Plánované aktivity (nejsou absolvovanou zátěží)</h3><ul><?php foreach ($context['upcoming'] as $event): ?><li><?= h($event['starts_at']) ?> – <?= h($event['ends_at']) ?></li><?php endforeach; ?></ul><?php endif; ?>

    <?php elseif ($module === 'strength'): ?>
        <p>Použijte srovnatelnou celkovou zátěž včetně vybavení. Automatická nabídka obsahuje pouze vhodné skutečné série z posledních 180 dní.</p>
        <form method="post">
            <?php $hidden('calculate', 'strength'); ?>
            <label class="perf-field">Zdroj<select name="series_source" class="form-select calc-input"><option value="">Ruční výpočet</option>
                <?php foreach (performanceStrengthSeries($pdo, $viewer, $date) as $key => $s): ?><option value="<?= h($key) ?>"><?= h($s['date'] . ' · ' . $s['name'] . ' · ' . $s['weight_kg'] . ' kg × ' . $s['reps'] . ' · ' . $key) ?></option><?php endforeach; ?>
            </select></label>
            <p class="small mt-2">Při výběru uložené série se následující ruční údaje ignorují.</p>
            <div class="perf-grid">
                <label class="perf-field">Název cviku<input class="form-control calc-input" name="exercise_name" maxlength="190"></label>
                <?php $number('weight_kg', 'Celková zátěž (kg)', null, .5, 600, 'any', false); $number('reps', 'Opakování', null, 1, 12, '1', false); ?>
            </div>
            <label class="d-block mt-3"><input type="checkbox" name="unassisted" value="1" required> Potvrzuji technicky správnou sérii bez dopomoci a správný význam zátěže.</label>
            <button class="btn calc-btn-ghost mt-3" name="action" value="calculate">Náhled</button>
            <button class="btn calc-btn-gold mt-3" name="action" value="save">Spočítat a uložit</button>
        </form>

    <?php elseif ($module === 'load'): ?>
        <?php $load = performanceLoad($context['activities'], $date); ?>
        <div class="perf-grid mb-3"><div class="calc-stat">7 dní: <strong><?= $load['acute_7d'] ?> AU</strong></div><div class="calc-stat">28 dní: <strong><?= $load['total_28d'] ?> AU</strong></div><div class="calc-stat">Pokrytí zapsaných aktivit: <strong><?= $load['coverage_pct'] !== null ? $load['coverage_pct'] . ' %' : 'nezjištěno' ?></strong></div></div>
        <p>Výpočet: skutečné minuty × RPE. Chybějící den není potvrzené volno; online časovač vyžaduje potvrzení aktivní délky.</p>
        <div class="perf-bars" aria-label="Denní vývoj tréninkové zátěže">
            <?php $peak = max(1, ...array_column($load['days'], 'load')); foreach ($load['days'] as $d => $day): ?>
            <div class="perf-bar-row"><span><?= h(substr($d, 5)) ?></span><div><i style="width:<?= round($day['load'] / $peak * 100, 1) ?>%"></i></div><span><?= $day['load'] ?> AU<?= $day['missing'] ? ' · neúplné' : '' ?></span></div>
            <?php endforeach; ?>
        </div>
        <form method="post" class="mt-3"><?php $hidden('save', 'load'); ?><button class="btn calc-btn-gold">Uložit tento přehled</button></form>
        <h3 class="h5 mt-4">Zaznamenané aktivity za 28 dní</h3>
        <div class="table-responsive"><table class="table perf-table"><thead><tr><th>Datum</th><th>Aktivita</th><th>Minuty</th><th>RPE</th><th>Zdroj</th></tr></thead><tbody>
        <?php foreach ($context['activities'] as $a): ?><tr><td><?= h($a['date']) ?></td><td><?= h($a['label']) ?><?= $a['linked_to'] ? ' · propojeno, nepočítá se podruhé' : '' ?></td><td><?= h($a['minutes'] !== null ? (string)$a['minutes'] : 'doplnit') ?></td><td><?= h($a['rpe'] !== null ? (string)$a['rpe'] : 'doplnit') ?></td><td><?= h($a['key']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if (!$isCoach): ?>
        <form method="post" class="mt-3">
            <?php $hidden('feedback', 'load'); ?><input type="hidden" name="scope" value="activity">
            <label class="perf-field">Hodnocení aktivity vybraného dne<select name="activity_key" class="form-select calc-input" required>
                <option value="">Vyberte aktivitu</option>
                <?php foreach ($context['activities'] as $a): if ($a['date'] !== $date || $a['linked_to']) continue; ?><option value="<?= h($a['key']) ?>"><?= h($a['label'] . ' · ' . $a['key']) ?><?= isset($a['timer_minutes']) ? ' · časovač ' . h((string)$a['timer_minutes']) . ' min (ověřte)' : '' ?></option><?php endforeach; ?>
            </select></label>
            <div class="perf-grid mt-3"><?php $number('training_duration_minutes', 'Skutečné aktivní minuty', null, 1, 600, '1'); $number('rpe_score', 'RPE celé aktivity (1 lehká – 10 maximální)', null, 1, 10, '1'); ?></div>
            <button class="btn calc-btn-gold mt-3">Uložit hodnocení</button>
        </form>
        <details class="mt-4"><summary>Propojit ruční aktivitu se stejným evidovaným tréninkem</summary>
            <form method="post" class="mt-3">
                <?php $hidden('link', 'load'); ?>
                <label class="perf-field">Ruční aktivita<select name="custom_key" class="form-select calc-input" required><option value="">Vyberte</option><?php foreach ($context['activities'] as $a): if (!str_starts_with($a['key'], 'custom:')) continue; ?><option value="<?= h($a['key']) ?>"><?= h($a['date'] . ' · ' . $a['label']) ?></option><?php endforeach; ?></select></label>
                <label class="perf-field mt-2">Stejný trénink<select name="target_key" class="form-select calc-input"><option value="">Zrušit propojení</option><?php foreach ($context['activities'] as $a): if (str_starts_with($a['key'], 'custom:')) continue; ?><option value="<?= h($a['key']) ?>"><?= h($a['date'] . ' · ' . $a['label'] . ' · ' . $a['key']) ?></option><?php endforeach; ?></select></label>
                <button class="btn calc-btn-gold mt-3">Potvrdit propojení</button>
            </form>
        </details>
        <a class="btn calc-btn-ghost mt-3" href="<?= h(BASE_URL . '/athlete_food_diary.php?date=' . $date) ?>">Zapsat další aktivitu ve Stravě</a>
        <?php endif; ?>

    <?php elseif ($module === 'recovery'): ?>
        <?php $recovery = performanceRecovery($context['daily']); ?>
        <p class="fs-4"><?= h($recovery['status']) ?><?= $recovery['score'] !== null ? ' · ' . $recovery['score'] . '/100' : '' ?></p>
        <p>Hodnocení pro tento den; chybějící hodnoty se nenahrazují průměrem. Není lékařskou diagnózou.</p>
        <?php if (!$isCoach): ?>
        <form method="post">
            <?php $hidden('feedback', 'recovery'); ?><input type="hidden" name="scope" value="daily">
            <div class="perf-grid">
                <?php foreach (['sleep_hours' => ['Spánek poslední noci (h)', 0, 24], 'energy_score' => ['Energie (1–10)', 1, 10], 'motivation_score' => ['Motivace (1–10)', 1, 10], 'feeling_score' => ['Celkový pocit (1–10)', 1, 10], 'muscle_pain_score' => ['Bolest svalů (0–10)', 0, 10], 'joint_pain_score' => ['Bolest kloubů (0–10)', 0, 10]] as $key => [$label, $min, $max]) $number($key, $label, $context['daily'][$key] ?? null, $min, $max, $key === 'sleep_hours' ? '.25' : '1', false); ?>
            </div>
            <button class="btn calc-btn-gold mt-3">Uložit denní hodnocení</button>
        </form>
        <?php endif; ?>
        <form method="post" class="mt-3"><?php $hidden('save', 'recovery'); ?><button class="btn calc-btn-ghost">Uložit výsledek do historie</button></form>
        <h3 class="h5 mt-4">Vývoj hodnocení za posledních 28 dní</h3>
        <div class="table-responsive"><table class="table perf-table"><thead><tr><th>Datum</th><th>Připravenost</th><th>Spánek</th><th>Stav</th></tr></thead><tbody>
        <?php foreach (performanceRecoveryTrend($pdo, $viewer, $date) as $day => $trend): ?>
        <tr><td><?= h($day) ?></td><td><?= $trend['result']['score'] ?? 'Neúplné' ?></td><td><?= h(isset($trend['values']['sleep_hours']) ? (string)$trend['values']['sleep_hours'] . ' h' : 'Nezadáno') ?></td><td><?= h($trend['result']['status']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>

    <?php elseif ($module === 'hyrox'): ?>
        <p>Transparentní součet běžeckých úseků, stanovišť, přechodů a penalizací. Vše zadávejte v sekundách.</p>
        <form method="get" class="perf-grid mb-3">
            <?php if ($isCoach): ?><input type="hidden" name="athlete_id" value="<?= $athleteId ?>"><?php endif; ?>
            <input type="hidden" name="module" value="hyrox"><input type="hidden" name="date" value="<?= h($date) ?>">
            <?php $division = (string)($_GET['division'] ?? 'open'); $gender = (string)($_GET['gender'] ?? 'male'); ?>
            <label class="perf-field">Divize<select name="division" class="form-select calc-input"><?php foreach (['open', 'pro', 'doubles', 'relay'] as $d): ?><option <?= $division === $d ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?></select></label>
            <label class="perf-field">Kategorie<select name="gender" class="form-select calc-input"><?php foreach (['male' => 'Muži', 'female' => 'Ženy', 'mixed' => 'Mixed'] as $g => $label): ?><option value="<?= h($g) ?>" <?= $gender === $g ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
            <button class="btn calc-btn-ghost">Načíst standardy</button>
        </form>
        <?php
        $standards = [];
        try { $standards = performanceStandards($pdo, $date, $division, $gender); }
        catch (InvalidArgumentException $e) { echo '<p class="calc-note">' . h($e->getMessage()) . '</p>'; }
        ?>
        <details class="mb-3"><summary>Standardy pro <?= h($division . '/' . $gender) ?> (ověřte aktuální soutěžní pravidla)</summary><?php performanceRenderTree($standards); ?></details>
        <?php if (!$standards): ?><p class="calc-note">Standardy nejsou dostupné. Výpočet je do doplnění zablokován.</p><?php else: ?>
        <form method="post">
            <?php $hidden('calculate', 'hyrox'); ?>
            <input type="hidden" name="division" value="<?= h($division) ?>"><input type="hidden" name="gender" value="<?= h($gender) ?>">
            <div class="perf-grid"><?php foreach (performanceHyroxSegments() as $key => $label) $number('segments[' . $key . ']', $label . ' (s)', in_array($key, ['roxzone', 'penalties'], true) ? 0 : null, in_array($key, ['roxzone', 'penalties'], true) ? 0 : 1, 14400, '1'); ?></div>
            <label class="d-block mt-3"><input type="checkbox" required name="standards_confirmed" value="1"> Ověřil/a jsem kategorii, délky, zátěže a platnost standardů. Týmové formáty nejsou individuální výkon.</label>
            <button class="btn calc-btn-ghost mt-3" name="action" value="calculate">Náhled</button>
            <button class="btn calc-btn-gold mt-3" name="action" value="save">Uložit odhad</button>
            <?php if (!$isCoach): ?>
            <details class="mt-4"><summary>Místo odhadu uložit skutečný pokus k existující aktivitě</summary>
                <label class="perf-field mt-2">Dokončená aktivita tohoto dne<select name="activity_key" class="form-select calc-input"><option value="">Vyberte</option><?php foreach ($context['activities'] as $a): if ($a['date'] !== $date || $a['linked_to']) continue; ?><option value="<?= h($a['key']) ?>"><?= h($a['label'] . ' · ' . $a['key']) ?></option><?php endforeach; ?></select></label>
                <button class="btn calc-btn-gold mt-3" name="action" value="hyrox_attempt">Uložit skutečné mezičasy</button>
                <p class="small mt-2">Jeden pokus na aktivitu. Opakované odeslání nevytvoří nový trénink ani druhý pokus.</p>
            </details>
            <?php endif; ?>
        </form>
        <?php endif; ?>
        <?php $attempts = performanceRows($pdo, 'SELECT id, entry_date, category, total_seconds, activity_key FROM athlete_hyrox_attempts WHERE athlete_id = ? ORDER BY entry_date DESC, id DESC LIMIT 30', [$athleteId]); ?>
        <?php if ($attempts): ?><h3 class="h5 mt-4">Skutečné pokusy</h3><?php foreach ($attempts as $attempt): ?><details class="mb-2"><summary><?= h($attempt['entry_date'] . ' · ' . $attempt['category'] . ' · ' . $attempt['total_seconds'] . ' s · ' . $attempt['activity_key']) ?></summary><?php performanceRenderTree(performanceRows($pdo, 'SELECT segment_key, seconds FROM athlete_hyrox_splits WHERE attempt_id = ? ORDER BY segment_key', [$attempt['id']])); ?>
        <a class="btn calc-btn-ghost btn-sm" target="_blank" rel="noopener" href="<?= h(BASE_URL . '/performance_print.php?' . http_build_query(['attempt_id' => $attempt['id'], 'athlete_id' => $athleteId])) ?>">Tisk pokusu</a>
        <?php if (!$isCoach): ?><form method="post" data-confirm="Smazat mezičasy pokusu? Původní trénink zůstane zachován."><?php $hidden('delete_attempt', 'hyrox'); ?><input type="hidden" name="id" value="<?= (int)$attempt['id'] ?>"><button class="btn calc-btn-ghost btn-sm">Smazat pokus</button></form><?php endif; ?>
        </details><?php endforeach; endif; ?>
    <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php
    $load = performanceLoad($context['activities'], $date);
    $recovery = performanceRecovery($context['daily']);
    $intelligence = performanceIntelligence($context, $load, $recovery);
    ?>
    <section class="calc-metal calc-panel mb-4">
        <h2>TrainerApp Coach Intelligence</h2>
        <p>Vysvětlitelná lokální pravidla · verze 1.0 · bez odesílání dat externí AI službě.</p>
        <?php if (!$intelligence): ?><p>Doporučení nejsou zapnutá. Sportovec je může povolit níže.</p><?php endif; ?>
        <?php foreach ($intelligence as $rule): ?><article class="calc-note mb-2"><strong><?= h($rule['text']) ?></strong><div class="small mt-1"><?= h($rule['code']) ?> · <?= h(implode(', ', $rule['sources'])) ?> · <?= h($date) ?></div></article><?php endforeach; ?>
        <p class="small mb-0">Doporučení nepřepisují tréninkový plán, cíle Analýzy sportovce ani rezervace v kalendáři.</p>
    </section>

    <section class="calc-metal calc-panel mb-4">
        <h2>Historie výsledků</h2>
        <p>Historická zpráva zůstává snímkem původních vstupů. Aktuální přehled se počítá z dostupných zdrojů znovu.</p>
        <div class="table-responsive"><table class="table perf-table"><thead><tr><th>Datum</th><th>Funkce</th><th>Verze</th><th>Akce</th></tr></thead><tbody>
        <?php foreach (performanceHistory($pdo, $viewer) as $row): ?>
        <tr><td><?= h($row['entry_date']) ?></td><td><?= h(performanceModules()[$row['module_key']]) ?><?= $row['coach_note'] ? ' · poznámka trenéra' : '' ?></td><td><?= h($row['algorithm_version']) ?></td><td>
            <a class="btn calc-btn-ghost btn-sm" href="<?= h($url(['id' => $row['id'], 'date' => $date])) ?>">Otevřít</a>
            <a class="btn calc-btn-ghost btn-sm" target="_blank" rel="noopener" href="<?= h(BASE_URL . '/performance_print.php?' . http_build_query(['id' => $row['id'], 'athlete_id' => $athleteId])) ?>">Tisk</a>
            <?php if (!$isCoach): ?><form class="d-inline" method="post" data-confirm="Smazat pouze historický výsledek?"><?php $hidden('delete'); ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn calc-btn-ghost btn-sm">Smazat</button></form><?php endif; ?>
        </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>

    <?php if (!$isCoach): ?>
    <section class="calc-metal calc-panel mb-4" id="settings">
        <h2>Sdílení a individuální doporučení</h2>
        <p>Nové oblasti jsou ve výchozím stavu nesdílené. Nastavení BMI a Analýzy sportovce se nemění.</p>
        <form method="post">
            <?php $hidden('settings'); ?>
            <?php foreach (['intelligence_enabled' => 'Zapnout individuální doporučení Coach Intelligence', 'share_performance' => 'Sdílet výkonnost (hydratace, 1RM, HYROX, zátěž) s mým trenérem', 'share_recovery' => 'Sdílet regeneraci (spánek, energie, motivace, bolest a připravenost) s mým trenérem'] as $key => $label): ?>
            <label class="d-block mb-3"><input type="checkbox" name="<?= h($key) ?>" value="1" <?= !empty($settings[$key]) ? 'checked' : '' ?>> <?= h($label) ?></label>
            <?php endforeach; ?>
            <button class="btn calc-btn-gold">Uložit nastavení</button>
        </form>
    </section>
    <?php endif; ?>
    <p class="perf-disclaimer">Výsledky jsou orientační, nejsou diagnózou ani zárukou výkonu. Při zdravotních omezeních, bolesti nebo neobvyklých potížích konzultujte kvalifikovaného odborníka.</p>
    <?php endif; ?>
</div>
<script src="<?= BASE_URL ?>/assets/js/performance.js" defer></script>
<?php if ($isCoach) renderFooter(); else renderAthleteFooter(); ?>
