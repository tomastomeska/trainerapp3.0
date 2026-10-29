<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/health_questionnaire.php';
require_once __DIR__ . '/../includes/medals.php';

requireAdminLogin();

$pdo = getDB();
healthQuestionnaireEnsureSchema($pdo);
$populationStats = athleteMedalPopulationStats($pdo);
$medals = athleteMedalDefinitions();
foreach ($medals as &$medal) {
    $medal['holder_count'] = $populationStats['medal_counts'][$medal['id']] ?? 0;
    $medal['holders'] = $populationStats['medal_holders'][$medal['id']] ?? [];
}
unset($medal);
$totalAwardedMedals = array_sum($populationStats['medal_counts']);

renderAdminHeader('Medaile');
?>
<div class="container-fluid py-4">
    <div class="mb-4">
        <h2><i class="fas fa-medal me-2 text-warning"></i>Medaile sportovců</h2>
        <p class="text-muted mb-3">Přehled ukazuje, kolik sportovců jednotlivá ocenění získalo. Započítávají se i dříve splněné podmínky.</p>
        <div class="d-flex flex-wrap gap-2">
            <span class="badge rounded-pill bg-dark px-3 py-2"><?= (int)$populationStats['total_athletes'] ?> sportovců celkem</span>
            <span class="badge rounded-pill bg-warning text-dark px-3 py-2"><?= count($medals) ?> medailí v sadě</span>
            <span class="badge rounded-pill bg-success px-3 py-2"><?= $totalAwardedMedals ?> získaných medailí celkem</span>
        </div>
    </div>
    <div class="medal-grid admin-medal-grid">
        <?php foreach ($medals as $medal): ?>
            <article class="admin-medal-item">
                <div class="medal-card <?= (int)$medal['holder_count'] > 0 ? 'is-earned' : 'is-locked' ?> admin-medal-card">
                    <img
                        class="medal-card__image"
                        src="<?= h(athleteMedalImageUrl((string)$medal['image'])) ?>"
                        alt="<?= h((string)$medal['title']) ?>"
                        loading="lazy"
                    >
                    <div class="medal-card__title"><?= h((string)$medal['title']) ?></div>
                    <div class="medal-card__date">Podmínka pro získání</div>
                    <div class="admin-medal-card__description"><?= h((string)$medal['description']) ?></div>
                    <div class="admin-medal-card__stats">
                        <?php if (!empty($medal['holders'])): ?>
                        <span
                            class="admin-medal-holder-tooltip"
                            tabindex="0"
                            aria-label="Zobrazit držitele medaile"
                            aria-describedby="medal-holders-<?= h((string)$medal['id']) ?>"
                        >
                            <strong><?= (int)$medal['holder_count'] ?></strong>
                            <span id="medal-holders-<?= h((string)$medal['id']) ?>" class="admin-medal-holder-tooltip__content" role="tooltip">
                                <?php foreach ($medal['holders'] as $holder): ?>
                                <span class="admin-medal-holder-tooltip__person">
                                    <?= h((string)$holder['name']) ?>
                                    <span>(<?= h((string)$holder['coach_name']) ?>)</span>
                                </span>
                                <?php endforeach; ?>
                            </span>
                        </span>
                        <?php else: ?>
                        <strong><?= (int)$medal['holder_count'] ?></strong>
                        <?php endif; ?>
                        <?php if ((int)$medal['holder_count'] === 1): ?>
                            sportovec získal
                        <?php elseif ((int)$medal['holder_count'] >= 2 && (int)$medal['holder_count'] <= 4): ?>
                            sportovci získali
                        <?php else: ?>
                            sportovců získalo
                        <?php endif; ?>
                        <?php if ((int)$populationStats['total_athletes'] > 0): ?>
                            <span class="admin-medal-card__share">
                                (<?= number_format((int)$medal['holder_count'] / (int)$populationStats['total_athletes'] * 100, 1, ',', ' ') ?> %)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
<?php renderAdminFooter(); ?>
