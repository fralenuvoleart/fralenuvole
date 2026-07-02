# WSForm Webhook Performance Analysis — `frl_wsf_send_form_submission_webhook`

**Date:** 2026-07-02
**Trigger:** Analytics showing `frl_wsf_execute_webhook_submission` at 944.98ms (76.69% of `/wp-cron.php` transaction, 1,232.22ms total).

---

## 1. Hook Chain Trace

```
wsf_submit_post_complete          (WS Form fires after form save)
  → frl_wsf_submit_webhook()       [webhooks.php:164]
    → frl_wsf_get_matching_configs()  [webhooks.php:85] — O(n) config match
    → frl_wsf_should_send_webhook()   [webhooks.php:109] — dedupe check (get_transient + set_transient)
    → wp_schedule_single_event()      [webhooks.php:222] — schedules cron (non-blocking! ✅)
    
-- LATER, during WP-Cron execution --

frl_wsf_send_form_submission_webhook action fires
  → frl_wsf_execute_webhook_submission()  [webhooks.php:235]
    → curl_init() + curl_setopt() + curl_exec() → **944ms here**
```

The button webhook handler [`frl_wsf_button_webhook_handler()`](modules/wsform/webhooks.php:368) follows the same path — either schedules cron or calls `frl_wsf_execute_webhook_submission()` directly.

---

## 2. Root Cause: External HTTP Latency

The 944.98ms is **entirely** the blocking [`curl_exec()`](modules/wsform/webhooks.php:273) call to Integrately's external webhook endpoint:

| Webhook | URL | `use_cron` |
|---------|-----|-----------|
| PBS Form #12 | `https://webhooks.integrately.com/a/webhooks/d3db87eb88ee48eeac177a49fc159070` | `true` (cron) |
| PBS Form #9 | `https://webhooks.integrately.com/a/webhooks/171f3cf7dd074bc08c0ad004a245c5d7` | `false` (sync!) |
| PBP Form #2 | `https://webhooks.integrately.com/a/webhooks/70ace417574440f7b3835a71655b8a40` | `false` (sync!) |
| Email button | `https://webhooks.integrately.com/a/webhooks/171f3cf7dd074bc08c0ad004a245c5d7` | per-button |

The trace shows execution on `wp-cron.php`, confirming the cron path is working for Form #12. The 944ms is Integrately's server-side processing + network round-trip. **This is an external dependency — the plugin code cannot control how fast Integrately responds.**

### Verdict: The time is JUSTIFIED. The delay is external (third-party SaaS latency), not a plugin code inefficiency.

---

## 3. Code Issues Found (Quality, Not Performance)

### 3.1 MISSING: `curl_close($ch)` — Memory Leak
[`frl_wsf_execute_webhook_submission()`](modules/wsform/webhooks.php:235-289) never calls `curl_close($ch)`. While PHP eventually cleans up at request end, this leaks the cURL handle for the remainder of the request. Minor but incorrect.

### 3.2 MISSING: `CURLOPT_CONNECTTIMEOUT`
The code sets `CURLOPT_TIMEOUT` to 15 seconds but does NOT set `CURLOPT_CONNECTTIMEOUT`. This means if `webhooks.integrately.com` is unreachable (DNS failure, network partition, firewall block), the connection attempt blocks for the **full 15 seconds** instead of failing fast. In the worst case (both configs hit + button webhook), this could block a cron job for 45+ seconds.

### 3.3 MISSING: DNS Caching
No `CURLOPT_DNS_CACHE_TIMEOUT` is set. Although cURL's default is 60s, the handle is never reused (no `curl_close()` → no handle pool). Each cron invocation creates a fresh cURL handle, forcing a new DNS lookup.

### 3.4 MISSING: `CURLOPT_ENCODING`
No `Accept-Encoding: gzip` support. Integrately likely supports it — adding this could reduce response body transfer time marginally.

### 3.5 `use_cron = false` for Forms #9 and PBP #2
Forms #9 (PBS) and #2 (PBP) have `'use_cron' => false` in [`config-constants-webhooks.php`](modules/wsform/config-constants-webhooks.php:58,86). This means these webhooks fire **synchronously** during the user's form submission request — blocking the user for the full 944ms HTTP round-trip. This is a UX concern, not visible in the cron trace, but worth noting. The button webhook handler also has per-button `use_cron` settings.

---

## 4. What Cannot Be Optimized

The ~944ms HTTP call to Integrately is irreducible from the plugin's perspective:

- **No async HTTP:** WordPress has no native async HTTP client. `WP_Http` (underlying `wp_remote_post()`) is also synchronous. The cron-based deferral is already the correct pattern.
- **No persistent connection pooling:** Each WP-Cron invocation is a separate PHP process — no cURL handle reuse across cron jobs.
- **Third-party latency:** Integrately's processing time is outside our control.

