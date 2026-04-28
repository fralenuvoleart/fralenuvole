# Cache System Review: Fralenuvole Plugin

**Scope:** `includes/core/cache/` + `config/config-cache*.php` + helper/functions
**Review Date:** 2026-04-28

---

## 1. WHY Was It Done? (Problem Statement)

The cache system solves a **multi-backend unification problem** on a multilingual WordPress site:

- **Primary Need:** Provide a single, consistent caching API regardless of which object cache backend is deployed (Litespeed, Docket Cache, Redis, Memcached, or plain WordPress Transients).
- **Language Awareness:** Cache keys must be scoped per-language (Polylang/WPML) for multilingual CPT permalinks, translations, and navigation — standard WordPress caching has no concept of language-scoped keys.
- **Dependency Management:** When cache group A changes, dependent groups B, C must also be cleared automatically (e.g., clearing `options` cascades to `theme`, `html`, `environment`, `admin`, `adminui`, `rewriter`).
- **Graceful Degradation:** If no external object cache is available, fall back to transients transparently.
- **Cache Clearing Tiers:** Different levels of cache clearing (light/all/hard) for different operational needs.
- **Orchestrated Operations:** Multi-step cache operations (clear + rewrite flush + third-party notification) that execute sequentially with lifecycle hooks, visible in a single source of truth.

---

## 2. Architecture

### Layered Design

```
Request → Helper Functions (frl_cache_get/set/remember/clear)
              │
              ▼
         Frl_Cache_Manager  (core static class)
              │
      ┌───────┼───────────────┐
      ▼       ▼               ▼
  Runtime   Object Cache   Transients
  (LRU)     (wp_cache)     (options table)
```

### Key Files

| File | Role |
|------|------|
| [`includes/core/cache/class-cache-manager.php`](includes/core/cache/class-cache-manager.php):1733 | Core: runtime LRU, persistent get/set/delete, provider detection, batch loads, dependency cascade, purge operations |
| [`includes/core/cache/class-cache-operations.php`](includes/core/cache/class-cache-operations.php):174 | Orchestrator: multi-step composite operations with lifecycle hooks |
| [`includes/core/cache/cache-cleanup.php`](includes/core/cache/cache-cleanup.php):287 | WordPress event hooks that trigger cache invalidation |
| [`config/config-cache.php`](config/config-cache.php):159 | Group definitions, TTLs, dependencies, preload config |
| [`config/config-cache-operations.php`](config/config-cache-operations.php):199 | Multi-step operation definitions (clear_hard, action_hard, etc.) |
| [`includes/helpers/functions-class-helpers.php`](includes/helpers/functions-class-helpers.php):403 | Gate-keeper functions (`frl_cache_is_loaded()`, `frl_cache_get/set/remember/clear`) |

### Class Diagram

```mermaid
graph TD
    A[frl_cache_get/set/remember/clear] --> B[frl_cache_is_loaded]
    B --> C[Frl_Cache_Manager]
    C --> D[Runtime LRU Cache]
    C --> E[Object Cache wp_cache]
    C --> F[Transients fallback]
    C --> G[DB direct queries]
    H[Frl_Cache_Operations] --> A
    H --> I[FRL_CACHE_OPERATIONS config]
    I --> J[clear_hard/all/light]
    I --> K[action_hard/flush/...]
```

### Init Sequence

In [`includes/bootstrap.php`](includes/bootstrap.php):42:
1. `Frl_Cache_Manager` is loaded and `::init()` is called (auto-preloads groups)
2. `Frl_Cache_Operations` is loaded after the manager
3. The error handler is initialized after cache

---

## 3. Features (Detailed)

### 3.1 Runtime LRU Cache
- Request-level static `$runtime_cache[]` array with max 1000 items
- LRU eviction via `$lru['access_order']` associative array (O(1) updates)
- Group-indexed keys (`$group_keys`) for efficient group-level clearing

### 3.2 Multi-Backend Support
- Auto-detects Litespeed, Docket Cache, Redis, Memcached via:
  - Class name heuristics (`str_contains($class_name, 'litespeed')`)
  - File content sniffing of `object-cache.php` (reads first 2KB)
  - Method detection for Docket (`dc_save()`)
