# Výkonnost a regenerace

Modul je součástí stávajících Kalkulaček. Používá stejné účty, profily,
vážení, Stravu a dokončené tréninky. Nezakládá druhý kalendář ani nové
záznamy absolvovaných tréninků.

Vzhled sdílí s BMI a Analýzou sportovce světlé stříbrné panely a formuláře,
tmavší ocelovou úvodní kartu a zlaté akcenty. Změny barev nemění výpočty,
uložená data ani oprávnění; tisk si zachovává bílý podklad.

## Bezpečné nasazení

### Tlačítka v administraci (bez přístupu k terminálu)

V **Administrace → Kalkulačky → Nasazení modulu Výkonnost a regenerace**
se zobrazí skutečný název připojené databáze a výsledek čtecí kontroly.
Používá se již nastavené připojení projektu; žádné heslo se nezadává do formuláře.

1. Domluvte servisní okno a pozastavte zápisy uživatelů do aplikace.
   Formulář sám nezavádí globální režim údržby.
2. Tlačítkem **Vytvořit a stáhnout zálohu dotčených tabulek** stáhněte SQL.
   Záloha obsahuje strukturu a všechny řádky `app_settings`,
   `mycoach_daily_questionnaires` a případných šesti tabulek nového modulu.
   Obsahuje i příkazy pro odstranění nových tabulek při návratu.
   **Není úplnou zálohou celé databáze ani uploads.** Úplnou provozní
   zálohu dál zajišťuje správce hostingu / stávající zálohovací mechanismus.
3. Soubor bezpečně uložte mimo veřejný web. Obsahuje osobní údaje.
   Obnovte administraci; uvidíte velikost a SHA-256 dokončeného exportu.
   Server nemůže potvrdit, že jej prohlížeč skutečně uložil.
4. Potvrďte uložení zálohy i pozastavené zápisy a opište zobrazený název databáze.
   Pak stiskněte **Vytvořit / doplnit tabulky modulu**.
   Potvrzení zálohy platí 30 minut pro tutéž databázi, server a admin relaci.
   Po pokusu o migraci je spotřebované; při chybě je potřeba nová záloha.
5. Po úspěchu zapněte hlavní přepínač modulu a požadované podfunkce.
   Nasazení samo nemění globální přístup ani souhlasy sportovců.

Záloha i migrace vyžadují přihlášeného superadministrátora a POST s CSRF.
Záloha se vytváří do privátního dočasného souboru, který je po odeslání odstraněn;
nevzniká veřejně dostupný SQL soubor. Při chybě zálohy se migrace nepovolí.
Export čte data v konzistentním InnoDB snapshotu, po řádcích bez načítání
celých tabulek do paměti. Pro jiné enginy, tabulky s triggery, velké exporty
binární rozšíření dotčených tabulek nebo nedostatečná oprávnění použijte
úplnou zálohu správce a CLI migraci.
Webový export vyžaduje přímé oprávnění `TRIGGER` pro dotčené tabulky,
ověřené přes `SHOW GRANTS`; bez možnosti spolehlivě zjistit triggery export odmítne.
Oprávnění získaná pouze přes role jsou konzervativně odmítnutá.
Zámek databáze brání souběžným zálohám a migracím **tohoto modulu**,
neblokuje však ostatní aplikace ani zápisy sportovců.

Obnovu staženého SQL provádí správce databáze do původní databáze se
zastavenými zápisy a vypnutým modulem. Nahrazuje obsah dotčených tabulek;
pozdější změny v nich by se ztratily. Automatická obnova přes web záměrně není.
Při chybě DDL nejprve zkontrolujte částečně provedené změny a chybový log.

### CLI varianta

1. Zálohujte databázi a nejprve ověřte nasazení na její lokální/testovací kopii.
2. Nasaďte soubory modulu. Vyžadují PHP 8.1+ a PDO MySQL
   (stejně jako stávající mobilní API).
3. V prostředí CLI nastavte `TRAINERAPP_PERFORMANCE_DB_PASS`.
   Heslo nepatří do repozitáře ani do parametrů příkazové řádky.
