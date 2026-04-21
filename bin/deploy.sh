#!/usr/bin/env bash
# bin/deploy.sh — Safe deploy script for hl-core plugin.
#
# Guardrails:
#   1. Tarball is sourced from `git ls-files` so only tracked files ship.
#      Impossible to accidentally leak dev artifacts (.playwright-mcp, data/, etc.).
#   2. Before overwriting the target, reads the target's .deploy-manifest.json,
#      and aborts unless local HEAD is a descendant of the manifest's SHA.
#      This prevents a stale branch from rolling back newer work on the target.
#   3. After a successful deploy, writes a new .deploy-manifest.json on the
#      target with { sha, branch, version, deployed_at, deployer }.
#
# Usage:
#   bin/deploy.sh test             # safe deploy to test (requires manifest descendant)
#   bin/deploy.sh prod             # safe deploy to prod
#   bin/deploy.sh test --force     # bypass descendant check (requires typed confirm)
#   DEPLOYER=mateo bin/deploy.sh test
#
# Exit codes:
#   0 — deploy successful
#   1 — usage/config error
#   2 — descendant check failed, user did not confirm --force
#   3 — ssh/scp/tar failure mid-deploy (target state may be partial)

set -euo pipefail

TARGET="${1:-}"
FORCE="${2:-}"

if [[ -z "$TARGET" ]]; then
  echo "Usage: $0 test|prod [--force]"
  echo "  test — AWS Lightsail (44.221.6.201)"
  echo "  prod — Hostinger (academy.housmanlearning.com)"
  echo "  --force — bypass manifest descendant check (requires typed confirmation)"
  exit 1
fi

# ─── Environment config ───
case "$TARGET" in
  test)
    SSH_CMD=(ssh -i "$HOME/.ssh/hla-test-keypair.pem" bitnami@44.221.6.201)
    SCP_CMD=(scp -i "$HOME/.ssh/hla-test-keypair.pem")
    REMOTE_HOST="bitnami@44.221.6.201"
    REMOTE_PLUGIN_DIR="/opt/bitnami/wordpress/wp-content/plugins/hl-core"
    REMOTE_PLUGINS_DIR="/opt/bitnami/wordpress/wp-content/plugins"
    WP_PATH_ENV='export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin'
    WP_CACHE_FLUSH="$WP_PATH_ENV && wp --path=/opt/bitnami/wordpress cache flush"
    EXTRACT_CMD="cd $REMOTE_PLUGINS_DIR && sudo rm -rf hl-core && sudo tar -xzf /tmp/hl-core.tar.gz && sudo chown -R bitnami:daemon hl-core"
    ;;
  prod)
    SSH_CMD=(ssh -p 65002 u665917738@145.223.76.150)
    SCP_CMD=(scp -P 65002)
    REMOTE_HOST="u665917738@145.223.76.150"
    REMOTE_PLUGIN_DIR="/home/u665917738/domains/academy.housmanlearning.com/public_html/wp-content/plugins/hl-core"
    REMOTE_PLUGINS_DIR="/home/u665917738/domains/academy.housmanlearning.com/public_html/wp-content/plugins"
    WP_CACHE_FLUSH="cd /home/u665917738/domains/academy.housmanlearning.com/public_html && wp cache flush 2>/dev/null || true"
    EXTRACT_CMD="cd $REMOTE_PLUGINS_DIR && rm -rf hl-core && tar -xzf /tmp/hl-core.tar.gz"
    ;;
  *)
    echo "ERROR: unknown target '$TARGET' (use 'test' or 'prod')"
    exit 1
    ;;
esac

# ─── Local state ───
LOCAL_SHA=$(git rev-parse HEAD)
LOCAL_BRANCH=$(git rev-parse --abbrev-ref HEAD)
LOCAL_VERSION=$(grep "HL_CORE_VERSION" hl-core.php | grep -oE "'[0-9.]+'" | head -1 | tr -d "'")

if [[ -z "$LOCAL_VERSION" ]]; then
  echo "ERROR: could not parse HL_CORE_VERSION from hl-core.php"
  exit 1
fi

