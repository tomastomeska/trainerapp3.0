<?php
/**
 * @var bool $mustChangePassword
 * @var bool $mycoachAppIsLive
 * @var bool $coachSurveyPending
 * @var \PDO $pdo
 * @var \DateTimeImmutable $now
 * @var array<int, array<string, mixed>> $todayPlannedEvents
 * @var int $todayPendingCount
 * @var \Closure(array): string $formatCalendarEventPerson
 * @var int $tomorrowCount
 * @var array<string, mixed>|null $firstTomorrowEvent
 * @var array<string, array{label: string, sql: string}> $sortOptions
 * @var string|null $supportBankAccountForQr
 * @var string $supportBankAccount
 * @var string $supportContributorName
 * @var string $supportQrNote
 * @var array<int, int> $calculatorUnseenCounts
 */
require_once __DIR__ . '/dashboard_quick_menu.php';
require_once __DIR__ . '/info_channel_notification.php';
// Dashboard view; data and POST actions stay in dashboard.php.
renderHeader('Dashboard', false, true, true);
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

    .coach-today-plan-card .card-header {
        padding: .85rem 1rem;
        border-bottom: 0;
    }

    .coach-today-plan-grid {
        display: grid;
        gap: .65rem;
    }

    .coach-today-plan-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
    }

    .coach-today-plan-kicker {
        font-size: .68rem;
        font-weight: 800;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, .72);
    }

    .coach-today-plan-count {
        font-weight: 900;
        font-size: 1.15rem;
        line-height: 1.2;
    }

    .coach-today-plan-calendar-btn {
        white-space: nowrap;
        border-radius: .6rem;
    }

    .coach-today-plan-event {
        border-radius: 12px;
        padding: .75rem .85rem;
        border: 1px solid #e1e3e5;
        background: #f8f9fa;
        color: #212529;
        line-height: 1.35;
    }

    .coach-today-plan-event i {
        color: #495057;
    }

    .coach-today-plan-event strong {
        font-weight: 800;
    }

    .coach-today-plan-event--empty {
        color: #6c757d;
    }

    .coach-today-plan-list-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .45rem;
    }

    .coach-today-plan-list-toggle .fa-chevron-down {
        transition: transform .2s ease;
    }

    .coach-today-plan-list-toggle[aria-expanded="true"] .fa-chevron-down {
        transform: rotate(180deg);
    }

    .coach-today-events-list {
        display: grid;
        gap: .5rem;
        margin-top: .75rem;
        padding-top: .75rem;
        border-top: 1px solid #e1e3e5;
    }

    .coach-today-event {
        display: grid;
        grid-template-columns: 92px minmax(0, 1fr);
        align-items: start;
        gap: .65rem;
        padding: .7rem .75rem;
        border: 1px solid #e1e3e5;
        border-radius: 12px;
        background: #fff;
    }

    .coach-today-event__time {
        color: #343a40;
        font-weight: 800;
        white-space: nowrap;
    }

    .coach-today-event__details {
        min-width: 0;
    }

    .coach-today-event__name {
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .coach-today-event__meta {
        color: #6c757d;
        font-size: .875rem;
    }

    .coach-today-event__status {
        display: inline-block;
        margin-top: .3rem;
        padding: .12rem .45rem;
        border: 1px solid #dee2e6;
        border-radius: 999px;
        background: #f8f9fa;
        color: #495057;
        font-size: .72rem;
        font-weight: 700;
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

    .coach-dashboard-shortcut.is-disabled {
        background: #f8fafc;
        color: #64748b;
        border-style: dashed;
        pointer-events: none;
    }

    .coach-dashboard-shortcut.is-disabled .badge {
        opacity: .95;
    }

    .quick-tile.quick-tile-disabled {
        cursor: not-allowed;
        opacity: .9;
        pointer-events: none;
    }

    .athlete-card-mobile-link {
        display: none;
    }

    .athlete-card-mobile-link:focus-visible {
        outline: 3px solid #ffc107;
        outline-offset: 3px;
    }

    @media (max-width: 399.98px) {
        .athlete-card .card-body > :not(.athlete-card-mobile-link) {
            display: none !important;
        }

        .athlete-card .card-body {
            padding: .8rem 1rem;
        }

        .athlete-card-mobile-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
            color: #111827;
            font-weight: 700;
            text-decoration: none;
        }
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

        .coach-today-event {
            grid-template-columns: 84px minmax(0, 1fr);
            gap: .5rem;
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

<div class="card border-0 shadow-sm mb-3 coach-today-plan-card">
    <div class="card-header bg-dark text-white coach-today-plan-header">
        <div>
            <div class="coach-today-plan-kicker">Dnešní plán v kalendáři</div>
            <div class="coach-today-plan-count"><?= (int)$todayPendingCount ?> neproběhlých tréninků</div>
        </div>
        <a href="<?= BASE_URL ?>/calendar.php" class="btn btn-light btn-sm fw-semibold coach-today-plan-calendar-btn">
            <i class="fas fa-calendar-alt me-1"></i>Kalendář
        </a>
    </div>
    <div class="card-body py-3">
        <div class="coach-today-plan-grid">
            <?php if (!empty($ongoingTodayEvents)): ?>
                <?php $current = $ongoingTodayEvents[0]; ?>
                <div class="coach-today-plan-event fw-semibold">
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
                <div class="coach-today-plan-event coach-today-plan-event--empty fw-semibold">Aktuálně nic neprobíhá</div>
            <?php endif; ?>

            <?php if ($nextTodayEvent !== null && $minutesToNextTodayEvent !== null): ?>
            <div class="coach-today-plan-event text-start fw-semibold">
                <i class="fas fa-clock me-1"></i>
                Za <?= (int)$minutesToNextTodayEvent ?> min:
                <strong><?= h($formatCalendarEventPerson($nextTodayEvent)) ?></strong>
                <?php if (!empty($nextTodayEvent['location'])): ?>
                    · <?= h($nextTodayEvent['location']) ?>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="coach-today-plan-event coach-today-plan-event--empty fw-semibold">Dnes už další trénink nezačíná</div>
            <?php endif; ?>

            <div class="coach-today-plan-event text-start fw-semibold">
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

            <button class="btn btn-outline-dark btn-sm w-100 coach-today-plan-list-toggle"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#coach-today-events"
                    aria-expanded="false"
                    aria-controls="coach-today-events">
                <i class="fas fa-list-ul"></i>
                Dnešní události (<?= count($todayPlannedEvents) ?>)
                <i class="fas fa-chevron-down"></i>
            </button>

            <div class="collapse" id="coach-today-events">
                <div class="coach-today-events-list">
                    <?php if (!empty($todayPlannedEvents)): ?>
                        <?php foreach ($todayPlannedEvents as $event): ?>
                            <?php
                            $eventStart = new DateTimeImmutable($event['starts_at']);
                            $eventEnd = new DateTimeImmutable($event['ends_at']);
                            $eventStatus = $eventStart > $now
                                ? 'Nadcházející'
                                : ($eventEnd > $now ? 'Probíhá' : 'Proběhlo');
                            ?>
                            <article class="coach-today-event">
                                <time class="coach-today-event__time" datetime="<?= h((string)$event['starts_at']) ?>">
                                    <?= h($eventStart->format('H:i')) ?>–<?= h($eventEnd->format('H:i')) ?>
                                </time>
                                <div class="coach-today-event__details">
                                    <div class="coach-today-event__name"><?= h($formatCalendarEventPerson($event)) ?></div>
                                    <?php if (!empty($event['location'])): ?>
                                        <div class="coach-today-event__meta"><?= h($event['location']) ?></div>
                                    <?php endif; ?>
                                    <span class="coach-today-event__status"><?= h($eventStatus) ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="coach-today-plan-event coach-today-plan-event--empty">Na dnešek nejsou naplánované žádné události.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php ob_start(); ?>
<div class="dashboard-quick-tiles mb-3">
            <a data-shortcut="athletes" href="<?= BASE_URL ?>/dashboard.php" class="quick-tile quick-tile-muted">
                <span class="quick-tile__label"><i class="fas fa-house me-1"></i>Sportovci</span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
            <a data-shortcut="messages" href="<?= BASE_URL ?>/zpravy.php" class="quick-tile quick-tile-danger">
                <span class="quick-tile__label"><i class="fas fa-envelope me-1"></i>Zprávy</span>
                <span class="quick-tile__value"><?= (int)$unreadInboxCount ?></span>
            </a>
            <a data-shortcut="calendar" href="<?= BASE_URL ?>/calendar.php" class="quick-tile quick-tile-warning">
                <span class="quick-tile__label"><i class="fas fa-calendar-alt me-1"></i>Kalendář</span>
                <span class="quick-tile__value"><?= (int)$pendingCalendarRequestCount ?></span>
            </a>
            <a data-shortcut="info" href="<?= BASE_URL ?>/infokanal.php" class="quick-tile quick-tile-info">
                <span class="quick-tile__label"><i class="fas fa-lightbulb me-1"></i>Infokanál</span>
                <span class="quick-tile__value"><?= (int)$unreadInfoCount ?></span>
            </a>
            <a data-shortcut="exercises" href="<?= BASE_URL ?>/exercises.php" class="quick-tile quick-tile-success">
                <span class="quick-tile__label"><i class="fas fa-list me-1"></i>Cviky</span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
            <a data-shortcut="sets" href="<?= BASE_URL ?>/sady.php" class="quick-tile quick-tile-info">
                <span class="quick-tile__label"><i class="fas fa-layer-group me-1"></i>Sady</span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
            <a data-shortcut="payments" href="<?= BASE_URL ?>/payments.php" class="quick-tile quick-tile-muted">
                <span class="quick-tile__label"><i class="fas fa-wallet me-1"></i>Platby</span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
            <a data-shortcut="mealplans" href="<?= BASE_URL ?>/meal_plans.php" class="quick-tile quick-tile-success">
                <span class="quick-tile__label"><i class="fas fa-utensils me-1"></i>Jídelníčky</span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
            <a data-shortcut="gallery" href="<?= BASE_URL ?>/gallery.php" class="quick-tile quick-tile-info">
                <span class="quick-tile__label"><i class="fas fa-images me-1"></i>Galerie</span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
            <a data-shortcut="profile" href="<?= BASE_URL ?>/profile.php" class="quick-tile quick-tile-muted">
                <span class="quick-tile__label"><i class="fas fa-user-tie me-1"></i>Můj profil</span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
            <?php if ($coachSpecialTrainingEnabled): ?>
            <a data-shortcut="events" href="<?= BASE_URL ?>/special_training.php" class="quick-tile quick-tile-info">
                <span class="quick-tile__label"><i class="fas fa-flag-checkered me-1"></i>Events</span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
            <?php else: ?>
            <div data-shortcut="events" class="quick-tile quick-tile-muted quick-tile-disabled">
                <span class="quick-tile__label d-flex align-items-center flex-wrap gap-1"><i class="fas fa-lock me-1"></i>Events <span class="badge rounded-pill bg-secondary">Uzamčeno</span></span>
                <span class="quick-tile__value"><i class="fas fa-ban"></i></span>
            </div>
            <?php endif; ?>
            <a data-shortcut="online" href="<?= BASE_URL ?>/online_training.php" class="quick-tile quick-tile-warning">
                <span class="quick-tile__label"><i class="fas fa-laptop me-1"></i>Online tréninky</span>
                <span class="quick-tile__value"><?= (int)$onlineTrainingCount ?></span>
            </a>
            <a data-shortcut="calculators" href="<?= BASE_URL ?>/calculators.php" class="quick-tile quick-tile-muted">
                <span class="quick-tile__label d-flex align-items-center flex-wrap gap-1"><i class="fas fa-calculator me-1"></i>Kalkulačky <span class="badge rounded-pill bg-secondary">Ve vývoji</span></span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
            <?php if (mycoachAppEnabled() && $mycoachAppIsLive): ?>
            <a data-shortcut="mycoach" href="<?= BASE_URL ?>/mycoach_app.php" class="quick-tile quick-tile-warning">
            <?php else: ?>
            <a data-shortcut="mycoach" href="#" class="quick-tile quick-tile-warning" data-bs-toggle="modal" data-bs-target="#modalMyCoachComingSoon">
            <?php endif; ?>
                <span class="quick-tile__label d-flex align-items-center flex-wrap gap-1"><i class="fas fa-brain me-1"></i>MyCoach <span class="badge rounded-pill <?= $mycoachAppIsLive ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $mycoachAppIsLive ? 'Pro' : 'Brzy' ?></span></span>
                <span class="quick-tile__value"><i class="fas fa-<?= $mycoachAppIsLive ? 'star' : 'rocket' ?>"></i></span>
            </a>
            <a data-shortcut="surveys" href="<?= BASE_URL ?>/surveys.php" class="quick-tile quick-tile-info <?= $coachSurveyPending ? 'survey-pending' : '' ?>">
                <span class="quick-tile__label"><i class="fas fa-square-poll-vertical me-1"></i>Ankety</span>
                <span class="quick-tile__value"><?= $coachSurveyPending ? '<span class="badge rounded-pill bg-danger">!</span>' : '<i class="fas fa-chevron-right"></i>' ?></span>
            </a>
            <a data-shortcut="manual" href="<?= BASE_URL ?>/coach_manual.php" class="quick-tile quick-tile-success">
                <span class="quick-tile__label"><i class="fas fa-circle-question me-1"></i>Návod</span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
            <a data-shortcut="terms" href="<?= BASE_URL ?>/coach_terms.php" class="quick-tile quick-tile-warning">
                <span class="quick-tile__label"><i class="fas fa-file-contract me-1"></i>Podmínky</span>
                <span class="quick-tile__value"><i class="fas fa-chevron-right"></i></span>
            </a>
</div>
<?php renderDashboardQuickMenu('coach', (int)$coachId, ob_get_clean()); ?>

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
            <label for="athleteMode" class="small text-muted">Typ</label>
            <select id="athleteMode" name="mode" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?= $selectedMode === 'all' ? 'selected' : '' ?>>Všichni</option><option value="online" <?= $selectedMode === 'online' ? 'selected' : '' ?>>Pouze ONLINE</option><option value="standard" <?= $selectedMode === 'standard' ? 'selected' : '' ?>>Ostatní</option>
            </select>
        </form>
    </div>
    <div class="coach-dashboard-toolbar__actions">
        <?php if ($pairedAthleteCount >= 2): ?>
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
                <?php $healthInfo = coachDashboardHealthInfo($session); ?>
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
                        <div class="small text-<?= h((string)$healthInfo['variant']) ?> fw-semibold">
                            <i class="fas fa-heart-pulse me-1"></i><?= h((string)$healthInfo['text']) ?>
                        </div>
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
                        <div class="small mt-1">
                            <?php foreach ($pair['sessions'] as $session): ?>
                                <?php $healthInfo = coachDashboardHealthInfo($session); ?>
                                <div class="text-<?= h((string)$healthInfo['variant']) ?> fw-semibold">
                                    <i class="fas fa-heart-pulse me-1"></i><?= h($session['first_name'] . ' ' . $session['last_name']) ?>: <?= h((string)$healthInfo['text']) ?>
                                </div>
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
        <h4 class="text-muted"><?= $selectedMode === 'all' ? 'Zatím nemáte žádné sportovce' : 'Tomuto filtru neodpovídá žádný sportovec' ?></h4>
        <p class="text-muted"><?= $selectedMode === 'all' ? 'Přidejte prvního sportovce a začněte trénovat!' : 'Změňte výběr typu sportovce.' ?></p>
        <a href="<?= BASE_URL ?>/athlete_add.php" class="btn btn-warning fw-bold">
            <i class="fas fa-plus me-1"></i>Přidat sportovce
        </a>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($athletes as $a): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card athlete-card<?= !empty($a['online_only']) ? ' athlete-card--online' : '' ?> border-0 shadow-sm h-100">
            <div class="card-body">
                <a class="athlete-card-mobile-link" href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$a['id'] ?>">
                    <span><?= h($a['first_name'] . ' ' . $a['last_name']) ?></span>
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                </a>
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
                        <?php if (!empty($a['online_only'])): ?><span class="badge bg-dark">Pouze ONLINE</span><?php endif; ?>
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
                    <?php $healthInfo = coachDashboardHealthInfo($a); ?>
                    <span class="badge bg-<?= h((string)$healthInfo['variant']) ?><?= (string)$healthInfo['variant'] === 'warning' ? ' text-dark' : '' ?> me-1">
                        <i class="fas fa-heart-pulse me-1"></i>
                        <?= ($a['health_status_flag'] ?? '') === '' ? 'Dotazník chybí' : ((string)$healthInfo['variant'] === 'success' ? 'Dotazník OK' : 'Dotazník: omezení') ?>
                    </span>
                    <?php if (!empty($calculatorUnseenCounts[(int)$a['id']])): ?>
                    <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= (int)$a['id'] ?>#calculator-results" class="badge bg-danger text-decoration-none me-1">
                        <i class="fas fa-calculator me-1"></i>Kalkulačky: <?= (int)$calculatorUnseenCounts[(int)$a['id']] ?> nové
                    </a>
                    <?php endif; ?>
                    <?php if ($coachMyCoachEnabled): ?>
                        <?php $myCoachMeta = $athleteMyCoachMetaById[(int)$a['id']] ?? null; ?>
                        <?php if ($myCoachMeta && (int)($myCoachMeta['active_plan_count'] ?? 0) > 0): ?>
                        <span class="badge bg-success me-1">
                            <i class="fas fa-brain me-1"></i>MyCoach plán běží
                        </span>
                        <?php elseif ($myCoachMeta && !empty($myCoachMeta['mycoach_enabled'])): ?>
                        <span class="badge bg-info text-dark me-1">
                            <i class="fas fa-brain me-1"></i>MyCoach aktivní
                        </span>
                        <?php endif; ?>
                    <?php endif; ?>
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

                <form method="post" class="form-check form-switch mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="set_athlete_payment_access">
                    <input type="hidden" name="athlete_id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="payment_service_disabled" value="0">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        id="athletePayments<?= (int)$a['id'] ?>"
                        name="payment_service_enabled"
                        value="1"
                        <?= (int)$a['payment_service_enabled'] === 1 ? 'checked' : '' ?>
                        onchange="this.form.submit()"
                    >
                    <label class="form-check-label small fw-semibold" for="athletePayments<?= (int)$a['id'] ?>">
                        Platby <?= (int)$a['payment_service_enabled'] === 1 ? 'zapnuto' : 'vypnuto' ?>
                    </label>
                    <noscript><button type="submit" class="btn btn-outline-secondary btn-sm mt-1">Uložit</button></noscript>
                </form>

                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>/athlete_detail.php?id=<?= $a['id'] ?>"
                       class="btn btn-dark btn-sm flex-fill">
                        <i class="fas fa-user me-1"></i>Detail
                    </a>
                    <a href="<?= BASE_URL ?>/athlete_chat.php?id=<?= $a['id'] ?>"
                       class="btn btn-outline-success btn-sm flex-fill">
                        <i class="fas fa-comments me-1"></i>Chat
                    </a>
                    <a href="<?= BASE_URL ?>/food_diary.php?athlete_id=<?= $a['id'] ?>"
                              class="btn btn-outline-primary btn-sm flex-fill">
                        <i class="fas fa-bowl-food me-1"></i>Strava
                    </a>
                    <?php if ($a['active_session_id']): ?>
                    <a href="<?= $a['active_paired_session_id'] ? BASE_URL . '/training_paired_session.php?id=' . (int)$a['active_paired_session_id'] : BASE_URL . '/training_session.php?id=' . (int)$a['active_session_id'] ?>"
                       class="btn <?= $a['active_paired_session_id'] ? 'btn-info text-dark' : 'btn-warning' ?> btn-sm flex-fill fw-bold">
                        <i class="fas fa-play me-1"></i>Pokračovat
                    </a>
                    <?php elseif (empty($a['online_only'])): ?>
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
        pointer-events: auto;
    }
    .force-password-card {
        position: relative;
        z-index: 2001;
        width: 100%;
        max-width: 520px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.22);
        padding: 20px;
        pointer-events: auto;
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

<?php if (!$mustChangePassword): ?>
    <?php renderUnreadInfoChannelNotification($pdo, 'coach', (int)$coachId); ?>
<?php endif; ?>
<?php renderFooter(); ?>
