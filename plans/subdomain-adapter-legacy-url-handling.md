# Subdomain Adapter — Legacy URL Handling Plan

## Quick Reference for a New Developer

**Context:** The Subdomain Adapter module maps subdomains to Polylang languages. It already transforms permalinks, canonicals, and language switcher URLs at runtime. This plan adds runtime handling for three remaining gaps: hardcoded links in post/block content, navigation menu items, and legacy incoming URLs that need 301 redirects.

**Read these files first:**
- [`class-subdomain-adapter.php`](modules/subdomain_adapter/class-subdomain-adapter.php:1) — Existing singleton with `transform_url()` at line 756
- [`config-constants-subdomain-adapter.php`](modules/subdomain_adapter/config-constants-subdomain-adapter.php:29) — `FRL_SUBDOMAIN_ADAPTER_MAP` constant
- [`docs/SUBDOMAIN-ADAPTER.md`](docs/SUBDOMAIN-ADAPTER.md:1) — Architecture documentation

**Key architectural principle:** The existing `transform_url()` takes two inputs: the current domain (from `$_SERVER['HTTP_HOST']`) and the content's language (from `frl_get_language()`). It computes the correct output domain. For content filters, we infer the language from the URL's path prefix (e.g., `/ru/` → `ru`) since we don't have a post object — `transform_url()` is NOT called directly for content URLs; instead a new method `transform_single_content_url()` handles the cross-host scenario where a hardcoded URL may point to a different host than the current request.

---

## Overview

The Subdomain Adapter correctly transforms permalinks, canonicals, home URLs, and language switcher URLs. However, three categories of links remain untransformed:

| Category | Example | Where Stored | Status |
|---|---|---|---|
| Hardcoded links in post content | `<a href="https://pbservices.ge/ru/services/">` | `post_content` (blocks, patterns, templates) | ❌ Not transformed |
| Classic navigation menu items | `wp_nav_menu_item` post with `url` meta | `wp_posts` / `wp_postmeta` | ❌ Not transformed |
| Legacy incoming URLs | User lands on `pbservices.ge/ru/post/` | N/A (external/bookmark) | ❌ No 301 redirect |

This plan adds runtime filters to handle all three, with zero database modification.

---

## Architecture Overview

```
Request Flow
════════════

pbservices.ge/ru/services/
        │
        ▼
┌──────────────────────────────────────┐
│  template_redirect (priority 5)      │  ← NEW: redirect_legacy_incoming_url()
│  Detects /ru/ prefix on main domain  │
│  → 301 → ru.pbservices.ge/services/  │
└──────────────────────────────────────┘
        │
        ▼
┌──────────────────────────────────────┐
│  WordPress renders the page          │
│                                      │
│  ┌─────────────────────────────────┐ │
│  │ the_content filter (PHP_INT_MAX)│ │  ← NEW: filter_the_content()
│  │ Regex scans post HTML for links │ │
│  │ → transforms hardcoded URLs     │ │
│  └─────────────────────────────────┘ │
│                                      │
│  ┌─────────────────────────────────┐ │
│  │ render_block filter (PHP_INT_MAX)│ │  ← NEW: filter_render_block()
│  │ Fast-fail str_contains guard    │ │
│  │ → transforms block inner URLs   │ │
│  └─────────────────────────────────┘ │
│                                      │
│  ┌─────────────────────────────────┐ │
│  │ wp_nav_menu_objects (PHP_INT_MAX)│ │  ← NEW: filter_nav_menu_objects()
│  │ Transforms menu item URLs       │ │
│  │ Uses post/term lang if available │ │
│  │ Falls back to path-based lang    │ │
│  └─────────────────────────────────┘ │
└──────────────────────────────────────┘
```

---

## File Changes

### File 1: `modules/subdomain_adapter/class-subdomain-adapter.php`

**Change:** Make `transform_url()` public.

**Line 756:** Change `private function transform_url` to `public function transform_url`.

**Reason:** The legacy class needs to call `Frl_Subdomain_Adapter::init()->transform_url()` for navigation menu items where we have a post/term object and can determine language via `frl_get_language()`. This is the same pattern used by the existing `filter_post_link`, `filter_term_link`, etc.

**No other changes to this file.**

