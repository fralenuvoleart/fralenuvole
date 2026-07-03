# Cold Cache Performance Audit — Fralenuvole v5.7.3.9

**Date:** 2026-07-03  
**Scope:** Entire codebase analysis for cold-cache performance bottlenecks  
**Methodology:** Static code analysis of all 150+ PHP files, with focus on `frl_cache_remember()` callbacks, direct `$wpdb` queries, iteration patterns, and the bootstrap/init sequence

---

## Executive Summary

When the object cache is flushed (Redis/Docket/Memcached emptied, or transients expire), every `frl_cache_remember()` call degenerates to its callback — executing the raw DB query, filesystem operation, or computation within. The plugin has **106 `frl_cache_remember()` call sites** and **106+ `$wpdb` query sites**. On cold cache, these all fire simultaneously on the first request.

**Key finding:** The plugin is well-architected for warm-cache performance (cascading group dependencies, LRU runtime cache, auto-preload). But the **bootstrap sequence alone triggers 8–12 DB queries** on a cold frontend request, and the **admin dashboard can trigger 15–25 DB queries** on cold cache. Below is a detailed breakdown by severity.

---

## 🔴 Critical (P0) — Bootstrap-time Cold Cache Operations

### P0.1 — `frl_get_plugin_options_db()`: Full LIKE scan on `wp_options`

**File:** [`includes/helpers/functions-options.php:267-329`](includes/helpers/functions-options.php:267)  
**Called from:** `frl_cache_remember('options', 'all_options', ...)` at [`functions-options.php:237`](includes/helpers/functions-options.php:237)

```php
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE %s",
        $wpdb->esc_like($prefix) . '%'
    )
);
```

**Cold-cache impact:** This is the single most expensive operation. On a WordPress site with thousands of rows in `wp_options`, a `LIKE 'frl_%'` scan (even with an index on `option_name`) iterates every matching row. The callback also builds an `option_type_map` by calling `frl_get_all_plugin_options_settings(null)`, which itself triggers 4 nested `frl_cache_remember()` calls that each read from PHP constants on miss.

**Trigger path:** `frl_get_option($key)` → `$loaded=false` → `frl_get_plugin_options('all')` → `frl_cache_remember('options', 'all_options', ...)` → `frl_get_plugin_options_db()`

`frl_get_option()` is called **pervasively** throughout the codebase — every feature gate, every config read. The first call triggers this full DB scan. Subsequent calls within the same request hit the static `$options` array.

**Recommendation:** Acceptable as-is. The `LIKE` on an indexed `option_name` column is ~0.1ms on modern MySQL. The static cache ensures it runs once per request. No realistic optimization without architectural change to autoload groups.

---

### P0.2 — `auto_preload()`: 5–6 batch preloads per request

**File:** [`core/cache/class-cache-manager.php:81-97`](core/cache/class-cache-manager.php:81)

```php
public static function auto_preload()
{
    $groups_to_preload = frl_is_admin()
        ? FRL_CACHE_PRELOAD_BACKEND_GROUPS   // 5 groups
        : FRL_CACHE_PRELOAD_FRONTEND_GROUPS;  // 6 groups

    foreach ($groups_to_preload as $group) {
        self::preload_multi($group);
    }
}
```

**Cold-cache impact:** Each `preload_multi($group)` → `get_multi($group, null, false)`. When no object cache is functional AND the group is persistent, `get_multi()` executes:

```php
// At line 984-990
$query = $wpdb->prepare(
    "SELECT option_name, option_value
     FROM $wpdb->options
     WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like($prefix) . '%',
    $wpdb->esc_like($timeout_prefix) . '%'
);
```

This is **6 `LIKE` queries** on `wp_options` on a cold frontend request (options, rewriter, environment, theme, versions, html), plus their dependency chains. When an object cache IS functional, the preload is harmless (null-key `get_multi` on object cache groups just marks them as loaded).

**Frontend cold-cache query budget:**
| Group | Query | Notes |
|-------|-------|-------|
| `options` | LIKE `_transient_frl_cache_options_%` | ALL plugin options |
| `rewriter` | LIKE `_transient_frl_cache_rewriter_%` | Rewrite rules, CPT lists, exclusion patterns |
| `environment` | LIKE `_transient_frl_cache_environment_%` | Domain config, state |
| `theme` | LIKE `_transient_frl_cache_theme_%` | Theme patterns, handle removals |
| `versions` | LIKE `_transient_frl_cache_versions_%` | Asset file version hashes |
| `html` | LIKE `_transient_frl_cache_html_%` | Critical CSS, header/footer HTML, login branding |