---

## 5. Recommended Actions

### Priority 1 — Code Hygiene (low effort, prevents worst-case timeouts)

| # | File | Change |
|---|------|--------|
| 1 | [`webhooks.php:273`](modules/wsform/webhooks.php:273) | Add `curl_close($ch)` after response handling |
| 2 | [`webhooks.php:268-270`](modules/wsform/webhooks.php:268) | Add `CURLOPT_CONNECTTIMEOUT` (5s) — fail fast on network issues, don't waste 15s |
| 3 | [`webhooks.php:268-270`](modules/wsform/webhooks.php:268) | Add `CURLOPT_DNS_CACHE_TIMEOUT` (300) — reuse DNS across handles in same request |
| 4 | [`webhooks.php:264`](modules/wsform/webhooks.php:264) | Add `CURLOPT_ENCODING => ''` — enable gzip/deflate |

**Final consensus (3 changes, zero risk):**

```diff
--- a/modules/wsform/webhooks.php
+++ b/modules/wsform/webhooks.php
@@ -259,16 +259,19 @@ function frl_wsf_execute_webhook_submission($args)
     // ... validation unchanged ...

     // Initialize cURL session with Webhook URL
     $ch = curl_init($webhook_url);

     // Set cURL options
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
+    curl_setopt($ch, CURLOPT_ENCODING, '');             // Accept gzip/deflate (transparent decompression)
     curl_setopt($ch, CURLOPT_HTTPHEADER, [
         'Accept: application/json',
         'Content-Type: application/json',
     ]);
     curl_setopt($ch, CURLOPT_POST, true);
     curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
+    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);        // Fail fast on DNS/TCP issues
     curl_setopt($ch, CURLOPT_TIMEOUT, 15);               // Keep at 15s

     // Execute the request and get the response
     $response = curl_exec($ch);
     $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

+    curl_close($ch);                                     // Explicit resource cleanup
+
     // Check for errors (unchanged)
```

**Rejected from original proposal:**
- `CURLOPT_DNS_CACHE_TIMEOUT` — per-handle cache destroyed by `curl_close()`, no benefit without `curl_share_init()`

**Rejected from alternative proposal:**
- `CURLOPT_TIMEOUT 10s` — risks false failures; 15s is already reasonable for fire-and-forget webhooks
- `CURLOPT_NOSIGNAL = true` — only relevant in threaded PHP SAPIs, not php-fpm/mod_php
- `CURLOPT_HTTP_VERSION = CURL_HTTP_VERSION_1_1` — forcing HTTP/1.1 with ALPN-capable TLS adds no benefit, could be counterproductive

### Priority 2 — Consider `use_cron = true` for Forms #9 and PBP #2

Changing `'use_cron' => true` for the forms currently set to `false` would prevent the synchronous 944ms blocking during user form submissions. However, this needs business validation — there may be a reason these particular webhooks need real-time delivery.

### Priority 3 — Consider `wp_remote_post()` over raw cURL

WordPress's `wp_remote_post()` provides connection pooling (via `WP_Http_Curl` class), automatic `curl_close()`, and integration with WordPress's HTTP API hooks. However, this is a larger refactor and the performance gain is marginal since the bottleneck is Integrately's response time, not the PHP overhead.

### What NOT to do

- **Do NOT reduce `CURLOPT_TIMEOUT` below 15s:** The current timeout is reasonable for external webhook processing. Reducing it risks false timeout failures when Integrately is slow.
- **Do NOT use `wp_remote_post()` with `blocking => false`:** WordPress "non-blocking" HTTP is a misnomer — it still blocks until headers are received, then releases. It also doesn't work reliably in cron contexts.

---

## 6. Summary

| Question | Answer |
|----------|--------|
| Is the 944ms time justified? | **Yes.** It is the external HTTP call to Integrately's webhook endpoint. |
| Does it depend on external factors? | **Yes.** Integrately's processing time + network latency between the server and Integrately's infrastructure. |
| Can the plugin code be optimized? | **Marginally.** There are code hygiene fixes (missing `curl_close`, missing `CURLOPT_CONNECTTIMEOUT`) but these address worst-case failure scenarios, not the normal 944ms execution time. |
| Is the architecture correct? | **Yes.** Deferring to WP-Cron prevents blocking user form submissions. The cron pattern is the correct approach for WordPress. |

**Bottom line:** The 944ms is acceptable — it's external latency the plugin cannot eliminate. The code hygiene fixes in Section 5 should be applied to prevent worst-case timeouts (e.g., 15s hang when Integrately is unreachable) and to fix the `curl_close` memory leak.
