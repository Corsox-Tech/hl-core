---
name: security
description: Security posture, hardening log, server configs, incident history, and ongoing checklist
---

# HL Core Security Reference

Last updated: 2026-04-13

## Cloudflare WAF (both servers — domain-level)

Active since 2026-04-13. 4 custom rules on `housmanlearning.com` (covers both prod and test subdomains):

| Rule | Action | Expression |
|------|--------|------------|
| Geo-block | Block | Not US/CA/CO, excluding verified bots |
| Login CAPTCHA | Managed Challenge | `/wp-login*` or `/login/` |
| xmlrpc block | Block | `/xmlrpc.php` |
| Uploads PHP block | Block | `/wp-content/uploads/*.php` |

Managed via Cloudflare dashboard → Security → WAF → Custom Rules.
DDoS L7 protection + Cloudflare Managed Free Ruleset also active (auto-enabled).

## Current Security Posture

### Test Server (AWS Lightsail)
- **Login access:** Restricted via Apache `<Location>` blocks in vhost config. Only whitelisted IPs can reach `/wp-login.php` or `/login/`. Everyone else gets 403.
- **Whitelisted IPs:** `191.104.69.x` (Mateo), `44.221.6.201` (server localhost), `127.0.0.1`
- **xmlrpc.php:** Blocked entirely (vhost config)
- **Security headers:** X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy (vhost config)
- **PHP in uploads:** Blocked via `<Directory>` directive in vhost
- **Search engine indexing:** Discouraged (`blog_public = 0`)
- **OPcache:** Enabled, tuned (30,000 max files, 256 MB)
- **PHP-FPM:** 4 workers (`pm.max_children = 4`)
- **Config file:** `/opt/bitnami/apache/conf/vhosts/wordpress-https-vhost.conf`
- **Backup of original:** `wordpress-https-vhost.conf.bak.20260412`

### Production (Hostinger LiteSpeed)
- **Login access:** NOT restricted by IP (needs Cloudflare — see checklist)
- **xmlrpc.php:** Blocked via `.htaccess` `<Files>` directive
- **Security headers:** Added to `.htaccess` (X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, Permissions-Policy)
- **PHP in uploads:** Blocked via `/wp-content/uploads/.htaccess`
- **Sensitive files:** wp-config.php, readme.html, license.txt blocked via `.htaccess`
- **Hidden files:** `.htaccess`, `.git`, `.env` blocked via `<FilesMatch>`
- **Directory browsing:** Disabled (`Options -Indexes`)
- **WP_DEBUG:** OFF (turned off 2026-04-12, was previously ON)
- **LiteSpeed Cache:** Active with rewrite rules in `.htaccess`
- **Backup of original .htaccess:** `.htaccess.bak-2026-04-12`

### Both Servers
- **Open registration:** Disabled (`users_can_register = 0`)
- **Default role:** Subscriber
- **Admin accounts:** 14 (prod), 13 (test) — review pending
- **Action Scheduler:** Purged 6,427 failed entries + 19,295 orphan logs on both servers (2026-04-12)

## How to Add a Whitelisted IP (Test Server)

Edit the vhost config:
```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201
sudo nano /opt/bitnami/apache/conf/vhosts/wordpress-https-vhost.conf
```
Add a new `Require ip X.X.X.X` line inside BOTH `<Location /wp-login.php>` and `<Location /login>` blocks. Then restart:
```bash
sudo /opt/bitnami/ctlscript.sh restart apache
```

## Deactivated Plugins (Security/Performance)

Deactivated on **both** servers on 2026-04-12:
| Plugin | Reason |
|--------|--------|
| `wp-file-manager` | Critical RCE vulnerability history (CVE-2020-25213 etc.) |
| `sql-buddy` | Direct browser DB access — use WP-CLI instead |
| `uncanny-automator` | 6,427 failed scheduled actions, cron every 1 min |
| `uncanny-automator-pro` | Companion to above |
| `uncanny-automator-user-lists` | Companion to above |
| `wp-migrate-db-pro` | Migration tool — only activate during migrations |
| `jsm-show-post-meta` | Debug tool — activate only when debugging (test only) |
| `jsm-show-user-meta` | Debug tool — activate only when debugging (test only) |
| `download-plugins-dashboard` | Utility — activate only when needed (test only) |
| `crocoblock-wizard` | Setup tool — one-time use (test only) |

**Do NOT reactivate `wp-file-manager` or `sql-buddy` on production.**

## WPCode Snippets Disabled (Test Server)

Disabled on 2026-04-12 (set to draft):
| ID | Title | Size | Reason |
|----|-------|------|--------|
| 34411 | copy mateo - show reports - Copy | 24 KB | Duplicate/test snippet, parsed every page load |
| 35715 | All Users New Table | 23 KB | Large snippet parsed every page load |
| 34918 | School District - Users Report | 19 KB | Large snippet parsed every page load |

These 3 snippets totaled ~66 KB of PHP eval'd on every request. Consider migrating to hl-core PHP classes.

## Incident Log

### 2026-04-12 — Brute-Force Login Attack on Test Server

**Discovery:** During performance investigation, found test server TTFB of 40-50 seconds. Root cause: sustained brute-force attack on `/wp-login.php` from 58+ unique IPs, ~30 requests/minute. All 4 PHP-FPM workers were permanently busy serving bot requests (each taking 20-40s), starving legitimate traffic.

**Attack volume:** 29,083 requests logged. Top attackers:
- 152.53.42.71 (1,183 requests), 116.118.2.113 (888), 163.172.73.66 (762)
- Full log saved: `/tmp/attack-log-2026-04-12.txt` on test server

**Response:**
1. Added `<Location>` IP whitelist to Apache vhost config — blocks non-whitelisted IPs at Apache level before PHP executes
2. Verified all bot requests now return 403 (no PHP execution)
3. Server load dropped from 7.0 to 1.2 within 30 seconds
4. TTFB dropped from 45s to **0.8s**

**Data exposure assessment:** No evidence of successful login. Bots received either 302 redirects (to BuddyBoss `/login/` page) or 403 after the block was applied. No POST requests with 200 status from non-whitelisted IPs in the attack log. wp-config.php was not accessible (blocked). No suspicious PHP files found in uploads.

**Production impact:** Same 6,426 failed Action Scheduler entries found on production (from the same Uncanny Automator misconfiguration). Purged. No access logs available on Hostinger to confirm bot traffic volume, but production has 64 cores and absorbs it. Still recommended to add Cloudflare for login rate limiting.

## Outstanding Security Checklist

### Priority 1 — DONE
- [x] **Cloudflare WAF active** (2026-04-13) — geo-block US/CA/CO, login CAPTCHA, xmlrpc block, uploads PHP block. Both prod and test covered.

### Priority 2 — This week
- [ ] Tighten `wp-config.php` permissions to 640 on prod (currently 644)
- [ ] Review 14 admin accounts — downgrade: `n8n` (→ Editor), `juanprueba` (→ remove)
- [ ] Enable 2FA for all admin accounts
- [ ] Verify Hostinger automatic backups are active

### Priority 3 — When ready
- [ ] Deactivate 11 JetFormBuilder legacy plugins (both servers)
- [ ] Deactivate 5 WPForms plugins if forms not in active use
- [ ] Migrate 3 disabled WPCode snippets to hl-core PHP classes (if still needed)
- [ ] Purge 46 inactive WPCode snippets to reduce `wpcode_snippets` autoloaded option (130 KB)
