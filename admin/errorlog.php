<?php
require_once __DIR__ . '/../includes/admin_auth.php';
requireAdminLogin();
require_once __DIR__ . '/header.php';

$pdo = getDB();

$hours = isset($_GET['hours']) ? max(1, min(168, (int)$_GET['hours'])) : 24;
$limitUsers = isset($_GET['limit']) ? max(10, min(200, (int)$_GET['limit'])) : 100;
$view = (string)($_GET['view'] ?? 'people');
if (!in_array($view, ['people', 'all', 'tech'], true)) {
    $view = 'people';
}

$hideNoise = (int)($_GET['hide_noise'] ?? 1) === 1;

$whereSql = 'l.created_at >= (NOW() - INTERVAL ? HOUR)';
$baseParams = [$hours];
if ($view === 'people') {
    $whereSql .= ' AND l.user_type IN ("coach", "athlete", "admin") AND l.user_id IS NOT NULL';
} elseif ($view === 'tech') {
    $whereSql .= ' AND l.user_type IN ("system", "guest")';
}
if ($hideNoise) {
    $whereSql .= ' AND l.event_type <> "db_connect_retry"';
}

$userStmt = $pdo->prepare(
    'SELECT
        l.user_type,
        l.user_id,
        COALESCE(
            MAX(NULLIF(l.user_name, "")),
            MAX(CASE WHEN l.user_type = "coach" THEN NULLIF(c.name, "") END),
            MAX(CASE WHEN l.user_type = "coach" THEN c.username END),
            MAX(CASE WHEN l.user_type = "athlete" THEN CONCAT(a.first_name, " ", a.last_name) END),
            MAX(CASE WHEN l.user_type = "admin" THEN NULLIF(sa.name, "") END),
            MAX(CASE WHEN l.user_type = "admin" THEN sa.username END)
        ) AS user_name,
        COUNT(*) AS total_events,
        SUM(CASE WHEN l.severity IN ("error", "critical") THEN 1 ELSE 0 END) AS error_events,
        SUM(CASE WHEN l.event_type = "slow_request" THEN 1 ELSE 0 END) AS slow_events,
        SUM(CASE WHEN l.event_type IN ("login_failed", "login_blocked") THEN 1 ELSE 0 END) AS auth_events,
        MAX(l.created_at) AS last_seen
     FROM app_event_log l
     LEFT JOIN coaches c ON l.user_type = "coach" AND l.user_id = c.id
     LEFT JOIN athletes a ON l.user_type = "athlete" AND l.user_id = a.id
     LEFT JOIN superadmins sa ON l.user_type = "admin" AND l.user_id = sa.id
     WHERE ' . $whereSql . '
     GROUP BY l.user_type, l.user_id
     ORDER BY error_events DESC, slow_events DESC, total_events DESC, last_seen DESC
     LIMIT ' . (int)$limitUsers
);
$userStmt->execute($baseParams);
$userRows = $userStmt->fetchAll();

$summaryStmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS all_events,
        SUM(CASE WHEN l.severity IN ("error", "critical") THEN 1 ELSE 0 END) AS error_events,
        SUM(CASE WHEN l.event_type = "slow_request" THEN 1 ELSE 0 END) AS slow_events,
        COUNT(DISTINCT CONCAT(l.user_type, ":", COALESCE(l.user_id, 0))) AS affected_users
     FROM app_event_log l
     WHERE ' . $whereSql
);
$summaryStmt->execute($baseParams);
$summary = $summaryStmt->fetch() ?: [];

$recentStmt = $pdo->prepare(
    'SELECT
        l.id,
        l.created_at,
        l.event_type,
        l.severity,
        l.user_type,
        l.user_id,
        l.message,
        l.duration_ms,
        COALESCE(
            NULLIF(l.user_name, ""),
            CASE WHEN l.user_type = "coach" THEN COALESCE(NULLIF(c.name, ""), c.username) END,
            CASE WHEN l.user_type = "athlete" THEN CONCAT(a.first_name, " ", a.last_name) END,
            CASE WHEN l.user_type = "admin" THEN COALESCE(NULLIF(sa.name, ""), sa.username) END
        ) AS user_name
     FROM app_event_log l
     LEFT JOIN coaches c ON l.user_type = "coach" AND l.user_id = c.id
     LEFT JOIN athletes a ON l.user_type = "athlete" AND l.user_id = a.id
     LEFT JOIN superadmins sa ON l.user_type = "admin" AND l.user_id = sa.id
     WHERE ' . $whereSql . '
     ORDER BY l.created_at DESC
     LIMIT 50'
);
$recentStmt->execute($baseParams);
$recent = $recentStmt->fetchAll();

renderAdminHeader('Errorlog');

