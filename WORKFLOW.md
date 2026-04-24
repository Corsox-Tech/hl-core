# WORKFLOW.md — HL Core Development Workflow

> **Purpose of this file:** the single reference that explains how the HL Core plugin is developed, why the current workflow exists, and what Mateo should do every time he starts a task. If you're a future Claude session and there's confusion about branches, worktrees, deploys, or session collisions — **read this first**.
>
> **Established:** 2026-04-23 • **Owner:** Mateo (Corsox CEO, solo operator) • **Context conversation:** "HLA Fix Github Conflicts" — designed via multi-agent review (main Claude + expert agent), dogfooded through 5+ live deploys during the setup session.

---

## 1. Why this exists (the problem we solved)

### 1.1 The incident

On **2026-04-20**, one parallel Claude session deployed branch `fix/tickets-8-10-on-prod` (branched from stale `main`) directly over prod. Prod at that moment was running **30+ commits of work from `feature/workflow-ux-m1`** — the email workflow builder, M2 cascading triggers, Course Surveys, Ticket QA, and the D-1 email notification. Shipping the stale branch *deleted all of those features from production* (they were no longer in the tarball). Recovery took hours of investigation and manual work. Full evidence log: `docs/superpowers/plans/2026-04-20-email-registry-cleanup-progress.md`.

### 1.2 The root cause (sharpened over two rounds of review)

Early framing: "parallel sessions share a working directory and step on each other." True, but not the root. The deeper problem — identified by the expert-agent review — was:

> **`main` on GitHub was lying about what was on prod, and every new Claude session inherited the lie.**

Specifically: `main` was 220 commits behind the actual prod state (`feature/workflow-ux-m1`). Every time a session ran `git checkout main` to start a ticket, it branched from 220-commit-stale code. The shared working directory amplified the damage, but the upstream bug was base-selection, not file-system sharing.

### 1.3 The operational reality this has to fit

- **Solo operator.** No code review happens — no PR reviewers, no team. Mateo is the only committer.
- **Parallel sessions.** 6–8 Claude sessions often open simultaneously in different terminals or Desktop tabs, working on different tickets.
- **Ship-to-prod fast.** The LMS has few users and most client requests are urgent — test/QA cycles aren't always affordable. Deploys go straight to prod.
- **Non-developer operator.** Mateo is a CEO with some software background. Workflow must be idiot-proof — rules that hold even when he says "just ship it."
- **Windows host** (Git Bash + PowerShell).

The workflow had to satisfy all of the above without adding ceremony that would erode under time pressure.

---

## 2. What we decided to implement

### 2.1 The core invariant

> **`origin/main` == prod's `.deploy-manifest.json` SHA at all times.**

This is enforced by construction, not convention. If this invariant holds, no session can branch from a stale base, and a deploy cannot accidentally roll back work.

### 2.2 The three pillars

**Pillar A — One ticket = one isolated worktree**
Every ticket lives in its own git worktree (a sibling folder or a `.claude/worktrees/` folder, depending on entry point). No two sessions share a working tree. `git checkout` in session A cannot mutate files session B is editing.

**Pillar B — Git hooks refuse unsafe actions**
- `bin/hooks/pre-commit` refuses commits on `main`. Forces ticket branches.
- `bin/hooks/pre-push` refuses manual pushes to `main`. Only `bin/deploy.sh` may update main (via `HL_DEPLOY_PUSH=1` env var).
- Hooks are wired via `core.hooksPath = bin/hooks` in the repo config. Every worktree inherits them automatically.

**Pillar C — Deploy script syncs main**
`bin/deploy.sh prod` does three things atomically:
1. Verifies local HEAD is a descendant of the current prod manifest SHA (refuses otherwise).
2. Ships tracked files to the prod server and updates `.deploy-manifest.json` there.
3. Fast-forwards `origin/main` to the deployed SHA. This keeps `main` == prod manifest SHA at all times, upholding the core invariant.

### 2.3 Files created/modified

| Path | Role |
|---|---|
| `CLAUDE.md` | Rule 0 (Session Start Protocol) routes every Claude session. Top-of-file checklist defines "ready to deploy" behavior. |
| `.claude/skills/parallel-sessions.md` | ~60-line skill loaded by Claude sessions. Details the branch/mode detection and deploy flow. |
| `bin/start-ticket.sh` | Creates a sibling worktree at `../hl-core-ticket-<N>-<slug>/` branched from the live prod SHA (read via SSH). CLI entry point. |
| `bin/finish-ticket.sh` | Verifies ticket's commits landed on origin/main, then removes the worktree + deletes the branch (local + remote). Accepts `ticket-*` and `claude/*` branches. |
| `bin/install-hooks.sh` | Sets `core.hooksPath = bin/hooks`. Run once; all current + future worktrees inherit. |
| `bin/hooks/pre-commit` | Refuses direct commits on `main`. |
| `bin/hooks/pre-push` | Refuses manual pushes to `main` unless `HL_DEPLOY_PUSH=1`. |
| `bin/deploy.sh` | Enhanced with main-sync step on prod deploys. |
| `docs/superpowers/specs/2026-04-23-parallel-sessions-workflow-design.md` | Full design spec with alternatives considered, rejected approaches, edge cases. |
| `WORKFLOW.md` (this file) | Single-entry reference. Read first when confused. |

