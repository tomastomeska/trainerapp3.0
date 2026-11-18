'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('assets/js/push-settings.js', 'utf8');

class Element {
    constructor(id) {
        this.id = id;
        this.disabled = true;
        this.textContent = '';
        this.children = [];
        this.handlers = {};
        this.dataset = {};
        this.classList = { toggle() {}, add() {} };
    }
    getAttribute(name) { return name === 'data-csrf' ? 'csrf' : null; }
    addEventListener(name, handler) { this.handlers[name] = handler; }
    appendChild(child) { this.children.push(child); }
    replaceChildren() { this.children = []; }
    querySelectorAll(selector) {
        if (selector === '[data-push-category]') return this.children;
        if (selector === 'button') return this.children.flatMap((item) => item.children.filter((child) => child.type === 'button'));
        return [];
    }
    matches(selector) { return selector === '[data-push-category]' && Boolean(this.dataset.pushCategory); }
}

async function setup(options) {
    const elements = {};
    for (const id of ['push-settings', 'push-status', 'push-activate', 'push-disable-all', 'push-devices', 'push-categories']) {
        elements[id] = new Element(id);
    }
    for (const category of ['messages', 'calendar', 'online_training', 'athlete', 'payments', 'surveys', 'info_channel', 'gallery']) {
        const input = new Element(category);
        input.dataset.pushCategory = category;
        elements['push-categories'].children.push(input);
    }
    const preferences = Object.fromEntries(elements['push-categories'].children.map((input) => [input.id, true]));
    let publicKeyCalls = 0;
    let preferenceLoads = 0;
    const preferenceCalls = [];
    const api = {
        isSupported: () => options.supported,
        list: async () => {
            if (options.listError) throw new Error('list failed');
            return { devices: options.devices || [] };
        },
        getPreferences: async () => {
            preferenceLoads++;
            if (options.preferenceReloadError && preferenceLoads > 1) throw new Error('reload failed');
            return { preferences };
        },
        getPublicKey: async () => {
            publicKeyCalls++;
            if (options.keyError) throw new Error('key failed');
            return 'key';
        },
        setPreference: async (category, enabled, csrf) => {
            if (options.preferenceError) throw new Error('save failed');
            preferenceCalls.push([category, enabled, csrf]);
        }
    };
    const context = {
        document: { getElementById: (id) => elements[id], createElement: (tag) => new Element(tag) },
        window: { TrainerAppPushSubscriptions: api, confirm: () => true },
        navigator: { userAgent: 'test', platform: 'test', maxTouchPoints: 0 },
        Notification: { permission: options.permission || 'default' }
    };
    vm.runInNewContext(source, context);
    await new Promise(setImmediate);
    return { elements, publicKeyCalls: () => publicKeyCalls, preferenceCalls };
}

(async () => {
    const unavailableStatus = new Element('push-status');
    vm.runInNewContext(source, {
        document: { getElementById: (id) => id === 'push-settings' ? new Element(id) : unavailableStatus },
        window: {}
    });
    assert.match(unavailableStatus.textContent, /skript/);

    let test = await setup({
        supported: false,
        devices: [{ id: 1, device_name: '<img src=x>', is_current: 0, registered_at: 'a', last_activity_at: 'b' }]
    });
    assert.strictEqual(test.elements['push-activate'].disabled, true);
    assert.strictEqual(test.elements['push-categories'].disabled, false);
    assert.strictEqual(test.publicKeyCalls(), 0);
    assert.strictEqual(test.elements['push-devices'].children[0].children[0].children[0].textContent, '<img src=x>');

    test = await setup({ supported: true, permission: 'denied', devices: [{ id: 1, is_current: 1 }] });
    assert.strictEqual(test.elements['push-activate'].disabled, true);

    test = await setup({ supported: true, permission: 'granted', devices: [{ id: 1, is_current: 1 }] });
    assert.strictEqual(test.elements['push-activate'].disabled, false);
    assert.match(test.elements['push-status'].textContent, /registrov/);
    for (const category of ['surveys', 'info_channel', 'gallery']) {
        const categoryInput = test.elements['push-categories'].children.find((item) => item.id === category);
        assert.strictEqual(categoryInput.checked, true);
        categoryInput.checked = false;
        await test.elements['push-categories'].handlers.change({ target: categoryInput });
    }
    assert.deepStrictEqual(test.preferenceCalls, [
        ['surveys', false, 'csrf'], ['info_channel', false, 'csrf'], ['gallery', false, 'csrf'],
    ]);

    test = await setup({ supported: true, listError: true });
    assert.strictEqual(test.elements['push-activate'].disabled, true);
    assert.strictEqual(test.elements['push-categories'].disabled, false);
    assert.match(test.elements['push-status'].textContent, /list failed/);

    test = await setup({ supported: true, preferenceError: true });
    const input = test.elements['push-categories'].children[0];
    input.checked = false;
    await test.elements['push-categories'].handlers.change({ target: input });
    assert.strictEqual(input.checked, true);
    assert.match(test.elements['push-status'].textContent, /save failed/);

    test = await setup({ supported: true, preferenceError: true, preferenceReloadError: true });
    const unavailableInput = test.elements['push-categories'].children[0];
    unavailableInput.checked = false;
    await test.elements['push-categories'].handlers.change({ target: unavailableInput });
    assert.strictEqual(test.elements['push-categories'].disabled, true);

    console.log('Push settings permission, device display and preference failure tests passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
