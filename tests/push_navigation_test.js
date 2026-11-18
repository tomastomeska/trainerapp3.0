'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

function fakeIndexedDb() {
    const records = new Map();
    return {
        records,
        open() {
            const request = {};
            const db = {
                createObjectStore() {},
                close() {},
                transaction() {
                    const transaction = {};
                    transaction.objectStore = () => ({
                        put(value, key) {
                            records.set(key, value);
                            setImmediate(() => transaction.oncomplete());
                        },
                        get(key) {
                            const get = {};
                            setImmediate(() => {
                                get.result = records.get(key);
                                get.onsuccess();
                                setImmediate(() => transaction.oncomplete());
                            });
                            return get;
                        },
                        delete(key) { records.delete(key); }
                    });
                    return transaction;
                }
            };
            setImmediate(() => {
                request.result = db;
                request.onupgradeneeded();
                request.onsuccess();
            });
            return request;
        }
    };
}

function worker() {
    const handlers = {};
    const indexedDB = fakeIndexedDb();
    const opened = [];
    const self = {
        location: { origin: 'https://example.com' },
        addEventListener: (name, handler) => { handlers[name] = handler; },
        clients: {
            matchAll: async () => [],
            openWindow: async (target) => { opened.push(target); return null; }
        }
    };
    vm.runInNewContext(fs.readFileSync('sw.js', 'utf8'), { self, indexedDB, URL, console, Date });
    return { handlers, indexedDB, opened };
}

async function click(test, url) {
    let task;
    test.handlers.notificationclick({
        notification: { data: { url }, close() {} },
        waitUntil: (promise) => { task = promise; }
    });
    await task;
}

async function consume(test, origin = 'https://example.com/dashboard') {
    let task;
    let result;
    test.handlers.message({
        data: { type: 'trainerapp-consume-notification-target' },
        source: { url: origin },
        ports: [{ postMessage: (value) => { result = value; } }],
        waitUntil: (promise) => { task = promise; }
    });
    if (task) await task;
    return result;
}

async function pageNavigation(target) {
    let load;
    let assigned = null;
    let workerMessage;
    const serviceWorker = {
        addEventListener: (_name, handler) => { workerMessage = handler; },
        ready: Promise.resolve({
            active: {
                postMessage: (_data, ports) => {
                    setImmediate(() => ports[0].peer.onmessage({ data: { url: target } }));
                }
            }
        }),
        register: async () => ({})
    };
    class MessageChannel {
        constructor() {
            this.port1 = { close() {} };
            this.port2 = { peer: this.port1 };
        }
    }
    const location = {
        origin: 'https://example.com',
        href: 'https://example.com/athlete_dashboard.php',
        assign: (url) => { assigned = url; }
    };
    const window = {
        isSecureContext: true,
        location,
        addEventListener: (name, handler) => { if (name === 'load') load = handler; }
    };
    const document = { addEventListener() {} };
    vm.runInNewContext(fs.readFileSync('assets/js/pwa.js', 'utf8'), {
        window, navigator: { serviceWorker }, document, MessageChannel, URL, setTimeout, clearTimeout, console
    });
    load();
    await new Promise((resolve) => setTimeout(resolve, 20));
    assert.strictEqual(typeof workerMessage, 'function');
    return assigned;
}

(async () => {
    const test = worker();
    await click(test, '/athlete_chat_mobile.php?target=coach');
    assert.deepStrictEqual(test.opened, ['https://example.com/athlete_chat_mobile.php?target=coach']);
    assert.strictEqual(await consume(test, 'https://other.example/'), undefined);
    assert.strictEqual((await consume(test)).url, 'https://example.com/athlete_chat_mobile.php?target=coach');
    assert.strictEqual((await consume(test)).url, null);

    await click(test, '/athlete_chat_mobile.php?target=coach');
    test.indexedDB.records.get('target').expiresAt = Date.now() - 1;
    assert.strictEqual((await consume(test)).url, null);
    assert.strictEqual(test.indexedDB.records.size, 0);

    await click(test, 'https://other.example/unsafe');
    assert.strictEqual((await consume(test)).url, 'https://example.com/login.php');

    assert.strictEqual(
        await pageNavigation('https://example.com/athlete_chat_mobile.php?target=coach'),
        'https://example.com/athlete_chat_mobile.php?target=coach'
    );
    assert.strictEqual(await pageNavigation('https://other.example/unsafe'), null);
    assert.strictEqual(await pageNavigation('https://example.com/athlete_dashboard.php'), null);
    console.log('Notification navigation persistence, expiry and same-origin checks passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
