<?php
// admin/zprava_sportovec_chat.php – individuální chat administrátora s jedním sportovcem
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/chat_attachments.php';
require_once __DIR__ . '/../includes/chat_message_deletions.php';
require_once __DIR__ . '/header.php';

requireAdminLogin();
$pdo = getDB();
ensureChatMessageDeletionTable($pdo);

$athleteId = intParam($_GET, 'athlete_id');
$athleteStmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM athletes WHERE id = ? LIMIT 1');
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();

if (!$athlete) {
    flash('danger', 'Sportovec nebyl nalezen.');
    redirect(BASE_URL . '/admin/zprava_sportovci.php');
}

$athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
$athleteContactName = $athleteName . ' (ID: ' . $athleteId . ')';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/zprava_sportovec_chat.php?athlete_id=' . $athleteId);
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
                'INSERT INTO admin_athlete_chat_messages (athlete_id, sender, body, attachment_path, attachment_name, admin_read_at)
                 VALUES (?, "admin", ?, ?, ?, NOW())'
            );
            $ins->execute([$athleteId, $body, $attachment['path'], $attachment['name']]);
            $chatMessageId = (int)$pdo->lastInsertId();

            if (!empty($athlete['email']) && isFirstChatMessageToday('admin_athlete_chat_messages', ['athlete_id' => $athleteId], 'admin', $chatMessageId)) {
                if (sendAthleteMessageNotificationEmail((string)$athlete['email'], $athleteName !== '' ? $athleteName : 'sportovče', 'Nová zpráva od administrátora', chatNotificationText($body, $attachment['name']))) {
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

$messages = fetchChatMessagesForUser($pdo, 'admin_athlete_chat_messages', ['athlete_id' => $athleteId], 'admin', 0);

renderAdminHeader('Chat se sportovcem');
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/admin/zprava_sportovci.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i></a>
    <h2 class="fw-bold mb-0"><i class="fas fa-comments me-2 text-primary"></i>Chat: <?= h($athleteContactName) ?></h2>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body" style="max-height:60vh; overflow-y:auto" id="chatScroll" data-chat-delete-api="<?= h(BASE_URL . '/admin/api/chat.php?action=delete&type=athlete&target_id=' . $athleteId) ?>" data-chat-delete-csrf="<?= h(csrfToken()) ?>">
                <?php if (empty($messages)): ?>
                <div class="text-muted text-center py-4">Zatím žádné zprávy. Napište sportovci první zprávu.</div>
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
                            <?= $isAdmin ? 'Vy' : h($athleteContactName) ?> · <?= formatDateTime((string)$m['created_at']) ?>
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
                        <textarea name="body" data-chat-input class="form-control" rows="3" maxlength="4000" placeholder="Napište zprávu sportovci..."></textarea>
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
