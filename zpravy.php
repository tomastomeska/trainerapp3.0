<?php
// zpravy.php – seznam zpráv pro přihlášeného trenéra
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$coachId = getCurrentCoachId();
$pdo     = getDB();

// Zpracování přesunu do archivu / smazání / obnovení / trvalé smazání
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/zpravy.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'send_chat_to_admin') {
        $chatBody = mb_substr(trim((string)($_POST['chat_body'] ?? '')), 0, 4000, 'UTF-8');
        if ($chatBody === '') {
            flash('danger', 'Zadejte prosím text zprávy.');
            redirect(BASE_URL . '/zpravy.php?tab=admin_chat');
        }

        try {
            $pdo->prepare(
                "INSERT INTO admin_coach_chat_messages (coach_id, sender, body, coach_read_at) VALUES (?, 'coach', ?, NOW())"
            )->execute([$coachId, $chatBody]);
            $chatMessageId = (int)$pdo->lastInsertId();

            $coach = getCurrentCoach();
            $coachName = trim((string)($coach['name'] ?? '')) !== '' ? (string)$coach['name'] : trim((string)($coach['username'] ?? 'trenér'));
            notifyAdminAboutNewCoachChatMessage($coachId, $chatMessageId, $coachName, $chatBody);

            flash('success', 'Zpráva byla odeslána administrátorovi.');
        } catch (Throwable $e) {
            error_log('coach admin chat send error: ' . $e->getMessage());
            flash('danger', 'Chat s administrátorem ještě není na této instanci dostupný.');
        }
        redirect(BASE_URL . '/zpravy.php?tab=admin_chat');
    }

    if ($action === 'send_bulk_to_athletes') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            flash('danger', 'Vyplňte prosím předmět i text zprávy.');
            redirect(BASE_URL . '/zpravy.php?tab=inbox');
        }

        $subject = mb_substr($subject, 0, 200, 'UTF-8');
        $body = mb_substr($body, 0, 4000, 'UTF-8');

        $athletesStmt = $pdo->prepare('SELECT id FROM athletes WHERE coach_id = ?');
        $athletesStmt->execute([$coachId]);
        $athleteIds = array_map(static fn(array $row): int => (int)$row['id'], $athletesStmt->fetchAll());

        if (empty($athleteIds)) {
            flash('warning', 'Nemáte žádné sportovce, kterým by šla zpráva odeslat.');
            redirect(BASE_URL . '/zpravy.php?tab=inbox');
        }

        $sentCount = 0;
        try {
            $pdo->beginTransaction();

            $messageStmt = $pdo->prepare(
                'INSERT INTO coach_athlete_messages (coach_id, subject, body, is_bulk) VALUES (?, ?, ?, 1)'
            );
            $messageStmt->execute([$coachId, $subject, $body]);
            $messageId = (int)$pdo->lastInsertId();

            $notificationStmt = $pdo->prepare('INSERT INTO athlete_notifications (athlete_id, subject, body) VALUES (?, ?, ?)');
            $recipientStmt = $pdo->prepare(
                'INSERT INTO coach_athlete_message_recipients (message_id, athlete_id, notification_id) VALUES (?, ?, ?)'
            );

            foreach ($athleteIds as $athleteId) {
                $notificationStmt->execute([$athleteId, $subject, $body]);
                $notificationId = (int)$pdo->lastInsertId();

                $recipientStmt->execute([$messageId, $athleteId, $notificationId]);
                $sentCount++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', 'Hromadnou zprávu se nepodařilo odeslat.');
            redirect(BASE_URL . '/zpravy.php?tab=inbox');
        }

        flash('success', 'Hromadná zpráva byla odeslána ' . $sentCount . ' sportovcům.');
        redirect(BASE_URL . '/zpravy.php?tab=sent_athletes');
    }

    if ($action === 'bulk_confirm_read') {
        $messageIds = array_values(array_filter(array_map('intval', (array)($_POST['message_ids'] ?? [])), fn($id) => $id > 0));
        $messageIds = array_values(array_unique($messageIds));

        if ($messageIds === []) {
            flash('warning', 'Nevybrali jste žádné zprávy pro hromadné potvrzení.');
        } else {
            $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
            $params       = array_merge([$coachId], $messageIds);

            $bulkStmt = $pdo->prepare("\n                UPDATE admin_message_recipients r\n                LEFT JOIN (SELECT DISTINCT message_id FROM message_actions) ma ON ma.message_id = r.message_id\n                SET r.read_at = NOW()\n                WHERE r.coach_id = ?\n                  AND r.status = 'inbox'\n                  AND r.read_at IS NULL\n                  AND ma.message_id IS NULL\n                  AND r.message_id IN ($placeholders)\n            ");
            $bulkStmt->execute($params);
            $updatedCount = $bulkStmt->rowCount();

            if ($updatedCount > 0) {
                flash('success', "Hromadně potvrzeno přečtení u {$updatedCount} zpráv.");
            } else {
                flash('warning', 'Vybrané zprávy nelze hromadně potvrdit (obsahují akci/podpis nebo už byly přečtené).');
            }
        }

        $redirectTab = $_GET['tab'] ?? 'inbox';
        redirect(BASE_URL . '/zpravy.php?tab=' . urlencode($redirectTab));
    }

    $mid    = intParam($_POST, 'message_id');

    // Zjisti aktuální stav (musí být příjemcem)
    $r = $pdo->prepare("SELECT * FROM admin_message_recipients WHERE message_id = ? AND coach_id = ?");
    $r->execute([$mid, $coachId]);
    $rec = $r->fetch();

    if ($rec) {
        if ($action === 'archive' && $rec['read_at'] !== null) {
            $pdo->prepare("UPDATE admin_message_recipients SET status='archived' WHERE message_id=? AND coach_id=?")
                ->execute([$mid, $coachId]);
            flash('success', 'Zpráva přesunuta do archivu.');
        } elseif ($action === 'delete' && $rec['read_at'] !== null) {
            $pdo->prepare("UPDATE admin_message_recipients SET status='deleted' WHERE message_id=? AND coach_id=?")
                ->execute([$mid, $coachId]);
            flash('success', 'Zpráva přesunuta do koše.');
        } elseif ($action === 'restore') {
            $pdo->prepare("UPDATE admin_message_recipients SET status='inbox' WHERE message_id=? AND coach_id=?")
                ->execute([$mid, $coachId]);
            flash('success', 'Zpráva obnovena do přijatých.');
        } elseif ($action === 'destroy') {
            $pdo->prepare("DELETE FROM admin_message_recipients WHERE message_id=? AND coach_id=?")
                ->execute([$mid, $coachId]);
            flash('success', 'Zpráva byla trvale smazána.');
        }
    }
    $redirectTab = $_GET['tab'] ?? '';
    redirect(BASE_URL . '/zpravy.php' . ($redirectTab ? '?tab=' . urlencode($redirectTab) : ''));
}

