# Životní cyklus účtů trenérů

- Stávající úloha `cron_email_notifications.php` u aktivního trenéra po 60 dnech bez přihlášení (u trenéra, který se nikdy nepřihlásil, od registrace) odešle e-mail s upozorněním. Bez platného e-mailu se upozornění ani následné smazání neprovede.
- Od okamžiku odeslání e-mailu běží přesně 336 hodin. Úspěšné přihlášení trenéra tuto lhůtu i upozornění zruší.
- Po uplynutí lhůty tentýž cron uloží SQL zálohu trenéra, jeho sportovců a souvisejících tréninkových dat a poté účet smaže.
- Zálohy lze stáhnout v administraci v části **Uživatelé → Zálohy trenérů**. Automaticky se odstraní po jednom roce.
- Ruční blokace v seznamu trenérů vyžaduje důvod „Porušení všeobecných podmínek“, uloží jej a odešle trenérovi informační e-mail. Odblokování zůstává dostupné.

## Nasazení

1. V administraci **Trenéři → Spustit migraci účtů** spusťte databázovou migraci.
2. Není potřeba přidávat cron: zpracování je součástí stávajícího `cron_email_notifications.php` a poběží při každém jeho spuštění.
3. Ověřte v JSON výstupu nebo logu cronu počty odeslaných upozornění, neúspěšných e-mailů a smazaných účtů. Úloha používá stejné SMTP nastavení jako aplikace.

Smazání se provede při prvním spuštění stávajícího cronu po uplynutí 336 hodin, proto skutečné smazání může být opožděno až o jeden interval jeho naplánovaného běhu.
