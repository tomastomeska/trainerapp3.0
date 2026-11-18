'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('athlete_calendar.php', 'utf8').replace(/\r\n/g, '\n');

function extract(pattern) {
    const match = source.match(pattern);
    assert(match, 'Expected calendar code is missing.');
    return match[0].replace(/<\?= BASE_URL \?>/g, '');
}

const weekCode = extract(/    async function loadWeekData\(\) \{[\s\S]*?\n    \}\n\n    function shiftMonthValue/).replace(/\n\n    function shiftMonthValue$/, '');
const monthCode = extract(/    async function loadAthleteMonthList\(\) \{[\s\S]*?\n    \}\n\n    reserveLocationInput/).replace(/\n\n    reserveLocationInput$/, '');
const tilesCode = extract(/    async function loadAthleteTrainingTiles\(\) \{[\s\S]*?\n    \}\n\n    athleteTrainingTiles/).replace(/\n\n    athleteTrainingTiles$/, '');
const focusCode = extract(/    const calendarUrlParams = [\s\S]*?    let weekDataRequestSeq = 0;/);
const refreshCode = extract(/    function reportWeekRefreshError\(error\) \{[\s\S]*?    document\.addEventListener\('visibilitychange', refreshVisibleCalendar\);[\s\S]*?\n    \}/);
const dayPilotLoaderCode = extract(/    function ensureDayPilotLoaded\(\) \{[\s\S]*?\n    \}\n\n    async function loadWeekData/).replace(/\n\n    async function loadWeekData$/, '');

async function testForegroundRefresh(page) {
    const text = fs.readFileSync(page, 'utf8').replace(/\r\n/g, '\n');
    const match = text.match(/    function refreshVisibleCalendar\(\) \{[\s\S]*?\n    \}[\s\S]*?    document\.addEventListener\('visibilitychange', refreshVisibleCalendar\);[\s\S]*?\n    \}/);
    assert(match, 'Foreground refresh is missing from ' + page);
    let visibilityHandler;
    let messageHandler;
    let loads = 0;
    const document = {
        visibilityState: 'hidden',
        addEventListener: (_name, handler) => { visibilityHandler = handler; },
        getElementById: () => ({ addEventListener() {} }),
    };
    const navigator = { serviceWorker: { addEventListener: (_name, handler) => { messageHandler = handler; } } };
    vm.runInNewContext(match[0], {
        document, navigator, console, weekDataRequestSeq: 0, loadMonthListData() {}, requestWeekRefresh: () => { loads++; }, alert: () => { throw new Error('Unexpected refresh failure.'); },
        loadWeekData: async () => { loads++; },
    });
    visibilityHandler();
    assert.strictEqual(loads, 0);
    document.visibilityState = 'visible';
    visibilityHandler();
    messageHandler({ data: { type: 'trainerapp-notification-click' } });
    messageHandler({ data: { type: 'other' } });
    assert.strictEqual(loads, 2, 'Foreground and notification clicks must refresh the visible calendar.');
}

