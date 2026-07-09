<?php
// admin/podpora.php - sprava ticketu podpory
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
$pdo = getDB();

function supportStatusTextForUser(string $status): string {
    return match ($status) {
        'new' => 'Nový',
        'open' => 'Rozpracovaný',
        'resolved' => 'Vyřešený',
        default => 'Neznámý',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/podpora.php');
    }

    $ticketId = intParam($_POST, 'ticket_id');
    $newStatus = (string)($_POST['status'] ?? 'new');
    $adminComment = trim((string)($_POST['admin_comment'] ?? ''));
    $allowed = ['new', 'open', 'resolved'];

    if ($ticketId <= 0 || !in_array($newStatus, $allowed, true)) {
        flash('danger', 'Neplatná data formuláře.');
        redirect(BASE_URL . '/admin/podpora.php');
    }

    if (in_array($newStatus, ['open', 'resolved'], true) && $adminComment === '') {
        flash('danger', 'Pro stav Rozpracovaný/Vyřešený je nutné vyplnit vyjádření administrátora.');
        redirect(BASE_URL . '/admin/podpora.php?id=' . $ticketId);
    }

    $ticketStmt = $pdo->prepare(
        'SELECT id, reporter_type, coach_id, athlete_id, reporter_name, subject, admin_note
         FROM support_tickets
         WHERE id = ?
         LIMIT 1'
    );
    $ticketStmt->execute([$ticketId]);
    $ticket = $ticketStmt->fetch();

    if (!$ticket) {
        flash('danger', 'Ticket nebyl nalezen.');
        redirect(BASE_URL . '/admin/podpora.php');
    }

    $existingNote = trim((string)($ticket['admin_note'] ?? ''));
    $newCombinedNote = $existingNote;

    if ($adminComment !== '') {
        $admin = getCurrentAdmin();
        $adminName = (string)($admin['name'] ?? $admin['username'] ?? 'Administrátor');
        $entryHeader = '[' . date('d.m.Y H:i') . ' | ' . supportStatusTextForUser($newStatus) . ' | ' . $adminName . ']';
        $entryBody = $entryHeader . "\n" . $adminComment;
        $newCombinedNote = $existingNote === '' ? $entryBody : ($existingNote . "\n\n" . $entryBody);
    }

    $stmt = $pdo->prepare('UPDATE support_tickets SET status = ?, admin_note = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$newStatus, $newCombinedNote, $ticketId]);

    // Informovat oznamovatele přes interní zprávy aplikace.
    if ($adminComment !== '') {
        $msgSubject = 'Podpora #' . (int)$ticket['id'] . ' - ' . supportStatusTextForUser($newStatus);
        $msgBody = "Aktualizace vašeho požadavku podpory.\n\n"
            . "Ticket: #" . (int)$ticket['id'] . "\n"
            . "Původní předmět: " . (string)$ticket['subject'] . "\n"
            . "Nový stav: " . supportStatusTextForUser($newStatus) . "\n\n"
            . "Vyjádření administrátora:\n" . $adminComment;

        if (($ticket['reporter_type'] ?? '') === 'coach' && !empty($ticket['coach_id'])) {
            createCoachSystemMessage((int)$ticket['coach_id'], $msgSubject, $msgBody, true);
        } elseif (($ticket['reporter_type'] ?? '') === 'athlete' && !empty($ticket['athlete_id'])) {
            createAthleteNotification((int)$ticket['athlete_id'], $msgSubject, $msgBody);
        }
    }

    flash('success', $adminComment !== ''
        ? 'Stav ticketu byl aktualizován a vyjádření bylo odesláno oznamovateli.'
        : 'Stav ticketu byl aktualizován.'
    );
    redirect(BASE_URL . '/admin/podpora.php?id=' . $ticketId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_coach_access') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/podpora.php');
    }

    $ticketId = intParam($_POST, 'ticket_id');
    if ($ticketId <= 0) {
        flash('danger', 'Neplatná data formuláře.');
        redirect(BASE_URL . '/admin/podpora.php');
    }

    $ticketStmt = $pdo->prepare(
        'SELECT id, reporter_type, coach_id, athlete_id, reporter_name, subject, admin_note, reporter_email
         FROM support_tickets
         WHERE id = ?
         LIMIT 1'
    );
    $ticketStmt->execute([$ticketId]);
    $ticket = $ticketStmt->fetch();

    if (!$ticket) {
        flash('danger', 'Ticket nebyl nalezen.');
        redirect(BASE_URL . '/admin/podpora.php');
    }

    if (!isCoachAccessRequest($ticket)) {
        flash('danger', 'Tato akce je dostupná pouze pro žádost o přístup trenéra.');
        redirect(BASE_URL . '/admin/podpora.php?id=' . $ticketId);
    }

    $existingNote = trim((string)($ticket['admin_note'] ?? ''));
    $admin = getCurrentAdmin();
    $adminName = (string)($admin['name'] ?? $admin['username'] ?? 'Administrátor');
    $entryHeader = '[' . date('d.m.Y H:i') . ' | Zamítnuto | ' . $adminName . ']';
    $entryBody = $entryHeader . "\n" . 'Žádost o přístup trenéra byla zamítnuta. Účet nebyl vytvořen.';
    $newCombinedNote = $existingNote === '' ? $entryBody : ($existingNote . "\n\n" . $entryBody);

    $stmt = $pdo->prepare('UPDATE support_tickets SET status = ?, admin_note = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute(['resolved', $newCombinedNote, $ticketId]);

    flash('success', 'Žádost o přístup trenéra byla zamítnuta a ticket byl uzavřen.');
    redirect(BASE_URL . '/admin/podpora.php?id=' . $ticketId);
}

$filter = (string)($_GET['status'] ?? 'new');
$allowedFilter = ['all', 'new', 'open', 'resolved'];
if (!in_array($filter, $allowedFilter, true)) {
    $filter = 'all';
}

$selectedId = intParam($_GET, 'id');

$whereSql = '';
$params = [];
if ($filter !== 'all') {
    $whereSql = 'WHERE t.status = ?';
    $params[] = $filter;
}

$listStmt = $pdo->prepare(
    "SELECT
        t.id, t.reporter_type, t.reporter_name, t.reporter_email,
        t.subject, t.issue_type, t.page_url, t.status, t.created_at,
        t.coach_id, t.athlete_id,
        c.name AS coach_name, c.username AS coach_username,
        a.first_name, a.last_name
     FROM support_tickets t
     LEFT JOIN coaches c ON c.id = t.coach_id
     LEFT JOIN athletes a ON a.id = t.athlete_id
     {$whereSql}
     ORDER BY
       CASE t.status WHEN 'new' THEN 1 WHEN 'open' THEN 2 ELSE 3 END,
       t.created_at DESC
     LIMIT 300"
);
$listStmt->execute($params);
$tickets = $listStmt->fetchAll();

if ($selectedId <= 0 && !empty($tickets)) {
    $selectedId = (int)$tickets[0]['id'];
}

$selectedTicket = null;
if ($selectedId > 0) {
    $detailStmt = $pdo->prepare(
        'SELECT
            t.*,
            c.name AS coach_name, c.username AS coach_username,
            a.first_name, a.last_name
         FROM support_tickets t
         LEFT JOIN coaches c ON c.id = t.coach_id
         LEFT JOIN athletes a ON a.id = t.athlete_id
         WHERE t.id = ?
         LIMIT 1'
    );
    $detailStmt->execute([$selectedId]);
    $selectedTicket = $detailStmt->fetch();
}

$statusCounts = [
    'new' => 0,
    'open' => 0,
    'resolved' => 0,
];
$countRows = $pdo->query('SELECT status, COUNT(*) AS cnt FROM support_tickets GROUP BY status')->fetchAll();
foreach ($countRows as $row) {
    $status = (string)$row['status'];
    if (isset($statusCounts[$status])) {
        $statusCounts[$status] = (int)$row['cnt'];
    }
}

function supportStatusBadge(string $status): string {
    return match ($status) {
        'new' => 'danger',
        'open' => 'warning text-dark',
        'resolved' => 'success',
        default => 'secondary',
    };
}

function supportStatusLabel(string $status): string {
    return match ($status) {
        'new' => 'Nový',
        'open' => 'Rozpracovaný',
        'resolved' => 'Vyřešený',
        default => 'Neznámý',
    };
}

function isCoachAccessRequest(array $ticket): bool {
    $subject = mb_strtolower((string)($ticket['subject'] ?? ''), 'UTF-8');
    $issueType = mb_strtolower((string)($ticket['issue_type'] ?? ''), 'UTF-8');

    $subjectMatches =
        str_contains($subject, 'žádost o přístup trenéra')
        || str_contains($subject, 'zadost o pristup trenera');

    $issueMatches =
        str_contains($issueType, 'žádost o přístup')
        || str_contains($issueType, 'zadost o pristup');

    // Reporter type can vary (coach/guest/empty) depending on request entry point.
    return $subjectMatches && $issueMatches;
}

renderAdminHeader('Podpora');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-life-ring me-2" style="color:#a78bfa"></i>Podpora
    </h4>
</div>

<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>/admin/podpora.php?status=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-dark' : 'btn-outline-dark' ?>">
                Vše (<?= array_sum($statusCounts) ?>)
            </a>
            <a href="<?= BASE_URL ?>/admin/podpora.php?status=new" class="btn btn-sm <?= $filter === 'new' ? 'btn-danger' : 'btn-outline-danger' ?>">
                Nové (<?= $statusCounts['new'] ?>)
            </a>
            <a href="<?= BASE_URL ?>/admin/podpora.php?status=open" class="btn btn-sm <?= $filter === 'open' ? 'btn-warning' : 'btn-outline-warning' ?>">
                Rozpracované (<?= $statusCounts['open'] ?>)
            </a>
            <a href="<?= BASE_URL ?>/admin/podpora.php?status=resolved" class="btn btn-sm <?= $filter === 'resolved' ? 'btn-success' : 'btn-outline-success' ?>">
                Vyřešené (<?= $statusCounts['resolved'] ?>)
            </a>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold">
                <i class="fas fa-inbox me-1"></i>Seznam ticketů
            </div>
            <div class="card-body p-0" style="max-height:72vh;overflow:auto;">
                <?php if (empty($tickets)): ?>
                <div class="p-4 text-muted text-center">Zatím nejsou žádné tickety.</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($tickets as $t): ?>
                    <a href="<?= BASE_URL ?>/admin/podpora.php?status=<?= h($filter) ?>&id=<?= (int)$t['id'] ?>"
                       class="list-group-item list-group-item-action <?= (int)$t['id'] === (int)$selectedId ? 'active' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold">#<?= (int)$t['id'] ?> - <?= h($t['subject']) ?></div>
                                <small class="<?= (int)$t['id'] === (int)$selectedId ? '' : 'text-muted' ?>">
                                    <?= h($t['reporter_name']) ?>
                                    (<?= $t['reporter_type'] === 'athlete' ? 'Sportovec' : 'Trenér' ?>)
                                </small>
                                <br>
                                <small class="<?= (int)$t['id'] === (int)$selectedId ? '' : 'text-muted' ?>">
                                    <?= h($t['issue_type']) ?>
                                </small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?= supportStatusBadge((string)$t['status']) ?>"><?= supportStatusLabel((string)$t['status']) ?></span>
                                <div><small class="<?= (int)$t['id'] === (int)$selectedId ? '' : 'text-muted' ?>"><?= formatDateTime((string)$t['created_at']) ?></small></div>
                                <?php if (isCoachAccessRequest($t) && (string)$t['status'] !== 'resolved'): ?>
                                <div class="mt-2">
                                    <a href="<?= BASE_URL ?>/admin/coach_add.php?from_ticket_id=<?= (int)$t['id'] ?>&name=<?= rawurlencode((string)$t['reporter_name']) ?>&email=<?= rawurlencode((string)$t['reporter_email']) ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-user-plus me-1"></i>Schválit a vytvořit trenéra
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fas fa-circle-info me-1"></i>Detail ticketu</span>
                <?php if ($selectedTicket): ?>
                <span class="badge bg-<?= supportStatusBadge((string)$selectedTicket['status']) ?>">#<?= (int)$selectedTicket['id'] ?> - <?= supportStatusLabel((string)$selectedTicket['status']) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$selectedTicket): ?>
                <p class="text-muted mb-0">Vyberte tiket ze seznamu vlevo.</p>
                <?php else: ?>
                <div class="mb-3">
                    <div class="fw-semibold fs-5 mb-1"><?= h($selectedTicket['subject']) ?></div>
                    <div class="text-muted small">Vytvořeno: <?= formatDateTime((string)$selectedTicket['created_at']) ?></div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="border rounded p-2 h-100">
                            <div class="text-muted small">Odesílatel</div>
                            <div class="fw-semibold"><?= h((string)$selectedTicket['reporter_name']) ?></div>
                            <div class="small text-muted"><?= $selectedTicket['reporter_type'] === 'athlete' ? 'Sportovec' : 'Trenér' ?></div>
                            <?php if (!empty($selectedTicket['reporter_email'])): ?>
                            <div class="small mt-1"><a href="mailto:<?= h((string)$selectedTicket['reporter_email']) ?>"><?= h((string)$selectedTicket['reporter_email']) ?></a></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-2 h-100">
                            <div class="text-muted small">Typ problému</div>
                            <div class="fw-semibold"><?= h((string)$selectedTicket['issue_type']) ?></div>
                            <?php if (!empty($selectedTicket['page_url'])): ?>
                            <div class="small mt-2">
                                <span class="text-muted">Stránka při odeslání:</span><br>
                                <a href="<?= h((string)$selectedTicket['page_url']) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= h((string)$selectedTicket['page_url']) ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold mb-1">Popis problému</label>
                    <div class="border rounded p-3" style="white-space:pre-wrap;"><?= h((string)$selectedTicket['description']) ?></div>
                </div>

                <?php if (!empty($selectedTicket['admin_note'])): ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-1">Historie vyjádření administrace</label>
                    <div class="border rounded p-3 bg-light" style="white-space:pre-wrap;"><?= h((string)$selectedTicket['admin_note']) ?></div>
                </div>
                <?php endif; ?>

                <?php if (isCoachAccessRequest($selectedTicket) && (string)$selectedTicket['status'] !== 'resolved'): ?>
                <div class="mb-3 border rounded p-3 bg-warning-subtle">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold">Žádost o přístup trenéra</div>
                            <div class="small text-muted">Tento tiket můžete rovnou schválit a z údajů vytvořit trenéra.</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="<?= BASE_URL ?>/admin/coach_add.php?from_ticket_id=<?= (int)$selectedTicket['id'] ?>&name=<?= rawurlencode((string)$selectedTicket['reporter_name']) ?>&email=<?= rawurlencode((string)$selectedTicket['reporter_email']) ?>"
                               class="btn btn-sm btn-primary">
                                <i class="fas fa-user-plus me-1"></i>Schválit a vytvořit trenéra
                            </a>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="reject_coach_access">
                                <input type="hidden" name="ticket_id" value="<?= (int)$selectedTicket['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Opravdu chcete tuto žádost zamítnout?');">
                                    <i class="fas fa-ban me-1"></i>Zamítnout registraci
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($selectedTicket['screenshot_path'])): ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold mb-1">Příloha / screenshot</label>
                    <div class="border rounded p-2">
                        <a href="<?= BASE_URL ?>/uploads/support/<?= rawurlencode((string)$selectedTicket['screenshot_path']) ?>" target="_blank" rel="noopener noreferrer">
                            <?php if (!empty($selectedTicket['screenshot_name'])): ?>
                                <?= h((string)$selectedTicket['screenshot_name']) ?>
                            <?php else: ?>
                                Otevřít screenshot
                            <?php endif; ?>
                        </a>
                        <div class="mt-2">
                            <img src="<?= BASE_URL ?>/uploads/support/<?= rawurlencode((string)$selectedTicket['screenshot_path']) ?>"
                                 alt="Screenshot ticketu"
                                 style="max-width:100%;height:auto;border-radius:6px;border:1px solid #e5e7eb;">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mb-2">
                    <form method="post" class="d-flex flex-wrap align-items-center gap-2">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="ticket_id" value="<?= (int)$selectedTicket['id'] ?>">
                        <label for="statusSelect" class="fw-semibold me-1">Stav ticketu:</label>
                        <select id="statusSelect" class="form-select" name="status" style="max-width:220px;">
                            <option value="new" <?= $selectedTicket['status'] === 'new' ? 'selected' : '' ?>>Nový</option>
                            <option value="open" <?= $selectedTicket['status'] === 'open' ? 'selected' : '' ?>>Rozpracovaný</option>
                            <option value="resolved" <?= $selectedTicket['status'] === 'resolved' ? 'selected' : '' ?>>Vyřešený</option>
                        </select>
                        <textarea id="adminComment" name="admin_comment" class="form-control" rows="3" style="min-width:320px;max-width:100%;" placeholder="Vyjádření administrátora (povinné pro Rozpracovaný/Vyřešený)"></textarea>
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-save me-1"></i>Uložit stav
                        </button>
                    </form>
                    <small class="text-muted d-block mt-2">
                        Každé vyjádření se uloží do historie ticketu a odešle se oznamovateli do zpráv v aplikaci.
                    </small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('form input[name="action"][value="update_status"]');
    if (!form) {
        return;
    }
    var updateForm = form.closest('form');
    if (!updateForm) {
        return;
    }

    updateForm.addEventListener('submit', function (e) {
        var statusEl = updateForm.querySelector('#statusSelect');
        var commentEl = updateForm.querySelector('#adminComment');
        if (!statusEl || !commentEl) {
            return;
        }

        var status = statusEl.value;
        var comment = commentEl.value.trim();
        if ((status === 'open' || status === 'resolved') && comment === '') {
            e.preventDefault();
            alert('Pro stav Rozpracovaný/Vyřešený je nutné vyplnit vyjádření administrátora.');
            commentEl.focus();
        }
    });
});
</script>

<?php renderAdminFooter(); ?>
