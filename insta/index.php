<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$profiles = instaGetProfiles();
$avatarVersion = (string)@filemtime(__DIR__ . '/lib.php');
instaRecordVisit();
?><!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Insta</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-a: #0f172a;
            --bg-b: #1d4ed8;
            --panel: rgba(255, 255, 255, 0.12);
            --line: rgba(255, 255, 255, 0.24);
            --text: #f8fafc;
            --muted: #cbd5e1;
            --accent: #22d3ee;
            --btn: #f59e0b;
            --btn-hover: #fbbf24;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
        }

        body {
            font-family: 'Manrope', sans-serif;
            color: var(--text);
            background:
                radial-gradient(1200px 600px at 10% -20%, rgba(34, 211, 238, 0.25), transparent 60%),
                radial-gradient(900px 450px at 100% 20%, rgba(245, 158, 11, 0.2), transparent 65%),
                linear-gradient(135deg, var(--bg-a), var(--bg-b));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .wrap {
            width: min(1040px, 100%);
            border: 1px solid var(--line);
            border-radius: 24px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.16), rgba(255, 255, 255, 0.08));
            backdrop-filter: blur(8px);
            box-shadow: 0 20px 60px rgba(2, 6, 23, 0.35);
            overflow: hidden;
            position: relative;
        }

        .top {
            padding: 28px 24px 16px;
            border-bottom: 1px solid var(--line);
        }

        h1 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.8rem, 4vw, 2.4rem);
            letter-spacing: 0.02em;
        }

        .sub {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 0.98rem;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            padding: 20px;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 18px;
            text-align: center;
        }

        .card h2 {
            margin: 2px 0 14px;
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: 0.02em;
        }

        .profile-avatar {
            width: 78px;
            height: 78px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255, 255, 255, 0.35);
            box-shadow: 0 8px 20px rgba(2, 6, 23, 0.3);
            margin: 2px auto 12px;
            display: block;
            background: #e2e8f0;
        }

        .profile-avatar.is-hidden {
            display: none;
        }

        .profile-avatar-wrap {
            width: 78px;
            height: 78px;
            margin: 2px auto 12px;
        }

        .profile-avatar-fallback {
            width: 78px;
            height: 78px;
            border-radius: 50%;
            margin: 0;
            display: none;
            align-items: center;
            justify-content: center;
            border: 3px solid rgba(255, 255, 255, 0.35);
            box-shadow: 0 8px 20px rgba(2, 6, 23, 0.3);
            background: linear-gradient(135deg, #0ea5e9, #22c55e);
            color: #f8fafc;
            font-weight: 800;
            font-size: 1.5rem;
            letter-spacing: 0.02em;
        }

        .profile-avatar-fallback.is-visible {
            display: flex;
        }

        .qr {
            width: min(100%, 320px);
            aspect-ratio: 1;
            object-fit: cover;
            border-radius: 14px;
            border: 2px solid rgba(255, 255, 255, 0.26);
            background: #fff;
        }

        .go {
            display: inline-block;
            margin-top: 14px;
            padding: 10px 16px;
            border-radius: 999px;
            color: #111827;
            text-decoration: none;
            font-weight: 800;
            background: var(--btn);
            transition: transform 0.15s ease, background 0.15s ease;
        }

        .go:hover {
            background: var(--btn-hover);
            transform: translateY(-1px);
        }

        .hotspot {
            position: absolute;
            right: 8px;
            bottom: 6px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            opacity: 0.14;
            cursor: pointer;
            background: #ffffff;
            border: 0;
        }

        .admin {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(2, 6, 23, 0.7);
            padding: 16px;
            z-index: 50;
        }

        .admin.show {
            display: flex;
        }

        .admin-card {
            width: min(520px, 100%);
            border-radius: 16px;
            border: 1px solid #334155;
            background: #0b1220;
            color: #e2e8f0;
            padding: 18px;
        }

        .admin-title {
            margin: 0 0 12px;
            font-family: 'Space Grotesk', sans-serif;
        }

        .admin-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .admin-row input {
            flex: 1;
            border-radius: 10px;
            border: 1px solid #334155;
            background: #020617;
            color: #fff;
            padding: 10px 12px;
            font-size: 1rem;
        }

        .btn {
            border: 0;
            border-radius: 10px;
            padding: 10px 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-load {
            background: #22c55e;
            color: #052e16;
        }

        .btn-close {
            background: #334155;
            color: #e2e8f0;
        }

        .btn-reset {
            background: #ef4444;
            color: #fff;
        }

        .status {
            min-height: 22px;
            color: #fca5a5;
            font-size: 0.92rem;
            margin-bottom: 8px;
        }

        .metric {
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 10px 12px;
            margin-top: 10px;
            background: #0f172a;
        }

        .metric strong {
            color: #22d3ee;
        }

        @media (max-width: 820px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main class="wrap">
        <header class="top">
            <h1>Sledujte nas na Instagramu</h1>
            <p class="sub">Naskenujte QR kod nebo klepnete na tlacitko pod kodem.</p>
        </header>

        <section class="grid">
            <?php foreach ($profiles as $profileId => $profile): ?>
                <?php
                    $profileAvatarSrc = 'profile_image.php?profile=' . urlencode((string)$profileId)
                        . '&v=' . urlencode(substr(sha1((string)($profile['url'] ?? '') . '|' . (string)($profile['profile_image'] ?? '')), 0, 10));
                    if ($avatarVersion !== '') {
                        $profileAvatarSrc .= '&cv=' . urlencode($avatarVersion);
                    }
                ?>
                <article class="card">
                    <div class="profile-avatar-wrap">
                        <img
                            class="profile-avatar"
                            src="<?= instaH($profileAvatarSrc) ?>"
                            alt="Profilovy obrazek <?= instaH((string)$profile['label']) ?>"
                            loading="lazy"
                            onerror="this.classList.add('is-hidden'); if (this.nextElementSibling) { this.nextElementSibling.classList.add('is-visible'); }"
                        >
                        <div class="profile-avatar-fallback" aria-hidden="true"><?= instaH(instaGetProfileInitial((string)$profile['label'])) ?></div>
                    </div>
                    <h2><?= instaH((string)$profile['label']) ?></h2>
                    <img class="qr" src="<?= instaH(instaGetQrImageWebPath((string)$profile['qr_file'])) ?>" alt="QR kod pro <?= instaH((string)$profile['label']) ?>" loading="lazy">
                    <div>
                        <a class="go" href="go.php?profile=<?= urlencode((string)$profileId) ?>">Otevrit Instagram</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <button class="hotspot" id="adminHotspot" type="button" aria-label="Admin vstup"></button>
    </main>

    <div class="admin" id="adminModal" aria-hidden="true">
        <div class="admin-card">
            <h2 class="admin-title">Miniadministrace Insta</h2>
            <div class="admin-row">
                <input type="password" id="adminPin" placeholder="Zadejte PIN">
                <button class="btn btn-load" id="adminLoad" type="button">Nacist</button>
            </div>
            <div class="status" id="adminStatus"></div>
            <div id="adminData"></div>
            <div class="admin-row" style="justify-content:space-between; margin-top:12px;">
                <button class="btn btn-reset" id="adminReset" type="button">Resetovat pocty</button>
                <button class="btn btn-close" id="adminClose" type="button">Zavrit</button>
            </div>
        </div>
    </div>

    <script>
        const hotspot = document.getElementById('adminHotspot');
        const adminModal = document.getElementById('adminModal');
        const adminPin = document.getElementById('adminPin');
        const adminLoad = document.getElementById('adminLoad');
        const adminStatus = document.getElementById('adminStatus');
        const adminData = document.getElementById('adminData');
        const adminReset = document.getElementById('adminReset');
        const adminClose = document.getElementById('adminClose');

        hotspot.addEventListener('dblclick', () => {
            adminModal.classList.add('show');
            adminModal.setAttribute('aria-hidden', 'false');
            adminPin.value = '';
            adminStatus.textContent = '';
            adminData.innerHTML = '';
            adminPin.focus();
        });

        adminClose.addEventListener('click', () => {
            adminModal.classList.remove('show');
            adminModal.setAttribute('aria-hidden', 'true');
        });

        adminModal.addEventListener('click', (event) => {
            if (event.target === adminModal) {
                adminModal.classList.remove('show');
                adminModal.setAttribute('aria-hidden', 'true');
            }
        });

        async function loadAdminStats() {
            const pin = adminPin.value.trim();
            if (!pin) {
                adminStatus.textContent = 'Nejdriv zadejte PIN.';
                adminData.innerHTML = '';
                return;
            }

            adminStatus.textContent = 'Nacitam...';
            adminData.innerHTML = '';

            try {
                const response = await fetch(`admin_stats.php?pin=${encodeURIComponent(pin)}`, {
                    method: 'GET',
                    credentials: 'same-origin'
                });
                const data = await response.json();

                if (!data.success) {
                    adminStatus.textContent = data.error || 'Nepodarilo se nacist statistiky.';
                    return;
                }

                adminStatus.textContent = '';

                const metrics = [];
                metrics.push(`<div class="metric"><div>Pristupy na stranku</div><strong>${Number(data.stats.visits_total || 0)}</strong></div>`);

                (data.profiles || []).forEach((profile) => {
                    metrics.push(
                        `<div class="metric"><div>${profile.label}</div><strong>${Number(profile.clicks || 0)}</strong> kliknuti na odkaz</div>`
                    );
                });

                metrics.push(`<div class="metric"><div>Posledni zmena</div><strong>${data.stats.updated_at || '-'}</strong></div>`);
                adminData.innerHTML = metrics.join('');
            } catch (error) {
                adminStatus.textContent = 'Chyba spojeni se serverem.';
            }
        }

        async function resetAdminStats() {
            const pin = adminPin.value.trim();
            if (!pin) {
                adminStatus.textContent = 'Nejdriv zadejte PIN.';
                return;
            }

            const ok = confirm('Opravdu resetovat vsechny pocty?');
            if (!ok) {
                return;
            }

            adminStatus.textContent = 'Resetuji...';

            try {
                const response = await fetch('admin_reset.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ pin })
                });

                const data = await response.json();
                if (!data.success) {
                    adminStatus.textContent = data.error || 'Reset se nepodaril.';
                    return;
                }

                adminStatus.textContent = data.message || 'Reset dokonceny.';
                await loadAdminStats();
            } catch (error) {
                adminStatus.textContent = 'Chyba spojeni se serverem.';
            }
        }

        adminLoad.addEventListener('click', loadAdminStats);
        adminReset.addEventListener('click', resetAdminStats);
        adminPin.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                loadAdminStats();
            }
        });
    </script>
</body>
</html>
