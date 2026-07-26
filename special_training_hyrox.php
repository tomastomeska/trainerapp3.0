<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

if (!function_exists('coachSpecialTrainingUnlocked')) {
    function coachSpecialTrainingUnlocked(PDO $pdo, int $coachId): bool {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'special_training_enabled'");
            if ($columnStmt === false || !$columnStmt->fetch()) {
                return false;
            }

            $valueStmt = $pdo->prepare('SELECT special_training_enabled FROM coaches WHERE id = ? LIMIT 1');
            $valueStmt->execute([$coachId]);
            return ((int)$valueStmt->fetchColumn()) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}

$pdo = getDB();
$coachId = (int)getCurrentCoachId();
if (!coachSpecialTrainingUnlocked($pdo, $coachId)) {
    flash('warning', 'Events jsou pro váš účet zatím uzamčené.');
    redirect(BASE_URL . '/dashboard.php');
}

renderHeader('Events - Hyrox', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-bolt me-2 text-warning"></i>Hyrox <span class="badge rounded-pill text-bg-secondary align-middle ms-1">Ve vývoji</span></h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/special_training.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Zpět na eventy
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body py-5 text-center">
        <h3 class="fw-bold mb-3">Ve vývoji</h3>
        <p class="text-muted mb-0">
            Sekce Hyrox bude sloužit pro evidenci special tréninků, výkonů a závodních příprav.
        </p>
    </div>
</div>

<?php renderFooter();
