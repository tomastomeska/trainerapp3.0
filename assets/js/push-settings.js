'use strict';

(function () {
    var root = document.getElementById('push-settings');
    if (!root) {
        return;
    }
    if (!window.TrainerAppPushSubscriptions) {
        var unavailableStatus = document.getElementById('push-status');
        unavailableStatus.textContent = 'Správa oznámení není dostupná: chybí potřebný skript.';
        unavailableStatus.classList.add('text-danger');
        return;
    }

    var api = window.TrainerAppPushSubscriptions;
    var csrf = root.getAttribute('data-csrf');
    var status = document.getElementById('push-status');
    var activate = document.getElementById('push-activate');
    var disableAll = document.getElementById('push-disable-all');
    var deviceList = document.getElementById('push-devices');
    var categories = document.getElementById('push-categories');
    var supported = api.isSupported();
    var publicKey = null;
    var devices = [];
    var devicesLoaded = false;
    var busy = false;

    function showStatus(message, isError) {
        status.textContent = message;
        status.classList.toggle('text-danger', Boolean(isError));
    }

    function describeError(error) {
        var messages = {
            csrf: 'Bezpečnostní token vypršel. Obnovte stránku.',
            account_not_available: 'Účet není dostupný pro správu oznámení.',
            push_service_unavailable: 'Služba oznámení není momentálně dostupná.',
            invalid_subscription: 'Registraci tohoto prohlížeče server nepřijal.',
            not_authenticated: 'Přihlaste se znovu.',
            device_not_found: 'Zařízení již není dostupné.'
        };
        return messages[error.message] || error.message || 'Správa oznámení selhala.';
    }

    function currentDevice() {
        return devices.some(function (device) {
            return Number(device.is_current) === 1;
        });
    }

    function updateControls() {
        var denied = supported && Notification.permission === 'denied';
        activate.disabled = busy || !supported || denied || !publicKey || !devicesLoaded;
        activate.textContent = supported && Notification.permission === 'granted' && currentDevice()
            ? 'Znovu zaregistrovat toto zařízení'
            : 'Aktivovat na tomto zařízení';
        disableAll.disabled = busy || !devicesLoaded || devices.length === 0;
        deviceList.querySelectorAll('button').forEach(function (button) {
            button.disabled = busy;
        });
    }

    function describeState() {
        if (!supported) {
            var ios = /iPhone|iPad|iPod/i.test(navigator.userAgent || '')
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            return ios
                ? 'Na iPhonu nebo iPadu otevřete TrainerApp přidanou na plochu. Ostatní zařízení můžete spravovat níže.'
                : 'Tento prohlížeč push oznámení nepodporuje. Ostatní zařízení můžete spravovat níže.';
        }
        if (Notification.permission === 'denied') {
            return 'Oznámení jsou v tomto prohlížeči zablokována. Povolte je v jeho nastavení.';
        }
        if (Notification.permission === 'granted' && currentDevice()) {
            return 'Oznámení jsou pro toto zařízení registrována a povolena v prohlížeči.';
        }
        return 'Oznámení na tomto zařízení nejsou aktivní.';
    }

    async function refreshAfterFailure(error) {
        var message = describeError(error);
        try {
            await loadDevices();
        } catch (refreshError) {
            message += ' Seznam zařízení se nepodařilo obnovit.';
        }
        showStatus(message, true);
    }

    async function removeDevice(device) {
        if (!window.confirm('Odebrat toto zařízení z oznámení vašeho účtu?')) {
            return;
        }
        busy = true;
        updateControls();
        try {
            await api.remove(device.id, csrf);
            await loadDevices();
            showStatus('Zařízení bylo odebráno.', false);
        } catch (error) {
            await refreshAfterFailure(error);
        } finally {
            busy = false;
            updateControls();
        }
    }

    function renderDevices() {
        deviceList.replaceChildren();
        if (devices.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'list-group-item text-muted';
            empty.textContent = 'Nemáte žádná aktivní zařízení.';
            deviceList.appendChild(empty);
            return;
        }

        devices.forEach(function (device) {
            var item = document.createElement('li');
            item.className = 'list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2';
            var text = document.createElement('div');
            var name = document.createElement('div');
            name.className = 'fw-semibold';
            name.textContent = device.device_name || 'Zařízení';
            if (Number(device.is_current) === 1) {
                name.textContent += supported && Notification.permission === 'granted'
                    ? ' (toto zařízení)'
                    : ' (toto zařízení; oznámení nejsou povolena)';
            }
            var details = document.createElement('small');
            details.className = 'text-muted';
            details.textContent = 'Registrováno: ' + device.registered_at + ' · Poslední aktivita: ' + device.last_activity_at;
            text.appendChild(name);
            text.appendChild(details);
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-danger';
            button.textContent = 'Odebrat';
            button.addEventListener('click', function () { removeDevice(device); });
            item.appendChild(text);
            item.appendChild(button);
            deviceList.appendChild(item);
        });
    }

    async function loadDevices() {
        devicesLoaded = false;
        updateControls();
        try {
            var result = await api.list(csrf);
            if (!Array.isArray(result.devices)) {
                throw new Error('Server vrátil neplatný seznam zařízení.');
            }
            devices = result.devices;
            devicesLoaded = true;
            renderDevices();
        } catch (error) {
            devices = [];
            deviceList.replaceChildren();
            var unavailable = document.createElement('li');
            unavailable.className = 'list-group-item text-danger';
            unavailable.textContent = 'Seznam zařízení se nepodařilo načíst.';
            deviceList.appendChild(unavailable);
            throw error;
        } finally {
            updateControls();
        }
    }

    async function loadPreferences() {
        var result = await api.getPreferences(csrf);
        var inputs = categories.querySelectorAll('[data-push-category]');
        inputs.forEach(function (input) {
            var value = result.preferences && result.preferences[input.dataset.pushCategory];
            if (typeof value !== 'boolean') {
                throw new Error('Server vrátil neplatné nastavení kategorií.');
            }
            input.checked = value;
        });
        categories.disabled = false;
    }

    activate.addEventListener('click', async function () {
        busy = true;
        updateControls();
        try {
            await api.subscribe(publicKey, csrf);
            await loadDevices();
            showStatus(describeState(), false);
        } catch (error) {
            await refreshAfterFailure(error);
        } finally {
            busy = false;
            updateControls();
        }
    });

    disableAll.addEventListener('click', async function () {
        if (!window.confirm('Deaktivovat oznámení pro tento účet na všech zařízeních?')) {
            return;
        }
        busy = true;
        updateControls();
        try {
            await api.disable(csrf);
            await loadDevices();
            showStatus('Oznámení pro tento účet byla deaktivována na všech zařízeních.', false);
        } catch (error) {
            await refreshAfterFailure(error);
        } finally {
            busy = false;
            updateControls();
        }
    });

    categories.addEventListener('change', async function (event) {
        var input = event.target;
        if (!input.matches('[data-push-category]')) {
            return;
        }
        var desired = input.checked;
        input.disabled = true;
        try {
            await api.setPreference(input.dataset.pushCategory, desired, csrf);
            showStatus('Nastavení kategorie bylo uloženo.', false);
        } catch (error) {
            var message = describeError(error);
            try {
                await loadPreferences();
            } catch (refreshError) {
                categories.disabled = true;
                message += ' Aktuální nastavení se nepodařilo ověřit.';
            }
            showStatus(message, true);
        } finally {
            input.disabled = false;
        }
    });

    (async function () {
        var errors = [];
        try {
            await loadDevices();
        } catch (error) {
            errors.push('Zařízení: ' + describeError(error));
        }
        try {
            await loadPreferences();
        } catch (error) {
            errors.push('Kategorie: ' + describeError(error));
        }
        if (supported) {
            try {
                publicKey = await api.getPublicKey(csrf);
                if (typeof publicKey !== 'string' || publicKey === '') {
                    throw new Error('Veřejný klíč oznámení není dostupný.');
                }
            } catch (error) {
                errors.push('Aktivace: ' + describeError(error));
            }
        }
        updateControls();
        showStatus(errors.length ? errors.join(' ') : describeState(), errors.length > 0);
    }());
}());
