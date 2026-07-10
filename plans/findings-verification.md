# Findings Verification — Deep-Dive Analysis

## Summary

| # | Finding | Verdict | Severity | Fix Regression Risk |
|---|---------|---------|----------|---------------------|
| 1 | Cache lock ineffective without object cache | **REAL** | Medium | **NONE** |
| 2 | `serialize()` wasted on cache hits | **NOT A BUG** | — | N/A |
| 3 | `purge_all()` iterates "default" as group | **REAL** | Low | **NONE** |
| 4 | `$deferred_writes` is public | **REAL** | Low | Requires facade helper updates |
| 5 | Array-key path returns wrong shape | **REAL (dead code)** | None | **NONE** |
| 6 | 5-level depth cap in sanitizer | **REAL** | Low | **NONE** |
| 7 | HTML field type unescaped | **REAL** | Low | **NONE** |
| 8 | Magic number 4437 | **REAL** | Trivial | **NONE** |

---

## Finding 1: Cache lock ineffective without object cache

**File:** [`core/cache/class-cache-manager.php:476-482`](../core/cache/class-cache-manager.php:476)

**Evidence:**
- `remember()` uses `wp_cache_add($lock_key, 1, 'locks', self::LOCK_TTL)` for stampede protection.
- The `'locks'` group is **not** in [`FRL_CACHE_PERSISTENT_GROUPS`](../config/config-cache.php:19) → transient fallback never applies.
- When Redis/Memcached is absent, `wp_cache_add()` stores in WP's per-request memory only → zero cross-process atomicity.
- Two concurrent PHP workers both "acquire" the lock and both regenerate the value.

**Why wasn't this spotted before?** Most production WordPress sites with this plugin run Redis/Memcached. On sites without it, the symptom (duplicate regeneration) is invisible — no errors, no data corruption. The retry loop with `usleep()` still runs, adding latency with zero benefit.

**Fix:** Add an early-exit before the lock loop:
```php
// Stampede lock requires a real object-cache backend; skip when absent.
if ( ! self::is_object_cache_truly_functional() ) {
    $value = $callback();
    if ( $value !== null ) {
        self::set( $group, $key, $value, $ttl );
    }
    return $value;
}
```

**Regression risk: NONE.** The lock already does nothing in this scenario. Removing the wasted `usleep()`/retry loop is purely beneficial.

---

## Finding 2: `serialize()` wasted on cache hits — NOT A BUG

**Files:** [`public/shortcodes.php:165`](../public/shortcodes.php:165), [`:721`](../public/shortcodes.php:721), [`:1179`](../public/shortcodes.php:1179)

**Why this is NOT a bug:** All three `serialize()` calls are inside `md5(serialize(...))` to construct the cache key. The cache key **must** be computed before `frl_cache_remember()` is called — the lookup cannot happen without the key. There is no way to defer or skip this computation on a cache hit. The `json_encode()` alternative would also need to run before the lookup, offering no structural advantage.

**Action:** Add a brief comment at each site explaining the pattern:
```php
// serialize() inside md5() is cache-key materialization — runs before lookup, not wasted.
```

This prevents future reviewers from re-flagging this as an optimization opportunity.

---

## Finding 3: `purge_all()` iterates "default" as group

**File:** [`core/cache/class-cache-manager.php:734`](../core/cache/class-cache-manager.php:734)

**Evidence:**
- `foreach (array_keys(self::$default_ttls) as $group)` iterates all TTL keys, including `'default' => HOUR_IN_SECONDS` ([config-cache.php:54](../config/config-cache.php:54)).
- `'default'` is a TTL fallback, not a real cache group. It appears in zero group lists.
- **Correction to original finding:** No "unrecognized-group warnings" are triggered. `clear_group_with_dependencies()` has no warning/error emission for unknown groups.
- `purge_group_runtime('default')` returns 0 immediately (`!isset(self::$group_keys['default'])` — [line 1193](../core/cache/class-cache-manager.php:1193)).
- `purge_group_storage('default')` attempts a flush on an empty group — wasted but harmless.
- No dependency cascade (not in `FRL_CACHE_DEPENDENCIES`).
- No browser cache clear (not in `FRL_CACHE_BROWSER_GROUPS`).
- **Confirmed:** Zero callers use `'default'` as a cache group name anywhere in the codebase.

