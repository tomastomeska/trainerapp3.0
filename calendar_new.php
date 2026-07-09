<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$coachId = (int)getCurrentCoachId();
$pdo = getDB();

$athletesStmt = $pdo->prepare(
    'SELECT id, first_name, last_name
     FROM athletes
     WHERE coach_id = ?
     ORDER BY first_name, last_name'
);
$athletesStmt->execute([$coachId]);
$athletes = $athletesStmt->fetchAll();

$venues = array_values(array_filter(getTrainingVenuesForCoach($coachId), fn($row) => !empty($row['name'])));

$athletesJson = json_encode($athletes);
$venuesJson = json_encode($venues, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kalendář</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --time-width: 60px;
            --day-width: 180px;
            --slot-px: 30px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', sans-serif;
            background: #f5f5f5;
        }

        .navbar {
            background: white !important;
            border-bottom: 1px solid #ddd;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .calendar-header {
            background: white;
            padding: 1rem;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 0;
        }

        .calendar-header h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .week-nav {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .week-nav button {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .week-nav button:hover {
            background: #f9f9f9;
        }

        .week-dates {
            font-weight: 600;
            min-width: 200px;
            text-align: center;
            font-size: 0.95rem;
        }

        .calendar-container {
            display: flex;
            background: white;
            overflow-x: auto;
            height: calc(100vh - 160px);
            border: 1px solid #ddd;
            margin: 1rem;
            border-radius: 4px;
        }

        .time-column {
            width: var(--time-width);
            flex-shrink: 0;
            border-right: 2px solid #ddd;
            background: #fafafa;
            overflow: hidden;
        }

        .time-header {
            height: 50px;
            border-bottom: 2px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .time-slot {
            height: var(--slot-px);
            line-height: var(--slot-px);
            font-size: 0.7rem;
            font-weight: 600;
            color: #666;
            text-align: center;
            border-bottom: 1px solid #f0f0f0;
            padding: 0 2px;
        }

        .days-scroll {
            flex: 1;
            display: flex;
            overflow-x: auto;
        }

        .day-column {
            width: var(--day-width);
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid #ddd;
            position: relative;
        }

        .day-column:last-child {
            border-right: none;
        }

        .day-header {
            height: 50px;
            padding: 0.5rem;
            background: #f9f9f9;
            border-bottom: 2px solid #ddd;
            font-weight: 600;
            font-size: 0.85rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
        }

        .day-header.today {
            background: #e3f2fd;
            color: #1976d2;
            font-weight: 700;
        }

        .day-grid {
            flex: 1;
            position: relative;
            background-image: repeating-linear-gradient(
                0deg,
                transparent 0px,
                transparent calc(var(--slot-px) - 1px),
                #f0f0f0 calc(var(--slot-px) - 1px),
                #f0f0f0 var(--slot-px)
            );
            background-size: 100% var(--slot-px);
        }

        .event {
            position: absolute;
            left: 2px;
            right: 2px;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            border-left: 4px solid #0369a1;
            border-radius: 4px;
            padding: 4px;
            cursor: pointer;
            overflow: hidden;
            font-size: 0.7rem;
            line-height: 1.2;
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: box-shadow 0.2s;
            min-height: 32px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .event:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        }

        .event.locked {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            border-left-color: #d97706;
            cursor: not-allowed;
        }

        .event-time {
            font-weight: 700;
            font-size: 0.72rem;
        }

        .event-title {
            font-weight: 600;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .event-location {
            font-size: 0.65rem;
            opacity: 0.9;
            margin-top: 1px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .event-location i {
            font-size: 0.6rem;
        }

        @media (max-width: 991.98px) {
            :root {
                --time-width: 50px;
                --day-width: 140px;
                --slot-px: 25px;
            }

            .calendar-header h1 {
                font-size: 1.1rem;
            }

            .week-nav {
                gap: 0.5rem;
            }

            .week-nav button {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }

            .week-dates {
                min-width: 150px;
                font-size: 0.85rem;
            }

            .event {
                font-size: 0.65rem;
            }

            .event-time {
                font-size: 0.65rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="calendar-header">
        <h1><i class="fas fa-calendar-alt me-2"></i>Kalendář</h1>
        <div class="week-nav">
            <button id="prevBtn">← Minulý</button>
            <div class="week-dates" id="weekLabel"></div>
            <button id="nextBtn">Další →</button>
        </div>
    </div>

    <div class="calendar-container">
        <div class="time-column">
            <div class="time-header"></div>
            <div id="timeSlots"></div>
        </div>
        <div class="days-scroll">
            <div id="daysContainer"></div>
        </div>
    </div>

    <!-- Event Modal -->
    <div class="modal fade" id="eventModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Nový trénink</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="errorMsg" class="alert alert-danger" style="display:none;"></div>
                    <form id="eventForm">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" id="eventId">

                        <div class="mb-3">
                            <label for="athlete" class="form-label fw-semibold">Sportovec</label>
                            <select id="athlete" class="form-select">
                                <option value="">-- Vlastní text --</option>
                                <?php foreach ($athletes as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= htmlspecialchars("{$a['last_name']} {$a['first_name']}") ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="title" class="form-label fw-semibold">Název</label>
                            <input type="text" id="title" class="form-control" maxlength="140" placeholder="Např. Běh, Síla...">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="startTime" class="form-label fw-semibold">Začátek</label>
                                <input type="datetime-local" id="startTime" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="duration" class="form-label fw-semibold">Trvání</label>
                                <select id="duration" class="form-select">
                                    <option value="30">30 min</option>
                                    <option value="45">45 min</option>
                                    <option value="60" selected>60 min</option>
                                    <option value="90">90 min</option>
                                    <option value="120">120 min</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="location" class="form-label fw-semibold">Místo</label>
                            <div class="input-group">
                                <select id="locMode" class="form-select" style="flex: 0 0 120px;">
                                    <option value="">Vlastní</option>
                                    <?php foreach ($venues as $venue): ?>
                                    <option value="<?= htmlspecialchars((string)$venue['name']) ?>"
                                            data-address="<?= htmlspecialchars((string)($venue['address'] ?? '')) ?>"
                                            data-note="<?= htmlspecialchars((string)($venue['note'] ?? '')) ?>">
                                        <?= htmlspecialchars((string)$venue['name']) ?>
                                        <?php if (!empty($venue['address'])): ?>
                                        — <?= htmlspecialchars((string)$venue['address']) ?>
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" id="location" class="form-control" maxlength="255" placeholder="Stadion, fitko...">
                            </div>
                            <div class="small text-muted mt-1" id="locationHint"></div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <button type="button" id="deleteBtn" class="btn btn-danger" style="display:none;">Smazat</button>
                    <button type="button" id="saveBtn" class="btn btn-primary">Uložit</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const athletes = <?= $athletesJson ?>;
        const venues = <?= $venuesJson ?>;
        const venueNames = venues.map(v => v.name);

        let modal = new bootstrap.Modal(document.getElementById('eventModal'));
        let currentDate = new Date();
        let currentEventId = null;
        let events = [];

        const TIME_START = 5;
        const TIME_END = 22;
        const TIME_STEP = 30; // minutes

        function getMonday(d) {
            const day = d.getDay();
            const diff = d.getDate() - day + (day === 0 ? -6 : 1);
            const result = new Date(d);
            result.setDate(diff);
            result.setHours(0, 0, 0, 0);
            return result;
        }

        function formatDateTime(d) {
            const y = d.getFullYear();
            const mo = String(d.getMonth() + 1).padStart(2, '0');
            const da = String(d.getDate()).padStart(2, '0');
            const h = String(d.getHours()).padStart(2, '0');
            const mi = String(d.getMinutes()).padStart(2, '0');
            return `${y}-${mo}-${da}T${h}:${mi}`;
        }

        function sqlToDate(s) {
            return new Date(s.replace(' ', 'T') + 'Z');
        }

        function formatDate(d) {
            return d.toLocaleDateString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric' });
        }

        function isToday(d) {
            const t = new Date();
            return d.getFullYear() === t.getFullYear() &&
                   d.getMonth() === t.getMonth() &&
                   d.getDate() === t.getDate();
        }

        function renderTimeSlots() {
            const html = [];
            for (let m = TIME_START * 60; m < TIME_END * 60; m += TIME_STEP) {
                const h = Math.floor(m / 60);
                const mi = m % 60;
                html.push(`<div class="time-slot">${String(h).padStart(2, '0')}:${String(mi).padStart(2, '0')}</div>`);
            }
            document.getElementById('timeSlots').innerHTML = html.join('');
        }

        function renderDays() {
            const monday = getMonday(currentDate);
            const html = [];

            for (let i = 0; i < 7; i++) {
                const d = new Date(monday);
                d.setDate(d.getDate() + i);
                const dateStr = d.toISOString().split('T')[0];

                html.push(`
                    <div class="day-column" data-date="${dateStr}">
                        <div class="day-header ${isToday(d) ? 'today' : ''}">
                            ${formatDate(d)}
                        </div>
                        <div class="day-grid"></div>
                    </div>
                `);
            }

            document.getElementById('daysContainer').innerHTML = html.join('');

            // Add click handlers
            document.querySelectorAll('.day-grid').forEach(grid => {
                grid.addEventListener('click', (e) => {
                    if (e.target === grid) {
                        const dateStr = grid.closest('.day-column').dataset.date;
                        const d = new Date(dateStr + 'T05:00:00');
                        const rect = grid.getBoundingClientRect();
                        const y = e.clientY - rect.top;
                        const totalMs = (TIME_END - TIME_START) * 60 * 60 * 1000;
                        const offsetMs = y / rect.height * totalMs;
                        d.setMinutes(Math.round(offsetMs / 60000 / TIME_STEP) * TIME_STEP);
                        openModal(null, d);
                    }
                });
            });

            updateWeekLabel();
            renderEvents();
        }

        function updateWeekLabel() {
            const monday = getMonday(currentDate);
            const sunday = new Date(monday);
            sunday.setDate(sunday.getDate() + 6);
            document.getElementById('weekLabel').textContent = 
                `${formatDate(monday)} – ${formatDate(sunday)}`;
        }

        async function loadEvents() {
            const monday = getMonday(currentDate);
            const weekStart = monday.toISOString().split('T')[0];

            try {
                const r = await fetch(`/api/calendar_data.php?week=${weekStart}`);
                const data = await r.json();
                events = data.events || [];
                renderEvents();
            } catch (e) {
                console.error(e);
            }
        }

        function renderEvents() {
            document.querySelectorAll('.day-grid').forEach(g => g.innerHTML = '');

            events.forEach(ev => {
                const start = sqlToDate(ev.starts_at);
                const end = sqlToDate(ev.ends_at);
                const dateStr = start.toISOString().split('T')[0];
                const col = document.querySelector(`.day-column[data-date="${dateStr}"] .day-grid`);

                if (!col) return;

                const calStart = new Date(dateStr + 'T05:00:00');
                const calEnd = new Date(dateStr + 'T22:00:00');
                const calMs = calEnd - calStart;

                const visStart = Math.max(start, calStart);
                const visEnd = Math.min(end, calEnd);

                const topPct = ((visStart - calStart) / calMs) * 100;
                const heiPct = ((visEnd - visStart) / calMs) * 100;

                const st = start.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
                const et = end.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
                const title = ev.first_name && ev.last_name ? `${ev.last_name} ${ev.first_name}` : ev.custom_title || 'Trénink';

                const div = document.createElement('div');
                div.className = 'event';
                div.style.top = topPct + '%';
                div.style.height = heiPct + '%';

                let html = `<span class="event-time">${st}–${et}</span>`;
                html += `<span class="event-title">${title}</span>`;
                if (ev.location) html += `<span class="event-location"><i class="fas fa-map-pin"></i> ${ev.location}</span>`;

                div.innerHTML = html;
                div.addEventListener('click', (e) => {
                    e.stopPropagation();
                    openModal(ev);
                });

                col.appendChild(div);
            });
        }

        function updateLocationHint() {
            const select = document.getElementById('locMode');
            const hint = document.getElementById('locationHint');
            if (!select || !hint) return;

            const option = select.options[select.selectedIndex];
            if (!option || !select.value) {
                hint.textContent = 'Vyberte existující místo, nebo zadejte vlastní.';
                return;
            }

            const parts = [option.dataset.address, option.dataset.note].filter(Boolean);
            hint.textContent = parts.length ? parts.join(' • ') : 'Místo je načtené z katalogu training_venues.';
        }

        function openModal(ev, startDate = null) {
            currentEventId = ev ? ev.id : null;
            document.querySelector('.modal-title').textContent = ev ? 'Upravit trénink' : 'Nový trénink';
            document.getElementById('deleteBtn').style.display = ev ? 'block' : 'none';

            document.getElementById('eventForm').reset();

            if (ev) {
                document.getElementById('eventId').value = ev.id;
                document.getElementById('athlete').value = ev.athlete_id || '';
                document.getElementById('title').value = ev.custom_title || '';
                document.getElementById('startTime').value = formatDateTime(sqlToDate(ev.starts_at));
                const mins = (sqlToDate(ev.ends_at) - sqlToDate(ev.starts_at)) / 60000;
                document.getElementById('duration').value = mins;
                
                if (ev.location) {
                    document.getElementById('locMode').value = venueNames.includes(ev.location) ? ev.location : '';
                    document.getElementById('location').value = ev.location;
                }
            } else {
                if (startDate) {
                    document.getElementById('startTime').value = formatDateTime(startDate);
                }
            }

            document.getElementById('errorMsg').style.display = 'none';
            updateLocationHint();
            modal.show();
        }

        document.getElementById('locMode').addEventListener('change', (e) => {
            if (e.target.value) {
                document.getElementById('location').value = e.target.value;
            }
            updateLocationHint();
        });

        document.getElementById('saveBtn').addEventListener('click', async () => {
            const start = document.getElementById('startTime').value;
            if (!start) {
                showError('Zvolte čas začátku');
                return;
            }

            const startD = new Date(start + ':00Z');
            const mins = parseInt(document.getElementById('duration').value);
            const endD = new Date(startD.getTime() + mins * 60000);

            const payload = {
                athlete_id: document.getElementById('athlete').value || null,
                custom_title: document.getElementById('title').value,
                starts_at: startD.toISOString().slice(0, 19).replace('T', ' '),
                ends_at: endD.toISOString().slice(0, 19).replace('T', ' '),
                location: document.getElementById('location').value || null,
                csrf_token: document.querySelector('[name="csrf_token"]').value
            };

            if (currentEventId) payload.id = currentEventId;

            try {
                const r = await fetch('/api/calendar_save_event.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await r.json();
                if (!data.success) {
                    showError(data.error || 'Chyba');
                    return;
                }

                modal.hide();
                loadEvents();
            } catch (e) {
                showError(e.message);
            }
        });

        document.getElementById('deleteBtn').addEventListener('click', async () => {
            if (!confirm('Smazat trénink?')) return;

            try {
                const r = await fetch('/api/calendar_delete_event.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: currentEventId,
                        csrf_token: document.querySelector('[name="csrf_token"]').value
                    })
                });

                const data = await r.json();
                if (!data.success) {
                    showError(data.error);
                    return;
                }

                modal.hide();
                loadEvents();
            } catch (e) {
                showError(e.message);
            }
        });

        document.getElementById('prevBtn').addEventListener('click', () => {
            currentDate.setDate(currentDate.getDate() - 7);
            renderDays();
            loadEvents();
        });

        document.getElementById('nextBtn').addEventListener('click', () => {
            currentDate.setDate(currentDate.getDate() + 7);
            renderDays();
            loadEvents();
        });

        function showError(msg) {
            const err = document.getElementById('errorMsg');
            err.textContent = msg;
            err.style.display = 'block';
        }

        // Init
        renderTimeSlots();
        renderDays();
        loadEvents();
    </script>
</body>
</html>
