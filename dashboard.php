<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = getCurrentCoachId();
$pdo     = getDB();
$mustChangePassword = !empty($_SESSION['coach_force_password_change']);
$forcePasswordError = null;

if ($mustChangePassword && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $forcePasswordError = 'Neplatný bezpečnostní token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action !== 'force_change_password') {
            $forcePasswordError = 'Při prvním přihlášení je nutné nejdříve změnit heslo.';
        } else {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $forcePasswordError = 'Vyplňte všechna pole pro změnu hesla.';
            } elseif (strlen($newPassword) < 6) {
                $forcePasswordError = 'Nové heslo musí mít alespoň 6 znaků.';
            } elseif ($newPassword !== $confirmPassword) {
                $forcePasswordError = 'Nová hesla se neshodují.';
            } else {
                $stmtCoachAuth = $pdo->prepare('SELECT password FROM coaches WHERE id = ? LIMIT 1');
                $stmtCoachAuth->execute([$coachId]);
                $coachAuth = $stmtCoachAuth->fetch();

                if (!$coachAuth || !password_verify($currentPassword, (string)$coachAuth['password'])) {
                    $forcePasswordError = 'Aktuální heslo není správné.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE coaches SET password = ?, force_password_change = 0 WHERE id = ?')
                        ->execute([$newHash, $coachId]);
                    $_SESSION['coach_force_password_change'] = 0;
                    flash('success', 'Heslo bylo úspěšně změněno.');
                    redirect(BASE_URL . '/dashboard.php');
                }
            }
        }
    }
}

$sortOptions = [
    'first_name' => [
        'label' => 'Jméno (A-Z)',
        'sql' => 'a.first_name ASC, a.last_name ASC, a.id ASC',
    ],
    'last_name' => [
        'label' => 'Příjmení (A-Z)',
        'sql' => 'a.last_name ASC, a.first_name ASC, a.id ASC',
    ],
    'last_training' => [
        'label' => 'Poslední trénink (nejnovější)',
        'sql' => 'CASE WHEN last_session_date IS NULL THEN 1 ELSE 0 END ASC, last_session_date DESC, a.first_name ASC, a.last_name ASC, a.id ASC',
    ],
];

$selectedSort = (string)($_GET['sort'] ?? 'first_name');
if (!isset($sortOptions[$selectedSort])) {
    $selectedSort = 'first_name';
}

$athleteOrderSql = $sortOptions[$selectedSort]['sql'];

$supportBankAccount = trim(getAppSetting('support_bank_account', ''));
$coachSupportStmt = $pdo->prepare('SELECT id, name, username FROM coaches WHERE id = ? LIMIT 1');
$coachSupportStmt->execute([$coachId]);
$coachSupportRow = $coachSupportStmt->fetch() ?: [];
$supportContributorName = trim((string)($coachSupportRow['name'] ?? ''));
if ($supportContributorName === '') {
    $supportContributorName = trim((string)($coachSupportRow['username'] ?? ''));
}
if ($supportContributorName === '') {
    $supportContributorName = 'trenér';
}
$supportBankAccountForQr = accountForSpd($supportBankAccount);
$supportQrNote = paymentAsciiText('Podpora TrainerApp - ' . $supportContributorName);

