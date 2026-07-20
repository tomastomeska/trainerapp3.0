<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

$athleteId = (int)getCurrentAthleteId();
$pdo = getDB();
$athleteStmt = $pdo->prepare(
    'SELECT a.id,
            a.coach_id,
            a.first_name,
            a.last_name,
            a.email,
            a.apple_calendar_sync_enabled,
            a.apple_calendar_token,
            a.apple_caldav_sync_enabled,
            a.apple_caldav_calendar_url,
            a.apple_caldav_username,
            a.apple_caldav_app_password,
                 a.apple_caldav_last_error,
                 a.apple_caldav_last_success_at,
            c.name AS coach_name
     FROM athletes a
     JOIN coaches c ON c.id = a.coach_id
     WHERE a.id = ?
     LIMIT 1'
);
$athleteStmt->execute([$athleteId]);
$athlete = $athleteStmt->fetch();

if (!$athlete) {
    session_destroy();
    redirect(BASE_URL . '/login.php');
}

function generateCalendarSubscriptionToken(): string
{
    return bin2hex(random_bytes(32));
}

function buildAbsoluteAppUrl(string $path): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    return $scheme . '://' . $host . BASE_URL . $path;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $actionTab = 'apple';

    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Neplatný bezpečnostní token.');
        redirect(BASE_URL . '/athlete_calendar.php?tab=' . $actionTab);
    }

    if ($action === 'update_apple_caldav_sync') {
        $syncEnabled = !empty($_POST['apple_caldav_sync_enabled']) ? 1 : 0;
        $calendarUrl = trim((string)($_POST['apple_caldav_calendar_url'] ?? ''));
        $username = trim((string)($_POST['apple_caldav_username'] ?? ''));
        $appPassword = trim((string)($_POST['apple_caldav_app_password'] ?? ''));
        $previousCalendarUrl = trim((string)($athlete['apple_caldav_calendar_url'] ?? ''));
        $previousUsername = trim((string)($athlete['apple_caldav_username'] ?? ''));
        $previousPassword = trim((string)($athlete['apple_caldav_app_password'] ?? ''));

        if ($syncEnabled === 1) {
            $discoveryErrorDetail = '';
            if ($username === '' || $appPassword === '') {
                flash('danger', 'Apple CalDAV: vyplňte Apple ID a app-specific heslo.');
                redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
            }

            if ($calendarUrl === '') {
                try {
                    $calendarCreated = false;
                    $calendarUrl = ensureAppleCaldavTrainerAppCalendarUrl($username, $appPassword, 'TrainerApp', $calendarCreated);
                } catch (Throwable $e) {
                    $discoveryErrorDetail = trim((string)$e->getMessage());
                    flash('danger', 'Apple CalDAV: nepodarilo se najit ani vytvorit kalendar "TrainerApp". Detail: ' . mb_substr(preg_replace('/\s+/', ' ', $discoveryErrorDetail), 0, 220, 'UTF-8') . '.');
                    redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
                }
            } else {
                try {
                    $urlProbe = appleCaldavProbeCollectionWritable($calendarUrl, $username, $appPassword);
                    if (empty($urlProbe['ok'])) {
                        $calendarCreated = false;
                        $calendarUrl = ensureAppleCaldavTrainerAppCalendarUrl($username, $appPassword, 'TrainerApp', $calendarCreated);
                    }
                } catch (Throwable $e) {
                    flash('danger', 'Apple CalDAV: zadane URL kalendare se nepodarilo overit ani nahradit spravnym kalendarem. Detail: ' . mb_substr(preg_replace('/\s+/', ' ', trim((string)$e->getMessage())), 0, 220, 'UTF-8') . '.');
                    redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
                }
            }

            if (!preg_match('#^https://#i', $calendarUrl)) {
                flash('danger', 'Apple CalDAV: URL kalendare musi zacinat https://');
                redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
            }
        }

        $updateStmt = $pdo->prepare(
            'UPDATE athletes
             SET apple_caldav_sync_enabled = ?,
                 apple_caldav_calendar_url = ?,
                 apple_caldav_username = ?,
                 apple_caldav_app_password = ?,
                 apple_caldav_last_error = NULL,
                 apple_caldav_last_success_at = NULL
             WHERE id = ?'
        );
        $updateStmt->execute([
            $syncEnabled,
            $calendarUrl !== '' ? rtrim($calendarUrl, '/') . '/' : null,
            $username !== '' ? $username : null,
            $appPassword !== '' ? $appPassword : null,
            $athleteId,
        ]);

        $normalizedPreviousUrl = $previousCalendarUrl !== '' ? rtrim($previousCalendarUrl, '/') . '/' : '';
        $normalizedCurrentUrl = $calendarUrl !== '' ? rtrim($calendarUrl, '/') . '/' : '';
        if ($normalizedPreviousUrl !== '' && $normalizedCurrentUrl !== '' && $normalizedPreviousUrl !== $normalizedCurrentUrl) {
            try {
                purgeAthleteAppleCaldavRemoteEvents(
                    $athleteId,
                    $previousUsername !== '' ? $previousUsername : $username,
                    $previousPassword !== '' ? $previousPassword : $appPassword
                );
            } catch (Throwable $e) {
                // Pri cleanup failu zachovame novou konfiguraci a nechame dalsi seed sync pokracovat.
            }
        }

        if ($syncEnabled === 1) {
            // Inicialni naplneni po pripojeni: znovu zapise blizke historicke i budouci udalosti sportovce.
            $seedStmt = $pdo->prepare(
                'SELECT id
                 FROM coach_calendar_events
                 WHERE (athlete_id = ? OR second_athlete_id = ?)
                   AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 ORDER BY starts_at ASC
                 LIMIT 300'
            );
            $seedStmt->execute([$athleteId, $athleteId]);
            $seedIds = $seedStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            foreach ($seedIds as $seedEventId) {
                enqueueAthleteAppleCaldavSync($athleteId, (int)$seedEventId, 'upsert');
            }
            processAthleteAppleCaldavSyncQueue(30);
        }

        flash('success', 'Apple CalDAV synchronizace byla uložena.');
        redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
    }

    if ($action === 'generate_apple_caldav_url') {
        $calendarUrl = '';
        $username = trim((string)($_POST['apple_caldav_username'] ?? ''));
        $appPassword = trim((string)($_POST['apple_caldav_app_password'] ?? ''));
        $previousCalendarUrl = trim((string)($athlete['apple_caldav_calendar_url'] ?? ''));
        $previousUsername = trim((string)($athlete['apple_caldav_username'] ?? ''));
        $previousPassword = trim((string)($athlete['apple_caldav_app_password'] ?? ''));

        if ($username === '' || $appPassword === '') {
            flash('danger', 'Apple CalDAV: vyplňte Apple ID a app-specific heslo pro vygenerovani URL.');
            redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
        }

        try {
            $calendarCreated = false;
            $calendarUrl = ensureAppleCaldavTrainerAppCalendarUrl($username, $appPassword, 'TrainerApp', $calendarCreated);
        } catch (Throwable $e) {
            flash('danger', 'Apple CalDAV: URL kalendare TrainerApp se nepodarilo automaticky vygenerovat. Detail: ' . mb_substr(preg_replace('/\s+/', ' ', trim((string)$e->getMessage())), 0, 900, 'UTF-8') . '.');
            redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
        }

        $calendarUrl = rtrim($calendarUrl, '/') . '/';
        $updateStmt = $pdo->prepare(
            'UPDATE athletes
             SET apple_caldav_sync_enabled = 1,
                 apple_caldav_calendar_url = ?,
                 apple_caldav_username = ?,
                 apple_caldav_app_password = ?,
                 apple_caldav_last_error = NULL,
                 apple_caldav_last_success_at = NULL
             WHERE id = ?'
        );
        $updateStmt->execute([$calendarUrl, $username, $appPassword, $athleteId]);

        $normalizedPreviousUrl = $previousCalendarUrl !== '' ? rtrim($previousCalendarUrl, '/') . '/' : '';
        if ($normalizedPreviousUrl !== '' && $normalizedPreviousUrl !== $calendarUrl) {
            try {
                purgeAthleteAppleCaldavRemoteEvents(
                    $athleteId,
                    $previousUsername !== '' ? $previousUsername : $username,
                    $previousPassword !== '' ? $previousPassword : $appPassword
                );
            } catch (Throwable $e) {
                // URL uz je prepnuta na TrainerApp; pripadny cleanup starych zaznamu dobehne az pri dalsim odpojeni nebo zmene.
            }
        }

        // Stejne chovani jako po Ulozit: rovnou naplnit blizke historicke i budouci udalosti sportovce.
        $seedStmt = $pdo->prepare(
            'SELECT id
             FROM coach_calendar_events
             WHERE (athlete_id = ? OR second_athlete_id = ?)
               AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY starts_at ASC
             LIMIT 300'
        );
        $seedStmt->execute([$athleteId, $athleteId]);
        $seedIds = $seedStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($seedIds as $seedEventId) {
            enqueueAthleteAppleCaldavSync($athleteId, (int)$seedEventId, 'upsert');
        }
        processAthleteAppleCaldavSyncQueue(30);

        flash('success', 'Apple CalDAV URL pro kalendar TrainerApp byla nastavena a push synchronizace byla automaticky zapnuta: ' . $calendarUrl);
        redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
    }

    if ($action === 'disconnect_apple_caldav_sync') {
        $previousUsername = trim((string)($athlete['apple_caldav_username'] ?? ''));
        $previousPassword = trim((string)($athlete['apple_caldav_app_password'] ?? ''));
        try {
            purgeAthleteAppleCaldavRemoteEvents($athleteId, $previousUsername, $previousPassword);
        } catch (Throwable $e) {
            // I pri chybe cleanupu musi jit ucet odpojit.
        }

        $updateStmt = $pdo->prepare(
            'UPDATE athletes
             SET apple_caldav_sync_enabled = 0,
                 apple_caldav_calendar_url = NULL,
                 apple_caldav_username = NULL,
                 apple_caldav_app_password = NULL,
                 apple_caldav_last_error = NULL,
                 apple_caldav_last_success_at = NULL
             WHERE id = ?'
        );
        $updateStmt->execute([$athleteId]);

        flash('success', 'Apple CalDAV ucet byl odpojen.');
        redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
    }

    if ($action === 'update_google_calendar_sync') {
        flash('warning', 'Google Kalendář je dočasně ve vývoji. Záložka je momentálně deaktivovaná.');
        redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
    }

    if ($action === 'regenerate_google_calendar_token') {
        flash('warning', 'Google Kalendář je dočasně ve vývoji. Záložka je momentálně deaktivovaná.');
        redirect(BASE_URL . '/athlete_calendar.php?tab=apple');
    }
}

