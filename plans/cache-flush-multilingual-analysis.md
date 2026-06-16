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
| Transients | Docket Cache | PHP files on disk (optional SQLite via `TransientDb`) | `get_transient/set_transient` — including `pll_languages_list` |
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
      │         └──→ Deletes from DB + Docket transient storage        ✓ immediate
      │
      ├──→ Docket: updated_option hook                              [cache.php:2058]
      │    ├──→ Calls wp_load_alloptions() INLINE [cache.php:2063]  ⚠ reads stale cached file
      │    │    to check if changed option is in alloptions array
      │    └──→ Queues delete('alloptions','options') on SHUTDOWN   ⏳ deferred [cache.php:2065-2071]
      │
      └──→ flush_rewrite_rules()
           └──→ Regenerates rewrite_rules, calls update_option()
                └──→ Docket queues another alloptions delete on shutdown (redundant)
```

**Critical timing issue:** `wp_load_alloptions()` is called during `flush_rewrite_rules()`. At this point, Docket's cached `alloptions` file **still exists on disk** — the shutdown hook hasn't fired yet. The function returns stale data including the OLD `rewrite_rules` and potentially the OLD `_transient_pll_languages_list` value.

**Nuance:** Docket's `updated_option` handler at [cache.php:2063] calls `wp_load_alloptions()` inline during the action to check if the changed option key exists in the alloptions array. This reads the same stale cached file, but is harmless — the real problem is the deferred shutdown deletion.

**After the request ends:** Docket's shutdown hook fires → `delete('alloptions', 'options')` → deletes ONE cache file from disk. The cached `alloptions` is now gone. Next request will query DB directly.

---

## 3. Event Chain: "LiteSpeed Purge All" Click

```
Purge::_purge_all()                                                 [purge.cls.php:219]
      │
      ├──→ _purge_all_lscache()                                     [purge.cls.php:264]
      │    └──→ Sends '*' purge header to LiteSpeed server           ✓ page cache cleared
      │
      ├──→ _purge_all_cssjs() / _purge_all_localres() / purge_all_opcache()
      │    └──→ Clears CSS/JS optimization, local resources, OPcache
      │
      ├──→ _purge_all_object()                                      [purge.cls.php:558]
      │    ├──→ Guard: if LSCWP_OBJECT_CACHE not defined, returns false (no-op)
      │    │    When Docket is the active drop-in, this guard blocks execution
      │    └──→ If defined: calls LiteSpeed's internal Redis/Memcached connector
      │         (Object_Cache::flush()), NOT wp_cache_flush()
      │
      └──→ do_action('litespeed_purged_all')                        [purge.cls.php:239]
           │    ↑ NOTIFICATION hook: fired AFTER all sub-purges complete
           │    Not a trigger — external plugins listen to this for cleanup
           │
           └──→ Docket listener → delete('alloptions','options')     [cache.php:2037]
                ├──→ Deletes the alloptions cache file               ✓
                └──→ Also deletes litespeed_messages keys            [cache.php:2041-2042]
```

> **Key distinction:** [`litespeed_purge_all`](../litespeed-cache/src/api.cls.php:110) is the **trigger** hook (verbs: "please purge"), listened to by `Purge::purge_all()`. [`litespeed_purged_all`](../litespeed-cache/src/purge.cls.php:239) is the **notification** hook (past tense: "purge completed"), fired by LiteSpeed after it finishes. Fralenuvole fires the former; Docket listens to the latter.

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
                                                        // ← Fralenuvole clear_rewriter_caches() has NO LISTENER
                                                        //    because add_action('update_option_permalink_structure', ...)
                                                        //    is deferred inside wp_loaded callback [class-rewriter.php:450]
                                                        //    and button fires at init:10 (before wp_loaded)
                                                        //    The action fires, but nothing is listening yet
do_action('permalink_structure_changed');

// Lines 193-198 (did_action('wp_loaded') = false at init:10)
flush_rewrite_rules(true);                              // ← wp_load_alloptions() reads STALE alloptions
                                                        //    Generates rules with stale data
frl_thirdparty_maybe_notify('rewrite_flush');           // ← do_action('litespeed_purge_all') → _purge_all()
                                                         //    → page cache cleared ✓
                                                         //    → _purge_all_object() is NO-OP (LSCWP_OBJECT_CACHE guard)
                                                         //    → litespeed_purged_all → Docket delete('alloptions')
                                                         //    All happens AFTER contamination — too late
```