4. Proveďte audit s **výslovně zvoleným** serverem, databází a uživatelem:

   ```powershell
   php scripts\migrate_performance.php --host=127.0.0.1 --port=3306 --database=NAZEV_DB --user=UZIVATEL
   ```

5. Zkontrolujte `Prerequisites: OK`. Audit je pouze čtecí. Chybějící tabulky
   původního MyCoach nejprve doplňte standardní migrací stávající aplikace;
   modul nezakládá náhradní profily. Audit nenahrazuje kontrolu integrity a zálohu.
6. Spusťte stejný příkaz s `--apply`. Migrace je aditivní a opakovatelná.
   MySQL/MariaDB DDL není transakční: při přerušení nejprve zkontrolujte chybu
   a potom migraci zopakujte. Cizí, nekompatibilní tabulky nepřepisuje.
7. V **Administrace → Kalkulačky** zapněte hlavní přepínač
   **Výkonnost a regenerace** a požadované podfunkce. Hlavní přepínač je
   před prvním nasazením vypnutý. Migrace již nastavené přepínače neresetuje.
8. Zachovává se také globální přístup ke Kalkulačkám, včetně původního pilotu
   sportovce ID 6. Trenér potřebuje globální povolení Kalkulaček.
9. Ověřte výpočty a přístup nejprve na pilotním sportovci, následně rozšiřte
   dostupnost. Produkční migraci nespouštějte bez kontroly cílové databáze.

Skript záměrně nenačítá aplikační konfiguraci ani její výchozí připojení.
Žádný běžný GET/POST kalkulaček nespouští migraci. Jedinou webovou výjimkou
je výslovně potvrzený POST superadministrátora popsaný výše.
Vypnutí hlavního přepínače zablokuje také přímé URL, tisk a API; data nemaže.
Pro případný rollback použijte vypnutí funkce, nikoli mazání původních tabulek.

## Funkce a význam dat

| Funkce | Výpočet / zdroj |
|---|---|
| Hydratace | 35 ml/kg, zaokrouhlení na 50 ml, navíc plán pro konkrétní délku aktivity: 600 ml/h nebo 800 ml/h v horku; volitelně naměřené pocení. Plán doplňování omezen na 1 l/h. |
| Test pocení | `(hmotnost před − po + vypito v litrech − moč v litrech) / hodiny`. Samostatné měření, nikoliv nový zápis váhy či nápojů. |
| 1RM | Epley a Brzycki, 1–12 opakování; při jednom opakování vrací skutečnou zátěž. Série bez dopomoci, časových a vzdálenostních režimů; zahrnuta skutečná hmotnost vybavení. |
| HYROX | Osm běhů, osm stanovišť, Roxzone a penalizace, celé sekundy. Odhad nebo skutečné mezičasy navázané na jednu existující aktivitu. |
| Zátěž | Skutečné aktivní minuty × RPE 1–10, kalendářních 7 a 28 dní, AU. Zobrazuje pokrytí **evidovaných** aktivit. |
| Regenerace | Verzované orientační skóre z šesti denních údajů: spánek, energie, motivace, pocit, bolest svalů a kloubů. Při chybějícím údaji není skóre dopočítáno. |

Hydratace a odhad maxima nejsou určeny nezletilým; pokud profil uvádí věk
pod 18 let, výpočet je zablokovaný. Chybějící datum narození samo nepotvrzuje
dospělost. Hydratační formulář a všechny výstupy obsahují upozornění na omezení.
Výsledky nejsou lékařskou diagnózou, povinným objemem k vypití ani pokynem
k testu skutečného maxima.

### Autoritativní zdroje a deduplikace

- Váha: poslední `athlete_weight_logs` nejpozději k vybranému dni, s datem
  měření; upozornění na starší váhu. Ruční výpočet nemění profil.
- Nápoje: jednotlivé `food_diary_hydration_entries`; starší denní
  `hydration_ml` se použije pouze bez dávek. Oba zdroje se nikdy nesčítají.
  Pivo a destiláty se nepočítají jako nealkoholický příjem; vlastní/neznámé
  nápoje nejsou automaticky považované za nealkoholické.
- Tréninky: jen dokončené, nesmazané `training_sessions` a dokončené
  `online_trainings`. Datum aktivity je den zahájení (u online bez začátku
  den dokončení), také při přechodu přes půlnoc.