---

### File 2: `modules/subdomain_adapter/class-subdomain-adapter-legacy.php` (NEW)

A new class `Frl_Subdomain_Adapter_Legacy` that registers three hooks and provides the content URL extraction logic.

#### Class Structure

```php
class Frl_Subdomain_Adapter_Legacy {

    // -------------------------------------------------------------------------
    // Singleton / Init
    // -------------------------------------------------------------------------

    private static ?self $instance = null;

    public static function init(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register_hooks();
        }
        return self::$instance;
    }

    private function __construct() {}

    // -------------------------------------------------------------------------
    // Hook Registration
    // -------------------------------------------------------------------------

    private function register_hooks(): void {
        $adapter = Frl_Subdomain_Adapter::init();

        // Guard: only register if adapter is configured and on a recognized domain.
        if (!$adapter->is_configured()) {
            return;
        }
        if (!$adapter->is_on_main_domain() && !$adapter->is_on_subdomain()) {
            return;
        }
        if (!frl_translator_is_enabled()) {
            return;
        }
        if (frl_is_already_running(__CLASS__ . '::register_hooks')) {
            return;
        }

        // 1. Legacy incoming URL redirect (main domain and subdomain).
        add_action('template_redirect', [$this, 'redirect_legacy_incoming_url'], 6);

        // 2. Post content transformation.
        add_filter('the_content', [$this, 'filter_the_content'], PHP_INT_MAX, 1);

        // 3. Block content transformation.
        add_filter('render_block', [$this, 'filter_render_block'], PHP_INT_MAX, 2);

        // 4. Classic navigation menu transformation.
        add_filter('wp_nav_menu_objects', [$this, 'filter_nav_menu_objects'], PHP_INT_MAX, 1);
    }

    // -------------------------------------------------------------------------
    // Gate
    // -------------------------------------------------------------------------

    private function should_transform(): bool {
        if (frl_is_admin() || frl_is_rest_api_request() || is_preview() || frl_is_cron_job_request()) {
            return false;
        }
        return frl_translator_is_enabled();
    }

    // -------------------------------------------------------------------------
    // 1. Legacy Incoming URL Redirect
    // -------------------------------------------------------------------------

    /**
     * 301-redirect legacy URLs to their canonical domain.
     *
     * Handles:
     * - Main domain: /{lang}/post-slug/ → {subdomain}/post-slug/
     * - Subdomain: /{same-lang}/post-slug/ → {subdomain}/post-slug/ (strip redundant prefix)
     */
    public function redirect_legacy_incoming_url(): void { ... }
}
```

#### Method: `redirect_legacy_incoming_url()`

```php
public function redirect_legacy_incoming_url(): void {
    if (!$this->should_transform()) {
        return;
    }

    $adapter = Frl_Subdomain_Adapter::init();

    if (is_404()) {
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $uri = preg_replace('/[\x00-\x1F\x7F]/', '', $uri);
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';

    // Extract language from first path segment.
    $segments = explode('/', trim($path, '/'));
    $first_segment = strtolower($segments[0] ?? '');

    $active_langs = frl_get_active_languages();
    if (empty($first_segment) || !in_array($first_segment, $active_langs, true)) {
        return;
    }

    $lang = $first_segment;

    // Determine target domain for this language.
    $target_host = $this->get_target_host_for_language($adapter, $lang);
    if ($target_host === null) {
        return; // Language has no mapped subdomain and is not default — nothing to redirect.
    }

    $target_url = $this->build_redirect_target($path, $lang, $target_host);

    // Avoid redirect loops: if the target equals current URL, don't redirect.
    $current_full = home_url($uri);
    if (rtrim($current_full, '/') === rtrim($target_url, '/')) {
        return;
    }

    // Preserve query string.
    if (!empty($_GET)) {
        $target_url = add_query_arg($_GET, $target_url);
    }

    add_filter('x_redirect_by', [Frl_Subdomain_Adapter::class, 'get_redirect_by'], 999);
    wp_redirect($target_url, 301);
    exit;
}
```

#### Method: `get_target_host_for_language()`

Determines where content of a given language should live:

