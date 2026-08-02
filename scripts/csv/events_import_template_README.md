# Events CSV import vzory

Soubor pro hromadny import eventu je v `scripts/csv/events_import_template.csv`.
Soubor pro hromadny import nadchazejicich udalosti je v `scripts/csv/upcoming_items_import_template.csv`.

## Prirazeni ke spravnemu eventu
- Klic je sloupec `slug`.
- Pokud `slug` uz existuje, event se aktualizuje.
- Pokud `slug` neexistuje, event se vytvori.

## Sloupce
- `slug` (povinny): unikatni identifikator eventu, napr. `hyrox`
- `name` (povinny): nazev eventu
- `icon_class` (volitelny): FA trida, napr. `fa-dumbbell`
- `description` (volitelny): text na karte eventu
- `badge_label` (volitelny): stitek, napr. `Novinka`
- `tile_image` (volitelny): cesta k obrazku karty, napr. `/uploads/events/images/hyrox.jpg`
- `audience` (volitelny): `coach`, `athlete`, `both`
- `sort_order` (volitelny): poradi zobrazeni, napr. `10`
- `is_active` (volitelny): `1` aktivni, `0` skryty

## Format
- Oddelovac muze byt `;` nebo `,`.
- Prvni radek musi byt hlavicka.
- Kodovani doporucene UTF-8.

## Poznamka
Import eventu pracuje s tabulkou `special_events` a paruje podle `slug`.
Import nadchazejicich udalosti pracuje s tabulkou `special_event_upcoming_items` a paruje podle `event_slug` -> `special_events.slug`.

## Sloupce pro nadchazejici udalosti
- `event_slug` (povinny): slug eventu, ke kteremu se ma polozka priradit
- `title` (povinny): nazev udalosti
- `event_date` (povinny): datum ve formatu `YYYY-MM-DD`
- `target_url` (volitelny): odkaz na registraci/oficialni stranku
- `sort_order` (volitelny): poradi zobrazeni
- `is_active` (volitelny): `1` aktivni, `0` skryty
