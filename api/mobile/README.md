# TrainerApp Mobile API – první fáze

Mobilní API používá Bearer token a pracuje se stejnou MySQL databází jako web.

## Nasazení

1. Nahraj složku `api/mobile/` na web TrainerApp.
2. Jednorázově spusť SQL `ops/security/db/mobile_api_tokens.sql` ve stejné databázi jako TrainerApp.
3. API musí být dostupné přes HTTPS.

## Endpointy

- `POST /api/mobile/login.php` – přihlášení trenéra
- `POST /api/mobile/logout.php` – zneplatnění tokenu
- `GET /api/mobile/me.php` – aktuální trenér
- `GET /api/mobile/athletes.php` – seznam sportovců trenéra
- `GET /api/mobile/athlete.php?id=123` – detail sportovce + poslední tréninky
- `GET /api/mobile/workout_sets.php` – aktivní tréninkové sady trenéra
- `POST /api/mobile/training_start.php` – vytvoření skutečného `training_session`

## Přihlášení

```json
POST /api/mobile/login.php
{
  "username": "trenér",
  "password": "heslo"
}
```

Odpověď obsahuje `token`. Další požadavky posílají:

`Authorization: Bearer <token>`

Mobilní API nevyužívá PHP session ani CSRF token. Oprávnění se kontroluje proti `coaches` a vazbě sportovce na trenéra.
