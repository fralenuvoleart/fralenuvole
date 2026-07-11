---
name: kinsta-log-analyzer
description: Fetch Kinsta server logs (error, access, cache-perf) via the Kinsta MCP API, save them to ~/Downloads/kinsta-logs/, analyze website traffic patterns, operational health (cache HIT/MISS ratios, bot activity, response times, error rates), and present a structured severity-ranked findings report with operational recommendations. This skill is about website operations & traffic analysis — not code debugging. Use when the user asks to "analyze Kinsta logs", "check server logs", "debug Kinsta site errors", or "review cache performance".
---

# Kinsta Log Analyzer

## When to Use
- User asks to "analyze Kinsta logs", "check server logs", "debug errors on Kinsta"
- Periodic log health checks
- Debugging performance issues, errors, or traffic anomalies
- Reviewing Kinsta edge cache efficiency

## When NOT to Use
- Non-Kinsta hosting (this skill is Kinsta API-specific)
- Real-time monitoring (this is a point-in-time analysis)
- Code debugging (this skill analyzes website traffic and operational health, not source code)
- If `.roo/mcp.json` has no `kinsta` server configured

## Scope
This skill is about **website operations & traffic analysis**. It answers questions like:
- "How healthy is my site's cache?"
- "Are bots overwhelming my traffic?"
- "Are visitors hitting errors or slow pages?"
- "What traffic patterns and anomalies exist?"

It does NOT diagnose PHP code bugs, WordPress plugin conflicts, or database queries — for those, use code-level debugging tools (WP_DEBUG, Query Monitor, Xdebug).

---

## How Logs Are Retrieved

The Kinsta API (`kinsta.logs.get`) is **line-based, not time-based**:

| Parameter | Required | Default | Description |
|---|---|---|---|
| `env_id` | Yes | — | Environment UUID |
| `file_name` | No | (all 3 fetched) | `error`, `access`, or `kinsta-cache-perf` |
| `lines` | No | 1000 (error), 3000 (access), 1000 (cache) | Most recent lines from each log |
| `--hours` (script) | No | **24** | Filter report to last N hours. Pass `--hours 3`, `--hours 72`, etc. |

- **No time-window filtering** — the API always returns the last N lines
- 1000 error lines ≈ 1–3 days; 1000 access lines ≈ 3–5 hours (access is much denser)
- To get better access/error log overlap, use **`"lines":3000`** for the access log (~12–15 hours coverage)
- The Kinsta API has **no offset/pagination** — just pass a higher `lines` value directly
- The `file_name` parameter accepts bare names (`error`) — do NOT append `.log` suffix

---

## Workflow

### Step 1: Discover Sites & Environments
1. Read `.roo/mcp.json` for `KINSTA_API_KEY` and `KINSTA_COMPANY_ID`.
2. **If the user specified a site name**, use it directly (match by `name` or `display_name`).
3. **If no site specified**:
   - Check conversation history for a previously analyzed site — reuse it.
   - If ≤3 sites exist in the account, list them inline (no `ask_followup` — just say "Analyzing pbservices.ge (only site found)" or "Which: pbservices.ge, pbproperty.ge, or pbnova.com?").
   - Only use `ask_followup_question` if >3 sites.
4. Call `kinsta.environments.list` with `site_id`. Default to **live** environment.

### Step 2: Fetch Logs
Generate a single timestamp, then **execute** [`scripts/fetch_logs.sh`](scripts/fetch_logs.sh) to fetch all three logs in parallel with retries — this encapsulates the fetch+retry logic deterministically instead of hand-writing bash each run:

```bash
TS=$(date -u +%Y-%m-%d_%H%M%S)
DIR=~/Downloads/kinsta-logs/{site_name}/{env_name}
mkdir -p "$DIR"

KINSTA_API_KEY="..." KINSTA_COMPANY_ID="..." \
  bash .roo/skills/kinsta-log-analyzer/scripts/fetch_logs.sh "$ENV_ID" "$DIR" "$TS"
```

**Fetch strategy** (implemented by the script, run in parallel):

| Priority | Log | Lines | Retries | Why |
|---|---|---|---|---|
| 1 | `error` | 1000 | 1 | ~1–3 days of error coverage |
| 2 | `access` | 3000 | 1 | ~12–15 hours, overlaps with error log for cross-analysis |
| 3 | `kinsta-cache-perf` | 1000 | **3** (flaky endpoint) | Cache HIT/MISS/BYPASS data |

**Why batch the access log**: 1000 access lines ≈ 3–5 hours, but the error log spans days. Batching 3000 access lines covers 12–15 hours, giving meaningful overlap for cross-file analysis.

**Retry logic**: If cache-perf fails with `NETWORK_ERROR`, the script retries with a 3s sleep between attempts, up to 3 times, then proceeds without it — check stderr output for `[FAILED]` lines.

**Package pinning**: the script pins `kinsta-mcp@1.0.3` rather than running unpinned `npx -y kinsta-mcp`, so behavior doesn't silently change between runs when a new version publishes.

**Credential note**: passing `KINSTA_API_KEY`/`KINSTA_COMPANY_ID` inline on the command line can leak secrets via shell history or `ps aux`. Prefer exporting them in the current shell session (`export KINSTA_API_KEY=...`) rather than prefixing the command, when running interactively.

