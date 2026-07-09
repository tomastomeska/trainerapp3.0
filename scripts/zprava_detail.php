<?php
// zprava_detail.php – zobrazení a potvrzení přečtení zprávy
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo     = getDB();

$id = intParam($_GET, 'id');

// Načti zprávu – jen pokud je trenér příjemcem
$stmt = $pdo->prepare("
    SELECT m.*, r.read_at, r.status AS folder, r.id AS recipient_id
    FROM admin_messages m
    JOIN admin_message_recipients r ON r.message_id = m.id AND r.coach_id = ?
    WHERE m.id = ?
");
$stmt->execute([$coachId, $id]);
$message = $stmt->fetch();

if (!$message) {
    flash('danger', 'Zpráva nebyla nalezena.');
    redirect(BASE_URL . '/zpravy.php');
}

// Načti akční tlačítka
$actStmt = $pdo->prepare("
    SELECT a.*, l.pressed_at, l.signature_data
    FROM message_actions a
    LEFT JOIN message_action_logs l ON l.action_id = a.id AND l.coach_id = ?
    WHERE a.message_id = ?
    ORDER BY a.sort_order
");
$actStmt->execute([$coachId, $id]);
$actions = $actStmt->fetchAll();
$hasRequiredAction = !empty($actions);

// Detekce: má trenér již stisknuté JAKÉKOLI tlačítko pro tuto zprávu?
$anyPressed = array_filter($actions, fn($a) => $a['pressed_at'] !== null) !== [];

// Zpracování potvrzení přečtení
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_read') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Neplatný token');
    }
    if ($hasRequiredAction) {
        flash('info', 'Tato zpráva se potvrzuje provedením požadované akce nebo podpisu.');
        redirect(BASE_URL . '/zprava_detail.php?id=' . $id);
    }
    if ($message['read_at'] === null) {
        $pdo->prepare("
            UPDATE admin_message_recipients SET read_at = NOW()
            WHERE message_id = ? AND coach_id = ?
        ")->execute([$id, $coachId]);
    }
    $redirect = $_POST['redirect'] ?? (BASE_URL . '/zpravy.php');
    redirect($redirect);
}

$isUnread = $message['read_at'] === null;
$requiresManualConfirm = $isUnread && !$hasRequiredAction;

renderHeader('Zpráva: ' . $message['subject']);
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/zpravy.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h3 class="fw-bold mb-0"><i class="fas fa-envelope-open me-2 text-primary"></i>Zpráva</h3>
</div>

<div class="row justify-content-center">
<div class="col-lg-8">

