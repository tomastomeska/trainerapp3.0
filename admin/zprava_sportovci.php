<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
$pdo = getDB();

$activeAthletesStmt = $pdo->query('SELECT id, first_name, last_name, email FROM athletes WHERE login_enabled = 1');
$activeAthletes = $activeAthletesStmt->fetchAll();
$activeAthleteIds = array_map(static fn(array $row): int => (int)$row['id'], $activeAthletes);
$errors = [];

$chatTableCheck = $pdo->query("SHOW TABLES LIKE 'admin_athlete_chat_messages'");
$chatTableExists = $chatTableCheck !== false && (bool)$chatTableCheck->fetchColumn();

$individualChatList = [];
if ($chatTableExists) {
    $individualChatList = $pdo->query(
        "SELECT a.id, a.first_name, a.last_name,
                (SELECT body FROM admin_athlete_chat_messages WHERE athlete_id = a.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_body,
                (SELECT created_at FROM admin_athlete_chat_messages WHERE athlete_id = a.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_at,
                (SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE athlete_id = a.id AND sender = 'athlete' AND admin_read_at IS NULL) AS unread_count
         FROM athletes a
         WHERE a.login_enabled = 1
         ORDER BY (last_at IS NULL) ASC, last_at DESC, a.first_name ASC, a.last_name ASC"
    )->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_chat_migration') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/zprava_sportovci.php');
    }

    $oldSecret = $_GET['secret'] ?? null;
    $_GET['secret'] = getCronSecret();
    ob_start();
    require __DIR__ . '/../scripts/migrate_admin_athlete_chat.php';
    $migrationOutput = trim((string)ob_get_clean());
    if ($oldSecret === null) { unset($_GET['secret']); } else { $_GET['secret'] = $oldSecret; }
    $migrationResult = json_decode($migrationOutput, true);
    if (is_array($migrationResult) && !empty($migrationResult['success'])) {
        flash('success', 'Migrace individuálního chatu proběhla úspěšně.');
    } else {
        $message = is_array($migrationResult) ? (string)($migrationResult['error'] ?? 'Neznámá chyba migrace.') : 'Migrace vrátila neočekávanou odpověď.';
        flash('danger', 'Migraci se nepodařilo dokončit: ' . $message);
    }
    redirect(BASE_URL . '/admin/zprava_sportovci.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'run_chat_migration') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/zprava_sportovci.php');
    }

    $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 255, 'UTF-8');
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000, 'UTF-8');
    if ($subject === '') {
        $errors[] = 'Předmět nesmí být prázdný.';
    }
    if ($body === '') {
        $errors[] = 'Text zprávy nesmí být prázdný.';
    }
    if (empty($activeAthleteIds)) {
        $errors[] = 'Nenachází se žádný sportovec s aktivním přístupem do aplikace.';
    }

    $attachmentPath = null;
    $attachmentName = null;
    if (!empty($_FILES['attachment']['name'])) {
        $upload = $_FILES['attachment'];
        if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Chyba při nahrávání přílohy (kód ' . (int)$upload['error'] . ').';
        } elseif ((int)$upload['size'] > 50 * 1024 * 1024) {
            $errors[] = 'Příloha nesmí být větší než 50 MB.';
        } else {
            $extension = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'rar', '7z', 'mp4', 'mov', 'avi', 'mkv', 'txt', 'ppt', 'pptx'];
            if (!in_array($extension, $allowedExtensions, true)) {
                $errors[] = 'Typ souboru .' . h($extension) . ' není povolen.';
            } else {
                $uploadDir = dirname(__DIR__) . '/uploads/messages/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    $errors[] = 'Přílohu se nepodařilo uložit.';
                } else {
                    $attachmentName = mb_substr((string)$upload['name'], 0, 255, 'UTF-8');
                    $attachmentPath = time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                    if (!move_uploaded_file((string)$upload['tmp_name'], $uploadDir . $attachmentPath)) {
                        $attachmentPath = null;
                        $attachmentName = null;
                        $errors[] = 'Přílohu se nepodařilo uložit.';
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $broadcastStmt = $pdo->prepare('INSERT INTO admin_athlete_broadcasts (subject, body, attachment_path, attachment_name) VALUES (?, ?, ?, ?)');
            $broadcastStmt->execute([$subject, $body, $attachmentPath, $attachmentName]);
            $broadcastId = (int)$pdo->lastInsertId();
            $messageStmt = $pdo->prepare('INSERT INTO athlete_notifications (athlete_id, subject, body, attachment_path, attachment_name) VALUES (?, ?, ?, ?, ?)');
            $recipientStmt = $pdo->prepare('INSERT INTO admin_athlete_broadcast_recipients (broadcast_id, athlete_id, notification_id) VALUES (?, ?, ?)');
            foreach ($activeAthleteIds as $athleteId) {
                $messageStmt->execute([$athleteId, $subject, $body, $attachmentPath, $attachmentName]);
                $recipientStmt->execute([$broadcastId, $athleteId, (int)$pdo->lastInsertId()]);
            }
            $pdo->commit();

            $emailSent = 0;
            foreach ($activeAthletes as $athlete) {
                if (empty($athlete['email'])) {
                    continue;
                }
                $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
                if (sendAthleteMessageNotificationEmail((string)$athlete['email'], $athleteName !== '' ? $athleteName : 'sportovče', $subject, $body)) {
                    $emailSent++;
                }
            }
            if ($emailSent > 0) {
                processEmailNotificationQueue(200, 'athlete_message_notification');
            }
            $emailNote = $emailSent > 0 ? ' E-mailová upozornění: ' . $emailSent . '.' : ' E-mailová upozornění se nepodařilo zařadit.';
            flash('success', 'Zpráva byla odeslána ' . count($activeAthleteIds) . ' sportovcům s aktivním přístupem.' . $emailNote);
            redirect(BASE_URL . '/admin/zprava_sportovci_detail.php?id=' . $broadcastId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('admin athlete bulk message error: ' . $e->getMessage());
            $errors[] = 'Hromadnou zprávu se nepodařilo odeslat. Zkuste to znovu.';
        }
    }
}