**Why wasn't this spotted before?** The waste is invisible — an empty flush and a dedup-bookkeeping entry. No errors, no performance impact anyone would notice.

**Fix:** Filter out the TTL-only key:
```php
foreach ( array_keys( self::$default_ttls ) as $group ) {
    if ( $group === 'default' ) {
        continue;
    }
    // ...
}
```

**Regression risk: NONE.** No code stores data in group `'default'`.

---

## Finding 4: `$deferred_writes` is public

**File:** [`core/cache/class-cache-manager.php:29`](../core/cache/class-cache-manager.php:29)

**Evidence:**
- `public static array $deferred_writes = array()` — the only public property in a class where all others are private.
- All external access goes through facade helpers in [`functions-class-helpers.php`](../includes/helpers/functions-class-helpers.php):
  - Line 218: `Frl_Cache_Manager::$deferred_writes` (getter)
  - Line 229: `Frl_Cache_Manager::$deferred_writes = array()` (clear)
  - Line 243: `Frl_Cache_Manager::$deferred_writes[$group][$key] = $value` (add)
- Internal uses in class-cache-manager.php: lines 712, 721, 1454.

**Why wasn't this spotted before?** The facade pattern IS intact — all external consumers go through helpers. The property is "public" but effectively accessed only through the intended API. This is a code-hygiene issue, not a functional bug.

**Fix:** Make `$deferred_writes` private and add three static methods:
- `add_deferred_write(string $group, string $key, mixed $value): void`
- `get_deferred_writes(): array`
- `clear_deferred_writes(): void`

Then update the three facade helpers in `functions-class-helpers.php` to call these methods instead of accessing the property directly.

**Regression risk: Requires coordinated change across 2 files** (class-cache-manager.php + functions-class-helpers.php). The 3 facade helpers must be updated simultaneously with the property visibility change. Internal class accesses (lines 712, 721, 1454) use `self::$deferred_writes` and would need to switch to `self::get_deferred_writes()` / `self::$deferred_writes = array()` → `self::clear_deferred_writes()`.

---

## Finding 5: Array-key path returns wrong shape — DEAD CODE

**File:** [`core/cache/class-cache-manager.php:377-383`](../core/cache/class-cache-manager.php:377)

**Evidence:**
- `get_cached_value()` at line 378 checks `is_array($key)` and returns `get_multi()` result (a map).
- Callers `get()` (line 421) and `remember()` (line 470) expect a scalar.
- **Critical discovery:** The facade helpers `frl_cache_get()` and `frl_cache_remember()` ([functions-class-helpers.php:75,121](../includes/helpers/functions-class-helpers.php:75)) both declare `string $key` — array keys are **impossible** through the facade.
- The only way to reach the array-key branch is by calling `Frl_Cache_Manager::get()` or `::remember()` directly with an array key — which nobody does.

**Why wasn't this spotted before?** The code path is unreachable. It's dead code from when the API originally accepted `string|array` before the facade helpers narrowed it to `string`.

**Fix:** Either:
1. Remove the dead `is_array($key)` branch and narrow the `$key` parameter type to `string`.
2. Or add a `@deprecated` note and leave it (harmless dead code).

**Regression risk: NONE.** Removing dead code can't cause regressions.

---

## Finding 6: `_frl_sanitize_recursive()` depth cap

**File:** [`includes/helpers/utilities.php:560`](../includes/helpers/utilities.php:560)