- Online časovač není spolehlivá aktivní délka: sportovec ji musí potvrdit.
- Ruční aktivity: původní `food_diary_custom_activities`. Stejnou aktivitu lze
  výslovně propojit s dokončeným tréninkem **téhož dne**; pak se nepočítá podruhé.
  Propojení je vratné. Pokud cílový trénink přestane být dostupný, ruční
  záznam znovu vystupuje samostatně.
- Kalendář: schválené nadcházející rezervace pouze pro kontext hydratace;
  nikdy ne jako důkaz absolvování. Platby, náhrady a rezervace se nemění.
- RPE, aktivní minuty a denní hodnocení: stávající
  `mycoach_daily_questionnaires`. Existující odpovídající záznam se aktualizuje;
  nevzniká druhý profil `mycoach_users`. Nullable `performance_key` odděluje
  denní hodnocení a jednotlivé aktivity. Online ID se nikdy nevkládá do
  cizího klíče pro prezenční trénink.
- Spánek: denní hodnocení má přednost; pokud chybí, použije se poslední
  `mycoach_recovery_entries` typu `sleep` daného dne, s původem údaje.
  Výslovná změna ve formuláři denního hodnocení nepřepisuje původní záznam spánku.
- Historická Analýza sportovce: lze využít uložené naměřené pocení; její
  nutriční cíle a výpočty se automaticky nemění.
- Starší osobní rekordy MyCoach mají jiný katalog cviků: nejsou automaticky
  spojovány podle názvu. MyCoach Pro zaškrtnutí v localStorage nejsou skutečné
  serverové záznamy dokončených aktivit.
- HYROX používá existující standardy a kategorie. Vyžaduje všech devět
  disciplín, jednoznačnou platnou verzi a příslušné zátěže; chybějící,
  nejednoznačné či neúplné standardy výpočet blokují. Pravidla musí uživatel
  před výpočtem ověřit, seed databáze není zárukou aktuálních soutěžních pravidel.

Prázdný den není potvrzené volno. Neznámá délka/RPE není nula.
Nová zátěž v AU se nemíchá s dřívějším proprietárním skóre MyCoach.
Není vypočítáván ACWR ani predikce úrazu.

## Sdílení, historie a tisk

`performance_settings` má tři nezávislé, výchozí **vypnuté** volby:
lokální doporučení, sdílení výkonnosti a sdílení regenerace.
Nastavení mění pouze sportovec. Trenér musí být aktuálně přiřazený sportovci
a mít povolenou odpovídající oblast. Subjektivní hodnocení, testy pocení
a skutečné pokusy zadává pouze sportovec.

Stejná kontrola platí pro stránku, historii, jednotlivou zprávu, tisk
i webové/mobilní API. Odvolání sdílení zablokuje i dříve otevřenou tiskovou URL
při dalším načtení. Již stažené PDF ani již zobrazený dokument nelze zpětně odebrat.

`performance_results` ukládá neměnné snímky vstupů, výsledků, zdrojů,
data, autora a verze algoritmu. Nejsou záměrně v obecné historii BMI:
stávající sdílení BMI nesmí obejít nové souhlasy. Trenér může přidat poznámku,
nepřepisuje tím vypočtený výsledek. Sportovec může snímek smazat; zdrojový
trénink se nemaže. Samostatně lze smazat test pocení nebo HYROX pokus
(včetně jeho mezičasů). Uložení má idempotentní klíč požadavku; opakovaný
požadavek s jinými vstupy není potichu přijat jako nový výsledek.

Každý uložený výsledek má vlastní A4 tisk/PDF se vstupy, původem dat,
verzí, identifikací sportovce a případnou poznámkou trenéra. Skutečné HYROX
pokusy mají vlastní tisk uložených mezičasů. Aktuální přehled a historický
snímek se mohou lišit po pozdější opravě zdrojů; historie se sama nepřepočítává.

## Coach Intelligence

