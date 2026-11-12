<?php
// admin/coaches.php - sprava treneru
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/coach_account_lifecycle.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
ensurePasswordAuditColumns($pdo);
coachAccountEnsureSchema($pdo);

// Blokace nebo odblokování trenéra
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect(BASE_URL . '/admin/coaches.php');
    }
    $cid = intParam($_POST, 'coach_id');
    $coachStmt = $pdo->prepare('SELECT id, name, username, email, is_active FROM coaches WHERE id = ?');
    $coachStmt->execute([$cid]);
    $targetCoach = $coachStmt->fetch();
    if (!$targetCoach) {
        flash('danger', 'Trenér nebyl nalezen.');
        redirect(BASE_URL . '/admin/coaches.php');
    }

    if ((int)$targetCoach['is_active'] === 1) {
        $reason = (string)($_POST['block_reason'] ?? '');
        if ($reason !== 'terms_violation') {
            flash('danger', 'Vyberte důvod blokace trenéra.');
            redirect(BASE_URL . '/admin/coaches.php');
        }

        $updateStmt = $pdo->prepare(
            'UPDATE coaches
             SET is_active = 0, block_reason = ?,
                 inactivity_deletion_notified_at = NULL, inactivity_deletion_due_at = NULL
             WHERE id = ? AND is_active = 1'
        );
        $updateStmt->execute([$reason, $cid]);
        if ($updateStmt->rowCount() !== 1) {
            flash('warning', 'Stav trenéra se mezitím změnil. Obnovte seznam a akci opakujte.');
            redirect(BASE_URL . '/admin/coaches.php');
        }
        $coachName = (string)($targetCoach['name'] ?: $targetCoach['username']);
        $emailSent = coachAccountSendBlockNotification(
            (string)($targetCoach['email'] ?? ''),
            $coachName,
            $reason
        );
        flash(
            $emailSent ? 'success' : 'warning',
            $emailSent
                ? 'Trenér byl zablokován a informační e-mail byl odeslán.'
                : 'Trenér byl zablokován, ale informační e-mail se nepodařilo odeslat. Zkontrolujte e-mail a nastavení SMTP.'
        );
    } else {
        $unblockStmt = $pdo->prepare('UPDATE coaches SET is_active = 1, block_reason = NULL WHERE id = ? AND is_active = 0');
        $unblockStmt->execute([$cid]);
        flash(
            $unblockStmt->rowCount() === 1 ? 'success' : 'warning',
            $unblockStmt->rowCount() === 1
                ? 'Trenér byl odblokován.'
                : 'Stav trenéra se mezitím změnil. Obnovte seznam a akci opakujte.'
        );
    }
    redirect(BASE_URL . '/admin/coaches.php');
}

// Všichni trenéři se statistikami (razeni dle posledniho prihlaseni)
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
$deletionNoticeCount = count(array_filter(
    $coaches,
    static fn(array $coach): bool => !empty($coach['inactivity_deletion_due_at'])
));
$showDeletionNoticeOnly = (string)($_GET['filter'] ?? '') === 'deletion_notice';
if ($showDeletionNoticeOnly) {
    $coaches = array_values(array_filter(
        $coaches,
        static fn(array $coach): bool => !empty($coach['inactivity_deletion_due_at'])
    ));
}