- Status tracking: active, inactive drop-in, broken, force-disabled

### 3.3 Language-Aware Keys
- Groups listed in `FRL_CACHE_LANGUAGE_GROUPS` get `$group . '_' . $lang . '_' . $key` format
- Language detected via `frl_get_language()`

### 3.4 Cache Dependency Cascading
- `FRL_CACHE_DEPENDENCIES` defines graph (e.g., `options → theme, html, environment, admin, adminui, rewriter`)
- `clear_group_with_dependencies()` recursively clears dependent groups
- Dedup via `$groups_cleared[]` prevents re-clearing within a request

### 3.5 Batch/Multi-Get Operations
- `get_multi()` supports array keys with chunking (100 per batch)
- On transient fallback, queries all transients for a group in one DB query
- Injects results into WordPress option cache via `wp_cache_add_multiple()`

### 3.6 Lock-Based Race Condition Prevention
- `remember()` uses `wp_cache_add()` with `LOCK_TTL` (2 seconds)
- Exponential backoff: 50ms → 100ms → 200ms
- Falls through to unconditional generation after max retries

### 3.7 Three Clearing Tiers
| Tier | What It Clears | Method |
|------|---------------|--------|
| Light | All groups except heavy (staticdata, blocks, translations, permalinks, postdata) | `purge_light()` |
| All | Everything including heavy groups, with dependency cascade | `purge_all()` |
| Hard | purge_all + wp_cache_flush() + all website transients + OPcache + browser headers | `hard_cache_reset()` |

### 3.8 Orchestrated Multi-Step Operations
- `Frl_Cache_Operations::run('action_hard')` executes:
  1. `frl_cache_clear('hard')` → delegates to `clear_hard`
  2. `frl_schedule_admin_rewrite_flush()` (deferred via 60s transient)
- Lifecycle hooks: `frl_before/after_cache_operation_*`
- All steps run regardless of failure (no abort)
- Re-entrancy guarded via `frl_is_already_running()`

### 3.9 Serialization Safety
- `frl_sanitize_for_serialization()` converts Closures → descriptive arrays, Objects → class names
- Depth-limited (max 5 levels) recursion guard
- Try-catch around `serialize()` to detect non-serializable values

### 3.10 Cache Cleanup Hooks
[`cache-cleanup.php`](includes/core/cache/cache-cleanup.php) registers hooks for:
- `save_post` → clears postdata, permalinks, meta, icons, schema, langswitcher, featured images
- `updated_option` → clears translated option caches for all languages
- `edited_term` → clears permalink and tracked meta caches
- `profile_update` → clears user meta cache
- `pll_save_strings_translations` → bumps translation version + clears translations group
- `wp_update_nav_menu` / `save_post_wp_navigation` → clears navigation cache
- `activated_plugin` / `deactivated_plugin` → clears MU plugin exclusion cache

---

## 4. Modularity Assessment

### Strengths
- **Gate-keeper pattern**: All public helper functions check `frl_cache_is_loaded()` before delegating — safe even if cache manager fails to load.
- **Configuration-driven**: Groups, TTLs, dependencies are constants in config files — easy to audit.
- **Separation of orchestration**: `Frl_Cache_Operations` cleanly separates the "what to do" (step definitions) from "how to do it" (cache manager methods).
- **Cleanup isolation**: Cache cleanup hooks are in their own file, not scattered across the codebase.
- **Inline documentation**: Step `note` fields in [`config-cache-operations.php`](config/config-cache-operations.php) document deferred chains so developers don't need to search the codebase.

### Weaknesses
- **All-static class**: `Frl_Cache_Manager` is entirely static — impossible to mock, test, or swap implementations. This is a common WordPress pattern but limits testability and extension.
- **Mixed responsibilities**: The class does:
  - Runtime cache management (LRU)
  - Persistent cache management (object cache/transients)
  - Provider detection
  - DB operations (transient deletion queries)
  - Browser cache header management
  - Auth cookie save/restore (in `purge_all()`)
