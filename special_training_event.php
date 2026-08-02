<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

if (!function_exists('coachSpecialTrainingUnlocked')) {
    function coachSpecialTrainingUnlocked(PDO $pdo, int $coachId): bool {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'special_training_enabled'");
            if ($columnStmt === false || !$columnStmt->fetch()) {
                return false;
            }

            $valueStmt = $pdo->prepare('SELECT special_training_enabled FROM coaches WHERE id = ? LIMIT 1');
            $valueStmt->execute([$coachId]);
            return ((int)$valueStmt->fetchColumn()) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }
}

$pdo = getDB();
$coachId = (int)getCurrentCoachId();
if (!coachSpecialTrainingUnlocked($pdo, $coachId)) {
    flash('warning', 'Events jsou pro váš účet zatím uzamčené.');
    redirect(BASE_URL . '/dashboard.php');
}

$slug = strtolower(trim((string)($_GET['event'] ?? '')));
$slug = preg_replace('/[^a-z0-9-]/', '', $slug) ?? '';
if ($slug === '') {
    flash('warning', 'Event nebyl vybrán.');
    redirect(BASE_URL . '/special_training.php');
}

$event = loadSpecialEventBySlug($pdo, $slug, 'coach');
if (!$event) {
    flash('warning', 'Požadovaný event nebyl nalezen nebo ještě není aktivní.');
    redirect(BASE_URL . '/special_training.php');
}

$tabs = is_array($event['tabs'] ?? null) ? $event['tabs'] : [];
$upcomingItems = is_array($event['upcoming_items'] ?? null) ? $event['upcoming_items'] : [];
$defaultTabId = !empty($tabs) ? ('tab-' . (int)$tabs[0]['id']) : '';
$eventName = (string)($event['name'] ?? 'Event');
$eventIcon = (string)($event['icon_class'] ?? 'fa-bolt');
$eventBadge = trim((string)($event['badge_label'] ?? ''));
$eventDescription = trim((string)($event['description'] ?? ''));
if (!preg_match('/^fa-[a-z0-9-]+$/', $eventIcon)) {
    $eventIcon = 'fa-bolt';
}

$upcomingByTab = [];
foreach ($upcomingItems as $upcomingItem) {
    $tabId = (int)($upcomingItem['tab_id'] ?? 0);
    if ($tabId <= 0) {
        continue;
    }

    if (!isset($upcomingByTab[$tabId])) {
        $upcomingByTab[$tabId] = [];
    }
    $upcomingByTab[$tabId][] = $upcomingItem;
}

$eventFormSubmitUrl = BASE_URL . '/event_form_submit.php';
$eventFormCsrfToken = csrfToken();

renderHeader('Events - ' . $eventName, false, true);
?>