```php
private function get_target_host_for_language(Frl_Subdomain_Adapter $adapter, string $lang): ?string {
    // Use the adapter's public accessor methods (added as part of this plan).
    $map = $adapter->get_domain_map();
    $current_host = $adapter->get_current_host();

    // Determine resolution context: which main domain's config should we use?
    if ($adapter->is_on_subdomain()) {
        // Find the primary main domain that registered this subdomain.
        $subdomain_info = $adapter->get_subdomain_info();
        $info = $subdomain_info[$current_host] ?? null;
        if ($info === null) return null;
        $resolve_domain = $info['main_domains'][0];
    } else {
        $resolve_domain = $current_host;
    }

    $config = $map[$resolve_domain] ?? [];
    if (empty($config)) return null;

    // If language has a mapped subdomain → target is that subdomain.
    if (isset($config[$lang]) && $config[$lang] !== '') {
        return $config[$lang];
    }

    // If language is the default → target is main domain (no prefix).
    $default_lang = $config['default_lang'] ?? 'en';
    if ($lang === $default_lang) {
        return $resolve_domain;
    }

    // Language has no mapped subdomain and is not default → target is main domain with prefix.
    // Return main domain (prefix will be added by the caller).
    return $resolve_domain;
}
```

#### Method: `build_redirect_target()`

```php
private function build_redirect_target(string $path, string $lang, string $target_host): string {
    $scheme = is_ssl() ? 'https' : 'http';

    // Strip the language prefix from the path.
    $prefix = '/' . $lang . '/';
    $path_lower = strtolower($path);
    if (str_starts_with($path_lower, $prefix)) {
        $path = '/' . substr($path, strlen($prefix));
    } elseif (strtolower(rtrim($path, '/')) === '/' . $lang) {
        // Handle case where path is exactly /{lang} (homepage)
        $path = '/';
    }

    // Determine if we need to add a prefix to the target.
    $adapter = Frl_Subdomain_Adapter::init();
    $map = $adapter->get_domain_map();
    $target_is_main = isset($map[$target_host]);
    if ($target_is_main) {
        $config = $map[$target_host] ?? [];
        $default_lang = $config['default_lang'] ?? 'en';
        // If language is not the default for this main domain AND not mapped to a subdomain,
        // add the language prefix.
        if ($lang !== $default_lang && !isset($config[$lang])) {
            $path = '/' . $lang . $path;
        }
    }

    return "{$scheme}://{$target_host}{$path}";
}
```

---

### Method: `filter_the_content()`

```php
public function filter_the_content(string $content): string {
    if (!$this->should_transform()) {
        return $content;
    }
    return $this->transform_urls_in_html($content);
}
```

### Method: `filter_render_block()`

```php
public function filter_render_block(string $block_content, array $block): string {
    if (!$this->should_transform()) {
        return $block_content;
    }

    // Fast-fail: skip blocks unlikely to contain URLs.
    $block_name = $block['blockName'] ?? '';
    $likely_has_urls = in_array($block_name, [
        'core/navigation',
        'core/navigation-link',
        'core/navigation-submenu',
        'core/button',
        'core/image',
        'core/custom-html',
    ], true) || $block_name === '' || str_starts_with($block_name, 'acf/');

    if (!$likely_has_urls && !str_contains($block_content, 'pbservices.ge')) {
        return $block_content;
    }

    // Static per-request block cache: avoid re-processing identical block HTML.
    static $block_cache = [];
    $sig = md5($block_content);
    if (isset($block_cache[$sig])) {
        return $block_cache[$sig];
    }

    return $block_cache[$sig] = $this->transform_urls_in_html($block_content);
}
```

**Note on block name list:** The `$likely_has_urls` array is a fast-fail optimization. The `str_contains('pbservices.ge')` guard below it catches any blocks that DO contain site URLs regardless of block name. The block name check is purely to avoid the `str_contains` call for blocks that frequently contain text without site URLs (headings, paragraphs, spacers, etc.). In practice, even without this optimization, `str_contains` on a short string is ~0.3μs, so the optimization is optional.

#### Method: `transform_urls_in_html()` — Core Content Transformation

This is the shared logic used by both `the_content` and `render_block`:

```php
private function transform_urls_in_html(string $html): string {
    $adapter = Frl_Subdomain_Adapter::init();

    // Build a set of recognized hosts from the domain map.
    $hosts = $this->get_recognized_hosts();
    if (empty($hosts)) {
        return $html;
    }

    // Build regex alternation of recognized hosts.
    $hosts_pattern = implode('|', array_map('preg_quote', $hosts, array_fill(0, count($hosts), '#')));

    // Match URLs in href, src, and action attributes.
    // Uses negative lookbehind for ' to avoid matching inside already-escaped content.
    $pattern = '#\b(?:href|src|action)=(["\'])(https?://(?:' . $hosts_pattern . ')(?:/[^"\'>\s]*)?)\1#i';

    return preg_replace_callback($pattern, function ($matches) use ($adapter) {
        $attr   = $matches[1]; // quote character
        $url    = $matches[2]; // the URL

        $transformed = $this->transform_single_content_url($adapter, $url);
        return "href={$attr}{$transformed}{$attr}";
    }, $html);
}
```

#### Method: `transform_single_content_url()`

The core algorithm for transforming a single hardcoded content URL:

```php
private function transform_single_content_url(Frl_Subdomain_Adapter $adapter, string $url): string {
    $parsed = wp_parse_url($url);
    if (empty($parsed['host']) || empty($parsed['path'])) {
        return $url;
    }

    $host   = strtolower($parsed['host']);
    $path   = $parsed['path'];
    $scheme = $parsed['scheme'] ?? 'https';

    // Determine if this host is a main domain or subdomain.
    $map = $adapter->get_domain_map();
    $subdomain_info = $adapter->get_subdomain_info();
    $is_main_domain = isset($map[$host]);
    $is_subdomain   = isset($subdomain_info[$host]);

    if (!$is_main_domain && !$is_subdomain) {
        return $url; // Not a recognized domain.
    }

    // Extract language from path prefix.
    $segments = explode('/', trim($path, '/'));
    $first_segment = strtolower($segments[0] ?? '');
    $active_langs = frl_get_active_languages();
    $lang = in_array($first_segment, $active_langs, true) ? $first_segment : null;

    // If no language in path, try to determine from context.
    if ($lang === null) {
        if ($is_subdomain) {
            $lang = $subdomain_info[$host]['lang'] ?? null;
        } elseif ($is_main_domain) {
            $lang = $map[$host]['default_lang'] ?? null;
        }
    }

    if ($lang === null) {
        return $url; // Cannot determine language.
    }

    // Look up where this language's content should live.
    $target_host = $this->resolve_target_host($map, $subdomain_info, $host, $lang);
    if ($target_host === null) {
        return $url;
    }

    // Strip the language prefix from the path (if present).
    $prefix = '/' . $lang . '/';
    $path_lower = strtolower($path);
    if (str_starts_with($path_lower, $prefix)) {
        $path = '/' . substr($path, strlen($prefix));
    } elseif (strtolower(rtrim($path, '/')) === '/' . $lang) {
        $path = '/';
    }

    // Determine if target is a main domain.
    $target_is_main = isset($map[$target_host]);

    // Add language prefix if needed.
    if ($target_is_main) {
        $default_lang = $map[$target_host]['default_lang'] ?? 'en';
        if ($lang !== $default_lang && !isset($map[$target_host][$lang])) {
            $path = '/' . $lang . $path;
        }
    }

    $query    = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

    $result = "{$scheme}://{$target_host}{$path}{$query}{$fragment}";

    // Avoid re-processing: if same as input, return as-is.
    if ($result === $url) {
        return $url;
    }

    return $result;
}
```

#### Method: `resolve_target_host()`

```php
private function resolve_target_host(array $map, array $subdomain_info, string $current_url_host, string $lang): ?string {
    // If the current URL host IS a subdomain for this language → target is that subdomain.
    if (isset($subdomain_info[$current_url_host]) && $subdomain_info[$current_url_host]['lang'] === $lang) {
        return $current_url_host;
    }

    // If the current URL host is a main domain, find the target from its config.
    if (isset($map[$current_url_host])) {
        if (isset($map[$current_url_host][$lang]) && $map[$current_url_host][$lang] !== '') {
            return $map[$current_url_host][$lang]; // Mapped subdomain.
        }
        return $current_url_host; // Default or unmapped language → stays on main.
    }

    // Current URL host is a subdomain for a different language.
    // Find the primary main domain for this subdomain and resolve from there.
    if (isset($subdomain_info[$current_url_host])) {
        $primary_main = $subdomain_info[$current_url_host]['main_domains'][0];
        $config = $map[$primary_main] ?? [];
        if (isset($config[$lang]) && $config[$lang] !== '') {
            return $config[$lang]; // Mapped subdomain.
        }
        return $primary_main; // Default or unmapped → primary main domain.
    }

    return null;
}
```