- **Constant-based configuration**: `FRL_CACHE_TTL`, `FRL_CACHE_PERSISTENT_GROUPS`, etc. are PHP `const` — cannot be filtered at runtime. Using `apply_filters()` would allow customization without redefining constants.
- **Tight coupling to globals**: Direct use of `$wpdb`, `get_option()`, `wp_cache_*()` functions throughout the class.
- **Unrecognized groups fail silently**: Calling `frl_cache_clear('metadata', ...)` with a group not in any definition array uses defaults silently — no warning logged.

---

## 5. Performance Analysis

### 5.1 Hot Path Costs

| Operation | Cost | Notes |
|-----------|------|-------|
| `should_bypass()` | 2x `get_option()` DB calls (first call) | Static cached after first call, but initial call reads `frl_disable_plugin` + `frl_disable_cache` from `wp_options` |
| `is_object_cache_truly_functional()` | File read + class name checks | Reads up to 2KB of `object-cache.php`. Static cached + transient-cached (WEEK_IN_SECONDS). Acceptable. |
| `get_provider_details()` | File read + plugin status checks | Cached in transient with WEEK_IN_SECONDS TTL. Heavy on first call. |
| `auto_preload()` | Multi-group preload on every page | Preloads 6 groups on frontend, 5 on backend. Each group triggers `get_multi()` which queries DB for transients if no object cache. **Significant overhead on transient-only sites.** |
| `serialize()` in `set()` | Try-catch + serialization | Serializes value twice on success (test + store). Could be optimized by testing serializability differently. |

### 5.2 Preloading Overhead
[`auto_preload()`](includes/core/cache/class-cache-manager.php:78) runs on every non-AJAX request:
- Frontend: preloads `options`, `rewriter`, `environment`, `theme`, `versions`, `html`
- Backend: preloads `options`, `environment`, `theme`, `versions`, `admin`

For transient-only sites (no object cache), each group preload triggers a `SELECT` query against `wp_options` fetching ALL transients for that group. On a site with many cached items, this could be **hundreds of rows per page load**.

### 5.3 `purge_all()` Double Work
When using transient fallback:
1. First deletes all plugin transients in a batch (`delete_transients_from_db(self::PREFIX)`) at line 867
2. Then iterates ALL groups and calls `clear_group_with_dependencies()` which tries to clear transients again per group at line 878

The per-group transient deletion will find nothing (already batch-deleted), but the loop still iterates each group and calls `purge_group_storage()` which checks `use_transient_fallback()` → calls `clear_transients($group)` → executes another `SELECT` + `DELETE` per group.

### 5.4 LRU Size Limit
`$max_runtime_items = 1000` — fixed constant. For sites with many posts/categories, this could cause churn. Should be configurable or adaptive.

### 5.5 Memory in `get_multi(null)`
When `get_multi($group, null)` is called for a persistent group with transient fallback, it loads ALL transient values and ALL timeout values for that group into memory. For large groups (e.g., `postdata` with thousands of posts), this could be memory-intensive.

---

## 6. Issues, Bugs & Logical Flaws

### 🔴 BUG: `'all_options, false'` Parameter Error (Critical)

In [`functions-options.php`](includes/helpers/functions-options.php):124 and :726:

```php
frl_cache_clear('options', 'all_options, false');
```

The signature is `frl_cache_clear(string $group, ?string $key = null, bool $include_dependencies = true)`. The string `'all_options, false'` is being passed as the **key parameter**, not as two separate arguments. This means:
- The key is set to the literal string `all_options, false`
- The third parameter defaults to `true` — **dependencies are NOT skipped**

The author intended `frl_cache_clear('options', 'all_options', false)` to skip dependency cascading, but the second argument leaked into the string.

**Impact:** Clearing `options.all_options` triggers dependency cascade (clears `theme`, `html`, `environment`, `admin`, `adminui`, `rewriter`) every time ANY option is updated. This causes excessive cache thrashing — especially problematic in [`frl_update_option()`](includes/helpers/functions-options.php:105) which is called frequently.

### 🟡 ISSUE: `metadata` Group Not Registered in Persistent Groups

In [`schema.php`](public/schema.php):55, the `frl_cache_remember()` call uses group `'metadata'`:

```php
$schema = frl_cache_remember('metadata', $cache_key, function () { ... });
```

The group `'metadata'` is **not defined** in `FRL_CACHE_PERSISTENT_GROUPS`, which means:

| Cache Backend | Persistence Behavior |
|--------------|---------------------|
| Object cache active (Redis/Litespeed/Memcached/Docket) | ✅ Stored via `wp_cache_set()` with group key `frl_metadata` — persists correctly |
| No object cache (transient-only) | ❌ **Not stored.** `use_transient_fallback('metadata')` returns `false` because `metadata` is not in `$persistent_groups_map`. The `set()` method at line 535 skips transient storage entirely. |

**Impact on transient-only sites:** Schema JSON-LD markup is regenerated on every page load — the per-request runtime cache still deduplicates within the same page, but no cross-request caching occurs.

**Recommended fix:** Add `'metadata'` to `FRL_CACHE_PERSISTENT_GROUPS` in [`config-cache.php`](config/config-cache.php) and optionally to `FRL_CACHE_TTL` with a specific TTL (e.g., `HOUR_IN_SECONDS` or `DAY_IN_SECONDS`). This ensures schema data is persisted via transients when no object cache is available.

### 🟡 ISSUE: Auth Cookie Save/Restore in `purge_all()`

In [`class-cache-manager.php`](includes/core/cache/class-cache-manager.php):842-904:

```php
$current_user_id = frl_get_current_user()->ID;
$auth_cookie = wp_parse_auth_cookie('', 'logged_in');
// ... cache clearing operations ...
if ($current_user_id && $auth_cookie) {
    wp_set_auth_cookie($current_user_id, true);
    wp_set_current_user($current_user_id);
}
```

This is a side effect in a cache-purge method. The rationale is undocumented, but likely a workaround for a specific edge case where cache clearing corrupts the auth session. This should be documented with WHY it exists, or extracted into a separate concern.

### 🟡 ISSUE: `pre_option_*` Filter Removal in `reset_options_caches()`

In [`class-cache-manager.php`](includes/core/cache/class-cache-manager.php):1330-1342:

```php
foreach (array_keys($wp_filter) as $filter_name) {
    if (str_starts_with($filter_name, 'pre_option_' . $prefix)) {
        remove_all_filters($filter_name);
    }
}
```

This removes ALL `pre_option_frl_*` filters, including ones that might have been added by other plugins or themes. While the plugin likely owns all `frl_*` prefixed options, this is an aggressive cleanup that could break interoperability.

### 🟡 ISSUE: `clear_group_with_dependencies()` Resets `$loaded_groups` on Key-Level Clears

In [`class-cache-manager.php`](includes/core/cache/class-cache-manager.php):1219-1223:

```php
if (isset(self::$loaded_groups[$group])) {
    unset(self::$loaded_groups[$group]);
    $stats['runtime']++;
}
```

When clearing a **single key** (not entire group), the `$loaded_groups` flag is still reset. This forces the next `get_multi(null)` call to re-query the database for all keys in the group, even though only one key changed. This could cause unnecessary DB queries.

### 🟡 ISSUE: `serialize()` Called Twice in Hot Path

In [`class-cache-manager.php`](includes/core/cache/class-cache-manager.php):502-522:

```php
try { serialize($value); } catch (\Throwable $e) { $value = $sanitized_value; }
// ... later ...
wp_cache_set($cache_key, $value, ..., $ttl);  // serializes again internally
```

The try-catch serializes the value just to test serializability. WordPress's `wp_cache_set()` will serialize again internally. For large data structures, this doubles serialization cost.

---

## 7. Areas of Improvement

### P1 - Fix Bugs (Critical)

| Bug | File:Line | Impact | Fix |
|-----|-----------|--------|-----|
| `'all_options, false'` string leak | [`functions-options.php`](includes/helpers/functions-options.php):124 | Excessive dependency cascade on every option update | Change to `frl_cache_clear('options', 'all_options', false)` |
| Same bug at line 726 | [`functions-options.php`](includes/helpers/functions-options.php):726 | Same | Same fix |
| `metadata` group not registered | [`cache-cleanup.php`](includes/core/cache/cache-cleanup.php):76 | Schema data not persisted across requests | Either add `metadata` to `FRL_CACHE_TTL` or change to use `'postdata'` group |

### P2 - Reduce `purge_all()` Double Work

