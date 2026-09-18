<?php
// admin/chat_mobile.php – odlehčená mobilní stránka jen pro chat s trenéry a sportovci (pro záložku/plochu na mobilu)
require_once __DIR__ . '/../includes/admin_auth.php';

requireAdminLogin();
$pdo = getDB();

$athleteTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_athlete_chat_messages'")->fetchColumn();
$coachTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_coach_chat_messages'")->fetchColumn();
$anyTableExists = $athleteTableExists || $coachTableExists;

$type = ($_GET['type'] ?? '') === 'coach' ? 'coach' : (($_GET['type'] ?? '') === 'athlete' ? 'athlete' : '');
$userId = intParam($_GET, 'user_id');
$person = null;
$errors = [];

if ($type === 'athlete' && $athleteTableExists && $userId > 0) {
    $stmt = $pdo->prepare('SELECT id, first_name, last_name, email FROM athletes WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) {
        $person = ['id' => (int)$row['id'], 'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']), 'email' => $row['email']];
    }
} elseif ($type === 'coach' && $coachTableExists && $userId > 0) {
    $stmt = $pdo->prepare('SELECT id, name, username, email FROM coaches WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) {
        $cname = ($row['name'] ?? '') !== '' ? (string)$row['name'] : (string)($row['username'] ?? 'trenér');
        $person = ['id' => (int)$row['id'], 'name' => $cname, 'email' => $row['email']];
    }
}
if (!$person) {
    $type = '';
    $userId = 0;
}

if ($person && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/admin/chat_mobile.php?type=' . $type . '&user_id=' . $userId);
    }

    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000, 'UTF-8');
    if ($body !== '') {
        try {
            if ($type === 'athlete') {
                $pdo->prepare(
                    'INSERT INTO admin_athlete_chat_messages (athlete_id, sender, body, admin_read_at) VALUES (?, "admin", ?, NOW())'
                )->execute([$userId, $body]);
                if (!empty($person['email'])) {
                    if (sendAthleteMessageNotificationEmail((string)$person['email'], $person['name'] !== '' ? $person['name'] : 'sportovče', 'Nová zpráva od administrátora', $body)) {
                        processEmailNotificationQueue(200, 'athlete_message_notification');
                    }
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO admin_coach_chat_messages (coach_id, sender, body, admin_read_at) VALUES (?, "admin", ?, NOW())'
                )->execute([$userId, $body]);
                if (!empty($person['email'])) {
                    sendCoachChatMessageNotificationEmail((string)$person['email'], $person['name'], $body);
                }
            }
        } catch (Throwable $e) {
            error_log('admin chat_mobile send error: ' . $e->getMessage());
            $errors[] = 'Zprávu se nepodařilo odeslat.';
        }
    }
    redirect(BASE_URL . '/admin/chat_mobile.php?type=' . $type . '&user_id=' . $userId);
}

$messages = [];
if ($person && $type === 'athlete') {
    $pdo->prepare("UPDATE admin_athlete_chat_messages SET admin_read_at = NOW() WHERE athlete_id = ? AND sender = 'athlete' AND admin_read_at IS NULL")
        ->execute([$userId]);
    $threadStmt = $pdo->prepare('SELECT * FROM admin_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at ASC, id ASC');
    $threadStmt->execute([$userId]);
    $messages = $threadStmt->fetchAll();
} elseif ($person && $type === 'coach') {
    $pdo->prepare("UPDATE admin_coach_chat_messages SET admin_read_at = NOW() WHERE coach_id = ? AND sender = 'coach' AND admin_read_at IS NULL")
        ->execute([$userId]);
    $threadStmt = $pdo->prepare('SELECT * FROM admin_coach_chat_messages WHERE coach_id = ? ORDER BY created_at ASC, id ASC');
    $threadStmt->execute([$userId]);
    $messages = $threadStmt->fetchAll();
}

