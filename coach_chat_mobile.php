<?php
// coach_chat_mobile.php – odlehčená mobilní stránka pro chat trenéra se sportovci i administrátorem (pro záložku/plochu na mobilu)
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

$chatTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'coach_athlete_chat_messages'")->fetchColumn();
$adminChatTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_coach_chat_messages'")->fetchColumn();

$target = ($_GET['target'] ?? '') === 'admin' ? 'admin' : '';
if ($target === 'admin' && !$adminChatTableExists) {
    $target = '';
}

$athleteId = $target === '' ? intParam($_GET, 'athlete_id') : 0;
$athlete = null;
$errors = [];

if ($target === '' && $chatTableExists && $athleteId > 0) {
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM athletes WHERE id = ? AND coach_id = ? LIMIT 1');
    $stmt->execute([$athleteId, $coachId]);
    $athlete = $stmt->fetch();
    if (!$athlete) {
        $athleteId = 0;
    }
}

if (($athlete || $target === 'admin') && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/coach_chat_mobile.php' . ($target === 'admin' ? '?target=admin' : '?athlete_id=' . $athleteId));
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
            $coach = getCurrentCoach();
            $coachName = trim((string)($coach['name'] ?? '')) !== '' ? (string)$coach['name'] : trim((string)($coach['username'] ?? 'trenér'));

            if ($target === 'admin') {
                $pdo->prepare("INSERT INTO admin_coach_chat_messages (coach_id, sender, body, attachment_path, attachment_name, coach_read_at) VALUES (?, 'coach', ?, ?, ?, NOW())")
                    ->execute([$coachId, $body, $attachment['path'], $attachment['name']]);
                $newId = (int)$pdo->lastInsertId();
                notifyAdminAboutNewCoachChatMessage($coachId, $newId, $coachName, chatNotificationText($body, $attachment['name']));
            } else {
                $pdo->prepare(
                    "INSERT INTO coach_athlete_chat_messages (coach_id, athlete_id, sender, body, attachment_path, attachment_name, coach_read_at) VALUES (?, ?, 'coach', ?, ?, ?, NOW())"
                )->execute([$coachId, $athleteId, $body, $attachment['path'], $attachment['name']]);
                $newId = (int)$pdo->lastInsertId();
                pushNotifyCoachAthleteChatMessage($pdo, $newId);

                $athleteName = trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name']);
                if (!empty($athlete['email'])) {
                    notifyAthleteAboutNewCoachChatMessage($coachId, $athleteId, $newId, (string)$athlete['email'], $athleteName, $coachName, chatNotificationText($body, $attachment['name']));
                }
            }
        } catch (Throwable $e) {
            error_log('coach chat_mobile send error: ' . $e->getMessage());
            $errors[] = 'Zprávu se nepodařilo odeslat.';
        }
    }
    if (!empty($errors)) {
        flash('danger', implode(' ', $errors));
    }
    redirect(BASE_URL . '/coach_chat_mobile.php' . ($target === 'admin' ? '?target=admin' : '?athlete_id=' . $athleteId));
}

$messages = [];
if ($target === 'admin') {
    $pdo->prepare("UPDATE admin_coach_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL")
        ->execute([$coachId]);
    $messages = fetchChatMessagesForUser($pdo, 'admin_coach_chat_messages', ['coach_id' => $coachId], 'coach', $coachId);
} elseif ($athlete) {
    $pdo->prepare("UPDATE coach_athlete_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND athlete_id = ? AND sender = 'athlete' AND coach_read_at IS NULL")
        ->execute([$coachId, $athleteId]);
    $messages = fetchChatMessagesForUser($pdo, 'coach_athlete_chat_messages', ['coach_id' => $coachId, 'athlete_id' => $athleteId], 'coach', $coachId);
}