<style>
    .event-shell {
        border: 1px solid #e3e8ef;
        border-radius: 1rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }

    .event-shell .nav-tabs {
        border-bottom: 1px solid #e3e8ef;
    }

    .event-shell .nav-link {
        font-weight: 600;
        color: #46556a;
        border: none;
        border-bottom: 3px solid transparent;
        border-radius: 0;
        background: transparent;
    }

    .event-shell .nav-link.active {
        color: #0d6efd;
        border-bottom-color: #0d6efd;
        background: transparent;
    }

    .event-pane {
        border: 1px solid #e1e8f0;
        background: #ffffff;
        border-radius: 0.9rem;
        padding: 1rem;
        line-height: 1.75 !important;
        font-size: 1.02rem;
    }

    .event-pane h1,
    .event-pane h2,
    .event-pane h3,
    .event-pane h4,
    .event-pane h5,
    .event-pane h6 {
        margin-top: 1.35rem !important;
        margin-bottom: .95rem !important;
        font-weight: 700;
    }

    .event-pane > :first-child {
        margin-top: 0 !important;
    }

    .event-pane p,
    .event-pane ul,
    .event-pane ol,
    .event-pane blockquote,
    .event-pane pre,
    .event-pane table,
    .event-pane form {
        margin-top: 0 !important;
        margin-bottom: 1.12rem !important;
    }

    .event-pane ul,
    .event-pane ol {
        padding-left: 1.35rem;
    }

    .event-pane li {
        margin-bottom: .72rem !important;
    }

    .event-pane li:last-child {
        margin-bottom: 0;
    }

    .event-pane table {
        width: 100%;
    }

    .event-pane table th,
    .event-pane table td {
        border: 1px solid #e4e9f1;
        padding: .5rem .6rem;
        vertical-align: top;
    }

    .event-pane table th {
        background: #f6f9fc;
    }

    .special-event-upcoming-item {
        border-color: #2b2b2b !important;
        background: linear-gradient(145deg, #111111 0%, #1a1a1a 100%);
        color: #f7f7f7;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    }

    .special-event-upcoming-title {
        color: #ff9f1a;
    }

    .special-event-upcoming-date {
        color: #ffd28a;
    }

    .special-event-upcoming-status {
        font-weight: 700;
    }

    .special-event-upcoming-status--future {
        background: #ff8c00;
        color: #111111;
    }

    .special-event-upcoming-status--running {
        background: #ff4d00;
        color: #ffffff;
    }

    .special-event-upcoming-status--past {
        background: #343a40;
        color: #ffffff;
    }

    .special-event-upcoming-link {
        border: 1px solid #ff9f1a;
        color: #ffb347;
    }

    .special-event-upcoming-link:hover {
        background: #ff9f1a;
        color: #111111;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0">
        <i class="fas <?= h($eventIcon) ?> me-2 text-warning"></i><?= h($eventName) ?>
        <?php if ($eventBadge !== ''): ?>
        <span class="badge rounded-pill text-bg-secondary align-middle ms-1"><?= h($eventBadge) ?></span>
        <?php endif; ?>
    </h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/special_training.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Zpět na eventy
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm event-shell">
    <?php if (!empty($tabs)): ?>
    <div class="card-header bg-white border-0 pt-3 pb-0">
        <ul class="nav nav-tabs card-header-tabs" id="eventTabs" role="tablist">
            <?php foreach ($tabs as $index => $tab): ?>
            <?php
                $tabDomId = 'tab-' . (int)$tab['id'];
                $isActive = ($index === 0);
            ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $isActive ? ' active' : '' ?>" id="<?= h($tabDomId) ?>-tab" data-bs-toggle="tab" data-bs-target="#<?= h($tabDomId) ?>" type="button" role="tab" aria-controls="<?= h($tabDomId) ?>" aria-selected="<?= $isActive ? 'true' : 'false' ?>">
                    <?= h((string)$tab['title']) ?>
                </button>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="card-body tab-content p-4" id="eventTabsContent">
        <?php if ($eventDescription !== ''): ?>
        <div class="alert alert-light border mb-4"><?= h($eventDescription) ?></div>
        <?php endif; ?>

        <?php if (empty($tabs)): ?>
        <div class="alert alert-warning mb-0">Tento event zatím nemá publikované žádné záložky.</div>
        <?php else: ?>
            <?php foreach ($tabs as $index => $tab): ?>
            <?php
                $tabDomId = 'tab-' . (int)$tab['id'];
                $isActive = ($index === 0);
                $html = trim((string)($tab['content_html'] ?? ''));
                $tabUpcomingItems = $upcomingByTab[(int)$tab['id']] ?? [];
            ?>
            <div class="tab-pane fade<?= $isActive ? ' show active' : '' ?>" id="<?= h($tabDomId) ?>" role="tabpanel" aria-labelledby="<?= h($tabDomId) ?>-tab">
                <div class="event-pane">
                    <?php if (!empty($tabUpcomingItems)): ?>
                        <?= renderSpecialEventUpcomingItems($tabUpcomingItems) ?>
                    <?php elseif ($html !== ''): ?>
                        <?= sanitizeSpecialEventHtml($html) ?>
                    <?php else: ?>
                        <p class="text-muted mb-0">Obsah této záložky zatím není vyplněn.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const submitUrl = <?= json_encode($eventFormSubmitUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const csrfToken = <?= json_encode($eventFormCsrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const eventSlug = <?= json_encode($slug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const eventName = <?= json_encode($eventName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const returnUrl = window.location.pathname + window.location.search;

    document.querySelectorAll('.event-pane form').forEach(function (form) {
        const action = (form.getAttribute('action') || '').trim();
        if (action === '' || action === '#') {
            form.setAttribute('action', submitUrl);
        }

        form.setAttribute('method', 'post');

        if (!form.querySelector('input[name="csrf_token"]')) {
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);
        }

        if (!form.querySelector('input[name="_event_slug"]')) {
            const slugInput = document.createElement('input');
            slugInput.type = 'hidden';
            slugInput.name = '_event_slug';
            slugInput.value = eventSlug;
            form.appendChild(slugInput);
        }

        if (!form.querySelector('input[name="_event_name"]')) {
            const nameInput = document.createElement('input');
            nameInput.type = 'hidden';
            nameInput.name = '_event_name';
            nameInput.value = eventName;
            form.appendChild(nameInput);
        }

        if (!form.querySelector('input[name="_return_url"]')) {
            const returnInput = document.createElement('input');
            returnInput.type = 'hidden';
            returnInput.name = '_return_url';
            returnInput.value = returnUrl;
            form.appendChild(returnInput);
        }

        if (!form.querySelector('input[name="_event_form_subject"]')) {
            const subjectInput = document.createElement('input');
            subjectInput.type = 'hidden';
            subjectInput.name = '_event_form_subject';
            subjectInput.value = 'Events formular';
            form.appendChild(subjectInput);
        }
    });

    const countdownNodes = document.querySelectorAll('[data-event-date]');
    const formatCountdown = function (secondsDiff) {
        if (secondsDiff <= 0) {
            return 'Probíhá';
        }

        const days = Math.floor(secondsDiff / 86400);
        const hours = Math.floor((secondsDiff % 86400) / 3600);
        const minutes = Math.floor((secondsDiff % 3600) / 60);
        const seconds = secondsDiff % 60;
        return 'Zbývá ' + days + ' dní, ' + hours + ' hodin, ' + minutes + ' minut, ' + seconds + ' sekund';
    };

    const updateCountdowns = function () {
        const now = new Date();
        countdownNodes.forEach(function (node) {
            const rawDate = (node.getAttribute('data-event-date') || '').trim();
            if (rawDate === '') {
                return;
            }

            const statusEl = node.querySelector('[data-upcoming-status]');
            if (!statusEl) {
                return;
            }

            const targetDate = new Date(rawDate + 'T00:00:00');
            if (Number.isNaN(targetDate.getTime())) {
                return;
            }

            const endDate = new Date(targetDate.getTime() + (24 * 60 * 60 * 1000));
            if (now >= endDate) {
                statusEl.textContent = 'Proběhl';
                statusEl.classList.remove('special-event-upcoming-status--future', 'special-event-upcoming-status--running');
                statusEl.classList.add('special-event-upcoming-status--past');
                return;
            }

            if (now >= targetDate && now < endDate) {
                statusEl.textContent = 'Probíhá';
                statusEl.classList.remove('special-event-upcoming-status--future', 'special-event-upcoming-status--past');
                statusEl.classList.add('special-event-upcoming-status--running');
                return;
            }

            const diffSeconds = Math.max(0, Math.floor((targetDate.getTime() - now.getTime()) / 1000));
            statusEl.textContent = formatCountdown(diffSeconds);
            statusEl.classList.remove('special-event-upcoming-status--running', 'special-event-upcoming-status--past');
            statusEl.classList.add('special-event-upcoming-status--future');
        });
    };

    updateCountdowns();
    setInterval(updateCountdowns, 1000);
});
</script>

<?php renderFooter();
