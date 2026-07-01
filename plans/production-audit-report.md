# Fralenuvole Production Audit Report

**Date:** 2026-07-01  
**Scope:** Full codebase audit for bugs, performance issues, and plugin conflicts  
**Focus:** Rewriter/Polylang rewrite rule corruption, performance throttles, WP/plugin interference

---

## Executive Summary

The codebase is architecturally mature with strong defensive patterns (re-entrancy guards, LRU caches, lock-based race prevention, feature-based rewriter). However, the audit identified **5 critical bugs**, **4 performance issues**, and **3 plugin-conflict risks** that should be addressed for production safety.

---

## 🔴 CRITICAL FINDINGS

### C1. `frl_alter_query()` Unconditionally Disables Meta/Term Caches on ALL Non-Main Queries

**File:** [`public/public.php`](public/public.php:433-462)  
**Severity:** CRITICAL — Breaks third-party plugins and themes

**Problem:**
```php
function frl_alter_query($query)
{
    if (!$query instanceof WP_Query || $query->is_main_query()) {
        return;
    }

    $query->set('update_post_meta_cache', false);
    $query->set('update_post_term_cache', false);
    $query->set('no_found_rows', true);
    $query->set('ignore_sticky_posts', true);
    $query->set('post_status', 'publish');
    $query->set('has_password', false);
    // ...
}
```

This fires on **every** `pre_get_posts` for **every** non-main `WP_Query` — including queries from other plugins (WooCommerce, GeoDirectory, ACF relationship fields, FacetWP, etc.). The settings are applied **unconditionally** before checking if the query belongs to a configured CPT.

**Impact:**
- `update_post_meta_cache = false` → Every subsequent `get_post_meta()` call triggers a separate DB query. On pages with ACF relationship fields or WooCommerce product loops, this causes **N+1 query explosions**.
- `no_found_rows = true` → Breaks pagination on any secondary query (e.g., widget loops, related posts plugins).
- `ignore_sticky_posts = true` → Sticky posts silently disappear from secondary loops.
- `post_status = 'publish'` → Overrides plugins that query `draft`, `pending`, or custom statuses in secondary loops (e.g., WooCommerce draft products in admin previews).
- `has_password = false` → Silently excludes password-protected posts from all secondary loops.

**Fix:** Move the optimization settings **inside** the CPT-specific block, or add a guard that only applies them to queries the plugin explicitly owns:

```php
function frl_alter_query($query)
{
    if (!$query instanceof WP_Query || $query->is_main_query()) {
        return;
    }

    // Only optimize queries for our configured CPTs
    $cpts_list = frl_textlist_to_array(frl_get_option('custom_wp_query'));
    if (empty($cpts_list)) {
        return;
    }

    $cpts_list = array_column($cpts_list, 0);
    $post_type = $query->get('post_type');

    if ($post_type && in_array($post_type, $cpts_list, true)) {
        $query->set('update_post_meta_cache', false);
        $query->set('update_post_term_cache', false);
        $query->set('no_found_rows', true);
        $query->set('ignore_sticky_posts', true);
        $query->set('post_status', 'publish');
        $query->set('has_password', false);
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
    }
}
```

---

### C2. `reset_options_caches()` Calls `remove_all_filters()` on ALL `pre_option_frl_*` Filters

**File:** [`core/cache/class-cache-manager.php`](core/cache/class-cache-manager.php:1367-1379)  
**Severity:** CRITICAL — Destroys Environment Manager's `pre_option_*` overrides

**Problem:**
```php
foreach (array_keys($wp_filter) as $filter_name) {
    if (str_starts_with($filter_name, 'pre_option_' . $prefix)) {
        remove_all_filters($filter_name);
        $cleared++;
    }
}
```

When `frl_cache_clear('options')` is called (e.g., during cache flush, post save, or settings update), this loop **removes all filters** registered on `pre_option_frl_*` hooks. The Environment Manager registers `pre_option_*` filters for domain-based configuration overrides. If any of those options use the `frl_` prefix, their filters are silently destroyed, causing the site to revert to DB-stored values (wrong environment configuration).