# Detect uncommitted changes
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "WARNING: working tree has uncommitted changes."
  echo "         The tarball will include only tracked files at HEAD — uncommitted changes will NOT ship."
  echo
fi

echo "━━━ Local ━━━"
echo "  SHA:     $LOCAL_SHA"
echo "  Branch:  $LOCAL_BRANCH"
echo "  Version: $LOCAL_VERSION"
echo

# ─── Descendant check ───
echo "━━━ Reading $TARGET manifest ━━━"
REMOTE_MANIFEST=$("${SSH_CMD[@]}" "cat $REMOTE_PLUGIN_DIR/.deploy-manifest.json 2>/dev/null || true")

if [[ -z "$REMOTE_MANIFEST" ]]; then
  echo "  No manifest on $TARGET (first deploy, or pre-manifest state)"
  if [[ "$FORCE" != "--force" ]]; then
    echo
    echo "  To create the first manifest, re-run with --force."
    echo "  Safe to do on the first post-guardrail deploy."
    exit 1
  fi
  echo "  --force set; proceeding to create initial manifest"
else
  REMOTE_SHA=$(echo "$REMOTE_MANIFEST" | grep -oE '"sha":[[:space:]]*"[a-f0-9]+"' | head -1 | sed 's/.*"\([a-f0-9]\+\)".*/\1/')
  REMOTE_BRANCH=$(echo "$REMOTE_MANIFEST" | grep -oE '"branch":[[:space:]]*"[^"]+"' | head -1 | sed 's/.*"branch":[[:space:]]*"\([^"]\+\)".*/\1/')
  REMOTE_VERSION_M=$(echo "$REMOTE_MANIFEST" | grep -oE '"version":[[:space:]]*"[^"]+"' | head -1 | sed 's/.*"version":[[:space:]]*"\([^"]\+\)".*/\1/')
  echo "  SHA:     $REMOTE_SHA"
  echo "  Branch:  $REMOTE_BRANCH"
  echo "  Version: $REMOTE_VERSION_M"
  echo

  # Make sure we have the remote SHA locally
  if ! git cat-file -e "$REMOTE_SHA" 2>/dev/null; then
    echo "━━━ ABORT ━━━"
    echo "  $TARGET is at SHA $REMOTE_SHA but that SHA does not exist in your local git history."
    echo "  Fetch first: git fetch --all"
    echo "  If the commit truly doesn't exist anywhere, that's a bigger problem — investigate."
    exit 2
  fi

  # Check if local HEAD is a descendant of remote SHA
  if git merge-base --is-ancestor "$REMOTE_SHA" HEAD 2>/dev/null; then
    COMMIT_COUNT=$(git rev-list --count "$REMOTE_SHA..HEAD")
    echo "  ✓ Local HEAD is a descendant of $TARGET (ahead by $COMMIT_COUNT commits)"
  else
    echo "━━━ ABORT ━━━"
    echo "  Local HEAD ($LOCAL_SHA on $LOCAL_BRANCH) is NOT a descendant of $TARGET."
    echo "  $TARGET is at $REMOTE_SHA ($REMOTE_BRANCH, v$REMOTE_VERSION_M)."
    echo
    echo "  Deploying would lose commits on $TARGET that are not in your local branch."
    echo "  Likely cause: you branched from main while $TARGET was ahead, or another session"
    echo "                deployed newer work and your branch doesn't include it yet."
    echo
    if [[ "$FORCE" == "--force" ]]; then
      echo "  --force set. To REALLY overwrite $TARGET with older work,"
      echo "  type exactly:  YES LOSE COMMITS"
      read -r -p "  Confirmation: " CONFIRM
      if [[ "$CONFIRM" != "YES LOSE COMMITS" ]]; then
        echo "  Aborted."
        exit 2
      fi
      echo "  Proceeding against safety advice."
    else
      echo "  If this is intentional (e.g., true emergency rollback), re-run with --force."
      exit 2
    fi
  fi
fi

