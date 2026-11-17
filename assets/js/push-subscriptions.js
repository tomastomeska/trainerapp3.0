'use strict';

(function () {
    var endpoint = '/api/push_subscriptions.php';

    function isSupported() {
        return window.isSecureContext
            && 'serviceWorker' in navigator
            && 'PushManager' in window
            && 'Notification' in window;
    }

    function decodeApplicationServerKey(value) {
        if (typeof value !== 'string' || !/^[A-Za-z0-9_-]+$/.test(value)) {
            throw new Error('Neplatny verejny VAPID klic.');
        }

        var base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        while (base64.length % 4) {
            base64 += '=';
        }

        var binary = window.atob(base64);
        var bytes = new Uint8Array(binary.length);
        for (var index = 0; index < binary.length; index += 1) {
            bytes[index] = binary.charCodeAt(index);
        }
        if (bytes.length !== 65 || bytes[0] !== 4) {
            throw new Error('Neplatny verejny VAPID klic.');
        }

        return bytes;
    }

    function post(action, csrfToken, values) {
        var payload = Object.assign({ action: action, csrf_token: csrfToken }, values || {});
        return fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                throw new Error('Server vratil neplatnou odpoved.');
            }).then(function (result) {
                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Pozadavek na spravu oznameni selhal.');
                }
                return result;
            });
        });
    }

    function deviceDetails() {
        var userAgent = navigator.userAgent || '';
        var deviceType = /iPhone|iPad|iPod/i.test(userAgent)
            ? 'ios'
            : (/Android/i.test(userAgent) ? 'android' : 'desktop');
        var browser = /Edg\//i.test(userAgent)
            ? 'Edge'
            : (/Firefox\//i.test(userAgent)
                ? 'Firefox'
                : (/Chrome\//i.test(userAgent)
                    ? 'Chrome'
                    : (/Safari\//i.test(userAgent) ? 'Safari' : 'Prohlizec')));
        var platform = deviceType === 'ios'
            ? 'iPhone/iPad'
            : (deviceType === 'android' ? 'Android' : 'Desktop');

        return {
            device_type: deviceType,
            device_name: browser + ' (' + platform + ')'
        };
    }

    function getCurrentSubscription() {
        if (!window.isSecureContext || !('serviceWorker' in navigator)) {
            return Promise.resolve(null);
        }
        return navigator.serviceWorker.getRegistration('/').then(function (registration) {
            return registration && registration.pushManager
                ? registration.pushManager.getSubscription()
                : null;
        });
    }

    function sameApplicationServerKey(subscription, expected) {
        var key = subscription.options && subscription.options.applicationServerKey;
        if (!key) {
            return false;
        }
        var actual = new Uint8Array(key);
        return actual.length === expected.length && actual.every(function (value, index) {
            return value === expected[index];
        });
    }

    function unsubscribe(subscription) {
        return subscription.unsubscribe().then(function (removed) {
            if (!removed) {
                throw new Error('Prohlížeč nepotvrdil odhlášení odběru. Změna na serveru již mohla proběhnout.');
            }
        });
    }

    window.TrainerAppPushSubscriptions = {
        isSupported: isSupported,

        getPublicKey: function (csrfToken) {
            return post('get_public_key', csrfToken).then(function (result) {
                return result.public_key;
            });
        },

        subscribe: function (publicKey, csrfToken) {
            if (!isSupported()) {
                return Promise.reject(new Error('Tento prohlizec push oznameni nepodporuje.'));
            }
            if (typeof csrfToken !== 'string' || csrfToken === '') {
                return Promise.reject(new Error('Chybi bezpecnostni token.'));
            }

            var applicationServerKey;
            try {
                applicationServerKey = decodeApplicationServerKey(publicKey);
            } catch (error) {
                return Promise.reject(error);
            }

            var permission = Notification.permission === 'granted'
                ? Promise.resolve('granted')
                : Notification.requestPermission();

            return permission.then(function (result) {
                if (result !== 'granted') {
                    throw new Error('Oznameni nebyla povolena.');
                }

                return navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function () {
                    return navigator.serviceWorker.ready;
                });
            }).then(function (registration) {
                return registration.pushManager.getSubscription().then(function (subscription) {
                    if (!subscription) {
                        return registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: applicationServerKey
                        });
                    }
                    if (!sameApplicationServerKey(subscription, applicationServerKey)) {
                        if (!window.confirm('Tento prohlížeč má odběr s jiným nebo neověřitelným klíčem. Pokračování jej odhlásí a může vypnout oznámení jiného účtu v tomto prohlížeči. Pokračovat?')) {
                            throw new Error('Aktivace byla zrušena.');
                        }
                        return unsubscribe(subscription).then(function () {
                            return registration.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: applicationServerKey
                            });
                        });
                    }
                    return post('list', csrfToken, { current_endpoint: subscription.endpoint }).then(function (listing) {
                        var owned = listing.devices.some(function (device) {
                            return Number(device.is_current) === 1;
                        });
                        if (!owned && !window.confirm('Tento odběr není aktivní pro aktuální účet. Aktivací může přestat doručování jinému účtu v tomto prohlížeči. Pokračovat?')) {
                            throw new Error('Aktivace byla zrušena.');
                        }
                        return subscription;
                    });
                });
            }).then(function (subscription) {
                var details = deviceDetails();
                return post('register', csrfToken, {
                    subscription: subscription.toJSON(),
                    device_type: details.device_type,
                    device_name: details.device_name
                }).then(function (result) {
                    result.endpoint = subscription.endpoint;
                    return result;
                });
            });
        },

        list: function (csrfToken) {
            return getCurrentSubscription().then(function (subscription) {
                return post('list', csrfToken, {
                    current_endpoint: subscription ? subscription.endpoint : null
                });
            });
        },

        remove: function (subscriptionId, csrfToken) {
            return getCurrentSubscription().then(function (subscription) {
                return post('list', csrfToken, {
                    current_endpoint: subscription ? subscription.endpoint : null
                }).then(function (listing) {
                    var currentDevice = subscription && listing.devices.some(function (device) {
                        return Number(device.id) === Number(subscriptionId) && Number(device.is_current) === 1;
                    });

                    return post('revoke', csrfToken, {
                        subscription_id: subscriptionId
                    }).then(function (result) {
                        return currentDevice ? unsubscribe(subscription).then(function () { return result; }) : result;
                    });
                });
            });
        },

        disable: function (csrfToken) {
            return getCurrentSubscription().then(function (subscription) {
                return post('list', csrfToken, {
                    current_endpoint: subscription ? subscription.endpoint : null
                }).then(function (listing) {
                    var currentDevice = subscription && listing.devices.some(function (device) {
                        return Number(device.is_current) === 1;
                    });
                    return post('disable', csrfToken).then(function (result) {
                        return currentDevice ? unsubscribe(subscription).then(function () { return result; }) : result;
                    });
                });
            });
        },

        getPreferences: function (csrfToken) {
            return post('get_preferences', csrfToken);
        },

        setPreference: function (category, enabled, csrfToken) {
            return post('set_preference', csrfToken, {
                category: category,
                enabled: Boolean(enabled)
            });
        }
    };
}());