$conversations = [];
if (!$person) {
    if ($athleteTableExists) {
        $athleteRows = $pdo->query(
            "SELECT a.id, a.first_name, a.last_name,
                    (SELECT body FROM admin_athlete_chat_messages WHERE athlete_id = a.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_body,
                    (SELECT created_at FROM admin_athlete_chat_messages WHERE athlete_id = a.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_at,
                    (SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE athlete_id = a.id AND sender = 'athlete' AND admin_read_at IS NULL) AS unread_count
             FROM athletes a
             WHERE a.login_enabled = 1"
        )->fetchAll();
        foreach ($athleteRows as $r) {
            $conversations[] = [
                'type' => 'athlete',
                'id' => (int)$r['id'],
                'name' => trim((string)$r['first_name'] . ' ' . (string)$r['last_name']),
                'last_body' => $r['last_body'],
                'last_at' => $r['last_at'],
                'unread_count' => (int)$r['unread_count'],
            ];
        }
    }
    if ($coachTableExists) {
        $coachRows = $pdo->query(
            "SELECT c.id, c.name, c.username,
                    (SELECT body FROM admin_coach_chat_messages WHERE coach_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_body,
                    (SELECT created_at FROM admin_coach_chat_messages WHERE coach_id = c.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_at,
                    (SELECT COUNT(*) FROM admin_coach_chat_messages WHERE coach_id = c.id AND sender = 'coach' AND admin_read_at IS NULL) AS unread_count
             FROM coaches c
             WHERE c.is_active = 1"
        )->fetchAll();
        foreach ($coachRows as $r) {
            $conversations[] = [
                'type' => 'coach',
                'id' => (int)$r['id'],
                'name' => ($r['name'] ?: $r['username']),
                'last_body' => $r['last_body'],
                'last_at' => $r['last_at'],
                'unread_count' => (int)$r['unread_count'],
            ];
        }
    }

    usort($conversations, static function (array $a, array $b): int {
        if (($a['unread_count'] > 0) !== ($b['unread_count'] > 0)) {
            return $a['unread_count'] > 0 ? -1 : 1;
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
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="mobile-web-app-capable" content="yes">
<title><?= $person ? h($person['name']) : 'Chat s uživateli' ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
        background: linear-gradient(135deg, #128c7e, #075e54);
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
    .type-icon { font-size: 12px; opacity: .85; margin-right: 4px; }
    .list { flex: 1; overflow-y: auto; background: #fff; }
    .list-item {
        display: flex; justify-content: space-between; align-items: center;
        padding: 12px 14px; border-bottom: 1px solid #eee; text-decoration: none; color: #111;
    }
    .list-item .name { font-weight: 600; }
    .list-item .name .fa-user-tie { color: #128c7e; margin-right: 4px; }
    .list-item .name .fa-person-running { color: #f39c12; margin-right: 4px; }
    .list-item .preview { font-size: 13px; color: #667781; margin-top: 2px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .list-item .meta { text-align: right; font-size: 11px; color: #667781; }
    .badge-unread {
        display: inline-block; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px;
        background: #25d366; color: #fff; font-size: 11px; font-weight: 700; line-height: 20px; text-align: center;
    }
    .empty { padding: 30px 16px; text-align: center; color: #667781; }
    .chat-body { flex: 1; overflow-y: auto; padding: 12px; }
    .bubble-row { display: flex; margin-bottom: 8px; }
    .bubble-row.from-admin { justify-content: flex-end; }
    .bubble {
        max-width: 80%; padding: 8px 11px; border-radius: 10px; font-size: 14.5px; line-height: 1.4;
        white-space: pre-wrap; word-wrap: break-word; box-shadow: 0 1px 1px rgba(0,0,0,0.08);
    }
    .bubble-row.from-user .bubble { background: #fff; }
    .bubble-row.from-admin .bubble { background: #dcf8c6; }
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
        border: none; background: #128c7e; color: #fff; width: 42px; height: 42px; border-radius: 50%; font-size: 16px;
    }
</style>
</head>
<body>

<?php if (!$anyTableExists): ?>
<div class="topbar"><span class="title">Chat s uživateli</span></div>
<div class="empty">Chat ještě není nasazen na této instanci. Spusťte migraci v administraci na <a href="<?= BASE_URL ?>/admin/zprava_sportovci.php">admin/zprava_sportovci.php</a>.</div>

<?php elseif ($person): ?>
<div class="topbar">
    <a href="<?= BASE_URL ?>/admin/chat_mobile.php"><i class="fas fa-arrow-left"></i></a>
    <span class="title"><i class="<?= $type === 'coach' ? 'fas fa-user-tie' : 'fas fa-person-running' ?> type-icon"></i><?= h($person['name']) ?></span>
</div>
<div class="chat-body" id="chatBody">
    <?php if (empty($messages)): ?>
    <div class="empty">Zatím žádné zprávy.</div>
    <?php else: foreach ($messages as $m): $isAdmin = $m['sender'] === 'admin'; ?>
    <div class="bubble-row <?= $isAdmin ? 'from-admin' : 'from-user' ?>">
        <div class="bubble">
            <?= h((string)$m['body']) ?>
            <?php if (!empty($m['attachment_name'])): ?>
            <div style="margin-top:4px"><a href="<?= h(BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path'])) ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> <?= h((string)$m['attachment_name']) ?></a></div>
            <?php endif; ?>
            <span class="bubble-time"><?= formatDateTime((string)$m['created_at']) ?></span>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>
<form class="chat-footer" method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="send">
    <input type="text" name="body" placeholder="Napište zprávu…" maxlength="4000" autocomplete="off" required>
    <button type="submit"><i class="fas fa-paper-plane"></i></button>
</form>
<script>
    var box = document.getElementById('chatBody');
    if (box) { box.scrollTop = box.scrollHeight; }
</script>

<?php else: ?>
<div class="topbar"><span class="title">Chat s trenéry a sportovci</span></div>
<div class="list">
    <?php if (empty($conversations)): ?>
    <div class="empty">Žádný aktivní uživatel.</div>
    <?php else: foreach ($conversations as $row): ?>
    <a class="list-item" href="<?= BASE_URL ?>/admin/chat_mobile.php?type=<?= h($row['type']) ?>&user_id=<?= (int)$row['id'] ?>">
        <div>
            <div class="name">
                <i class="<?= $row['type'] === 'coach' ? 'fas fa-user-tie' : 'fas fa-person-running' ?>"></i><?= h($row['name']) ?>
            </div>
            <div class="preview"><?= !empty($row['last_body']) ? h(mb_strimwidth((string)$row['last_body'], 0, 40, '…')) : 'Zatím žádná zpráva' ?></div>
        </div>
        <div class="meta">
            <?php if ($row['unread_count'] > 0): ?><div class="badge-unread"><?= $row['unread_count'] ?></div><?php endif; ?>
            <?php if (!empty($row['last_at'])): ?><div><?= formatDateTime((string)$row['last_at']) ?></div><?php endif; ?>
        </div>
    </a>
    <?php endforeach; endif; ?>
</div>
<?php endif; ?>

</body>
</html>
