---
name: deploy
description: SSH access, deployment commands, WP-CLI, environment targeting, staging/production rules
---

# HL Core Deployment & Environment Reference

## Repository
- **GitHub:** `https://github.com/Corsox-Tech/hl-core.git`
- **Branch:** `main` (single-branch workflow)
- **Git repo root:** this directory (plugin root) — git commands run from here
- Private repo — never commit data files or credentials

## Local Development
- **Claude Code working directory:** this directory (plugin root)
- **Local absolute path:** `C:\Users\MateoGonzalez\Dev Projects Mateo\housman-learning-academy\app\public\wp-content\plugins\hl-core\`
- Local files are the source of truth for editing. Claude Code edits files here.
- **Note:** The local WordPress installation exists only as a file editing workspace. Testing happens on the test server.

## Which Environment Am I Targeting? (READ THIS FIRST)

> **DEFAULT: Test server (AWS Lightsail)**
>
> As of March 2026, all development and testing happens on the **test** server.
> Staging and production exist but are NOT the active targets.
>
> **If you are unsure which environment to run a command against, ASK THE USER.**

| Environment | Status | When to Use |
|-------------|--------|-------------|
| **Test** (AWS Lightsail) | **ACTIVE DEFAULT** | All WP-CLI commands, testing, debugging, seeding |
| **Staging** (Hostinger) | Secondary — available but not primary | Only if user explicitly says "run on staging" |
| **Production** (Hostinger) | **LIVE** — requires explicit approval | Only with explicit user approval per session |

## Deployment Workflow (current)
1. Claude Code edits files locally (in the Dev Projects folder)
2. Claude Code commits and pushes to GitHub (`main` branch)
3. To deploy to **test**: upload plugin via SCP (tar + extract) — no Git auto-pull on AWS
4. To deploy to **production**: upload plugin via SCP to Hostinger — no .git on prod, GitHub auto-pull broken
5. Claude Code runs WP-CLI commands on target server via SSH to verify
6. Manual verification by the user

**Production is live** (since March 2026). Deploying to production still requires explicit user approval per session.

## Test Server — AWS Lightsail (DEFAULT TARGET)
- **URL:** `https://test.academy.housmanlearning.com`
- **AWS Lightsail** instance (us-east-1, 2GB RAM, 60GB disk, ~$12/mo)
- **WordPress root:** `/opt/bitnami/wordpress/`
- **Plugin path:** `/opt/bitnami/wordpress/wp-content/plugins/hl-core/`
- **Contains:** Full copy of production data (migrated March 2026) + hl-core plugin + demo seed data
- **Purpose:** Pre-production testing — validates hl-core works alongside all production plugins (JetEngine, LearnDash, BuddyBoss, Elementor, etc.)

## Test Server SSH Access

**Connection:**
```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201
```

**Running WP-CLI commands on test:**
```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress <command>'
```

**Examples:**
```bash
# Seed data
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress hl-core seed-demo'

# Check DB tables
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SELECT COUNT(*) FROM wp_hl_cycle"'

# Check plugin status
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress plugin list --status=active'

# Flush caches
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress cache flush'
```

**Deploying plugin updates to test (no Git auto-pull — must SCP):**
```bash
# From local machine:
cd "C:/Users/MateoGonzalez/Dev Projects Mateo/housman-learning-academy/app/public/wp-content/plugins/hl-core"
tar --exclude='.git' --exclude='data' --exclude='./vendor' --exclude='node_modules' --exclude='.superpowers' -czf /tmp/hl-core.tar.gz -C .. hl-core
scp -i ~/.ssh/hla-test-keypair.pem /tmp/hl-core.tar.gz bitnami@44.221.6.201:/tmp/
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress/wp-content/plugins && sudo rm -rf hl-core && sudo tar -xzf /tmp/hl-core.tar.gz && sudo chown -R bitnami:daemon hl-core'
```

## Staging Server (Hostinger — SECONDARY, not default)
- **URL:** `https://staging.academy.housmanlearning.com`
- **Hostinger hosting** with SSH access
- **Staging WordPress root:** `/home/u665917738/domains/academy.housmanlearning.com/public_html/staging/`
- **Staging plugin path:** `/home/u665917738/domains/academy.housmanlearning.com/public_html/staging/wp-content/plugins/hl-core/`
- **IMPORTANT:** Staging is a subdirectory install within the main domain, NOT a separate domain folder.
- **Note:** Hostinger auto-pulls from GitHub on push. Staging still receives code updates automatically.

