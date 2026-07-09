<?php
// admin/coaches.php – správa trenérů
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

// Rychlý toggle aktivity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/coaches.php');
    }
    $cid = intParam($_POST, 'coach_id');
    $pdo->prepare('UPDATE coaches SET is_active = 1 - is_active WHERE id = ?')->execute([$cid]);
    flash('success', 'Stav trenéra byl změněn.');
    redirect(BASE_URL . '/admin/coaches.php');
}

// Všichni trenéři se statistikami
$coaches = $pdo->query(
    'SELECT c.*,
                        (SELECT COUNT(*) FROM athletes a WHERE a.coach_id = c.id) AS athlete_count,
                        (SELECT COUNT(*) FROM exercises e WHERE e.coach_id = c.id) AS exercise_count,
                        (SELECT COUNT(*)
                         FROM training_sessions ts
                         JOIN athletes a2 ON a2.id = ts.athlete_id
                         WHERE a2.coach_id = c.id
                             AND ts.completed_at IS NOT NULL
                             AND ts.deleted_by_coach_at IS NULL) AS session_count,
                        (SELECT COUNT(*)
                         FROM training_sessions ts
                         JOIN athletes a3 ON a3.id = ts.athlete_id
                         WHERE a3.coach_id = c.id
                             AND ts.deleted_by_coach_at IS NOT NULL) AS deleted_session_count
         FROM coaches c
     ORDER BY c.last_login DESC, c.created_at DESC'
)->fetchAll();

renderAdminHeader('Trenéři');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-user-tie me-2" style="color:#a78bfa"></i>Správa trenérů
        <span class="badge ms-2" style="background:#312e81"><?= count($coaches) ?></span>
    </h4>
    <a href="<?= BASE_URL ?>/admin/coach_add.php" class="btn fw-bold"
       style="background:#7c3aed;color:#fff;border:none">
        <i class="fas fa-plus me-1"></i>Přidat trenéra
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($coaches)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-user-slash fa-3x mb-3 d-block"></i>
            Zatím žádní trenéři. <a href="<?= BASE_URL ?>/admin/coach_add.php">Přidat prvního trenéra.</a>
        </div>
        <?php else: ?>
        <div class="coaches-grid">
            <?php foreach ($coaches as $i => $c): ?>
            <article class="coach-card <?= $c['is_active'] ? '' : 'coach-card--inactive' ?>">
                <div class="coach-card__head">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-bold fs-5"><?= h($c['name'] ?: 'Bez jména') ?></div>
                            <div class="small text-muted">
                                #<?= $i + 1 ?> · @<?= h($c['username']) ?>
                            </div>
                        </div>
                        <?php if ($c['is_active']): ?>
                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Aktivní</span>
                        <?php else: ?>
                        <span class="badge bg-secondary"><i class="fas fa-ban me-1"></i>Blokován</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="coach-card__meta">
                    <div class="coach-stat-row">
                        <div class="coach-stat">
                            <div class="coach-stat__value text-warning">
                                <a href="<?= BASE_URL ?>/admin/coach_athletes.php?coach_id=<?= (int)$c['id'] ?>"
                                   class="text-warning text-decoration-none"
                                   title="Zobrazit sportovce trenéra">
                                    <?= (int)$c['athlete_count'] ?>
                                </a>
                            </div>
                            <div class="coach-stat__label">
                                <a href="<?= BASE_URL ?>/admin/coach_athletes.php?coach_id=<?= (int)$c['id'] ?>"
                                   class="text-reset text-decoration-none"
                                   title="Zobrazit sportovce trenéra">Sportovci</a>
                            </div>
                        </div>
                        <div class="coach-stat">
                            <div class="coach-stat__value text-primary"><?= (int)$c['exercise_count'] ?></div>
                            <div class="coach-stat__label">Cviky</div>
                        </div>
                        <div class="coach-stat">
                            <div class="coach-stat__value text-info"><?= (int)$c['session_count'] ?></div>
                            <div class="coach-stat__label">Tréninky</div>
                        </div>
                        <div class="coach-stat">
                            <div class="coach-stat__value text-danger"><?= (int)$c['deleted_session_count'] ?></div>
                            <div class="coach-stat__label">Smazané</div>
                        </div>
                    </div>

                    <div class="small mb-1">
                        <i class="fas fa-envelope me-1 text-muted"></i>
                        <?php if (!empty($c['email'])): ?>
                        <a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a>
                        <?php else: ?>
                        <span class="text-muted">Bez e-mailu</span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted">Poslední přihlášení: <?= $c['last_login'] ? formatDateTime($c['last_login']) : 'Nikdy' ?></div>
                    <div class="small text-muted">Přidán: <?= formatDate($c['created_at']) ?></div>
                    <div class="small mt-2">
                        <a href="<?= BASE_URL ?>/admin/coach_deleted_trainings.php?coach_id=<?= (int)$c['id'] ?>"
                           class="btn btn-outline-danger btn-sm" title="Smazané tréninky">
                            <i class="fas fa-folder-open me-1"></i>Zobrazit smazané tréninky
                        </a>
                    </div>
                </div>

                <div class="coach-card__actions">
                    <?php if ($c['is_active']): ?>
                    <form method="post" action="<?= BASE_URL ?>/admin/impersonate.php">
                        <?= csrfField() ?>
                        <input type="hidden" name="coach_id" value="<?= $c['id'] ?>">
                        <button type="submit"
                                class="btn btn-sm btn-outline-primary"
                                title="Přepnout do profilu trenéra"
                                onclick="return confirm('Přepnout do profilu trenéra <?= h(addslashes($c['name'] ?: $c['username'])) ?>?')">
                            <i class="fas fa-user-secret"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/admin/coach_edit.php?id=<?= $c['id'] ?>"
                       class="btn btn-outline-secondary btn-sm" title="Upravit">
                        <i class="fas fa-edit"></i>
                    </a>
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="coach_id" value="<?= $c['id'] ?>">
                        <button type="submit"
                                class="btn btn-sm <?= $c['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                title="<?= $c['is_active'] ? 'Blokovat' : 'Aktivovat' ?>">
                            <i class="fas fa-<?= $c['is_active'] ? 'ban' : 'check' ?>"></i>
                        </button>
                    </form>
                    <a href="<?= BASE_URL ?>/admin/coach_delete.php?id=<?= $c['id'] ?>"
                       class="btn btn-outline-danger btn-sm" title="Smazat">
                        <i class="fas fa-trash"></i>
                    </a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php renderAdminFooter(); ?>
