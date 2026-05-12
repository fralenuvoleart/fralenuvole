# Fix: Subdomain Adapter — Homepage Language Switcher URL

## Bug Description

On `staging.pbservices.ge` (default language EN), the Polylang language switcher on the EN homepage generates `https://staging.pbservices.ge/ru/` instead of `https://ru.pbservices.ge/` for the Russian language link. Non-homepage page links work correctly (e.g., `ru.pbservices.ge/some-post/`).

## Root Cause (Two Interdependent Problems)

### Problem 1: `pll_get_home_url` filter does not exist in modern Polylang

The Subdomain Adapter had a dead hook:

```php
add_filter('pll_get_home_url', [$this, 'filter_pll_get_home_url'], 20, 2);
```

This filter **does not exist** in Polylang 3.7+. The real flow is:

1. **Polylang's `PLL_Frontend_Static_Pages::pll_pre_translation_url()`** — called when the language switcher builds the RU translation URL on the EN front page.
2. This calls **`$language->get_home_url()`** on `PLL_Language` (language.php:598).
3. When **`PLL_CACHE_LANGUAGES`** and **`PLL_CACHE_HOME_URL`** are both true (the default), `get_home_url()` returns **`$this->home_url`** directly — a private property set during language object creation. **No filter is fired.**
4. This `home_url` property is set during language object creation via the **`pll_additional_language_data`** filter, where Polylang's own `PLL_Links_Model::set_language_home_urls()` (at p10) calls `PLL_Links_Permalinks::front_page_url()` which returns `home_url() + get_page_uri($front_page_id)` — always on the **main domain**.
5. When caching is disabled (either constant false), `get_home_url()` fires the **`pll_language_home_url`** filter — not `pll_get_home_url`.

### Problem 2: All URL filters registered at same priority as Polylang

Polylang registers all its URL filters at priority **20**:
- `post_link`, `post_type_link`, `page_link`, `term_link`, `attachment_link` — in `PLL_Filters_Links`
- `page_link` — additionally in `PLL_Static_Pages` (overrides front page URLs)

The Subdomain Adapter also registered at priority **20**. Since WordPress executes same-priority hooks in **registration order**, and Subdomain Adapter loads at `plugins_loaded/5` (before Polylang's `plugins_loaded/10`), the execution was:

1. Subdomain Adapter's `filter_page_link()` runs first (correctly transforms URL)
2. Polylang's `PLL_Static_Pages::page_link()` runs second and **overrides** the URL with `$lang->get_home_url()` = main-domain URL

The transformation was discarded.

## Fix (Two Parts)

### Part A: Override `home_url` at language creation time (primary fix)

**Hook into `pll_additional_language_data`** at priority 20 (after Polylang's own handler at p10) to set the correct subdomain URL directly in the language object's cached `home_url` property.

```php
add_filter('pll_additional_language_data', [$this, 'filter_pll_additional_language_data'], 20, 2);
```

This is the **primary fix** because when `PLL_Language::get_home_url()` is called by the language switcher, it returns `$this->home_url` (the cached property) without firing any filter — so the value must be correct at creation time.

**Also hook into `pll_language_home_url`** (for the non-cached fallback path when `PLL_CACHE_HOME_URL` is false):

```php
add_filter('pll_language_home_url', [$this, 'filter_pll_language_home_url'], 20, 2);
```

Replaces the old dead `pll_get_home_url` hook.

### Part B: Move all URL transformation hooks to `PHP_INT_MAX`

Ensures Subdomain Adapter always runs **last** — no plugin can ever override the subdomain transformation:

```php
add_filter('post_link',             [$this, 'filter_post_link'],        PHP_INT_MAX, 2);
add_filter('post_type_link',        [$this, 'filter_post_type_link'],   PHP_INT_MAX, 2);
add_filter('page_link',             [$this, 'filter_page_link'],        PHP_INT_MAX, 2);
add_filter('term_link',             [$this, 'filter_term_link'],        PHP_INT_MAX, 3);
add_filter('wpseo_canonical',       [$this, 'filter_canonical_url'],    PHP_INT_MAX, 1);
add_filter('the_seo_framework_meta_render_data', [$this, 'filter_tsf_canonical_url'], PHP_INT_MAX, 1);
```

## Files Modified

| File | Change |
|------|--------|
| [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php) | Replaced `pll_get_home_url` hook with `pll_additional_language_data` (p20) + `pll_language_home_url` (p20). Changed all URL hooks from p20 to `PHP_INT_MAX`. Added `filter_pll_additional_language_data()` and `filter_pll_language_home_url()` methods. Removed dead `filter_pll_get_home_url()`. |

## Detailed Code Changes

### `register_hooks()` (line 291)

**Before:**
```php
add_filter('post_link',             [$this, 'filter_post_link'],        20, 2);
add_filter('post_type_link',        [$this, 'filter_post_type_link'],   20, 2);
add_filter('page_link',             [$this, 'filter_page_link'],        20, 2);
add_filter('term_link',             [$this, 'filter_term_link'],        20, 3);
add_filter('wpseo_canonical',       [$this, 'filter_canonical_url'],    20, 1);
add_filter('the_seo_framework_meta_render_data', [$this, 'filter_tsf_canonical_url'], 20, 1);
add_filter('pll_get_home_url',      [$this, 'filter_pll_get_home_url'], 20, 2);
```

**After:**
```php
// Language home URL filter (non-cached path)
add_filter('pll_language_home_url', [$this, 'filter_pll_language_home_url'], 20, 2);

// Override home_url in language data (cached path — primary fix)
add_filter('pll_additional_language_data', [$this, 'filter_pll_additional_language_data'], 20, 2);

// URL transformation filters (priority PHP_INT_MAX — always last)
add_filter('post_link',             [$this, 'filter_post_link'],        PHP_INT_MAX, 2);
add_filter('post_type_link',        [$this, 'filter_post_type_link'],   PHP_INT_MAX, 2);
add_filter('page_link',             [$this, 'filter_page_link'],        PHP_INT_MAX, 2);
add_filter('term_link',             [$this, 'filter_term_link'],        PHP_INT_MAX, 3);
add_filter('wpseo_canonical',       [$this, 'filter_canonical_url'],    PHP_INT_MAX, 1);
add_filter('the_seo_framework_meta_render_data', [$this, 'filter_tsf_canonical_url'], PHP_INT_MAX, 1);
```

### New: `filter_pll_additional_language_data()` (line 482)

Sets `$additional_data['home_url']` to the correct subdomain URL for mapped languages during language object creation. Uses the same domain resolution logic as the URL transformation methods.

### New: `filter_pll_language_home_url()` (line 417)

Replaces the old `filter_pll_get_home_url()` — same domain resolution logic but accepts the `$language` array (Polylang's format for this filter) instead of a string slug.

## Testing

1. Visit `staging.pbservices.ge` (EN homepage)
2. Inspect the language switcher link for RU
3. Verify it points to `https://ru.pbservices.ge/` instead of `https://staging.pbservices.ge/ru/`
4. Verify non-homepage language switcher links still point to `ru.pbservices.ge/...`
5. Verify EN → RU navigation links on sub-pages still work
