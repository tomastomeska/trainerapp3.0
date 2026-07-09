<?php
// admin/login_message.php – správa hlášky po přihlášení trenéra
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();

$pdo = getDB();

// Načíst aktuální hlášku
$msg = $pdo->query('SELECT * FROM login_message ORDER BY id DESC LIMIT 1')->fetch();

$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Neplatný bezpečnostní token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $newMessage = trim($_POST['message'] ?? '');
            if ($newMessage === '') {
                $error = 'Zpráva nesmí být prázdná.';
            } else {
                if ($msg) {
                    // Pokud se zpráva změnila, inkrementujeme verzi (trenéři uvidí znovu)
                    $changed = ($newMessage !== $msg['message']);
                    $newVersion = $changed ? ($msg['version'] + 1) : $msg['version'];
                    $pdo->prepare('UPDATE login_message SET message = ?, version = ? WHERE id = ?')
                        ->execute([$newMessage, $newVersion, $msg['id']]);
                    if ($changed) {
                        // Smazat záznamy o skrytí – všichni trenéři uvidí novou zprávu
                        $pdo->prepare('DELETE FROM coach_message_seen WHERE message_version < ?')
                            ->execute([$newVersion]);
                        $success = 'Zpráva byla aktualizována. Trenéři ji uvidí při příštím přihlášení.';
                    } else {
                        $success = 'Zpráva byla uložena (obsah zprávy se nezměnil, trenéři ji neuvidí znovu).';
                    }
                } else {
                    $pdo->prepare('INSERT INTO login_message (message, version) VALUES (?, 1)')
                        ->execute([$newMessage]);
                    $success = 'Zpráva byla vytvořena. Trenéři ji uvidí při příštím přihlášení.';
                }
                // Znovu načíst
                $msg = $pdo->query('SELECT * FROM login_message ORDER BY id DESC LIMIT 1')->fetch();
            }
        } elseif ($action === 'delete') {
            if ($msg) {
                $pdo->prepare('DELETE FROM coach_message_seen WHERE message_version <= ?')
                    ->execute([$msg['version']]);
                $pdo->prepare('DELETE FROM login_message WHERE id = ?')
                    ->execute([$msg['id']]);
                $msg     = null;
                $success = 'Zpráva byla smazána. Trenérům se již nebude zobrazovat.';
            }
        }
    }
}

renderAdminHeader('Hláška po přihlášení');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-bell me-2" style="color:#a78bfa"></i>Hláška po přihlášení
    </h4>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-1"></i><?= h($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle me-1"></i><?= h($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-pen me-2"></i>
        <?= $msg ? 'Upravit zprávu' : 'Vytvořit novou zprávu' ?>
        <?php if ($msg): ?>
        <span class="badge ms-2" style="background:#7c3aed;font-size:.75rem">
            verze <?= (int)$msg['version'] ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <div class="mb-3">
                <label for="messageInput" class="form-label fw-semibold">Text zprávy</label>
                <textarea name="message" id="messageInput" rows="6"
                          class="form-control"
                          placeholder="Napište zprávu, která se zobrazí trenérům po přihlášení…"
                          required><?= h($msg['message'] ?? '') ?></textarea>
                <div class="form-text">
                    <strong>První řádek</strong> bude zobrazen jako tučný nadpis, zbytek jako normální text.
                    Při změně obsahu se automaticky zvýší verze a trenéři, kteří zvolili „příště nezobrazovat", uvidí zprávu znovu.
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn fw-bold"
                        style="background:#7c3aed;color:#fff;border:none">
                    <i class="fas fa-save me-1"></i><?= $msg ? 'Uložit změny' : 'Vytvořit zprávu' ?>
                </button>
                <?php if ($msg): ?>
                <button type="submit" form="deleteForm" class="btn btn-outline-danger"
                        onclick="return confirm('Opravdu smazat hlášku? Trenérům se přestane zobrazovat.')">
                    <i class="fas fa-trash me-1"></i>Smazat zprávu
                </button>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($msg): ?>
        <form method="post" id="deleteForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header fw-bold" style="background:#1e1e2e;color:#fff">
        <i class="fas fa-eye me-2"></i>Náhled (jak uvidí trenér)
    </div>
    <div class="card-body">
        <?php
        $pvLines = explode("\n", $msg['message'], 2);
        $pvTitle = trim($pvLines[0]);
        $pvBody  = isset($pvLines[1]) ? trim($pvLines[1]) : '';
        ?>
        <div class="modal-content border-0 shadow" style="max-width:480px;margin:0 auto">
            <div class="modal-header" style="background:#1e1e2e;border-bottom:2px solid #f59e0b;border-radius:.375rem .375rem 0 0">
                <span style="font-size:1.05rem;font-weight:700;color:#f59e0b">
                    <i class="fas fa-triangle-exclamation me-2"></i>Důležité upozornění
                </span>
            </div>
            <div class="modal-body">
                <?php if ($pvTitle !== ''): ?>
                <p class="fw-bold mb-2"><?= h($pvTitle) ?></p>
                <?php endif; ?>
                <?php if ($pvBody !== ''): ?>
                <p class="mb-0" style="white-space:pre-wrap;color:#374151"><?= h($pvBody) ?></p>
                <?php endif; ?>
            </div>
            <div class="modal-footer flex-column align-items-start gap-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="previewDismiss" disabled>
                    <label class="form-check-label text-muted" for="previewDismiss">
                        Příště nezobrazovat
                    </label>
                </div>
                <button class="btn btn-warning w-100" disabled>
                    <i class="fas fa-check me-1"></i>Rozumím
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// renderAdminFooter
echo '</div></div></div>';
echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
echo '</body></html>';
?>