### 2.4 Key decisions and why

| Decision | Why | Rejected alternatives |
|---|---|---|
| Keep branch name `main` (not rename to `prod`) | Matches Claude's default instincts; every session's "branch from main" becomes correct with zero retraining. | Renaming `feature/workflow-ux-m1` to `prod` — would require retraining every Claude session and GitHub default-branch changes. |
| Enforcement in git hooks, not prose | When user says "just ship it," Claude rationalizes around prose rules. Hooks cannot rationalize. | Relying on CLAUDE.md alone — prior attempts (`docs/parallel-sessions-rules` branch, 93-line draft) were never merged and never governed anything. |
| Trunk-based (no long-lived feature branches) | Solo operator; no review overhead; one source of truth. | GitFlow — unnecessary for one committer. |
| PRs optional, not required | User's PRs were shipping receipts, not reviews. PR #21 proved PRs go stale. Memory + `docs/` archive better than stale open PRs. | PRs-required — adds friction with no review value for a solo operator. |
| Three worktree entry points are OK | CLI `bin/start-ticket.sh`, Claude Code Desktop `worktree ✓` checkbox, and `claude --worktree NAME` all produce safe isolation. Pick by preference. | Forcing a single entry point — would conflict with Desktop's native worktree support. |
| Small fixes skip STATUS.md/README.md | Over-updating those files turned every 3-line tweak into ceremony. Git log + prod manifest SHA are the authoritative record; STATUS.md is for major milestones only. | Mandating docs updates on every commit — user rejected after seeing Claude add a paragraph for a rate-limit bump. |
| Deploy to **both** prod and test by default | Neither server auto-pulls from GitHub. Test drift was a contributor to the 2026-04-20 incident. Deploying both keeps them in lockstep. | Prod-only deploys — test diverges silently. |

### 2.5 One-time cleanup done on 2026-04-23

- Reset `main` from stale `f8043a6` to prod tip `06317fa`, cherry-picked the guardrails commit on top → `bba9d63`.
- Bootstrap-deployed `bba9d63` via ephemeral `setup-bootstrap` worktree so prod manifest == main. One-time exception to the "no deploy from main" rule; documented in the memory file.
- Deleted 16 stale local + remote branches (email-v2-track1/2/3, ticket-18, ticket-31, email-registry-cleanup, feature/workflow-ux-m1, reconcile/workflow-ux-m1, etc.).
- Closed PR #21 (auto-closed when its branch was deleted).
- Two branches **kept** for user decision (may still be live): `feature/feature-tracker-ux-improvements` (local) and `fix/ticket-admin-cancel-isolated` (local + remote).

---

## 3. What Mateo should do every time he works on a task

### 3.1 Starting a task — pick ONE of these three entry points

All three produce a safe, isolated worktree. They differ only in ergonomics.

**Option A — Claude Code Desktop (easiest, recommended)**
1. Open Claude Code Desktop pointed at `hl-core`.
2. Branch dropdown: pick **`main`**. Keep the **`worktree`** checkbox **checked**.
3. Start the chat. First message: *"Working on ticket `<N>`, `<short description>`."* Or, if it's not a Feature Tracker ticket: *"Quick fix — `<description>`."*
4. Claude detects the Desktop-generated branch (`claude/<adj>-<noun>-<hex>`) and Rule 0 kicks in — either renames it to `ticket-<N>-<slug>` (if a ticket number was named) or accepts the auto-name and proceeds.

**Option B — CLI `claude --worktree NAME` (one command)**
1. In a terminal inside the `hl-core` folder:
   ```
   claude -w ticket-27-email-adjust
   ```
   (or any descriptive name). Claude launches in a fresh worktree at `.claude/worktrees/<name>/`, branched from `main`.
2. First message: *"Working on ticket 27, email adjust."*

**Option C — CLI `bin/start-ticket.sh` (legacy entry, same effect)**
1. In a terminal inside `hl-core`:
   ```
   bash bin/start-ticket.sh 27 email-adjust
   ```
2. Script prints `cd ../hl-core-ticket-27-email-adjust && claude` — run both.
3. Claude starts on `ticket-27-email-adjust`; Rule 0 confirms and proceeds.

Worktree location differs (`.claude/worktrees/` for A/B, sibling folder for C). Both are safe. Claude-handled cleanup (`bin/finish-ticket.sh`) handles all three branch patterns.

### 3.2 Shipping a task

Once the work is done and committed on the ticket branch:

