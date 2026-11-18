'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

function run(page, search, available) {
    const source = fs.readFileSync(page, 'utf8').replace(/\r\n/g, '\n');
    const pattern = page === 'athlete_gallery.php'
        ? /document\.addEventListener\('DOMContentLoaded', function \(\) \{\n    const params = new URLSearchParams\(window\.location\.search\);[\s\S]*?\n\}\);/
        : /document\.addEventListener\('DOMContentLoaded', function \(\) \{\n    const id = new URLSearchParams\(window\.location\.search\)\.get\('admin_file'\);[\s\S]*?\n\}\);/;
    const match = source.match(pattern);
    assert(match, 'Gallery notification navigation is missing.');
    let handler;
    let clicked = false;
    let warning = false;
    const button = { click: () => { clicked = true; } };
    const document = {
        addEventListener: (_name, callback) => { handler = callback; },
        querySelector: (selector) => {
            if (selector !== available) return null;
            return page === 'athlete_gallery.php' ? { querySelector: () => button } : button;
        },
        getElementById: () => ({ classList: { remove: () => { warning = true; } } }),
    };
    vm.runInNewContext(match[0], { document, window: { location: { search } }, URLSearchParams });
    handler();
    return { clicked, warning };
}

assert.deepStrictEqual(run('athlete_gallery.php', '?admin_file=12', '[data-gallery-source="admin"][data-gallery-id="12"]'), { clicked: true, warning: false });
assert.deepStrictEqual(run('athlete_gallery.php', '?coach_file=23', '[data-gallery-source="coach"][data-gallery-id="23"]'), { clicked: true, warning: false });
assert.deepStrictEqual(run('gallery.php', '?admin_file=12', '[data-admin-gallery-id="12"]'), { clicked: true, warning: false });
assert.deepStrictEqual(run('athlete_gallery.php', '?coach_file=23', ''), { clicked: false, warning: true });
assert.deepStrictEqual(run('gallery.php', '?admin_file=12', ''), { clicked: false, warning: true });
assert.deepStrictEqual(run('athlete_gallery.php', '?admin_file=bad', ''), { clicked: false, warning: false });
console.log('Gallery notification links open only available items.');
