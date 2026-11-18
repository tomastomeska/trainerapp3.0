'use strict';

if ('serviceWorker' in navigator && window.isSecureContext) {
    var pwaScript = document.currentScript;
    var badgeCsrf = pwaScript ? pwaScript.getAttribute('data-push-csrf') : null;
    var badgeCheckPending = false;
    var pendingNavigationCheck = false;
    var pendingNavigationRecheck = false;

    async function refreshUnreadBadge() {
        if (!badgeCsrf || typeof navigator.setAppBadge !== 'function'
            || document.visibilityState !== 'visible' || badgeCheckPending) {
            return;
        }
        badgeCheckPending = true;
        try {
            var response = await fetch('/api/push_subscriptions.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_unread_count', csrf_token: badgeCsrf })
            });
            var result = await response.json();
            if (!response.ok || !result.success || !Number.isSafeInteger(result.unread_count) || result.unread_count < 0) {
                throw new Error('Unread message count is unavailable.');
            }
            await navigator.setAppBadge(result.unread_count);
        } catch (error) {
            console.error('Application badge could not be refreshed.', error);
        } finally {
            badgeCheckPending = false;
        }
    }

    function finishNotificationCheck() {
        pendingNavigationCheck = false;
        if (pendingNavigationRecheck) {
            pendingNavigationRecheck = false;
            checkNotificationTarget();
        }
    }

    function checkNotificationTarget() {
        if (typeof MessageChannel === 'undefined') {
            return;
        }
        if (pendingNavigationCheck) {
            pendingNavigationRecheck = true;
            return;
        }
        pendingNavigationCheck = true;
        navigator.serviceWorker.ready.then(function (registration) {
            if (!registration.active) {
                throw new Error('Active service worker is unavailable.');
            }
            var channel = new MessageChannel();
            var timeout = setTimeout(function () {
                channel.port1.close();
                finishNotificationCheck();
                console.warn('Notification navigation check timed out.');
            }, 5000);
            channel.port1.onmessage = function (event) {
                clearTimeout(timeout);
                channel.port1.close();
                finishNotificationCheck();
                if (event.data && event.data.error) {
                    console.error('Notification navigation check failed:', event.data.error);
                    return;
                }
                if (!event.data || typeof event.data.url !== 'string') {
                    return;
                }
                try {
                    var target = new URL(event.data.url, window.location.origin);
                    if (target.origin === window.location.origin && target.href !== window.location.href) {
                        window.location.assign(target.href);
                    }
                } catch (error) {
                    console.error('Invalid notification navigation target.', error);
                }
            };
            try {
                registration.active.postMessage({ type: 'trainerapp-consume-notification-target' }, [channel.port2]);
            } catch (error) {
                clearTimeout(timeout);
                channel.port1.close();
                throw error;
            }
        }).catch(function (error) {
            finishNotificationCheck();
            console.error('Notification navigation check failed.', error);
        });
    }

    navigator.serviceWorker.addEventListener('message', function (event) {
        if (event.data && event.data.type === 'trainerapp-notification-click') {
            checkNotificationTarget();
        }
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            checkNotificationTarget();
            refreshUnreadBadge();
        }
    });

    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function () {
            checkNotificationTarget();
            refreshUnreadBadge();
        }).catch(function (error) {
            console.error('TrainerApp service worker registration failed.', error);
        });
    });
    if (badgeCsrf && typeof navigator.setAppBadge === 'function') {
        window.setInterval(refreshUnreadBadge, 15000);
    }
}