**Impact:**
- On staging/dev subdomains, environment-specific options (siteurl, home, etc.) may revert to production values after any cache clear.
- The `frl_is_already_running(__CLASS__)` guard prevents re-entry within the same request, but the damage is done: filters are removed and never re-registered.

**Fix:** Only remove filters that the plugin itself registered, or use a more targeted approach:
```php
// Only remove filters registered by THIS plugin's option system
// Do NOT remove Environment Manager's pre_option_* filters
```
Or better: remove this block entirely — `wp_cache_delete('alloptions', 'options')` and `frl_get_option('__reset__')` already handle cache invalidation. The `remove_all_filters` call is redundant and destructive.

---

### C3. `with_auth_preservation()` Re-issues Auth Cookie on Every Cache Purge

**File:** [`core/cache/class-cache-manager.php`](core/cache/class-cache-manager.php:840-861)  
**Severity:** CRITICAL — Session security risk

**Problem:**
```php
private static function with_auth_preservation(callable $fn)
{
    $current_user = wp_get_current_user();
    $current_user_id = $current_user->ID;
    $auth_cookie = wp_parse_auth_cookie('', 'logged_in');

    $result = $fn();

    if ($current_user_id && $auth_cookie) {
        wp_set_auth_cookie($current_user_id, true);
        wp_set_current_user($current_user_id);
    }

    return $result;
}
```

This is called by `purge_all()` and `hard_cache_reset()`. The comment explains the intent: preserve auth state across cache operations. However:

1. `wp_set_auth_cookie($current_user_id, true)` with `true` (remember-me) **always** issues a new remember-me cookie, even if the user logged in without "remember me". This extends session lifetime unexpectedly.
2. If `$current_user_id` is 0 (no user logged in) but `$auth_cookie` is truthy (stale cookie from a logged-out session), the condition `if ($current_user_id && $auth_cookie)` correctly skips. But if a different user's cookie is in the request (e.g., due to cookie manipulation), `wp_get_current_user()` may return a different user than the cookie belongs to.
3. Calling `wp_set_auth_cookie()` inside a cache purge operation is a side effect that should not exist — cache operations should never touch authentication state.

**Fix:** Remove `with_auth_preservation()` entirely. If auth state corruption was observed during cache operations, the root cause should be fixed (e.g., the `alloptions` cache deletion). Re-issuing auth cookies is a security anti-pattern.

---

### C4. Subdomain Adapter `sync_default_lang()` Writes to Polylang's `polylang` Option on Every First Subdomain Visit

**File:** [`core/translator/adapters/polylang.php`](core/translator/adapters/polylang.php:153-168) + [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php:1134-1174)  
**Severity:** CRITICAL — Cross-subdomain DB corruption

**Problem:**

`Frl_Polylang_Adapter::set_default_language()` does a read-modify-write on the `polylang` option:
```php
$pll_options = get_option('polylang', []);
$pll_options['default_lang'] = $lang;
update_option('polylang', $pll_options);
```

This is called from `Frl_Subdomain_Adapter::sync_default_lang()` on the **first visit** to a mapped subdomain. The problem:

1. **Race condition:** If two requests hit the subdomain simultaneously (e.g., after a cache flush), both read the same `polylang` option, both modify `default_lang`, and both write. If any other Polylang setting was changed between the read and write by another process, it's silently lost.
2. **Cross-subdomain contamination:** The `polylang` option is **global** — it's not scoped per-subdomain. Writing `default_lang = 'ru'` on `ru.pbservices.ge` means that if the main domain (`pbservices.ge`) reads this option before the subdomain adapter's `pll_get_current_language` filter fires, it sees `default_lang = 'ru'` — wrong for the main domain.
3. **The `check_default_lang_mismatch` filter** at [`class-subdomain-adapter.php:1186`](modules/subdomain_adapter/class-subdomain-adapter.php:1186) re-triggers EM enforcement when the DB default doesn't match, creating a **feedback loop**: subdomain writes `default_lang = ru` → main domain reads it → EM detects mismatch → EM enforcement runs → may write it back → subdomain detects mismatch again.