// Načtení sportovců s doplňkovými info
$stmt = $pdo->prepare(
    'SELECT a.*, 
            TIMESTAMPDIFF(YEAR, a.birth_date, CURDATE()) AS age,
            (SELECT COUNT(*) FROM training_sessions ts
                         WHERE ts.athlete_id = a.id
                             AND ts.completed_at IS NOT NULL
                             AND ts.deleted_by_coach_at IS NULL) AS session_count,
            (SELECT ts2.started_at FROM training_sessions ts2
                         WHERE ts2.athlete_id = a.id
                             AND ts2.completed_at IS NOT NULL
                             AND ts2.deleted_by_coach_at IS NULL
             ORDER BY ts2.completed_at DESC LIMIT 1) AS last_session_date,
            (SELECT ws.name FROM training_sessions ts3
             JOIN workout_sets ws ON ts3.workout_set_id = ws.id
                         WHERE ts3.athlete_id = a.id
                             AND ts3.completed_at IS NOT NULL
                             AND ts3.deleted_by_coach_at IS NULL
                         ORDER BY ts3.completed_at DESC LIMIT 1) AS last_set_name,
            (SELECT ts4.id FROM training_sessions ts4
                                                 WHERE ts4.athlete_id = a.id
                                                     AND ts4.completed_at IS NULL
                                                     AND ts4.deleted_by_coach_at IS NULL
                         ORDER BY ts4.started_at DESC LIMIT 1) AS active_session_id,
            (SELECT ts4.paired_session_id FROM training_sessions ts4
                                                 WHERE ts4.athlete_id = a.id
                                                     AND ts4.completed_at IS NULL
                                                     AND ts4.deleted_by_coach_at IS NULL
                         ORDER BY ts4.started_at DESC LIMIT 1) AS active_paired_session_id,
            (SELECT ts4.started_at FROM training_sessions ts4
                                                 WHERE ts4.athlete_id = a.id
                                                     AND ts4.completed_at IS NULL
                                                     AND ts4.deleted_by_coach_at IS NULL
                         ORDER BY ts4.started_at DESC LIMIT 1) AS active_session_started_at,
            (SELECT ws.name FROM training_sessions ts4
             JOIN workout_sets ws ON ws.id = ts4.workout_set_id
                                                 WHERE ts4.athlete_id = a.id
                                                     AND ts4.completed_at IS NULL
                                                     AND ts4.deleted_by_coach_at IS NULL
                         ORDER BY ts4.started_at DESC LIMIT 1) AS active_set_name,
            (SELECT w.weight_kg FROM athlete_weight_logs w WHERE w.athlete_id = a.id ORDER BY w.measured_at DESC LIMIT 1) AS current_weight,
                        (SELECT w.weight_kg FROM athlete_weight_logs w WHERE w.athlete_id = a.id ORDER BY w.measured_at ASC LIMIT 1) AS initial_weight,
                        (SELECT COUNT(*) FROM athlete_meal_plans amp
                         WHERE amp.athlete_id = a.id
                             AND amp.removed_at IS NULL) AS active_meal_plan_count
     FROM athletes a
     WHERE a.coach_id = ?
     ORDER BY ' . $athleteOrderSql
);
$stmt->execute([$coachId]);
$athletes = $stmt->fetchAll();

$activeSessionsStmt = $pdo->prepare(
    'SELECT ts.id AS session_id,
            ts.athlete_id,
            ts.paired_session_id,
            ts.started_at,
            a.first_name,
            a.last_name,
            ws.name AS set_name,
            (SELECT w.weight_kg
             FROM athlete_weight_logs w
             WHERE w.athlete_id = a.id
             ORDER BY w.measured_at DESC, w.id DESC
             LIMIT 1) AS latest_weight_kg,
            (SELECT w.measured_at
             FROM athlete_weight_logs w
             WHERE w.athlete_id = a.id
             ORDER BY w.measured_at DESC, w.id DESC
             LIMIT 1) AS latest_weight_measured_at
     FROM training_sessions ts
     JOIN athletes a ON a.id = ts.athlete_id
     JOIN workout_sets ws ON ws.id = ts.workout_set_id
     WHERE a.coach_id = ?
       AND ts.completed_at IS NULL
       AND ts.deleted_by_coach_at IS NULL
     ORDER BY COALESCE(ts.paired_session_id, ts.id) DESC, ts.started_at DESC'
);
$activeSessionsStmt->execute([$coachId]);
$activeSessions = $activeSessionsStmt->fetchAll();

$activeIndividualSessions = [];
$activePairedSessions = [];
foreach ($activeSessions as $session) {
    if (!empty($session['paired_session_id'])) {
        $pairedId = (int)$session['paired_session_id'];
        if (!isset($activePairedSessions[$pairedId])) {
            $activePairedSessions[$pairedId] = [
                'paired_session_id' => $pairedId,
                'started_at' => $session['started_at'],
                'sessions' => [],
            ];
        }
        $activePairedSessions[$pairedId]['sessions'][] = $session;
        continue;
    }

    $activeIndividualSessions[] = $session;
}

// Dnešní plán z kalendáře (neproběhlé + právě probíhající)
$todayCalendarStmt = $pdo->prepare(
        "SELECT e.id,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            a.first_name,
            a.last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     WHERE e.coach_id = ?
             AND e.approval_status = 'approved'
       AND e.starts_at >= CURDATE()
       AND e.starts_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
       AND e.ends_at > NOW()
         ORDER BY e.starts_at ASC, e.id ASC"
);
$todayCalendarStmt->execute([$coachId]);
$todayPlannedEvents = $todayCalendarStmt->fetchAll();

$now = new DateTimeImmutable('now');

$formatCalendarEventPerson = static function (array $event): string {
    if (!empty($event['first_name']) || !empty($event['last_name'])) {
        return trim((string)($event['first_name'] ?? '') . ' ' . (string)($event['last_name'] ?? ''));
    }
    if (!empty($event['custom_title'])) {
        return (string)$event['custom_title'];
    }
    return 'Trénink bez názvu';
};

$ongoingTodayEvents = [];
$nextTodayEvent = null;

