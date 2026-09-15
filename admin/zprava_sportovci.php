<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
$pdo = getDB();

$activeAthletesStmt = $pdo->query('SELECT id, first_name, last_name, email FROM athletes WHERE login_enabled = 1');
$activeAthletes = $activeAthletesStmt->fetchAll();
$activeAthleteIds = array_map(static fn(array $row): int => (int)$row['id'], $activeAthletes);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    <h2 class="fw-bold mb-0"><i class="fas fa-bullhorn me-2 text-primary"></i>Hromadná zpráva sportovcům</h2>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
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