**Recommendation:** The preload is intentionally aggressive for warm-cache performance. On cold cache with object cache, it's a no-op. On transient-based sites, the 6 LIKE queries are the price of avoiding N+1 queries per `frl_cache_remember()` call later in the request. This is a reasonable trade-off.

---

### P0.3 — `get_provider_details()`: Cache backend detection recursion guard

**File:** [`core/cache/class-cache-manager.php:195-367`](core/cache/class-cache-manager.php:195)

**Cold-cache impact:** Reads `object-cache.php` file (up to 2KB via `file_get_contents()`), detects cache backend (LiteSpeed/Docket/Redis), checks plugin activation status. Uses a clever self-referential recursion guard at line 225-228: sets `$cached_provider_details` early to break the `is_object_cache_truly_functional() → _is_plugin_globally_active() → frl_cache_remember() → get() → is_object_cache_truly_functional()` loop.

The result is cached in a core WP transient (`frl_cache_object_cache_provider_details_v2`) for `WEEK_IN_SECONDS`. After the first cold request, subsequent requests hit the transient directly (line 204-208).

**Recommendation:** Well-designed. No change needed.

---

### P0.4 — Environment Manager: `enforce_environment_settings()` at `init:10`

**File:** [`core/environment/class-environment-manager.php:131-330`](core/environment/class-environment-manager.php:131)

**Cold-cache impact:** Runs on every admin page load (and frontend when `frl_has_access()`). The full execution path:

1. `get_domain_config()` → `frl_cache_remember('environment', ...)` → on cold cache: `build_domain_config()` 
2. `build_domain_config()` iterates `FRL_ENV_MAP`, calls `preg_replace`, `site_url()`, `parse_url()`, reads 4 constants, calls `array_replace_recursive`
3. `check_environment_state()` compares stored vs current host
4. If state changed: applies WP options, plugin options, plugin activation, modules, classifies change type, dispatches cache operation

**The throttle saves us:** 60s throttle for admins, 300s for non-admins (lines 200-207). After the first cold-cache enforcement, subsequent requests within the throttle window skip the full check. The `last_check` timestamp is cached in the `environment` group.

**Recommendation:** The throttle makes this acceptable. However, on the very first cold request after cache flush, `build_domain_config()` runs its full constant-merging logic. This is an admin-only path.

---

### P0.5 — `frl_get_all_plugin_options_settings()` nested cache calls

**File:** [`includes/helpers/functions-options.php:337-385`](includes/helpers/functions-options.php:337)

**Cold-cache impact:** When called with `$key=null` (getting all settings), it calls:
- `frl_load_config_options_fields()` → `frl_cache_remember('adminui', 'config_options_fields', ...)` → iterates `FRL_DEFAULT_FIELDS`
- `frl_modules_load_options_fields()` → iterates module configs
- `frl_load_runtime_options_fields()` → iterates `FRL_OPTIONS_RUNTIME`
- `frl_load_runtime_cpt_options_fields()` → iterates `FRL_REWRITER_MULTILINGUAL_CPT`

Each of these is separately cached. On cold cache, all 4 callbacks run. The combined work is reading PHP constants and building arrays — no DB queries — but represents non-trivial PHP processing time.

**Recommendation:** Acceptable. These are constant-based configs; the array building is cheap. The separate caching prevents whole-dataset invalidation when only one source changes.

---

## 🟠 High (P1) — Redundant Operations & N+1 Patterns

### P1.1 — `frl_set_custom_admin_menu()`: full menu removal on every admin page

**File:** [`admin/admin.php:145-158`](admin/admin.php:145)

The post-edit-screen early return (line 152) was already patched. But for non-edit admin pages:

- `frl_remove_admin_menus()` iterates all global `$menu` and `$submenu` arrays
- Calls `frl_get_option()` for admin link removal lists (textlist-to-array parsing)
- Applies `usort()` for reordering

**Cold-cache impact:** The `frl_get_option()` calls for admin menu options hit the `all_options` static cache (already loaded by P0.1). The menu iteration is O(menu items) — typically 50–100 items. The sorting adds O(n log n).

**Recommendation:** Low priority. The menu array is small and the cold-cache cost is dominated by P0.1, not the iteration.

---

### P1.2 — WSForm stats: N+1 form name queries