$athleteCaldavConnected = !empty($athlete['apple_caldav_username']) && !empty($athlete['apple_caldav_calendar_url']);

$athleteGoogleCalendarUrl = null;
if (!empty($athlete['apple_calendar_sync_enabled']) && !empty($athlete['apple_calendar_token'])) {
    $athleteGoogleCalendarUrl = buildAbsoluteAppUrl('/athlete_calendar_feed.php/' . rawurlencode((string)$athlete['apple_calendar_token']) . '/trainerapp-calendar.ics');
}

$activeTab = (string)($_GET['tab'] ?? 'week');
if (!in_array($activeTab, ['week', 'month', 'apple'], true)) {
    $activeTab = 'week';
}

$venues = array_values(array_filter(getTrainingVenuesForCoach((int)$athlete['coach_id']), fn($row) => !empty($row['name'])));
renderAthleteHeader('Můj kalendář', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-calendar-alt me-2 text-warning"></i>Můj kalendář</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <a href="<?= BASE_URL ?>/logout.php" class="btn btn-outline-danger btn-sm">
            <i class="fas fa-sign-out-alt me-1"></i>Odhlásit
        </a>
    </div>
</div>

<style>
.calendar-shell {
    overflow-x: auto;
    border-radius: 12px;
}

.calendar-grid {
    width: 100%;
    table-layout: fixed;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 100%;
}

.calendar-grid th,
.calendar-grid td {
    border: 1px solid #e5e7eb;
    vertical-align: top;
    padding: .35rem;
}

.calendar-grid thead th {
    background: #111827;
    color: #f9fafb;
    position: sticky;
    top: 0;
    z-index: 2;
}

.calendar-grid .time-col {
    width: 72px;
    min-width: 72px;
    text-align: center;
    font-weight: 700;
    background: #f8fafc;
    color: #374151;
}

.calendar-grid .slot-cell {
    height: 64px;
    cursor: pointer;
    background: #ffffff;
    transition: background .12s ease-in-out;
}

.calendar-grid .slot-cell:hover {
    background: #fffbeb;
}

.calendar-grid .slot-cell.is-locked {
    background: repeating-linear-gradient(
        45deg,
        #f3f4f6,
        #f3f4f6 8px,
        #e5e7eb 8px,
        #e5e7eb 16px
    );
    cursor: not-allowed;
}

#daypilotCalendar .coach-calendar-pending {
    animation: athletePendingPulse .72s ease-in-out infinite alternate;
}

#daypilotCalendar .athlete-event-non-cancelable {
    filter: grayscale(.35) brightness(.9);
    opacity: .82;
    cursor: not-allowed;
    box-shadow: inset 0 0 0 2px rgba(17, 24, 39, .28);
}

@keyframes athletePendingPulse {
    0% {
        opacity: 1;
        transform: scale(1);
        filter: saturate(1) brightness(1);
        box-shadow: 0 0 0 0 rgba(249, 115, 22, .0);
    }
    100% {
        opacity: .9;
        transform: scale(1.03);
        filter: saturate(1.35) brightness(1.08);
        box-shadow: 0 0 0 6px rgba(249, 115, 22, .45);
    }
}

@media (prefers-reduced-motion: reduce) {
    #daypilotCalendar .coach-calendar-pending {
        animation: none;
    }
}

