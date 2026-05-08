# Subdomain Adapter — Code Review Fixes

## Summary

Implementation plan for fixes identified during code review of `modules/subdomain_adapter/`. All changes are surgical, low-risk, and independently verifiable.

---

## Fix 1: Remove Dead "No Queried Object" Redirect Block

**Severity:** Medium (dead code, misleading)
**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)
**Lines:** 699-711

### Why

The safety net filter [`filter_pll_current_language`](modules/subdomain_adapter/class-subdomain-adapter.php:328) (priority 2) forces Polylang's current language to always equal `$this->current_subdomain_lang` on subdomains. `frl_get_language()` reads this forced value. Therefore `$current_lang !== $this->current_subdomain_lang` is **always false** — the redirect never fires.

### Correct Behavior

Author archives, date archives, and post type archives should render locally on the subdomain. Polylang's internal `pre_get_posts` filter already restricts queries to the current language, so visitors see only content in the subdomain's language. No redirect is needed.

### Action

Remove lines 699-711 (the entire "no queried object" block including its comment). The `WP_Post` and `WP_Term` branches above it remain unchanged — they use `frl_get_language($obj->ID)` which reads the *object's* language, not the request language, so they work correctly.

### Before/After

```php
// REMOVE this entire block:
        // Handle queries with no queried object (author archives, date archives,
        // post type archives) where the current request language doesn't match
        // the subdomain.
        $current_lang = frl_get_language();
        if ($current_lang && $current_lang !== $this->current_subdomain_lang) {
            $redirect_url = $this->transform_url(
                home_url($_SERVER['REQUEST_URI']),
                $current_lang
            );
            add_filter('x_redirect_by', fn() => 'Frl_Subdomain_Adapter', 999);
            wp_redirect($redirect_url, 301);
            exit;
        }
    }
```

---

## Fix 2: Rename Config Key `'default'` → `'default_lang'`

**Severity:** Low (clarity improvement)
**Files affected:** 3

### Why

The key name `'default'` is ambiguous — it could mean "default behavior" or "default settings". `'default_lang'` unambiguously means "the default language for this main domain." Internally the class already uses `$default_lang` and `$main_default` as variable names; only the config constant key lags behind.

### Changes

#### 2a. Config constant

**File:** [`modules/subdomain_adapter/config-constants-subdomain-adapter.php`](modules/subdomain_adapter/config-constants-subdomain-adapter.php)

- Line 19: Update comment: `'default_lang' key specifies the default language`
- Line 33: `'default' => 'en',` → `'default_lang' => 'en',`
- Line 37: `'default' => 'en',` → `'default_lang' => 'en',`

#### 2b. Class references

**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)

- Line 157: `isset($config['default'])` → `isset($config['default_lang'])`
- Line 159: `if ($lang === 'default')` → `if ($lang === 'default_lang')`
- Line 376: `$this->domain_map[$resolve_domain]['default']` → `$this->domain_map[$resolve_domain]['default_lang']`
- Line 596: `$lang_map['default']` → `$lang_map['default_lang']`

#### 2c. Remove misleading `$content_lang !== 'default'` check

**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)
**Line:** 594

`$content_lang` is a language slug (e.g., `'en'`, `'ru'`) from `frl_get_language()`. It is never the literal string `'default'`. The check `$content_lang !== 'default'` is always true — dead code that confuses readers.

```php
// BEFORE:
$target_subdomain = ($content_lang !== 'default' && isset($lang_map[$content_lang]))
    ? $lang_map[$content_lang] : null;

// AFTER:
$target_subdomain = isset($lang_map[$content_lang])
    ? $lang_map[$content_lang] : null;
```

#### 2d. Documentation

**File:** [`docs/SUBDOMAIN-ADAPTER.md`](docs/SUBDOMAIN-ADAPTER.md)

- Line 43: `'default' => 'en',` → `'default_lang' => 'en',`
- Line 47: `'default' => 'en',` → `'default_lang' => 'en',`
- Line 55: Update description: `'default_lang' specifies the language with no URL prefix`
- Lines 142-151: Update all examples in "Adding a New Language Subdomain" section
- Lines 165-169: Update staging domain example

---

## Fix 3: Add Static Cache to `frl_get_language()`

**Severity:** Enhancement (performance)
**File:** [`includes/helpers/functions-translation-helpers.php`](includes/helpers/functions-translation-helpers.php)
**Lines:** 46-56