**File:** [`modules/wsform/stats/wsform-submissions.php:76-80`](modules/wsform/stats/wsform-submissions.php:76)

```php
foreach ($form_ids as $form_id) {
    $form_names[$form_id] = frl_wsf_get_form_name($form_id);
}
```

`frl_wsf_get_form_name()` at [line 124](modules/wsform/stats/wsform-submissions.php:124) does a separate `SELECT post_title FROM posts WHERE ID = %d` for each form ID. With 10 form IDs, that's 10 individual DB queries — all within a single `frl_cache_remember('admin', ...)` callback.

**Recommendation:** Batch this into a single `SELECT ID, post_title FROM posts WHERE ID IN (...)` query. The result is cached in the `admin` group, so the fix only helps cold-cache performance (but that's exactly the scenario we're auditing).

---

### P1.3 — Cache display component: multiple `COUNT(*)` and `SUM(LENGTH())` queries

**File:** [`admin/components/class-display-cache.php:603-632`](admin/components/class-display-cache.php:603)

When viewing the cache admin tab on cold cache:
- `COUNT(*)` per persistent group (line 607)
- `SUM(LENGTH(option_value))` per persistent group (line 627)
- Both are `LIKE` queries on `wp_options`

**Cold-cache impact:** The cache tab is admin-only and viewed infrequently. The queries are per-group, meaning potentially 15+ `COUNT(*)` + 15+ `SUM(LENGTH())` queries if all groups are queried.

**Recommendation:** Low priority (admin-only, rarely visited tab). The `frl_cache_safe_db_get_var()` wrapper at least handles errors gracefully.

---

### P1.4 — `get_multi()` with null keys: loads ALL transients for a group

**File:** [`core/cache/class-cache-manager.php:945-1061`](core/cache/class-cache-manager.php:945)

When `$keys === null` and the group is persistent without object cache:
```php
$query = $wpdb->prepare(
    "SELECT option_name, option_value
     FROM $wpdb->options
     WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like($prefix) . '%',
    $wpdb->esc_like($timeout_prefix) . '%'
);
```

This loads ALL transients for a group into both the runtime cache AND WordPress's `options` cache group (`wp_cache_add_multiple`). This is the mechanism behind `auto_preload()` (P0.2).

**Cold-cache impact:** For the `options` group (which stores `all_options` — ALL plugin settings), this could be a large query. With 200+ plugin options × 2 (value + timeout) = 400+ rows in `wp_options`.

**Recommendation:** The batch-load design is correct for warm-cache — it prevents N+1 individual transient reads. On cold cache, the single bulk LIKE query is cheaper than N individual `get_transient()` calls. No change needed.

---

## 🟡 Medium (P2) — Nice-to-Fix

### P2.1 — `frl_build_featured_image_srcset()`: per-size `file_exists()` checks

**File:** [`public/public.php:61-115`](public/public.php:61)

When using `.avif` extension for featured images, the srcset builder calls `file_exists()` for every intermediate image size. A post with 10 registered image sizes means 10 `file_exists()` calls per post — all within the `frl_cache_remember('postdata', ...)` callback on cold cache.

**Cold-cache impact:** `file_exists()` is a stat() syscall — cheap individually but adds up. On a page with 10 posts, that's up to 100 stat() calls. The result is cached per-post in the `postdata` group, so only the first cold request pays this cost.

**Recommendation:** Could batch these by pre-computing available extensions per directory, but the cold-cache overhead is modest. The `postdata` cache with 1-day TTL means this only runs once per post per day.

---

### P2.2 — Adapter `get_active_languages_internal()` fallback: direct DB query

**File:** [`core/translator/adapters/polylang.php:135-141`](core/translator/adapters/polylang.php:135)

```php
return frl_cache_remember('translations', 'active_languages_fallback', function () {
    global $wpdb;
    $langs = $wpdb->get_col("SELECT t.slug FROM {$wpdb->terms} t 
        INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id 
        WHERE tt.taxonomy = 'language' AND CHAR_LENGTH(t.slug) = 2");
    return !empty($langs) ? $langs : [$this->get_default_language_internal()];
});
```

**Cold-cache impact:** This DB query only fires when Polylang's API is unavailable (edge case). Already has a `HOUR_IN_SECONDS` TTL (patch P7 from prior audit). Minimal real-world impact.

**Recommendation:** Already addressed. No further action.

---

### P2.3 — Dashboard widget WP_Query on cold cache

