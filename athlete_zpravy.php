<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/chat_attachments.php';
require_once __DIR__ . '/includes/chat_message_deletions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$athlete   = getCurrentAthlete();
$coachId   = (int)($athlete['coach_id'] ?? 0);
$pdo       = getDB();
ensureChatMessageDeletionTable($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_zpravy.php');
    }

    $action = (string)($_POST['action'] ?? '');

    // Označit přijatou zprávu jako přečtenou
    if ($action === 'mark_read') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        if ($notificationId > 0) {
            $pdo->prepare('UPDATE athlete_notifications SET read_at = NOW() WHERE id = ? AND athlete_id = ? AND read_at IS NULL')
                ->execute([$notificationId, $athleteId]);
        }
        redirect(BASE_URL . '/athlete_zpravy.php');
    }

    if ($action === 'bulk_mark_read') {
        $notificationIds = array_values(array_filter(array_map('intval', (array)($_POST['notification_ids'] ?? [])), static fn($id) => $id > 0));
        $notificationIds = array_values(array_unique($notificationIds));

        if ($notificationIds === []) {
            flash('warning', 'Nevybrali jste žádné zprávy pro hromadné potvrzení.');
            redirect(BASE_URL . '/athlete_zpravy.php?tab=inbox');
        }

        $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
        $params = array_merge([$athleteId], $notificationIds);
        $stmtBulk = $pdo->prepare(
            "UPDATE athlete_notifications
             SET read_at = NOW()
             WHERE athlete_id = ?
               AND read_at IS NULL
               AND id IN ($placeholders)"
        );
        $stmtBulk->execute($params);
        $updatedCount = $stmtBulk->rowCount();

        if ($updatedCount > 0) {
            flash('success', "Hromadně potvrzeno přečtení u {$updatedCount} zpráv.");
        } else {
            flash('warning', 'Vybrané zprávy už byly přečtené nebo nejsou dostupné.');
        }

        redirect(BASE_URL . '/athlete_zpravy.php?tab=inbox');
    }

    // Napsat zprávu administrátorovi (individuální chat)
    if ($action === 'send_chat_to_admin') {
        $chatBody = mb_substr(trim((string)($_POST['chat_body'] ?? '')), 0, 4000, 'UTF-8');
        $attachment = receiveChatAttachment($_FILES);
        if ($attachment['error'] !== null) {
            flash('danger', $attachment['error']);
            redirect(BASE_URL . '/athlete_zpravy.php?tab=admin_chat');
        }
        if ($chatBody === '' && $attachment['path'] === null) {
            flash('danger', 'Zadejte zprávu nebo přidejte přílohu.');
            redirect(BASE_URL . '/athlete_zpravy.php?tab=admin_chat');
        }

        try {
            $pdo->prepare(
                "INSERT INTO admin_athlete_chat_messages (athlete_id, sender, body, attachment_path, attachment_name, athlete_read_at) VALUES (?, 'athlete', ?, ?, ?, NOW())"
            )->execute([$athleteId, $chatBody, $attachment['path'], $attachment['name']]);
            $chatMessageId = (int)$pdo->lastInsertId();

            $athleteFullName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
            notifyAdminAboutNewAthleteChatMessage($athleteId, $chatMessageId, $athleteFullName !== '' ? $athleteFullName : 'sportovec', chatNotificationText($chatBody, $attachment['name']));

            flash('success', 'Zpráva byla odeslána administrátorovi.');
        } catch (Throwable $e) {
            error_log('athlete admin chat send error: ' . $e->getMessage());
            flash('danger', 'Chat s administrátorem ještě není na této instanci dostupný.');
        }
        redirect(BASE_URL . '/athlete_zpravy.php?tab=admin_chat');
    }

    // Napsat novou zprávu trenérovi
    if ($action === 'send_message') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body    = trim((string)($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            flash('danger', 'Vyplňte prosím předmět i text zprávy.');
            redirect(BASE_URL . '/athlete_zpravy.php?tab=sent');
        }

        if ($coachId > 0) {
            $athleteName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
            $fullSubject = "[Zpráva od sportovce] {$athleteName}: {$subject}";
            createAthleteToCoachMessage($athleteId, $coachId, $fullSubject, $body);
            flash('success', 'Zpráva byla odeslána trenérovi.');
        } else {
            flash('danger', 'Nepodařilo se odeslat zprávu – trenér nenalezen.');
        }
        redirect(BASE_URL . '/athlete_zpravy.php?tab=sent');
    }

    redirect(BASE_URL . '/athlete_zpravy.php');
}