### Why (verified against source)

A full audit of [`Frl_Translation_Service`](includes/core/translator/class-translation-service.php) reveals that **every** helper function in this file is already cached at some level — except one path:

| Helper function | Service method | Cache? | Evidence |
|----------------|---------------|--------|----------|
| `frl_translator_is_enabled()` | *(none)* | N/A | Checks constants + `frl_get_option` (has own layers) |
| `frl_is_multilingual()` | [`is_multilingual()`](includes/core/translator/class-translation-service.php:87) | ✅ | `$is_multilingual_cache` at [line 91](includes/core/translator/class-translation-service.php:91) |
| `frl_get_language(null)` | [`get_language()`](includes/core/translator/class-translation-service.php:115) | ✅ | `$language_cache` at [line 117](includes/core/translator/class-translation-service.php:117) |
| **`frl_get_language($id)`** | **[`get_object_language()`](includes/core/translator/class-translation-service.php:631)** | **❌** | **No `$object_language_cache` property exists. Calls adapter directly every time.** |
| `frl_get_default_language()` | [`get_default_language()`](includes/core/translator/class-translation-service.php:141) | ✅ | `$default_language_cache` at [line 143](includes/core/translator/class-translation-service.php:143) |
| `frl_get_active_languages()` | [`get_active_languages()`](includes/core/translator/class-translation-service.php:154) | ✅ | `$active_languages_cache` at [line 156](includes/core/translator/class-translation-service.php:156) |
| `frl_get_translation()` | [`get_translation()`](includes/core/translator/class-translation-service.php:169) | ✅ | `frl_cache_remember` at [line 179](includes/core/translator/class-translation-service.php:179) |
| `frl_get_translation_block()` | [`get_translation_block()`](includes/core/translator/class-translation-service.php:195) | ✅ | `frl_cache_remember` at [line 216](includes/core/translator/class-translation-service.php:216) |
| `frl_get_translation_permalink()` | [`get_translation_batch_permalinks()`](includes/core/translator/class-translation-service.php:396) | ✅ | `static $request_cache` at [line 399](includes/core/translator/class-translation-service.php:399) |
| `frl_get_translation_batch_permalinks()` | *(same)* | ✅ | `static $request_cache` |
| `frl_process_permalink_patterns()` | [`process_permalink_patterns()`](includes/core/translator/class-translation-service.php:828) | ✅ | `frl_cache_remember` at [line 837](includes/core/translator/class-translation-service.php:837) |
| `frl_get_post_translations()` | [`get_post_translations()`](includes/core/translator/class-translation-service.php:597) | ✅ | `frl_cache_remember` at [line 600](includes/core/translator/class-translation-service.php:600) |
| `frl_get_term_translations()` | [`get_term_translations()`](includes/core/translator/class-translation-service.php:613) | ✅ | `frl_cache_remember` at [line 619](includes/core/translator/class-translation-service.php:619) |

The `get_object_language()` method at [line 631](includes/core/translator/class-translation-service.php:631) has **zero caching** — it calls `detect_post_language()` → `$this->adapter->get_post_language()` → `pll_get_post_language()` on every invocation. While `pll_get_post_language()` internally hits WordPress object cache for the meta value, the full call chain (helper → service → adapter → Polylang → object cache) still executes.

On a page with 50 links, the subdomain adapter calls `frl_get_language($post->ID)` 50 times.

### Design Decision: Fix at Service Level, Not Helper Level

The helper functions in [`functions-translation-helpers.php`](includes/helpers/functions-translation-helpers.php) are thin wrappers — their job is to provide a procedural API with a `frl_translator_is_enabled()` guard. All caching belongs in [`Frl_Translation_Service`](includes/core/translator/class-translation-service.php) where:

- It's centralized (one file to audit, one place to manage)
- All callers benefit (helpers, direct service calls, internal cross-calls)
- Cache invalidation is managed in one place
- The caching strategy is visible in property declarations

Adding a static cache to the helper would create two cache layers for the same data — a source of stale-data bugs and memory waste. The service already has `$language_cache` for the `$id === null` path; it just needs the same treatment for the `$id !== null` path.

### Action

**File:** [`includes/core/translator/class-translation-service.php`](includes/core/translator/class-translation-service.php)

**Step 1:** Add property declaration after line 37 (after `$active_languages_cache`):

```php
private array $object_language_cache = [];
```