# ─── Build tarball ───
echo
echo "━━━ Building tarball from git-tracked files ━━━"
MANIFEST_FILE=$(mktemp)
# Exclude the .claude/ development workspace entirely; everything else tracked by git ships.
git ls-files | grep -v '^\.claude/' > "$MANIFEST_FILE"
FILE_COUNT=$(wc -l < "$MANIFEST_FILE" | tr -d ' ')
echo "  Files: $FILE_COUNT (tracked, excluding .claude/)"

tar -czf /tmp/hl-core.tar.gz \
  --transform='s,^,hl-core/,' \
  --files-from="$MANIFEST_FILE"
rm -f "$MANIFEST_FILE"

TAR_SIZE=$(du -h /tmp/hl-core.tar.gz | cut -f1)
echo "  Size:  $TAR_SIZE"

# Sanity check the tarball — should NOT contain .playwright-mcp, .claude, or .git
LEAKED=$(tar -tzf /tmp/hl-core.tar.gz | grep -E 'hl-core/(\.playwright-mcp|\.claude|\.git|\.superpowers|data)/' || true)
if [[ -n "$LEAKED" ]]; then
  echo "  ✗ Tarball contains dev artifacts — aborting:"
  echo "$LEAKED" | head -5
  rm -f /tmp/hl-core.tar.gz
  exit 3
fi
echo "  ✓ No dev artifacts in tarball"

# ─── Upload + extract ───
echo
echo "━━━ Uploading to $TARGET ━━━"
"${SCP_CMD[@]}" /tmp/hl-core.tar.gz "${REMOTE_HOST}:/tmp/hl-core.tar.gz"
echo "  ✓ Upload done"

echo
echo "━━━ Extracting on $TARGET ━━━"
"${SSH_CMD[@]}" "$EXTRACT_CMD"
echo "  ✓ Extract done"

# ─── Write new manifest ───
echo
echo "━━━ Writing new manifest ━━━"
NOW=$(date -u +%Y-%m-%dT%H:%M:%SZ)
DEPLOYER_VAL="${DEPLOYER:-$(git config user.email 2>/dev/null || echo 'unknown')}"
# Build JSON safely (no nested heredoc escape hell)
MANIFEST_JSON="{\"sha\":\"$LOCAL_SHA\",\"branch\":\"$LOCAL_BRANCH\",\"version\":\"$LOCAL_VERSION\",\"deployed_at\":\"$NOW\",\"deployer\":\"$DEPLOYER_VAL\"}"

case "$TARGET" in
  test)
    "${SSH_CMD[@]}" "echo '$MANIFEST_JSON' | sudo tee $REMOTE_PLUGIN_DIR/.deploy-manifest.json > /dev/null && sudo chown bitnami:daemon $REMOTE_PLUGIN_DIR/.deploy-manifest.json"
    ;;
  prod)
    "${SSH_CMD[@]}" "echo '$MANIFEST_JSON' > $REMOTE_PLUGIN_DIR/.deploy-manifest.json"
    ;;
esac
echo "  ✓ Manifest written: $MANIFEST_JSON"

# ─── Flush cache + verify ───
echo
echo "━━━ Flushing cache + verifying on $TARGET ━━━"
"${SSH_CMD[@]}" "$WP_CACHE_FLUSH" 2>/dev/null || true

REMOTE_CODE_VERSION=$("${SSH_CMD[@]}" "grep 'HL_CORE_VERSION' $REMOTE_PLUGIN_DIR/hl-core.php | grep -oE \"'[0-9.]+'\" | head -1 | tr -d \"'\"")
if [[ "$REMOTE_CODE_VERSION" == "$LOCAL_VERSION" ]]; then
  echo "  ✓ $TARGET is at v$REMOTE_CODE_VERSION (SHA $LOCAL_SHA, branch $LOCAL_BRANCH)"
else
  echo "  ✗ Version mismatch after deploy: local $LOCAL_VERSION vs remote $REMOTE_CODE_VERSION"
  exit 3
fi

# ─── Clean up ───
rm -f /tmp/hl-core.tar.gz
"${SSH_CMD[@]}" "rm -f /tmp/hl-core.tar.gz"

echo
echo "━━━ Deploy successful ━━━"
echo "  Next deploy to $TARGET must be a descendant of $LOCAL_SHA (or use --force)."
