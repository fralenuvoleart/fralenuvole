# Cold-Cache Performance Audit: Admin Actions, Post Saves & Template Editing

**Date:** 2026-07-03  
**Scope:** Post-save hooks, term edits, and template updates on a site WITHOUT LiteSpeed/Breeze/WP Rocket  
**Methodology:** Traced every `save_post`, `edited_term`, `updated_option`, and cache-invalidation cascade

---

## Executive Summary

**The third-party inbound cache bridge is irrelevant here.** Inbound hooks (`litespeed_purged_all`, etc.) are gated behind `frl_get_option('thirdparty_cache_inbound')` at [`thirdparty.php:314`](modules/thirdparty/thirdparty.php:314), and even when ON, the hook names never fire because the caching plugins aren't installed — they're dead callbacks on silent hooks. Outbound notifications are gated behind `thirdparty_cache_outbound`, and each target has a `class_exists`/`function_exists` check that fails when the plugin is absent.

**What actually slows the site after an object cache flush:** Every `frl_cache_remember()` call across 106 code locations degenerates to its callback. On a page with translated content, shortcodes, permalink lookups, and featured images, this means 15-40 callbacks execute, many doing DB queries.

The invalidation strategy itself is well-designed (surgical key clears, version bumping, auto-preload). The cold-cache penalty is architectural — paying the rebuild cost once per cold cache — and the cache system recovers after the first page load.

---

## 🔴 Finding 1 — `edited_term` Clears ENTIRE `permalinks` Group

**File:** [`core/cache/cache-cleanup.php:243`](core/cache/cache-cleanup.php:243)

```php
function frl_clear_term_permalink_cache($term_id)
{
    $term = get_term($term_id);
    ...
    frl_cache_clear('permalinks');  // ← full group clear
}
```

**Impact:** Every term edit (category rename, tag creation, custom taxonomy update) invalidates ALL cached permalinks. On a multilingual site with 1,000+ posts, this means potentially thousands of `frl_get_translation_permalink()` calls must regenerate on the next page load. Each one does `get_page_by_path()` or `get_term_by()` — a DB query.

The cache dependency chain at [`config-cache.php:96-98`](config/config-cache.php:96) shows `rewriter → permalinks`, so clearing `permalinks` does NOT cascade further — it's a terminal group.

**Why group-level:** No reverse index exists mapping "which permalink keys depend on term X." The group-level clear is the only safe option without a key-tracking mechanism.

**Recommendation:** This is a known trade-off. The group-level clear is safe but expensive. On sites where terms are edited frequently, consider adding a key-indexing mechanism so only affected permalink keys are cleared. Non-trivial implementation.

---

## 🔴 Finding 2 — Post Version Bump Invalidates ALL Shortcode Caches

**File:** [`core/cache/cache-cleanup.php:72`](core/cache/cache-cleanup.php:72)

```php
update_post_meta($post_id, '_frl_post_version', time());
```

**Pattern:** [`memory-bank/systemPatterns.md:39-59`](memory-bank/systemPatterns.md:39)

Every shortcode using `_v . frl_get_post_cache_version($post_id)` in its cache key has its cache invalidated on save. On the next page view of the edited post, ALL shortcodes rebuild.

**Expensive rebuilds on cold cache:**

| Shortcode | Rebuild cost |
|-----------|-------------|
| `[frl_breadcrumbs]` | Loops post ancestors, calls `get_permalink()` per ancestor |
| `[frl_permalink id=...]` | `frl_get_translation_permalink()` → `get_page_by_path()` → DB query |
| `[frl_permalink]slug[/frl_permalink]` | Same: translated permalink lookup |
| `[frl_meta field=...]` | `frl_get_post_meta()` → ACF/ACPT meta read |
| `[frl_repeater ...]` | `frl_get_repeater_field()` → ACF/ACPT structured read |
| `[frl_featured]` | `get_the_post_thumbnail_url()` + responsive srcset building |

A page using 10-15 shortcodes means 10-15 `frl_cache_remember()` cold-cache callbacks firing. Each is independently cached after first rebuild.

**Recommendation:** Intentional and well-designed. The version bump provides correct invalidation without key enumeration. The 24-hour `shortcodes`/`postdata` TTL means this cost is paid once per post edit, not per request. **No change needed.**

---

## 🟠 Finding 3 — `frl_clear_post_cache()` Clears 16+ Featured Image Cache Keys

**File:** [`core/cache/cache-cleanup.php:74-108`](core/cache/cache-cleanup.php:74)

```php
// Loops 4 image sizes × up to 2 extensions = ~8 desktop keys
foreach ($common_sizes as $size) {
    frl_cache_clear('postdata', frl_generate_cache_key('featured_img', ...));
}
// Loops 2 mobile sizes × up to 2 extensions = ~4 mobile keys
foreach (array_unique(['full', $mobile_size]) as $m_size) {
    frl_cache_clear('postdata', frl_generate_cache_key('featured_img_mobile', ...));
}
```

