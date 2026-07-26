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

renderAthleteHeader('Events', false, true);

$eventTiles = [
    [
        'name' => 'Hyrox',
        'icon' => 'fa-bolt',
        'href' => BASE_URL . '/athlete_special_training_hyrox.php',
        'status' => 'První event',
        'enabled' => true,
    ],
    ['name' => 'Spartan', 'icon' => 'fa-mountain', 'href' => '#', 'status' => '', 'enabled' => false],
    ['name' => 'Maraton', 'icon' => 'fa-person-running', 'href' => '#', 'status' => '', 'enabled' => false],
    ['name' => 'Triatlon', 'icon' => 'fa-water', 'href' => '#', 'status' => '', 'enabled' => false],
    ['name' => 'Cross Duatlon', 'icon' => 'fa-road', 'href' => '#', 'status' => '', 'enabled' => false],
    ['name' => 'OCR', 'icon' => 'fa-dumbbell', 'href' => '#', 'status' => '', 'enabled' => false],
];
?>

<style>
    .special-event-tile {
        display: block;
        text-decoration: none;
        color: inherit;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
        padding: 1.25rem;
        height: 240px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: transform .18s ease, box-shadow .18s ease;
    }

    .special-event-tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 28px rgba(15, 23, 42, .12);
    }

    .special-event-tile.is-disabled {
        opacity: .72;
        pointer-events: none;
    }

    .special-event-tile__mark {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        border: 3px solid #ffc107;
        background: #111827;
        color: #f8fafc;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
    }

    .special-event-tile__name {
        font-weight: 800;
        font-size: 1.25rem;
        margin-top: .9rem;
        line-height: 1.2;
        min-height: 2.4em;
    }

    .special-event-tile p {
        margin-top: auto !important;
        display: -webkit-box;
        line-clamp: 2;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-flag-checkered me-2 text-warning"></i>Events <span class="badge rounded-pill text-bg-secondary align-middle ms-1">Ve vývoji</span></h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Domů
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-4 text-center">
        <h3 class="fw-bold mb-3">Ve vývoji</h3>
        <p class="text-muted mb-0">
            Zde naleznete speciální tréninky pro vaše eventy typu Hyrox, Spartan, Maraton a mnoho dalšího.
        </p>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($eventTiles as $tile): ?>
    <div class="col-12 col-md-6 col-xl-4">
        <a href="<?= h($tile['href']) ?>" class="special-event-tile<?= $tile['enabled'] ? '' : ' is-disabled' ?>">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <span class="special-event-tile__mark"><i class="fas <?= h($tile['icon']) ?>"></i></span>
                <?php if ($tile['status'] !== ''): ?>
                <span class="badge <?= $tile['enabled'] ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= h($tile['status']) ?></span>
                <?php endif; ?>
            </div>
            <div class="special-event-tile__name"><?= h($tile['name']) ?></div>
            <p class="text-muted mb-0 mt-2">Vstoupit do eventu a evidovat special tréninky.</p>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<?php renderAthleteFooter();
