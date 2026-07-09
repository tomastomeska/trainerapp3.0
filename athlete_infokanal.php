<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)(getCurrentAthleteId() ?? 0);
$pdo = getDB();

$selectedCategoryId = intParam($_GET, 'category');
$selectedArticleId = intParam($_GET, 'article');

if ($selectedArticleId > 0) {
    $visibleStmt = $pdo->prepare(
        "SELECT ia.id, ic.id AS category_id
         FROM info_articles ia
         JOIN info_categories ic ON ic.id = ia.category_id
         WHERE ia.id = ?
           AND ia.is_active = 1
           AND ia.published_at <= NOW()
           AND ia.target_audience IN ('all', 'athlete')
           AND ic.is_active = 1
           AND ic.audience IN ('all', 'athlete')
         LIMIT 1"
    );
    $visibleStmt->execute([$selectedArticleId]);
    $visible = $visibleStmt->fetch();

    if ($visible) {
        $pdo->prepare(
            'INSERT INTO info_article_reads_athlete (article_id, athlete_id, read_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
        )->execute([(int)$visible['id'], $athleteId]);

        if ($selectedCategoryId <= 0) {
            $selectedCategoryId = (int)$visible['category_id'];
        }
    }
}

$articlesStmt = $pdo->prepare(
    "SELECT
        ia.id,
        ia.title,
        ia.body,
        ia.published_at,
        ic.id AS category_id,
        ic.name AS category_name,
        ic.sort_order,
        ir.read_at
     FROM info_articles ia
     JOIN info_categories ic ON ic.id = ia.category_id
     LEFT JOIN info_article_reads_athlete ir ON ir.article_id = ia.id AND ir.athlete_id = ?
     WHERE ia.is_active = 1
       AND ia.published_at <= NOW()
       AND ia.target_audience IN ('all', 'athlete')
       AND ic.is_active = 1
       AND ic.audience IN ('all', 'athlete')
     ORDER BY ic.sort_order ASC, ic.name ASC, ia.published_at DESC, ia.id DESC"
);
$articlesStmt->execute([$athleteId]);
$rows = $articlesStmt->fetchAll();

$categories = [];
$articlesByCategory = [];
$unreadByCategory = [];
$selectedArticle = null;

foreach ($rows as $row) {
    $cid = (int)$row['category_id'];
    if (!isset($categories[$cid])) {
        $categories[$cid] = [
            'id' => $cid,
            'name' => (string)$row['category_name'],
            'sort_order' => (int)$row['sort_order'],
        ];
        $articlesByCategory[$cid] = [];
        $unreadByCategory[$cid] = 0;
    }

    $articlesByCategory[$cid][] = $row;
    if (empty($row['read_at'])) {
        $unreadByCategory[$cid]++;
    }
    if ((int)$row['id'] === $selectedArticleId) {
        $selectedArticle = $row;
    }
}

if ($selectedCategoryId <= 0 && !empty($categories)) {
    $firstCategory = reset($categories);
    $selectedCategoryId = (int)$firstCategory['id'];
}

renderAthleteHeader('Infokanal');
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <h2 class="mb-0"><i class="fas fa-lightbulb me-2 text-warning"></i>Infokanál</h2>
    <span class="text-muted small">Tipy, novinky a důležité informace pro sportovce</span>
</div>

<?php if (empty($categories)): ?>
<div class="alert alert-info">
    V Infokanálu zatím nejsou žádné publikované příspěvky.
</div>
<?php else: ?>

<ul class="nav nav-pills mb-3 flex-wrap gap-2">
    <?php foreach ($categories as $category): ?>
    <?php $cid = (int)$category['id']; ?>
    <li class="nav-item">
        <a class="nav-link <?= $selectedCategoryId === $cid ? 'active' : '' ?>"
           href="<?= BASE_URL ?>/athlete_infokanal.php?category=<?= $cid ?>">
            <?= h($category['name']) ?>
            <?php if (($unreadByCategory[$cid] ?? 0) > 0): ?>
            <span class="badge rounded-pill bg-danger ms-1"><?= (int)$unreadByCategory[$cid] ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white fw-semibold">
                <i class="fas fa-list me-2 text-warning"></i>Články v kategorii
            </div>
            <div class="list-group list-group-flush">
                <?php foreach (($articlesByCategory[$selectedCategoryId] ?? []) as $article): ?>
                <?php
                $isActive = (int)$article['id'] === (int)($selectedArticle['id'] ?? 0);
                $isUnread = empty($article['read_at']);
                ?>
                <a href="<?= BASE_URL ?>/athlete_infokanal.php?category=<?= (int)$article['category_id'] ?>&article=<?= (int)$article['id'] ?>"
                   class="list-group-item list-group-item-action <?= $isActive ? 'active' : '' ?>">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold"><?= h((string)$article['title']) ?></div>
                            <div class="small <?= $isActive ? 'text-white-50' : 'text-muted' ?>">
                                <?= formatDateTime((string)$article['published_at']) ?>
                            </div>
                        </div>
                        <?php if ($isUnread): ?>
                        <span class="badge rounded-pill bg-danger">Nové</span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <?php if ($selectedArticle): ?>
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-dark text-white">
                <div class="small text-white-50 mb-1">
                    <i class="fas fa-folder-open me-1 text-warning"></i><?= h((string)$selectedArticle['category_name']) ?>
                </div>
                <h5 class="mb-0"><?= h((string)$selectedArticle['title']) ?></h5>
            </div>
            <div class="card-body">
                <div class="small text-muted mb-3">
                    Publikováno: <?= formatDateTime((string)$selectedArticle['published_at']) ?>
                </div>
                <div style="white-space:pre-wrap;line-height:1.55;">
                    <?= h((string)$selectedArticle['body']) ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-secondary mb-0">Vyberte článek vlevo.</div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php renderAthleteFooter(); ?>
