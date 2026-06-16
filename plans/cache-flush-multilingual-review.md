# Cache Flush Multilingual Analysis — Final Verified Code Review

> **Review Date:** 2026-06-16  
> **Reviewer:** Architect mode — code-verified against live sources  
> **Document Under Review:** [`plans/cache-flush-multilingual-analysis.md`](plans/cache-flush-multilingual-analysis.md) (corrected version)  

---

## Executive Summary

The analysis correctly diagnoses the root cause of secondary-language 404 errors and proposes a valid fix. All inaccuracies found during review have been corrected in the analysis document. Verified against live source code of all four plugins: **Fralenuvole, Docket Cache v26.04.04, LiteSpeed Cache, Polylang**.

---

## 1. Verification of Original Claims (Pre-Correction)

### Claims Verified as Factually Correct ✅

| # | Claim | Source Evidence |
|---|-------|----------------|
| 1 | Docket `updated_option` handler defers `alloptions` deletion to shutdown | [`cache.php:2065-2071`](../docket-cache/includes/cache.php:2065) — `add_action('shutdown', ..., PHP_INT_MAX - 1)` |
| 2 | `wp_load_alloptions()` called inline during the action to check option membership | [`cache.php:2063`](../docket-cache/includes/cache.php:2063) — `$alloptions = wp_load_alloptions()` |
| 3 | Cached `alloptions` file survives until shutdown | Shutdown hook fires after request processing; `dc_remove()` at [`cache.php:1293`](../docket-cache/includes/cache.php:1293) deletes file |
| 4 | `_purge_all()` calls sub-purges and fires `litespeed_purged_all` at end | [`purge.cls.php:219-239`](../litespeed-cache/src/purge.cls.php:219) |
| 5 | `_purge_all_lscache()` sends `*` purge header | [`purge.cls.php:264-265`](../litespeed-cache/src/purge.cls.php:264) — `$this->_add('*')` |
| 6 | Docket listens to `litespeed_purged_all` for alloptions cleanup | [`cache.php:2037-2044`](../docket-cache/includes/cache.php:2037) |
| 7 | Polylang hooks `clean_languages_cache()` to `update_option_permalink_structure` | [`model.php:117-119`](../polylang/src/model.php:117) |
| 8 | `clean_languages_cache()` → `delete_transient('pll_languages_list')` | [`Languages.php:849-852`](../polylang/src/Model/Languages.php:849) |
| 9 | Button fires at `init:10` via `frl_process_plugin_actions` | [`logged-user.php:11-12`](includes/shared/logged-user.php:11): `add_action('init', 'frl_process_plugin_actions', 10)` |
| 10 | `clear_rewriter_caches()` hooks deferred inside `wp_loaded` callback | [`class-rewriter.php:449-451`](core/rewriter/class-rewriter.php:449): inner `add_action` inside outer `add_action('wp_loaded', ...)` |
| 11 | `did_action('wp_loaded')` fallback at line 193 does NOT call `clear_rewriter_caches()` | [`plugin-lifecycle.php:193-198`](includes/plugin-lifecycle.php:193): only `flush_rewrite_rules(true)` + `frl_thirdparty_maybe_notify()` |
| 12 | Fralenuvole outbound: `litespeed_purge_all` for `rewrite_flush` trigger | [`config-constants-thirdparty.php:82-88`](modules/thirdparty/config-constants-thirdparty.php:82): `'target' => 'litespeed_purge_all'`, `'triggers' => ['hard', 'rewrite_flush']` |
| 13 | LiteSpeed registers listener for `litespeed_purge_all` as trigger hook | [`api.cls.php:110`](../litespeed-cache/src/api.cls.php:110): `add_action('litespeed_purge_all', 'Purge::purge_all')` |
| 14 | `O_PURGE_HOOK_ALL` default is empty array `[]` | [`base.cls.php:413`](../litespeed-cache/src/base.cls.php:413): `self::O_PURGE_HOOK_ALL => []` |
| 15 | Docket `cachedir_flush()` has `static $is_done` guard | [`Filesystem.php:1009-1013`](../docket-cache/includes/src/Filesystem.php:1009) |
| 16 | Docket `cachedir_flush()` has 180s timeout | [`Filesystem.php:1035`](../docket-cache/includes/src/Filesystem.php:1035): `max_execution_time = $this->get_max_execution_time(180)` |
| 17 | Docket `dc_flush()` calls `cachedir_flush()` which iterates all files | [`cache.php:1257-1261`](../docket-cache/includes/cache.php:1257) → [`Filesystem.php:1045`](../docket-cache/includes/src/Filesystem.php:1045) |
| 18 | Docket `unlink()` uses exclusive file locking | [`Filesystem.php:463-468`](../docket-cache/includes/src/Filesystem.php:463): `flock` with `LOCK_EX | LOCK_NB` |

