'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('assets/js/push-subscriptions.js', 'utf8');
const publicKeyBytes = Buffer.concat([Buffer.from([4]), Buffer.alloc(64, 1)]);
const publicKey = publicKeyBytes.toString('base64url');

function setup(options) {
    const calls = [];
    let unsubscribeCalls = 0;
    let subscribeCalls = 0;
    const subscription = options.subscription || null;
    const registration = {
        pushManager: {
            getSubscription: async () => subscription,
            subscribe: async () => {
                subscribeCalls++;
                return {
                    endpoint: 'https://fcm.googleapis.com/new',
                    toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/new', keys: {} })
                };
            }
        }
    };
    const notification = { permission: 'granted', requestPermission: async () => 'granted' };
    const window = {
        isSecureContext: options.supported !== false,
        PushManager: function () {},
        Notification: notification,
        atob: (value) => Buffer.from(value, 'base64').toString('binary'),
        confirm: () => options.confirm === true
    };
    const navigator = { userAgent: 'test' };
    if (options.supported !== false) {
        navigator.serviceWorker = {
            getRegistration: async () => registration,
            register: async () => registration,
            ready: Promise.resolve(registration)
        };
    }
    const fetch = async (_url, request) => {
        const payload = JSON.parse(request.body);
        calls.push(payload.action);
        const result = payload.action === 'list'
            ? { success: true, devices: options.devices || [] }
            : { success: true, subscription_id: 7 };
        return { ok: true, json: async () => result };
    };
    const context = { window, navigator, Notification: notification, fetch, Uint8Array, Promise };
    vm.runInNewContext(source, context);
    if (subscription) {
        subscription.unsubscribe = async () => {
            unsubscribeCalls++;
            return options.unsubscribeResult !== false;
        };
    }
    return {
        api: window.TrainerAppPushSubscriptions,
        calls,
        unsubscribed: () => unsubscribeCalls,
        subscribed: () => subscribeCalls
    };
}

function currentSubscription(keyBytes) {
    return {
        endpoint: 'https://fcm.googleapis.com/current',
        options: { applicationServerKey: Uint8Array.from(keyBytes).buffer },
        toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/current', keys: {} })
    };
}

(async () => {
    let test = setup({ supported: false });
    await test.api.list('csrf');
    assert.deepStrictEqual(test.calls, ['list']);

    test = setup({ subscription: currentSubscription(publicKeyBytes), confirm: false });
    await assert.rejects(test.api.subscribe(publicKey, 'csrf'));
    assert.deepStrictEqual(test.calls, ['list']);
    assert.strictEqual(test.unsubscribed(), 0);

    test = setup({ subscription: currentSubscription(publicKeyBytes), devices: [{ id: 7, is_current: 1 }] });
    await test.api.subscribe(publicKey, 'csrf');
    assert.deepStrictEqual(test.calls, ['list', 'register']);
    assert.strictEqual(test.unsubscribed(), 0);

    const differentKey = Buffer.concat([Buffer.from([4]), Buffer.alloc(64, 2)]);
    test = setup({ subscription: currentSubscription(differentKey), confirm: false });
    await assert.rejects(test.api.subscribe(publicKey, 'csrf'));
    assert.strictEqual(test.unsubscribed(), 0);
    assert.strictEqual(test.subscribed(), 0);

    test = setup({ subscription: currentSubscription(differentKey), confirm: true });
    await test.api.subscribe(publicKey, 'csrf');
    assert.strictEqual(test.unsubscribed(), 1);
    assert.strictEqual(test.subscribed(), 1);
    assert.deepStrictEqual(test.calls, ['register']);

    test = setup({
        subscription: currentSubscription(publicKeyBytes),
        devices: [{ id: 7, is_current: 1 }],
        unsubscribeResult: false
    });
    await assert.rejects(test.api.remove(7, 'csrf'));
    assert.deepStrictEqual(test.calls, ['list', 'revoke']);

    test = setup({ subscription: currentSubscription(publicKeyBytes), devices: [] });
    await test.api.disable('csrf');
    assert.deepStrictEqual(test.calls, ['list', 'disable']);
    assert.strictEqual(test.unsubscribed(), 0);

    console.log('Push subscription ownership and device-management tests passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
