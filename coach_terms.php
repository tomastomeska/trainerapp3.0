<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

requireLogin();

renderHeader('Všeobecné podmínky pro trenéry', false, true);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-file-contract me-2 text-warning"></i>Všeobecné podmínky pro trenéry</h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Zpět na dashboard
        </a>
        <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Vytisknout
        </button>
    </div>
</div>

<div class="alert alert-warning border-0 shadow-sm">
    Tento dokument má informativní charakter a vymezuje účel aplikace, odpovědnost uživatelů a omezení odpovědnosti vývojáře.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-semibold">1) Účel aplikace</div>
    <div class="card-body">
        <p>
            Aplikace je určena pro interní použití v úzké skupině uživatelů, zejména mezi trenérem a jeho sportovci,
            za účelem evidence tréninků, plánování, komunikace, orientačního sledování výkonu a organizační podpory.
            Nejde o veřejnou komerční platformu ani univerzální software pro masové nasazení.
        </p>
        <p class="mb-0">
            Uživatel bere na vědomí, že aplikace slouží jako podpůrný nástroj. Nenahrazuje odborný úsudek trenéra,
            individuální konzultaci, zdravotní vyšetření ani jiné odborné služby.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">2) Ne-komerční povaha projektu</div>
    <div class="card-body">
        <p>
            Vývojář není podnikatel, projekt není vytvářen za účelem podnikání a neslouží k přímému či nepřímému výdělku vývojáře.
            Projekt není určen k prodeji, licencování třetím stranám ani k poskytování placených služeb vývojářem.
        </p>
        <p class="mb-0">
            Pokud uživatel aplikaci používá v rámci vlastní trenérské praxe, činí tak na vlastní odpovědnost a bez vzniku jakéhokoli
            obchodního vztahu mezi uživatelem a vývojářem. Vývojář neposkytuje garance obchodní vhodnosti, nepřetržité dostupnosti
            ani souladu se specifickými regulatorními požadavky uživatele.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">3) Odpovědnost za data a jejich ochranu</div>
    <div class="card-body">
        <p>
            Uživatel je plně odpovědný za správnost, aktuálnost a zákonnost dat, která do aplikace vkládá.
            Uživatel je povinen pravidelně provádět vlastní zálohy důležitých údajů a uchovávat je bezpečným způsobem.
        </p>
        <p>
            Vývojář nenese odpovědnost za ztrátu dat, poškození dat, neúplnost záznamů, zpoždění zápisu,
            nekompatibilitu po aktualizaci prostředí, výpadek hostingu, poruchu serveru, poruchu databáze,
            chybu síťového připojení, zásah třetí osoby, malware, chybnou konfiguraci nebo nesprávné použití aplikace.
        </p>
        <p class="mb-0">
            Uživatel výslovně souhlasí s tím, že ochrana dat je sdílená odpovědnost, přičemž primární odpovědnost
            za provozní zálohování, kontrolu integrity dat a obnovu dat leží na provozovateli prostředí a uživateli.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">4) Omezení odpovědnosti vývojáře</div>
    <div class="card-body">
        <p>
            V maximálním rozsahu přípustném právními předpisy se vylučuje odpovědnost vývojáře za jakoukoli přímou,
            nepřímou, následnou, náhodnou či zvláštní škodu vzniklou v souvislosti s používáním nebo nemožností používání aplikace.
        </p>
        <p>
            To zahrnuje zejména ušlý zisk, přerušení provozu, ztrátu klientů, ztrátu reputace, ztrátu dat,
            nesprávná rozhodnutí učiněná na základě údajů v aplikaci a jakékoli další majetkové či nemajetkové újmy.
        </p>
        <p class="mb-0">
            Vývojář neposkytuje záruku, že aplikace bude bezchybně fungovat ve všech prostředích, v každé chvíli dostupná,
            bezpečná proti všem hrozbám nebo vhodná pro konkrétní účel uživatele.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">5) Provoz, dostupnost a změny systému</div>
    <div class="card-body">
        <p>
            Aplikace může být průběžně měněna, upravována, aktualizována nebo dočasně nedostupná.
            Vývojář není povinen zajišťovat nepřetržitý provoz, okamžité opravy, kompatibilitu se všemi verzemi
            softwaru třetích stran ani zpětnou kompatibilitu všech funkcí.
        </p>
        <p class="mb-0">
            Uživatel bere na vědomí, že funkce mohou být změněny, omezeny nebo odstraněny, a že některé chyby mohou
            být odstraněny až v budoucí verzi nebo nemusí být odstraněny vůbec, pokud to povaha projektu neumožňuje.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">6) Povinnosti trenéra jako uživatele</div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Chránit přístupové údaje a nepředávat je třetím osobám.</li>
            <li>Pravidelně kontrolovat správnost záznamů v trénincích, kalendáři, platbách a jídelníčcích.</li>
            <li>Provádět pravidelné exporty nebo zálohy důležitých dat.</li>
            <li>Používat aplikaci pouze způsobem, který nepoškozuje ostatní uživatele ani provoz systému.</li>
            <li>Nevkládat obsah odporující právním předpisům, dobrým mravům nebo právům třetích osob.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">7) Zdravotní a odborná upozornění</div>
    <div class="card-body">
        <p>
            Aplikace neposkytuje zdravotní péči, lékařské doporučení, diagnózu ani léčbu.
            Veškeré tréninkové a výživové postupy musí být přizpůsobeny individuálnímu stavu sportovce
            a musí respektovat jeho zdravotní omezení.
        </p>
        <p class="mb-0">
            Trenér odpovídá za odborné vedení sportovce. Vývojář nenese odpovědnost za zdravotní komplikace,
            zranění nebo jiné následky spojené s realizací tréninkových či výživových doporučení.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">8) Soukromí, citlivé údaje a přístup k účtu</div>
    <div class="card-body">
        <p>
            Uživatel je povinen zacházet s osobními údaji sportovců odpovědně a pouze v rozsahu nezbytném
            pro účel spolupráce. Uživatel je zároveň povinen zabezpečit své zařízení i účet proti zneužití.
        </p>
        <p class="mb-0">
            Vývojář nenese odpovědnost za únik dat způsobený slabým heslem, sdíleným účtem,
            nezabezpečeným zařízením, napadením infrastruktury třetí strany nebo jiným jednáním mimo přímou kontrolu vývojáře.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">9) Přijetí podmínek</div>
    <div class="card-body">
        <p>
            Používáním aplikace uživatel potvrzuje, že se s těmito podmínkami seznámil, porozuměl jim
            a souhlasí s nimi. Pokud uživatel s podmínkami nesouhlasí, neměl by aplikaci používat.
        </p>
        <p class="mb-0">
            Vývojář si vyhrazuje právo text podmínek přiměřeně aktualizovat. Aktuální verze je vždy zveřejněna na této stránce.
        </p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header fw-semibold">10) Kontakt</div>
    <div class="card-body">
        <p class="mb-2">
            Pro dotazy k těmto podmínkám nebo k provozu aplikace použijte následující kontakt:
        </p>
        <p class="mb-0"><strong>E-mail:</strong> tomas.tomeska@seznam.cz</p>
    </div>
</div>

<div class="alert alert-secondary border-0 shadow-sm mb-4">
    Datum poslední úpravy podmínek: <?= date('d.m.Y') ?>
</div>

<?php renderFooter(); ?>