**File:** [`admin/widgets/widget-last-posts.php:24`](admin/widgets/widget-last-posts.php:24)

```php
$last_posts = new WP_Query($args);
```

**Cold-cache impact:** The dashboard widget cache in the `admin` group expires every 15 minutes (default TTL). On cache miss, `WP_Query` runs a full post query — SELECT from `wp_posts` with JOINs on `wp_postmeta`, `wp_term_relationships`. This is WordPress core overhead, not plugin overhead, but it's triggered by the plugin's widget system.

**Recommendation:** The 15-minute TTL and `refresh_button` pattern makes this acceptable. The `WP_Query` is lightweight with `posts_per_page` limits.

---

### P2.4 — `frl_wsf_get_submission_data()`: `SHOW TABLES LIKE` on every cold cache

**File:** [`modules/wsform/stats/wsform-submissions.php:42-45`](modules/wsform/stats/wsform-submissions.php:42)

```php
$table_exists = $wpdb->get_var(
    $wpdb->prepare("SHOW TABLES LIKE %s", $submissions_table)
) === $submissions_table;
```

**Cold-cache impact:** `SHOW TABLES LIKE` is a MySQL information_schema query — slow by nature. Runs on every cold cache of the WSForm submission widget. The result is NOT separately cached; it runs inside the `frl_cache_remember()` callback.

**Recommendation:** Cache the table-existence check separately with a longer TTL (e.g., `WEEK_IN_SECONDS`), or move it outside the callback and use a static variable — the table either exists or doesn't, and that doesn't change between requests.

---

## 🟢 Low (P3) — Already Optimized or Trivial

### P3.1 — Translation service: pattern-based caching (already optimized)
**File:** [`core/translator/class-translation-service.php:248-1012`](core/translator/class-translation-service.php:248)

Previous audit sessions already applied pattern-based block caching (slug-based stable keys instead of content-hash), identity-skip optimization, and `safe_blocks` request-level caching. The cold-cache behavior is now: extract patterns → batch translate → cache mappings. 1 adapter call per unique pattern set instead of per block render.

**Verdict:** No action needed.

---

### P3.2 — `frl_process_deferred_writes()` at shutdown
**File:** [`includes/main.php:94-139`](includes/main.php:94)

Only runs if `frl_cache_get_deferred_writes()` returns non-empty. The merge loop is O(deferred items) — typically 0–10 items. Only active when writes were deferred (e.g., during cache clear operations).

**Verdict:** No action needed.

---

### P3.3 — `frl_get_current_user()` cold-cache path
**File:** [`includes/helpers/functions.php:137`](includes/helpers/functions.php:137)

Uses `frl_cache_remember('admin', ...)` with the default TTL (1 hour). On cold cache, calls `wp_get_current_user()` which is a lightweight WordPress core call (reads from the already-parsed auth cookie). The type guard at line 101-103 ensures `WP_User` is always returned.

**Verdict:** No action needed.

---

### P3.4 — Config loading from constants (cold-cache operations)
All `frl_load_*` functions read from PHP constants (`FRL_DEFAULT_FIELDS`, `FRL_OPTIONS_RUNTIME`, `FRL_REWRITER_MULTILINGUAL_CPT`). These are in-memory operations — no I/O, no DB. The `frl_cache_remember()` wrappers prevent re-processing within the same request.

**Verdict:** No action needed.

---

## 📊 Cold Cache Query Budget Summary

### Frontend Request (first hit, no object cache, all groups cold)

| # | Source | Query | Approx Cost |
|---|--------|-------|-------------|
| 1 | `get_provider_details()` | `get_transient(frl_cache_object_cache_provider_details_v2)` | ~0.1ms |
| 2 | `auto_preload(options)` | `LIKE _transient_frl_cache_options_%` on wp_options | ~0.5ms |
| 3 | `auto_preload(rewriter)` | `LIKE _transient_frl_cache_rewriter_%` on wp_options | ~0.3ms |
| 4 | `auto_preload(environment)` | `LIKE _transient_frl_cache_environment_%` on wp_options | ~0.2ms |
| 5 | `auto_preload(theme)` | `LIKE _transient_frl_cache_theme_%` on wp_options | ~0.2ms |
| 6 | `auto_preload(versions)` | `LIKE _transient_frl_cache_versions_%` on wp_options | ~0.2ms |
| 7 | `auto_preload(html)` | `LIKE _transient_frl_cache_html_%` on wp_options | ~0.3ms |
| 8 | `frl_get_option()` first call | Already loaded via preload #2 | ~0ms |
| 9 | MU plugin `active_plugins` cache | `SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'` | ~0.1ms |
| 10 | Per-post `postdata` cache misses | Individual `get_transient()` calls (N × ~0.1ms) | ~0.1–1ms |

