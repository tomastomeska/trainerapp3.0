'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

async function click(clients, url) {
    const handlers = {};
    const opened = [];
    const self = {
        location: { origin: 'https://example.com' },
        addEventListener: (name, handler) => { handlers[name] = handler; },
        clients: {
            matchAll: async () => clients,
            openWindow: async (target) => { opened.push(target); }
        }
    };
    const indexedDB = { open() { throw new Error('test storage failure'); } };
    vm.runInNewContext(fs.readFileSync('sw.js', 'utf8'), { self, indexedDB, URL, console: { error() {}, warn() {} } });
    let task;
    handlers.notificationclick({
        notification: { data: { url }, close() {} },
        waitUntil: (promise) => { task = promise; }
    });
    await task;
    return opened;
}

(async () => {
    let focused = false;
    const client = {
        url: 'https://example.com/current',
        navigate: async (url) => {
            assert.strictEqual(url, 'https://example.com/target');
            return { focus: async () => { focused = true; }, postMessage() {} };
        }
    };
    assert.deepStrictEqual(await click([client], '/target'), []);
    assert.strictEqual(focused, true);

    const failing = { url: 'https://example.com/current', navigate: async () => { throw new Error('test'); } };
    assert.deepStrictEqual(await click([failing], '/target'), ['https://example.com/target']);
    assert.deepStrictEqual(await click([], 'https://evil.example/'), ['https://example.com/login.php']);
    console.log('Notification click navigation and fallback passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
