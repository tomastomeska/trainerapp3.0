<?php
// admin/zprava_sportovec_chat.php – individuální chat administrátora s jedním sportovcem
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
$pdo = getDB();

$athleteId = intParam($_GET, 'athlete_id');
$athleteStmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM athletes WHERE id = ? LIMIT 1');
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();

if (!$athlete) {
    flash('danger', 'Sportovec nebyl nalezen.');
    redirect(BASE_URL . '/admin/zprava_sportovci.php');
}

$athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/zprava_sportovec_chat.php?athlete_id=' . $athleteId);
    }

    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000, 'UTF-8');
    if ($body === '') {
        $errors[] = 'Text zprávy nesmí být prázdný.';
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
            $ins = $pdo->prepare(
                'INSERT INTO admin_athlete_chat_messages (athlete_id, sender, body, attachment_path, attachment_name, admin_read_at)
                 VALUES (?, "admin", ?, ?, ?, NOW())'
            );
            $ins->execute([$athleteId, $body, $attachmentPath, $attachmentName]);

            if (!empty($athlete['email'])) {
                if (sendAthleteMessageNotificationEmail((string)$athlete['email'], $athleteName !== '' ? $athleteName : 'sportovče', 'Nová zpráva od administrátora', $body)) {
                    processEmailNotificationQueue(200, 'athlete_message_notification');
                }
            }

            redirect(BASE_URL . '/admin/zprava_sportovec_chat.php?athlete_id=' . $athleteId);
        } catch (Throwable $e) {
            error_log('admin athlete chat send error: ' . $e->getMessage());
            $errors[] = 'Zprávu se nepodařilo odeslat. Zkuste to znovu.';
        }
    }
}

// Označit zprávy od sportovce jako přečtené administrátorem
$pdo->prepare("UPDATE admin_athlete_chat_messages SET admin_read_at = NOW() WHERE athlete_id = ? AND sender = 'athlete' AND admin_read_at IS NULL")
    ->execute([$athleteId]);

$thread = $pdo->prepare('SELECT * FROM admin_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at ASC, id ASC');
$thread->execute([$athleteId]);
$messages = $thread->fetchAll();

renderAdminHeader('Chat se sportovcem');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/zprava_sportovci.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i></a>
    <h2 class="fw-bold mb-0"><i class="fas fa-comments me-2 text-primary"></i>Chat: <?= h($athleteName) ?></h2>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body" style="max-height:60vh; overflow-y:auto" id="chatScroll">
                <?php if (empty($messages)): ?>
                <div class="text-muted text-center py-4">Zatím žádné zprávy. Napište sportovci první zprávu.</div>
                <?php else: foreach ($messages as $m): $isAdmin = $m['sender'] === 'admin'; ?>
                <div class="d-flex mb-3 <?= $isAdmin ? 'justify-content-end' : 'justify-content-start' ?>">
                    <div class="p-2 px-3 rounded-3 <?= $isAdmin ? 'bg-primary text-white' : 'bg-light border' ?>" style="max-width:75%">
                        <div style="white-space:pre-wrap"><?= h((string)$m['body']) ?></div>
                        <?php if (!empty($m['attachment_name'])): ?>
                        <div class="mt-1">
                            <a class="d-inline-flex align-items-center gap-1 small <?= $isAdmin ? 'text-white' : 'text-primary' ?>" href="<?= h(BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path'])) ?>" target="_blank" rel="noopener">
                                <i class="fas fa-paperclip"></i><?= h((string)$m['attachment_name']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="small mt-1 <?= $isAdmin ? 'text-white-50' : 'text-muted' ?>">
                            <?= $isAdmin ? 'Vy' : h($athleteName) ?> · <?= formatDateTime((string)$m['created_at']) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="card-footer">
                <form method="post" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="send">
                    <div class="mb-2">
                        <textarea name="body" class="form-control" rows="3" maxlength="4000" placeholder="Napište zprávu sportovci..." required></textarea>
                    </div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <input type="file" name="attachment" class="form-control form-control-sm" style="max-width:300px" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif,.webp,.zip,.rar,.7z,.mp4,.mov,.avi,.mkv,.txt,.ppt,.pptx">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Odeslat</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('chatScroll');
    if (box) { box.scrollTop = box.scrollHeight; }
});
</script>

<?php renderAdminFooter(); ?>
