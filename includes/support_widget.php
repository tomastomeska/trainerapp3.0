<?php

function renderSupportWidget(string $userType = 'coach'): void {
    $userType = $userType === 'athlete' ? 'athlete' : 'coach';
    $csrf = csrfToken();
    $apiUrl = BASE_URL . '/api/support_ticket_create.php';
    $supportBankAccount = trim(getAppSetting('support_bank_account', ''));
    $supportBankAccountForQr = accountForSpd($supportBankAccount);

    $supportContributorName = '';
    if ($userType === 'athlete' && function_exists('getCurrentAthlete')) {
        $athlete = getCurrentAthlete();
        if (is_array($athlete)) {
            $supportContributorName = trim((string)($athlete['first_name'] ?? '') . ' ' . (string)($athlete['last_name'] ?? ''));
            if ($supportContributorName === '') {
                $supportContributorName = trim((string)($athlete['email'] ?? ''));
            }
        }
    }
    if ($supportContributorName === '' && function_exists('getCurrentCoach')) {
        $coach = getCurrentCoach();
        if (is_array($coach)) {
            $supportContributorName = trim((string)($coach['name'] ?? ''));
            if ($supportContributorName === '') {
                $supportContributorName = trim((string)($coach['username'] ?? ''));
            }
        }
    }
    if ($supportContributorName === '') {
        $supportContributorName = $userType === 'athlete' ? 'sportovec' : 'trener';
    }
    $supportQrNote = paymentAsciiText('Podpora TrainerApp - ' . $supportContributorName);

    $showAdminChat = false;
    $adminChatUnreadInitial = 0;
    $adminChatApiPath = '/api/athlete_chat_admin.php';
    $showPeerChat = false;
    $peerChatApiPath = '';
    $peerChatLabel = '';
    $peerChatUnreadInitial = 0;

    $ensurePeerChatTable = static function (PDO $pdo): bool {
        try {
            $check = $pdo->query("SHOW TABLES LIKE 'coach_athlete_chat_messages'");
            $found = $check !== false && (bool)$check->fetchColumn();
            if (!$found) {
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
                $found = true;
            }
            return $found;
        } catch (Throwable $e) {
            error_log('renderSupportWidget peer chat table check/create error: ' . $e->getMessage());
            return false;
        }
    };

    if ($userType === 'athlete' && function_exists('athleteIsLoggedIn') && athleteIsLoggedIn()) {
        try {
            $chatAthleteId = (int)getCurrentAthleteId();
            $pdo = getDB();
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'admin_athlete_chat_messages'");
            $tableFound = $tableCheck !== false && (bool)$tableCheck->fetchColumn();
            if (!$tableFound) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `admin_athlete_chat_messages` (
                        `id`              INT AUTO_INCREMENT PRIMARY KEY,
                        `athlete_id`      INT NOT NULL,
                        `sender`          ENUM('admin','athlete') NOT NULL,
                        `body`            TEXT NOT NULL,
                        `attachment_path` VARCHAR(500) NULL,
                        `attachment_name` VARCHAR(255) NULL,
                        `admin_read_at`   DATETIME NULL,
                        `athlete_read_at` DATETIME NULL,
                        `admin_notified_at` DATETIME NULL,
                        `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        KEY `idx_admin_athlete_chat_athlete` (`athlete_id`, `created_at`),
                        CONSTRAINT `fk_admin_athlete_chat_athlete`
                            FOREIGN KEY (`athlete_id`) REFERENCES `athletes`(`id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $tableFound = true;
            }
            if ($tableFound) {
                $showAdminChat = true;
                $adminChatApiPath = '/api/athlete_chat_admin.php';
                $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_athlete_chat_messages WHERE athlete_id = ? AND sender = 'admin' AND athlete_read_at IS NULL");
                $unreadStmt->execute([$chatAthleteId]);
                $adminChatUnreadInitial = (int)$unreadStmt->fetchColumn();
            }

            $athleteRow = getCurrentAthlete();
            $athleteCoachId = (int)($athleteRow['coach_id'] ?? 0);
            if ($athleteCoachId > 0 && $ensurePeerChatTable($pdo)) {
                $coachRowStmt = $pdo->prepare('SELECT name, username FROM coaches WHERE id = ? LIMIT 1');
                $coachRowStmt->execute([$athleteCoachId]);
                $coachRow = $coachRowStmt->fetch();
                if ($coachRow) {
                    $showPeerChat = true;
                    $peerChatApiPath = '/api/athlete_coach_chat.php';
                    $peerChatLabel = ($coachRow['name'] ?? '') !== '' ? (string)$coachRow['name'] : (string)($coachRow['username'] ?? 'Trenér');
                    $peerUnreadStmt = $pdo->prepare("SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE athlete_id = ? AND sender = 'coach' AND athlete_read_at IS NULL");
                    $peerUnreadStmt->execute([$chatAthleteId]);
                    $peerChatUnreadInitial = (int)$peerUnreadStmt->fetchColumn();
                }
            }
        } catch (Throwable $e) {
            error_log('renderSupportWidget athlete chat table check/create error: ' . $e->getMessage());
            $showAdminChat = false;
        }
    } elseif ($userType === 'coach' && function_exists('isLoggedIn') && isLoggedIn()) {
        try {
            $chatCoachId = (int)getCurrentCoachId();
            $pdo = getDB();
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'admin_coach_chat_messages'");
            $tableFound = $tableCheck !== false && (bool)$tableCheck->fetchColumn();
            if (!$tableFound) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `admin_coach_chat_messages` (
                        `id`              INT AUTO_INCREMENT PRIMARY KEY,
                        `coach_id`        INT NOT NULL,
                        `sender`          ENUM('admin','coach') NOT NULL,
                        `body`            TEXT NOT NULL,
                        `attachment_path` VARCHAR(500) NULL,
                        `attachment_name` VARCHAR(255) NULL,
                        `admin_read_at`   DATETIME NULL,
                        `coach_read_at`   DATETIME NULL,
                        `admin_notified_at` DATETIME NULL,
                        `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        KEY `idx_admin_coach_chat_coach` (`coach_id`, `created_at`),
                        CONSTRAINT `fk_admin_coach_chat_coach`
                            FOREIGN KEY (`coach_id`) REFERENCES `coaches`(`id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                $tableFound = true;
            }
            if ($tableFound) {
                $showAdminChat = true;
                $adminChatApiPath = '/api/coach_chat_admin.php';
                $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_coach_chat_messages WHERE coach_id = ? AND sender = 'admin' AND coach_read_at IS NULL");
                $unreadStmt->execute([$chatCoachId]);
                $adminChatUnreadInitial = (int)$unreadStmt->fetchColumn();
            }

            if ($ensurePeerChatTable($pdo)) {
                $showPeerChat = true;
                $peerChatApiPath = '/api/coach_athlete_chat.php';
                $peerChatLabel = 'Sportovci';
                $peerUnreadStmt = $pdo->prepare("SELECT COUNT(*) FROM coach_athlete_chat_messages WHERE coach_id = ? AND sender = 'athlete' AND coach_read_at IS NULL");
                $peerUnreadStmt->execute([$chatCoachId]);
                $peerChatUnreadInitial = (int)$peerUnreadStmt->fetchColumn();
            }
        } catch (Throwable $e) {
            error_log('renderSupportWidget coach chat table check/create error: ' . $e->getMessage());
            $showAdminChat = false;
        }
    }

    $showChatWidget = $showAdminChat || $showPeerChat;
    $chatWidgetUnreadInitial = $adminChatUnreadInitial + $peerChatUnreadInitial;
    ?>
<div id="supportWidgetRoot">
    <div class="support-fab-stack">
        <?php if ($showChatWidget): ?>
        <button type="button" class="support-fab support-fab-chat" id="adminChatFab" title="Zprávy">
            <i class="fas fa-comment-dots"></i>
            <span class="support-fab-badge<?= $chatWidgetUnreadInitial > 0 ? '' : ' d-none' ?>" id="adminChatBadge"><?= $chatWidgetUnreadInitial ?></span>
        </button>
        <?php endif; ?>
        <button type="button" class="support-fab support-fab-gift" id="supportGiftFab" title="Dobrovolná podpora provozu">
            <span aria-hidden="true">🎁</span>
        </button>
        <button type="button" class="support-fab support-fab-help" id="supportFab" title="Nahlásit problém">
            <i class="fas fa-question"></i>
        </button>
    </div>

    <?php if ($showChatWidget): ?>
    <div class="admin-chat-panel d-none" id="adminChatPanel">
        <div class="admin-chat-panel-header">
            <button type="button" class="btn-link text-white d-none" id="adminChatBackBtn" aria-label="Zpět" style="border:none;background:none;padding:0 8px 0 0;font-size:16px"><i class="fas fa-arrow-left"></i></button>
            <span id="adminChatPanelTitle"><i class="fas fa-comment-dots me-2"></i>Zprávy</span>
            <button type="button" class="btn-close btn-close-white" id="adminChatCloseBtn" aria-label="Zavřít"></button>
        </div>
        <div class="admin-chat-panel-body" id="adminChatHub">
            <div class="text-muted text-center small py-3">Načítám…</div>
        </div>
        <div class="admin-chat-panel-body d-none" id="adminChatBody"></div>
        <form class="admin-chat-panel-footer d-none" id="adminChatForm">
            <input type="text" class="form-control" id="adminChatInput" maxlength="4000" placeholder="Napište zprávu…" autocomplete="off" required>
            <button type="submit" class="btn btn-primary" id="adminChatSendBtn"><i class="fas fa-paper-plane"></i></button>
        </form>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="supportContributionGlobalModal" tabindex="-1" aria-labelledby="supportContributionGlobalModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="supportContributionGlobalModalLabel"><i class="fas fa-heart me-2 text-warning"></i>Dobrovolná podpora provozu</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">Jde jen o volitelnou podporu provozu aplikace. Aplikace zůstává zdarma a nic není potřeba platit.</p>
                    <?php if ($supportBankAccountForQr === null): ?>
                    <div class="alert alert-warning mb-3">Pro tento účet zatím není v administraci nastavené číslo účtu.</div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label for="supportContributionGlobalAmount" class="form-label fw-semibold">Částka</label>
                        <input type="number" min="1" step="1" class="form-control form-control-lg" id="supportContributionGlobalAmount" placeholder="Např. 100">
                    </div>
                    <div class="border rounded-3 p-3 bg-light mb-3">
                        <img id="supportContributionGlobalQrImage" src="" alt="QR kód pro příspěvek" class="img-fluid border rounded p-2 bg-white d-none" style="max-width:220px;">
                        <div id="supportContributionGlobalQrEmpty" class="text-muted small">Zadejte částku a QR kód se zobrazí automaticky.</div>
                    </div>
                    <div class="small"><strong>Účet:</strong> <span><?= h($supportBankAccount) ?></span></div>
                    <div class="small"><strong>Odesílatel:</strong> <span><?= h($supportContributorName) ?></span></div>
                    <div class="small"><strong>Poznámka:</strong> <span><?= h($supportQrNote) ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer justify-content-between flex-wrap gap-2">
                    <div class="small text-muted">Aplikace zůstává bezplatná. Příspěvek je pouze dobrovolná pomoc s provozem.</div>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zavřít</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="supportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-life-ring me-2 text-primary"></i>Kontaktovat podporu
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
                </div>
                <form id="supportForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="alert d-none" id="supportAlert" role="alert"></div>

                        <div class="mb-3">
                            <label for="supportSubject" class="form-label fw-semibold">Předmět</label>
                            <input type="text" class="form-control" id="supportSubject" name="subject" maxlength="255" required>
                        </div>

                        <div class="mb-3">
                            <label for="supportIssueType" class="form-label fw-semibold">O jaký problém jde?</label>
                            <select class="form-select" id="supportIssueType" name="issue_type" required>
                                <option value="" selected disabled>Vyberte typ problému</option>
                                <option value="Technický problém">Technický problém</option>
                                <option value="Nejasné chování aplikace">Nejasné chování aplikace</option>
                                <option value="Chyba v datech">Chyba v datech</option>
                                <option value="Platby">Platby</option>
                                <option value="Jiné">Jiné</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="supportDescription" class="form-label fw-semibold">Popis problému</label>
                            <textarea class="form-control" id="supportDescription" name="description" rows="5" maxlength="5000" required></textarea>
                        </div>

                        <div>
                            <label for="supportScreenshot" class="form-label fw-semibold">Screenshot (volitelné)</label>
                            <input type="file" class="form-control" id="supportScreenshot" name="screenshot" accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="form-text">Maximální velikost je 8 MB.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                        <button type="submit" class="btn btn-primary" id="supportSubmitBtn">
                            <i class="fas fa-paper-plane me-1"></i>Odeslat
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.support-fab-stack {
    position: fixed;
    right: 18px;
    bottom: 18px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    z-index: 1080;
}
.support-fab {
    width: 54px;
    height: 54px;
    border: none;
    border-radius: 50%;
    color: #fff;
    font-size: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform .15s ease, box-shadow .2s ease;
}
.support-fab-help {
    background: linear-gradient(135deg, #0d6efd, #0b5ed7);
    box-shadow: 0 10px 24px rgba(13, 110, 253, 0.35);
}
.support-fab-gift {
    background: linear-gradient(135deg, #fecaca, #fda4af);
    color: #7f1d1d;
    box-shadow: 0 10px 24px rgba(244, 114, 182, 0.28);
    font-size: 24px;
}
.support-fab:hover {
    transform: translateY(-2px);
}
.support-fab-help:hover {
    box-shadow: 0 12px 28px rgba(13, 110, 253, 0.4);
}
.support-fab-gift:hover {
    box-shadow: 0 12px 28px rgba(244, 114, 182, 0.36);
}
.support-fab:active {
    transform: translateY(0);
}
@media (max-width: 768px) {
    .support-fab-stack {
        right: 12px;
        bottom: 12px;
        gap: 8px;
    }
    .support-fab {
        width: 50px;
        height: 50px;
        font-size: 20px;
    }
    .support-fab-gift {
        font-size: 22px;
    }
}
.support-fab-chat {
    background: linear-gradient(135deg, #25d366, #128c7e);
    box-shadow: 0 10px 24px rgba(37, 211, 102, 0.35);
    position: relative;
}
.support-fab-chat:hover {
    box-shadow: 0 12px 28px rgba(37, 211, 102, 0.42);
}
.support-fab-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border-radius: 10px;
    background: #dc3545;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}
.admin-chat-panel {
    position: fixed;
    right: 18px;
    bottom: 90px;
    width: 330px;
    max-width: calc(100vw - 24px);
    height: 440px;
    max-height: calc(100vh - 120px);
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 16px 40px rgba(0,0,0,0.25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 1085;
}
.admin-chat-panel-header {
    background: linear-gradient(135deg, #128c7e, #075e54);
    color: #fff;
    padding: 12px 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
}
.admin-chat-panel-header #adminChatPanelTitle {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.admin-chat-panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    background: #ece5dd;
}
#adminChatHub {
    padding: 0;
    background: #fff;
}
.admin-chat-hub-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 12px 14px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    color: #111;
    background: #fff;
}
.admin-chat-hub-item:hover {
    background: #f7f7f7;
}
.admin-chat-hub-item .name {
    font-weight: 600;
    font-size: 14px;
}
.admin-chat-hub-item .name i {
    margin-right: 6px;
    color: #128c7e;
}
.admin-chat-hub-item .preview {
    font-size: 12px;
    color: #667781;
    margin-top: 2px;
    max-width: 190px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.admin-chat-hub-badge {
    display: inline-block;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    background: #dc3545;
    color: #fff;
    font-size: 10.5px;
    font-weight: 700;
    line-height: 18px;
    text-align: center;
}
.admin-chat-bubble-row {
    display: flex;
    margin-bottom: 8px;
}
.admin-chat-bubble-row.from-athlete {
    justify-content: flex-end;
}
.admin-chat-bubble {
    max-width: 78%;
    padding: 8px 11px;
    border-radius: 10px;
    font-size: 14px;
    line-height: 1.4;
    white-space: pre-wrap;
    word-wrap: break-word;
    box-shadow: 0 1px 1px rgba(0,0,0,0.08);
}
.admin-chat-bubble-row.from-admin .admin-chat-bubble {
    background: #fff;
}
.admin-chat-bubble-row.from-athlete .admin-chat-bubble {
    background: #dcf8c6;
}
.admin-chat-bubble-time {
    display: block;
    margin-top: 3px;
    font-size: 10.5px;
    color: #667781;
    text-align: right;
}
.admin-chat-read-status {
    margin-left: 4px;
    font-weight: 700;
    letter-spacing: -1px;
}
.admin-chat-read-status.is-read {
    color: #128c7e;
}
.admin-chat-panel-footer {
    display: flex;
    gap: 8px;
    padding: 10px;
    background: #f0f0f0;
    border-top: 1px solid #ddd;
}
@media (max-width: 768px) {
    .admin-chat-panel {
        right: 12px;
        bottom: 82px;
        width: calc(100vw - 24px);
    }
}
</style>

<script>
(function () {
    const supportFab = document.getElementById('supportFab');
    const supportGiftFab = document.getElementById('supportGiftFab');
    const supportModalEl = document.getElementById('supportModal');
    const supportContributionGlobalModalEl = document.getElementById('supportContributionGlobalModal');
    const supportForm = document.getElementById('supportForm');
    const supportAlert = document.getElementById('supportAlert');
    const supportSubmitBtn = document.getElementById('supportSubmitBtn');

    if (!supportFab || !supportGiftFab || !supportModalEl || !supportContributionGlobalModalEl || !supportForm || !supportAlert || !supportSubmitBtn) {
        return;
    }

    const supportModal = new bootstrap.Modal(supportModalEl);
    const supportContributionGlobalModal = new bootstrap.Modal(supportContributionGlobalModalEl);

    const supportBankAccount = <?= json_encode($supportBankAccountForQr, JSON_UNESCAPED_UNICODE) ?>;
    const supportQrNote = <?= json_encode($supportQrNote, JSON_UNESCAPED_UNICODE) ?>;
    const contributionAmountInput = document.getElementById('supportContributionGlobalAmount');
    const contributionQrImage = document.getElementById('supportContributionGlobalQrImage');
    const contributionQrEmpty = document.getElementById('supportContributionGlobalQrEmpty');

    supportFab.addEventListener('click', function () {
        supportAlert.className = 'alert d-none';
        supportAlert.textContent = '';
        supportModal.show();
    });

    supportGiftFab.addEventListener('click', function () {
        supportContributionGlobalModal.show();
    });

    if (contributionAmountInput && contributionQrImage && contributionQrEmpty && supportBankAccount !== null) {
        const buildQrUrl = (amount) => {
            const spd = [
                'SPD*1.0',
                'ACC:' + supportBankAccount,
                'CC:CZK',
                'AM:' + amount.toFixed(2),
                'MSG:' + supportQrNote,
            ].join('*');

            return 'https://quickchart.io/qr?size=220&text=' + encodeURIComponent(spd);
        };

        const updateContributionQr = () => {
            const amount = parseFloat(String(contributionAmountInput.value || '').replace(',', '.'));
            if (!Number.isFinite(amount) || amount <= 0) {
                contributionQrImage.classList.add('d-none');
                contributionQrEmpty.classList.remove('d-none');
                contributionQrImage.removeAttribute('src');
                return;
            }

            contributionQrImage.src = buildQrUrl(amount);
            contributionQrImage.classList.remove('d-none');
            contributionQrEmpty.classList.add('d-none');
        };

        contributionAmountInput.addEventListener('input', updateContributionQr);
        contributionAmountInput.addEventListener('change', updateContributionQr);
    }

    supportForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        supportSubmitBtn.disabled = true;
        const originalHtml = supportSubmitBtn.innerHTML;
        supportSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Odesílám...';

        const formData = new FormData(supportForm);
        formData.append('csrf_token', <?= json_encode($csrf) ?>);
        formData.append('page_url', window.location.href);
        formData.append('portal', <?= json_encode($userType) ?>);

        try {
            const response = await fetch(<?= json_encode($apiUrl) ?>, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const data = await response.json().catch(() => ({ ok: false, error: 'Neočekávaná odpověď serveru.' }));

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Odeslání ticketu se nepodařilo.');
            }

            supportAlert.className = 'alert alert-success';
            supportAlert.textContent = 'Děkujeme, požadavek na podporu byl odeslán.';
            supportForm.reset();

            setTimeout(function () {
                supportModal.hide();
            }, 1200);
        } catch (err) {
            supportAlert.className = 'alert alert-danger';
            supportAlert.textContent = err && err.message ? err.message : 'Odeslání ticketu se nepodařilo.';
        } finally {
            supportSubmitBtn.disabled = false;
            supportSubmitBtn.innerHTML = originalHtml;
        }
    });
})();

(function () {
    const chatFab = document.getElementById('adminChatFab');
    const chatPanel = document.getElementById('adminChatPanel');
    const chatBadge = document.getElementById('adminChatBadge');
    const chatHub = document.getElementById('adminChatHub');
    const chatBody = document.getElementById('adminChatBody');
    const chatForm = document.getElementById('adminChatForm');
    const chatInput = document.getElementById('adminChatInput');
    const chatCloseBtn = document.getElementById('adminChatCloseBtn');
    const chatBackBtn = document.getElementById('adminChatBackBtn');
    const chatTitle = document.getElementById('adminChatPanelTitle');

    if (!chatFab || !chatPanel || !chatHub || !chatBody || !chatForm || !chatInput || !chatCloseBtn) {
        return;
    }

    const csrfToken = <?= json_encode($csrf) ?>;
    const showAdminChat = <?= json_encode($showAdminChat) ?>;
    const showPeerChat = <?= json_encode($showPeerChat) ?>;
    const peerChatLabel = <?= json_encode($peerChatLabel) ?>;
    const isCoach = <?= json_encode($userType === 'coach') ?>;
    const adminApiUrl = <?= json_encode(BASE_URL . $adminChatApiPath) ?>;
    const peerApiUrl = <?= json_encode(BASE_URL . $peerChatApiPath) ?>;

    let isOpen = false;
    let view = 'hub'; // 'hub' | 'thread'
    let currentThread = null; // { apiUrl, extraQuery, label }
    let lastId = 0;
    let fastPollTimer = null;
    let slowPollTimer = null;
    const unreadByKey = {};

    const escapeHtml = (str) => String(str).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));

    const totalUnread = () => Object.values(unreadByKey).reduce((sum, n) => sum + (n || 0), 0);

    const updateBadge = () => {
        const count = totalUnread();
        if (count > 0) {
            chatBadge.textContent = count > 99 ? '99+' : String(count);
            chatBadge.classList.remove('d-none');
        } else {
            chatBadge.classList.add('d-none');
        }
    };

    const messageIsMine = (m) => {
        if (!currentThread) { return false; }
        if (currentThread.key === 'admin') { return m.sender !== 'admin'; }
        return m.sender === (isCoach ? 'coach' : 'athlete');
    };

    const renderMessage = (m) => {
        const row = document.createElement('div');
        const isMine = messageIsMine(m);
        row.className = 'admin-chat-bubble-row from-' + (isMine ? 'athlete' : 'admin');
        let html = '<div class="admin-chat-bubble">' + escapeHtml(m.body).replace(/\n/g, '<br>');
        if (m.attachment_url) {
            html += '<div class="mt-1"><a href="' + m.attachment_url + '" target="_blank" rel="noopener">'
                + '<i class="fas fa-paperclip"></i> ' + escapeHtml(m.attachment_name || 'Příloha') + '</a></div>';
        }
        html += '<span class="admin-chat-bubble-time">' + escapeHtml(m.created_at)
            + (isMine ? '<span class="admin-chat-read-status' + (m.read_at ? ' is-read' : '') + '" title="' + (m.read_at ? 'Přečteno' : 'Odesláno') + '">' + (m.read_at ? '✓✓' : '✓') + '</span>' : '')
            + '</span></div>';
        row.innerHTML = html;
        chatBody.appendChild(row);
        if (Number(m.id) > lastId) {
            lastId = Number(m.id);
        }
    };

    const scrollToBottom = () => {
        chatBody.scrollTop = chatBody.scrollHeight;
    };

    const showHub = () => {
        view = 'hub';
        currentThread = null;
        if (fastPollTimer) { clearInterval(fastPollTimer); fastPollTimer = null; }
        chatTitle.innerHTML = '<i class="fas fa-comment-dots me-2"></i>Zprávy';
        chatBackBtn.classList.add('d-none');
        chatHub.classList.remove('d-none');
        chatBody.classList.add('d-none');
        chatForm.classList.add('d-none');
        loadHub();
    };

    const openThread = (key, apiUrl, extraQuery, label, iconClass) => {
        view = 'thread';
        currentThread = { key, apiUrl, extraQuery: extraQuery || '', label };
        lastId = 0;
        chatTitle.innerHTML = '<i class="' + (iconClass || 'fas fa-comment-dots') + ' me-2"></i>' + escapeHtml(label);
        chatBackBtn.classList.remove('d-none');
        chatHub.classList.add('d-none');
        chatBody.classList.remove('d-none');
        chatForm.classList.remove('d-none');
        loadThread();
    };

    const hubItemHtml = (key, name, iconClass, unread, preview) => {
        return '<div class="admin-chat-hub-item" data-key="' + key + '">'
            + '<div><div class="name"><i class="' + iconClass + '"></i>' + escapeHtml(name) + '</div>'
            + (preview ? '<div class="preview">' + escapeHtml(preview) + '</div>' : '')
            + '</div>'
            + (unread > 0 ? '<span class="admin-chat-hub-badge">' + unread + '</span>' : '')
            + '</div>';
    };

    const bindHubClicks = () => {
        chatHub.querySelectorAll('.admin-chat-hub-item').forEach((el) => {
            el.addEventListener('click', () => {
                const key = el.getAttribute('data-key');
                if (key === 'admin') {
                    openThread('admin', adminApiUrl, '', 'Administrátor', 'fas fa-user-shield');
                } else if (key === 'peer') {
                    openThread('peer', peerApiUrl, '', peerChatLabel, 'fas fa-user-tie');
                } else if (key.indexOf('athlete:') === 0) {
                    const athleteId = key.split(':')[1];
                    const name = el.getAttribute('data-name') || 'Sportovec';
                    openThread('athlete:' + athleteId, peerApiUrl, '&athlete_id=' + athleteId, name, 'fas fa-person-running');
                }
            });
        });
    };

    const loadHub = async () => {
        chatHub.innerHTML = '<div class="text-muted text-center small py-3">Načítám…</div>';
        const items = [];

        if (showAdminChat) {
            try {
                const res = await fetch(adminApiUrl + '?action=unread_count', { credentials: 'same-origin' });
                const data = await res.json();
                unreadByKey.admin = (data && data.ok) ? (data.unread_count || 0) : 0;
            } catch (e) { unreadByKey.admin = unreadByKey.admin || 0; }
            items.push({ html: hubItemHtml('admin', 'Administrátor', 'fas fa-user-shield', unreadByKey.admin, null) });
        }

        if (showPeerChat && !isCoach) {
            try {
                const res = await fetch(peerApiUrl + '?action=unread_count', { credentials: 'same-origin' });
                const data = await res.json();
                unreadByKey.peer = (data && data.ok) ? (data.unread_count || 0) : 0;
            } catch (e) { unreadByKey.peer = unreadByKey.peer || 0; }
            items.push({ html: hubItemHtml('peer', peerChatLabel, 'fas fa-user-tie', unreadByKey.peer, null) });
        }

        if (showPeerChat && isCoach) {
            try {
                const res = await fetch(peerApiUrl + '?action=list', { credentials: 'same-origin' });
                const data = await res.json();
                if (data && data.ok) {
                    let athleteTotal = 0;
                    data.athletes.forEach((a) => {
                        athleteTotal += a.unread_count || 0;
                        items.push({
                            html: hubItemHtml('athlete:' + a.id, a.name, 'fas fa-person-running', a.unread_count, a.last_body),
                            name: a.name,
                            key: 'athlete:' + a.id
                        });
                    });
                    unreadByKey.peer = athleteTotal;
                }
            } catch (e) { /* tichy fail */ }
        }

        updateBadge();

        if (items.length === 0) {
            chatHub.innerHTML = '<div class="text-muted text-center small py-3">Zatím nejsou dostupné žádné konverzace.</div>';
            return;
        }

        chatHub.innerHTML = items.map((it) => it.html).join('');
        items.forEach((it) => {
            if (it.key) {
                const el = chatHub.querySelector('.admin-chat-hub-item[data-key="' + it.key + '"]');
                if (el) { el.setAttribute('data-name', it.name); }
            }
        });
        bindHubClicks();
    };

    const pollUnreadForBadgeOnly = () => {
        if (showAdminChat) {
            fetch(adminApiUrl + '?action=unread_count', { credentials: 'same-origin' })
                .then((r) => r.json())
                .then((data) => { if (data && data.ok) { unreadByKey.admin = data.unread_count || 0; updateBadge(); } })
                .catch(() => {});
        }
        if (showPeerChat) {
            fetch(peerApiUrl + '?action=unread_count', { credentials: 'same-origin' })
                .then((r) => r.json())
                .then((data) => { if (data && data.ok) { unreadByKey.peer = data.unread_count || 0; updateBadge(); } })
                .catch(() => {});
        }
    };

    const pollNewMessages = async () => {
        if (!currentThread) { return; }
        try {
            const res = await fetch(currentThread.apiUrl + '?action=poll&since_id=' + lastId + currentThread.extraQuery, { credentials: 'same-origin' });
            const data = await res.json();
            if (data && data.ok && data.messages.length > 0) {
                data.messages.forEach((m) => renderMessage(m));
                scrollToBottom();
            }
        } catch (e) { /* tichy fail */ }
    };

    const loadThread = async () => {
        if (!currentThread) { return; }
        chatBody.innerHTML = '<div class="text-muted text-center small py-3">Načítám zprávy…</div>';
        try {
            const res = await fetch(currentThread.apiUrl + '?action=thread' + currentThread.extraQuery, { credentials: 'same-origin' });
            const data = await res.json();
            chatBody.innerHTML = '';
            if (data && data.ok) {
                if (data.messages.length === 0) {
                    chatBody.innerHTML = '<div class="text-muted text-center small py-3">Zatím žádné zprávy. Napište první zprávu.</div>';
                } else {
                    data.messages.forEach((m) => renderMessage(m));
                }
                scrollToBottom();
            }
            if (fastPollTimer) { clearInterval(fastPollTimer); }
            fastPollTimer = setInterval(pollNewMessages, 5000);
            chatInput.focus();
        } catch (e) {
            chatBody.innerHTML = '<div class="text-danger text-center small py-3">Zprávy se nepodařilo načíst.</div>';
        }
    };

    const openPanel = () => {
        isOpen = true;
        chatPanel.classList.remove('d-none');
        if (slowPollTimer) { clearInterval(slowPollTimer); slowPollTimer = null; }
        showHub();
    };

    const closePanel = () => {
        isOpen = false;
        chatPanel.classList.add('d-none');
        if (fastPollTimer) { clearInterval(fastPollTimer); fastPollTimer = null; }
        slowPollTimer = setInterval(pollUnreadForBadgeOnly, 20000);
    };

    chatFab.addEventListener('click', function () {
        if (isOpen) {
            closePanel();
        } else {
            openPanel();
        }
    });

    chatCloseBtn.addEventListener('click', closePanel);
    chatBackBtn.addEventListener('click', showHub);

    chatForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        const body = chatInput.value.trim();
        if (body === '' || !currentThread) {
            return;
        }

        const sendBtn = document.getElementById('adminChatSendBtn');
        sendBtn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('action', 'send');
            formData.append('body', body);
            formData.append('csrf_token', csrfToken);
            if (currentThread.key.indexOf('athlete:') === 0) {
                formData.append('athlete_id', currentThread.key.split(':')[1]);
            }

            const res = await fetch(currentThread.apiUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
            const data = await res.json();
            if (data && data.ok) {
                chatInput.value = '';
                await pollNewMessages();
            }
        } catch (e) { /* tichy fail, uzivatel muze zkusit odeslat znovu */ } finally {
            sendBtn.disabled = false;
            chatInput.focus();
        }
    });

    pollUnreadForBadgeOnly();
    slowPollTimer = setInterval(pollUnreadForBadgeOnly, 20000);
})();
</script>
<?php
}