<?php if ($isUnread): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="fas fa-exclamation-triangle fs-5"></i>
    <?php if ($hasRequiredAction): ?>
    <span><strong>Nepřečtená zpráva.</strong> Pro potvrzení přečtení použijte níže požadované akční tlačítko nebo podpis.</span>
    <?php else: ?>
    <span><strong>Nepřečtená zpráva.</strong> Po přečtení potvrďte přečtení tlačítkem níže – dokud tak neučiníte, nelze stránku opustit.</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <div class="fw-bold fs-5"><?= h($message['subject']) ?></div>
            <div class="text-muted small">
                <i class="fas fa-calendar me-1"></i><?= date('d.m.Y H:i', strtotime($message['sent_at'])) ?>
                &nbsp;&nbsp;<i class="fas fa-user-shield me-1"></i>Administrátor TrainerApp
            </div>
        </div>
        <?php if ($message['read_at']): ?>
        <span class="badge bg-success align-self-center">
            <i class="fas fa-check me-1"></i>Přečteno <?= date('d.m.Y H:i', strtotime($message['read_at'])) ?>
        </span>
        <?php else: ?>
        <span class="badge bg-danger align-self-center">Nepřečteno</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div style="white-space:pre-wrap;font-size:1rem;line-height:1.7"><?= h($message['body']) ?></div>
    </div>
    <?php if ($message['attachment_name']): ?>
    <div class="card-footer">
        <i class="fas fa-paperclip me-1 text-muted"></i>
        <strong>Příloha:</strong>
        <a href="<?= BASE_URL ?>/uploads/messages/<?= rawurlencode($message['attachment_path']) ?>"
           target="_blank" class="ms-1">
            <?= h($message['attachment_name']) ?>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($actions)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-hand-pointer me-2"></i>Požadované akce</div>
    <div class="card-body d-flex flex-wrap gap-3">
    <?php foreach ($actions as $act): ?>
        <?php $done = $act['pressed_at'] !== null; ?>
        <?php if ($act['action_type'] === 'signature'): ?>
            <?php if ($done && $act['signature_data']): ?>
            <div class="text-center">
                <img src="<?= h($act['signature_data']) ?>" class="border rounded mb-1"
                     style="max-width:260px;max-height:100px;background:#fff">
                <div><span class="badge bg-success"><i class="fas fa-check me-1"></i><?= h($act['label']) ?></span></div>
                <small class="text-muted"><?= date('d.m.Y H:i', strtotime($act['pressed_at'])) ?></small>
            </div>
            <?php elseif ($anyPressed && !$done): ?>
            <button class="btn btn-warning disabled opacity-50" disabled>
                <i class="fas fa-signature me-1"></i><?= h($act['label']) ?>
                <small class="d-block" style="font-size:.72rem">Jiná volba již zvolena</small>
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-warning"
                    data-action-id="<?= $act['id'] ?>"
                    data-action-type="signature"
                    data-action-label="<?= h($act['label']) ?>">
                <i class="fas fa-signature me-1"></i><?= h($act['label']) ?>
            </button>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($done): ?>
            <button class="btn btn-success disabled" disabled>
                <i class="fas fa-check me-1"></i><?= h($act['label']) ?>
                <small class="d-block" style="font-size:.72rem"><?= date('d.m.Y H:i', strtotime($act['pressed_at'])) ?></small>
            </button>
            <?php elseif ($anyPressed && !$done): ?>
            <button class="btn btn-secondary disabled opacity-50" disabled>
                <?= h($act['label']) ?>
                <small class="d-block" style="font-size:.72rem">Jiná volba již zvolena</small>
            </button>
            <?php else: ?>
            <button type="button" class="btn btn-primary"
                    data-action-id="<?= $act['id'] ?>"
                    data-action-type="button">
                <?= h($act['label']) ?>
            </button>
            <?php endif; ?>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($requiresManualConfirm): ?>
<div class="card border-danger shadow-sm mb-4" id="confirmCard">
    <div class="card-body text-center py-4">
        <p class="mb-3 fw-semibold">
            <i class="fas fa-hand-point-down me-2 text-danger"></i>
            Potvrďte, že jste zprávu přečetli.
        </p>
        <form id="confirmReadForm" method="post">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="confirm_read">
            <input type="hidden" name="redirect" value="<?= h(BASE_URL . '/zpravy.php') ?>" id="confirmRedirect">
            <button type="submit" class="btn btn-danger btn-lg px-5" id="btnConfirmRead">
                <i class="fas fa-check-circle me-2"></i>Potvrzuji přečtení
            </button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="d-flex gap-2 justify-content-end mb-4">
    <?php if ($message['folder'] === 'inbox'): ?>
    <form method="post" action="<?= BASE_URL ?>/zpravy.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="archive">
        <input type="hidden" name="message_id" value="<?= $id ?>">
        <button class="btn btn-outline-secondary"><i class="fas fa-archive me-1"></i>Archivovat</button>
    </form>
    <form method="post" action="<?= BASE_URL ?>/zpravy.php" onsubmit="return confirm('Přesunout do koše?')">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="message_id" value="<?= $id ?>">
        <button class="btn btn-outline-danger"><i class="fas fa-trash me-1"></i>Smazat</button>
    </form>
    <?php elseif ($message['folder'] === 'archived'): ?>
    <form method="post" action="<?= BASE_URL ?>/zpravy.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="message_id" value="<?= $id ?>">
        <button class="btn btn-outline-primary"><i class="fas fa-inbox me-1"></i>Přesunout do přijatých</button>
    </form>
    <?php else: ?>
    <form method="post" action="<?= BASE_URL ?>/zpravy.php">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="message_id" value="<?= $id ?>">
        <button class="btn btn-outline-primary"><i class="fas fa-inbox me-1"></i>Obnovit</button>
    </form>
    <form method="post" action="<?= BASE_URL ?>/zpravy.php"
          onsubmit="return confirm('Trvale smazat? Tuto akci nelze vrátit.')">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="destroy">
        <input type="hidden" name="message_id" value="<?= $id ?>">
        <button class="btn btn-outline-danger"><i class="fas fa-times me-1"></i>Trvale smazat</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