foreach ($todayPlannedEvents as $event) {
    $eventStart = new DateTimeImmutable($event['starts_at']);
    $eventEnd = new DateTimeImmutable($event['ends_at']);

    if ($eventStart <= $now && $eventEnd > $now) {
        $ongoingTodayEvents[] = $event;
        continue;
    }

    if ($eventStart > $now && $nextTodayEvent === null) {
        $nextTodayEvent = $event;
    }
}

$todayPendingCount = count($todayPlannedEvents);

$unreadInboxCount = 0;
$pendingCalendarRequestCount = 0;
$unreadInfoCount = 0;
try {
    $unreadInboxStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM admin_message_recipients
         WHERE coach_id = ?
           AND status = 'inbox'
           AND read_at IS NULL"
    );
    $unreadInboxStmt->execute([$coachId]);
    $unreadInboxCount = (int)$unreadInboxStmt->fetchColumn();

    $pendingCalendarStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM coach_calendar_events
         WHERE coach_id = ?
           AND approval_status = 'pending'
           AND requested_by_athlete_id IS NOT NULL"
    );
    $pendingCalendarStmt->execute([$coachId]);
    $pendingCalendarRequestCount = (int)$pendingCalendarStmt->fetchColumn();

    $infoUnreadStmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM info_articles ia\n        JOIN info_categories ic ON ic.id = ia.category_id\n        LEFT JOIN info_article_reads_coach ir ON ir.article_id = ia.id AND ir.coach_id = ?\n        WHERE ia.is_active = 1\n          AND ia.published_at <= NOW()\n          AND ia.target_audience IN ('all', 'coach')\n          AND ic.is_active = 1\n          AND ic.audience IN ('all', 'coach')\n          AND ir.article_id IS NULL\n    ");
    $infoUnreadStmt->execute([$coachId]);
    $unreadInfoCount = (int)$infoUnreadStmt->fetchColumn();
} catch (Throwable $e) {
    $unreadInboxCount = 0;
    $pendingCalendarRequestCount = 0;
    $unreadInfoCount = 0;
}

$minutesToNextTodayEvent = null;
if ($nextTodayEvent !== null) {
    $nextStart = new DateTimeImmutable($nextTodayEvent['starts_at']);
    $minutesToNextTodayEvent = (int)max(0, ceil(($nextStart->getTimestamp() - $now->getTimestamp()) / 60));
}

// Zítřejší plán z kalendáře
$tomorrowStart = $now->modify('tomorrow')->setTime(0, 0, 0);
$tomorrowEnd = $tomorrowStart->modify('+1 day');

$tomorrowCalendarStmt = $pdo->prepare(
        "SELECT e.id,
            e.custom_title,
            e.location,
            e.starts_at,
            e.ends_at,
            a.first_name,
            a.last_name
     FROM coach_calendar_events e
     LEFT JOIN athletes a ON a.id = e.athlete_id
     WHERE e.coach_id = ?
             AND e.approval_status = 'approved'
       AND e.starts_at >= ?
       AND e.starts_at < ?
         ORDER BY e.starts_at ASC, e.id ASC"
);
$tomorrowCalendarStmt->execute([
    $coachId,
    $tomorrowStart->format('Y-m-d H:i:s'),
    $tomorrowEnd->format('Y-m-d H:i:s'),
]);
$tomorrowPlannedEvents = $tomorrowCalendarStmt->fetchAll();

$tomorrowCount = count($tomorrowPlannedEvents);
$firstTomorrowEvent = $tomorrowPlannedEvents[0] ?? null;
$firstTomorrowTime = null;
if ($firstTomorrowEvent) {
    $firstTomorrowTime = (new DateTimeImmutable($firstTomorrowEvent['starts_at']))->format('H:i');
}

renderHeader('Dashboard', false, true);
?>

