'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

function extract(source, pattern, nextFunction) {
    const match = source.match(pattern);
    assert(match, 'Expected calendar function is missing.');
    return match[0].replace(new RegExp('\\n\\n    ' + nextFunction + '$'), '')
        .replace(/<\?= BASE_URL \?>/g, '');
}

function setup(page) {
    const source = fs.readFileSync(page, 'utf8').replace(/\r\n/g, '\n');
    const next = page === 'athlete_calendar.php' ? 'function ensureDayPilotLoaded' : 'function fromSqlDateTime';
    const render = extract(source,
        new RegExp('    function renderDayPilotCalendar\\(\\) \\{[\\s\\S]*?\\n    \\}\\n\\n    ' + next),
        next);
    const now = new Date();
    const monday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7));
    const statuses = new Set(['d-none']);
    const library = {
        Calendar: function () {
            this.init = () => {};
            this.update = (value) => { context.updated = value; };
        },
    };
    const context = {
        window: { DayPilot: library },
        DayPilot: library,
        Date, URLSearchParams,
        daypilotCalendarEl: { style: { setProperty() {} } },
        dayPilotCalendar: null,
        currentWeekStart: monday,
        currentTimeSlotKey: '',
        calendarCellHeight: 56,
        hourStart: 5,
        hourEnd: 22,
        toDateKey: (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`,
        addDays: (date, days) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + days),
        events: [], locks: [],
        toDayPilotEvent: (event) => event,
        toDayPilotLockEvent: (lock) => lock,
        calendarGrid: { closest: () => ({ classList: { add() {} } }) },
        daypilotCard: { classList: { remove() {} } },
        document: { getElementById: () => ({ classList: { add: (value) => statuses.add(value) } }) },
        fetch: async () => ({ ok: true, json: async () => ({ success: true, events: [{ id: 7 }], locks: [] }) }),
        weekDataRequestSeq: 0,
        weekRangeLabel: { textContent: '' },
        getWeekRangeLabel: () => 'current week',
        athleteWeekMonthJumpInput: null,
        syncAthleteWeekRangeSelectWithCurrentWeek() {},
        pendingFocusEventId: 7,
        openEventDetailModal: (event) => { context.opened = event.id; },
        loadAthleteMonthList: async () => {},
        loadAthleteTrainingTiles: async () => {},
    };
    let code = render;
    if (page === 'athlete_calendar.php') {
        code += '\n' + extract(source, /    async function loadWeekData\(\) \{[\s\S]*?\n    \}\n\n    function shiftMonthValue/, 'function shiftMonthValue');
    }
    vm.runInNewContext(code + '\nthis.renderDayPilotCalendar = renderDayPilotCalendar;'
        + (page === 'athlete_calendar.php' ? ' this.loadWeekData = loadWeekData;' : ''), context);
    return context;
}

(async () => {
    const athlete = setup('athlete_calendar.php');
    await athlete.loadWeekData();
    assert.strictEqual(athlete.updated.events[0].id, 7);
    assert.strictEqual(athlete.opened, 7, 'A notification target must open after rendering.');
    assert.strictEqual(athlete.weekRangeLabel.textContent, 'current week');
    const coach = setup('calendar.php');
    assert.strictEqual(coach.renderDayPilotCalendar(), true);
    assert(coach.updated, 'The coach calendar must render without scrollToHour.');
    console.log('Both calendars render without unsupported scrolling and the athlete notification target opens.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
