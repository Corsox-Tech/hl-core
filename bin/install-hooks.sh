#!/usr/bin/env bash
# bin/install-hooks.sh — wire this repo's hooks into git, once.
#
# Sets core.hooksPath to bin/hooks so every worktree (current and future)
# runs the same hook scripts. Since bin/hooks/* is tracked in git, any
# worktree checked out from this repo has the hooks available automatically —
# no per-worktree install needed.
#
# Run once after cloning, or after pulling a change that updates hooks.
# Safe to re-run.

set -euo pipefail

git config core.hooksPath bin/hooks

# Git Bash on Windows sometimes loses +x on hook scripts after checkout.
chmod +x bin/hooks/* 2>/dev/null || true

echo "✓ Hooks installed."
echo "  core.hooksPath = $(git config core.hooksPath)"
echo "  Hooks active in every worktree of this repo."