## Staging SSH Access (use ONLY when user explicitly requests)

**Connection:**
```bash
ssh -p 65002 u665917738@145.223.76.150
```

**Running WP-CLI commands on staging:**
```bash
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp <command>"
```

**ABSOLUTE RULES FOR STAGING — NEVER VIOLATE THESE:**
1. **ALWAYS `cd` into the staging directory first.** Every SSH command MUST start with `cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging &&` before running any `wp` command. Running `wp` from any other directory could hit production.
2. **NEVER run commands against production.** The production root is `/home/u665917738/domains/academy.housmanlearning.com/public_html/` (WITHOUT `/staging/`). If you see a path without `/staging/` in it, STOP — you are targeting production.
3. **NEVER `cd` to the production root** (`/home/u665917738/domains/academy.housmanlearning.com/public_html/`) — this is the live site.
4. **NEVER modify files directly on the server** via SSH. All code changes go through Git (edit locally → commit → push → Hostinger auto-pulls). SSH is for running commands and debugging only.
5. **If in doubt, don't run the command.** Ask the user first.

## Production Server (Hostinger — LIVE)
- **URL:** `https://academy.housmanlearning.com`
- **WordPress root:** `/home/u665917738/domains/academy.housmanlearning.com/public_html/`
- **Plugin path:** `/home/u665917738/domains/academy.housmanlearning.com/public_html/wp-content/plugins/hl-core/`
- **Do NOT deploy to production without explicit approval from the user.**
- **No .git directory** — GitHub auto-pull does NOT work. Must SCP tarball.

**Deploying plugin updates to production (SCP — requires user approval):**
```bash
# From local machine:
cd "C:/Users/MateoGonzalez/Dev Projects Mateo/housman-learning-academy/app/public/wp-content/plugins/hl-core"
tar --exclude='.git' --exclude='data' --exclude='./vendor' --exclude='node_modules' --exclude='.superpowers' -czf /tmp/hl-core.tar.gz -C .. hl-core
scp -P 65002 /tmp/hl-core.tar.gz u665917738@145.223.76.150:/tmp/
ssh -p 65002 u665917738@145.223.76.150 'cd /home/u665917738/domains/academy.housmanlearning.com/public_html/wp-content/plugins && rm -rf hl-core && tar -xzf /tmp/hl-core.tar.gz'
```

**Running WP-CLI commands on production (use with caution):**
```bash
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html && wp <command>"
```

## After deploying new schema changes (run on TEST via SSH):
```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress hl-core nuke --confirm="DELETE ALL DATA" && wp --path=/opt/bitnami/wordpress hl-core seed-demo'
```

## After adding new shortcode pages:
```bash
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress hl-core create-pages'
```

## .gitignore
```
node_modules/
.DS_Store
*.log
/vendor/
/data/       # Private Excel files — never commit
```

## WP-CLI Commands
- `wp hl-core seed-demo [--clean]` — Generic demo data (2 schools, 15 enrollments, code: DEMO-2026)
- `wp hl-core seed-lutheran [--clean]` — Lutheran Services Florida control group data (12 schools, 47 teachers, 286 children, assessment-only pathway, code: LUTHERAN_CONTROL_2026)
- `wp hl-core seed-palm-beach [--clean]` — ELC Palm Beach program data (12 schools, 47 teachers, 286 children, code: ELC-PB-2026)
- `wp hl-core nuke --confirm="DELETE ALL DATA"` — **DESTRUCTIVE: Deletes ALL HL Core data** (all hl_* tables truncated, seeded users removed, auto-increment reset). Safety gate: only runs if site URL contains `staging.academy.housmanlearning.com`, `test.academy.housmanlearning.com`, or `.local`.
- `wp hl-core create-pages [--force] [--status=draft]` — Creates all shortcode WordPress pages
- `wp hl-core migrate-routing-types [--dry-run]` — Populates `routing_type` on existing pathways from pathway_code patterns (idempotent, safe to re-run)
- `wp hl-core sync-ld-enrollment --cycle_id=X [--dry-run]` — Syncs LearnDash course enrollment for all pathway assignments in a cycle (explicit + role fallback). Idempotent, safe to re-run.

