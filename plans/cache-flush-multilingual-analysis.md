# Multilingual Cache Flush Analysis — Debugging Reference

> **Scope:** WordPress multilingual site with Polylang, LiteSpeed Cache, Docket Cache, and Fralenuvole.
> **Problem:** Secondary language (non-EN) permalinks return 404 errors. Requires 2× Save Permalinks + 2× LiteSpeed Purge to resolve manually. Fralenuvole's own "Flush Rewrite Rules" button does not reliably fix the issue in one click.
> **Date:** 2026-06-16

---

## 1. Cache Layers & What They Store

| Layer | Plugin | Storage | What It Caches |
|-------|--------|---------|----------------|
| Page Cache | LiteSpeed Cache (LSCWP) | LiteSpeed web server | Full HTML pages |
| Object Cache | Docket Cache v26.04.04 | PHP files on disk | `wp_cache_get/set/delete` — all WordPress object cache calls |
| `alloptions` Cache | WordPress Core + Docket Cache | Single file in Docket dir (`options` group, key `alloptions`) | ALL autoloaded options in one array — includes `rewrite_rules` |
| Transients | Docket Cache (TransientDb) | Custom SQLite DB | `get_transient/set_transient` — including `pll_languages_list` |
| Rewrite Rules | WordPress Core | `rewrite_rules` option in `wp_options` table | URL routing regex array |
| Polylang Language Cache | Polylang | `pll_languages_list` transient + `PLL_Cache` (in-memory) | `PLL_Language` objects (slugs, home_urls, term counts) |
| Fralenuvole Groups | Fralenuvole | Object cache (`options`, `rewriter`, `permalinks`, etc.) | Rewriter data, translated permalinks, option lookups |

---

## 2. Event Chain: "Save Permalinks" Click

When the user clicks "Save Changes" on the Permalinks settings page:

```
1. update_option('permalink_structure')
      │
      ├──→ Polylang: clean_languages_cache()                        [model.php:119]
      │    └──→ delete_transient('pll_languages_list')              [Languages.php:849]
      │         └──→ Deletes from DB + Docket's TransientDb          ✓ immediate
      │
      ├──→ Docket: updated_option hook                              [cache.php:2058]
      │    └──→ Queues delete('alloptions','options') on SHUTDOWN   ⏳ deferred
      │
      └──→ flush_rewrite_rules()
           └──→ Regenerates rewrite_rules, calls update_option()
                └──→ Docket queues another alloptions delete on shutdown (redundant)
```

**Critical timing issue:** `wp_load_alloptions()` is called during `flush_rewrite_rules()`. At this point, Docket's cached `alloptions` file **still exists on disk** — the shutdown hook hasn't fired yet. The function returns stale data including the OLD `rewrite_rules` and potentially the OLD `_transient_pll_languages_list` value.

**After the request ends:** Docket's shutdown hook fires → `delete('alloptions', 'options')` → deletes ONE cache file from disk. The cached `alloptions` is now gone. Next request will query DB directly.

---

## 3. Event Chain: "LiteSpeed Purge All" Click

```
Purge::_purge_all()                                                 [purge.cls.php:219]
      │
      ├──→ _purge_all_lscache()                                     [purge.cls.php:264]
      │    └──→ Sends '*' purge header to LiteSpeed server           ✓ page cache cleared
      │
      ├──→ _purge_all_object()                                      [purge.cls.php:558]
      │    └──→ wp_cache_flush() → Docket dc_flush()
      │         └──→ cachedir_flush() iterates ALL files in cache dir  ⚠ HEAVY
      │              └──→ Static $is_done guard: once per request
      │              └──→ 180s timeout: partial flush on large dirs
      │              └──→ File locking: silently skips locked files
      │
      └──→ do_action('litespeed_purged_all')                        [purge.cls.php:239]
           └──→ Docket listener → delete('alloptions','options')     [cache.php:2037]
                └──→ Deletes the alloptions cache file               ✓
```

