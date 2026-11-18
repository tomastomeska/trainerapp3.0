'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

async function workerPush(payload, failBadge = false) {
    const handlers = {};
    const counts = [];
    const notifications = [];
    let errors = 0;
    const self = {
        location: { origin: 'https://example.com' },
        navigator: {
            setAppBadge: async (count) => {
                if (failBadge) throw new Error('test failure');
                counts.push(count);
            }
        },
        registration: { showNotification: async (title) => { notifications.push(title); } },
        addEventListener: (name, handler) => { handlers[name] = handler; }
    };
    vm.runInNewContext(fs.readFileSync('sw.js', 'utf8'), {
        self, URL, Promise, console: { error() { errors++; } }
    });
    let task;
    handlers.push({ data: { json: () => payload }, waitUntil: (promise) => { task = promise; } });
    await task;
    return { counts, notifications, errors };
}

async function pageBadge() {
    let load;
    let interval;
    let count = 2;
    let fails = false;
    const counts = [];
    const requests = [];
    const window = {
        isSecureContext: true,
        addEventListener: (name, handler) => { if (name === 'load') load = handler; },
        setInterval: (handler) => { interval = handler; }
    };
    const document = {
        currentScript: { getAttribute: () => 'test-csrf' },
        visibilityState: 'visible',
        addEventListener() {}
    };
    const navigator = {
        serviceWorker: {
            addEventListener() {},
            ready: new Promise(() => {}),
            register: async () => ({})
        },
        setAppBadge: async (value) => { counts.push(value); }
    };
    const fetch = async (_url, request) => {
        requests.push(JSON.parse(request.body));
        return { ok: !fails, json: async () => fails ? { success: false } : { success: true, unread_count: count } };
    };
    vm.runInNewContext(fs.readFileSync('assets/js/pwa.js', 'utf8'), {
        window, document, navigator, fetch, URL, console: { error() {} }
    });
    load();
    await new Promise(setImmediate);
    assert.deepStrictEqual(counts, [2]);
    assert.strictEqual(requests[0].action, 'get_unread_count');
    assert.strictEqual(requests[0].csrf_token, 'test-csrf');
    count = 0;
    await interval();
    assert.deepStrictEqual(counts, [2, 0]);
    fails = true;
    await interval();
    assert.deepStrictEqual(counts, [2, 0]);
}

(async () => {
    let result = await workerPush({ unread_count: 3 });
    assert.deepStrictEqual(result.counts, [3]);
    assert.deepStrictEqual(result.notifications, ['TrainerApp']);
    result = await workerPush({ unread_count: 0 });
    assert.deepStrictEqual(result.counts, [0]);
    result = await workerPush({ unread_count: -1 });
    assert.deepStrictEqual(result.counts, []);
    assert.deepStrictEqual(result.notifications, ['TrainerApp']);
    result = await workerPush({ unread_count: 2 }, true);
    assert.strictEqual(result.errors, 1);
    assert.deepStrictEqual(result.notifications, ['TrainerApp']);
    await pageBadge();
    console.log('Push badge count, clearing and notification failure isolation passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