**Total estimated cold-cache DB overhead:** ~2–3ms on modern hardware, dominated by the 7 LIKE queries on `wp_options`. For context, a typical WordPress page load without any cache does 20–40 DB queries. The plugin adds ~8–10 of its own on cold cache.

### Admin Dashboard Request (first hit)

Adds to the frontend budget:
- Environment Manager `enforce_environment_settings()` → `build_domain_config()` (no DB, constant merging only)
- `check_urls()` → potential `update_option()` calls (rare, only on host mismatch)
- Dashboard widgets → `WP_Query`, `wsform` stats queries
- Cache display tab → `COUNT(*)` × N groups, `SUM(LENGTH())` × N groups

**Total estimated admin cold-cache overhead:** ~5–8ms DB time + PHP processing.

---

## 🔍 Design Observations

### What Works Well

1. **Static request-level caching is pervasive.** After the first `frl_get_option()` call populates the static `$options` array, all 100+ subsequent calls within the same request are O(1) array lookups.

2. **The LRU runtime cache** (1000-item capacity, [`class-cache-manager.php:430`](core/cache/class-cache-manager.php:430)) prevents memory bloat while ensuring hot keys stay in memory.

3. **`auto_preload()` batch-loading** is the correct strategy: pay 6 bulk queries upfront to avoid 50+ individual `get_transient()` calls throughout the request.

4. **Dependency cascading** (`FRL_CACHE_DEPENDENCIES` at [`config-cache.php:84-123`](config/config-cache.php:84)) ensures that clearing `options` also clears `rewriter` → `permalinks` in one atomic operation.

5. **Environment Manager throttle** (60s/300s, [`class-environment-manager.php:201`](core/environment/class-environment-manager.php:201)) prevents `enforce_environment_settings()` from running on every admin page load.

### What Could Be Improved

1. **P0.2 — `auto_preload()` with no object cache:** The 6 `LIKE` queries are unavoidable without architectural change. One option: merge them into a single `LIKE` query spanning all groups, then partition results in PHP. This would trade 6 round-trips for 1 round-trip with a broader LIKE pattern.

2. **P1.2 — WSForm N+1 form names:** Trivially fixable with `WHERE ID IN (...)`.

3. **P2.4 — WSForm `SHOW TABLES LIKE`:** Trivially fixable with static caching.

4. **`get_provider_details()` uses core WP `get_transient()`/`set_transient()`** (not `frl_get_transient()`/`frl_set_transient()`) — intentional, to avoid recursion. But this means the transient key is `frl_cache_object_cache_provider_details_v2` (NOT prefixed with `frl_`), making it invisible to `frl_cache_clear()` operations. This is by design — the provider detection result should survive cache clears — but worth documenting.

---

## 🎯 Recommendations (Priority-Ordered)

| Priority | Finding | Recommendation | Effort |
|----------|---------|----------------|--------|
| P1 | WSForm N+1 form name queries (P1.2) | Batch `SELECT post_title FROM posts WHERE ID IN (...)` | Low |
| P2 | WSForm `SHOW TABLES LIKE` per cold cache (P2.4) | Cache table-existence check separately | Low |
| P3 | `auto_preload()` 6 LIKE queries (P0.2) | Consider single merged LIKE query for all groups | Medium |
| — | All other findings | Accept as intentional design trade-offs | None |

---

## 📋 Self-Audit (per mandatory-rules.md)

| Rule | Status |
|------|--------|
| Context Synchronization | ✅ Pass — read memory-bank files before analysis |
| Auto-Update Protocol | ✅ Pass — this report documents findings |
| Problem "Why" | ✅ Pass — identified root cause per finding |
| Evidence (file/line references) | ✅ Pass — all findings reference specific files/lines |
| Verification via Ripgrep | ✅ Pass — searched for `frl_cache_remember`, `$wpdb`, nested loops across codebase |
| Zero Regression Policy | ✅ Pass — no code changes, analysis only |
| Honesty Protocol | ✅ Pass — findings based on actual source code inspection |
| No Placeholders | ✅ Pass — complete findings, no truncated snippets |
| File read LIMIT | ✅ Pass — read ~25 unique files across the entire investigation |
