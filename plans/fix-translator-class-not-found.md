# Fix: `Frl_Translation_Service` Class Not Found — Plan

## Bug Summary

**Error:** PHP Fatal error: Class `Frl_Translation_Service` not found in [`includes/helpers/functions-translation-helpers.php:81`](includes/helpers/functions-translation-helpers.php:81)

**Call chain:**
1. `admin-post.php` saves a plugin option → `update_option()` fires `updated_option`
2. [`cache-cleanup.php:20`](includes/core/cache/cache-cleanup.php:20) hooks `frl_clear_option_cache()` into `updated_option`
3. `frl_clear_option_cache()` calls `frl_get_active_languages()` (line 118)
4. [`frl_get_active_languages()`](includes/helpers/functions-translation-helpers.php:76) calls `frl_translator_is_enabled()` — which in theory should return `false` if the translator is disabled
5. But due to a **static cache staleness race**, `frl_get_option('disable_translator')` returns an outdated value → guard passes → `Frl_Translation_Service::get_instance()` → **Class not found**

## Root Cause

`frl_get_option()` uses a `static $options` cache populated during `plugins_loaded`. When `updated_option` fires *in the same request* that changes `disable_translator`, the static cache still holds the **old value**. The new value is in the database but `frl_get_option()` doesn't see it.

For the crash to occur specifically — `frl_translator_is_enabled()` must return `true` (meaning `disable_translator` is falsy in the cache), yet the class was never loaded (because at `plugins_loaded` time, `disable_translator` was truthy).

This is a classic **static cache poisoning** race condition:
- At `plugins_loaded`: `disable_translator = 1` in cache → translator NOT loaded
- Later: option updated to `0` → static cache NOT refreshed (only persistent cache is cleared)
- Even later: `updated_option` fires → `frl_get_option('disable_translator')` returns stale `1` → should still fail safe

**The most likely exact scenario**: Some other code path triggers `frl_get_option('__reset__')` or `frl_get_option(key, $bypass_cache=true)` mid-request, which reloads options from DB *after* the update, making the static cache reflect the new `0` value. Then `frl_translator_is_enabled()` returns `true` → but the class file was never `require_once`'d.

## Fix: Single Change in `frl_translator_is_enabled()`

**File:** [`includes/helpers/functions-translation-helpers.php`](includes/helpers/functions-translation-helpers.php)

**Function:** [`frl_translator_is_enabled()`](includes/helpers/functions-translation-helpers.php:20)

Add a `class_exists('Frl_Translation_Service')` check as the final safeguard:

```php
function frl_translator_is_enabled(): bool
{
    if (!frl_is_multilingual_active()) {
        return false;
    }
    if (frl_get_option('disable_translator')) {
        return false;
    }
    if (!class_exists('Frl_Translation_Service')) {
        return false;
    }
    return true;
}
```

This single change protects **all 11 helper functions** that call `Frl_Translation_Service::get_instance()` — because they all gate on `frl_translator_is_enabled()` first.

## Why This Fix

- **Single point of defense**: One `class_exists()` in the central guard protects every consumer
- **Zero behavioral change**: All consumers already return early/fallback when the guard returns `false`
- **Minimal overhead**: `class_exists()` for an already-loaded class is negligible (nanoseconds)
- **Minimal diff**: Only 3 lines added to one function
