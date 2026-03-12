# CLAUDE.md — HL Core Plugin

> **COMMIT CHECKLIST:** 1) Update README.md (build queue checkboxes + "What's Implemented") 2) Commit README.md alongside code 3) A task is NOT done until README.md is updated.
> **BEFORE CONTEXT COMPACTION:** Commit all code → update README.md with status (`[x]` done, `[~]` partial with notes) → commit & push → THEN compact.

## Project Overview
WordPress site for Housman Learning Academy. Primary target: **hl-core** custom plugin.
Products: **B2E Mastery Program** (2-year, 25-course), **Short Courses** (standalone), **ECSELent Adventures** (physical + online).
See `docs/B2E_MASTER_REFERENCE.md` for the complete product catalog.

**Key paths:**
- **README.md** — Living status tracker. Read FIRST every session.
- **docs/** — 11 canonical spec files.
- **LearnDash:** `../sfwd-lms/` — hooks/functions reference.
- **data/** — Private Excel files. Gitignored, never commit.

## Mandatory Workflow Rules

### 1. Always read README.md first
Read `README.md` at session start to understand what's built and pending.

### 2. How to continue between sessions
When user says "continue" / "keep going" / starts a new session: **DO NOT code immediately.**
1. Read README.md — check Build Queue for `[x]` done, `[~]` in-progress, `[ ]` pending
2. Report status: last completed, in-progress details, next tasks
3. Ask: "Should I continue with [specific task], or something else?"
4. Wait for confirmation before writing code

### 3. ALWAYS update README.md
After any feature/fix/refactor: update "What's Implemented", check off Build Queue items `[x]`, mark partial `[~]` with notes, update file tree if new files added. Self-check: "Did I update README.md?"

### 4. Before context compaction — see top-of-file checklist

### 5. Read relevant docs before building features
Read specific doc file(s) before implementing. See `.claude/skills/architecture.md` for the doc index.

### 6. Terminology
Three-level hierarchy: **Cohort** (optional container) → **Track** (full program engagement) → **Phase** (time period within Track). Pathways belong to Phases.
- **Track** = full program engagement (spans all Phases/years), NOT a single phase. Stored in `hl_track`.
- **Phase** = time period within a Track. Stored in `hl_phase`.
- **Cohort** = optional container grouping Tracks. Stored in `hl_cohort`.
- **Learning Plan** = client-facing term for Pathway. Three plans: Teacher, Mentor, Leader.
- **Track Type** = `program` (full B2E) or `course` (simple institutional access).

### UI Label Remapping
Code/DB uses internal terms; UI displays client-friendly labels via `HL_Label_Remap` gettext filter.

| Code / DB | UI Display | Why |
|---|---|---|
| `Track`, `track_id`, `hl_track` | **Partnership** | Client preference |
| `Activity`, `activity_id`, `hl_activity` | **Component** | Avoids confusion |

**NEVER rename PHP variables/classes/DB columns to match display labels.** Use internal terms in `__()` calls — the remap handles display. Full details: `.claude/skills/architecture.md`.

## Code Conventions
- **PHP 7.4+** with WordPress coding standards
- **Class prefix:** `HL_` — **Table prefix:** `hl_`
- **Singleton** main plugin class — **Repository pattern** for DB access — **Service layer** for logic
- **Custom capabilities** for auth (`manage_hl_core`) — **Audit logging** via `HL_Audit_Service`
- Leave `// TODO:` comments for incomplete features

## Plugin Dependencies
- **WordPress 6.0+**, **PHP 7.4+**, **LearnDash** (required)
- **JetFormBuilder** (observation forms only) — **BuddyBoss Theme + Platform** (optional, degrades gracefully)

## Environment
Local files = editing workspace only (no PHP locally). Default target: **test server** (AWS Lightsail).
Claude Code launches from this directory (plugin root). GitHub: `Corsox-Tech/hl-core`, branch: `main`.

## On-Demand References
- **Deployment, SSH, WP-CLI commands:** read `.claude/skills/deploy.md`
- **Domain architecture, roles, forms, control groups:** read `.claude/skills/architecture.md`
- **Build status, phases, what's next:** read `STATUS.md`
- **Doc file index:** in `.claude/skills/architecture.md` (top section)