1. Say to Claude: **"ready to deploy"** (or "ship it" / "commit and deploy to prod").
2. Claude will:
   - Bump the patch version silently (`HL_CORE_VERSION` in `hl-core.php`) — won't ask.
   - Commit any outstanding changes.
   - Run `bash bin/deploy.sh prod` (server update + main sync).
   - Run `bash bin/deploy.sh test` (keep test in lockstep).
   - Report one line: *"Prod + test both at SHA `<x>`, v`<y>`. Done."*
3. Want to cleanup? Say **"finish the ticket"** → Claude runs `bash bin/finish-ticket.sh` (removes worktree, deletes branch local + remote).

Claude should NOT ask about version bumps for small fixes, should NOT ask about deploy target (always both), and should NOT add STATUS.md/README.md entries for follow-up fixes. If Claude does any of that, remind it to read `WORKFLOW.md` section 3.2.

### 3.3 When Claude pauses with "Which ticket are you working on?"

That's Rule 0 (Session Start Protocol) doing its job. It fires when:
- You opened Claude on `main` in the main `hl-core` folder (not a worktree).
- Claude wants to know whether to route your request into a ticket worktree or stay in read-only mode.

Three valid answers:
- **"Ticket `<N>`, `<description>`"** → Claude creates (or routes to) a ticket worktree.
- **"No ticket, just exploring"** / **"Just reviewing"** → Claude stays read-only; won't modify tracked files.
- **"Quick fix, `<what>`"** → Claude offers to spin up a worktree for it; you can also name it a maintenance ticket like `999`.

### 3.4 Parallel work

Run any combination of entry points across terminals/tabs. Each session is in its own worktree; they cannot collide. The hooks + deploy's descendant check catch any case where they might otherwise step on each other — if a deploy aborts with "not a descendant," another session shipped first; rebase your ticket onto the new `origin/main` and redeploy.

### 3.5 What NOT to do

- **Don't `git checkout main` and commit there.** Pre-commit hook will refuse.
- **Don't `git push origin main` manually.** Pre-push hook will refuse.
- **Don't write ad-hoc `tar … && scp … && ssh …` deploy chains.** Use `bin/deploy.sh`.
- **Don't bypass hooks** with `--no-verify` or `--force` unless explicitly authorized for that specific action.
- **Don't update STATUS.md/README.md for small fixes.** Only for new planned features.

---

## 4. When to revisit this design

Signals that the workflow needs updating:

- **Team grows beyond solo operator.** PRs + required reviews become valuable again; trunk-based-without-review doesn't scale to multiple committers without accidents.
- **Test environment becomes meaningful.** If real QA happens on test (beyond "look at it briefly"), the "deploy both" default may need to become "deploy test first, await QA, then prod."
- **CI/CD is introduced.** GitHub Actions or similar would take over the main-sync step and make some hooks redundant.
- **Ticket volume drops to < 1/week.** The worktree ceremony might be overkill; a simpler main-with-branches model could work.
- **Recurring deploy aborts** on the descendant check — suggests pre-push hook needs tightening to catch the problem upstream.

If you're revisiting: start with `docs/superpowers/specs/2026-04-23-parallel-sessions-workflow-design.md` for the full design rationale, then this file, then `CLAUDE.md` for the enforced rules.

---

## 5. Quick reference

```bash
# Start a ticket (CLI, pick one)
bash bin/start-ticket.sh 27 email-adjust     # sibling-folder worktree
claude -w ticket-27-email-adjust             # .claude/worktrees worktree, launches Claude

# Ship a ticket (from inside the ticket worktree)
# Say to Claude: "ready to deploy" or "ship it"
# Claude runs:
bash bin/deploy.sh prod
bash bin/deploy.sh test

# Clean up a ticket
bash bin/finish-ticket.sh   # or tell Claude "finish the ticket"

# Check what's on a server
ssh -p 65002 u665917738@145.223.76.150 'cat /home/u665917738/domains/academy.housmanlearning.com/public_html/wp-content/plugins/hl-core/.deploy-manifest.json'
ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cat /opt/bitnami/wordpress/wp-content/plugins/hl-core/.deploy-manifest.json'

# If a hook blocks you — read the error. DO NOT --force or --no-verify without explicit authorization.
```

## 6. Contact and context

- **Primary operator:** Mateo Gonzalez (`mateo@corsox.com`)
- **Client:** Housman Learning Academy (`academy.housmanlearning.com`) — Yuyan Huang is the product contact
- **Repo:** `github.com/Corsox-Tech/hl-core`
- **Prod server:** Hostinger (`145.223.76.150`), SSH port `65002`, user `u665917738`
- **Test server:** AWS Lightsail (`44.221.6.201`), user `bitnami`, key `~/.ssh/hla-test-keypair.pem`

If this file is out of date vs. actual behavior, trust what the scripts and hooks do (they're the enforcement) and update this file.
