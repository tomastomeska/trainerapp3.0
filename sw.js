'use strict';

const APP_ROOT = new URL('/', self.location.origin);
const APP_ICON = new URL('/assets/pwa/icon-192.png?v=2', self.location.origin).href;
const NAVIGATION_DB = 'trainerapp-push-navigation';
const NAVIGATION_STORE = 'pending';

function openNavigationDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(NAVIGATION_DB, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(NAVIGATION_STORE);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('Notification navigation storage is blocked.'));
    });
}

async function saveNotificationTarget(url) {
    const db = await openNavigationDb();
    try {
        await new Promise((resolve, reject) => {
            const transaction = db.transaction(NAVIGATION_STORE, 'readwrite');
            transaction.objectStore(NAVIGATION_STORE).put({ url, expiresAt: Date.now() + 120000 }, 'target');
            transaction.oncomplete = resolve;
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error || new Error('Notification navigation storage aborted.'));
        });
    } finally {
        db.close();
    }
}

async function consumeNotificationTarget() {
    const db = await openNavigationDb();
    try {
        return await new Promise((resolve, reject) => {
            const transaction = db.transaction(NAVIGATION_STORE, 'readwrite');
            const store = transaction.objectStore(NAVIGATION_STORE);
            const request = store.get('target');
            let target = null;
            request.onsuccess = () => {
                const value = request.result;
                if (value) {
                    store.delete('target');
                    if (typeof value.url === 'string' && value.expiresAt > Date.now()) {
                        target = getSafeTargetUrl(value.url).href;
                    }
                }
            };
            transaction.oncomplete = () => resolve(target);
            transaction.onerror = () => reject(transaction.error);
            transaction.onabort = () => reject(transaction.error || new Error('Notification navigation storage aborted.'));
        });
    } finally {
        db.close();
    }
}

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
    const type = typeof payload.type === 'string' ? payload.type.slice(0, 64) : '';
    const options = {
        body,
        icon: APP_ICON,
        badge: APP_ICON,
        data: {
            url: targetUrl.href,
            type
        },
        tag: typeof payload.tag === 'string' ? payload.tag.slice(0, 128) : undefined,
        renotify: false
    };

    const tasks = [shouldSuppressVisibleChatNotification(type, targetUrl).then((suppress) => {
        if (!suppress) {
            return self.registration.showNotification(title, options);
        }
    })];
    if (Number.isSafeInteger(payload.unread_count) && payload.unread_count >= 0
        && self.navigator && typeof self.navigator.setAppBadge === 'function') {
        tasks.push(Promise.resolve().then(() => self.navigator.setAppBadge(payload.unread_count)).catch((error) => {
            console.error('Application badge could not be updated.', error);
        }));
    }
    event.waitUntil(Promise.all(tasks));
});

async function shouldSuppressVisibleChatNotification(type, targetUrl) {
    let expectedPath;
    if (type === 'coach_chat_message' && targetUrl.pathname === '/athlete_chat_mobile.php'
        && targetUrl.searchParams.get('target') === 'coach') {
        expectedPath = '/athlete_chat_mobile.php';
    } else if (type === 'athlete_chat_message' && targetUrl.pathname === '/coach_chat_mobile.php') {
        const athleteId = targetUrl.searchParams.get('athlete_id');
        if (!athleteId || !/^[1-9]\d*$/.test(athleteId)) {
            return false;
        }
        expectedPath = '/coach_chat_mobile.php';
    } else {
        return false;
    }

    try {
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        return clients.some((client) => {
            if (client.visibilityState !== 'visible' || client.focused === false) {
                return false;
            }
            const clientUrl = new URL(client.url);
            if (clientUrl.origin !== self.location.origin || clientUrl.pathname !== expectedPath) {
                return false;
            }
            if (type === 'coach_chat_message') {
                return clientUrl.searchParams.get('target') === 'coach';
            }
            return clientUrl.searchParams.get('athlete_id') === targetUrl.searchParams.get('athlete_id');
        });
    } catch (error) {
        console.error('Visible chat could not be checked before displaying a notification.', error);
        return false;
    }
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = getSafeTargetUrl(event.notification.data && event.notification.data.url);
    event.waitUntil((async () => {
        try {
            await saveNotificationTarget(targetUrl.href);
        } catch (error) {
            console.error('Notification navigation target could not be saved.', error);
        }
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of clients) {
            if (new URL(client.url).origin === self.location.origin) {
                try {
                    const windowClient = await client.navigate(targetUrl.href);
                    if (windowClient) {
                        await windowClient.focus();
                        try {
                            windowClient.postMessage({ type: 'trainerapp-notification-click' });
                        } catch (error) {
                            console.warn('Notification window could not be notified.', error);
                        }
                        return;
                    }
                    console.warn('Notification navigation returned no window.');
                } catch (error) {
                    console.warn('Notification navigation or focus failed.');
                }
            }
        }

        const windowClient = await self.clients.openWindow(targetUrl.href);
        if (windowClient) {
            try {
                windowClient.postMessage({ type: 'trainerapp-notification-click' });
            } catch (error) {
                console.warn('Notification window could not be notified.', error);
            }
        }
    })());
});

self.addEventListener('message', (event) => {
    if (!event.data || event.data.type !== 'trainerapp-consume-notification-target'
        || !event.ports[0] || !event.source || typeof event.source.url !== 'string'
        || new URL(event.source.url).origin !== self.location.origin) {
        return;
    }
    const port = event.ports[0];
    event.waitUntil(consumeNotificationTarget().then((url) => {
        port.postMessage({ url });
    }).catch((error) => {
        console.error('Notification navigation target could not be read.', error);
        port.postMessage({ error: 'navigation_storage_failed' });
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
