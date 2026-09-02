<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

renderHeader('Návod pro trenéry', false, true);
?>

<style>
    .manual-sticky-nav {
        position: sticky;
        top: 72px;
        z-index: 1015;
    }

    .manual-anchor-links {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
    }

    .manual-anchor-links .btn {
        border-radius: 999px;
    }

    .manual-priority {
        font-size: .7rem;
        font-weight: 700;
        padding: .2rem .5rem;
        border-radius: 999px;
        margin-left: .45rem;
        vertical-align: middle;
    }

    .manual-priority-high {
        background: #fee2e2;
        color: #991b1b;
    }

    .manual-priority-mid {
        background: #fef3c7;
        color: #92400e;
    }

    .manual-priority-low {
        background: #dbeafe;
        color: #1e3a8a;
    }

    @media (max-width: 991.98px) {
        .manual-sticky-nav {
            top: 62px;
        }
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-book-open me-2 text-warning"></i>Návod pro trenéry</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Zpět na dashboard
        </a>
        <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Vytisknout návod
        </button>
    </div>
</div>

<div class="alert alert-info shadow-sm border-0">
    Tento návod je praktický pracovní postup krok za krokem. Každá kapitola popisuje přesně co otevřít, co vyplnit a co zkontrolovat.
</div>

<div class="alert alert-success shadow-sm border-0">
    <strong>Novinky v této verzi:</strong>
    <ul class="mb-0 mt-2">
        <li>v detailu sportovce je dostupná sekce <strong>Strava</strong> s denními záznamy jídel, aktivit a pitného režimu,</li>
        <li>pitný režim je nově veden <strong>průběžně po dávkách</strong> a obsahuje typ nápoje (voda, sladký nápoj, káva, čaj, protein, pivo, tvrdý alkohol, vlastní),</li>
        <li>nová práce se <strong>zdravotním dotazníkem sportovce</strong> v dashboardu, detailu sportovce i během tréninku,</li>
        <li>moduly <strong>Events</strong> a <strong>MyCoach</strong> jsou řízené per-účtem (odemčeno/uzamčeno),</li>
        <li><strong>Apple CalDAV push</strong> + stažení Apple profilu <strong>.mobileconfig</strong>,</li>
        <li>u sad je dostupná <strong>archivace</strong> (aktivní/archiv/vše),</li>
        <li>videa: nahrávání až <strong>1 GB na soubor</strong>, složky a sdílení po sportovcích,</li>
        <li>Events obsah je centrálně spravovaný v administraci (eventy, záložky, média, formuláře, CSV importy).</li>
    </ul>
</div>

<div class="card border-0 shadow-sm mb-4 manual-sticky-nav">
    <div class="card-body py-2">
        <div class="manual-anchor-links">
            <a href="#coach-start" class="btn btn-outline-dark btn-sm">Start</a>
            <a href="#coach-athletes" class="btn btn-outline-dark btn-sm">Sportovci</a>
            <a href="#coach-health" class="btn btn-outline-dark btn-sm">Z. dotazník</a>
            <a href="#coach-training" class="btn btn-outline-dark btn-sm">Trénink</a>
            <a href="#coach-calendar" class="btn btn-outline-dark btn-sm">Kalendář</a>
            <a href="#coach-food-diary" class="btn btn-outline-dark btn-sm">Strava</a>
            <a href="#coach-payments" class="btn btn-outline-dark btn-sm">Platby</a>
            <a href="#coach-files" class="btn btn-outline-dark btn-sm">Soubory</a>
            <a href="#coach-events" class="btn btn-outline-dark btn-sm">Events</a>
            <a href="#coach-mycoach" class="btn btn-outline-dark btn-sm">MyCoach</a>
            <a href="#coach-messages" class="btn btn-outline-dark btn-sm">Zprávy</a>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-light fw-semibold"><i class="fas fa-compass me-2 text-warning"></i>Rychlá orientace: kde co najdu</div>
    <div class="card-body">
        <div class="row g-2 small">
            <div class="col-12 col-md-6"><i class="fas fa-users me-2 text-muted"></i><strong>Sportovci:</strong> detail, historie, akce účtu</div>
            <div class="col-12 col-md-6"><i class="fas fa-heart-pulse me-2 text-muted"></i><strong>Z. dotazník:</strong> zdravotní stav sportovce, upozornění a nové změny</div>
            <div class="col-12 col-md-6"><i class="fas fa-list me-2 text-muted"></i><strong>Cviky:</strong> vlastní knihovna cviků</div>
            <div class="col-12 col-md-6"><i class="fas fa-layer-group me-2 text-muted"></i><strong>Sady:</strong> aktivní/archiv/vše, editace struktury</div>
            <div class="col-12 col-md-6"><i class="fas fa-calendar-alt me-2 text-muted"></i><strong>Kalendář:</strong> plánování, schvalování, Apple sync</div>
            <div class="col-12 col-md-6"><i class="fas fa-wallet me-2 text-muted"></i><strong>Platby:</strong> výzvy, stav úhrad, účtenky</div>
            <div class="col-12 col-md-6"><i class="fas fa-utensils me-2 text-muted"></i><strong>Jídelníčky:</strong> tvorba a přiřazení plánů</div>
            <div class="col-12 col-md-6"><i class="fas fa-bowl-food me-2 text-muted"></i><strong>Strava sportovce:</strong> reálný příjem jídla, pitný režim po dávkách, poznámky trenéra</div>
            <div class="col-12 col-md-6"><i class="fas fa-folder-open me-2 text-muted"></i><strong>Soubory sportovce:</strong> dokumenty, které vám sportovec zpřístupnil</div>
            <div class="col-12 col-md-6"><i class="fas fa-video me-2 text-muted"></i><strong>Videa:</strong> upload, složky, sdílení sportovcům</div>
            <div class="col-12 col-md-6"><i class="fas fa-flag-checkered me-2 text-muted"></i><strong>Events:</strong> práce s publikovanými eventy</div>
            <div class="col-12 col-md-6"><i class="fas fa-brain me-2 text-muted"></i><strong>MyCoach:</strong> cíle, monitoring, dotazníky</div>
            <div class="col-12 col-md-6"><i class="fas fa-comments me-2 text-muted"></i><strong>Zprávy:</strong> komunikace se sportovci</div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-start">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-list-check me-2"></i>Jak začít od nuly (doporučené pořadí)
        <span class="manual-priority manual-priority-high">Doporučeno ihned</span>
    </div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Otevřete Profil a zkontrolujte vlastní údaje a heslo.</li>
            <li>V Cvicích připravte nebo upravte cviky, které budete používat.</li>
            <li>V Sadách vytvořte minimálně 1 sadu pro začínajícího sportovce.</li>
            <li>V Sportovci založte nového sportovce.</li>
            <li>Po založení zkontrolujte, zda sportovec vyplnil zdravotní dotazník.</li>
            <li>V detailu sportovce spusťte první trénink nebo vložte minulý trénink.</li>
            <li>V Kalendáři zapište příští termín tréninku.</li>
            <li>V Platbách založte první platbu.</li>
            <li>V Jídelníčcích vytvořte plán a přiřaďte ho sportovci.</li>
            <li>Ve Zprávách pošlete sportovci úvodní instrukce.</li>
            <li>Pokud máte odemčené moduly, nastavte také Events a MyCoach workflow.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-athletes">
    <div class="card-header fw-semibold"><i class="fas fa-users me-2 text-warning"></i>1) Sportovci: založení, úprava, detail <span class="manual-priority manual-priority-high">Denně</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Jak založit nového sportovce</h6>
        <ol>
            <li>V horním menu otevřete Sportovci.</li>
            <li>Klikněte na Přidat sportovce.</li>
            <li>Vyplňte povinná pole (jméno, příjmení, přihlašovací údaje a další zobrazená pole) a zkontrolujte, že sportovec patří pod správného trenéra.</li>
            <li>Zkontrolujte, že nejsou překlepy v e-mailu a uživatelském jménu.</li>
            <li>Klikněte na Uložit.</li>
            <li>Po uložení otevřete detail sportovce a ověřte, že se profil vytvořil správně.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak upravit údaje sportovce</h6>
        <ol>
            <li>V seznamu Sportovci klikněte na konkrétního sportovce.</li>
            <li>Otevřete Upravit sportovce.</li>
            <li>Změňte požadovaná pole.</li>
            <li>Uložte změny a vraťte se do detailu.</li>
            <li>Ověřte, že se změny propsaly.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-health">
    <div class="card-header fw-semibold"><i class="fas fa-heart-pulse me-2 text-warning"></i>2) Zdravotní dotazník sportovce <span class="manual-priority manual-priority-high">Kontrolovat před tréninkem</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Kde zdravotní stav uvidíte</h6>
        <ol>
            <li>Na dashboardu u sportovce uvidíte stav dotazníku: nevyplněn, omezení nebo vše v pořádku.</li>
            <li>V detailu sportovce je samostatná karta Zdravotní dotazník sportovce.</li>
            <li>Při živém i párovém tréninku se zdravotní stav zobrazuje v hlavičce sportovce.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak s dotazníkem pracovat v detailu sportovce</h6>
        <ol>
            <li>Otevřete detail sportovce.</li>
            <li>Najděte kartu Zdravotní dotazník sportovce.</li>
            <li>Zkontrolujte datum posledního vyplnění a počet upozornění.</li>
            <li>Pokud jsou aktivní upozornění, projděte jejich text a upravte trénink podle omezení.</li>
            <li>Pro archivaci nebo předání použijte Tisk dotazníku.</li>
        </ol>

        <h6 class="fw-bold mt-3">Změny zdravotního stavu od sportovce</h6>
        <ol>
            <li>Sportovec může průběžně hlásit změny: omezení, zranění, léky, alergie nebo jiný stav.</li>
            <li>Tyto změny uvidíte v detailu sportovce v seznamu hlášení.</li>
            <li>Po zpracování můžete změny označit jako přečtené.</li>
            <li>Nové změny berte jako prioritu před dalším plánováním zátěže.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-dumbbell me-2 text-warning"></i>3) Cviky: jak vytvořit a používat</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak vytvořit nový cvik</h6>
        <ol>
            <li>Otevřete Cviky.</li>
            <li>Klikněte na Přidat cvik.</li>
            <li>Zadejte název cviku.</li>
            <li>Volitelně vyberte fotografii a doplňte kategorii/svalové zařazení.</li>
            <li>Uložte cvik.</li>
            <li>Vyhledejte nový cvik v seznamu a zkontrolujte, že je dostupný.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak upravit nebo smazat cvik</h6>
        <ol>
            <li>V seznamu cviků najděte položku.</li>
            <li>Klikněte na Upravit nebo Smazat.</li>
            <li>Pokud mažete, potvrďte dialog.</li>
            <li>Po úpravě ověřte, že se název/fotka správně zobrazuje i v sadách.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-layer-group me-2 text-warning"></i>4) Sady: kompletní postup vytvoření</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak vytvořit novou sadu krok za krokem</h6>
        <ol>
            <li>V menu otevřete Sady.</li>
            <li>Klikněte na Přidat sadu.</li>
            <li>Zadejte název sady (např. Začátečník A, Redukce, Síla).</li>
            <li>Postupně přidávejte cviky do sady.</li>
            <li>Nastavte pořadí cviků tak, jak mají jít v tréninku za sebou.</li>
            <li>Uložte sadu.</li>
            <li>Otevřete detail sady a proveďte rychlou kontrolu pořadí a obsahu.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak upravit existující sadu</h6>
        <ol>
            <li>V Sadách otevřete konkrétní sadu.</li>
            <li>Klikněte na Upravit.</li>
            <li>Přidejte nebo odeberte cvik, případně změňte pořadí.</li>
            <li>Uložte změny.</li>
            <li>Zkontrolujte, že při spuštění tréninku se načítá nová verze sady.</li>
        </ol>

        <h6 class="fw-bold mt-3">Archivace sad (nově)</h6>
        <ol>
            <li>V Sadách použijte přepínač Aktivní / Archiv / Vše.</li>
            <li>Sady, které nechcete používat pro nové tréninky, archivujte místo mazání.</li>
            <li>Archivovanou sadu lze kdykoliv obnovit mezi aktivní.</li>
            <li>Flexibilní sadu nelze archivovat.</li>
            <li>Sady použité v historii tréninků nemažte, doporučený postup je archivace.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-training">
    <div class="card-header fw-semibold"><i class="fas fa-stopwatch me-2 text-warning"></i>5) Trénink: živě, ručně, párově <span class="manual-priority manual-priority-high">Klíčové</span></div>
    <div class="card-body">
        <h6 class="fw-bold">A. Jak spustit živý trénink</h6>
        <ol>
            <li>Otevřete Sportovci a přejděte do detailu sportovce.</li>
            <li>Klikněte na Spustit trénink.</li>
            <li>Vyberte sadu, kterou chcete jet.</li>
            <li>Před zapisováním výkonu zkontrolujte stav zdravotního dotazníku v hlavičce.</li>
            <li>V hlavičce tréninku zkontrolujte poslední hmotnost sportovce (kg + datum vážení).</li>
            <li>U každého cviku vyplňujte série (opakování, váha nebo čas podle typu cviku).</li>
            <li>Průběžně kontrolujte, že se data ukládají do správného cviku/série.</li>
            <li>Na konci klikněte na Dokončit trénink.</li>
            <li>Zkontrolujte detail dokončeného tréninku.</li>
        </ol>

        <h6 class="fw-bold mt-3">B. Jak vložit minulý trénink ručně</h6>
        <ol>
            <li>V detailu sportovce klikněte na Přidat minulý trénink.</li>
            <li>Vyberte sadu.</li>
            <li>Zadejte datum tréninku, případně místo a poznámku.</li>
            <li>Vyplňte série stejně jako u živého tréninku.</li>
            <li>Klikněte na Uložit.</li>
            <li>Ověřte záznam v historii tréninků.</li>
        </ol>

        <h6 class="fw-bold mt-3">C. Jak vést párový trénink</h6>
        <ol>
            <li>Spusťte párový trénink z příslušné akce v aplikaci.</li>
            <li>Vyberte oba sportovce.</li>
            <li>Zvolte sadu pro párovou jednotku.</li>
            <li>U obou sportovců zkontrolujte stav zdravotního dotazníku a případná omezení.</li>
            <li>U obou sportovců v hlavičce ověřte poslední hmotnost (kg + datum).</li>
            <li>Zapisujte průběh tréninku a dokončete párovou session.</li>
            <li>Zkontrolujte výsledek v detailu obou sportovců.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-calendar">
    <div class="card-header fw-semibold"><i class="fas fa-calendar-alt me-2 text-warning"></i>6) Kalendář: nové termíny a změny <span class="manual-priority manual-priority-high">Denně</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Jak vytvořit nový termín</h6>
        <ol>
            <li>Otevřete Kalendář.</li>
            <li>Klikněte na Nový termín.</li>
            <li>Zvolte sportovce (nebo dva sportovce pro párový trénink).</li>
            <li>Vyplňte datum, čas, místo a typ události. U nových akcí můžete použít i <strong>Skupinový trénink / skupinovou lekci</strong>.</li>
            <li>Uložte termín.</li>
            <li>Zkontrolujte, že se termín zobrazil v kalendáři správně.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak upravit nebo zrušit termín</h6>
        <ol>
            <li>Klikněte na událost v kalendáři.</li>
            <li>Zvolte Upravit nebo Zrušit.</li>
            <li>Při úpravě změňte potřebná pole a uložte.</li>
            <li>Po zrušení ověřte, že událost zmizela nebo je označená jako zrušená.</li>
            <li>V měsíčním přehledu si průběžně hlídejte také požadavky na schválení, abyste je nemuseli dohledávat v detailu každého dne.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak propojit kalendář s Apple Kalendářem</h6>
        <ol>
            <li>Otevřete Kalendář.</li>
            <li>Přepněte se do záložky Apple Kalendář.</li>
            <li>Zapněte volbu Zapnout Apple CalDAV push synchronizaci.</li>
            <li>Vyplňte Apple ID, app-specific heslo a volitelně CalDAV URL.</li>
            <li>Klikněte na Uložit Apple CalDAV nebo na Vygenerovat URL TrainerApp.</li>
            <li>Volitelně stáhněte Apple profil (.mobileconfig) a nainstalujte jej v zařízení.</li>
            <li>Pokud migrujete historii, použijte Natáhnout dřívější události.</li>
            <li>Pamatujte, že se synchronizují události; uzamčené časy se neposílají.</li>
            <li>Při duplicitách po instalaci profilu nechte stejný kalendář zapnutý jen v jedné sekci (TrainerApp nebo iCloud).</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak propojit kalendář s Google Kalendářem</h6>
        <ol>
            <li>V aktuální verzi je záložka Google Kalendář v UI označená jako Ve vývoji.</li>
            <li>Jakmile je modul aktivní, nejdřív propojte Google účet trenéra a potom zapněte sync.</li>
            <li>Volitelně můžete použít i ICS odkaz pro odběr v Google Kalendáři.</li>
            <li>Počítejte s tím, že Google změny načítá periodicky a ne vždy okamžitě.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-payments">
    <div class="card-header fw-semibold"><i class="fas fa-wallet me-2 text-warning"></i>7) Platby: zápis a kontrola <span class="manual-priority manual-priority-mid">Měsíčně</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Jak připravit a otevřít výzvu k úhradě</h6>
        <ol>
            <li>Otevřete Platby.</li>
            <li>Zvolte období (měsíc), které chcete uzavřít/řešit.</li>
            <li>Zkontrolujte přehled tréninků a vypočtené částky pro sportovce.</li>
            <li>Výzvu otevřete globálně nebo po sportovcích (released).</li>
            <li>U sportovce lze odeslat e-mail s výzvou a QR kódem.</li>
            <li>Ověřte, že sportovec vidí výzvu v sekci Platby.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak pracovat s potvrzením platby</h6>
        <ol>
            <li>Po přijetí platby označte platbu jako Uhrazeno.</li>
            <li>V seznamu plateb otevřete konkrétní záznam.</li>
            <li>Klikněte na detail/účtenku.</li>
            <li>Použijte tisk nebo sdílení podle potřeby.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-utensils me-2 text-warning"></i>8) Jídelníčky: vytvoření a přiřazení</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak vytvořit nový jídelníček</h6>
        <ol>
            <li>Otevřete Jídelníčky.</li>
            <li>Klikněte na Vytvořit jídelníček.</li>
            <li>Zadejte název plánu.</li>
            <li>Po dnech přidávejte jídla, gramáže a poznámky.</li>
            <li>Uložte plán.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak přiřadit jídelníček sportovci</h6>
        <ol>
            <li>V detailu jídelníčku zvolte Přiřadit sportovci.</li>
            <li>Vyberte konkrétního sportovce.</li>
            <li>Potvrďte přiřazení.</li>
            <li>Zkontrolujte, že sportovec plán vidí ve své sekci Jídelníčky.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak upravit existující jídelníček</h6>
        <ol>
            <li>Otevřete jídelníček, proveďte změny a uložte.</li>
            <li>Po uložení ověřte, že sportovec dostal informaci o aktualizaci.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-food-diary">
    <div class="card-header fw-semibold"><i class="fas fa-bowl-food me-2 text-warning"></i>8b) Strava sportovce: co kontrolovat denně</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak otevřít Stravu konkrétního sportovce</h6>
        <ol>
            <li>V dashboardu nebo detailu sportovce klikněte na tlačítko Strava.</li>
            <li>Vyberte den v kalendáři.</li>
            <li>Zkontrolujte, co sportovec skutečně jedl a co označil jako vynechané.</li>
            <li>Podle potřeby napište poznámku k jednotlivému jídlu nebo k celému dni.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak číst pitný režim</h6>
        <ol>
            <li>V denním detailu sledujte součet vypitého množství za den.</li>
            <li>V seznamu dávek ověřte skladbu nápojů: voda, sladké nápoje, káva, čaj, protein, pivo, tvrdý alkohol a vlastní nápoje.</li>
            <li>U položky Vlastní je vidět název, který sportovec doplnil.</li>
            <li>Pokud je pití nedostatečné nebo nevhodně složené, zapište doporučení do poznámky k dni.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-images me-2 text-warning"></i>9) Galerie: složky a nahrávání</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak přidat složku a soubory</h6>
        <ol>
            <li>Otevřete Galerie.</li>
            <li>Klikněte na Nová složka a zadejte název.</li>
            <li>Otevřete složku a klikněte na Nahrát.</li>
            <li>Vyberte fotky/soubory a potvrďte upload.</li>
            <li>Po nahrání zkontrolujte náhledy a názvy.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-files">
    <div class="card-header fw-semibold"><i class="fas fa-folder-open me-2 text-primary"></i>10) Soubory sportovce: kontrola sdílených dokumentů</div>
    <div class="card-body">
        <ol>
            <li>V detailu konkrétního sportovce klikněte na tlačítko Soubory.</li>
            <li>Červený badge na tlačítku ukazuje počet nově zpřístupněných a dosud neprohlédnutých souborů.</li>
            <li>Po otevření přehledu se nové soubory označí jako prohlédnuté.</li>
            <li>Dokumenty jsou řazené podle typu; vícesouborová sada má společný název a jednotlivé soubory se stahují samostatně.</li>
            <li>Po nahrání sdíleného dokumentu přijde systémová zpráva i e-mail s názvem sportovce a typem dokumentu.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-events">
    <div class="card-header fw-semibold"><i class="fas fa-flag-checkered me-2 text-warning"></i>11) Events: práce s eventy <span class="manual-priority manual-priority-low">Volitelné</span></div>
    <div class="card-body">
        <ol>
            <li>Na dashboardu otevřete dlaždici Events (pokud je účet odemčený).</li>
            <li>Vyberte event kartu a přejděte do detailu eventu.</li>
            <li>Používejte záložky eventu podle scénáře (obsah, pravidla, formuláře, harmonogram).</li>
            <li>V části Nadcházející položky sledujte živý stav položek (zbývá / probíhá / proběhlo).</li>
            <li>Obsah eventů (karty, záložky, média, importy) upravuje administrátor v admin/events.php.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-mycoach">
    <div class="card-header fw-semibold"><i class="fas fa-brain me-2 text-warning"></i>12) MyCoach: cíle a monitoring <span class="manual-priority manual-priority-mid">Průběžně</span></div>
    <div class="card-body">
        <ol>
            <li>Na dashboardu otevřete dlaždici MyCoach (pokud je účet odemčený).</li>
            <li>Nastavte jeden nebo více aktivních cílů.</li>
            <li>Určete primární cíl (hvězdička), který řídí hlavní doporučení.</li>
            <li>Využijte stránky Dotazník, Grafy a Sportovci pro denní práci.</li>
            <li>Sledujte badge MyCoach plán běží, readiness a stav sportovců.</li>
            <li>Cíle lze prodloužit nebo ukončit; ukončené přecházejí do archivu.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-video me-2 text-warning"></i>13) Videa: nahrání, třídění a sdílení</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak pracovat s videosekcí</h6>
        <ol>
            <li>V menu otevřete Videa.</li>
            <li>V části Moje videa vidíte všechny své nahrávky.</li>
            <li>V části Moje složky si můžete vytvářet vlastní tematické složky, přejmenovávat je a mazat.</li>
            <li>V části Složky sportovců otevřete konkrétního sportovce a spravujete, co od vás uvidí.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak nahrát video a nastavit viditelnost</h6>
        <ol>
            <li>Klikněte na Nahrát video.</li>
            <li>Vyberte jedno nebo více videí (maximálně 1 GB na video).</li>
            <li>Zvolte viditelnost: Soukromé / Všichni sportovci / Vybraní sportovci.</li>
            <li>Při volbě Vybraní sportovci označte konkrétní příjemce.</li>
            <li>Volitelně doplňte popis videa a zvolte vlastní složku.</li>
            <li>Po uložení ověřte, že se video zobrazilo ve správné složce.</li>
            <li>Pokud upload padá, zkontrolujte server limity upload_max_filesize a post_max_size.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak upravit sdílení nebo odebrat video sportovci</h6>
        <ol>
            <li>Otevřete video z Moje videa nebo ze složky sportovce.</li>
            <li>V detailu videa upravte popis, složku a viditelnost.</li>
            <li>Ve složce konkrétního sportovce použijte tlačítko Vypnout, pokud chcete sdílení odebrat jen jemu.</li>
            <li>Při úplném smazání videa se záznam odstraní ze všech složek.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-messages">
    <div class="card-header fw-semibold"><i class="fas fa-comments me-2 text-warning"></i>14) Zprávy: komunikace se sportovci <span class="manual-priority manual-priority-high">Reakce do 24 h</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Jak odeslat novou zprávu</h6>
        <ol>
            <li>Otevřete Zprávy.</li>
            <li>Klikněte na Nová zpráva.</li>
            <li>Vyberte příjemce.</li>
            <li>Napište předmět a text zprávy.</li>
            <li>Klikněte na Odeslat.</li>
            <li>Ověřte ve vláknu, že zpráva odešla.</li>
        </ol>

        <h6 class="fw-bold mt-3">Automatické systémové zprávy</h6>
        <ol>
            <li>Notifikace k narozeninám sportovců chodí automaticky e-mailem i do Zpráv.</li>
            <li>Kalendářové požadavky sportovců sledujte v přehledech a následně potvrzujte/odmítejte.</li>
            <li>Po sdílení nového dokumentu sportovcem přijde zpráva s jeho jménem a typem dokumentu; stejná notifikace se odešle e-mailem.</li>
            <li>Změny hmotnosti, které si sportovec zapisuje v profilu, již nezatěžují inbox samostatnými zprávami.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-chart-line me-2 text-warning"></i>15) Grafy a reporty: jak vyhodnocovat</div>
    <div class="card-body">
        <ol>
            <li>Otevřete Grafy nebo Reporty.</li>
            <li>Vyberte sportovce a období.</li>
            <li>Vyhodnoťte trend (váha, výkonnost, frekvence tréninků).</li>
            <li>Na základě výsledků upravte sadu, plán tréninku nebo jídelníček.</li>
            <li>Po změně informujte sportovce přes Zprávy.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-user-cog me-2 text-warning"></i>15) Profil a heslo</div>
    <div class="card-body">
        <ol>
            <li>Otevřete Profil.</li>
            <li>Upravte osobní údaje a uložte.</li>
            <li>Pro změnu hesla otevřete Změna hesla.</li>
            <li>Zadejte aktuální heslo, nové heslo a potvrzení.</li>
            <li>Uložte a znovu se přihlaste, pokud je to vyžadováno.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coach-routine">
    <div class="card-header fw-semibold"><i class="fas fa-calendar-week me-2 text-warning"></i>Denní workflow trenéra</div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Ráno: otevřete Kalendář a ověřte dnešní termíny.</li>
            <li>Před tréninkem: zkontrolujte zdravotní stav sportovce a nové změny v dotazníku.</li>
            <li>Průběžně: ve Stravě kontrolujte reálný příjem jídla a pitný režim po dávkách.</li>
            <li>Po každém tréninku: zkontrolujte uložení výsledků.</li>
            <li>Odpoledne: projděte Zprávy a odpovězte na dotazy.</li>
            <li>Průběžně: kontrolujte platební výzvy ve vybraném období a označujte uhrazené položky.</li>
            <li>Večer: zkontrolujte Platby, případně doplňte chybějící záznamy.</li>
            <li>Pokud používáte MyCoach: zkontrolujte readiness a běžící plány sportovců.</li>
            <li>1x týdně: vyhodnoťte Grafy a upravte sady/jídelníčky.</li>
        </ol>
    </div>
</div>

<div class="alert alert-warning border-0 shadow-sm mb-4">
    Pokud narazíte na problém v aplikaci, použijte plovoucí tlačítko podpory v pravém dolním rohu. Přiložte screenshot a přesný popis kroku, kde problém vznikl.
</div>

<?php renderFooter(); ?>
