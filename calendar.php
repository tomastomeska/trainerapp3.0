<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

$coachId = (int)getCurrentCoachId();
$pdo = getDB();

$athleteStmt = $pdo->prepare(
    'SELECT id, first_name, last_name
     FROM athletes
     WHERE coach_id = ?
    ORDER BY first_name, last_name'
);
$athleteStmt->execute([$coachId]);
$athletes = $athleteStmt->fetchAll();

$venues = array_values(array_filter(getTrainingVenuesForCoach($coachId), fn($row) => !empty($row['name'])));

renderHeader('Kalendář', false, true);
?>

<style>
    .calendar-top-actions {
        display: flex;
        gap: .5rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    @media (max-width: 767.98px) {
        .calendar-top-actions {
            justify-content: flex-start;
        }
    }
</style>

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

.slot-event {
    display: block;
    width: 100%;
    text-align: left;
    border: 0;
    border-radius: 10px;
    padding: .35rem .45rem;
    background: #0ea5e9;
    color: #fff;
    line-height: 1.15;
    font-size: .78rem;
    font-weight: 700;
}

.slot-event.pending {
    background: #f97316;
    color: #ffffff;
    border: 2px solid #fff7ed;
    animation: pendingPulse .72s ease-in-out infinite alternate;
}

.slot-event.updated {
    box-shadow: inset 0 0 0 2px rgba(255,255,255,.45);
}

.slot-event.paired {
    padding-top: .25rem;
}

.slot-event .paired-names {
    display: grid;
    grid-template-columns: 1fr 1fr;
    column-gap: .35rem;
    margin-bottom: .2rem;
}

.slot-event .paired-names .name-col {
    font-size: .68rem;
    line-height: 1.08;
    font-weight: 700;
    word-break: break-word;
}

.slot-event .paired-names .name-col:last-child {
    text-align: right;
}

#daypilotCalendar .coach-calendar-pending {
    animation: pendingPulse .72s ease-in-out infinite alternate;
}

@keyframes pendingPulse {
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
    .slot-event.pending,
    #daypilotCalendar .coach-calendar-pending {
        animation: none;
    }
}

.slot-event .time {
    font-weight: 600;
    font-size: .75rem;
}

.slot-event .where {
    display: block;
    font-size: .68rem;
    opacity: .95;
    margin-top: 2px;
    font-weight: 500;
}

.slot-add-hint {
    color: #9ca3af;
    font-size: .72rem;
    margin-top: .1rem;
    text-align: center;
}

.lock-chip {
    display: inline-block;
    border-radius: 999px;
    padding: .08rem .45rem;
    font-size: .67rem;
    font-weight: 700;
    background: #374151;
    color: #fff;
}

.lock-list-item {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: .55rem .6rem;
}

.request-banner {
    border-radius: 10px;
    padding: .65rem .8rem;
    background: #fff7ed;
    border: 1px solid #fdba74;
    color: #9a3412;
    font-size: .9rem;
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

    .calendar-grid .day-name {
        display: block;
        font-size: .65rem;
        line-height: 1;
    }

    .calendar-grid .day-date {
        display: block;
        font-size: .68rem;
    }

    .slot-event {
        font-size: .62rem;
        padding: .22rem .3rem;
        border-radius: 8px;
    }

    .slot-event .where {
        font-size: .56rem;
        margin-top: 1px;
    }

    .slot-add-hint {
        font-size: .58rem;
    }

    #daypilotCard .card-body {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    #daypilotCalendar {
        min-width: 860px;
    }

    #legacyCalendarGridCard {
        display: none;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-calendar-alt me-2 text-warning"></i>Kalendář trenéra</h2>
    <div class="calendar-top-actions">
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <button class="btn btn-outline-secondary btn-sm" id="prevWeekBtn">
            <i class="fas fa-chevron-left me-1"></i>Předchozí týden
        </button>
        <button class="btn btn-outline-dark btn-sm" id="todayWeekBtn">
            Tento týden
        </button>
        <button class="btn btn-outline-secondary btn-sm" id="nextWeekBtn">
            Další týden<i class="fas fa-chevron-right ms-1"></i>
        </button>
        <button class="btn btn-warning btn-sm fw-bold" id="quickAddBtn">
            <i class="fas fa-plus me-1"></i>Přidat trénink
        </button>
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="calendarViewTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="week-view-tab" data-bs-toggle="tab" data-bs-target="#week-view-pane" type="button" role="tab" aria-controls="week-view-pane" aria-selected="true">
            Týdenní kalendář
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="month-list-tab" data-bs-toggle="tab" data-bs-target="#month-list-pane" type="button" role="tab" aria-controls="month-list-pane" aria-selected="false">
            Měsíční seznam
        </button>
    </li>
</ul>

<div class="tab-content">
<div class="tab-pane fade show active" id="week-view-pane" role="tabpanel" aria-labelledby="week-view-tab" tabindex="0">

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <div class="fw-bold" id="weekRangeLabel">Načítám týden…</div>
            <div class="text-muted small">Klikněte do slotu pro přidání tréninku nebo uzamčení času.</div>
        </div>
        <div class="d-flex align-items-center gap-2 small flex-wrap">
            <span class="badge" style="background:#22c55e;color:#fff">Trénink (1 sportovec)</span>
            <span class="badge" style="background:#0ea5e9;color:#fff">Párový trénink (2 sportovci)</span>
            <span class="badge" style="background:#f97316;color:#fff">Ke schválení</span>
            <span class="lock-chip">Uzamčeno</span>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3" id="daypilotCard">
    <div class="card-body p-2 p-md-3">
        <div class="small text-muted mb-2 d-lg-none">
            <i class="fas fa-hand-point-right me-1"></i>Kalendář na mobilu posunete vodorovně tahem.
        </div>
        <div id="daypilotCalendar"></div>
    </div>
</div>

<div class="card border-0 shadow-sm" id="legacyCalendarGridCard">
    <div class="card-body p-2 p-md-3">
        <div class="calendar-shell">
            <table class="calendar-grid" id="calendarGrid" aria-label="Týdenní kalendář"></table>
        </div>
    </div>
</div>

</div>

