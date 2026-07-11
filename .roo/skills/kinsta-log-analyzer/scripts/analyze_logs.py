#!/usr/bin/env python3
"""
Kinsta Log Analyzer — business-owner health report.
Usage: python3 scripts/analyze_logs.py <error.json> <access.json> [cache.json] [--hours N] [--no-geoip]

Note: ip_country() performs a live network call to ipinfo.io per unique IP and is
therefore NOT deterministic — results depend on network availability and a
third-party service, and visitor IPs are sent off-site for classification.
Pass --no-geoip to disable this (privacy/speed/determinism).
"""
import json, re, sys, os, argparse, subprocess
from collections import Counter, defaultdict
from datetime import datetime, timezone, timedelta

def bar_chart(value, max_val=100, width=15, fill="█", empty="░"):
    pct = min(value / max(max_val, 1), 1.0)
    n = int(pct * width)
    return fill * n + empty * (width - n)

def flag_emoji(cc):
    """Derive a flag emoji from any 2-letter ISO country code (no hardcoded table)."""
    if not cc or len(cc) != 2 or not cc.isalpha(): return ""
    return "".join(chr(0x1F1E6 + ord(c.upper()) - ord("A")) for c in cc)

GEOIP_ENABLED = True  # toggled by --no-geoip in main()
_GEOIP_CACHE = {}

def ip_country(ip):
    """Geo-IP lookup via ipinfo.io. NETWORK CALL — not deterministic, cached per-run.
    Returns (country_code, flag) or ('?', '') when disabled/unavailable."""
    if not GEOIP_ENABLED: return "?", ""
    if ip in _GEOIP_CACHE: return _GEOIP_CACHE[ip]
    try:
        r = subprocess.run(["curl", "-s", "--connect-timeout", "2", "--max-time", "3",
                           f"https://ipinfo.io/{ip}/country"], capture_output=True, text=True)
        cc = r.stdout.strip()[:2] if r.stdout.strip() else "?"
        result = (cc, flag_emoji(cc))
    except Exception:
        result = ("?", "")
    _GEOIP_CACHE[ip] = result
    return result

def ip_safety(ip, count):
    """Classify IP by observed request behavior — NOT by country of origin."""
    if ip in ("::1", "127.0.0.1"): return "localhost — do not block"
    if re.match(r"^10\.|^172\.(1[6-9]|2\d|3[01])\.|^192\.168\.", ip): return "private — do not block"
    if count >= 5: return "⚠️ repeated scanning behavior — review and consider blocking"
    return "ℹ️ low volume — monitor before blocking"

def parse_apache_ts(ts_str):
    try: return datetime.strptime(ts_str, "%d/%b/%Y:%H:%M:%S %z")
    except ValueError: return None

def parse_error_ts(ts_str):
    try: return datetime.strptime(ts_str, "%Y/%m/%d %H:%M:%S").replace(tzinfo=timezone.utc)
    except ValueError: return None

def time_ago(ts):
    if ts is None: return ""
    diff = datetime.now(timezone.utc) - ts
    if diff < timedelta(minutes=1): return "just now"
    if diff < timedelta(hours=1): return f"{int(diff.total_seconds()/60)}m ago"
    if diff < timedelta(hours=24): return f"{int(diff.total_seconds()/3600)}h ago"
    return f"{diff.days}d ago"

def extract_logs(fpath):
    if not os.path.exists(fpath): return None, "file not found"
    with open(fpath) as f: data = json.load(f)
    if data.get("isError") or data.get("result", {}).get("isError"):
        return None, data["result"]["content"][0]["text"]
    try:
        inner = json.loads(data["result"]["content"][0]["text"])
        return inner["environment"]["container_info"]["logs"], None
    except (json.JSONDecodeError, KeyError) as e: return None, f"Format: {e}"

def norm(url): return url.split("?")[0].rstrip("/") or "/"

def filter_by_hours(logs, hours, log_type):
    if hours is None: return logs, None, None, len(logs.strip().split("\n"))
    cutoff = datetime.now(timezone.utc) - timedelta(hours=hours)
    lines = logs.strip().split("\n")
    filtered, first_ts, last_ts = [], None, None
    for line in lines:
        if log_type == "error":
            m = re.match(r"(\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2})", line)
            ts = parse_error_ts(m.group(1)) if m else None
        else:
            m = re.match(r".*?\[([^\]]+)\]", line)
            ts = parse_apache_ts(m.group(1)) if m else None
        if ts is None: filtered.append(line); continue
        if first_ts is None: first_ts = ts
        last_ts = ts
        if ts >= cutoff: filtered.append(line)
    return "\n".join(filtered), first_ts, last_ts, len(lines)

# Kinsta containers use paths like /www/{site}_{id}/public/... — strip that
# generically so nothing site-specific is hardcoded into this analyzer.
_SITE_PATH_RE = re.compile(r"^/www/[^/]+/public")
def relpath(p):
    stripped = _SITE_PATH_RE.sub("", p)
    return stripped or p

def extract_client(line):
    """Client IP from an nginx error-log line, if present on that line."""
    m = re.search(r"client: ([0-9a-fA-F:.]+)", line)
    return m.group(1) if m else None

