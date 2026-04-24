# CLAUDE.md — HL Core Plugin

> **WHEN WORK IS DONE — just ask:** *"Ready to deploy?"* On "yes" (or "ship it" / "deploy to prod"): bump patch version + commit + `bash bin/deploy.sh prod` + `bash bin/deploy.sh test` (both; neither server auto-pulls). **No other prep questions.** Pick sensible defaults silently.
>
> **When to also update STATUS.md / README.md:** only for **new planned features** (ticking a Build Queue item off, or adding a new section to README's "What's Implemented"). For follow-up fixes, tiny adjustments, bug fixes on already-shipped tickets — **skip both**. The git log + ticket branches + prod manifest SHA are the record; STATUS.md/README.md are for humans skimming high-level capability, not for every 3-line tweak.
>
> **BEFORE CONTEXT COMPACTION:** commit all code on the current ticket branch → push → THEN compact. (If the ticket is a major feature milestone, also update STATUS.md/README.md as above.)

## Project Overview
WordPress site for Housman Learning Academy. Primary target: **hl-core** custom plugin.
Products: **B2E Mastery Program** (2-year, 25-course), **Short Courses** (standalone), **ECSELent Adventures** (physical + online).
See `docs/B2E_MASTER_REFERENCE.md` for the complete product catalog.

**Key paths:**
- **WORKFLOW.md** — How we develop/ship this plugin (branches, worktrees, hooks, deploys). If you're confused about *how work happens* here, **read this first**. Self-contained; explains *why* we do it this way and *what* the user should do daily.
- **STATUS.md** — Build queue + task tracking. Read for high-level project state.
- **README.md** — What's Implemented detail, architecture tree.
- **docs/** — 11 canonical spec files.
- **LearnDash:** `../sfwd-lms/` — hooks/functions reference.
- **data/** — Private Excel files. Gitignored, never commit.

## Rule 0: Session Start Protocol (BEFORE anything else)

Every session. No exceptions. Before reading STATUS.md, before writing any code, before answering the user's first message:

1. Run `git branch --show-current` to find your branch.
2. Run `test -f .git && echo worktree || echo main-repo` to determine isolation state. (A worktree has `.git` as a file; the canonical main repo has `.git` as a directory.)
3. Route based on branch:

   **Branch `main`:**
   Your FIRST response must be: *"Which ticket are you working on today? (Or say 'no ticket' if you're exploring, reviewing, or just chatting.)"*
   - If user names a ticket (e.g., "ticket 34 csv-export"):
     - **If `main-repo`** (you're in the canonical plugin folder, e.g., CLI opened `claude` in the hl-core dir): offer to run `bash bin/start-ticket.sh <N> [slug]` (slug = short lowercase-dash name). After the script creates a sibling worktree, tell the user to `cd` into it and restart Claude there.
     - **If `worktree`** (Desktop auto-worktree via the "worktree" checkbox, or any other isolated worktree on `main`): run `git checkout -b ticket-<N>-<slug>` in place. Confirm: *"Switched to `ticket-<N>-<slug>`. Working on ticket <N> in this worktree."* Then proceed.
   - If user says "no ticket" / "just exploring" / etc. → read-only mode. Do NOT modify tracked files.

   **Branch matches `ticket-<N>[-<slug>]`:**
   - Your FIRST response must confirm: *"I see we're on `<branch-name>`. Continuing work on ticket `<N>`?"*
   - After confirmation, proceed with work.

   **Branch matches `claude/<adjective>-<noun>-<hex>`** (a Claude Code Desktop auto-generated session branch):
   - Your FIRST response must be: *"Desktop created a session branch (`<branch-name>`) for us. What are we working on? If this is a Feature Tracker ticket, tell me the number and I'll rename the branch to our `ticket-<N>-<slug>` convention so the normal cleanup flow works."*
   - If user names a ticket: run `git branch -m ticket-<N>-<slug>` to rename the branch in place. Then proceed.
   - If user says "quick fix, no ticket" or similar: accept the Desktop-generated name, proceed with work. Deploy.sh works normally from this branch. `bin/finish-ticket.sh` also accepts `claude/*` branches for cleanup.

   **Any other branch name:**
   - Unusual. Ask the user what they want to do before acting (could be legacy work-in-progress or an accidental branch).

4. Full rules for parallel sessions, commits, and deploys live in `.claude/skills/parallel-sessions.md` — read it any time the user says "commit and push", "deploy to prod", or a git hook rejects something.

This rule is non-negotiable even if the user says "just do X, don't ask." The orientation check takes 5 seconds and prevents the class of incident that nuked prod on 2026-04-20.

### Two entry points — pick what fits the context

- **Claude Code CLI** (terminal `claude` in the plugin folder): use `bash bin/start-ticket.sh <N> [slug]`. It creates a sibling folder worktree, branched from the live prod SHA, and prints the exact `cd` + `claude` command to run. This is the canonical flow.
- **Claude Code Desktop** (app with the "worktree" checkbox): pick branch `main` in the dropdown, keep the **worktree** box checked, and start the chat. Desktop auto-generates a session branch named `claude/<adjective>-<noun>-<hex>` and drops you into an isolated worktree on it. Rule 0 accepts this branch pattern and asks what you're working on; if it's a Feature Tracker ticket, Claude renames the branch to `ticket-<N>-<slug>` so the normal finish-ticket flow applies. Otherwise Claude works on the auto-branch directly. (If you want a pre-existing ticket branch instead, pick it from the dropdown before starting.)

## Mandatory Workflow Rules

### 0. Deploying — default: prod first, then test. One command each.

**When the user says "ready to deploy" / "ship it" / "deploy to prod" / "commit and deploy":**
1. If code changes aren't committed yet, bump the patch version (`HL_CORE_VERSION` in `hl-core.php`, e.g., `1.3.2 → 1.3.3`) and commit everything together. **Don't ask about version bumps for small fixes — just do a patch bump.** Ask only if the change is large enough to justify minor/major.
2. Run `bash bin/deploy.sh prod` from the ticket worktree.
3. On prod success, immediately run `bash bin/deploy.sh test` — both servers are manual-deploy (neither auto-pulls from GitHub), and test drift is what caused the 2026-04-20 incident.
4. Report: *"Prod + test both at SHA `<x>`, v<version>. Done."*

Skip test deploy only if the user explicitly says "prod only." Don't ask about it otherwise — deploy both by default.

**Do not write ad-hoc `tar ... && scp ... && ssh ... rm -rf hl-core` chains. Do not `git push origin main` manually — the pre-push hook will refuse.** The script enforces three guardrails that together prevent the class of incident that happened on **2026-04-20** (a parallel Claude session rolled back prod by deploying a stale branch):
> 1. **Tarball source = `git ls-files`.** Only committed files ship. Dev artifacts (`.playwright-mcp/`, `.superpowers/`, untracked debug files) cannot leak.
> 2. **Pre-deploy descendant check.** Reads `.deploy-manifest.json` on the target and aborts if local HEAD is not a descendant of the recorded SHA. Blocks stale-branch overwrites.
> 3. **Main-sync on prod deploy.** After a successful prod deploy, the script fast-forwards `origin/main` to the deployed SHA (using `HL_DEPLOY_PUSH=1` as the sanctioned pre-push-hook bypass). This keeps `main` == prod manifest SHA at all times, so the next ticket branch starts from a correct base.
>
> The pre-commit hook also refuses direct commits on `main`, and the pre-push hook refuses any manual push to `main`. Ticket branches are mandatory (see `bin/start-ticket.sh`).
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

### 3. STATUS.md / README.md — only for meaningful milestones
Update STATUS.md + README.md when:
- A **new planned feature** from the Build Queue is being ticked off (check `[x]` on STATUS.md, add a line to README's "What's Implemented").
- A **new capability, page, DB table, or major refactor** ships (README file tree may also need updating).

**Do NOT update STATUS.md or README.md for:**
- Small follow-up fixes or adjustments on already-shipped tickets (e.g., tweaking a rate limit, fixing a typo, relaxing a threshold).
- Bug fixes that don't introduce new capability.
- UX polish on existing features.

The git log + prod manifest SHA + ticket branches are the authoritative record. STATUS.md/README.md are a human-readable summary of *major* capability — keep them skimmable, not exhaustive.

If you're not sure whether a change qualifies: it probably doesn't. Ship the code, skip the docs update, ask the user only if they ask you to.

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
GitHub: `Corsox-Tech/hl-core`. Default/prod branch: `main` — `main` always equals the prod manifest SHA (enforced via `bin/deploy.sh` + hooks). Ticket work happens in sibling worktrees at `../hl-core-ticket-<N>[-<slug>]/` created by `bash bin/start-ticket.sh`. The main worktree at `...\hl-core` only ever holds the `main` branch — never ticket work.

## On-Demand References
- **Parallel sessions / ticket worktrees / commit + deploy rules:** `.claude/skills/parallel-sessions.md`
- **Deployment (ALWAYS use `bin/deploy.sh`):** `.claude/skills/deploy.md`
- **2026-04-20 rollback incident + guardrail design:** `docs/superpowers/plans/2026-04-20-email-registry-cleanup-progress.md` (full evidence log) + `docs/superpowers/plans/2026-04-20-email-registry-cleanup.md` (spec + architecture)
- **Workflow design spec (this model):** `docs/superpowers/specs/2026-04-23-parallel-sessions-workflow-design.md`
- **Domain architecture, roles, forms, control groups:** `.claude/skills/architecture.md`
- **Security posture, hardening, incident log, checklists:** `.claude/skills/security.md`
- **Full implementation details, architecture tree:** `README.md`
- **Doc file index:** in `.claude/skills/architecture.md` (top section)

## Workflow quick reference
```bash
# Start a new ticket (creates a sibling worktree, branched from live prod SHA):
bash bin/start-ticket.sh 34 csv-export
# Then: cd ../hl-core-ticket-34-csv-export && claude

# Deploy to test (AWS Lightsail) — from inside the ticket worktree:
bash bin/deploy.sh test

# Deploy to prod (Hostinger) — from inside the ticket worktree:
bash bin/deploy.sh prod
# This also fast-forwards origin/main so it tracks prod.

# Finish a ticket after prod is green — removes worktree + deletes branch:
bash bin/finish-ticket.sh

# Check what's currently on a target:
ssh -p 65002 u665917738@145.223.76.150 'cat /home/u665917738/domains/academy.housmanlearning.com/public_html/wp-content/plugins/hl-core/.deploy-manifest.json'
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cat /opt/bitnami/wordpress/wp-content/plugins/hl-core/.deploy-manifest.json'
```

Multiple Claude sessions in parallel is safe BY CONSTRUCTION under this model: each ticket lives in its own worktree folder with its own branch, so `git checkout` in session A cannot mutate files session B is editing. The pre-commit/pre-push hooks + deploy-time descendant check catch anything that slips past orientation. If any hook or `bin/deploy.sh` aborts, **read the error** and fix the root cause — never `--force` or `--no-verify` without explicit user approval for that specific action.
