#!/bin/bash
# warmup-local.sh — Cache warmer for pbservices.ge (local-only).
#
# Crawls the public sitemap and curls every listed URL to warm both Cloudflare
# edge cache and Kinsta Nginx page cache.  Must run from a machine *outside*
# the Kinsta origin network — Cloudflare WAF blocks the origin server from
# curling its own domain (HTTP 403).  For server-side cache warming a separate
# WP-CLI–based script is needed.
#
# Usage:  ./tools/warmup-local.sh
# Monitor: tail -f "$(find logs -maxdepth 1 -name 'warmup-*.log' -printf '%T@ %p\n' | sort -rn | head -1 | cut -d' ' -f2-)"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
set -euo pipefail

LOG_DIR="$(dirname "$SCRIPT_DIR")/logs"
mkdir -p "$LOG_DIR"

SITEMAP_URL="https://pbservices.ge/sitemap-index.xml"
USER_AGENT="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 FRL-Warmup/1.0"
# 3 s — safety-first for Kinsta (firewall + CDN edge cache + bot detection).
# 500 pages × 3 s ≈ 25 min at 20 req/min, well within human browsing patterns.
# Below 2 s risks bot-detection blocks; above 4 s yields no extra safety.
DELAY=3
TIMEOUT=10
MAX_SITEMAP_DEPTH=3
LOG_FILE="$LOG_DIR/warmup-$(date +%Y%m%d-%H%M%S).log"

echo "Warmup started  $(date '+%Y-%m-%d %H:%M:%S')" | tee "$LOG_FILE"
echo "Log: $LOG_FILE" | tee -a "$LOG_FILE"

# Extract <loc>...</loc> contents — decode XML-escaped & so query strings survive.
extract_locs() {
    grep -oE '<loc>[^<]*</loc>' | sed -E 's#</?loc>##g; s/&/\&/g'
}

# Fetch with a hard timeout and follow redirects (-L).
fetch() {
    curl -s -f -L --max-time "$TIMEOUT" "$1" 2>/dev/null
}

# --- Phase 1: Discover page URLs from sitemap(s) ---
echo "Discovering URLs from sitemap..." | tee -a "$LOG_FILE"
START_TIME=$(date +%s)

seen_sitemaps=()
page_urls=()
# Two parallel arrays — sitemap URLs already contain ":" in "https://",
# so a ":" delimiter would truncate the URL when split back apart.
queue=("$SITEMAP_URL")
queue_depth=(0)
queue_pos=0

while ((queue_pos < ${#queue[@]})); do
    sm_url="${queue[queue_pos]}"
    depth="${queue_depth[queue_pos]}"
    ((queue_pos++)) || true

    # Linear-scan dedup — sitemap count is tiny (depth-limited to 3).
    _seen=0
    for _s in "${seen_sitemaps[@]}"; do
        [[ "$_s" == "$sm_url" ]] && { _seen=1; break; }
    done
    [[ $_seen -eq 1 ]] && continue
    seen_sitemaps+=("$sm_url")

    body=$(fetch "$sm_url")
    if [[ -z "$body" ]]; then
        echo "ERROR: Could not fetch sitemap: $sm_url" | tee -a "$LOG_FILE"
        sleep "$DELAY"
        continue
    fi

    while read -r loc; do
        [[ -z "$loc" ]] && continue
        if [[ "$loc" =~ \.xml(\?.*)?$ ]]; then
            if ((depth < MAX_SITEMAP_DEPTH)); then
                queue+=("$loc")
                queue_depth+=("$((depth + 1))")
            else
                echo "WARNING: Max sitemap nesting depth reached, skipping: $loc" | tee -a "$LOG_FILE"
            fi
        else
            page_urls+=("$loc")
        fi
    done < <(echo "$body" | extract_locs)

    sleep "$DELAY"
done

if [[ ${#page_urls[@]} -eq 0 ]]; then
    echo "ERROR: No page URLs found in sitemap(s) (URL may be broken, down, or contain no <loc> entries)" | tee -a "$LOG_FILE"
    exit 1
fi

TOTAL=${#page_urls[@]}
echo "Found $TOTAL pages to warm." | tee -a "$LOG_FILE"

# --- Phase 2: Warm each page ---
success=0
failed=0
count=0

for url in "${page_urls[@]}"; do
    ((count++)) || true
    status=$(curl -s -o /dev/null -L -A "$USER_AGENT" -w "%{http_code}" --max-time "$TIMEOUT" "$url" 2>/dev/null || echo "000")

    if [[ "$status" =~ ^[23][0-9]{2}$ ]]; then
        echo "[$count/$TOTAL] ✓ $status -> $url" | tee -a "$LOG_FILE"
        ((success++)) || true
    else
        echo "[$count/$TOTAL] ✗ $status -> $url" | tee -a "$LOG_FILE"
        ((failed++)) || true
    fi

    sleep "$DELAY"
done

ELAPSED=$(( $(date +%s) - START_TIME ))
echo "Completed: $success success, $failed failed ($TOTAL total) in ${ELAPSED}s" | tee -a "$LOG_FILE"

# Clean up logs older than 7 days to save disk space.
find "$LOG_DIR" -maxdepth 1 -name "warmup-*.log" -type f -mtime +7 -delete 2>/dev/null