def extract_request(line):
    """The 'METHOD /url HTTP/x.x' request string from an nginx error-log line, if present."""
    m = re.search(r'request: "([^"]+)"', line)
    return m.group(1) if m else None

# PHP error/warning/notice lines end either "... in FILE on line N" or "... in FILE:N"
_PHP_SIG_ON_LINE = re.compile(
    r"PHP message:\s*PHP (Fatal error|Parse error|Warning|Notice|Deprecated):?\s+(.*?)\s+in\s+(\S+)\s+on\s+line\s+(\d+)"
)
_PHP_SIG_COLON = re.compile(
    r"PHP message:\s*PHP (Fatal error|Parse error|Warning|Notice|Deprecated):?\s+(.*?)\s+in\s+(\S+):(\d+)"
)

def extract_php_signature(line):
    """Extract (severity, message, relative_file, line_no) from a PHP error-log line, or None."""
    m = _PHP_SIG_ON_LINE.search(line) or _PHP_SIG_COLON.search(line)
    if not m: return None
    severity, msg, file_path, lineno = m.groups()
    return severity, msg.strip(), relpath(file_path), lineno

# Heuristic bot categorization (by published User-Agent) — verify before blocking.
BOT_CATEGORIES = {
    "GPTBot": "🤖 AI Assistant / Answer Engine",
    "ChatGPT-User": "🤖 AI Assistant / Answer Engine",
    "OAI-SearchBot": "🤖 AI Assistant / Answer Engine",
    "PerplexityBot": "🤖 AI Assistant / Answer Engine",
    "Google-Extended": "🤖 AI Assistant / Answer Engine",
    "ClaudeBot": "🤖 AI Assistant / Answer Engine",
    "Bytespider": "🤖 AI Assistant / Answer Engine",
    "Anthropic-ai": "🤖 AI Assistant / Answer Engine",
    "Googlebot": "🔍 Search Engine Crawler",
    "Bingbot": "🔍 Search Engine Crawler",
    "YandexBot": "🔍 Search Engine Crawler",
    "Baiduspider": "🔍 Search Engine Crawler",
    "DuckDuckBot": "🔍 Search Engine Crawler",
    "AhrefsBot": "📈 SEO / Marketing Crawler",
    "SemrushBot": "📈 SEO / Marketing Crawler",
    "MJ12bot": "📈 SEO / Marketing Crawler",
    "Dataprovider": "📈 SEO / Marketing Crawler",
    "facebookexternalhit": "📱 Social Media Bot",
    "Twitterbot": "📱 Social Media Bot",
    "Discordbot": "📱 Social Media Bot",
    "LinkedInBot": "📱 Social Media Bot",
    "Applebot": "📱 Social Media Bot",
    "PetalBot": "⚠️ Aggressive / Scanner Bot",
    "Amazonbot": "⚠️ Aggressive / Scanner Bot",
}

# ═══════════════════════════════════════════════════════════════════
# ANALYZE
# ═══════════════════════════════════════════════════════════════════