<style>
    .coach-dashboard-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: .75rem;
        flex-wrap: wrap;
    }

    .coach-dashboard-toolbar__sort {
        flex: 1 1 280px;
        min-width: 280px;
    }

    .coach-dashboard-toolbar__sort form {
        display: flex;
        align-items: center;
        gap: .5rem;
        flex-wrap: wrap;
    }

    .coach-dashboard-toolbar__actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: .5rem;
        flex-wrap: wrap;
    }

    .coach-today-plan-card .card-body {
        padding: 1rem;
    }

    .coach-today-plan-grid {
        display: grid;
        gap: .65rem;
    }

    .coach-today-plan-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: .75rem;
        flex-wrap: wrap;
    }

    .coach-today-plan-kicker {
        font-size: .72rem;
        font-weight: 800;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: #6b7280;
    }

    .coach-today-plan-count {
        font-weight: 900;
        font-size: 1.35rem;
        line-height: 1.05;
    }

    .coach-today-plan-event {
        border-radius: 14px;
        padding: .8rem .9rem;
        border: 1px solid rgba(15, 23, 42, .08);
        line-height: 1.35;
        box-shadow: 0 1px 8px rgba(15, 23, 42, .04);
    }

    .coach-today-plan-event strong {
        font-weight: 800;
    }

    .coach-today-plan-event--ongoing {
        background: linear-gradient(135deg, #dcfce7 0%, #ecfdf5 100%);
        color: #14532d;
    }

    .coach-today-plan-event--next {
        background: linear-gradient(135deg, #fef3c7 0%, #fff7ed 100%);
        color: #7c2d12;
    }

    .coach-today-plan-event--tomorrow {
        background: linear-gradient(135deg, #dbeafe 0%, #eff6ff 100%);
        color: #1e3a8a;
    }

    .coach-dashboard-shortcuts-mobile {
        display: none;
    }

    .coach-dashboard-alert-tiles {
        display: grid;
    }

    .coach-dashboard-shortcuts-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .6rem;
    }

    .coach-dashboard-shortcut {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
        color: #111827;
        text-decoration: none;
        padding: .72rem .78rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem;
        font-weight: 700;
        font-size: .92rem;
        box-shadow: 0 3px 10px rgba(15, 23, 42, .04);
    }

    .coach-dashboard-shortcut .badge {
        font-size: .72rem;
    }

    @media (max-width: 991.98px) {
        .coach-dashboard-toolbar__sort,
        .coach-dashboard-toolbar__actions {
            width: 100%;
        }

        .coach-dashboard-toolbar__sort {
            min-width: 0;
        }

        .coach-dashboard-toolbar__actions {
            justify-content: center;
        }

        .coach-dashboard-toolbar__sort form {
            width: 100%;
            justify-content: space-between;
        }

        .coach-dashboard-toolbar__sort .form-select {
            flex: 1 1 220px;
        }
    }

    @media (max-width: 1427.98px) {
        .coach-dashboard-shortcuts-mobile {
            display: block;
        }

        .coach-dashboard-alert-tiles {
            display: none;
        }
    }

    @media (max-width: 575.98px) {

        .coach-today-plan-card .card-body {
            padding: .85rem;
        }

        .coach-today-plan-count {
            font-size: 1.15rem;
        }

        .coach-today-plan-event {
            padding: .7rem .75rem;
            border-radius: 12px;
        }

        .coach-dashboard-toolbar__actions {
            width: 100%;
        }

        .coach-dashboard-toolbar__actions .btn {
            flex: 1 1 calc(50% - .25rem);
            min-width: 0;
        }

        .coach-dashboard-toolbar__actions .btn-paired-highlight {
            order: 1;
        }

        .coach-dashboard-toolbar__actions .btn-warning {
            order: 2;
        }

        #active-trainings .card-body {
            padding: .85rem;
        }

        #active-trainings .border.rounded-3.p-2 {
            padding: .8rem !important;
            border-radius: 12px !important;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }

        #active-trainings .fw-bold.small {
            font-size: .96rem;
        }

        #active-trainings .text-muted.small {
            font-size: .78rem;
        }

        #active-trainings .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<?php if ($mustChangePassword): ?>
