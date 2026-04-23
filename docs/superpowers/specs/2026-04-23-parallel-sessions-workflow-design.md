# Parallel Sessions & Ticket-First Workflow — Design Spec

> **Date:** 2026-04-23
> **Author:** Mateo (with multi-agent review: main Claude + expert agent)
> **Status:** Spec — pending approval
> **Supersedes:** Draft `.claude/skills/parallel-sessions.md` on branch `docs/parallel-sessions-rules` (never merged)

---

## 1. Why this exists

### The operator
Mateo, CEO of Corsox, solo non-developer operator. Runs up to 8 Claude Code sessions in parallel against this repo while tasks pile up from the Housman Learning client. Deploys directly to prod because the LMS has few users and most requests are urgent.

### What went wrong
On **2026-04-20**, a parallel Claude session deployed a stale branch (`fix/tickets-8-10-on-prod`, based on an old `main`) over the live prod state (which had 30+ commits from `feature/workflow-ux-m1`). Result: workflow builder, email registry, course surveys, and ticket QA features **disappeared from prod for users**. Recovered by hand. Full evidence log: `docs/superpowers/plans/2026-04-20-email-registry-cleanup-progress.md`.

`bin/deploy.sh` was built in response, with a descendant check that reads the target's `.deploy-manifest.json` and refuses to overwrite it with a non-descendant SHA. That prevents the exact incident from repeating. But the *upstream* cause — sessions branching from a stale `main` and stepping on each other in a shared working directory — is still present and still produces confusion, lost work, and merge pain day-to-day.

### Root cause (sharpened by expert-agent review)
The real bug is **"`main` is a lie, and every session believes the lie."** At time of spec:
- `main` is 220 commits behind the actual prod state.
- `feature/workflow-ux-m1` is the *de facto* main (it's what's on prod).
- Every new Claude session reads CLAUDE.md's "branch: `main`" line, runs `git checkout main`, and builds on a base that has been wrong for weeks.

The shared working directory amplifies the damage (one `git checkout` mutates files visible to all 8 sessions), but the base lie is upstream of the amplifier.

---

## 2. Goals

1. **`main` tells the truth.** `main` on GitHub = what is on prod, always.
2. **Every session starts on a correct base.** A session branching from `main` must produce code safe to ship.
3. **Real parallelism.** 6+ Claude sessions can work on 6+ tickets concurrently without stepping on each other.
4. **Enforcement in hooks, not prose.** When the user says "just ship it," the repo refuses unsafe actions even if Claude tries to comply.
5. **Minimal cognitive load.** One new command for the user to learn (to open a ticket workspace). Everything else is automated or unchanged.
6. **Idiot-proof by default.** Future Claude sessions orient themselves at session start — they ask which ticket, verify they're in the right worktree, and route deploys through the safe script.

---

## 3. Architectural choices (with alternatives considered)

### 3.1 Isolation: git worktrees
**Chosen:** Each ticket gets its own **git worktree** in a sibling folder: `../hl-core-ticket-N-slug`. The main worktree at `hl-core/` holds only the `main` branch.

- **Alternatives considered:**
  - *Full clones* — simpler concept but slow setup (30s/new ticket), disk-heavy, and requires remembering to `git fetch` in each. Expert agent recommended this; rejected after user pushback on setup speed.
  - *Same directory, many sessions* — status quo. Catastrophic. Rejected.
- **Why worktrees win:** ~2-second setup per ticket, shared `.git` (one `fetch` updates all), shared hook templates, no Windows path-length issues (sibling dirs, not nested).

### 3.2 Branching: trunk-based, short-lived ticket branches
**Chosen:** One long-lived branch, `main` = prod. Every ticket gets a short-lived `ticket-N-slug` branch, created from current prod SHA, merged back into `main` via `bin/deploy.sh prod`.