$tab = in_array($_GET['tab'] ?? '', ['archived','deleted','sent_athletes','admin_chat'], true) ? (string)$_GET['tab'] : 'inbox';

// Individuální chat s administrátorem (tabulka nemusí existovat, dokud neproběhne migrace)
$adminChatTableExists = false;
try {
    $chatTableCheck = $pdo->query("SHOW TABLES LIKE 'admin_coach_chat_messages'");
    $adminChatTableExists = $chatTableCheck !== false && (bool)$chatTableCheck->fetchColumn();
} catch (Throwable $e) {
    $adminChatTableExists = false;
}

$adminChatMessages = [];
$adminChatUnread = 0;
if ($adminChatTableExists) {
    if ($tab === 'admin_chat') {
        $pdo->prepare("UPDATE admin_coach_chat_messages SET coach_read_at = NOW() WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL")
            ->execute([$coachId]);
    }
    $adminChatStmt = $pdo->prepare('SELECT * FROM admin_coach_chat_messages WHERE coach_id = ? ORDER BY created_at ASC, id ASC');
    $adminChatStmt->execute([$coachId]);
    $adminChatMessages = $adminChatStmt->fetchAll();

    $adminChatUnreadStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_coach_chat_messages WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL");
    $adminChatUnreadStmt->execute([$coachId]);
    $adminChatUnread = (int)$adminChatUnreadStmt->fetchColumn();
}

