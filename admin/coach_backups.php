<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/coach_account_lifecycle.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
coachAccountEnsureSchema($pdo);
$backups = $pdo->query(
    'SELECT id, coach_username, coach_email, deleted_at, expires_at
     FROM coach_account_backups
     WHERE expires_at > NOW()
     ORDER BY deleted_at DESC, id DESC
     LIMIT 500'
)->fetchAll();

renderAdminHeader('Zálohy trenérů');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-box-archive me-2 text-success"></i>Zálohy smazaných trenérů</h4>
    <span class="badge text-bg-secondary"><?= count($backups) ?></span>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (!$backups): ?>
        <div class="text-center text-muted py-5">Archiv zatím neobsahuje žádné platné zálohy.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Trenér</th>
                    <th>E-mail</th>
                    <th>Smazáno</th>
                    <th>Platnost do</th>
                    <th class="text-end">Záloha</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($backups as $backup): ?>
                <tr>
                    <td><?= h((string)$backup['coach_username']) ?></td>
                    <td><?= h((string)($backup['coach_email'] ?? '')) ?></td>
                    <td><?= h(formatDateTime((string)$backup['deleted_at'])) ?></td>
                    <td><?= h(formatDateTime((string)$backup['expires_at'])) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-success" href="<?= BASE_URL ?>/admin/coach_backup_download.php?id=<?= (int)$backup['id'] ?>">
                            <i class="fas fa-download me-1"></i>Stáhnout SQL
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<p class="small text-muted mt-3">Zálohy se automaticky mažou po uplynutí jednoho roku.</p>

<?php renderAdminFooter(); ?>
