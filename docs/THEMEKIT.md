# ThemeKit

Theme orchestration layer providing theme-independent body classes, base styles, block pattern management, and font optimization.

**File:** [`core/themekit/themekit.php`](core/themekit/themekit.php)  
**Config:** [`config/config-themekit.php`](config/config-themekit.php)

## Init

[`frl_themekit_init()`](core/themekit/themekit.php:18) is called at `init:20`. Uses `frl_is_already_running(__FUNCTION__)` guard. Registers hooks based on admin options:

| Option | Hook | Priority |
|--------|------|----------|
| `themekit_body_classes` | `admin_body_class` → `frl_themekit_admin_body_classes` | 9999 |
| `themekit_body_classes` | `body_class` → `frl_themekit_frontend_body_classes` | 10 |
| `themekit_base_css` | `wp_enqueue_scripts` → `frl_themekit_enqueue_base_styles` | `FRL_THEMEKIT_STYLE_PRIORITY['themekit']` (10) |
| `themekit_font_display_swap` | `wp_theme_json_data_user` → `frl_themekit_font_display_swap_filter` | default |
| `themekit_remove_wp_patterns` | — → `frl_themekit_remove_core_block_patterns()` | immediate |
| `themekit_remove_provider_patterns` | `init` → `frl_themekit_remove_provider_block_patterns` | 999 |
| `themekit_remove_provider_styles` | `wp_enqueue_scripts` → `frl_themekit_remove_provider_styles` | 99999 |

## Features

### Dynamic Body Classes

**Admin:** `uid-{id}`, `role-{role}` (from `frl_get_current_user()`).

**Frontend:** `uid-{id}`, `role-{role}`, `slug-{slug}`, `tax-{taxonomy}`, `tax-{slug}`, `path-{segment}`, `has-{param}` (for tracked query params). Cached via `frl_cache_remember('postdata', ...)` with cache key incorporating user ID, object ID, request path, and query signature. Only runs on `frl_is_valid_frontend_page_request()`.

### Base Styles

Enqueues [`assets/css/themekit-styles.css`](assets/css/themekit-styles.css) at priority 10 via `frl_enqueue_scripts()`.

### Font Display Optimization

Forces `font-display: swap` for all fonts uploaded via the WordPress Font Library by filtering `wp_theme_json_data_user`.

### Pattern & Style Removal

- **Core patterns:** Removes all WordPress core block patterns when `themekit_remove_wp_patterns` is enabled
- **Provider patterns:** Removes block patterns matching configured provider slugs (cached via `frl_cache_remember` in `themekit` group, keyed by MD5 of option value)
- **Provider styles:** Dequeues styles matching configured provider slugs at `wp_enqueue_scripts:99999`

## Configuration

[`config/config-themekit.php`](config/config-themekit.php):

```php
FRL_THEMEKIT_STYLE_PRIORITY = ['themekit' => 10, 'modules' => 15];
FRL_THEMEKIT_PATTERNS_CATEGORIES = ['sections', 'queries', 'ACF', 'editorial'];
FRL_THEMEKIT_TRACKED_QUERY_PARAMS = ['frlq'];
```

## Extension

Add tracked query parameters to `FRL_THEMEKIT_TRACKED_QUERY_PARAMS`. Example: adding `'ref'` results in a `has-ref` body class when `?ref=...` is in the URL.