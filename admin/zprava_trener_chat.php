<?php
// admin/zprava_trener_chat.php – individuální chat administrátora s jedním trenérem
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/chat_attachments.php';
require_once __DIR__ . '/../includes/chat_message_deletions.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
$pdo = getDB();
ensureChatMessageDeletionTable($pdo);

$coachId = intParam($_GET, 'coach_id');
$coachStmt = $pdo->prepare('SELECT id, name, username, email FROM coaches WHERE id = ? LIMIT 1');
$coachStmt->execute([$coachId]);
$coach = $coachStmt->fetch();

if (!$coach) {
    flash('danger', 'Trenér nebyl nalezen.');
    redirect(BASE_URL . '/admin/zpravy.php');
}

$coachName = ($coach['name'] ?? '') !== '' ? (string)$coach['name'] : (string)($coach['username'] ?? 'trenér');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/zprava_trener_chat.php?coach_id=' . $coachId);
    }

    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000, 'UTF-8');
    $attachment = receiveChatAttachment($_FILES);
    if ($attachment['error'] !== null) {
        $errors[] = $attachment['error'];
    } elseif ($body === '' && $attachment['path'] === null) {
        $errors[] = 'Zadejte zprávu nebo přidejte přílohu.';
    }

    if (empty($errors)) {
        try {
            $ins = $pdo->prepare(
                'INSERT INTO admin_coach_chat_messages (coach_id, sender, body, attachment_path, attachment_name, admin_read_at)
                 VALUES (?, "admin", ?, ?, ?, NOW())'
            );
            $ins->execute([$coachId, $body, $attachment['path'], $attachment['name']]);
            $chatMessageId = (int)$pdo->lastInsertId();

            if (!empty($coach['email']) && isFirstChatMessageToday('admin_coach_chat_messages', ['coach_id' => $coachId], 'admin', $chatMessageId)) {
                sendCoachChatMessageNotificationEmail((string)$coach['email'], $coachName, chatNotificationText($body, $attachment['name']));
            }

            redirect(BASE_URL . '/admin/zprava_trener_chat.php?coach_id=' . $coachId);
        } catch (Throwable $e) {
            error_log('admin coach chat send error: ' . $e->getMessage());
            $errors[] = 'Zprávu se nepodařilo odeslat. Zkuste to znovu.';
        }
    }
}

// Označit zprávy od trenéra jako přečtené administrátorem
$pdo->prepare("UPDATE admin_coach_chat_messages SET admin_read_at = NOW() WHERE coach_id = ? AND sender = 'coach' AND admin_read_at IS NULL")
    ->execute([$coachId]);

$messages = fetchChatMessagesForUser($pdo, 'admin_coach_chat_messages', ['coach_id' => $coachId], 'admin', 0);

renderAdminHeader('Chat s trenérem');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/zpravy.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i></a>
    <h2 class="fw-bold mb-0"><i class="fas fa-comments me-2 text-primary"></i>Chat: <?= h($coachName) ?></h2>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body" style="max-height:60vh; overflow-y:auto" id="chatScroll" data-chat-delete-api="<?= h(BASE_URL . '/admin/api/chat.php?action=delete&type=coach&target_id=' . $coachId) ?>" data-chat-delete-csrf="<?= h(csrfToken()) ?>">
                <?php if (empty($messages)): ?>
                <div class="text-muted text-center py-4">Zatím žádné zprávy. Napište trenérovi první zprávu.</div>
                <?php else: foreach ($messages as $m): $isAdmin = $m['sender'] === 'admin'; ?>
                <div class="d-flex mb-3 <?= $isAdmin ? 'justify-content-end' : 'justify-content-start' ?>" data-message-id="<?= (int)$m['id'] ?>">
                    <div class="p-2 px-3 rounded-3 <?= $isAdmin ? 'bg-primary text-white' : 'bg-light border' ?>" style="max-width:75%;position:relative;padding-right:2.5rem">
                        <div style="white-space:pre-wrap"><?= h((string)$m['body']) ?></div>
                        <?php if (!empty($m['attachment_name'])): ?>
                        <div class="mt-1">
                            <a class="d-inline-flex align-items-center gap-1 small <?= $isAdmin ? 'text-white' : 'text-primary' ?>" href="<?= h(BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path'])) ?>" target="_blank" rel="noopener">
                                <i class="fas fa-paperclip"></i><?= h((string)$m['attachment_name']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="small mt-1 <?= $isAdmin ? 'text-white-50' : 'text-muted' ?>">
                            <?= $isAdmin ? 'Vy' : h($coachName) ?> · <?= formatDateTime((string)$m['created_at']) ?>
                        </div>
                        <button type="button" class="chat-message-more" data-chat-message-menu-toggle aria-label="Možnosti zprávy" title="Možnosti zprávy"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></button>
                        <div class="chat-message-menu" data-chat-message-menu hidden>
                            <button type="button" data-chat-delete-scope="me">Odstranit pro mě</button>
                            <?php if ($isAdmin && empty($m['deleted_for_everyone'])): ?><button type="button" data-chat-delete-scope="everyone">Odstranit pro všechny</button><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="card-footer">
                <form method="post" enctype="multipart/form-data" data-chat-composer>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="send">
                    <div class="mb-2">
                        <textarea name="body" data-chat-input class="form-control" rows="3" maxlength="4000" placeholder="Napište zprávu trenérovi..."></textarea>
                    </div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <button type="submit" data-chat-send class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Odeslat</button>
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