def analyze_error_log(logs):
    findings = {"critical": [], "medium": [], "low": []}
    lines = logs.strip().split("\n")
    all_ts = re.findall(r"(\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2})", logs)
    ts_range = f"{all_ts[0]} → {all_ts[-1]}" if all_ts else "N/A"
    ips = re.findall(r"client: (\d+\.\d+\.\d+\.\d+)", logs)
    ip_counter = Counter(ips)
    error_entries = []

    scanner_paths = Counter()
    for m in re.finditer(r'directory index of "([^"]+)"', logs):
        scanner_paths[relpath(m.group(1))] += 1
    scanner_ips = Counter(re.findall(r"directory index.*?client: (\d+\.\d+\.\d+\.\d+)", logs, re.DOTALL))

    SEV_MAP = {"Fatal error": "critical", "Parse error": "critical",
               "Warning": "medium", "Deprecated": "low", "Notice": "low"}

    # Group PHP messages by (severity, file, line) — one real bug = one finding,
    # with actual client IPs / requests attached when the log line records them.
    signatures = {}
    other_stderr = Counter()
    ssl_ts, conn_ts = [], []

    for line in lines:
        ts_m = re.match(r"(\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2})", line)
        ts = parse_error_ts(ts_m.group(1)) if ts_m else None

        sig = extract_php_signature(line)
        if sig:
            severity_word, msg, file_path, lineno = sig
            key = (severity_word, file_path, lineno)
            d = signatures.setdefault(key, {
                "severity_word": severity_word, "message": msg, "file": file_path, "line": lineno,
                "count": 0, "first_ts": None, "last_ts": None,
                "clients": Counter(), "requests": Counter(),
            })
            d["count"] += 1
            if ts:
                if d["first_ts"] is None or ts < d["first_ts"]: d["first_ts"] = ts
                if d["last_ts"] is None or ts > d["last_ts"]: d["last_ts"] = ts
            client = extract_client(line)
            if client: d["clients"][client] += 1
            req = extract_request(line)
            if req: d["requests"][req] += 1
            continue

        if "directory index" in line and "forbidden" in line:
            continue  # already captured via scanner_paths/scanner_ips above
        if re.search(r"SSL|ssl_certificate", line, re.IGNORECASE):
            ssl_ts.append(ts)
        elif re.search(r"connection refused|upstream timed out", line, re.IGNORECASE):
            conn_ts.append(ts)
        elif re.search(r"FastCGI sent in stderr", line, re.IGNORECASE):
            m = re.search(r'stderr:\s*"([^"]{0,160})', line)
            text = (m.group(1) if m else line.strip()[:160]).strip()
            if text: other_stderr[text] += 1

    for (severity_word, file_path, lineno), d in signatures.items():
        severity = SEV_MAP.get(severity_word, "medium")
        findings[severity].append({
            "kind": "php",
            "label": f"PHP {severity_word}",
            "message": d["message"],
            "file": file_path,
            "line": lineno,
            "count": d["count"],
            "first_ts": d["first_ts"].strftime("%Y/%m/%d %H:%M:%S") if d["first_ts"] else "unknown",
            "last_ts_str": d["last_ts"].strftime("%Y/%m/%d %H:%M:%S") if d["last_ts"] else "unknown",
            "last_ago": time_ago(d["last_ts"]),
            "clients": d["clients"].most_common(5),
            "requests": d["requests"].most_common(3),
        })

    def _bucket_generic(ts_list, severity, label, what):
        ts_list = [t for t in ts_list if t]
        if not ts_list: return
        first_ts, last_ts = min(ts_list), max(ts_list)
        findings[severity].append({
            "kind": "generic", "label": label, "count": len(ts_list),
            "first_ts": first_ts.strftime("%Y/%m/%d %H:%M:%S"),
            "last_ts_str": last_ts.strftime("%Y/%m/%d %H:%M:%S"),
            "last_ago": time_ago(last_ts), "what": what,
        })

    _bucket_generic(ssl_ts, "critical", "SSL/certificate error",
                     "SSL handshake failed — visitors saw security warnings. Check MyKinsta → Domains → certificate status.")
    _bucket_generic(conn_ts, "critical", "Connection refused / upstream timeout",
                     "nginx couldn't reach PHP-FPM — visitors saw 502/504. Check PHP worker limits in MyKinsta → Resource Usage.")

    if other_stderr:
        findings["low"].append({
            "kind": "stderr_samples", "label": "Other PHP/stderr messages",
            "count": sum(other_stderr.values()), "samples": other_stderr.most_common(5),
        })

    if scanner_paths:
        findings["low"].append({
            "kind": "generic", "label": "403 Forbidden — directory probing",
            "count": sum(scanner_paths.values()),
            "first_ts": "see Directory Scanner Activity section below", "last_ts_str": "", "last_ago": "",
            "what": "Bot tried to list a WordPress directory. Kinsta blocked it correctly. See the Bot section for IPs to block.",
        })

    return findings, ts_range, ip_counter, error_entries, scanner_paths, scanner_ips

def analyze_access_log(logs):
    lines = logs.strip().split("\n")
    response_times, entries = [], []
    for line in lines:
        m = re.match(r'\S+ (\S+) \[([^\]]+)\] ([A-Z]+) "([^"]*)" HTTP/[\d.]+ (\d{3})', line)
        if not m: continue
        ip, ts_str, method, url, status = m.groups()
        ts = parse_apache_ts(ts_str)
        # Kinsta appends the response time near the end, but the very last token can be a
        # placeholder "-" (upstream field). Scan the last few tokens from the right instead
        # of assuming a fixed index, so a trailing "-" or an extra field doesn't break this.
        rt = 0
        for tok in reversed(line.rstrip().split()[-3:]):
            try:
                rt = float(tok)
                break
            except ValueError:
                continue
        if rt > 0.001: response_times.append(rt)
        entries.append({"ts": ts, "ip": ip, "url": norm(url), "status": status, "rt": rt})

    stc = Counter(e["status"] for e in entries)
    fivexx = [e for e in entries if e["status"].startswith("5")]
    slow = [e for e in entries if e["rt"] > 2.0]
    avg_rt = sum(response_times)/len(response_times) if response_times else 0

    # Drill-down: which URLs/IPs are behind each 4xx/5xx status code
    status_urls = defaultdict(Counter)
    status_ips = defaultdict(set)
    for e in entries:
        if e["status"] and e["status"][0] in ("4", "5"):
            status_urls[e["status"]][e["url"]] += 1
            status_ips[e["status"]].add(e["ip"])

    # Bot detection with time windows — includes AI assistant/crawler bots so they can be
    # split from search-engine/SEO/social/scanner bots in the report (see BOT_CATEGORIES).
    bot_patterns = [
        ("Googlebot", r"Googlebot"), ("Amazonbot", r"Amazonbot"),
        ("ChatGPT-User", r"ChatGPT-User"), ("YandexBot", r"YandexBot"),
        ("PetalBot", r"PetalBot"), ("OAI-SearchBot", r"OAI-SearchBot"),
        ("Bingbot", r"bingbot"), ("Dataprovider", r"Dataprovider"),
        ("AhrefsBot", r"AhrefsBot"), ("SemrushBot", r"SemrushBot"),
        ("MJ12bot", r"MJ12bot"), ("DuckDuckBot", r"DuckDuckBot"),
        ("Baiduspider", r"Baiduspider"), ("Applebot", r"Applebot"),
        ("facebookexternalhit", r"facebookexternalhit"), ("Twitterbot", r"Twitterbot"),
        ("Discordbot", r"Discordbot"), ("LinkedInBot", r"LinkedInBot"),
        ("GPTBot", r"GPTBot"), ("ClaudeBot", r"ClaudeBot"),
        ("PerplexityBot", r"PerplexityBot"), ("Google-Extended", r"Google-Extended"),
        ("Bytespider", r"Bytespider"), ("Anthropic-ai", r"anthropic-ai"),
    ]
    bot_data = {}
    for name, pat in bot_patterns:
        ts_list = []
        for line in lines:
            if re.search(pat, line, re.IGNORECASE):
                m2 = re.match(r'\S+ \S+ \[([^\]]+)\]', line)
                if m2:
                    bt = parse_apache_ts(m2.group(1))
                    if bt: ts_list.append(bt)
        if ts_list:
            ts_sorted = sorted(ts_list)
            bot_data[name] = {"count": len(ts_list), "first": ts_sorted[0], "last": ts_sorted[-1]}

    # Hourly
    hourly = Counter()
    for e in entries:
        if e["ts"]: hourly[e["ts"].strftime("%H:00")] += 1

    # Query param extraction from raw log
    query_params = Counter()
    for line in lines:
        m = re.search(r'"GET ([^?"]*)\?([^ "]+)', line)
        if m:
            for param in m.group(2).split("&"):
                name = param.split("=")[0]
                query_params[name] += 1

    access_ts = [e["ts"] for e in entries if e["ts"]]

    return {"total": len(lines), "statuses": stc, "avg_rt": avg_rt,
            "slow": slow, "bot_data": bot_data, "fivexx": fivexx,
            "entries": entries, "hourly": dict(sorted(hourly.items())),
            "query_params": query_params, "status_urls": status_urls,
            "status_ips": status_ips,
            "first_ts": min(access_ts) if access_ts else None,
            "last_ts": max(access_ts) if access_ts else None}

