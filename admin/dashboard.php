<?php
// admin/dashboard.php – přehled superadministrátora
require_once __DIR__ . '/../includes/admin_auth.php';

requireAdminLogin();

require_once __DIR__ . '/header.php';

$pdo = getDB();

// Statistiky
$totalCoaches  = (int)$pdo->query('SELECT COUNT(*) FROM coaches')->fetchColumn();
$activeCoaches = (int)$pdo->query('SELECT COUNT(*) FROM coaches WHERE is_active = 1')->fetchColumn();
$totalAthletes = (int)$pdo->query('SELECT COUNT(*) FROM athletes')->fetchColumn();
$totalSessions = (int)$pdo->query('SELECT COUNT(*) FROM training_sessions WHERE completed_at IS NOT NULL')->fetchColumn();
$totalGlobalExercises = (int)$pdo->query('SELECT COUNT(*) FROM exercises WHERE is_global = 1')->fetchColumn();
$totalGlobalMeals = (int)$pdo->query('SELECT COUNT(*) FROM global_meals')->fetchColumn();
$totalAdminVenues = (int)$pdo->query('SELECT COUNT(*) FROM training_venues WHERE created_by_coach_id IS NULL')->fetchColumn();
$totalCoachExercises = (int)$pdo->query('SELECT COUNT(*) FROM exercises WHERE coach_id IS NOT NULL')->fetchColumn();
$totalCoachMeals = (int)$pdo->query('SELECT COUNT(*) FROM coach_meals WHERE coach_id IS NOT NULL AND global_meal_id IS NULL')->fetchColumn();
$totalCoachSets = (int)$pdo->query('SELECT COUNT(*) FROM workout_sets WHERE coach_id IS NOT NULL')->fetchColumn();

// Posledních 5 přidaných trenérů
$recentCoaches = $pdo->query(
    'SELECT c.*, COUNT(DISTINCT a.id) AS athlete_count,
            COUNT(DISTINCT ts.id) AS session_count
     FROM coaches c
     LEFT JOIN athletes a ON a.coach_id = c.id
     LEFT JOIN training_sessions ts ON ts.athlete_id = a.id AND ts.completed_at IS NOT NULL
     GROUP BY c.id
     ORDER BY c.created_at DESC
     LIMIT 5'
)->fetchAll();

renderAdminHeader('Přehled');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-gauge-high me-2" style="color:#a78bfa"></i>Přehled systému
    </h4>
    <a href="<?= BASE_URL ?>/admin/coach_add.php" class="btn btn-sm fw-bold"
       style="background:#7c3aed;color:#fff;border:none">
        <i class="fas fa-plus me-1"></i>Přidat trenéra
    </a>
</div>

<!-- Statistiky -->
<style>
    .admin-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: .75rem;
    }

    .admin-stat-card {
        border-radius: 12px;
        min-height: 108px;
        padding: .9rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
    }

    .admin-stat-card__value {
        font-size: 2.15rem;
        font-weight: 800;
        line-height: 1.05;
    }

    .admin-stat-card__label {
        margin-top: .35rem;
    }
</style>

<div class="admin-stats-grid mb-4">
    <div class="card border-0 shadow-sm admin-stat-card">
        <div class="admin-stat-card__value" style="color:#7c3aed"><?= $totalCoaches ?></div>
        <div class="text-muted small admin-stat-card__label">Trenérů celkem</div>
    </div>
    <div class="card border-0 shadow-sm admin-stat-card">
        <div class="admin-stat-card__value text-success"><?= $activeCoaches ?></div>
        <div class="text-muted small admin-stat-card__label">Aktivních trenérů</div>
    </div>
    <div class="card border-0 shadow-sm admin-stat-card">
        <div class="admin-stat-card__value text-warning"><?= $totalAthletes ?></div>
        <div class="text-muted small admin-stat-card__label">Sportovců celkem</div>
    </div>
    <div class="card border-0 shadow-sm admin-stat-card">
        <div class="admin-stat-card__value text-info"><?= $totalSessions ?></div>
        <div class="text-muted small admin-stat-card__label">Tréninků celkem</div>
    </div>
    <div class="card border-0 shadow-sm admin-stat-card">
        <div class="admin-stat-card__value" style="color:#6d28d9"><?= $totalGlobalExercises ?></div>
        <div class="text-muted small admin-stat-card__label">Globálních cviků</div>
    </div>
    <div class="card border-0 shadow-sm admin-stat-card">
        <div class="admin-stat-card__value" style="color:#0f766e"><?= $totalGlobalMeals ?></div>
        <div class="text-muted small admin-stat-card__label">Globálních jídel</div>
    </div>
    <div class="card border-0 shadow-sm admin-stat-card">
        <div class="admin-stat-card__value" style="color:#b45309"><?= $totalAdminVenues ?></div>
        <div class="text-muted small admin-stat-card__label">Sportovišť od administrátora</div>
    </div>
    <div class="card border-0 shadow-sm admin-stat-card">
        <div class="admin-stat-card__value" style="color:#1d4ed8"><?= $totalCoachExercises ?></div>
        <div class="text-muted small admin-stat-card__label">Cviků přidaných trenéry</div>
    </div>
    <div class="card border-0 shadow-sm admin-stat-card">
        <div class="admin-stat-card__value" style="color:#be185d"><?= $totalCoachMeals ?></div>
        <div class="text-muted small admin-stat-card__label">Jídel přidaných trenéry</div>
    </div>
    <div class="card border-0 shadow-sm admin-stat-card">
        <div class="admin-stat-card__value" style="color:#0f4c81"><?= $totalCoachSets ?></div>
        <div class="text-muted small admin-stat-card__label">Sad vytvořených trenéry</div>
    </div>
</div>

<!-- Poslední trenéři -->
<div class="card border-0 shadow-sm">
    <div class="card-header fw-bold d-flex justify-content-between align-items-center"
         style="background:#1e1e2e;color:#fff">
        <span><i class="fas fa-user-tie me-2"></i>Naposledy přidaní trenéři</span>
        <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-sm btn-outline-secondary">
            Zobrazit vše
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentCoaches)): ?>
        <div class="text-center py-4 text-muted">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>Zatím žádní trenéři.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Trenér</th>
                        <th>Username</th>
                        <th class="text-center">Sportovci</th>
                        <th class="text-center">Tréninky</th>
                        <th class="text-center">Stav</th>
                        <th>Přidán</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentCoaches as $c): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($c['name'] ?: '–') ?></td>
                        <td><code><?= h($c['username']) ?></code></td>
                        <td class="text-center">
                            <span class="badge bg-warning text-dark"><?= $c['athlete_count'] ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info text-dark"><?= $c['session_count'] ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($c['is_active']): ?>
                            <span class="badge bg-success">Aktivní</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Neaktivní</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= formatDate($c['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderAdminFooter(); ?>