$conversations = [];
if ($target === '' && !$athlete) {
    if ($adminChatTableExists) {
        $adminLastStmt = $pdo->prepare(
            "SELECT COUNT(*) AS unread_count FROM admin_coach_chat_messages WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL"
        );
        $adminLastStmt->execute([$coachId]);
        $adminRow = $adminLastStmt->fetch();
        $adminPreview = fetchChatMessagePreview($pdo, 'admin_coach_chat_messages', ['coach_id' => $coachId], 'coach', $coachId);
        $conversations[] = [
            'target' => 'admin',
            'name' => 'Administrátor',
            'last_body' => $adminPreview['body'],
            'last_at' => $adminPreview['created_at'],
            'unread_count' => (int)($adminRow['unread_count'] ?? 0),
        ];
    }
    if ($chatTableExists) {
        $listStmt = $pdo->prepare(
            "SELECT a.id, a.first_name, a.last_name,
                    (SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE coach_id = ? AND athlete_id = a.id AND sender = 'athlete' AND coach_read_at IS NULL) AS unread_count
             FROM athletes a
             WHERE a.coach_id = ?
             ORDER BY a.first_name ASC, a.last_name ASC"
        );
        $listStmt->execute([$coachId, $coachId]);
        foreach ($listStmt->fetchAll() as $row) {
            $preview = fetchChatMessagePreview($pdo, 'coach_athlete_chat_messages', ['coach_id' => $coachId, 'athlete_id' => (int)$row['id']], 'coach', $coachId);
            $conversations[] = [
                'target' => 'athlete',
                'id' => (int)$row['id'],
                'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'last_body' => $preview['body'],
                'last_at' => $preview['created_at'],
                'unread_count' => (int)$row['unread_count'],
            ];
        }
    }
    usort($conversations, static function (array $a, array $b): int {
        if (($a['target'] === 'admin') !== ($b['target'] === 'admin')) {
            return $a['target'] === 'admin' ? -1 : 1;
        }
        $aAt = $a['last_at'] ?? '';
        $bAt = $b['last_at'] ?? '';
        if ($aAt === $bAt) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $aAt > $bAt ? -1 : 1;
    });
}
?>
<?php $sidebarChat = true; ?>
<?php if (!$sidebarChat): ?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="mobile-web-app-capable" content="yes">
<title><?= $target === 'admin' ? 'Administrátor' : ($athlete ? h(trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])) : 'Zprávy') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php else: ?>
<?php renderHeader('Zprávy', false, true); ?>
<?php endif; ?>
<style>
    * { box-sizing: border-box; }
    html, body { height: 100%; margin: 0; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        background: #ece5dd;
        display: flex;
        flex-direction: column;
        height: 100vh;
        height: 100dvh;
        overflow: hidden;
    }
    .topbar {
        background: linear-gradient(135deg, #0d6efd, #0b5ed7);
        color: #fff;
        padding: 12px 14px;
        padding-top: calc(12px + env(safe-area-inset-top));
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }
    .topbar a { color: #fff; text-decoration: none; font-size: 18px; }
    .topbar .title { font-weight: 600; font-size: 16px; flex: 1; }
    .list { flex: 1; overflow-y: auto; background: #fff; }
    .list-item {
        display: flex; justify-content: space-between; align-items: center;
        padding: 12px 14px; border-bottom: 1px solid #eee; text-decoration: none; color: #111;
    }
    .list-item .name { font-weight: 600; }
    .list-item .preview { font-size: 13px; color: #667781; margin-top: 2px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .list-item .meta { text-align: right; font-size: 11px; color: #667781; }
    .badge-unread {
        display: inline-block; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px;
        background: #dc3545; color: #fff; font-size: 11px; font-weight: 700; line-height: 20px; text-align: center;
    }
    .empty { padding: 30px 16px; text-align: center; color: #667781; }
    .chat-body { flex: 1; overflow-y: auto; padding: 12px; }
    .bubble-row { display: flex; margin-bottom: 8px; }
    .bubble-row.from-coach { justify-content: flex-end; }
    .bubble {
        max-width: 80%; padding: 8px 11px; border-radius: 10px; font-size: 14.5px; line-height: 1.4;
        white-space: pre-wrap; word-wrap: break-word; box-shadow: 0 1px 1px rgba(0,0,0,0.08);
    }
    .bubble-row.from-athlete .bubble { background: #fff; }
    .bubble-row.from-coach .bubble { background: #d4e6ff; }
    .bubble-time { display: block; margin-top: 3px; font-size: 10.5px; color: #667781; text-align: right; }
    .chat-footer {
        display: flex; gap: 8px; padding: 10px;
        padding-bottom: calc(10px + env(safe-area-inset-bottom));
        background: #f0f0f0; border-top: 1px solid #ddd; flex-shrink: 0;
    }
    .chat-footer input[type=text] {
        flex: 1; border: 1px solid #ccc; border-radius: 20px; padding: 10px 14px; font-size: 15px;
    }
    .chat-footer button {
        border: none; background: #0d6efd; color: #fff; width: 42px; height: 42px; border-radius: 50%; font-size: 16px;
    }
</style>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/chat-composer.css?v=6">
<?php if (!$sidebarChat): ?>
</head>
<body>
<?php else: ?>
<script>document.body.classList.add('dashboard-standalone-chat');</script>
<?php endif; ?>
<script src="<?= BASE_URL ?>/assets/js/pwa.js?v=3" data-push-csrf="<?= h(csrfToken()) ?>" defer></script>

<?php if (!$chatTableExists && !$adminChatTableExists): ?>
<div class="topbar"><span class="title">Chat</span></div>
<div class="empty">Chat ještě není nasazen na této instanci. Požádejte administrátora o spuštění migrace.</div>

<?php elseif ($target === 'admin'): ?>
<div class="topbar">
    <a href="<?= BASE_URL ?>/coach_chat_mobile.php"><i class="fas fa-arrow-left"></i></a>
    <span class="title"><i class="fas fa-user-shield me-1"></i>Administrátor</span>
</div>
<div class="chat-body" id="chatBody">
    <?php if (empty($messages)): ?>
    <div class="empty">Zatím žádné zprávy.</div>
    <?php else: foreach ($messages as $m): $isCoach = $m['sender'] === 'coach'; ?>
    <div class="bubble-row <?= $isCoach ? 'from-coach' : 'from-athlete' ?>" data-message-id="<?= (int)$m['id'] ?>" data-deleted-event-id="<?= (int)($m['deleted_event_id'] ?? 0) ?>">
        <div class="bubble">
            <?= h((string)$m['body']) ?>
            <?php if (!empty($m['attachment_name'])): ?>
            <div style="margin-top:4px"><a href="<?= h(BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path'])) ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> <?= h((string)$m['attachment_name']) ?></a></div>
            <?php endif; ?>
            <span class="bubble-time"><?= formatDateTime((string)$m['created_at']) ?></span>
            <button type="button" class="chat-message-more" data-chat-message-menu-toggle aria-label="Možnosti zprávy" title="Možnosti zprávy"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></button>
            <div class="chat-message-menu" data-chat-message-menu hidden>
                <button type="button" data-chat-delete-scope="me">Odstranit pro mě</button>
                <?php if ($isCoach && empty($m['deleted_for_everyone'])): ?><button type="button" data-chat-delete-scope="everyone">Odstranit pro všechny</button><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>
<form class="chat-footer" method="post" enctype="multipart/form-data" data-chat-composer>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="send">
    <input type="text" name="body" data-chat-input placeholder="Napište zprávu…" maxlength="4000" autocomplete="off">
    <button type="submit" data-chat-send><i class="fas fa-paper-plane"></i></button>
    <span class="chat-send-error" data-chat-error role="status"></span>
</form>
<script>
    var box = document.getElementById('chatBody');
    if (box) { box.scrollTop = box.scrollHeight; }
</script>

<?php elseif ($athlete): ?>
<div class="topbar">
    <a href="<?= BASE_URL ?>/coach_chat_mobile.php"><i class="fas fa-arrow-left"></i></a>
    <span class="title"><?= h(trim((string)$athlete['first_name'] . ' ' . (string)$athlete['last_name'])) ?></span>
</div>
<div class="chat-body" id="chatBody">
    <?php if (empty($messages)): ?>
    <div class="empty">Zatím žádné zprávy.</div>
    <?php else: foreach ($messages as $m): $isCoach = $m['sender'] === 'coach'; ?>
    <div class="bubble-row <?= $isCoach ? 'from-coach' : 'from-athlete' ?>" data-message-id="<?= (int)$m['id'] ?>" data-deleted-event-id="<?= (int)($m['deleted_event_id'] ?? 0) ?>">
        <div class="bubble">
            <?= h((string)$m['body']) ?>
            <?php if (!empty($m['attachment_name'])): ?>
            <div style="margin-top:4px"><a href="<?= h(BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path'])) ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> <?= h((string)$m['attachment_name']) ?></a></div>
            <?php endif; ?>
            <span class="bubble-time"><?= formatDateTime((string)$m['created_at']) ?></span>
            <button type="button" class="chat-message-more" data-chat-message-menu-toggle aria-label="Možnosti zprávy" title="Možnosti zprávy"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></button>
            <div class="chat-message-menu" data-chat-message-menu hidden>
                <button type="button" data-chat-delete-scope="me">Odstranit pro mě</button>
                <?php if ($isCoach && empty($m['deleted_for_everyone'])): ?><button type="button" data-chat-delete-scope="everyone">Odstranit pro všechny</button><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>
<form class="chat-footer" method="post" enctype="multipart/form-data" data-chat-composer>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="send">
    <input type="text" name="body" data-chat-input placeholder="Napište zprávu…" maxlength="4000" autocomplete="off">
    <button type="submit" data-chat-send><i class="fas fa-paper-plane"></i></button>
    <span class="chat-send-error" data-chat-error role="status"></span>
</form>
<script>
    var box = document.getElementById('chatBody');
    if (box) { box.scrollTop = box.scrollHeight; }
</script>

<?php else: ?>
<div class="topbar"><span class="title">Zprávy</span></div>
<div class="list">
    <?php if (empty($conversations)): ?>
    <div class="empty">Zatím nemáte žádné konverzace.</div>
    <?php else: foreach ($conversations as $row): $unreadN = (int)$row['unread_count']; $href = $row['target'] === 'admin' ? (BASE_URL . '/coach_chat_mobile.php?target=admin') : (BASE_URL . '/coach_chat_mobile.php?athlete_id=' . (int)$row['id']); ?>
    <a class="list-item" href="<?= h($href) ?>">
        <div>
            <div class="name"><?php if ($row['target'] === 'admin'): ?><i class="fas fa-user-shield me-1"></i><?php endif; ?><?= h($row['name']) ?></div>
            <div class="preview"><?= !empty($row['last_body']) ? h(mb_strimwidth((string)$row['last_body'], 0, 40, '…')) : 'Zatím žádná zpráva' ?></div>
        </div>
        <div class="meta">
            <?php if ($unreadN > 0): ?><div class="badge-unread"><?= $unreadN ?></div><?php endif; ?>
            <?php if (!empty($row['last_at'])): ?><div><?= formatDateTime((string)$row['last_at']) ?></div><?php endif; ?>
        </div>
    </a>
    <?php endforeach; endif; ?>
</div>
<?php endif; ?>

<?php if ($sidebarChat): ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>
<?php if ($messages !== []): ?>
<script>
(function () {
    var box = document.getElementById('chatBody');
    if (!box) return;
    var apiUrl = <?= json_encode(BASE_URL . ($target === 'admin' ? '/api/coach_chat_admin.php' : '/api/coach_athlete_chat.php')) ?>;
    var apiQuery = <?= json_encode($target === 'admin' ? '' : '?athlete_id=' . $athleteId) ?>;
    var csrfToken = <?= json_encode(csrfToken()) ?>;
    box.addEventListener('click', async function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        var toggle = target.closest('[data-chat-message-menu-toggle]');
        if (toggle) {
            var menu = toggle.parentElement.querySelector('[data-chat-message-menu]');
            box.querySelectorAll('[data-chat-message-menu]').forEach(function (item) { if (item !== menu) item.hidden = true; });
            menu.hidden = !menu.hidden;
            return;
        }
        var button = target.closest('[data-chat-delete-scope]');
        if (!button) {
            if (!target.closest('[data-chat-message-menu]')) box.querySelectorAll('[data-chat-message-menu]').forEach(function (item) { item.hidden = true; });
            return;
        }
        var row = button.closest('.bubble-row');
        var scope = button.dataset.chatDeleteScope;
        if (!row) return;
        if (scope === 'everyone' && !window.confirm('Opravdu chcete odstranit tuto zprávu pro všechny účastníky?')) return;
        var formData = new FormData();
        formData.append('message_id', row.dataset.messageId);
        formData.append('scope', scope);
        formData.append('csrf_token', csrfToken);
        try {
            var response = await fetch(apiUrl + '?action=delete' + apiQuery, { method: 'POST', body: formData, credentials: 'same-origin' });
            var data = await response.json();
            if (!response.ok || !data || !data.ok) throw new Error(data && data.error ? data.error : 'Zprávu se nepodařilo odstranit.');
            if (data.warning) window.alert(data.warning);
            if (scope === 'me') row.remove();
            else if (data.message) window.location.reload();
            else row.remove();
        } catch (error) {
            window.alert(error && error.message ? error.message : 'Zprávu se nepodařilo odstranit.');
        }
    });
})();
</script>
<?php endif; ?>
<script src="<?= BASE_URL ?>/assets/js/chat-composer.js?v=6"></script>
</body>
</html>
