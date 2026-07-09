/* assets/js/app.js – Globální JavaScript */

// Sdílený autosave helper pro sportovní formuláře.
window.createSportAutosave = function createSportAutosave(options) {
    const form = options.form;
    const statusEl = options.statusEl || null;
    const endpoint = options.endpoint;
    const buildPayload = options.buildPayload;
    const debounceMs = typeof options.debounceMs === 'number' ? options.debounceMs : 700;

    if (!form || !endpoint || typeof buildPayload !== 'function') {
        return null;
    }

    let timer = null;
    let saving = false;
    let queued = false;
    let lastSavedHash = '';

    function setStatus(text, cls) {
        if (!statusEl) {
            return;
        }
        statusEl.classList.remove('text-muted', 'text-success', 'text-danger');
        statusEl.classList.add(cls || 'text-muted');
        statusEl.textContent = text;
    }

    async function saveNow(force) {
        const payload = buildPayload();
        const hash = JSON.stringify(payload);

        if (!force && hash === lastSavedHash) {
            return;
        }

        if (saving) {
            queued = true;
            return;
        }

        saving = true;
        setStatus('Ukladam...', 'text-muted');

        try {
            const resp = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            });
            const data = await resp.json();
            if (!data.success) {
                throw new Error(data.error || 'Autosave chyba');
            }
            lastSavedHash = hash;
            setStatus('Ulozeno ' + (data.saved_at || ''), 'text-success');
        } catch (e) {
            setStatus('Neulozeno', 'text-danger');
        } finally {
            saving = false;
            if (queued) {
                queued = false;
                saveNow(false);
            }
        }
    }

    function scheduleSave() {
        setStatus('Neulozene zmeny', 'text-muted');
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(function() {
            saveNow(false);
        }, debounceMs);
    }

    form.addEventListener('input', function(e) {
        if (!e.target || e.target.type === 'hidden') {
            return;
        }
        scheduleSave();
    });

    form.addEventListener('change', function(e) {
        if (!e.target || e.target.type === 'hidden') {
            return;
        }
        scheduleSave();
    });

    return {
        scheduleSave,
        saveNow: function() { return saveNow(true); }
    };
};

// Auto-zavírání flash alertů po 5 sekundách
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert.alert-dismissible');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // Aktivní nav-link
    const currentPath = window.location.pathname;
    document.querySelectorAll('.navbar-nav .nav-link').forEach(function (link) {
        if (link.getAttribute('href') === currentPath) {
            link.classList.add('active');
        }
    });

    // Na malych displejich skladat bezne tabulky do karet misto horizontalniho posuvu.
    (function initMobileTableStack() {
        const MOBILE_BREAKPOINT = 575.98;

        function isExcludedTable(table) {
            return table.matches('.mealplan-items-table, .coach-mealplan-items-table, .print-table, .no-mobile-stack');
        }

        function getHeaders(table) {
            const headers = Array.from(table.querySelectorAll('thead th'));
            if (!headers.length) {
                return null;
            }
            return headers.map(function(th) {
                return (th.textContent || '').replace(/\s+/g, ' ').trim();
            });
        }

        function applyStack(table) {
            const headers = getHeaders(table);
            if (!headers || !headers.length) {
                return;
            }

            table.classList.add('mobile-stack-table');
            const wrap = table.closest('.table-responsive');
            if (wrap) {
                wrap.classList.add('mobile-stack-wrap');
            }

            table.querySelectorAll('tbody tr').forEach(function(row) {
                const cells = Array.from(row.children).filter(function(cell) {
                    return cell.tagName === 'TD' || cell.tagName === 'TH';
                });

                if (!cells.length) {
                    return;
                }

                if (cells.length === 1 && parseInt(cells[0].getAttribute('colspan') || '1', 10) > 1) {
                    cells[0].setAttribute('data-label', '');
                    cells[0].setAttribute('data-auto-label', '1');
                    return;
                }

                let headerIndex = 0;
                cells.forEach(function(cell) {
                    if (!cell.hasAttribute('data-label')) {
                        const label = headers[headerIndex] || '';
                        cell.setAttribute('data-label', label);
                        cell.setAttribute('data-auto-label', '1');
                    }
                    const span = parseInt(cell.getAttribute('colspan') || '1', 10);
                    headerIndex += Number.isFinite(span) && span > 0 ? span : 1;
                });
            });
        }

        function removeStack(table) {
            table.classList.remove('mobile-stack-table');
            const wrap = table.closest('.table-responsive');
            if (wrap) {
                wrap.classList.remove('mobile-stack-wrap');
            }

            table.querySelectorAll('[data-auto-label="1"]').forEach(function(cell) {
                cell.removeAttribute('data-auto-label');
                cell.removeAttribute('data-label');
            });
        }

        function refreshTables() {
            const isMobile = window.innerWidth <= MOBILE_BREAKPOINT;
            document.querySelectorAll('.table-responsive > .table').forEach(function(table) {
                if (isExcludedTable(table)) {
                    return;
                }
                if (isMobile) {
                    applyStack(table);
                } else {
                    removeStack(table);
                }
            });
        }

        let resizeTimer = null;
        window.addEventListener('resize', function() {
            if (resizeTimer) {
                clearTimeout(resizeTimer);
            }
            resizeTimer = setTimeout(refreshTables, 120);
        });

        refreshTables();
    })();
});