**Impact:** Polylang's `default_lang` can become corrupted, causing wrong language detection, wrong URL generation, and potential redirect loops.

**Fix:** The `pll_get_current_language` filter approach (already implemented at [`class-subdomain-adapter.php:460`](modules/subdomain-adapter.php:460)) is the correct mechanism — it overrides the language at runtime without touching the DB. The `set_default_language()` DB write should be **removed** or gated behind an explicit admin action, not triggered automatically on first visit.

---

### C5. Rewriter `late_rescue()` Clears Polylang's `tax_query` — Breaks Language Filtering

**File:** [`core/rewriter/features/class-cpt-base-removal-feature.php`](core/rewriter/features/class-cpt-base-removal-feature.php:104-107)  
**Severity:** CRITICAL — Wrong language content served

**Problem:**
```php
// Polylang adds a language tax_query that forces is_tax=true; remove it so WP treats this as singular.
$query->set( 'taxonomy', '' );
$query->set( 'term', '' );
$query->set( 'tax_query', [] );
```

When the CPT Base Removal feature's `late_rescue()` fires, it **unconditionally clears** Polylang's language tax_query. This means:
1. On a multilingual site, a URL like `/ru/my-post/` resolves to the post, but Polylang's language filter is removed — the post could be in **any language**, not just RU.
2. If the post is actually in EN but the URL was accessed on the RU subdomain, the user sees English content at a Russian URL — **wrong content served**.
3. The `lang` query var is set by `resolve_request()`, but Polylang's `tax_query` does more than just set `lang` — it enforces that only posts in the current language are returned.

**Impact:** Wrong-language content served at translated URLs. SEO duplicate content issues. Potential for Google to index English content under Russian URLs.

**Fix:** Do not clear Polylang's tax_query. Instead, ensure `resolve_request()` sets the correct `lang` query var and let Polylang's existing language filtering handle the rest. If the tax_query causes `is_tax=true` instead of `is_singular`, the issue is in the query var structure, not in Polylang's filtering.

---

## 🟡 PERFORMANCE ISSUES

### P1. `frl_alter_query()` Fires on Every `pre_get_posts` — Even When Plugin Has No CPTs Configured

**File:** [`public/public.php`](public/public.php:14)  
**Severity:** MEDIUM

Even when `custom_wp_query` option is empty, the hook fires and the function runs, calling `frl_textlist_to_array()` and `frl_get_option()` on every non-main query. While these are cached, the hook registration itself is unconditional.

**Fix:** Register the hook only when the option is non-empty, or add an early return before any function calls:
```php
function frl_alter_query($query)
{
    if (!$query instanceof WP_Query || $query->is_main_query()) {
        return;
    }
    // Early exit: no CPTs configured
    $cpts_list = frl_textlist_to_array(frl_get_option('custom_wp_query'));
    if (empty($cpts_list)) {
        return;
    }
    // ... rest of optimization only for configured CPTs
}
```

---

### P2. Cache Manager `auto_preload()` Loads All Frontend Groups on Every Request

**File:** [`core/cache/class-cache-manager.php`](core/cache/class-cache-manager.php:81-97)  
**Severity:** MEDIUM

```php
public static function auto_preload()
{
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return false;
    }
    $groups_to_preload = frl_is_admin()
        ? FRL_CACHE_PRELOAD_BACKEND_GROUPS
        : FRL_CACHE_PRELOAD_FRONTEND_GROUPS;
    foreach ($groups_to_preload as $group) {
        self::preload_multi($group);
    }
    return true;
}
```

On sites **without** an object cache (transient fallback), `preload_multi()` for each group runs a `SELECT * FROM wp_options WHERE option_name LIKE ...` query. If multiple groups are preloaded, this is multiple full-table scans on `wp_options` on every frontend request.

**Fix:** Only preload when using object cache, or limit preloading to a single combined query for all groups when using transients.

---