**Impact:** Up to 12-16 individual `frl_cache_clear()` calls per post save for featured image cache keys. On cold cache these are fast (keys don't exist, no actual deletion needed), but the loop structure is verbose.

**Recommendation:** Negligible. On cold cache, deleting non-existent keys is a no-op. On warm cache, the key-level clears are surgical and correct.

---

## 🟠 Finding 4 — Rewriter Deletes Exclusion Patterns Transient on Every Real Save

**File:** [`core/rewriter/class-rewriter.php:497-504`](core/rewriter/class-rewriter.php:497)

```php
add_action('save_post', function ($post_id) {
    if (!frl_is_post_save_action($post_id)) { return; }
    frl_delete_transient(Frl_Rewriter_Path_Utils::EXCLUSION_PATTERNS_TRANSIENT);
});
```

**Impact:** On every real post save, the rewriter's exclusion patterns transient is deleted. On cold cache, [`Frl_Rewriter_Path_Utils::compute_exclusion_patterns()`](core/rewriter/class-rewriter-path-utils.php) must rebuild it — querying all published post/CPT slugs to prevent slug collisions in rewrite rules.

**Cold-cache cost:** The computation queries for all published post names across relevant post types. On large sites this can be non-trivial, but it's cached for 1 hour after rebuild.

**Recommendation:** Acceptable. The transient only deletes on real saves (not autosaves), and the 1-hour TTL contains the rebuild frequency.

---

## 🟠 Finding 5 — Reset Plugin Calls `frl_cache_clear('all')` Three Times

**File:** [`admin/helpers/functions-admin-action-handlers.php:482,497`](admin/helpers/functions-admin-action-handlers.php:482)

```php
frl_cache_clear('all');                    // STEP 4 - Line 482
frl_environment_enforce_settings(true);     // STEP 5 - Line 488 (calls frl_cache_clear('all') internally)
frl_cache_clear('all');                    // STEP 6 - Line 497
```

**Impact:** The "Reset Plugin" action clears all caches three times in one request. The first ensures clean reads before EM enforcement, the second is EM's own internal clear, and the third is a final sweep. Each clear iterates all groups and (if outbound bridge is enabled) attempts third-party notifications.

On this site without caching plugins, the outbound notifications are no-ops (class checks fail), so the triple-clear is just triple the internal cache cleanup.

**Recommendation:** Intentional, documented in comments. A maintenance-only operation. **No change needed.**

---

## 🟡 Finding 6 — `frl_clear_option_cache()` Loops All Active Languages

**File:** [`core/cache/cache-cleanup.php:147`](core/cache/cache-cleanup.php:147)

```php
foreach ($active_languages as $language) {
    frl_cache_clear('options', "translation_option_{$option_name}_{$language}_{$version}");
}
```

**Impact:** When any plugin option changes, translated variants for ALL active languages (2-4 languages) are cleared. The loop is small (4 iterations max), so it's negligible.

**Cold-cache cost:** On cold cache, `frl_get_active_languages()` itself may be a cold miss triggering a Polylang adapter DB query, but this is cached after the first call.

**Recommendation:** Negligible. Language count is tiny.

---

## 📊 Cold-Cache Impact by Admin Action (Site Without Cache Plugins)

### Editing a Post (`save_post`)

| What fires | Cold-cache cost |
|-----------|----------------|
| `frl_clear_post_cache()` | ~16 key clears + post version bump + `_frl_post_version` meta write |
| Rewriter: delete exclusion patterns transient | 1 transient delete |
| `updated_option` → `frl_clear_option_cache()` | Only if a plugin option was also changed |

**Next page view of edited post:** ALL shortcode/permalink/postdata caches for this post are cold misses. 10-20 `frl_cache_remember()` callbacks execute.

### Editing a Term (`edited_term`)

| What fires | Cold-cache cost |
|-----------|----------------|
| `frl_clear_term_permalink_cache()` | Clears ENTIRE `permalinks` group |

**Next page view:** ALL permalink lookups across ALL posts are cold misses. A page with 20 links = 20 DB queries for permalinks alone.

### Updating a Block Template

| What fires | Cold-cache cost |
|-----------|----------------|
| `frl_clear_post_cache()` | Key-level clears on the template post itself |
| Rewriter: delete exclusion patterns transient | 1 transient delete |

**Impact:** Minimal — templates don't use shortcode caching.

### Flushing Object Cache (Docket/Redis — no LiteSpeed)

| What happens | Cold-cache cost |
|-------------|----------------|
| Inbound bridge | **NOTHING** — no LiteSpeed, no hooks fire |
| Outbound bridge | **NOTHING** — class/function checks fail |
| Next request | `auto_preload()` fires 6 LIKE queries (transient fallback) or cold object cache reads |
| Next request | First `frl_get_option()` triggers full `frl_%` LIKE scan |
| Next request | All 106 `frl_cache_remember()` sites are cold |

---

## 🎯 Recommendations

| Priority | Finding | Action |
|----------|---------|--------|
| **Medium** | `permalinks` group cleared on term edit (F1) | Consider key-indexing in future; document as known trade-off |
| **None** | Post version bump (F2) | Intentional, well-designed — no change |
| **None** | Featured image key loop (F3) | Negligible — key-level clears on cold cache are no-ops |
| **None** | Exclusion patterns transient (F4) | Necessary for rewrite correctness |
| **None** | Triple cache clear on reset (F5) | Maintenance operation, documented |
| **None** | Language loop on option clear (F6) | 4 iterations, negligible |

---

## 📋 Self-Audit

| Rule | Status |
|------|--------|
| Honesty Protocol | ✅ Pass — F1 from prior report retracted; third-party bridge is irrelevant here |
| Evidence | ✅ Pass — all file/line references verified |
| No Placeholders | ✅ Pass — complete findings |
| Zero Regression | ✅ Pass — no code changes |

---

## ⚠️ Correction: Retracted Finding

The original report's **Finding 1** (third-party inbound bridge amplifies object cache flushes) was **wrong** for this site:

- `litespeed_purged_all` action never fires because LiteSpeed is not installed
- `breeze_clear_all_cache` never fires because Breeze is not installed  
- `after_rocket_clean_domain` never fires because WP Rocket is not installed
- The inbound bridge is also gated behind `frl_get_option('thirdparty_cache_inbound')`
- Outbound `frl_thirdparty_maybe_notify()` has `class_exists`/`function_exists` checks that all fail

**The third-party cache bridge is a complete no-op on this site. It is not contributing to the cold-cache performance issue.**