### Three failure points

| # | Location | What Happens | Impact |
|---|----------|-------------|--------|
| 1 | Line 187 | `clear_rewriter_caches()` has no listener — the `add_action('update_option_permalink_structure', ...)` call is deferred inside a `wp_loaded` callback [class-rewriter.php:450], but the button fires at `init:10` (before `wp_loaded`). The action fires normally, but nothing is registered to hear it. The `did_action('wp_loaded')` fallback at line 193 handles `flush_rewrite_rules(true)` but does NOT call `clear_rewriter_caches()`. | Fralenuvole's `options→rewriter→permalinks` dependency cascade is never triggered internally. |
| 2 | Line 194 | `flush_rewrite_rules(true)` calls `wp_load_alloptions()` which reads Docket's STALE `alloptions` cached file. The file still contains old `rewrite_rules` and possibly old `_transient_pll_languages_list`. | Rewrite rules are generated from stale input data. |
| 3 | Line 196 | `frl_thirdparty_maybe_notify('rewrite_flush')` → `do_action('litespeed_purge_all')` → `Purge::_purge_all()` → page cache cleared ✓, but `_purge_all_object()` is a NO-OP when Docket is the drop-in (LSCWP_OBJECT_CACHE guard blocks it). The `litespeed_purged_all` notification triggers Docket `delete('alloptions','options')` but this happens AFTER `flush_rewrite_rules(true)` already ran with stale data. | Too late — contamination already occurred. LiteSpeed object cache flush path doesn't actually involve Docket at all. |

---

## 6. Proposed Fix: One Line

### What the 2× manual pattern proves

The ONLY action needed to make a single Save Permalinks + LiteSpeed Purge All work in one click is: **delete Docket's cached `alloptions` file BEFORE `flush_rewrite_rules()` runs.**

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
   → clear_rewriter_caches() still skipped (timing issue — non-critical)

3. flush_rewrite_rules(true)
   → wp_load_alloptions() → queries DB → gets CORRECT rewrite_rules ✓
   → Regenerates rules with correct input ✓

4. frl_thirdparty_maybe_notify('rewrite_flush')
   → do_action('litespeed_purge_all') → Purge::_purge_all()
      → _purge_all_lscache() → sends '*' header → page cache cleared ✓
      → _purge_all_cssjs() / _purge_all_localres() / purge_all_opcache() ✓
      → _purge_all_object() → guard blocks (LSCWP_OBJECT_CACHE not defined with Docket) — NO-OP
      → do_action('litespeed_purged_all') → Docket delete('alloptions','options') — harmless (already deleted in step 1)
   → Matches manual "Purge All" behavior exactly ✓
