# Secrets Rotation and Env Setup (Production)

## 1) Rotate DB credentials immediately
1. Connect as DBA and run template:
- `ops/security/db/rotate_credentials.sql.example`
2. Fill placeholders:
- `REPLACE_DB_NAME`
- `REPLACE_WITH_NEW_STRONG_DB_PASSWORD`
- optional old user removal

## 2) Rotate SMTP password in mail provider
1. Create a new SMTP app password or reset mailbox password.
2. Disable old SMTP password.
3. Keep SMTP username/from aligned with provider policy.

## 3) Configure runtime env vars on web server
1. Use Apache template:
- `ops/security/web/apache-trainerapp-env.conf.example`
2. Add it into the HTTPS vhost for production domain.
3. Reload Apache.

Example (Debian/Ubuntu):
```bash
sudo cp /opt/trainerapp/ops/security/web/apache-trainerapp-env.conf.example /etc/apache2/conf-available/trainerapp-env.conf
sudo nano /etc/apache2/conf-available/trainerapp-env.conf
sudo a2enconf trainerapp-env
sudo systemctl reload apache2
```

## 4) Mandatory production flags
- `TRAINERAPP_ENABLE_LIST_PAGE=0`
- `TRAINERAPP_ENABLE_SETUP_ADMIN=0`
- `TRAINERAPP_SESSION_SECURE=1`

## 5) Validate after deploy
1. Login works for coach/athlete/admin.
2. Password reset email sends successfully.
3. Admin 2FA email OTP sends successfully.
4. `setup_admin.php` is not usable.
5. `list.php` is deleted (404).

## 6) Clean-up
1. Remove/disable old DB user and old SMTP password.
2. Clear sensitive shell history if secrets were typed in plain text.
3. Store new secrets only in secret manager or protected server config.
