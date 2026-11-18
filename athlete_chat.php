<?php
// athlete_chat.php – individuální chat trenéra s jedním jeho sportovcem
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/chat_attachments.php';
require_once __DIR__ . '/includes/chat_message_deletions.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/push_chat_notifications.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo = getDB();
ensureChatMessageDeletionTable($pdo);

$athleteId = intParam($_GET, 'id');
$athleteStmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
$athleteStmt->execute([$athleteId, $coachId]);
$athlete = $athleteStmt->fetch();

if (!$athlete) {
    flash('danger', 'Sportovec nebyl nalezen.');
    redirect(BASE_URL . '/dashboard.php');
}

$athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
$errors = [];

$chatTableCheck = $pdo->query("SHOW TABLES LIKE 'coach_athlete_chat_messages'");
$chatTableExists = $chatTableCheck !== false && (bool)$chatTableCheck->fetchColumn();
if (!$chatTableExists) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `coach_athlete_chat_messages` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `coach_id`        INT NOT NULL,
            `athlete_id`      INT NOT NULL,
            `sender`          ENUM('coach','athlete') NOT NULL,
            `body`            TEXT NOT NULL,
            `attachment_path` VARCHAR(500) NULL,
            `attachment_name` VARCHAR(255) NULL,
            `coach_read_at`   DATETIME NULL,
            `athlete_read_at` DATETIME NULL,
            `coach_notified_at`   DATETIME NULL,
            `athlete_notified_at` DATETIME NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_coach_athlete_chat_coach` (`coach_id`, `created_at`),
            KEY `idx_coach_athlete_chat_athlete` (`athlete_id`, `created_at`),
            CONSTRAINT `fk_coach_athlete_chat_coach`
                FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_coach_athlete_chat_athlete`
                FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $chatTableExists = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_chat.php?id=' . $athleteId);
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
                'INSERT INTO coach_athlete_chat_messages (coach_id, athlete_id, sender, body, attachment_path, attachment_name, coach_read_at)
                 VALUES (?, ?, "coach", ?, ?, ?, NOW())'
            );
            $ins->execute([$coachId, $athleteId, $body, $attachment['path'], $attachment['name']]);
            $newId = (int)$pdo->lastInsertId();
            pushNotifyCoachAthleteChatMessage($pdo, $newId);

            if (!empty($athlete['email'])) {
                $coach = getCurrentCoach();
                $coachName = trim((string)($coach['name'] ?? '')) !== '' ? (string)$coach['name'] : trim((string)($coach['username'] ?? 'trenér'));
                notifyAthleteAboutNewCoachChatMessage($coachId, $athleteId, $newId, (string)$athlete['email'], $athleteName, $coachName, chatNotificationText($body, $attachment['name']));
            }

            redirect(BASE_URL . '/athlete_chat.php?id=' . $athleteId);
        } catch (Throwable $e) {
            error_log('coach athlete_chat send error: ' . $e->getMessage());
            $errors[] = 'Zprávu se nepodařilo odeslat. Zkuste to znovu.';
        }
    }
}

$pdo->prepare("UPDATE coach_athlete_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND athlete_id = ? AND sender = 'athlete' AND coach_read_at IS NULL")
    ->execute([$coachId, $athleteId]);

$messages = fetchChatMessagesForUser($pdo, 'coach_athlete_chat_messages', ['coach_id' => $coachId, 'athlete_id' => $athleteId], 'coach', $coachId);

renderHeader('Chat: ' . $athleteName);
?>

<div class="d-flex align-items-center mb-4 gap-3">
    <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$athleteId ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i></a>
    <h2 class="fw-bold mb-0"><i class="fas fa-comments me-2 text-primary"></i>Chat: <?= h($athleteName) ?></h2>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body" style="max-height:60vh; overflow-y:auto" id="chatScroll" data-chat-delete-api="<?= h(BASE_URL . '/api/coach_athlete_chat.php?athlete_id=' . $athleteId . '&action=delete') ?>" data-chat-delete-csrf="<?= h(csrfToken()) ?>">
                <?php if (empty($messages)): ?>
                <div class="text-muted text-center py-4">Zatím žádné zprávy. Napište sportovci první zprávu.</div>
                <?php else: foreach ($messages as $m): $isCoach = $m['sender'] === 'coach'; ?>
                <div class="d-flex mb-3 <?= $isCoach ? 'justify-content-end' : 'justify-content-start' ?>" data-message-id="<?= (int)$m['id'] ?>">
                    <div class="p-2 px-3 rounded-3 <?= $isCoach ? 'bg-primary text-white' : 'bg-light border' ?>" style="max-width:75%;position:relative;padding-right:2.5rem">
                        <div style="white-space:pre-wrap"><?= h((string)$m['body']) ?></div>
                        <?php if (!empty($m['attachment_name'])): ?>
                        <div class="mt-1">
                            <a class="d-inline-flex align-items-center gap-1 small <?= $isCoach ? 'text-white' : 'text-primary' ?>" href="<?= h(BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path'])) ?>" target="_blank" rel="noopener">
                                <i class="fas fa-paperclip"></i><?= h((string)$m['attachment_name']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="small mt-1 <?= $isCoach ? 'text-white-50' : 'text-muted' ?>">
                            <?= $isCoach ? 'Vy' : h($athleteName) ?> · <?= formatDateTime((string)$m['created_at']) ?>
                        </div>
                        <button type="button" class="chat-message-more" data-chat-message-menu-toggle aria-label="Možnosti zprávy" title="Možnosti zprávy"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></button>
                        <div class="chat-message-menu" data-chat-message-menu hidden>
                            <button type="button" data-chat-delete-scope="me">Odstranit pro mě</button>
                            <?php if ($isCoach && empty($m['deleted_for_everyone'])): ?><button type="button" data-chat-delete-scope="everyone">Odstranit pro všechny</button><?php endif; ?>
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

<?php renderFooter(); ?>