<div class="tab-pane fade" id="month-list-pane" role="tabpanel" aria-labelledby="month-list-tab" tabindex="0">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="mb-0"><i class="fas fa-list me-2 text-warning"></i>Události v měsíci</h5>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="monthListPrevBtn"><i class="fas fa-chevron-left"></i></button>
                    <input type="month" class="form-control form-control-sm" id="monthListMonth" style="max-width: 180px;">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="monthListNextBtn"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Čas</th>
                            <th>Sportovec</th>
                            <th>Typ události</th>
                            <th>Místo</th>
                            <th>Stav</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody id="monthListBody">
                        <tr><td colspan="7" class="text-muted">Načítám data...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="text-muted small mt-2 d-none" id="monthListEmpty">V tomto měsíci nejsou žádné události.</div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="eventForm">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="eventModalTitle"><i class="fas fa-calendar-plus me-2 text-warning"></i>Trénink</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="eventId" value="">
                    <input type="hidden" id="lockId" value="">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="eventIsLock">
                        <label class="form-check-label fw-semibold" for="eventIsLock">Uzamknout časy (místo tréninku)</label>
                    </div>

                    <div id="eventTrainingFields">

                    <div id="eventAthleteFields">
                    <div class="mb-3">
                        <label for="eventAthlete" class="form-label fw-semibold">Sportovec</label>
                        <select id="eventAthlete" class="form-select">
                            <option value="">-- Bez sportovce --</option>
                            <?php foreach ($athletes as $a): ?>
                            <option value="<?= (int)$a['id'] ?>">
                                <?= h($a['last_name'] . ' ' . $a['first_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Vyberte svého sportovce, nebo níže napište vlastní název.</div>
                    </div>

                    <div class="mb-3">
                        <label for="eventSecondAthlete" class="form-label fw-semibold">Druhý sportovec (párový trénink)</label>
                        <select id="eventSecondAthlete" class="form-select">
                            <option value="">-- Bez druhého sportovce --</option>
                            <?php foreach ($athletes as $a): ?>
                            <option value="<?= (int)$a['id'] ?>">
                                <?= h($a['last_name'] . ' ' . $a['first_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Vyberte druhého účastníka pro párovou hodinu.</div>
                    </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold d-block">Typ události</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="eventTitleType" id="eventTitleTraining" value="training" checked>
                                <label class="form-check-label" for="eventTitleTraining">Trénink</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="eventTitleType" id="eventTitleConsultation" value="consultation">
                                <label class="form-check-label" for="eventTitleConsultation">Konzultační hodina</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="eventTitleType" id="eventTitleOther" value="other">
                                <label class="form-check-label" for="eventTitleOther">Jiné</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="eventTitleType" id="eventTitleGroupLesson" value="group_lesson">
                                <label class="form-check-label" for="eventTitleGroupLesson">Skupinová lekce</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="eventCustomTitle" class="form-label fw-semibold">Název vlastní</label>
                        <input type="text" id="eventCustomTitle" class="form-control" maxlength="140" placeholder="Např. Konzultace / regenerace / soukromý trénink">
                        <div class="form-text">Vlastní název je volitelný a přepíše zvolený typ události.</div>
                    </div>

                    <div class="mb-3">
                        <label for="eventLocationMode" class="form-label fw-semibold">Místo konání</label>
                        <div class="input-group">
                            <select id="eventLocationMode" class="form-select" style="flex: 0 0 140px">
                                <option value="custom">Napsat sám</option>
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
                            <input type="text" id="eventLocation" class="form-control" maxlength="255" placeholder="Např. Stadion, fitko, hala, venku...">
                        </div>
                        <div class="form-text">Vyberte existující místo ze sportovišť, nebo zadejte vlastní.</div>
                        <div class="small text-muted mt-1" id="eventLocationHint"></div>
                    </div>

                    <div class="mb-3">
                        <label for="eventColor" class="form-label fw-semibold">Barva události</label>
                        <select id="eventColor" class="form-select">
                            <option value="blue">Modrá</option>
                            <option value="green" selected>Zelená</option>
                            <option value="red">Červená</option>
                            <option value="orange">Oranžová</option>
                            <option value="teal">Tyrkysová</option>
                            <option value="yellow">Žlutá</option>
                            <option value="purple">Fialová</option>
                            <option value="gray">Šedá</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label for="eventDate" class="form-label fw-semibold">Čas začátku</label>
                        <div class="row g-2">
                            <div class="col-md-7">
                                <input type="date" id="eventDate" class="form-control" required>
                            </div>
                            <div class="col-3 col-md-3">
                                <select id="eventHour" class="form-select" required></select>
                            </div>
                            <div class="col-3 col-md-2">
                                <select id="eventMinute" class="form-select" required>
                                    <option value="00">00</option>
                                    <option value="15">15</option>
                                    <option value="30">30</option>
                                    <option value="45">45</option>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" id="eventStart" value="">
                    </div>
                    <div class="small text-muted">Délka je vždy pevně 60 minut.</div>

                    <div class="mt-3">
                        <label for="eventRepeatMode" class="form-label fw-semibold">Opakování</label>
                        <select id="eventRepeatMode" class="form-select">
                            <option value="none">Neopakovat</option>
                            <option value="weekly_until_date">Každý týden do data</option>
                            <option value="weekly_end_of_next_month">Každý týden do konce příštího měsíce</option>
                            <option value="weekly_end_of_year">Každý týden do konce roku</option>
                        </select>
                    </div>

                    <div class="mt-2 d-none" id="eventRepeatUntilWrap">
                        <label for="eventRepeatUntil" class="form-label fw-semibold">Opakovat do</label>
                        <input type="date" id="eventRepeatUntil" class="form-control">
                    </div>

                    <div class="small text-muted mt-1" id="eventRepeatHint"></div>
                    <div class="small text-muted mt-1">
                        U jednorázové události lze opakování doplnit i dodatečně. Vytvoří se navazující termíny od tohoto data dál.
                    </div>

                    <div class="mt-3">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="eventIsMakeup">
                            <label class="form-check-label fw-semibold" for="eventIsMakeup">Náhradní termín</label>
                        </div>
                        <div class="small text-muted">Při označení jako náhrada se hrazený měsíc určí automaticky.</div>
                    </div>

                    <div class="alert alert-warning mt-3 mb-0 d-none" id="eventMakeupSuggestion">
                        <div class="fw-semibold mb-1"><i class="fas fa-circle-exclamation me-1"></i>Možná náhrada z dříve uhrazených tréninků</div>
                        <div class="small" id="eventMakeupSuggestionText"></div>
                        <button type="button" class="btn btn-sm btn-outline-dark mt-2" id="eventUseMakeupBtn">
                            <i class="fas fa-check me-1"></i>Označit tento termín jako náhradu
                        </button>
                    </div>

                    </div>

                    <div id="lockFields" class="d-none">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="lockUnlockMode">
                            <label class="form-check-label fw-semibold" for="lockUnlockMode">Odemknout vybraný interval</label>
                        </div>

                        <div class="mb-3">
                            <label for="lockNoteInline" class="form-label fw-semibold">Poznámka (volitelně)</label>
                            <input type="text" id="lockNoteInline" class="form-control" maxlength="255" placeholder="Např. Mimo práci / dovolená / administrativa">
                        </div>

                        <div class="mb-3">
                            <label for="lockStartDate" class="form-label fw-semibold">Od</label>
                            <div class="row g-2">
                                <div class="col-6 col-sm-7">
                                    <input type="date" id="lockStartDate" class="form-control">
                                </div>
                                <div class="col-3 col-sm-3">
                                    <select id="lockStartHour" class="form-select"></select>
                                </div>
                                <div class="col-3 col-sm-2">
                                    <select id="lockStartMinute" class="form-select">
                                        <option value="00">00</option>
                                        <option value="15">15</option>
                                        <option value="30">30</option>
                                        <option value="45">45</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label for="lockEndDate" class="form-label fw-semibold">Do</label>
                            <div class="row g-2">
                                <div class="col-6 col-sm-7">
                                    <input type="date" id="lockEndDate" class="form-control">
                                </div>
                                <div class="col-3 col-sm-3">
                                    <select id="lockEndHour" class="form-select"></select>
                                </div>
                                <div class="col-3 col-sm-2">
                                    <select id="lockEndMinute" class="form-select">
                                        <option value="00">00</option>
                                        <option value="15">15</option>
                                        <option value="30">30</option>
                                        <option value="45">45</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="lockRangeStart" value="">
                        <input type="hidden" id="lockRangeEnd" value="">

                        <div class="mt-3">
                            <label for="lockRepeatMode" class="form-label fw-semibold">Opakování uzamčení</label>
                            <select id="lockRepeatMode" class="form-select">
                                <option value="none">Neopakovat</option>
                                <option value="weekly_until_date">Každý týden do data</option>
                                <option value="weekly_end_of_next_month">Každý týden do konce příštího měsíce</option>
                                <option value="weekly_end_of_year">Každý týden do konce roku</option>
                            </select>
                        </div>

                        <div class="mt-2 d-none" id="lockRepeatUntilWrap">
                            <label for="lockRepeatUntil" class="form-label fw-semibold">Uzamykat do</label>
                            <input type="date" id="lockRepeatUntil" class="form-control">
                        </div>

                        <div class="small text-muted mt-1" id="lockRepeatHint"></div>
                    </div>

                    <div id="eventError" class="alert alert-danger py-2 px-3 mt-3 mb-0 d-none"></div>
                    <div id="paymentInfo" class="alert alert-success py-2 px-3 mt-3 mb-0 d-none"></div>
                    <div id="requestInfo" class="request-banner mt-3 d-none"></div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-danger me-auto d-none" id="deleteEventBtn">
                        <i class="fas fa-trash me-1"></i>Smazat
                    </button>
                    <button type="button" class="btn btn-success d-none" id="approveEventBtn">
                        <i class="fas fa-check me-1"></i>Schválit
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="fas fa-save me-1"></i>Uložit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://unpkg.com/@daypilot/daypilot-lite-javascript@5.6.0/daypilot-javascript.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = <?= json_encode(csrfToken(), JSON_UNESCAPED_UNICODE) ?>;
    const calendarGrid = document.getElementById('calendarGrid');
    const weekRangeLabel = document.getElementById('weekRangeLabel');

    const eventModalEl = document.getElementById('eventModal');
    const eventModal = new bootstrap.Modal(eventModalEl);
    const eventModalTitle = document.getElementById('eventModalTitle');

    const eventForm = document.getElementById('eventForm');
    const eventIdInput = document.getElementById('eventId');
    const lockIdInput = document.getElementById('lockId');
    const eventIsLockInput = document.getElementById('eventIsLock');
    const eventTrainingFields = document.getElementById('eventTrainingFields');
    const lockFields = document.getElementById('lockFields');
    const eventAthleteInput = document.getElementById('eventAthlete');
    const eventSecondAthleteInput = document.getElementById('eventSecondAthlete');
    const eventAthleteFields = document.getElementById('eventAthleteFields');
    const eventCustomTitleInput = document.getElementById('eventCustomTitle');
    const eventLocationModeInput = document.getElementById('eventLocationMode');
    const eventLocationInput = document.getElementById('eventLocation');
    const eventLocationHint = document.getElementById('eventLocationHint');
    const eventColorInput = document.getElementById('eventColor');
    const eventDateInput = document.getElementById('eventDate');
    const eventHourInput = document.getElementById('eventHour');
    const eventMinuteInput = document.getElementById('eventMinute');
    const eventStartInput = document.getElementById('eventStart');
    const eventRepeatModeInput = document.getElementById('eventRepeatMode');
    const eventRepeatUntilWrap = document.getElementById('eventRepeatUntilWrap');
    const eventRepeatUntilInput = document.getElementById('eventRepeatUntil');
    const eventRepeatHint = document.getElementById('eventRepeatHint');
    const eventIsMakeupInput = document.getElementById('eventIsMakeup');
    const eventMakeupSuggestion = document.getElementById('eventMakeupSuggestion');
    const eventMakeupSuggestionText = document.getElementById('eventMakeupSuggestionText');
    const eventUseMakeupBtn = document.getElementById('eventUseMakeupBtn');
    const lockUnlockModeInput = document.getElementById('lockUnlockMode');
    const lockNoteInlineInput = document.getElementById('lockNoteInline');
    const lockStartDateInput = document.getElementById('lockStartDate');
    const lockStartHourInput = document.getElementById('lockStartHour');
    const lockStartMinuteInput = document.getElementById('lockStartMinute');
    const lockEndDateInput = document.getElementById('lockEndDate');
    const lockEndHourInput = document.getElementById('lockEndHour');
    const lockEndMinuteInput = document.getElementById('lockEndMinute');
    const lockRangeStartInput = document.getElementById('lockRangeStart');
    const lockRangeEndInput = document.getElementById('lockRangeEnd');
    const lockRepeatModeInput = document.getElementById('lockRepeatMode');
    const lockRepeatUntilWrap = document.getElementById('lockRepeatUntilWrap');
    const lockRepeatUntilInput = document.getElementById('lockRepeatUntil');
    const lockRepeatHint = document.getElementById('lockRepeatHint');
    const eventError = document.getElementById('eventError');
    const paymentInfo = document.getElementById('paymentInfo');
    const requestInfo = document.getElementById('requestInfo');
    const deleteEventBtn = document.getElementById('deleteEventBtn');
    const approveEventBtn = document.getElementById('approveEventBtn');
    const daypilotCalendarEl = document.getElementById('daypilotCalendar');
    const daypilotCard = document.getElementById('daypilotCard');
    const monthListMonthInput = document.getElementById('monthListMonth');
    const monthListPrevBtn = document.getElementById('monthListPrevBtn');
    const monthListNextBtn = document.getElementById('monthListNextBtn');
    const monthListBody = document.getElementById('monthListBody');
    const monthListEmpty = document.getElementById('monthListEmpty');

    const czechDayShort = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];
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
    const titleTypeLabels = {
        training: 'Trénink',
        consultation: 'Konzultační hodina',
        other: 'Jiné',
        group_lesson: 'Skupinová lekce',
    };

    let currentWeekStart = getMonday(new Date());
    let events = [];
    let locks = [];
    let dayPilotCalendar = null;
    let activeEvent = null;
    let currentMakeupSuggestion = null;
    const makeupSuggestionCache = new Map();

    function normalizeColorKey(colorKey) {
        if (typeof colorKey !== 'string') {
            return 'green';
        }
        return Object.prototype.hasOwnProperty.call(eventColorSchemes, colorKey) ? colorKey : 'green';
    }

    function getEventColorScheme(event) {
        if ((event.approval_status || 'approved') === 'pending') {
            return { backColor: '#f97316', barColor: '#ea580c', fontColor: '#ffffff' };
        }

        const isPairedTraining = Number(event.athlete_id || 0) > 0 && Number(event.second_athlete_id || 0) > 0;
        const isSingleAthleteTraining = Number(event.athlete_id || 0) > 0 && Number(event.second_athlete_id || 0) === 0;

        if (isPairedTraining) {
            return eventColorSchemes.blue;
        }

        if (isSingleAthleteTraining) {
            return eventColorSchemes.green;
        }

        return eventColorSchemes[normalizeColorKey(event.color_key)];
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

    function toMonthKey(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        return `${y}-${m}`;
    }

    function toDateTimeInputValue(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        const h = String(date.getHours()).padStart(2, '0');
        const min = String(date.getMinutes()).padStart(2, '0');
        return `${y}-${m}-${d}T${h}:${min}`;
    }

    function toDateTimeSecondsValue(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        const h = String(date.getHours()).padStart(2, '0');
        const min = String(date.getMinutes()).padStart(2, '0');
        return `${y}-${m}-${d}T${h}:${min}:00`;
    }

    function dayPilotDateToJs(value) {
        if (!value) {
            return null;
        }
        const raw = typeof value.toString === 'function' ? value.toString() : String(value);
        return new Date(raw.replace(' ', 'T'));
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function isRangeLocked(start, end) {
        return locks.some((lock) => {
            const lockStart = fromSqlDateTime(lock.starts_at);
            const lockEnd = fromSqlDateTime(lock.ends_at);
            return lockStart < end && lockEnd > start;
        });
    }

    function toDayPilotEvent(event) {
        const startDate = fromSqlDateTime(event.starts_at);
        const endDate = fromSqlDateTime(event.ends_at);
        const title = getEventTitle(event);
        const timeLabel = `${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}`;
        const athleteLabel = getEventAthletesLabel(event);
        const athleteNames = athleteLabel ? athleteLabel.split(' + ') : [];
        const isPairedTraining = athleteNames.length === 2;
        const place = event.location ? `\nMísto: ${event.location}` : '';
        const time = `\nČas: ${formatTimeCs(startDate)} - ${formatTimeCs(endDate)}`;
        const color = getEventColorScheme(event);
        const statusMeta = getEventStatusMeta(event);
        const athleteInfo = athleteLabel && title !== athleteLabel ? `\nSportovci: ${athleteLabel}` : '';
        const statusLine = statusMeta.label ? `\nStav: ${statusMeta.label}` : '';
        const paymentPaid = String(event.payment_status || '') === 'paid';
        const paymentLabel = paymentPaid ? 'Uhrazeno' : '';
        const detailLine = [timeLabel, event.location || '', statusMeta.label, paymentLabel].filter(Boolean).join(' | ');
        const badgeHtml = [
            statusMeta.label ? `<span class="badge text-bg-warning text-dark me-1">${escapeHtml(statusMeta.label)}</span>` : '',
            paymentPaid ? '<span class="badge bg-success">Uhrazeno</span>' : '',
        ].filter(Boolean).join(' ');

        return {
            id: String(event.id),
            text: isPairedTraining
                ? [event.location || '', timeLabel, statusMeta.label, paymentLabel].filter(Boolean).join('\n')
                : [title, detailLine].filter(Boolean).join('\n'),
            html: isPairedTraining
                ? `<div style="display:grid;grid-template-columns:1fr 1fr;column-gap:8px;font-weight:700;line-height:1.1;font-size:12px;margin-bottom:4px;"><div>${escapeHtml(athleteNames[0])}</div><div style="text-align:right;">${escapeHtml(athleteNames[1])}</div></div><div style="text-align:center;font-weight:600;line-height:1.2;">${escapeHtml(event.location || '')}</div><div style="text-align:center;font-weight:600;line-height:1.2;">${escapeHtml(timeLabel)}</div><div style="text-align:center;line-height:1.1;margin-top:3px;">${badgeHtml}</div>`
                : `<div style="font-weight:700;line-height:1.15;font-size:12px;">${escapeHtml(title)}</div><div style="font-size:11px;line-height:1.15;margin-top:2px;">${escapeHtml(event.location || '')}</div><div style="font-size:11px;line-height:1.15;margin-top:2px;">${escapeHtml(timeLabel)}</div><div style="margin-top:3px;">${badgeHtml}</div>`,
            toolTip: `${title}${time}${place}${athleteInfo}${statusLine}${paymentLabel ? `\nStav úhrady: ${paymentLabel}` : ''}`,
            start: toDateTimeSecondsValue(startDate),
            end: toDateTimeSecondsValue(endDate),
            backColor: color.backColor,
            barColor: color.barColor,
            fontColor: color.fontColor,
            cssClass: [
                statusMeta.className === 'pending' ? 'coach-calendar-pending' : '',
                isPairedTraining ? 'coach-calendar-paired' : '',
            ].filter(Boolean).join(' '),
        };
    }

    function getSelectedEventTitleType() {
        return document.querySelector('input[name="eventTitleType"]:checked')?.value || 'training';
    }

    function setSelectedEventTitleType(type) {
        const target = document.querySelector(`input[name="eventTitleType"][value="${type}"]`) || document.querySelector('input[name="eventTitleType"][value="training"]');
        if (target) {
            target.checked = true;
        }
    }

    function inferTitleTypeFromEvent(event) {
        const normalizedTitle = String(event?.custom_title || '').trim().toLowerCase();
        if (normalizedTitle === titleTypeLabels.consultation.toLowerCase()) {
            return 'consultation';
        }
        if (normalizedTitle === titleTypeLabels.other.toLowerCase()) {
            return 'other';
        }
        if (
            normalizedTitle === titleTypeLabels.group_lesson.toLowerCase()
            || (Number(event?.athlete_id || 0) === 0 && Number(event?.second_athlete_id || 0) === 0 && normalizedTitle !== '')
        ) {
            return 'group_lesson';
        }
        return 'training';
    }

    function updateEventLocationHint() {
        const selectedOption = eventLocationModeInput.options[eventLocationModeInput.selectedIndex] || null;

        if (!selectedOption || eventLocationModeInput.value === 'custom') {
            eventLocationHint.textContent = 'Můžete napsat vlastní místo nebo vybrat ze sportovišť.';
            return;
        }

        const address = selectedOption.dataset.address || '';
        const note = selectedOption.dataset.note || '';
        const parts = [];

        if (address) parts.push(address);
        if (note) parts.push(note);

        eventLocationHint.textContent = parts.length ? parts.join(' • ') : 'Místo je načtené ze sportovišť.';
    }

    function syncSecondAthleteOptions() {
        const primaryValue = eventAthleteInput.value;
        Array.from(eventSecondAthleteInput.options).forEach((option) => {
            if (!option.value) {
                option.disabled = false;
                return;
            }
            option.disabled = option.value === primaryValue;
        });

        if (eventSecondAthleteInput.value && eventSecondAthleteInput.value === primaryValue) {
            eventSecondAthleteInput.value = '';
        }
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
        };
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

    function normalizeEventStartInputToQuarterHour() {
        if (!eventStartInput.value) {
            return;
        }

        const parsed = new Date(eventStartInput.value);
        if (Number.isNaN(parsed.getTime())) {
            return;
        }

        eventStartInput.value = toDateTimeInputValue(snapDateToQuarterHour(parsed));
    }

    function populateEventHourOptions() {
        eventHourInput.innerHTML = '';
        for (let hour = hourStart; hour < hourEnd; hour++) {
            const value = String(hour).padStart(2, '0');
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            eventHourInput.appendChild(option);
        }
    }

    function populateLockHourOptions(targetSelect) {
        targetSelect.innerHTML = '';
        for (let hour = 0; hour < 24; hour++) {
            const value = String(hour).padStart(2, '0');
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            targetSelect.appendChild(option);
        }
    }

    function syncEventStartFromControls() {
        const date = eventDateInput.value;
        const hour = eventHourInput.value;
        const minute = eventMinuteInput.value;

        if (!date || !hour || !minute) {
            eventStartInput.value = '';
            return;
        }

        eventStartInput.value = `${date}T${hour}:${minute}`;
    }

    function setEventStartControls(date) {
        const snapped = snapDateToQuarterHour(date);
        const minute = String(snapped.getMinutes()).padStart(2, '0');

        eventDateInput.value = toDateKey(snapped);
        eventHourInput.value = String(snapped.getHours()).padStart(2, '0');
        eventMinuteInput.value = ['00', '15', '30', '45'].includes(minute) ? minute : '00';

        syncEventStartFromControls();
    }

    function syncLockRangeFromControls() {
        const startDate = lockStartDateInput.value;
        const startHour = lockStartHourInput.value;
        const startMinute = lockStartMinuteInput.value;
        const endDate = lockEndDateInput.value;
        const endHour = lockEndHourInput.value;
        const endMinute = lockEndMinuteInput.value;

        if (!startDate || !startHour || !startMinute) {
            lockRangeStartInput.value = '';
        } else {
            lockRangeStartInput.value = `${startDate}T${startHour}:${startMinute}`;
        }

        if (!endDate || !endHour || !endMinute) {
            lockRangeEndInput.value = '';
        } else {
            lockRangeEndInput.value = `${endDate}T${endHour}:${endMinute}`;
        }
    }

    function setLockRangeControls(startDate, endDate) {
        const snappedStart = snapDateToQuarterHour(startDate);
        let snappedEnd = snapDateToQuarterHour(endDate);

        if (snappedEnd <= snappedStart) {
            snappedEnd = new Date(snappedStart);
            snappedEnd.setMinutes(snappedEnd.getMinutes() + 60);
        }

        lockStartDateInput.value = toDateKey(snappedStart);
        lockStartHourInput.value = String(snappedStart.getHours()).padStart(2, '0');
        lockStartMinuteInput.value = String(snappedStart.getMinutes()).padStart(2, '0');

        lockEndDateInput.value = toDateKey(snappedEnd);
        lockEndHourInput.value = String(snappedEnd.getHours()).padStart(2, '0');
        lockEndMinuteInput.value = String(snappedEnd.getMinutes()).padStart(2, '0');

        if (!['00', '15', '30', '45'].includes(lockStartMinuteInput.value)) {
            lockStartMinuteInput.value = '00';
        }
        if (!['00', '15', '30', '45'].includes(lockEndMinuteInput.value)) {
            lockEndMinuteInput.value = '00';
        }

        syncLockRangeFromControls();
    }

    function setRepeatControlsEnabled(enabled) {
        eventRepeatModeInput.disabled = !enabled;
        eventRepeatUntilInput.disabled = !enabled;
    }

    function updateRepeatControls() {
        const mode = eventRepeatModeInput.value;
        const showUntilDate = mode === 'weekly_until_date' && !eventRepeatModeInput.disabled;

        eventRepeatUntilWrap.classList.toggle('d-none', !showUntilDate);

        if (eventRepeatModeInput.disabled) {
            eventRepeatHint.textContent = 'Tuto událost už nelze převést na nové opakování (je součástí série).';
        } else if (mode === 'none') {
            eventRepeatHint.textContent = '';
        } else {
            eventRepeatHint.textContent = 'Vytvoří se samostatné události, které můžete později mazat po jedné nebo od určitého data dál.';
        }
    }

    function clearMakeupSuggestion() {
        currentMakeupSuggestion = null;
        eventMakeupSuggestion.classList.add('d-none');
        eventMakeupSuggestionText.textContent = '';
        eventUseMakeupBtn.classList.add('d-none');
    }

    function getSelectedStartMonth() {
        const date = eventDateInput.value;
        if (!date) {
            return '';
        }
        const parsed = new Date(`${date}T00:00`);
        if (Number.isNaN(parsed.getTime())) {
            return '';
        }
        return toMonthKey(parsed);
    }

    function renderMakeupSuggestion(payload) {
        if (!payload || !payload.success || !payload.has_outstanding) {
            clearMakeupSuggestion();
            return;
        }

        currentMakeupSuggestion = payload;
        const targetMonth = payload.target_month_label || getSelectedStartMonth();
        eventMakeupSuggestionText.textContent = `Sportovec má ${payload.outstanding_sessions} nevyčerpaný(é) trénink(y) z dříve uhrazených období. Pro ${targetMonth} můžete tento termín označit jako náhradu.`;
        eventMakeupSuggestion.classList.remove('d-none');
        eventUseMakeupBtn.classList.toggle('d-none', eventIsMakeupInput.checked);
    }

    async function refreshMakeupSuggestion() {
        const isNewEvent = !eventIdInput.value;
        const isLockMode = eventIsLockInput.checked;
        const athleteId = eventAthleteInput.value ? Number(eventAthleteInput.value) : 0;
        const startsAt = eventStartInput.value;

        if (!isNewEvent || isLockMode || athleteId <= 0 || !startsAt) {
            clearMakeupSuggestion();
            return;
        }

        const monthKey = getSelectedStartMonth();
        if (!monthKey) {
            clearMakeupSuggestion();
            return;
        }

        const cacheKey = `${athleteId}|${monthKey}`;
        if (makeupSuggestionCache.has(cacheKey)) {
            renderMakeupSuggestion(makeupSuggestionCache.get(cacheKey));
            return;
        }

        const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_makeup_hint.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                csrf_token: csrfToken,
                athlete_id: athleteId,
                starts_at: startsAt,
            }),
        });

        if (!payload.success) {
            clearMakeupSuggestion();
            return;
        }

        makeupSuggestionCache.set(cacheKey, payload);
        renderMakeupSuggestion(payload);
    }

    function updateLockRepeatControls() {
        const mode = lockRepeatModeInput.value;
        const unlockMode = lockUnlockModeInput.checked;
        const showUntilDate = mode === 'weekly_until_date' && !unlockMode;

        lockRepeatUntilWrap.classList.toggle('d-none', !showUntilDate);
        lockRepeatModeInput.disabled = unlockMode;
        lockRepeatUntilInput.disabled = unlockMode;

        if (unlockMode) {
            lockRepeatHint.textContent = 'Odemknutí se provede jen pro zadaný interval.';
            lockRepeatUntilInput.value = '';
            return;
        }

        if (mode === 'none') {
            lockRepeatHint.textContent = '';
        } else {
            lockRepeatHint.textContent = 'Uzamčení se uloží po týdnech až do zvoleného termínu.';
        }
    }

    function updateModeUI() {
        const lockMode = eventIsLockInput.checked;
        const selectedType = getSelectedEventTitleType();
        const isGroupLesson = selectedType === 'group_lesson';

        eventTrainingFields.classList.toggle('d-none', lockMode);
        lockFields.classList.toggle('d-none', !lockMode);
        if (eventAthleteFields) {
            eventAthleteFields.classList.toggle('d-none', lockMode || isGroupLesson);
        }

        if (!lockMode && isGroupLesson) {
            eventAthleteInput.value = '';
            eventSecondAthleteInput.value = '';
            eventIsMakeupInput.checked = false;
        }

        if (lockMode) {
            eventModalTitle.innerHTML = '<i class="fas fa-lock me-2 text-warning"></i>Uzamčení času';
            deleteEventBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Smazat uzamčení';
            clearMakeupSuggestion();
        } else {
            eventModalTitle.innerHTML = '<i class="fas fa-calendar-plus me-2 text-warning"></i>Trénink';
            deleteEventBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Smazat';
            lockUnlockModeInput.checked = false;
        }

        updateLockRepeatControls();
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
                cellDuration: 60,
                cellHeight: isCompactMobile ? 62 : 84,
                eventArrangement: 'SideBySide',
                useEventBoxes: 'Never',
                showNonBusiness: false,
                businessWeekends: true,
                heightSpec: 'BusinessHoursNoScroll',
                businessBeginsHour: hourStart,
                businessEndsHour: hourEnd,
                eventMoveHandling: 'Disabled',
                eventResizeHandling: 'Disabled',
                eventDeleteHandling: 'Disabled',
                timeRangeSelectedHandling: 'JavaScript',
                onTimeRangeSelected: (args) => {
                    const rangeStart = dayPilotDateToJs(args.start);
                    const rangeEnd = dayPilotDateToJs(args.end);
                    dayPilotCalendar.clearSelection();

                    if (!rangeStart || !rangeEnd) {
                        return;
                    }

                    openEventModal(null, rangeStart);

                    if (isRangeLocked(rangeStart, rangeEnd)) {
                        eventIsLockInput.checked = true;
                        lockUnlockModeInput.checked = true;
                        setLockRangeControls(rangeStart, rangeEnd);
                        updateModeUI();
                    }
                },
                onEventClick: (args) => {
                    const clickedId = String(args.e.id());
                    if (clickedId.startsWith('lock-')) {
                        const lockId = Number(clickedId.replace('lock-', ''));
                        const lock = locks.find((item) => Number(item.id) === lockId);
                        if (lock) {
                            openEventModal(null, null, lock);
                        }
                        return;
                    }

                    const event = events.find((item) => String(item.id) === clickedId);
                    if (event) {
                        openEventModal(event);
                    }
                },
                onBeforeCellRender: (args) => {
                    const start = dayPilotDateToJs(args.cell.start);
                    const end = dayPilotDateToJs(args.cell.end);

                    if (start && end && isRangeLocked(start, end)) {
                        args.cell.backColor = '#e5e7eb';
                    }
                },
            });

            dayPilotCalendar.init();
        }

        dayPilotCalendar.update({
            startDate: toDateKey(currentWeekStart),
            events: [...locks.map(toDayPilotLockEvent), ...events.map(toDayPilotEvent)],
        });

        if (calendarGrid && calendarGrid.closest('.card')) {
            calendarGrid.closest('.card').classList.add('d-none');
        }
        if (daypilotCard) {
            daypilotCard.classList.remove('d-none');
        }

        return true;
    }

    function fromSqlDateTime(sqlDateTime) {
        return new Date(sqlDateTime.replace(' ', 'T'));
    }

    function formatDateCs(date) {
        return new Intl.DateTimeFormat('cs-CZ', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
    }

    function formatTimeCs(date) {
        return new Intl.DateTimeFormat('cs-CZ', { hour: '2-digit', minute: '2-digit' }).format(date);
    }

    function showError(el, text) {
        el.textContent = text;
        el.classList.remove('d-none');
    }

    function clearError(el) {
        el.textContent = '';
        el.classList.add('d-none');
    }

    function setWeekRangeLabel() {
        const end = addDays(currentWeekStart, 6);
        weekRangeLabel.textContent = `${formatDateCs(currentWeekStart)} - ${formatDateCs(end)}`;
    }

    function isSlotLocked(slotStart) {
        const slotEnd = new Date(slotStart);
        slotEnd.setHours(slotEnd.getHours() + 1);
        return locks.some((lock) => {
            const lockStart = fromSqlDateTime(lock.starts_at);
            const lockEnd = fromSqlDateTime(lock.ends_at);
            return lockStart < slotEnd && lockEnd > slotStart;
        });
    }

    function getEventsForSlot(slotStart) {
        const slotStartMs = slotStart.getTime();
        return events.filter((event) => {
            const start = fromSqlDateTime(event.starts_at);
            return start.getTime() === slotStartMs;
        });
    }

    function getEventAthletesLabel(event) {
        const names = [];
        if (event.athlete_id && event.first_name && event.last_name) {
            names.push(`${event.last_name} ${event.first_name}`);
        }
        if (event.second_athlete_id && event.second_first_name && event.second_last_name) {
            names.push(`${event.second_last_name} ${event.second_first_name}`);
        }
        return names.join(' + ');
    }

    function getEventTitle(event) {
        if (event.is_foreign) {
            return 'Obsazeno';
        }

        if (event.second_athlete_id) {
            return getEventAthletesLabel(event) || 'Párový trénink';
        }

        const athletesLabel = getEventAthletesLabel(event);
        if (athletesLabel) {
            return athletesLabel;
        }

        if (event.custom_title) {
            return event.custom_title;
        }
        return 'Trénink';
    }

    function renderCalendar() {
        setWeekRangeLabel();

        if (renderDayPilotCalendar()) {
            return;
        }

        const dayDates = Array.from({ length: 7 }, (_, i) => addDays(currentWeekStart, i));

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');

        const headTime = document.createElement('th');
        headTime.className = 'time-col';
        headTime.textContent = 'Čas';
        headRow.appendChild(headTime);

        dayDates.forEach((dayDate, idx) => {
            const th = document.createElement('th');
            th.innerHTML = `<span class="day-name">${czechDayShort[idx]}</span><span class="day-date">${formatDateCs(dayDate)}</span>`;
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);

        const tbody = document.createElement('tbody');

        for (let hour = hourStart; hour < hourEnd; hour++) {
            const tr = document.createElement('tr');

            const timeTd = document.createElement('td');
            timeTd.className = 'time-col';
            timeTd.textContent = `${String(hour).padStart(2, '0')}:00`;
            tr.appendChild(timeTd);

            dayDates.forEach((dayDate) => {
                const slotStart = new Date(dayDate);
                slotStart.setHours(hour, 0, 0, 0);

                const td = document.createElement('td');
                td.className = 'slot-cell';
                td.dataset.slot = toDateTimeInputValue(slotStart);

                const locked = isSlotLocked(slotStart);
                if (locked) {
                    td.classList.add('is-locked');
                }

                const slotEvents = getEventsForSlot(slotStart);
                if (slotEvents.length > 0) {
                    slotEvents.forEach((event) => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'slot-event';
                        const color = getEventColorScheme(event);
                        const statusMeta = getEventStatusMeta(event);
                        btn.style.background = color.backColor;
                        btn.style.borderColor = color.barColor;
                        btn.style.color = color.fontColor;
                        if (statusMeta.className) {
                            btn.classList.add(statusMeta.className);
                        }
                        
                        const eventStart = fromSqlDateTime(event.starts_at);
                        const eventEnd = fromSqlDateTime(event.ends_at);
                        
                        // Formát času s minutami
                        const startTime = eventStart.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
                        const endTime = eventEnd.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
                        
                        const title = getEventTitle(event);
                        const athletesLabel = getEventAthletesLabel(event);
                        const athleteNames = athletesLabel ? athletesLabel.split(' + ') : [];
                        const isPairedTraining = athleteNames.length === 2;
                        const statusSuffix = statusMeta.label ? ` • ${statusMeta.label}` : '';
                        const paymentPaid = String(event.payment_status || '') === 'paid';
                        const paymentSuffix = paymentPaid ? ' • Uhrazeno' : '';
                        const locationLine = event.location ? `<span class="where">${escapeHtml(event.location)}</span>` : '';
                        if (isPairedTraining) {
                            btn.classList.add('paired');
                            btn.innerHTML = `<span class="paired-names"><span class="name-col">${escapeHtml(athleteNames[0])}</span><span class="name-col">${escapeHtml(athleteNames[1])}</span></span>${locationLine}<span class="time">${startTime}-${endTime}${paymentSuffix}</span>`;
                        } else {
                            btn.innerHTML = `<span class="time">${startTime}-${endTime}${paymentSuffix}</span><div class="fw-semibold">${escapeHtml(title)}${statusSuffix}</div>${locationLine}`;
                        }
                        btn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            openEventModal(event);
                        });
                        td.appendChild(btn);
                    });
                } else if (locked) {
                    const lockInfo = document.createElement('div');
                    lockInfo.className = 'slot-add-hint';
                    lockInfo.textContent = 'Uzamčeno';
                    td.appendChild(lockInfo);
                } else {
                    const hint = document.createElement('div');
                    hint.className = 'slot-add-hint';
                    hint.innerHTML = '<i class="fas fa-plus"></i>';
                    td.appendChild(hint);
                }

                td.addEventListener('click', () => {
                    if (locked) {
                        openEventModal(null, slotStart);
                        eventIsLockInput.checked = true;
                        lockUnlockModeInput.checked = true;
                        updateModeUI();
                        return;
                    }
                    openEventModal(null, slotStart);
                });

                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        }

        calendarGrid.innerHTML = '';
        calendarGrid.appendChild(thead);
        calendarGrid.appendChild(tbody);
    }

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, options);
        let payload;
        try {
            payload = await response.json();
        } catch (e) {
            payload = { success: false, error: 'Neplatná odpověď serveru' };
        }
        return payload;
    }

    async function loadWeekData() {
        const query = new URLSearchParams({ week_start: toDateKey(currentWeekStart) });
        const payload = await fetchJson(`<?= BASE_URL ?>/api/calendar_data.php?${query.toString()}`);
        if (!payload.success) {
            alert(payload.error || 'Nepodařilo se načíst kalendář');
            return;
        }

        events = payload.events || [];
        locks = payload.locks || [];
        renderCalendar();
        await loadMonthListData();
    }

    function shiftMonthValue(monthValue, offset) {
        const parsed = new Date(`${monthValue}-01T00:00:00`);
        if (Number.isNaN(parsed.getTime())) {
            return monthValue;
        }
        parsed.setMonth(parsed.getMonth() + offset);
        return `${parsed.getFullYear()}-${String(parsed.getMonth() + 1).padStart(2, '0')}`;
    }

    function getMonthStatusBadge(statusClass, statusLabel) {
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

    function renderMonthList(items) {
        monthListBody.innerHTML = '';
        if (!Array.isArray(items) || items.length === 0) {
            monthListBody.innerHTML = '<tr><td colspan="7" class="text-muted">V tomto měsíci nejsou žádné události.</td></tr>';
            monthListEmpty.classList.remove('d-none');
            return;
        }

        monthListEmpty.classList.add('d-none');
        items.forEach((item) => {
            const tr = document.createElement('tr');
            const canApprove = !!item.can_approve;
            const actionHtml = canApprove
                ? `<button type="button" class="btn btn-sm btn-success js-approve-month-item" data-event-id="${Number(item.id || 0)}"><i class="fas fa-check me-1"></i>Schválit</button>`
                : '<span class="text-muted small">-</span>';
            tr.innerHTML = `
                <td>${escapeHtml(item.date_label || '-')}</td>
                <td>${escapeHtml(item.time_label || '-')}</td>
                <td>${escapeHtml(item.athlete_label || '-')}</td>
                <td>${escapeHtml(item.type_label || '-')}</td>
                <td>${escapeHtml(item.location_label || '-')}</td>
                <td>${getMonthStatusBadge(item.status_class || 'secondary', item.status_label || 'Bez stavu')}</td>
                <td>${actionHtml}</td>
            `;
            monthListBody.appendChild(tr);
        });
    }

    async function loadMonthListData() {
        if (!monthListMonthInput || !monthListMonthInput.value) {
            return;
        }

        const query = new URLSearchParams({ month: monthListMonthInput.value });
        const payload = await fetchJson(`<?= BASE_URL ?>/api/calendar_month_list.php?${query.toString()}`);
        if (!payload.success) {
            monthListBody.innerHTML = '<tr><td colspan="7" class="text-danger">Načtení měsíčního seznamu selhalo.</td></tr>';
            monthListEmpty.classList.add('d-none');
            return;
        }

        renderMonthList(payload.items || []);
    }

    function openEventModal(event = null, slotDate = null, lock = null) {
        clearError(eventError);
        paymentInfo.textContent = '';
        paymentInfo.classList.add('d-none');
        requestInfo.textContent = '';
        requestInfo.classList.add('d-none');
        approveEventBtn.classList.add('d-none');
        activeEvent = event;

        if (lock) {
            eventIsLockInput.checked = true;
            eventIsLockInput.disabled = true;
            lockIdInput.value = String(lock.id);
            eventIdInput.value = '';

            lockUnlockModeInput.checked = false;
            lockNoteInlineInput.value = lock.note || '';
            setLockRangeControls(fromSqlDateTime(lock.starts_at), fromSqlDateTime(lock.ends_at));
            lockRepeatModeInput.value = 'none';
            lockRepeatUntilInput.value = '';

            deleteEventBtn.classList.remove('d-none');
            clearMakeupSuggestion();
        } else if (event) {
            eventIsLockInput.checked = false;
            eventIsLockInput.disabled = true;
            lockIdInput.value = '';
            eventIdInput.value = event.id;
            setSelectedEventTitleType(inferTitleTypeFromEvent(event));
            eventAthleteInput.value = event.athlete_id ? String(event.athlete_id) : '';
            eventSecondAthleteInput.value = event.second_athlete_id ? String(event.second_athlete_id) : '';
            eventCustomTitleInput.value = inferTitleTypeFromEvent(event) === 'training' ? (event.custom_title || '') : '';
            const hasLocationOption = !!Array.from(eventLocationModeInput.options).find((option) => option.value === String(event.location || ''));
            eventLocationModeInput.value = hasLocationOption ? String(event.location) : 'custom';
            eventLocationInput.value = event.location || '';
            eventColorInput.value = normalizeColorKey(event.color_key);
            setEventStartControls(fromSqlDateTime(event.starts_at));
            eventRepeatModeInput.value = 'none';
            eventRepeatUntilInput.value = '';
            eventIsMakeupInput.checked = Number(event.is_makeup_session || 0) === 1;
            setRepeatControlsEnabled(!event.series_id);
            updateRepeatControls();
            deleteEventBtn.classList.remove('d-none');
            clearMakeupSuggestion();

            if (String(event.payment_status || '') === 'paid') {
                paymentInfo.textContent = 'Tato událost patří do již uhrazeného období.';
                paymentInfo.classList.remove('d-none');
            }

            const isPendingRequest = (event.approval_status || 'approved') === 'pending' && Number(event.requested_by_athlete_id || 0) > 0;
            if (isPendingRequest) {
                requestInfo.textContent = 'Toto je nový požadavek sportovce. Můžete jej schválit, zamítnout nebo upravit. Uložení změn požadavek automaticky schválí.';
                requestInfo.classList.remove('d-none');
                approveEventBtn.classList.remove('d-none');
                deleteEventBtn.innerHTML = '<i class="fas fa-xmark me-1"></i>Zamítnout';
            } else {
                deleteEventBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Smazat';
            }
        } else {
            eventIsLockInput.checked = false;
            eventIsLockInput.disabled = false;
            lockIdInput.value = '';
            eventIdInput.value = '';
            setSelectedEventTitleType('training');
            eventAthleteInput.value = '';
            eventSecondAthleteInput.value = '';
            eventCustomTitleInput.value = '';
            eventLocationModeInput.value = 'custom';
            eventLocationInput.value = '';
            eventColorInput.value = 'green';

            const base = slotDate ? new Date(slotDate) : new Date();
            base.setMinutes(0, 0, 0);
            if (!slotDate && base.getHours() < hourStart) {
                base.setHours(hourStart);
            }
            if (!slotDate && base.getHours() >= hourEnd) {
                base.setDate(base.getDate() + 1);
                base.setHours(hourStart);
            }
            setEventStartControls(base);

            const lockStart = new Date(base);
            lockStart.setMinutes(0, 0, 0);
            const lockEnd = new Date(lockStart);
            lockEnd.setHours(lockEnd.getHours() + 1);
            lockUnlockModeInput.checked = false;
            lockNoteInlineInput.value = '';
            setLockRangeControls(lockStart, lockEnd);
            lockRepeatModeInput.value = 'none';
            lockRepeatUntilInput.value = '';

            eventRepeatModeInput.value = 'none';
            eventRepeatUntilInput.value = '';
            eventIsMakeupInput.checked = false;
            setRepeatControlsEnabled(true);
            updateRepeatControls();
            deleteEventBtn.classList.add('d-none');
            deleteEventBtn.innerHTML = '<i class="fas fa-trash me-1"></i>Smazat';
            clearMakeupSuggestion();
        }

    syncSecondAthleteOptions();
        updateEventLocationHint();
        updateModeUI();
        refreshMakeupSuggestion();

        eventModal.show();
    }

    eventLocationModeInput.addEventListener('change', (e) => {
        if (e.target.value !== 'custom') {
            eventLocationInput.value = e.target.value;
        }
        updateEventLocationHint();
    });
    eventLocationInput.addEventListener('input', updateEventLocationHint);
    eventAthleteInput.addEventListener('change', () => {
        syncSecondAthleteOptions();
        refreshMakeupSuggestion();
    });

    eventDateInput.addEventListener('change', () => {
        syncEventStartFromControls();
        refreshMakeupSuggestion();
    });
    eventHourInput.addEventListener('change', () => {
        syncEventStartFromControls();
        refreshMakeupSuggestion();
    });
    eventMinuteInput.addEventListener('change', () => {
        syncEventStartFromControls();
        refreshMakeupSuggestion();
    });
    lockStartDateInput.addEventListener('change', syncLockRangeFromControls);
    lockStartHourInput.addEventListener('change', syncLockRangeFromControls);
    lockStartMinuteInput.addEventListener('change', syncLockRangeFromControls);
    lockEndDateInput.addEventListener('change', syncLockRangeFromControls);
    lockEndHourInput.addEventListener('change', syncLockRangeFromControls);
    lockEndMinuteInput.addEventListener('change', syncLockRangeFromControls);
    eventRepeatModeInput.addEventListener('change', updateRepeatControls);
    eventIsMakeupInput.addEventListener('change', () => {
        if (eventIsMakeupInput.checked) {
            eventUseMakeupBtn.classList.add('d-none');
        } else if (currentMakeupSuggestion && currentMakeupSuggestion.has_outstanding) {
            eventUseMakeupBtn.classList.remove('d-none');
        }
    });
    eventIsLockInput.addEventListener('change', () => {
        updateModeUI();
        refreshMakeupSuggestion();
    });
    document.querySelectorAll('input[name="eventTitleType"]').forEach((input) => {
        input.addEventListener('change', () => {
            updateModeUI();
            refreshMakeupSuggestion();
        });
    });
    lockUnlockModeInput.addEventListener('change', updateModeUI);
    lockRepeatModeInput.addEventListener('change', updateLockRepeatControls);

    eventUseMakeupBtn.addEventListener('click', () => {
        eventIsMakeupInput.checked = true;
        eventUseMakeupBtn.classList.add('d-none');
    });

    eventForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearError(eventError);

        if (eventIsLockInput.checked) {
            syncLockRangeFromControls();
            const lockStartsAt = lockRangeStartInput.value;
            const lockEndsAt = lockRangeEndInput.value;
            const lockRepeatMode = lockUnlockModeInput.checked ? 'none' : lockRepeatModeInput.value;
            const lockRepeatUntil = lockRepeatUntilInput.value;

            if (!lockStartsAt || !lockEndsAt) {
                showError(eventError, 'Vyberte interval uzamčení od-do.');
                return;
            }

            if (!lockUnlockModeInput.checked && lockRepeatMode === 'weekly_until_date' && !lockRepeatUntil) {
                showError(eventError, 'Vyberte datum, do kterého se má uzamčení opakovat.');
                return;
            }

            const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_save_lock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    lock_id: lockIdInput.value ? Number(lockIdInput.value) : 0,
                    starts_at: lockStartsAt,
                    ends_at: lockEndsAt,
                    note: lockNoteInlineInput.value.trim(),
                    mode: lockUnlockModeInput.checked ? 'unlock' : 'lock',
                    repeat_mode: lockRepeatMode,
                    repeat_until: lockRepeatUntil,
                }),
            });

            if (!payload.success) {
                showError(eventError, payload.error || 'Uložení uzamčení se nepodařilo.');
                return;
            }

            eventModal.hide();
            await loadWeekData();
            return;
        }

        syncEventStartFromControls();

        const startsAt = eventStartInput.value;
        const athleteId = eventAthleteInput.value ? Number(eventAthleteInput.value) : 0;
        const secondAthleteId = eventSecondAthleteInput.value ? Number(eventSecondAthleteInput.value) : 0;
        const customTitle = eventCustomTitleInput.value.trim();
        const titleType = getSelectedEventTitleType();
        const repeatMode = eventRepeatModeInput.disabled ? 'none' : eventRepeatModeInput.value;
        const repeatUntil = eventRepeatUntilInput.value;

        if (!startsAt) {
            showError(eventError, 'Vyberte datum a čas začátku tréninku.');
            return;
        }

        if (repeatMode === 'weekly_until_date' && !repeatUntil) {
            showError(eventError, 'Vyberte datum, do kterého se má trénink opakovat.');
            return;
        }

        if (titleType === 'group_lesson') {
            if (!customTitle) {
                showError(eventError, 'U skupinové lekce vyplňte název.');
                return;
            }
            if (!eventLocationInput.value.trim()) {
                showError(eventError, 'U skupinové lekce vyberte nebo vyplňte místo konání.');
                return;
            }
        }

        if (titleType === 'training' && !athleteId && !customTitle) {
            showError(eventError, 'Vyberte sportovce nebo vyplňte vlastní název.');
            return;
        }

        if (athleteId > 0 && secondAthleteId > 0 && athleteId === secondAthleteId) {
            showError(eventError, 'Párový trénink vyžaduje dva různé sportovce.');
            return;
        }

        if (!eventIsMakeupInput.checked && currentMakeupSuggestion && currentMakeupSuggestion.has_outstanding) {
            const outstandingCount = Number(currentMakeupSuggestion.outstanding_sessions || 0);
            const confirmUse = confirm(
                `Sportovec má k dispozici ${outstandingCount} náhradní trénink(y) z dřívější úhrady.\n` +
                'Chcete tento termín označit jako náhradu?\n\n' +
                'OK = označit jako náhradu\nStorno = uložit bez náhrady'
            );

            if (confirmUse) {
                eventIsMakeupInput.checked = true;
            }
        }

        const isMakeupSession = eventIsMakeupInput.checked;

        const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_save_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                csrf_token: csrfToken,
                event_id: eventIdInput.value ? Number(eventIdInput.value) : 0,
                athlete_id: athleteId,
                second_athlete_id: secondAthleteId,
                title_type: titleType,
                custom_title: customTitle,
                location: eventLocationInput.value.trim(),
                color_key: normalizeColorKey(eventColorInput.value),
                starts_at: startsAt,
                is_makeup_session: isMakeupSession,
                repeat_mode: repeatMode,
                repeat_until: repeatUntil,
            }),
        });

        if (!payload.success) {
            showError(eventError, payload.error || 'Uložení se nepodařilo.');
            return;
        }

        eventModal.hide();
        await loadWeekData();
    });

    approveEventBtn.addEventListener('click', async () => {
        const eventId = Number(eventIdInput.value || 0);
        if (!eventId) {
            return;
        }

        syncEventStartFromControls();

        const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_save_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                csrf_token: csrfToken,
                event_id: eventId,
                athlete_id: eventAthleteInput.value ? Number(eventAthleteInput.value) : 0,
                second_athlete_id: eventSecondAthleteInput.value ? Number(eventSecondAthleteInput.value) : 0,
                title_type: getSelectedEventTitleType(),
                custom_title: eventCustomTitleInput.value.trim(),
                location: eventLocationInput.value.trim(),
                color_key: normalizeColorKey(eventColorInput.value),
                starts_at: eventStartInput.value,
                is_makeup_session: eventIsMakeupInput.checked,
                repeat_mode: 'none',
                repeat_until: '',
                approval_action: 'approve',
            }),
        });

        if (!payload.success) {
            showError(eventError, payload.error || 'Schválení se nepodařilo.');
            return;
        }

        eventModal.hide();
        await loadWeekData();
    });

    monthListBody.addEventListener('click', async (e) => {
        const approveBtn = e.target.closest('.js-approve-month-item');
        if (!approveBtn) {
            return;
        }

        const eventId = Number(approveBtn.dataset.eventId || 0);
        if (!eventId) {
            return;
        }

        approveBtn.disabled = true;
        const originalHtml = approveBtn.innerHTML;
        approveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Schvaluji';

        const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_approve_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                csrf_token: csrfToken,
                event_id: eventId,
            }),
        });

        if (!payload.success) {
            alert(payload.error || 'Schválení se nepodařilo.');
            approveBtn.disabled = false;
            approveBtn.innerHTML = originalHtml;
            return;
        }

        await loadWeekData();
    });

    deleteEventBtn.addEventListener('click', async () => {
        if (eventIsLockInput.checked) {
            const lockId = Number(lockIdInput.value || 0);
            if (!lockId) {
                return;
            }

            if (!confirm('Opravdu chcete toto uzamčení smazat?')) {
                return;
            }

            const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_delete_lock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    lock_id: lockId,
                }),
            });

            if (!payload.success) {
                showError(eventError, payload.error || 'Smazání uzamčení se nepodařilo.');
                return;
            }

            eventModal.hide();
            await loadWeekData();
            return;
        }

        const eventId = Number(eventIdInput.value || 0);
        if (!eventId) {
            return;
        }

        let deleteScope = 'single';
        if (activeEvent && activeEvent.series_id) {
            const deleteFuture = confirm('Smazat tento trénink i všechny budoucí ve stejné sérii?\n\nOK = Ano (tento + budoucí)\nStorno = Vybrat jen tento');
            if (deleteFuture) {
                deleteScope = 'future';
            } else {
                const deleteSingle = confirm('Smazat jen tento trénink?');
                if (!deleteSingle) {
                    return;
                }
            }
        } else {
            if (!confirm('Opravdu chcete tento trénink smazat?')) {
                return;
            }
        }

        const payload = await fetchJson('<?= BASE_URL ?>/api/calendar_delete_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                csrf_token: csrfToken,
                event_id: eventId,
                delete_scope: deleteScope,
            }),
        });

        if (!payload.success) {
            showError(eventError, payload.error || 'Smazání se nepodařilo.');
            return;
        }

        if (payload.message && Number(payload.paid_affected_count || 0) > 0) {
            alert(payload.message);
        }

        eventModal.hide();
        await loadWeekData();
    });

    document.getElementById('prevWeekBtn').addEventListener('click', async () => {
        currentWeekStart = addDays(currentWeekStart, -7);
        await loadWeekData();
    });

    document.getElementById('nextWeekBtn').addEventListener('click', async () => {
        currentWeekStart = addDays(currentWeekStart, 7);
        await loadWeekData();
    });

    document.getElementById('todayWeekBtn').addEventListener('click', async () => {
        currentWeekStart = getMonday(new Date());
        await loadWeekData();
    });

    document.getElementById('quickAddBtn').addEventListener('click', () => {
        openEventModal();
    });

    monthListMonthInput.value = `${currentWeekStart.getFullYear()}-${String(currentWeekStart.getMonth() + 1).padStart(2, '0')}`;
    monthListMonthInput.addEventListener('change', () => {
        loadMonthListData();
    });

    monthListPrevBtn.addEventListener('click', () => {
        monthListMonthInput.value = shiftMonthValue(monthListMonthInput.value, -1);
        loadMonthListData();
    });

    monthListNextBtn.addEventListener('click', () => {
        monthListMonthInput.value = shiftMonthValue(monthListMonthInput.value, 1);
        loadMonthListData();
    });

    populateEventHourOptions();
    populateLockHourOptions(lockStartHourInput);
    populateLockHourOptions(lockEndHourInput);
    syncSecondAthleteOptions();
    updateEventLocationHint();
    updateModeUI();

    loadWeekData();
});
</script>

<?php renderFooter();
