'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('athlete_calendar.php', 'utf8').replace(/\r\n/g, '\n');
const match = source.match(/    async function cancelMyEvent\(eventId, onSuccess = null\) \{[\s\S]*?\n    \}\n\n    function renderDayPilotCalendar/);
assert(match, 'Calendar cancellation function is missing.');
const code = match[0].replace(/\n\n    function renderDayPilotCalendar$/, '').replace(/<\?= BASE_URL \?>/g, '');
assert(source.includes('await cancelMyEvent(eventId, closeEventDetailModal);'), 'Detail modal must close on successful deletion.');

async function testOpeningTransition() {
    const closeMatch = source.match(/    function closeEventDetailModal\(\) \{[\s\S]*?\n    \}\n\n    async function cancelMyEvent/);
    assert(closeMatch, 'Detail modal close function is missing.');
    const closeCode = closeMatch[0].replace(/\n\n    async function cancelMyEvent$/, '');
    const listeners = new Map();
    let visible = false;
    let hides = 0;
    let opening = true;
    const modalEl = {
        classList: { contains: () => visible },
        addEventListener: (name, fn) => listeners.set(name, fn),
        removeEventListener: (name) => listeners.delete(name),
    };
    const context = {
        eventDetailModalActive: true,
        eventDetailModalEl: modalEl,
        eventDetailModal: { hide: () => {
            hides++;
            if (!opening) {
                visible = false;
                listeners.get('hidden.bs.modal')();
            }
        } },
        setTimeout,
        clearTimeout,
    };
    vm.runInNewContext(closeCode + '\nthis.closeEventDetailModal = closeEventDetailModal;', context);
    const closing = context.closeEventDetailModal();
    assert.strictEqual(hides, 1, 'Closing must be attempted during the opening transition.');
    visible = true;
    opening = false;
    listeners.get('shown.bs.modal')();
    await closing;
    assert.strictEqual(hides, 2, 'Closing must be retried after the opening transition.');
    assert.strictEqual(visible, false);
    assert.strictEqual(listeners.size, 0);
}

async function run(payload, refreshFails) {
    const calls = [];
    const context = {
        csrfToken: 'test',
        fetch: async () => {
            if (payload === null) throw new Error('Connection lost');
            return { json: async () => payload };
        },
        alert: (message) => calls.push(['alert', message]),
        console: { error: () => calls.push(['error']) },
        invalidateReserveMakeupSuggestionCache: () => calls.push(['invalidate']),
        loadWeekData: async () => {
            calls.push(['refresh']);
            if (refreshFails) throw new Error('Refresh failed');
        },
    };
    vm.runInNewContext(code + '\nthis.cancelMyEvent = cancelMyEvent;', context);
    await context.cancelMyEvent(1, () => calls.push(['hide']));
    return calls;
}

(async () => {
    await testOpeningTransition();
    const success = await run({ success: true, message: 'Zrušeno' }, false);
    assert.deepStrictEqual(success.map((call) => call[0]), ['hide', 'alert', 'invalidate', 'refresh']);
    const failedRefresh = await run({ success: true }, true);
    assert.deepStrictEqual(failedRefresh.map((call) => call[0]), ['hide', 'invalidate', 'refresh', 'error', 'alert']);
    const failedDelete = await run({ success: false, error: 'Nelze zrušit' }, false);
    assert.deepStrictEqual(failedDelete.map((call) => call[0]), ['alert']);
    const lostResponse = await run(null, false);
    assert.deepStrictEqual(lostResponse.map((call) => call[0]), ['error', 'alert']);
    console.log('Calendar modal closes after successful cancellation, even if refresh fails.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
