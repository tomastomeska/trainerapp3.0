<?php
// admin/coach_deleted_trainings.php - Smazane treninky trenera + obnova
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();
$coachId = intParam($_GET, 'coach_id');

$stmtCoach = $pdo->prepare('SELECT id, username, name FROM coaches WHERE id = ?');
$stmtCoach->execute([$coachId]);
$coach = $stmtCoach->fetch();

if (!$coach) {
    flash('danger', 'Trener nenalezen.');
    redirect(BASE_URL . '/admin/coaches.php');
}

function adminSqlVal(PDO $pdo, mixed $v): string {
    if ($v === null) {
        return 'NULL';
    }
    return $pdo->quote((string)$v);
}

function adminExportRows(PDO $pdo, string $table, array $rows, array &$lines): void {
    if (empty($rows)) {
        return;
    }

    $lines[] = '-- Tabulka: ' . $table;
    foreach ($rows as $row) {
        $cols = implode(', ', array_map(static fn($k) => '`' . $k . '`', array_keys($row)));
        $vals = implode(', ', array_map(static fn($v) => adminSqlVal($pdo, $v), array_values($row)));
        $lines[] = "INSERT INTO `{$table}` ({$cols}) VALUES ({$vals});";
    }
    $lines[] = '';
}

function adminTableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function adminFetchBySessionIds(PDO $pdo, string $table, array $sessionIds, string $fk = 'session_id'): array {
    if (empty($sessionIds) || !adminTableExists($pdo, $table)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$fk}` IN ({$placeholders}) ORDER BY id ASC");
    $stmt->execute($sessionIds);
    return $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatny bezpecnostni token.');
        redirect(BASE_URL . '/admin/coach_deleted_trainings.php?coach_id=' . $coachId);
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'restore') {
        $sessionId = intParam($_POST, 'session_id');
        $check = $pdo->prepare(
            'SELECT ts.id
             FROM training_sessions ts
             JOIN athletes a ON a.id = ts.athlete_id
             WHERE ts.id = ?
               AND a.coach_id = ?
               AND ts.deleted_by_coach_at IS NOT NULL'
        );
        $check->execute([$sessionId, $coachId]);
        if ($check->fetch()) {
            $pdo->prepare('UPDATE training_sessions SET deleted_by_coach_at = NULL, deleted_by_coach_id = NULL WHERE id = ?')
                ->execute([$sessionId]);
            flash('success', 'Trenink byl obnoven.');
        } else {
            flash('danger', 'Smazany trenink nebyl nalezen.');
        }

        redirect(BASE_URL . '/admin/coach_deleted_trainings.php?coach_id=' . $coachId);
    }

    if ($action === 'bulk_export' || $action === 'bulk_delete') {
        $selectedIds = array_values(array_unique(array_filter(array_map(
            static fn($v) => (int)$v,
            $_POST['selected_ids'] ?? []
        ), static fn($v) => $v > 0)));

        if (empty($selectedIds)) {
            flash('danger', 'Nevybral jste zadne treninky.');
            redirect(BASE_URL . '/admin/coach_deleted_trainings.php?coach_id=' . $coachId);
        }

        $inClause = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = array_merge([$coachId], $selectedIds);
        $stmtAllowed = $pdo->prepare(
            "SELECT ts.id
             FROM training_sessions ts
             JOIN athletes a ON a.id = ts.athlete_id
             WHERE a.coach_id = ?
               AND ts.deleted_by_coach_at IS NOT NULL
               AND ts.id IN ({$inClause})"
        );
        $stmtAllowed->execute($params);
        $allowedIds = array_map(static fn($r) => (int)$r['id'], $stmtAllowed->fetchAll());

        if (empty($allowedIds)) {
            flash('danger', 'Vybrane treninky nebyly nalezeny.');
            redirect(BASE_URL . '/admin/coach_deleted_trainings.php?coach_id=' . $coachId);
        }

        if ($action === 'bulk_export') {
            $lines = [];
            $lines[] = '-- ============================================================';
            $lines[] = '-- Zaloha smazanych treninku trenera: ' . ($coach['username'] ?? ('coach_' . $coachId));
            $lines[] = '-- Exportovano: ' . date('d.m.Y H:i:s');
            $lines[] = '-- Pocet treninku: ' . count($allowedIds);
            $lines[] = '-- ============================================================';
            $lines[] = '';
            $lines[] = 'SET NAMES utf8mb4;';
            $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
            $lines[] = '';

            $sessionRows = adminFetchBySessionIds($pdo, 'training_sessions', $allowedIds, 'id');
            adminExportRows($pdo, 'training_sessions', $sessionRows, $lines);

            $tseRows = adminFetchBySessionIds($pdo, 'training_session_exercises', $allowedIds);
            adminExportRows($pdo, 'training_session_exercises', $tseRows, $lines);

            $seriesRows = adminFetchBySessionIds($pdo, 'session_series', $allowedIds);
            adminExportRows($pdo, 'session_series', $seriesRows, $lines);

            $runTreadmillRows = adminFetchBySessionIds($pdo, 'run_treadmill_sessions', $allowedIds);
            adminExportRows($pdo, 'run_treadmill_sessions', $runTreadmillRows, $lines);

            $runOutdoorRows = adminFetchBySessionIds($pdo, 'run_outdoor_sessions', $allowedIds);
            adminExportRows($pdo, 'run_outdoor_sessions', $runOutdoorRows, $lines);

            if (!empty($runOutdoorRows) && adminTableExists($pdo, 'run_outdoor_splits')) {
                $runIds = array_map(static fn($r) => (int)$r['id'], $runOutdoorRows);
                $placeholders = implode(',', array_fill(0, count($runIds), '?'));
                $stmtSplits = $pdo->prepare("SELECT * FROM run_outdoor_splits WHERE run_session_id IN ({$placeholders}) ORDER BY id ASC");
                $stmtSplits->execute($runIds);
                adminExportRows($pdo, 'run_outdoor_splits', $stmtSplits->fetchAll(), $lines);
            }

            $golfRows = adminFetchBySessionIds($pdo, 'golf_sessions', $allowedIds);
            adminExportRows($pdo, 'golf_sessions', $golfRows, $lines);

            if (!empty($golfRows) && adminTableExists($pdo, 'golf_holes')) {
                $golfIds = array_map(static fn($r) => (int)$r['id'], $golfRows);
                $placeholders = implode(',', array_fill(0, count($golfIds), '?'));
                $stmtHoles = $pdo->prepare("SELECT * FROM golf_holes WHERE golf_session_id IN ({$placeholders}) ORDER BY id ASC");
                $stmtHoles->execute($golfIds);
                adminExportRows($pdo, 'golf_holes', $stmtHoles->fetchAll(), $lines);
            }

            $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
            $lines[] = '';
            $lines[] = '-- Konec zalohy';

            $safeCoach = preg_replace('/[^a-z0-9_]/i', '_', (string)($coach['username'] ?? ('coach_' . $coachId)));
            $filename = 'zaloha_smazanych_treninku_' . $safeCoach . '_' . date('Ymd_His') . '.sql';
            $sql = implode("\n", $lines);

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($sql));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo $sql;
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($allowedIds), '?'));
        $pdo->beginTransaction();
        try {
            if (adminTableExists($pdo, 'run_treadmill_sessions')) {
                $stmtDelRunTreadmill = $pdo->prepare("DELETE FROM run_treadmill_sessions WHERE session_id IN ({$placeholders})");
                $stmtDelRunTreadmill->execute($allowedIds);
            }

            if (adminTableExists($pdo, 'run_outdoor_splits') && adminTableExists($pdo, 'run_outdoor_sessions')) {
                $stmtDelSplits = $pdo->prepare(
                    "DELETE ros
                     FROM run_outdoor_splits ros
                     JOIN run_outdoor_sessions ro ON ro.id = ros.run_session_id
                     WHERE ro.session_id IN ({$placeholders})"
                );
                $stmtDelSplits->execute($allowedIds);
            }

            if (adminTableExists($pdo, 'run_outdoor_sessions')) {
                $stmtDelRunOutdoor = $pdo->prepare("DELETE FROM run_outdoor_sessions WHERE session_id IN ({$placeholders})");
                $stmtDelRunOutdoor->execute($allowedIds);
            }

            if (adminTableExists($pdo, 'golf_holes') && adminTableExists($pdo, 'golf_sessions')) {
                $stmtDelHoles = $pdo->prepare(
                    "DELETE gh
                     FROM golf_holes gh
                     JOIN golf_sessions gs ON gs.id = gh.golf_session_id
                     WHERE gs.session_id IN ({$placeholders})"
                );
                $stmtDelHoles->execute($allowedIds);
            }

            if (adminTableExists($pdo, 'golf_sessions')) {
                $stmtDelGolf = $pdo->prepare("DELETE FROM golf_sessions WHERE session_id IN ({$placeholders})");
                $stmtDelGolf->execute($allowedIds);
            }

            if (adminTableExists($pdo, 'training_session_exercises')) {
                $stmtDelTse = $pdo->prepare("DELETE FROM training_session_exercises WHERE session_id IN ({$placeholders})");
                $stmtDelTse->execute($allowedIds);
            }

            if (adminTableExists($pdo, 'session_series')) {
                $stmtDelSeries = $pdo->prepare("DELETE FROM session_series WHERE session_id IN ({$placeholders})");
                $stmtDelSeries->execute($allowedIds);
            }

            $stmtDeleteTs = $pdo->prepare("DELETE FROM training_sessions WHERE id IN ({$placeholders})");
            $stmtDeleteTs->execute($allowedIds);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'Trvale smazani selhalo: ' . h($e->getMessage()));
            redirect(BASE_URL . '/admin/coach_deleted_trainings.php?coach_id=' . $coachId);
        }

        flash('success', 'Trvale smazano treninku: ' . count($allowedIds) . '.');
        redirect(BASE_URL . '/admin/coach_deleted_trainings.php?coach_id=' . $coachId);
    }
}

$stmt = $pdo->prepare(
        'SELECT ts.id, ts.started_at, ts.completed_at, ts.location, ts.deleted_by_coach_at,
                        ts.deleted_by_coach_id,
            ws.name AS set_name,
                        c.name AS coach_name,
                        c.username AS coach_username,
                        dc.name AS deleted_by_name,
                        dc.username AS deleted_by_username,
            a.first_name, a.last_name,
            (SELECT COUNT(*) FROM session_series ss WHERE ss.session_id = ts.id) AS total_series
     FROM training_sessions ts
     JOIN athletes a ON a.id = ts.athlete_id
     JOIN workout_sets ws ON ws.id = ts.workout_set_id
         JOIN coaches c ON c.id = a.coach_id
         LEFT JOIN coaches dc ON dc.id = ts.deleted_by_coach_id
     WHERE a.coach_id = ?
       AND ts.deleted_by_coach_at IS NOT NULL
     ORDER BY ts.deleted_by_coach_at DESC, ts.started_at DESC'
);
$stmt->execute([$coachId]);
$deletedSessions = $stmt->fetchAll();

renderAdminHeader('Smazane treninky');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/coaches.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Zpet
    </a>
    <h4 class="fw-bold mb-0">
        <i class="fas fa-folder-open me-2 text-danger"></i>
        Smazane treninky: <?= h($coach['name'] ?: $coach['username']) ?>
    </h4>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($deletedSessions)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
            Zadny smazany trenink.
        </div>
        <?php else: ?>
        <form method="post" id="bulk-deleted-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="bulk-action-input" value="">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-bottom bg-light">
                <div class="form-check m-0">
                    <input class="form-check-input" type="checkbox" id="select-all-deleted">
                    <label class="form-check-label" for="select-all-deleted">Vybrat vše</label>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btn-export-selected">
                        <i class="fas fa-download me-1"></i>Export zálohy vybraných
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="btn-delete-selected">
                        <i class="fas fa-trash me-1"></i>Trvale smazat vybrané
                    </button>
                </div>
            </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th></th>
                        <th>#</th>
                        <th>Sportovec</th>
                        <th>Trenér</th>
                        <th>Sada</th>
                        <th>Datum</th>
                        <th class="text-center">Serii</th>
                        <th>Smazal</th>
                        <th>Smazano</th>
                        <th class="text-end">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deletedSessions as $i => $s): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input deleted-select" name="selected_ids[]" value="<?= (int)$s['id'] ?>">
                        </td>
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($s['first_name'] . ' ' . $s['last_name']) ?></td>
                        <td><?= h(($s['coach_name'] ?: $s['coach_username'])) ?></td>
                        <td><span class="badge bg-secondary"><?= h($s['set_name']) ?></span></td>
                        <td><?= formatDateTime($s['completed_at'] ?: $s['started_at']) ?></td>
                        <td class="text-center"><span class="badge bg-dark"><?= (int)$s['total_series'] ?></span></td>
                        <td class="text-muted small">
                            <?= h(($s['deleted_by_name'] ?: $s['deleted_by_username'] ?: ($s['coach_name'] ?: $s['coach_username']))) ?>
                        </td>
                        <td class="text-muted small"><?= formatDateTime($s['deleted_by_coach_at']) ?></td>
                        <td class="text-end">
                            <button type="submit"
                                    name="action"
                                    value="restore"
                                    class="btn btn-success btn-sm"
                                    formaction="<?= BASE_URL ?>/admin/coach_deleted_trainings.php?coach_id=<?= (int)$coachId ?>"
                                    onclick="document.getElementById('bulk-action-input').value='restore'; document.getElementById('restore-session-id').value='<?= (int)$s['id'] ?>'; return confirm('Obnovit tento trenink?');">
                                <i class="fas fa-rotate-left me-1"></i>Obnovit
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <input type="hidden" id="restore-session-id" name="session_id" value="">
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($deletedSessions)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('bulk-deleted-form');
    const actionInput = document.getElementById('bulk-action-input');
    const selectAll = document.getElementById('select-all-deleted');
    const itemCheckboxes = Array.from(document.querySelectorAll('.deleted-select'));
    const exportBtn = document.getElementById('btn-export-selected');
    const deleteBtn = document.getElementById('btn-delete-selected');

    if (!form || !actionInput || !itemCheckboxes.length) {
        return;
    }

    const selectedCount = function() {
        return itemCheckboxes.filter(function(cb) { return cb.checked; }).length;
    };

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            itemCheckboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
        });
    }

    const submitBulk = function(action) {
        const count = selectedCount();
        if (count === 0) {
            alert('Nejprve vyberte alespoň jeden trénink.');
            return;
        }

        if (action === 'bulk_delete') {
            const ok = confirm('Opravdu chcete trvale smazat ' + count + ' vybraných tréninků? Tuto akci nelze vrátit zpět.');
            if (!ok) {
                return;
            }
        }

        actionInput.value = action;
        form.submit();
    };

    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            submitBulk('bulk_export');
        });
    }

    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            submitBulk('bulk_delete');
        });
    }
});
</script>
<?php endif; ?>

<?php renderAdminFooter();
