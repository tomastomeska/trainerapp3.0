<?php
require_once __DIR__ . '/../includes/admin_auth.php';
requireAdminLogin();
require_once __DIR__ . '/header.php';

$pdo = getDB();

$type = (string)($_GET['type'] ?? 'guest');
$allowedTypes = ['admin', 'coach', 'athlete', 'guest', 'system'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'guest';
}

$view = (string)($_GET['view'] ?? 'people');
if (!in_array($view, ['people', 'all', 'tech'], true)) {
    $view = 'people';
}

$hideNoise = (int)($_GET['hide_noise'] ?? 1) === 1;

$id = isset($_GET['id']) && $_GET['id'] !== '' ? max(1, (int)$_GET['id']) : null;
$hours = isset($_GET['hours']) ? max(1, min(720, (int)$_GET['hours'])) : 168;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$offset = ($page - 1) * $perPage;

$params = [$type, $hours];
$where = 'user_type = ? AND created_at >= (NOW() - INTERVAL ? HOUR)';
if ($id !== null) {
    $where .= ' AND user_id = ?';
    $params[] = $id;
} else {
    $where .= ' AND user_id IS NULL';
}

if ($hideNoise && in_array($type, ['system', 'guest'], true)) {
    $where .= ' AND event_type <> "db_connect_retry"';
}

