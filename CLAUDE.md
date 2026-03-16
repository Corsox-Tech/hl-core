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
Hierarchy: **Partnership** (full program engagement) → **Cycle** (time period within Partnership). Pathways belong to Cycles.
- **Partnership** = full program engagement (spans all Cycles/years). Stored in `hl_partnership`. Has `partnership_type`: `program` (full B2E) or `course` (simple institutional access).
- **Cycle** = time period within a Partnership. Stored in `hl_cycle`.
- **Cohort** = optional container grouping Partnerships. Stored in `hl_cohort`. Hidden from all UI — retained in DB only.
- **Learning Plan** = client-facing term for Pathway. Three plans: Teacher, Mentor, Leader.

### Naming (Post-Rename V2)
Code, DB, and UI all use the same terms now — no remapping layer needed.
- `HL_Label_Remap` still exists in code but is **legacy — pending removal**. Do not add new remap entries.
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
- **Deployment, SSH, WP-CLI commands:** read `.claude/skills/deploy.md`
- **Domain architecture, roles, forms, control groups:** read `.claude/skills/architecture.md`
- **Full implementation details, architecture tree:** read `README.md`
- **Doc file index:** in `.claude/skills/architecture.md` (top section)
