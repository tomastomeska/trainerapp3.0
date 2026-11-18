'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('calendar.php', 'utf8').replace(/\r\n/g, '\n');

function extract(pattern) {
    const match = source.match(pattern);
    assert(match, 'Expected coach calendar code is missing.');
    return match[0].replace(/<\?= BASE_URL \?>/g, '');
}

const weekCode = extract(/    async function fetchJson\([\s\S]*?\n    \}\n\n    function shiftMonthValue/).replace(/\n\n    function shiftMonthValue$/, '');
const monthCode = extract(/    async function loadMonthListData\(\) \{[\s\S]*?\n    \}\n\n    function renderTrainingTiles/).replace(/\n\n    function renderTrainingTiles$/, '');
const refreshCode = extract(/    function refreshVisibleCalendar\(\) \{[\s\S]*?    document\.addEventListener\('visibilitychange', refreshVisibleCalendar\);[\s\S]*?\n    \}/);

function element(hidden = true) {
    const classes = new Set(hidden ? ['d-none'] : []);
    return {
        classList: {
            add: (value) => classes.add(value),
            remove: (value) => classes.delete(value),
            contains: (value) => classes.has(value),
        },
        addEventListener: function (_name, handler) { this.handler = handler; },
    };
}

function setup(fetch) {
    const nodes = {
        calendarRefreshStatus: element(),
        calendarRefreshRetry: element(),
        monthListRefreshStatus: element(),
        monthListRefreshRetry: element(),
    };
    const logs = [];
    const context = {
        fetch, URLSearchParams, Date,
        document: { visibilityState: 'visible', getElementById: (id) => nodes[id], addEventListener() {} },
        navigator: {},
        console: { error: (...args) => logs.push(args) },
        alert: (message) => { context.alerts.push(message); },
        alerts: [],
        weekDataRequestSeq: 0,
        monthListRequestSeq: 0,
        currentWeekStart: new Date(2030, 1, 4),
        navigationMonthValue: '2030-02',
        weekMonthJumpInput: null,
        monthListMonthInput: { value: '2030-02' },
        toDateKey: () => '2030-02-04',
        setCalendarNavBusy: (busy) => { context.busy = busy; },
        syncWeekRangeSelectWithCurrentWeek() {},
        rebuildRescheduleOriginEventIds() {},
        renderCalendar: () => { context.rendered = context.events.map((event) => event.id); },
        renderMonthList: (items) => { context.monthItems = items; },
        openEventModal: (event) => { context.opened = event.id; },
        events: [], locks: [], pendingFocusEventId: 7,
        monthItems: [{ id: 99 }],
    };
    vm.runInNewContext(weekCode + '\n' + monthCode + '\n' + refreshCode
        + '\nthis.loadWeekData = loadWeekData; this.loadMonthListData = loadMonthListData; this.refresh = refreshVisibleCalendar;', context);
    return { context, nodes, logs };
}

const response = (payload) => ({ json: async () => payload });
const tick = () => new Promise((resolve) => setImmediate(resolve));

(async () => {
    let monthFails = true;
    const isolated = setup(async (url, options) => {
        assert.strictEqual(options.cache, 'no-store');
        if (url.includes('calendar_month_list.php')) {
            if (monthFails) throw new Error('Month network failure');
            return response({ success: true, items: [{ id: 8 }] });
        }
        return response({ success: true, events: [{ id: 7 }], locks: [] });
    });
    await isolated.context.loadWeekData(true);
    assert.deepStrictEqual(isolated.context.rendered, [7]);
    assert.strictEqual(isolated.context.opened, 7);
    assert.strictEqual(isolated.context.monthItems[0].id, 99, 'A failed auxiliary request must retain the previous list.');
    assert(!isolated.nodes.monthListRefreshStatus.classList.contains('d-none'));
    assert(isolated.nodes.calendarRefreshStatus.classList.contains('d-none'));
    assert.strictEqual(isolated.logs.length, 1);
    monthFails = false;
    await isolated.nodes.monthListRefreshRetry.handler();
    assert.strictEqual(isolated.context.monthItems[0].id, 8);
    assert(isolated.nodes.monthListRefreshStatus.classList.contains('d-none'));

    const monthPending = [];
    const monthRace = setup(() => new Promise((resolve, reject) => monthPending.push({ resolve, reject })));
    const oldMonth = monthRace.context.loadMonthListData();
    const newMonth = monthRace.context.loadMonthListData();
    monthPending[1].resolve(response({ success: true, items: [{ id: 11 }] }));
    await newMonth;
    monthPending[0].reject(new Error('Old month request failed'));
    await oldMonth;
    assert.strictEqual(monthRace.context.monthItems[0].id, 11);
    assert(monthRace.nodes.monthListRefreshStatus.classList.contains('d-none'));
    assert.strictEqual(monthRace.logs.length, 0);

    let mode = 'network';
    const failed = setup(async (url) => {
        if (url.includes('calendar_month_list.php')) return response({ success: true, items: [] });
        if (mode === 'network') throw new Error('Week network failure');
        if (mode === 'server') return response({ success: false, error: 'Session expired' });
        return response({ success: true, events: [{ id: 7 }], locks: [] });
    });
    failed.context.refresh();
    await tick();
    assert(!failed.nodes.calendarRefreshStatus.classList.contains('d-none'));
    assert.strictEqual(failed.context.alerts.length, 0);
    assert.strictEqual(failed.context.busy, false);
    mode = 'server';
    failed.context.refresh();
    await tick();
    assert(!failed.nodes.calendarRefreshStatus.classList.contains('d-none'));
    assert.strictEqual(failed.logs.length, 2);
    mode = 'ok';
    failed.nodes.calendarRefreshRetry.handler();
    await tick();
    assert(failed.nodes.calendarRefreshStatus.classList.contains('d-none'));
    assert.deepStrictEqual(failed.context.rendered, [7]);
    failed.nodes.calendarRefreshStatus.classList.remove('d-none');
    await failed.context.loadWeekData();
    assert(failed.nodes.calendarRefreshStatus.classList.contains('d-none'), 'A successful manual refresh clears an earlier warning.');

    const pending = [];
    const race = setup((url) => {
        if (url.includes('calendar_month_list.php')) return Promise.resolve(response({ success: true, items: [] }));
        return new Promise((resolve, reject) => pending.push({ resolve, reject }));
    });
    race.context.refresh();
    race.context.refresh();
    pending[1].resolve(response({ success: true, events: [{ id: 7 }], locks: [] }));
    await tick();
    pending[0].reject(new Error('Old request failed'));
    await tick();
    assert.deepStrictEqual(race.context.rendered, [7]);
    assert.strictEqual(race.context.opened, 7);
    assert(race.nodes.calendarRefreshStatus.classList.contains('d-none'), 'An obsolete failure must not display an error.');
    assert.strictEqual(race.logs.length, 0);
    console.log('Coach calendar refresh isolation, retry and request ordering passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
