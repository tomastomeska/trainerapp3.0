<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

renderAthleteHeader('Návod pro sportovce', false, true);
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
    <h2 class="mb-0"><i class="fas fa-book-open me-2 text-warning"></i>Návod pro sportovce</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/athlete_dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Domů
        </a>
        <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Vytisknout návod
        </button>
    </div>
</div>

<div class="alert alert-info shadow-sm border-0">
    Tento návod je praktický postup pro každodenní práci sportovce v aplikaci, krok za krokem.
</div>

<div class="alert alert-success shadow-sm border-0">
    <strong>Novinky v této verzi:</strong>
    <ul class="mb-0 mt-2">
        <li>modul <strong>Strava</strong> pro reálný denní záznam jídel a aktivit včetně fotek,</li>
        <li>ve Stravě nový <strong>pitný režim</strong> průběžně po dávkách (voda, sladký nápoj, káva, čaj, protein, pivo, tvrdý alkohol, vlastní nápoj),</li>
        <li>nová dlaždice <strong>Z. dotazník</strong> na dashboardu a samostatná stránka zdravotního dotazníku,</li>
        <li>dlaždice <strong>Events</strong> a <strong>MyCoach</strong> na dashboardu (podle odemčení účtu),</li>
        <li>dlaždice <strong>Soubory</strong> pro vlastní dokumenty s volitelným zpřístupněním trenérovi,</li>
        <li><strong>Apple Kalendář (CalDAV push)</strong> s možností stáhnout <strong>.mobileconfig</strong> profil,</li>
        <li>v kalendáři lepší práce s termíny: týdenní přehled, měsíční seznam, přehledné stavy schválení,</li>
        <li>sekce <strong>Videa</strong> s bezpečným interním přehráváním a automatickou obnovou seznamu.</li>
    </ul>
</div>