---

### Method: `filter_nav_menu_objects()`

```php
public function filter_nav_menu_objects(array $menu_items): array {
    if (!$this->should_transform()) {
        return $menu_items;
    }

    $adapter = Frl_Subdomain_Adapter::init();

    foreach ($menu_items as $item) {
        if (!$item instanceof \WP_Post) {
            continue;
        }

        $url = $item->url ?? '';
        if (empty($url)) {
            continue;
        }

        $transformed = null;

        // Best case: menu item points to a post or term — use the object.
        if ($item->object === 'post' || $item->object === 'page' || $item->object === 'custom') {
            $object_id = (int) ($item->object_id ?? 0);
            if ($object_id > 0 && in_array($item->object, ['post', 'page'], true)) {
                $lang = frl_get_language($object_id, 'post');
                if (!empty($lang)) {
                    $transformed = $adapter->transform_url($url, $lang);
                }
            }
        }

        if ($item->object === 'category' || $item->object === 'post_tag') {
            $object_id = (int) ($item->object_id ?? 0);
            if ($object_id > 0) {
                $lang = frl_get_language($object_id, 'term');
                if (!empty($lang)) {
                    $transformed = $adapter->transform_url($url, $lang);
                }
            }
        }

        // Fallback: custom link or unknown object → extract language from URL path.
        if ($transformed === null) {
            $transformed = $this->transform_single_content_url($adapter, $url);
        }

        if ($transformed !== null && $transformed !== $url) {
            $item->url = $transformed;
        }
    }

    return $menu_items;
}
```

**Note:** For menu items pointing to actual post objects, we use the existing `transform_url()` with the post's language — this handles all four cases correctly. For custom links (manually typed URLs), we fall back to the content URL extraction approach.

---

### Method: `get_recognized_hosts()`

Builds a flat list of all recognized hosts (main domains + subdomains) for the regex pattern:

```php
private function get_recognized_hosts(): array {
    static $hosts = null;
    if ($hosts !== null) {
        return $hosts;
    }

    $adapter = Frl_Subdomain_Adapter::init();
    $map = $adapter->get_domain_map();
    $hosts = array_keys($map);

    foreach ($map as $config) {
        foreach ($config as $key => $value) {
            if ($key !== 'default_lang' && is_string($value) && $value !== '') {
                $hosts[] = $value;
            }
        }
    }

    $hosts = array_unique($hosts);
    return $hosts;
}
```

---

### File 3: `modules/subdomain_adapter/subdomain_adapter.php`

**Change:** Add require and init for the legacy class.

After line 28 (`Frl_Subdomain_Adapter::init();`), add:

```php
// Load and initialize the legacy URL handler (content, navigation, redirects).
require_once __DIR__ . '/class-subdomain-adapter-legacy.php';
Frl_Subdomain_Adapter_Legacy::init();
```

**Note on timing:** `Frl_Subdomain_Adapter::init()` runs during plugin bootstrap, which happens at `plugins_loaded` priority 5 (inside `frl_plugins_loaded()` → `frl_modules_init()`). The legacy class init registers hooks via `add_action`/`add_filter`, which is valid at this point. No need to wrap in a later hook.

---

## Additional Change: Public Accessors on Frl_Subdomain_Adapter

Add four new public methods to `Frl_Subdomain_Adapter` to support the legacy class without violating encapsulation:

```php
// After line 275 (after is_on_main_domain()) in class-subdomain-adapter.php:

/**
 * Get the domain map (main → { lang → subdomain, default_lang → lang }).
 *
 * @return array<string, array<string, string>>
 */
public function get_domain_map(): array {
    return $this->domain_map;
}

/**
 * Get the subdomain reverse index.
 *
 * @return array<string, array{lang: string, default_lang: string, main_domains: string[]}>
 */
public function get_subdomain_info(): array {
    return $this->subdomain_info;
}

/**
 * Get the current HTTP_HOST.
 *
 * @return string
 */
public function get_current_host(): string {
    return $this->current_host;
}

/**
 * Get the current subdomain's language, or null if not on a subdomain.
 *
 * @return string|null
 */
public function get_subdomain_lang(): ?string {
    return $this->current_subdomain_lang;
}
```

---

## Performance Analysis

| Filter | Fast-Fail | Cost Per Cache Miss | With Page Cache |
|---|---|---|---|
| `template_redirect` | Only fires for URLs with language prefix | ~2ms (one wp_parse_url + string ops) | N/A (redirects not cached) |
| `the_content` | preg_replace_callback only on matched URLs | ~1-3ms for typical post body | Zero (HTML cached) |
| `render_block` | str_contains guard + static block cache | ~0.5ms per matching block | Zero |
| `wp_nav_menu_objects` | Only menu renders (1-2× per page) | O(n) menu items, most cached via transform_url's static cache | Zero |

**Key caching layers:**
1. `transform_url()` static `$transform_cache` and `$parsed_cache` (per-request)
2. `render_block` static `$block_cache` (per-request, for identical block HTML)
3. Litespeed page cache (cross-request, hours/days TTL)
4. `get_recognized_hosts()` static variable (computed once per request)

---

## Testing Checklist

### 1. Content URL Transformation

| Test | Expected |
|---|---|
| Post on `pbservices.ge` with hardcoded `pbservices.ge/ru/services/` link | Link transformed to `ru.pbservices.ge/services/` |
| Post on `pbservices.ge` with hardcoded `pbservices.ge/en/services/` (default) link | Link becomes `pbservices.ge/services/` (prefix stripped) |
| Post on `ru.pbservices.ge` with hardcoded `pbservices.ge/ru/services/` link | Link becomes `ru.pbservices.ge/services/` |
| Post on `ru.pbservices.ge` with hardcoded `pbservices.ge/it/services/` link | Link becomes `pbservices.ge/it/services/` |
| Hardcoded external link (`google.com`) | Unchanged |
| Hardcoded link to `ru.pbservices.ge/services/` (already correct on subdomain, RU) | Unchanged (no-op) |
| Link with query string `?ref=home` | Query preserved |
| Link with fragment `#section` | Fragment preserved |
| Image `src` attribute with site URL | Transformed |
| Relative URL `/ru/services/` | Unchanged (regex only matches absolute URLs) |

### 2. Block Content

| Test | Expected |
|---|---|
| `core/navigation` with hardcoded links in link attributes | Links transformed |
| `core/button` with site URL | Transformed |
| `core/paragraph` without site URLs | No transformation attempted (str_contains fast-fail) |
| ACF block rendering ACF field values containing site URLs | Transformed via render_block |
| Pattern inserted into post content | Transformed via the_content |

### 3. Navigation Menus

| Test | Expected |
|---|---|
| Menu item pointing to RU post (object=post, object_id set) | URL transformed using post's language |
| Menu item pointing to RU category (object=category) | URL transformed using term's language |
| Custom link to `pbservices.ge/ru/services/` | URL transformed via content URL extraction |
| Custom link to external site | Unchanged |
| Menu displayed on `ru.pbservices.ge` | All URLs point to correct domains |

### 4. Legacy URL Redirects

| Test | Expected |
|---|---|
| Visit `pbservices.ge/ru/services/` | 301 → `ru.pbservices.ge/services/` |
| Visit `pbservices.ge/ru/` (homepage) | 301 → `ru.pbservices.ge/` |
| Visit `ru.pbservices.ge/ru/services/` | 301 → `ru.pbservices.ge/services/` |
| Visit `pbservices.ge/en/services/` (default, mapped) | If EN is default with no prefix on main, 301 → `pbservices.ge/services/` |
| Visit `pbservices.ge/it/services/` (unmapped language) | No redirect (stays on main with prefix) |
| 404 on `ru.pbservices.ge/nonexistent/` | No redirect (is_404 guard) |
| Admin page `pbservices.ge/wp-admin/` | No redirect (is_admin guard) |

