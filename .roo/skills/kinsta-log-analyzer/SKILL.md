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

## Report Audience & Purpose

The output is not a raw data dump — it's **an insightful, narrative report meant to be presented
to management**, structured to (a) let a non-technical reader grasp site health in under a minute
via "At a Glance," and (b) guide **specific, informed corrective action** for each finding, sourced
from Kinsta's own current documentation, not generic hosting advice. Every section below exists in
service of this: the script's raw tables are the evidence; the Analyst Commentary (Step 6) is where
that evidence becomes a narrative a business owner can act on without needing to interpret a table
themselves. **Never recommend an action by referencing this skill's own host codebase** (file
paths, function/constant names, config files of the WordPress plugin being hosted) — the report's
reader manages *infrastructure*, not this repo. Every action must be something doable from the
MyKinsta panel or sourced from an actual Kinsta KB article (cited in Step 6.6); if no such action
exists for a finding, say so plainly rather than inventing a code-level fix.

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
- **The API hard-caps `lines` at 20,000** — a request above that fails with `VALIDATION_ERROR:
  Number must be less than or equal to 20000`. This is the true ceiling on how far back any single
  fetch can reach, regardless of the site's traffic volume.
- The `file_name` parameter accepts bare names (`error`) — do NOT append `.log` suffix
- **Kinsta rotates `access.log` roughly daily** (confirmed: rotated filenames follow
  `access.log-YYYY-MM-DD-<unix-timestamp>`) — but `kinsta.logs.get` transparently spans rotation
  boundaries when `lines` exceeds the current unrotated file's size, so a normal fetch does not
  need manual rotation-file handling.
