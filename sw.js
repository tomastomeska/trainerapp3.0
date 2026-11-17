'use strict';

const APP_ROOT = new URL('/', self.location.origin);
const APP_ICON = new URL('/assets/pwa/icon-192.png', self.location.origin).href;

self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let payload = {};
    if (event.data) {
        try {
            payload = event.data.json();
        } catch (error) {
            payload = { body: event.data.text() };
        }
    }
    if (!payload || typeof payload !== 'object') {
        payload = {};
    }

    const title = typeof payload.title === 'string' && payload.title.trim()
        ? payload.title.trim().slice(0, 100)
        : 'TrainerApp';
    const body = typeof payload.body === 'string' && payload.body.trim()
        ? payload.body.trim().slice(0, 240)
        : 'M\u00e1\u0161 nov\u00e9 ozn\u00e1men\u00ed v TrainerApp.';
    const targetUrl = getSafeTargetUrl(payload.url);
    const options = {
        body,
        icon: APP_ICON,
        badge: APP_ICON,
        data: {
            url: targetUrl.href,
            type: typeof payload.type === 'string' ? payload.type.slice(0, 64) : ''
        },
        tag: typeof payload.tag === 'string' ? payload.tag.slice(0, 128) : undefined,
        renotify: false
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = getSafeTargetUrl(event.notification.data && event.notification.data.url);
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (clients) => {
        for (const client of clients) {
            if (new URL(client.url).origin === self.location.origin) {
                try {
                    const windowClient = await client.navigate(targetUrl.href);
                    if (windowClient) {
                        await windowClient.focus();
                        return;
                    }
                    console.warn('Notification navigation returned no window.');
                } catch (error) {
                    console.warn('Notification navigation or focus failed.');
                }
            }
        }

        return self.clients.openWindow(targetUrl.href);
    }));
});

function getSafeTargetUrl(value) {
    if (typeof value !== 'string' || value.length > 2048) {
        return new URL('login.php', APP_ROOT);
    }

    try {
        const url = new URL(value, APP_ROOT);
        if (url.origin !== self.location.origin || !url.pathname.startsWith(APP_ROOT.pathname)) {
            return new URL('login.php', APP_ROOT);
        }

        return url;
    } catch (error) {
        return new URL('login.php', APP_ROOT);
    }
}