</div>
</div>

<?php if ($requiresManualConfirm): ?>
<!-- Modal: musíte potvrdit přečtení -->
<div class="modal fade" id="leaveModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Nejprve potvrďte přečtení</h5>
            </div>
            <div class="modal-body">
                Před opuštěním zprávy musíte potvrdit, že jste ji přečetli.
                Stiskněte tlačítko <strong>Potvrzuji přečtení a odcházím</strong>.
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="btnStay">Zůstat na stránce</button>
                <button class="btn btn-danger" id="btnConfirmAndLeave">
                    <i class="fas fa-check-circle me-1"></i>Potvrzuji přečtení a odcházím
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: podpis -->
<div class="modal fade" id="signModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="signModalLabel"><i class="fas fa-signature me-2"></i>Elektronický podpis</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p id="signTouchNotice" class="text-info small mb-2 d-none">
                    <i class="fas fa-info-circle me-1"></i>Podpis lze provést pouze na dotykovém zařízení (tablet, mobil). Na tomto zařízení není dotykový vstup dostupný.
                </p>
                <canvas id="signCanvas" width="460" height="180"
                        style="border:2px solid #dee2e6;border-radius:6px;touch-action:none;max-width:100%;background:#fff"></canvas>
                <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearSign">
                        <i class="fas fa-undo me-1"></i>Vymazat
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="button" class="btn btn-warning" id="btnSubmitSign">
                    <i class="fas fa-check me-1"></i>Potvrdit podpis
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const REQUIRES_MANUAL_CONFIRM  = <?= $requiresManualConfirm ? 'true' : 'false' ?>;
const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
const ACTION_URL = <?= json_encode(BASE_URL . '/api/message_action.php') ?>;
let   confirmed  = false;
let   pendingUrl = null;