$coachAthleteChatTableExists = false;
try {
    $coachAthleteChatCheck = $pdo->query("SHOW TABLES LIKE 'coach_athlete_chat_messages'");
    $coachAthleteChatTableExists = $coachAthleteChatCheck !== false && (bool)$coachAthleteChatCheck->fetchColumn();
} catch (Throwable $e) {
    $coachAthleteChatTableExists = false;
}

// Zprávy pro tohoto trenéra dle záložky
$messages = $pdo->prepare("
    SELECT m.id, m.subject, m.sent_at, m.attachment_name,
           r.read_at, r.status,
           COALESCE(ma.has_actions, 0) AS has_actions
    FROM admin_messages m
    JOIN admin_message_recipients r ON r.message_id = m.id AND r.coach_id = ?
    LEFT JOIN (
        SELECT DISTINCT message_id, 1 AS has_actions
        FROM message_actions
    ) ma ON ma.message_id = m.id
    WHERE r.status = ?
    ORDER BY m.sent_at DESC
");
$messages = [];
$bulkSentMessages = [];
$bulkRecipientsByMessage = [];

if ($tab === 'sent_athletes') {
    $bulkSentStmt = $pdo->prepare(
        "SELECT cam.id,
                cam.subject,
                cam.body,
                cam.created_at,
                COUNT(car.id) AS recipient_count,
                SUM(CASE WHEN an.read_at IS NOT NULL THEN 1 ELSE 0 END) AS read_count
         FROM coach_athlete_messages cam
         LEFT JOIN coach_athlete_message_recipients car ON car.message_id = cam.id
         LEFT JOIN athlete_notifications an ON an.id = car.notification_id
         WHERE cam.coach_id = ? AND cam.is_bulk = 1
         GROUP BY cam.id
         ORDER BY cam.created_at DESC, cam.id DESC"
    );
    $bulkSentStmt->execute([$coachId]);
    $bulkSentMessages = $bulkSentStmt->fetchAll();

    $messageIds = array_map(static fn(array $row): int => (int)$row['id'], $bulkSentMessages);
    if (!empty($messageIds)) {
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $recipientStmt = $pdo->prepare(
            "SELECT car.message_id,
                    a.id AS athlete_id,
                    a.first_name,
                    a.last_name,
                    an.read_at
             FROM coach_athlete_message_recipients car
             JOIN athletes a ON a.id = car.athlete_id
             LEFT JOIN athlete_notifications an ON an.id = car.notification_id
             WHERE car.message_id IN ($placeholders)
             ORDER BY a.first_name ASC, a.last_name ASC, a.id ASC"
        );
        $recipientStmt->execute($messageIds);
        foreach ($recipientStmt->fetchAll() as $recipient) {
            $mid = (int)$recipient['message_id'];
            if (!isset($bulkRecipientsByMessage[$mid])) {
                $bulkRecipientsByMessage[$mid] = [];
            }
            $bulkRecipientsByMessage[$mid][] = $recipient;
        }
    }
} elseif ($tab !== 'admin_chat') {
    // Zprávy pro tohoto trenéra dle záložky
    $messagesStmt = $pdo->prepare("
        SELECT m.id, m.subject, m.sent_at, m.attachment_name,
               r.read_at, r.status,
               COALESCE(ma.has_actions, 0) AS has_actions
        FROM admin_messages m
        JOIN admin_message_recipients r ON r.message_id = m.id AND r.coach_id = ?
        LEFT JOIN (
            SELECT DISTINCT message_id, 1 AS has_actions
            FROM message_actions
        ) ma ON ma.message_id = m.id
        WHERE r.status = ?
        ORDER BY m.sent_at DESC
    ");
    $messagesStmt->execute([$coachId, $tab]);
    $messages = $messagesStmt->fetchAll();
}

// Počty pro badge záložek
$counts = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt,
           SUM(read_at IS NULL) AS unread
    FROM admin_message_recipients
    WHERE coach_id = ?
    GROUP BY status
");
$counts->execute([$coachId]);
$tabCounts = [];
foreach ($counts->fetchAll() as $row) {
    $tabCounts[$row['status']] = ['cnt' => $row['cnt'], 'unread' => $row['unread']];
}

$bulkCountStmt = $pdo->prepare('SELECT COUNT(*) FROM coach_athlete_messages WHERE coach_id = ? AND is_bulk = 1');
$bulkCountStmt->execute([$coachId]);
$sentAthletesCount = (int)$bulkCountStmt->fetchColumn();

$unreadInbox = (int)($tabCounts['inbox']['unread'] ?? 0);

renderHeader('Zprávy', false, true);
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <h3 class="fw-bold mb-0">
        <i class="fas fa-envelope me-2 text-primary"></i>Moje zprávy
    </h3>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($coachAthleteChatTableExists): ?>
        <a href="<?= BASE_URL ?>/coach_chat_mobile.php" class="btn btn-outline-success" target="_blank" rel="noopener">
            <i class="fas fa-mobile-screen-button me-1"></i>Mobilní chat
        </a>
        <?php endif; ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkAthleteMessageModal">
            <i class="fas fa-paper-plane me-1"></i>Napsat všem sportovcům
        </button>
    </div>
</div>

<!-- Záložky -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'inbox' ? 'active' : '' ?>" href="<?= BASE_URL ?>/zpravy.php?tab=inbox">
            <i class="fas fa-inbox me-1"></i>Přijaté
            <?php $inboxCnt = (int)($tabCounts['inbox']['cnt'] ?? 0); if ($inboxCnt > 0): ?>
            <span class="badge <?= $unreadInbox > 0 ? 'bg-danger' : 'bg-secondary' ?> ms-1">
                <?= $unreadInbox > 0 ? $unreadInbox : $inboxCnt ?>
            </span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'archived' ? 'active' : '' ?>" href="<?= BASE_URL ?>/zpravy.php?tab=archived">
            <i class="fas fa-archive me-1"></i>Archiv
            <?php $archCnt = (int)($tabCounts['archived']['cnt'] ?? 0); if ($archCnt > 0): ?>
            <span class="badge bg-secondary ms-1"><?= $archCnt ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'deleted' ? 'active' : '' ?>" href="<?= BASE_URL ?>/zpravy.php?tab=deleted">
            <i class="fas fa-trash me-1"></i>Smazané
            <?php $delCnt = (int)($tabCounts['deleted']['cnt'] ?? 0); if ($delCnt > 0): ?>
            <span class="badge bg-secondary ms-1"><?= $delCnt ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'admin_chat' ? 'active' : '' ?>" href="<?= BASE_URL ?>/zpravy.php?tab=admin_chat">
            <i class="fas fa-comments me-1"></i>Administrátor
            <?php if ($adminChatUnread > 0): ?>
            <span class="badge bg-danger ms-1"><?= $adminChatUnread ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'admin_chat'): ?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card border-0 shadow-sm">
            <div class="card-body" style="max-height:60vh; overflow-y:auto" id="adminChatScroll">
                <?php if (empty($adminChatMessages)): ?>
                <div class="text-muted text-center py-4">Zatím žádné zprávy. Napšte administrátorovi svůj dotaz.</div>
                <?php else: foreach ($adminChatMessages as $m): $isCoachMsg = $m['sender'] === 'coach'; ?>
                <div class="d-flex mb-3 <?= $isCoachMsg ? 'justify-content-end' : 'justify-content-start' ?>">
                    <div class="p-2 px-3 rounded-3 <?= $isCoachMsg ? 'bg-primary text-white' : 'bg-light border' ?>" style="max-width:75%">
                        <div style="white-space:pre-wrap"><?= h((string)$m['body']) ?></div>
                        <?php if (!empty($m['attachment_name'])): ?>
                        <div class="mt-1">
                            <a class="d-inline-flex align-items-center gap-1 small <?= $isCoachMsg ? 'text-white' : 'text-primary' ?>" href="<?= h(BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path'])) ?>" target="_blank" rel="noopener">
                                <i class="fas fa-paperclip"></i><?= h((string)$m['attachment_name']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="small mt-1 <?= $isCoachMsg ? 'text-white-50' : 'text-muted' ?>">
                            <?= $isCoachMsg ? 'Vy' : 'Administrátor' ?> · <?= date('d.m.Y H:i', strtotime((string)$m['created_at'])) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="card-footer">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="send_chat_to_admin">
                    <div class="mb-2">
                        <textarea name="chat_body" class="form-control" rows="3" maxlength="4000" placeholder="Napšte zprávu administrátorovi..." required></textarea>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Odeslat</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('adminChatScroll');
    if (box) { box.scrollTop = box.scrollHeight; }
});
</script>

