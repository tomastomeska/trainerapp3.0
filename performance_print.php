<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/performance.php';
require_once __DIR__ . '/includes/performance_view.php';
header('Cache-Control: no-store');
$isCoach = isLoggedIn();
if ($isCoach) requireLogin(); else requireAthleteLogin();
try {
    performanceValidateInput($_GET);
    $pdo = getDB();
    $actor = (int)($isCoach ? getCurrentCoachId() : getCurrentAthleteId());
    $viewer = performanceViewer($pdo, $isCoach ? 'coach' : 'athlete', $actor, $isCoach ? intParam($_GET, 'athlete_id') : $actor);
    $report = isset($_GET['attempt_id']) ? performanceAttemptReport($pdo, $viewer, intParam($_GET, 'attempt_id')) : performanceReport($pdo, $viewer, intParam($_GET, 'id'));
} catch (DomainException $e) {
    http_response_code(403);
    exit(h($e->getMessage()));
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    exit(h($e->getMessage()));
} catch (Throwable $e) {
    error_log('performance print: ' . $e->getMessage());
    http_response_code(503);
    exit('Zprávu se nepodařilo načíst. Ověřte dostupnost modulu a migraci databáze.');
}
?>
<!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Výkonnost a regenerace — <?= h($viewer['athlete']['first_name'] . ' ' . $viewer['athlete']['last_name']) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/performance.css">
<style>
body { font: 14px/1.5 system-ui, sans-serif; color: #17212f; background: #e7e9ed; margin: 0; }
main { max-width: 190mm; margin: 20px auto; padding: 10mm; background: white; }
header { border-bottom: 3px solid #b98c30; margin-bottom: 20px; }
nav { text-align: center; padding: 15px; background: #20252b; color: white; }
nav a { color: white; margin-right: 20px; }
button { padding: 10px 20px; cursor: pointer; }
.perf-values dt { color: #475569; }.perf-values > div { border-color: #ddd; }
@page { size: A4; margin: 14mm; }
@media print { body { background: white; } main { margin: 0; padding: 0; max-width: none; } nav { display: none; } }
</style></head><body>
<nav><a href="<?= h(BASE_URL . '/performance.php?' . http_build_query(['athlete_id' => $viewer['athlete_id'], 'module' => $report['module']])) ?>">Zpět</a><button type="button" onclick="window.print()">Tisk / uložit PDF</button></nav>
<main><header><p>TrainerApp · Výkonnost a regenerace</p>
<h1><?= h($viewer['athlete']['first_name'] . ' ' . $viewer['athlete']['last_name']) ?> (ID <?= $viewer['athlete_id'] ?>)</h1>
<p>Vytištěno <?= h(date('j. n. Y H:i')) ?> · Historická zpráva, nikoliv nový přepočet.</p></header>
<?php performanceRenderReport($report); ?>
<p>Výsledky jsou orientační. Nenahrazují lékařské vyšetření ani individuální vedení kvalifikovaným trenérem. Uložené zdroje mohly být od vytvoření zprávy opraveny nebo smazány.</p>
<footer>TrainerApp · <?= h($report['kind'] ?? 'Zpráva') ?> #<?= $report['id'] ?> · algoritmus <?= h($report['version']) ?></footer>
</main><script>document.querySelectorAll('details').forEach(element => { element.open = true; });</script>
</body></html>
