<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

renderHeader('Návod pro trenéry', false, true);
?>

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
    <strong>Novinky v této verzi:</strong> při vedení tréninku se u sportovce zobrazuje <strong>poslední zadaná hmotnost s datem</strong>, notifikace k narozeninám chodí trenérovi <strong>e-mailem i do Zpráv</strong> a platební výzvy se sportovci zobrazují přehledněji i na dashboardu.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-list-check me-2"></i>Jak začít od nuly (doporučené pořadí)
    </div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Otevřete Profil a zkontrolujte vlastní údaje a heslo.</li>
            <li>V Cvicích připravte nebo upravte cviky, které budete používat.</li>
            <li>V Sadách vytvořte minimálně 1 sadu pro začínajícího sportovce.</li>
            <li>V Sportovci založte nového sportovce.</li>
            <li>V detailu sportovce spusťte první trénink nebo vložte minulý trénink.</li>
            <li>V Kalendáři zapište příští termín tréninku.</li>
            <li>V Platbách založte první platbu.</li>
            <li>V Jídelníčcích vytvořte plán a přiřaďte ho sportovci.</li>
            <li>Ve Zprávách pošlete sportovci úvodní instrukce.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-users me-2 text-warning"></i>1) Sportovci: založení, úprava, detail</div>
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

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-dumbbell me-2 text-warning"></i>2) Cviky: jak vytvořit a používat</div>
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
    <div class="card-header fw-semibold"><i class="fas fa-layer-group me-2 text-warning"></i>3) Sady: kompletní postup vytvoření</div>
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
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-stopwatch me-2 text-warning"></i>4) Trénink: živě, ručně, párově</div>
    <div class="card-body">
        <h6 class="fw-bold">A. Jak spustit živý trénink</h6>
        <ol>
            <li>Otevřete Sportovci a přejděte do detailu sportovce.</li>
            <li>Klikněte na Spustit trénink.</li>
            <li>Vyberte sadu, kterou chcete jet.</li>
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
            <li>U obou sportovců v hlavičce ověřte poslední hmotnost (kg + datum).</li>
            <li>Zapisujte průběh tréninku a dokončete párovou session.</li>
            <li>Zkontrolujte výsledek v detailu obou sportovců.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-calendar-alt me-2 text-warning"></i>5) Kalendář: nové termíny a změny</div>
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
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-wallet me-2 text-warning"></i>6) Platby: zápis a kontrola</div>
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
    <div class="card-header fw-semibold"><i class="fas fa-utensils me-2 text-warning"></i>7) Jídelníčky: vytvoření a přiřazení</div>
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

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-images me-2 text-warning"></i>8) Galerie: složky a nahrávání</div>
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

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-comments me-2 text-warning"></i>9) Zprávy: komunikace se sportovci</div>
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
            <li>Změny hmotnosti, které si sportovec zapisuje v profilu, již nezatěžují inbox samostatnými zprávami.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-chart-line me-2 text-warning"></i>10) Grafy a reporty: jak vyhodnocovat</div>
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
    <div class="card-header fw-semibold"><i class="fas fa-user-cog me-2 text-warning"></i>11) Profil a heslo</div>
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

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-calendar-week me-2 text-warning"></i>Denní workflow trenéra</div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Ráno: otevřete Kalendář a ověřte dnešní termíny.</li>
            <li>Po každém tréninku: zkontrolujte uložení výsledků.</li>
            <li>Odpoledne: projděte Zprávy a odpovězte na dotazy.</li>
            <li>Průběžně: kontrolujte platební výzvy ve vybraném období a označujte uhrazené položky.</li>
            <li>Večer: zkontrolujte Platby, případně doplňte chybějící záznamy.</li>
            <li>1x týdně: vyhodnoťte Grafy a upravte sady/jídelníčky.</li>
        </ol>
    </div>
</div>

<div class="alert alert-warning border-0 shadow-sm mb-4">
    Pokud narazíte na problém v aplikaci, použijte plovoucí tlačítko podpory v pravém dolním rohu. Přiložte screenshot a přesný popis kroku, kde problém vznikl.
</div>

<?php renderFooter(); ?>
