(() => {
    const button = document.getElementById('dashboard-sidebar-toggle');
    if (!button) return;

    const overlayViewport = matchMedia('(min-width: 992px) and (max-width: 1228px)');
    const navigation = document.getElementById('dashboard-sidebar-navigation');
    const updateButton = () => {
        const collapsed = overlayViewport.matches
            ? !document.body.classList.contains('dashboard-sidebar-overlay-open')
            : document.body.classList.contains('dashboard-sidebar-collapsed');
        const label = collapsed ? 'Zobrazit menu' : 'Skrýt menu';
        button.setAttribute('aria-expanded', String(!collapsed));
        button.setAttribute('aria-label', label);
        button.title = label;
        button.querySelector('i').className = `fas fa-chevron-${collapsed ? 'right' : 'left'}`;
    };
    const closeOverlay = () => {
        document.body.classList.remove('dashboard-sidebar-overlay-open');
        updateButton();
    };

    overlayViewport.addEventListener('change', closeOverlay);
    window.addEventListener('pageshow', closeOverlay);
    updateButton();

    button.addEventListener('click', () => {
        if (overlayViewport.matches) {
            document.body.classList.toggle('dashboard-sidebar-overlay-open');
            updateButton();
            return;
        }

        const collapsed = document.body.classList.toggle('dashboard-sidebar-collapsed');
        updateButton();
        const secure = location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `${button.dataset.cookieName}=${collapsed ? 'collapsed' : 'expanded'}; Path=${button.dataset.cookiePath}; Max-Age=31536000; SameSite=Lax${secure}`;
        if (!document.cookie.split('; ').includes(`${button.dataset.cookieName}=${collapsed ? 'collapsed' : 'expanded'}`)) {
            console.warn('Stav bocniho menu se nepodarilo ulozit. Zkontrolujte povoleni cookies.');
        }
    });

    navigation.addEventListener('click', (event) => {
        if (overlayViewport.matches && event.target instanceof Element
            && event.target.closest('a[href], button[type="submit"], [data-bs-toggle="modal"]')) {
            closeOverlay();
        }
    });
    navigation.addEventListener('submit', () => {
        if (overlayViewport.matches) closeOverlay();
    });
})();