<?php else: ?>

<?php if (empty($messages)): ?>
<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>
    <?php if ($tab === 'inbox'): ?>Nemáte žádné zprávy.
    <?php elseif ($tab === 'archived'): ?>Archiv je prázdný.
    <?php else: ?>Koš je prázdný.<?php endif; ?>
</div>
<?php else: ?>
<?php if ($tab === 'inbox'): ?>
<form method="post" id="bulkMarkReadForm" class="d-flex align-items-center flex-wrap gap-2 mb-3">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="bulk_confirm_read">
    <button type="submit" class="btn btn-sm btn-primary" id="btnBulkConfirmRead" disabled>
        <i class="fas fa-check-double me-1"></i>Hromadně potvrdit přečtení
    </button>
    <span class="text-muted small">Platí jen pro nepřečtené zprávy bez akčního tlačítka nebo podpisu.</span>
</form>
<?php endif; ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="msgTable">
            <thead class="table-dark">
                <tr>
                    <th style="width:36px">
                        <?php if ($tab === 'inbox'): ?>
                        <input type="checkbox" class="form-check-input" id="bulkSelectAll" title="Vybrat vše pro hromadné potvrzení">
                        <?php endif; ?>
                    </th>
                    <th style="width:22px"></th>
                    <th>Předmět</th>
                    <th>Datum</th>
                    <th style="width:40px"><i class="fas fa-paperclip"></i></th>
                    <th>Stav</th>
                    <th style="width:110px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($messages as $m): ?>
            <?php $unread = $m['read_at'] === null; ?>
            <?php $hasActions = (int)$m['has_actions'] === 1; ?>
            <?php $canBulkConfirm = $tab === 'inbox' && $unread && !$hasActions; ?>
            <tr class="<?= $unread ? 'table-warning fw-semibold' : '' ?> msg-row"
                style="cursor:pointer"
                data-href="<?= BASE_URL ?>/zprava_detail.php?id=<?= $m['id'] ?>">
                <td onclick="event.stopPropagation()">
                    <?php if ($tab === 'inbox'): ?>
                    <input
                        type="checkbox"
                        class="form-check-input js-bulk-read-item"
                        form="bulkMarkReadForm"
                        name="message_ids[]"
                        value="<?= (int)$m['id'] ?>"
                        <?= $canBulkConfirm ? '' : 'disabled' ?>
                    >
                    <?php endif; ?>
                </td>
                <td>
                    <i class="fas fa-circle <?= $unread ? 'text-danger' : 'text-success' ?>"
                       style="font-size:.55rem"></i>
                </td>
                <td><?= h($m['subject']) ?></td>
                <td class="text-nowrap"><?= date('d.m.Y H:i', strtotime($m['sent_at'])) ?></td>
                <td><?php if ($m['attachment_name']): ?><i class="fas fa-paperclip text-muted"></i><?php endif; ?></td>
                <td>
                    <?php if ($unread): ?>
                    <span class="badge bg-danger">Nepřečteno</span>
                    <?php if ($hasActions): ?>
                    <span class="badge bg-warning text-dark ms-1">Nutné otevřít</span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="badge bg-success">Přečteno</span>
                    <?php endif; ?>
                </td>
                <td class="text-end" onclick="event.stopPropagation()">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="message_id" value="<?= $m['id'] ?>">
                        <?php if ($tab === 'inbox'): ?>
                            <?php if (!$unread): ?>
                            <button name="action" value="archive" class="btn btn-sm btn-outline-secondary" title="Archivovat">
                                <i class="fas fa-archive"></i>
                            </button>
                            <button name="action" value="delete" class="btn btn-sm btn-outline-danger" title="Smazat"
                                    onclick="return confirm('Přesunout do koše?')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php else: ?>
                            <span class="text-muted small"><?= $hasActions ? 'otevřete zprávu' : 'lze hromadně' ?></span>
                            <?php endif; ?>
                        <?php elseif ($tab === 'archived'): ?>
                            <button name="action" value="restore" class="btn btn-sm btn-outline-primary" title="Obnovit">
                                <i class="fas fa-inbox"></i>
                            </button>
                            <button name="action" value="delete" class="btn btn-sm btn-outline-danger" title="Do koše"
                                    onclick="return confirm('Přesunout do koše?')">
                                <i class="fas fa-trash"></i>
                            </button>
                        <?php else: ?>
                            <button name="action" value="restore" class="btn btn-sm btn-outline-primary" title="Obnovit">
                                <i class="fas fa-inbox"></i>
                            </button>
                            <button name="action" value="destroy" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('Trvale smazat? Tuto akci nelze vrátit.')" title="Trvale smazat">
                                <i class="fas fa-times"></i>
                            </button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