function focus(search) {
    const context = {
        window: { location: { search } },
        URLSearchParams,
        Date,
        getMonday: (date) => {
            const result = new Date(date);
            result.setDate(result.getDate() - ((result.getDay() + 6) % 7));
            result.setHours(0, 0, 0, 0);
            return result;
        },
        toDateKey: (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`,
    };
    vm.runInNewContext(focusCode + '\nthis.result = { currentWeekStart, pendingFocusEventId };', context);
    return context.result;
}

function calendarContext(fetch) {
    const logs = [];
    const monthBody = { innerHTML: '' };
    const trainingTiles = { innerHTML: '' };
    const classes = new Set(['d-none']);
    const retry = {};
    const errorDetail = { textContent: '' };
    const timers = [];
    const context = {
        fetch, URLSearchParams, Date,
        document: {
            visibilityState: 'visible',
            getElementById: (id) => id === 'athleteCalendarRefreshRetry' ? retry : (id === 'athleteCalendarRefreshError' ? errorDetail : {
                classList: { add: (value) => classes.add(value), remove: (value) => classes.delete(value) },
            }),
            addEventListener() {},
        },
        navigator: {},
        window: { setTimeout: (callback) => timers.push(callback) },
        console: { error: (...args) => logs.push(args) },
        alert: (text) => { throw new Error('Unexpected alert: ' + text); },
        weekRangeLabel: { textContent: '' },
        currentWeekStart: new Date(2030, 1, 4),
        weekDataRequestSeq: 0,
        getWeekRangeLabel: () => 'week',
        toDateKey: (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`,
        athleteWeekMonthJumpInput: null,
        syncAthleteWeekRangeSelectWithCurrentWeek() {},
        events: [], locks: [], dayPilotCalendar: {},
        pendingFocusEventId: 7,
        openEventDetailModal: (event) => { context.opened = event.id; },
        renderDayPilotCalendar() { context.rendered = context.events.map((event) => event.id); return true; },
        ensureDayPilotLoaded: async () => true,
        athleteMonthInput: { value: '2030-02' },
        athleteMonthListBody: monthBody,
        athleteMonthListEmpty: { classList: { add() {} } },
        renderAthleteMonthList: (items) => { context.monthItems = items; },
        athleteTrainingYearFilter: { value: '2030' },
        athleteTrainingStatusFilter: { value: 'approved' },
        athleteShowingCancelled: false,
        athleteTrainingMonth: '02',
        athleteTrainingTiles: trainingTiles,
        renderAthleteTrainingTiles: (items) => { context.tileItems = items; },
    };
    retry.addEventListener = (_name, handler) => { retry.handler = handler; };
    vm.runInNewContext(weekCode + '\n' + monthCode + '\n' + tilesCode + '\n' + refreshCode
        + '\nthis.loadWeekData = loadWeekData; this.requestWeekRefresh = requestWeekRefresh;', context);
    return { context, logs, monthBody, trainingTiles, classes, retry, timers, errorDetail };
}

(async () => {
    await testForegroundRefresh('athlete_calendar.php');
    await testForegroundRefresh('calendar.php');
    const focused = focus('?focus_date=2030-02-13&focus_event=41');
    assert.strictEqual(focused.currentWeekStart.getFullYear(), 2030);
    assert.strictEqual(focused.currentWeekStart.getMonth(), 1);
    assert.strictEqual(focused.currentWeekStart.getDate(), 11);
    assert.strictEqual(focused.pendingFocusEventId, 41);
    assert.strictEqual(focus('?focus_date=2030-02-31').currentWeekStart.getTime(), focus('').currentWeekStart.getTime());

    const requests = [];
    const result = calendarContext(async (url, options) => {
        requests.push({ url, options });
        if (url.includes('athlete_calendar_month_list.php')) throw new Error('Network failed');
        if (url.includes('athlete_calendar_training_list.php')) return { ok: true, json: async () => ({ success: true, items: [{ id: 8 }] }) };
        return { ok: true, json: async () => ({ success: true, events: [{ id: 7 }], locks: [] }) };
    });
    await result.context.loadWeekData();
    assert.deepStrictEqual(result.context.rendered, [7]);
    assert.strictEqual(result.context.opened, 7, 'An accessible event must open without waiting for auxiliary lists.');
    assert.strictEqual(result.context.tileItems[0].id, 8);
    assert.match(result.monthBody.innerHTML, /Načtení měsíčního seznamu selhalo/);
    assert.strictEqual(result.logs.length, 1);
    assert(requests.every((request) => request.options.cache === 'no-store'), 'Every calendar view must request fresh data.');

    const pending = [];
    const race = calendarContext((url, options) => {
        if (!url.includes('athlete_calendar_data.php')) {
            return Promise.resolve({ ok: true, json: async () => ({ success: true, items: [] }) });
        }
        return new Promise((resolve) => pending.push({ resolve, options }));
    });
    const oldRequest = race.context.loadWeekData();
    race.context.pendingFocusEventId = 1;
    race.context.currentWeekStart = new Date(2030, 1, 11);
    const newRequest = race.context.loadWeekData();
    pending[1].resolve({ ok: true, json: async () => ({ success: true, events: [{ id: 2 }], locks: [] }) });
    await newRequest;
    pending[0].resolve({ ok: true, json: async () => ({ success: true, events: [{ id: 1 }], locks: [] }) });
    await oldRequest;
    assert.deepStrictEqual(race.context.rendered, [2], 'An older response must not overwrite the selected week.');
    assert.strictEqual(race.context.opened, undefined, 'A stale event must not open.');

    const failingPending = [];
    const failedRace = calendarContext((url) => {
        if (!url.includes('athlete_calendar_data.php')) {
            return Promise.resolve({ ok: true, json: async () => ({ success: true, items: [] }) });
        }
        return new Promise((resolve, reject) => failingPending.push({ resolve, reject }));
    });
    failedRace.context.requestWeekRefresh(true);
    failedRace.context.requestWeekRefresh(true);
    failingPending[1].resolve({ ok: true, json: async () => ({ success: true, events: [{ id: 7 }], locks: [] }) });
    await new Promise((resolve) => setImmediate(resolve));
    failingPending[0].reject(new Error('Old request interrupted'));
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepStrictEqual(failedRace.context.rendered, [7]);
    assert.strictEqual(failedRace.context.opened, 7);
    assert(failedRace.classes.has('d-none'), 'A stale failure must not display a warning.');
    assert.strictEqual(failedRace.logs.length, 0);
    assert.strictEqual(failedRace.timers.length, 0);

    let attempts = 0;
    const recovery = calendarContext(async (url) => {
        if (!url.includes('athlete_calendar_data.php')) {
            return { ok: true, json: async () => ({ success: true, items: [] }) };
        }
        attempts++;
        if (attempts === 1) throw new Error('Connection interrupted');
        return { ok: true, json: async () => ({ success: true, events: [{ id: 7 }], locks: [] }) };
    });
    recovery.context.requestWeekRefresh(true);
    await new Promise((resolve) => setImmediate(resolve));
    assert(!recovery.classes.has('d-none'));
    assert.match(recovery.errorDetail.textContent, /Connection interrupted/);
    assert.strictEqual(recovery.context.pendingFocusEventId, 7);
    assert.strictEqual(recovery.timers.length, 1);
    recovery.timers[0]();
    await new Promise((resolve) => setImmediate(resolve));
    assert(recovery.classes.has('d-none'));
    assert.strictEqual(recovery.context.opened, 7, 'Retry must still open the target event.');
    assert.strictEqual(attempts, 2);

    let failedAttempts = 0;
    const persistentFailure = calendarContext(async () => {
        failedAttempts++;
        throw new Error('Still offline');
    });
    persistentFailure.context.requestWeekRefresh(true);
    await new Promise((resolve) => setImmediate(resolve));
    persistentFailure.timers[0]();
    await new Promise((resolve) => setImmediate(resolve));
    assert.strictEqual(failedAttempts, 2, 'Automatic retry must be bounded.');
    assert.strictEqual(persistentFailure.timers.length, 1);
    assert(!persistentFailure.classes.has('d-none'));

    let supersededAttempts = 0;
    const superseded = calendarContext(async (url) => {
        if (!url.includes('athlete_calendar_data.php')) {
            return { ok: true, json: async () => ({ success: true, items: [] }) };
        }
        supersededAttempts++;
        if (supersededAttempts === 1) throw new Error('First request failed');
        return { ok: true, json: async () => ({ success: true, events: [{ id: 7 }], locks: [] }) };
    });
    superseded.context.requestWeekRefresh(true);
    await new Promise((resolve) => setImmediate(resolve));
    superseded.context.requestWeekRefresh();
    await new Promise((resolve) => setImmediate(resolve));
    superseded.timers[0]();
    await new Promise((resolve) => setImmediate(resolve));
    assert.strictEqual(supersededAttempts, 2, 'An obsolete timer must not start another refresh.');
    assert(superseded.classes.has('d-none'));

    let apiFails = true;
    const serverFailure = calendarContext(async (url) => {
        if (url.includes('athlete_calendar_data.php') && apiFails) {
            return { ok: true, json: async () => ({ success: false, error: 'Nepřihlášen' }) };
        }
        return { ok: true, json: async () => ({ success: true, events: [{ id: 7 }], locks: [], items: [] }) };
    });
    serverFailure.context.requestWeekRefresh();
    await new Promise((resolve) => setImmediate(resolve));
    assert(!serverFailure.classes.has('d-none'));
    assert.match(serverFailure.errorDetail.textContent, /Nepřihlášen/);
    apiFails = false;
    serverFailure.retry.handler();
    await new Promise((resolve) => setImmediate(resolve));
    assert(serverFailure.classes.has('d-none'));
    assert.strictEqual(serverFailure.context.opened, 7);

    const scripts = [];
    const loader = {
        window: {},
        document: { createElement: () => ({}), head: { appendChild: (script) => scripts.push(script) } },
        dayPilotLoadPromise: null,
    };
    vm.runInNewContext(dayPilotLoaderCode + '\nthis.ensureDayPilotLoaded = ensureDayPilotLoaded;', loader);
    const firstLoad = loader.ensureDayPilotLoaded();
    scripts[0].onerror();
    assert.strictEqual(await firstLoad, false);
    const secondLoad = loader.ensureDayPilotLoaded();
    assert.strictEqual(scripts.length, 2, 'A failed library load must be retryable.');
    loader.window.DayPilot = { Calendar: function () {} };
    scripts[1].onload();
    assert.strictEqual(await secondLoad, true);
    console.log('Calendar fresh-data requests, list failure isolation and notification date focus passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
