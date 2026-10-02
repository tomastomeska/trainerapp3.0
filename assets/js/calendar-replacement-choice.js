function askCalendarReplacementChoice() {
    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'border rounded shadow p-0';
        dialog.style.width = 'min(480px, calc(100% - 32px))';
        const header = document.createElement('div');
        header.className = 'd-flex justify-content-between align-items-center border-bottom p-3';
        const title = document.createElement('h5');
        title.className = 'mb-0';
        title.textContent = 'Zrušení tréninku';
        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'btn-close';
        closeButton.setAttribute('aria-label', 'Zavřít');
        header.append(title, closeButton);
        const body = document.createElement('div');
        body.className = 'p-3';
        body.textContent = 'Umožnit náhradu do 14 kalendářních dní od původního termínu, nebo zrušit bez nároku na náhradní slot?';
        const footer = document.createElement('div');
        footer.className = 'd-flex flex-wrap justify-content-end gap-2 border-top p-3';
        const allowButton = document.createElement('button');
        allowButton.type = 'button';
        allowButton.className = 'btn btn-primary';
        allowButton.textContent = 'Umožnit náhradu';
        const noneButton = document.createElement('button');
        noneButton.type = 'button';
        noneButton.className = 'btn btn-outline-danger';
        noneButton.textContent = 'Bez náhrady';
        footer.append(allowButton, noneButton);
        dialog.append(header, body, footer);
        let choice = null;
        dialog.addEventListener('close', () => {
            dialog.remove();
            resolve(choice);
        }, { once: true });
        closeButton.addEventListener('click', () => dialog.close());
        allowButton.addEventListener('click', () => {
            choice = '14_days';
            dialog.close();
        });
        noneButton.addEventListener('click', () => {
            choice = 'none';
            dialog.close();
        });
        document.body.appendChild(dialog);
        dialog.showModal();
    });
}