@media (max-width: 991.98px) {
    .calendar-grid th,
    .calendar-grid td {
        padding: .22rem;
    }

    .calendar-grid .time-col {
        width: 52px;
        min-width: 52px;
        font-size: .68rem;
    }

    .calendar-grid .slot-cell {
        height: 52px;
    }

    #daypilotCard .card-body {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    #daypilotCalendar {
        min-width: 860px;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-calendar-alt me-2 text-warning"></i>Kalendář</h2>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-secondary btn-sm" id="prevWeekBtn">
            <i class="fas fa-chevron-left me-1"></i>Předchozí týden
        </button>
        <button class="btn btn-outline-dark btn-sm" id="todayWeekBtn">Tento týden</button>
        <button class="btn btn-outline-secondary btn-sm" id="nextWeekBtn">
            Další týden<i class="fas fa-chevron-right ms-1"></i>
        </button>
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="athleteCalendarTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'week' ? 'active' : '' ?>" id="athlete-week-tab" data-bs-toggle="tab" data-bs-target="#athlete-week-pane" type="button" role="tab" aria-controls="athlete-week-pane" aria-selected="<?= $activeTab === 'week' ? 'true' : 'false' ?>">
            Týdenní kalendář
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'month' ? 'active' : '' ?>" id="athlete-month-tab" data-bs-toggle="tab" data-bs-target="#athlete-month-pane" type="button" role="tab" aria-controls="athlete-month-pane" aria-selected="<?= $activeTab === 'month' ? 'true' : 'false' ?>">
            Měsíční seznam
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'apple' ? 'active' : '' ?>" id="athlete-apple-tab" data-bs-toggle="tab" data-bs-target="#athlete-apple-pane" type="button" role="tab" aria-controls="athlete-apple-pane" aria-selected="<?= $activeTab === 'apple' ? 'true' : 'false' ?>">
            Apple Kalendář <span class="badge rounded-pill text-bg-warning ms-1 align-middle">Beta</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link disabled" id="athlete-google-tab" type="button" role="tab" aria-controls="athlete-google-pane" aria-selected="false" aria-disabled="true" tabindex="-1" title="Ve vývoji">
            Google Kalendář <span class="badge rounded-pill text-bg-secondary ms-1 align-middle">Ve vývoji</span>
        </button>
    </li>
</ul>

<div class="tab-content">
<div class="tab-pane fade <?= $activeTab === 'week' ? 'show active' : '' ?>" id="athlete-week-pane" role="tabpanel" aria-labelledby="athlete-week-tab" tabindex="0">

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="fw-bold" id="weekRangeLabel">Načítám týden...</div>
            <div class="text-muted small">Klikněte do volného slotu pro vytvoření rezervace.</div>
        </div>
        <div class="d-flex align-items-center gap-2 small flex-wrap">
            <span class="badge" style="background:#16a34a;color:#fff">Schváleno</span>
            <span class="badge" style="background:#f97316;color:#fff">Ke schválení</span>
            <span class="badge" style="background:#374151;color:#fff">Obsazeno</span>
            <span class="badge" style="background:#9ca3af;color:#111827">Nelze zrušit</span>
            <span class="badge text-bg-secondary">Uzamčeno</span>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm" id="daypilotCard">
    <div class="card-body p-2 p-md-3">
        <div class="small text-muted mb-2 d-lg-none">
            <i class="fas fa-hand-point-right me-1"></i>Kalendář na mobilu posunete vodorovně tahem.
        </div>
        <div id="daypilotCalendar"></div>
    </div>
</div>

</div>

<div class="tab-pane fade <?= $activeTab === 'month' ? 'show active' : '' ?>" id="athlete-month-pane" role="tabpanel" aria-labelledby="athlete-month-tab" tabindex="0">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="mb-0"><i class="fas fa-list me-2 text-warning"></i>Moje události v měsíci</h5>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="athleteMonthPrevBtn"><i class="fas fa-chevron-left"></i></button>
                    <input type="month" class="form-control form-control-sm" id="athleteMonthInput" style="max-width: 180px;">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="athleteMonthNextBtn"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Typ události</th>
                            <th>Datum</th>
                            <th>Čas</th>
                            <th>Místo</th>
                            <th>Stav</th>
                        </tr>
                    </thead>
                    <tbody id="athleteMonthListBody">
                        <tr><td colspan="5" class="text-muted">Načítám data...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="text-muted small mt-2 d-none" id="athleteMonthListEmpty">V tomto měsíci nemáte žádné události.</div>
        </div>
    </div>
</div>

<div class="tab-pane fade <?= $activeTab === 'apple' ? 'show active' : '' ?>" id="athlete-apple-pane" role="tabpanel" aria-labelledby="athlete-apple-tab" tabindex="0">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <h5 class="mb-0">Apple rychlý sync (CalDAV)</h5>
                <?php if ($athleteCaldavConnected): ?>
                <span class="badge text-bg-success">Připojeno</span>
                <?php else: ?>
                <span class="badge text-bg-secondary">Nepřipojeno</span>
                <?php endif; ?>
            </div>
            <div class="form-text mb-3">
                Přímý zápis do vašeho iCloud kalendáře bez čekání na obnovu ICS odběru.
            </div>

            <?php if (!empty($athlete['apple_caldav_sync_enabled']) && !empty($athlete['apple_caldav_last_error'])): ?>
            <div class="alert alert-danger py-2 px-3 small">
                <div class="fw-semibold mb-1"><i class="fas fa-circle-exclamation me-1"></i>Poslední chyba synchronizace</div>
                <div><?= h((string)$athlete['apple_caldav_last_error']) ?></div>
            </div>
            <?php elseif (!empty($athlete['apple_caldav_sync_enabled']) && !empty($athlete['apple_caldav_last_success_at'])): ?>
            <div class="alert alert-success py-2 px-3 small">
                Poslední úspěšná synchronizace: <?= h((string)$athlete['apple_caldav_last_success_at']) ?>
            </div>
            <?php endif; ?>

            <form method="post" class="mb-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_apple_caldav_sync">

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="athleteAppleCaldavSyncEnabled" name="apple_caldav_sync_enabled" value="1" <?= !empty($athlete['apple_caldav_sync_enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="athleteAppleCaldavSyncEnabled">Zapnout Apple CalDAV push synchronizaci</label>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="athleteAppleCaldavCalendarUrl">CalDAV URL kalendáře (volitelné)</label>
                        <input type="url" class="form-control" id="athleteAppleCaldavCalendarUrl" name="apple_caldav_calendar_url" placeholder="https://caldav.icloud.com/..." value="<?= h((string)($athlete['apple_caldav_calendar_url'] ?? '')) ?>">
                        <div class="form-text">Když necháte prázdné, systém hledá pouze kalendář s názvem TrainerApp.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="athleteAppleCaldavUsername">Apple ID</label>
                        <input type="text" class="form-control" id="athleteAppleCaldavUsername" name="apple_caldav_username" value="<?= h((string)($athlete['apple_caldav_username'] ?? '')) ?>" autocomplete="username">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="athleteAppleCaldavAppPassword">App-specific heslo</label>
                        <input type="password" class="form-control" id="athleteAppleCaldavAppPassword" name="apple_caldav_app_password" value="<?= h((string)($athlete['apple_caldav_app_password'] ?? '')) ?>" autocomplete="new-password">
                        <div class="form-text text-danger">Nejedna se o heslo k Apple ID. Pouzijte pouze app-specific heslo vygenerovane na appleid.apple.com.</div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" class="btn btn-dark fw-semibold">
                        <i class="fas fa-bolt me-1"></i>Uložit CalDAV sync
                    </button>
                    <button type="submit" class="btn btn-outline-secondary fw-semibold" name="action" value="generate_apple_caldav_url">
                        <i class="fas fa-wand-magic-sparkles me-1"></i>Vygenerovat URL TrainerApp
                    </button>
                    <a href="<?= BASE_URL ?>/athlete_apple_caldav_mobileconfig.php" class="btn btn-outline-primary">
                        <i class="fas fa-mobile-screen-button me-1"></i>Stahnout Apple profil (.mobileconfig)
                    </a>
                    <?php if ($athleteCaldavConnected): ?>
                    <button type="submit" class="btn btn-outline-danger" name="action" value="disconnect_apple_caldav_sync" onclick="return confirm('Odpojit Apple CalDAV účet?');">
                        <i class="fas fa-link-slash me-1"></i>Odpojit účet
                    </button>
                    <?php endif; ?>
                </div>
                <div class="alert alert-warning py-2 px-3 mt-3 mb-0 small">
                    <strong>Dulezite:</strong> Po kliknuti na <strong>Vygenerovat URL TrainerApp</strong> se URL navaze a Apple CalDAV push synchronizace se zapne automaticky.
                    Neni potreba dalsi klik na Ulozit.
                </div>
            </form>

            <?php if ($athleteCaldavConnected): ?>
            <div class="alert alert-danger py-2 px-3 mt-2 mb-0 small">
                <div class="fw-semibold"><i class="fas fa-triangle-exclamation me-1"></i>Pozor na zdvojeni po instalaci profilu</div>
                <div>iOS po instalaci .mobileconfig prida vsechny kalendare Apple uctu. V aplikaci Kalendar nechte v sekci TrainerApp zapnuty pouze cilovy kalendar pro TrainerApp, ostatni odskrtnete.</div>
            </div>

            <div class="border border-danger rounded-3 p-3 mt-3 bg-white small">
                <div class="fw-semibold mb-2">Ukazkovy navod (co zapnout a co skryt)</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="fw-semibold mb-1">Sekce: TrainerApp Sportovec</div>
                        <pre class="bg-light border rounded p-2 mb-0">[ ] Domaci
[ ] Pracovní kalendář
[ ] Kalendář 1
[x] TrainerApp   (nechat zapnute)
[ ] Kalendar</pre>
                    </div>
                    <div class="col-md-6">
                        <div class="fw-semibold mb-1">Sekce: iCloud</div>
                        <pre class="bg-light border rounded p-2 mb-0">[x] Domaci
[x] Pracovni kalendář
[x] Kalendář 1
[ ] TrainerApp   (vypnout, jinak duplicita)
[x] Kalendar</pre>
                    </div>
                </div>
                <div class="mt-2">Postup v iPhonu: Kalendar -> Kalendare. Stejny kalendar (napr. Cviceni) nesmi byt zapnuty v obou sekcich zaroven; nechte ho zapnuty jen v jedne sekci.</div>
            </div>
            <?php endif; ?>

            <div class="row g-3 small mt-1">
                <div class="col-lg-4">
                    <div class="border rounded-3 bg-light p-3 h-100">
                        <div class="fw-semibold mb-2">1. Vytvoření hesla u Apple</div>
                        <ol class="mb-0 ps-3">
                            <li>Otevřete <a href="https://appleid.apple.com" target="_blank" rel="noopener">appleid.apple.com</a> a přihlaste se svým Apple ID.</li>
                            <li>V sekci Sign-In and Security otevřete App-Specific Passwords.</li>
                            <li>Klikněte na Generate an app-specific password.</li>
                            <li>Napište název třeba TrainerApp.</li>
                            <li>Zkopírujte heslo a vložte ho do pole App-specific heslo zde v aplikaci.</li>
                        </ol>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded-3 bg-light p-3 h-100">
                        <div class="fw-semibold mb-2">2. Doporučený kalendář (jen bez .mobileconfig)</div>
                        <ol class="mb-0 ps-3">
                            <li>Tento postup od bodu 2 platí pouze pokud jste nepoužili nastavovací soubor .mobileconfig.</li>
                            <li>Neni potreba predem rucne vytvaret kalendar TrainerApp v mobilu.</li>
                            <li>Kdyz nechate CalDAV URL prazdnou, system hleda cilovy kalendar TrainerApp a kdyz chybi, pokusi se ho vytvorit automaticky.</li>
                            <li>Když se termíny zapisují jinam, vložte sem ručně URL správného kalendáře a znovu uložte.</li>
                        </ol>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded-3 bg-light p-3 h-100">
                        <div class="fw-semibold mb-2">3. Co se děje po uložení</div>
                        <ol class="mb-0 ps-3">
                            <li>Pred instalaci profilu musi byt v iPhonu zapnuto: Nastaveni > [tvoje jmeno] > iCloud > Kalendare.</li>
                            <li>Po stazeni .mobileconfig otevrete aplikaci Nastaveni a klepnete na polozku Profil byl stazen.</li>
                            <li>Vaše tréninky se zapisují přímo do iCloudu bez čekání na obnovu odběru.</li>
                            <li>Nové a změněné termíny se většinou projeví během několika sekund až minut.</li>
                            <li>Po instalaci .mobileconfig otevřete v iPhonu aplikaci Kalendář > Kalendáře a stejné kalendáře nenechávejte zapnuté v obou sekcích zároveň.</li>
                            <li>Pokud máte starý odebíraný Apple kalendář, vypněte ho, ať nevidíte duplicity.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="tab-pane fade <?= $activeTab === 'google' ? 'show active' : '' ?>" id="athlete-google-pane" role="tabpanel" aria-labelledby="athlete-google-tab" tabindex="0">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="post" class="mb-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_google_calendar_sync">

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="athleteGoogleCalendarSyncEnabled" name="google_calendar_sync_enabled" value="1" <?= !empty($athlete['apple_calendar_sync_enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="athleteGoogleCalendarSyncEnabled">Synchronizovat moje tréninky do Google Kalendáře</label>
                </div>

                <div class="form-text mb-3">
                    Synchronizují se stejné události jako do Apple Kalendáře. Neschválené termíny trenérem se v kalendáři zobrazí jako čekající na schválení.
                </div>

                <button type="submit" class="btn btn-warning fw-semibold">
                    <i class="fas fa-save me-1"></i>Uložit nastavení
                </button>
            </form>

            <?php if ($athleteGoogleCalendarUrl !== null): ?>
            <div class="alert alert-info py-2 small mb-3">
                Postup: 1) zkopírujte odkaz níže, 2) v Google Kalendáři zvolte Přidat kalendář podle URL, 3) potvrďte přidání. Budou se synchronizovat jen vaše tréninky.
            </div>
            <label class="form-label fw-semibold">Soukromý Google kalendář odkaz (ICS)</label>
            <div class="input-group mb-2">
                <input type="text" id="athleteGoogleCalendarUrlField" class="form-control" value="<?= h($athleteGoogleCalendarUrl) ?>" readonly onclick="this.select();">
                <button type="button" class="btn btn-outline-secondary" id="copyAthleteGoogleCalendarUrlBtn">Kopírovat odkaz</button>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                <a href="https://calendar.google.com/calendar/u/0/r/settings/addbyurl?cid=<?= rawurlencode($athleteGoogleCalendarUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-dark btn-sm">Otevřít Přidat podle URL</a>
                <span class="small text-muted" id="copyAthleteGoogleCalendarUrlStatus" aria-live="polite"></span>
            </div>
            <div class="alert alert-warning py-2 small mb-3">
                Na mobilu se nový odběr v aplikaci Google Kalendář často projeví se zpožděním. Pokud už kalendář vidíte na počítači, je to v pořádku a na mobil se obvykle doplní automaticky později.
            </div>
            <div class="row g-3 small mb-2">
                <div class="col-md-6">
                    <div class="border rounded-3 bg-light p-3 h-100">
                        <div class="fw-semibold mb-2">Postup na webu Google Kalendáře</div>
                        <ol class="mb-0 ps-3">
                            <li>Otevřete Google Kalendář v prohlížeči.</li>
                            <li>V levém panelu klikněte na plus u Další kalendáře.</li>
                            <li>Zvolte Z URL a vložte soukromý ICS odkaz.</li>
                            <li>Potvrďte Přidat kalendář.</li>
                        </ol>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border rounded-3 bg-light p-3 h-100">
                        <div class="fw-semibold mb-2">Postup na mobilu (Android/iPhone)</div>
                        <ol class="mb-0 ps-3">
                            <li>Otevřete Google Kalendář v mobilním prohlížeči, ne v aplikaci: <a href="https://calendar.google.com/calendar/u/0/r/settings/addbyurl" target="_blank" rel="noopener">https://calendar.google.com/calendar/u/0/r/settings/addbyurl</a>.</li>
                            <li>Přihlaste se stejným Google účtem jako v aplikaci.</li>
                            <li>Vložte URL přes Přidat kalendář podle URL.</li>
                            <li>Počkejte na první synchronizaci a v aplikaci zkontrolujte, že je kalendář zapnutý.</li>
                        </ol>
                    </div>
                </div>
            </div>
            <details class="mt-3">
                <summary class="small text-muted" style="cursor:pointer;">Pokročilé: bezpečnost soukromého odkazu</summary>
                <div class="small text-muted mt-2">
                    Obnovení soukromého odkazu okamžitě zneplatní původní URL. Použijte pouze tehdy, pokud se domníváte, že byl odkaz sdílen nepovolané osobě nebo pokud potřebujete vynutit nový odběr.
                </div>
                <form method="post" class="mt-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="regenerate_google_calendar_token">
                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Opravdu obnovit soukromý odkaz? Tímto okamžitě přestanou fungovat všechny stávající Google odběry a bude nutné přidat nový odkaz.');">
                        <i class="fas fa-rotate-right me-1"></i>Obnovit soukromý odkaz
                    </button>
                </form>
            </details>
            <?php else: ?>
            <div class="small text-muted">Po zapnutí a uložení se zde zobrazí váš soukromý odkaz pro Google Kalendář.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="eventDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-calendar-day me-2 text-warning"></i>Detail události</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <div class="small text-muted">Název</div>
                    <div class="fw-semibold" id="eventDetailTitle">-</div>
                </div>
                <div class="mb-2">
                    <div class="small text-muted">Termín</div>
                    <div class="fw-semibold" id="eventDetailWhen">-</div>
                </div>
                <div class="mb-2">
                    <div class="small text-muted">Místo</div>
                    <div class="fw-semibold" id="eventDetailLocation">-</div>
                </div>
                <div class="mb-1">
                    <div class="small text-muted">Stav</div>
                    <div class="fw-semibold" id="eventDetailStatus">-</div>
                </div>
                <div class="alert alert-success border mt-3 mb-0 py-2 d-none" id="eventDetailPaymentInfo"></div>
                <div class="alert alert-light border mt-3 mb-0 py-2" id="eventDetailCancelInfo">Tento termín lze zrušit.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zavřít</button>
                <button type="button" class="btn btn-danger" id="eventDetailCancelBtn">
                    <i class="fas fa-trash-alt me-1"></i>Zrušit událost
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="reserveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="reserveForm">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus me-2 text-warning"></i>Rezervovat termín</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="reserveStart" value="">

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Čas začátku</label>
                        <div class="row g-2">
                            <div class="col-md-7">
                                <input type="date" class="form-control" id="reserveDate" required>
                            </div>
                            <div class="col-3 col-md-3">
                                <select id="reserveHour" class="form-select" required></select>
                            </div>
                            <div class="col-3 col-md-2">
                                <select id="reserveMinute" class="form-select" required>
                                    <option value="00">00</option>
                                    <option value="15">15</option>
                                    <option value="30">30</option>
                                    <option value="45">45</option>
                                </select>
                            </div>
                        </div>
                        <div class="small text-muted mt-1">Délka je vždy pevně 60 minut.</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold d-block">Typ události</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="reserveTitleType" id="reserveTitleTraining" value="training" checked>
                                <label class="form-check-label" for="reserveTitleTraining">Trénink</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="reserveTitleType" id="reserveTitleConsultation" value="consultation">
                                <label class="form-check-label" for="reserveTitleConsultation">Konzultační hodina</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="reserveTitleType" id="reserveTitleOther" value="other">
                                <label class="form-check-label" for="reserveTitleOther">Jiné</label>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="form-label fw-semibold">Místo</label>
                        <div class="input-group">
                            <select id="reserveLocation" class="form-select">
                                <option value="">Vyberte místo</option>
                                <?php foreach ($venues as $venue): ?>
                                <option value="<?= h((string)$venue['name']) ?>"
                                        data-address="<?= h((string)($venue['address'] ?? '')) ?>"
                                        data-note="<?= h((string)($venue['note'] ?? '')) ?>">
                                    <?= h((string)$venue['name']) ?>
                                    <?php if (!empty($venue['address'])): ?>
                                    - <?= h((string)$venue['address']) ?>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="small text-muted mt-1" id="reserveLocationHint"></div>
                    </div>

                    <div class="alert alert-warning mt-3 mb-0 d-none" id="reserveMakeupSuggestion">
                        <div class="fw-semibold mb-1"><i class="fas fa-circle-exclamation me-1"></i>Nevyužitý uhrazený trénink</div>
                        <div class="small" id="reserveMakeupSuggestionText"></div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="reserveUseMakeup">
                            <label class="form-check-label fw-semibold" for="reserveUseMakeup">Použít tento termín jako náhradu</label>
                        </div>
                        <div class="small text-muted mt-2">Hrazený měsíc se při náhradě vybere automaticky.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn btn-warning fw-bold">Rezervovat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://unpkg.com/@daypilot/daypilot-lite-javascript@5.6.0/daypilot-javascript.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE) ?>;
    const weekRangeLabel = document.getElementById('weekRangeLabel');
    const daypilotCalendarEl = document.getElementById('daypilotCalendar');
    const eventDetailModalEl = document.getElementById('eventDetailModal');
    const eventDetailModal = new bootstrap.Modal(eventDetailModalEl);
    const reserveModalEl = document.getElementById('reserveModal');
    const reserveModal = new bootstrap.Modal(reserveModalEl);
    const reserveLocationInput = document.getElementById('reserveLocation');
    const reserveLocationHint = document.getElementById('reserveLocationHint');
    const reserveDateInput = document.getElementById('reserveDate');
    const reserveHourInput = document.getElementById('reserveHour');
    const reserveMinuteInput = document.getElementById('reserveMinute');
    const reserveStartInput = document.getElementById('reserveStart');
    const reserveMakeupSuggestion = document.getElementById('reserveMakeupSuggestion');
    const reserveMakeupSuggestionText = document.getElementById('reserveMakeupSuggestionText');
    const reserveUseMakeupInput = document.getElementById('reserveUseMakeup');
    const eventDetailTitleEl = document.getElementById('eventDetailTitle');
    const eventDetailWhenEl = document.getElementById('eventDetailWhen');
    const eventDetailLocationEl = document.getElementById('eventDetailLocation');
    const eventDetailStatusEl = document.getElementById('eventDetailStatus');
    const eventDetailPaymentInfoEl = document.getElementById('eventDetailPaymentInfo');
    const eventDetailCancelInfoEl = document.getElementById('eventDetailCancelInfo');
    const eventDetailCancelBtn = document.getElementById('eventDetailCancelBtn');
    const athleteMonthInput = document.getElementById('athleteMonthInput');
    const athleteMonthPrevBtn = document.getElementById('athleteMonthPrevBtn');
    const athleteMonthNextBtn = document.getElementById('athleteMonthNextBtn');
    const athleteMonthListBody = document.getElementById('athleteMonthListBody');
    const athleteMonthListEmpty = document.getElementById('athleteMonthListEmpty');

    let currentWeekStart = getMonday(new Date());
    let events = [];
    let locks = [];
    let dayPilotCalendar = null;
    let selectedEventForDetail = null;
    const reserveMakeupSuggestionCache = new Map();
    const hourStart = 5;
    const hourEnd = 22;
    const isCompactMobile = window.matchMedia('(max-width: 991.98px)').matches;
    const eventColorSchemes = {
        blue: { backColor: '#0ea5e9', barColor: '#0284c7', fontColor: '#ffffff' },
        green: { backColor: '#22c55e', barColor: '#16a34a', fontColor: '#ffffff' },
        red: { backColor: '#ef4444', barColor: '#dc2626', fontColor: '#ffffff' },
        orange: { backColor: '#f97316', barColor: '#ea580c', fontColor: '#ffffff' },
        teal: { backColor: '#14b8a6', barColor: '#0f766e', fontColor: '#ffffff' },
        yellow: { backColor: '#facc15', barColor: '#ca8a04', fontColor: '#111827' },
        purple: { backColor: '#8b5cf6', barColor: '#7c3aed', fontColor: '#ffffff' },
        gray: { backColor: '#6b7280', barColor: '#4b5563', fontColor: '#ffffff' },
    };

    function normalizeColorKey(colorKey) {
        if (typeof colorKey !== 'string') {
            return 'blue';
        }
        return Object.prototype.hasOwnProperty.call(eventColorSchemes, colorKey) ? colorKey : 'blue';
    }

    function getEventColorScheme(event) {
        if (event.is_foreign) {
            return { backColor: '#1f2937', barColor: '#111827', fontColor: '#ffffff' };
        }

        if ((event.approval_status || 'approved') === 'pending') {
            return { backColor: '#f97316', barColor: '#ea580c', fontColor: '#ffffff' };
        }

        return eventColorSchemes.green;
    }

    function getEventStatusMeta(event) {
        if ((event.approval_status || 'approved') === 'pending') {
            return {
                label: 'Ke schválení',
                className: 'pending',
            };
        }

        if (event.coach_modified_at) {
            return {
                label: 'Upraveno trenérem',
                className: 'updated',
            };
        }

        return {
            label: '',
            className: '',
        };
    }

    function getEventTitle(event) {
        if (event.is_foreign) {
            return 'Obsazeno';
        }

        if (event.second_athlete_id) {
            return 'Párový trénink';
        }

        if (event.custom_title) {
            return event.custom_title;
        }
        if (event.athlete_id) {
            return 'Trénink';
        }
        return 'Rezervace';
    }

    function getMonday(date) {
        const d = new Date(date);
        d.setHours(0, 0, 0, 0);
        const day = d.getDay();
        const diff = d.getDate() - (day === 0 ? 6 : day - 1);
        d.setDate(diff);
        return d;
    }

    function addDays(date, days) {
        const d = new Date(date);
        d.setDate(d.getDate() + days);
        return d;
    }

    function toDateKey(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function toDateTimeInputValue(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        const h = String(date.getHours()).padStart(2, '0');
        const min = String(date.getMinutes()).padStart(2, '0');
        return `${y}-${m}-${d}T${h}:${min}`;
    }

    function toMonthKey(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        return `${y}-${m}`;
    }

    function toDateTimeSecondsValue(date) {
        return `${toDateTimeInputValue(date)}:00`;
    }

    function fromSqlDateTime(value) {
        return new Date(String(value).replace(' ', 'T'));
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function dayPilotDateToJs(value) {
        if (!value) return null;
        const raw = typeof value.toString === 'function' ? value.toString() : String(value);
        return new Date(raw.replace(' ', 'T'));
    }

    function formatDateCs(date) {
        return `${String(date.getDate()).padStart(2, '0')}.${String(date.getMonth() + 1).padStart(2, '0')}.${date.getFullYear()}`;
    }

    function formatTimeCs(date) {
        return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
    }

    function snapDateToQuarterHour(date) {
        const d = new Date(date);
        d.setSeconds(0, 0);
        const minutes = d.getMinutes();
        const snappedMinutes = Math.round(minutes / 15) * 15;
        if (snappedMinutes >= 60) {
            d.setHours(d.getHours() + 1, 0, 0, 0);
        } else {
            d.setMinutes(snappedMinutes, 0, 0);
        }
        return d;
    }

    function populateReserveHourOptions() {
        reserveHourInput.innerHTML = '';
        for (let hour = hourStart; hour < hourEnd; hour++) {
            const value = String(hour).padStart(2, '0');
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            reserveHourInput.appendChild(option);
        }
    }

    function syncReserveStartFromControls() {
        const date = reserveDateInput.value;
        const hour = reserveHourInput.value;
        const minute = reserveMinuteInput.value;
        if (!date || !hour || !minute) {
            reserveStartInput.value = '';
            return null;
        }
        reserveStartInput.value = `${date}T${hour}:${minute}`;
        return new Date(`${date}T${hour}:${minute}`);
    }

    function setReserveStartControls(date) {
        const snapped = snapDateToQuarterHour(date);
        const minute = String(snapped.getMinutes()).padStart(2, '0');
        reserveDateInput.value = toDateKey(snapped);
        reserveHourInput.value = String(snapped.getHours()).padStart(2, '0');
        reserveMinuteInput.value = ['00', '15', '30', '45'].includes(minute) ? minute : '00';
        syncReserveStartFromControls();
    }

    function canCancelEventByTime(event) {
        if (!event || !event.starts_at) return false;
        const startDate = fromSqlDateTime(event.starts_at);
        if (!(startDate instanceof Date) || Number.isNaN(startDate.getTime())) return false;
        return startDate > new Date();
    }

    function isLateCancellationWindow(event) {
        if (!event || !event.starts_at) return false;
        const startDate = fromSqlDateTime(event.starts_at);
        if (!(startDate instanceof Date) || Number.isNaN(startDate.getTime())) return false;
        const diffMs = startDate.getTime() - Date.now();
        return diffMs > 0 && diffMs < (12 * 60 * 60 * 1000);
    }

    function getWeekRangeLabel() {
        const start = new Date(currentWeekStart);
        const end = addDays(start, 6);
        return `${formatDateCs(start)} - ${formatDateCs(end)}`;
    }

    function isRangeLocked(start, end) {
        return locks.some((lock) => {
            const lockStart = fromSqlDateTime(lock.starts_at);
            const lockEnd = fromSqlDateTime(lock.ends_at);
            return lockStart < end && lockEnd > start;
        });
    }

    function isRangeOccupied(start, end) {
        return events.some((event) => {
            const eventStart = fromSqlDateTime(event.starts_at);
            const eventEnd = fromSqlDateTime(event.ends_at);
            return eventStart < end && eventEnd > start;
        });
    }

    function toDayPilotEvent(event) {
        const startDate = fromSqlDateTime(event.starts_at);
        const endDate = fromSqlDateTime(event.ends_at);
        const title = getEventTitle(event);
        const statusMeta = getEventStatusMeta(event);
        const timeLabel = `${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}`;
        const detailLine = event.is_foreign
            ? timeLabel
            : [timeLabel, event.location ? `Místo: ${event.location}` : 'Místo: -'].filter(Boolean).join('\n');
        const place = event.is_foreign ? '' : (event.location ? `\nMísto: ${event.location}` : '');
        const time = `\nČas: ${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}`;
        const color = getEventColorScheme(event);
        const statusLine = (!event.is_foreign && statusMeta.label) ? `\nStav: ${statusMeta.label}` : '';
        const paymentPaid = String(event.payment_status || '') === 'paid';
        const paymentLabel = paymentPaid ? 'Uhrazeno' : '';
        const ownedByAthlete = Boolean(event.is_mine || event.is_requested_by_me);
        const canCancel = Boolean(event.can_cancel ?? ownedByAthlete);
        const nonCancelableOwnedEvent = ownedByAthlete && !canCancel;
        const isForeign = Boolean(event.is_foreign);

        return {
            id: String(event.id),
            text: [title, detailLine].filter(Boolean).join('\n'),
            toolTip: isForeign
                ? `${title}${time}`
                : `${title}${time}${place}${statusLine}`
                + (paymentLabel ? `\nStav úhrady: ${paymentLabel}` : '')
                + (nonCancelableOwnedEvent ? '\nPoznámka: Tento termín už nelze zrušit.' : ''),
            start: toDateTimeSecondsValue(startDate),
            end: toDateTimeSecondsValue(endDate),
            backColor: color.backColor,
            barColor: color.barColor,
            fontColor: color.fontColor,
            cssClass: [
                statusMeta.className === 'pending' ? 'coach-calendar-pending' : '',
                nonCancelableOwnedEvent ? 'athlete-event-non-cancelable' : '',
            ].filter(Boolean).join(' '),
            moveDisabled: true,
            resizeDisabled: true,
            clickDisabled: isForeign,
            mine: canCancel,
            data: {
                mine: canCancel,
                is_foreign: isForeign,
            },
        };
    }

    function openEventDetailModal(event) {
        if (!event) return;

        const title = getEventTitle(event);
        const startDate = fromSqlDateTime(event.starts_at);
        const endDate = fromSqlDateTime(event.ends_at);
        const statusMeta = getEventStatusMeta(event);
        const canCancel = Boolean(event.can_cancel ?? (event.is_mine || event.is_requested_by_me));
        const lateCancellation = isLateCancellationWindow(event);

        selectedEventForDetail = event;
        eventDetailTitleEl.textContent = title;
        eventDetailWhenEl.textContent = `${formatDateCs(startDate)} ${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}`;
        eventDetailLocationEl.textContent = event.location || '-';
        eventDetailStatusEl.textContent = statusMeta.label || 'Schváleno';

        if (String(event.payment_status || '') === 'paid') {
            eventDetailPaymentInfoEl.textContent = 'Tato událost patří do již uhrazeného období.';
            eventDetailPaymentInfoEl.classList.remove('d-none');
        } else {
            eventDetailPaymentInfoEl.textContent = '';
            eventDetailPaymentInfoEl.classList.add('d-none');
        }

        if (canCancel) {
            eventDetailCancelBtn.classList.remove('d-none');
            eventDetailCancelBtn.disabled = false;
            if (lateCancellation) {
                eventDetailCancelInfoEl.className = 'alert alert-danger mt-3 mb-0 py-2';
                eventDetailCancelInfoEl.textContent = 'Pozor: Zrušení méně než 12 hodin před začátkem je bez nároku na kompenzaci. Tento termín nelze nahradit.';
            } else {
                eventDetailCancelInfoEl.className = 'alert alert-light border mt-3 mb-0 py-2';
                eventDetailCancelInfoEl.textContent = 'Tento termín lze zrušit.';
            }
        } else {
            eventDetailCancelBtn.classList.add('d-none');
            eventDetailCancelInfoEl.className = 'alert alert-secondary mt-3 mb-0 py-2';
            eventDetailCancelInfoEl.textContent = canCancelEventByTime(event)
                ? 'Tento termín nelze zrušit, protože není přiřazený tobě.'
                : 'Minulé nebo právě probíhající termíny nelze rušit.';
        }

        eventDetailModal.show();
    }

    function toDayPilotLockEvent(lock) {
        const startDate = fromSqlDateTime(lock.starts_at);
        const endDate = fromSqlDateTime(lock.ends_at);
        const note = lock.note ? `\nPoznámka: ${lock.note}` : '';

        return {
            id: `lock-${lock.id}`,
            text: 'Uzamčeno',
            toolTip: `Uzamčeno\nČas: ${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}${note}`,
            start: toDateTimeSecondsValue(startDate),
            end: toDateTimeSecondsValue(endDate),
            backColor: '#9ca3af',
            barColor: '#6b7280',
            fontColor: '#111827',
            moveDisabled: true,
            resizeDisabled: true,
            clickDisabled: true,
        };
    }

    function updateReserveLocationHint() {
        const selectedOption = reserveLocationInput.options[reserveLocationInput.selectedIndex] || null;

        if (!selectedOption || !reserveLocationInput.value) {
            reserveLocationHint.textContent = 'Vyberte existující místo z databáze.';
            return;
        }

        const address = selectedOption.dataset.address || '';
        const note = selectedOption.dataset.note || '';
        const parts = [];

        if (address) parts.push(address);
        if (note) parts.push(note);

        reserveLocationHint.textContent = parts.length ? parts.join(' • ') : 'Místo je načtené z katalogu training_venues.';
    }

    function clearReserveMakeupSuggestion() {
        reserveMakeupSuggestion.classList.add('d-none');
        reserveMakeupSuggestionText.textContent = '';
        reserveUseMakeupInput.checked = false;
    }

    function invalidateReserveMakeupSuggestionCache(monthKey = null) {
        if (!monthKey) {
            reserveMakeupSuggestionCache.clear();
            return;
        }
        reserveMakeupSuggestionCache.delete(monthKey);
    }

    function renderReserveMakeupSuggestion(payload, startDate) {
        if (!payload || !payload.success) {
            clearReserveMakeupSuggestion();
            return;
        }

        const hasOutstandingPaid = Boolean(payload.has_outstanding);
        const hasRequiredReplacement = Boolean(payload.has_required_replacement);
        if (!hasOutstandingPaid && !hasRequiredReplacement) {
            clearReserveMakeupSuggestion();
            return;
        }

        const targetMonthLabel = payload.target_month_label || `${String(startDate.getMonth() + 1).padStart(2, '0')}/${startDate.getFullYear()}`;
        const lines = [];

        if (hasRequiredReplacement) {
            const count = Number(payload.required_replacement_count || 0);
            const deadlineRaw = payload.required_replacement_deadline_at || '';
            const deadlineText = deadlineRaw ? new Date(String(deadlineRaw).replace(' ', 'T')).toLocaleDateString('cs-CZ') : '';
            lines.push(
                count > 1
                    ? `Máte ${count} zrušené termíny, které je potřeba nahradit.`
                    : 'Máte zrušený termín, který je potřeba nahradit.'
            );
            if (deadlineText) {
                lines.push(`Náhradní rezervaci je potřeba vytvořit nejpozději do ${deadlineText}.`);
            }
            lines.push('Náhradní termín vybírejte přednostně ve stejném měsíci jako byl zrušený termín.');
            lines.push('Do dalšího měsíce lze náhradu přesunout jen při zrušení v posledním týdnu měsíce.');
        }

        if (hasOutstandingPaid) {
            lines.push(`Máte ${payload.outstanding_sessions} nevyužitý(é) uhrazený(é) trénink(y). Můžete je použít jako náhradu pro ${targetMonthLabel}.`);
        }

        reserveMakeupSuggestionText.textContent = lines.join(' ');
        reserveMakeupSuggestion.classList.remove('d-none');
        reserveUseMakeupInput.checked = true;
    }

    async function refreshReserveMakeupSuggestion(startDate) {
        clearReserveMakeupSuggestion();
        if (!(startDate instanceof Date) || Number.isNaN(startDate.getTime())) {
            return;
        }

        const monthKey = toMonthKey(startDate);
        if (reserveMakeupSuggestionCache.has(monthKey)) {
            renderReserveMakeupSuggestion(reserveMakeupSuggestionCache.get(monthKey), startDate);
            return;
        }

        const payload = await fetch('<?= BASE_URL ?>/api/athlete_calendar_makeup_hint.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                starts_at: toDateTimeInputValue(startDate),
            }),
        }).then((response) => response.json());

        if (!payload.success) {
            return;
        }

        reserveMakeupSuggestionCache.set(monthKey, payload);
        renderReserveMakeupSuggestion(payload, startDate);
    }

    function openReserveModal(startDate) {
        populateReserveHourOptions();
        setReserveStartControls(startDate);
        reserveLocationInput.value = '';
        updateReserveLocationHint();
        clearReserveMakeupSuggestion();
        refreshReserveMakeupSuggestion(startDate);
        reserveModal.show();
    }

    async function cancelMyEvent(eventId) {
        const response = await fetch('<?= BASE_URL ?>/api/athlete_calendar_delete_event.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                event_id: Number(eventId),
            }),
        });
        const payload = await response.json();
        if (!payload.success) {
            alert(payload.error || 'Termín se nepodařilo zrušit.');
            return;
        }
        if (payload.message) {
            alert(payload.message);
        }
        invalidateReserveMakeupSuggestionCache();
        await loadWeekData();
    }

    function renderDayPilotCalendar() {
        if (!window.DayPilot || typeof window.DayPilot.Calendar !== 'function' || !daypilotCalendarEl) {
            return false;
        }

        if (!dayPilotCalendar) {
            dayPilotCalendar = new DayPilot.Calendar('daypilotCalendar', {
                locale: 'cs-cz',
                viewType: 'Week',
                weekStarts: 1,
                startDate: toDateKey(currentWeekStart),
                cellDuration: 60,
                cellHeight: isCompactMobile ? 56 : 68,
                eventArrangement: 'SideBySide',
                useEventBoxes: 'Never',
                showNonBusiness: false,
                businessWeekends: true,
                heightSpec: 'BusinessHoursNoScroll',
                businessBeginsHour: hourStart,
                businessEndsHour: hourEnd,
                durationBarVisible: true,
                bubble: null,
                eventMoveHandling: 'Disabled',
                eventResizeHandling: 'Disabled',
                eventDeleteHandling: 'Disabled',
                timeRangeSelectedHandling: 'JavaScript',
                onTimeRangeSelected: (args) => {
                    const start = dayPilotDateToJs(args.start);
                    const end = dayPilotDateToJs(args.end);
                    dayPilotCalendar.clearSelection();

                    if (!start || !end) return;
                    if (isRangeLocked(start, end)) {
                        alert('Vybraný čas je uzamčený.');
                        return;
                    }
                    if (isRangeOccupied(start, end)) {
                        alert('Vybraný čas je obsazený.');
                        return;
                    }

                    openReserveModal(start);
                },
                onEventClick: async (args) => {
                    const eventId = String(args.e.id());
                    const srcEvent = events.find((item) => String(item.id) === eventId) || null;
                    if (!srcEvent) return;
                    if (srcEvent.is_foreign) return;
                    openEventDetailModal(srcEvent);
                },
            });

            dayPilotCalendar.init();
        }

        dayPilotCalendar.update({
            startDate: toDateKey(currentWeekStart),
            events: [...locks.map(toDayPilotLockEvent), ...events.map(toDayPilotEvent)],
        });

        return true;
    }

    async function loadWeekData() {
        weekRangeLabel.textContent = getWeekRangeLabel();
        const params = new URLSearchParams({ week_start: toDateKey(currentWeekStart) });
        const response = await fetch(`<?= BASE_URL ?>/api/athlete_calendar_data.php?${params.toString()}`, {
            credentials: 'same-origin',
        });
        const payload = await response.json();

        if (!payload.success) {
            alert(payload.error || 'Nepodařilo se načíst kalendář.');
            return;
        }

        events = payload.events || [];
        locks = payload.locks || [];

        if (!renderDayPilotCalendar()) {
            alert('Nepodařilo se inicializovat zobrazení kalendáře.');
        }

        await loadAthleteMonthList();
    }

    function shiftMonthValue(monthValue, offset) {
        const parsed = new Date(`${monthValue}-01T00:00:00`);
        if (Number.isNaN(parsed.getTime())) {
            return monthValue;
        }
        parsed.setMonth(parsed.getMonth() + offset);
        return `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}`;
    }

    function getStatusBadge(statusClass, statusLabel) {
        const classMap = {
            success: 'bg-success',
            warning: 'bg-warning text-dark',
            danger: 'bg-danger',
            info: 'bg-info text-dark',
            secondary: 'bg-secondary',
        };
        const css = classMap[statusClass] || 'bg-secondary';
        return `<span class="badge ${css}">${escapeHtml(statusLabel)}</span>`;
    }

    function renderAthleteMonthList(items) {
        athleteMonthListBody.innerHTML = '';
        if (!Array.isArray(items) || items.length === 0) {
            athleteMonthListBody.innerHTML = '<tr><td colspan="5" class="text-muted">V tomto měsíci nemáte žádné události.</td></tr>';
            athleteMonthListEmpty.classList.remove('d-none');
            return;
        }

        athleteMonthListEmpty.classList.add('d-none');
        items.forEach((item) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeHtml(item.type_label || '-')}</td>
                <td>${escapeHtml(item.date_label || '-')}</td>
                <td>${escapeHtml(item.time_label || '-')}</td>
                <td>${escapeHtml(item.location_label || '-')}</td>
                <td>${getStatusBadge(item.status_class || 'secondary', item.status_label || 'Bez stavu')}</td>
            `;
            athleteMonthListBody.appendChild(tr);
        });
    }

    async function loadAthleteMonthList() {
        if (!athleteMonthInput || !athleteMonthInput.value) {
            return;
        }

        const params = new URLSearchParams({ month: athleteMonthInput.value });
        const response = await fetch(`<?= BASE_URL ?>/api/athlete_calendar_month_list.php?${params.toString()}`, {
            credentials: 'same-origin',
        });
        const payload = await response.json();

        if (!payload.success) {
            athleteMonthListBody.innerHTML = '<tr><td colspan="5" class="text-danger">Načtení měsíčního seznamu selhalo.</td></tr>';
            athleteMonthListEmpty.classList.add('d-none');
            return;
        }

        renderAthleteMonthList(payload.items || []);
    }

    reserveLocationInput.addEventListener('change', () => {
        updateReserveLocationHint();
    });

    [reserveDateInput, reserveHourInput, reserveMinuteInput].forEach((input) => {
        input.addEventListener('change', () => {
            const startDate = syncReserveStartFromControls();
            if (startDate instanceof Date && !Number.isNaN(startDate.getTime())) {
                refreshReserveMakeupSuggestion(startDate);
            }
        });
    });

    eventDetailCancelBtn.addEventListener('click', async () => {
        if (!selectedEventForDetail) {
            return;
        }

        const lateCancellation = isLateCancellationWindow(selectedEventForDetail);
        const confirmMessage = lateCancellation
            ? 'Opravdu chcete zrušit tuto událost? Pozor: je to méně než 12 hodin před začátkem, termín bude bez nároku na kompenzaci a nepůjde nahradit.'
            : 'Opravdu chcete zrušit tuto událost?';

        const ok = confirm(confirmMessage);
        if (!ok) {
            return;
        }

        const eventId = Number(selectedEventForDetail.id || 0);
        if (!eventId) {
            return;
        }

        await cancelMyEvent(eventId);
        eventDetailModal.hide();
    });

    document.getElementById('reserveForm').addEventListener('submit', async (event) => {
        event.preventDefault();

        const payload = {
            csrf_token: csrfToken,
            starts_at: reserveStartInput.value,
            title_type: document.querySelector('input[name="reserveTitleType"]:checked')?.value || 'training',
            location: reserveLocationInput.value.trim(),
            is_makeup_session: reserveUseMakeupInput.checked ? 1 : 0,
            allow_auto_makeup: !reserveMakeupSuggestion.classList.contains('d-none') ? 1 : 0,
        };

        const response = await fetch('<?= BASE_URL ?>/api/athlete_calendar_save_event.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        const result = await response.json();
        if (!result.success) {
            alert(result.error || 'Nepodařilo se vytvořit rezervaci.');
            return;
        }

        invalidateReserveMakeupSuggestionCache();
        reserveModal.hide();
        await loadWeekData();
    });

    document.getElementById('prevWeekBtn').addEventListener('click', () => {
        currentWeekStart = addDays(currentWeekStart, -7);
        loadWeekData();
    });

    document.getElementById('nextWeekBtn').addEventListener('click', () => {
        currentWeekStart = addDays(currentWeekStart, 7);
        loadWeekData();
    });

    document.getElementById('todayWeekBtn').addEventListener('click', () => {
        currentWeekStart = getMonday(new Date());
        loadWeekData();
    });

    athleteMonthInput.value = `${currentWeekStart.getFullYear()}-${String(currentWeekStart.getMonth() + 1).padStart(2, '0')}`;
    athleteMonthInput.addEventListener('change', () => {
        loadAthleteMonthList();
    });

    athleteMonthPrevBtn.addEventListener('click', () => {
        athleteMonthInput.value = shiftMonthValue(athleteMonthInput.value, -1);
        loadAthleteMonthList();
    });

    athleteMonthNextBtn.addEventListener('click', () => {
        athleteMonthInput.value = shiftMonthValue(athleteMonthInput.value, 1);
        loadAthleteMonthList();
    });

    function setupCalendarUrlCopy(buttonId, inputId, statusId) {
        const copyButton = document.getElementById(buttonId);
        const copyInput = document.getElementById(inputId);
        const copyStatus = document.getElementById(statusId);
        if (!copyButton || !copyInput) {
            return;
        }

        copyButton.addEventListener('click', async () => {
            copyInput.focus();
            copyInput.select();

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(copyInput.value);
                } else {
                    document.execCommand('copy');
                }

                if (copyStatus) {
                    copyStatus.textContent = 'Odkaz zkopírován.';
                }
            } catch (error) {
                if (copyStatus) {
                    copyStatus.textContent = 'Kopírování se nepodařilo, zkopírujte odkaz ručně.';
                }
            }
        });
    }

    setupCalendarUrlCopy('copyAthleteGoogleCalendarUrlBtn', 'athleteGoogleCalendarUrlField', 'copyAthleteGoogleCalendarUrlStatus');

    populateReserveHourOptions();
    updateReserveLocationHint();
    loadWeekData();
});
</script>

<?php renderAthleteFooter();
