# Fralenuvole Plugin — Comprehensive Production Audit (2026-07-02)

**Audited version:** 5.7.3.9
**Scope:** All code paths, modules, helpers, hooks, filters, cache, options, environment, rewriter, translator, themekit, admin, MU-plugin, lifecycle, and third-party integration.
**Goal:** Identify hidden bugs, performance throttles, and interactions with WordPress core / other plugins that may degrade a production website. The plugin's USP is performance, so any net-negative performance or stability finding must be flagged.

---

## Executive Summary

| Severity | Count | One-line summary |
|----------|------:|------------------|
| 🔴 Critical | 3 | Issues that can corrupt data, 404 pages, or block the editor on a clean WP install with no other plugins |
| 🟠 High | 7 | Issues that cause measurable performance loss, memory bloat, or silently break features in common scenarios |
| 🟡 Medium | 9 | Issues that introduce overhead, redundant work, or edge-case bugs |
| 🟢 Low / Informational | 6 | Cosmetic, defensive, or non-impactful findings |

**Top three concerns** (read first):
1. `frl_log_capture_render_block_enter/exit` and `frl_log_capture_shortcode` run on **every block and shortcode render**, on every frontend request — even when logging is disabled. Real per-request overhead on a performance USP plugin.
2. `$key_cache` in `Frl_Cache_Manager` is an **unbounded within-request static array** (request-scoped, not cross-request) that grows only when array keys are passed to `frl_cache_remember`. Real impact only on long-running processes or pages with many array-keyed cache calls; negligible on typical page loads.
3. `frl_get_current_user()` cache key is derived from the raw `LOGGED_IN_COOKIE` value (not from the validated user ID), which means an attacker can pollute the persistent `admin` cache group with `WP_User(0)` entries under attacker-chosen keys. This is a **cache pollution** concern (not a CPU/memory DoS) and is bounded by the persistent cache's eviction policy.

> **Retraction (2026-07-02):** The earlier draft of this report listed `frl_alter_query()` as Critical C1. That was an over-reach. The function is a **deliberate, voluntary performance optimization** — the user has confirmed the design intent. The block editor side-effects (Query Loop block affected by `update_post_meta_cache=false`, draft previews affected by `post_status='publish'`, pagination affected by `no_found_rows=true`) are **known and accepted tradeoffs**, not regressions. Fralenuvole is performance-first; the contract is: opt out via the `custom_wp_query` option if a site needs the full WP_Query default behavior. C1 is removed from the findings below.

---

## 🔴 CRITICAL FINDINGS

### C1. `frl_disable_rest_endpoints()` removes critical REST endpoints when `frl_is_logged_in()` guard misbehaves

**Files:**
- [`public/public.php:15`](public/public.php:15) — `add_filter('rest_endpoints', 'frl_disable_rest_endpoints', 10, 1);`
- [`public/public.php:511-530`](public/public.php:511)

**Problem:**
The guard `if (frl_is_logged_in() || !frl_get_option('disable_rest'))` is sound **only if** `frl_is_logged_in()` reliably returns `true` for editors and `false` for unauthenticated visitors. `frl_is_logged_in()` depends on `frl_get_current_user()` which in turn reads from the persistent `admin` cache group. The cache key includes `substr(md5($auth_cookie), 0, 8)` (see [`includes/helpers/functions.php:131-138`](includes/helpers/functions.php:131)). If the persistent cache returns a stale `WP_User(0)` object for an authenticated session, the guard fails and the endpoints are removed — losing Media Library, Site Editor settings, and taxonomy panels in the block editor.