$tab = in_array($_GET['tab'] ?? '', ['sent', 'admin_chat']) ? $_GET['tab'] : 'inbox';

// Individuální chat s administrátorem (tabulka nemusí existovat, dokud neproběhne migrace)
$adminChatTableExists = false;
try {
    $chatTableCheck = $pdo->query("SHOW TABLES LIKE 'admin_athlete_chat_messages'");
    $adminChatTableExists = $chatTableCheck !== false && (bool)$chatTableCheck->fetchColumn();
} catch (Throwable $e) {
    $adminChatTableExists = false;
}

$adminChatMessages = [];
$adminChatUnread = 0;
if ($adminChatTableExists) {
    if ($tab === 'admin_chat') {
        $pdo->prepare("UPDATE admin_athlete_chat_messages SET athlete_read_at = NOW() WHERE athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL")
            ->execute([$athleteId]);
    }
    $adminChatMessages = fetchChatMessagesForUser($pdo, 'admin_athlete_chat_messages', ['athlete_id' => $athleteId], 'athlete', $athleteId);

    $adminChatUnreadStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL");
    $adminChatUnreadStmt->execute([$athleteId]);
    $adminChatUnread = (int)$adminChatUnreadStmt->fetchColumn();
}

// Přijaté zprávy (od trenéra)
$inboxStmt = $pdo->prepare(
    'SELECT id, subject, body, attachment_path, attachment_name, read_at, created_at
     FROM athlete_notifications
     WHERE athlete_id = ?
     ORDER BY created_at DESC, id DESC'
);
$inboxStmt->execute([$athleteId]);
$inbox = $inboxStmt->fetchAll();

// Odeslané zprávy trenérovi
$sentStmt = $pdo->prepare(
    'SELECT m.id, m.subject, m.body, m.sent_at,
            r.read_at AS coach_read_at
     FROM admin_messages m
     LEFT JOIN admin_message_recipients r ON r.message_id = m.id AND r.coach_id = ?
     WHERE m.from_athlete_id = ?
     ORDER BY m.sent_at DESC'
);
$sentStmt->execute([$coachId, $athleteId]);
$sent = $sentStmt->fetchAll();

$unreadCount = count(array_filter($inbox, fn($m) => empty($m['read_at'])));

renderAthleteHeader('Zprávy', false, true);
?>

<style>
.athlete-message-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: .6rem;
}

.athlete-message-actions .athlete-page-action {
    width: 100%;
}

.athlete-message-tip {
    padding: .8rem 1rem;
    border: 1px solid #e2e8f0;
    border-left: 3px solid #e8c34f;
    border-radius: 10px;
    background: #fff;
    color: #475569;
}

.athlete-message-tabs {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: .45rem;
    border: 0;
}