// Klik na celý řádek → detail zprávy
document.querySelectorAll('.msg-row').forEach(row => {
    row.addEventListener('click', () => {
        window.location.href = row.dataset.href;
    });
});

const bulkSelectAll = document.getElementById('bulkSelectAll');
const bulkItems = Array.from(document.querySelectorAll('.js-bulk-read-item'));
const bulkSubmit = document.getElementById('btnBulkConfirmRead');

function updateBulkState() {
    if (!bulkSubmit) return;
    const selectedCount = bulkItems.filter((item) => item.checked && !item.disabled).length;
    bulkSubmit.disabled = selectedCount === 0;
}

if (bulkSelectAll) {
    bulkSelectAll.addEventListener('change', () => {
        bulkItems.forEach((item) => {
            if (!item.disabled) {
                item.checked = bulkSelectAll.checked;
            }
        });
        updateBulkState();
    });
}

bulkItems.forEach((item) => {
    item.addEventListener('change', () => {
        if (bulkSelectAll) {
            const enabledItems = bulkItems.filter((it) => !it.disabled);
            bulkSelectAll.checked = enabledItems.length > 0 && enabledItems.every((it) => it.checked);
        }
        updateBulkState();
    });
});

updateBulkState();
</script>

<div class="modal fade" id="bulkAthleteMessageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send_bulk_to_athletes">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-paper-plane me-2 text-warning"></i>Hromadná zpráva sportovcům</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border small mb-3">
                        Zpráva se odešle všem vašim sportovcům do jejich modulu <strong>Zprávy</strong>.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Předmět <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" maxlength="200" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Text zprávy <span class="text-danger">*</span></label>
                        <textarea name="body" class="form-control" rows="6" maxlength="4000" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i>Odeslat všem
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