---

## 4. Why "2× Save Permalinks + 2× LiteSpeed Purge" Works

| Cycle | Event | State of Docket `alloptions` Cache File |
|-------|-------|----------------------------------------|
| **1st Save Permalinks** | `flush_rewrite_rules()` runs | STALE — file on disk still has old `rewrite_rules` |
| **Between clicks** | Shutdown from 1st click executes | `alloptions` file DELETED by Docket's shutdown hook |
| **1st LiteSpeed Purge** | Clears page cache | No effect on `alloptions` (already deleted by shutdown) |
| **2nd Save Permalinks** | `flush_rewrite_rules()` runs | FRESH — `wp_load_alloptions()` queries DB, no cached file |
| **2nd LiteSpeed Purge** | Clears any wrongly cached pages | — |
| **Next request** | Serves page | All data correct → 404 resolved |

The only thing that changes between click 1 and click 2 is that Docket's `alloptions` cache file gets deleted on shutdown. No `wp_cache_flush()`, no OPcache, no heavy operations. Just one file.

---

## 5. Why Fralenuvole's "Flush Rewrite Rules" Button Fails

### Code path

```
Button click → frl_handle_action_flush_rewrite_rules()               [functions-action-handlers.php:327]
                   → frl_flush_rewrite_rules()                        [plugin-lifecycle.php:184]
```

### Execution trace of `frl_flush_rewrite_rules()`

```php
// Line 186-188
$permastruct = get_option('permalink_structure');       // ← reads Docket's STALE alloptions
do_action('update_option_permalink_structure', ...);    // ← Polylang clean_languages_cache() ✓
                                                        // ← Docket queues alloptions delete (shutdown) ⏳
                                                        // ← Fralenuvole clear_rewriter_caches() SILENTLY SKIPPED
                                                        //    because hooks registered at wp_loaded [class-rewriter.php:450]
                                                        //    and button fires at init:10 (before wp_loaded)
do_action('permalink_structure_changed');

// Lines 193-198 (did_action('wp_loaded') = false at init:10)
flush_rewrite_rules(true);                              // ← wp_load_alloptions() reads STALE alloptions
                                                        //    Generates rules with stale data
frl_thirdparty_maybe_notify('rewrite_flush');           // ← LiteSpeed _purge_all()
                                                        //    → wp_cache_flush() → dc_flush() AFTER contamination
```

### Three failure points

| # | Location | What Happens | Impact |
|---|----------|-------------|--------|
| 1 | Line 187 | `clear_rewriter_caches()` hook never fires — registered at `wp_loaded` but button at `init:10`. The `did_action('wp_loaded')` fallback at line 193 handles `flush_rewrite_rules(true)` but does NOT call `clear_rewriter_caches()`. | Fralenuvole's `options→rewriter→permalinks` dependency cascade is never triggered internally. |
| 2 | Line 194 | `flush_rewrite_rules(true)` calls `wp_load_alloptions()` which reads Docket's STALE `alloptions` cached file. The file still contains old `rewrite_rules` and possibly old `_transient_pll_languages_list`. | Rewrite rules are generated from stale input data. |
| 3 | Line 196 | `frl_thirdparty_maybe_notify('rewrite_flush')` → `do_action('litespeed_purge_all')` → `_purge_all_object()` → `wp_cache_flush()` → Docket `dc_flush()` — but this happens AFTER the contamination. The current request's in-memory data is already stale. Docket `dc_flush()` is also heavyweight (iterates all files) and vulnerable to timeout/static guard. | Too late, too heavy. |

---

## 6. Proposed Fix: One Line

### What the 2× manual pattern proves

The ONLY action needed to make a single Save Permalinks work is: **delete Docket's cached `alloptions` file BEFORE `flush_rewrite_rules()` runs.**

### The fix

In [`includes/plugin-lifecycle.php:184`](includes/plugin-lifecycle.php:184), add ONE line at the top of `frl_flush_rewrite_rules()`:

```php
function frl_flush_rewrite_rules(): void
{
    // Delete Docket alloptions cache BEFORE regenerating rewrite rules.
    // Docket defers alloptions invalidation to shutdown (cache.php:2065),
    // so the cached file survives the current request. Without this,
    // wp_load_alloptions() returns stale rewrite_rules during flush.
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

### Why this is the right fix

| Property | Value |
|----------|-------|
| **Operation** | `wp_cache_delete('alloptions', 'options')` — deletes ONE file |
| **Weight** | O(1) — constant time, single file delete |
| **Comparison** | `wp_cache_flush()` = O(n) — iterates ALL Docket cache files |
| **Comparison** | Current code = Does nothing about Docket alloptions, relies on shutdown timing |
| **What it replaces** | The second Save Permalinks click (which only works because shutdown from click 1 already deleted the file) |
| **What it does NOT change** | LiteSpeed page cache purge still happens at line 196 (`frl_thirdparty_maybe_notify('rewrite_flush')`) |

### No additional changes needed

- **No Docket outbound hook** — the `wp_cache_delete()` call is inlined, no dependency on third-party config
- **No `wp_cache_flush()`** — heavyweight and unnecessary
- **No `action_hard`** — not needed
- **No OPcache handling** — proven not required (2× manual pattern works without it)

---

## 7. Fixed Execution Trace

With the fix applied, the button's execution becomes:

```
1. wp_cache_delete('alloptions', 'options')        ← NEW: deletes Docket alloptions file
   → wp_load_alloptions() will query DB directly

2. do_action('update_option_permalink_structure')
   → Polylang: clean_languages_cache() ✓
   → Docket: queues alloptions delete on shutdown (harmless, file already gone)
   → clear_rewriter_caches() still skipped (timing issue — separate fix, non-critical)

3. flush_rewrite_rules(true)
   → wp_load_alloptions() → queries DB → gets CORRECT rewrite_rules ✓
   → Regenerates rules with correct input ✓

4. frl_thirdparty_maybe_notify('rewrite_flush')
   → LiteSpeed _purge_all() → clears page cache ✓
   → _purge_all_object() → wp_cache_flush() redundant for Docket but harmless