### Step 3: Analyze
Run the bundled script. **Default: last 24 hours.** Add `--hours N` for other windows:
```bash
# Default (24 hours):
python3 .roo/skills/kinsta-log-analyzer/scripts/analyze_logs.py \
  "$DIR/${TS}_error.json" \
  "$DIR/${TS}_access.json" \
  "$DIR/${TS}_cache.json"

# Custom timeframe (e.g., last 3 hours, last 72 hours):
python3 .roo/skills/kinsta-log-analyzer/scripts/analyze_logs.py \
  "$DIR/${TS}_error.json" \
  "$DIR/${TS}_access.json" \
  "$DIR/${TS}_cache.json" \
  --hours 3

# Skip geo-IP lookups (privacy/speed — no data sent to ipinfo.io, fully deterministic):
python3 .roo/skills/kinsta-log-analyzer/scripts/analyze_logs.py \
  "$DIR/${TS}_error.json" "$DIR/${TS}_access.json" "$DIR/${TS}_cache.json" --no-geoip
```

**Privacy note**: by default, `ip_country()` sends each unique visitor IP to `ipinfo.io` over the network to resolve a country code — this is the only non-deterministic, network-dependent part of the script (results are cached per-run to avoid duplicate lookups). Pass `--no-geoip` to keep the analysis fully local and deterministic; the report will show "unknown" instead of a country.

The script filters entries to the requested time window and produces:
- **⚠️ Time-window disclosure** — flags when the error log's timerange doesn't overlap the access log's (shorter) window, so a "0" status-code count isn't mistaken for "no errors happened"
- **🔴 Critical / 🟡 Warnings / 🟢 Informational** — each PHP Fatal/Parse/Warning/Notice/Deprecated is grouped by its actual (file, line) signature with the **real extracted message**, occurrence count, first/last seen, and any client IP(s)/request(s) recorded on that log line — not a generic canned tip
- **🟢 Other PHP/stderr messages** — anything that didn't match a known PHP severity still shows up with actual sample text (never silently dropped)
- **🔎 Status-code drill-down** — for every 4xx/5xx code present, the top URLs and distinct IP count behind it
- **🤖 Bot categorization** — bot traffic split into AI Assistant/Answer Engine, Search Engine, SEO/Marketing, Social Media, and Aggressive/Scanner buckets (heuristic by User-Agent)
- **🔗 Cross-Log Correlations** — Cache↔access matches, most cache-MISSed URLs, error↔access pairs
- **📈 Traffic at a Glance** — Status codes, response times, bot traffic, top IPs (with geo-IP unless `--no-geoip`)
- **📊 Edge Cache Health** — HIT/MISS/BYPASS with verdict and optimization steps (shown as *no cache data*, not a misleading 0%, when `cache.json` is absent/empty)

### Step 4: Open Report
The script auto-opens the report in VS Code. Present the key findings to the user.

### Step 5: Present Report
Present a concise summary to the user confirming the report is open and listing the top findings.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `NETWORK_ERROR` on cache-perf | Transient Kinsta API issue | Retry up to 3x with 3s sleep between |
| Tool name not found (e.g. `kinstasiteslist`) | Roo Code strips dots from tool names | Use stdio JSON-RPC via `execute_command` |
| `Validation error: Invalid enum value` | Used `error.log` instead of `error` | Use bare names: `error`, `access`, `kinsta-cache-perf` |
| Cross-file analysis empty | Error and access logs don't overlap in time | Use `"lines":3000` for the access log for better overlap |
| Report file not found | Wrong timestamp used | Check `$DIR` for the generated `*_report.md` |

---

## Files

| File | Purpose | Action |
|---|---|---|
| [`scripts/fetch_logs.sh`](scripts/fetch_logs.sh) | Parallel log fetch with per-log retry, pinned `kinsta-mcp` version | **Execute** in Step 2 |
| [`scripts/analyze_logs.py`](scripts/analyze_logs.py) | Log analysis + cross-file correlation (local parsing is deterministic; geo-IP lookups are not — see `--no-geoip`) | **Execute** in Step 3 |
| [`references/operational-playbook.md`](references/operational-playbook.md) | Expert server guidance for each anomaly type | **Read** when the report flags an issue needing deeper action |

## Configuration
Reads credentials from `.roo/mcp.json` → `mcpServers.kinsta.env`.

## Privacy & Retention
- Visitor IPs from the access/error logs are written to disk under `~/Downloads/kinsta-logs/` and are not automatically cleaned up — periodically prune old report directories if this is a concern.
- Unless `--no-geoip` is passed, visitor IPs are also sent to the third-party `ipinfo.io` service for country lookup during Step 3.
- The generated report embeds raw visitor IPs and is opened in VS Code; treat it like any other file containing visitor data.

## Output Structure
```
~/Downloads/kinsta-logs/
└── {site_name}/
    └── {env_name}/
        ├── {YYYY-MM-DD_HHMMSS}_error.json
        ├── {YYYY-MM-DD_HHMMSS}_access.json
        ├── {YYYY-MM-DD_HHMMSS}_cache.json
        └── {YYYY-MM-DD_HHMMSS}_report.md
```
