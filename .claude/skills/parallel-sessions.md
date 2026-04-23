# Parallel-Safe Workflow

> **When to read:** (1) starting any new task, (2) user says "commit and push" or "deploy to prod", (3) a git hook rejects an action.

## The model in one paragraph

`main` on GitHub always equals what's on prod. Every ticket lives on a short-lived `ticket-<N>-<slug>` branch inside an **isolated worktree** (either a sibling folder created by `bin/start-ticket.sh` for CLI use, or a Desktop-managed worktree spawned via the "worktree" checkbox). Shipping is via `bash bin/deploy.sh prod` from inside the ticket worktree — that script deploys the server AND fast-forwards `origin/main` so they stay in lockstep. Direct commits and pushes to `main` are blocked by git hooks.

## Start of every session

1. Run `git branch --show-current` (call result BRANCH) and `test -f .git && echo worktree || echo main-repo` (call result MODE).
2. **If BRANCH is `main`:** ask "Which ticket are you working on today? (or 'no ticket' if exploring/reviewing)."
   - If user names ticket `<N>` [optional `<slug>`]:
     - **MODE = `main-repo`** (CLI in the canonical plugin folder): offer `bash bin/start-ticket.sh <N> <slug>`; after it creates the sibling worktree, tell user to `cd` + restart Claude there.
     - **MODE = `worktree`** (Desktop auto-worktree, or any isolated worktree on main): run `git checkout -b ticket-<N>-<slug>` in place and proceed.
   - If user says "no ticket" → read-only mode, no tracked-file edits.
3. **If BRANCH matches `ticket-<N>[-<slug>]`:** confirm "continuing ticket `<N>`?" → proceed.
4. **If BRANCH is anything else:** unusual — ask user before acting.

## What "commit and push to prod" means

From inside a ticket worktree:
1. Commit your work on the ticket branch (hooks refuse commits on main, so this is automatic).
2. Run `bash bin/deploy.sh prod`. That script:
   - Verifies your tip is a descendant of the current prod SHA (refuses otherwise).
   - Ships tracked files to the prod server.
   - Updates the server's `.deploy-manifest.json`.
   - Fast-forwards `origin/main` to your tip.
3. When done and confirmed live: `bash bin/finish-ticket.sh` to remove the worktree + delete the branch (local + remote).

**Never** `git push origin main` directly. The pre-push hook will refuse. The only sanctioned path is `bin/deploy.sh prod`.

## When a hook rejects an action

Read the error message. Do not use `--no-verify` or `--force` unless the user has explicitly approved it in the current conversation and you have a documented reason. Typical recoveries:

- **Pre-commit refused commit on main** → start a ticket: `bash bin/start-ticket.sh <N> [slug]`.
- **Pre-push refused push to main** → you meant `bash bin/deploy.sh prod`.
- **Deploy.sh descendant check failed** → another session shipped while you were working. Rebase your ticket onto the new `origin/main` and redeploy.

## Forbidden

- Committing on `main`, ever.
- Pushing to `main` manually.
- Running `git worktree add` without going through `bin/start-ticket.sh` (bypasses the prod-SHA-base guarantee).
- Bypassing hooks with `--no-verify` or `--force` without explicit user approval for that specific action.
- Deploying from the main worktree or with branch `main` checked out (ticket branches only — even a Desktop worktree must `git checkout -b ticket-<N>-<slug>` before any work).