### P3. Rewriter `transform_url()` Uses `frl_cache_remember()` Inside a `try/finally` — Lock Contention

**File:** [`core/rewriter/class-rewriter.php`](core/rewriter/class-rewriter.php:216)  
**Severity:** LOW

`frl_cache_remember()` acquires a lock via `wp_cache_add()`. On high-traffic sites with many concurrent first-time URL transformations, the lock mechanism causes `usleep()` delays (50ms → 100ms → 200ms) for up to 3 attempts before falling through to generate the value anyway.

**Fix:** For permalink caching (which is idempotent — multiple processes generating the same value is safe), consider using `frl_cache_get()` + callback + `frl_cache_set()` without locking, since the callback is deterministic.

---

### P4. `Frl_CPT_Base_Removal_Feature::applies_to_request()` and `resolve_request()` Duplicate DB Queries

**File:** [`core/rewriter/features/class-cpt-base-removal-feature.php`](core/rewriter/features/class-cpt-base-removal-feature.php:253-445)  
**Severity:** MEDIUM

Both `applies_to_request()` and `resolve_request()` have their own static caches (`$slug_hit_map`, `$multi_index`), but these are **method-scoped** — they don't share. The persistent `frl_cache_remember` layer is shared (same cache key), but on a cache miss, both methods run the same `get_posts()` query independently.

The flow is: `filter_request()` → `applies_to_request()` (DB query) → `resolve_request()` (same DB query again, different static cache).

**Fix:** Have `applies_to_request()` delegate to `resolve_request()` and check if the result is non-empty (this is already done in some features but not consistently).

---

## 🟠 PLUGIN CONFLICT RISKS

### PC1. `remove_all_filters('pre_option_*')` in `frl_update_option()` May Remove Other Plugins' Filters

**File:** [`includes/helpers/functions-options.php`](includes/helpers/functions-options.php:119)  
**Severity:** MEDIUM

```php
remove_all_filters('pre_option_' . $prefixed_key);
$result = update_option($prefixed_key, $normalized_value, $autoload);
```

If another plugin has registered a filter on `pre_option_frl_*` (e.g., for monitoring or debugging), it's silently removed every time `frl_update_option()` is called. This is the same pattern as C2 but at the option-write level.

**Fix:** Only remove filters registered by this plugin, tracked via a registry.

---

### PC2. Subdomain Adapter Hooks `post_type_link` at `PHP_INT_MAX` — Overrides All Other Plugins

**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php:397-400)  
**Severity:** LOW (by design, but worth noting)

The subdomain adapter registers URL filters at `PHP_INT_MAX` priority, guaranteeing it runs last. This is intentional ("final authority on URL structure for SEO"), but it means:
- Any plugin that hooks `post_type_link` at a lower priority and expects its output to be final will be silently overridden.
- Debugging URL issues becomes harder because the last filter is not visible in normal hook inspection.

**Mitigation:** This is acceptable for a production site where SEO consistency is the priority. Document this behavior clearly.

---

### PC3. MU Plugin Exclusion Modifies `active_plugins` at `pre_option` Level — May Confuse Health Check

**File:** [`includes/mu/functions-mu.php`](includes/mu/functions-mu.php:346-368)  
**Severity:** LOW

The `pre_option_active_plugins` filter returns a modified array with excluded plugins removed. WordPress's Site Health check and plugin admin pages read `active_plugins` — they will show excluded plugins as "active" (because the DB still has them) but they won't be loaded. This can confuse administrators.

**Mitigation:** The existing admin UI should display which plugins are currently excluded. This is a UX issue, not a bug.

---

## ✅ REWRITER/POLYLANG CORRUPTION ANALYSIS

### Can the rewriter store corrupted rewrite rules for pages in other languages?

**Short answer:** No, with one exception (C5 above).

**Detailed analysis:**

1. **Rule generation is config-driven, not data-driven.** Rules are generated from `FRL_REWRITER_MULTILINGUAL_CPT` + option values, not from runtime request data. A corrupted request cannot corrupt the rules.

