#!/bin/sh
# Dependency-free staging smoke check for the rewriter.
#
# Usage: tests/smoke-urls.sh <base-url> [url-list-file]
#
# For the homepage and every URL in the list it checks:
#   - HTTP status 200
#   - at most one redirect
#   - a self-referencing canonical
#   - no leftover translation delimiters ({{...}}) in the HTML
#
# URL list format, one per line (# comments and blank lines ignored):
#   https://example.test/it/blog/category/foo/
#   https://example.test/it/blog/category/foo/|https://example.test/it/blog/foo/
# The optional second field is the expected canonical.
#
# Without a list file the script also checks every hreflang alternate found on
# the homepage, so the language homepages are covered with no configuration.
#
# If the host blocks non-browser clients (403), run this from an allowed
# network or against the Kinsta staging domain directly.

set -eu

BASE="${1:-}"
LIST="${2:-}"
UA="Mozilla/5.0 (compatible; frl-smoke/1.0; +https://pbservices.ge/)"

if [ -z "$BASE" ]; then
	echo "Usage: $0 <base-url> [url-list-file]" >&2
	exit 2
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

FAIL=0

# Strip a trailing slash so /x and /x/ compare equal.
frl_smoke_norm() {
	printf '%s' "$1" | sed 's#/$##'
}

# Extract the first canonical href from an HTML file.
frl_smoke_canonical() {
	tr '>' '\n' < "$1" | grep -i 'rel="canonical"' | head -n 1 | sed -n 's/.*href="\([^"]*\)".*/\1/p'
}

check_url() {
	url="$1"
	expected="${2:-}"
	body="$TMP/body.html"
	reasons=""

	meta=$(curl -sS -L --max-time 25 -A "$UA" -o "$body" -w '%{http_code} %{num_redirects} %{url_effective}' "$url" 2>/dev/null || true)
	status=$(printf '%s' "$meta" | awk '{print $1}')
	redirects=$(printf '%s' "$meta" | awk '{print $2}')
	effective=$(printf '%s' "$meta" | awk '{print $3}')

	[ "$status" = "200" ] || reasons="$reasons status=$status"
	if [ -n "$redirects" ] && [ "$redirects" -gt 1 ] 2>/dev/null; then
		reasons="$reasons redirects=$redirects"
	fi

	canonical=$(frl_smoke_canonical "$body")
	if [ -z "$canonical" ]; then
		reasons="$reasons no-canonical"
	else
		target="${expected:-$effective}"
		if [ "$(frl_smoke_norm "$canonical")" != "$(frl_smoke_norm "$target")" ]; then
			reasons="$reasons canonical=$canonical expected=$target"
		fi
	fi

	if grep -q '{{' "$body" 2>/dev/null; then
		reasons="$reasons translation-delimiters"
	fi

	if [ -z "$reasons" ]; then
		echo "ok   - $url"
	else
		echo "FAIL - $url:$reasons"
		FAIL=1
	fi
}

# 1. The base URL always.
check_url "$BASE"

# 2. Every language homepage advertised by the base URL.
curl -sS -L --max-time 25 -A "$UA" "$BASE" 2>/dev/null | tr '>' '\n' | grep -i 'hreflang=' | sed -n 's/.*href="\([^"]*\)".*/\1/p' | sort -u > "$TMP/alts.txt" || true
if [ -s "$TMP/alts.txt" ]; then
	while IFS= read -r alternate; do
		[ "$alternate" = "$BASE" ] || check_url "$alternate"
	done < "$TMP/alts.txt"
fi

# 3. Every URL in the optional list.
if [ -n "$LIST" ]; then
	if [ ! -f "$LIST" ]; then
		echo "List file not found: $LIST" >&2
		exit 2
	fi
	while IFS= read -r line || [ -n "$line" ]; do
		case "$line" in
			'' | \#*) continue ;;
		esac
		url=$(printf '%s' "$line" | cut -d'|' -f1)
		expected=$(printf '%s' "$line" | cut -d'|' -f2 -s)
		check_url "$url" "$expected"
	done < "$LIST"
fi

if [ "$FAIL" -ne 0 ]; then
	echo "Smoke check failed." >&2
	exit 1
fi

echo "Smoke check passed."
