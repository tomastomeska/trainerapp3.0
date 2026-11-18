'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

async function testAthletePolling() {
    const source = fs.readFileSync('athlete_chat_mobile.php', 'utf8');
    const match = source.match(/(function pollNew\(\) \{[\s\S]*?)\n    setInterval\(pollNew, 5000\);/);
    assert(match, 'Athlete chat polling function is missing.');
    const code = match[1].replace(/<\?= json_encode\(\$target\) \?>/g, '"coach"');
    const requests = [];
    const document = { hidden: true };
    const context = {
        document,
        apiUrl: '/api/chat',
        lastId: 5,
        fetch: (url) => {
            requests.push(url);
            return Promise.resolve({ json: async () => ({ ok: true, messages: [] }) });
        }
    };
    vm.runInNewContext(code + '\npollNew();', context);
    await new Promise(setImmediate);
    assert.strictEqual(requests[0], '/api/chat?action=poll&since_id=5&mark_read=0');
    document.hidden = false;
    vm.runInNewContext('pollNew();', context);
    await new Promise(setImmediate);
    assert.strictEqual(requests[1], '/api/chat?action=poll&since_id=5');
}

async function testWidgetPolling() {
    const source = fs.readFileSync('includes/support_widget.php', 'utf8');
    const match = source.match(/(const pollNewMessages = async \(\) => \{[\s\S]*?\n    \};)/);
    assert(match, 'Chat widget polling function is missing.');
    let requests = 0;
    const document = { hidden: true };
    const context = {
        document,
        currentThread: { apiUrl: '/api/chat', extraQuery: '' },
        lastId: 5,
        fetch: async () => { requests++; return { json: async () => ({ ok: true, messages: [] }) }; }
    };
    vm.runInNewContext(match[1] + '\nthis.poll = pollNewMessages;', context);
    await context.poll();
    assert.strictEqual(requests, 0);
    document.hidden = false;
    await context.poll();
    assert.strictEqual(requests, 1);
}

function testWidgetBadges() {
    const source = fs.readFileSync('includes/support_widget.php', 'utf8');
    const match = source.match(/    const totalUnread = \(\) =>[\s\S]*?\n    \};/);
    assert(match, 'Widget badge update is missing.');
    assert(source.includes('id="supportFabMenuBadge"') && source.includes('.support-fab-stack.is-open #supportFabMenuBadge'), 'Menu badge must exist and be hidden when actions are open.');
    function badge() {
        return {
            textContent: '', hidden: false, attributes: {},
            classList: { toggle(_name, hidden) { this.owner.hidden = hidden; } },
            setAttribute(name, value) { this.attributes[name] = value; }
        };
    }
    const chatBadge = badge();
    const menuBadge = badge();
    chatBadge.classList.owner = chatBadge;
    menuBadge.classList.owner = menuBadge;
    const unreadByKey = { admin: 1, peer: 2 };
    const context = { chatBadge, menuBadge, unreadByKey };
    vm.runInNewContext(match[0] + '\nthis.updateBadge = updateBadge;', context);
    context.updateBadge();
    assert.strictEqual(chatBadge.textContent, '3');
    assert.strictEqual(menuBadge.textContent, '3');
    assert.strictEqual(menuBadge.hidden, false);
    assert.strictEqual(menuBadge.attributes['aria-label'], 'Nepřečtené zprávy: 3');
    unreadByKey.peer = 100;
    context.updateBadge();
    assert.strictEqual(chatBadge.textContent, '99+');
    assert.strictEqual(menuBadge.textContent, '99+');
    unreadByKey.admin = 0;
    unreadByKey.peer = 0;
    context.updateBadge();
    assert.strictEqual(chatBadge.hidden, true);
    assert.strictEqual(menuBadge.hidden, true);
}

(async () => {
    await testAthletePolling();
    await testWidgetPolling();
    testWidgetBadges();
    console.log('Background chat polling preserves unread messages.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
