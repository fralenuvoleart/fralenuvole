# Cache System — Architectural Reference

> Multi-backend caching layer with language awareness, dependency cascading, and orchestrated operations.

---

## Table of Contents

1. [Problem Statement & Goals](#1-problem-statement--goals)
2. [Architecture Overview](#2-architecture-overview)
3. [File Map](#3-file-map)
4. [Cache Groups & Configuration](#4-cache-groups--configuration)
5. [Frl_Cache_Manager — Core](#5-frl_cache_manager--core)
   - 5.1 [Runtime LRU Cache](#51-runtime-lru-cache)
   - 5.2 [Persistent Cache (Object Cache / Transients)](#52-persistent-cache-object-cache--transients)
   - 5.3 [Provider Detection](#53-provider-detection)
   - 5.4 [Language-Aware Keys](#54-language-aware-keys)
   - 5.5 [Dependency Cascading](#55-dependency-cascading)
   - 5.6 [Three Clearing Tiers](#56-three-clearing-tiers)
   - 5.7 [Atomic Group Clearing](#57-atomic-group-clearing)
   - 5.8 [Race Condition Prevention](#58-race-condition-prevention)
   - 5.9 [Auth Preservation](#59-auth-preservation)
6. [Frl_Cache_Operations — Orchestrator](#6-frl_cache_operations--orchestrator)
7. [Cache Cleanup Hooks](#7-cache-cleanup-hooks)
8. [Helper Functions API](#8-helper-functions-api)
9. [Clearing Behavior Reference](#9-clearing-behavior-reference)
10. [Performance Considerations](#10-performance-considerations)
11. [Design Notes & Known Considerations](#11-design-notes--known-considerations)
12. [The Options Read/Write Path](#12-the-options-readwrite-path)
13. [Admin Cache-Clear Actions — Execution Context & Guarantees](#13-admin-cache-clear-actions--execution-context--guarantees)

---

## 1. Problem Statement & Goals

The cache system addresses the following requirements on a multilingual WordPress site:

| Requirement | Solution |
|---|---|
| **Unified caching API** across any backend | [`Frl_Cache_Manager`](core/cache/class-cache-manager.php) abstracts over Litespeed, Docket Cache, Redis, Memcached, and WordPress Transients |
| **Language-scoped keys** (Polylang/WPML) | `FRL_CACHE_LANGUAGE_GROUPS` adds a language prefix to cache keys |
| **Automatic dependency clearing** | `FRL_CACHE_DEPENDENCIES` recursively clears groups when their dependents change |
| **Graceful degradation** | Falls back to transients when no external object cache is available |
| **Tiered cache clearing** | Light / All / Hard — different purge depths for different operational needs |
| **Composite operations** | [`Frl_Cache_Operations`](core/cache/class-cache-operations.php) sequences multi-step operations (clear + rewrite flush) with lifecycle hooks |

---

## 2. Architecture Overview

### Layered Design

```
Request
    │
    ▼
Helper Functions  ───  Gate-keeper ─── Frl_Cache_Manager
(frl_cache_get/     (frl_cache_       (static class)
 set/remember/       is_loaded())          │
 clear)                               ┌────┼────────────┐
                                      ▼    ▼            ▼
                                  Runtime  Object     Transients
                                  (LRU)    Cache      (options
                                           (wp_cache)  table)
                                      │
                                      ▼
                             Frl_Cache_Operations
                             (orchestrator)
                                      │
                                      ▼
                            FRL_CACHE_OPERATIONS
                            (config-cache-operations.php)
```

### Init Sequence

In [`includes/bootstrap.php`](includes/bootstrap.php):

1. `Frl_Cache_Manager` is loaded and `::init()` is called, which initializes O(1) lookup maps and triggers `auto_preload()` for the current context (admin/frontend)
2. `Frl_Cache_Operations` is loaded after the manager
3. Cache cleanup hooks ([`cache-cleanup.php`](core/cache/cache-cleanup.php)) are loaded, registering WordPress event listeners for automatic cache invalidation
4. The public helper functions in [`functions-class-helpers.php`](includes/helpers/functions-class-helpers.php) provide the gate-keeper API

---

## 3. File Map

| File | Role |
|---|---|
| [`core/cache/class-cache-manager.php`](core/cache/class-cache-manager.php) | Core cache engine: runtime LRU, persistent get/set/delete, provider detection, batch loads, dependency cascading, purge operations, atomic clearing |
| [`core/cache/class-cache-operations.php`](core/cache/class-cache-operations.php) | Orchestrator: multi-step composite operations with lifecycle hooks |
| [`core/cache/cache-cleanup.php`](core/cache/cache-cleanup.php) | WordPress event hooks that trigger automatic cache invalidation |
| [`config/config-cache.php`](config/config-cache.php) | Group definitions, TTLs, dependencies, preload config, browser groups |
| [`config/config-cache-operations.php`](config/config-cache-operations.php) | Multi-step operation definitions (`clear_hard`, `action_hard`, etc.) |
| [`includes/helpers/functions-class-helpers.php`](includes/helpers/functions-class-helpers.php) | Gate-keeper functions (`frl_cache_is_loaded()`, `frl_cache_get/set/remember/clear`) |

---

## 4. Cache Groups & Configuration

All cache groups, TTLs, and relationships are defined as PHP constants in [`config/config-cache.php`](config/config-cache.php).

### Persistent Groups

Groups stored across requests (in object cache or transients):

```php
const FRL_CACHE_PERSISTENT_GROUPS = [
    'staticdata',   // Heavy/infrequently changing data
    'theme',        // Stable theme data
    'html',         // HTML fragments
    'versions',     // Asset file versions
    'postdata',     // Post ID-specific data
    'blocks',       // Block content (used in cleanup hooks)
    'shortcodes',   // Shortcode output
    'translations', // String translations
    'permalinks',   // URL generation
    'rewriter',     // Rewriter data
    'metafields',   // Meta fields data
    'options',      // Plugin options
    'environment',  // Environment config data
    'adminui',      // Admin interface assembly
    'admin',        // Admin UI data
];
```

### TTLs per Group

```php
const FRL_CACHE_TTL = [
    'staticdata'    => WEEK_IN_SECONDS,  // 1 week
    'theme'         => WEEK_IN_SECONDS,  // 1 week
    'html'          => WEEK_IN_SECONDS,  // 1 week
    'postdata'      => DAY_IN_SECONDS,   // 1 day
    'blocks'        => DAY_IN_SECONDS,   // 1 day
    'shortcodes'    => DAY_IN_SECONDS,   // 1 day
    'translations'  => DAY_IN_SECONDS,   // 1 day
    'permalinks'    => DAY_IN_SECONDS,   // 1 day
    'rewriter'      => DAY_IN_SECONDS,   // 1 day
    'metafields'    => DAY_IN_SECONDS,   // 1 day
    'versions'      => DAY_IN_SECONDS,   // 1 day
    'options'       => HOUR_IN_SECONDS,  // 1 hour
    'environment'   => HOUR_IN_SECONDS,  // 1 hour
    'adminui'       => DAY_IN_SECONDS,   // 1 day
    'admin'         => HOUR_IN_SECONDS,  // 1 hour
    'default'       => HOUR_IN_SECONDS,  // 1 hour fallback
];
```

### Language-Aware Groups

Groups that receive a language prefix in their cache keys:

`postdata`, `metafields`, `permalinks`, `translations`, `shortcodes`, `blocks`

See [§5.4 Language-Aware Keys](#54-language-aware-keys).

### Heavy Groups

Groups excluded from "light" purge:

`staticdata`, `blocks`, `translations`, `permalinks`, `postdata`

### Scripts Groups

Groups cleared when a "scripts" flush is requested:

`versions`, `html`, `shortcodes`

### Browser Cache Groups

Groups that trigger browser `Clear-Site-Data` headers on clear:

`html`, `permalinks`, `options`, `shortcodes`

### Dependency Graph

See [§5.5 Dependency Cascading](#55-dependency-cascading).

### Preload Groups

- **Frontend** (`FRL_CACHE_PRELOAD_FRONTEND_GROUPS`): `options`, `rewriter`, `environment`, `theme`, `versions`, `html`
- **Backend** (`FRL_CACHE_PRELOAD_BACKEND_GROUPS`): `options`, `environment`, `theme`, `versions`, `admin`

### Runtime Limit

`FRL_CACHE_RUNTIME_MAX_ITEMS = 1000` — max items in the per-request LRU cache.

---

## 5. Frl_Cache_Manager — Core

[`Frl_Cache_Manager`](core/cache/class-cache-manager.php) is a fully static class. It provides all cache read/write/clear operations across three storage tiers.

### 5.1 Runtime LRU Cache

A per-request in-memory caching layer.

**Implementation:**
- [`$runtime_cache`](core/cache/class-cache-manager.php:16): `array` — static storage
- [`$lru['access_order']`](core/cache/class-cache-manager.php:23-25): Associative array tracking access order (most recently used at the tail). O(1) updates via `unset()` + `[]=`.
- [`$group_keys`](core/cache/class-cache-manager.php:18): Index of cache keys per group for O(1) group-level clearing
- [`$max_runtime_items`](core/cache/class-cache-manager.php:20): Configurable via `FRL_CACHE_RUNTIME_MAX_ITEMS`

**Eviction:** When the runtime cache exceeds `$max_runtime_items`, the least recently used item (head of `$lru['access_order']`) is evicted.

**Key methods:**
- [`set_runtime()`](core/cache/class-cache-manager.php:413): Stores value, updates group index and LRU order, prunes if over limit
- [`get_runtime()`](core/cache/class-cache-manager.php:475): Returns value, moves key to MRU position
- [`remove_runtime_item()`](core/cache/class-cache-manager.php:448): Removes from storage, LRU tracking, and group index
- [`purge_group_runtime()`](core/cache/class-cache-manager.php:1309): Clears all runtime keys for a group at once

### 5.2 Persistent Cache (Object Cache / Transients)

Two backends:

| Backend | When Used | Storage Mechanism |
|---|---|---|
| **Object Cache** | `is_object_cache_truly_functional()` returns `true` | `wp_cache_set/get/delete()` with group prefix `FRL_CACHE_PREFIX . $group` |
| **Transients** | `use_transient_fallback()` returns `true` | `set_transient/get_transient/delete_transient()` with key `FRL_CACHE_PREFIX . $cache_key` |

The decision is made per-group: groups listed in `FRL_CACHE_PERSISTENT_GROUPS` use transients when object cache is absent; non-persistent groups bypass persistent storage entirely.

**Key methods:**
- [`set()`](core/cache/class-cache-manager.php:496): Writes to runtime + persistent. Sanitizes values via `frl_sanitize_for_serialization()` before passing to `wp_cache_set()`/`set_transient()`.
- [`get()`](core/cache/class-cache-manager.php:547): Runtime → Persistent → Callback generation. Populates runtime cache on persistent hit.
- [`get_multi()`](core/cache/class-cache-manager.php:932): Batch load. Supports `$keys = null` (load all keys for group) or specific array of keys. On transient fallback with `$keys = null`, queries all transients for the group in a single `SELECT`, injects results into WordPress option cache via `wp_cache_add_multiple()`. Chunks specific-key loads in batches of 100.
- [`preload_multi()`](core/cache/class-cache-manager.php:1160): Calls `get_multi()` with `$return_values = false` (populates runtime cache without returning).

### 5.3 Provider Detection

[`get_provider_details()`](core/cache/class-cache-manager.php:211) detects the active object cache provider.

**Detection methods:**

| Provider | Detection Criteria |
|---|---|
| **Litespeed** | Class name contains `litespeed` OR `object-cache.php` content contains `litespeed`. Plugin active check via `LSCWP_V` constant + `is_plugin_active()`. |
| **Docket Cache** | Method `dc_save()` exists on `$wp_object_cache` or `->_object_cache`. Plugin active check + `DOCKET_CACHE_DISABLED` constant. |
| **Redis** | Class name contains `redis`. |
| **Memcached** | Class name contains `memcached`. |
| **Generic drop-in** | Class is `WP_Object_Cache` or other unknown class. Marked as non-functional. |
| **No drop-in** | `wp_using_ext_object_cache()` is `false`. Uses transients. |

**Functional vs Non-functional:**
- A provider is "effectively functional" only if its plugin is active AND the detection methods confirm it's running.
- An inactive drop-in (plugin disabled but `object-cache.php` present) is marked non-functional → falls back to transients.

**Caching:** Provider details are cached in a core `set_transient()` (not through the cache manager's own API, to avoid recursion) with `WEEK_IN_SECONDS` TTL.

### 5.4 Language-Aware Keys

Groups listed in `FRL_CACHE_LANGUAGE_GROUPS` get their cache key prefixed with the current language:

```
Format:  {group}_{lang}_{key}
Example: permalinks_en_post_42
```

The language is obtained via [`frl_get_language()`](includes/helpers/functions-translator-helpers.php) and is scoped to the current request context.

### 5.5 Dependency Cascading

[`clear_group_with_dependencies()`](core/cache/class-cache-manager.php:1189) implements recursive dependency clearing.

**Dependency graph** (from `FRL_CACHE_DEPENDENCIES`):

```
options
  ├── theme
  ├── html
  ├── environment
  │    ├── adminui
  │    └── admin
  ├── admin
  ├── adminui
  └── rewriter
       └── permalinks

translations
  └── metafields

staticdata
  └── adminui

environment
  ├── adminui
  └── admin
```

**Dedup:** [`$groups_cleared`](core/cache/class-cache-manager.php:45) tracks which groups have been fully cleared this request. Full-group clears check this flag and skip if already done. Single-key clears do not set this flag, so dependencies still cascade on key-level clears.

**Dependency exclusion:** Callers can pass `$include_dependencies = false` to suppress cascading (used internally by `purge_light()` and `atomic_clear_group()`).

### 5.6 Three Clearing Tiers

| Tier | Method | What It Clears | Dependencies | Heavy Groups |
|---|---|---|---|---|
| **Light** | [`purge_light()`](core/cache/class-cache-manager.php:1621) | All groups except heavy | No | Skipped |
| **All** | [`purge_all()`](core/cache/class-cache-manager.php:855) | All groups | Yes | Cleared |
| **Hard** | [`hard_cache_reset()`](core/cache/class-cache-manager.php:1732) | `purge_all()` + `wp_cache_flush()` + all website transients + browser headers + `do_action()` | Yes | Cleared |

**`purge_all()` details:**
1. Reset runtime state (cache, key cache, LRU, deferred writes, loaded groups)
2. Batch-delete all plugin transients from DB (if transient fallback)
3. Iterate all groups, calling `clear_group_with_dependencies()` with dependency cascade
4. Respects `$transients_batch_deleted` flag to skip redundant per-group transient deletion
5. Wraps execution in [`with_auth_preservation()`](core/cache/class-cache-manager.php:835) to prevent auth cookie side-effects

**`purge_light()` details:**
1. Iterates all groups, skipping those in `FRL_CACHE_HEAVY_GROUPS`
2. Clears each group WITHOUT dependencies (`$include_dependencies = false`)
3. Resets key cache and deferred writes

**`hard_cache_reset()` details:**
1. Calls `purge_all()`
2. Calls `wp_cache_flush()` (global WP object cache flush)
3. Calls `clear_all_website_transients()` (all `_transient_*` rows from DB, except admin notices)
4. Fires `{FRL_PREFIX}_cache_after_hard_cache_reset` action hook

### 5.7 Atomic Group Clearing

[`atomic_clear_group()`](core/cache/class-cache-manager.php:1478) provides transactional clearing for transient-backed groups.

**Behavior:**
- **Object cache functional:** Delegates to `clear_group_with_dependencies()` (no transaction needed — object cache ops are inherently atomic per-key). Returns normalized stats.
- **Transient fallback:** Wraps the deletion in `execute_with_transaction()` (SQL transaction). If the transaction fails, runtime cache is still cleared but persistent state is preserved via rollback.

**Return shape:** `array{group: string, runtime_cleared: int, persistent_cleared: int, transaction_used: bool, success: bool}`

### 5.8 Race Condition Prevention

[`remember()`](core/cache/class-cache-manager.php:622) uses a locking mechanism:

1. Generates a lock key via `wp_cache_add()` with `FRL_CACHE_LOCK_TTL` (2 seconds)
2. On contention, waits with exponential backoff (50ms → 100ms → 200ms)
3. Re-checks cache between retries in case another process generated the value
4. Falls through to unconditional generation after max retries

### 5.9 Auth Preservation

[`with_auth_preservation()`](core/cache/class-cache-manager.php:835) snapshots and restores the current user's auth cookie around cache operations. This is necessary because:

- Object cache operations can interfere with WordPress's auth cookie validation
- Options table operations (transient deletion) may indirectly affect auth state
- Without this wrapper, users could be unexpectedly logged out during cache maintenance

Currently used by `purge_all()`.

---

## 6. Frl_Cache_Operations — Orchestrator

[`Frl_Cache_Operations`](core/cache/class-cache-operations.php) is a runtime dispatcher for composite cache operations. The operation definitions live in the `FRL_CACHE_OPERATIONS` constant ([`config/config-cache-operations.php`](config/config-cache-operations.php)).

### Two-Tier Design

| Tier | Prefix | Purpose | Called By |
|---|---|---|---|
| **Helper** | `clear_*` | Low-level composite clears | `frl_cache_clear('hard'/'all'/'light')` |
| **Action** | `action_*` | Admin-facing composite operations | Action handlers in [`functions-action-handlers.php`](includes/helpers/functions-action-handlers.php) |

### Operation Registry

| Operation | Steps | Description |
|---|---|---|
| `clear_hard` | 1. `Frl_Cache_Manager::hard_cache_reset()` | Full plugin cache reset |
| `clear_all` | 1. `Frl_Cache_Manager::purge_all()` | Purge all groups |
| `clear_light` | 1. `Frl_Cache_Manager::purge_light()` | Light purge |
| `action_hard` | 1. `frl_cache_clear('hard')` → delegates to `clear_hard` | Admin: hard reset + immediate rewrite flush |
| | 2. `frl_flush_rewrite_rules()` (immediate, mirrors WP permalink save) | |
| `action_flush_rewrite_rules` | 1. `frl_flush_rewrite_rules()` | Admin: flush rewrite rules immediately |
| `action_clear_plugin_transients` | 1. `frl_cache_clear('plugin_transients')` | Admin: clear plugin transients + admin UI cache |
| | 2. `frl_cache_clear('adminui')` | |
| `action_clear_website_transients` | 1. `frl_cache_clear('website_transients')` | Admin: clear all website transients + admin UI cache |
| | 2. `frl_cache_clear('adminui')` | |
| `action_clear_scripts_tags` | 1. `frl_cache_clear('versions')` | Admin: clear CSS/JS caches |
| | 2. `frl_cache_clear('html')` | |
| | 3. `frl_cache_clear('shortcodes')` | |

### Execution Model

- **Sequential:** Steps execute in order, one after another
- **No abort:** All steps always run regardless of individual step failures
- **Lifecycle hooks:** Each operation has `before` and/or `after` hooks (e.g., `frl_before_cache_operation_clear_hard`)
- **Re-entrancy guard:** Uses `frl_is_already_running()` to prevent duplicate execution
- **Return shape:** `array{operation: string, label: string, success: bool, steps: array, error?: string}`

### Lifecycle Hook Convention

```
frl_before_cache_operation_{operation_key}
frl_after_cache_operation_{operation_key}
```

The before hook receives `(string $operation)`. The after hook receives `(string $operation, array $results)`.

---

## 7. Cache Cleanup Hooks

[`cache-cleanup.php`](core/cache/cache-cleanup.php) registers WordPress event listeners that trigger automatic cache invalidation.

### Registered Hooks

| Hook | Priority | Args | Function | What It Clears |
|---|---|---|---|---|
| `init` | 10 | 0 | [`frl_register_hooks_rewrite_flush()`](core/cache/cache-cleanup.php:28) | Registers `created_/edited_/deleted_category/post_tag` hooks → rewrite flush |
| `update_option` | 10 | 1 | [`frl_clear_option_transient()`](core/cache/cache-cleanup.php:164) | Plugin transient matching option name |
| `pll_save_strings_translations` | 10 | 0 | [`frl_clear_translation_cache()`](core/cache/cache-cleanup.php:192) | Bumps `translation_version`, clears `translations` group (→ cascades to `metafields`) |
| `edited_term` | 10 | 1 | [`frl_clear_term_permalink_cache()`](core/cache/cache-cleanup.php:238) | `permalinks` group + tracked meta for term |
| `save_post` | 10 | 1 | [`frl_clear_post_cache()`](core/cache/cache-cleanup.php:43) | Postdata, permalinks, meta, langswitcher, featured images |
| `save_post_wp_navigation` | 10 | 1 | [`frl_clear_navigation_cache()`](core/cache/cache-cleanup.php:208) | `wp_navigation_{$post_id}` key in `permalinks` group |
| `wp_update_nav_menu` | 10 | 1 | [`frl_clear_menu_cache()`](core/cache/cache-cleanup.php:225) | `wp_menu_{$menu_id}` key in `permalinks` group (separate namespace from navigation posts) |
| `profile_update` | 10 | 1 | [`frl_clear_user_cache()`](core/cache/cache-cleanup.php:182) | Tracked meta for user |
| `updated_option` | 10 | 1 | [`frl_clear_option_cache()`](core/cache/cache-cleanup.php:108) | Translated option caches for all languages (plugin-owned options only) |
| `activated_plugin` | 10 | 2 | [`frl_purge_mu_plugin_exclusion_cache()`](core/cache/cache-cleanup.php:296) | MU plugin exclusion cache keys |
| `deactivated_plugin` | 10 | 2 | `frl_purge_mu_plugin_exclusion_cache()` | MU plugin exclusion cache keys |

### Navigation Cache — Key Namespace Separation

Two separate functions handle different navigation types to avoid ID namespace collisions:

- [`frl_clear_navigation_cache($post_id)`](core/cache/cache-cleanup.php:208) — hooked to `save_post_wp_navigation`. Cache key: `wp_navigation_{$post_id}` (post IDs)
- [`frl_clear_menu_cache($menu_id)`](core/cache/cache-cleanup.php:225) — hooked to `wp_update_nav_menu`. Cache key: `wp_menu_{$menu_id}` (term IDs)

This prevents collisions between post IDs and term IDs that may use the same numeric value.

### Post Cache — Comprehensive Clearing

[`frl_clear_post_cache($post_id)`](core/cache/cache-cleanup.php:43) clears:

1. **Postdata:** `post_{$post_id}_translations` key
2. **Permalinks:** `post_{$post_id}` key
3. **Metafields:** All tracked translated meta keys for the post
4. **Shortcodes:** Language switcher key (`langswitcher_{type}_post_{$post_id}`)
5. **Postdata (featured image):** `featured_img_{$post_id}_{$size}_{responsive|single}` (both variants; extension is auto-detected at render time, not keyed) + mobile hero variant (`featured_img_mobile_{$post_id}_{$size}`)

---

## 8. Helper Functions API

Public helper functions in [`functions-class-helpers.php`](includes/helpers/functions-class-helpers.php) provide the gate-keeper API. All functions check `frl_cache_is_loaded()` before delegating to `Frl_Cache_Manager`.

| Function | Signature | Description |
|---|---|---|
| `frl_cache_get()` | `($group, $key, $callback = null, $ttl = null)` | Get value from cache, optionally generate via callback |
| `frl_cache_set()` | `($group, $key, $value, $ttl = null)` | Store value in cache |
| `frl_cache_remember()` | `($group, $key, $callback, $ttl = null)` | Cache-aside with lock-based race condition prevention |
| `frl_cache_clear()` | `($group, $key = null, $include_dependencies = true)` | Clear cache — delegates to `Frl_Cache_Manager::clear_group_with_dependencies()` for normal groups, or `Frl_Cache_Operations::run()` for `'hard'/'all'/'light'` |
| `frl_cache_get_multi()` | `($group, $keys = null, $return_values = true)` | Batch get multiple keys |
| `frl_cache_preload_multi()` | `($group, $keys = null)` | Preload group into runtime cache |

### `frl_cache_clear()` Routing Logic

```
frl_cache_clear($group, $key, $include_dependencies)
    │
    ├── $group === 'hard'      → Frl_Cache_Operations::run('clear_hard')
    ├── $group === 'all'       → Frl_Cache_Operations::run('clear_all')
    ├── $group === 'light'     → Frl_Cache_Operations::run('clear_light')
    ├── $group === 'opcache'   → Frl_Cache_Manager::opcache_reset()
    ├── $group === 'plugin_transients'    → Frl_Cache_Manager::clear_transients()
    ├── $group === 'website_transients'   → Frl_Cache_Manager::clear_all_website_transients()
    └── Any other group        → Frl_Cache_Manager::clear_group_with_dependencies($group, $key, $include_dependencies)
```

---

## 9. Clearing Behavior Reference

### Single-Key Clear

```
frl_cache_clear('postdata', 'post_42')

Calls: clear_group_with_dependencies('postdata', 'post_42', true)
  1. Generate cache key: 'postdata_post_42'
  2. Delete from object cache or transients
  3. Remove from runtime cache (if present)
  4. NO dependency cascade (key-level clears don't cascade)
  5. NO browser cache headers
  6. NO groups_cleared dedup flag
```

### Full-Group Clear

```
frl_cache_clear('options')

Calls: clear_group_with_dependencies('options', null, true)
  1. Check $groups_cleared — skip if already cleared this request
  2. Set $groups_cleared['options'] = true
  3. Purge persistent storage for group (transients or object cache)
  4. Purge runtime cache for group
  5. Reset WordPress option caches (alloptions + frl_get_option static)
  6. Remove pre_option_frl_* filters
  7. Cascade to dependencies: theme, html, environment, admin, adminui, rewriter
  8. Send browser cache headers (if group is in FRL_CACHE_BROWSER_GROUPS)
```

### Hard Cache Reset (Action)

```
frl_handle_action_clear_cache_hard()
  1. Frl_Cache_Operations::run('action_hard')
     1a. frl_cache_clear('hard')
         → Frl_Cache_Operations::run('clear_hard')
            - Frl_Cache_Manager::hard_cache_reset()
              • purge_all() (all groups + dependencies)
              • clear_transients() (this plugin's own transients only,
                matched by prefix — other plugins'/themes' transients are
                left untouched; use the separate "Clear Website Transients"
                action for a full-site purge)
              • do_action(FRL_PREFIX . '_after_hard_cache_reset')
     1b. frl_flush_rewrite_rules()
         → do_action('update_option_permalink_structure')
         → clear_rewriter_caches() (clears options→rewriter→permalinks + flush_rewrite_rules(true);
            the 'options' clear here is a no-op in practice — clear_hard already
            cleared it in step 1a, and the $groups_cleared de-dup guard in
            clear_group_with_dependencies() prevents a second pass)
         → Polylang clean_languages_cache()
         → do_action('permalink_structure_changed')
```

Step order is deliberate: caches are purged *before* rewrite rules are
regenerated, so `flush_rewrite_rules(true)` reads fresh, non-stale option
values when features rebuild their rules.

---

## 10. Performance Considerations

### Hot Path Costs

| Operation | Cost | Caching |
|---|---|---|
| `should_bypass()` | 2 `get_option()` DB calls (first call) | Static cached per request |
| `is_object_cache_truly_functional()` | File read + class name checks | Static + transient (1 week) |
| `get_provider_details()` | File read + plugin status checks | Static + transient (1 week) |
| `auto_preload()` | Multi-group batch load | — |
| `serialize()` safety check | Now a no-op for safe types | `frl_sanitize_for_serialization()` used directly |

### Preloading Overhead

`auto_preload()` runs on every non-AJAX request:
- **Frontend:** Preloads 6 groups (`options`, `rewriter`, `environment`, `theme`, `versions`, `html`)
- **Backend:** Preloads 5 groups (`options`, `environment`, `theme`, `versions`, `admin`)

On transient-only sites, each group preload triggers a `SELECT` query against `wp_options`. This is batched into a single query per group (via `get_multi($group, null)`), which is more efficient than scattered `get_transient()` calls during page render.

### Batch Delete Optimization

`purge_all()` performs a single batch transient deletion for all plugin transients, then skips per-group transient deletion via `$transients_batch_deleted` flag. This prevents N+1 query patterns.

### Memory in get_multi(null)

When `get_multi($group, null)` is called for a transient-backed group, it loads ALL transients for that group into memory. For very large groups (e.g., `postdata` with thousands of posts), this can be memory-intensive. The trade-off is balanced against the alternative of N individual `get_transient()` calls.

### Provider Detection Caching

Provider details are cached in a core transient (not through the cache manager's own API) with `WEEK_IN_SECONDS` TTL. This avoids re-detection on every page load while allowing cache to naturally expire if the environment changes.

---

## 11. Design Notes & Known Considerations

### Deliberate Design Choices (Not Defects)

| Choice | Rationale |
|---|---|
| `Frl_Cache_Manager` is a fully static class | Impossible to mock/swap in isolation, but this is the established convention across the codebase and consistent with how WordPress itself is typically extended. |
| Configuration lives in PHP constants, not filterable via `apply_filters()` | Constants are `grep`-able and diff-able across the whole config surface in one place; runtime filterability was judged not worth the audit-trail cost for this plugin's deployment model. |
| `purge_all()` batches transient deletion once, then skips redundant per-group deletion | Avoids an N+1 query pattern when purging every group in one call. |

### Diagnostic: Non-Functional Group Flush Detection

`purge_group_storage()` sets a short-lived canary key in the target object-cache group immediately before calling `wp_cache_flush_group()`, then checks whether it survived. Some object-cache drop-ins implement `wp_cache_flush_group()` as a no-op stub or don't support group-scoped flushing at all — without this check, a "Clear Cache" admin action could report success while stale data remains cached until natural TTL expiry. The check is purely diagnostic: it logs a warning via `frl_log()` when detected, but never changes the function's return value or any caller-visible behavior.

### Known Limitation

The `metadata` cache group is not listed in `FRL_CACHE_PERSISTENT_GROUPS`. This only affects sites with no functional object cache (transient-fallback only) — on any site with Redis/Litespeed/Memcached/Docket active, the group works correctly via the object-cache path regardless of this list.

---

## 12. The Options Read/Write Path

`frl_get_option()` and `frl_update_option()` ([`functions-options.php`](includes/helpers/functions-options.php)) are not thin wrappers around `get_option()`/`update_option()` — they form a self-healing config-schema layer built on top of `Frl_Cache_Manager`. Every layer below exists to solve one specific, real problem.

### Read path (`frl_get_option()`)

Four tiers, each a fallback for the one before it:

1. **Request-local static array** — `$options`/`$loaded` statics inside the function. Populated once per request; subsequent calls in the same request are a pure array lookup, zero cache/DB cost.
2. **Persistent cache** — first load calls `frl_get_plugin_options('all')` → [`Frl_Cache_Manager::remember('options', 'all_options', …)`](core/cache/class-cache-manager.php:736), which is lock-protected against cache-stampede (see §5.8) and backed by [`frl_get_plugin_options_db()`](includes/helpers/functions-options.php:267) — a single `LIKE 'frl_%'` query normalizing every row against a type map built once per request.
3. **Stale-cache recovery** — if a key is missing from the loaded snapshot, [`frl_handle_missing_option_key()`](includes/helpers/functions-options.php:735) does one direct `get_option()` DB-cache-aware check. If the key exists in the DB but was missing from the cached snapshot (e.g. written by WP-CLI, direct `wpdb`, staging sync), it patches the snapshot and single-key-clears the stale cache entry (no dependency cascade — this is a read-path correction, not a config change).
4. **Missing-option self-seeding** — if the key genuinely doesn't exist in the DB (a new field shipped in a code deploy), [`frl_set_missing_option_default()`](includes/helpers/functions-options.php:768) looks up its registered default/type/autoload, writes it once, and batches the cache invalidation across N missing keys in a single request via a `$all_options_cleared` static guard (seeding 20 new options after a deploy costs one cache clear, not 20). A `$write_attempted` guard ensures each key is only seeded once per request even under repeated calls.

This tier is what makes shipping a new config field in code "just work" on production without a migration script — the first read after deploy seeds and persists the default automatically.

### Write path (`frl_update_option()`)

```php
remove_all_filters('pre_option_' . $prefixed_key);   // strip prior same-request closures
$result = update_option($prefixed_key, $normalized_value, $autoload);
frl_cache_clear('options', 'all_options', false);     // single-key clear only, no cascade
add_filter('pre_option_' . $prefixed_key, fn() => $normalized_value, 9999, 1);
```

- `remove_all_filters()` before writing: without it, multiple writes to the same key within one request would stack anonymous closures, and since anonymous closures can't be targeted individually by `remove_filter()`, WordPress would return the **first** stale closure's value instead of the latest write.
- The `pre_option_{$key}` filter at priority `9999`: guarantees native `get_option('frl_x')` calls made by *other* code (Rewriter features, third-party plugins, theme code) — which do **not** go through `frl_get_option()`'s own read path — see the fresh value immediately, same request, same millisecond, regardless of what priority any other `pre_option_frl_*` filter was registered at.
- Scoped strictly to `pre_option_frl_*` — the plugin's own private option namespace — so this trick never affects any option belonging to another plugin or theme.

### The one real same-request nuance

`frl_get_option()`'s static array is populated from the plugin's own DB read path, not from native `get_option()` — so it is *not* covered by the `pre_option_` filter trick above. Practical consequence: within the single request that first force-applies an environment/config change (`init` priority 10, see [HOOKS.md](docs/HOOKS.md)), if `frl_get_option()` was already called once before [`Frl_Environment_Applier::apply_plugin_options()`](core/environment/class-environment-applier.php:120)'s write loop runs, a later call for a key that loop just rewrote will return the pre-write value for the *rest of that one request only* — the very next request is fully correct, since the persistent cache is invalidated by the orchestrated bulk clear that runs right after the write loop. This is the deliberate cost of batching N writes into one cache-clear instead of N cascading clears (each fanning out to `theme`/`html`/`admin`/`adminui`/`rewriter` per `FRL_CACHE_DEPENDENCIES`), confined to the rare state-transition request, and irrelevant to routine traffic or the warm-cache read path.

---

## 13. Admin Cache-Clear Actions — Execution Context & Guarantees

### Works identically from the adminbar on frontend pages

Adminbar cache links are built with `add_query_arg(FRL_PREFIX . '_action', $action)` ([`logged-user.php`](includes/shared/logged-user.php:186)) — no base URL, so they target the *current* page, admin or frontend. Processing happens in [`frl_process_plugin_actions()`](includes/helpers/functions-action-handlers.php:24), hooked unconditionally at `init:10` — **not** gated to `is_admin()` — and capability-checked via `frl_has_access('manage_options')` independent of context. [`frl_safe_redirect()`](includes/helpers/functions.php:791) returns the user to the referring page via the `Referer` header. The clearing action itself is fully functional on frontend pages.

**Known gap:** the success/failure confirmation is stored via `frl_add_admin_notice()` as a transient, but is only ever rendered by `frl_display_all_admin_notices()`, hooked exclusively to `admin_notices` — a hook that never fires outside `wp-admin`. Clicking a cache-clear link from the adminbar while browsing the live site clears the cache correctly, but shows no visual confirmation on that page load; the stored notice will only surface if the admin later opens `wp-admin` before its timeout expires.

### Why "Clear Caches (All)" and "Flush Rewrite Rules" take several seconds even on Redis

**Flush Rewrite Rules** is not cache-bound, so a fast object-cache backend does not speed it up: `flush_rewrite_rules(true)` is a WordPress-core operation that regenerates the entire rule set across every post type, taxonomy, and multilingual CPT slug variant, then (with `$hard = true`) writes the full serialized ruleset to the DB and attempts a `.htaccess` save. This is CPU/regex-generation-bound and DB-write-bound — the cost scales with the number of active languages, CPTs, and taxonomies, independent of the caching backend.

**Cache Clear (All)** ([`purge_all()`](core/cache/class-cache-manager.php:977)) itself is fast on Redis — 15 groups × a handful of sub-millisecond `wp_cache_*` round trips. The multi-second wall-clock time perceived after clicking the button is the combined click-to-page-loaded cycle: the redirect lands back on the admin settings page with a now-cold `adminui`/`admin`/`theme`/`staticdata` cache — the exact groups just purged — so that page's first render after the click legitimately rebuilds everything `frl_cache_remember()` was previously short-circuiting. This is the deliberate cost of a comprehensive purge, not a redundant operation.

### No redundant clearing across composite actions

`action_hard` runs `frl_cache_clear('hard')` (→ `purge_all()`, clears `options` among 15 groups) followed by `frl_flush_rewrite_rules()` (→ `clear_rewriter_caches()` → `frl_cache_clear('options')` again). This looks redundant on paper but isn't: [`clear_group_with_dependencies()`](core/cache/class-cache-manager.php:1311) tracks `self::$groups_cleared[$group]` and returns immediately with zero stats for any group already cleared earlier in the same request. Since step 1 marks `options` as cleared, step 2's call is a verified no-op, not a double clear.
