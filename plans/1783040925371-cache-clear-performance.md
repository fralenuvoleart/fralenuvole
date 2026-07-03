# Plan: Cache Clear Performance Optimization

## Problem
Cache clear operations ("Clear Caches (All)", "Flush Rewrite Rules") take 5–20s. The delay comes from redundant work in `purge_all()` and `reset_options_caches()`, plus a cold admin page load triggering 16 redundant LIKE queries in `frl_get_all_plugin_transients()`.

## Patches

### Patch 1 — Remove dead `$wp_filter` iteration from `reset_options_caches()`

**File:** `core/cache/class-cache-manager.php`  
**Lines to remove:** 1372–1384 (the `global $wp_filter` block through `$stats['runtime'] += $cleared;`)  
**Lines to keep:** 1359 (`wp_cache_delete('alloptions', 'options')`), 1363 (`frl_get_option('__reset__')`), 1392 (`frl_is_already_running(__CLASS__, true)`)

```php
// REMOVE this entire block (lines ~1372–1384):
global $wp_filter;
if (!empty($wp_filter)) {
    $prefix = frl_prefix();
    $cleared = 0;
    foreach (array_keys($wp_filter) as $filter_name) {
        if (str_starts_with($filter_name, 'pre_option_' . $prefix)) {
            remove_all_filters($filter_name);
            $cleared++;
        }
    }
    $stats['runtime'] += $cleared;
}
```

**Rationale:** The `remove_all_filters('pre_option_frl_*')` calls are dead code. The closures are already:
- Removed by `frl_update_option()` at `functions-options.php:124` before every `update_option()`
- Removed by `frl_delete_option()` at `functions-options.php:164` before every `delete_option()`
- Bypassed by `frl_get_plugin_options_db()` which reads `$wpdb` directly, never via `get_option()`
- Not persisted across requests (anonymous closures created per-request)

**Speedup applies to:** All cache clear buttons ("Clear Caches (All)", "Clear Caches (Hard)", "Flush Rewrite Rules", any single-group clear touching `options`). P1 fires in "Flush Rewrite Rules" via: `frl_flush_rewrite_rules()` → `do_action('update_option_permalink_structure')` → `clear_rewriter_caches()` → `frl_cache_clear('options')` → `reset_options_caches()`.

---

### Patch 2 — Skip redundant dependency cascading in `purge_all()`

**File:** `core/cache/class-cache-manager.php`  
**Line:** 906

Change:
```php
$group_stats = self::clear_group_with_dependencies($group, null, true);
```
To:
```php
$group_stats = self::clear_group_with_dependencies($group, null, false);
```

**Rationale:** All dependency targets (`theme`, `html`, `environment`, `admin`, `adminui`, `rewriter`, `permalinks`, `metafields`) are also keys in `FRL_CACHE_TTL` and are reached by the outer `foreach`. The cascade causes redundant recursive `clear_group_with_dependencies()` calls that are guarded by `$groups_cleared` — but the recursion stack itself is unnecessary. `purge_light()` at line 1664 already passes `false` for the same reason.

**Speedup applies to:** "Clear Caches (All)" and "Clear Caches (Hard)" (both call `purge_all()`).

**Does NOT affect:** `clear_rewriter_caches()` — that path calls `frl_cache_clear('options')` which correctly uses its own dependency cascade to reach groups not otherwise touched.

---

### Patch 3 — Consolidate 16 LIKE queries into 1 in `frl_get_all_plugin_transients()`

**File:** `admin/helpers/functions-admin-ui.php`  
**Function:** `frl_get_all_plugin_transients()` (lines 413–537)

Replace the per-group loop (15 iterations, each with 4 LIKE conditions and `LIMIT 50`) and the base-prefix query (another 4 LIKE conditions with `LIMIT 50`) with a single query:

```sql
SELECT option_name, option_value FROM wp_options
WHERE option_name LIKE %s        -- '_transient_frl_%'
   OR option_name LIKE %s        -- '_transient_timeout_frl_%'
   OR option_name LIKE %s        -- '_site_transient_frl_%'
   OR option_name LIKE %s        -- '_site_transient_timeout_frl_%'
ORDER BY option_id DESC
LIMIT %d                         -- 800 (16 groups × 50)
```

Then group results in PHP by extracting the cache group from the option name (the segment after `_cache_`), and separate non-grouped entries (no `_cache_` in the path).

**Implementation approach:**
1. Remove the `foreach ($groups_to_query as $group)` loop (lines 429–462)
2. Remove the base-prefix query block (lines 464–513)
3. Replace with a single `$wpdb->get_results()` call using the consolidated query
4. Post-process: iterate the single result set and group entries by their cache group prefix using the same `str_starts_with` logic that the old code used to exclude grouped entries from the base query

**Rationale:** 16 round-trips to MySQL vs 1. On Kinsta with Redis for object cache, `_transient_frl_*` entries live in Redis, not `wp_options` — the MySQL table has very few matching rows, so the broader LIKE pattern is efficient. On sites without object cache, the `LIMIT 800` caps returned rows.

**Speedup applies to:** The admin page redirect after any cache clear that invalidates the `staticdata` group (All, Hard, or `staticdata` group clear). The `frl_get_all_plugin_transients()` result is cached in `staticdata` with `WEEK_IN_SECONDS` TTL.

---

## Validation

1. `php -l` syntax check on all 3 modified files
2. Click each cache clear button and verify:
   - Response time measurably reduced
   - No PHP errors, warnings, or notices in debug log
3. Verify admin cache display tab shows correct transient counts per group (same number of entries as before, just loaded faster)
4. Verify `frl_get_option()` returns correct values after any cache clear
5. Verify `wp_cache_delete('alloptions', 'options')` and `frl_get_option('__reset__')` remain in `reset_options_caches()` — these are the functional lines, not the dead `$wp_filter` block

## Risk Assessment

| Patch | Risk Level | Mitigation |
|-------|-----------|------------|
| P1 | Zero | Closures managed elsewhere; direct DB reads bypass get_option() filters |
| P2 | Zero | All cascade targets in outer loop; `$groups_cleared` prevents double-clear |
| P3 | Low | Broader LIKE pattern scans more index entries; `LIMIT 800` caps returned rows; Kinsta Redis means few rows in wp_options |
