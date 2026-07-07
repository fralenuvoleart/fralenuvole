#!/bin/bash
set -euo pipefail

# Self-protect: re-exec from a stable temp copy so `git reset --hard` below
# can never affect the currently-running script instance (this script lives
# inside the same repo it resets).
if [ -z "${DEPLOY_SH_REEXECED:-}" ]; then
    TMP_SELF="$(mktemp /tmp/deploy.XXXXXX.sh)"
    cp "$0" "$TMP_SELF"
    chmod +x "$TMP_SELF"
    export DEPLOY_SH_REEXECED=1
    exec "$TMP_SELF" "$@"
fi

PLUGIN_DIR="$HOME/public/wp-content/plugins/fralenuvole"
BRANCH="main"

echo "---"
echo "🚀 Deploying fralenuvole plugin from GitHub to PBS Production on Kinsta"
echo "---"

cd "$PLUGIN_DIR"

echo "Current commit:"
git log -1 --oneline

echo "Fetching latest from GitHub..."
git fetch origin "$BRANCH"

echo "Commits about to be applied:"
git log --oneline HEAD..origin/$BRANCH

PREV_COMMIT=$(git rev-parse HEAD)

echo "Resetting to origin/$BRANCH..."
git reset --hard "origin/$BRANCH"

echo "New commit:"
git log -1 --oneline

# Prune dev-only files/directories from production. These are versioned in
# git for history/continuity but must never persist on the live server.
# `git reset --hard` (both the normal deploy path and the rollback path
# below) always restores them (they're tracked), so this must run after
# EVERY reset - success or rollback - to keep production in the same clean,
# consistent state regardless of outcome.
prune_dev_files() {
    echo "🧹 Pruning dev-only files/directories from production..."

    # Generic sweep of all top-level dotfiles/dot-dirs except .git, which is
    # required by this script itself (git fetch/reset/log all need it present
    # for every future deploy to keep working).
    while IFS= read -r -d '' entry; do
        name="$(basename "$entry")"
        if [ "$name" != ".git" ]; then
            rm -rf "$entry"
        fi
    done < <(find "$PLUGIN_DIR" -maxdepth 1 -name '.*' ! -name '.' ! -name '..' -print0)

    # Non-dot dev-only files/directories (not caught by the dotfile sweep above).
    local dev_only_paths=(
        "docs"
        "memory-bank"
        "plans"
        "phpcs.xml"
        "AGENTS.md"
    )
    for path in "${dev_only_paths[@]}"; do
        rm -rf "${PLUGIN_DIR:?}/${path:?}"
    done

    # Root-level composer*/*.json files (composer.json, composer.lock, etc.).
    # Scoped to maxdepth 1 so it never touches the runtime-required JSON files
    # in core/themekit/theme-json/.
    find "$PLUGIN_DIR" -maxdepth 1 \( -iname '*composer*' -o -iname '*.json' \) -exec rm -f {} +

    echo "✅ Dev-only files pruned."
}

echo "---"
prune_dev_files

echo "---"
echo "🔎 Running PHP syntax check on all plugin files..."
LINT_FAILED=0
LINT_LOG=$(mktemp)

while IFS= read -r -d '' file; do
    if ! php -l "$file" > "$LINT_LOG" 2>&1; then
        echo "❌ Syntax error in: $file"
        cat "$LINT_LOG"
        LINT_FAILED=1
    fi
done < <(find "$PLUGIN_DIR" -name '*.php' -print0)

rm -f "$LINT_LOG"

if [ "$LINT_FAILED" -eq 1 ]; then
    echo "---"
    echo "🛑 Syntax error(s) detected in the new code — rolling back to $PREV_COMMIT"
    git reset --hard "$PREV_COMMIT"
    echo "---"
    prune_dev_files
    echo "❌ Deploy ABORTED and rolled back. Fix the syntax error(s) above and redeploy."
    exit 1
fi

echo "✅ PHP syntax check passed on all files."
echo "---"
echo "✅ Deploy finished — fralenuvole plugin is now up to date on PBS Production on Kinsta."
