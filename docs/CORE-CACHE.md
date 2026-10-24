# Cache Manager

Multi-backend caching layer with language awareness, dependency cascading, and orchestrated operations.

## Architecture Overview

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

In `includes/bootstrap.php`:

1. `Frl_Cache_Manager` is loaded and `::init()` called — initializes O(1) lookup maps, triggers `auto_preload()` for current context
2. `Frl_Cache_Operations` is loaded after the manager
3. Cache cleanup hooks (`cache-cleanup.php`) register WordPress event listeners for automatic invalidation
4. Public helper functions in `functions-class-helpers.php` provide the gate-keeper API

## File Map

| File | Role |
|---|---|
| `core/cache/class-cache-manager.php` | Core engine: persistent get/set/delete, dependency cascading, purge operations, atomic clearing. Uses three traits. |
| `core/cache/trait-cache-lru.php` | Runtime LRU tracking/eviction (`set_runtime()`, `get_runtime()`, `remove_runtime_item()`) |
| `core/cache/trait-cache-batch.php` | Bulk DB operations (`batch_preload_transients()`, `execute_with_transaction()`, `safe_batch_delete_transients()`) |
| `core/cache/trait-cache-diagnostics.php` | Object-cache provider detection and config introspection |
| `core/cache/class-cache-operations.php` | Orchestrator: multi-step composite operations with lifecycle hooks |
| `core/cache/cache-cleanup.php` | WordPress event hooks for automatic cache invalidation |
| `config/config-cache.php` | Group definitions, TTLs, dependencies, preload config, browser groups |
| `config/config-cache-operations.php` | Multi-step operation definitions |
| `includes/helpers/functions-class-helpers.php` | Gate-keeper functions (`frl_cache_is_loaded()`, `frl_cache_get/set/remember/clear`) |

## Cache Groups & Configuration

All defined as PHP constants in `config/config-cache.php`.

### Persistent Groups

Groups stored across requests (object cache or transients):

`staticdata`, `theme`, `html`, `versions`, `postdata`, `blocks`, `shortcodes`, `translations`, `permalinks`, `rewriter`, `metafields`, `options`, `environment`, `adminui`, `admin`

### TTLs

| Group | TTL |
|---|---|
| `staticdata`, `theme`, `html` | `WEEK_IN_SECONDS` |
| `postdata`, `blocks`, `shortcodes`, `translations`, `permalinks`, `rewriter`, `metafields`, `versions`, `adminui` | `DAY_IN_SECONDS` |
| `options` | `DAY_IN_SECONDS` (actively invalidated; TTL is a safety net for out-of-band DB edits) |
| `environment`, `admin`, `default` | `HOUR_IN_SECONDS` |

### Language-Aware Groups

`postdata`, `metafields`, `permalinks`, `translations`, `shortcodes`, `blocks`

Keys are prefixed: `{group}_{lang}_{key}` (e.g., `permalinks_en_post_42`).

### Heavy Groups (excluded from light purge)

`staticdata`, `blocks`, `translations`, `permalinks`, `postdata`

### Scripts Groups (cleared on scripts flush)

`versions`, `html`, `shortcodes`

### Browser Cache Groups (trigger `Clear-Site-Data` headers)

`html`, `permalinks`, `options`, `shortcodes`

### Preload Groups

- **Frontend:** `options`, `rewriter`, `environment`, `theme`, `versions`, `html`
- **Backend:** `options`, `environment`, `theme`, `versions`, `admin`