### 5. Regression Checks

| Test | Expected |
|---|---|
| Permalink generation on main domain | Unchanged |
| Permalink generation on subdomain | Unchanged |
| Language switcher on main domain | Unchanged |
| Language switcher on subdomain | Unchanged |
| Canonical URLs (Yoast / TSF) | Unchanged |
| RSS feed URLs | Unchanged |
| REST API responses | Unchanged (should_transform gate) |
| Staging domain (`staging.pbservices.ge`) | Unchanged |
| Preview mode | Unchanged (is_preview gate) |

---

## Edge Cases & Notes

1. **Relative URLs are NOT touched.** The regex only matches absolute `https?://` URLs with recognized hosts.

2. **Non-HTML content (JSON, scripts) is NOT touched.** The regex uses `\b(?:href|src|action)=` to only match HTML attribute values.

3. **Serialized data in post_content.** The regex operates on the final rendered HTML, not raw `post_content`. WordPress unserializes blocks before rendering, so serialized data is handled by `render_block`.

4. **wp_navigation block menus.** These render through `render_block_core_navigation()` → `render_block` filter, so they are caught by `filter_render_block()`. The existing `frl_render_block_core_navigation_translation` filter handles post ID translation; our filter runs at `PHP_INT_MAX` after it.

5. **No double-transformation.** Since we use `str_contains` fast-fail on the raw block content, and the result is cached per-request via static `$block_cache`, the same block HTML is never processed twice.

6. **Redirect loop prevention.** The `redirect_legacy_incoming_url()` method compares the computed target URL against the current URL and skips the redirect if they match.

7. **Query string preservation.** Both the redirect handler and content URL transformations preserve query strings and fragments.

8. **HTTP/HTTPS.** Uses `is_ssl()` for dynamic protocol detection, consistent with the existing `get_scheme()` method.

---

## Summary of All Changes (Implementation Order)

### Step 1: Add public accessors to `class-subdomain-adapter.php`
After line 275, add 4 methods: `get_domain_map()`, `get_subdomain_info()`, `get_current_host()`, `get_subdomain_lang()`.

### Step 2: Make `transform_url()` public in `class-subdomain-adapter.php`
Line 756: change `private function transform_url` → `public function transform_url`.

### Step 3: Create `class-subdomain-adapter-legacy.php`
New file in `modules/subdomain_adapter/`. Contains the `Frl_Subdomain_Adapter_Legacy` class with:
- `init()` — singleton pattern
- `register_hooks()` — registers 4 hooks (template_redirect, the_content, render_block, wp_nav_menu_objects)
- `should_transform()` — defensive gate
- `redirect_legacy_incoming_url()` — 301 handler for legacy URL patterns
- `get_target_host_for_language()` — resolve correct domain for a language
- `build_redirect_target()` — build the redirect destination URL
- `filter_the_content()` — post content URL transformation
- `filter_render_block()` — block content URL transformation (with str_contains fast-fail + block cache)
- `transform_urls_in_html()` — shared HTML regex scanning logic
- `transform_single_content_url()` — core algorithm for cross-host URL transformation
- `resolve_target_host()` — determine target host for a language given a source host
- `filter_nav_menu_objects()` — nav menu item URL transformation
- `get_recognized_hosts()` — build flat set of recognized hosts (cached static)

### Step 4: Wire into `subdomain_adapter.php`
After line 28 (`Frl_Subdomain_Adapter::init();`), add:
```php
require_once __DIR__ . '/class-subdomain-adapter-legacy.php';
Frl_Subdomain_Adapter_Legacy::init();
```

### Step 5: Run the testing checklist (lines 678-739)
Verify all content URL, block, navigation, redirect, and regression test cases.

### File Manifest
| File | Action | Lines |
|------|--------|-------|
| `class-subdomain-adapter.php` | Edit: add 4 accessor methods | +20 |
| `class-subdomain-adapter.php` | Edit: transform_url visibility | 1 line changed |
| `class-subdomain-adapter-legacy.php` | **Create** | ~350 new |
| `subdomain_adapter.php` | Edit: add require + init | +3 |