- **Alternatives considered:**
  - *GitFlow-style (main + develop + feature/*)* — overkill for a solo operator.
  - *Keep `feature/workflow-ux-m1` as de facto main, rename to `prod`* (expert-agent suggestion) — rejected; better to rename the truth back to `main` so Claude's default instincts are correct with zero retraining.
- **Why trunk-based wins:** One source of truth, no zombie branches, Claude's default instincts produce correct behavior.

### 3.3 Code review: PRs become optional
**Chosen:** PRs are not on the default path. You may open a PR for work you want to archive/re-read (major refactors, schema changes), but ship-to-prod goes straight through `bin/deploy.sh prod`.

- **Why:** You're the only committer. PR #21 proved PRs function as shipping receipts, not reviews. The memory system + `docs/superpowers/plans/` archive role better than stale open PRs do.

### 3.4 Enforcement: git hooks
**Chosen:** Pre-commit + pre-push hooks + existing deploy-time descendant check.

- **Why not CLAUDE.md prose alone:** When you say "just ship it," Claude rationalizes around prose rules. Hooks cannot rationalize.

### 3.5 Session orientation: first-response routing via CLAUDE.md
**Chosen:** CLAUDE.md instructs every Claude session, in its first response, to check its working directory, detect which worktree it's in, and either confirm the active ticket or ask which ticket to work on.

- **Alternatives considered:**
  - *SessionStart hook that auto-runs* — fragile, platform-dependent, swallows output.
  - *No orientation (status quo)* — relies on the user to always `cd` into the right folder before launching Claude; too easy to forget.
- **Why orientation wins:** Zero new machinery, 100% coverage (CLAUDE.md is loaded on every session start), graceful — if user says "no ticket, just reviewing," the session proceeds in read-only mode.

### 3.6 Dropped from prior design
- ❌ "Keep `hl-core.php` / `STATUS.md` / `README.md` out of commits until the final commit." Fiddly ritual that breaks under pressure. One-ticket-per-worktree means no two sessions edit those files simultaneously, so the problem disappears.
- ❌ `.active-sessions.json` PID registry. Unreliable on Windows; solved by worktrees existing.

---

## 4. Components

### 4.1 Cleanup (one-time, destructive)

**Step order matters. Each step is atomic — stop and verify before the next.**

1. **Confirm prod SHA.** Read `.deploy-manifest.json` from the live prod server. Expected: top of `feature/workflow-ux-m1`.
2. **Reset `main` to prod SHA.** Locally: `git checkout main && git reset --hard <prod-sha>`. Then force-push: `git push origin main --force-with-lease`.
3. **Verify `main` == prod.** Compare tip SHA of `origin/main` to server manifest SHA.
4. **Close PR #21** with comment: "merged via direct integration into workflow-ux-m1 (now main) — see SHA 5d1d53f. Closing as stale."
5. **Delete stale local branches.** Candidates: `feature/course-survey-builder`, `feature/email-registry-cleanup`, `feature/email-v2-feedback-fixes`, `feature/email-v2-track1-admin-ux`, `feature/email-v2-track2-builder`, `feature/email-v2-track3-backend`, `feature/feature-tracker-ux-improvements`, `feature/ticket-18-continuing-pathways`, `feature/ticket-31-coach-zoom-settings`, `feature/ticket-admin-cancel`, `feature/workflow-ux-m1`, `fix/ticket-29-isolated`, `fix/ticket-9-isolated`, `fix/ticket-admin-cancel-isolated`, `fix/tickets-8-10-isolated`, `fix/tickets-8-10-on-prod`, `reconcile/workflow-ux-m1`, `docs/parallel-sessions-rules`. All either shipped, merged, or superseded.
6. **Delete stale remote branches.** Same list, minus any not on origin.
7. **Update GitHub default branch.** Confirm it's `main` (not `workflow-ux-m1`).

### 4.2 New scripts (`bin/`)

All Unix shell (`bash`), runnable on Windows via Git Bash — same as existing `bin/deploy.sh`.

#### `bin/start-ticket.sh <ticket-number> [slug]`
- Fetches origin.
- Reads prod manifest SHA from the live server (same SSH call as `deploy.sh`).
- Creates worktree at `../hl-core-ticket-N-slug/` on branch `ticket-N-slug` based on prod SHA.
- Installs hooks into the new worktree's `.git/hooks/` (via `bin/install-hooks.sh`).
- If worktree already exists, prints its path and instructs user to `cd` there.
- Prints: `Ready. Run: cd ../hl-core-ticket-N-slug && claude`

#### `bin/finish-ticket.sh`
- Must be run from inside a ticket worktree after deploy.
- Verifies the ticket's commits are present in `origin/main` (i.e., the deploy landed).
- Asks user to confirm deletion.
- `cd ../hl-core && git worktree remove ../hl-core-ticket-N-slug && git branch -d ticket-N-slug && git push origin --delete ticket-N-slug`.

#### `bin/install-hooks.sh`
- Copies `bin/hooks/pre-commit` and `bin/hooks/pre-push` into every worktree's `.git/hooks/` directory.
- Run once after pulling this change; re-run after new worktree creation (called from `start-ticket.sh`).

### 4.3 New git hooks (`bin/hooks/`)

#### `pre-commit`
- If current branch is `main` (check via `git symbolic-ref --short HEAD`):
  - Block the commit.
  - Print: `Refused: direct commits to main are not allowed. Run 'bin/start-ticket.sh <N>' to start work on a ticket branch.`
  - Exit 1.
- Otherwise, allow.

#### `pre-push`
- Only runs when pushing `main`:
  - Fetch the target's prod manifest SHA (via SSH as in `deploy.sh`).
  - If local `main` tip is not a descendant of prod SHA → block, print error, exit 1.
  - If local `main` tip is fast-forward from prod SHA → allow.
- All other branch pushes: allow.

### 4.4 New skill file `.claude/skills/parallel-sessions.md`

~30 lines. Replaces the 93-line draft. Contents:
- One-ticket-per-worktree rule.
- How to start a ticket (`bin/start-ticket.sh`).
- How to finish a ticket (`bin/finish-ticket.sh` after `bin/deploy.sh prod`).
- How to interpret "commit and push to prod" — always via `bin/deploy.sh prod` from inside the ticket worktree.
- When hooks reject — read the message, don't `--force`.

### 4.5 CLAUDE.md changes

**Add new top-of-file rule (before any other rule):**

```markdown
## Rule 0: Session orientation (FIRST ACTION of every session)

Before responding to the user's first message, run `git worktree list` and `pwd` (or check your cwd). Then:

- **If cwd is the main worktree (ends in `hl-core`)**: Your first response must ask: "Which ticket are you working on today? (Or say 'no ticket' if you're just exploring, reviewing, or doing docs work.)"
  - If user names a ticket (e.g., "ticket 34"): check `git worktree list` for a matching worktree.
    - Exists → tell user to `cd` there and restart Claude. Do NOT proceed in main worktree.
    - Doesn't exist → offer to run `bash bin/start-ticket.sh 34 <slug>`. After creation, tell user to `cd` and restart Claude.
  - If user says "no ticket": proceed in read-only mode. Do NOT write to tracked files.
- **If cwd is a ticket worktree (ends in `hl-core-ticket-N-slug`)**: Your first response must confirm: "I see we're in the ticket-N worktree. Continuing work on ticket N?"

Deviation from this rule is not allowed even if the user says "just do X" — ask first, then do.
```

**Update existing rules:**
- Replace "branch: `main`" stale note with accurate current state.
- Replace Rule 1 ("Always read STATUS.md first") with: "Always orient first (Rule 0), then read STATUS.md."
- Add: "When user says 'deploy to prod' or 'commit, push, deploy' — always via `bash bin/deploy.sh prod` from inside the ticket worktree. Never `git push origin main` directly."

### 4.6 Memory files

- Update `project_deploy_guardrails_2026_04.md` to note the new hook layer.
- New: `project_parallel_sessions_2026_04_23.md` — summarizes the new model so future sessions' memory loads the shape.

---

## 5. Flows (illustrated)

### 5.1 Starting a new ticket (common path)

```
User opens terminal in hl-core\
User: bash bin/start-ticket.sh 34 csv-export
Script: fetches, reads prod SHA, creates worktree at ../hl-core-ticket-34-csv-export
Script: "Ready. Run: cd ../hl-core-ticket-34-csv-export && claude"
User: cd ../hl-core-ticket-34-csv-export && claude
Claude: "I see we're in the ticket-34-csv-export worktree. Continuing work on ticket 34?"
User: "yes, let's add CSV export to the admin dashboard"
Claude: proceeds.
```

### 5.2 Starting work (forgot the script)

```
User opens terminal in hl-core\ (main worktree)
User: claude
User: "let's work on ticket 34"
Claude: checks git worktree list. No hl-core-ticket-34-* worktree exists.
Claude: "Ticket 34 needs its own worktree. I'll create one. Run:
        bash bin/start-ticket.sh 34 csv-export
        Then cd ../hl-core-ticket-34-csv-export && claude to continue."
(Claude does NOT write code in main worktree.)
```

### 5.3 Finishing a ticket

```
Inside ../hl-core-ticket-34-csv-export with all work committed
User: "deploy to prod and we're done"
Claude: bash bin/deploy.sh prod
  → deploy.sh pushes branch, merges to main, deploys, updates server manifest
Claude: "Shipped ticket 34 to prod @ v1.3.2 (SHA <sha>). Ready to clean up the worktree?"
User: "yes"
Claude: bash bin/finish-ticket.sh
  → verifies commits are in origin/main
  → removes worktree, deletes branch local + remote
Claude: "Ticket 34 worktree removed. main is clean."
```

### 5.4 Hook refuses a bad action

```
Claude (by mistake): git commit on main in main worktree
pre-commit hook: refuses, prints "Refused: direct commits to main..."
Claude: reads error, explains to user, proposes bin/start-ticket.sh instead
```

### 5.5 Non-ticket work (exploring, reviewing, chatting)

```
User: claude (in hl-core\)
Claude: "Which ticket are you working on today?"
User: "no ticket, just reviewing what shipped this week"
Claude: proceeds in read-only mode — reads files, runs queries, summarizes.
        Does NOT write to tracked files.
```

---

## 6. Error handling / edge cases

- **Ticket number collision** (user says "ticket 34" but 34 doesn't exist in the Feature Tracker): Claude accepts the number as-is; the worktree is named from the number + user-supplied slug. Verification against Feature Tracker DB is optional, not required.
- **Worktree already exists but on a different branch**: `start-ticket.sh` refuses, prints the existing path, user investigates manually.
- **Orphan worktree references** (folder deleted but `git worktree list` still lists it): `start-ticket.sh` runs `git worktree prune` before creating.
- **Pre-push hook fails** (local `main` not a fast-forward from prod SHA): means someone else (another session) shipped while you were working. Rebase your ticket branch onto `origin/main` and re-deploy.
- **Deploy.sh descendant check fails** (same cause as above, caught at deploy time): same rebase recovery.
- **User runs `claude` in a ticket worktree but for a different ticket**: Claude's orientation response catches the mismatch and asks for clarification.
- **Hooks not installed in a new worktree**: `start-ticket.sh` always installs; if user creates a worktree manually, `bin/install-hooks.sh` is idempotent and re-installable.

---

## 7. Testing plan

- [ ] Manual: run `bin/start-ticket.sh 99 smoke-test` in a clean main worktree. Verify worktree created, hooks installed, branch based on prod SHA.
- [ ] Manual: inside new worktree, try `git commit` on `main` (won't be on main, but check hook fires if forced). Verify pre-commit refuses direct commit on main.
- [ ] Manual: try to `git push origin main` from a non-fast-forward state. Verify pre-push refuses.
- [ ] Manual: `bin/deploy.sh test` from inside ticket worktree. Verify deploy succeeds.
- [ ] Manual: `bin/finish-ticket.sh`. Verify worktree + branch removed.
- [ ] Manual: Claude session orientation — launch Claude in main worktree, verify first response asks ticket question. Launch in ticket worktree, verify first response confirms active ticket.
- [ ] Manual: try to trick Claude ("just commit on main directly"). Verify Claude refuses based on Rule 0.

---

## 8. Rollout order

1. Write scripts + hooks on the current branch (`feature/ticket-31-coach-zoom-settings`) — they're additive, no risk.
2. Test scripts on a throwaway ticket number.
3. Install hooks in current worktree, verify they fire correctly.
4. Update CLAUDE.md + new skill file + memory files.
5. Commit everything.
6. Then do the destructive cleanup (reset main, delete branches, close PR #21).
7. Merge current branch into new `main` (or reset `main` to include these commits — whichever path preserves the work).

**Order matters because** the cleanup step 6 is irreversible-ish (reflog-recoverable for 90 days). Doing it last means if anything is wrong with the new scripts, we haven't torched the old branch structure yet.

---

## 9. What we're explicitly NOT doing

- Not introducing CI. Single operator, prod is the test environment.
- Not introducing PR requirements or reviewers. Solo dev.
- Not migrating to `prod` as the branch name. Keeping `main` for convention + reduced Claude retraining.
- Not tracking active sessions via a file (`.active-sessions.json`). Worktrees provide the isolation that file was trying to emulate.
- Not auto-running session orientation via a hook. Prose rule + CLAUDE.md priority is sufficient and fails gracefully.
- Not bumping `HL_CORE_VERSION` or updating `STATUS.md`/`README.md` as part of this change. The new model eliminates those merge-conflict hot spots by design.

---

## 10. Success criteria

- Zero future incidents where a Claude session overwrites another's prod work.
- New ticket start → first commit takes ≤ 1 minute.
- When user says "commit and push to prod" on the wrong branch or in the wrong worktree, hooks refuse and Claude re-routes gracefully.
- `main` tip always matches `.deploy-manifest.json` on prod within one deploy cycle.
- Future Claude sessions, given only CLAUDE.md + skill file, follow the workflow without drift even under "just ship it" pressure from user.