def analyze_cache_log(logs):
    hits = len(re.findall(r"\bHIT KINSTAWP", logs))
    misses = len(re.findall(r"\bMISS KINSTAWP", logs))
    bypasses = len(re.findall(r"\bBYPASS KINSTAWP", logs))
    total = hits + misses + bypasses
    if total == 0: return None
    entries = []
    for m in re.finditer(r"\[([^\]]+)\] (HIT|MISS|BYPASS) KINSTAWP(?:_MOBILE)? (\S+) ([A-Z]+) \"([^\"]+)\"", logs):
        ts = parse_apache_ts(m.group(1))
        entries.append({"ts": ts, "status": m.group(2), "ip": m.group(3), "url": norm(m.group(5))})
    return {"HIT": hits, "MISS": misses, "BYPASS": bypasses, "total": total, "entries": entries}

# ═══════════════════════════════════════════════════════════════════
# CROSS-ANALYSIS
# ═══════════════════════════════════════════════════════════════════

def cross_analyze(access_entries, cache_entries, error_entries, ip_counter, scanner_ips):
    results = {}
    if access_entries and cache_entries:
        acc_by_url = defaultdict(list)
        for e in access_entries: acc_by_url[e["url"]].append(e)

        slow_misses = []
        for ce in cache_entries:
            if ce["status"] != "MISS": continue
            for ae in acc_by_url.get(ce["url"], []):
                if ce["ts"] and ae["ts"] and abs((ce["ts"]-ae["ts"]).total_seconds()) < 5 and ae["rt"] > 1.0:
                    slow_misses.append({"url": ce["url"], "rt": ae["rt"], "ip": ae["ip"]})
                    break
        results["slow_cache_misses"] = slow_misses[:3]

        miss_by_url = defaultdict(list)
        for ce in cache_entries:
            if ce["status"] == "MISS" and ce["ts"]: miss_by_url[ce["url"]].append(ce["ts"])
        top_missed = []
        for url, timestamps in sorted(miss_by_url.items(), key=lambda x: -len(x[1]))[:7]:
            tss = sorted(timestamps)
            top_missed.append({"url": url, "count": len(timestamps),
                               "from": tss[0].strftime("%H:%M"), "to": tss[-1].strftime("%H:%M")})
        results["top_missed_urls"] = top_missed

        hit_urls = set(ce["url"] for ce in cache_entries if ce["status"] == "HIT")
        miss_urls_set = set(ce["url"] for ce in cache_entries if ce["status"] == "MISS")
        hit_rts, miss_rts = [], []
        for e in access_entries:
            if e["rt"] > 0.001:
                if e["url"] in hit_urls: hit_rts.append(e["rt"])
                elif e["url"] in miss_urls_set: miss_rts.append(e["rt"])
        if hit_rts and miss_rts:
            ah, am = sum(hit_rts)/len(hit_rts), sum(miss_rts)/len(miss_rts)
            results["hit_vs_miss_rt"] = (ah, am, len(hit_rts), len(miss_rts))

    if ip_counter and access_entries:
        acc_ip_set = set(e["ip"] for e in access_entries)
        suspicious = [(ip, cnt) for ip, cnt in ip_counter.most_common(10) if ip in acc_ip_set]
        results["suspicious_ips"] = suspicious[:5]

    results["top_ips"] = Counter(e["ip"] for e in access_entries).most_common(8)
    results["scanner_ips"] = scanner_ips.most_common(5)
    return results

