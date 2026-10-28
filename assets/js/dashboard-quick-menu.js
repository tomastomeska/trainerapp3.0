(() => {
    const menu = document.getElementById('personal-quick-menu');
    if (!menu) return;
    const tiles = menu.querySelector('#quick-menu-selected .dashboard-quick-tiles');
    [...tiles.children]
        .sort((left, right) => Number(getComputedStyle(left).order) - Number(getComputedStyle(right).order))
        .forEach(tile => tiles.append(tile));
    const list = menu.querySelector('.quick-menu-editor-list');
    const updateButtons = () => {
        const rows = [...list.children];
        rows.forEach((row, index) => {
            row.querySelector('[data-menu-move="-1"]').disabled = index === 0;
            row.querySelector('[data-menu-move="1"]').disabled = index === rows.length - 1;
        });
    };
    list.addEventListener('click', event => {
        const button = event.target.closest('[data-menu-move]');
        if (!button) return;
        const row = button.closest('[data-menu-row]');
        const previous = button.dataset.menuMove === '-1';
        const sibling = previous ? row.previousElementSibling : row.nextElementSibling;
        if (!sibling) return;
        list.insertBefore(previous ? row : sibling, previous ? sibling : row);
        updateButtons();
        const focusButton = button.disabled
            ? row.querySelector(`[data-menu-move="${previous ? '1' : '-1'}"]`)
            : button;
        focusButton.focus();
        menu.querySelector('[data-menu-announcement]').textContent =
            `${row.querySelector('label').textContent.trim()}: pozice ${[...list.children].indexOf(row) + 1}`;
    });
    updateButtons();
})();