$totalStmt = $pdo->prepare('SELECT COUNT(*) FROM app_event_log WHERE ' . $where);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$summaryStmt = $pdo->prepare(
    'SELECT
        MAX(NULLIF(user_name, "")) AS user_name,
        COUNT(*) AS all_events,
        SUM(CASE WHEN severity IN ("error", "critical") THEN 1 ELSE 0 END) AS error_events,
        SUM(CASE WHEN event_type = "slow_request" THEN 1 ELSE 0 END) AS slow_events,
        SUM(CASE WHEN event_type IN ("login_failed", "login_blocked") THEN 1 ELSE 0 END) AS auth_events,
        MAX(created_at) AS last_seen
     FROM app_event_log
     WHERE ' . $where
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: [];

$eventsStmt = $pdo->prepare(
    'SELECT
        id,
        created_at,
        event_type,
        severity,
        message,
        context_json,
        request_uri,
        request_method,
        http_status,
        duration_ms,
        ip_address
     FROM app_event_log
     WHERE ' . $where . '
     ORDER BY created_at DESC
     LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset
);
$eventsStmt->execute($params);
$events = $eventsStmt->fetchAll();

$resolvedName = trim((string)($summary['user_name'] ?? ''));
if ($resolvedName === '' && $id !== null) {
    if ($type === 'coach') {
        $stmt = $pdo->prepare('SELECT name, username FROM coaches WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if ($u) {
            $resolvedName = (string)($u['name'] ?: $u['username']);
        }
    } elseif ($type === 'athlete') {
        $stmt = $pdo->prepare('SELECT first_name, last_name FROM athletes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if ($u) {
            $resolvedName = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']);
        }
    } elseif ($type === 'admin') {
        $stmt = $pdo->prepare('SELECT name, username FROM superadmins WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if ($u) {
            $resolvedName = (string)($u['name'] ?: $u['username']);
        }
    }
}

if ($resolvedName === '') {
    $resolvedName = $id !== null ? ('Uživatel #' . $id) : strtoupper($type);
}

$severityClass = static function (string $severity): string {
    return match ($severity) {
        'critical' => 'bg-danger',
        'error' => 'bg-warning text-dark',
        'warning' => 'bg-info text-dark',
        default => 'bg-secondary',
    };
};

renderAdminHeader('Errorlog - detail uživatele');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="fas fa-user-clock me-2" style="color:#a78bfa"></i><?= h($resolvedName) ?></h4>
        <div class="text-muted small">
            Typ: <strong><?= h($type) ?></strong>
            <?php if ($id !== null): ?>
                · ID: <strong>#<?= (int)$id ?></strong>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/admin/errorlog.php?hours=<?= (int)min(168, $hours) ?>&view=<?= h($view) ?>" class="btn btn-sm btn-outline-dark">← Zpět na Errorlog</a>
        <form method="get" class="d-flex align-items-center gap-2">
            <input type="hidden" name="type" value="<?= h($type) ?>">
            <input type="hidden" name="view" value="<?= h($view) ?>">
            <?php if ($id !== null): ?>
                <input type="hidden" name="id" value="<?= (int)$id ?>">
            <?php endif; ?>
            <input type="hidden" name="hide_noise" value="<?= $hideNoise ? 1 : 0 ?>">
            <select name="hours" class="form-select form-select-sm" style="width:auto">
                <option value="24" <?= $hours === 24 ? 'selected' : '' ?>>24 hodin</option>
                <option value="72" <?= $hours === 72 ? 'selected' : '' ?>>72 hodin</option>
                <option value="168" <?= $hours === 168 ? 'selected' : '' ?>>7 dní</option>
                <option value="720" <?= $hours === 720 ? 'selected' : '' ?>>30 dní</option>
            </select>
            <button class="btn btn-sm btn-dark" type="submit">Filtrovat</button>
        </form>
    </div>
</div>

<?php if (in_array($type, ['system', 'guest'], true)): ?>
    <div class="alert alert-warning border-0 py-2 small">
        Technický detail nemusí být svázaný s konkrétním člověkem.
        <?php if ($hideNoise): ?>
            Opakované záznamy typu <code>db_connect_retry</code> jsou skryté.
            <a href="<?= BASE_URL ?>/admin/errorlog_user.php?type=<?= urlencode($type) ?><?= $id !== null ? '&id=' . (int)$id : '' ?>&hours=<?= (int)$hours ?>&view=<?= h(urlencode($view)) ?>&hide_noise=0">Zobrazit i skryté</a>
        <?php else: ?>
            <a href="<?= BASE_URL ?>/admin/errorlog_user.php?type=<?= urlencode($type) ?><?= $id !== null ? '&id=' . (int)$id : '' ?>&hours=<?= (int)$hours ?>&view=<?= h(urlencode($view)) ?>&hide_noise=1">Skrýt opakované db_connect_retry</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-info"><?= (int)($summary['all_events'] ?? 0) ?></div>
            <div class="text-muted small">Událostí</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-danger"><?= (int)($summary['error_events'] ?? 0) ?></div>
            <div class="text-muted small">Chyby</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-warning"><?= (int)($summary['slow_events'] ?? 0) ?></div>
            <div class="text-muted small">Pomalé requesty</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-6 fw-bold text-muted"><?= !empty($summary['last_seen']) ? h(formatDateTime((string)$summary['last_seen'])) : '–' ?></div>
            <div class="text-muted small">Poslední výskyt</div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-list-check me-2"></i>Události
    </div>
    <div class="card-body p-0">
        <?php if (empty($events)): ?>
            <div class="text-center py-4 text-muted">Žádné události pro vybraný filtr.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Čas</th>
                        <th>Event</th>
                        <th>Severity</th>
                        <th>Zpráva</th>
                        <th>URI</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">ms</th>
                        <th>Kontext</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($events as $event): ?>
                        <?php
                        $contextPretty = '–';
                        if (!empty($event['context_json'])) {
                            $decoded = json_decode((string)$event['context_json'], true);
                            if (is_array($decoded)) {
                                $contextPretty = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                if ($contextPretty === false) {
                                    $contextPretty = (string)$event['context_json'];
                                }
                            } else {
                                $contextPretty = (string)$event['context_json'];
                            }
                        }
                        ?>
                        <tr>
                            <td class="text-muted small"><?= h(formatDateTime((string)$event['created_at'])) ?></td>
                            <td><code><?= h((string)$event['event_type']) ?></code></td>
                            <td><span class="badge <?= $severityClass((string)$event['severity']) ?>"><?= h((string)$event['severity']) ?></span></td>
                            <td class="small"><?= h((string)$event['message']) ?></td>
                            <td class="small text-muted" style="max-width:260px;word-break:break-all"><?= h((string)$event['request_uri']) ?></td>
                            <td class="text-center small"><?= $event['http_status'] !== null ? (int)$event['http_status'] : '–' ?></td>
                            <td class="text-center small"><?= $event['duration_ms'] !== null ? (int)$event['duration_ms'] : '–' ?></td>
                            <td class="small text-muted" style="max-width:320px;word-break:break-word"><?= h($contextPretty) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm mb-0">
            <?php
            $baseParams = 'type=' . urlencode($type) . '&hours=' . $hours;
            $baseParams .= '&view=' . urlencode($view);
            $baseParams .= '&hide_noise=' . ($hideNoise ? '1' : '0');
            if ($id !== null) {
                $baseParams .= '&id=' . $id;
            }
            ?>
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= BASE_URL ?>/admin/errorlog_user.php?<?= $baseParams ?>&page=<?= max(1, $page - 1) ?>">Předchozí</a>
            </li>
            <li class="page-item disabled"><span class="page-link">Strana <?= (int)$page ?> / <?= (int)$totalPages ?></span></li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= BASE_URL ?>/admin/errorlog_user.php?<?= $baseParams ?>&page=<?= min($totalPages, $page + 1) ?>">Další</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<?php renderAdminFooter(); ?>
