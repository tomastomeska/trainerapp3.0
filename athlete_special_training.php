<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

if (!function_exists('athleteSpecialTrainingUnlocked')) {
    function athleteSpecialTrainingUnlocked(PDO $pdo, int $athleteId): bool {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM athletes LIKE 'special_training_enabled'");
            if ($columnStmt === false || !$columnStmt->fetch()) {
                return false;
            }

            $valueStmt = $pdo->prepare('SELECT special_training_enabled FROM athletes WHERE id = ? LIMIT 1');
            $valueStmt->execute([$athleteId]);
            return ((int)$valueStmt->fetchColumn()) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}

$pdo = getDB();
$athleteId = (int)getCurrentAthleteId();
if (!athleteSpecialTrainingUnlocked($pdo, $athleteId)) {
    flash('warning', 'Events jsou pro váš účet zatím uzamčené.');
    redirect(BASE_URL . '/athlete_dashboard.php');
}

$introText = trim(getAppSetting('events_intro_text_athlete', 'Vyber event a otevri jeho zalozky. Obsah eventu se nyni spravuje centralne v administraci.'));

renderAthleteHeader('Events', false, true);

$eventRows = loadSpecialEvents($pdo, 'athlete');
$eventTiles = [];
foreach ($eventRows as $eventRow) {
    $slug = (string)($eventRow['slug'] ?? '');
    $icon = (string)($eventRow['icon_class'] ?? 'fa-bolt');
    if (!preg_match('/^fa-[a-z0-9-]+$/', $icon)) {
        $icon = 'fa-bolt';
    }

    $tileImage = trim((string)($eventRow['tile_image'] ?? ''));

    $eventTiles[] = [
        'name' => (string)($eventRow['name'] ?? 'Event'),
        'icon' => $icon,
        'href' => BASE_URL . '/athlete_special_training_event.php?event=' . rawurlencode($slug),
        'status' => (string)($eventRow['badge_label'] ?? ''),
        'enabled' => true,
        'description' => (string)($eventRow['description'] ?? ''),
        'tile_image' => $tileImage !== '' ? photoUrl($tileImage, 'events') : '',
    ];
}

?>

<style>
    .special-event-tile {
        display: block;
        text-decoration: none;
        color: inherit;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
        background: #ffffff;
        overflow: hidden;
        height: 100%;
        transition: transform .18s ease, box-shadow .18s ease;
    }

    .special-event-tile__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: .75rem;
        padding: 1rem 1rem .65rem;
    }

    .special-event-tile__title {
        font-weight: 800;
        font-size: 1.05rem;
        line-height: 1.15;
        color: #0f172a;
        min-width: 0;
    }

    .special-event-tile__media {
        position: relative;
        height: 210px;
        background: #f8fafc;
        overflow: hidden;
        margin: 0 1rem 1rem;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
    }

    .special-event-tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 28px rgba(15, 23, 42, .12);
    }

    .special-event-tile.is-disabled {
        opacity: .72;
        pointer-events: none;
    }

    .special-event-tile__media img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        object-position: center;
        display: block;
        background: #ffffff;
    }

    .special-event-tile__fallback {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #f8fafc;
        font-size: 2rem;
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 45%, #334155 100%);
    }

    .special-event-tile__mark {
        width: 66px;
        height: 66px;
        border-radius: 50%;
        border: 3px solid #ffc107;
        background: #111827;
        color: #f8fafc;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.65rem;
    }

    .special-event-tile__status {
        align-self: flex-start;
        position: static;
        flex-shrink: 0;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-flag-checkered me-2 text-warning"></i>Events</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Domů
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <?php if ($introText !== ''): ?>
            <p class="text-muted mb-0"><?= h($introText) ?></p>
        <?php else: ?>
            <p class="text-muted mb-0">&nbsp;</p>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($eventTiles)): ?>
    <div class="alert alert-light border">Momentálně nejsou publikované žádné aktivní eventy.</div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($eventTiles as $tile): ?>
        <div class="col-12 col-md-6 col-xl-4">
            <a href="<?= h($tile['href']) ?>" class="special-event-tile<?= $tile['enabled'] ? '' : ' is-disabled' ?>">
                <div class="special-event-tile__head">
                    <div class="special-event-tile__title"><?= h($tile['name']) ?></div>
                    <?php if ($tile['status'] !== ''): ?>
                        <span class="badge <?= $tile['enabled'] ? 'bg-warning text-dark' : 'bg-secondary' ?> special-event-tile__status"><?= h($tile['status']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="special-event-tile__media">
                    <?php if ($tile['tile_image'] !== ''): ?>
                        <img src="<?= h($tile['tile_image']) ?>" alt="<?= h($tile['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="special-event-tile__fallback">
                            <span class="special-event-tile__mark"><i class="fas <?= h($tile['icon']) ?>"></i></span>
                        </div>
                    <?php endif; ?>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php renderAthleteFooter();
