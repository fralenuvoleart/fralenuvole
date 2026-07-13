#!/bin/bash
SITEMAP_URL="https://pbservices.ge/sitemap-index.xml"
USER_AGENT="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
DELAY=3
TIMEOUT=10
MAX_SITEMAP_DEPTH=3   # safety cap against a malformed/self-referencing sitemap index
LOG_FILE="warmup-$(date +%Y%m%d-%H%M%S).log"

echo "Starting cache warmup..." | tee -a "$LOG_FILE"

# Extract <loc>...</loc> contents only (not any bare https:// substring) so
# xmlns/XSL-stylesheet hrefs on the same line are never mistaken for a
# sitemap/page URL. Also decode XML-escaped "&amp;" so query strings survive.
extract_locs() {
    grep -oE '<loc>[^<]*</loc>' | sed -E 's#</?loc>##g; s/&amp;/\&/g'
}

# Fetch with a hard timeout (-f/-s as before) and follow redirects (-L), so a
# stalled host can't hang the script and a 3xx canonical redirect resolves to
# the real target instead of just warming the redirect response itself.
fetch() {
    curl -s -f -L --max-time "$TIMEOUT" "$1" 2>/dev/null
}

# --- Discover real page URLs, recursing through sitemap-index nesting ---
# $SITEMAP_URL may be a sitemap index (its <loc> entries point to *other*
# sitemaps, e.g. wp-sitemap-posts-post-1.xml) rather than a flat list of
# pages. Any discovered <loc> ending in .xml is treated as a nested sitemap
# and queued for its own fetch/extract pass; everything else is a real page
# URL to warm. A visited-set + depth cap prevent infinite loops on a
# malformed/self-referencing index.
declare -A seen_sitemaps
page_urls=()
# Two parallel arrays (not a single "url:depth" encoded string) because
# sitemap URLs already contain ":" (the "https://" scheme), which would
# collide with a ":" delimiter and truncate the URL when split back apart.
queue=("$SITEMAP_URL")
queue_depth=(0)

while ((${#queue[@]} > 0)); do
    sm_url="${queue[0]}"
    depth="${queue_depth[0]}"
    queue=("${queue[@]:1}")
    queue_depth=("${queue_depth[@]:1}")

    [[ -n "${seen_sitemaps[$sm_url]:-}" ]] && continue
    seen_sitemaps["$sm_url"]=1

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

success=0
failed=0

for url in "${page_urls[@]}"; do
    status=$(curl -s -o /dev/null -L -A "$USER_AGENT" -w "%{http_code}" --max-time "$TIMEOUT" "$url" 2>/dev/null || echo "000")

    if [[ "$status" =~ ^[23][0-9]{2}$ ]]; then
        echo "✓ $status -> $url" | tee -a "$LOG_FILE"
        ((success++))
    else
        echo "✗ $status -> $url" | tee -a "$LOG_FILE"
        ((failed++))
    fi

    sleep "$DELAY"
done

echo "Completed: $success success, $failed failed" | tee -a "$LOG_FILE"

# Automatically clean up logs older than 7 days to save Kinsta disk space
find . -name "warmup-*.log" -type f -mtime +7 -delete 2>/dev/null