$broadcasts = $pdo->query(
    'SELECT b.*, COUNT(r.id) AS recipient_count, SUM(n.read_at IS NOT NULL) AS read_count
     FROM admin_athlete_broadcasts b
     LEFT JOIN admin_athlete_broadcast_recipients r ON r.broadcast_id = b.id
     LEFT JOIN athlete_notifications n ON n.id = r.notification_id
     GROUP BY b.id
     ORDER BY b.created_at DESC, b.id DESC'
)->fetchAll();

renderAdminHeader('Zpráva sportovcům');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/zpravy.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i></a>
    <h2 class="fw-bold mb-0 flex-grow-1"><i class="fas fa-bullhorn me-2 text-primary"></i>Hromadná zpráva sportovcům</h2>
    <?php if ($chatTableExists): ?>
    <a href="<?= BASE_URL ?>/admin/chat_mobile.php" class="btn btn-outline-success btn-sm" target="_blank" rel="noopener">
        <i class="fas fa-mobile-screen-button me-1"></i>Mobilní chat
    </a>
    <?php endif; ?>
</div>

<?php if ($chatTableExists): ?>
<div class="alert alert-light border small mb-4">
    <i class="fas fa-circle-info me-1 text-muted"></i>Pro rychlý přístup na mobilu otevřete
    <a href="<?= BASE_URL ?>/admin/chat_mobile.php" target="_blank" rel="noopener"><?= h(BASE_URL) ?>/admin/chat_mobile.php</a>
    a přes menu prohlížeče ho přidejte na plochu – uvidíte seznam chatů se sportovci i trenéry na jednom místě.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold"><i class="fas fa-comments me-2"></i>Individuální chat se sportovcem</div>
            <?php if (!$chatTableExists): ?>
            <div class="card-body">
                <div class="alert alert-warning mb-3 mb-md-0">
                    Individuální chat ještě není nasazen na této instanci (chybí tabulka <code>admin_athlete_chat_messages</code>).
                </div>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="run_chat_migration">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-database me-1"></i>Spustit migraci</button>
                </form>
            </div>
            <?php elseif (empty($individualChatList)): ?>
            <div class="card-body text-muted">Žádný sportovec s aktivním přístupem do aplikace.</div>
            <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($individualChatList as $row): $name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']); $unreadN = (int)$row['unread_count']; ?>
                <a href="<?= BASE_URL ?>/admin/zprava_sportovec_chat.php?athlete_id=<?= (int)$row['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold"><?= h($name) ?></div>
                        <?php if (!empty($row['last_body'])): ?>
                        <div class="small text-muted text-truncate" style="max-width:420px"><?= h(mb_strimwidth((string)$row['last_body'], 0, 90, '…')) ?></div>
                        <?php else: ?>
                        <div class="small text-muted">Zatím žádná zpráva.</div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <?php if ($unreadN > 0): ?>
                        <span class="badge bg-danger rounded-pill mb-1"><?= $unreadN ?></span><br>
                        <?php endif; ?>
                        <?php if (!empty($row['last_at'])): ?>
                        <span class="small text-muted"><?= formatDateTime((string)$row['last_at']) ?></span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="alert alert-info border">
            <i class="fas fa-users me-2"></i>Zpráva se odešle všem sportovcům s aktivním přístupem do aplikace, bez ohledu na jejich trenéra.
            <strong class="ms-1">Příjemců: <?= count($activeAthleteIds) ?></strong>
        </div>
        <form method="post" enctype="multipart/form-data" class="card shadow-sm">
            <div class="card-body p-4">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label for="subject" class="form-label fw-semibold">Předmět <span class="text-danger">*</span></label>
                    <input type="text" id="subject" name="subject" class="form-control" maxlength="255" value="<?= h($_POST['subject'] ?? '') ?>" required>
                </div>
                <div class="mb-0">
                    <label for="body" class="form-label fw-semibold">Text zprávy <span class="text-danger">*</span></label>
                    <textarea id="body" name="body" class="form-control" rows="10" maxlength="4000" required><?= h($_POST['body'] ?? '') ?></textarea>
                </div>
                <div class="mt-3">
                    <label for="attachment" class="form-label fw-semibold">Příloha <small class="text-muted">(max. 50 MB, dokument, obrázek nebo video)</small></label>
                    <input type="file" id="attachment" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif,.webp,.zip,.rar,.7z,.mp4,.mov,.avi,.mkv,.txt,.ppt,.pptx">
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted small">Zpráva se sportovcům zobrazí v modulu Zprávy.</span>
                <button type="submit" class="btn btn-primary" onclick="return confirm('Odeslat tuto zprávu všem <?= count($activeAthleteIds) ?> sportovcům s aktivním přístupem?')"><i class="fas fa-paper-plane me-1"></i>Odeslat všem sportovcům</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-header fw-semibold"><i class="fas fa-clock-rotate-left me-2"></i>Odeslané hromadné zprávy</div>
    <?php if (empty($broadcasts)): ?>
    <div class="card-body text-muted">Zatím nebyla odeslána žádná hromadná zpráva sportovcům.</div>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-dark"><tr><th>Předmět</th><th>Odesláno</th><th>Příjemci</th><th>Přečteno</th><th></th></tr></thead>
        <tbody><?php foreach ($broadcasts as $broadcast): $total = (int)$broadcast['recipient_count']; $read = (int)$broadcast['read_count']; $badgeClass = $read === $total ? 'success' : ($read > 0 ? 'warning text-dark' : 'secondary'); ?>
            <tr><td class="fw-semibold"><?= h($broadcast['subject']) ?></td><td class="text-nowrap"><?= formatDateTime((string)$broadcast['created_at']) ?></td><td><?= $total ?></td><td><span class="badge bg-<?= $badgeClass ?>"><?= $read ?>/<?= $total ?></span></td><td class="text-end"><a href="<?= BASE_URL ?>/admin/zprava_sportovci_detail.php?id=<?= (int)$broadcast['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye me-1"></i>Detail</a></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php renderAdminFooter(); ?>