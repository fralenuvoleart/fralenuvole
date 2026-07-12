#!/bin/bash
SITEMAP_URL="https://example.com/wp-sitemap-posts-post-1.xml"
USER_AGENT="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
DELAY=3
TIMEOUT=10
LOG_FILE="warmup-$(date +%Y%m%d-%H%M%S).log"

echo "Starting cache warmup..." | tee -a "$LOG_FILE"

# -s (silent) and -f (fail silently on HTTP errors so the string is truly empty)
sitemap=$(curl -s -f "$SITEMAP_URL")
[[ -z "$sitemap" ]] && { echo "ERROR: Could not fetch sitemap (URL may be broken or down)" | tee -a "$LOG_FILE"; exit 1; }

success=0
failed=0

while read -r url; do
    status=$(curl -s -o /dev/null -A "$USER_AGENT" -w "%{http_code}" --max-time $TIMEOUT "$url" 2>/dev/null || echo "000")
    
    if [[ "$status" =~ ^[23][0-9]{2}$ ]]; then
        echo "✓ $status -> $url" | tee -a "$LOG_FILE"
        ((success++))
    else
        echo "✗ $status -> $url" | tee -a "$LOG_FILE"
        ((failed++))
    fi
    
    sleep $DELAY
done < <(echo "$sitemap" | grep -oE 'https://[^<]+')

echo "Completed: $success success, $failed failed" | tee -a "$LOG_FILE"

# Automatically clean up logs older than 7 days to save Kinsta disk space
find . -name "warmup-*.log" -type f -mtime +7 -delete 2>/dev/null