- **`access.log` is origin-server traffic only — confirmed (do not re-derive this each run): it does
  not include requests Cloudflare's edge cache served without ever reaching Kinsta's origin server**
  (300+ global PoPs — see Kinsta's own [Edge Caching docs](https://kinsta.com/docs/wordpress-hosting/caching/edge-caching)).
  **This was verified against a site's actual downloaded rotated log files** — re-fetching at the
  20,000-line ceiling and manually reconstructing the same 24h window from raw files both agreed
  with the original fetch to within 6 requests, ruling out sampling/truncation as the cause of any
  gap. **Do not default to a "the dashboard's date range must be longer than 24h" explanation
  without the user confirming their dashboard's window** — a same-order-of-magnitude coincidence in
  a multi-day total is not evidence of anything; always ask/confirm the dashboard's actual reported
  window before proposing a time-window mismatch, and prefer the origin-vs-edge-cache explanation as
  the primary hypothesis when the dashboard figure is confirmed to be a genuine 24h count (one HTML
  request can carry dozens of edge-cached sub-resource requests MyKinsta's Analytics counts but
  `access.log` never sees). State this origin-vs-edge scope distinction explicitly in the report's
  Traffic Overview section whenever the user cites a higher dashboard number for the same window.

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
5. **Read [`references/site-context.md`](references/site-context.md)** — it records the admin's
   and business owner's timezones and each site's confirmed primary visitor market. This is
   required context for interpreting traffic-hour patterns and geo-IP results correctly in Step 6;
   skipping it leads to misreading normal local business hours as "anomalies." If the site being
   analyzed has an `unknown — ask` entry, ask the user once via `ask_followup_question`, then
   persist the answer into that file via `apply_diff` before continuing — never ask twice for the
   same fact.

### Step 2: Fetch Logs + Baseline Probe (parallel)

**Execution order matters** — fetch the logs and probe the *fixed* sample URL set at the same
time, not sequentially, because the fixed URLs don't depend on any analysis result and probing
them close to the log-fetch time gives a more temporally-consistent snapshot than waiting until
after analysis finishes. Only the *dynamic* URLs (chosen from findings) wait for Step 4.

1. Generate a single timestamp, then **execute** [`scripts/fetch_logs.sh`](scripts/fetch_logs.sh) to fetch all three logs in parallel with retries — this encapsulates the fetch+retry logic deterministically instead of hand-writing bash each run:

   ```bash
   TS=$(date -u +%Y-%m-%d_%H%M%S)
   DIR=~/Downloads/kinsta-logs/{site_name}/{env_name}
   mkdir -p "$DIR"

   KINSTA_API_KEY="..." KINSTA_COMPANY_ID="..." \
     bash .roo/skills/kinsta-log-analyzer/scripts/fetch_logs.sh "$ENV_ID" "$DIR" "$TS"
   ```

2. **At the same time** (a separate tool call, not sequentially blocking on step 1), **execute**
   [`scripts/probe_urls.py`](scripts/probe_urls.py) against the fixed sample URL list from
   [`references/site-context.md`](references/site-context.md) → "Known Probe URLs" for this site:

   ```bash
   python3 .roo/skills/kinsta-log-analyzer/scripts/probe_urls.py \
     --output "$DIR/${TS}_probe_baseline.json" \
     https://site.example/robots.txt https://site.example/ ...
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
Run the bundled script. **Default: last 24 hours.** Add `--hours N` for other windows. The script
prints the generated report's path on its last line (`📄 <path>`) — capture it as `$REPORT_PATH`,
since Steps 4/6/7/8 all reference the report by that path, not by `$DIR`:
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

**Report location**: the report is NOT written into `$DIR` — it's written to a single flat
`~/Downloads/kinsta-logs/reports/` folder (created automatically), named
`report_{site_name}_{env_name}_{YYYYMMDDHHMM}.md`, so reports from every site/environment/run stay
browsable in one place without digging through per-site subfolders. The raw `_error.json`/
`_access.json`/`_cache.json`/`_probe_*.json` files remain in `$DIR` as before.

**Privacy note**: by default, `ip_country()` sends each unique visitor IP to `ipinfo.io` over the network to resolve a country code — this is the only non-deterministic, network-dependent part of the script (results are cached per-run to avoid duplicate lookups). Pass `--no-geoip` to keep the analysis fully local and deterministic; the report will show "unknown" instead of a country.

**⚠️ If you are iterating on the script itself (debugging/testing a change to `analyze_logs.py`),
run it against scratch-copied log files in `/tmp/`, never against the real `$DIR` log files whose
resulting `$REPORT_PATH` the user is actively reviewing.** The script always regenerates its entire
report file from scratch on every run — any Analyst Commentary manually appended in Step 6 is NOT
part of that generation and gets silently destroyed by a later test run that resolves to the same
`reports/` filename (same site/env/timestamp). This has happened before during this skill's own
development. Copy the log JSON files to a scratch directory first and run against those; only run
against the real `$DIR` log files as the deliberate final step, immediately followed by
re-appending Step 6's commentary in the same batch of work — never leave the live report in a
regenerated-but-uncommented state between messages.

The script filters entries to the requested time window and produces:
- **⚠️ Time-window disclosure** — flags when the error log's timerange doesn't overlap the access log's (shorter) window, so a "0" status-code count isn't mistaken for "no errors happened"
- **🔴 Critical / 🟡 Warnings / 🟢 Informational** — each PHP Fatal/Parse/Warning/Notice/Deprecated is grouped by its actual (file, line) signature with the **real extracted message**, occurrence count, first/last seen, and any client IP(s)/request(s) recorded on that log line — not a generic canned tip
- **🟢 Other PHP/stderr messages** — anything that didn't match a known PHP severity still shows up with actual sample text (never silently dropped)
- **🔎 Status-code drill-down** — for every 4xx/5xx code present, the top URLs and distinct IP count behind it
- **🤖 Bot categorization** — bot traffic split into AI Assistant/Answer Engine, Search Engine, SEO/Marketing, Social Media, and Regional/Compliance-Unverified buckets (heuristic by User-Agent), **each bot annotated with a URL-concentration pattern** (`⚠️ concentrated: NN% on 1 URL` vs. `distributed: N distinct URLs`) plus distinct-IP count and a category Totals row, and the highest-volume bot per category gets its actual top-5 URLs listed in a collapsible block — this is the evidence Step 6 must cite, not something to infer from the count alone
- **🔗 Cross-Log Correlations** — Cache↔access matches, most cache-MISSed URLs, error↔access pairs
- **📈 Traffic at a Glance** — Status codes, response times, bot traffic, top IPs with **both geo-IP country and ASN/hosting-provider org** (unless `--no-geoip`) — a "hosting/proxy" flag means the country tag is infrastructure location, not a visitor's location; the report explicitly warns about this rather than letting it be misread
- **📊 Edge Cache Health** — HIT/MISS/BYPASS with verdict and optimization steps (shown as *no cache data*, not a misleading 0%, when `cache.json` is absent/empty)

### Step 4: Targeted URL Probe (Dynamic, Post-Analysis)

**Execute** [`scripts/probe_urls.py`](scripts/probe_urls.py) again — this second pass probes only
the URLs that Step 3's analysis actually flagged, since those can't be known before analysis runs
(unlike Step 2's fixed baseline probe). Both probe passes are real-time snapshots of right now, not
the log window, and Step 6 must state that distinction explicitly whenever it cites either one.

1. **Build the dynamic URL list from the report you just generated**: the top cache-MISSed URL,
   the slowest page, and the top 404/403 URL (skip any 404 URL that is itself an obvious
   spam-injection payload — probing it live adds nothing).
2. **Run it**:
   ```bash
   python3 .roo/skills/kinsta-log-analyzer/scripts/probe_urls.py \
     --output "$DIR/${TS}_probe_targeted.json" \
     https://site.example/top-missed-url-from-report \
     https://site.example/slowest-page-from-report \
     https://site.example/top-404-from-report
   ```
3. **Read both probe JSON files** (`_probe_baseline.json` from Step 2 and `_probe_targeted.json`
   from this step) and cross-match against the log-derived report:
   - Does the live `http_code` match what the log window showed for that URL? A mismatch (e.g.
     log shows `200`, live probe shows `404`) means content changed between the log window and
     now — state this explicitly, don't treat it as a report error.
   - Does a `Set-Cookie` header appear on a public page? This directly confirms/refutes any BYPASS
     hypothesis from the Cache Root Cause Analysis — cite the actual cookie name (e.g. `__cf_bm` is
     Cloudflare Bot Management, not a WordPress/Polylang cookie — don't misattribute it).
   - Does `x-kinsta-cache` or an equivalent cache-status header confirm the log's HIT/MISS/BYPASS
     pattern for that specific URL right now?
   - Is `time_total` for the live probe consistent with the log's `avg_rt`/slowest-pages data, or
     does it suggest the site has gotten faster/slower since the log window closed?

### Step 5: Open Report
The script auto-opens the report in VS Code.

### Step 6: Analyst Commentary (Critical Reasoning)

**This is LLM reasoning, not automated — every analysis is unique.** This is also the step users
judge the skill's expertise on — mediocre, generic advice here (e.g. "add Crawl-Delay for AI bots,"
"block Chinese bots because they're Chinese") is a failure of this step, not an acceptable
approximation. After the script generates the base report, you MUST:

1. **Read the generated report** (`read_file` on the `*_report.md`) and both probe JSON files —
   `_probe_baseline.json` from Step 2 and `_probe_targeted.json` from Step 4.

2. **Read [`references/bot-taxonomy.md`](references/bot-taxonomy.md) in full before writing any
   bot-related recommendation.** It contains the accurate nature of each bot (crawler vs.
   real-time user-triggered agent), the actual robots.txt/Crawl-Delay compliance matrix, and the
   unbiased three-question assessment framework. Two hard rules from that file apply to every
   report, no exceptions:
   - **Never recommend `Crawl-Delay:` for a bot without documented support for it** (only Bingbot,
     YandexBot, AhrefsBot, SemrushBot, MJ12bot have it — see the compliance matrix). For every
     other bot, the only real levers are `Disallow` (best-effort) or a hard block/throttle.
   - **Never justify a keep/block verdict by the bot operator's country of origin.** Apply the same
     three questions (nature, compliance, audience relevance) to every bot and show your work —
     see the "Regional / High-Volume Crawlers" table for the reference example of doing this
     correctly (Amazonbot: US-operated but low relevance ≠ automatic keep; Bytespider:
     China-operated but the *actual* disqualifying facts are documented non-compliance + low
     audience relevance, not its origin).

3. **Check [`references/site-context.md`](references/site-context.md) against the report's
   traffic-hour and top-IP data**:
   - Convert flagged hour-of-day anomalies to **both** the admin's and the business owner's local
     time before calling anything a spike — a "spike" during the target market's normal business
     hours is not an anomaly.
   - For every top IP or scanner IP flagged as `hosting/proxy` in the ASN/Provider column, do not
     describe it as a visitor from that country — describe it as infrastructure, and say so
     explicitly with the actual org string as evidence.
   - **Check the Reverse DNS column too, not just ASN/Provider — ASN alone is not enough.** An ASN
     of "Google LLC" does NOT mean Google's own crawler; it only means the IP is on Google Cloud's
     network. A confirmed real miss from an earlier run: an IP reported as "Google LLC" was
     initially left unattributed, when its actual PTR record (`*.googleusercontent.com`) shows it's
     an unrelated third party's customer VM. Conversely, a PTR under the bot's own domain (e.g.
     `bot.semrush.com`, `dataproviderbot.com`) is positive confirmation the traffic is genuinely who
     it claims to be. See [`bot-taxonomy.md`](references/bot-taxonomy.md#asn-is-not-enough--always-check-reverse-dns-too)
     for the known-pattern table before writing any "who is this IP" conclusion.
   - If the confirmed primary market is known (e.g. Georgia for pbservices.ge) and RU/ZH-language
     traffic appears, do not flag it as suspicious by default — that language segment may be the
     actual target market. Only flag it when the specific URL/payload is itself a spam/injection
     pattern.
   - **Business-owner Easter egg (evidence-gated, do not force it):** if a top IP's geo/ASN
     plausibly resolves to the business owner's location (Tbilisi, per `site-context.md`) AND that
     IP's behavior shows obsessive-checking (unusually high request count on its own, or repeated
     hits on the same page/admin path), you may add ONE concise, tasteful, sarcastic one-liner
     about checking one's own site obsessively — in the finding's Interpretation, not as a
     separate section. If no such evidence exists, do not add a joke; fabricating one to be funny
     violates the no-fabrication rule and is worse than no joke at all.

4. **Consult the per-bot URL-concentration data and the Concentrated Traffic Spikes & Bursts
   section already in the report** — do not guess whether a bot's traffic is "targeted" or
   "distributed." The report states the actual distinct-URL count, top-URL share, per-bot top-IP
   share, and lists the real top-5 URLs for the highest-volume bot per category. Cite these numbers
   directly (e.g. "ChatGPT-User's 641 requests spread across 160 distinct URLs, with its top 5
   being cost-of-living/neighborhood blog posts — consistent with real users asking ChatGPT about
   relocating to Georgia, not bulk scraping" vs. "ClaudeBot: 164 of 183 requests (90%) from a
   single IP, all to `/robots.txt` — this is a burst, not normal crawling behavior").

5. **Do not read, search, or open ANY file in the hosted plugin/theme codebase for this step, or
   at any other point in this skill.** This skill's own Scope (top of this file) already says it
   "does NOT diagnose PHP code bugs, WordPress plugin conflicts, or database queries" — that
   includes not reading the code "just to check," even privately. Bot-mitigation recommendations
   are decided **exclusively** from: (a) a MyKinsta-panel action (Denied IPs, Edge Caching
   exclusions, etc.), (b) a Kinsta support ticket for a WAF/`limit_req` rule, or (c) an honest,
   generic "no Kinsta-panel or documented fix exists for this pattern — flag it for whoever
   maintains the site's code" when neither (a) nor (b) applies. Never open `config/`, `includes/`,
   or any other plugin source path to check for an existing mitigation — the report's severity
   judgment is based only on the log data itself (e.g. request/IP concentration), never on
   anything read from the site's own code.

6. **Look up the current Kinsta Knowledge Base for any 🔴/🟡 finding** using `tavily-search` scoped
   to `kinsta.com` (e.g. `site:kinsta.com/knowledgebase edge caching bypass query strings`). Cite
   the specific article/URL found and summarize its guidance — this is what elevates
   recommendations from "generic hosting advice" to "what Kinsta support would actually tell you,"
   sourced from Kinsta's own current documentation rather than assumed from memory. If no relevant
   article is found, say so rather than fabricating a citation. **This is the "How" — never a
   boilerplate tip.** A live Kinsta KB citation, a MyKinsta-panel action, or an honest "no
   documented Kinsta-side fix — flag for your developer" (phrased generically, never naming this
   codebase) are the only three acceptable answers to "how do I fix this."

7. **Structure every finding around four questions internally — What / Why / Who / How** — this is
   the analytical spine you use to REASON through each finding, but it is not what the reader sees.
   The **visible labels in the report are always Incident / Analysis / Actor / Actions** (Step 6.8
   spells out the exact template) — "What/Why/Who/How" is your private checklist, never printed:

   | Internal question | Visible label | Answers |
   |---|---|---|
   | What? | **Incident** | The flagged finding, stated with its exact evidence (numbers, URLs, IPs). |
   | Why? | **Analysis** | Why it's suspicious or anomalous — cross-referencing bot-taxonomy.md/site-context.md/probe results as applicable. Ordinary/expected activity gets "why this is NOT an anomaly" instead. |
   | Who? | **Actor** | The actor (bot name, IP, or "unknown") PLUS an explicit classification tier — see Step 6.8's tier scale — never just prose judgment. |
   | How? | **Actions** | The concrete action, sourced from live Kinsta KB documentation (Step 6.6), a MyKinsta-panel step, or **bold "No action required" with a ✅** — never a canned tip disconnected from this finding's actual evidence, and never anything derived from reading the hosted app's own source code (Step 6.5 forbids opening it at all). |

   Cross-cutting lenses to apply this framework to: attack patterns (spam injection, xmlrpc
   probing), traffic anomalies (hour spikes — state the multiplier, convert to local time per Step
   6.3), bot strategy (per bot-taxonomy.md), cache root cause (cite top-missed URLs/query
   params/probe header evidence), 404/error triage, and IP/geo sanity (hosting/proxy flags).

8. **Append an `## 📋 Analyst Commentary & Recommendations` section** to the report using
   `apply_diff`. Use a **finding-card format** for the Traffic Anomalies, Attack/Security Findings,
   and Concentrated Bursts subsections specifically — freeform prose paragraphs are not acceptable
   there. **Each of Incident/Analysis/Actor/Actions MUST be its own bullet list item** (not
   consecutive bold-label lines in one paragraph) — list items are the only Markdown construct
   guaranteed to render on separate lines across every renderer; runs of `**Label:** text` lines
   without blank lines between them visually collapse into one paragraph in several renderers,
   which was the primary readability complaint against earlier versions of this skill's output.

   **Tone calibration — avoid both extremes, every time.** This has been a repeated failure mode
   in both directions and must be checked explicitly before finalizing any Overall Assessment,
   At a Glance status line, or card verdict:
   - **Too alarmist:** dressing up routine housekeeping (a stale cache entry, a low-value crawler,
     a missing trailing slash) in emergency language, or a severity icon one tier higher than the
     evidence supports (see the icon table below — 🔴 is reserved, not a default).
   - **Too dismissive:** the opposite failure, and equally wrong — waving away a real, measurable,
     currently-below-target metric (e.g. a cache HIT rate sitting at 24–46% against a >70% target)
     with casual language like "minor housekeeping, nothing urgent" or "nothing to see here." A
     below-target metric with a concrete, evidence-backed fix is a genuine 🟡 finding worth an
     accurate, specific description — not a shrug.
   - **The correct register:** state the actual measured severity in plain, professional, objective
     terms, exactly as supported by the evidence — no more, no less. "Cache HIT rate is 24%, well
     below the >70% target, driven by two identified causes — fixable, but currently costing real
     performance" is calibrated. "Nothing urgent" is not, when a metric is sitting at a third of
     target.
   - **Avoid cheerleading-style status openers even when a real caveat follows** — e.g. "Overall
     status: healthy and secure, with one issue worth fixing" still reads as dismissive by leading
     with reassurance before the finding. Prefer a neutral, factual lead: **"No active security
     incidents; [metric] is below target and requires attention"** — state what was and wasn't
     found, in that order, without an adjective doing the reader's judgment for them.
   - Re-read every summary line against this test before finalizing: *would someone who only reads
     this one sentence come away with an accurate impression of how serious this actually is — not
     more dramatic, not more reassuring?*

   **Severity icon vocabulary — do not mix these two axes up:**
   | Icon | Reserved for |
   |---|---|
   | 🔴 | A genuine, active emergency — site down, active security breach, data at risk. Reserve this; do not use it for routine housekeeping (a stale cache file, a misbehaving bot, a low-value crawler) even if the fix is "high priority." |
   | 🟡 | A real concern worth attention soon, but not actively harming anyone right now. |
   | ✅ / 🟢 | Healthy, or handled correctly already — no action needed. |
   | 🔧 | A worthwhile housekeeping/maintenance action — NOT a health/security severity. Use this (never 🔴/🟡) for "add this bot to the throttle list," "flush this stale cache directory," "block this low-value crawler." |

   **Actor classification tier** (required in every card's Actor line): `Safe` / `Benign` /
   `Suspicious` / `Malicious` — a single word from this exact scale, stated plainly, not implied by
   the card's icon alone (the icon is about urgency, the tier is about intent — they are different
   axes and both must be stated).

   Template:

   ```markdown
   #### 🔴|🟡|🔧|✅ [Short title]
   - **Incident:** [exact evidence — numbers/URLs/IPs from the report, or "not observed in this window"]
   - **Analysis:** [interpretation — is this suspicious, and why/why not]
   - **Actor:** [who/what + classification tier: Safe/Benign/Suspicious/Malicious + targeted URL(s)]
   - **Actions:** [concrete action per Step 6.6, or **bold "No action required"** with a ✅]
   ```

   Full section structure:
   - **Overall Assessment** — one-paragraph verdict with severity (✅ healthy / 🟡 concerns / 🔴 action needed)
   - **Discrepancy Notes** (if user mentioned dashboard numbers) — explain gaps honestly
   - **Attack/Security Findings** (if any) — Incident/Analysis/Actor/Actions card format, one card per distinct pattern
   - **Cache Root Cause Analysis** — go beyond "HIT rate is low" to identify the specific mechanism, evidence-cited, informed by both probe passes' live header data (Steps 2 & 4)
   - **Bot Traffic Strategy** — table (bot | verdict | evidence) showing which to keep/throttle/block and why, **plus a Totals row** (sum of requests, % of all bot traffic) — never a keep/block verdict decided by country of origin alone. `bot-taxonomy.md` informs your reasoning (Step 6.2) but is never cited as a column in the report — it's an internal skill reference file the reader has no access to, exactly like the hosted app's source code (Step 6.5). The "Evidence" column must stand on its own in plain language (the actual numbers/behavior), not point anywhere the reader can't open.
   - **Concentrated Traffic Spikes & Bursts** — Incident/Analysis/Actor/Actions card format for anything flagged in the report's own Bursts section (Step 3's script output); use 🔧, not 🔴, unless the pattern is an active attack, not just nuisance polling — name the actor IP/bot and the specific target URL(s) explicitly
   - **Traffic Anomalies** — Incident/Analysis/Actor/Actions card format, one card per spike/pattern, with admin/owner local-time conversion
   - **404/Error Fix Recommendations** — triaged list of what's worth fixing, with counts; use 🔧 (High/Medium priority) / ℹ️ (Low priority) — never 🔴/🟡, since these are housekeeping items, not health emergencies
   - **Live Probe Cross-Match** — bullet list of what both probe passes (Steps 2 & 4) confirmed or contradicted from the log-derived analysis
   - **Kinsta KB References** — bullet list of every KB article looked up in Step 6.6, with URL and one-line summary of its relevant guidance

