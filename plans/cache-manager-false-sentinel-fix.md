# Cache Manager: `false` Sentinel Ambiguity Fix

## Problem

`Frl_Cache_Manager::get()` uses `false` as the sentinel value for "cache miss" in both the transient fallback path and the object cache path:

```php
// Transient path (line 567-571)
$data = get_transient(self::PREFIX . $cache_key);
if ($data !== false) {  // ← false = "cache miss" sentinel
    self::set_runtime($cache_key, $data, $group);
    return $data;
}

// Object cache path (line 576-580)
$data = wp_cache_get($cache_key, self::PREFIX . $group);
if ($data !== false) {  // ← false = "cache miss" sentinel
    self::set_runtime($cache_key, $data, $group);
    return $data;
}
```

This creates an ambiguity: **a cached `false` value is indistinguishable from "key doesn't exist."** Any callback that returns `false` will cause an infinite cache-miss cycle — the callback runs on every request, hits the DB/filesystem, returns `false` again, and re-caches it.

## Affected Callers (Audit of 85 `frl_cache_remember` usages)

### Confirmed Affected (return `false` from callback)

| # | File:Line | Callback returns `false` when... | Impact |
|---|-----------|----------------------------------|--------|
| 1 | [`admin/admin.php:325`](admin/admin.php:325) | Theme stylesheet doesn't exist | `file_exists()` runs every request |
| 2 | [`class-cpt-base-removal-feature.php:315`](core/rewriter/features/class-cpt-base-removal-feature.php:315) | No post found for slug | DB query runs every request |
| 3 | [`class-cpt-base-removal-feature.php:317`](core/rewriter/features/class-cpt-base-removal-feature.php:317) | No posts in `get_posts()` | DB query runs every request |
| 4 | [`functions.php:832`](includes/helpers/functions.php:832) | No post ID or empty taxonomy (early return, **never reaches cache**) | **NOT affected** — returns before `frl_cache_remember` |

### NOT Affected (return truthy values or `null`)

The remaining 81 callers return arrays, strings, objects, integers, or `null` — all distinguishable from `false`. Key patterns:

- **Array returns** (most common): `[]`, `['key' => 'value']`, etc. — truthy, safe
- **String returns**: URLs, HTML, slugs — truthy, safe
- **Object returns**: `WP_User`, `WP_Post`, etc. — truthy, safe
- **Integer returns**: IDs, timestamps — truthy (except `0`, but none return `0` from callbacks)
- **`null` returns**: `Frl_Cache_Manager::get()` explicitly skips caching for `null` at [line 589-591](core/cache/class-cache-manager.php:589) — safe by design

### Edge Case: `0` as a cached value

No caller was found returning `0` from a callback. If one existed, it would have the same ambiguity problem since `0 !== false` is `true` (so `0` would be cached correctly), but `if ($value !== null)` at [line 641](core/cache/class-cache-manager.php:641) would still store it. **`0` is safe** — only `false` and `null` are problematic.

## Proposed Fix

### Approach: Use a unique sentinel object instead of `false`

Add a private constant sentinel to `Frl_Cache_Manager`:

```php
/** Unique sentinel for cache misses — distinguishable from any cached value. */
private static $CACHE_MISS;

// Initialize once
private static function init_sentinel(): void {
    if (self::$CACHE_MISS === null) {
        self::$CACHE_MISS = new \stdClass();
    }
}
```

### Changes Required

#### 1. `Frl_Cache_Manager::get()` — Replace `false` sentinel checks

**Transient path** (line 567-571):
```php
// BEFORE
$data = get_transient(self::PREFIX . $cache_key);
if ($data !== false) {

// AFTER
$data = get_transient(self::PREFIX . $cache_key);
if ($data !== false || get_transient(self::PREFIX . $cache_key . '_exists') === '1') {
```

Wait — this won't work because `get_transient` is a WordPress core function we can't change. The real fix is at the **`set` level**: when storing a value, also store a companion key that signals "this key intentionally exists."

Actually, the simpler approach: **wrap all cached values in a container object**. This is the cleanest solution:

```php
// In set(): wrap value before storing
wp_cache_set($cache_key, ['_v' => $value], self::PREFIX . $group, $ttl);

// In get(): unwrap after retrieval
$raw = wp_cache_get($cache_key, self::PREFIX . $group);
if ($raw !== false && is_array($raw) && array_key_exists('_v', $raw)) {
    $data = $raw['_v'];
    // ... proceed with $data (which can be false, 0, null, etc.)
}
```

But this is a **breaking change** — all existing cached data would be invalidated on first read (cache miss → regenerate → store wrapped). That's acceptable for a one-time migration.

### Refined Approach: Value Wrapper Pattern

