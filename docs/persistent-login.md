# Zapamatované přihlášení

Trenér a sportovec mohou zůstat přihlášeni na jednotlivých zařízeních.
Volba je při přihlášení předem zaškrtnutá; na sdíleném zařízení ji uživatel
může vypnout. Existující session získá zapamatování až při dalším přihlášení.
Platnost je 30 dní od posledního obnovení při používání stránky; obnovuje se
nejvýše jednou za hodinu. Automatické API dotazy dobu neprodlužují. Smazání
cookie, odhlášení nebo blokování cookie může vyžadovat nové přihlášení.

Cookie obsahuje náhodný identifikátor a tajnou hodnotu; databáze ukládá jen
její hash. Cookie je `Secure`, `HttpOnly` a `SameSite=Lax`; je nutné HTTPS.
HTTPS se kontroluje podle aktuálního požadavku, včetně běžného předání
protokolu z hostingového proxy. Session cookie se na HTTPS rovněž označí jako
`Secure`, i když je konfigurační přepínač prostředí vypnutý.
Na každém požadavku se ověřuje stav účtu a platnost zařízení. Změna hesla
zneplatní zapamatování na ostatních zařízeních; při běžné změně hesla zůstane
aktuální zařízení zachováno. Seznam zařízení je v profilu; odhlášení zařízení
neodstraňuje jeho push registraci. Administrátorské přihlášení se nemění.
Názvy zařízení jsou orientační, nejsou to unikátní názvy telefonů.

## Nasazení

1. Zálohujte databázi a v phpMyAdmin ručně spusťte
   `sql/remembered_devices.sql`. Ověřte vytvoření tabulky.
2. Zálohujte stávající `includes/auth.php`, `login.php`, `logout.php`,
   `profile.php`, `athlete_profile_edit.php`, `athlete_change_password.php`
   a `dashboard.php`.
3. Nahrajte nové `includes/remembered_devices.php` a
   `remembered_device_remove.php`.
4. Nahrajte `includes/auth.php`, potom ostatních šest zálohovaných souborů.
   Bez připravené tabulky novou verzi nenasazujte.
5. Na testovacích účtech ověřte trenéra i sportovce, obnovení po vypršení PHP
   session a přepnutí Wi-Fi/mobilní sítě, volbu pro sdílené zařízení, více
   zařízení, odhlášení aktuálního a jiného zařízení, změnu a reset hesla,
   nucenou změnu hesla, deaktivovaný účet a režim zastupování administrátorem.
   Ověřte i příchod push oznámení po odhlášení.

Offline testy používají simulovanou databázi; nenahrazují uvedené ověření
na WEDOSu. Přechod mezi sítěmi sám o sobě neověří obnovení po vypršení session.

## Návrat

Nejprve obnovte zálohy šesti souborů mimo `includes/auth.php`, potom obnovte
`includes/auth.php`. Nové soubory a tabulku ponechte, dokud nebude návrat
ověřen. Obnovená aplikace neobnovuje přihlášení z nové cookie, ale již otevřené
PHP session mohou trvat do svého zániku. Případné mazání záznamů či tabulky
vyžaduje samostatné rozhodnutí; při pozdějším opětovném nasazení by dosud
platné záznamy opět umožňovaly přihlášení.