9. **Write and place the `## 📌 At a Glance` section — written LAST, placed near the TOP.** This is
   the management-facing summary answering exactly two questions: *did any anomalies occur in this
   period, and what important actions do we need to take?* It must be written after everything
   above (it summarizes findings you haven't derived yet until now), but it belongs near the very
   TOP of the document — immediately **after** the "Time Period" section (so the reader sees the
   report's actual data coverage before the interpretive summary) and before "Health Summary" — not
   appended at the bottom with everything else. Since `apply_diff` in this mode can only touch
   files under `.roo/skills*` and the report lives elsewhere, use `execute_command` (e.g. a short
   Python or `sed` insertion) to insert it at that specific position — a plain append would bury it
   at the bottom where a management reader would never scroll to find it. Structure:
   - One-line overall status with severity icon
   - **Anomalies found in this period** — bullet list, one line per distinct finding, severity icon per line, plain-language (no jargon a manager wouldn't know)
   - **Priority actions this period** — numbered list, ordered by urgency, each action concrete enough to hand to whoever's fixing it (cite the specific file/setting per Step 6.5/6.6, not "check the cache settings")

9a. **Always bracket the "Time Period" section with a short, plain-language MyKinsta-vs-report
    scope warning — mandatory on every run, not just when a discrepancy comes up.** The
    business-owner reader will almost certainly have seen a MyKinsta dashboard total at some point,
    and this report's request count is always the smaller, origin-only figure (see the "How Logs
    Are Retrieved" origin-vs-edge-cache note near the top of this file) — silence on this point is
    what causes confusion, not the gap itself. **Keep both notes short — 1–2 sentences each, max
    ~30 words apiece; this is a pointer, not an explanation** (the full technical version already
    lives in "Traffic Overview" — do not duplicate it here). Insert via the same `execute_command`
    mechanism as Step 9, in the same pass:
    - **Immediately BEFORE** `## Time Period`, one line in this register (adapt the wording, keep
      the length, keep it neutral/factual — not defensive-sounding phrasing like "before you
      compare..."): *"⚠️ Scope note: this report counts only requests that reached the server —
      not traffic Cloudflare's edge cache served directly. MyKinsta's own 24h total will be higher;
      that's expected."*
    - **Immediately AFTER** the Time Period list, before `## 📌 At a Glance`, one line: *"ℹ️ The
      window above is accurate for server-side activity only, not total site traffic — see 'Traffic
      Overview' below for details."*

10. **Final review pass — SILENT, no visible report section.** After At a Glance is written (not
    before — it must review the complete document, including that section), re-read the ENTIRE
    assembled report once, with the target audience (Step "Report Audience & Purpose" at the top of
    this file) in mind. Check specifically for: (a) every number quoted in At a Glance matches the
    detailed section it summarizes — no drift between the summary and the evidence; (b) no redundant
    restatement of the same finding in two different sections with different framing; (c) narrative
    tone throughout — a manager should be able to read this without translating jargon. **This step
    produces zero visible output when the report already passes** — do not add a "Final Review
    Note," "QA Note," or any section narrating that a review happened; that's process commentary
    about your own work, not analysis the reader needs, and reads as if it were left in by mistake.
    If you find an actual inconsistency, silently fix it in place via `apply_diff`/`execute_command`
    (edit the wrong section directly) — the existence of a fix is invisible; only the corrected
    report is visible. Do not defer any fix found here to Step 7.

11. **Be honest about uncertainty.** If the data doesn't answer a question, or a Kinsta KB search
    found nothing relevant, say so — do not fabricate explanations or citations.

### Step 7: Export PDF

**Execute** [`scripts/export_pdf.sh`](scripts/export_pdf.sh) against the FINAL report — only after
Step 6's Analyst Commentary, At a Glance, and silent final review are already written to disk,
since the PDF is a snapshot of whatever the Markdown file contains at the moment it runs:

```bash
bash .roo/skills/kinsta-log-analyzer/scripts/export_pdf.sh "$REPORT_PATH"
```

Uses `md-to-pdf` via `npx` (a small package, not Puppeteer's full bundle) driven by the system's
already-installed Chromium (`/usr/bin/chromium`) — `PUPPETEER_SKIP_DOWNLOAD`/
`PUPPETEER_EXECUTABLE_PATH` stop Puppeteer from downloading its own ~300MB Chromium copy. Also
applies [`scripts/report.css`](scripts/report.css) and an explicit `--pdf-options` override.

**Both `--stylesheet` and `--pdf-options` REPLACE `md-to-pdf`'s defaults, they do not merge with
them** (confirmed by reading `md-to-pdf`'s own source) — this is why `report.css` is a complete,
self-contained stylesheet (not just a diff of a few properties) and why `--pdf-options` re-states
`format:"a4"` even though only the `margin` value actually needed changing (the tool's own default
margin is asymmetric: `top:30mm/right:40mm/bottom:30mm/left:20mm`). Output is
`{report_path minus .md}.pdf` in the same `reports/` folder. If Chromium isn't present at that path,
the script exits with a warning instead of failing the run — the Markdown report is the primary
deliverable regardless of whether the PDF export succeeds.

### Step 8: Present Report

**The chat summary must never contain an insight, number, or recommendation that isn't already
written into the report file.** Chat is a condensed pointer to the report, not a second, richer
analysis that competes with it — if you find yourself writing something more useful in the chat
response than what's in the file, that's a bug: go back and add it to the report via `apply_diff`
first, then summarize it in chat. Present a concise summary confirming the report is open, quoting
(not re-deriving) the Overall Assessment verdict and the top 3-5 findings/actions directly from the
Analyst Commentary section you just wrote, and note where the PDF was saved (or that it was skipped,
per Step 7).

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `NETWORK_ERROR` on cache-perf | Transient Kinsta API issue | Retry up to 3x with 3s sleep between |
| Tool name not found (e.g. `kinstasiteslist`) | Roo Code strips dots from tool names | Use stdio JSON-RPC via `execute_command` |
| `Validation error: Invalid enum value` | Used `error.log` instead of `error` | Use bare names: `error`, `access`, `kinsta-cache-perf` |
| Cross-file analysis empty | Error and access logs don't overlap in time | Use `"lines":8000` for the access log for full 24h coverage |
| Report file not found | Looked in `$DIR` instead of the reports folder | Reports live in `~/Downloads/kinsta-logs/reports/`, named `report_{site_name}_{env_name}_{YYYYMMDDHHMM}.md` — check `analyze_logs.py`'s printed `📄` line for the exact path |
| Analyst Commentary vanished after a later run | `analyze_logs.py` regenerates the entire report from scratch every run — a manually-appended commentary is not part of that generation and gets silently overwritten | Never re-run the script against real `$DIR` log files whose report is the one being reviewed; use scratch-copied log files (Step 3's warning). If it already happened, re-run Step 3 cleanly, then redo Steps 6.8–6.10 in the same batch of work |
| Report shows "unknown"/"no PTR record" everywhere | `--no-geoip` was passed, or `ipinfo.io` is failing/rate-limiting broadly | Check the top-of-report banner — it states which case applies. Re-run without `--no-geoip`, or wait and retry if ipinfo.io is down |
| PDF export fails/skipped | Chromium not found at `/usr/bin/chromium`, or `npx`/network unavailable | Set `CHROMIUM_BIN` to the correct path and re-run [`scripts/export_pdf.sh`](scripts/export_pdf.sh); the Markdown report is still valid on its own regardless |

---

## Files

| File | Purpose | Action |
|---|---|---|
| [`scripts/fetch_logs.sh`](scripts/fetch_logs.sh) | Parallel log fetch with per-log retry, pinned `kinsta-mcp` version | **Execute** in Step 2 |
| [`scripts/analyze_logs.py`](scripts/analyze_logs.py) | Log analysis + cross-file correlation, including per-bot URL/IP-concentration ("bursts"), ASN/hosting-provider/reverse-DNS detection, and grouped status codes (local parsing is deterministic; geo-IP/ASN/PTR lookups are not — see `--no-geoip`). **Always regenerates the full report from scratch**, and writes it to `~/Downloads/kinsta-logs/reports/` (not `$DIR`) — see Step 3's scratch-testing warning and the Troubleshooting entry above | **Execute** in Step 3 |
| [`scripts/probe_urls.py`](scripts/probe_urls.py) | Live HTTP probe (status/timing/headers) — a real-time snapshot, not historical. Run twice: baseline (fixed URLs, Step 2) and targeted (dynamic URLs from findings, Step 4) | **Execute** in Steps 2 & 4 |
| [`scripts/export_pdf.sh`](scripts/export_pdf.sh) | Converts the final Markdown report to PDF via `md-to-pdf`/`npx`, driven by the system's existing Chromium (no Puppeteer download, no pandoc) | **Execute** in Step 7, after Step 6 is fully written |
| [`scripts/report.css`](scripts/report.css) | Print stylesheet applied by `export_pdf.sh` — larger body text, `table-layout: fixed` + word-wrap so wide tables don't overflow/truncate in the PDF | Used automatically by `export_pdf.sh`; edit directly if PDF styling needs further tweaks |
| [`references/site-context.md`](references/site-context.md) | Admin/business-owner timezones, each site's confirmed primary market, and the fixed "Known Probe URLs" list per site — a living cache, update it when the user confirms new context | **Read** in Steps 1 & 2; **update** via `apply_diff` when new context is learned |
| [`references/bot-taxonomy.md`](references/bot-taxonomy.md) | Accurate, unbiased per-bot reference: real nature (crawler vs. on-demand agent), robots.txt/Crawl-Delay compliance matrix, Kinsta/WordPress-generic mitigation tiers (no hosted-app code involved — see Step 6.5), and the ASN-vs-reverse-DNS distinction | **Read in full** in Step 6 before writing any bot-related recommendation |
| [`references/operational-playbook.md`](references/operational-playbook.md) | Expert server guidance for each anomaly type (cache, errors, response time, traffic spikes, SSL) | **Read** when the report flags an issue needing deeper action |

## Configuration
Reads credentials from `.roo/mcp.json` → `mcpServers.kinsta.env`. Kinsta Knowledge Base lookups
(Step 6.6) use the `tavily` MCP server (`tavily-search`), also configured in `.roo/mcp.json`.
PDF export (Step 7) shells out to `npx md-to-pdf`, pointed at the system's Chromium binary via
`CHROMIUM_BIN` (default `/usr/bin/chromium`) — no separate install/config needed if Chromium is
already present.

## Privacy & Retention
- Visitor IPs from the access/error logs are written to disk under `~/Downloads/kinsta-logs/` and are not automatically cleaned up — periodically prune old log/report files if this is a concern.
- Unless `--no-geoip` is passed, visitor IPs are also sent to the third-party `ipinfo.io` service up to three times per unique IP — country lookup, ASN/organization lookup (`ip_org()`), and reverse-DNS/PTR lookup (`ip_hostname()`) — during Step 3.
- Both probe passes (Step 2's baseline, Step 4's targeted) send real HTTP requests to the site being analyzed (and only that site — never a third party) from wherever this skill runs; this generates a handful of extra hits in the site's own logs at probe time, self-identified via a distinctive User-Agent (`Kinsta-Log-Analyzer-Probe`) so future analysis runs recognize this as this skill's own traffic, not an unknown visitor.
- The generated report (and its PDF) embeds raw visitor IPs and is opened in VS Code; treat it like any other file containing visitor data.
- Step 6.6's Kinsta KB lookups send search queries (not visitor data) to `tavily-search`.

## Output Structure
```
~/Downloads/kinsta-logs/
├── {site_name}/
│   └── {env_name}/
│       ├── {YYYY-MM-DD_HHMMSS}_error.json
│       ├── {YYYY-MM-DD_HHMMSS}_access.json
│       ├── {YYYY-MM-DD_HHMMSS}_cache.json
│       ├── {YYYY-MM-DD_HHMMSS}_probe_baseline.json
│       └── {YYYY-MM-DD_HHMMSS}_probe_targeted.json
└── reports/
    ├── report_{site_name}_{env_name}_{YYYYMMDDHHMM}.md
    └── report_{site_name}_{env_name}_{YYYYMMDDHHMM}.pdf
```
Raw per-run logs/probes stay nested under `{site_name}/{env_name}/`; every generated report (and
its PDF) lands in a single flat `reports/` folder, since the filename itself already encodes
site, environment, and timestamp.
