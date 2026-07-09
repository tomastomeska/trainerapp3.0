1. Enable Cloudflare WAF managed ruleset (OWASP + Cloudflare Managed Rules).
2. Add custom rule: block requests with known bad bot score and path contains /login.php or /login_admin.php.
3. Add rate limit rule:
- URI path: /login.php, /login_admin.php
- Threshold: 10 requests / 1 minute per IP
- Action: Managed Challenge or Block for 10 minutes.
4. Enable Bot Fight Mode.
5. Enable "Under Attack" only during active incident.
6. Restrict /admin/* with IP allowlist if operationally possible.
