# Operational Playbook — Kinsta Log Analyzer

Expert-level guidance for each anomaly type found in the report.

---

## Cache Health

### HIT Rate Too Low (<50%)

**Why it matters**: Every MISS hits the origin server (PHP + database), consuming resources and slowing response. A 50% HIT rate means half your traffic is uncached.

**Diagnose**:
1. In MyKinsta → Edge Caching, check which URLs are MISSing.
2. Look for BYPASS patterns in the cache-perf log: query strings (`?page=2`, `?utm_source=...`), cookies, or `wp-admin` paths.
3. Check if you have cache exclusion rules in MyKinsta that are too broad.

**Fix**:
- **Query strings**: WordPress pagination (`?page=`) and UTM tracking params bypass Kinsta cache. Consider using path-based pagination or excluding marketing URLs from cache expectations.
- **Cookies**: Any `Set-Cookie` response header prevents caching. Common causes: WooCommerce cart, wpForo sessions, comment cookies on pages with closed comments.
- **Cache warming**: After deploys or cache clears, run a crawler (e.g., `wget --mirror`) against your sitemap to pre-warm the edge cache.
- **Kinsta edge cache exclusions**: In MyKinsta → Edge Caching, add paths that should NEVER be cached (e.g., `/checkout/`, `/my-account/`) so MISSes there don't dilute your HIT ratio.

### BYPASS Rate Too High (>10%)

**Normal BYPASS**: `wp-cron.php`, `/wp-admin/*`, sitemaps, API endpoints.

**Abnormal BYPASS**: Public-facing pages with query strings or cookies.

**Fix**:
- Check DB for `wordpress_logged_in_*` cookies set on pages that don't need them (plugin conflict).
- Audit marketing UTM params — consider stripping them via JavaScript redirect or server-side before the request hits WordPress.
- In MyKinsta → Edge Caching → "Force cache for URLs with query strings", selectively enable for known-safe params.

### MISS Response Times >1s

The origin server is slow. Common causes:
- **Uncached WP REST API calls blocking the main thread**
- **Heavy database queries** on uncached pages — use Kinsta APM to trace
- **External API calls** during page generation (currency converters, chat widgets)
- **PHP worker exhaustion** — check MyKinsta → Resource Usage for PHP worker limit hits

---

## Bot Traffic Management

### Aggressive Crawlers

**Identify the threat**: Look at the access log for:
- Single IP hitting many URLs rapidly (look for same IP in top list with >20 req)
- User agents like `Go-http-client`, `python-requests`, or empty UAs
- Requests to nonexistent paths (scanner behavior)

**Mitigation options** (ordered by effort):

1. **robots.txt** (fastest, but only honors polite bots):
   ```
   User-agent: PetalBot
   Disallow: /
   User-agent: Amazonbot
   Crawl-delay: 10
   ```

2. **Kinsta IP Deny** (MyKinsta → Tools → Denied IPs):
   - Add abusive IPs or CIDR ranges.
   - These get blocked at nginx level before hitting WordPress.
   - Use for targeted blocking of known bad actors.

3. **Rate limiting via Kinsta**:
   - Contact Kinsta support to configure `limit_req` zones for specific paths.
   - Example: limit `/wp-login.php` to 5 req/min per IP.

4. **Cloudflare WAF** (if using Cloudflare in front of Kinsta):
   - Create custom WAF rules: block by ASN, country, or UA pattern.
   - Rate limiting rules with configurable thresholds.

5. **WordPress-level blocking**:
   - Plugin like Wordfence or BBQ Block Bad Queries.
   - .htaccess rules: `Deny from` for specific IPs.

### Legitimate Bot Balance

Good bots (Googlebot, Bingbot) need access for SEO. Don't block them — let Kinsta edge cache handle their cached page requests efficiently.

---

## Error Response Patterns

### 5xx Errors (Server Errors)

These mean visitors saw error pages.

**Immediate actions**:
1. Check MyKinsta → Analytics → HTTP Status Codes for the 5xx trend (spike or constant).
2. Enable Kinsta APM to capture full traces of failing transactions.
3. Check PHP error log for corresponding fatal errors at the same timestamp.
4. If correlated with high traffic, check PHP worker limits (MyKinsta → Resource Usage).

**Common fixes**:
- **PHP memory exhaustion**: increase `memory_limit` in MyKinsta → Tools → PHP Engine.
- **PHP worker exhaustion**: upgrade PHP workers or optimize slow endpoints.
- **Plugin/theme fatal errors**: roll back recent deploys, check error log for the file and line.

### 403 Forbidden Spikes

- Normal: Kinsta nginx blocks directory listing by default — scan bots get 403.
- Abnormal: legitimate users getting 403 from IP Deny or WAF rules.
- Check MyKinsta → Tools → Denied IPs for overly broad CIDR blocks.

### 404 Not Found

- Top 404 URLs in the access log → redirect or fix broken links.
- If from a specific referrer, contact the linking site.
- Set up 301 redirects in WordPress (Redirection plugin) or nginx.

---

## Response Time Optimization

### Pages >2s

**Diagnose** with Kinsta APM:
1. Sort transactions by duration.
2. Look for slow MySQL queries (often the #1 cause).
3. Check external HTTP calls (API integrations, webhooks).

**Kinsta-specific optimizations**:
- Enable **Edge Caching** for static assets (CSS/JS/images already cached by Kinsta CDN).
- Use Kinsta's **Image Optimization** (lossy/lossless) to reduce payload size.
- Verify PHP version is 8.0+ (MyKinsta → Tools → PHP Engine) — PHP 8.x is 2-3x faster than 7.x.
- Check MyKinsta → Resource Usage → PHP workers: if frequently hitting the limit, upgrade.

---

## Traffic Spikes

### Sudden Traffic Increase

**Determine if legitimate**:
- Check top IPs — are they known bots or real visitors?
- Check top URLs — news article going viral, or DDoS?
- Check referrers — social media, news aggregators, or none?

**If legitimate** (viral content):
- Edge cache should absorb most requests to cached pages.
- Monitor PHP worker usage — only uncached dynamic requests hit PHP.
- Consider temporarily upgrading PHP workers.

**If attack** (DDoS):
- Contact Kinsta support immediately — they have DDoS mitigation at the network edge.
- Enable Kinsta's DDoS protection in MyKinsta if not already on.
- Block source IPs/networks in MyKinsta → Tools → Denied IPs.

---

## SSL & Security

### SSL Certificate Issues

- Check MyKinsta → Domains → SSL certificate expiry.
- Kinsta auto-renews Let's Encrypt certs — manual action only needed for custom certs.
- If using Cloudflare, ensure SSL mode is "Full (strict)".

### Suspicious Login Attempts

- Look for repeated POST to `/wp-login.php` or `/xmlrpc.php` from same IP.
- Block in MyKinsta → Tools → Denied IPs.
- Consider disabling XML-RPC if not needed (Kinsta can do this at nginx level).