### Claims That Were Found Inaccurate (All Now Corrected) 🔧

| # | Original Claim | Correction Applied |
|---|---------------|-------------------|
| **I1** | `_purge_all_object()` calls `wp_cache_flush()` → Docket `dc_flush()` | **Fixed.** `_purge_all_object()` gates on `LSCWP_OBJECT_CACHE` ([`purge.cls.php:559`](../litespeed-cache/src/purge.cls.php:559)) and calls LiteSpeed's internal `Object_Cache::flush()` (Redis/Memcached connector), not `wp_cache_flush()`. When Docket is the drop-in, the guard blocks execution entirely. |
| **I2** | LiteSpeed Purge All → wp_cache_flush → clears ALL Docket files including Fralenuvole groups | **Fixed** (follows from I1). LiteSpeed does NOT call `wp_cache_flush()`. Docket is only affected via `litespeed_purged_all` → `delete('alloptions', 'options')` + two `litespeed_messages` keys. |
| **I3** | Transients stored in "Custom SQLite DB" | **Fixed.** SQLite (`TransientDb`) is conditional behind `$this->use_transientdb` flag ([`cache.php:1289`](../docket-cache/includes/cache.php:1289)). Default transient storage is file-based via `$this->fs()->unlink()`. |
| **I4** | LiteSpeed Purge All calls `dc_flush()` via `wp_cache_flush()` | **Fixed** (follows from I1). `dc_flush()` (O(n) full directory flush) is only triggered by explicit `wp_cache_flush()` calls. LiteSpeed does not make this call. |

---

## 2. Hook Distinction: `litespeed_purge_all` vs `litespeed_purged_all`

This distinction is critical and was not clearly made in the original analysis. Now corrected.

| Hook | Type | Registered At | Purpose |
|------|------|--------------|---------|
| `litespeed_purge_all` | **Trigger** (imperative) | [`api.cls.php:110`](../litespeed-cache/src/api.cls.php:110) | External callers fire this to **request** a purge. LiteSpeed listens via `Purge::purge_all()`. Fralenuvole fires this via `do_action('litespeed_purge_all')`. |
| `litespeed_purged_all` | **Notification** (past tense) | [`purge.cls.php:239`](../litespeed-cache/src/purge.cls.php:239) | LiteSpeed fires this **after** completing all purge operations. External listeners (like Docket) use this for post-purge cleanup. |

**Flow:** `Fralenuvole` → `do_action('litespeed_purge_all')` → `Purge::purge_all()` → `_purge_all_lscache/csjs/localres/object/opcache` → `do_action('litespeed_purged_all')` → `Docket::delete('alloptions', 'options')`

---

## 3. Minimal Event Chain Verification (Post-Fix)

### Question: Does the fixed button trigger only the minimal chain without redundancies?

**Answer: Yes.** Let me trace the complete event chain after the fix:

```
USER CLICK → frl_process_plugin_actions() [init:10]
  → frl_handle_action_flush_rewrite_rules()
    → frl_flush_rewrite_rules()
      
      ┌─ STEP 1: wp_cache_delete('alloptions', 'options')
      │  → Docket delete() → dc_remove() → unlink(file) 
      │  → O(1), synchronous, disk file deleted immediately
      │  → wp_load_alloptions() will now query DB ✓
      │
      ├─ STEP 2: $permastruct = get_option('permalink_structure')
      │  → wp_load_alloptions() → cache MISS → queries DB → FRESH DATA ✓
      │
      ├─ STEP 3: do_action('update_option_permalink_structure', ...)
      │  ├── Polylang: clean_languages_cache() → delete_transient('pll_languages_list') ✓
      │  ├── Docket updated_option: queues alloptions delete on SHUTDOWN (harmless — already deleted)
      │  └── clear_rewriter_caches(): NOT registered yet (wp_loaded hasn't fired) — non-critical
      │
      ├─ STEP 4: flush_rewrite_rules(true)
      │  → wp_load_alloptions() → DB → CORRECT rewrite_rules → regenerates ✓
      │  → update_option('rewrite_rules') → Docket queues another shutdown delete (harmless)
      │
      └─ STEP 5: frl_thirdparty_maybe_notify('rewrite_flush')
         → do_action('litespeed_purge_all')           [TRIGGER hook]
           → Purge::purge_all()
             → _purge_all_lscache() → '*' header      ✓ page cache purged
             → _purge_all_cssjs()                     ✓
             → _purge_all_localres()                  ✓
             → purge_all_opcache()                    ✓
             → _purge_all_object() → LSCWP_OBJECT_CACHE guard → NO-OP (Docket is drop-in)
           → do_action('litespeed_purged_all')         [NOTIFICATION hook]
             → Docket: delete('alloptions', 'options') — harmless, already deleted
             → Docket: delete('litespeed_messages', ...) — cleanup

SHUTDOWN:
  → Docket's queued alloptions deletions (from steps 3, 4): two harmless no-ops
```

