<?php

require_once __DIR__ . '/push_install_guide.php';

function renderPushSettings(bool $collapsed = false): void
{
    ?>
    <section class="card border-0 shadow-sm mb-4" id="push-settings" data-csrf="<?= h(csrfToken()) ?>">
        <div class="card-header bg-dark text-white<?= $collapsed ? ' p-0' : '' ?>">
            <?php if ($collapsed): ?>
            <button class="coach-profile-card-toggle btn text-white text-start w-100 fw-bold p-3" type="button" data-bs-toggle="collapse" data-bs-target="#push-settings-content" aria-expanded="false" aria-controls="push-settings-content">
                <span><i class="fas fa-bell me-2"></i>Oznámení</span>
                <i class="fas fa-chevron-down" aria-hidden="true"></i>
            </button>
            <?php else: ?>
            <i class="fas fa-bell me-2"></i>Oznámení
            <?php endif; ?>
        </div>
        <?php if ($collapsed): ?>
        <div id="push-settings-content" class="collapse">
        <?php endif; ?>
        <div class="card-body">
            <p class="text-muted small">Push oznámení můžete spravovat pro každé zařízení zvlášť. Na iPhonu otevřete TrainerApp přidanou na plochu.</p>
            <p class="text-muted small">Jednotlivé typy událostí se postupně zapojují; nastavení kategorie samo o sobě nezapne dosud nepřipojené oznámení.</p>
            <?php renderPushInstallGuide(); ?>
            <p id="push-status" role="status" aria-live="polite">Načítám nastavení oznámení…</p>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button id="push-activate" type="button" class="btn btn-warning fw-semibold" disabled>Aktivovat na tomto zařízení</button>
                <button id="push-disable-all" type="button" class="btn btn-outline-danger" disabled>Deaktivovat na všech zařízeních</button>
            </div>
            <h3 class="h6 fw-bold">Registrovaná zařízení</h3>
            <p class="small text-muted">Odebrání jiného zařízení zastaví doručování na toto zařízení pro váš účet.</p>
            <ul id="push-devices" class="list-group mb-3"></ul>
            <fieldset id="push-categories" disabled>
                <legend class="h6 fw-bold">Kategorie oznámení</legend>
                <?php foreach ([
                    'messages' => 'Zprávy',
                    'calendar' => 'Kalendář',
                    'online_training' => 'Online tréninky',
                    'athlete' => 'Sportovec',
                    'payments' => 'Platby',
                    'surveys' => 'Ankety a dotazníky',
                    'info_channel' => 'Infokanál',
                    'gallery' => 'Galerie',
                ] as $category => $label): ?>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="push-category-<?= h($category) ?>" data-push-category="<?= h($category) ?>">
                        <label class="form-check-label" for="push-category-<?= h($category) ?>"><?= h($label) ?></label>
                    </div>
                <?php endforeach; ?>
            </fieldset>
            <noscript>Pro správu oznámení zapněte JavaScript.</noscript>
        </div>
        <?php if ($collapsed): ?>
        </div>
        <?php endif; ?>
    </section>
    <script src="<?= BASE_URL ?>/assets/js/push-subscriptions.js?v=2" defer></script>
    <script src="<?= BASE_URL ?>/assets/js/push-settings.js?v=1" defer></script>
    <?php
}
