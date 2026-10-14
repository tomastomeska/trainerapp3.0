<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Smazání účtu – <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #0f766e 100%);
            color: #e2e8f0;
            min-height: 100vh;
            padding: 32px 16px;
        }

        .policy-card {
            max-width: 960px;
            margin: 0 auto;
            background: rgba(15, 23, 42, 0.82);
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
        }

        .policy-card .card-body {
            padding: 32px clamp(20px, 4vw, 40px);
        }

        h1 {
            font-size: clamp(2.1rem, 5vw, 3.2rem);
            margin-bottom: 0.9rem;
            color: #f8fafc;
            font-weight: 800;
            letter-spacing: -0.04em;
            line-height: 1.15;
        }

        h2 {
            margin-top: 1.7rem;
            font-size: 1.35rem;
            color: #67e8f9;
        }

        p,
        li {
            color: #dbe7ff;
            line-height: 1.7;
        }

        ul {
            padding-left: 1.2rem;
        }

        .muted {
            color: #cbd5e1;
        }

        .top-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .btn-back {
            background: #67e8f9;
            border: 0;
            color: #082f49;
            font-weight: 700;
        }

        .btn-back:hover {
            background: #a5f3fc;
            color: #082f49;
        }

        .info-box {
            background: rgba(8, 47, 73, 0.5);
            border: 1px solid rgba(103, 232, 249, 0.3);
            border-radius: 16px;
            padding: 18px 20px;
            margin: 1.2rem 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-actions">
            <a href="<?= h(BASE_URL) ?>/login.php" class="btn btn-back">← Zpět na přihlášení</a>
        </div>

        <div class="card policy-card">
            <div class="card-body">
                <p class="muted mb-2"><?= h(APP_NAME) ?></p>
                <h1>Žádost o smazání účtu</h1>
                <p>Uživatelé mohou požádat o smazání svého účtu a přidružených dat. Níže najdete přesný postup, typy údajů, které budou smazány, a údaje, které mohou být v některých případech ponechány z důvodu zákonných povinností.</p>

                <div class="info-box">
                    <strong>Google Play / obchodní požadavek:</strong> Tato stránka slouží jako veřejná adresa pro žádost o odstranění účtu a souvisejících dat. Uživatelé mohou požadavek zaslat prostřednictvím aplikace nebo e-mailu a my údaje zpracujeme v souladu s GDPR a platnými zákonnými povinnostmi.
                </div>

                <h2>1. Jak požádat o smazání účtu</h2>
                <p>Požadavek na smazání účtu lze odeslat:</p>
                <ul>
                    <li>přímo v profilu aplikace ve formuláři „Žádost o smazání účtu“,</li>
                    <li>na kontaktní e-mail: <a href="mailto:info@reservio.online" class="text-info">info@reservio.online</a>.</li>
                </ul>
                <p>V žádosti uveďte prosím jméno, e-mail, které účty vlastníte, a případný důvod. Obdržíme-li požadavek, zpracujeme jej v nejkratším možném termínu.</p>

                <h2>2. Jaká data budou smazána</h2>
                <p>Po schválení žádosti budou smazány nebo anonymizovány následující typy dat:</p>
                <ul>
                    <li>osobní profil uživatele a přístupová data k účtu,</li>
                    <li>údaje o přihlášení, heslech a bezpečnostních nastaveních,</li>
                    <li>profilová data, soukromé poznámky a osobní nastavení,</li>
                    <li>záznamy o trénincích, plánování, komunikaci a průběhu aktivity v rozsahu aplikace,</li>
                    <li>přidružené soubory, fotografie nebo dočasné datové záznamy spojené s účtem.</li>
                </ul>

                <h2>3. Jaká data mohou být ponechána</h2>
                <p>V některých případech mohou být některé záznamy uchovány i po smazání účtu, pokud je to vyžadováno:</p>
                <ul>
                    <li>zákonnými povinnostmi, zejména v oblasti účetnictví, daňové evidence, fakturace nebo vymáhání práv,</li>
                    <li>ochranou před podvodem, porušováním pravidel nebo řešením obchodních sporů,</li>
                    <li>záznamy o platbách, fakturách nebo přijatých úhradách, které je třeba uchovávat podle právních předpisů,</li>
                    <li>technické záznamy sloužící k bezpečnosti nebo analýze incidentů, pokud je jejich uchování oprávněné.</li>
                </ul>
                <p>Taková data jsou uchovávána pouze po nezbytně nutnou dobu a v rozsahu vyžadovaném platnými předpisy. V praxi se jedná o data, která nejsou potřebná pro běžné provozování účtu, ale mohou být relevantní pro právní, daňové nebo bezpečnostní povinnosti.</p>

                <h2>4. Doba uchování</h2>
                <p>Většina osobních dat spojených s účtem bude smazána bez zbytečného odkladu po schválení žádosti. U dat, které musí být zachována z důvodu zákonných povinností, se doba uchování stanoví podle platných právních předpisů a konkrétních evidenčních požadavků. V některých případech může jít o kratší nebo delší období dle typu záznamu a konkrétního vztahu k uživateli.</p>

                <h2>5. Kontakty a další informace</h2>
                <p>Pokud máte dotaz k smazání účtu nebo potřebujete doplnit žádost, kontaktujte nás na e-mailu <a href="mailto:info@reservio.online" class="text-info">info@reservio.online</a>.</p>
                <p>Více informací o zpracování osobních údajů naleznete také v <a href="<?= h(BASE_URL) ?>/privacy_policy.php" class="text-info">Zásadách ochrany soukromí</a>.</p>

                <p class="mt-4 mb-0 muted">Tato stránka byla vytvořena pro potřeby Google Play a pro zpřístupnění jasného a věcného postupu smazání účtu a přidružených dat.</p>
            </div>
        </div>
    </div>
</body>
</html>
