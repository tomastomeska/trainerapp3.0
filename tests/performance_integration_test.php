<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['port:', 'database:']);
$port = (string)($options['port'] ?? '');
$database = (string)($options['database'] ?? '');
if (!ctype_digit($port) || !preg_match('/^trainerapp_performance_test_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "Use a NEW isolated local database: --port=3399 --database=trainerapp_performance_test_NAME\n");
    exit(2);
}
$db = new PDO("mysql:host=127.0.0.1;port=$port;dbname=$database;charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
]);
function getDB(): PDO { return $GLOBALS['db']; }
function getAppSetting(string $key, string $default = ''): string {
    $stmt = getDB()->prepare('SELECT `value` FROM app_settings WHERE `key` = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string)$v;
}
require_once __DIR__ . '/../includes/performance_api.php';
require_once __DIR__ . '/../includes/performance_view.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
$passed = 0;
function icheck(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    $GLOBALS['passed']++;
}
function ireject(callable $fn, string $name): void {
    try { $fn(); } catch (DomainException | InvalidArgumentException $e) { icheck(true, $name); return; }
    throw new RuntimeException('FAIL: expected rejection: ' . $name);
}
function action(array $input): array {
    return performanceAction(getDB(), performanceViewer(getDB(), 'athlete', 6, 6), $input + ['date' => '2026-02-28', 'request_key' => bin2hex(random_bytes(16))]);
}
icheck((int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() === 0, 'isolated database must be empty');
foreach (explode(';', (string)file_get_contents(__DIR__ . '/performance_fixture.sql')) as $sql) if (trim($sql) !== '') $db->exec($sql);
icheck(performanceSchemaCheck($db)['missing'] === [], 'base schema audit');
icheck(!performanceSchemaReady($db), 'module absent before migration');
performanceMigrate($db);
icheck(performanceSchemaReady($db), 'module present after migration');
$db->exec("UPDATE app_settings SET `value` = '1' WHERE `key` = 'calculator_performance_enabled'");
performanceMigrate($db);
icheck(getAppSetting('calculator_performance_enabled') === '1', 'idempotent migration does not reset enabled flag');
icheck((int)$db->query('SELECT COUNT(*) FROM mycoach_daily_questionnaires')->fetchColumn() === 2, 'migration preserves legacy feedback');
$db->exec("INSERT INTO app_settings VALUES ('calculator_app_enabled_for_all', '1')");
$athlete = performanceViewer($db, 'athlete', 6, 6);
$coach = performanceViewer($db, 'coach', 10, 6);
icheck($athlete['settings'] === ['intelligence_enabled' => false, 'share_performance' => false, 'share_recovery' => false], 'private defaults');
icheck(!performanceCan($coach, 'load') && !performanceCan($coach, 'recovery'), 'coach private defaults');
ireject(fn() => performanceViewer($db, 'coach', 20, 6), 'unrelated coach');
ireject(fn() => performanceViewer($db, 'athlete', 7, 6), 'other athlete');
$c = performanceContext($db, $athlete, '2026-02-28');
icheck($c['weight']['kg'] === 80.0 && $c['weight']['date'] === '2026-02-20', 'weight as of selected date');
icheck($c['fluids']['total_ml'] === 900 && $c['fluids']['non_alcohol_ml'] === 500 && $c['fluids']['alcohol_ml'] === 400, 'individual drinks authoritative, no cached double count');
$db->exec("INSERT INTO food_diary_hydration_entries VALUES (4,1,'custom',200)");
$customDrinks = performanceContext($db, $athlete, '2026-02-28')['fluids'];
icheck($customDrinks['total_ml'] === 1100 && $customDrinks['non_alcohol_ml'] === 500 && $customDrinks['unclassified_ml'] === 200, 'custom drink not silently classified as non-alcoholic');
$db->exec('DELETE FROM food_diary_hydration_entries WHERE id=4');
icheck(performanceContext($db, $athlete, '2026-02-27')['fluids']['total_ml'] === 1200, 'legacy hydration fallback');
$activities = array_column($c['activities'], null, 'key');
icheck(isset($activities['session:1'], $activities['online:1'], $activities['custom:1']), 'actual activity adapters');
icheck(!isset($activities['session:2'], $activities['session:3'], $activities['session:4'], $activities['online:2'], $activities['online:3']), 'unfinished, deleted, other athlete excluded');
icheck(count($c['upcoming']) === 1 && count($c['activities']) === 5, 'calendar never creates completed activity');
icheck($activities['online:1']['minutes'] === null && $activities['online:1']['timer_minutes'] === 120.0, 'online timer not active duration, cross midnight kept on start day');
icheck($activities['session:5']['minutes'] === null, 'missing timer not invented');
icheck($c['daily']['sleep_hours'] === 8.0 && str_contains($c['daily_source'], 'mycoach_recovery_entries:1'), 'existing sleep fallback and source');
icheck(performanceLoad($c['activities'], $c['date'])['acute_7d'] === 300.0, 'legacy RPE adopted');
$series = performanceStrengthSeries($db, $athlete, '2026-03-01');
icheck(array_keys($series) === ['series:1', 'online_result:2'], '1RM only actual eligible no assistance/distance or missing equipment');
icheck($series['series:1']['weight_kg'] === 100.0 && $series['online_result:2']['weight_kg'] === 100.0, 'equipment included once');
ireject(fn() => action(['action' => 'feedback', 'scope' => 'activity', 'activity_key' => 'online:3', 'rpe_score' => 5, 'training_duration_minutes' => 60]), 'feedback rejects other athlete');
ireject(fn() => action(['action' => 'feedback', 'scope' => 'activity', 'activity_key' => 'online:1', 'rpe_score' => 5, 'training_duration_minutes' => 60.5]), 'integer minutes preserved by INT column');
action(['action' => 'feedback', 'scope' => 'activity', 'activity_key' => 'session:1', 'rpe_score' => 6, 'training_duration_minutes' => 50]);
icheck((int)$db->query('SELECT COUNT(*) FROM mycoach_daily_questionnaires WHERE workout_id=1')->fetchColumn() === 1, 'existing session feedback updated, not duplicated');
action(['action' => 'feedback', 'scope' => 'activity', 'activity_key' => 'online:1', 'rpe_score' => 7, 'training_duration_minutes' => 40]);
action(['action' => 'feedback', 'scope' => 'activity', 'activity_key' => 'online:1', 'rpe_score' => 7, 'training_duration_minutes' => 40]);
icheck((int)$db->query("SELECT COUNT(*) FROM mycoach_daily_questionnaires WHERE performance_key='activity:online:1' AND workout_id IS NULL")->fetchColumn() === 1, 'online feedback no fake training_sessions FK, no duplicate');
action(['action' => 'feedback', 'scope' => 'activity', 'activity_key' => 'custom:1', 'rpe_score' => 6, 'training_duration_minutes' => 50]);
action(['action' => 'link', 'custom_key' => 'custom:1', 'target_key' => 'session:1']);
ireject(fn() => action(['action' => 'link', 'custom_key' => 'custom:2', 'target_key' => 'session:1']), 'cross day link rejected');
$c = performanceContext($db, $athlete, '2026-02-28');
icheck(performanceLoad($c['activities'], $c['date'])['acute_7d'] === 580.0, 'explicit link prevents double load');
$daily = ['sleep_hours' => 8, 'energy_score' => 8, 'feeling_score' => 8, 'motivation_score' => 8, 'muscle_pain_score' => 0, 'joint_pain_score' => 0];
action(['action' => 'feedback', 'scope' => 'daily'] + $daily);
icheck((int)$db->query("SELECT COUNT(*) FROM mycoach_daily_questionnaires WHERE user_id=60 AND workout_id IS NULL AND (performance_key LIKE 'daily:%' OR performance_key IS NULL)")->fetchColumn() === 1, 'daily feedback adopts legacy day');
icheck(performanceRecovery(performanceContext($db, $athlete, '2026-02-28')['daily'])['score'] === 82, 'persisted daily feedback calculation');
$key = bin2hex(random_bytes(16));
$input = ['action' => 'save', 'module' => 'hydration', 'weight_kg' => 80, 'minutes' => 60, 'request_key' => $key];
$saved = action($input)['report'];
$replayed = action($input)['report'];
icheck($saved['id'] === $replayed['id'] && count(performanceHistory($db, $athlete)) === 1, 'result request idempotency');
ireject(fn() => action(['minutes' => 90] + $input), 'changed result with reused key rejected');
icheck($saved['results']['total_ml'] === 3400 && $saved['inputs']['weight_kg'] === 80, 'stored snapshot shape');
ireject(fn() => performanceReport($db, $coach, $saved['id']), 'unshared print/report blocked');
action(['action' => 'settings', 'intelligence_enabled' => '1', 'share_performance' => '1']);
$coach = performanceViewer($db, 'coach', 10, 6);
icheck(performanceReport($db, $coach, $saved['id'])['id'] === $saved['id'], 'explicit performance sharing');
icheck(performanceContext($db, $coach, '2026-02-28')['daily'] === [], 'recovery remains private');
$api = performanceApiData($db, $coach, ['date' => '2026-02-28']);
icheck($api['recovery'] === null && !in_array('missing-readiness', array_column($api['intelligence'], 'code'), true), 'API not inferring private recovery');
ireject(fn() => performanceAction($db, $coach, ['action' => 'feedback', 'scope' => 'daily', 'date' => '2026-02-28']), 'coach cannot enter self-report');
performanceAction($db, $coach, ['action' => 'review', 'id' => $saved['id'], 'coach_note' => '<script>alert(1)</script>', 'date' => '2026-02-28']);
ob_start(); performanceRenderReport(performanceReport($db, $coach, $saved['id'])); $html = ob_get_clean();
icheck(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'report escaping');
$sweatKey = bin2hex(random_bytes(16));
$sweatInput = ['action' => 'sweat', 'pre_kg' => 80, 'post_kg' => 79, 'drink_ml' => 500, 'urine_ml' => 0, 'minutes' => 60, 'request_key' => $sweatKey];
action($sweatInput); action($sweatInput);
ireject(fn() => action(['post_kg' => 78] + $sweatInput), 'changed sweat with reused key rejected');
icheck((int)$db->query('SELECT COUNT(*) FROM athlete_sweat_tests')->fetchColumn() === 1, 'sweat idempotency');
icheck((int)$db->query('SELECT COUNT(*) FROM athlete_weight_logs')->fetchColumn() === 3 && (int)$db->query('SELECT COUNT(*) FROM food_diary_hydration_entries')->fetchColumn() === 3, 'test measurement never duplicates weigh-in or drinks');
action(['action' => 'delete_sweat', 'id' => 1]);
icheck((int)$db->query('SELECT COUNT(*) FROM athlete_sweat_tests')->fetchColumn() === 0, 'sweat deletion');
foreach (['run_1km','ski_erg_1000','sled_push','sled_pull','burpee_broad_jumps','row_1000','farmer_carry','sandbag_lunges','wall_balls'] as $name) {
    $db->prepare("INSERT INTO mycoach_hyrox_standards (discipline_key,discipline_name,version_label,distance_or_reps,is_active,valid_from) VALUES (?,?,'test','test',1,'2026-01-01')")->execute([$name, $name]);
    if (in_array($name, ['sled_push','sled_pull','farmer_carry','sandbag_lunges','wall_balls'], true)) $db->prepare("INSERT INTO mycoach_hyrox_standard_weights (standard_id,division,gender_category,weight_label,weight_kg,sort_order) VALUES (?,'open','male','Fixture only',20,100)")->execute([(int)$db->lastInsertId()]);
}
$hyrox = ['action' => 'hyrox_attempt', 'activity_key' => 'session:1', 'segments' => array_fill_keys(array_keys(performanceHyroxSegments()), 60), 'standards_confirmed' => '1'];
action($hyrox); action($hyrox);
icheck(performanceAttemptReport($db, $coach, 1)['results']['total_seconds'] === 1080, 'actual attempt printable persisted result');
icheck(performanceContext($db, $coach, '2026-02-28')['latest_results']['hyrox']['source'] === 'athlete_hyrox_attempts:1', 'intelligence reuses actual HYROX splits');
ireject(fn() => action(['segments' => ['run_1' => 61] + $hyrox['segments']] + $hyrox), 'conflicting actual attempt never silently overwritten');
ireject(fn() => performanceStandards($db, '2026-02-28', 'pro', 'female'), 'missing category weights blocked');
icheck((int)$db->query('SELECT COUNT(*) FROM athlete_hyrox_attempts')->fetchColumn() === 1 && (int)$db->query('SELECT COUNT(*) FROM athlete_hyrox_splits')->fetchColumn() === 18, 'HYROX one actual attempt and 18 splits');
action(['action' => 'delete_attempt', 'id' => 1]);
icheck((int)$db->query('SELECT COUNT(*) FROM athlete_hyrox_splits')->fetchColumn() === 0, 'attempt deletion cascades only splits');
icheck((int)$db->query('SELECT COUNT(*) FROM training_sessions')->fetchColumn() === 5 && (int)$db->query('SELECT COUNT(*) FROM online_trainings')->fetchColumn() === 3, 'no duplicate workout records');
ireject(fn() => performanceCalculate($db, performanceViewer($db, 'athlete', 8, 8), 'strength', ['exercise_name' => 'Squat', 'weight_kg' => 20, 'reps' => 5, 'unassisted' => '1'], '2026-02-28'), 'junior maximum blocked');
ireject(fn() => action(['action' => 'save', 'module' => 'hydration', 'weight_kg' => [80], 'minutes' => 0]), 'structured malformed input');
ireject(fn() => action(['action' => 'save', 'module' => 'load', 'date' => '2999-01-01']), 'future history blocked');
$db->exec("INSERT INTO app_settings VALUES ('calculator_performance_hydration_enabled','0')");
ireject(fn() => performanceReport($db, $coach, $saved['id']), 'child toggle blocks direct report/print');
$api = performanceApiData($db, performanceViewer($db, 'coach', 10, 6), ['date' => '2026-02-28']);
icheck($api['context']['weight'] === null && $api['context']['fluids'] === null && !isset($api['sweat_tests']), 'disabled hydration not leaked via aggregate API');
$db->exec("UPDATE app_settings SET `value`='1' WHERE `key`='calculator_performance_hydration_enabled'");
action(['action' => 'settings']);
$coach = performanceViewer($db, 'coach', 10, 6);
ireject(fn() => performanceReport($db, $coach, $saved['id']), 'revocation blocks previously shared report');
icheck(performanceHistory($db, $coach) === [], 'revocation filters history');
action(['action' => 'delete', 'id' => $saved['id']]);
icheck(performanceHistory($db, $athlete) === [], 'athlete may delete own historical snapshot');
$db->exec('RENAME TABLE food_diary_hydration_entries TO fixture_hidden_drinks');
$fallback = performanceContext($db, $athlete, '2026-02-28');
icheck($fallback['fluids']['total_ml'] === 5000 && $fallback['fluids']['non_alcohol_ml'] === null && count($fallback['warnings']) > 0, 'older schema fallback explicit and not added to missing entries');
$db->exec('RENAME TABLE fixture_hidden_drinks TO food_diary_hydration_entries');
$db->exec('ALTER TABLE online_training_results CHANGE actual_equipment_weight fixture_hidden_equipment DECIMAL(7,2) NULL');
icheck(array_keys(performanceStrengthSeries($db, $athlete, '2026-03-01')) === ['series:1'], 'partial online result schema safely excluded');
$db->exec('ALTER TABLE online_training_results CHANGE fixture_hidden_equipment actual_equipment_weight DECIMAL(7,2) NULL');
$db->exec("UPDATE app_settings SET `value`='0' WHERE `key`='calculator_performance_enabled'");
ireject(fn() => performanceViewer($db, 'athlete', 6, 6), 'parent toggle blocks all access including pilot');
echo "OK: $passed performance database assertions\n";
