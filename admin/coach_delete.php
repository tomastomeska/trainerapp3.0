<?php
// admin/coach_delete.php – potvrzovací stránka smazání trenéra se zálohou
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo     = getDB();
$coachId = intParam($_GET, 'id') ?: intParam($_POST, 'coach_id');

$stmt = $pdo->prepare('SELECT * FROM coaches WHERE id = ?');
$stmt->execute([$coachId]);
$coach = $stmt->fetch();

if (!$coach) {
    flash('danger', 'Trenér nenalezen.');
    redirect(BASE_URL . '/admin/coaches.php');
}

// ── Potvrzené smazání ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_confirmed') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/coaches.php');
    }
    $pdo->prepare('DELETE FROM coaches WHERE id = ?')->execute([$coachId]);
    flash('success', 'Trenér ' . h($coach['username']) . ' byl smazán se všemi daty.');
    redirect(BASE_URL . '/admin/coaches.php');
}

// ── Statistiky toho, co bude smazáno ─────────────────────────────────────────
$stats = $pdo->prepare(
    'SELECT
        COUNT(DISTINCT a.id)   AS athletes,
        COUNT(DISTINCT e.id)   AS exercises,
        COUNT(DISTINCT ws.id)  AS workout_sets,
        COUNT(DISTINCT ts.id)  AS sessions,
        COUNT(DISTINCT ss.id)  AS series
     FROM coaches c
     LEFT JOIN athletes a   ON a.coach_id = c.id
     LEFT JOIN exercises e  ON e.coach_id = c.id
     LEFT JOIN workout_sets ws ON ws.coach_id = c.id
     LEFT JOIN training_sessions ts ON ts.athlete_id = a.id
     LEFT JOIN session_series ss    ON ss.session_id  = ts.id
     WHERE c.id = ?'
);
$stats->execute([$coachId]);
$s = $stats->fetch();

// Seznam sportovců pro přehled
$athleteList = $pdo->prepare(
    'SELECT first_name, last_name,
            (SELECT COUNT(*) FROM training_sessions ts
             WHERE ts.athlete_id = a.id AND ts.completed_at IS NOT NULL) AS sessions
     FROM athletes a WHERE a.coach_id = ? ORDER BY last_name, first_name LIMIT 20'
);
$athleteList->execute([$coachId]);
$athletes = $athleteList->fetchAll();

renderAdminHeader('Smazat trenéra');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h4 class="fw-bold mb-0 text-danger">
        <i class="fas fa-trash me-2"></i>Smazat trenéra
    </h4>
</div>

<!-- Varování -->
<div class="alert alert-danger border-danger border-2 mb-4" style="border-left:6px solid #dc3545 !important">
    <div class="d-flex align-items-start gap-3">
        <div class="fs-2 pt-1"><i class="fas fa-triangle-exclamation"></i></div>
        <div>
            <h5 class="alert-heading mb-1">Nevratná operace!</h5>
            <p class="mb-0">
                Smazáním trenéra <strong><?= h($coach['name'] ?: $coach['username']) ?></strong>
                (<code><?= h($coach['username']) ?></code>) se <strong>trvale odstraní</strong>
                veškerá jeho data. Tuto akci nelze vzít zpět bez zálohy.
            </p>
        </div>
    </div>
</div>

<!-- Co bude smazáno -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold">
        <i class="fas fa-database me-2"></i>Data, která budou smazána
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-4 col-lg-2">
                <div class="border rounded p-3 text-center <?= $s['athletes'] > 0 ? 'border-warning' : '' ?>">
                    <div class="fs-3 fw-bold <?= $s['athletes'] > 0 ? 'text-warning' : 'text-muted' ?>">
                        <?= $s['athletes'] ?>
                    </div>
                    <div class="small text-muted">Sportovců</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="border rounded p-3 text-center">
                    <div class="fs-3 fw-bold text-muted"><?= $s['exercises'] ?></div>
                    <div class="small text-muted">Cviků</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="border rounded p-3 text-center">
                    <div class="fs-3 fw-bold text-muted"><?= $s['workout_sets'] ?></div>
                    <div class="small text-muted">Sad</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="border rounded p-3 text-center <?= $s['sessions'] > 0 ? 'border-danger' : '' ?>">
                    <div class="fs-3 fw-bold <?= $s['sessions'] > 0 ? 'text-danger' : 'text-muted' ?>">
                        <?= $s['sessions'] ?>
                    </div>
                    <div class="small text-muted">Tréninků</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="border rounded p-3 text-center">
                    <div class="fs-3 fw-bold text-muted"><?= $s['series'] ?></div>
                    <div class="small text-muted">Sérií</div>
                </div>
            </div>
        </div>

        <?php if (!empty($athletes)): ?>
        <h6 class="fw-semibold mt-3 mb-2">
            <i class="fas fa-users me-1 text-warning"></i>Sportovci, kteří budou smazáni:
        </h6>
        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-2">
            <?php foreach ($athletes as $a): ?>
            <div class="col">
                <div class="d-flex align-items-center gap-2 border rounded p-2 small">
                    <i class="fas fa-user text-muted"></i>
                    <span><?= h($a['first_name'] . ' ' . $a['last_name']) ?></span>
                    <?php if ($a['sessions'] > 0): ?>
                    <span class="badge bg-danger ms-auto"><?= $a['sessions'] ?>×</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ($s['athletes'] > 20): ?>
            <div class="col">
                <div class="p-2 text-muted small">
                    ... a dalších <?= $s['athletes'] - 20 ?> sportovců
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Záloha -->
<div class="card border-0 shadow-sm mb-4 border-success border-2" style="border-left:6px solid #198754 !important">
    <div class="card-body d-flex align-items-center gap-4">
        <div>
            <h6 class="fw-bold mb-1">
                <i class="fas fa-download me-2 text-success"></i>Doporučeno: Stáhněte si zálohu před smazáním
            </h6>
            <p class="text-muted small mb-0">
                Záloha obsahuje všechna data trenéra ve formátu SQL.
                Lze ji importovat zpět přes phpMyAdmin nebo MySQL klientem.
            </p>
        </div>
        <div class="ms-auto flex-shrink-0">
            <a href="<?= BASE_URL ?>/admin/coach_export.php?id=<?= $coachId ?>"
               class="btn btn-success fw-bold px-4"
               target="_blank">
                <i class="fas fa-file-code me-2"></i>Stáhnout zálohu (.sql)
            </a>
        </div>
    </div>
</div>

<!-- Akce -->
<div class="d-flex gap-3 align-items-center">
    <form method="post" id="deleteForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_confirmed">
        <input type="hidden" name="coach_id" value="<?= $coachId ?>">
        <button type="button" class="btn btn-danger fw-bold px-4"
                onclick="confirmDelete()">
            <i class="fas fa-trash me-2"></i>Ano, smazat trenéra a veškerá data
        </button>
    </form>
    <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary px-4">
        <i class="fas fa-arrow-left me-1"></i>Zrušit
    </a>
</div>

<script>
function confirmDelete() {
    if (confirm('POSLEDNÍ POTVRZENÍ: Opravdu trvale smazat trenéra "<?= h(addslashes($coach['username'])) ?>" a VEŠKERÁ jeho data?\n\nTato akce je NEVRATNÁ!')) {
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php renderAdminFooter(); ?>