$severityClass = static function (string $severity): string {
    return match ($severity) {
        'critical' => 'bg-danger',
        'error' => 'bg-warning text-dark',
        'warning' => 'bg-info text-dark',
        default => 'bg-secondary',
    };
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-triangle-exclamation me-2" style="color:#a78bfa"></i>Errorlog
    </h4>
    <form method="get" class="d-flex align-items-center gap-2">
        <label class="small text-muted">Období</label>
        <select name="hours" class="form-select form-select-sm" style="width:auto">
            <option value="24" <?= $hours === 24 ? 'selected' : '' ?>>24 hodin</option>
            <option value="48" <?= $hours === 48 ? 'selected' : '' ?>>48 hodin</option>
            <option value="72" <?= $hours === 72 ? 'selected' : '' ?>>72 hodin</option>
            <option value="168" <?= $hours === 168 ? 'selected' : '' ?>>7 dní</option>
        </select>
        <select name="view" class="form-select form-select-sm" style="width:auto">
            <option value="people" <?= $view === 'people' ? 'selected' : '' ?>>Lidé</option>
            <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>Vše</option>
            <option value="tech" <?= $view === 'tech' ? 'selected' : '' ?>>Technické</option>
        </select>
        <select name="hide_noise" class="form-select form-select-sm" style="width:auto">
            <option value="1" <?= $hideNoise ? 'selected' : '' ?>>Skrýt šum</option>
            <option value="0" <?= !$hideNoise ? 'selected' : '' ?>>Ukázat šum</option>
        </select>
        <input type="hidden" name="limit" value="<?= (int)$limitUsers ?>">
        <button class="btn btn-sm btn-dark" type="submit">Filtrovat</button>
    </form>
</div>

<?php if ($view === 'people'): ?>
    <div class="alert alert-info border-0 py-2 small">
        Zobrazeny jsou jen události přiřazené ke konkrétním uživatelům (trenér, sportovec, admin). Technické systémové logy jsou skryté.
    </div>
<?php elseif ($view === 'tech'): ?>
    <div class="alert alert-warning border-0 py-2 small">
        Zobrazen je technický pohled (system/guest). Tyto záznamy nemusí být navázané na konkrétního uživatele.
    </div>
<?php endif; ?>

<?php if ($hideNoise): ?>
    <div class="alert alert-secondary border-0 py-2 small">
        Aktivní filtr „Skrýt šum“: události <code>db_connect_retry</code> jsou v přehledu skryté.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold" style="color:#7c3aed"><?= (int)($summary['affected_users'] ?? 0) ?></div>
            <div class="text-muted small">Dotčených uživatelů</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-danger"><?= (int)($summary['error_events'] ?? 0) ?></div>
            <div class="text-muted small">Chybové události</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-warning"><?= (int)($summary['slow_events'] ?? 0) ?></div>
            <div class="text-muted small">Pomalé požadavky</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-info"><?= (int)($summary['all_events'] ?? 0) ?></div>
            <div class="text-muted small">Událostí celkem</div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold d-flex justify-content-between align-items-center" style="background:#1e1e2e;color:#fff">
        <span><i class="fas fa-users me-2"></i>Uživatelé s nestandardními událostmi</span>
        <small class="text-secondary">Posledních <?= (int)$hours ?> h</small>
    </div>
    <div class="card-body p-0">
        <?php if (empty($userRows)): ?>
            <div class="text-center py-4 text-muted">Žádné záznamy v daném období.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Uživatel</th>
                        <th>Typ</th>
                        <th class="text-center">Chyby</th>
                        <th class="text-center">Pomalé</th>
                        <th class="text-center">Přihlášení</th>
                        <th class="text-center">Celkem</th>
                        <th>Poslední výskyt</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($userRows as $row): ?>
                        <?php
                        $uid = $row['user_id'] !== null ? (int)$row['user_id'] : null;
                        $uname = trim((string)($row['user_name'] ?? ''));
                        $userLabel = $uname !== '' ? $uname : (($uid !== null ? '#' . $uid : 'Neznámý uživatel'));
                        $detailUrl = BASE_URL . '/admin/errorlog_user.php?type=' . urlencode((string)$row['user_type']);
                        if ($uid !== null) {
                            $detailUrl .= '&id=' . $uid;
                        }
                        $detailUrl .= '&hours=' . $hours . '&view=' . urlencode($view) . '&hide_noise=' . ($hideNoise ? '1' : '0');
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= h($userLabel) ?></td>
                            <td><span class="badge bg-secondary"><?= h((string)$row['user_type']) ?></span></td>
                            <td class="text-center"><span class="badge bg-danger"><?= (int)$row['error_events'] ?></span></td>
                            <td class="text-center"><span class="badge bg-warning text-dark"><?= (int)$row['slow_events'] ?></span></td>
                            <td class="text-center"><span class="badge bg-info text-dark"><?= (int)$row['auth_events'] ?></span></td>
                            <td class="text-center"><span class="badge bg-dark"><?= (int)$row['total_events'] ?></span></td>
                            <td class="small text-muted"><?= formatDateTime((string)$row['last_seen']) ?></td>
                            <td class="text-end">
                                <a href="<?= h($detailUrl) ?>" class="btn btn-sm btn-outline-dark">Detail</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-clock-rotate-left me-2"></i>Poslední události
    </div>
    <div class="card-body p-0">
        <?php if (empty($recent)): ?>
            <div class="text-center py-4 text-muted">Žádné záznamy.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Čas</th>
                        <th>Typ události</th>
                        <th>Severity</th>
                        <th>Uživatel</th>
                        <th>Zpráva</th>
                        <th class="text-center">ms</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $row): ?>
                        <?php
                        $uid = $row['user_id'] !== null ? (int)$row['user_id'] : null;
                        $uname = trim((string)($row['user_name'] ?? ''));
                        $userLabel = $uname !== '' ? $uname : (($uid !== null ? '#' . $uid : 'guest'));
                        ?>
                        <tr>
                            <td class="text-muted small"><?= formatDateTime((string)$row['created_at']) ?></td>
                            <td><code><?= h((string)$row['event_type']) ?></code></td>
                            <td><span class="badge <?= $severityClass((string)$row['severity']) ?>"><?= h((string)$row['severity']) ?></span></td>
                            <td class="small"><?= h($row['user_type'] . ' / ' . $userLabel) ?></td>
                            <td class="small"><?= h((string)$row['message']) ?></td>
                            <td class="text-center small"><?= $row['duration_ms'] !== null ? (int)$row['duration_ms'] : '–' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php renderAdminFooter(); ?>