.athlete-message-tabs .nav-link {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    min-height: 42px;
    padding: .45rem .65rem;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: linear-gradient(145deg, #fff 0%, #f8fafc 100%);
    color: #273449;
    font-weight: 600;
    text-align: center;
}

.athlete-message-tabs .nav-link.active {
    border-color: #efd477;
    background: #fff3c9;
    color: #765900;
}

.athlete-message-list {
    display: grid;
    gap: .65rem;
}

.athlete-message-card {
    overflow: hidden;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
}

.athlete-message-card.is-unread {
    border-left: 3px solid #e8c34f;
}

.athlete-message-card > .card-body {
    padding: .65rem .8rem;
}

.athlete-message-summary {
    display: flex;
    align-items: center;
    gap: .65rem;
}

.athlete-message-summary .form-check {
    margin: 0;
    padding-left: 0;
    flex-shrink: 0;
}

.athlete-message-summary .form-check-input {
    margin: 0;
}

.athlete-message-toggle {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 16px;
    align-items: center;
    gap: .65rem;
    flex: 1;
    min-width: 0;
    padding: .35rem 0;
    border: 0;
    background: transparent;
    color: #273449;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.athlete-message-toggle:hover {
    color: #765900;
}

.athlete-message-toggle:focus-visible {
    border-radius: 8px;
    outline: 3px solid rgba(232, 195, 79, .55);
    outline-offset: 3px;
}

.athlete-message-subject {
    overflow-wrap: anywhere;
    font-weight: 700;
}

.athlete-message-summary-text {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: .75rem;
    min-width: 0;
}

.athlete-message-date {
    color: #64748b;
    font-size: .82rem;
    white-space: nowrap;
}

.athlete-message-toggle .fa-chevron-down {
    color: #987500;
    transition: transform 160ms ease;
}

.athlete-message-toggle[aria-expanded="true"] .fa-chevron-down {
    transform: rotate(180deg);
}

.athlete-message-details {
    margin-top: .55rem;
    padding-top: .75rem;
    border-top: 1px solid #e2e8f0;
}

.athlete-message-body {
    color: #334155;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

@media (max-width: 575.98px) {
    .athlete-message-tabs .nav-link {
        gap: .25rem;
        padding: .4rem .25rem;
        font-size: .8rem;
    }

    .athlete-message-summary {
        align-items: flex-start;
    }

    .athlete-message-toggle {
        grid-template-columns: minmax(0, 1fr) 14px;
    }

    .athlete-message-summary-text {
        grid-template-columns: minmax(0, 1fr);
        gap: .1rem;
    }

    .athlete-message-date {
        font-size: .75rem;
        white-space: normal;
    }
}
</style>

<div class="athlete-message-actions mb-3">
    <a href="<?= BASE_URL ?>/athlete_chat_mobile.php" class="athlete-page-action" target="_blank" rel="noopener">
        <i class="fas fa-mobile-screen-button"></i>Mobilní chat
    </a>
    <a href="<?= BASE_URL ?>/athlete_zpravy.php?tab=admin_chat" class="athlete-page-action">
        <i class="fas fa-comments"></i>Napsat administrátorovi
    </a>
    <button type="button" class="athlete-page-action athlete-page-action--primary" data-bs-toggle="modal" data-bs-target="#composeModal">
        <i class="fas fa-pen"></i>Napsat trenérovi
    </button>
</div>
<div class="athlete-message-tip small mb-3">
    <i class="fas fa-circle-info me-1 text-muted"></i>Tip: klikni na <strong>Mobilní chat</strong> – otevře se rychlá stránka s chatem na trenéra i administrátora a přímo na ní najdeš návod, jak si ji uložit na plochu telefonu jako ikonku.
</div>

<!-- Záložky -->
<ul class="nav athlete-message-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'inbox' ? 'active' : '' ?>" href="<?= BASE_URL ?>/athlete_zpravy.php?tab=inbox">
            <i class="fas fa-inbox"></i><span>Přijaté</span>
            <?php if ($unreadCount > 0): ?>
            <span class="badge bg-danger ms-1"><?= $unreadCount ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'sent' ? 'active' : '' ?>" href="<?= BASE_URL ?>/athlete_zpravy.php?tab=sent">
            <i class="fas fa-paper-plane"></i><span>Odeslané</span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'admin_chat' ? 'active' : '' ?>" href="<?= BASE_URL ?>/athlete_zpravy.php?tab=admin_chat">
            <i class="fas fa-comments"></i><span class="d-none d-sm-inline">Administrátor</span><span class="d-sm-none">Admin</span>
            <?php if ($adminChatUnread > 0): ?>
            <span class="badge bg-danger ms-1"><?= $adminChatUnread ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'inbox'): ?>

<?php if (empty($inbox)): ?>
<div class="alert alert-light border"><i class="fas fa-inbox me-2 text-warning"></i>Zatím nemáte žádné přijaté zprávy.</div>
<?php else: ?>
<form method="post" id="bulkMarkReadAthleteForm" class="d-flex align-items-center flex-wrap gap-2 mb-3">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="bulk_mark_read">
    <button type="submit" class="btn athlete-page-action athlete-page-action--primary" id="btnBulkReadAthlete" disabled>
        <i class="fas fa-check-double me-1"></i>Hromadně potvrdit přečtení
    </button>
    <span class="text-muted small">Platí pro vybrané nepřečtené zprávy.</span>
</form>
<section class="card athlete-page-card shadow-sm mb-3">
    <div class="card-header">
        <h2><i class="fas fa-inbox me-2 text-warning"></i>Přijaté zprávy <span class="badge rounded-pill text-bg-warning ms-1"><?= count($inbox) ?></span></h2>
    </div>
    <div class="card-body p-2 p-md-3">
        <div class="form-check d-flex align-items-center gap-2 mb-2 p-0">
            <input type="checkbox" class="form-check-input m-0" id="bulkSelectAllAthlete" title="Vybrat vše pro hromadné potvrzení">
            <label class="small text-muted" for="bulkSelectAllAthlete">Vybrat všechny nepřečtené</label>
        </div>
        <div class="athlete-message-list">
            <?php foreach ($inbox as $m): $isUnread = empty($m['read_at']); $messageId = (int)$m['id']; ?>
            <article class="card athlete-message-card<?= $isUnread ? ' is-unread' : '' ?>">
                <div class="card-body">
                    <div class="athlete-message-summary">
                        <?php if ($isUnread): ?>
                        <div class="form-check">
                            <input type="checkbox"
                                   class="form-check-input bulk-read-athlete"
                                   name="notification_ids[]"
                                   value="<?= $messageId ?>"
                                   form="bulkMarkReadAthleteForm"
                                   aria-label="Vybrat zprávu: <?= h((string)$m['subject']) ?>">
                        </div>
                        <?php endif; ?>
                        <button type="button"
                                class="athlete-message-toggle"
                                data-bs-toggle="collapse"
                                data-bs-target="#inboxMessageDetails<?= $messageId ?>"
                                aria-expanded="false"
                                aria-controls="inboxMessageDetails<?= $messageId ?>">
                            <span class="athlete-message-summary-text">
                                <span class="athlete-message-subject"><?= h((string)($m['subject'] ?: 'Bez předmětu')) ?></span>
                                <span class="athlete-message-date"><?= h(formatDateTime((string)$m['created_at'])) ?></span>
                            </span>
                            <i class="fas fa-chevron-down" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="collapse" id="inboxMessageDetails<?= $messageId ?>">
                        <div class="athlete-message-details">
                            <div class="athlete-message-body"><?= h((string)$m['body']) ?></div>
                            <?php if (!empty($m['attachment_name'])): ?>
                            <button type="button" class="btn athlete-page-action mt-3 message-attachment"
                                    data-attachment-url="<?= h(BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path'])) ?>"
                                    data-attachment-name="<?= h((string)$m['attachment_name']) ?>">
                                <i class="fas fa-paperclip"></i><?= h((string)$m['attachment_name']) ?>
                            </button>
                            <?php endif; ?>
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
                                <?php if ($isUnread): ?>
                                <span class="badge rounded-pill text-bg-warning">Nepřečteno</span>
                                <form method="post" class="m-0">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= $messageId ?>">
                                    <button type="submit" class="btn athlete-page-action athlete-page-action--primary">Označit přečtené</button>
                                </form>
                                <?php else: ?>
                                <span class="badge rounded-pill text-bg-secondary">Přečteno</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php elseif ($tab === 'sent'): ?>

<?php if (empty($sent)): ?>
<div class="alert alert-light border"><i class="fas fa-paper-plane me-2 text-warning"></i>Zatím jste trenérovi žádnou zprávu nepsali.</div>
<?php else: ?>
<section class="card athlete-page-card shadow-sm mb-3">
    <div class="card-header">
        <h2><i class="fas fa-paper-plane me-2 text-warning"></i>Odeslané zprávy <span class="badge rounded-pill text-bg-warning ms-1"><?= count($sent) ?></span></h2>
    </div>
    <div class="card-body p-2 p-md-3">
        <div class="athlete-message-list">
            <?php foreach ($sent as $m): $messageId = (int)$m['id']; ?>
            <article class="card athlete-message-card">
                <div class="card-body">
                    <button type="button"
                            class="athlete-message-toggle"
                            data-bs-toggle="collapse"
                            data-bs-target="#sentMessageDetails<?= $messageId ?>"
                            aria-expanded="false"
                            aria-controls="sentMessageDetails<?= $messageId ?>">
                        <span class="athlete-message-summary-text">
                            <span class="athlete-message-subject"><?= h((string)($m['subject'] ?: 'Bez předmětu')) ?></span>
                            <span class="athlete-message-date"><?= h(formatDateTime((string)$m['sent_at'])) ?></span>
                        </span>
                        <i class="fas fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="collapse" id="sentMessageDetails<?= $messageId ?>">
                        <div class="athlete-message-details">
                            <div class="athlete-message-body"><?= h((string)$m['body']) ?></div>
                            <div class="mt-3">
                                <?php if (!empty($m['coach_read_at'])): ?>
                                <span class="badge rounded-pill text-bg-secondary">Trenér zprávu přečetl</span>
                                <?php else: ?>
                                <span class="badge rounded-pill text-bg-warning">Čeká na přečtení</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php elseif ($tab === 'admin_chat'): ?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <section class="card athlete-page-card shadow-sm">
            <div class="card-header">
                <h2><i class="fas fa-comments me-2 text-warning"></i>Konverzace s administrátorem</h2>
            </div>
            <div class="card-body" style="max-height:60vh; overflow-y:auto" id="adminChatScroll" data-chat-delete-api="<?= h(BASE_URL . '/api/athlete_chat_admin.php?action=delete') ?>" data-chat-delete-csrf="<?= h(csrfToken()) ?>">
                <?php if (empty($adminChatMessages)): ?>
                <div class="text-muted text-center py-4">Zatím žádné zprávy. Napište administrátorovi svůj dotaz.</div>
                <?php else: foreach ($adminChatMessages as $m): $isAthleteMsg = $m['sender'] === 'athlete'; ?>
                <div class="d-flex mb-3 <?= $isAthleteMsg ? 'justify-content-end' : 'justify-content-start' ?>" data-message-id="<?= (int)$m['id'] ?>">
                    <div class="p-2 px-3 rounded-3 <?= $isAthleteMsg ? 'bg-warning' : 'bg-light border' ?>" style="max-width:75%;position:relative;padding-right:2.5rem">
                        <div style="white-space:pre-wrap"><?= h((string)$m['body']) ?></div>
                        <?php if (!empty($m['attachment_name'])): ?>
                        <div class="mt-1">
                            <a class="d-inline-flex align-items-center gap-1 small" href="<?= h(BASE_URL . '/uploads/messages/' . rawurlencode((string)$m['attachment_path'])) ?>" target="_blank" rel="noopener">
                                <i class="fas fa-paperclip"></i><?= h((string)$m['attachment_name']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="small mt-1 text-muted">
                            <?= $isAthleteMsg ? 'Vy' : 'Administrátor' ?> · <?= formatDateTime((string)$m['created_at']) ?>
                        </div>
                        <button type="button" class="chat-message-more" data-chat-message-menu-toggle aria-label="Možnosti zprávy" title="Možnosti zprávy"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></button>
                        <div class="chat-message-menu" data-chat-message-menu hidden>
                            <button type="button" data-chat-delete-scope="me">Odstranit pro mě</button>
                            <?php if ($isAthleteMsg && empty($m['deleted_for_everyone'])): ?><button type="button" data-chat-delete-scope="everyone">Odstranit pro všechny</button><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="card-footer">
                <form method="post" enctype="multipart/form-data" data-chat-composer>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="send_chat_to_admin">
                    <div class="mb-2">
                        <textarea name="chat_body" data-chat-input class="form-control" rows="3" maxlength="4000" placeholder="Napište zprávu administrátorovi..."></textarea>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="submit" data-chat-send class="btn athlete-page-action athlete-page-action--primary"><i class="fas fa-paper-plane"></i>Odeslat</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('adminChatScroll');
    if (box) { box.scrollTop = box.scrollHeight; }
});
</script>

