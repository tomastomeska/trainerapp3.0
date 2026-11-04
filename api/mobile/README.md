# TrainerApp Mobile API – první fáze

Mobilní API používá Bearer token a pracuje se stejnou MySQL databází jako web.

## Nasazení

1. Nahraj složku `api/mobile/` na web TrainerApp.
2. Při prvním nasazení vytvoř tabulku tokenů níže uvedeným SQL ve stejné databázi jako TrainerApp. Samostatné soubory SQL byly při úklidu odstraněny; existující databáze se tím nemění.
3. API musí být dostupné přes HTTPS.

```sql
CREATE TABLE IF NOT EXISTS mobile_api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_type VARCHAR(20) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mobile_api_token_hash (token_hash),
    KEY idx_mobile_api_user (account_type, user_id),
    KEY idx_mobile_api_expiry (expires_at),
    KEY idx_mobile_api_revoked (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

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
