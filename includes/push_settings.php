<?php

require_once __DIR__ . '/push_install_guide.php';

function renderPushSettings(): void
{
    ?>
    <section class="card border-0 shadow-sm mb-4" id="push-settings" data-csrf="<?= h(csrfToken()) ?>">
        <div class="card-header bg-dark text-white">
            <i class="fas fa-bell me-2"></i>Oznámení
        </div>
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
    </section>
    <script src="<?= BASE_URL ?>/assets/js/push-subscriptions.js?v=2" defer></script>
    <script src="<?= BASE_URL ?>/assets/js/push-settings.js?v=1" defer></script>
    <?php
}
