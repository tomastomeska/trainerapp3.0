# MyCoach - import cviku z CSV (administrace)

Tento import je urceny pro stranku **Admin > MyCoach > Sprava cviku**.

## Kde to najdu
- Otevri: `/admin/mycoach_content.php`
- Zalozka: **Sprava cviku**
- Karta: **Import cviku z CSV**

## Sablona
- Stahni soubor: `mycoach_cviky_import_sablona.csv`
- Oddelovac: **strednik** `;`
- Kodovani: UTF-8 s BOM (nastaveno v sablone)

## Excel a ceske znaky
Pokud po otevreni souboru vidis rozsypane znaky (napr. `Ã¡`, `Å™`), neotvirej CSV dvojklikem.

Pouzij v Excelu import:
1. `Data` -> `Z textu/CSV`
2. Vyber soubor `mycoach_cviky_import_sablona.csv`
3. `Puvod souboru` nastav na `65001: Unicode (UTF-8)`
4. `Oddelovac` nastav na `Strednik` (`;`)
5. Potvrd `Nacist`

Pri ukladani zpet do CSV pouzij `CSV UTF-8` (pokud to Excel nabidne).

## Povinne a doporucene sloupce
- Povinny sloupec: `nazev` (nebo `name`)
- Doporucene sloupce:
  - `id`
  - `kategorie`
  - `obtiznost`
  - `svalove_partie`
  - `vybaveni`
  - `video_url`
  - `thumbnail`
  - `popis`
  - `instrukce`
  - `poradi`
  - `aktivni`

## Vice kategorii u jednoho cviku
Do sloupce `kategorie` zadej vice kategorii oddelenych carkou.

Priklad:
`Síla, Horní část těla, Kondice`

Stejne to funguje i u sloupce `svalove_partie`.

## Jak funguje aktualizace vs. vlozeni
- Pokud je vyplnene `id` a cvik s timto ID existuje -> **aktualizace**
- Pokud `id` chybi, ale existuje stejny `nazev` -> **aktualizace**
- Jinak -> **vlozeni noveho cviku**

## Povolene hodnoty obtiznosti
- `začátečník` / `zacatecnik` / `beginner`
- `střední` / `stredni` / `intermediate`
- `pokročilý` / `pokrocily` / `advanced`

## Povolene hodnoty aktivni
- Aktivni: `1`, `ano`, `true`, `yes`, `aktivni`
- Neaktivni: `0`, `ne`, `false`, `no`

## Doporuceny postup
1. Stahni sablonu CSV.
2. Vypln radky cviku.
3. Nahraj soubor v karte "Import cviku z CSV".
4. Po importu zkontroluj pocet pridanych/aktualizovanych cviku ve flash zprave.