These lists are a **transient-path** optimization. On a functional object cache, `auto_preload()` early-returns (a group's keys cannot be enumerated from the object cache), so these groups warm lazily on first access; the lists are inert there.

### Dependency Graph

```
options       → theme, html, environment, admin, adminui, rewriter
rewriter      → permalinks
translations  → metafields
environment   → adminui, admin
staticdata    → adminui
```

### Runtime Limit

`FRL_CACHE_RUNTIME_MAX_ITEMS = 1000` — max items in per-request LRU cache.

### Single-Site Scope

Cache keys are intentionally **not** blog-scoped — no `get_current_blog_id()` segment and no `switch_blog` reset. The plugin is single-site by design: one configuration shared across a multisite network, differentiated per environment, not per blog. Do not add blog scoping without revisiting this decision.

## Frl_Cache_Manager — Core

`Frl_Cache_Manager` is a fully static class providing all cache operations across three storage tiers.

### Runtime LRU Cache

Per-request in-memory caching with LRU eviction. When the runtime cache exceeds `$max_runtime_items`, the least recently used item is evicted.

**Key methods** (LRU methods in `Frl_Cache_Lru_Trait`):
- `set_runtime()` — stores value, updates group index and LRU order, prunes if over limit
- `get_runtime()` — returns value, moves key to MRU position
- `remove_runtime_item()` — removes from storage, LRU tracking, and group index
- `purge_group_runtime()` — clears all runtime keys for a group at once

### Persistent Cache

Two backends, decided per-group:

| Backend | When Used | Mechanism |
|---|---|---|
| Object Cache | `is_object_cache_truly_functional()` returns `true` | `wp_cache_set/get/delete()` with group prefix `FRL_CACHE_PREFIX . $group` |
| Transients | `use_transient_fallback()` returns `true` | `set_transient/get_transient/delete_transient()` with key `FRL_CACHE_PREFIX . $cache_key` |

Groups in `FRL_CACHE_PERSISTENT_GROUPS` use transients when object cache is absent; non-persistent groups bypass persistent storage entirely.

**Key methods:**
- `set()` — writes to runtime + persistent
- `get()` — runtime → persistent → callback generation. Populates runtime on persistent hit.
- `get_multi()` — batch load. `$keys = null` loads all keys for a group **only on the transient path** (a `LIKE` query); on a functional object cache keys cannot be enumerated, so it returns the runtime-hydrated subset. Chunks specific-key loads in batches of 100.
- `preload_multi()` — calls `get_multi()` with `$return_values = false`. On a functional object cache this is a no-op (no enumeration); groups listed in `FRL_CACHE_PRELOAD_*_GROUPS` warm lazily on first access there. The eager preload only does real work on the transient path, where `auto_preload()` collapses the groups into one batched `LIKE` query.

**Falsey values:** a cached `false`/`null` is distinguishable from a miss. Object-cache reads use `wp_cache_get(..., $found)` (with a legacy `!== false` fallback for drop-ins that don't populate `$found`); transient reads/writes wrap falsey values in a `__frl_falsey__` sentinel so `get_transient()` returning `false` still means "miss".

### Provider Detection

`get_provider_details()` detects the active object cache provider:

| Provider | Detection |
|---|---|
| Litespeed | Class name contains `litespeed` OR `object-cache.php` content contains `litespeed` |
| Docket Cache | Method `dc_save()` exists on `$wp_object_cache` |
| Redis | Class name contains `redis` |
| Memcached | Class name contains `memcached` |
| Generic/None | Falls back to transients |

A provider is "effectively functional" only if its plugin is active AND detection confirms it's running. Provider details are cached in a core transient with `WEEK_IN_SECONDS` TTL.

### Three Clearing Tiers

| Tier | Method | What It Clears | Dependencies | Heavy Groups |
|---|---|---|---|---|
| Light | `purge_light()` | All groups except heavy | No | Skipped |
| All | `purge_all()` | All groups | Yes | Cleared |
| Hard | `hard_cache_reset()` | `purge_all()` + plugin transients + action hook | Yes | Cleared |

**`purge_all()`:** Resets runtime state, batch-deletes plugin transients from DB (if transient fallback), iterates all groups with dependency cascade. Wrapped in `with_auth_preservation()`.

**`purge_light()`:** Iterates all groups skipping `FRL_CACHE_HEAVY_GROUPS`, clears each WITHOUT dependencies.

**`hard_cache_reset()`:** Calls `purge_all()` → `clear_transients()` (plugin-prefixed only, NOT all website transients) → fires `frl_cache__after_hard_cache_reset` action. Does **not** call `wp_cache_flush()` or `clear_all_website_transients()`.

### Race Condition Prevention

`remember()` is a plain cache-aside with **no stampede lock**. An earlier `wp_cache_add()` short-TTL lock was removed: with a single attempt it never serialized concurrent misses (a failed lock fell straight through to generation) while costing two object-cache round-trips per miss. All `remember()` callbacks are idempotent reads, so concurrent double-execution during a rare race window wastes CPU but cannot corrupt data. Do not re-add a lock without a real wait-and-recheck loop.

### Auth Preservation

`with_auth_preservation()` snapshots and restores the current user's auth cookie around cache operations, preventing unexpected logouts during cache maintenance. Used by `purge_all()`.

## Frl_Cache_Operations — Orchestrator

`Frl_Cache_Operations` dispatches composite operations defined in `FRL_CACHE_OPERATIONS`.

### Operation Tiers

| Tier | Prefix | Purpose | Called By |
|---|---|---|---|
| Helper | `clear_*` | Low-level composite clears | `frl_cache_clear('hard'/'all'/'light'/'options'/'rewriter')` |
| Action | `action_*` | Admin-facing operations | Action handlers in `functions-action-handlers.php` |
| Environment | `env_*` | Environment Manager enforcement | `Frl_Environment_Manager::enforce_environment_settings()` |

### Operation Registry

| Operation | Steps |
|---|---|
| `clear_hard` | `Frl_Cache_Manager::hard_cache_reset()` |
| `clear_all` | `Frl_Cache_Manager::purge_all()` |
| `clear_light` | `Frl_Cache_Manager::purge_light()` |
| `clear_options` | `clear_group_with_dependencies('options')` → cascades to theme, html, environment, admin, adminui, rewriter → permalinks |
| `clear_rewriter` | `clear_group_with_dependencies('rewriter')` → cascades to permalinks |
| `action_hard` | `frl_cache_clear('hard')` → `frl_flush_rewrite_rules()` |
| `action_flush_rewrite_rules` | `frl_flush_rewrite_rules()` |
| `action_clear_plugin_transients` | `frl_cache_clear('plugin_transients')` → `frl_cache_clear('adminui')` |
| `action_clear_website_transients` | `frl_cache_clear('website_transients')` → `frl_cache_clear('adminui')` |
| `action_clear_scripts_tags` | `frl_cache_clear('versions')` → `frl_cache_clear('html')` → `frl_cache_clear('shortcodes')` |
| `env_enforce_full` | `frl_cache_clear('all')` → `frl_flush_rewrite_rules()` |
| `env_enforce_url_change` | `frl_cache_clear('all')` |
| `env_enforce_options` | `frl_cache_clear('options')` |

### Execution Model

- **Sequential:** Steps execute in order; all steps always run regardless of individual failures
- **Lifecycle hooks:** `frl_before_cache_operation_{key}` / `frl_after_cache_operation_{key}`
- **Re-entrancy guard:** Uses `frl_is_already_running()` to prevent duplicate execution
- **Return shape:** `array{operation, label, success, steps, error?}`

## Cache Cleanup Hooks

`cache-cleanup.php` registers WordPress event listeners for automatic invalidation:

| Hook | What It Clears |
|---|---|
| `save_post` | Postdata, permalinks, meta, langswitcher, featured images |
| `save_post_wp_navigation` | `wp_navigation_{$post_id}` key in `permalinks` |
| `wp_update_nav_menu` | `wp_menu_{$menu_id}` key in `permalinks` |
| `edited_term` | `permalinks` group + tracked meta for term |
| `pll_save_strings_translations` | Bumps `translation_version`, clears `translations` (→ cascades to `metafields`) |
| `update_option` | Plugin transient matching option name |
| `updated_option` | Translated option caches for all languages (plugin-owned options only) |
| `profile_update` | Tracked meta for user |
| `activated_plugin` / `deactivated_plugin` | MU plugin exclusion cache keys |

## Helper Functions API

All in `functions-class-helpers.php`. Every function checks `frl_cache_is_loaded()` before delegating.

| Function | Description |
|---|---|
| `frl_cache_get($group, $key, $callback, $ttl)` | Get value, optionally generate via callback |
| `frl_cache_set($group, $key, $value, $ttl)` | Store value |
| `frl_cache_remember($group, $key, $callback, $ttl)` | Cache-aside (no lock — see Race Condition Prevention) |
| `frl_cache_clear($group, $key, $include_dependencies)` | Clear cache — routes to orchestrator or direct manager calls |
| `frl_cache_clear_keys($group, $keys)` | Batch-clear specific keys via one `wp_cache_delete_multiple()` (per-key fallback on older cores); also drops runtime entries and resets the options static caches for the `options` group |
| `frl_cache_get_multi($group, $keys, $return_values)` | Batch get multiple keys |
| `frl_cache_preload_multi($group, $keys)` | Preload group into runtime cache |

### `frl_cache_clear()` Routing

```
frl_cache_clear($group, $key, $include_dependencies)
    │
    ├── $group ∈ {hard, all, light, options, rewriter}
    │       → Frl_Cache_Operations::run('clear_{group}')
    ├── $group === 'plugin_transients'
    │       → Frl_Cache_Manager::clear_transients()
    ├── $group === 'website_transients'
    │       → Frl_Cache_Manager::clear_all_website_transients()
    └── Any other group
            → Frl_Cache_Manager::clear_group_with_dependencies($group, $key, $include_dependencies)
```

## Clearing Behavior Reference

### Single-Key Clear

`frl_cache_clear('postdata', 'post_42')` — deletes the specific key from persistent storage and runtime. No dependency cascade, no browser headers, no `$groups_cleared` dedup flag.

### Full-Group Clear

`frl_cache_clear('options')` — checks `$groups_cleared` dedup, purges persistent + runtime storage, resets the plugin's `frl_get_option` static cache, cascades to dependencies, sends browser headers if the group is in `FRL_CACHE_BROWSER_GROUPS`. It deliberately does **not** clear WordPress's own `alloptions` cache (that would force a full autoloaded-options reload site-wide), and it does **not** remove `pre_option_frl_*` filters — those are managed per-write by `frl_update_option()` / `frl_delete_option()`.

### Hard Cache Reset (Action)

`frl_handle_action_clear_cache_hard()` → `Frl_Cache_Operations::run('action_hard')`:
1. `frl_cache_clear('hard')` → `hard_cache_reset()`: `purge_all()` + `clear_transients()` (plugin-prefixed only) + `do_action('frl_cache__after_hard_cache_reset')`
2. `frl_flush_rewrite_rules()` → fires `update_option_permalink_structure` → `flush_rewrite_rules(true)` → Polylang `clean_languages_cache()`

Step order is deliberate: caches are purged before rewrite rules are regenerated.

## The Options Read/Write Path

### Read path (`frl_get_option()`)

Four tiers:
1. **Request-local static array** — populated once per request, zero cache/DB cost thereafter
2. **Persistent cache** — first load calls `frl_get_plugin_options('all')` → `Frl_Cache_Manager::remember('options', 'all_options', …)`, backed by a single `LIKE 'frl_%'` DB query (no lock — see Race Condition Prevention)
3. **Stale-cache recovery** — if a key is missing from the snapshot, does one direct `get_option()` check and patches the snapshot
4. **Missing-option self-seeding** — if a key genuinely doesn't exist in DB (new field from code deploy), looks up its registered default/type/autoload, writes it once, batches cache invalidation across N missing keys. On a normal install/reset most defaults are already pre-written by `frl_seed_option_defaults()` at activation, so this path mainly covers options added by a later code deploy.

### Write path (`frl_update_option()`)

```php
remove_all_filters('pre_option_' . $prefixed_key);
$result = update_option($prefixed_key, $normalized_value, $autoload);
frl_cache_clear('options', 'all_options', false);  // single-key clear, no cascade
add_filter('pre_option_' . $prefixed_key, fn() => $normalized_value, 9999, 1);
```

- `remove_all_filters()` prevents stale anonymous closure stacking on repeated writes
- `pre_option_` filter at priority 9999 guarantees native `get_option('frl_x')` calls from other code see the fresh value immediately
- Scoped strictly to `pre_option_frl_*` — never affects other plugins

## Performance Considerations

- **Frontend, warm cache:** Zero DB queries for anything this plugin controls — consequence of layered caching (static array → runtime LRU → persistent cache → batched preload)
- **`auto_preload()`** runs on every non-AJAX request. On transient-only sites it batches the preload groups into a single DB query; on a functional object cache it early-returns (keys cannot be enumerated per group), so groups warm lazily instead
- **`purge_all()`** performs a single batch transient deletion, then skips per-group deletion via `$transients_batch_deleted` flag
- **Provider detection** is cached in a core transient with `WEEK_IN_SECONDS` TTL
- **Admin "Hard Reset"** can take several seconds — this is expected: it chains `purge_all()` + `clear_transients()` + `flush_rewrite_rules(true)`. The cost is paid only on explicit admin request, never on visitor-facing pages

## Admin Cache-Clear Actions — Context

Adminbar cache links work identically from frontend pages: built with `add_query_arg(FRL_PREFIX . '_action', $action)`, processed at `init:12` (not gated to `is_admin()`), capability-checked via `frl_has_access()`. The clearing action is fully functional on frontend pages.

**Known gap:** success/failure confirmation is stored via `frl_add_admin_notice()` as a transient, but only rendered by `frl_display_all_admin_notices()` hooked to `admin_notices` — a hook that never fires outside `wp-admin`. Clicking a cache-clear link from the adminbar on the live site clears the cache correctly but shows no visual confirmation until the admin visits `wp-admin`.

## Design Notes

- `Frl_Cache_Manager` is a fully static class — consistent with the codebase convention
- Configuration lives in PHP constants, not filterable via `apply_filters()` — `grep`-able and diff-able
- `purge_all()` batches transient deletion once, then skips redundant per-group deletion — avoids N+1 queries
- The `metadata` cache group is not in `FRL_CACHE_PERSISTENT_GROUPS` — only affects transient-fallback sites; on any site with functional object cache, the group works correctly via the object-cache path