**Step 2:** Replace [`get_object_language()`](includes/core/translator/class-translation-service.php:631) with:

```php
public function get_object_language(int $id, string $type = 'post'): string
{
    $key = "{$type}:{$id}";
    if (array_key_exists($key, $this->object_language_cache)) {
        return $this->object_language_cache[$key];
    }
    if ($type === 'term') {
        return $this->object_language_cache[$key] = $this->detect_term_language($id);
    }
    return $this->object_language_cache[$key] = $this->detect_post_language($id);
}
```

Uses `array_key_exists()` (not `isset()`) because `''` is a valid return when no language is assigned.

**No changes to [`frl_get_language()`](includes/helpers/functions-translation-helpers.php:46) helper** — it remains a thin wrapper and benefits automatically from the service-level cache.


---

## Fix 4: Add Error-Condition Debug Logging

**Severity:** Enhancement (debuggability)
**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)

### Why

The module is completely silent. When something goes wrong (missing config, failed URL parse), there's no trace. Adding conditional `frl_log()` calls gated behind `WP_DEBUG` helps developers diagnose issues without flooding production logs.

### What to log (error conditions only — NOT successful operations)

| Location | Condition | Log message |
|----------|-----------|-------------|
| [`detect():148`](modules/subdomain_adapter/class-subdomain-adapter.php:148) | Constant not defined or empty after cast | `"Subdomain Adapter: FRL_SUBDOMAIN_ADAPTER_MAP not defined or empty"` |
| [`transform_url():578`](modules/subdomain_adapter/class-subdomain-adapter.php:578) | `$this->subdomain_info` missing for current host | `"Subdomain Adapter: Missing subdomain info for host {host}"` |
| [`transform_url():607`](modules/subdomain_adapter/class-subdomain-adapter.php:607) | `wp_parse_url()` returns no host | `"Subdomain Adapter: Failed to parse URL {url}"` |
| [`filter_pll_get_home_url():355`](modules/subdomain_adapter/class-subdomain-adapter.php:355) | Subdomain info missing | `"Subdomain Adapter: Missing subdomain info in home_url filter for {host}"` |
| [`redirect_non_target_content():667`](modules/subdomain_adapter/class-subdomain-adapter.php:667) | Subdomain info missing | `"Subdomain Adapter: Missing subdomain info in redirect for {host}"` |

### Pattern

```php
if (defined('WP_DEBUG') && WP_DEBUG) {
    frl_log('Subdomain Adapter: Missing subdomain info for host {host}', [
        'host' => $this->current_subdomain_host,
    ]);
}
```

---

## Fix 5: Fix Potential TypeError in `filter_pll_get_home_url`

**Severity:** Low (unreachable in practice, but type system violation)
**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)
**Lines:** 352-356

### Why

The method declares `: string` return type, but Polylang's `pll_get_home_url` filter passes `string|false`. The defensive early returns pass through `$url` directly — if `$url` is `false`, this violates the return type.

In practice these paths are unreachable (subdomain info is always set when `is_on_subdomain()` is true; hooks only register on recognized domains), but the type system doesn't know that.

### Action

Change the two early returns to handle `false`:

```php
// Line 356:
if (!isset($this->subdomain_info[$this->current_subdomain_host])) {
    return is_string($url) ? $url : '';
}

// Line 362:
} else {
    return is_string($url) ? $url : '';
}
```

---

## Fix 6: Consolidate DRY — Merge Identical URL Filters

**Severity:** Low (code quality)
**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)
**Lines:** 441-501

### Why

[`filter_post_link`](modules/subdomain_adapter/class-subdomain-adapter.php:441), [`filter_post_type_link`](modules/subdomain_adapter/class-subdomain-adapter.php:464), and [`filter_page_link`](modules/subdomain_adapter/class-subdomain-adapter.php:487) have identical logic — only the hook name differs. This is ~60 lines of duplicated code.

### Action

Extract the common logic into a private method, keep the three public methods as thin wrappers:

```php
/**
 * Shared logic for post_link, post_type_link, and page_link filters.
 *
 * @param  string   $link The permalink.
 * @param  \WP_Post $post The post object.
 * @return string
 */
private function filter_post_link_internal(string $link, \WP_Post $post): string {
    if (!$this->should_transform()) {
        return $link;
    }
    $content_lang = frl_get_language($post->ID);
    if (empty($content_lang)) {
        return $link;
    }
    return $this->transform_url($link, $content_lang);
}

public function filter_post_link(string $link, $post): string {
    if (!$post instanceof \WP_Post) {
        return $link;
    }
    return $this->filter_post_link_internal($link, $post);
}

public function filter_post_type_link(string $link, $post): string {
    if (!$post instanceof \WP_Post) {
        return $link;
    }
    return $this->filter_post_link_internal($link, $post);
}

public function filter_page_link(string $link, $post): string {
    if (!$post instanceof \WP_Post) {
        return $link;
    }
    return $this->filter_post_link_internal($link, $post);
}
```

---

## Fix 7: Extract Repeated Arrow Function Closure

**Severity:** Low (minor overhead, code quality)
**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)
**Lines:** 680, 693, 708

### Why

The identical `fn() => 'Frl_Subdomain_Adapter'` closure is created 3 times in [`redirect_non_target_content()`](modules/subdomain_adapter/class-subdomain-adapter.php:659). Each creates a new `Closure` object. After Fix 1 removes one instance, 2 remain.

### Action

Add a private static method and reference it:

```php
/**
 * Returns the redirect-by header value for wp_redirect().
 *
 * @return string
 */
private static function get_redirect_by(): string {
    return 'Frl_Subdomain_Adapter';
}
```

Replace all instances of:
```php
add_filter('x_redirect_by', fn() => 'Frl_Subdomain_Adapter', 999);
```
with:
```php
add_filter('x_redirect_by', [self::class, 'get_redirect_by'], 999);
```

---

## Fix 8: Add Explicit `is_404()` Check

**Severity:** Low (code clarity)
**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)
**Line:** 659 (top of `redirect_non_target_content`)

### Why

The intent "404s are not redirected" is documented in the docblock and external docs, but not expressed in code. An explicit check makes the behavior self-documenting and guards against future changes that might accidentally redirect 404s.

### Action

Add after the `is_on_subdomain()` check:

```php
public function redirect_non_target_content(): void {
    if (!$this->is_on_subdomain()) {
        return;
    }
    if (is_404()) {
        return; // 404s render locally on the subdomain
    }
    // ... rest of method
}
```

---

## Fix 9: Sanitize `$_SERVER['REQUEST_URI']` Usage

**Severity:** Low (defense-in-depth)
**File:** [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php)
**Lines:** 677, 690

### Why

`$_SERVER['REQUEST_URI']` is raw user input. While `home_url()` and `wp_redirect()` handle URL encoding, stripping null bytes and control characters is a sensible defense-in-depth measure.

### Action

Add a helper and use it:

```php
/**
 * Safely get the current request URI.
 *
 * @return string
 */
private function get_request_uri(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    // Strip null bytes and control characters.
    $uri = preg_replace('/[\x00-\x1F\x7F]/', '', $uri);
    return $uri;
}
```

Replace `$_SERVER['REQUEST_URI']` with `$this->get_request_uri()` in the two remaining redirect blocks (after Fix 1 removes the third).

---

## Fix 10: Consolidate Translation Service Caching — Batch Methods

**Severity:** Low (consistency)
**File:** [`includes/core/translator/class-translation-service.php`](includes/core/translator/class-translation-service.php)

### Why

[`get_translation_batch_strings()`](includes/core/translator/class-translation-service.php:345) and [`get_translation_batch_permalinks()`](includes/core/translator/class-translation-service.php:396) use `static $request_cache` variables inside the method body for request-scoped deduplication. The other request-scoped caches use **declared instance properties** (`$language_cache`, `$default_language_cache`, etc.).

This is inconsistent. The `static` variables are invisible from the property declarations at the top of the class — you only discover them by reading method bodies.

### Regression Analysis

Since `Frl_Translation_Service` is a **singleton** ([line 21](includes/core/translator/class-translation-service.php:21), [line 63](includes/core/translator/class-translation-service.php:63)), exactly one instance exists per request. A method-level `static` variable and an instance property have **identical lifetime and scope**. The cache key construction, `isset()` checks, and assignments are unchanged. This is a purely mechanical conversion with **zero regression risk**.

### Action

**Step 1:** Add property declarations after `$object_language_cache`:

```php
private array $batch_strings_cache = [];
private array $batch_permalinks_cache = [];
```

