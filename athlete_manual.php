<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/athlete_header.php';

requireAthleteLogin();

renderAthleteHeader('Návod pro sportovce', false, true);
?>

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
    <strong>Novinky v této verzi:</strong> na profilu nyní v kartě <strong>Platby</strong> vidíte přehled posledních období podobně jako v sekci Platby, a v profilu můžete průběžně vést <strong>historii hmotnosti</strong>.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold">
        <i class="fas fa-rocket me-2"></i>Jak začít po prvním přihlášení
    </div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Přihlaste se přístupem od trenéra.</li>
            <li>Otevřete Profil a zkontrolujte osobní údaje.</li>
            <li>Volitelně doplňte aktuální hmotnost do sekce hmotnosti v profilu.</li>
            <li>V Kalendáři ověřte nejbližší termíny tréninku.</li>
            <li>V Jídelníčcích projděte aktuálně přiřazený plán.</li>
            <li>V Platbách zkontrolujte přehled období a zbývajících tréninků.</li>
            <li>Ve Zprávách si přečtěte nové instrukce od trenéra.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-user me-2 text-warning"></i>1) Profil: kontrola údajů a změna hesla</div>
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
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-calendar-alt me-2 text-warning"></i>2) Kalendář: jak pracovat s termíny</div>
    <div class="card-body">
        <h6 class="fw-bold">Jak zkontrolovat nejbližší tréninky</h6>
        <ol>
            <li>Otevřete Kalendář.</li>
            <li>Přepněte se na aktuální týden/měsíc podle potřeby.</li>
            <li>Klikněte na konkrétní termín a zkontrolujte datum, čas a místo.</li>
        </ol>

        <h6 class="fw-bold mt-3">Jak požádat o změnu termínu</h6>
        <ol>
            <li>V Kalendáři otevřete termín, který potřebujete řešit.</li>
            <li>Zvolte možnost požadavku na změnu (pokud je dostupná).</li>
            <li>Napište stručný důvod a navrhněte alternativní čas.</li>
            <li>Odešlete požadavek a sledujte schválení trenérem.</li>
            <li><strong>Nově</strong> se čekající požadavky zobrazují přehledněji, takže snadno poznáte, co ještě čeká na potvrzení.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-dumbbell me-2 text-warning"></i>3) Tréninky a detail tréninku</div>
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

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-utensils me-2 text-warning"></i>4) Jídelníčky: jak plán používat denně</div>
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

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-wallet me-2 text-warning"></i>5) Platby: jak číst přehled</div>
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
    <div class="card-header fw-semibold"><i class="fas fa-chart-line me-2 text-warning"></i>6) Grafy: jak vyhodnocovat progres</div>
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
    <div class="card-header fw-semibold"><i class="fas fa-images me-2 text-warning"></i>7) Galerie: jak pracovat s podklady</div>
    <div class="card-body">
        <ol>
            <li>Otevřete Galerii.</li>
            <li>Vyberte složku nebo fotografii, kterou sdílel trenér.</li>
            <li>Zkontrolujte, zda jde o aktuální podklad k tréninku.</li>
            <li>Při nejasnostech pošlete dotaz přes Zprávy.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-envelope me-2 text-warning"></i>8) Zprávy: komunikace s trenérem</div>
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

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-calendar-week me-2 text-warning"></i>Doporučená denní rutina sportovce</div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Ráno: ověřte dnešní termín v Kalendáři.</li>
            <li>Během dne: dodržujte jídelníček podle aktuálního plánu.</li>
            <li>Po kontrole progresu: doplňte aktuální hmotnost do profilu.</li>
            <li>Po tréninku: otevřete detail tréninku a zhodnoťte výkon.</li>
            <li>Večer: zkontrolujte Zprávy a potvrďte další kroky s trenérem.</li>
        </ol>
    </div>
</div>

<div class="alert alert-warning border-0 shadow-sm mb-4">
    Pokud v aplikaci narazíte na chybu, použijte tlačítko podpory v pravém dolním rohu. Popište problém co nejpřesněji a přiložte screenshot.
</div>

<?php renderAthleteFooter(); ?>
