# Parallel-Safe Workflow

> **When to read:** (1) starting any new task, (2) user says "commit and push" or "deploy to prod", (3) a git hook rejects an action.

## The model in one paragraph

`main` on GitHub always equals what's on prod. Every ticket gets its own **git worktree** in a sibling folder (`../hl-core-ticket-<N>[-<slug>]`), branched from the live prod SHA. Work happens in the ticket worktree. Shipping is via `bash bin/deploy.sh prod` — that script deploys the server AND fast-forwards `origin/main` so they stay in lockstep. Direct commits and pushes to `main` are blocked by git hooks.

## Start of every session

1. Run `git worktree list` + check cwd.
2. If cwd is the main worktree (ends in `hl-core`): ask the user "Which ticket are you working on today? (or 'no ticket' if exploring/reviewing)."
3. If user names a ticket and a matching worktree exists elsewhere → tell them to `cd` there and restart Claude. Do NOT do ticket work in the main worktree.
4. If user names a ticket and no worktree exists → run `bash bin/start-ticket.sh <N> [slug]` (it creates the worktree), then instruct the user to cd + restart Claude.
5. If user says "no ticket" → you may read/review but do NOT modify tracked files.
6. If cwd is a ticket worktree (ends in `hl-core-ticket-<N>-...`): confirm "continuing ticket N?" and proceed.

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
- Deploying from the main worktree (it holds `main` only — never ticket work).