# ═══════════════════════════════════════════════════════════════════
# REPORT
# ═══════════════════════════════════════════════════════════════════

def generate_report(site_name, error_findings, error_meta, access_data,
                    cache_data, cross_results, scanner_paths, hours, data_errors=None):
    data_errors = data_errors or {}
    now = datetime.now(timezone.utc)
    L = []

    period = f"Last {hours} hours" if hours else "All available data"
    L.append(f"# {site_name} — Kinsta Health Report")
    L.append("")
    L.append(f"**{now.strftime('%d %B %Y, %H:%M UTC')}** · {period}")
    if error_meta:
        L.append(f"{error_meta['total_lines']} error lines · "
                 f"{access_data['total'] if access_data else 0} requests · "
                 f"{cache_data['total'] if cache_data else 0} cache entries · "
                 f"{error_meta['timerange']}")
    L.append("")

    # Surface actual fetch/parse failures instead of silently showing "unavailable"
    if data_errors:
        L.append("> ⚠️ **Data source issues**")
        for source, reason in data_errors.items():
            L.append(f"> - `{source}` log: {reason}")
        L.append("")

    # Disclose the time-window mismatch between error log (can span days) and access
    # log (usually hours) — otherwise a "0" 5xx/4xx count looks wrong when it's really
    # just outside the access log's shorter retained window.
    acc_first = access_data.get("first_ts") if access_data else None
    acc_last = access_data.get("last_ts") if access_data else None
    if error_meta and acc_first and acc_last:
        L.append(f"> ℹ️ **Time windows differ**: access log covers "
                 f"`{acc_first.strftime('%Y-%m-%d %H:%M')} → {acc_last.strftime('%Y-%m-%d %H:%M')} UTC` "
                 f"({acc_last - acc_first}); error log covers `{error_meta['timerange']}`. "
                 f"Status-code counts below only reflect the access log's window — "
                 f"errors outside it won't show up as matching status codes.")
        L.append("")

    # ══════════════════════════════════════════════════════════════
    # HEALTH SUMMARY
    # ══════════════════════════════════════════════════════════════
    L.append("## Health Summary")
    L.append("")

    critical_count = len(error_findings.get("critical", []))
    medium_count = len(error_findings.get("medium", []))
    fivexx_count = len(access_data["fivexx"]) if access_data else 0
    hit_pct = cache_data["HIT"]/cache_data["total"]*100 if cache_data else None
    bypass_pct = cache_data["BYPASS"]/cache_data["total"]*100 if cache_data else None
    avg_rt = access_data["avg_rt"] if access_data else 0
    slow_count = len(access_data["slow"]) if access_data else 0

    L.append("| Metric | Value | Severity |")
    L.append("|---|---|---|")
    if critical_count or fivexx_count:
        health, hicon = "Action required", "🔴"
    elif hit_pct is not None and hit_pct < 50:
        health, hicon = "Cache needs optimization", "🟡"
    elif medium_count:
        health, hicon = "Minor warnings", "🟡"
    else:
        health, hicon = "Operating normally", "✅"
    L.append(f"| Status | {hicon} **{health}** | |")
    # Distinguish "no cache data" from an actual 0% HIT rate — conflating the two
    # previously showed a false 🔴 whenever cache.json was missing/empty.
    if hit_pct is not None:
        L.append(f"| Cache HIT rate | **{hit_pct:.0f}%** (target >70%) | "
                 f"{'✅' if hit_pct >= 70 else ('🟡' if hit_pct >= 50 else '🔴')} |")
        L.append(f"| Cache BYPASS rate | **{bypass_pct:.0f}%** | "
                 f"{'✅' if bypass_pct <= 10 else '🟡'} |")
    else:
        L.append("| Cache HIT rate | *no cache data* | — |")
        L.append("| Cache BYPASS rate | *no cache data* | — |")
    L.append(f"| Avg response time | **{avg_rt:.3f}s** | "
             f"{'✅' if avg_rt < 0.5 else ('🟡' if avg_rt < 1.0 else '🔴')} |")
    L.append(f"| Slow pages (>2s) | **{slow_count}** | "
             f"{'✅' if slow_count == 0 else ('🟡' if slow_count < 5 else '🔴')} |")
    L.append(f"| Server errors (5xx) | **{fivexx_count}** | "
             f"{'✅' if fivexx_count == 0 else '🔴'} |")
    L.append(f"| Error types | {critical_count} critical, {medium_count} warnings | "
             f"{'✅' if critical_count == 0 else '🔴'} |")
    L.append("")

    # ══════════════════════════════════════════════════════════════
    # ISSUES — each finding now carries real extracted data (message, file:line,
    # client IPs, requests) instead of only a canned generic "fix" tip.
    # ══════════════════════════════════════════════════════════════
    for sev_key, title in [("critical", "## 🔴 Issues Found"), ("medium", "## 🟡 Warnings"),
                            ("low", "## 🟢 Informational")]:
        findings = error_findings.get(sev_key, [])
        if not findings: continue
        L.append(title)
        L.append("")
        for f in findings:
            kind = f.get("kind", "generic")
            if kind == "php":
                L.append(f"### {f['label']}: {f['message']}")
                L.append("")
                L.append(f"`{f['file']}:{f['line']}`")
                L.append("")
                L.append("| | |")
                L.append("|---|---|")
                L.append(f"| Occurrences | **{f['count']}** |")
                L.append(f"| First seen | {f['first_ts']} |")
                L.append(f"| Last seen | {f['last_ts_str']} ({f['last_ago']}) |")
                if f["clients"]:
                    parts = []
                    for ip, cnt in f["clients"]:
                        cc, flag = ip_country(ip)
                        cdisp = f"{flag} {cc}" if flag else (cc if cc != "?" else "unknown")
                        parts.append(f"`{ip}` ({cdisp}, {cnt}×)")
                    L.append(f"| Client IP(s) | {', '.join(parts)} |")
                else:
                    L.append("| Client IP(s) | *not recorded on this log line* |")
                if f["requests"]:
                    reqs = [f"`{r}` ({c}×)" for r, c in f["requests"]]
                    L.append(f"| Request(s) | {', '.join(reqs)} |")
                else:
                    L.append("| Request(s) | *not recorded on this log line* |")
                L.append("")
                L.append(f"**Fix**: Open `{f['file']}` at line **{f['line']}** — {f['message']}")
                L.append("")
                L.append("---")
                L.append("")
            elif kind == "stderr_samples":
                L.append(f"### {f['label']}")
                L.append("")
                L.append(f"**{f['count']}** occurrences that didn't match a known PHP severity pattern. "
                         f"Actual sample messages (not a generic tip):")
                L.append("")
                for text, cnt in f["samples"]:
                    L.append(f"- `{text}` ({cnt}×)")
                L.append("")
                L.append("---")
                L.append("")
            else:
                L.append(f"### {f['label']}")
                L.append("")
                L.append("| | |")
                L.append("|---|---|")
                L.append(f"| Occurrences | **{f['count']}** |")
                L.append(f"| First seen | {f['first_ts']} |")
                if f.get("last_ts_str"):
                    L.append(f"| Last seen | {f['last_ts_str']} ({f['last_ago']}) |")
                L.append("")
                L.append(f"**{f['what']}**")
                L.append("")
                L.append("---")
                L.append("")

    # ══════════════════════════════════════════════════════════════
    # CACHE
    # ══════════════════════════════════════════════════════════════
    L.append("## Cache Performance")
    L.append("")
    if cache_data:
        total = cache_data["total"]
        L.append("| Status | Requests | Share |")
        L.append("|---|---|---|")
        for s in ["HIT", "MISS", "BYPASS"]:
            cnt = cache_data[s]
            pct = cnt/total*100
            bar = bar_chart(pct, 100, 15)
            L.append(f"| {s} | {cnt} | `{bar}` **{pct:.0f}%** |")
        L.append("")

        if hit_pct >= 70: v = "✅ Most visitors get instant cached pages."
        elif hit_pct >= 50: v = "🟡 Nearly half of visitors wait for the origin server."
        else: v = "🔴 More than half of requests miss cache."
        L.append(f"**Assessment**: {v} Target is >70% HIT.")
        L.append("")

        if cross_results.get("top_missed_urls"):
            L.append("### Pages Most Frequently Missing Cache")
            L.append("")
            L.append("| Page | MISSes | Active between |")
            L.append("|---|---|---|")
            for m in cross_results["top_missed_urls"][:5]:
                L.append(f"| `{m['url']}` | {m['count']} | {m['from']}–{m['to']} UTC |")
            L.append("")

        if cross_results.get("hit_vs_miss_rt"):
            ah, am, nh, nm = cross_results["hit_vs_miss_rt"]
            ratio = am/ah if ah > 0 else 0
            L.append("### Response Time: Cache HIT vs MISS")
            L.append("")
            L.append("| | Avg | Samples |")
            L.append("|---|---|---|")
            L.append(f"| Cache HIT | **{ah:.3f}s** | {nh} |")
            L.append(f"| Cache MISS | **{am:.3f}s** | {nm} |")
            if ratio > 1.01:
                L.append(f"| **Difference** | MISS is **{ratio:.1f}x slower** — cache provides clear benefit | |")
            elif ratio < 0.99:
                L.append(f"| **Difference** | MISS is **{ratio:.1f}x faster** (cached pages are heavier content) | |")
            else:
                L.append(f"| **Difference** | Similar speed — cache has neutral impact | |")
            L.append("")

        L.append("### How to Improve Cache HIT Rate")
        L.append("")
        qp = access_data.get("query_params", Counter()) if access_data else Counter()
        top_params = qp.most_common(3)
        L.append("| # | Action |")
        L.append("|---|---|")
        if top_params:
            param_list = ", ".join(f"`?{p}=`" for p, _ in top_params)
            L.append(f"| 1 | **Query strings found in your traffic**: {param_list}. "
                     f"MyKinsta → Edge Caching → add these to force-cached query strings. |")
        else:
            L.append("| 1 | **Check for query strings** — `?page=`, `?utm_source=` prevent caching. MyKinsta → Edge Caching → add safe params. |")
        L.append("| 2 | **Eliminate cookies on public pages** — any `Set-Cookie` header (WooCommerce, wpForo, comments) disables cache. |")
        L.append("| 3 | **Pre-warm after deploys** — crawl your sitemap with `wget --mirror` or a cache warmer plugin. |")
        L.append("| 4 | **Check Cloudflare** — verify Kinsta edge cache is primary layer, not bypassed by Cloudflare settings. |")
        L.append("")
    else:
        reason = data_errors.get("cache")
        L.append(f"Cache data unavailable{f': {reason}' if reason else ' (no cache_file provided or empty response)'}.")
        L.append("")

    # ══════════════════════════════════════════════════════════════
    # BOTS — grouped by heuristic category so AI assistants/answer engines are
    # visible separately from search engines, SEO crawlers, social bots, and scanners.
    # ══════════════════════════════════════════════════════════════
    L.append("## Bot & Crawler Traffic")
    L.append("")
    if access_data and access_data["bot_data"]:
        bots = access_data["bot_data"]
        total_bot = sum(b["count"] for b in bots.values())
        total_req = access_data["total"]
        L.append(f"**{total_bot}** of **{total_req}** requests (**{total_bot/total_req*100:.0f}%**) from known bots.")
        L.append("")
        L.append("*Categorization is a heuristic based on published User-Agent strings — verify before blocking.*")
        L.append("")

        by_cat = defaultdict(list)
        for name, b in bots.items():
            by_cat[BOT_CATEGORIES.get(name, "❓ Other / Unclassified Bot")].append((name, b))

        for cat in sorted(by_cat, key=lambda c: -sum(b["count"] for _, b in by_cat[c])):
            items = sorted(by_cat[cat], key=lambda x: -x[1]["count"])
            cat_total = sum(b["count"] for _, b in items)
            L.append(f"### {cat} ({cat_total} requests)")
            L.append("")
            L.append("| Bot | Requests | Active window |")
            L.append("|---|---|---|")
            for name, b in items:
                window = f"{b['first'].strftime('%H:%M')}–{b['last'].strftime('%H:%M')} UTC"
                L.append(f"| {name} | **{b['count']}** | {window} |")
            L.append("")

        if cross_results.get("scanner_ips"):
            L.append("### Scanner IPs — Block List")
            L.append("")
            L.append("| IP | Requests | Country | Safety |")
            L.append("|---|---|---|---|")
            for ip, cnt in cross_results["scanner_ips"][:5]:
                cc, flag = ip_country(ip)
                safe = ip_safety(ip, cnt)
                country_display = f"{flag} {cc}" if flag else (cc if cc != "?" else "unknown")
                L.append(f"| `{ip}/32` | {cnt} | {country_display} | {safe} |")
            L.append("")
            L.append("*Add these to MyKinsta → Tools → Denied IPs. `/32` blocks only that single IP.*")
            L.append("")
    else:
        L.append("No bot data.")
        L.append("")

    # ══════════════════════════════════════════════════════════════
    # IP INTELLIGENCE
    # ══════════════════════════════════════════════════════════════
    if cross_results.get("top_ips"):
        L.append("## Top Visitor IPs")
        L.append("")
        L.append("| IP | Requests | Country |")
        L.append("|---|---|---|")
        for ip, cnt in cross_results["top_ips"]:
            if ip in ("::1", "127.0.0.1"):
                country_display = "localhost"
            else:
                cc, flag = ip_country(ip)
                country_display = f"{flag} {cc}" if flag else (cc if cc != "?" else "unknown")
            L.append(f"| **{ip}** | {cnt} | {country_display} |")
        L.append("")
        L.append("")

    # ══════════════════════════════════════════════════════════════
    # TRAFFIC
    # ══════════════════════════════════════════════════════════════
    L.append("## Traffic Overview")
    L.append("")
    if access_data:
        total = access_data["total"]
        stc = access_data["statuses"]

        L.append("### Status Codes")
        L.append("")
        L.append("| Code | Count | Share |")
        L.append("|---|---|---|")
        for code in ["200", "301", "302", "304", "400", "403", "404", "405", "500", "502", "503"]:
            cnt = stc.get(code, 0)
            if cnt:
                pct = cnt/total*100
                bar = bar_chart(pct, 100, 15)
                L.append(f"| {code} | {cnt} | `{bar}` **{pct:.0f}%** |")
        L.append("")

        # Drill-down: exactly which URLs/IPs are behind each 4xx/5xx code, instead
        # of leaving the reader with only an aggregate count.
        status_urls = access_data.get("status_urls") or {}
        status_ips = access_data.get("status_ips") or {}
        error_codes = sorted(c for c in status_urls if status_urls[c])
        if error_codes:
            L.append("### Errors by Status Code — Drill-Down")
            L.append("")
            for code in error_codes:
                urls = status_urls[code]
                ips = status_ips.get(code, set())
                total_code = sum(urls.values())
                L.append(f"**{code}** — {total_code} requests from {len(ips)} distinct IP(s)")
                L.append("")
                L.append("| URL | Count |")
                L.append("|---|---|")
                for url, cnt in urls.most_common(5):
                    L.append(f"| `{url}` | {cnt} |")
                L.append("")

        L.append("### Requests per Hour (UTC)")
        L.append("")
        if access_data.get("hourly"):
            max_h = max(access_data["hourly"].values()) if access_data["hourly"] else 1
            L.append("| Hour | Requests | Distribution |")
            L.append("|---|---|---|")
            for hour, cnt in access_data["hourly"].items():
                bar = bar_chart(cnt, max_h, 20)
                L.append(f"| {hour} | {cnt} | `{bar}` |")
        L.append("")

        L.append("### Performance")
        L.append("")
        L.append(f"| Metric | Value |")
        L.append(f"|---|---|")
        L.append(f"| Average response time | **{access_data['avg_rt']:.3f}s** |")
        L.append(f"| Slow pages (>2s) | **{len(access_data['slow'])}** |")
        L.append(f"| Server errors (5xx) | **{len(access_data['fivexx'])}** |")
        L.append("")
    else:
        reason = data_errors.get("access")
        L.append(f"Access log unavailable{f': {reason}' if reason else ''}.")
        L.append("")

    # ══════════════════════════════════════════════════════════════
    # SCANNER DETAILS
    # ══════════════════════════════════════════════════════════════
    if scanner_paths:
        significant = [(p, c) for p, c in scanner_paths.most_common() if c >= 2]
        trimmed = len(scanner_paths) - len(significant)
        L.append("## Directory Scanner Activity")
        L.append("")
        L.append(f"Paths probed by bots (Kinsta correctly returned 403 to all):")
        L.append("")
        L.append("| Path | Attempts |")
        L.append("|---|---|")
        for path, cnt in significant[:10]:
            L.append(f"| `{path}` | {cnt} |")
        if trimmed:
            L.append(f"| *({trimmed} paths with 1 attempt trimmed)* | |")
        L.append("")
        L.append("*Normal background noise — Kinsta blocks directory listing by default.*")
        L.append("")

    return "\n".join(L)

