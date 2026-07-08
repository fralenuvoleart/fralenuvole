# Comment Cleanup Plan — Final

## Methodology

Comprehensive regex scan (`//[\sA-Za-z].{80,}`) across ALL PHP files → 234 results reviewed individually. `phpcs:ignore` lines excluded per explicit instruction. The vast majority of comments in this codebase are already appropriately sized (1–2 lines, directly informative). Only 7 files have genuinely bloated comments needing trimming.

---

## File 1: [`includes/shared/website-features.php`](includes/shared/website-features.php:241)

**Lines 241–249:** 9-line inline comment duplicating `_frl_` convention already documented in [`memory-bank/systemPatterns.md`](memory-bank/systemPatterns.md:27-30).

**Replace with:**
```php
	// Schedule one-time cron to batch-close comments. Completion marker uses
	// the _frl_ internal-state convention (see systemPatterns.md). Skipped for REST/AJAX.
```

Also trim lines 262–263 and 301–302 to 1 line each.

---

## File 2: [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php)

#### A. PHPDoc for `frl_get_auth_cookie_user_data()` (lines 111–131) — 21 → 8 lines

```php
/**
 * Reads and verifies the WordPress auth cookie before pluggable.php loads.
 *
 * Replicates wp_validate_auth_cookie()'s HMAC algorithm using LOGGED_IN_KEY/SALT
 * from wp-config.php. Does NOT check WP_Session_Tokens revocation (see systemPatterns.md).
 * Cached 300s per username via frl_cache_remember.
 *
 * @return array|false User data with 'id' and 'caps', or false on failure.
 */
```

#### B. HMAC verification comment (lines 208–214) — 7 → 2 lines

```php
	// Verify HMAC signature (replicates wp_validate_auth_cookie/wp_hash algorithm).
	// Without this, any visitor could impersonate a known username from author archives.
```

#### C. PHPDoc for `frl_add_exclusion_filter_cron()` (lines 478–496) — 19 → 6 lines

```php
/**
 * Sanitizes the cron option: removes orphaned events from excluded plugins and
 * ensures $event['args'] is always an array (prevents PHP 8+ TypeError on null).
 *
 * Uses option_cron (not pre_option_cron) because alloptions cache bypasses
 * pre_option_* filters. Read-time only — does not modify the database.
 *
 * @return void
 */
```

#### D. Other verbose inline comments:

| Lines | Replace with |
|-------|-------------|
| 248–250 (3 lines) | `// Frontend context: HTML pages + frontend AJAX (not admin, REST, or cron)` |
| 322–324 (3 lines) | `// Cron filter also sanitizes args — needed even with empty exclusion list` |
| 360–363 (4 lines) | `// Cache with WEEK_IN_SECONDS TTL. frl_cache_remember is safe inside pre_option_* (no recursion risk).` |
| 440–444 (5 lines) | `// Direct DB query wrapped in frl_cache_remember (safe inside pre_site_option_* filter — no recursion).` |
| 519–526 (6 lines) | `// Non-excluded plugins have registered their schedules by now. Non-array keys pass through untouched.` |
| 545–548 (4 lines) | `// Prevent TypeError when wp-cron.php passes null args to do_action_ref_array()` |

---

## File 3: [`includes/helpers/utilities.php`](includes/helpers/utilities.php:630)

**Lines 630–638:** 9-line inline comment about DOMDocument entity re-encoding behavior.

**Replace with:**
```php
	// Fast path: strings without '<' or '&' are unaffected by DOMDocument's
	// entity re-encoding on saveHTML() round-trip (bare '&' → '&').
```

---

## File 4: [`core/environment/class-environment-manager.php`](core/environment/class-environment-manager.php:220)

**Lines 220–224:** 5-line comment about throttle periods and migrate mode.

**Replace with:**
```php
		// Admin and migrate-mode users: 60s throttle. Others: 300s.
```

---

## File 5: [`includes/helpers/functions-options.php`](includes/helpers/functions-options.php:806)

**Lines 806–814:** 9-line comment about cache invalidation during option seeding.

**Replace with:**
```php
	// Invalidate stale all_options cache so next request picks up this new option.
	// Batched once per request: clearing after first write is sufficient.
```

---

## File 6: [`modules/wsform/webhooks.php`](modules/wsform/webhooks.php:407)

**Lines 407–412:** 6-line explanation for nonce removal on public endpoint.

**Replace with:**
```php
	// Public analytics endpoint (nopriv). Protected by sanitization, deduplication,
	// and rate limiting. No nonce: Cloudflare CDN caching causes nonce expiration.
```

---

## File 7: [`admin/helpers/functions-admin-action-handlers.php`](admin/helpers/functions-admin-action-handlers.php:528)

**Lines 528–536:** 9 lines across two STEP comments during plugin reset.

**Replace with:**
```php
	// STEP 4: Clear cached options so defaults are read from DB for environment/debug apply.
```
(merge STEP 4 + 5 into one short line; remove the 4-line STEP 4 and 4-line STEP 5)

---

## Summary

| File | Change |
|------|--------|
| `website-features.php` | 9-line → 2-line comment |
| `functions-mu.php` | 2 bloated PHPDocs + 6 verbose inline comments trimmed |
| `utilities.php` | 9-line → 2-line DOMDocument comment |
| `class-environment-manager.php` | 5-line → 1-line throttle comment |
| `functions-options.php` | 9-line → 2-line cache comment |
| `webhooks.php` | 6-line → 2-line nonce comment |
| `functions-admin-action-handlers.php` | 9-line → 1-line STEP comment |

**Zero `phpcs:ignore` lines modified. Zero behavioral changes.**