2. **Rules are cached with a config hash.** [`class-rewriter-coordinator.php:309`](core/rewriter/class-rewriter-coordinator.php:309) — `generate_config_hash()` includes all option values. If options change, the hash changes, and old cached rules are never served.

3. **`clear_rewriter_caches()` fires on all relevant `update_option_*` hooks.** [`class-rewriter.php:451-461`](core/rewriter/class-rewriter.php:451-461) — Any option change triggers cache clearing + `flush_rewrite_rules(true)`.

4. **Duplicate pattern detection prevents silent overrides.** [`abstract-base-feature.php:242-283`](core/rewriter/features/abstract-base-feature.php:242-283) — Both intra-feature and cross-feature duplicate patterns are detected and logged.

5. **`prioritize_translated_cpt_rules()` ensures translated rules take precedence.** [`class-cpt-single-base-translation-feature.php:125-140`](core/rewriter/features/class-cpt-single-base-translation-feature.php:125-140) — Translated rules are prepended to the ruleset.

6. **Polylang's `clean_languages_cache()` is triggered via `update_option_permalink_structure`.** [`frl_flush_rewrite_rules()`](includes/plugin-lifecycle.php:172) mirrors `WP_Rewrite::set_permalink_structure()`, firing the hook Polylang listens to.

**The one risk:** Finding C5 — `late_rescue()` clearing Polylang's `tax_query` can cause the wrong-language post to be served at a translated URL. This is not "corrupted rewrite rules" but "corrupted query resolution" — the rules are correct, but the request filter overrides Polylang's language enforcement.

---

## 📊 SUMMARY TABLE

| ID | Severity | Category | File | Status |
|----|----------|----------|------|--------|
| C1 | CRITICAL | Bug | `public/public.php:433` | Unconditional query optimization breaks plugins |
| C2 | CRITICAL | Bug | `core/cache/class-cache-manager.php:1374` | `remove_all_filters` destroys EM overrides |
| C3 | CRITICAL | Security | `core/cache/class-cache-manager.php:856` | Auth cookie re-issued on cache purge |
| C4 | CRITICAL | Bug | `core/translator/adapters/polylang.php:166` | Polylang `default_lang` DB corruption |
| C5 | CRITICAL | Bug | `core/rewriter/features/class-cpt-base-removal-feature.php:104` | Polylang tax_query cleared — wrong language served |
| P1 | MEDIUM | Performance | `public/public.php:14` | Hook fires even when no CPTs configured |
| P2 | MEDIUM | Performance | `core/cache/class-cache-manager.php:81` | Preload runs full-table scans on no-cache sites |
| P3 | LOW | Performance | `core/rewriter/class-rewriter.php:216` | Lock contention on permalink cache |
| P4 | MEDIUM | Performance | `core/rewriter/features/class-cpt-base-removal-feature.php:253` | Duplicate DB queries in applies/resolve |
| PC1 | MEDIUM | Conflict | `includes/helpers/functions-options.php:119` | `remove_all_filters` on option write |
| PC2 | LOW | Conflict | `modules/subdomain_adapter/class-subdomain-adapter.php:397` | `PHP_INT_MAX` priority overrides all plugins |
| PC3 | LOW | Conflict | `includes/mu/functions-mu.php:346` | Excluded plugins appear active in admin |

---

## 🔧 RECOMMENDED FIX ORDER

1. **C1** — Move query optimization inside CPT check (highest impact, easiest fix)
2. **C5** — Stop clearing Polylang's tax_query in `late_rescue()`
3. **C2** — Remove `remove_all_filters` block from `reset_options_caches()`
4. **C4** — Remove automatic `set_default_language()` DB write; rely on `pll_get_current_language` filter
5. **C3** — Remove `with_auth_preservation()` or replace with non-cookie approach
6. **P1-P4** — Performance optimizations
7. **PC1-PC3** — Plugin conflict mitigations

---

*Audit performed by: Zoo (Architect mode)*  
*Files reviewed: 15+ core files across rewriter, cache, translator, environment, subdomain adapter, MU plugin, and public-facing code*
