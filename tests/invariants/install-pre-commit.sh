#!/bin/sh
# Install the pre-commit hook: invariant tests + syntax check on staged PHP.
#
# Usage: tests/invariants/install-pre-commit.sh
# Safe to re-run. An existing non-Fralenuvole hook is backed up once.

set -eu

ROOT=$(git rev-parse --show-toplevel)
HOOK="$ROOT/.git/hooks/pre-commit"
MARKER="frl-invariants"

if [ -f "$HOOK" ] && ! grep -q "$MARKER" "$HOOK"; then
	BACKUP="$HOOK.backup.$(date +%s)"
	cp "$HOOK" "$BACKUP"
	echo "Existing hook backed up to $BACKUP"
fi

cat > "$HOOK" <<'HOOK_EOF'
#!/bin/sh
# frl-invariants: dependency-free invariant tests + staged PHP syntax check.
set -e
ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"
php tests/invariants/run.php --quiet
git diff --cached --name-only --diff-filter=ACM | while IFS= read -r file; do
	case "$file" in
		*.php) php -l "$file" >/dev/null || { echo "Syntax error: $file" >&2; exit 1; } ;;
	esac
done
HOOK_EOF

chmod +x "$HOOK"
echo "Installed $HOOK"
