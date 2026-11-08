<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
require_once __DIR__ . '/includes/header.php';

renderHeader('Návod: Online tréninky', false, true);
?>
<style>
.ot-manual { --ot-ink: #20252b; --ot-muted: #64748b; --ot-accent: #f0ad00; --ot-line: #e2e8f0; max-width: 1120px; margin-inline: auto; }
.ot-manual-hero { position: relative; overflow: hidden; border-radius: 1rem; padding: clamp(1.5rem, 4vw, 3rem); color: #fff; background: linear-gradient(120deg, #20252b 0%, #303943 68%, #414b56 100%); }
.ot-manual-hero::after { position: absolute; right: -3rem; bottom: -7rem; width: 18rem; height: 18rem; border: 2rem solid rgb(255 255 255 / 5%); border-radius: 50%; content: ""; }
.ot-manual-hero__tag { color: #ffd166; font-size: .78rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
.ot-manual-hero h1 { max-width: 760px; font-size: clamp(2rem, 4.5vw, 3.25rem); font-weight: 850; letter-spacing: -.035em; }
.ot-manual-hero p { max-width: 760px; color: #e2e8f0; font-size: 1.08rem; }
.ot-manual-nav { display: flex; flex-wrap: wrap; gap: .55rem; margin-block: 1rem 2rem; }
.ot-manual-nav a { border: 1px solid var(--ot-line); border-radius: 999px; padding: .4rem .8rem; color: #334155; background: #fff; font-size: .86rem; text-decoration: none; }
.ot-manual-nav a:hover { border-color: #e6b800; background: #fffbeb; }
.ot-manual-section { scroll-margin-top: 1.25rem; margin-block: 2.5rem; }
.ot-manual-section h2 { margin-bottom: .35rem; font-size: clamp(1.35rem, 2.5vw, 1.8rem); font-weight: 800; }
.ot-manual-lead { max-width: 820px; color: var(--ot-muted); }
.ot-manual-step { height: 100%; border: 1px solid var(--ot-line); border-radius: .9rem; background: #fff; box-shadow: 0 .25rem .9rem rgb(15 23 42 / 4%); }
.ot-manual-step__body { padding: 1.1rem; }
.ot-manual-step__number { display: inline-grid; width: 2rem; height: 2rem; place-items: center; margin-right: .4rem; border-radius: 50%; color: #563e00; background: #fff0bd; font-weight: 850; }
.ot-manual-note { border-left: 4px solid #e6b800; border-radius: .35rem; padding: 1rem 1.15rem; background: #fffbeb; }
.ot-manual-note--blue { border-color: #3b82f6; background: #eff6ff; }
.ot-manual-note--red { border-color: #ef4444; background: #fef2f2; }
.ot-manual-figure { overflow: hidden; border: 1px solid var(--ot-line); border-radius: 1rem; background: linear-gradient(180deg, #fff, #f8fafc); }
.ot-manual-figure svg { display: block; width: 100%; height: auto; }
.ot-manual-figure figcaption { padding: .75rem 1rem; border-top: 1px solid var(--ot-line); color: var(--ot-muted); font-size: .88rem; }
.ot-manual-status { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .7rem; }
.ot-manual-status > div { border: 1px solid var(--ot-line); border-radius: .7rem; padding: .85rem; background: #fff; }
.ot-manual-status strong { display: block; margin-bottom: .25rem; }
.ot-manual-table th { background: #f1f5f9; }
.ot-manual-table td, .ot-manual-table th { padding: .65rem; }
.ot-manual-faq details { border: 1px solid var(--ot-line); border-radius: .7rem; padding: .9rem 1rem; background: #fff; }
.ot-manual-faq details + details { margin-top: .6rem; }
.ot-manual-faq summary { cursor: pointer; font-weight: 700; }
@media (max-width: 767.98px) { .ot-manual-status { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media print {
    body { background: #fff !important; }
    .ot-manual { max-width: none; font-size: 10pt; }
    .ot-manual-hero { padding: 1.2rem; color: #111 !important; background: #f1f5f9 !important; }
    .ot-manual-hero p, .ot-manual-hero__tag { color: #334155 !important; }
    .ot-manual-nav, .ot-manual-back { display: none !important; }
    .ot-manual-section { margin-block: 1.2rem; break-inside: avoid; }
    .ot-manual-step, .ot-manual-figure, .ot-manual-faq details { box-shadow: none; break-inside: avoid; }
}
</style>

<main class="ot-manual">
    <div class="d-flex align-items-center gap-2 mb-3 ot-manual-back">
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/online_training.php"><i class="fas fa-arrow-left me-1"></i>Zpět na online tréninky</a>
        <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()"><i class="fas fa-print me-1"></i>Tisk / PDF</button>
    </div>

    <header class="ot-manual-hero">
        <div class="ot-manual-hero__tag mb-2">Průvodce pro trenéry</div>
        <h1 class="mb-3">Online tréninky od přípravy po zpětnou vazbu</h1>
        <p class="mb-0">Vytvořte sportovci samostatný tréninkový plán, přidejte jasné instrukce, předepsané hodnoty i ukázky cviků a po odcvičení zkontrolujte výsledek.</p>
    </header>

    <nav class="ot-manual-nav" aria-label="Obsah návodu">
        <a href="#prehled">Jak to funguje</a>
        <a href="#predplatne">Předplatné</a>
        <a href="#vytvoreni">Vytvoření</a>
        <a href="#priprava">Příprava a odeslání</a>
        <a href="#vyhodnoceni">Výsledky</a>
        <a href="#uctovani">Účtování</a>
        <a href="#potize">Řešení potíží</a>
    </nav>

    <section class="ot-manual-section" id="prehled">
        <h2>1. Jak online trénink funguje</h2>
        <p class="ot-manual-lead">Online trénink je plán, který sportovec samostatně odcvičí a zaznamená v aplikaci. Není to klasická společná tréninková jednotka.</p>
        <figure class="ot-manual-figure">
            <svg viewBox="0 0 1000 220" role="img" aria-labelledby="ot-flow-title ot-flow-desc">
                <title id="ot-flow-title">Průběh online tréninku</title>
                <desc id="ot-flow-desc">Trenér připraví předplatné a trénink, odešle ho sportovci, sportovec ho odcvičí a trenér zkontroluje výsledky.</desc>
                <defs><marker id="ot-arrow" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto"><path d="M0,0 L0,6 L9,3 z" fill="#94a3b8"/></marker></defs>
                <rect width="1000" height="220" fill="#f8fafc"/>
                <g stroke="#cbd5e1" stroke-width="4" marker-end="url(#ot-arrow)"><path d="M175 109 H270"/><path d="M375 109 H470"/><path d="M575 109 H670"/><path d="M775 109 H870"/></g>
                <g font-family="system-ui, sans-serif" text-anchor="middle">
                    <g transform="translate(30 46)"><rect width="145" height="126" rx="18" fill="#fff" stroke="#f0ad00" stroke-width="3"/><circle cx="72" cy="38" r="20" fill="#fff0bd"/><text x="72" y="45" font-size="21" font-weight="800" fill="#7c5c00">1</text><text x="72" y="82" font-size="16" font-weight="700" fill="#20252b">Předplatné</text><text x="72" y="105" font-size="12" fill="#64748b">u sportovce</text></g>
                    <g transform="translate(270 46)"><rect width="145" height="126" rx="18" fill="#fff" stroke="#cbd5e1" stroke-width="2"/><circle cx="72" cy="38" r="20" fill="#e2e8f0"/><text x="72" y="45" font-size="21" font-weight="800" fill="#334155">2</text><text x="72" y="82" font-size="16" font-weight="700" fill="#20252b">Příprava</text><text x="72" y="105" font-size="12" fill="#64748b">cviky a zadání</text></g>
                    <g transform="translate(470 46)"><rect width="145" height="126" rx="18" fill="#fff" stroke="#cbd5e1" stroke-width="2"/><circle cx="72" cy="38" r="20" fill="#e2e8f0"/><text x="72" y="45" font-size="21" font-weight="800" fill="#334155">3</text><text x="72" y="82" font-size="16" font-weight="700" fill="#20252b">Odeslání</text><text x="72" y="105" font-size="12" fill="#64748b">sportovci přijde zpráva</text></g>
                    <g transform="translate(670 46)"><rect width="145" height="126" rx="18" fill="#fff" stroke="#cbd5e1" stroke-width="2"/><circle cx="72" cy="38" r="20" fill="#e2e8f0"/><text x="72" y="45" font-size="21" font-weight="800" fill="#334155">4</text><text x="72" y="82" font-size="16" font-weight="700" fill="#20252b">Odcvičení</text><text x="72" y="105" font-size="12" fill="#64748b">zápis výsledků</text></g>
                    <g transform="translate(870 46)"><rect width="100" height="126" rx="18" fill="#fff" stroke="#cbd5e1" stroke-width="2"/><circle cx="50" cy="38" r="20" fill="#e2e8f0"/><text x="50" y="45" font-size="21" font-weight="800" fill="#334155">5</text><text x="50" y="82" font-size="15" font-weight="700" fill="#20252b">Kontrola</text><text x="50" y="105" font-size="12" fill="#64748b">a reakce</text></g>
                </g>
            </svg>
            <figcaption>Schéma základního postupu. Tréninky se připravují v sekci <strong>Online tréninky</strong>.</figcaption>
        </figure>
        <div class="row g-3 mt-2">
            <div class="col-md-6"><div class="ot-manual-note h-100"><strong>Důležité:</strong> Online tréninky jsou oddělené od běžných tréninků s trenérem. Výsledky online tréninku se nepoužijí jako poslední výkon sportovce v klasickém tréninku.</div></div>
            <div class="col-md-6"><div class="ot-manual-note ot-manual-note--blue h-100"><strong>Číslování:</strong> Každý sportovec má u každého trenéra vlastní řadu online tréninků od 1. Zobrazené číslo (například ONLINE #003) je pořadové číslo, ne databázové ID.</div></div>
        </div>
    </section>

    <section class="ot-manual-section" id="predplatne">
        <h2>2. Nejdřív ověřte online předplatné</h2>
        <p class="ot-manual-lead">Předplatné se spravuje u konkrétního sportovce. Platné a uhrazené časové předplatné je nutné k vytvoření i odeslání online tréninku.</p>
        <div class="row g-3">
            <div class="col-lg-7">
                <ol class="mb-0">
                    <li class="mb-2">Otevřete <strong>Sportovci</strong> a přejděte do detailu sportovce.</li>
                    <li class="mb-2">Najděte část <strong>Online předplatné</strong> a zadejte cenu, datum začátku a datum konce platnosti.</li>
                    <li class="mb-2">Uložte předplatné. U placeného předplatného aplikace vytvoří platební výzvu; zkontrolujte její úhradu v <strong>Platbách</strong>.</li>
                    <li>Ujistěte se, že předplatné již platí. Budoucí předplatné lze využít až od data začátku.</li>
                </ol>
            </div>
            <div class="col-lg-5">
                <div class="ot-manual-note ot-manual-note--blue h-100">
                    <strong>Co si pohlídat</strong>
                    <ul class="mb-0 mt-2">
                        <li>Předplatné za 0 Kč je bezplatné a nečeká na úhradu.</li>
                        <li>U placeného předplatného musí být výzva uhrazena.</li>
                        <li>Počet tréninků v platném předplatném není omezen.</li>
                        <li>Historický balík tréninků se zobrazí v historii, ale neopravňuje k novému odeslání.</li>
                    </ul>
                </div>
            </div>
        </div>
        <p class="small text-muted mt-3 mb-0">Přehled platných, budoucích i neuhrazených předplatných najdete v nabídce <a href="<?= BASE_URL ?>/online_subscriptions.php">Online předplatná</a>. Z detailu sportovce lze předplatné také ukončit; platební historie zůstane zachovaná.</p>
    </section>

    <section class="ot-manual-section" id="vytvoreni">
        <h2>3. Založte nový online trénink</h2>
        <p class="ot-manual-lead">V nabídce <strong>Online tréninky</strong> klikněte na <strong>Nový online trénink</strong>. Vyplňte základní zadání; samotný trénink se po vytvoření otevře v editoru, kde jej můžete dopracovat před odesláním.</p>
        <div class="row row-cols-1 row-cols-md-2 g-3">
            <div class="col"><article class="ot-manual-step"><div class="ot-manual-step__body"><h3 class="h6 fw-bold"><span class="ot-manual-step__number">1</span>Vyberte sportovce</h3><p class="mb-0">V seznamu uvidíte také informaci, zda má sportovec platné předplatné. Bez platného předplatného nelze pokračovat k vytvoření tréninku.</p></div></article></div>
            <div class="col"><article class="ot-manual-step"><div class="ot-manual-step__body"><h3 class="h6 fw-bold"><span class="ot-manual-step__number">2</span>Vyberte tréninkovou sadu</h3><p class="mb-0">Sada předvyplní cviky. Můžete použít vlastní aktivní sadu nebo dostupnou globální sadu. Popis sady napoví, pro koho je vhodná.</p></div></article></div>
            <div class="col"><article class="ot-manual-step"><div class="ot-manual-step__body"><h3 class="h6 fw-bold"><span class="ot-manual-step__number">3</span>Doplňte název a instrukce</h3><p class="mb-0">Název je volitelný; pokud jej necháte prázdný, použije se název sady. Instrukce mohou vysvětlit cíl, tempo, techniku, intenzitu nebo bezpečnostní upozornění.</p></div></article></div>
            <div class="col"><article class="ot-manual-step"><div class="ot-manual-step__body"><h3 class="h6 fw-bold"><span class="ot-manual-step__number">4</span>Přidejte média a odkazy</h3><p class="mb-0">Při založení lze připojit více fotografií či videí a internetové odkazy s volitelným popisem. Přílohy lze doplnit i později v detailu tréninku.</p></div></article></div>
        </div>
        <div class="ot-manual-note mt-3">
            <strong>Přílohy:</strong> V jednom souboru lze nahrát maximálně 50 MB; celkový limit závisí také na nastavení serveru. Pokud požadavek s více soubory neprojde, nahrajte je postupně v detailu tréninku.
        </div>
    </section>

    <section class="ot-manual-section" id="priprava">
        <h2>4. Připravte zadání a odešlete jej</h2>
        <p class="ot-manual-lead">Po založení se otevře detail ve stavu <strong>Vytvořeno</strong>. Dokud je trénink neodeslaný, můžete upravovat jeho obsah.</p>
        <div class="row g-3">
            <div class="col-lg-7">
                <ol>
                    <li class="mb-2"><strong>Instrukce:</strong> doplňte nebo upravte text pro sportovce a klikněte na <strong>Uložit instrukce</strong>. Pište konkrétně; myslete na to, že sportovec bude cvičit samostatně.</li>
                    <li class="mb-2"><strong>Cviky:</strong> sada už obsahuje své cviky. V editoru můžete přidávat další dostupné cviky, měnit jejich pořadí nebo odstranit cvik, který do plánu nepatří.</li>
                    <li class="mb-2"><strong>Série a hodnoty:</strong> upravte počet sérií (1 až 20) a zadejte předepsanou váhu, opakování nebo dobu podle typu cviku. Použijte tlačítko <strong>Uložit předepsané hodnoty</strong>, pokud není změna uložena přímo u řádku.</li>
                    <li class="mb-2"><strong>Přílohy u cviku:</strong> přidejte ukázku nebo odkaz přímo v kartě cviku. Tyto přílohy se zobrazí u konkrétního cviku. Přílohy celého tréninku hledejte v samostatné části stránky.</li>
                    <li><strong>Závěrečná kontrola:</strong> projděte pořadí cviků, série, předepsané hodnoty, instrukce, odkazy a soubory.</li>
                </ol>
            </div>
            <div class="col-lg-5">
                <figure class="ot-manual-figure">
                    <svg viewBox="0 0 680 390" role="img" aria-labelledby="ot-table-title ot-table-desc">
                        <title id="ot-table-title">Ukázka předepsaných hodnot a výsledků</title>
                        <desc id="ot-table-desc">Tabulka cviků odděluje zadání trenéra od skutečných výsledků zapsaných sportovcem.</desc>
                        <rect width="680" height="390" rx="18" fill="#f8fafc"/>
                        <rect x="22" y="20" width="636" height="350" rx="14" fill="#fff" stroke="#dbe3ec" stroke-width="2"/>
                        <path d="M22 34a14 14 0 0 1 14-14h608a14 14 0 0 1 14 14v46H22z" fill="#20252b"/>
                        <text x="46" y="59" font-family="system-ui,sans-serif" font-size="20" font-weight="700" fill="#fff">Dřep s činkou</text>
                        <text x="48" y="111" font-family="system-ui,sans-serif" font-size="14" font-weight="700" fill="#795900">ZADÁNÍ TRENÉRA</text>
                        <text x="366" y="111" font-family="system-ui,sans-serif" font-size="14" font-weight="700" fill="#075985">VÝSLEDEK SPORTOVCE</text>
                        <rect x="40" y="126" width="292" height="42" rx="6" fill="#fff4cc"/><rect x="348" y="126" width="292" height="42" rx="6" fill="#e0f2fe"/>
                        <text x="54" y="152" font-family="system-ui,sans-serif" font-size="14" fill="#334155">Série</text><text x="132" y="152" font-family="system-ui,sans-serif" font-size="14" fill="#334155">Váha</text><text x="220" y="152" font-family="system-ui,sans-serif" font-size="14" fill="#334155">Opak.</text>
                        <text x="362" y="152" font-family="system-ui,sans-serif" font-size="14" fill="#334155">Série</text><text x="440" y="152" font-family="system-ui,sans-serif" font-size="14" fill="#334155">Váha</text><text x="528" y="152" font-family="system-ui,sans-serif" font-size="14" fill="#334155">Opak.</text>
                        <g font-family="system-ui,sans-serif" font-size="15" fill="#334155">
                            <path d="M40 178H640M40 226H640M40 274H640M40 322H640" stroke="#e2e8f0" stroke-width="2"/>
                            <text x="58" y="208">1</text><text x="138" y="208">60 kg</text><text x="230" y="208">8</text><text x="370" y="208">1</text><text x="450" y="208" fill="#0369a1" font-weight="700">58 kg</text><text x="538" y="208" fill="#0369a1" font-weight="700">8</text>
                            <text x="58" y="256">2</text><text x="138" y="256">60 kg</text><text x="230" y="256">8</text><text x="370" y="256">2</text><text x="450" y="256" fill="#0369a1" font-weight="700">60 kg</text><text x="538" y="256" fill="#0369a1" font-weight="700">7</text>
                            <text x="58" y="304">3</text><text x="138" y="304">60 kg</text><text x="230" y="304">8</text><text x="370" y="304">3</text><text x="450" y="304" fill="#0369a1" font-weight="700">60 kg</text><text x="538" y="304" fill="#0369a1" font-weight="700">8</text>
                        </g>
                        <text x="46" y="348" font-family="system-ui,sans-serif" font-size="12" fill="#64748b">Žlutá = vaše předpisy · Modrá = zapsané výsledky sportovce</text>
                    </svg>
                    <figcaption>Orientace ve výsledcích: po odcvičení se vedle vašeho zadání zobrazí hodnoty sportovce.</figcaption>
                </figure>
            </div>
        </div>
        <div class="ot-manual-note ot-manual-note--red mt-3">
            <strong>Odeslání je důležitý krok:</strong> tlačítko <strong>Odeslat sportovci</strong> se aktivuje jen při platném předplatném. Po odeslání dostane sportovec upozornění v aplikaci a e-mail se seznamem cviků. Trénink už není koncept k běžné editaci; před odesláním jej proto pečlivě zkontrolujte.
        </div>
    </section>

    <section class="ot-manual-section" id="vyhodnoceni">
        <h2>5. Sledujte průběh a vyhodnoťte výkon</h2>
        <div class="ot-manual-status mb-3">
            <div><strong><span class="badge bg-secondary">Vytvořeno</span></strong>Koncept, který trenér může připravit a upravit.</div>
            <div><strong><span class="badge bg-primary">Odesláno</span></strong>Sportovec trénink obdržel, ale ještě jej nezahájil.</div>
            <div><strong><span class="badge bg-warning text-dark">Trénink zahájen – probíhá</span></strong>Sportovec začal zapisovat své výsledky.</div>
            <div><strong><span class="badge bg-success">Dokončený</span></strong>Výsledky jsou připravené ke kontrole trenérem.</div>
        </div>
        <ol>
            <li class="mb-2">Sportovec otevře trénink a stiskne <strong>Zahájit online trénink</strong>. Teprve poté může zapisovat váhu a opakování.</li>
            <li class="mb-2">Výsledky a poznámky se průběžně ukládají. Po dokončení tréninku sportovec stiskne <strong>Dokončit online trénink</strong>.</li>
            <li class="mb-2">Otevřete detail dokončeného tréninku. Ve sloupcích vedle předepsaných hodnot najdete skutečné výsledky sportovce, poznámku k sérii a celkovou poznámku.</li>
            <li class="mb-2">Prohlédněte přílohy sportovce; fotografie, videa a odkazy mohou být připojené k celému tréninku i ke konkrétním cvikům.</li>
            <li>Napište <strong>Reakci na dokončený trénink</strong> a odešlete ji. Sportovec dostane upozornění. V přehledu uvidíte stav dokončení, kontroly i reakce.</li>
        </ol>
        <div class="ot-manual-note ot-manual-note--blue">Pokud máte detail otevřený ještě před změnou stavu, obnovte stránku. Sportovec nemůže měnit výsledky po dokončení; po trenérově kontrole nebo reakci se uzavře také správa příloh.</div>
    </section>

    <section class="ot-manual-section" id="uctovani">
        <h2>6. Rozlišujte dva druhy plateb</h2>
        <p class="ot-manual-lead">Předplatné sportovce a měsíční sazba trenéra jsou oddělené věci.</p>
        <div class="table-responsive">
            <table class="table table-bordered align-middle ot-manual-table">
                <thead><tr><th>Platba</th><th>Za co je</th><th>Kde se spravuje</th></tr></thead>
                <tbody>
                    <tr><th>Online předplatné sportovce</th><td>Oprávnění konkrétního sportovce přijímat online tréninky ve zvoleném období. Počet tréninků v platném předplatném není omezen.</td><td>Detail sportovce a přehled <a href="<?= BASE_URL ?>/online_subscriptions.php">Online předplatná</a>; výzvy a jejich úhrady v Platbách.</td></tr>
                    <tr><th>Měsíční sazba trenéra</th><td>Počet online tréninků, které trenér odeslal v kalendářním měsíci. Rozhoduje datum odeslání, ne datum dokončení.</td><td>Administrace určuje výchozí nebo individuální sazbu. Výzva se po vystavení zobrazí trenérovi na stránce Platby.</td></tr>
                </tbody>
            </table>
        </div>
        <figure class="ot-manual-figure mt-3">
            <svg viewBox="0 0 920 220" role="img" aria-labelledby="ot-rates-title ot-rates-desc">
                <title id="ot-rates-title">Měsíční sazební pásma trenéra</title>
                <desc id="ot-rates-desc">Počet odeslaných online tréninků určuje sazbu, která se použije na všechny tréninky v měsíci.</desc>
                <rect width="920" height="220" fill="#f8fafc"/>
                <g font-family="system-ui,sans-serif" text-anchor="middle">
                    <g transform="translate(22 36)"><rect width="194" height="132" rx="16" fill="#fff" stroke="#f0ad00" stroke-width="2"/><text x="97" y="45" font-size="26" font-weight="800" fill="#7c5c00">1–50</text><text x="97" y="77" font-size="15" font-weight="700" fill="#20252b">tréninků za měsíc</text><text x="97" y="108" font-size="14" fill="#64748b">sazba pásma × všechny</text><text x="97" y="128" font-size="14" fill="#64748b">měsíční tréninky</text></g>
                    <g transform="translate(246 36)"><rect width="194" height="132" rx="16" fill="#fff" stroke="#cbd5e1" stroke-width="2"/><text x="97" y="45" font-size="26" font-weight="800" fill="#334155">51–150</text><text x="97" y="77" font-size="15" font-weight="700" fill="#20252b">tréninků za měsíc</text><text x="97" y="108" font-size="14" fill="#64748b">sazba pásma × všechny</text><text x="97" y="128" font-size="14" fill="#64748b">měsíční tréninky</text></g>
                    <g transform="translate(470 36)"><rect width="194" height="132" rx="16" fill="#fff" stroke="#cbd5e1" stroke-width="2"/><text x="97" y="45" font-size="26" font-weight="800" fill="#334155">151–500</text><text x="97" y="77" font-size="15" font-weight="700" fill="#20252b">tréninků za měsíc</text><text x="97" y="108" font-size="14" fill="#64748b">sazba pásma × všechny</text><text x="97" y="128" font-size="14" fill="#64748b">měsíční tréninky</text></g>
                    <g transform="translate(694 36)"><rect width="204" height="132" rx="16" fill="#fff" stroke="#cbd5e1" stroke-width="2"/><text x="102" y="45" font-size="26" font-weight="800" fill="#334155">501+</text><text x="102" y="77" font-size="15" font-weight="700" fill="#20252b">tréninků za měsíc</text><text x="102" y="108" font-size="14" fill="#64748b">sazba pásma × všechny</text><text x="102" y="128" font-size="14" fill="#64748b">měsíční tréninky</text></g>
                </g>
                <text x="460" y="198" text-anchor="middle" font-family="system-ui,sans-serif" font-size="14" fill="#475569">Například při 120 odeslaných trénincích se sazba pásma 51–150 použije na všech 120.</text>
            </svg>
            <figcaption>Konkrétní částky se mohou lišit podle výchozího nebo individuálního nastavení správce. Ověřte sazby platné pro váš účet.</figcaption>
        </figure>
        <div class="ot-manual-note mt-3"><strong>Výzva trenéra:</strong> Po vystavení ji najdete na stránce <a href="<?= BASE_URL ?>/payments.php">Platby</a>, kde je také účtenka s počtem tréninků, použitým pásmem, sazbou za trénink a celkovou částkou. Vystavená výzva uloží sazbu a počet jako záznam za daný měsíc.</div>
    </section>

    <section class="ot-manual-section ot-manual-faq" id="potize">
        <h2>7. Časté otázky a řešení potíží</h2>
        <details><summary>Nemohu vytvořit ani odeslat trénink. Co zkontrolovat?</summary><p class="mt-2 mb-0">Otevřete detail sportovce a ověřte, zda má předplatné, zda již začalo platit a zda byla placená výzva označena jako uhrazená. Pomůže také přehled Online předplatná a stránka Platby.</p></details>
        <details><summary>Sportovec má předplatné, ale začíná až později.</summary><p class="mt-2 mb-0">Budoucí předplatné povolí vytvoření a odesílání online tréninků až od data začátku. Do té doby počkejte, případně zkontrolujte správnost platnosti v detailu sportovce.</p></details>
        <details><summary>Neprošla fotografie nebo video.</summary><p class="mt-2 mb-0">Limit jednoho souboru je 50 MB, ale server může mít nižší celkový limit požadavku. Zkuste zmenšit video nebo nahrávat soubory postupně přímo v detailu online tréninku.</p></details>
        <details><summary>Trénink je odeslaný, ale sportovec jej nevidí.</summary><p class="mt-2 mb-0">Ověřte, že trénink má stav Odesláno, a sportovec ať otevře v aplikaci sekci Online tréninky. Po odeslání mu přijde upozornění; může zkontrolovat také e-mail.</p></details>
        <details><summary>Kde uvidím, zda sportovec začal nebo dokončil?</summary><p class="mt-2 mb-0">V přehledu Online tréninky otevřete sportovce. U každého tréninku uvidíte jeho stav a oddělené značky Dokončeno, Zkontrolováno a Reagováno. Prohlédnutím dokončeného detailu se trénink označí jako zkontrolovaný.</p></details>
        <details><summary>Lze znovu použít dřívější trénink?</summary><p class="mt-2 mb-0">Ano. V seznamu tréninků použijte Kopírovat. U dřívějšího odeslaného tréninku se můžete rozhodnout, zda zkopírovat i přílohy. Zkopírovaný trénink je nový koncept; zkontrolujte cílového sportovce, předpisy, přílohy a před odesláním platnost předplatného.</p></details>
        <details><summary>Jak je číslování propojené s běžnými tréninky?</summary><p class="mt-2 mb-0">Online tréninky mají oddělenou číselnou řadu pro každého sportovce a trenéra. Neovlivňují poslední výkon v klasickém tréninku s trenérem.</p></details>
    </section>

    <div class="d-flex flex-wrap gap-2 mb-5 ot-manual-back">
        <a class="btn btn-warning fw-bold" href="<?= BASE_URL ?>/online_training_new.php"><i class="fas fa-plus me-1"></i>Vytvořit online trénink</a>
        <a class="btn btn-outline-dark" href="<?= BASE_URL ?>/online_training.php">Přejít na přehled online tréninků</a>
    </div>
</main>
<?php renderFooter(); ?>
