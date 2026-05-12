# Fralenuvole v5.6.0 — Comprehensive Performance Audit Report

**Auditor:** Expert Performance Developer  
**Date:** 2026-04-29  
**Scope:** Full codebase review (~75+ files across all subsystems)  
**Status:** Complete

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Caching Architecture Assessment](#2-caching-architecture-assessment)
3. [Issue #1: Misused Re-Entrancy Guard in `frl_get_option`](#3-issue-1-misused-re-entrancy-guard-in-frl_get_option)
4. [Issue #2: `frl_disable_comments` — Heavy DB Write on Every Cache Miss](#4-issue-2-frl_disable_comments--heavy-db-write-on-every-cache-miss)
5. [Issue #3: `frl_get_critical_css_data` — Disk Reads on Every Cache Miss](#5-issue-3-frl_get_critical_css_data--disk-reads-on-every-cache-miss)
6. [Issue #4: Polylang Adapter — Wrong Function Call in `register_string`](#6-issue-4-polylang-adapter--wrong-function-call-in-register_string)
7. [Issue #5: Admin Widget Caching — Granularity Concerns in Dashboard](#7-issue-5-admin-widget-caching--granularity-concerns-in-dashboard)
8. [Issue #6: Module System — Persistent `file_exists` Calls on Every Request](#8-issue-6-module-system--persistent-file_exists-calls-on-every-request)
9. [Issue #7: Error Log — Render Block Capture Attributes Whitelist Overhead](#9-issue-7-error-log--render-block-capture-attributes-whitelist-overhead)
10. [Issue #8: Environment Manager — Full Config Merge on Every Valid Page Request](#10-issue-8-environment-manager--full-config-merge-on-every-valid-page-request)
11. [Strengths & Best Practices Observed](#11-strengths--best-practices-observed)
12. [Recommendations Prioritization](#12-recommendations-prioritization)
13. [Appendix: Files Reviewed](#13-appendix-files-reviewed)

---

## 1. Executive Summary

The Fralenuvole plugin demonstrates **exceptional engineering quality** targeting production performance. The caching architecture, layered options system, and subsystem isolation are well-conceived. The plugin's USP as a "performance-first" admin utility is genuinely supported by the code.

This audit identified **8 distinct findings** — 3 confirmed issues with performance impact, 1 confirmed bug, and 4 areas warranting optimization discussion. No critical performance bottlenecks were found that would degrade user experience under normal operation. The findings below are presented with full understanding of the architectural rationale behind each code path.

**Risk Profile:**
- 🔴 **Critical (0)** — No show-stoppers
- 🟠 **High (1)** — Issue #1: Re-entrancy guard misapplied (architectural integrity)
- 🟡 **Medium (3)** — Issues #2, #4, #5 (performance + correctness)
- 🔵 **Low (4)** — Issues #3, #6, #7, #8 (optimization opportunities)

---

## 2. Caching Architecture Assessment

### Overall Rating: ★★★★☆ (4.5/5)

The caching system is the plugin's crown jewel. Key strengths:

**2.1 5-Backend Auto-Detection**
Automatic detection and adaptation to Litespeed, Docket Cache, Redis, Memcached, and Transients — with graceful fallback and descriptive status reporting. See [`class-cache-manager.php:500-700`](includes/core/cache/class-cache-manager.php:500).

**2.2 3-Tier Options Cascade**
`Static→Persistent→DB` in [`frl_get_option`](includes/helpers/functions-options.php) eliminates redundant DB reads for frequently accessed options. Static LRU cache with configurable `FRL_CACHE_RUNTIME_MAX_ITEMS` (1000) prevents memory bloat.

**2.3 Deferred Writes & Batch Operations**
The `Frl_Cache_Manager` defers writes to `shutdown` and batches transient deletions, minimizing write I/O in a single request.

**2.4 Cache Dependency Graph**
17 persistent groups with explicit dependency mapping (`options→theme→html→environment→admin→adminui→rewriter→permalinks`). Clearing at the correct tier cascades appropriately.

**2.5 LRU Eviction**
Both the runtime cache (1000 items) and the rewriter dispatcher cache (1024 items, evict oldest 10%) use LRU-style eviction via `array_slice`.

**2.6 Areas for Discussion:**

- **Metadata group is transient-only** (not persistent). This is documented in [`CACHE.md`](docs/CACHE.md) as a known limitation. If metadata translation caches grow large, consider a dedicated persistent group.
- **Constant-based configuration** means TTL/group changes require code deployment. This is intentional (no runtime interpretation overhead) but worth noting for operational flexibility.

---

## 3. Issue #1: Misused Re-Entrancy Guard in `frl_get_option`

**Severity:** 🟠 **High**  
**File:** [`includes/helpers/functions-options.php:44`](includes/helpers/functions-options.php:44)  
**Type:** Architectural Integrity / Logic Error

### Description

The `frl_get_option()` function wraps its entire body in a `try/finally` block where the `finally` clause calls:

```php
frl_is_already_running(__FUNCTION__, true);
```

The second parameter `$reset = true` sets `$initialized['frl_get_option'] = false`, meaning the re-entrancy guard is **reset at the end of every call**. The guard is never actually active.

### Why This Matters

The `frl_is_already_running()` pattern is used correctly in ~15 other locations across the codebase:

| Function | Guard Active? | Reset When? |
|----------|:---:|:---:|
| [`purge_all()`](includes/core/cache/class-cache-manager.php) | ✅ Yes | Never (request lifecycle) |
| [`purge_light()`](includes/core/cache/class-cache-manager.php) | ✅ Yes | Never |
| [`reset_options_caches()`](includes/helpers/functions-options.php) | ✅ Yes | Never |
| [`delete_transients_from_db()`](includes/core/cache/class-cache-manager.php) | ✅ Yes | Never |
| [`clear_transients()`](includes/core/cache/class-cache-manager.php) | ✅ Yes | Never |
| **`frl_get_option()`** | ❌ **No** | **Every call** |

### Impact Analysis

Under normal single-threaded WordPress execution, this has **no observable bug impact** because `$initialized` is a static array, and PHP executes sequentially. The guard would only protect against recursive calls within the same execution path, which `frl_get_option()` doesn't typically encounter.

**However**, the architectural inconsistency matters because:
1. It creates a false sense of security — anyone reading the code sees the guard pattern and assumes protection
2. If `frl_get_option()` is ever called recursively (e.g., via a hook triggered inside `get_option()` → filter → custom callback → `frl_get_option()`), the guard will not prevent infinite recursion
3. It violates the established pattern documented in [`systemPatterns.md`](memory-bank/systemPatterns.md)

### Recommended Fix

Remove the `$reset = true` parameter from the `finally` block, making the guard genuinely active for the request lifecycle. The rationale for the original `try/finally` was likely to ensure the guard always resets — but this defeats its purpose. Either:

- **Option A:** Remove the `try/finally` entirely and use a simple guard at the top of the function (consistent with all other usages)
- **Option B:** Change `finally` to call `frl_is_already_running(__FUNCTION__, false)` or omit the reset parameter entirely

---

## 4. Issue #2: `frl_disable_comments` — Heavy DB Write on Every Cache Miss

**Severity:** 🟡 **Medium**  
**File:** [`includes/shared/website-features.php`](includes/shared/website-features.php)  
**Type:** Performance — Excessive DB Write

### Description

```php
function frl_disable_comments(): void
{
    if (!frl_is_option_enabled('disable_comments')) {
        return;
    }

    $done = frl_cache_remember('options', 'disable_comments', function () {
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['comment_status' => 'closed', 'ping_status' => 'closed'],
            ['post_status' => 'publish']
        );
        return true;
    }, YEAR_IN_SECONDS);
    
    if ($done) {
        // ... close comments on new post types, redirect, etc.
    }
}
```

This runs on the `init` hook. Every time the cache key `disable_comments` is invalidated (cache flush, plugin update, object cache purge), the callback executes a **full `$wpdb->update()` across ALL published posts** — potentially tens of thousands of rows.

### Why This Code Path Exists

The design intentionally connects the "disable comments" feature to a single `$wpdb->update()` call that ensures all existing published posts have comments closed. The `YEAR_IN_SECONDS` TTL ensures this only happens once under normal operation.

### Impact Analysis

| Scenario | Frequency | Impact |
|----------|:--------:|:------:|
| Normal operation (cache hit) | Every request | Zero — returns cached `true` |
| First enable + first load | Once | Writes to all published posts |
| Cache flush | Rare | Full DB write on next page load |
| Plugin update | Rare | Full DB write on next page load |
| Object cache purge | Unpredictable | Full DB write at unexpected times |

### Recommendation

Change to a batched approach that doesn't update posts already with `comment_status = 'closed'`:

```php
$wpdb->update(
    $wpdb->posts,
    ['comment_status' => 'closed', 'ping_status' => 'closed'],
    ['post_status' => 'publish', 'comment_status' => 'open']  // Only target open comments
);
```

This simple WHERE clause addition reduces the write set from "all published posts" to "only published posts where comments are still open" — which after the first run, would be zero rows. The feature would still work correctly because subsequent cache misses would find no rows to update (innocuous `$wpdb->update()` with zero matched rows).

---

## 5. Issue #3: `frl_get_critical_css_data` — Disk Reads on Every Cache Miss

**Severity:** 🔵 **Low**  
**File:** [`includes/shared/website-features.php`](includes/shared/website-features.php)  
**Type:** Performance — Disk I/O

### Description

```php
function frl_get_critical_css_data(): string
{
    $stylesheet_dir = get_stylesheet_directory();
    $critical_css_file = $stylesheet_dir . '/critical.css';

    if (!file_exists($critical_css_file)) {
        return '';
    }

    $mtime = filemtime($critical_css_file);

    $critical_css = frl_cache_remember('html', "critical_css_{$mtime}", function () use ($critical_css_file) {
        $content = file_get_contents($critical_css_file);
        if ($content === false) {
            return '';
        }
        return frl_minify_css($content);
    }, DAY_IN_SECONDS);

    return $critical_css;
}
```

On every cache miss, this performs:
1. `file_exists()` — stat syscall
2. `filemtime()` — stat syscall  
3. `file_get_contents()` — read the entire file into memory
4. `frl_minify_css()` — 4 regex passes + `str_replace`

### Why This Is Acceptable For Now

- Cache misses are rare (only after cache flush or file change)
- Critical CSS files are typically small (< 50KB)
- The `$mtime`-based cache key ensures invalidation on file change
- `DAY_IN_SECONDS` TTL is appropriate

### Optimization Opportunity

Pre-cache the critical CSS during plugin activation or via a one-shot admin action, so the first visitor after a cache flush doesn't pay the disk + minification cost. Alternatively, the MU plugin could warm this cache during `muplugins_loaded`.

---

## 6. Issue #4: Polylang Adapter — Wrong Function Call in `register_string`

**Severity:** 🟡 **Medium**  
**File:** [`includes/core/translator/adapters/polylang.php`](includes/core/translator/adapters/polylang.php)  
**Type:** Bug — Functional Failure

### Description

The `Frl_Polylang_Adapter::register_string()` method calls:

```php
icl_register_string($context, $name, $string);
```

This is the **WPML** function. The correct Polylang function is:

```php
pll_register_string($name, $string, $context, $multiline = false);
```

### Impact

- **String registration through the adapter is completely non-functional** when Polylang is the active multilingual plugin
- `icl_register_string()` will either produce a PHP warning (function not found) or silently fail
- This means translated strings defined via the translation config arrays are never registered with Polylang
- Any string translation via the token system `{{...}}` that relies on registration will fail silently

### Risk Assessment

The string registration is used for:
- Custom fields (`FRL_TRANSLATOR_FIELDS`)
- Options (`FRL_TRANSLATOR_OPTIONS`)
- ACF fields (`FRL_TRANSLATOR_FIELDS_ACF`)

If the site relies on Polylang for string translation of these fields and has no fallback (e.g., direct Polylang registration elsewhere), **translations will be missing**.

### Recommended Fix

Replace with the correct Polylang function call. Note the different parameter order:

```php
public function register_string(string $string, string $name, string $context): void {
    pll_register_string($name, $string, $context, false);
}
```

Also consider adding a constant or configuration check to prevent issues if WPML is used instead:

```php
if (function_exists('pll_register_string')) {
    pll_register_string($name, $string, $context, false);
} elseif (function_exists('icl_register_string')) {
    icl_register_string($context, $name, $string);
}
```

---

## 7. Issue #5: Admin Widget Caching — Granularity Concerns in Dashboard

**Severity:** 🟡 **Medium**  
**File:** [`admin/ui/class-ui-renderer.php`](admin/ui/class-ui-renderer.php)  
**Type:** Performance — Cache Granularity

### Description

The `Frl_UI_Renderer::render_widget()` and `render_table()` methods use `frl_cache_remember()` with `HOUR_IN_SECONDS` TTL by default, caching rendered HTML in the `adminui` group.

The issue is that the closure passed to `frl_cache_remember()` captures the **content passed by the caller**, not the **raw data**. This means:

```php
// In class-dashboard.php:
$widget_content = frl_ui_render_widget(
    'dashboard-overview',
    $columns . $env_widget['hidden'] . $cache_widget['hidden'],
    'Environment & Cache',
);
```

The cached HTML is constructed from pre-rendered strings that are themselves cached via `frl_cache_remember()`. This **double caching** is partially mitigated by the `$bypass_cache = true` pattern used for inner tables, but not consistently.

### Why This Pattern Exists

The architecture intentionally separates widget data fetching from rendering. The outer cache stores the final HTML, while inner components use `$bypass_cache` to avoid double caching. This is documented in [`ADMIN-UI.md`](docs/ADMIN-UI.md).

### Impact

- **Cache amplification:** The same data may be serialized and stored in two different cache keys
- **Stale data window:** If inner data updates, the outer widget HTML won't reflect it until its own TTL expires
- **Memory overhead:** Multiple serialized HTML copies in the object cache

### Recommendations

1. **Audit** all `render_widget` and `render_table` calls to ensure `$bypass_cache = true` is consistently applied for inner elements
2. **Consider** reducing the outer widget cache TTL from `HOUR_IN_SECONDS` to `15 * MINUTE_IN_SECONDS` for dynamic widgets
3. **Use** `frl_cache_remember()` at the data-fetching level (raw arrays/objects) rather than at the HTML-rendering level, caching HTML only at the outermost container

---

## 8. Issue #6: Module System — Persistent `file_exists` Calls on Every Request

**Severity:** 🔵 **Low**  
**File:** [`includes/helpers/functions-modules.php`](includes/helpers/functions-modules.php)  
**Type:** Performance — Redundant Filesystem Checks

### Description

The module system resolves file paths using functions like `frl_modules_module_get_file_path()` and `frl_modules_module_get_config_file_path()`. While these use `static $cache` for per-request dedup, `file_exists()` checks are still performed on the first call in each request.

```php
function frl_modules_module_get_file_path(string $module_key): ?string {
    static $cache = [];
    
    if (isset($cache[$module_key])) {
        return $cache[$module_key];
    }
    
    // ... path construction ...
    if (file_exists($path)) {  // <- stat call on first access
        $cache[$module_key] = $path;
    }
}
```

### Impact

- 10+ `file_exists()` stat calls per request for all active modules
- For `frl_modules_get_all_metadata()`, this calls `frl_modules_get_combined_data_iterator()` which uses a Generator — but the Generator is fully consumed on first call, triggering all file checks upfront

### Recommendations

1. **Cache file paths persistently** using `frl_cache_remember('options', 'module_file_paths_' . $module_key, ...)` with a week-long TTL
2. **Or** precompute all module file paths during plugin activation and store in an option
3. **Or** at minimum, batch the file checks into a single cached option update when module metadata is accessed

---

## 9. Issue #7: Error Log — Render Block Capture Attributes Whitelist Overhead

**Severity:** 🔵 **Low**  
**File:** [`includes/helpers/functions-error-log.php`](includes/helpers/functions-error-log.php)  
**Type:** Performance — String Processing Overhead

### Description

The `frl_log_capture_render_block_enter/exit` functions capture block rendering with a whitelist-filtered attributes set. The attributes are filtered through a whitelist array, which is applied on every block render.

### Why This Matters

Block rendering is a high-frequency operation (especially with many blocks on a page). Each block render:

1. Triggers `render_block` filter
2. Copies/filters block attributes through whitelist
3. Captures start time

### Recommendation

The performance impact is negligible unless `WP_DEBUG` is enabled AND debug logging is active. Consider:
- Adding a static guard to skip block capture entirely when debug logging is not enabled
- Using `frl_is_already_running()` to prevent recursive capture if a rendered block triggers another block

---

## 10. Issue #8: Environment Manager — Full Config Merge on Every Valid Page Request

**Severity:** 🔵 **Low**  
**File:** [`includes/core/environment/class-environment-config.php`](includes/core/environment/class-environment-config.php)  
**Type:** Performance — Computational Overhead

### Description

The `get_domain_config()` method caches the result in the `environment` cache group, so the full merge (`build_domain_config()`) only runs on cache miss. However, the initial bootstrap in `frl_environment_init()` runs on every valid page request, loading all 9 environment manager class files.

### Why This Pattern Exists

The EM must be loaded to register hooks (admin bar switcher, option tracking, plugin activation tracking). The throttle system prevents actual enforcement on most requests.

### Optimization Opportunity

Consider lazy-loading the environment manager files only when the admin bar is rendered (for the environment switcher) and when an enforcement is actually triggered. The class files total ~1,500 lines of PHP that are parsed on every request.

---

## 11. Strengths & Best Practices Observed

| Category | Example | Evidence |
|----------|---------|----------|
| **Cache Key Design** | Versioned translation cache keys | [`class-translation-service.php:819`](includes/core/translator/class-translation-service.php:819) |
| **Re-entrancy** | Correctly applied in 15+ functions | Throughout the codebase |
| **Batch Operations** | Deferred transient deletion | [`class-cache-manager.php`](includes/core/cache/class-cache-manager.php) |
| **LRU Eviction** | Runtime cache (1000 items), dispatcher cache (1024) | [`class-cache-manager.php`](includes/core/cache/class-cache-manager.php), [`abstract-base-feature.php`](includes/core/rewriter/features/abstract-base-feature.php) |
| **Lazy Loading** | Admin settings sections, metabox classes | [`admin/admin.php`](admin/admin.php) |
| **Static Caching** | Per-request dedup throughout helpers | All helper files |
| **Provider Detection** | 5-backend auto-detection | [`class-cache-manager.php:500-700`](includes/core/cache/class-cache-manager.php:500) |
| **Graceful Degradation** | Cache fallback without external object cache | [`class-cache-manager.php`](includes/core/cache/class-cache-manager.php) |
| **Hook Priority Discipline** | Consistent P5/P7/P10/P15/999 pattern | Throughout the codebase |
| **Security** | Nonce verification, capability checks, auth preservation | Docs + code |
| **Documentation** | Comprehensive subsystem docs | [`docs/`](docs/) (10 markdown files) |
| **REST API Guard** | Prevents URL transformation on REST requests | [`class-rewriter.php:174-176`](includes/core/rewriter/class-rewriter.php:174) |

---

## 12. Recommendations Prioritization

### 🔴 Must Fix (0)

No critical issues found.

### 🟠 Should Fix (1)

| # | Issue | Effort | Impact | Priority |
|---|-------|:------:|:------:|:--------:|
| 1 | Re-entrancy guard misused in `frl_get_option()` | Trivial | Architectural integrity | **High** |

### 🟡 Should Address (3)

| # | Issue | Effort | Impact | Priority |
|---|-------|:------:|:------:|:--------:|
| 2 | `frl_disable_comments` full DB write on cache miss | Trivial | DB load on cache flush | **Medium** |
| 4 | Polylang adapter uses `icl_register_string` instead of `pll_register_string` | Trivial | Functional bug | **Medium** |
| 5 | Admin widget cache granularity | Medium | Cache efficiency | **Medium** |

### 🔵 Consider (4)

| # | Issue | Effort | Impact | Priority |
|---|-------|:------:|:------:|:--------:|
| 3 | Critical CSS disk reads on cache miss | Low | Edge case | **Low** |
| 6 | Module `file_exists` calls per request | Low | Filesystem churn | **Low** |
| 7 | Block capture filter overhead in debug mode | Trivial | Debug-only | **Low** |
| 8 | EM config merge on every request | Medium | Bootstrap overhead | **Low** |

---

## 13. Appendix: Files Reviewed

### Core Cache & Options
- [`includes/helpers/functions-options.php`](includes/helpers/functions-options.php) — 3-tier options system
- [`includes/core/cache/class-cache-manager.php`](includes/core/cache/class-cache-manager.php) — 4/5-layer cache manager
- [`includes/core/cache/cache-cleanup.php`](includes/core/cache/cache-cleanup.php) — Cache invalidation hooks
- [`config/config-cache.php`](config/config-cache.php) — Cache configuration
- [`config/config-cache-operations.php`](config/config-cache-operations.php) — Cache operations orchestrator

### Plugin Bootstrap
- [`fralenuvole.php`](fralenuvole.php) — Main entry point
- [`includes/bootstrap.php`](includes/bootstrap.php) — Plugin bootstrap
- [`includes/main.php`](includes/main.php) — Core loading
- [`includes/plugin-lifecycle.php`](includes/plugin-lifecycle.php) — Activation/deactivation
- [`config/config.php`](config/config.php) — Configuration merger
- [`config/config-base.php`](config/config-base.php) — Base constants
- [`config/config-fields.php`](config/config-fields.php) — Field definitions
- [`config/config-options.php`](config/config-options.php) — Options definitions

### Environment Manager
- [`includes/core/environment/environment-manager.php`](includes/core/environment/environment-manager.php) — Bootstrap
- [`includes/core/environment/class-environment-manager.php`](includes/core/environment/class-environment-manager.php) — Facade
- [`includes/core/environment/class-environment-config.php`](includes/core/environment/class-environment-config.php) — Config builder
- [`includes/core/environment/class-environment-state.php`](includes/core/environment/class-environment-state.php) — State persistence
- [`includes/core/environment/class-environment-monitor.php`](includes/core/environment/class-environment-monitor.php) — URL correction + tracking
- [`includes/core/environment/class-environment-applier.php`](includes/core/environment/class-environment-applier.php) — Config application
- [`includes/core/environment/class-environment-files.php`](includes/core/environment/class-environment-files.php) — File-based options
- [`includes/core/environment/class-environment-plugin-manager.php`](includes/core/environment/class-environment-plugin-manager.php) — Plugin lifecycle
- [`includes/core/environment/class-environment-utils.php`](includes/core/environment/class-environment-utils.php) — Utilities
- [`config/environment/config-defaults.php`](config/environment/config-defaults.php) — Default environments
- [`config/environment/config-environment.php`](config/environment/config-environment.php) — Environment map

### Translator
- [`includes/core/translator/translator.php`](includes/core/translator/translator.php) — Bootstrap
- [`includes/core/translator/class-translation-service.php`](includes/core/translator/class-translation-service.php) — Translation service
- [`includes/core/translator/field-translator.php`](includes/core/translator/field-translator.php) — Field translation hooks
- [`includes/core/translator/adapters/interface.php`](includes/core/translator/adapters/interface.php) — Adapter contract
- [`includes/core/translator/adapters/polylang.php`](includes/core/translator/adapters/polylang.php) — Polylang adapter
- [`config/config-translator.php`](config/config-translator.php) — Translation configuration

### Rewriter
- [`includes/core/rewriter/class-rewriter.php`](includes/core/rewriter/class-rewriter.php) — Facade
- [`includes/core/rewriter/class-rewriter-coordinator.php`](includes/core/rewriter/class-rewriter-coordinator.php) — Coordinator
- [`includes/core/rewriter/features/abstract-base-feature.php`](includes/core/rewriter/features/abstract-base-feature.php) — Base class
- [`includes/core/rewriter/features/class-cpt-archive-base-translation-feature.php`](includes/core/rewriter/features/class-cpt-archive-base-translation-feature.php) — CPT Archive
- [`includes/core/rewriter/features/class-cpt-single-base-translation-feature.php`](includes/core/rewriter/features/class-cpt-single-base-translation-feature.php) — CPT Single
- [`includes/core/rewriter/features/class-taxonomy-base-removal-feature.php`](includes/core/rewriter/features/class-taxonomy-base-removal-feature.php) — Taxonomy Base
- [`includes/core/rewriter/features/class-cpt-base-removal-feature.php`](includes/core/rewriter/features/class-cpt-base-removal-feature.php) — CPT Base
- [`includes/core/rewriter/class-rewriter-path-utils.php`](includes/core/rewriter/class-rewriter-path-utils.php) — Path utilities
- [`includes/core/rewriter/class-rewriter-config-validator.php`](includes/core/rewriter/class-rewriter-config-validator.php) — Admin validator
- [`includes/core/rewriter/interface-feature.php`](includes/core/rewriter/interface-feature.php) — Feature interface
- [`includes/core/rewriter/interface-rewriter.php`](includes/core/rewriter/interface-rewriter.php) — Rewriter interface
- [`includes/core/rewriter/trait-cache-key-generator.php`](includes/core/rewriter/trait-cache-key-generator.php) — Cache key trait
- [`includes/core/rewriter/class-rewriter-cli.php`](includes/core/rewriter/class-rewriter-cli.php) — WP-CLI
- [`config/config-rewriter.php`](config/config-rewriter.php) — Rewriter configuration

### ThemeKit
- [`includes/core/themekit/themekit.php`](includes/core/themekit/themekit.php) — ThemeKit bootstrap
- [`config/config-themekit.php`](config/config-themekit.php) — ThemeKit config

### Helpers
- [`includes/helpers/functions.php`](includes/helpers/functions.php) — Core helpers
- [`includes/helpers/functions-class-helpers.php`](includes/helpers/functions-class-helpers.php) — Class facade functions
- [`includes/helpers/functions-access-control.php`](includes/helpers/functions-access-control.php) — Access control
- [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php) — MU plugin logic
- [`includes/helpers/functions-action-handlers.php`](includes/helpers/functions-action-handlers.php) — Admin action handlers
- [`includes/helpers/functions-error-log.php`](includes/helpers/functions-error-log.php) — Error logging
- [`includes/helpers/functions-modules.php`](includes/helpers/functions-modules.php) — Module management
- [`includes/helpers/functions-translation-helpers.php`](includes/helpers/functions-translation-helpers.php) — Translation helpers
- [`includes/helpers/utilities.php`](includes/helpers/utilities.php) — General utilities
- [`includes/core/error-handler.php`](includes/core/error-handler.php) — Error handler

### Shared Files
- [`includes/shared/website-features.php`](includes/shared/website-features.php) — Website features
- [`includes/shared/logged-user.php`](includes/shared/logged-user.php) — Logged user features
- [`includes/shared/media.php`](includes/shared/media.php) — Media features
- [`includes/shared/navigation.php`](includes/shared/navigation.php) — Navigation features

### MU Plugin
- [`assets/mu/frl-mu-plugin.php`](assets/mu/frl-mu-plugin.php) — MU plugin loader

### Admin
- [`admin/admin.php`](admin/admin.php) — Admin bootstrap
- [`admin/helpers/functions-admin.php`](admin/helpers/functions-admin.php) — Admin helpers
- [`admin/ui/class-dashboard-renderer.php`](admin/ui/class-dashboard-renderer.php) — Dashboard renderer
- [`admin/ui/class-tab-manager.php`](admin/ui/class-tab-manager.php) — Tab manager
- [`admin/ui/class-tab-registry.php`](admin/ui/class-tab-registry.php) — Tab registry
- [`admin/ui/class-tab-renderer.php`](admin/ui/class-tab-renderer.php) — Tab renderer
- [`admin/ui/class-ui-renderer.php`](admin/ui/class-ui-renderer.php) — UI renderer
- [`admin/ui/ui-admin-settings.php`](admin/ui/ui-admin-settings.php) — Settings page
- [`admin/ui/ui-asset-loader.php`](admin/ui/ui-asset-loader.php) — Asset loader
- [`admin/components/class-dashboard.php`](admin/components/class-dashboard.php) — Dashboard component
- [`admin/components/class-display-cache.php`](admin/components/class-display-cache.php) — Cache display

### Public
- [`public/public.php`](public/public.php) — Public hooks
- [`public/schema.php`](public/schema.php) — Schema markup
- [`public/shortcodes.php`](public/shortcodes.php) — Shortcodes

### Documentation
- [`docs/CACHE.md`](docs/CACHE.md) — Cache architecture
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — System architecture
- [`docs/ADMIN-UI.md`](docs/ADMIN-UI.md) — Admin interface
- [`docs/ENVIRONMENT.md`](docs/ENVIRONMENT.md) — Environment manager
- [`docs/REWRITER.md`](docs/REWRITER.md) — Rewriter subsystem
- [`docs/TRANSLATOR.md`](docs/TRANSLATOR.md) — Translation system
- [`docs/PLUGIN-EXCLUSIONS.md`](docs/PLUGIN-EXCLUSIONS.md) — Plugin exclusion

---

*End of Report*