window.addEventListener('load', function() {

// ── Prevence odchodu bez potvrzení ─────────────────────────
if (REQUIRES_MANUAL_CONFIRM) {
    const leaveModalEl = document.getElementById('leaveModal');
    const leaveModal   = new bootstrap.Modal(leaveModalEl);

    window.addEventListener('beforeunload', e => {
        if (confirmed) return;
        e.preventDefault();
        e.returnValue = '';
    });

    history.pushState(null, '', location.href);
    window.addEventListener('popstate', () => {
        if (confirmed) return;
        history.pushState(null, '', location.href);
        leaveModal.show();
    });

    document.addEventListener('click', e => {
        if (confirmed) return;
        const link = e.target.closest('a[href]');
        if (!link) return;
        const href = link.href;
        if (!href || href.startsWith('#') || href.startsWith('javascript') || link.target === '_blank') return;
        e.preventDefault();
        pendingUrl = href;
        leaveModal.show();
    }, true);

    document.getElementById('btnConfirmAndLeave').addEventListener('click', async () => {
        await doConfirmRead(pendingUrl || <?= json_encode(BASE_URL . '/zpravy.php') ?>);
    });

    document.getElementById('btnStay').addEventListener('click', () => {
        leaveModal.hide();
        pendingUrl = null;
    });

    document.getElementById('confirmReadForm').addEventListener('submit', async e => {
        e.preventDefault();
        await doConfirmRead(<?= json_encode(BASE_URL . '/zpravy.php') ?>);
    });

    async function doConfirmRead(url) {
        try {
            const fd = new FormData();
            fd.append('action', 'confirm_read');
            fd.append('csrf_token', CSRF_TOKEN);
            await fetch(location.href, { method: 'POST', body: fd });
        } catch(err) {}
        confirmed = true;
        window.location.href = url;
    }
}

// ── Akční tlačítka ─────────────────────────────────────────
document.querySelectorAll('[data-action-id]').forEach(btn => {
    btn.addEventListener('click', async function() {
        const actionId   = this.dataset.actionId;
        const actionType = this.dataset.actionType;
        const label      = this.dataset.actionLabel || '';

        if (actionType === 'signature') {
            openSignModal(actionId, label);
        } else {
            const userConfirmed = await confirmAction(this.textContent.trim());
            if (!userConfirmed) return;
            this.disabled = true;
            const ok = await sendAction(actionId, null);
            if (ok) {
                confirmed = true;
                location.reload();
            }
        }
    });
});

function confirmAction(label) {
    return new Promise(resolve => {
        const msg = 'Opravdu chcete potvrdit volbu:\n"' + label + '"?\n\nTuto volbu nelze po potvrzení změnit.';
        resolve(window.confirm(msg));
    });
}

async function sendAction(actionId, signatureData) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('action_id', actionId);
    if (signatureData) fd.append('signature_data', signatureData);
    try {
        const resp = await fetch(ACTION_URL, { method: 'POST', body: fd });
        return resp.ok;
    } catch(e) { return false; }
}

// ── Podpis ─────────────────────────────────────────────────
let currentSignActionId = null;
const canvas = document.getElementById('signCanvas');
const ctx    = canvas.getContext('2d');
let isDrawing = false, lastX = 0, lastY = 0;

function openSignModal(actionId, label) {
    currentSignActionId = actionId;
    document.getElementById('signModalLabel').textContent = label;
    const isTouch = window.matchMedia('(pointer: coarse)').matches;
    document.getElementById('signTouchNotice').classList.toggle('d-none', isTouch);
    clearCanvas();
    new bootstrap.Modal(document.getElementById('signModal')).show();
}

function clearCanvas() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
}

function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const src = e.touches ? e.touches[0] : e;
    return [(src.clientX - rect.left) * scaleX, (src.clientY - rect.top) * scaleY];
}

canvas.addEventListener('mousedown', e => { isDrawing = true; [lastX, lastY] = getPos(e); });
canvas.addEventListener('touchstart', e => { isDrawing = true; [lastX, lastY] = getPos(e); e.preventDefault(); }, { passive: false });

function draw(e) {
    if (!isDrawing) return;
    const [x, y] = getPos(e);
    ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(x, y);
    ctx.strokeStyle = '#1a1a2e'; ctx.lineWidth = 2.5; ctx.lineCap = 'round';
    ctx.stroke();
    [lastX, lastY] = [x, y];
    if (e.type === 'touchmove') e.preventDefault();
}
canvas.addEventListener('mousemove', draw);
canvas.addEventListener('touchmove', draw, { passive: false });
['mouseup','mouseleave','touchend'].forEach(ev => canvas.addEventListener(ev, () => isDrawing = false));

document.getElementById('btnClearSign').addEventListener('click', clearCanvas);

document.getElementById('btnSubmitSign').addEventListener('click', async function() {
    // Zkontroluj zdali byl podpis nakreslen
    const blank = document.createElement('canvas');
    blank.width = canvas.width; blank.height = canvas.height;
    const bCtx = blank.getContext('2d');
    bCtx.fillStyle = '#fff'; bCtx.fillRect(0, 0, blank.width, blank.height);
    if (canvas.toDataURL() === blank.toDataURL()) {
        alert('Nejprve se prosím podepište.'); return;
    }
    const ok = await sendAction(currentSignActionId, canvas.toDataURL('image/png'));
    if (ok) {
        bootstrap.Modal.getInstance(document.getElementById('signModal')).hide();
        confirmed = true;
        location.reload();
    }
});

}); // end window load
</script>

<?php renderFooter(); ?>