renderAdminHeader('Trenéři');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-user-tie me-2" style="color:#a78bfa"></i>Správa trenérů
        <span class="badge ms-2" style="background:#312e81"><?= count($coaches) ?></span>
    </h4>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($showDeletionNoticeOnly): ?>
        <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary">
            <i class="fas fa-list me-1"></i>Zobrazit všechny
        </a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>/admin/coaches.php?filter=deletion_notice" class="btn btn-outline-warning">
            <i class="fas fa-hourglass-half me-1"></i>Ve výpovědní lhůtě (<?= $deletionNoticeCount ?>)
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/coach_account_migration.php" class="btn btn-outline-primary">
            <i class="fas fa-database me-1"></i>Spustit migraci účtů
        </a>
        <a href="<?= BASE_URL ?>/admin/coach_add.php" class="btn fw-bold" style="background:#7c3aed;color:#fff;border:none">
            <i class="fas fa-plus me-1"></i>Přidat trenéra
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($coaches)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-user-slash fa-3x mb-3 d-block"></i>
            <?php if ($showDeletionNoticeOnly): ?>
            Žádní trenéři nejsou právě ve výpovědní lhůtě.
            <?php else: ?>
            Zatím žádní trenéři. <a href="<?= BASE_URL ?>/admin/coach_add.php">Přidat prvního trenéra.</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive d-none d-sm-block">
            <table class="table table-hover align-middle mb-0 no-mobile-stack">
                <thead class="table-light">
                <tr>
                    <th>Trenér</th>
                    <th>E-mail</th>
                    <th>Poslední přihlášení</th>
                    <th class="text-center">Sport.</th>
                    <th class="text-center">Cviky</th>
                    <th class="text-center">Trén.</th>
                    <th class="text-center">Smaz.</th>
                    <th>Heslo</th>
                    <th>Stav</th>
                    <th class="text-end">Detail</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($coaches as $i => $c): ?>
                <?php $detailId = 'coachDetail' . (int)$c['id']; ?>
                <tr class="<?= $c['is_active'] ? '' : 'table-secondary' ?>">
                    <td>
                        <div class="fw-semibold"><?= h($c['name'] ?: 'Bez jména') ?></div>
                        <div class="small text-muted">#<?= $i + 1 ?> · @<?= h($c['username']) ?></div>
                    </td>
                    <td>
                        <?php if (!empty($c['email'])): ?>
                        <a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a>
                        <?php else: ?>
                        <span class="text-muted">Bez e-mailu</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap"><?= $c['last_login'] ? formatDateTime($c['last_login']) : 'Nikdy' ?></td>
                    <td class="text-center">
                        <a href="<?= BASE_URL ?>/admin/athletes.php?coach_id=<?= (int)$c['id'] ?>" class="badge text-bg-warning text-decoration-none" title="Zobrazit sportovce trenéra"><?= (int)$c['athlete_count'] ?></a>
                    </td>
                    <td class="text-center"><span class="badge text-bg-primary"><?= (int)$c['exercise_count'] ?></span></td>
                    <td class="text-center"><span class="badge text-bg-info"><?= (int)$c['session_count'] ?></span></td>
                    <td class="text-center"><span class="badge text-bg-danger"><?= (int)$c['deleted_session_count'] ?></span></td>
                    <td>
                        <div class="small fw-semibold">
                            <?php if (!empty($c['password'])): ?>
                                <span class="badge bg-success">Nastaveno</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Bez hesla</span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted">
                            <?= !empty($c['password_changed_at']) ? 'Změna: ' . h(formatDateTime((string)$c['password_changed_at'])) : 'Změna: ' . h(formatDate($c['created_at'])) ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($c['is_active']): ?>
                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Aktivní</span>
                        <?php if (!empty($c['inactivity_deletion_due_at'])): ?>
                        <span class="badge bg-warning text-dark d-block mt-1">Výpovědní lhůta do <?= h(formatDateTime((string)$c['inactivity_deletion_due_at'])) ?></span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="badge bg-secondary"><i class="fas fa-ban me-1"></i>Blokován</span>
                        <?php if (($c['block_reason'] ?? '') === 'terms_violation'): ?>
                        <span class="small text-muted d-block">Porušení všeobecných podmínek</span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $detailId ?>" aria-expanded="false" aria-controls="<?= $detailId ?>">
                            <i class="fas fa-chevron-down me-1"></i>Rozbalit
                        </button>
                    </td>
                </tr>
                <tr>
                    <td colspan="10" class="p-0 border-0">
                        <div id="<?= $detailId ?>" class="collapse px-3 pt-2 pb-3 bg-body-tertiary border-top">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="small text-muted">Přidán: <?= formatDate($c['created_at']) ?></div>
                                <a href="<?= BASE_URL ?>/admin/coach_deleted_trainings.php?coach_id=<?= (int)$c['id'] ?>" class="btn btn-outline-danger btn-sm" title="Smazané tréninky">
                                    <i class="fas fa-folder-open me-1"></i>Smazané tréninky
                                </a>
                            </div>
                            <div class="small text-muted mt-2">
                                <strong>Heslo:</strong> <?= !empty($c['password']) ? 'nastaveno' : 'bez hesla' ?> ·
                                <strong>Poslední změna:</strong> <?= !empty($c['password_changed_at']) ? formatDateTime((string)$c['password_changed_at']) : formatDate($c['created_at']) ?>
                            </div>
                            <div class="d-flex flex-wrap align-items-start gap-2 mt-2 coach-detail-actions">
                                <?php if ($c['is_active']): ?>
                                <form method="post" action="<?= BASE_URL ?>/admin/impersonate.php">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="coach_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Přepnout do profilu trenéra" onclick="return confirm('Přepnout do profilu trenéra <?= h(addslashes($c['name'] ?: $c['username'])) ?>?')">
                                        <i class="fas fa-user-secret me-1"></i>Přepnout profil
                                    </button>
                                </form>
                                <?php endif; ?>
                                <a href="<?= BASE_URL ?>/admin/coach_edit.php?id=<?= $c['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Upravit">
                                    <i class="fas fa-edit me-1"></i>Upravit
                                </a>
                                <form method="post" class="coach-block-form<?= $c['is_active'] ? ' coach-block-form--active' : '' ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="coach_id" value="<?= $c['id'] ?>">
                                    <?php if ($c['is_active']): ?>
                                    <label class="small text-muted d-block mb-1" for="blockReason<?= (int)$c['id'] ?>">Důvod blokace</label>
                                    <select class="form-select form-select-sm mb-1" name="block_reason" id="blockReason<?= (int)$c['id'] ?>" required>
                                        <option value="">Vyberte důvod</option>
                                        <option value="terms_violation">Porušení všeobecných podmínek</option>
                                    </select>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-sm <?= $c['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $c['is_active'] ? 'Blokovat' : 'Odblokovat' ?>">
                                        <i class="fas fa-<?= $c['is_active'] ? 'ban' : 'check' ?> me-1"></i><?= $c['is_active'] ? 'Blokovat' : 'Odblokovat' ?>
                                    </button>
                                </form>
                                <a href="<?= BASE_URL ?>/admin/coach_delete.php?id=<?= $c['id'] ?>" class="btn btn-outline-danger btn-sm" title="Smazat">
                                    <i class="fas fa-trash me-1"></i>Smazat
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-sm-none p-2">
            <?php foreach ($coaches as $i => $c): ?>
            <?php $mobileDetailId = 'coachDetailMobile' . (int)$c['id']; ?>
            <article class="coach-mobile-card <?= $c['is_active'] ? '' : 'coach-mobile-card--inactive' ?>">
                <div class="coach-mobile-card__head">
                    <div>
                        <div class="coach-mobile-card__name"><?= h($c['name'] ?: 'Bez jména') ?></div>
                        <div class="coach-mobile-card__meta">#<?= $i + 1 ?> · @<?= h($c['username']) ?></div>
                    </div>
                    <?php if ($c['is_active']): ?>
                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>Aktivní</span>
                    <?php if (!empty($c['inactivity_deletion_due_at'])): ?>
                    <span class="badge bg-warning text-dark">Výpovědní lhůta do <?= h(formatDateTime((string)$c['inactivity_deletion_due_at'])) ?></span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="badge bg-secondary"><i class="fas fa-ban me-1"></i>Blokován</span>
                    <?php if (($c['block_reason'] ?? '') === 'terms_violation'): ?>
                    <span class="small text-muted">Porušení všeobecných podmínek</span>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="coach-mobile-card__stats">
                    <div class="coach-mobile-stat">
                        <span class="coach-mobile-stat__label">Sport.</span>
                        <a href="<?= BASE_URL ?>/admin/coach_athletes.php?coach_id=<?= (int)$c['id'] ?>" class="badge text-bg-warning text-decoration-none" title="Zobrazit sportovce trenéra"><?= (int)$c['athlete_count'] ?></a>
                    </div>
                    <div class="coach-mobile-stat">
                        <span class="coach-mobile-stat__label">Cviky</span>
                        <span class="badge text-bg-primary"><?= (int)$c['exercise_count'] ?></span>
                    </div>
                    <div class="coach-mobile-stat">
                        <span class="coach-mobile-stat__label">Trén.</span>
                        <span class="badge text-bg-info"><?= (int)$c['session_count'] ?></span>
                    </div>
                    <div class="coach-mobile-stat">
                        <span class="coach-mobile-stat__label">Smaz.</span>
                        <span class="badge text-bg-danger"><?= (int)$c['deleted_session_count'] ?></span>
                    </div>
                </div>

                <div class="coach-mobile-card__info">
                    <div class="coach-mobile-row">
                        <span class="coach-mobile-row__label">E-mail</span>
                        <?php if (!empty($c['email'])): ?>
                        <a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a>
                        <?php else: ?>
                        <span class="text-muted">Bez e-mailu</span>
                        <?php endif; ?>
                    </div>
                    <div class="coach-mobile-row">
                        <span class="coach-mobile-row__label">Poslední přihlášení</span>
                        <span><?= $c['last_login'] ? formatDateTime($c['last_login']) : 'Nikdy' ?></span>
                    </div>
                        <div class="coach-mobile-row">
                            <span class="coach-mobile-row__label">Heslo</span>
                            <span><?= !empty($c['password']) ? 'nastaveno' : 'bez hesla' ?></span>
                        </div>
                        <div class="coach-mobile-row">
                            <span class="coach-mobile-row__label">Poslední změna</span>
                            <span><?= !empty($c['password_changed_at']) ? formatDateTime($c['password_changed_at']) : formatDate($c['created_at']) ?></span>
                        </div>
                </div>

                <button class="btn btn-sm btn-outline-secondary w-100 mt-2" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $mobileDetailId ?>" aria-expanded="false" aria-controls="<?= $mobileDetailId ?>">
                    <i class="fas fa-chevron-down me-1"></i>Detail a akce
                </button>

                <div id="<?= $mobileDetailId ?>" class="collapse mt-2 pt-2 border-top">
                    <div class="small text-muted mb-2">Přidán: <?= formatDate($c['created_at']) ?></div>
                    <a href="<?= BASE_URL ?>/admin/coach_deleted_trainings.php?coach_id=<?= (int)$c['id'] ?>" class="btn btn-outline-danger btn-sm w-100 mb-2" title="Smazané tréninky">
                        <i class="fas fa-folder-open me-1"></i>Smazané tréninky
                    </a>

                    <div class="coach-mobile-card__actions">
                        <?php if ($c['is_active']): ?>
                        <form method="post" action="<?= BASE_URL ?>/admin/impersonate.php">
                            <?= csrfField() ?>
                            <input type="hidden" name="coach_id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Přepnout do profilu trenéra" onclick="return confirm('Přepnout do profilu trenéra <?= h(addslashes($c['name'] ?: $c['username'])) ?>?')">
                                <i class="fas fa-user-secret me-1"></i>Přepnout profil
                            </button>
                        </form>
                        <?php endif; ?>

                        <a href="<?= BASE_URL ?>/admin/coach_edit.php?id=<?= $c['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Upravit">
                            <i class="fas fa-edit me-1"></i>Upravit
                        </a>

                        <form method="post" class="coach-block-form<?= $c['is_active'] ? ' coach-block-form--active' : '' ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="coach_id" value="<?= $c['id'] ?>">
                            <?php if ($c['is_active']): ?>
                            <label class="small text-muted d-block mb-1" for="blockReasonMobile<?= (int)$c['id'] ?>">Důvod blokace</label>
                            <select class="form-select form-select-sm mb-1" name="block_reason" id="blockReasonMobile<?= (int)$c['id'] ?>" required>
                                <option value="">Vyberte důvod</option>
                                <option value="terms_violation">Porušení všeobecných podmínek</option>
                            </select>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-sm <?= $c['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $c['is_active'] ? 'Blokovat' : 'Odblokovat' ?>">
                                <i class="fas fa-<?= $c['is_active'] ? 'ban' : 'check' ?> me-1"></i><?= $c['is_active'] ? 'Blokovat' : 'Odblokovat' ?>
                            </button>
                        </form>

                        <a href="<?= BASE_URL ?>/admin/coach_delete.php?id=<?= $c['id'] ?>" class="btn btn-outline-danger btn-sm" title="Smazat">
                            <i class="fas fa-trash me-1"></i>Smazat
                        </a>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.coach-detail-actions {
    align-items: flex-start;
}

.coach-detail-actions > form {
    margin: 0;
}

.coach-block-form--active {
    width: 15rem;
    max-width: 100%;
}

.coach-block-form--active .form-select {
    width: 100%;
}

@media (max-width: 575.98px) {
    .coach-mobile-card {
        border: 1px solid #dde5ee;
        border-radius: 12px;
        background: #fff;
        padding: 0.75rem;
        margin-bottom: 0.7rem;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
    }

    .coach-mobile-card--inactive {
        background: #f8fafc;
    }

    .coach-mobile-card__head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.6rem;
        margin-bottom: 0.5rem;
    }

    .coach-mobile-card__name {
        font-weight: 700;
        font-size: 1rem;
        line-height: 1.2;
    }

    .coach-mobile-card__meta {
        font-size: 0.78rem;
        color: #64748b;
    }

    .coach-mobile-card__stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.35rem;
        padding: 0.45rem 0;
        margin-bottom: 0.45rem;
        border-top: 1px dashed #e2e8f0;
        border-bottom: 1px dashed #e2e8f0;
    }

    .coach-mobile-stat {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 0.2rem;
        min-width: 0;
    }

    .coach-mobile-stat__label {
        font-size: 0.67rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #64748b;
    }

    .coach-mobile-card__info {
        display: grid;
        gap: 0.42rem;
    }

    .coach-mobile-row {
        display: grid;
        gap: 0.08rem;
    }

    .coach-mobile-row__label {
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #64748b;
    }

    .coach-mobile-card__actions {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        gap: 0.5rem;
    }

    .coach-mobile-card__actions > form {
        margin: 0;
    }

    .coach-mobile-card__actions > .coach-block-form--active {
        display: grid;
        flex: 1 1 100%;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: end;
        gap: 0.35rem 0.5rem;
    }

    .coach-mobile-card__actions .coach-block-form--active label {
        grid-column: 1 / -1;
        margin: 0;
    }

    .coach-mobile-card__actions .coach-block-form--active .form-select {
        min-width: 0;
        margin: 0;
    }
}
</style>

<?php renderAdminFooter(); ?>