<div class="card border-0 shadow-sm mb-4 manual-sticky-nav">
    <div class="card-body py-2">
        <div class="manual-anchor-links">
            <a href="#athlete-start" class="btn btn-outline-dark btn-sm">Start</a>
            <a href="#athlete-profile" class="btn btn-outline-dark btn-sm">Profil</a>
            <a href="#athlete-health" class="btn btn-outline-dark btn-sm">Z. dotazník</a>
            <a href="#athlete-calendar" class="btn btn-outline-dark btn-sm">Kalendář</a>
            <a href="#athlete-online-training" class="btn btn-outline-dark btn-sm">Online tréninky</a>
            <a href="#athlete-food" class="btn btn-outline-dark btn-sm">Jídelníčky</a>
            <a href="#athlete-food-diary" class="btn btn-outline-dark btn-sm">Strava</a>
            <a href="#athlete-payments" class="btn btn-outline-dark btn-sm">Platby</a>
            <a href="#athlete-files" class="btn btn-outline-dark btn-sm">Soubory</a>
            <a href="#athlete-events" class="btn btn-outline-dark btn-sm">Events</a>
            <a href="#athlete-mycoach" class="btn btn-outline-dark btn-sm">MyCoach</a>
            <a href="#athlete-messages" class="btn btn-outline-dark btn-sm">Zprávy</a>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-light fw-semibold"><i class="fas fa-compass me-2 text-warning"></i>Rychlá orientace: kde co najdu</div>
    <div class="card-body">
        <div class="row g-2 small">
            <div class="col-12 col-md-6"><i class="fas fa-calendar-alt me-2 text-muted"></i><strong>Kalendář:</strong> termíny, stavy schválení, změny termínů</div>
            <div class="col-12 col-md-6"><i class="fas fa-laptop me-2 text-muted"></i><strong>Online tréninky:</strong> samostatně odcvičené tréninky od trenéra</div>
            <div class="col-12 col-md-6"><i class="fas fa-heart-pulse me-2 text-muted"></i><strong>Z. dotazník:</strong> vstupní zdravotní informace a průběžné hlášení změn</div>
            <div class="col-12 col-md-6"><i class="fas fa-wallet me-2 text-muted"></i><strong>Platby:</strong> přehled období, stavy plateb, QR platby</div>
            <div class="col-12 col-md-6"><i class="fas fa-utensils me-2 text-muted"></i><strong>Jídelníčky:</strong> aktuální plán od trenéra</div>
            <div class="col-12 col-md-6"><i class="fas fa-bowl-food me-2 text-muted"></i><strong>Strava:</strong> reálně snědené jídlo, pitný režim, poznámky a fotky</div>
            <div class="col-12 col-md-6"><i class="fas fa-chart-line me-2 text-muted"></i><strong>Grafy:</strong> trend progresu a hmotnosti</div>
            <div class="col-12 col-md-6"><i class="fas fa-folder-open me-2 text-muted"></i><strong>Soubory:</strong> vlastní dokumenty a jejich sdílení s trenérem</div>
            <div class="col-12 col-md-6"><i class="fas fa-video me-2 text-muted"></i><strong>Videa:</strong> sdílená videa od trenéra</div>
            <div class="col-12 col-md-6"><i class="fas fa-flag-checkered me-2 text-muted"></i><strong>Events:</strong> event karty, záložky, formuláře</div>
            <div class="col-12 col-md-6"><i class="fas fa-brain me-2 text-muted"></i><strong>MyCoach:</strong> cíle, dotazník, denní doporučení</div>
            <div class="col-12 col-md-6"><i class="fas fa-envelope me-2 text-muted"></i><strong>Zprávy:</strong> komunikace s trenérem</div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-start">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-rocket me-2"></i>Jak začít po prvním přihlášení
        <span class="manual-priority manual-priority-high">Doporučeno ihned</span>
    </div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Přihlaste se přístupem od trenéra.</li>
            <li>Otevřete Profil a zkontrolujte osobní údaje.</li>
            <li>Pokud se zobrazí výzva, přejděte do Zdravotního dotazníku a vyplňte ho.</li>
            <li>Volitelně doplňte aktuální hmotnost do sekce hmotnosti v profilu.</li>
            <li>V Kalendáři ověřte nejbližší termíny tréninku.</li>
            <li>V Jídelníčcích projděte aktuálně přiřazený plán.</li>
            <li>V Platbách zkontrolujte přehled období a zbývajících tréninků.</li>
            <li>Ve Zprávách si přečtěte nové instrukce od trenéra.</li>
            <li>Pokud máte odemčené moduly, otevřete také Events a MyCoach.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-profile">
    <div class="card-header fw-semibold"><i class="fas fa-user me-2 text-warning"></i>1) Profil: kontrola údajů a změna hesla <span class="manual-priority manual-priority-high">Důležité</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Jak zkontrolovat a upravit profil</h6>
        <ol>
            <li>V menu otevřete Profil.</li>
            <li>Zkontrolujte osobní údaje, e-mail a další zobrazené informace.</li>
            <li>Pokud je potřeba, klikněte na Upravit, změny zapište a uložte.</li>
            <li>Po uložení ověřte, že se nové údaje zobrazují správně.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak změnit heslo</h6>
        <ol>
            <li>Otevřete Změna hesla.</li>
            <li>Zadejte staré heslo.</li>
            <li>Zadejte nové heslo a jeho potvrzení.</li>
            <li>Klikněte na Uložit.</li>
            <li>Při příštím přihlášení použijte nové heslo.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak zapisovat hmotnost</h6>
        <ol>
            <li>V Profilu najděte sekci Zaznamenat aktuální hmotnost.</li>
            <li>Zadejte datum vážení a hodnotu v kg.</li>
            <li>Klikněte na Uložit.</li>
            <li>V historii hmotnosti můžete záznam kdykoliv upravit nebo smazat.</li>
            <li>Zadaná hmotnost se ukládá do vaší historie a trenér ji uvidí při vedení tréninku.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak propojit moje tréninky s Apple Kalendářem (doporučeno)</h6>
        <ol>
            <li>V menu otevřete Kalendář a přejděte na záložku Apple Kalendář (Beta).</li>
            <li>Zapněte volbu Synchronizovat moje tréninky do Apple Kalendáře.</li>
            <li>Vyplňte Apple ID a app-specific heslo.</li>
            <li>Klikněte na Uložit CalDAV sync, případně na Vygenerovat URL TrainerApp.</li>
            <li>Volitelně klikněte na Stáhnout Apple profil (.mobileconfig) a profil nainstalujte v iPhonu/iPadu.</li>
            <li>Pokud migrujete historii, použijte tlačítko Natáhnout dřívější události.</li>
            <li>Při duplicitách po instalaci profilu ponechte stejný kalendář zapnutý jen v jedné sekci (TrainerApp nebo iCloud).</li>
            <li>Neschválené termíny se v kalendáři zobrazí jako Ke schválení a po schválení se automaticky přepnou.</li>
        </ol>

        <h6 class="fw-bold mt-3">Google Kalendář</h6>
        <ol>
            <li>V aktuální verzi je záložka Google Kalendář u sportovce označená jako Ve vývoji.</li>
            <li>Pokud bude aktivovaná, postup bude stejný jako dříve přes soukromý ICS odkaz.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-health">
    <div class="card-header fw-semibold"><i class="fas fa-heart-pulse me-2 text-warning"></i>2) Zdravotní dotazník: první vyplnění a hlášení změn <span class="manual-priority manual-priority-high">Vyplnit před tréninkem</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Kdy dotazník vyplnit</h6>
        <ol>
            <li>Na dashboardu sledujte dlaždici <strong>Z. dotazník</strong>.</li>
            <li>Pokud je červená nebo se po přihlášení otevře výzva, je potřeba dotazník vyplnit nebo aktualizovat.</li>
            <li>Po přidání nových otázek vás aplikace znovu vyzve k aktualizaci.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak vyplnit vstupní dotazník</h6>
        <ol>
            <li>Otevřete dlaždici <strong>Z. dotazník</strong> na dashboardu.</li>
            <li>Projděte všechny kroky formuláře postupně.</li>
            <li>Vyplňte pravdivě zdravotní omezení, onemocnění, alergie, léky a další relevantní informace.</li>
            <li>Na závěr dotazník odešlete.</li>
            <li>Po uložení je trenér automaticky informován.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak nahlásit změnu zdravotního stavu</h6>
        <ol>
            <li>Na stránce dotazníku přejděte do části hlášení změn zdravotního stavu.</li>
            <li>Vyberte typ změny: omezení, zranění, léky, alergie, stav nebo jiné.</li>
            <li>Doplňte detail, případně datum účinnosti a důležitost.</li>
            <li>Změnu uložte; trenér dostane informaci automaticky.</li>
            <li>Odeslané hlášení můžete později upravit nebo smazat.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-online-training">
    <div class="card-header fw-semibold"><i class="fas fa-laptop me-2 text-warning"></i>Online tréninky</div>
    <div class="card-body">
        <ol>
            <li>Na dashboardu nebo v Online trénincích otevřete nový trénink od trenéra.</li>
            <li>Nejdříve klikněte na Zahájit online trénink. Teprve potom můžete zapisovat váhu a opakování.</li>
            <li>U každé série zapisujte skutečně odcvičenou váhu a počet opakování. Hodnoty se průběžně ukládají.</li>
            <li>Prohlédněte si instrukce, fotografie, video a odkazy připojené k tréninku nebo cviku.</li>
            <li>V průběhu můžete přidat vlastní poznámku a fotografie.</li>
            <li>Po dokončení klikněte na Dokončit online trénink. Poté už výsledky nelze měnit.</li>
        </ol>
        <p class="mb-0">Online trénink probíhá samostatně. Nevyžaduje místo, GPS ani potvrzení přítomnosti trenéra.</p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-calendar">
    <div class="card-header fw-semibold"><i class="fas fa-calendar-alt me-2 text-warning"></i>3) Kalendář: jak pracovat s termíny <span class="manual-priority manual-priority-high">Každý den</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Jak zkontrolovat nejbližší tréninky</h6>
        <ol>
            <li>Otevřete Kalendář.</li>
            <li>V Týdenním kalendáři zkontrolujte nejbližší termíny a jejich stav.</li>
            <li>V Měsíčním seznamu si zkontrolujte přehled všech událostí v měsíci.</li>
            <li>Klikněte na konkrétní termín a zkontrolujte datum, čas, místo a stav schválení.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak řešit změnu termínu</h6>
        <ol>
            <li>U čekajícího termínu (Ke schválení) můžete termín přímo upravit nebo zrušit.</li>
            <li>U schváleného budoucího termínu pošlete požadavek na změnu.</li>
            <li>Pokud je termín označený jako Nelze zrušit, je potřeba domluva s trenérem přes Zprávy.</li>
            <li>Průběžně sledujte stav požadavku v kalendáři a v měsíčním seznamu.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-dumbbell me-2 text-warning"></i>4) Tréninky a detail tréninku</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak zobrazit historii tréninků</h6>
        <ol>
            <li>V Profilu nebo detailu sportovce otevřete historii tréninků.</li>
            <li>Vyberte konkrétní trénink podle data.</li>
            <li>Otevřete Detail tréninku.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak číst detail tréninku</h6>
        <ol>
            <li>U skupinových lekcí se nyní v přehledu zobrazí přesnější typ události, takže hned poznáte, o jaký termín jde.</li>
            <li>Zkontrolujte seznam cviků v tréninku.</li>
            <li>U každého cviku projděte série, opakování, váhy nebo čas.</li>
            <li>Porovnejte výkon s předchozími tréninky.</li>
            <li>Nepřesnosti nebo nejasnosti napište trenérovi do Zpráv.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-food">
    <div class="card-header fw-semibold"><i class="fas fa-utensils me-2 text-warning"></i>5) Jídelníčky: jak plán používat denně</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak otevřít a číst jídelníček</h6>
        <ol>
            <li>Otevřete Jídelníčky.</li>
            <li>Vyberte aktuálně přiřazený plán.</li>
            <li>Procházejte plán po dnech (pondělí až neděle).</li>
            <li>U každého dne zkontrolujte typ jídla, množství a poznámku.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak reagovat na změnu jídelníčku</h6>
        <ol>
            <li>Po notifikaci otevřete znovu Jídelníčky.</li>
            <li>Najděte nově upravený plán.</li>
            <li>Porovnejte, co se změnilo oproti předchozímu režimu.</li>
            <li>V případě nejasností napište trenérovi.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-food-diary">
    <div class="card-header fw-semibold"><i class="fas fa-bowl-food me-2 text-warning"></i>5b) Strava: reálný denní záznam jídla a pitného režimu</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak zapisovat jídlo během dne</h6>
        <ol>
            <li>Otevřete sekci Strava.</li>
            <li>Vyberte den v kalendáři.</li>
            <li>U každého jídla vyplňte položky, množství, poznámku a volitelně fotku.</li>
            <li>Pokud jste jídlo neměli, použijte volbu Jídlo jsem vynechal.</li>
            <li>Záznam průběžně ukládejte; nemusíte čekat na konec dne.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak používat pitný režim (průběžně po dávkách)</h6>
        <ol>
            <li>V denním detailu Stravy použijte blok Pitný režim.</li>
            <li>Zaškrtněte druh pití (můžete i více současně).</li>
            <li>Zadejte množství dávky v ml nebo l a klikněte na Přidat dávku.</li>
            <li>Pro volbu Vlastní doplňte název nápoje.</li>
            <li>Historii dávek otevřete přes rozbalovací sekci Historie dávek.</li>
            <li>Součet vypitého množství vidíte hned v Dnešním přehledu.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-payments">
    <div class="card-header fw-semibold"><i class="fas fa-wallet me-2 text-warning"></i>6) Platby: jak číst přehled <span class="manual-priority manual-priority-mid">Měsíčně</span></div>
    <div class="card-body">
        <ol>
            <li>V Profilu v kartě Platby zkontrolujte rychlý přehled posledních období.</li>
            <li>Pro plný přehled klikněte na Zobrazit platby.</li>
            <li>Zkontrolujte období, počet započítaných tréninků, částku a stav (Čeká na úhradu / Uhrazeno).</li>
            <li>Pokud je platba čekající, otevřete QR a uhraďte podle pokynů.</li>
            <li>Po úhradě ověřte, že se stav změnil na Uhrazeno.</li>
            <li>V případě nesrovnalostí kontaktujte trenéra přes Zprávy.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-chart-line me-2 text-warning"></i>7) Grafy: jak vyhodnocovat progres</div>
    <div class="card-body">
        <ol>
            <li>Otevřete Grafy.</li>
            <li>Vyberte období, které chcete sledovat.</li>
            <li>Projděte trend váhy a dalších dostupných metrik.</li>
            <li>Zapište si body, které chcete konzultovat s trenérem.</li>
            <li>Po konzultaci upravte režim podle doporučení.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-images me-2 text-warning"></i>8) Galerie: jak pracovat s podklady</div>
    <div class="card-body">
        <ol>
            <li>Otevřete Galerii.</li>
            <li>Vyberte složku nebo fotografii, kterou sdílel trenér.</li>
            <li>Zkontrolujte, zda jde o aktuální podklad k tréninku.</li>
            <li>Při nejasnostech pošlete dotaz přes Zprávy.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-files">
    <div class="card-header fw-semibold"><i class="fas fa-folder-open me-2 text-primary"></i>9) Soubory: vlastní dokumenty a sdílení s trenérem</div>
    <div class="card-body">
        <ol>
            <li>Na dashboardu otevřete dlaždici Soubory.</li>
            <li>Klikněte na Nahrát soubor a vyberte jeden dokument nebo více souborů ze svého zařízení.</li>
            <li>Vyberte typ dokumentu; při výběru více souborů zadejte povinný společný název dokumentové sady.</li>
            <li>Zapněte Zpřístupnit soubor trenérovi jen u podkladů, které má trenér vidět.</li>
            <li>Po uploadu lze sdílení kdykoli přepnout; soukromé soubory vidíte pouze vy.</li>
            <li>Soubory jsou v seznamu řazené podle typu; soubory v jedné sadě mají společný název a lze je stáhnout jednotlivě nebo smazat celou sadu.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-events">
    <div class="card-header fw-semibold"><i class="fas fa-flag-checkered me-2 text-warning"></i>10) Events: jak pracovat se speciálními událostmi <span class="manual-priority manual-priority-low">Volitelné</span></div>
    <div class="card-body">
        <ol>
            <li>Pokud je modul odemčený, otevřete na dashboardu dlaždici Events.</li>
            <li>Vyberte konkrétní event kartu a otevřete detail.</li>
            <li>V detailu přepínejte záložky (obsah, pravidla, formuláře, další informace).</li>
            <li>U nadcházejících položek sledujte živý stav (zbývá / probíhá / proběhlo).</li>
            <li>Pokud je potřeba vyplnit formulář, odešlete jej přímo v detailu eventu.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-mycoach">
    <div class="card-header fw-semibold"><i class="fas fa-brain me-2 text-warning"></i>11) MyCoach: cíle, dotazník a denní doporučení <span class="manual-priority manual-priority-mid">Průběžně</span></div>
    <div class="card-body">
        <ol>
            <li>Pokud je modul odemčený, otevřete na dashboardu dlaždici MyCoach.</li>
            <li>V přehledu založte nebo vyberte aktivní cíl.</li>
            <li>Nastavte primární cíl (hvězdička), pokud máte více aktivních cílů.</li>
            <li>Vyplňte Úvodní dotazník na samostatné stránce dotazníku.</li>
            <li>Průběžně sledujte doporučení, readiness a timeline (Start/Taper/Finish/Archiv).</li>
            <li>Cíle lze prodlužovat nebo ukončovat; ukončené cíle se přesouvají do archivu.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-video me-2 text-warning"></i>12) Videa od trenéra: přehrávání a orientace</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak otevřít videa od trenéra</h6>
        <ol>
            <li>V menu klikněte na Videa nebo použijte dlaždici Videa na dashboardu.</li>
            <li>V horní části se otevře hlavní přehrávač.</li>
            <li>Pod přehrávačem najdete seznam sdílených videí.</li>
            <li>Kliknutím na vybranou položku ji hned přehrajete v hlavním okně.</li>
        </ol>

        <h6 class="fw-bold mt-3">Na co si dát pozor</h6>
        <ol>
            <li>Videa jsou dostupná jen po přihlášení do vašeho účtu.</li>
            <li>Pokud trenér přidá nové video, seznam se průběžně automaticky obnovuje.</li>
            <li>Když nějaké video nevidíte nebo nejde přehrát, napište trenérovi přes Zprávy.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-messages">
    <div class="card-header fw-semibold"><i class="fas fa-envelope me-2 text-warning"></i>13) Zprávy: komunikace s trenérem <span class="manual-priority manual-priority-high">Reakce do 24 h</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Jak odeslat zprávu trenérovi</h6>
        <ol>
            <li>Otevřete Zprávy.</li>
            <li>Klikněte na Nová zpráva nebo otevřete existující vlákno.</li>
            <li>Napište konkrétní text (co, kdy, kde, jaký problém).</li>
            <li>Klikněte na Odeslat.</li>
            <li>Průběžně sledujte odpověď.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athlete-routine">
    <div class="card-header fw-semibold"><i class="fas fa-calendar-week me-2 text-warning"></i>Doporučená denní rutina sportovce</div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Ráno: ověřte dnešní termín v Kalendáři.</li>
            <li>Při změně zdravotního stavu ihned odešlete aktualizaci v Z. dotazníku.</li>
            <li>Během dne: dodržujte jídelníček podle aktuálního plánu.</li>
            <li>Během dne: průběžně přidávejte dávky pitného režimu do Stravy.</li>
            <li>Po kontrole progresu: doplňte aktuální hmotnost do profilu.</li>
            <li>Po tréninku: otevřete detail tréninku a zhodnoťte výkon.</li>
            <li>Pokud používáte MyCoach: zapište denní vstupy a zkontrolujte doporučení.</li>
            <li>Večer: zkontrolujte Zprávy a potvrďte další kroky s trenérem.</li>
        </ol>
    </div>
</div>

<div class="alert alert-warning border-0 shadow-sm mb-4">
    Pokud v aplikaci narazíte na chybu, použijte tlačítko podpory v pravém dolním rohu. Popište problém co nejpřesněji a přiložte screenshot.
</div>

<?php renderAthleteFooter(); ?>
