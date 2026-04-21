# CLAUDE.md — HL Core Plugin

> **COMMIT CHECKLIST:** 1) Update STATUS.md (build queue checkboxes) + README.md ("What's Implemented") 2) Commit both alongside code 3) A task is NOT done until both are updated.
> **BEFORE CONTEXT COMPACTION:** Commit all code → update STATUS.md + README.md with status (`[x]` done, `[~]` partial with notes) → commit & push → THEN compact.

## Project Overview
WordPress site for Housman Learning Academy. Primary target: **hl-core** custom plugin.
Products: **B2E Mastery Program** (2-year, 25-course), **Short Courses** (standalone), **ECSELent Adventures** (physical + online).
See `docs/B2E_MASTER_REFERENCE.md` for the complete product catalog.

**Key paths:**
- **STATUS.md** — Build queue + task tracking. Read FIRST every session.
- **README.md** — What's Implemented detail, architecture tree.
- **docs/** — 11 canonical spec files.
- **LearnDash:** `../sfwd-lms/` — hooks/functions reference.
- **data/** — Private Excel files. Gitignored, never commit.

## Mandatory Workflow Rules

### 0. Deploying to test or prod — ALWAYS use `bin/deploy.sh`
> **Do not write ad-hoc `tar ... && scp ... && ssh ... rm -rf hl-core` chains.** Use `bash bin/deploy.sh test` or `bash bin/deploy.sh prod`. The script enforces two guardrails that prevent a class of incident that happened on **2026-04-20** (a parallel Claude session rolled back prod by deploying a stale branch):
> 1. **Tarball source = `git ls-files`.** Only committed files ship. Dev artifacts (`.playwright-mcp/`, `.superpowers/`, untracked debug files) cannot leak.
> 2. **Pre-deploy descendant check.** Reads `.deploy-manifest.json` on the target and aborts if local HEAD is not a descendant of the recorded SHA. Blocks stale-branch overwrites.
>
> Raw `tar/scp` is only acceptable as an emergency escape hatch documented in `.claude/skills/deploy.md`, and only when the script is provably broken.
>
> **Incident record (full detail):** `docs/superpowers/plans/2026-04-20-email-registry-cleanup-progress.md`. Read this if you find yourself confused about why prod has certain code vs not, especially around the email workflow builder, M2 cascade, Course Surveys, Ticket QA, or the D-1 email notification. Those features were on `feature/workflow-ux-m1` (30+ commits ahead of `main`) and were accidentally rolled back then restored.

### 1. Always read STATUS.md first
Read `STATUS.md` at session start to see what's done, in-progress, and next.

### 2. How to continue between sessions
When user says "continue" / "keep going" / starts a new session: **DO NOT code immediately.**
1. Read STATUS.md — check Build Queue for `[x]` done, `[~]` in-progress, `[ ]` pending
2. Report status: last completed, in-progress details, next tasks
3. Ask: "Should I continue with [specific task], or something else?"
4. Wait for confirmation before writing code

### 3. ALWAYS update STATUS.md AND README.md
After any feature/fix/refactor: update STATUS.md (check off build queue items `[x]`, mark partial `[~]`) AND update README.md ("What's Implemented" section, file tree if new files). Self-check: "Did I update BOTH files?"

### 4. Before context compaction — see top-of-file checklist

### 5. Read relevant docs before building features
Read specific doc file(s) before implementing. See `.claude/skills/architecture.md` for the doc index.

### 6. Protected files — do NOT edit unless explicitly asked
These files are project configuration, not development targets. Do NOT modify them during normal feature development:
- `CLAUDE.md`, `STATUS.md`, `.claude/skills/deploy.md`, `.claude/skills/architecture.md`
- Exception: STATUS.md build queue checkboxes (`[x]`/`[~]`) and README.md "What's Implemented" — those ARE updated per Rule #3.
- Exception: If the user explicitly asks to update a reference file.

### 7. Terminology
Hierarchy: **Partnership** (container) → **Cycle** (yearly run). Pathways belong to Cycles.
- **Partnership** = program-level container (groups Cycles for cross-cycle reporting). Stored in `hl_partnership`. Simple entity: name, code, description, status.
- **Cycle** = time-bounded run within a Partnership (the operational entity). Stored in `hl_cycle`. Has `cycle_type`: `program` (full B2E) or `course` (simple institutional access). Enrollments, teams, pathways, components all belong to a Cycle.
- **Learning Plan** = client-facing term for Pathway. Three plans: Teacher, Mentor, Leader.
- No `hl_cohort` table — removed in Grand Rename V3. Old `hl_cycle` (Phase entity) also deleted.

### Feature Tracker ("Tickets")
When the user says "tickets" or "issues" they mean the **Feature Tracker** — an internal ticket system built into HL Core (`[hl_feature_tracker]` shortcode). Admins and coaches submit bugs, improvements, and feature requests. DB tables: `hl_ticket`, `hl_ticket_comment`, `hl_ticket_attachment`. Service: `HL_Ticket_Service`. Frontend: `HL_Frontend_Feature_Tracker`. Spec: `docs/superpowers/specs/2026-04-06-feature-tracker-design.md`. To query tickets from the DB, use WP-CLI on the server: `wp db query "SELECT * FROM wp_hl_ticket"`.

### Naming (Post-Rename V3)
Code, DB, and UI all use the same terms now — no remapping layer needed.
- `HL_Label_Remap` has been removed from code. No remapping layer exists.
- `HL_JFB_Integration` still exists but is **legacy — pending full removal**. All forms are now built in PHP. Do not add new JFB references.

## Code Conventions
- **PHP 7.4+** with WordPress coding standards
- **Class prefix:** `HL_` — **Table prefix:** `hl_`
- **Singleton** main plugin class — **Repository pattern** for DB access — **Service layer** for logic
- **Custom capabilities** for auth (`manage_hl_core`) — **Audit logging** via `HL_Audit_Service`
- Leave `// TODO:` comments for incomplete features

## Plugin Dependencies
- **WordPress 6.0+**, **PHP 7.4+**, **LearnDash** (required)
- **BuddyBoss Theme + Platform** (optional, degrades gracefully)
- ~~JetFormBuilder~~ — legacy integration still loaded; pending full removal. Do not add new JFB code.

## Environment
Local files = editing workspace only (no PHP locally). Default target: **test server** (AWS Lightsail).
Claude Code launches from this directory (plugin root). GitHub: `Corsox-Tech/hl-core`, branch: `main`.

## On-Demand References
- **Deployment (ALWAYS use `bin/deploy.sh`):** `.claude/skills/deploy.md`
- **2026-04-20 rollback incident + guardrail design:** `docs/superpowers/plans/2026-04-20-email-registry-cleanup-progress.md` (full evidence log) + `docs/superpowers/plans/2026-04-20-email-registry-cleanup.md` (spec + architecture)
- **Domain architecture, roles, forms, control groups:** `.claude/skills/architecture.md`
- **Security posture, hardening, incident log, checklists:** `.claude/skills/security.md`
- **Full implementation details, architecture tree:** `README.md`
- **Doc file index:** in `.claude/skills/architecture.md` (top section)

## Deploy quick reference
```bash
# Deploy to test (AWS Lightsail):
bash bin/deploy.sh test

# Deploy to prod (Hostinger; requires explicit user approval per session):
bash bin/deploy.sh prod

# Check what's currently on a target:
ssh -p 65002 u665917738@145.223.76.150 'cat /home/u665917738/domains/academy.housmanlearning.com/public_html/wp-content/plugins/hl-core/.deploy-manifest.json'
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cat /opt/bitnami/wordpress/wp-content/plugins/hl-core/.deploy-manifest.json'
```

Running multiple Claude sessions in parallel is fine — the descendant check guarantees that a stale session cannot overwrite a newer one's work. If the script aborts with "NOT a descendant," **read the manifest** and investigate before using `--force`. The abort is almost always correct.