**Step 2:** In [`get_translation_batch_strings()`](includes/core/translator/class-translation-service.php:345):
- Remove line 348: `static $request_cache = [];`
- Replace all `$request_cache` → `$this->batch_strings_cache`

**Step 3:** In [`get_translation_batch_permalinks()`](includes/core/translator/class-translation-service.php:396):
- Remove line 399: `static $request_cache = [];`
- Replace all `$request_cache` → `$this->batch_permalinks_cache`

---

## Fix 11: Add Caching Strategy Documentation

**Severity:** Low (documentation)
**File:** [`includes/core/translator/class-translation-service.php`](includes/core/translator/class-translation-service.php)

### Why

There is no comment or docblock explaining *why* some methods use properties, others use `frl_cache_remember`. A future developer adding a method has no guidance on which pattern to follow.

### Action

Add to the class docblock (after the existing description, before `@package`):

```php
/**
 * ## Caching Strategy
 *
 * Two categories, two mechanisms:
 *
 * - **Request-immutable values** (language, default language, active languages,
 *   object language, multilingual checks): stored in declared instance properties
 *   (e.g., `private ?string $language_cache`). These never change during a request;
 *   persistent caching would add serialization overhead for zero benefit.
 *
 * - **Persistent values** (translations, permalinks, block translations, post/term
 *   translation maps): stored via `frl_cache_remember()` with appropriate groups
 *   (`translations`, `blocks`, `permalinks`, `postdata`). These involve expensive
 *   operations (DB queries, Polylang API calls) and are stable until content changes.
 *
 * Helper functions in `functions-translation-helpers.php` are thin wrappers with
 * NO caching of their own — all caching lives here in the service.
 */
```

---

## Fix 12: Update Documentation


**Severity:** Low (documentation accuracy)
**File:** [`docs/SUBDOMAIN-ADAPTER.md`](docs/SUBDOMAIN-ADAPTER.md)

### Changes

1. Update all `'default'` → `'default_lang'` in config examples (covered in Fix 2d)
2. Line 122: Update "Queries with no object" bullet to reflect that these now render locally (not redirected):
   ```markdown
   - **Queries with no object** (author archives, date archives, post type archives)
     render locally with language-filtered content via Polylang's query filtering.
   ```
3. Line 123: Update 404 note to mention the explicit `is_404()` guard:
   ```markdown
   **404 errors are NOT redirected** — guarded by an explicit `is_404()` check.
   They render locally on the subdomain in the subdomain's language.
   ```

---

## Execution Order

| # | Fix | Risk | Dependencies |
|---|-----|------|-------------|
| 1 | Remove dead redirect block | None | None |
| 2 | Rename `'default'` → `'default_lang'` | Low (config change) | None |
| 3 | Add `$object_language_cache` to service | None (additive) | None |
| 4 | Add error-condition debug logging | None (additive, gated) | None |
| 5 | Fix TypeError in `filter_pll_get_home_url` | None (defensive) | None |
| 6 | Consolidate DRY URL filters | Low (refactor) | None |
| 7 | Extract repeated closure | None (additive) | Fix 1 (removes one instance) |
| 8 | Add explicit `is_404()` check | None (additive) | None |
| 9 | Sanitize `$_SERVER['REQUEST_URI']` | None (additive) | Fix 1 (removes one instance) |
| 10 | Convert batch `static` → instance properties | None (mechanical) | None |
| 11 | Add caching strategy docblock | None (additive) | Fixes 3, 10 |
| 12 | Update documentation | None | Fixes 1, 2, 8 |

All fixes are independent and can be applied in any order. The suggested order prioritizes correctness fixes (1, 2, 5) before quality improvements (6, 7, 8, 9, 10) and enhancements (3, 4, 11).

### Files Changed

| File | Fixes |
|------|-------|
| [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php) | 1, 2b, 2c, 4, 5, 6, 7, 8, 9 |
| [`modules/subdomain_adapter/config-constants-subdomain-adapter.php`](modules/subdomain_adapter/config-constants-subdomain-adapter.php) | 2a |
| [`includes/core/translator/class-translation-service.php`](includes/core/translator/class-translation-service.php) | 3, 10, 11 |
| [`docs/SUBDOMAIN-ADAPTER.md`](docs/SUBDOMAIN-ADAPTER.md) | 2d, 12 |
| [`includes/helpers/functions-translation-helpers.php`](includes/helpers/functions-translation-helpers.php) | *(no changes — helpers remain thin wrappers)* |
