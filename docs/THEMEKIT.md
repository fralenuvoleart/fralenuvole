# ThemeKit

ThemeKit is a Theme Orchestration Layer designed to provide the plugin with fine-grained control over the active theme's behavior, styling, and block patterns without requiring direct modifications to theme files.

## 🎯 Purpose
The primary goal of ThemeKit is to ensure theme independence and provide a mechanism for contextual styling and asset management that persists across theme changes.

### Problems Solved
- **Theme Independence:** Injects necessary styles and body classes regardless of the active theme.
- **Customization Persistence:** Theme-level modifications (like removing provider styles) are configurable via admin options rather than hard-coded.
- **Context-Aware Styling:** Injects User IDs, Roles, and Page Slugs into the `<body>` tag, enabling precise CSS targeting without complex template overrides.

## 🚀 Features

### 1. Dynamic Body Class Injection
ThemeKit adds contextual classes to the body tag to facilitate targeted styling.

- **Admin Area:** Adds `uid-{id}` and `role-{role}`.
- **Frontend:** Adds:
    - `uid-{id}`: Current user ID.
    - `role-{role}`: User roles (imploded with hyphens).
    - `slug-{slug}`: Current post/page slug.
    - `tax-{taxonomy}` and `tax-{slug}`: For archive pages.
    - `path-{segment}`: Fallback for non-singular/non-archive routes.
    - `has-{param}`: Added when tracked query parameters (defined in `FRL_THEMEKIT_TRACKED_QUERY_PARAMS`) are present.

### 2. Asset & Pattern Management
- **Base Styles:** Enqueues a base stylesheet with a deterministic priority to ensure correct override behavior.
- **Provider Blacklisting:** Allows the removal of styles and block patterns from specific third-party providers (themes/plugins) via admin options.
- **Core Pattern Control:** Can disable all WordPress core block patterns to provide a cleaner editor experience.

### 3. Theme JSON Manipulation
- **Font Display Optimization:** Forces `font-display: swap` for fonts uploaded via the WordPress Font Library to improve Core Web Vitals (LCP/CLS).

## 🏗️ Architecture

### Design Pattern
ThemeKit uses a **Procedural, Hook-Driven Registry** pattern. The `frl_themekit_init()` function acts as the central dispatcher, registering filters and actions based on the current configuration.

### Key Components
- **Logic:** `includes/core/themekit/themekit.php`
- **Configuration:** `config/config-themekit.php` (Constants for priorities, categories, and tracked params).
- **Settings:** Managed via `frl_get_option` calls throughout the initialization.

## ⚡ Performance

Performance is a critical priority for ThemeKit, especially regarding the dynamic body class injection and provider removal.

### Identification Caching
ThemeKit employs a two-phase approach:

1.  **Identification Phase (Cached):** The "fuzzy search" for handles or patterns matching provider slugs is wrapped in `frl_cache_remember` using the `themekit` cache group. The cache key is based on the MD5 hash of the options value, ensuring automatic invalidation when settings change.
2.  **Execution Phase (Direct):** The resulting list of handles/names is retrieved from cache and processed via direct calls to `wp_dequeue_style` or `unregister_block_pattern`.

This reduces the complexity to $O(H)$ (where $H$ is the number of items actually being removed) for the vast majority of requests.

## 🛠️ Developer Reference

### Adding Tracked Query Parameters
To add a new query parameter that should trigger a body class, add it to the `FRL_THEMEKIT_TRACKED_QUERY_PARAMS` constant in `config/config-themekit.php`.
*Example:* Adding `'ref'` will result in a `has-ref` class when `?ref=...` is in the URL.

### Style Loading Order
Style priorities are defined in `FRL_THEMEKIT_STYLE_PRIORITY`.
- `themekit`: Base plugin styles.
- `modules`: Module-specific styles.
These are designed to load in a deterministic order relative to the theme's main stylesheet.
