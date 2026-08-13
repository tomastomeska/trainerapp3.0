# MyCoach - import treninku z CSV (administrace)

Tento import je urceny pro stranku **Admin > MyCoach > Sprava treninku**.

## Kde to najdu
- Otevri: `/admin/mycoach_content.php`
- Zalozka: **Treninky**
- Karta: **Import treninku z CSV**

## Sablona
- Stahni soubor: `mycoach_treninky_import_sablona.csv`
- Oddelovac: **strednik** `;`
- Kodovani: UTF-8 s BOM

## Sloupce
- Povinne: `nazev` (nebo `title`)
- Volitelne:
  - `id`
  - `sekce` (nazev sekce)
  - `sekce_id`
  - `kategorie`
  - `obtiznost`
  - `delka_min`
  - `thumbnail`
  - `popis`
  - `poradi`
  - `aktivni`

## Vice kategorii u jednoho treninku
Do sloupce `kategorie` zapis vice hodnot oddelenych carkou.

Priklad:
`Protažení, Kardio, Trénink doma`

## Jak funguje aktualizace / vlozeni
- Pokud je vyplnene `id` a zaznam existuje -> aktualizace
- Pokud `id` chybi, ale existuje stejny `nazev` -> aktualizace
- Jinak -> vlozeni noveho treninku

## Obtiznost
- `začátečník` / `zacatecnik` / `beginner`
- `střední` / `stredni` / `intermediate`
- `pokročilý` / `pokrocily` / `advanced`

## Aktivni
- Aktivni: `1`, `ano`, `true`, `yes`, `aktivni`
- Neaktivni: `0`, `ne`, `false`, `no`

## Poznamka k sekci
- Priorita je `sekce_id`
- Pokud `sekce_id` neni vyplnena, pouzije se `sekce` (nazev sekce)
- Kdyz se sekce nenajde, trenink se ulozi bez sekce

## Excel a ceske znaky
Pokud vidis rozsypane znaky (`Ã¡`, `Å™`), nacti CSV pres:
1. `Data` -> `Z textu/CSV`
2. Kodovani `65001: UTF-8`
3. Oddelovac `;`