Pravidla běží lokálně v PHP, bez externí AI služby. Vycházejí pouze z dostupných
a sdílených oblastí. Každé doporučení obsahuje kód pravidla, prioritu, zdroje
a verzi. Bolest a nepříznivá regenerace mají přednost před výkonem.
Pravidla propojují spánek/regeneraci se zátěží a odhadem síly, evidenci nápojů
s uloženým plánem hydratace a rozložení HYROX mezičasů s konzultací techniky.
600 AU je vysvětlený orientační signál, nikoli zdravotní hranice.
Žádné doporučení automaticky nemění tréninkový nebo jídelní plán.

## API

- Web: `api/performance.php`; session autentizace; GET čte, POST mění.
  POST přijímá formulář nebo JSON a vždy vyžaduje `csrf_token`.
- Mobil: `api/mobile/performance.php?account_type=athlete` nebo
  `account_type=coach`. Bearer token ověřuje původní mobilní autentizace
  včetně povolení mobilního přístupu. Zvolený typ musí odpovídat tokenu.
  API adaptér neznamená automatické přidání obrazovek do mobilního klienta.
- Trenér navíc předává `athlete_id`; sportovcův cíl je vždy odvozen
  z ověřeného účtu, nikoli z podstrčeného ID.
- GET: `date=YYYY-MM-DD`; volitelně `id` pro snímek nebo `attempt_id`
  pro skutečný HYROX pokus. Standardy: `division`, `gender`.
- POST `action`: `calculate`, `save`, `settings`, `feedback`, `sweat`,
  `link`, `review`, `delete`, `hyrox_attempt`, `delete_sweat`, `delete_attempt`.
  Výpočet má `module` (`hydration`, `strength`, `hyrox`, `load`, `recovery`)
  a `date`. Ukládání snímku, testu či pokusu vyžaduje 32znakový hex
  `request_key` vygenerovaný klientem pro jeden logický požadavek.
- HYROX `segments` obsahuje `run_1`…`run_8`, `station_1`…`station_8`,
  `roxzone`, `penalties` (celé sekundy), dále `division`, `gender`,
  `standards_confirmed=1`; skutečný pokus navíc `activity_key`.
- 1RM: `series_source` nebo `exercise_name`, `weight_kg`, `reps`;
  vždy `unassisted=1`. Hydratace: `weight_kg`, `minutes`,
  `environment=normal|hot`, volitelně `sweat_rate_l_h`.
- Feedback: `scope=daily` a šest denních polí, nebo `scope=activity`,
  `activity_key`, celočíselné `training_duration_minutes` a `rpe_score`.
- Nastavení používá řetězce `"1"` pro zapnuté přepínače; vynechané jsou vypnuté.
- Odpověď: `{success:true,data:...}` nebo `{success:false,error:...}`.
  Stavové kódy: 401 nepřihlášený/neplatný token, 403 oprávnění/CSRF/vypnutí,
  405 nepovolená metoda, 422 neplatná data, 503 chyba dostupnosti.
  Chybějící HYROX standardy jsou v agregovaném GET explicitně označené;
  při výpočtu se požadavek odmítne. Odpovědi se necachují.

## Ověření

```powershell
php tests\performance_test.php
php tests\athlete_analysis_test.php
```

Integrační test vyžaduje **prázdnou izolovanou** databázi na loopback serveru,
lokální testovací účet root bez hesla; nikdy se nesmí spouštět na produkční kopii
obsahující skutečné osoby. Název musí začínat `trainerapp_performance_test_`.
Test vytvoří syntetické fixture tabulky a data, nikoli přesný klon celé aplikace.

```powershell
php tests\performance_integration_test.php --port=3399 --database=trainerapp_performance_test_prvni
php tests\performance_deployment_test.php --port=3399 --database=trainerapp_performance_test_nasazeni
```

Testuje dvojí migraci, zachování dat a přepínačů, zdrojové adaptéry,
souhlasy a jejich odvolání, přístup cizích účtů, historii, tiskový renderer,
idempotenci, deduplikaci aktivit, uložení do původních dotazníků,
mezičasy a mazání bez smazání zdrojového tréninku.
Test nasazení navíc skutečně obnovuje export před migrací i po migraci,
ověřuje přesnost dat včetně Unicode a SQL speciálních znaků, časovou platnost
a vazbu potvrzení na databázi, souběžný zámek a blokování při chybě.