#### Step 1: Add wrapper/unwrap helpers to `Frl_Cache_Manager`

```php
/** Wrap a value for safe storage (distinguishes from cache miss). */
private static function wrap_value($value): array {
    return ['_frl_v' => $value, '_frl_exists' => true];
}

/** Unwrap a stored value. Returns null if not a valid wrapper. */
private static function unwrap_value($raw): mixed {
    if (is_array($raw) && isset($raw['_frl_exists'])) {
        return $raw['_frl_v'];
    }
    return null; // Not a wrapped value = cache miss
}
```

#### Step 2: Update `Frl_Cache_Manager::set()` to wrap values

At [line 515-530](core/cache/class-cache-manager.php:515):
```php
// BEFORE
self::set_runtime($cache_key, $value, $group);
if (self::is_object_cache_truly_functional()) {
    return wp_cache_set($cache_key, $value, self::PREFIX . $group, $ttl);
} elseif (self::use_transient_fallback($group)) {
    return set_transient(self::PREFIX . $cache_key, $value, $ttl);
}

// AFTER
$wrapped = self::wrap_value($value);
self::set_runtime($cache_key, $wrapped, $group);
if (self::is_object_cache_truly_functional()) {
    return wp_cache_set($cache_key, $wrapped, self::PREFIX . $group, $ttl);
} elseif (self::use_transient_fallback($group)) {
    return set_transient(self::PREFIX . $cache_key, $wrapped, $ttl);
}
```

#### Step 3: Update `Frl_Cache_Manager::get()` to unwrap values

At [line 551-580](core/cache/class-cache-manager.php:551):
```php
// Runtime cache check
$data = self::get_runtime($cache_key);
if ($data !== null) {
    $unwrapped = self::unwrap_value($data);
    if ($unwrapped !== null || (is_array($data) && isset($data['_frl_exists']))) {
        return $unwrapped;
    }
}

// Transient path
$data = get_transient(self::PREFIX . $cache_key);
if ($data !== false) {
    $unwrapped = self::unwrap_value($data);
    if ($unwrapped !== null || (is_array($data) && isset($data['_frl_exists']))) {
        self::set_runtime($cache_key, $data, $group);
        return $unwrapped;
    }
}

// Object cache path
$data = wp_cache_get($cache_key, self::PREFIX . $group);
if ($data !== false) {
    $unwrapped = self::unwrap_value($data);
    if ($unwrapped !== null || (is_array($data) && isset($data['_frl_exists']))) {
        self::set_runtime($cache_key, $data, $group);
        return $unwrapped;
    }
}
```

#### Step 4: Update `Frl_Cache_Manager::remember()` callback storage

At [line 640-643](core/cache/class-cache-manager.php:640):
```php
// BEFORE
$value = $callback();
if ($value !== null) {
    self::set($group, $key, $value, $ttl);
}

// AFTER (no change needed — set() now wraps automatically)
$value = $callback();
if ($value !== null) {
    self::set($group, $key, $value, $ttl);  // set() wraps internally
}
```

#### Step 5: Handle migration (existing unwrapped data)

On first read after deployment, existing cached data won't have the `_frl_exists` wrapper. The `unwrap_value()` function returns `null` for unwrapped data, which is treated as a cache miss — the callback regenerates and stores the wrapped version. **This is a one-time cache warm-up, not a regression.**

### Risk Assessment

| Risk | Mitigation |
|---|---|
| Existing cache data invalidated on first read | Acceptable — one-time warm-up, no data loss |
| External code reading cache directly | No external code reads `Frl_Cache_Manager` keys directly |
| Performance overhead of wrapping | Negligible — one array allocation per get/set |
| `null` values still skipped from caching | Preserved behavior — `null` means "don't cache" by design |

### Files to Modify

1. [`core/cache/class-cache-manager.php`](core/cache/class-cache-manager.php) — Add `wrap_value()`, `unwrap_value()`, update `get()`, `set()`, `remember()`
2. [`includes/helpers/functions.php`](includes/helpers/functions.php:847) — **Revert** the `false` → `[]` normalization in `frl_cf_get_post_terms()` since the cache manager now handles `false` correctly (optional — keeping it is also fine as defensive coding)
3. [`admin/admin.php`](admin/admin.php:325) — **No change needed** — cache manager fix handles it automatically

### Verification Plan

1. Unit test: Cache a `false` value, verify it's retrieved as `false` on next request
2. Unit test: Cache `0`, `''`, `[]`, `null` — verify each is handled correctly
3. Integration test: Load a page with no terms assigned — verify `frl_cf_get_post_terms()` returns `[]` without DB query on second load
4. Integration test: Load admin with missing theme stylesheet — verify `file_exists()` doesn't run on second load
