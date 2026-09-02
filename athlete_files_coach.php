<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = (int)getCurrentCoachId();
$athleteId = intParam($_GET, 'athlete_id');
$pdo = getDB();

$athleteStmt = $pdo->prepare('SELECT id, first_name, last_name FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
$athleteStmt->execute([$athleteId, $coachId]);
$athlete = $athleteStmt->fetch();
if (!$athlete) {
    flash('danger', 'Sportovec nebyl nalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$categories = ['medical' => 'Zdravotní dokumentace', 'body_measurements' => 'Tělesná měření', 'sports_tests' => 'Sportovní testy', 'laboratory_results' => 'Laboratorní výsledky', 'nutrition' => 'Výživová dokumentace', 'training' => 'Tréninková dokumentace', 'physiotherapy' => 'Fyzioterapie a rehabilitace', 'injuries' => 'Úrazy a omezení', 'competition' => 'Závodní dokumentace', 'other' => 'Ostatní'];
$pdo->prepare('UPDATE athlete_files SET coach_viewed_at = NOW() WHERE athlete_id = ? AND coach_id = ? AND shared_with_coach = 1 AND coach_viewed_at IS NULL')
    ->execute([$athleteId, $coachId]);
$filesStmt = $pdo->prepare("SELECT * FROM athlete_files WHERE athlete_id = ? AND coach_id = ? AND shared_with_coach = 1 ORDER BY FIELD(document_category, 'medical', 'body_measurements', 'sports_tests', 'laboratory_results', 'nutrition', 'training', 'physiotherapy', 'injuries', 'competition', 'other'), created_at DESC");
$filesStmt->execute([$athleteId, $coachId]);
$files = $filesStmt->fetchAll();
$fileGroups = [];
foreach ($files as $file) {
    $groupKey = !empty($file['upload_batch']) ? 'batch_' . $file['upload_batch'] : 'file_' . $file['id'];
    if (!isset($fileGroups[$groupKey])) {
        $fileGroups[$groupKey] = ['primary' => $file, 'files' => []];
    }
    $fileGroups[$groupKey]['files'][] = $file;
}

renderHeader('Soubory sportovce');
?>
<div class="d-flex align-items-center mb-4 gap-3"><a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $athleteId ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i></a><h2 class="mb-0"><i class="fas fa-folder-open me-2 text-primary"></i>Soubory: <?= h($athlete['first_name'] . ' ' . $athlete['last_name']) ?></h2></div>
<?php if (empty($files)): ?>
<div class="alert alert-light border text-center py-4">Sportovec vám zatím nezpřístupnil žádné soubory.</div>
<?php else: ?>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Soubor</th><th>Typ</th><th>Nahráno</th><th class="text-end">Stažení</th></tr></thead><tbody><?php foreach ($fileGroups as $group): $file = $group['primary']; ?><tr><td><i class="fas fa-file me-2 text-primary"></i><strong><?= h($file['display_name'] ?: $file['original_name']) ?></strong><?php if (count($group['files']) > 1): ?><span class="badge bg-secondary ms-1"><?= count($group['files']) ?> souborů</span><?php endif; ?><?php foreach ($group['files'] as $groupFile): ?><small class="text-muted d-block ms-4"><?= h($groupFile['original_name']) ?> (<?= round((int)$groupFile['file_size'] / 1024, 1) ?> KB) <a href="<?= BASE_URL ?>/athlete_file_download.php?id=<?= (int)$groupFile['id'] ?>">Stáhnout</a></small><?php endforeach; ?></td><td><?= h($categories[$file['document_category']] ?? $categories['other']) ?></td><td><?= h(date('d.m.Y H:i', strtotime($file['created_at']))) ?></td><td class="text-end"><span class="text-muted small">Jednotlivě</span></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php renderFooter(); ?>