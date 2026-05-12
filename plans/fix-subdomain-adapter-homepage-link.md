# Fix: Subdomain Adapter — Homepage Language Switcher URL

## Bug Description

On `staging.pbservices.ge` (default language EN), the Polylang language switcher on the EN homepage generates `https://staging.pbservices.ge/ru/` instead of `https://ru.pbservices.ge/` for the Russian language link. Non-homepage page links work correctly (e.g., `ru.pbservices.ge/some-post/`).

## Root Cause

**Priority ordering conflict** between the Subdomain Adapter's [`filter_page_link()`](modules/subdomain_adapter/class-subdomain-adapter.php:557) and Polylang's [`PLL_Static_Pages::page_link()`](https://github.com/polylang/polylang/blob/master/src/static-pages.php).

### Detailed Flow

When the user is on the EN homepage (static front page) of `staging.pbservices.ge`:

1. **Polylang's `PLL_Switcher`** needs the RU version URL. It calls [`PLL_Frontend_Links::get_translation_url($language)`](https://github.com/polylang/polylang/blob/master/src/frontend/frontend-links.php) for RU.

2. Since the current page is a static front page, `is_page()` is true, so Polylang calls [`get_page_link($ru_front_page_id)`](https://github.com/polylang/polylang/blob/master/src/frontend/frontend-links.php:101).

3. **WordPress `_get_page_link` filter** fires first:
   - Polylang's [`PLL_Filters_Links::_get_page_link()`](https://github.com/polylang/polylang/blob/master/src/filters-links.php:53) adds the `/ru/` language prefix via `switch_language_in_link()`.
   - URL becomes: `https://staging.pbservices.ge/ru/`

4. **WordPress `page_link` filter** fires at priority 20:
   - **Step A** (`plugins_loaded/5` registers first): Subdomain Adapter's [`filter_page_link()`](modules/subdomain_adapter/class-subdomain-adapter.php:557) **correctly** transforms the URL to `https://ru.pbservices.ge/` (via `transform_url()`).
   - **Step B** (`plugins_loaded/10` registers second): Polylang's [`PLL_Static_Pages::page_link()`](https://github.com/polylang/polylang/blob/master/src/static-pages.php:121) detects `$id == $lang->page_on_front` (front page match) and **overrides** the URL with [`$lang->get_home_url()`](https://github.com/polylang/polylang/blob/master/src/links-model.php:110) which returns `https://staging.pbservices.ge/ru/`.

**Final result:** `https://staging.pbservices.ge/ru/` — the transformation from Step A is discarded.

### Why Non-Homepage Pages Work

For non-front pages, [`PLL_Static_Pages::page_link()`](https://github.com/polylang/polylang/blob/master/src/static-pages.php:121) returns the link unchanged because `$id != $lang->page_on_front`. The subdomain adapter's transformation is preserved.

### Why `post_link` / `term_link` Work

Those filters don't have a corresponding static-pages override in Polylang.

## Fix

**Change the `page_link` filter priority from 20 to 21** in the subdomain adapter's [`register_hooks()`](modules/subdomain_adapter/class-subdomain-adapter.php:326).

### Before (broken)
```php
add_filter('page_link', [$this, 'filter_page_link'], 20, 2);
```

### After (fixed)
```php
add_filter('page_link', [$this, 'filter_page_link'], 21, 2);
```

### Corrected Execution Order

1. `_get_page_link` → Polylang adds `/ru/` prefix → `https://staging.pbservices.ge/ru/`
2. `page_link` at **p20** → `PLL_Static_Pages::page_link()` overrides to `$lang->get_home_url()` = `https://staging.pbservices.ge/ru/`
3. `page_link` at **p21** → Subdomain adapter's `filter_page_link()` transforms to `https://ru.pbservices.ge/` ✅

### Why This is Safe

- **No other `page_link` hooks at p20-21**: Only the subdomain adapter and `PLL_Static_Pages` use `page_link`. Moving to p21 only affects the ordering between these two.
- **`_get_page_link` is unaffected**: That's a separate filter with its own priority system.
- **Non-front pages unaffected**: `PLL_Static_Pages::page_link()` returns the link unchanged for non-front pages, so our filter at p21 receives the same URL it would at p20.
- **`transform_url()` handles the URL correctly**: Whether the input is from `_get_page_link` or `$lang->get_home_url()`, the URL structure is the same (`https://staging.pbservices.ge/ru/`), and `transform_url()` correctly strips the prefix and swaps the domain.

## Files to Modify

| File | Line | Change |
|------|------|--------|
| [`modules/subdomain_adapter/class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php) | 326 | `20` → `21` in `add_filter('page_link', ...)` |

## Testing

1. Visit `staging.pbservices.ge` (EN homepage)
2. Inspect the language switcher link for RU
3. Verify it points to `https://ru.pbservices.ge/` instead of `https://staging.pbservices.ge/ru/`
4. Verify non-homepage language switcher links still point to `ru.pbservices.ge/...`
5. Verify EN → RU navigation links on sub-pages still work