<?php endif; ?>


<div class="modal fade" id="messageAttachmentModal" tabindex="-1" aria-labelledby="messageAttachmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="height:calc(100vh - 2rem)">
        <div class="modal-content h-100 d-flex flex-column overflow-hidden">
            <div class="modal-header">
                <h5 class="modal-title text-truncate" id="messageAttachmentModalLabel">Příloha zprávy</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body p-0 flex-grow-1 overflow-hidden" style="min-height:0">
                <iframe id="messageAttachmentFrame" title="Náhled přílohy zprávy" class="w-100 h-100 border-0 d-block"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- Modal: napsat zprávu trenérovi -->
<div class="modal fade" id="composeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send_message">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-pen me-2 text-warning"></i>Napsat trenérovi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Předmět <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" maxlength="200" required placeholder="Např. Dotaz k tréninku">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Zpráva <span class="text-danger">*</span></label>
                        <textarea name="body" class="form-control" rows="5" required maxlength="4000" placeholder="Napište svou zprávu..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn athlete-page-action" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn athlete-page-action athlete-page-action--primary"><i class="fas fa-paper-plane"></i>Odeslat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const attachmentModalElement = document.getElementById('messageAttachmentModal');
    const attachmentFrame = document.getElementById('messageAttachmentFrame');
    const attachmentTitle = document.getElementById('messageAttachmentModalLabel');

    document.querySelectorAll('.message-attachment').forEach(function (button) {
        button.addEventListener('click', function () {
            attachmentTitle.textContent = button.dataset.attachmentName || 'Příloha zprávy';
            attachmentFrame.src = button.dataset.attachmentUrl;
            bootstrap.Modal.getOrCreateInstance(attachmentModalElement).show();
        });
    });

    attachmentModalElement.addEventListener('hidden.bs.modal', function () {
        attachmentFrame.removeAttribute('src');
    });

    const selectAll = document.getElementById('bulkSelectAllAthlete');
    const submitBtn = document.getElementById('btnBulkReadAthlete');

    if (!selectAll || !submitBtn) {
        return;
    }

    const itemSelector = 'input.bulk-read-athlete';
    const items = Array.from(document.querySelectorAll(itemSelector));

    const refreshBulkState = function () {
        const selectedItems = items.filter(function (item) { return item.checked; });
        submitBtn.disabled = selectedItems.length === 0;
        selectAll.checked = items.length > 0 && selectedItems.length === items.length;
        selectAll.indeterminate = selectedItems.length > 0 && selectedItems.length < items.length;
    };

    selectAll.addEventListener('change', function () {
        items.forEach(function (item) {
            item.checked = selectAll.checked;
        });
        refreshBulkState();
    });

    items.forEach(function (item) {
        item.addEventListener('change', refreshBulkState);
    });

    refreshBulkState();
});
</script>

<?php renderAthleteFooter(); ?>