<div class="alert alert-warning mb-3">
    Po prvním přihlášení je nutné změnit heslo, než budete pokračovat v práci.
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3 coach-dashboard-shortcuts-mobile">
    <div class="card-body p-3">
        <div class="small text-uppercase fw-bold text-muted mb-2">Rychlé menu</div>
        <div class="coach-dashboard-shortcuts-grid">
            <a href="<?= BASE_URL ?>/dashboard.php" class="coach-dashboard-shortcut">
                <span><i class="fas fa-house me-1"></i>Sportovci</span>
            </a>
            <a href="<?= BASE_URL ?>/zpravy.php" class="coach-dashboard-shortcut">
                <span><i class="fas fa-envelope me-1"></i>Zprávy</span>
                <?php if ($unreadInboxCount > 0): ?><span class="badge bg-danger"><?= (int)$unreadInboxCount ?></span><?php endif; ?>
            </a>
            <a href="<?= BASE_URL ?>/calendar.php" class="coach-dashboard-shortcut">
                <span><i class="fas fa-calendar-alt me-1"></i>Kalendář</span>
                <?php if ($pendingCalendarRequestCount > 0): ?><span class="badge bg-warning text-dark"><?= (int)$pendingCalendarRequestCount ?></span><?php endif; ?>
            </a>
            <a href="<?= BASE_URL ?>/infokanal.php" class="coach-dashboard-shortcut">
                <span><i class="fas fa-lightbulb me-1"></i>Infokanál</span>
                <?php if ($unreadInfoCount > 0): ?><span class="badge bg-danger"><?= (int)$unreadInfoCount ?></span><?php endif; ?>
            </a>
            <a href="<?= BASE_URL ?>/exercises.php" class="coach-dashboard-shortcut"><span><i class="fas fa-list me-1"></i>Cviky</span></a>
            <a href="<?= BASE_URL ?>/sady.php" class="coach-dashboard-shortcut"><span><i class="fas fa-layer-group me-1"></i>Sady</span></a>
            <a href="<?= BASE_URL ?>/payments.php" class="coach-dashboard-shortcut"><span><i class="fas fa-wallet me-1"></i>Platby</span></a>
            <a href="<?= BASE_URL ?>/meal_plans.php" class="coach-dashboard-shortcut"><span><i class="fas fa-utensils me-1"></i>Jídelníčky</span></a>
            <a href="<?= BASE_URL ?>/gallery.php" class="coach-dashboard-shortcut"><span><i class="fas fa-images me-1"></i>Galerie</span></a>
            <a href="<?= BASE_URL ?>/profile.php" class="coach-dashboard-shortcut"><span><i class="fas fa-user-tie me-1"></i>Můj profil</span></a>
            <a href="<?= BASE_URL ?>/coach_manual.php" class="coach-dashboard-shortcut"><span><i class="fas fa-circle-question me-1"></i>Návod</span></a>
            <a href="<?= BASE_URL ?>/coach_terms.php" class="coach-dashboard-shortcut"><span><i class="fas fa-file-contract me-1"></i>Podmínky</span></a>
        </div>
    </div>
</div>

<div class="dashboard-quick-tiles coach-dashboard-alert-tiles mb-3">
    <a href="<?= BASE_URL ?>/zpravy.php" class="quick-tile quick-tile-danger">
        <span class="quick-tile__label"><i class="fas fa-envelope me-1"></i>Zprávy</span>
        <span class="quick-tile__value"><?= (int)$unreadInboxCount ?></span>
    </a>
    <a href="<?= BASE_URL ?>/calendar.php" class="quick-tile quick-tile-warning">
        <span class="quick-tile__label"><i class="fas fa-calendar-alt me-1"></i>Žádosti kalendáře</span>
        <span class="quick-tile__value"><?= (int)$pendingCalendarRequestCount ?></span>
    </a>
</div>

