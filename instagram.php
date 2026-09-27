<?php
declare(strict_types=1);

require_once __DIR__ . '/insta/lib.php';

$profiles = instaGetProfiles();
instaRecordVisit();
?><!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#192620">
    <title>Instagram | Reservio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: light;
            --ink: #192620;
            --muted: #617068;
            --paper: #f4f5ef;
            --line: #dce2d9;
            --lime: #d8f36a;
            --pink: #ef8d78;
            --white: #fff;
        }
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            color: var(--ink);
            font-family: 'DM Sans', sans-serif;
            background-color: var(--paper);
            background-image: radial-gradient(#d5ddd1 0.7px, transparent 0.7px);
            background-size: 18px 18px;
        }
        .page { width: min(1040px, 100%); margin: 0 auto; padding: 48px 26px 24px; }
        .masthead { display: flex; align-items: center; justify-content: space-between; padding-bottom: 18px; border-bottom: 1px solid var(--line); }
        .brand { display: flex; align-items: center; gap: 10px; font-size: .8rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .brand-mark { width: 12px; height: 12px; background: var(--pink); border-radius: 50%; box-shadow: 5px 4px 0 var(--lime); }
        .eyebrow { margin: 0 0 12px; color: #68776d; font-size: .76rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        .intro { padding: 54px 0 34px; }
        h1 { margin: 0; font-family: 'Space Grotesk', sans-serif; font-size: clamp(2.6rem, 7vw, 5.1rem); line-height: .98; letter-spacing: 0; }
        .intro-copy { max-width: 520px; margin: 18px 0 0; color: var(--muted); font-size: 1.05rem; line-height: 1.65; }
        .profile-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .profile-card { display: grid; grid-template-columns: 84px minmax(0, 1fr); align-items: center; gap: 20px; min-height: 172px; padding: 22px; background: var(--white); border: 1px solid var(--line); border-radius: 6px; animation: arrive .45s both; }
        .profile-card:nth-child(2) { animation-delay: .06s; }
        .avatar-wrap { width: 84px; height: 84px; position: relative; }
        .avatar { width: 84px; height: 84px; display: block; object-fit: cover; border: 3px solid var(--lime); border-radius: 50%; background: #e6e9e2; }
        .avatar-fallback { position: absolute; inset: 0; display: grid; place-items: center; border: 3px solid var(--lime); border-radius: 50%; background: var(--ink); color: var(--lime); font-family: 'Space Grotesk', sans-serif; font-size: 1.8rem; }
        .avatar-fallback[hidden] { display: none; }
        .profile-card h2 { margin: 0; font-family: 'Space Grotesk', sans-serif; font-size: 1.25rem; line-height: 1.15; }
        .profile-card p { min-height: 1.5em; margin: 7px 0 14px; color: var(--muted); font-size: .9rem; line-height: 1.45; }
        .visit-link { display: inline-flex; align-items: center; gap: 8px; color: var(--ink); font-size: .88rem; font-weight: 800; text-decoration: none; }
        .visit-link:hover { text-decoration: underline; text-decoration-color: var(--pink); text-decoration-thickness: 2px; text-underline-offset: 4px; }
        .visit-link span { width: 24px; height: 24px; display: grid; place-items: center; border-radius: 50%; background: var(--lime); font-size: 1rem; }
        .empty { padding: 32px; border: 1px dashed #aeb8ac; color: var(--muted); }
        footer { display: flex; justify-content: space-between; align-items: center; margin-top: 36px; padding-top: 16px; border-top: 1px solid var(--line); color: #758078; font-size: .78rem; }
        .admin-open { padding: 7px 10px; border: 0; background: transparent; color: #758078; font: inherit; cursor: pointer; }
        .admin-open:hover { color: var(--ink); }
        .modal { position: fixed; inset: 0; z-index: 5; display: none; align-items: center; justify-content: center; padding: 18px; background: rgb(16 28 22 / 70%); }
        .modal.is-open { display: flex; }
        .admin-panel { width: min(760px, 100%); max-height: min(90vh, 900px); overflow: auto; padding: 24px; background: var(--paper); border-radius: 8px; box-shadow: 0 18px 70px rgb(0 0 0 / 25%); }
        .admin-heading { display: flex; justify-content: space-between; gap: 16px; align-items: start; margin-bottom: 18px; }
        .admin-heading h2 { margin: 0; font-family: 'Space Grotesk', sans-serif; font-size: 1.5rem; }
        .close-button { width: 36px; height: 36px; border: 1px solid var(--line); background: white; border-radius: 50%; font-size: 1.2rem; cursor: pointer; }
        .pin-row, .admin-actions { display: flex; gap: 9px; align-items: center; flex-wrap: wrap; }
        input { min-width: 0; padding: 10px 11px; border: 1px solid #bdc7bc; border-radius: 4px; background: white; color: var(--ink); font: inherit; }
        .pin-row input { flex: 1; }
        .action { padding: 10px 13px; border: 0; border-radius: 4px; background: var(--ink); color: white; font: inherit; font-size: .88rem; font-weight: 700; cursor: pointer; }
        .action.secondary { border: 1px solid var(--line); background: white; color: var(--ink); }
        .action.danger { background: #a94437; }
        .status { min-height: 24px; margin: 10px 0; color: #a94437; font-size: .88rem; }
        .metrics { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; margin: 12px 0 20px; }
        .metric { padding: 12px 14px; border: 1px solid var(--line); background: white; }
        .metric span { display: block; color: var(--muted); font-size: .8rem; }
        .metric strong { display: block; margin-top: 4px; font-family: 'Space Grotesk', sans-serif; font-size: 1.35rem; }
        .editor-title { margin: 22px 0 10px; font-family: 'Space Grotesk', sans-serif; font-size: 1.05rem; }
        .profile-row { position: relative; display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin: 9px 0; padding: 12px; border: 1px solid var(--line); background: white; }
        .profile-row input { width: 100%; }
        .profile-row .wide { grid-column: 1 / -1; }
        .profile-row .row-remove { justify-self: end; }
        .admin-actions { justify-content: space-between; margin-top: 14px; }
        @keyframes arrive { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 700px) {
            .page { padding: 28px 18px 18px; }
            .intro { padding: 42px 0 26px; }
            .profile-grid { grid-template-columns: 1fr; }
            .profile-card { min-height: 150px; }
        }
        @media (max-width: 440px) {
            .profile-card { grid-template-columns: 64px minmax(0, 1fr); gap: 14px; padding: 16px; }
            .avatar-wrap, .avatar { width: 64px; height: 64px; }
            .profile-row { grid-template-columns: 1fr; }
            .profile-row .wide { grid-column: auto; }
            .admin-panel { padding: 18px; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
    </style>
</head>
<body>
    <main class="page">
        <header class="masthead">
            <div class="brand"><span class="brand-mark" aria-hidden="true"></span> Reservio</div>
            <p class="eyebrow" style="margin:0">Naše profily</p>
        </header>
        <section class="intro" aria-labelledby="page-title">
            <p class="eyebrow">Najdete nás také</p>
            <h1 id="page-title">Na Instagramu.</h1>
            <p class="intro-copy">Vyberte si profil a podívejte se, co je u nás nového.</p>
        </section>
        <?php if ($profiles !== []): ?>
            <section class="profile-grid" aria-label="Instagramové účty">
                <?php foreach ($profiles as $profileId => $profile): ?>
                    <?php $avatarUrl = 'insta/profile_image.php?profile=' . rawurlencode((string)$profileId); ?>
                    <article class="profile-card">
                        <div class="avatar-wrap">
                            <img class="avatar" src="<?= instaH($avatarUrl) ?>" alt="Profilová fotografie <?= instaH((string)$profile['label']) ?>" loading="lazy" onerror="this.hidden=true; this.nextElementSibling.hidden=false">
                            <span class="avatar-fallback" aria-hidden="true" hidden><?= instaH(instaGetProfileInitial((string)$profile['label'])) ?></span>
                        </div>
                        <div>
                            <h2><?= instaH((string)$profile['label']) ?></h2>
                            <p><?= instaH((string)($profile['description'] ?? '')) ?></p>
                            <a class="visit-link" href="insta/go.php?profile=<?= rawurlencode((string)$profileId) ?>">
                                <span aria-hidden="true">↗</span> Zobrazit profil
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <p class="empty">Momentálně tu nejsou žádné profily.</p>
        <?php endif; ?>
        <footer>
            <span>© Reservio</span>
            <button class="admin-open" id="adminOpen" type="button">Správa</button>
        </footer>
    </main>

    <div class="modal" id="adminModal" aria-hidden="true">
        <section class="admin-panel" role="dialog" aria-modal="true" aria-labelledby="adminTitle">
            <div class="admin-heading">
                <div><p class="eyebrow">Instagram</p><h2 id="adminTitle">Správa stránky</h2></div>
                <button class="close-button" id="adminClose" type="button" aria-label="Zavřít">×</button>
            </div>
            <div class="pin-row">
                <input type="password" id="adminPin" autocomplete="current-password" placeholder="Administrační PIN" aria-label="Administrační PIN">
                <button class="action" id="adminLoad" type="button">Odemknout</button>
            </div>
            <p class="status" id="adminStatus" role="status" aria-live="polite"></p>
            <div id="adminContent" hidden>
                <div class="metrics" id="metrics"></div>
                <h3 class="editor-title">Instagramové účty</h3>
                <div id="profileEditor"></div>
                <div class="admin-actions">
                    <div class="pin-row">
                        <button class="action secondary" id="addProfile" type="button">+ Přidat účet</button>
                        <button class="action" id="saveProfiles" type="button">Uložit účty</button>
                    </div>
                    <button class="action danger" id="resetStats" type="button">Resetovat počítadla</button>
                </div>
            </div>
        </section>
    </div>
    <script>
        const modal = document.getElementById('adminModal');
        const pinInput = document.getElementById('adminPin');
        const statusText = document.getElementById('adminStatus');
        const adminContent = document.getElementById('adminContent');
        const profileEditor = document.getElementById('profileEditor');
        const metrics = document.getElementById('metrics');

        function makeInput(value, placeholder, name, maxLength) {
            const input = document.createElement('input');
            input.value = value || '';
            input.placeholder = placeholder;
            input.name = name;
            input.maxLength = maxLength;
            input.setAttribute('aria-label', placeholder);
            return input;
        }

        function addProfileRow(profile = {}) {
            const row = document.createElement('div');
            row.className = 'profile-row';
            row.dataset.id = profile.id || `profile_${Date.now()}_${Math.floor(Math.random() * 1000)}`;
            const title = makeInput(profile.label, 'Název účtu', 'label', 80);
            const url = makeInput(profile.url, 'https://www.instagram.com/uzivatel/', 'url', 500);
            url.type = 'url';
            url.required = true;
            const description = makeInput(profile.description, 'Krátký popis', 'description', 240);
            description.classList.add('wide');
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'action secondary row-remove';
            remove.textContent = 'Odebrat účet';
            remove.addEventListener('click', () => row.remove());
            row.append(title, url, description, remove);
            profileEditor.append(row);
        }

        function renderAdmin(data) {
            metrics.replaceChildren();
            const metricItems = [
                ['Načtení stránky', data.stats.visits_total],
                ...(data.profiles || []).map((profile) => [`Kliknutí · ${profile.label}`, profile.clicks])
            ];
            metricItems.forEach(([label, value]) => {
                const metric = document.createElement('div');
                metric.className = 'metric';
                const caption = document.createElement('span');
                caption.textContent = label;
                const count = document.createElement('strong');
                count.textContent = String(Number(value) || 0);
                metric.append(caption, count);
                metrics.append(metric);
            });

            profileEditor.replaceChildren();
            (data.profiles || []).forEach(addProfileRow);
            adminContent.hidden = false;
        }

        async function loadAdmin() {
            const pin = pinInput.value.trim();
            if (!pin) {
                statusText.textContent = 'Zadejte administrační PIN.';
                return;
            }
            statusText.textContent = 'Načítám…';
            try {
                const response = await fetch('insta/admin_stats.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    cache: 'no-store',
                    body: JSON.stringify({ pin })
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Přístup se nepodařilo ověřit.');
                statusText.textContent = '';
                renderAdmin(data);
            } catch (error) {
                adminContent.hidden = true;
                statusText.textContent = error.message || 'Chyba spojení se serverem.';
            }
        }

        async function saveProfiles() {
            const profiles = [...profileEditor.querySelectorAll('.profile-row')].map((row) => ({
                id: row.dataset.id,
                label: row.querySelector('[name="label"]').value.trim(),
                url: row.querySelector('[name="url"]').value.trim(),
                description: row.querySelector('[name="description"]').value.trim()
            }));
            statusText.textContent = 'Ukládám účty…';
            try {
                const response = await fetch('insta/admin_profiles_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pin: pinInput.value.trim(), profiles })
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Účty se nepodařilo uložit.');
                statusText.textContent = data.message;
                await loadAdmin();
            } catch (error) {
                statusText.textContent = error.message || 'Chyba spojení se serverem.';
            }
        }

        async function resetStats() {
            if (!window.confirm('Opravdu vynulovat všechna načtení a kliknutí?')) return;
            statusText.textContent = 'Resetuji počítadla…';
            try {
                const response = await fetch('insta/admin_reset.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pin: pinInput.value.trim() })
                });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.error || 'Počítadla se nepodařilo resetovat.');
                statusText.textContent = data.message;
                await loadAdmin();
            } catch (error) {
                statusText.textContent = error.message || 'Chyba spojení se serverem.';
            }
        }

        document.getElementById('adminOpen').addEventListener('click', () => {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            pinInput.focus();
        });
        document.getElementById('adminClose').addEventListener('click', () => {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        });
        modal.addEventListener('click', (event) => {
            if (event.target === modal) document.getElementById('adminClose').click();
        });
        document.getElementById('adminLoad').addEventListener('click', loadAdmin);
        document.getElementById('addProfile').addEventListener('click', () => addProfileRow());
        document.getElementById('saveProfiles').addEventListener('click', saveProfiles);
        document.getElementById('resetStats').addEventListener('click', resetStats);
        pinInput.addEventListener('keydown', (event) => { if (event.key === 'Enter') loadAdmin(); });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modal.classList.contains('is-open')) document.getElementById('adminClose').click(); });
    </script>
</body>
</html>