<?php
// athlete_chat_mobile.php – odlehčená mobilní stránka jen pro chat sportovce s trenérem a administrátorem (pro záložku/plochu na mobilu)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAthleteLogin();
$athleteId = (int)getCurrentAthleteId();
$athlete = getCurrentAthlete();
$coachId = (int)($athlete['coach_id'] ?? 0);
$pdo = getDB();

$coachChatTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'coach_athlete_chat_messages'")->fetchColumn();
$adminChatTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'admin_athlete_chat_messages'")->fetchColumn();

$coachName = '';
if ($coachId > 0) {
    $coachStmt = $pdo->prepare('SELECT name, username, email FROM coaches WHERE id = ? LIMIT 1');
    $coachStmt->execute([$coachId]);
    $coach = $coachStmt->fetch();
    if ($coach) {
        $coachName = ($coach['name'] ?? '') !== '' ? (string)$coach['name'] : (string)($coach['username'] ?? 'trenér');
    }
}

$target = in_array($_GET['target'] ?? '', ['coach', 'admin'], true) ? (string)$_GET['target'] : '';
if ($target === 'coach' && (!$coachChatTableExists || $coachId <= 0)) {
    $target = '';
}
if ($target === 'admin' && !$adminChatTableExists) {
    $target = '';
}

if ($target !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_chat_mobile.php?target=' . $target);
    }

    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000, 'UTF-8');
    if ($body !== '') {
        try {
            if ($target === 'coach') {
                $pdo->prepare(
                    "INSERT INTO coach_athlete_chat_messages (coach_id, athlete_id, sender, body, athlete_read_at) VALUES (?, ?, 'athlete', ?, NOW())"
                )->execute([$coachId, $athleteId, $body]);
                $newId = (int)$pdo->lastInsertId();

                $athleteName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
                if (!empty($coach['email'])) {
                    notifyCoachAboutNewAthleteChatMessage($coachId, $athleteId, $newId, (string)$coach['email'], $coachName, $athleteName !== '' ? $athleteName : 'sportovec', $body);
                }
            } else {
                $pdo->prepare(
                    "INSERT INTO admin_athlete_chat_messages (athlete_id, sender, body, athlete_read_at) VALUES (?, 'athlete', ?, NOW())"
                )->execute([$athleteId, $body]);
                $newId = (int)$pdo->lastInsertId();

                $athleteName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
                notifyAdminAboutNewAthleteChatMessage($athleteId, $newId, $athleteName !== '' ? $athleteName : 'sportovec', $body);
            }
        } catch (Throwable $e) {
            error_log('athlete chat_mobile send error: ' . $e->getMessage());
        }
    }
    redirect(BASE_URL . '/athlete_chat_mobile.php?target=' . $target);
}

$messages = [];
if ($target === 'coach') {
    $pdo->prepare("UPDATE coach_athlete_chat_messages SET athlete_read_at = NOW() WHERE athlete_id = ? AND sender = 'coach' AND athlete_read_at IS NULL")
        ->execute([$athleteId]);
    $threadStmt = $pdo->prepare('SELECT * FROM coach_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at ASC, id ASC');
    $threadStmt->execute([$athleteId]);
    $messages = $threadStmt->fetchAll();
} elseif ($target === 'admin') {
    $pdo->prepare("UPDATE admin_athlete_chat_messages SET athlete_read_at = NOW() WHERE athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL")
        ->execute([$athleteId]);
    $threadStmt = $pdo->prepare('SELECT * FROM admin_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at ASC, id ASC');
    $threadStmt->execute([$athleteId]);
    $messages = $threadStmt->fetchAll();
}

$conversations = [];
if ($target === '') {
    if ($coachChatTableExists && $coachId > 0) {
        $lastStmt = $pdo->prepare(
            "SELECT
                (SELECT body FROM coach_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at DESC, id DESC LIMIT 1) AS last_body,
                (SELECT created_at FROM coach_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at DESC, id DESC LIMIT 1) AS last_at,
                (SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE athlete_id = ? AND sender = 'coach' AND athlete_read_at IS NULL) AS unread_count"
        );
        $lastStmt->execute([$athleteId, $athleteId, $athleteId]);
        $row = $lastStmt->fetch();
        $conversations[] = [
            'target' => 'coach',
            'name' => $coachName !== '' ? $coachName : 'Trenér',
            'last_body' => $row['last_body'] ?? null,
            'last_at' => $row['last_at'] ?? null,
            'unread_count' => (int)($row['unread_count'] ?? 0),
        ];
    }
    if ($adminChatTableExists) {
        $lastStmt = $pdo->prepare(
            "SELECT
                (SELECT body FROM admin_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at DESC, id DESC LIMIT 1) AS last_body,
                (SELECT created_at FROM admin_athlete_chat_messages WHERE athlete_id = ? ORDER BY created_at DESC, id DESC LIMIT 1) AS last_at,
                (SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL) AS unread_count"
        );
        $lastStmt->execute([$athleteId, $athleteId, $athleteId]);
        $row = $lastStmt->fetch();
        $conversations[] = [
            'target' => 'admin',
            'name' => 'Administrátor',
            'last_body' => $row['last_body'] ?? null,
            'last_at' => $row['last_at'] ?? null,
            'unread_count' => (int)($row['unread_count'] ?? 0),
        ];
    }
}

