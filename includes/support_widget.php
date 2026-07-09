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
    ?>
<div id="supportWidgetRoot">
    <div class="support-fab-stack">
        <button type="button" class="support-fab support-fab-gift" id="supportGiftFab" title="Dobrovolná podpora provozu">
            <span aria-hidden="true">🎁</span>
        </button>
        <button type="button" class="support-fab support-fab-help" id="supportFab" title="Nahlásit problém">
            <i class="fas fa-question"></i>
        </button>
    </div>

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
</script>
<?php
}
