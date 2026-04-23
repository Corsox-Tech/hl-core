#!/usr/bin/env bash
# bin/finish-ticket.sh — tear down a ticket worktree after a successful prod deploy.
#
# Must be run from INSIDE the ticket worktree, after `bash bin/deploy.sh prod`
# has landed the ticket's commits on origin/main.
#
# What it does:
#   1. Verifies the current branch's tip is present in origin/main.
#   2. Confirms with user.
#   3. Switches to the main worktree, removes this worktree, deletes the branch
#      (local + remote).
#
# Usage:
#   bash bin/finish-ticket.sh

set -euo pipefail

CURRENT_BRANCH=$(git symbolic-ref --short HEAD 2>/dev/null || echo "")
CURRENT_DIR=$(pwd)

if [[ ! "$CURRENT_BRANCH" =~ ^ticket- ]] && [[ ! "$CURRENT_BRANCH" =~ ^claude/ ]]; then
  echo "ERROR: not on a ticket branch (current: '$CURRENT_BRANCH')."
  echo "       Expected 'ticket-*' or 'claude/*' (Desktop auto-session)."
  echo "       Run this from inside a ticket worktree only."
  exit 1
fi

echo "━━━ Verifying ticket shipped ━━━"
git fetch origin main --quiet
LOCAL_HEAD=$(git rev-parse HEAD)

if ! git merge-base --is-ancestor HEAD origin/main 2>/dev/null; then
  echo "  ✗ Your ticket's tip ($LOCAL_HEAD) is NOT in origin/main."
  echo
  echo "  This means one of:"
  echo "    - You haven't deployed yet. Run: bash bin/deploy.sh prod"
  echo "    - Deploy succeeded locally but the main-push step failed."
  echo "      Fix: HL_DEPLOY_PUSH=1 git push origin HEAD:main"
  echo "    - A parallel deploy superseded yours. Rebase and redeploy."
  echo
  exit 1
fi
echo "  ✓ Ticket commits are in origin/main — safe to clean up."

# Find the main worktree root
MAIN_COMMON_DIR=$(git rev-parse --git-common-dir)
MAIN_REPO_DIR=$(cd "$MAIN_COMMON_DIR/.." && pwd)

echo
echo "━━━ About to remove ━━━"
echo "  Branch:    $CURRENT_BRANCH (local + remote)"
echo "  Worktree:  $CURRENT_DIR"
echo "  After:     your shell will still be in a deleted folder."
echo "             cd to $MAIN_REPO_DIR when done."
echo
read -r -p "Proceed? [y/N]: " CONFIRM

if [[ "$CONFIRM" != "y" ]] && [[ "$CONFIRM" != "Y" ]]; then
  echo "Aborted."
  exit 0
fi

# Switch to main repo (can't remove a worktree while inside it)
cd "$MAIN_REPO_DIR"

# Remove worktree
echo
echo "━━━ Removing worktree ━━━"
git worktree remove --force "$CURRENT_DIR"
echo "  ✓ Worktree removed"

# Delete local branch
echo
echo "━━━ Deleting local branch ━━━"
git branch -D "$CURRENT_BRANCH"
echo "  ✓ Local branch deleted"

# Delete remote branch (best-effort)
echo
echo "━━━ Deleting remote branch ━━━"
if git push origin --delete "$CURRENT_BRANCH" 2>&1; then
  echo "  ✓ Remote branch deleted"
else
  echo "  (no remote branch, or already deleted — ignored)"
fi

echo
echo "━━━ Done ━━━"
echo "  Ticket ${CURRENT_BRANCH} fully cleaned up."
echo "  Your shell is in: $CURRENT_DIR (deleted). Run:"
echo "    cd $MAIN_REPO_DIR"
