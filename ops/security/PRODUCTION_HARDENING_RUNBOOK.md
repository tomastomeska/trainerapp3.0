# Production Hardening Runbook (TrainerApp)

## 0) Immediate prerequisites
- Ensure you have tested restore from backups on non-production environment.
- Keep one break-glass admin account with strong password and verified mailbox.
- Schedule maintenance window for auth changes (2FA rollout).

## 1) Daily offsite backups + restore testing
1. Copy and set executable:
- ops/security/backup/backup_and_offsite.sh
- ops/security/backup/restore_test.sh
2. Configure env file (example `/etc/trainerapp-backup.env`) with:
- APP_NAME, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
- APP_ROOT, UPLOADS_DIR, BACKUP_DIR
- OFFSITE_RCLONE_REMOTE, OFFSITE_PREFIX
3. Add cron jobs:
- Daily backup at 02:10
- Weekly restore test at Sunday 03:00

Example crontab:
```bash
10 2 * * * source /etc/trainerapp-backup.env && /opt/trainerapp/ops/security/backup/backup_and_offsite.sh
0 3 * * 0 source /etc/trainerapp-backup.env && /opt/trainerapp/ops/security/backup/restore_test.sh
```
4. Alerting:
- Send backup/restore logs to monitoring (mail, Slack, SIEM).
- Trigger alert on non-zero exit code.

## 2) DB least privilege
1. Execute SQL from:
- ops/security/db/least_privilege.sql
2. Update app env to use dedicated app user (`trainerapp_app`).
3. Validate app behavior and migrations.
4. If runtime schema changes are not needed, revoke CREATE/ALTER/DROP later.

## 3) Enforce HTTPS and block HTTP
1. Apply vhost template from:
- ops/security/web/apache-force-https.conf
2. Ensure valid certificate and auto-renewal (Let's Encrypt/certbot).
3. Verify:
- `http://...` redirects to `https://...` with 301
- HSTS present on HTTPS responses
4. If behind reverse proxy/CDN, ensure origin-only access is restricted.

## 4) Firewall + fail2ban/WAF
1. Firewall baseline (Linux example):
- Allow only 22 (restricted), 80, 443.
- DB port (3306/3307) only from app host/private subnet.
2. Install fail2ban and deploy:
- ops/security/fail2ban/trainerapp-auth.conf
- ops/security/fail2ban/trainerapp-auth.jail.local
3. If using Cloudflare (recommended), apply:
- ops/security/web/cloudflare-waf-rules.md

## 5) Enable 2FA for admin accounts
- Implemented in app login flows:
- login_admin.php
- admin/login_admin.php
- scripts/login_admin.php

Behavior:
1. Admin enters username/password.
2. System sends 6-digit OTP to admin email.
3. OTP must be entered within 10 minutes.
4. Session becomes authenticated only after OTP verification.

DB migration included in:
- config/database.php
- admin/config/database.php
- scripts/config/database.php
(added `superadmins.two_factor_enabled` default 1)

## 6) Post-deployment verification checklist
- [ ] Backup created and uploaded offsite.
- [ ] Restore test completed successfully.
- [ ] App connects with least-privileged DB account.
- [ ] HTTP redirects to HTTPS everywhere.
- [ ] WAF/rate-limit rules active.
- [ ] fail2ban jail active and banning test IP.
- [ ] Admin login requires OTP and succeeds with valid code.
- [ ] Invalid OTP attempts are blocked after repeated failures.

## 7) Incident response minimum
- Immediately rotate DB credentials and SMTP credentials after suspected breach.
- Invalidate all active sessions.
- Restore DB/uploads from known good backup if integrity compromised.
- Audit `app_event_log`, web server logs, auth failures, and admin actions.