$targetName = $target === 'coach' ? ($coachName !== '' ? $coachName : 'Trenér') : ($target === 'admin' ? 'Administrátor' : '');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="mobile-web-app-capable" content="yes">
<title><?= $target !== '' ? h($targetName) : 'Zprávy' ?></title>
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
        background: linear-gradient(135deg, #f39c12, #d68910);
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
    .list-item .name .fa-user-tie { color: #0d6efd; margin-right: 4px; }
    .list-item .name .fa-user-shield { color: #128c7e; margin-right: 4px; }
    .list-item .preview { font-size: 13px; color: #667781; margin-top: 2px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .list-item .meta { text-align: right; font-size: 11px; color: #667781; }
    .badge-unread {
        display: inline-block; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px;
        background: #dc3545; color: #fff; font-size: 11px; font-weight: 700; line-height: 20px; text-align: center;
    }
    .empty { padding: 30px 16px; text-align: center; color: #667781; }
    .chat-body { flex: 1; overflow-y: auto; padding: 12px; }
    .bubble-row { display: flex; margin-bottom: 8px; }
    .bubble-row.from-athlete { justify-content: flex-end; }
    .bubble {
        max-width: 80%; padding: 8px 11px; border-radius: 10px; font-size: 14.5px; line-height: 1.4;
        white-space: pre-wrap; word-wrap: break-word; box-shadow: 0 1px 1px rgba(0,0,0,0.08);
    }
    .bubble-row.from-other .bubble { background: #fff; }
    .bubble-row.from-athlete .bubble { background: #ffe8b3; }
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
        border: none; background: #f39c12; color: #fff; width: 42px; height: 42px; border-radius: 50%; font-size: 16px;
    }
    .a2hs-banner {
        margin: 10px; padding: 12px 14px; background: #fff7e6; border: 1px solid #ffe1a8; border-radius: 10px; font-size: 13px; color: #5c4400;
    }
    .a2hs-banner strong { display: block; margin-bottom: 4px; }
    .a2hs-banner .a2hs-close { float: right; border: none; background: none; font-size: 16px; color: #5c4400; line-height: 1; }
    .notify-bar {
        margin: 0 10px 10px; padding: 10px 12px; background: #eef6ff; border: 1px solid #cfe4ff; border-radius: 10px;
        display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 13px; color: #0b3d78;
    }
    .notify-bar button {
        border: none; background: #0d6efd; color: #fff; padding: 6px 12px; border-radius: 8px; font-size: 13px; white-space: nowrap;
    }
</style>
</head>
<body>

<?php if ($target !== ''): ?>
<div class="topbar">
    <a href="<?= BASE_URL ?>/athlete_chat_mobile.php"><i class="fas fa-arrow-left"></i></a>
    <span class="title"><i class="<?= $target === 'coach' ? 'fas fa-user-tie' : 'fas fa-user-shield' ?>"></i> <?= h($targetName) ?></span>
</div>
<div class="chat-body" id="chatBody">
    <?php if (empty($messages)): ?>
    <div class="empty">Zatím žádné zprávy.</div>
    <?php else: foreach ($messages as $m): $isMine = $m['sender'] === 'athlete'; ?>
    <div class="bubble-row <?= $isMine ? 'from-athlete' : 'from-other' ?>">
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
(function () {
    var box = document.getElementById('chatBody');
    if (box) { box.scrollTop = box.scrollHeight; }

    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }

    var apiUrl = <?= json_encode(BASE_URL . ($target === 'coach' ? '/api/athlete_coach_chat.php' : '/api/athlete_chat_admin.php')) ?>;
    var lastId = <?= (int)($messages !== [] ? $messages[count($messages) - 1]['id'] : 0) ?>;
    var partnerLabel = <?= json_encode($targetName) ?>;

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function appendMessage(m) {
        var row = document.createElement('div');
        row.className = 'bubble-row ' + (m.sender === 'athlete' ? 'from-athlete' : 'from-other');
        var html = '<div class="bubble">' + escapeHtml(m.body).replace(/\n/g, '<br>');
        if (m.attachment_url) {
            html += '<div style="margin-top:4px"><a href="' + m.attachment_url + '" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> ' + escapeHtml(m.attachment_name || 'Příloha') + '</a></div>';
        }
        html += '<span class="bubble-time">' + escapeHtml(m.created_at) + '</span></div>';
        row.innerHTML = html;
        box.appendChild(row);
        if (Number(m.id) > lastId) { lastId = Number(m.id); }
    }

    function pollNew() {
        fetch(apiUrl + '?action=poll&since_id=' + lastId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.messages.length) { return; }
                var hasIncoming = false;
                data.messages.forEach(function (m) {
                    appendMessage(m);
                    if (m.sender !== 'athlete') { hasIncoming = true; }
                });
                box.scrollTop = box.scrollHeight;
                if (hasIncoming && 'Notification' in window && Notification.permission === 'granted' && document.hidden) {
                    new Notification('Nová zpráva od ' + partnerLabel, { body: 'Máš novou zprávu v chatu.' });
                }
            })
            .catch(function () {});
    }

    setInterval(pollNew, 5000);
})();
</script>

<?php else: ?>
<div class="topbar"><span class="title">Zprávy</span></div>
<div class="a2hs-banner d-none" id="a2hsBanner">
    <button type="button" class="a2hs-close" id="a2hsClose" aria-label="Zavřít">&times;</button>
    <strong><i class="fas fa-mobile-screen-button me-1"></i>Ulož si tuto stránku na plochu</strong>
    <span id="a2hsText">Otevři menu prohlížeče a zvol „Přidat na plochu“ – pak sem naskočíš jedním klepnutím jako z aplikace.</span>
</div>
<div class="notify-bar d-none" id="notifyBar">
    <span><i class="fas fa-bell me-1"></i>Chceš v mobilu upozornění na novou zprávu?</span>
    <button type="button" id="notifyEnableBtn">Povolit</button>
</div>
<div class="list" id="convList">
    <?php if (empty($conversations)): ?>
    <div class="empty">Chat zatím není k dispozici.</div>
    <?php else: foreach ($conversations as $row): ?>
    <a class="list-item" data-target="<?= h($row['target']) ?>" href="<?= BASE_URL ?>/athlete_chat_mobile.php?target=<?= h($row['target']) ?>">
        <div>
            <div class="name">
                <i class="<?= $row['target'] === 'coach' ? 'fas fa-user-tie' : 'fas fa-user-shield' ?>"></i><?= h($row['name']) ?>
            </div>
            <div class="preview"><?= !empty($row['last_body']) ? h(mb_strimwidth((string)$row['last_body'], 0, 40, '…')) : 'Zatím žádná zpráva' ?></div>
        </div>
        <div class="meta">
            <span class="badge-slot">
            <?php if ($row['unread_count'] > 0): ?><div class="badge-unread"><?= $row['unread_count'] ?></div><?php endif; ?>
            </span>
            <?php if (!empty($row['last_at'])): ?><div><?= formatDateTime((string)$row['last_at']) ?></div><?php endif; ?>
        </div>
    </a>
    <?php endforeach; endif; ?>
</div>
<script>
(function () {
    var a2hsBanner = document.getElementById('a2hsBanner');
    var a2hsClose = document.getElementById('a2hsClose');
    var a2hsText = document.getElementById('a2hsText');
    var dismissed = false;
    try { dismissed = localStorage.getItem('athleteChatA2hsDismissed') === '1'; } catch (e) {}

    if (!dismissed && a2hsBanner) {
        var ua = navigator.userAgent || '';
        var isIos = /iphone|ipad|ipod/i.test(ua);
        if (isIos) {
            a2hsText.textContent = 'Klepni dole na ikonu Sdílet (čtvereček se šipkou) a zvol "Přidat na plochu".';
        } else {
            a2hsText.textContent = 'Klepni na tři tečky (nabídku) v prohlížeči a zvol "Přidat na plochu" / "Nainstalovat aplikaci".';
        }
        a2hsBanner.classList.remove('d-none');
    }

    if (a2hsClose) {
        a2hsClose.addEventListener('click', function () {
            a2hsBanner.classList.add('d-none');
            try { localStorage.setItem('athleteChatA2hsDismissed', '1'); } catch (e) {}
        });
    }

    var notifyBar = document.getElementById('notifyBar');
    var notifyBtn = document.getElementById('notifyEnableBtn');
    if (notifyBar && 'Notification' in window) {
        if (Notification.permission === 'default') {
            notifyBar.classList.remove('d-none');
        }
        notifyBtn.addEventListener('click', function () {
            Notification.requestPermission().then(function () {
                notifyBar.classList.add('d-none');
            });
        });
    }

    var endpoints = [
        { target: 'coach', url: <?= json_encode(BASE_URL . '/api/athlete_coach_chat.php') ?> },
        { target: 'admin', url: <?= json_encode(BASE_URL . '/api/athlete_chat_admin.php') ?> }
    ];
    var lastCounts = {};

    function refreshUnread() {
        endpoints.forEach(function (ep) {
            fetch(ep.url + '?action=unread_count', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) { return; }
                    var count = data.unread_count || 0;
                    var row = document.querySelector('.list-item[data-target="' + ep.target + '"] .badge-slot');
                    if (row) {
                        row.innerHTML = count > 0 ? '<div class="badge-unread">' + count + '</div>' : '';
                    }
                    if (lastCounts[ep.target] !== undefined && count > lastCounts[ep.target] && 'Notification' in window && Notification.permission === 'granted') {
                        var label = ep.target === 'coach' ? 'trenéra' : 'administrátora';
                        new Notification('Nová zpráva od ' + label, { body: 'Máš novou zprávu v chatu.', icon: '' });
                    }
                    lastCounts[ep.target] = count;
                })
                .catch(function () {});
        });
    }

    refreshUnread();
    setInterval(refreshUnread, 20000);
})();
</script>
<?php endif; ?>

</body>
</html>
