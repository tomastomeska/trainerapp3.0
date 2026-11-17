'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const php = fs.readFileSync(path.join(__dirname, '..', 'athlete_chat_mobile.php'), 'utf8');
const match = php.match(/<script>\s*(window\.trainerAppSuppressCoachChatAlerts = true;[\s\S]*?)<\/script>/);
assert(match, 'Legacy alert coordination script is missing.');
const script = match[1].replace(/<\?= json_encode\(csrfToken\(\),[\s\S]*?\?>/, '"test-csrf"');

async function check(devices, enabled, expected, reject) {
    let errors = 0;
    const window = {
        TrainerAppPushSubscriptions: {
            list: async () => {
                if (reject) throw new Error('test failure');
                return { devices };
            },
            getPreferences: async () => ({ preferences: { messages: enabled } })
        }
    };
    vm.runInNewContext(script, { window, Promise, console: { error: () => { errors++; } } });
    await new Promise((resolve) => setImmediate(resolve));
    assert.strictEqual(window.trainerAppSuppressCoachChatAlerts, expected);
    assert.strictEqual(errors, reject ? 1 : 0);
}

(async () => {
    await check([{ is_current: 1 }], true, true, false);
    await check([{ is_current: 0 }], true, false, false);
    await check([], false, true, false);
    await check([], true, true, true);
    console.log('Legacy chat alerts respect push registration and preferences.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
