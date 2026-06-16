# Multilingual Cache Flush — Developer Reference

> **Version:** 1.0 | **Date:** 2026-06-16  
> **Scope:** WordPress + Polylang + LiteSpeed Cache + Docket Cache + Fralenuvole  
> **Problem Solved:** Secondary language 404s caused by stale rewrite rules during cache flush operations  

---

## Table of Contents

1. [System Architecture](#1-system-architecture)
2. [Problem Statement](#2-problem-statement)
3. [Root Cause](#3-root-cause)
4. [Manual Workaround Explained](#4-manual-workaround-explained)
5. [Solution Applied](#5-solution-applied)
6. [Cross-Plugin Notification Matrix](#6-cross-plugin-notification-matrix)
7. [Automatic Flush Scenarios](#7-automatic-flush-scenarios)
8. [Developer Extension Points](#8-developer-extension-points)
9. [Source File Map](#9-source-file-map)

---

## 1. System Architecture

### 1.1 Cache Layers

| Layer | Runtime | Storage | Critical Data |
|-------|---------|---------|---------------|
| Page Cache | LiteSpeed | Web server (LSCache header) | Full HTML pages |
| Object Cache | Docket Cache | PHP files on disk | All `wp_cache_*()` calls |
| `alloptions` | WP Core + Docket | Single Docket file (`options` group, key `alloptions`) | ALL autoloaded options including `rewrite_rules` |
| Transients | Docket Cache | PHP files (optional SQLite via `TransientDb` flag) | `pll_languages_list`, other expiring data |
| Rewrite Rules | WordPress Core | `rewrite_rules` option in `wp_options` table | URL routing regex array |
| Polylang Lang Cache | Polylang | `pll_languages_list` transient + in-memory `PLL_Cache` | `PLL_Language` objects (slugs, URLs, counts) |
| Fralenuvole Groups | Fralenuvole | Object cache groups (`options`, `rewriter`, `permalinks`, etc.) | Translated post bases, URL mappings, computed data |

### 1.2 Dependency Cascade

Fralenuvole's cache groups follow a directed invalidation cascade:

```
options
  ├── theme
  ├── html
  ├── environment
  ├── admin
  ├── adminui
  └── rewriter
        └── permalinks
```

Clearing `options` automatically cascades to all dependent groups. Defined in `FRL_CACHE_DEPENDENCIES`.

### 1.3 Hook Distinction (Critical)

| Hook | Type | Fired By | Listener |
|------|------|----------|----------|
| `litespeed_purge_all` | **Trigger** (imperative) | External callers requesting purge | `Purge::purge_all()` in LiteSpeed |
| `litespeed_purged_all` | **Notification** (past tense) | LiteSpeed after purge completes | Docket (`delete('alloptions','options')`), Fralenuvole (inbound bridge) |

Fralenuvole's outbound configuration fires `litespeed_purge_all` as the trigger. Fralenuvole's inbound listener hooks `litespeed_purged_all` for cleanup.

---

## 2. Problem Statement

**Symptom:** Secondary language (non-EN) permalinks return 404 errors on a Polylang multilingual site.

**Manual Resolution:** Requires **2× Save Permalinks + 2× LiteSpeed Purge All** (four separate clicks).

**Fralenuvole Failure:** The plugin's own "Flush Rewrite Rules" admin button does not reliably fix the issue in one click.

---

## 3. Root Cause

### 3.1 The Core Timing Defect

Docket Cache defers `alloptions` cache file deletion to the WordPress **shutdown** hook (`PHP_INT_MAX - 1` priority). When `flush_rewrite_rules()` runs during the same request:

1. `wp_load_alloptions()` is called internally by `flush_rewrite_rules()`
2. Docket's cached `alloptions` file **still exists on disk** (shutdown hasn't fired yet)
3. The cached file returns **stale `rewrite_rules`** — the old rules from before the permalink change
4. `flush_rewrite_rules()` generates new rules using stale input, producing broken output

The stale file survives until shutdown. Docket's `updated_option` handler defers deletion to [`cache.php:2065`](../docket-cache/includes/cache.php:2065):

```php
add_action(
    'shutdown',
    function () {
        $this->delete('alloptions', 'options');
    },
    PHP_INT_MAX - 1
);
```

### 3.2 Why the Button Fails (Three Failure Points)

The "Flush Rewrite Rules" button fires at `init:10` (before `wp_loaded`), calling the chain:

```
Button → frl_handle_action_flush_rewrite_rules()
       → frl_flush_rewrite_rules()                               [plugin-lifecycle.php:184]
```

| # | Failure | Location |
|---|---------|----------|
| 1 | `clear_rewriter_caches()` has NO listener at `init:10` — hook registration is deferred inside a `wp_loaded` callback | [`class-rewriter.php:450`](core/rewriter/class-rewriter.php:450) |
| 2 | `flush_rewrite_rules(true)` reads Docket's STALE `alloptions` cached file (file survives until shutdown) | [`plugin-lifecycle.php:194`](includes/plugin-lifecycle.php:194) |
| 3 | `frl_thirdparty_maybe_notify('rewrite_flush')` → LiteSpeed purge happens **after** contamination already occurred | [`plugin-lifecycle.php:196`](includes/plugin-lifecycle.php:196) |

### 3.3 Why `_purge_all_object()` Is a NO-OP with Docket

LiteSpeed's `_purge_all_object()` ([`purge.cls.php:558`](../litespeed-cache/src/purge.cls.php:558)) gates on `LSCWP_OBJECT_CACHE`:

```php
if (!defined('LSCWP_OBJECT_CACHE')) {
    return false;  // ← Docket is the active drop-in, so this exits immediately
}
```

When Docket is the object cache drop-in, `LSCWP_OBJECT_CACHE` is not defined, and the method returns `false`. LiteSpeed does NOT call `wp_cache_flush()`. The only Docket interaction from LiteSpeed is via the `litespeed_purged_all` notification → `delete('alloptions', 'options')`.

---

## 4. Manual Workaround Explained

### 4.1 Why "2× Save Permalinks + 2× LiteSpeed Purge" Works

| Cycle | Event | alloptions Cache State |
|-------|-------|----------------------|
| 1st Save Permalinks | `flush_rewrite_rules()` runs | **STALE** — file still has old `rewrite_rules` |
| Between clicks | Shutdown from 1st click | **DELETED** by Docket's shutdown hook |
| 1st LiteSpeed Purge | Clears page cache | No effect on alloptions (already gone) |
| 2nd Save Permalinks | `flush_rewrite_rules()` runs | **FRESH** — DB query, no cached file |
| 2nd LiteSpeed Purge | Clears wrongly cached pages | — |
| Next request | Serves page | ✅ Correct → 404 resolved |

The ONLY thing that changes between click 1 and click 2 is that Docket's `alloptions` cache file gets deleted on shutdown. No `wp_cache_flush()`, no OPcache, no heavy operations. Just one file.

---

## 5. Solution Applied

### 5.1 The Fix

In [`includes/plugin-lifecycle.php:184-206`](includes/plugin-lifecycle.php:184), one line was added at the top of `frl_flush_rewrite_rules()`:

```php
function frl_flush_rewrite_rules(): void
{
    // Delete Docket's cached alloptions BEFORE regenerating rewrite rules.
    // Docket defers alloptions invalidation to shutdown (cache.php:2065),
    // so the cached file survives the current request. Without this,
    // wp_load_alloptions() returns stale rewrite_rules during flush,
    // causing 404 errors on secondary-language permalinks.
    wp_cache_delete('alloptions', 'options');

    $permastruct = get_option('permalink_structure');
    do_action('update_option_permalink_structure', $permastruct, $permastruct);
    do_action('permalink_structure_changed', $permastruct, $permastruct);

    if (!did_action('wp_loaded')) {
        flush_rewrite_rules(true);
        if (function_exists('frl_thirdparty_maybe_notify')) {
            frl_thirdparty_maybe_notify('rewrite_flush');
        }
    }
}
```

### 5.2 Fix Characteristics

| Property | Value |
|----------|-------|
| Operation | `wp_cache_delete('alloptions', 'options')` — deletes single file |
| Complexity | O(1) — constant time |
| API | Standard WordPress Object Cache (works with any drop-in, not Docket-specific) |
| Side Effects | None — subsequent shutdown deletes hit missing-file fast path (~0 cost) |
| Replaces | The 2nd Save Permalinks click from the manual workaround |

### 5.3 Post-Fix Execution Trace

```
1. wp_cache_delete('alloptions', 'options')        ← Synchronous file delete
   → wp_load_alloptions() will now query DB directly

2. do_action('update_option_permalink_structure')
   → Polylang: clean_languages_cache() ✓
   → Docket: queues alloptions delete on shutdown (harmless, file already gone)
   → clear_rewriter_caches() still skipped (timing — non-critical)

3. flush_rewrite_rules(true)
   → wp_load_alloptions() → DB → CORRECT rewrite_rules ✓

4. frl_thirdparty_maybe_notify('rewrite_flush')
   → do_action('litespeed_purge_all') → Purge::_purge_all()
      → _purge_all_lscache() → '*' header → page cache cleared ✓
      → _purge_all_cssjs() / _purge_all_localres() / purge_all_opcache() ✓
      → _purge_all_object() → guard blocks (Docket is drop-in) — NO-OP
      → do_action('litespeed_purged_all') → Docket delete('alloptions') — harmless
```

**Result: One click produces identical effects as Save Permalinks + LiteSpeed Purge All.**

### 5.4 Non-Critical Limitation

`clear_rewriter_caches()` is still not triggered by the button (hook registration is deferred to `wp_loaded`). This means Fralenuvole's `rewriter` and `permalinks` object cache groups are not explicitly cleared. These groups contain computed/derived data that self-heals on the next cache miss. They are NOT the source of 404 errors.

---

## 6. Cross-Plugin Notification Matrix

| Direction | Mechanism | Key Source | Auto? |
|-----------|-----------|------------|:-----:|
| Save Permalinks → Polylang | `update_option_permalink_structure` → `clean_languages_cache()` | [`model.php:119`](../polylang/src/model.php:119) | ✅ |
| Save Permalinks → Docket alloptions | `updated_option` → shutdown-deferred `delete('alloptions')` | [`cache.php:2058-2071`](../docket-cache/includes/cache.php:2058) | ⏳ |
| Save Permalinks → LiteSpeed | `O_PURGE_HOOK_ALL` defaults to `[]` — no hook wired | [`base.cls.php:413`](../litespeed-cache/src/base.cls.php:413) | ✗ |
| LiteSpeed Purge → Docket alloptions | `litespeed_purged_all` → `delete('alloptions', 'options')` | [`cache.php:2037`](../docket-cache/includes/cache.php:2037) | ✅ |
| LiteSpeed Purge → Docket (full) | No path — `_purge_all_object()` gates on `LSCWP_OBJECT_CACHE` | [`purge.cls.php:559`](../litespeed-cache/src/purge.cls.php:559) | ✗ |
| LiteSpeed Purge → Polylang | No hook exists | — | ✗ |
| Polylang → Docket | `delete_transient()` only (transient scope) | — | ✗ |
| Fralenuvole → LiteSpeed | Outbound: `do_action('litespeed_purge_all')` (trigger hook) | [`config-constants-thirdparty.php:85`](modules/thirdparty/config-constants-thirdparty.php:85) | ✅ |
| Fralenuvole → Docket alloptions | Direct: `wp_cache_delete('alloptions', 'options')` (the fix) | [`plugin-lifecycle.php:191`](includes/plugin-lifecycle.php:191) | ✅ |
| LiteSpeed → Fralenuvole | Inbound: `litespeed_purged_all` → `clear('light')` + schedule flush | [`config-constants-thirdparty.php:29`](modules/thirdparty/config-constants-thirdparty.php:29) | ✅ |
| Breeze → Fralenuvole | Inbound: `breeze_clear_all_cache` → `clear('light')` + schedule flush | [`config-constants-thirdparty.php:37`](modules/thirdparty/config-constants-thirdparty.php:37) | ✅ |
| WP Rocket → Fralenuvole | Inbound: `after_rocket_clean_domain` → `clear('light')` + schedule flush | [`config-constants-thirdparty.php:43`](modules/thirdparty/config-constants-thirdparty.php:43) | ✅ |

---

## 7. Automatic Flush Scenarios

### 7.1 Fralenuvole Inbound Bridge

When an external cache plugin purges, Fralenuvole's inbound listener ([`thirdparty.php:405`](modules/thirdparty/thirdparty.php:405)):

1. Calls `frl_cache_clear('light')` → `purge_light()` → clears ALL groups **except** `FRL_CACHE_HEAVY_GROUPS` (`['staticdata', 'blocks', 'translations', 'permalinks', 'postdata']`). The `options` group IS cleared, which cascades to `reset_options_caches()` → `wp_cache_delete('alloptions', 'options')`.

2. Calls `frl_schedule_rewrite_flush()` — schedules a cron event with **15-second delay** and **60-second cooldown** to prevent cross-plugin ping-pong loops.

### 7.2 Risk Summary

| Scenario | Risk | Rationale |
|----------|:----:|-----------|
| LiteSpeed auto-purge on WordPress upgrade | None | Inbound bridge clears alloptions + delayed cron runs as separate request |
| Polylang language cache TTL regeneration | None | Read-only operation, no rewrite rules interaction |
| Post/term CRUD auto-flush (Fralenuvole) | None* | Cron pattern avoids same-request timing; fix adds belt-and-suspenders |
| Fralenuvole cache TTL expiration | None | Passive invalidation — no active flush occurs |

*Was Low risk before the fix. Now eliminated.

### 7.3 Key Architectural Safeguard

Fralenuvole's `purge_light()` ALWAYS calls `wp_cache_delete('alloptions', 'options')` via `reset_options_caches()` ([`class-cache-manager.php:1349`](core/cache/class-cache-manager.php:1349)). This means every inbound purge notification correctly invalidates the `alloptions` before the scheduled rewrite flush fires. Combined with the fix in `frl_flush_rewrite_rules()` (which also deletes alloptions), the system is doubly protected.

---

## 8. Developer Extension Points

### 8.1 Adding a New Inbound Cache Plugin Hook

To make Fralenuvole respond to a new third-party plugin's cache purge:

```php
add_filter('frl_thirdparty_inbound_hooks', function(array $hooks): array {
    $hooks['my_plugin_purged_all'] = [
        'label'         => 'My Cache Plugin',
        'clear'         => 'light',          // 'light', 'all', 'hard', or array of group names
        'rewrite_flush' => true,             // whether to also schedule a rewrite flush
    ];
    return $hooks;
});
```

The hook name must be the action fired by the external plugin after cache purge completes.

### 8.2 Adding a New Outbound Cache Plugin Target

To notify another plugin when Fralenuvole purges:

```php
add_filter('frl_thirdparty_outbound_hooks', function(array $hooks): array {
    $hooks['my_plugin'] = [
        'label'    => 'My Cache Plugin',
        'type'     => 'action',              // 'action' for do_action(), 'function' for direct call
        'target'   => 'my_plugin_purge_all', // action name or function name
        'check'    => 'MyPlugin\\Purge',     // class/function that must exist (safety check)
        'triggers' => ['hard', 'rewrite_flush'], // internal triggers that should notify
    ];
    return $hooks;
});
```

Supported triggers: `'hard'`, `'all'`, `'light'`, `'rewrite_flush'`.

### 8.3 Adding a New Query-Param Inbound Trigger

For plugins that bypass `do_action()` (e.g., admin-bar buttons):

```php
add_filter('frl_thirdparty_inbound_queries', function(array $queries): array {
    $queries['my_plugin_bar_purge'] = [
        'label'         => 'My Plugin',
        'clear'         => 'light',
        'rewrite_flush' => true,
        'query_key'     => 'myplugin_purge',
    ];
    return $queries;
});
```

The nonce is auto-verified using the pattern `{query_key}_cache`.

### 8.4 Extending Cache Clear Operations

Fralenuvole cache operations are defined declaratively in [`config-cache-operations.php`](config/config-cache-operations.php). Three tiers:

| Tier | Naming | Called By |
|------|--------|-----------|
| Helper | `clear_*` | `frl_cache_clear($group)` internally |
| Action | `action_*` | Admin `frl_action` GET parameter handlers |
| Cron | `cron_*` | `frl_daily_cache_cleanup` cron job |

To add a new helper operation, add an entry to the `FRL_CACHE_OPERATIONS` constant.

### 8.5 Cache Dependency Cascade

Cache dependencies are defined via `FRL_CACHE_DEPENDENCIES` constant. When group `options` is cleared, all dependent groups (`theme`, `html`, `environment`, `admin`, `adminui`, `rewriter`, `permalinks`) are automatically invalidated.

### 8.6 Heavy Groups

Groups defined in `FRL_CACHE_HEAVY_GROUPS` are skipped by `purge_light()`:
- `staticdata`, `blocks`, `translations`, `permalinks`, `postdata`

These are cleared individually on specific triggers (e.g., `permalinks` on `save_post`, `translations` on language change).

### 8.7 The `frl_flush_rewrite_rules()` Function

**Location:** [`includes/plugin-lifecycle.php:184`](includes/plugin-lifecycle.php:184)

**Callers:**
- Cron hook: `add_action('frl_flush_rewrite_rules', 'frl_flush_rewrite_rules')` — scheduled by `frl_schedule_rewrite_flush()` with 15s delay
- Admin button: `frl_handle_action_flush_rewrite_rules()` → `frl_flush_rewrite_rules()`
- Activation/Deactivation: `frl_schedule_rewrite_flush()` schedules the cron (does NOT call directly before `init`)

**Guarantee:** The function now always calls `wp_cache_delete('alloptions', 'options')` at entry, making it self-sufficient regardless of Docket's shutdown timing.

---

## 9. Source File Map

### Fralenuvole Plugin

| File | Lines | Content |
|------|-------|---------|
| [`includes/plugin-lifecycle.php`](includes/plugin-lifecycle.php) | 184-206, 16-19, 219-223 | `frl_flush_rewrite_rules()` (fix applied), cron hook, `frl_schedule_rewrite_flush()` |
| [`includes/helpers/functions-action-handlers.php`](includes/helpers/functions-action-handlers.php) | 327-336 | `frl_handle_action_flush_rewrite_rules()` — button handler |
| [`core/rewriter/class-rewriter.php`](core/rewriter/class-rewriter.php) | 397-417, 440-458 | `clear_rewriter_caches()`, `register_cache_invalidation_hooks()` (deferred to `wp_loaded`) |
| [`core/cache/class-cache-manager.php`](core/cache/class-cache-manager.php) | 1341-1354, 1624-1652 | `reset_options_caches()` (calls `wp_cache_delete('alloptions','options')`), `purge_light()` |
| [`core/cache/cache-cleanup.php`](core/cache/cache-cleanup.php) | 27-33, 55-64 | Term CRUD → schedule rewrite flush, post save invalidation |
| [`modules/thirdparty/config-constants-thirdparty.php`](modules/thirdparty/config-constants-thirdparty.php) | 26-48, 61-103 | `FRL_THIRDPARTY_INBOUND_HOOKS`, `FRL_THIRDPARTY_INBOUND_QUERIES`, `FRL_THIRDPARTY_OUTBOUND_HOOKS` |
| [`modules/thirdparty/thirdparty.php`](modules/thirdparty/thirdparty.php) | 320-332, 341-436, 519-559 | Inbound/outbound bridge, `frl_thirdparty_inbound_cache_clear()`, `frl_thirdparty_maybe_notify()` |
| [`config/config-cache.php`](config/config-cache.php) | 67-74 | `FRL_CACHE_HEAVY_GROUPS` |
| [`config/config-cache-operations.php`](config/config-cache-operations.php) | 80-128 | `clear_light`, `clear_options`, `clear_rewriter` operation definitions |
| [`includes/shared/logged-user.php`](includes/shared/logged-user.php) | 11-12 | Button hook: `add_action('init', 'frl_process_plugin_actions', 10)` |

### LiteSpeed Cache Plugin

| File | Lines | Content |
|------|-------|---------|
| [`src/purge.cls.php`](../litespeed-cache/src/purge.cls.php) | 208-240, 547-583 | `_purge_all()`, `_purge_all_object()` (guards on `LSCWP_OBJECT_CACHE`) |
| [`src/api.cls.php`](../litespeed-cache/src/api.cls.php) | 108-122 | `litespeed_purge_all` trigger hook registration |
| [`src/core.cls.php`](../litespeed-cache/src/core.cls.php) | 100-115 | `O_PURGE_HOOK_ALL` (default: `[]`), auto-purge on upgrade |
| [`src/base.cls.php`](../litespeed-cache/src/base.cls.php) | 397-413, 597 | `O_PURGE_ON_UPGRADE` default: `false`, `O_PURGE_HOOK_ALL` default: `[]` |

### Docket Cache Plugin

| File | Lines | Content |
|------|-------|---------|
| [`includes/cache.php`](../docket-cache/includes/cache.php) | 605-621, 1257-1298, 2037-2078 | `delete()`, `dc_flush()`, `dc_remove()`, `litespeed_purged_all` listener, `updated_option` shutdown-deferred handler |
| [`includes/src/Filesystem.php`](../docket-cache/includes/src/Filesystem.php) | 452-501, 1007-1085 | `unlink()` with file locking and OPcache flush, `cachedir_flush()` with static guard and 180s timeout |

### Polylang Plugin

| File | Lines | Content |
|------|-------|---------|
| [`src/model.php`](../polylang/src/model.php) | 117-121, 193-197 | `clean_languages_cache()` hooked to `update_option_permalink_structure` |
| [`src/Model/Languages.php`](../polylang/src/Model/Languages.php) | 30, 849-875 | `TRANSIENT_NAME = 'pll_languages_list'`, `clean_cache()` → `delete_transient()` |
| [`src/links-directory.php`](../polylang/src/links-directory.php) | 148-256 | Language slug embedding in rewrite rules |
| [`src/frontend/choose-lang.php`](../polylang/src/frontend/choose-lang.php) | 81-129 | `set_language()` fallback chain |

---

## Appendix A: Quick Reference — Flush Sequence

**Ideal manual flush order for a clean multilingual slate:**

1. **Fralenuvole "Flush Rewrite Rules" button** (or Save Permalinks) — deletes alloptions + regenerates rewrite rules + notifies LiteSpeed
2. Wait 15 seconds for cron completion (or check that rewrite rules are present)
3. Visit secondary-language URLs to verify

With the fix applied, step 1 alone suffices. No need for separate LiteSpeed Purge or second Save Permalinks click.

## Appendix B: Glossary

| Term | Definition |
|------|-----------|
| **alloptions** | WordPress autoloaded options cached as a single array under key `alloptions` in the `options` object cache group. Includes `rewrite_rules`. |
| **Trigger hook** | Action fired to **request** a purge (e.g., `litespeed_purge_all`) |
| **Notification hook** | Action fired **after** purge completes (e.g., `litespeed_purged_all`) |
| **Inbound bridge** | Fralenuvole listens to external purge notifications and clears its own caches |
| **Outbound bridge** | When Fralenuvole purges, it notifies external cache plugins |
| **Heavy groups** | Cache groups skipped by `purge_light()` to avoid expensive mass invalidation |
| **Dependency cascade** | Clearing one group automatically clears all groups that depend on it |
