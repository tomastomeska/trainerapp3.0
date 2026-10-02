<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment_billing.php';
require_once __DIR__ . '/includes/calendar_replacements.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = (int)getCurrentCoachId();
$pdo = getDB();
$now = new DateTimeImmutable();
$athleteId = max(0, (int)($_GET['athlete_id'] ?? 0));
$statusLabels = [
    'attention' => 'K řešení',
    'all' => 'Všechny záznamy',
    'open' => 'Čeká na výběr slotu',
    'soon' => 'Končí do 48 hodin',
    'pending' => 'Čeká na schválení',
    'approved' => 'Náhrada schválena',
    'expired' => 'Lhůta propadla',
    'none' => 'Bez náhrady',
    'revoked' => 'Nárok zrušen trenérem',
    'missing' => 'Chybějící údaje nebo slot',
];
$status = (string)($_GET['status'] ?? 'attention');
if (!isset($statusLabels[$status])) {
    $status = 'attention';
}
$dateFilters = [];
foreach (['from', 'to'] as $dateKey) {
    $value = (string)($_GET[$dateKey] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateFilters[$dateKey] = $date && $date->format('Y-m-d') === $value ? $value : '';
}
$revocationSchemaReady = true;
try {
    foreach (['replacement_revoked_at', 'replacement_revoked_by_coach_id', 'replacement_revocation_reason'] as $column) {
        if (!paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', $column)) {
            $revocationSchemaReady = false;
        }
    }
} catch (Throwable $exception) {
    $revocationSchemaReady = false;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = null;
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Neplatný CSRF token. Obnovte stránku a zkuste to znovu.';
    } elseif (($_POST['action'] ?? '') !== 'revoke_replacement') {
        $error = 'Neplatná akce.';
    } elseif (!$revocationSchemaReady) {
        $error = 'Zrušení nároku vyžaduje aktualizaci databáze administrátorem.';
    } elseif (($_POST['confirm_revocation'] ?? '') !== '1') {
        $error = 'Potvrďte zrušení nároku na náhradu.';
    } else {
        try {
            calendarRevokeReplacement(
                $pdo,
                $coachId,
                (int)($_POST['cancellation_id'] ?? 0),
                is_string($_POST['reason'] ?? null) ? $_POST['reason'] : ''
            );
            flash('success', 'Nárok na náhradu byl zrušen. Záznam zůstává v historii; původní výzva i platby jsou beze změny.');
        } catch (RuntimeException $exception) {
            if ($exception instanceof PDOException) {
                error_log('Replacement revocation failed: ' . $exception->getMessage());
                $error = 'Zrušení nároku se nepodařilo uložit. Zkuste obnovit přehled.';
            } else {
                $error = $exception->getMessage();
            }
        } catch (Throwable $exception) {
            error_log('Replacement revocation failed: ' . $exception->getMessage());
            $error = 'Zrušení nároku se nepodařilo uložit. Zkuste obnovit přehled.';
        }
    }
    if ($error !== null) {
        flash('danger', $error);
    }
    redirect(BASE_URL . '/calendar_replacements.php?' . http_build_query([
        'athlete_id' => $athleteId,
        'status' => $status,
        'from' => $dateFilters['from'],
        'to' => $dateFilters['to'],
        'page' => max(1, (int)($_GET['page'] ?? 1)),
    ]));
}
$athletesStmt = $pdo->prepare('SELECT id, first_name, last_name FROM athletes WHERE coach_id = ? ORDER BY last_name, first_name');
$athletesStmt->execute([$coachId]);
$athletes = $athletesStmt->fetchAll(PDO::FETCH_ASSOC);
$rows = [];
$loadError = null;
try {
    foreach (['replacement_required', 'replacement_deadline_at', 'replacement_event_id', 'payment_status_snapshot', 'billing_month'] as $column) {
        if (!paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', $column)) {
            throw new RuntimeException('missing_schema');
        }
    }
    $issueSelect = paymentBillingColumnExists($pdo, 'coach_calendar_event_cancellations', 'billing_issue_number')
        ? 'c.billing_issue_number'
        : '1 AS billing_issue_number';
    $sql = "SELECT c.*, {$issueSelect}, a.first_name, a.last_name,
                   actor.first_name AS actor_first_name, actor.last_name AS actor_last_name,
                   replacement.id AS replacement_id,
                   replacement.starts_at AS replacement_starts_at,
                   replacement.ends_at AS replacement_ends_at,
                   replacement.location AS replacement_location,
                   replacement.approval_status AS replacement_approval_status
            FROM coach_calendar_event_cancellations c
            LEFT JOIN athletes a ON a.id = c.athlete_id AND a.coach_id = c.coach_id
            LEFT JOIN athletes actor ON actor.id = c.canceled_by_athlete_id AND actor.coach_id = c.coach_id
            LEFT JOIN coach_calendar_events replacement
                   ON replacement.id = c.replacement_event_id AND replacement.coach_id = c.coach_id
                  AND (replacement.athlete_id = c.athlete_id OR replacement.second_athlete_id = c.athlete_id)
            WHERE c.coach_id = ?";
    $params = [$coachId];
    if ($athleteId > 0) {
        $sql .= ' AND c.athlete_id = ?';
        $params[] = $athleteId;
    }
    if ($dateFilters['from'] !== '') {
        $sql .= ' AND c.starts_at >= ?';
        $params[] = $dateFilters['from'] . ' 00:00:00';
    }
    if ($dateFilters['to'] !== '') {
        $sql .= ' AND c.starts_at < ?';
        $params[] = (new DateTimeImmutable($dateFilters['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('Calendar replacements overview: ' . $exception->getMessage());
    $loadError = 'Přehled náhrad se nepodařilo načíst. Ověřte připojení a dostupnost databázových sloupců pro náhradní termíny.';
}

$counts = array_fill_keys(array_keys($statusLabels), 0);
foreach ($rows as &$row) {
    $row['state'] = calendarReplacementState($row, $now);
    $counts[$row['state']['key']]++;
    $counts['all']++;
    if (in_array($row['state']['key'], ['open', 'soon', 'pending', 'missing'], true)) {
        $counts['attention']++;
    }
}
unset($row);
$filteredRows = array_values(array_filter($rows, static function (array $row) use ($status): bool {
    if ($status === 'all') return true;
    if ($status === 'attention') return in_array($row['state']['key'], ['open', 'soon', 'pending', 'missing'], true);
    if ($status === 'open') return in_array($row['state']['key'], ['open', 'soon'], true);
    return $row['state']['key'] === $status;
}));
usort($filteredRows, static function (array $left, array $right): int {
    $priority = $left['state']['priority'] <=> $right['state']['priority'];
    if ($priority !== 0) return $priority;
    $deadline = strcmp((string)($left['replacement_deadline_at'] ?? ''), (string)($right['replacement_deadline_at'] ?? ''));
    if ($deadline !== 0 && in_array($left['state']['key'], ['open', 'soon'], true)) return $deadline;
    return strcmp((string)$right['starts_at'], (string)$left['starts_at']) ?: ((int)$right['id'] <=> (int)$left['id']);
});
$pageSize = 40;
$pageCount = max(1, (int)ceil(count($filteredRows) / $pageSize));
$page = min($pageCount, max(1, (int)($_GET['page'] ?? 1)));
$visibleRows = array_slice($filteredRows, ($page - 1) * $pageSize, $pageSize);
$filterParams = ['athlete_id' => $athleteId, 'status' => $status, 'from' => $dateFilters['from'], 'to' => $dateFilters['to']];
$pageUrl = static function (array $overrides) use ($filterParams): string {
    return BASE_URL . '/calendar_replacements.php?' . http_build_query(array_merge($filterParams, $overrides));
};

renderHeader('Náhrady tréninků');
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h2 class="mb-0"><i class="fas fa-calendar-check me-2 text-warning"></i>Náhrady tréninků</h2>
    <div class="d-flex align-items-center gap-2">
        <span class="small text-muted">Stav k <?= h($now->format('d.m.Y H:i')) ?></span>
        <a href="<?= h($pageUrl(['page' => $page])) ?>" class="btn btn-outline-secondary btn-sm" title="Obnovit přehled" aria-label="Obnovit přehled"><i class="fas fa-rotate"></i></a>
        <a href="<?= BASE_URL ?>/calendar.php" class="btn btn-outline-dark btn-sm"><i class="fas fa-calendar-alt me-1"></i>Kalendář</a>
    </div>
</div>
<?php if ($loadError !== null): ?>
    <div class="alert alert-danger" role="alert"><?= h($loadError) ?></div>
<?php else: ?>
<div class="row g-3 py-3 border-top border-bottom mb-3">
    <?php foreach ([
        ['attention', 'K řešení', 'text-dark', $counts['attention']],
        ['soon', 'Končí do 48 hodin', 'text-danger', $counts['soon']],
        ['pending', 'Čeká na schválení', 'text-warning', $counts['pending']],
        ['approved', 'Náhrada schválena', 'text-success', $counts['approved']],
        ['expired', 'Lhůta propadla', 'text-secondary', $counts['expired']],
    ] as [$summaryStatus, $summaryLabel, $summaryColor, $summaryCount]): ?>
        <div class="col-6 col-lg">
            <a href="<?= h($pageUrl(['status' => $summaryStatus, 'page' => 1])) ?>" class="text-decoration-none <?= h($summaryColor) ?>">
                <div class="small"><?= h($summaryLabel) ?></div>
                <div class="fs-3 fw-semibold"><?= (int)$summaryCount ?></div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (!$revocationSchemaReady && $loadError === null): ?>
    <div class="alert alert-warning">Zrušení nároků je dostupné po aktualizaci databáze administrátorem.</div>
<?php endif; ?>
<form method="get" class="row g-2 align-items-end mb-4">
    <div class="col-12 col-md-3">
        <label for="replacementAthlete" class="form-label small">Sportovec</label>
        <select id="replacementAthlete" name="athlete_id" class="form-select form-select-sm">
            <option value="0">Všichni sportovci</option>
            <?php foreach ($athletes as $athlete): ?>
                <option value="<?= (int)$athlete['id'] ?>" <?= $athleteId === (int)$athlete['id'] ? 'selected' : '' ?>><?= h(trim($athlete['last_name'] . ' ' . $athlete['first_name'])) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label for="replacementStatus" class="form-label small">Stav náhrady</label>
        <select id="replacementStatus" name="status" class="form-select form-select-sm">
            <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                <option value="<?= h($statusKey) ?>" <?= $status === $statusKey ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label for="replacementFrom" class="form-label small">Původní termín od</label>
        <input id="replacementFrom" type="date" name="from" value="<?= h($dateFilters['from']) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-6 col-md-2">
        <label for="replacementTo" class="form-label small">Původní termín do</label>
        <input id="replacementTo" type="date" name="to" value="<?= h($dateFilters['to']) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-12 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-dark btn-sm"><i class="fas fa-filter me-1"></i>Filtrovat</button>
        <a href="<?= BASE_URL ?>/calendar_replacements.php" class="btn btn-outline-secondary btn-sm" title="Zrušit filtry" aria-label="Zrušit filtry"><i class="fas fa-filter-circle-xmark"></i></a>
    </div>
</form>
<?php if ($loadError === null): ?>
<div class="small text-muted mb-2"><?= count($filteredRows) ?> záznamů</div>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-light"><tr><th>Sportovec</th><th>Původní termín</th><th>Zrušení</th><th>Stav / lhůta rezervace</th><th>Náhradní slot</th><th>Úhrada původního termínu</th><th>Akce</th></tr></thead>
        <tbody>
        <?php foreach ($visibleRows as $row): ?>
            <tr>
                <td>
                    <?php if (!empty($row['first_name']) || !empty($row['last_name'])): ?>
                        <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$row['athlete_id'] ?>" class="fw-semibold text-decoration-none"><?= h(trim($row['last_name'] . ' ' . $row['first_name'])) ?></a>
                    <?php else: ?>
                        <span class="text-muted">Sportovec již není dostupný</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="fw-semibold text-nowrap"><?= h(formatDateTime($row['starts_at'])) ?></div>
                    <div class="small text-muted"><?= h($row['location'] ?: 'Bez místa') ?></div>
                    <?php if ($row['cancellation_scope'] === 'pair_exit'): ?><div class="small text-muted">Párová účast</div><?php endif; ?>
                </td>
                <td>
                    <div><?= $row['canceled_by'] === 'coach' ? 'Trenér' : 'Sportovec' ?></div>
                    <?php if ($row['canceled_by'] === 'athlete' && !empty($row['actor_last_name'])): ?><div class="small text-muted"><?= h(trim($row['actor_last_name'] . ' ' . $row['actor_first_name'])) ?></div><?php endif; ?>
                    <div class="small text-muted text-nowrap"><?= h(formatDateTime($row['canceled_at'])) ?></div>
                    <?php if ($row['approval_status'] === 'pending'): ?><div class="small text-muted">Neschválená žádost</div><?php endif; ?>
                </td>
                <td>
                    <span class="badge bg-<?= h($row['state']['color']) ?> <?= $row['state']['color'] === 'warning' ? 'text-dark' : '' ?>"><?= h($row['state']['label']) ?></span>
                    <?php if ($row['state']['key'] === 'revoked'): ?>
                        <div class="small text-muted mt-1"><?= h(formatDateTime($row['replacement_revoked_at'])) ?></div>
                        <div class="small text-break"><?= h((string)($row['replacement_revocation_reason'] ?? '')) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($row['replacement_required']) && !empty($row['replacement_deadline_at'])): ?>
                        <div class="small mt-1 text-nowrap">Do <?= h(formatDateTime($row['replacement_deadline_at'])) ?></div>
                        <?php if (in_array($row['state']['key'], ['open', 'soon'], true)): ?>
                            <div class="small fw-semibold <?= $row['state']['key'] === 'soon' ? 'text-danger' : '' ?>">Zbývá <?= h(calendarReplacementRemaining($row['replacement_deadline_at'], $now)) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['replacement_id'])): ?>
                        <a class="fw-semibold text-nowrap" href="<?= BASE_URL ?>/calendar.php?tab=week&amp;focus_date=<?= h(substr($row['replacement_starts_at'], 0, 10)) ?>&amp;focus_event=<?= (int)$row['replacement_id'] ?>"><?= h(formatDateTime($row['replacement_starts_at'])) ?></a>
                        <div class="small text-muted"><?= h($row['replacement_location'] ?: 'Bez místa') ?></div>
                    <?php elseif ($row['state']['key'] === 'missing'): ?>
                        <span class="small text-danger">Nutná kontrola evidence</span>
                    <?php elseif (in_array($row['state']['key'], ['open', 'soon'], true)): ?>
                        <span class="small text-muted">Dosud nevybrán</span>
                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                </td>
                <td>
                    <?php $paymentStatus = (string)$row['payment_status_snapshot']; ?>
                    <div class="small <?= $paymentStatus === 'paid' ? 'text-success' : 'text-muted' ?>"><?= $paymentStatus === 'paid' ? 'Při zrušení uhrazeno' : ($paymentStatus === 'pending' ? 'Při zrušení neuhrazeno' : 'Bez vystavené výzvy při zrušení') ?></div>
                    <?php if ($paymentStatus !== 'none' && !empty($row['billing_month']) && !empty($row['athlete_id'])): ?>
                        <a class="small" href="<?= BASE_URL ?>/payment_receipt.php?month=<?= h(substr($row['billing_month'], 0, 7)) ?>&amp;athlete_id=<?= (int)$row['athlete_id'] ?>&amp;issue=<?= max(1, (int)$row['billing_issue_number']) ?>">Výzva <?= h(date('m/Y', strtotime($row['billing_month']))) ?> #<?= max(1, (int)$row['billing_issue_number']) ?></a>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['replacement_required']) && empty($row['replacement_revoked_at']) && empty($row['replacement_id'])): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm js-revoke-replacement"
                                <?= !$revocationSchemaReady ? 'disabled' : '' ?>
                                data-cancellation-id="<?= (int)$row['id'] ?>"
                                data-athlete="<?= h(trim((string)($row['last_name'] ?? '') . ' ' . (string)($row['first_name'] ?? ''))) ?>"
                                data-original-start="<?= h(formatDateTime($row['starts_at'])) ?>">
                            <i class="fas fa-ban me-1"></i>Zrušit nárok na náhradu
                        </button>
                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($visibleRows === []): ?><tr><td colspan="7" class="text-center text-muted py-4">Pro vybrané filtry nejsou žádné záznamy.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php if ($pageCount > 1): ?>
<nav aria-label="Stránkování náhrad" class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <?php if ($page > 1): ?><a class="btn btn-outline-secondary btn-sm" href="<?= h($pageUrl(['page' => $page - 1])) ?>"><i class="fas fa-chevron-left me-1"></i>Předchozí</a><?php else: ?><span></span><?php endif; ?>
    <span class="small text-muted">Strana <?= $page ?> / <?= $pageCount ?></span>
    <?php if ($page < $pageCount): ?><a class="btn btn-outline-secondary btn-sm" href="<?= h($pageUrl(['page' => $page + 1])) ?>">Další<i class="fas fa-chevron-right ms-1"></i></a><?php else: ?><span></span><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php if ($revocationSchemaReady): ?>
<div class="modal fade" id="revokeReplacementModal" tabindex="-1" aria-labelledby="revokeReplacementTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" action="<?= h($pageUrl(['page' => $page])) ?>" class="modal-content" id="revokeReplacementForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="revoke_replacement">
            <input type="hidden" name="cancellation_id" id="revokeCancellationId">
            <div class="modal-header">
                <h5 class="modal-title" id="revokeReplacementTitle">Zrušit nárok na náhradu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <div class="fw-semibold mb-2" id="revokeReplacementContext"></div>
                <p>Nárok bude uzavřen a sportovec jej nebude moci použít pro nový slot. Původní výzva i platby zůstávají beze změny.</p>
                <label class="form-label" for="revokeReplacementReason">Důvod zrušení</label>
                <textarea class="form-control" id="revokeReplacementReason" name="reason" rows="3" maxlength="1000" required></textarea>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="confirm_revocation" value="1" id="confirmRevocation" required>
                    <label class="form-check-label" for="confirmRevocation">Potvrzuji zrušení nároku bez změny plateb.</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zpět</button>
                <button type="submit" class="btn btn-danger" id="revokeReplacementSubmit"><i class="fas fa-ban me-1"></i>Zrušit nárok</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.getElementById('revokeReplacementModal');
    const form = document.getElementById('revokeReplacementForm');
    const submitButton = document.getElementById('revokeReplacementSubmit');
    const modal = new bootstrap.Modal(modalElement);
    document.querySelectorAll('.js-revoke-replacement').forEach((button) => {
        button.addEventListener('click', () => {
            form.reset();
            submitButton.disabled = false;
            document.getElementById('revokeCancellationId').value = button.dataset.cancellationId;
            document.getElementById('revokeReplacementContext').textContent = `${button.dataset.athlete || 'Sportovec'}: původní termín ${button.dataset.originalStart}`;
            modal.show();
        });
    });
    modalElement.addEventListener('shown.bs.modal', () => document.getElementById('revokeReplacementReason').focus());
    form.addEventListener('submit', (event) => {
        const reason = document.getElementById('revokeReplacementReason');
        if (!reason.value.trim()) {
            event.preventDefault();
            reason.focus();
            return;
        }
        submitButton.disabled = true;
    });
    window.addEventListener('pageshow', () => { submitButton.disabled = false; });
});
</script>
<?php endif; ?>
<?php renderFooter(); ?>