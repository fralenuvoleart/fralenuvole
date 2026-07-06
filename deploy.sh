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
    echo "❌ Deploy ABORTED and rolled back. Fix the syntax error(s) above and redeploy."
    exit 1
fi

echo "✅ PHP syntax check passed on all files."
echo "---"
echo "✅ Deploy finished — fralenuvole plugin is now up to date on PBS Production on Kinsta."
