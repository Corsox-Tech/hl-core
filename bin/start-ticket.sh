#!/usr/bin/env bash
# bin/start-ticket.sh — create an isolated worktree for a new ticket.
#
# Branches from the current prod manifest SHA (read live from the server), so
# the ticket's base cannot be stale. Creates a sibling folder worktree at
# ../hl-core-ticket-<N>[-<slug>] with its own branch.
#
# Usage:
#   bash bin/start-ticket.sh 34                 # branch ticket-34
#   bash bin/start-ticket.sh 34 csv-export      # branch ticket-34-csv-export
#   bash bin/start-ticket.sh 34 csv-export --from-origin   # skip SSH, use origin/main
#
# After the worktree is created, cd into it and run `claude` there.

set -euo pipefail

TICKET_NUM="${1:-}"
SLUG="${2:-}"
FROM_ORIGIN=""
for arg in "$@"; do
  if [[ "$arg" == "--from-origin" ]]; then FROM_ORIGIN="1"; fi
done

if [[ -z "$TICKET_NUM" ]] || [[ "$TICKET_NUM" == "--from-origin" ]]; then
  echo "Usage: bash bin/start-ticket.sh <ticket-number> [slug] [--from-origin]"
  echo "  ticket-number — required, e.g. 34"
  echo "  slug          — optional short name, e.g. csv-export"
  echo "  --from-origin — skip SSH to prod, branch from origin/main instead"
  exit 1
fi

# Strip leading # if user passed "#34"
TICKET_NUM="${TICKET_NUM#\#}"

# Validate ticket number is numeric
if ! [[ "$TICKET_NUM" =~ ^[0-9]+$ ]]; then
  echo "ERROR: ticket number must be numeric, got: $TICKET_NUM"
  exit 1
fi

# Build branch + worktree names
if [[ -n "$SLUG" ]] && [[ "$SLUG" != "--from-origin" ]]; then
  # Validate slug: lowercase alphanumeric and dashes
  if ! [[ "$SLUG" =~ ^[a-z0-9-]+$ ]]; then
    echo "ERROR: slug must be lowercase alphanumeric and dashes only, got: $SLUG"
    exit 1
  fi
  BRANCH_NAME="ticket-${TICKET_NUM}-${SLUG}"
  WORKTREE_DIR="hl-core-ticket-${TICKET_NUM}-${SLUG}"
else
  BRANCH_NAME="ticket-${TICKET_NUM}"
  WORKTREE_DIR="hl-core-ticket-${TICKET_NUM}"
fi

WORKTREE_PATH="../${WORKTREE_DIR}"

# Prune dead worktrees first (folder deleted but still in git metadata)
git worktree prune

# Check if worktree already exists
if git worktree list --porcelain | grep -q "worktree .*${WORKTREE_DIR}$"; then
  EXISTING=$(git worktree list | grep "${WORKTREE_DIR}" | awk '{print $1}')
  echo "━━━ Worktree already exists ━━━"
  echo "  Path: $EXISTING"
  echo
  echo "  To continue work on ticket #${TICKET_NUM}:"
  echo "    cd $EXISTING"
  echo "    claude"
  exit 0
fi

# Check if branch already exists (from a previous, removed worktree)
if git show-ref --verify --quiet "refs/heads/$BRANCH_NAME"; then
  echo "━━━ Branch exists but worktree doesn't ━━━"
  echo "  Branch: $BRANCH_NAME"
  echo
  echo "  Rebuilding worktree from existing branch..."
  git worktree add "$WORKTREE_PATH" "$BRANCH_NAME"
  echo
  echo "✓ Worktree rebuilt at $WORKTREE_PATH"
  echo
  echo "Next:"
  echo "  cd $WORKTREE_PATH"
  echo "  claude"
  exit 0
fi

# Read prod manifest SHA (unless --from-origin)
if [[ -z "$FROM_ORIGIN" ]]; then
  echo "━━━ Reading prod manifest SHA ━━━"
  PROD_MANIFEST=$(ssh -p 65002 u665917738@145.223.76.150 \
    'cat /home/u665917738/domains/academy.housmanlearning.com/public_html/wp-content/plugins/hl-core/.deploy-manifest.json 2>/dev/null || true')

  if [[ -z "$PROD_MANIFEST" ]]; then
    echo "  ✗ Could not read prod manifest via SSH."
    echo "  Falling back to origin/main. If this is wrong, abort and retry with good SSH."
    FROM_ORIGIN="1"
  else
    PROD_SHA=$(echo "$PROD_MANIFEST" | grep -oE '"sha":[[:space:]]*"[a-f0-9]+"' | head -1 | sed 's/.*"\([a-f0-9]\+\)".*/\1/')
    echo "  Prod SHA: $PROD_SHA"
  fi
fi

if [[ -n "$FROM_ORIGIN" ]]; then
  echo "━━━ Using origin/main as base ━━━"
  git fetch origin main --quiet
  PROD_SHA=$(git rev-parse origin/main)
  echo "  origin/main: $PROD_SHA"
fi

# Make sure we have the SHA locally
if ! git cat-file -e "$PROD_SHA" 2>/dev/null; then
  echo "  Fetching $PROD_SHA from origin..."
  git fetch origin --quiet || true
  if ! git cat-file -e "$PROD_SHA" 2>/dev/null; then
    echo "ERROR: SHA $PROD_SHA not found locally or in origin."
    echo "       Something is out of sync. Try: git fetch --all"
    exit 1
  fi
fi

# Create the worktree
echo
echo "━━━ Creating worktree ━━━"
echo "  Path:   $WORKTREE_PATH"
echo "  Branch: $BRANCH_NAME"
echo "  Base:   $PROD_SHA"
git worktree add "$WORKTREE_PATH" -b "$BRANCH_NAME" "$PROD_SHA"

# Hooks come automatically via core.hooksPath=bin/hooks, since bin/hooks/
# is tracked and every worktree has it. If core.hooksPath isn't set yet
# (first-time setup), install it now.
if [[ -z "$(git config core.hooksPath 2>/dev/null || true)" ]]; then
  echo
  echo "━━━ First-time hooks install ━━━"
  git config core.hooksPath bin/hooks
  chmod +x bin/hooks/* 2>/dev/null || true
  echo "  ✓ core.hooksPath set to bin/hooks (applies to all worktrees)"
fi

echo
echo "━━━ Ready ━━━"
echo
echo "  Next step — open a new terminal and run:"
echo "    cd $WORKTREE_PATH"
echo "    claude"
echo
echo "  When you start Claude there, tell it you're working on ticket #${TICKET_NUM}."