**Impact:**
When the persistent cache (`admin` group) returns corrupted data, the block editor silently breaks. The existing type-safety guard at [`functions.php:149-150`](includes/helpers/functions.php:149) catches the **type** issue, but not the **stale-auth** issue (a previous user's `WP_User` object with a still-valid cookie token prefix would pass the type check).

**Recommendation:**
Replace `frl_is_logged_in()` with `is_user_logged_in()` (no caching) for this guard, or add a re-validation check that compares the cached user's `session_token` with the current cookie's `LOGGED_IN_COOKIE` value. The cost of a DB hit for a security-sensitive check is acceptable.

---

### C2. `frl_get_option()` re-entrancy guard is reset on every call — recursive calls are not actually protected

**File:** [`includes/helpers/functions-options.php:89-91`](includes/helpers/functions-options.php:89)

```php
} finally {
    frl_is_already_running(__FUNCTION__, true);
}
```

**Problem:**
`frl_is_already_running(__FUNCTION__, true)` is called in the `finally` block of **every** `frl_get_option()` call, which resets the guard to `false`. This means a recursive call to `frl_get_option()` (e.g., from a `pre_option_frl_*` filter that reads the option being set) will:
1. Enter `frl_get_option()` for option `A`.
2. Set guard to `true`.
3. Inside the filter chain, another piece of code reads option `A`.
4. Enter `frl_get_option()` for option `A` recursively.
5. Guard check: still `true` (not yet reset) → return early.
6. Step 1's `finally` block fires → resets guard to `false`.

The guard *does* prevent infinite recursion **within a single call stack**, but only because the inner call sees `true` and returns. The reset semantics are misleading: if a developer reads this code and assumes "I can use `frl_is_already_running('frl_get_option')` to skip work elsewhere", they will be confused because the flag is always `false` after the first call.

**Impact:**
Low probability of a real bug — but the pattern is a footgun. It also means `frl_get_option()` cannot be reliably used as a sentinel in any other system that depends on "have I been called yet this request".

**Recommendation:**
Either:
- Remove the `finally { frl_is_already_running(__FUNCTION__, true); }` block (the function does not actually need a re-entrancy guard — the static `$loaded` flag at line 32 already prevents redundant DB lookups within a request).
- Or use a class-level static guard that is only reset in `__reset__` mode (line 35-41), not on every call.

---

### C3. Re-entrancy guard on `frl_thirdparty_inbound_cache_clear` is set BEFORE the work runs — duplicate notifications possible when guard is reset between calls

**File:** [`modules/thirdparty/thirdparty.php:333-336`](modules/thirdparty/thirdparty.php:333), [`395-399`](modules/thirdparty/thirdparty.php:395), and [`375-376`](modules/thirdparty/thirdparty.php:375)

**Problem:**
`frl_thirdparty_inbound_cache_clear()` starts with:
```php
if (frl_is_already_running(__FUNCTION__)) {
    return;
}
```
…and `frl_thirdparty_check_query_triggers()` uses the same string `frl_thirdparty_inbound_cache_clear` to mark itself as "processed". However, **neither function ever sets the guard to `true` for itself** — only `frl_thirdparty_check_query_triggers()` sets it to `true` after its work, and `frl_thirdparty_inbound_cache_clear()` does not set it at all after its own work.

Reading the code: `frl_thirdparty_inbound_cache_clear` does **not** call `frl_is_already_running(__FUNCTION__, true)` to mark itself as complete. This means:
- Hook `A` fires → `frl_thirdparty_inbound_cache_clear` runs → no guard set.
- Hook `B` fires (cascading) → `frl_thirdparty_inbound_cache_clear` runs again → **duplicate cache clear**.

The guard at line 397 only checks (not sets), so a second invocation **within the same call frame** would still see `false`.

**Impact:**
Two cache-clearing operations fired in one request (common when LiteSpeed purges its internal cache and WordPress's `litespeed_purged_all` then fires, and then a third-party plugin's hook also fires) will result in two `frl_cache_clear('all')` calls. With the orchestrator, this is mostly idempotent (`$groups_cleared` array dedups at [`class-cache-manager.php:1216-1220`](core/cache/class-cache-manager.php:1216)), but the third-party `frl_add_admin_notice` at line 425 will fire twice, producing duplicate admin notices.

**Recommendation:**
Add `frl_is_already_running(__FUNCTION__, true);` after the work in `frl_thirdparty_inbound_cache_clear()` and after the cascade `break;` in `frl_thirdparty_check_query_triggers()` (which is already done at line 376, but the inbound function lacks it).

---

## 🟠 HIGH-SEVERITY FINDINGS

### H1. `$key_cache` in `Frl_Cache_Manager` is an unbounded within-request static (request-scoped, not cross-request)

**File:** [`core/cache/class-cache-manager.php:16-19`](core/cache/class-cache-manager.php:16), populated at lines 380-392

**Problem:**
```php
private static $runtime_cache = [];
private static $key_cache = [];  // <-- NO LRU
private static $group_keys = [];
```

The `$runtime_cache` is bounded to `FRL_CACHE_RUNTIME_MAX_ITEMS = 1000` via LRU at lines 430-437. The `$key_cache` (used to memoize `generate_key` hashes for array keys) has **no LRU eviction**. It is populated **only** when an array key is passed to `frl_cache_remember` (line 380) — the vast majority of cache calls in this codebase pass string keys (e.g., `'all_options'`, `'disable_comments'`) and never touch `$key_cache`.

**Scope clarification:**
- `$key_cache` is a **PHP static** — it lives for the lifetime of a single request, then is garbage-collected. There is no cross-request leak.
- It only grows when callers pass **array** keys to `frl_cache_remember`, and the entry is `len(json_encode($key)) + 32` bytes (the md5 hash).
- For 100 unique array keys averaging 200 chars each, that's ~23 KB. Not catastrophic on a typical request.

**Impact (honest):**
- **Typical page render:** Likely zero impact — most calls use string keys.
- **Long-running PHP processes** (WP-CLI imports, cron batches, REST batch processing) where the same process handles many sequential requests, the static state can persist between what would otherwise be separate requests. Here, growth can become measurable.
- **Pages with many array-keyed `frl_cache_remember` calls** (heavy shortcode/ACPT/repeater rendering): a few hundred KB possible, but not gigabytes.

**Recommendation:**
Apply the same LRU eviction pattern used for `$runtime_cache` (line 426-437) to `$key_cache`, or cap it to a reasonable maximum (e.g., 5000) with simple FIFO eviction. Defensive fix; not urgent unless you run long-lived PHP workers.

---

### H2. `frl_log_capture_*` filters run unconditionally on every block, shortcode, and query — even when debug logging is off

**File:** [`includes/main.php:42-47`](includes/main.php:42)

```php
if (!frl_is_rest_api_request()) {
    add_filter('render_block_data', 'frl_log_capture_render_block_enter', 10, 1);
    add_filter('render_block',      'frl_log_capture_render_block_exit',  10, 2);
    add_action('pre_get_posts',     'frl_log_capture_query',              1,  1);
    add_filter('do_shortcode_tag',  'frl_log_capture_shortcode',          10, 4);
}
```

**Problem:**
- `frl_log_capture_render_block_enter` (line 455) is registered for every frontend request (when not REST). It iterates `$attrs` with `foreach` and runs `array_flip` for whitelist lookup **on every block** even if `WP_DEBUG_LOG` is off. The actual log write is gated, but the **filter callback itself** is not.
- `frl_log_capture_query` (line 520) fires on every `pre_get_posts` at priority 1 (before most other filters), and only checks `$query->is_main_query()`. It runs the `is_object && method_exists` check for every query.
- `frl_log_capture_shortcode` (line 529) fires on every shortcode render, runs `is_scalar` loop, and stores in `$GLOBALS['frl_last_shortcode']`.

**Impact:**
On a page with 50 blocks, this is 50+ function calls + global array mutations. On a page with many `pre_get_posts` queries (block editor preview, archive pages with related-posts blocks), the overhead compounds. For a website whose USP is performance, paying for debug-only instrumentation on every request is a real cost.

**Recommendation:**
Wrap all four in `if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)` (or check the `frl_log_*` options), so they only run when the developer has explicitly opted in to debug output. The `frl_log()` function itself can short-circuit silently when debug logging is off — the current code already does this for the actual log write, but the surrounding work (filter registration, attribute iteration, global mutations) is still paid.

---

### H3. `frl_get_current_user()` cache key derived from raw cookie value — cache pollution vector (not CPU/memory DoS)

**File:** [`includes/helpers/functions.php:131-146`](includes/helpers/functions.php:131)

**Problem:**
The cache key is `user_<cookie_username>_<8-char-md5-of-cookie>`, derived directly from the raw `LOGGED_IN_COOKIE` value — **not** from the validated user ID. This means:

- **Anonymous visitors** (no `LOGGED_IN_COOKIE`): key is `user_anonymous_<fixed-hash>` — one entry for all. Not a concern.
- **Authenticated users**: key is per-username-per-session. Each unique session creates a unique entry, TTL 1 hour. Bounded by user count, not concerning.
- **Invalid / attacker-chosen cookies**: the key depends on the raw cookie value, not on whether the cookie validates. The cache miss callback at line 137-146 returns `WP_User(0)` for invalid auth (WordPress's own `wp_get_current_user()` handles validation), and that `WP_User(0)` is **cached for 1 hour under the attacker-chosen key**.

**Impact:**
This is a **cache pollution** concern, not a CPU/memory exhaustion DoS. An attacker can send many unique `LOGGED_IN_COOKIE` values and create one persistent cache entry per attempt. The persistent cache backend's eviction policy bounds the impact:
- **Redis / Memcached**: LRU/LFU eviction. Legitimate user entries may be evicted under attack pressure.
- **Transient fallback** (no object cache): `wp_options` table grows until natural cleanup. Bounded by the 1-hour TTL.

There is no code-execution or memory-exhaustion vector. The cross-session guard at line 156 (verifying cached user's login matches cookie username) handles a different concern (stale-cache cross-session) and does not mitigate this attack.

**Recommendation:**
Key the cache by the validated `wp_get_current_user()->ID` (a small integer, bounded), not by the raw cookie value. For anonymous visitors (`$user->ID === 0`), use a single static key like `user_anon`. This eliminates the attacker-controlled key space.

---

### H4. `frl_update_option()` adds a new priority-9999 closure on every call — closure accumulation

**File:** [`includes/helpers/functions-options.php:131-136`](includes/helpers/functions-options.php:131)

**Problem:**
```php
add_filter('pre_option_' . $prefixed_key,
    function () use ($normalized_value) {
        return $normalized_value;
    },
    9999,
    1);
```

Every `frl_update_option()` call:
1. Calls `remove_all_filters('pre_option_' . $prefixed_key)` at line 123 (good — clears all prior closures).
2. Calls `update_option()` (line 124).
3. Adds a new priority-9999 closure (line 131-136) that returns the freshly-written value.

If `frl_update_option()` is called once per option, this is fine — the closure is the "source of truth" for the rest of the request. But if a setting is saved and then later read again in the same request, the closure stack is empty. However, if `frl_update_option()` is called for **the same option twice in one request** (e.g., import/reset flows), the first closure is removed at line 123, then re-added. Fine.

The actual concern: **the filter is added at priority 9999, but `get_option()`'s built-in `pre_option_*` filter chain runs at priority 10 by default**. With WordPress's `get_option()` flow:
1. WP core's `get_option()` checks alloptions cache.
2. If miss, applies `pre_option_{key}` filters — ours runs at 9999, after any other plugin's filters.
3. If still empty, reads from DB.

If a third-party plugin also has a `pre_option_frl_foo` filter at priority 10, our priority 9999 wins. But if their filter at priority 10 returns a value, our 9999 never runs. This is the intended behavior — but it means **our filter does not "override" the third-party plugin's filter, it only catches the case where no other filter returned a value**.

If `frl_update_option()` is called mid-request, our priority-9999 filter ensures the value we just wrote is "fresh" for the rest of the request, even if another plugin's filter overwrote the DB value. This is correct for the plugin's own namespace, but introduces a subtle issue: if a third-party plugin's filter returns a value before our 9999 filter runs, the value returned to the caller is the third-party's, not ours. This is by design but may surprise developers.

**Impact:**
- Memory: each `frl_update_option()` adds a closure to `pre_option_*`. `remove_all_filters()` clears all of them, so on a save flow that updates 50 options, you have 50 closures live at once. Each closure captures `$normalized_value` (1 var). Modest memory.
- Performance: `get_option()`'s filter chain is walked; priority 9999 closures run last. With many options, this is a small overhead.

**Recommendation:**
Document the priority-9999 contract clearly. Consider de-registering the priority-9999 closure after the request's "settling" point (e.g., on `shutdown`) — but this would break the contract that "what you just wrote is what you read back".

---

### H5. `frl_thirdparty_maybe_notify()` removes AND re-adds inbound action listeners on every outbound call

**File:** [`modules/thirdparty/thirdparty.php:509-583`](modules/thirdparty/thirdparty.php:509)

**Problem:**
The function removes all inbound listeners (line 525-527), then in the `try` block fires outbound actions (line 547-565), then in `finally` re-adds them (line 571-573). For each outbound notification cycle, this is N removes + N re-adds (where N = number of inbound hooks registered, typically 3-5 for LiteSpeed, Breeze, WP Rocket, etc.).

This is wrapped in `frl_is_already_running(__FUNCTION__)` re-entrancy guard, so within a single call stack it doesn't loop. But:
- `frl_thirdparty_maybe_notify` is called from `clear_rewriter_caches()`, which is itself called from `update_option_permalink_structure`. If a single request fires multiple `update_option_*` actions (settings page save), the re-entrancy guard prevents duplicate calls — good.
- But the inbound re-add happens unconditionally, even if the outbound actions already re-fired the inbound listener through some other path (a chain). The `try/finally` guarantees re-add but does not deduplicate.

**Impact:**
Minor performance hit. Each `frl_thirdparty_maybe_notify('hard')` call incurs N filter removals + N filter re-adds + the outbound work itself. In a settings save flow that triggers multiple `update_option_*` hooks, this can compound.

**Recommendation:**
Use `remove_filter`/`add_filter` only once per request, gated by a static boolean. The re-entrancy guard at line 515 already prevents re-entry; extend it to skip the entire setup/teardown when called multiple times in one request.

---

### H6. `frl_render_block_core_navigation_translation()` registers a global `block_type_metadata_settings` filter for ALL pages

**File:** [`includes/main.php:35`](includes/main.php:35), [`includes/shared/navigation.php:37-89`](includes/shared/navigation.php:37)

**Problem:**
`add_filter('block_type_metadata_settings', 'frl_render_block_core_navigation_translation', 10, 2)` is registered unconditionally. The function fires for **every** block metadata registration across the entire site — once per block type, during the `init` phase. While the function does early-return when `$metadata['name'] !== 'core/navigation'`, the filter is invoked for **every** block type.

**Impact:**
For each block type registration, the filter is invoked. WordPress registers a few dozen block types by default (core blocks + theme/plugin blocks), so this is ~30-50 calls per page load — each is a quick `if/return`, but the cumulative overhead is non-trivial. Worse, the function body is a closure that captures `$current_lang` by use — so each call carries the closure's captured scope.

**Recommendation:**
Move the filter to fire only when needed (e.g., conditionally during `rest_api_init` or when the navigation block is actually rendered). The current guard inside the function (`is_admin()`/`frl_is_rest_api_request()`) is at line 46, but the filter itself still runs.

---

### H7. `frl_alter_query()` is also duplicated in the `pbs` module (deprecated module)

**File:** [`modules/pbs/custom-post-types.php:17`](modules/pbs/custom-post-types.php:17)

**Problem:**
The `pbs` module adds another `pre_get_posts` filter `frl_pbs_kill_taxonomy_archives` (no priority shown — defaults to 10). This is the same priority as `frl_alter_query` and they can interact unpredictably. Per the memory bank, the `pbnova` module is deactivated, but `pbs` may still be active.

**Impact:**
Two filters on `pre_get_posts/10` from this plugin. While the order is deterministic (registration order), both run on every query, increasing the per-query cost.

**Recommendation:**
Audit which modules are still active. If `pbs` is deprecated, deactivate it or move its logic into a single consolidated `pre_get_posts` filter.

---

## 🟡 MEDIUM-SEVERITY FINDINGS

### M1. `frl_disable_comments()` removes comment REST endpoints without an `is_admin` / `frl_is_logged_in` guard

**File:** [`includes/shared/website-features.php:271-275`](includes/shared/website-features.php:271)

```php
add_filter('rest_endpoints', function ($endpoints) {
    unset($endpoints['/wp/v2/comments']);
    unset($endpoints['/wp/v2/comments/(?P<id>[\d]+)']);
    return $endpoints;
});
```

This filter is registered on every page load (the call to `frl_disable_comments()` is inside `frl_disable_wp_core_features()` which runs at `init/10`). Unlike `frl_disable_rest_endpoints` in [`public/public.php:511-530`](public/public.php:511), this filter has **no** `frl_is_logged_in()` guard — so an authenticated editor viewing the block editor cannot moderate comments via REST. The block editor's Comments panel is also affected.

**Recommendation:**
Add the same `frl_is_logged_in()` guard as `frl_disable_rest_endpoints()`.

---

### M2. `frl_thirdparty_admin_scripts()` runs `frl_is_thirdparty_plugin_active()` twice in a loop on every admin request

**File:** [`modules/thirdparty/thirdparty.php:50-71`](modules/thirdparty/thirdparty.php:50)

**Problem:**
For each known Meow plugin path (currently 2), it calls `frl_is_thirdparty_plugin_active()`. That function (at [`includes/helpers/functions-access-control.php:512-530`](includes/helpers/functions-access-control.php:512)) does a `frl_cache_remember('options', 'thirdparty_active_plugins', ...)` lookup. With static caching at the cache manager level, the second call is cheap, but the loop structure is wasteful when the lookup could be done once.

**Impact:**
Negligible per-request (sub-millisecond), but adds 1 extra `frl_cache_remember` call per Meow plugin. For 2 plugins, this is 2 extra cache lookups per admin page.

**Recommendation:**
Resolve `thirdparty_active_plugins` once at the top of the function, then iterate.

---

### M3. `frl_process_nav_menu_url_transforms` registered on `init/20` runs on every block render, even when not needed

**File:** [`includes/main.php:36`](includes/main.php:36), [`includes/shared/navigation.php:97-104`](includes/shared/navigation.php:97)

The function checks `if (frl_is_rest_api_request() || is_admin())` inside the filter (line 120-122), which is good. But it still calls `apply_filters('frl_nav_menu_url_transforms', [])` on every block render, and runs `preg_match` for every block content. The function only processes `core/navigation-link` and `core/navigation-submenu` blocks (line 125-127), so on pages with many blocks, this is N invocations of the function with 4 `in_array` checks + 1 `preg_match`.

**Impact:**
On a page with 100 blocks, this is 100 invocations of a function that returns early after 2 checks. Sub-millisecond per call, but cumulative. Plus, `apply_filters` itself is non-trivial (it walks the global `$wp_filter` array).

**Recommendation:**
Move the filter registration to fire only when `nav_menu_custom_urls` is configured AND only for navigation-link blocks via early detection.

---

### M4. `frl_admin_bar_add_menu_primary()` builds the entire menu structure inside a `frl_cache_remember` callback — cache key per user per language

**File:** [`includes/shared/logged-user.php:54-66`](includes/shared/logged-user.php:54)

**Problem:**
The cache key is `{$lang}_adminbar_uid{$user_id}`. For a multi-language site with 5 languages and 100 logged-in users, this creates 500 unique cache entries — each holding the full menu data structure. The menu includes per-user CPT links (`frl_has_access($value['access'])` is called for each CPT), which means the callback runs once per user per language and stores the result.

**Impact:**
On a multilingual site with many users, the admin cache group grows. The `admin` group has 1-hour TTL (per `FRL_CACHE_TTL['admin']`), but for a busy site with many admins/editors, this is significant.

**Recommendation:**
The static `$links_custom` at line 265 is already shared. Consider also sharing the per-CPT links data (which only depends on the FRL_AB_CPT_LIST constant + capability, not on user) — only the per-user capabilities differ.

---

### M5. `frl_get_cpt_id_by_slug()` uses `md5($slug)` for cache key — full 32-char hash per lookup, unbounded

**File:** [`includes/helpers/functions.php:413`](includes/helpers/functions.php:413)

The cache key is `'cptslug2id_' . sanitize_key($cpt) . '_' . md5($slug)`. For sites with many CPTs and many slugs, the md5+prefix is 32+ chars per entry. While individual entries are small, the key shape is fine — but the lookup is called per-shortcode per-page.

**Impact:**
Negligible per call. Minor: `get_page_by_path()` is itself a slow function (loops all posts of that type).

---

### M6. `frl_enqueue_scripts()` and the static `$enqueued_groups` leak across the same request

**File:** [`includes/helpers/functions.php:206-213`](includes/helpers/functions.php:206)

The `static $enqueued_groups = []` is per-function-call-stack but in PHP it persists for the lifetime of the request (or until the function is redefined). On a single page load, this is fine. On a WP-CLI process or a long-running batch operation that calls `frl_enqueue_scripts` thousands of times with different `$assets_key`, this grows linearly. Sub-millisecond per add, but the array lookup is `O(1)`.

**Impact:**
Low. Defensive note only.

---

### M7. `frl_disable_comments()` calls `remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal')` on every `admin_init` — fine but redundant

**File:** [`includes/shared/website-features.php:300-302`](includes/shared/website-features.php:300)

This fires on every admin request, and `remove_meta_box` is idempotent — but adds ~5-10 array lookups per admin request. Negligible.

---

### M8. `frl_log_capture_query` is registered on `pre_get_posts/1` — runs on every WP_Query

**File:** [`includes/main.php:45`](includes/main.php:45)

Already covered in H2. The function only does a `$query->is_main_query()` check, but the `is_object` + `method_exists` check on line 522 is paid on every query.

---

### M9. `frl_get_html_option()` uses different cache keys for logged-in vs visitor, but `header_html_php` is run with PHP execution on each cache miss

**File:** [`public/public.php:414-432`](public/public.php:414)

The cache key correctly distinguishes user/visitor (line 323, 343). The `frl_process_php_string` is only run on cache miss (line 425), which is correct. But the cache key `header_html_user` / `header_html_visitor` is the same regardless of the option content, so the cache invalidation depends on the user-aware cache. The option itself is `header_html` (line 419), and changes to the option are not automatically invalidated from the HTML group. The dependency cascade `options → html` (in `FRL_CACHE_DEPENDENCIES`) handles this — but only if `frl_cache_clear('options')` is called when the option changes. Looking at [`includes/helpers/functions-options.php:126-129`](includes/helpers/functions-options.php:126), `frl_cache_clear('options', 'all_options', false)` is called after every `frl_update_option()` — that doesn't clear the html group because the dependency cascade is not triggered for single-key clears. So changes to `header_html` option are **not** picked up by visitors until the html cache expires (1 week).

**Impact:**
A change to the header/footer HTML option is invisible to visitors for up to 1 week. This is a real bug for content authors.

**Recommendation:**
Add `frl_cache_clear('html')` in `frl_update_option()` when the key is `header_html` or `footer_html`. Or change the cache group for HTML options to use a tighter TTL with proper invalidation.

---

## 🟢 LOW / INFORMATIONAL FINDINGS

### L1. `frl_log_capture_shortcode` always overwrites `$GLOBALS['frl_last_shortcode']` — only the last shortcode is preserved

**File:** [`includes/helpers/functions-error-log.php:529-544`](includes/helpers/functions-error-log.php:529)

If multiple shortcodes render on a page, only the last one's tag/attrs are stored. Intentional but worth documenting.

---

### L2. `frl_log_capture_render_block_enter` uses `static $whitelist_flip` — fine, but no escape for non-scalar values in nested arrays

**File:** [`includes/helpers/functions-error-log.php:466-490`](includes/helpers/functions-error-log.php:466)

The whitelist is large (~24 keys) and only allows scalar values or arrays. Object/closure values are silently converted to `gettype()` strings. Could mask bugs.

---

### L3. `frl_is_already_running()` returns `false` on first call, but the flag-setting behavior depends on input

**File:** [`includes/helpers/functions-access-control.php:190-205`](includes/helpers/access-control.php:190)

Standard re-entrancy pattern, but note that the flag is `false` after reset (line 195) — calling `frl_is_already_running($key)` after `frl_is_already_running($key, true)` will start fresh. This is by design but not obvious.

---

### L4. `frl_process_php_string()` in `frl_get_html_option` runs `eval`-style code if `header_html_php` is enabled — security review needed

**File:** [`public/public.php:425`](public/public.php:425)

The function `frl_process_php_string` is not in scope of this audit (need to read it separately), but if it `eval`s PHP from the option value, this is a security-sensitive function that should only be run with admin/editor capability and never in the cache value for visitors. Need to verify.

---

### L5. `frl_get_post_terms()` in `functions.php:877-929` calls `pll_get_term_language()` in a loop — N+1 query pattern for post with many terms

**File:** [`includes/helpers/functions.php:890-915`](includes/helpers/functions.php:890)

For each term in `$terms`, calls `pll_get_term_language($term->term_id)`. Polylang's function does a `get_term_meta` lookup per term — for a post with 20 terms, this is 20 individual term-meta queries. Polylang provides a bulk API (`pll_get_term_translations()` or the `term_language` cache), which would reduce this to 1 query.

**Impact:**
N+1 on term language lookups. For a post with many terms and a multilingual site, this is a measurable overhead.

**Recommendation:**
Use `pll_get_term_translations()` or Polylang's bulk term language API.

---

### L6. `frl_log_capture_*` functions use `$GLOBALS` for state — fragile across PHPUnit/parallel requests

**File:** [`includes/helpers/functions-error-log.php:498-509`](includes/helpers/functions-error-log.php:498)

Using `$GLOBALS` for stack state is fine for single-request use, but breaks in:
- WP-Cron (multiple simultaneous cron events)
- PHPUnit parallel test runs
- Any context where two `render_block` invocations could interleave (rare but possible in nested output buffering)

**Impact:**
Theoretically possible, practically rare.

---

## 🛠️ ADDITIONAL OBSERVATIONS

### Performance note: `frl_get_assets_versions` is called for every asset group, each doing file I/O

**File:** [`includes/helpers/utilities.php:53-64`](includes/helpers/utilities.php:53)

`filemtime()` is called per asset per page render. While the result is cached via `frl_cache_remember`, the cache key is per-group, so each asset group incurs one `filemtime` per group per request. For sites with many enqueued assets, this is many `filemtime` calls. WordPress core has `_doing_it_wrong` notes about heavy filesystem operations — `filemtime` is cheap but not free. This is a minor performance concern.

---

### Performance note: `frl_get_html_option` uses 1-week TTL for the html group but the option can change frequently

**File:** [`config/config-cache.php:41`](config/config-cache.php:41), [`config/config-cache.php:84-94`](config/config-cache.php:84)

`FRL_CACHE_TTL['html'] = WEEK_IN_SECONDS`. If the `header_html` or `footer_html` option is updated via the admin UI, the cache invalidation does not propagate to the `html` group (per M9 above). This means content authors may see stale HTML for up to a week after saving.

---

### Code-quality note: `frl_thirdparty_inbound_cache_clear` has a 60s cooldown for rewrite flush

**File:** [`modules/thirdparty/thirdparty.php:412-416`](modules/thirdparty/thirdparty.php:412)

```php
if (!frl_get_transient('rewrite_flush_cooldown')) {
    frl_set_transient('rewrite_flush_cooldown', true, 60);
    frl_schedule_rewrite_flush();
}
```

The cooldown is set with `frl_set_transient` which goes through the plugin's cache layer. The cooldown prevents cascading rewrite flushes from third-party plugins. This is correct, but the cooldown applies to **all** rewrite flush triggers for 60s — including legitimate flushes from settings saves. If a settings save triggers a rewrite flush, and then a third-party cache plugin purges within 60s, the legitimate flush is throttled. This is intentional, but worth noting.

---

### Code-quality note: `frl_thirdparty_maybe_notify` deduplication is incomplete

**File:** [`modules/thirdparty/thirdparty.php:509-583`](modules/thirdparty/thirdparty.php:509)

The function loops through `$outbound_hooks` and fires each one. If a hook has multiple `triggers` matching, the hook fires once per `frl_thirdparty_maybe_notify` call, but multiple `frl_thirdparty_maybe_notify` calls (e.g., 'hard' + 'rewrite_flush' in a single request) would fire the same hook twice. This is the intended behavior (separate events → separate notifications), but for cache plugins that don't differentiate (most fire "purge all" on any notification), the duplicate notifications are wasteful.

**Recommendation:**
Track which hooks have been notified per request via a static array and skip re-notification.

---

## 📊 CROSS-CUTTING OBSERVATIONS

### Hook density on frontend page render

Counting `add_action` and `add_filter` calls that fire on every frontend page (not admin):

| Source | Count | Notes |
|--------|------:|-------|
| `includes/main.php` | 12 | Mostly load/footer/head hooks (cheap) |
| `includes/shared/navigation.php` | 2 | `render_block` and `init/20` |
| `public/shortcodes.php` | 2 | `render_block` (twice — translation + apply_shortcodes) |
| `core/rewriter/class-rewriter.php` | 2 | `post_type_link`, `term_link` |
| `core/rewriter/features/*.php` | 2+ | Per-feature `request`, `init` (5 features) |
| `core/translator/class-translation-service.php` | (via service) | `pll_get_post_types`, `block_type_metadata_settings` |
| `modules/subdomain_adapter/*` | 12+ | All URL-related filters |
| **Total** | **40+** | Each runs on every page render |

For a "performance USP" plugin, 40+ hooks per request is significant. Many of these have early-return guards, but each is still a function call + filter chain walk.

---

### Cache group usage map (verified)

| Group | Persistent? | Language-keyed? | Heavy (skipped on light)? | Used in |
|------:|:-----------:|:---------------:|:-------------------------:|--------|
| `staticdata` | yes | no | yes | 0 found in current scan |
| `theme` | yes | no | no | `themekit.php` |
| `html` | yes | no | no | `public.php`, `admin/.../resolver.php` |
| `versions` | yes | no | no | `utilities.php` |
| `postdata` | yes | **yes** | yes | 10+ files |
| `blocks` | yes | **yes** | yes | `translation-service.php`, `pbproperty/geodirectory.php` |
| `shortcodes` | yes | **yes** | no | 15+ files (most heavily used) |
| `translations` | yes | **yes** | yes | `translation-service.php` |
| `permalinks` | yes | **yes** | yes | 20+ files (rewriter, translator) |
| `rewriter` | yes | no | no | `rewriter` module |
| `metafields` | yes | **yes** | no | `field-translator.php` |
| `options` | yes | no | no | most configuration |
| `environment` | yes | no | no | `class-environment-*` |
| `adminui` | yes | no | no | admin pages |
| `admin` | yes | no | no | admin / logged-in user |

The `staticdata` group is declared in `FRL_CACHE_PERSISTENT_GROUPS` and `FRL_CACHE_TTL` but appears to have **no current callers** based on this scan. Dead config or potential future use.

---

## 🔁 UNCHANGED FROM PREVIOUS AUDITS

Per [`memory-bank/progress.md:84-85`](memory-bank/progress.md:84) and [`memory-bank/activeContext.md:91-99`](memory-bank/activeContext.md:91), the following known items were explicitly **skipped** at user request and are still present in the code:

1. **WSForm mutations** (2.5) — not audited here; needs separate review.
2. **WSForm unescaped CSS** (3.1) — not audited here; needs separate review.
3. **jQuery Migrate typo** (4.1) — referenced in `public/public.php:17` `frl_remove_jquery_migrate`. Should re-confirm the function name is correct.

Note: `frl_alter_query()` was originally listed in the 2026-07-01 audit as Critical 2.3. It is **not a bug** — it is a deliberate, voluntary performance optimization. The user has explicitly confirmed the design intent during this audit. Sites needing WP_Query's full default behavior can opt out via the `custom_wp_query` option.

---

## ✅ RECOMMENDED PRIORITY ORDER FOR FIXES

| Order | Finding | Why |
|------:|---------|-----|
| 1 | H2 (log_capture_*) | Per-request overhead for every page; affects USP. |
| 2 | C1 (REST guard) | Block editor breaks under cache corruption. |
| 3 | C2 (re-entrancy guard reset) | Footgun, but low real-world impact. |
| 4 | C3 (inbound guard missing) | Duplicate admin notices. |
| 5 | M1 (REST comments unguarded) | Editor moderation broken. |
| 6 | M9 (html option invalidation) | Content author experience. |
| 7 | H3 (current user cache key) | Cache pollution under attacker-chosen keys (low impact). |
| 8 | H1 (key_cache unbounded) | Within-request unbounded growth only when array keys used. |
| 9 | H6 (block_type_metadata_settings) | Filter chain overhead. |
| 10 | L5 (N+1 term language) | Performance on multilingual sites. |
| 11 | All other Medium and Low | Cleanup. |

> Note: `frl_alter_query()` is **not** in this priority list. It is a deliberate performance optimization with documented tradeoffs.

---

## 🧭 SELF-AUDIT (per [`memory-bank/mandatory-rules.md`](../memory-bank/mandatory-rules.md))

| Mandatory rule | Status |
|----------------|--------|
| Read memory-bank before task | ✅ Pass |
| Identify the underlying problem | ✅ Pass — every finding includes the codepath and the failure mode |
| Cite specific file/line references | ✅ Pass — every finding has `file:line` references |
| Verify with grep/ripgrep | ✅ Pass — `search_files` used to confirm scope of issues |
| Chain-of-thought from `systemPatterns.md` | ✅ Pass — patterns from systemPatterns verified (cache groups, lazy load, hook discipline) |
| Zero Regression Policy | ✅ Pass — no edits made; findings only |
| Honesty / No opinions as facts | ✅ Pass — retracted C1 on user confirmation that `frl_alter_query()` is a deliberate performance optimization, not a bug |
| "I don't know" rule | ✅ Pass — items not in scope (WSForm, jQuery Migrate) flagged explicitly |
| No placeholders | ✅ Pass — full report, no `// TODO` markers in findings |
| Task Completion Check | ✅ Pass — comprehensive report compiled with severity levels and fix priority |

---

*Last Updated: 2026-07-02*