<div class="card border-0 shadow-sm mb-3 coach-today-plan-card">
    <div class="card-body py-3">
        <div class="coach-today-plan-grid">
            <div class="coach-today-plan-header">
                <div>
                    <div class="coach-today-plan-kicker">Dnešní plán v kalendáři</div>
                    <div class="coach-today-plan-count"><?= (int)$todayPendingCount ?> neproběhlých tréninků</div>
                </div>
                <a href="<?= BASE_URL ?>/calendar.php" class="btn btn-outline-secondary btn-sm align-self-start">
                    <i class="fas fa-calendar-alt me-1"></i>Kalendář
                </a>
            </div>

            <div class="coach-today-plan-grid">
                <?php if (!empty($ongoingTodayEvents)): ?>
                    <?php $current = $ongoingTodayEvents[0]; ?>
                    <div class="coach-today-plan-event coach-today-plan-event--ongoing fw-semibold">
                        <i class="fas fa-circle-play me-1"></i>
                        Probíhá:
                        <strong><?= h($formatCalendarEventPerson($current)) ?></strong>
                        <?php if (!empty($current['location'])): ?>
                            · <?= h($current['location']) ?>
                        <?php endif; ?>
                        <?php if (count($ongoingTodayEvents) > 1): ?>
                            <span class="d-block mt-1">+ další <?= count($ongoingTodayEvents) - 1 ?></span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="coach-today-plan-event bg-light text-muted fw-semibold">Aktuálně nic neprobíhá</div>
                <?php endif; ?>

                <?php if ($nextTodayEvent !== null && $minutesToNextTodayEvent !== null): ?>
                <div class="coach-today-plan-event coach-today-plan-event--next text-start fw-semibold">
                    <i class="fas fa-clock me-1"></i>
                    Za <?= (int)$minutesToNextTodayEvent ?> min:
                    <strong><?= h($formatCalendarEventPerson($nextTodayEvent)) ?></strong>
                    <?php if (!empty($nextTodayEvent['location'])): ?>
                        · <?= h($nextTodayEvent['location']) ?>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="coach-today-plan-event bg-light text-muted fw-semibold">Dnes už další trénink nezačíná</div>
                <?php endif; ?>

                <div class="coach-today-plan-event coach-today-plan-event--tomorrow text-start fw-semibold">
                    <i class="fas fa-calendar-day me-1"></i>
                    Zítra naplánováno: <strong><?= (int)$tomorrowCount ?></strong>
                    <?php if ($firstTomorrowEvent): ?>
                        <span class="d-block mt-1">
                            První: <strong><?= h($formatCalendarEventPerson($firstTomorrowEvent)) ?></strong>
                            v <?= h((string)$firstTomorrowTime) ?>
                            <?php if (!empty($firstTomorrowEvent['location'])): ?>
                                · <?= h($firstTomorrowEvent['location']) ?>
                            <?php endif; ?>
                        </span>
                    <?php else: ?>
                        <span class="d-block mt-1">Bez naplánovaného tréninku</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="coach-dashboard-toolbar mb-4">
    <h2 class="mb-0"><i class="fas fa-users me-2 text-warning"></i>Moji sportovci</h2>
    <div class="coach-dashboard-toolbar__sort">
        <form method="get" class="d-flex align-items-center gap-2">
            <label for="sortAthletes" class="small text-muted">Řazení</label>
            <select id="sortAthletes" name="sort" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($sortOptions as $sortKey => $sortMeta): ?>
                <option value="<?= h($sortKey) ?>" <?= $selectedSort === $sortKey ? 'selected' : '' ?>>
                    <?= h($sortMeta['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="coach-dashboard-toolbar__actions">
        <?php if (count($athletes) >= 2): ?>
        <a href="<?= BASE_URL ?>/training_paired_start.php" class="btn btn-sm fw-bold btn-paired-highlight">
            <i class="fas fa-people-group me-1"></i>Párový trénink
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/athlete_add.php" class="btn btn-warning btn-sm fw-bold">
            <i class="fas fa-plus me-1"></i>Přidat sportovce
        </a>
    </div>
</div>

<?php if (!empty($activeIndividualSessions) || !empty($activePairedSessions)): ?>
<div class="card border-0 shadow-sm mb-3" id="active-trainings">
    <div class="card-header bg-dark text-white fw-bold py-2">
        <i class="fas fa-stopwatch me-2"></i>Aktivní tréninky
    </div>
    <div class="card-body py-3">
        <?php if (!empty($activeIndividualSessions)): ?>
        <div class="mb-3">
            <div class="fw-semibold mb-2 small text-uppercase text-muted">Individuální</div>
            <div class="row g-2 row-cols-1 row-cols-md-2 row-cols-xl-3">
                <?php foreach ($activeIndividualSessions as $session): ?>
                <div class="col">
                    <div class="border rounded-3 p-2 h-100 bg-light d-flex flex-column gap-1">
                        <div class="fw-bold small"><?= h($session['first_name'] . ' ' . $session['last_name']) ?></div>
                        <div class="text-muted small"><?= h($session['set_name']) ?> · <?= formatDateTime($session['started_at']) ?></div>
                        <?php if ($session['latest_weight_kg'] !== null): ?>
                        <div class="text-muted small">
                            <i class="fas fa-weight-scale me-1"></i>
                            <?= number_format((float)$session['latest_weight_kg'], 1, ',', '') ?> kg
                            <?php if (!empty($session['latest_weight_measured_at'])): ?>
                                (<?= formatDate((string)$session['latest_weight_measured_at']) ?>)
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/training_session.php?id=<?= (int)$session['session_id'] ?>"
                           class="btn btn-sm btn-warning fw-bold align-self-start">
                            <i class="fas fa-play me-1"></i>Pokračovat
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($activePairedSessions)): ?>
        <div>
            <div class="fw-semibold mb-2 small text-uppercase text-muted">Párové</div>
            <div class="row g-2 row-cols-1 row-cols-md-2 row-cols-xl-3">
                <?php foreach ($activePairedSessions as $pair): ?>
                <div class="col">
                    <div class="border rounded-3 p-2 h-100 bg-light d-flex flex-column gap-1">
                        <div class="fw-bold small">
                            <i class="fas fa-people-group me-1 text-info"></i>Párový trénink
                        </div>
                        <div class="text-muted small">
                            <?= count($pair['sessions']) ?> sportovci · <?= formatDateTime($pair['started_at']) ?>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($pair['sessions'] as $session): ?>
                            <span class="badge bg-white text-dark border small">
                                <?= h($session['first_name'] . ' ' . $session['last_name']) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <div class="small text-muted">
                            <?php foreach ($pair['sessions'] as $session): ?>
                                <?php if ($session['latest_weight_kg'] !== null): ?>
                                <div>
                                    <i class="fas fa-weight-scale me-1"></i>
                                    <?= h($session['first_name'] . ' ' . $session['last_name']) ?>:
                                    <?= number_format((float)$session['latest_weight_kg'], 1, ',', '') ?> kg
                                    <?php if (!empty($session['latest_weight_measured_at'])): ?>
                                        (<?= formatDate((string)$session['latest_weight_measured_at']) ?>)
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?= BASE_URL ?>/training_paired_session.php?id=<?= (int)$pair['paired_session_id'] ?>"
                           class="btn btn-sm btn-info text-dark fw-bold align-self-start">
                            <i class="fas fa-play me-1"></i>Pokračovat společně
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (empty($athletes)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <div class="display-3 text-muted mb-3">🏃</div>
        <h4 class="text-muted">Zatím nemáte žádné sportovce</h4>
        <p class="text-muted">Přidejte prvního sportovce a začněte trénovat!</p>
        <a href="<?= BASE_URL ?>/athlete_add.php" class="btn btn-warning fw-bold">
            <i class="fas fa-plus me-1"></i>Přidat sportovce
        </a>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($athletes as $a): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card athlete-card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-center mb-3">
                    <?php if ($a['photo']): ?>
                    <img src="<?= h(photoUrl($a['photo'], 'athletes')) ?>" alt="Fotografie"
                         class="rounded-circle"
                         style="width:100px;height:100px;object-fit:cover;border:3px solid #ffc107;">
                    <?php else: ?>
                    <?php $initials = strtoupper(mb_substr($a['first_name'], 0, 1, 'UTF-8') . mb_substr($a['last_name'], 0, 1, 'UTF-8')); ?>
                    <div class="avatar-initials" title="<?= h($a['first_name'] . ' ' . $a['last_name']) ?>">
                        <?= $initials ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h5 class="card-title mb-0 fw-bold">
                            <?= h($a['first_name'] . ' ' . $a['last_name']) ?>
                        </h5>
                        <?php $age = calculateAge($a['birth_date'] ?? null); ?>
                        <small class="text-muted"><?= $age !== null ? $age . ' let' : '' ?></small>
                    </div>
                    <span class="badge bg-warning text-dark rounded-pill fs-6">
                        <?= $a['session_count'] ?>×
                    </span>
                </div>

                <?php if ($a['active_session_id']): ?>
                <div class="mb-3">
                    <span class="badge <?= $a['active_paired_session_id'] ? 'bg-info text-dark' : 'bg-success' ?> me-1">
                        <i class="fas <?= $a['active_paired_session_id'] ? 'fa-people-group' : 'fa-circle-play' ?> me-1"></i>
                        <?= $a['active_paired_session_id'] ? 'Probíhá párový trénink' : 'Probíhá trénink' ?>
                    </span>
                    <span class="badge bg-light text-dark border">
                        <i class="fas fa-layer-group me-1"></i><?= h($a['active_set_name'] ?? '') ?>
                    </span>
                    <?php if (!empty($a['active_session_started_at'])): ?>
                    <div class="small text-muted mt-1">
                        <i class="fas fa-clock me-1"></i>Od <?= formatDateTime($a['active_session_started_at']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($a['email']): ?>
                <p class="text-muted small mb-2">
                    <i class="fas fa-envelope me-1"></i><?= h($a['email']) ?>
                </p>
                <?php endif; ?>

                <?php if ($a['phone_contact']): ?>
                <p class="text-muted small mb-2">
                    <i class="fas fa-phone me-1"></i><?= h($a['phone_contact']) ?>
                </p>
                <?php endif; ?>

                <div class="mb-3">
                    <span class="badge bg-light text-dark border me-1">
                        <i class="fas fa-utensils me-1"></i>Jídelníčky: <?= (int)$a['active_meal_plan_count'] ?>
                    </span>
                    <?php if ($a['last_session_date']): ?>
                    <span class="badge bg-light text-dark border me-1">
                        <i class="fas fa-clock me-1"></i>Poslední trénink: <?= formatDate($a['last_session_date']) ?>
                    </span>
                    <?php if ($a['last_set_name']): ?>
                    <span class="badge bg-secondary">
                        <i class="fas fa-layer-group me-1"></i><?= h($a['last_set_name']) ?>
                    </span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="badge bg-light text-muted border">Žádný trénink</span>
                    <?php endif; ?>
                </div>

                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $a['id'] ?>"
                       class="btn btn-dark btn-sm flex-fill">
                        <i class="fas fa-user me-1"></i>Detail
                    </a>
                    <?php if ($a['active_session_id']): ?>
                    <a href="<?= $a['active_paired_session_id'] ? BASE_URL . '/training_paired_session.php?id=' . (int)$a['active_paired_session_id'] : BASE_URL . '/training_session.php?id=' . (int)$a['active_session_id'] ?>"
                       class="btn <?= $a['active_paired_session_id'] ? 'btn-info text-dark' : 'btn-warning' ?> btn-sm flex-fill fw-bold">
                        <i class="fas fa-play me-1"></i>Pokračovat
                    </a>
                    <?php else: ?>
                    <a href="<?= BASE_URL ?>/training_new.php?athlete_id=<?= $a['id'] ?>"
                       class="btn btn-warning btn-sm flex-fill">
                        <i class="fas fa-play me-1"></i>Trénink
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal fade" id="supportContributionModal" tabindex="-1" aria-labelledby="supportContributionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="supportContributionModalLabel"><i class="fas fa-heart me-2 text-warning"></i>Dobrovolná podpora provozu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">Jde jen o volitelnou podporu provozu aplikace. Aplikace zůstává zdarma a nic není potřeba platit.</p>
                <?php if ($supportBankAccountForQr === null): ?>
                <div class="alert alert-warning mb-3">Pro tento účet zatím není v administraci nastavené číslo účtu.</div>
                <?php else: ?>
                <div class="mb-3">
                    <label for="supportContributionAmount" class="form-label fw-semibold">Částka</label>
                    <input type="number" min="1" step="1" class="form-control form-control-lg" id="supportContributionAmount" placeholder="Např. 100">
                </div>
                <div class="border rounded-3 p-3 bg-light mb-3">
                    <img id="supportContributionQrImage" src="" alt="QR kód pro příspěvek" class="img-fluid border rounded p-2 bg-white d-none" style="max-width:220px;">
                    <div id="supportContributionQrEmpty" class="text-muted small">Zadejte částku a QR kód se zobrazí automaticky.</div>
                </div>
                <div class="small"><strong>Účet:</strong> <span id="supportContributionAccount"><?= h($supportBankAccount) ?></span></div>
                <div class="small"><strong>Odesílatel:</strong> <span id="supportContributionSender"><?= h($supportContributorName) ?></span></div>
                <div class="small"><strong>Poznámka:</strong> <span id="supportContributionNotePreview"><?= h($supportQrNote) ?></span></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer justify-content-between flex-wrap gap-2">
                <div class="small text-muted">Aplikace zůstává bezplatná. Příspěvek je pouze dobrovolná pomoc s provozem.</div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zavřít</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const supportBankAccount = <?= json_encode($supportBankAccountForQr, JSON_UNESCAPED_UNICODE) ?>;
    const supportContributorName = <?= json_encode($supportContributorName, JSON_UNESCAPED_UNICODE) ?>;
    const supportQrNote = <?= json_encode($supportQrNote, JSON_UNESCAPED_UNICODE) ?>;
    const amountInput = document.getElementById('supportContributionAmount');
    const qrImage = document.getElementById('supportContributionQrImage');
    const qrEmpty = document.getElementById('supportContributionQrEmpty');

    if (!amountInput || !qrImage || !qrEmpty || supportBankAccount === null) {
        return;
    }

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

    const updateQr = () => {
        const amount = parseFloat(String(amountInput.value || '').replace(',', '.'));
        if (!Number.isFinite(amount) || amount <= 0) {
            qrImage.classList.add('d-none');
            qrEmpty.classList.remove('d-none');
            qrImage.removeAttribute('src');
            return;
        }

        qrImage.src = buildQrUrl(amount);
        qrImage.classList.remove('d-none');
        qrEmpty.classList.add('d-none');
    };

    amountInput.addEventListener('input', updateQr);
    amountInput.addEventListener('change', updateQr);
})();
</script>

<?php if ($mustChangePassword): ?>
<style>
    .force-password-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 18, 25, 0.66);
        z-index: 2000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .force-password-card {
        width: 100%;
        max-width: 520px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.22);
        padding: 20px;
    }
</style>
<div class="force-password-overlay" role="dialog" aria-modal="true" aria-labelledby="forcePasswordChangeTitle">
    <div class="force-password-card">
        <h5 class="mb-3" id="forcePasswordChangeTitle"><i class="fas fa-key me-2"></i>Povinná změna hesla</h5>
        <p class="mb-3">Z bezpečnostních důvodů je při prvním přihlášení nutné změnit heslo.</p>
        <?php if ($forcePasswordError): ?>
        <div class="alert alert-danger py-2"><?= h($forcePasswordError) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="force_change_password">
            <div class="mb-3">
                <label class="form-label">Aktuální heslo</label>
                <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Nové heslo</label>
                <input type="password" name="new_password" class="form-control" autocomplete="new-password" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Potvrzení nového hesla</label>
                <input type="password" name="confirm_password" class="form-control" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-warning w-100 fw-bold">Změnit heslo a pokračovat</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
