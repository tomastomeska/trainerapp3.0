<?php
require_once __DIR__ . '/../includes/admin_auth.php';

requireAdminLogin();
require_once __DIR__ . '/header.php';

renderAdminHeader('Návod pro SuperAdmin');
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
    <h4 class="fw-bold mb-0">
        <i class="fas fa-book-open me-2" style="color:#a78bfa"></i>Návod pro SuperAdmin
    </h4>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-house me-1"></i>Dashboard
        </a>
        <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Vytisknout
        </button>
    </div>
</div>

<div class="alert border-0 shadow-sm" style="background:linear-gradient(135deg,#ede9fe 0%,#ddd6fe 100%);color:#312e81;">
    Tento návod popisuje kompletní provozní workflow administrace: od založení trenéra až po správu zdravotního dotazníku, Events, podpory, e-mailových notifikací a kontrolu stability systému.
</div>

<div class="card border-0 shadow-sm mb-4 manual-sticky-nav">
    <div class="card-body py-3">
        <div class="small text-uppercase fw-bold text-secondary mb-2">Rychlá navigace</div>
        <div class="manual-anchor-links">
            <a href="#quickstart" class="btn btn-sm btn-outline-primary">Start</a>
            <a href="#coaches" class="btn btn-sm btn-outline-primary">Trenéři</a>
            <a href="#athletes" class="btn btn-sm btn-outline-primary">Sportovci</a>
            <a href="#content" class="btn btn-sm btn-outline-primary">Obsah aplikace</a>
            <a href="#health-questionnaire-admin" class="btn btn-sm btn-outline-primary">Z. dotazník</a>
            <a href="#events" class="btn btn-sm btn-outline-primary">Events</a>
            <a href="#support" class="btn btn-sm btn-outline-primary">Podpora</a>
            <a href="#ops" class="btn btn-sm btn-outline-primary">Provoz a stabilita</a>
            <a href="#admin-routine" class="btn btn-sm btn-outline-primary">Denní rutina</a>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="quickstart">
    <div class="card-header fw-semibold"><i class="fas fa-rocket me-2 text-warning"></i>1) Doporučený start od nuly <span class="manual-priority manual-priority-high">Doporučeno ihned</span></div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Vytvořte trenéra v sekci Trenéři.</li>
            <li>Zkontrolujte jeho aktivní stav a základní údaje.</li>
            <li>V případě potřeby založte sportovce ručně v sekci Sportovci.</li>
            <li>Nastavte globální cviky, globální jídla a sportoviště.</li>
            <li>Zkontrolujte konfiguraci zdravotního dotazníku a globální přístup sportovců.</li>
            <li>Zkontrolujte Events a případné publikované eventy.</li>
            <li>Projděte fronty podpory a notifikací (Podpora, E-mailové notifikace).</li>
            <li>Na závěr ověřte logy v Errorlogu.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="coaches">
    <div class="card-header fw-semibold"><i class="fas fa-user-tie me-2 text-warning"></i>2) Trenéři: založení, správa, přístup <span class="manual-priority manual-priority-high">Denně</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Založení nového trenéra</h6>
        <ol>
            <li>Otevřete Trenéři a klikněte na Přidat trenéra.</li>
            <li>Vyplňte identifikační údaje a přístupové informace.</li>
            <li>Uložte a ověřte, že je trenér viditelný v seznamu.</li>
        </ol>

        <h6 class="fw-bold mt-3">Běžná správa trenéra</h6>
        <ol>
            <li>V detailu trenéra upravujte stav účtu (aktivní/neaktivní).</li>
            <li>Kontrolujte počet sportovců a tréninků.</li>
            <li>Při řešení incidentu použijte impersonaci a reprodukujte problém v trenérském UI.</li>
            <li>Po dokončení vždy ukončete impersonaci.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="athletes">
    <div class="card-header fw-semibold"><i class="fas fa-users me-2 text-warning"></i>3) Sportovci v administraci <span class="manual-priority manual-priority-high">Denně</span></div>
    <div class="card-body">
        <ol>
            <li>V sekci Sportovci filtrujte podle trenéra a stavu.</li>
            <li>Proveďte úpravy profilu, reset přístupu nebo deaktivaci podle potřeby.</li>
            <li>Při řešení podpory kontrolujte vazbu sportovec-trenér a historii aktivit.</li>
            <li>Zásahy do účtu vždy dělejte tak, aby byla zachována auditní stopa.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="content">
    <div class="card-header fw-semibold"><i class="fas fa-layer-group me-2 text-warning"></i>4) Obsah aplikace (globální data)</div>
    <div class="card-body">
        <h6 class="fw-bold">Globální cviky a jídla</h6>
        <ol>
            <li>Spravujte je pouze v admin sekcích Globální cviky a Globální jídla.</li>
            <li>U změn názvů dbejte na zpětnou kompatibilitu existujících plánů.</li>
            <li>Před mazáním ověřte, zda nejsou položky navázané v aktivních datech.</li>
        </ol>

        <h6 class="fw-bold mt-3">Sportoviště a import tréninků</h6>
        <ol>
            <li>Sportoviště držte normalizovaná, bez duplicitních názvů.</li>
            <li>CSV importy tréninků spouštějte na validovaných datech.</li>
            <li>Po importu proveďte namátkovou kontrolu výsledků.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="health-questionnaire-admin">
    <div class="card-header fw-semibold"><i class="fas fa-heart-pulse me-2 text-warning"></i>5) Zdravotní dotazník sportovce <span class="manual-priority manual-priority-high">Nový modul</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Správa otázek</h6>
        <ol>
            <li>Otevřete sekci Zdravotní dotazník v levém admin menu.</li>
            <li>Spravujte kroky, názvy sekcí, klíče otázek, typy vstupů a pořadí.</li>
            <li>U výběrových otázek nastavujte možnosti a podle potřeby i podmíněné zobrazení.</li>
            <li>Pro rizikové odpovědi nastavujte alert módy a text upozornění pro trenéra.</li>
            <li>Změny dělejte opatrně, protože přidání nové otázky může vyžádat aktualizaci dotazníků u sportovců.</li>
        </ol>

        <h6 class="fw-bold mt-3">Globální přístup sportovců</h6>
        <ol>
            <li>V Nastavení aplikace najděte blok Zdravotní dotazník sportovce.</li>
            <li>Přepínačem můžete modul povolit nebo dočasně vypnout pro celý systém.</li>
            <li>Po vypnutí se sportovci na stránku dotazníku nedostanou.</li>
            <li>Tento zásah používejte jen výjimečně, například při servisním okně nebo redesignu formuláře.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="events">
    <div class="card-header fw-semibold"><i class="fas fa-flag-checkered me-2 text-warning"></i>6) Events modul <span class="manual-priority manual-priority-mid">Průběžně</span></div>
    <div class="card-body">
        <ol>
            <li>V sekci Events spravujete eventy, záložky a publikaci pro cílové publikum.</li>
            <li>Používejte unikátní slugy a konzistentní názvosloví eventů.</li>
            <li>Média nahrávejte přes vestavěné upload akce (obrázek/video v editoru).</li>
            <li>Formuláře z eventů odesílají data na cílový e-mail nastavený v administraci.</li>
            <li>CSV import používejte jen podle dostupných šablon v scripts/csv.</li>
            <li>Pokud nejsou eventy publikované, uživatelské stránky mají zobrazit pouze prázdný stav.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="support">
    <div class="card-header fw-semibold"><i class="fas fa-life-ring me-2 text-warning"></i>7) Podpora, zprávy a oznámení <span class="manual-priority manual-priority-high">Priorita</span></div>
    <div class="card-body">
        <h6 class="fw-bold">Podpora</h6>
        <ol>
            <li>Nové tickety najdete v sekci Podpora (badge v levém menu).</li>
            <li>Třiďte podle priority: blokující přihlášení, platby, kalendář, obsah.</li>
            <li>Uzavřené tickety krátce komentujte pro budoucí dohledání.</li>
        </ol>

        <h6 class="fw-bold mt-3">Zprávy trenérům a hlášky po přihlášení</h6>
        <ol>
            <li>Systémové zprávy publikujte jasně, stručně a s termínem účinnosti.</li>
            <li>V sekci Zprávy sportovcům odešlete interní oznámení všem sportovcům s aktivním přístupem do aplikace, bez ohledu na jejich trenéra.</li>
            <li>Hlášku po přihlášení používejte pro kritické změny nebo výpadky.</li>
            <li>U důležitých změn kombinujte hlášku + Infokanál + e-mailovou notifikaci.</li>
            <li>Po zpřístupnění dokumentu sportovcem přijde trenérovi systémová zpráva i e-mail s názvem sportovce a typem dokumentu.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="online-trainings">
    <div class="card-header fw-semibold"><i class="fas fa-laptop me-2 text-warning"></i>Online tréninky</div>
    <div class="card-body">
        <ol class="mb-0">
            <li>V Online trénincích kontrolujte stav databázového schématu a spusťte migraci po nasazení nové verze modulu.</li>
            <li>Servisní přehled umožňuje filtrovat online tréninky podle trenéra, sportovce a stavu.</li>
            <li>Trvalé smazání používejte pouze pro testovací nebo chybně vytvořená data; smaže výsledky, přílohy, soubory i účetní položky navázané na konkrétní trénink.</li>
            <li>Před zásahem do produkčního účtování ověřte, zda sportovci nebo trenérovi již neodešla měsíční výzva.</li>
            <li>Pro oznámení funkce trenérům použijte tlačítko Připravit zprávu trenérům v administraci Online tréninků.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="ops">
    <div class="card-header fw-semibold"><i class="fas fa-server me-2 text-warning"></i>8) Provoz a stabilita <span class="manual-priority manual-priority-high">Kritické</span></div>
    <div class="card-body">
        <ol>
            <li>Pravidelně kontrolujte Errorlog a opakované chyby řešte prioritně.</li>
            <li>Migrace databáze spouštějte řízeně, mimo kritické špičky provozu.</li>
            <li>Modul Soubory sportovce používá tabulku <code>athlete_files</code>; po nasazení spusťte na produkčním serveru <code>scripts/migrate_athlete_files.php</code>.</li>
            <li>Dokumenty se ukládají do <code>uploads/athlete_files/athlete_ID/</code>; více souborů může tvořit jednu sadu přes <code>upload_batch</code> a zpřístupnění se ověřuje přes autorizovaný download endpoint.</li>
            <li>U synchronizací kalendářů kontrolujte fronty, chybové stavy a poslední úspěšné běhy.</li>
            <li>U e-mailů kontrolujte frontu notifikací a případné fallback odesílání.</li>
            <li>Před větší změnou si ověřte dopad na coach i athlete část aplikace.</li>
        </ol>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4" id="admin-routine">
    <div class="card-header fw-semibold"><i class="fas fa-calendar-week me-2 text-warning"></i>Denní rutina SuperAdmina</div>
    <div class="card-body">
        <ol class="mb-0">
            <li>Ráno: dashboard + nové tickety podpory.</li>
            <li>Zkontrolujte, zda zdravotní dotazník běží v požadovaném režimu a nejsou potřeba úpravy formuláře.</li>
            <li>Dopoledne: kontrola errorlogu a kritických incidentů.</li>
            <li>Odpoledne: změny obsahu (Events, Infokanál, hlášky).</li>
            <li>Večer: rychlá kontrola notifikačních front a stavu integrací.</li>
        </ol>
    </div>
</div>

<div class="alert alert-warning border-0 shadow-sm mb-4">
    Při zásazích do účtů a produkčních dat vždy zapisujte důvod změny a držte konzistentní auditní stopu.
</div>

<?php renderAdminFooter(); ?>