When transient fallback is active, `purge_all()` does:
1. Batch delete ALL plugin transients (line 867)
2. Per-group loop trying to delete transients again (line 878)

**Fix:** Skip the per-group transient deletion when the batch delete has already run. Add a flag `$transients_already_batch_deleted` that `purge_group_storage()` checks.

### P3 - Add Unrecognized Group Warning

Add a warning log when `clear_group_with_dependencies()` receives a group not in any defined array:

```php
if (!isset(self::$TTL[$group]) && !isset(self::$persistent_groups_map[$group])) {
    frl_log("Cache: unrecognized group '{group}' — using defaults", ['group' => $group]);
}
```

### P4 - Make Configuration Filterable

Consider adding `apply_filters()` wrappers around key cache configurations:

- `frl_cache_ttl` — for runtime TTL overrides
- `frl_cache_persistent_groups` — for adding custom groups
- `frl_cache_dependencies` — for extending dependency graph

This would allow site-specific customizations without constant redefinition.

### P5 - Extract Provider Detection

The 158-line `get_provider_details()` method in [`class-cache-manager.php`](includes/core/cache/class-cache-manager.php):208-366 mixes provider detection with cache management. Extract into a dedicated `Cache_Provider_Detector` class:

```php
class Frl_Cache_Provider_Detector {
    public function detect(): array { ... }
    public function is_functional(): bool { ... }
}
```

### P6 - Auth Cookie Side-Effect Refactor

Extract auth state save/restore from `purge_all()` into its own method or a decorator:

```php
private static function with_auth_preservation(callable $fn) {
    $user_id = frl_get_current_user()->ID;
    $cookie = wp_parse_auth_cookie('', 'logged_in');
    $result = $fn();
    if ($user_id && $cookie) { /* restore */ }
    return $result;
}
```

### P7 - Optimize Serialization Test

Replace the try-catch `serialize()` test with a faster check. Since `frl_sanitize_for_serialization()` already handles the problematic cases (Closures, objects), the try-catch could be limited to just those:

```php
$has_unsafe_types = false;
// Check for Closures or objects in the value
_frl_check_serialization_safety($value, $has_unsafe_types);
if ($has_unsafe_types) {
    frl_sanitize_for_serialization($value);
}
```

### P8 - Optional Preloading

Make `auto_preload()` skippable via a filter or option. The preloading of all frontend/backend groups on every page load is expensive for transient-only sites:

```php
// In auto_preload():
if (!apply_filters('frl_cache_auto_preload', true)) {
    return false;
}
```

### P9 - Configurable LRU Size

Make `$max_runtime_items` configurable:

```php
private static $max_runtime_items = FRL_CACHE_RUNTIME_MAX_ITEMS;
// With a default in config:
const FRL_CACHE_RUNTIME_MAX_ITEMS = 1000;
```

---

## 8. Verification Checklist

Before declaring this review complete:

- [x] Read all cache source files
- [x] Read cache configuration files
- [x] Traced helper function chain from public API to core
- [x] Checked for unrecognized group usage across codebase
- [x] Validated parameter correctness in `frl_cache_clear()` calls
- [x] Analyzed performance hot paths
- [x] Checked for recursion risks (re-entrancy guards)
- [x] Verified dependency graph logic
- [x] Cross-referenced with memory bank architectural rules

---

## Summary

The cache system is **well-architected for its purpose**: a unified multi-backend caching layer with language awareness, dependency cascading, and orchestrated operations. The code quality is high with good inline documentation.

**Critical findings:**
1. **Two instances of `'all_options, false'` string-as-parameter bug** that prevents dependency skipping during option updates — this causes unnecessary cache thrashing on every option change.
2. **`metadata` group is used but never defined** — schema cache data only lives per-request.

**Moderate concerns:**
- All-static design limits testability
- `purge_all()` does redundant work on transient-only sites
- `auto_preload()` overhead on every page load
- Auth cookie management is a side effect in `purge_all()`
- `reset_options_caches()` aggressively removes all `pre_option_frl_*` filters

**Positive highlights:**
- Clean separation of orchestration from execution
- Excellent inline documentation with step notes
- Comprehensive cleanup hooks for WordPress events
- Smart provider detection with caching
- Race condition protection in `remember()`
- Graceful degradation across caching backends