# ═══════════════════════════════════════════════════════════════════
# MAIN
# ═══════════════════════════════════════════════════════════════════

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("error_file")
    parser.add_argument("access_file")
    parser.add_argument("cache_file", nargs="?")
    parser.add_argument("--hours", type=int, default=24)
    parser.add_argument("--no-geoip", action="store_true",
                         help="Disable network geo-IP lookups to ipinfo.io (privacy/speed/determinism)")
    args = parser.parse_args()

    global GEOIP_ENABLED
    GEOIP_ENABLED = not args.no_geoip

    site_name = os.path.basename(os.path.dirname(os.path.dirname(args.error_file)))

    data_errors = {}
    err_logs, err_err = extract_logs(args.error_file)
    if err_err: data_errors["error"] = err_err
    acc_logs, acc_err = extract_logs(args.access_file)
    if acc_err: data_errors["access"] = acc_err
    cache_data = None; cache_entries = []
    if args.cache_file:
        cl, cache_err = extract_logs(args.cache_file)
        if cache_err: data_errors["cache"] = cache_err
        if cl:
            cache_data = analyze_cache_log(cl)
            if cache_data: cache_entries = cache_data.get("entries", [])

    err_filtered = err_logs; acc_filtered = acc_logs
    if err_logs: err_filtered, _, _, _ = filter_by_hours(err_logs, args.hours, "error")
    if acc_logs: acc_filtered, _, _, _ = filter_by_hours(acc_logs, args.hours, "access")

    error_findings = {"critical": [], "medium": [], "low": []}
    error_meta = None; error_entries = []; access_data = None; access_entries = []
    scanner_paths = Counter(); scanner_ips = Counter()

    if err_filtered:
        error_findings, ts_range, ip_counter, error_entries, scanner_paths, scanner_ips = \
            analyze_error_log(err_filtered)
        error_meta = {"timerange": ts_range, "total_lines": len(err_filtered.split("\n"))}
    if acc_filtered:
        access_data = analyze_access_log(acc_filtered)
        access_entries = access_data.get("entries", [])

    cross_results = cross_analyze(access_entries, cache_entries, error_entries,
                                  ip_counter if err_filtered else Counter(), scanner_ips)

    report = generate_report(site_name, error_findings, error_meta, access_data,
                             cache_data, cross_results, scanner_paths, args.hours, data_errors)

    report_dir = os.path.dirname(args.error_file)
    ts = "_".join(os.path.basename(args.error_file).split("_")[:2])
    report_path = os.path.join(report_dir, f"{ts}_report.md")
    with open(report_path, "w") as f: f.write(report)

    print(report)
    print(f"\n📄 {report_path}")

    import subprocess as sp
    try: sp.run(["code", report_path], check=False, timeout=5)
    except Exception: pass

if __name__ == "__main__": main()