### Redundancy Analysis

| Redundant Operation | Why Harmless | Weight |
|--------------------|-------------|--------|
| Docket `updated_option` queues `alloptions` delete on shutdown (step 3) | File already deleted in step 1; shutdown `unlink()` on missing file returns `true` immediately ([`Filesystem.php:457-459`](../docket-cache/includes/src/Filesystem.php:457)) | ~0 cost |
| `flush_rewrite_rules(true)` → `update_option('rewrite_rules')` → Docket queues another shutdown delete | Same — file gone, no-op | ~0 cost |
| `litespeed_purged_all` → Docket `delete('alloptions', 'options')` | Same — file gone, no-op | ~0 cost |
| `_purge_all_object()` → guarded NO-OP | `LSCWP_OBJECT_CACHE` not defined with Docket → returns `false` immediately | ~0 cost |

**Verdict: Zero meaningful redundancy.** All secondary alloptions deletions gracefully hit a missing-file fast path. The chain is minimal and identical in effect to the manual 2-click pattern (Save Permalinks + LiteSpeed Purge All).

---

## 4. Comparison: Manual Workaround vs Fixed Button

| Operation | Manual 2-Click | Fixed Button (1 Click) |
|-----------|---------------|----------------------|
| Delete `alloptions` cache before `flush_rewrite_rules()` | ✅ (via shutdown from Click 1) | ✅ (`wp_cache_delete` in step 1) |
| `flush_rewrite_rules(true)` with fresh DB data | ✅ (Click 2) | ✅ (step 4) |
| Polylang language cache cleared | ✅ (Click 1 & 2) | ✅ (step 3) |
| LiteSpeed page cache purged | ✅ (LiteSpeed Purge All button) | ✅ (step 5) |
| LiteSpeed CSS/JS/OPcache purged | ✅ | ✅ |
| Docket full `wp_cache_flush()` | NOT performed (not needed) | NOT performed (not needed) |
| Extra clicks required | 2× Save Permalinks + 2× LiteSpeed Purge | **1 click** |

The fixed button produces the **identical effective result** as the manual workaround, in a single click. Nothing extra is triggered. Nothing is missed.

---

## 5. Final Assessment

### Core Diagnosis: ✅ Correct

The root cause is Docket's deferred shutdown `alloptions` deletion. During the same request as `flush_rewrite_rules()`, the cached alloptions file is still on disk. `wp_load_alloptions()` returns stale data including old `rewrite_rules`, corrupting the newly generated rules.

### Proposed Fix: ✅ Correct

`wp_cache_delete('alloptions', 'options')` at the top of `frl_flush_rewrite_rules()` ([`plugin-lifecycle.php:184`](includes/plugin-lifecycle.php:184)) is:
- **Minimal:** One line, O(1) operation
- **Standard:** Uses WordPress Object Cache API, not plugin-specific
- **Sufficient:** This is the ONLY missing step proven by the 2-click workaround
- **Safe:** No side effects; subsequent shutdown-level deletes become harmless no-ops on missing files

### Analysis Trustworthiness: ✅ Trustworthy (After Corrections)

All 4 inaccuracies found during review have been corrected in the analysis document:
1. ✅ `_purge_all_object()` → `wp_cache_flush()` claim removed; now correctly shows the `LSCWP_OBJECT_CACHE` guard
2. ✅ "LiteSpeed chain clears Fralenuvole groups" claim removed; replaced with accurate behavior
3. ✅ Transient storage now described as "PHP files on disk (optional SQLite via TransientDb)"
4. ✅ Full `dc_flush()` claim from LiteSpeed Purge removed

The `litespeed_purge_all` (trigger) vs `litespeed_purged_all` (notification) distinction is now clearly documented.

### Minimal Chain After Fix: ✅ Verified

The fixed button produces exactly the same effects as Save Permalinks + LiteSpeed Purge All, with zero meaningful redundancy. All secondary operations are harmless no-ops on missing files.

---

## 6. Implementation Notes

To apply the fix, add ONE line to [`includes/plugin-lifecycle.php:184`](includes/plugin-lifecycle.php:184):

```php
function frl_flush_rewrite_rules(): void
{
    wp_cache_delete('alloptions', 'options');   // ← ADD THIS LINE

    $permastruct = get_option('permalink_structure');
    // ... rest unchanged
}
```

No other files need modification. No configuration changes required.