**Evidence:**
- `if ($depth > 5)` — depth starts at 0, so levels 0–5 (6 total) are traversed.
- At depth 6+, objects/arrays are silently replaced with placeholder strings.
- Called only from `frl_sanitize_for_serialization()` ([line 613](../includes/helpers/utilities.php:613)).
- `frl_sanitize_for_serialization()` is called only from `Frl_Cache_Manager::set()` and `::set_multi()` ([lines 258, 315](../core/cache/class-cache-manager.php:258)), and **only inside catch blocks** for `\Throwable` when `serialize()` fails.
- This means the depth cap only matters for values that are BOTH deeply nested (>6 levels) AND contain non-serializable elements (closures, resources).

**Why wasn't this spotted before?** The trigger condition is extremely rare: you need a value nested 7+ levels deep that also contains a closure or resource. Most cacheable values are plain arrays/scalars that `serialize()` handles fine.

**Fix:** Increase the depth cap (e.g., `$depth > 10`) and add an `error_log()` call when truncation happens.

**Regression risk: NONE.** Increasing the cap only affects the truncation threshold. Circular references would still be caught by `serialize()` throwing, not by this depth check.

---

## Finding 7: HTML field type unescaped in `render_field()`

**File:** [`admin/ui/class-ui-renderer.php:921-928`](../admin/ui/class-ui-renderer.php:921)

**Evidence:**
- The `'html'` case (line 919) outputs `$value` raw as `%5$s` inside `<textarea>`.
- The `'textarea'`/`'textlist'` case (lines 906-917) correctly uses `esc_textarea($value)`.
- Fields using `type => 'html'` ([config-options.php:378,405,777,802,827](../config/config-options.php:378)):
  - `header_html`, `footer_html` — both `restricted => true`
  - Three widget content fields — also `restricted`
- `$disabled` at line 822 comes from field config (`$field['disabled'] ?? ''`) — trusted source.

**Why wasn't this spotted before?** The `'html'` type is intentionally for raw HTML injection (header/footer scripts). Users with the `frl_manage_restricted` capability (admins) are the only editors. But this is inconsistent: the sibling `'textarea'` type escapes, and `'html'` should too for defense-in-depth.

**Fix:** Change line 927 from `$value` to `esc_textarea( $value )`.

**Regression risk: NONE.** `esc_textarea()` converts `<` to `<` etc., which displays correctly in a textarea regardless of content type. Admins editing raw HTML will see the HTML source code rendered as text inside the textarea, which is the correct textarea behavior.

---

## Finding 8: Magic number 4437

**File:** [`core/error-handler.php:155`](../core/error-handler.php:155)

**Evidence:**
- `if ($current_reporting === 0 || $current_reporting === 4437)` — literal `4437`.
- `FRL_PHP8_SUPPRESSED_ERROR_CODE = 4437` defined at [`config/config-base.php:120`](../config/config-base.php:120).
- Load order confirmed: `config.php` → `config-base.php` ([line 11](../config/config.php:11)) → `bootstrap.php` loads `config.php` ([line 37](../includes/bootstrap.php:37)) → `error-handler.php` ([line 45](../includes/bootstrap.php:45)). Constant IS available.

**Fix:** Replace `4437` with `FRL_PHP8_SUPPRESSED_ERROR_CODE`.

**Regression risk: NONE.** The constant has the identical value.

---

## Action Items (Priority Order)

1. **Fix #8** — trivial, immediate: replace `4437` with `FRL_PHP8_SUPPRESSED_ERROR_CODE` in [`error-handler.php:155`](../core/error-handler.php:155).
2. **Add comment for #2** — add a one-liner at each of the 3 `serialize()` sites in [`shortcodes.php`](../public/shortcodes.php) explaining the pattern.
3. **Fix #3** — add `if ($group === 'default') continue;` in the `purge_all()` loop.
4. **Fix #1** — add `!is_object_cache_truly_functional()` guard before the stampede lock in `remember()`.
5. **Fix #7** — add `esc_textarea($value)` to the `'html'` case in `render_field()`.
6. **Fix #5** — remove dead `is_array($key)` branch in `get_cached_value()` (optional, cosmetic).
7. **Fix #6** — increase depth cap + add warning log (optional).
8. **Fix #4** — make `$deferred_writes` private + add accessor methods (requires coordinated change across 2 files).
