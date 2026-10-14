<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zásady ochrany soukromí – <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%);
            color: #e2e8f0;
            min-height: 100vh;
            padding: 32px 16px;
        }

        .policy-card {
            max-width: 920px;
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
            text-shadow: 0 0 22px rgba(251, 191, 36, 0.18);
        }

        h2 {
            margin-top: 1.7rem;
            font-size: 1.35rem;
            color: #fbbf24;
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
            background: #fbbf24;
            border: 0;
            color: #111827;
            font-weight: 700;
        }

        .btn-back:hover {
            background: #facc15;
            color: #111827;
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
                <p class="muted mb-2">TrainerApp</p>
                <h1>Zásady ochrany soukromí</h1>
                <p>Tyto zásady popisují, jak <?= h(APP_NAME) ?> shromažďuje, používá a chrání údaje osob, které využívají naši platformu pro plánování tréninků, komunikaci a správu dat.</p>

                <h2>1. Jaké údaje zpracováváme</h2>
                <p>Zpracováváme zejména:</p>
                <ul>
                    <li>identifikační údaje a kontaktní údaje (jméno, příjmení, e-mail, telefon, přihlašovací jméno),</li>
                    <li>údaje o účtu, přístupu a nastavení aplikace,</li>
                    <li>údaje o tréninku, plánech, termínech, notifikacích a souvisejících aktivitách,</li>
                    <li>technické údaje o přístupu (IP adresa, typ zařízení, prohlížeč, čas přístupu a logy přístupů),</li>
                    <li>data potřebná pro podporu, reset hesla a zabezpečení účtu.</li>
                </ul>

                <h2>2. Proč údaje zpracováváme</h2>
                <p>Údaje používáme pro:</p>
                <ul>
                    <li>poskytování a zajištění funkčnosti aplikace,</li>
                    <li>vytváření a správu uživatelských účtů,</li>
                    <li>plánování tréninků, komunikaci a evidenci aktivity,</li>
                    <li>bezpečnost, ochranu před zneužitím a odhalování incidentů,</li>
                    <li>technickou podporu, služby a vývoj platformy.</li>
                </ul>

                <h2>3. Zásady ukládání a přístupu</h2>
                <p>Osobní údaje jsou ukládány v zabezpečeném prostředí a jsou přístupné pouze oprávněným osobám, které je potřebují k provozu systému nebo k řešení podpory. Přístup je omezen na základě role, oprávnění a bezpečnostních pravidel aplikace.</p>

                <h2>4. Délka uchování</h2>
                <p>Údaje uchováváme po dobu nezbytnou k plnění účelu, pro který byly shromážděny, nebo po dobu, která je stanovena právními předpisy, vnitřními pravidly nebo požadavky smluvních vztahů.</p>

                <h2>5. Vaše práva</h2>
                <p>V souladu s platnými předpisy máte právo:</p>
                <ul>
                    <li>žádat přístup ke svým osobním údajům,</li>
                    <li>požadovat opravu nepřesných nebo neúplných údajů,</li>
                    <li>požadovat omezení zpracování,</li>
                    <li>požadovat vymazání údajů, pokud to zákon nebrání,</li>
                    <li>vznést námitku proti zpracování,</li>
                    <li>převést data do strojově čitelné podoby.</li>
                </ul>
                <p>V případě žádosti o výkon těchto práv nás kontaktujte na e-mailu <a href="mailto:info@reservio.online" class="text-warning">info@reservio.online</a>.</p>

                <h2>6. Soubory cookies a technické nástroje</h2>
                <p>Platforma může používat technické záznamy, cookies nebo obdobné mechanismy k zajištění bezpečnosti, přihlášení, udržení relace a správného fungování aplikace. Pokud je v určitém kontextu vyžadováno další informování, poskytne se samostatné oznámení.</p>

                <h2>7. Kontaktní údaje</h2>
                <p>Pro otázky k ochraně osobních údajů, žádostem o přístup nebo návrhům na zlepšení kontaktujte:</p>
                <p><strong>Tomáš Tomeška</strong><br>
                E-mail: <a href="mailto:info@reservio.online" class="text-warning">info@reservio.online</a></p>

                <p class="mt-4 mb-0 muted">Tato verze zásad je určena pro informování uživatelů a může být při změně funkcí aplikace aktualizována.</p>
            </div>
        </div>
    </div>
</body>
</html>
