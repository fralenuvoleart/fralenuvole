#!/bin/bash
set -euo pipefail

# One-way mirror: fralenuvole plugin source of truth -> Fralenuvole.art workspace.
# Copies only production files; excludes IDE/git and dev-only files that the
# Fralenuvole.art repo intentionally does not track (mirrors deploy-remote.sh
# prune list, plus IDE artifacts).

SOURCE_DIR="/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/fralenuvole"
TARGET_DIR="/mnt/backup/BACKUP/WWW/FRALENUVOLE/fralenuvole.art/wp-content/plugins/fralenuvole"

DRY_RUN=false
DO_PUSH=false
for arg in "$@"; do
    case "$arg" in
        --dry-run|-n) DRY_RUN=true ;;
        --push)        DO_PUSH=true ;;
    esac
done

# Local-only guard: both paths are machine-specific absolute paths.
if [[ ! -d "$SOURCE_DIR" ]]; then
    echo "❌ Error: $SOURCE_DIR not found." >&2
    exit 1
fi
if [[ ! -d "$TARGET_DIR" ]]; then
    echo "❌ Error: $TARGET_DIR not found." >&2
    exit 1
fi

# IDE/git + dev-only files/dirs that must NOT be mirrored into the target repo.
EXCLUDES=(
    --exclude='.git/'
    --exclude='.gitattributes'
    --exclude='.gitignore'
    --exclude='.editorconfig'
    --exclude='.dev/'
    --exclude='.vscode/'
    --exclude='.idea/'
    --exclude='.roo*'
    --exclude='docs/'
    --exclude='memory-bank/'
    --exclude='plans/'
    --exclude='vendor/'
    --exclude='logs/'
    --exclude='composer.json'
    --exclude='composer.lock'
    --exclude='AGENTS.md'
    --exclude='phpcs.xml'
    --exclude='*.md'
)

RSYNC_ARGS=(-a --delete --info=stats1)

if $DRY_RUN; then
    RSYNC_ARGS+=(--dry-run --itemize-changes)
    echo "🔍 DRY RUN — no changes will be applied"
else
    echo "🚀 Mirroring plugin to Fralenuvole.art workspace"
fi
echo "---"

rsync "${RSYNC_ARGS[@]}" "${EXCLUDES[@]}" "$SOURCE_DIR/" "$TARGET_DIR/"

echo "---"
echo "✅ Sync complete."

if $DRY_RUN; then
    echo "🔍 Dry run — nothing written."
    exit 0
fi

if $DO_PUSH; then
    echo "Committing and pushing to fralenuvole.art repo..."
    cd "$TARGET_DIR/../../.."
    if git diff --quiet && git diff --cached --quiet && [[ -z "$(git ls-files --others --exclude-standard)" ]]; then
        echo "No changes to commit."
        exit 0
    fi
    git add wp-content/plugins/fralenuvole
    git commit -m "chore: sync fralenuvole plugin from source of truth"
    git push
    echo "✅ Pushed to fralenuvole.art repo."
else
    echo "Review changes with:"
    echo "  cd \"$TARGET_DIR/../../..\" && git status"
    echo "To auto-commit+push, re-run with --push"
fi