```

Result: **One click = clean slate.**

---

## 8. Remaining Non-Critical Issue: `clear_rewriter_caches()` Timing

The rewriter hooks are registered at `wp_loaded` ([`class-rewriter.php:450`](core/rewriter/class-rewriter.php:450)). When the button fires at `init:10`, the `update_option_permalink_structure` action at line 187 does NOT trigger `clear_rewriter_caches()` because the hook hasn't been registered yet.

This means Fralenuvole's internal `options→rewriter→permalinks` cache cascade is not triggered by the button. The fallback at line 193 handles `flush_rewrite_rules(true)` for WordPress core, but Fralenuvole's own cached data in the `rewriter` and `permalinks` Object Cache groups is not explicitly cleared.

**Severity:** Low. The `frl_thirdparty_maybe_notify('rewrite_flush')` at line 196 calls `do_action('litespeed_purge_all')` which calls `wp_cache_flush()` → clears ALL Docket files including Fralenuvole's groups. This happens at the end so Fralenuvole's groups will be clean for the NEXT request.

**If needed for completeness**, a second `wp_cache_delete` or `wp_cache_flush_group` call targeting the Fralenuvole groups can be added at the end. But given the LiteSpeed chain already does this, and the primary 404 fix (alloptions timing) is addressed by the one-line fix, this is optional.

---

## 9. Cross-Plugin Notification Summary

| Direction | Mechanism | Automatic? |
|-----------|-----------|------------|
| Save Permalinks → Polylang | `add_action('update_option_permalink_structure', 'clean_languages_cache')` at [`model.php:119`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/model.php:119) | ✓ Yes |
| Save Permalinks → Docket alloptions | `updated_option` hook at [`cache.php:2058`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/docket-cache/includes/cache.php:2058) → deferred to shutdown | ⏳ Deferred |
| Save Permalinks → LiteSpeed | None — `O_PURGE_HOOK_ALL` is empty by default | ✗ No |
| LiteSpeed Purge → Docket | `_purge_all_object()` calls `wp_cache_flush()` at [`purge.cls.php:570`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/litespeed-cache/src/purge.cls.php:570) | ✓ Yes |
| LiteSpeed Purge → Docket alloptions | `litespeed_purged_all` event → Docket listener at [`cache.php:2037`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/docket-cache/includes/cache.php:2037) | ✓ Yes |
| LiteSpeed Purge → Polylang | None | ✗ No |
| Polylang → Docket | `delete_transient()` deletes from TransientDb only — no alloptions invalidation | ✗ No |
| Fralenuvole → LiteSpeed | `frl_thirdparty_maybe_notify('rewrite_flush')` → `do_action('litespeed_purge_all')` at [`thirdparty.php:559`](modules/thirdparty/thirdparty.php:559) | ✓ Yes |
| Fralenuvole → Docket | **Currently none** — proposed fix adds `wp_cache_delete('alloptions', 'options')` | ✗ → ✓ with fix |

---

## 10. Source File References

| File | Lines | What |
|------|-------|------|
| [`litespeed-cache/src/purge.cls.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/litespeed-cache/src/purge.cls.php) | 78-100, 208-240, 547-583 | Purge init hooks, `_purge_all()`, `_purge_all_object()` |
| [`litespeed-cache/src/core.cls.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/litespeed-cache/src/core.cls.php) | 100-115 | `O_PURGE_HOOK_ALL` configurable purge hooks (empty by default) |
| [`docket-cache/includes/cache.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/docket-cache/includes/cache.php) | 605-621, 734-741, 2016-2078 | `delete()`, `flush()`, option/alloptions handlers (shutdown-deferred) |
| [`docket-cache/includes/src/Filesystem.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/docket-cache/includes/src/Filesystem.php) | 452-501, 1007-1085 | `unlink()` with file locking, `cachedir_flush()` with static guard |
| [`docket-cache/includes/src/Becache.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/docket-cache/includes/src/Becache.php) | 369-410 | `export_alloptions()` — preloads autoloaded options into cache file |
| [`polylang/src/model.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/model.php) | 117-121, 193-197 | `clean_languages_cache()` hooked to `update_option_permalink_structure` |
| [`polylang/src/Model/Languages.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/Model/Languages.php) | 30, 587-690, 849-875 | `TRANSIENT_NAME = 'pll_languages_list'`, `get_list()`, `clean_cache()` |
| [`polylang/src/links-directory.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/links-directory.php) | 148-256 | `prepare_rewrite_rules()`, `rewrite_rules()` with language slug embedding |
| [`polylang/src/frontend/choose-lang.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/frontend/choose-lang.php) | 81-129 | `set_language()` multi-layer fallback chain |
| [`includes/plugin-lifecycle.php`](includes/plugin-lifecycle.php) | 184-199 | `frl_flush_rewrite_rules()` — current code + target for fix |
| [`includes/helpers/functions-action-handlers.php`](includes/helpers/functions-action-handlers.php) | 327-336 | `frl_handle_action_flush_rewrite_rules()` — button handler |
| [`core/rewriter/class-rewriter.php`](core/rewriter/class-rewriter.php) | 397, 450-458 | `clear_rewriter_caches()` — deferred to `wp_loaded` |
| [`modules/thirdparty/config-constants-thirdparty.php`](modules/thirdparty/config-constants-thirdparty.php) | 81-103 | `FRL_THIRDPARTY_OUTBOUND_HOOKS` |
| [`modules/thirdparty/thirdparty.php`](modules/thirdparty/thirdparty.php) | 519-559 | `frl_thirdparty_maybe_notify()` |