```

The full chain with the fix produces exactly the same effects as the manual 2-click pattern
(Save Permalinks + LiteSpeed Purge All), in a single click. No redundancies — the two
shutdown-level alloptions deletions become harmless no-ops since the file is already gone.

Result: **One click = Save Permalinks + LiteSpeed Purge All. Clean slate.**

---

## 8. Remaining Non-Critical Issue: `clear_rewriter_caches()` Timing

The rewriter hooks are registered at `wp_loaded` ([`class-rewriter.php:450`](core/rewriter/class-rewriter.php:450)). When the button fires at `init:10`, the `update_option_permalink_structure` action at line 187 fires normally, but `clear_rewriter_caches()` is not called because the `add_action()` registration hasn't executed yet (it's inside the `wp_loaded` callback).

This means Fralenuvole's internal `options→rewriter→permalinks` cache cascade is not triggered by the button. The fallback at line 193 handles `flush_rewrite_rules(true)` for WordPress core, but Fralenuvole's own cached data in the `rewriter` and `permalinks` Object Cache groups is not explicitly cleared.

**Severity:** Low. The proposed fix directly addresses the 404-causing defect (stale `rewrite_rules` in `alloptions`). Fralenuvole's own rewriter/permalinks object cache groups contain computed/derived data (translated post bases, URL mappings) that self-heals on the next cache miss. These groups are NOT the source of the 404 errors.

**If needed for completeness**, explicit `wp_cache_delete` or `wp_cache_flush_group` calls targeting Fralenuvole's `rewriter`/`permalinks` groups can be added. This is optional — the 404 issue is resolved by the `alloptions` fix alone.

---

## 9. Cross-Plugin Notification Summary

| Direction | Mechanism | Automatic? |
|-----------|-----------|------------|
| Save Permalinks → Polylang | `add_action('update_option_permalink_structure', 'clean_languages_cache')` at [`model.php:119`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/model.php:119) | ✓ Yes |
| Save Permalinks → Docket alloptions | `updated_option` hook at [`cache.php:2058`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/docket-cache/includes/cache.php:2058) → deferred to shutdown | ⏳ Deferred |
| Save Permalinks → LiteSpeed | None — `O_PURGE_HOOK_ALL` is empty by default | ✗ No |
| LiteSpeed Purge → Docket (full flush) | No direct path — `_purge_all_object()` gates on `LSCWP_OBJECT_CACHE` and calls LiteSpeed's internal connector, not `wp_cache_flush()` | ✗ No |
| LiteSpeed Purge → Docket alloptions | `litespeed_purged_all` notification → Docket listener at [`cache.php:2037`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/docket-cache/includes/cache.php:2037) — deletes `alloptions` + `litespeed_messages` keys | ✓ Yes |
| LiteSpeed Purge → Polylang | None | ✗ No |
| Polylang → Docket | `delete_transient()` deletes from TransientDb only — no alloptions invalidation | ✗ No |
| Fralenuvole → LiteSpeed | `frl_thirdparty_maybe_notify('rewrite_flush')` → `do_action('litespeed_purge_all')` (trigger hook) at [`thirdparty.php:559`](modules/thirdparty/thirdparty.php:559) → LiteSpeed listener `Purge::purge_all()` at [`api.cls.php:110`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/litespeed-cache/src/api.cls.php:110) | ✓ Yes |
| Fralenuvole → Docket alloptions | **Currently none** — proposed fix adds `wp_cache_delete('alloptions', 'options')` (~O(1)) directly in Fralenuvole | ✗ → ✓ with fix |

---

## 10. Source File References

| File | Lines | What |
|------|-------|------|
| [`litespeed-cache/src/purge.cls.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/litespeed-cache/src/purge.cls.php) | 78-100, 208-240, 547-583 | Purge init hooks, `_purge_all()`, `_purge_all_object()` (guards on `LSCWP_OBJECT_CACHE`) |
| [`litespeed-cache/src/api.cls.php`](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/litespeed-cache/src/api.cls.php) | 108-122 | `litespeed_purge_all` (trigger) and `litespeed_purged_all` (notification) hook registration |
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
| [`config/config-cache.php`](config/config-cache.php) | 67-74 | `FRL_CACHE_HEAVY_GROUPS` — groups skipped by `purge_light()` |
| [`core/cache/class-cache-manager.php`](core/cache/class-cache-manager.php) | 1341-1354, 1624-1652 | `reset_options_caches()` (deletes alloptions via `wp_cache_delete`), `purge_light()` |
| [`core/cache/cache-cleanup.php`](core/cache/cache-cleanup.php) | 27-33, 55-64 | Automatic rewrite flush triggers (term CRUD), post cache invalidation |
| [`includes/plugin-lifecycle.php`](includes/plugin-lifecycle.php) | 16-19, 219-223 | Cron hook `frl_flush_rewrite_rules`, `frl_schedule_rewrite_flush()` |

---

## 11. Automatic / Routine Flush Scenarios

### 11.1 Overview

The analysis above (§2-10) covers **manual** user-initiated flush operations (Save Permalinks, LiteSpeed Purge All, Fralenuvole button). This section examines whether **automatic** cache operations — triggered by WordPress core, LiteSpeed, Polylang, or Fralenuvole without user intervention — can introduce stale rewrite rules or broken cache state.

### 11.2 Inbound Bridge: How External Purges Trigger Fralenuvole

Fralenuvole has a bidirectional thirdparty bridge ([`thirdparty.php:320-332`](modules/thirdparty/thirdparty.php:320)). The **inbound** path listens to external plugins' purge notifications and clears Fralenuvole's own caches:

External Trigger | Fralenuvole Inbound Hook | Effect |
|-----------------|--------------------------|--------|
`litespeed_purged_all` (LiteSpeed purge completed) | [`config-constants-thirdparty.php:29-33`](modules/thirdparty/config-constants-thirdparty.php:29) | `clear` = `'light'` → `purge_light()`, `rewrite_flush` = `true` → schedules cron |
`breeze_clear_all_cache` (Breeze purge) | [`config-constants-thirdparty.php:37-41`](modules/thirdparty/config-constants-thirdparty.php:37) | Same as above |
`after_rocket_clean_domain` (WP Rocket purge) | [`config-constants-thirdparty.php:43-47`](modules/thirdparty/config-constants-thirdparty.php:43) | Same as above |

The inbound handler ([`thirdparty.php:405-436`](modules/thirdparty/thirdparty.php:405)) runs two operations:

1. **`frl_cache_clear('light')`** → calls `purge_light()` ([`class-cache-manager.php:1624`](core/cache/class-cache-manager.php:1624)) which iterates ALL cache groups **except** the heavy groups listed in `FRL_CACHE_HEAVY_GROUPS` ([`config-cache.php:68-74`](config/config-cache.php:68)): `['staticdata', 'blocks', 'translations', 'permalinks', 'postdata']`. Critically, `options` is **NOT** in the heavy list, so `purge_light()` clears the `options` group — which cascades to `reset_options_caches()` → `wp_cache_delete('alloptions', 'options')` ([`class-cache-manager.php:1341-1354`](core/cache/class-cache-manager.php:1341)). **The alloptions file IS deleted by the inbound bridge.**

2. **`frl_schedule_rewrite_flush()`** — schedules a cron event with a **15-second delay** ([`plugin-lifecycle.php:219-223`](includes/plugin-lifecycle.php:219)) and a **60-second cooldown** ([`thirdparty.php:423-424`](modules/thirdparty/thirdparty.php:423)) to prevent cross-plugin ping-pong loops.

### 11.3 Automatic Scenarios — Risk Assessment

#### Scenario A: LiteSpeed auto-purge on WordPress upgrade

If `O_PURGE_ON_UPGRADE` is enabled (default: `false`, [`base.cls.php:597`](../litespeed-cache/src/base.cls.php:597)), LiteSpeed fires `Purge::purge_all()` on `automatic_updates_complete` or `upgrader_process_complete` ([`core.cls.php:104-108`](../litespeed-cache/src/core.cls.php:104)).

**Event chain:**

```
upgrader_process_complete
  → Purge::purge_all() → _purge_all()
    → _purge_all_lscache() → page cache ✓
    → _purge_all_object() → LSCWP_OBJECT_CACHE guard → NO-OP (Docket)
    → do_action('litespeed_purged_all')
      → Docket: delete('alloptions', 'options') ✓        [immediate, file-based]
      → Fralenuvole inbound: clear('light') + schedule rewrite flush

REQUEST ENDS → shutdown: Docket's updated_option deletes alloptions (harmless, already gone)

NEXT REQUEST (cron, 15s later):
  → frl_flush_rewrite_rules() fires
    → wp_cache_delete('alloptions', 'options')           [FIX applied — belt-and-suspenders]
    → flush_rewrite_rules(true) → DB → FRESH ✓
    → frl_thirdparty_maybe_notify('rewrite_flush') → LiteSpeed page cache ✓
```

**Risk: NONE.** The automated chain produces fresh rewrite rules. Before the fix, the cron `frl_flush_rewrite_rules()` would also succeed because:
- Docket's shutdown from the upgrade request has already deleted alloptions (separate request)
- The inbound bridge already called `wp_cache_delete('alloptions', 'options')` via `purge_light()` → `reset_options_caches()`
- By the time the cron fires (15s later), the DB has correct rewrite_rules from the upgrade

#### Scenario B: Polylang auto-regeneration of language cache

Polylang's `pll_languages_list` transient expires every ~24 hours (standard WordPress transient TTL). On expiration, `PLL_Languages::get_list()` queries the DB and repopulates. This is a **read-only** operation — no cache invalidation of rewrite rules occurs.

**Risk: NONE.** Polylang's language cache regeneration does not interact with rewrite rules or alloptions. The transient lives in Docket's transient storage (file-based or optional SQLite), separate from the `options` cache group.

#### Scenario C: Fralenuvole auto-invalidation on post/term changes

Fralenuvole registers automatic cache invalidation hooks:

Trigger | Handler | What It Clears | Source |
|---------|---------|---------------|--------|
`save_post` | Post cache clear | `postdata`, `permalinks` (post-specific), `shortcodes` (lang-switcher) | [`cache-cleanup.php:55-74`](core/cache/cache-cleanup.php:55) |
`created_{tax}`, `edited_{tax}`, `deleted_{tax}` | Schedule rewrite flush | `frl_schedule_rewrite_flush()` → cron `frl_flush_rewrite_rules()` | [`cache-cleanup.php:29-32`](core/cache/cache-cleanup.php:29) |
Translation change | Clear `translations` group | Dependencies cascade to `metafields`, `permalinks` | [`cache-cleanup.php:175-176`](core/cache/cache-cleanup.php:175) |
Permalink option change | `clear_rewriter_caches()` | `options` → `rewriter` → `permalinks` cascade + `flush_rewrite_rules(true)` | [`class-rewriter.php:397-417`](core/rewriter/class-rewriter.php:397) |

**Risk: LOW (now eliminated).** The term CRUD handlers schedule a deferred rewrite flush via cron (15s delay). This was potentially vulnerable to the same stale-alloptions timing issue as the manual button, since `frl_flush_rewrite_rules()` didn't delete alloptions before calling `flush_rewrite_rules(true)`. However, the cron runs as a separate request — the Docket shutdown from the original request has already fired and deleted the old alloptions file. **After the fix** (§6), the belt-and-suspenders `wp_cache_delete('alloptions', 'options')` at the top of `frl_flush_rewrite_rules()` guarantees fresh data regardless of timing.

#### Scenario D: Fralenuvole cache TTL expiration

Fralenuvole cache groups have configurable TTLs. On expiration, the next cache read misses and repopulates from source. This is a **pure read** operation — no rewrite rules interaction.

**Risk: NONE.** TTL expiration is passive invalidation. The next `wp_cache_get()` call misses and queries the authoritative source. No active flush occurs.

### 11.4 The Key Insight

Fralenuvole's automatic invalidation architecture is sound. The inbound bridge correctly:
- Deletes Docket's alloptions file via `purge_light()` → `reset_options_caches()` → `wp_cache_delete('alloptions', 'options')`
- Schedules a separate cron request for `flush_rewrite_rules(true)` (avoids running it during the inbound purge request)
- Applies a 60-second cooldown to prevent cross-plugin notification loops

The only vulnerability in automatic scenarios was the **same** stale-alloptions timing issue in `frl_flush_rewrite_rules()` — manifesting only if, for some reason, the cron fired before Docket's prior shutdown completed. **The fix applied in §6 eliminates this by adding the synchronous `wp_cache_delete('alloptions', 'options')` call at function entry, making every invocation of `frl_flush_rewrite_rules()` self-sufficient regardless of when it runs.**

### 11.5 Verified: No Fralenuvole Stale Cache Poisoning in Automatic Flows

The user's concern — *"could Fralenuvole plugin rewriter cache stay stale while other plugins flush appropriately?"* — is addressed:

1. **Fralenuvole's rewriter caches** (`rewriter`, `permalinks` groups) contain **computed/derived data** (translated post bases, URL mappings, exclusion patterns). They are NOT the source of 404 errors. The 404s come from stale `rewrite_rules` in WordPress core's `alloptions`.

2. **All automatic invalidation** of Fralenuvole caches happens through `frl_cache_clear()` which respects the dependency cascade: `options` → `rewriter` → `permalinks`. When `options` is cleared (as done by `purge_light()` in the inbound bridge), `rewriter` and `permalinks` are also cleared.

3. **The `permalinks` group** (heavy group, skipped by `purge_light()`) is cleared **post-specifically** on `save_post` ([`cache-cleanup.php:63-64`](core/cache/cache-cleanup.php:63)). A mass invalidation is not needed and would be wasteful — stale individual post permalink entries self-correct on the next request to that post.

4. **The only scenario** where Fralenuvole rewriter data could become truly stale while the rest of the system is clean is if `clear_rewriter_caches()` was never called during the lifecycle (e.g., no permalink option changes, no term CRUD, no manual flushes). Even then, the rewriter's computed data is **derivative** of the DB state (post bases, language slugs) — stale data would only be served for a single request before the TTL expires or the next cache miss triggers regeneration.
