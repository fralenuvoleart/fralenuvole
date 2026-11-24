#!/usr/bin/env bash
# Manual repo verification gate — run before committing or uploading.
# Not installed as a git hook.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

echo "==> PHP syntax check (tracked PHP files)"
php_files=()
while IFS= read -r -d '' file; do
	php_files+=("$file")
done < <(git ls-files -z '*.php')

lint_failed=0
for file in "${php_files[@]}"; do
	if [[ -f "$file" ]]; then
		if ! php -l "$file" >/dev/null; then
			lint_failed=1
		fi
	fi
done
if [[ $lint_failed -ne 0 ]]; then
	echo "PHP syntax check failed." >&2
	exit 1
fi
echo "ok - ${#php_files[@]} PHP files linted"

echo
echo "==> Invariant tests"
php tests/invariants/run.php --quiet

echo
echo "==> Phone pattern gate tests"
php tests/phone-pattern-gate-test.php >/dev/null
echo "ok - phone-pattern-gate-test.php"

echo
echo "ALL CHECKS PASSED."
