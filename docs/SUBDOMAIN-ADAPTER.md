# Subdomain Adapter — Architecture Reference

## Design Goal

The Subdomain Adapter solves the **cross-domain multilingual URL problem**. When a site serves different languages on different subdomains (e.g., `ru.pbservices.ge` for Russian, `pbservices.ge` for English), every URL on the site must point to the correct domain for its content's language. Links, canonicals, hreflang tags, and the language switcher must all agree.

The module provides **bidirectional URL transformation** between a main domain and its language-specific subdomain mirrors, with zero `str_replace` cost for the common case.

**Core Constraint**: The module must not interfere with requests on unrecognized domains. Hooks are only registered when the current `HTTP_HOST` matches a configured main domain or subdomain.

---

## Architectural Principles

### 1. Work With Polylang, Not Against It

The key insight: instead of post-processing URLs with string replacements, the module tells Polylang what the "default" language is on each subdomain via the [`pll_get_current_language`](modules/subdomain_adapter/class-subdomain-adapter.php:363) filter at priority 10. This is the ONLY filter in Polylang 3.7+ that controls `PLL()->curlang` during language resolution (inside `PLL_Choose_Lang::set_language()`). Polylang then naturally generates clean URLs (no language prefix) for that language. This makes target-language URLs **zero-cost** on subdomains.

### 1.1 Automatic Default Language Sync

On first visit to a mapped subdomain, the adapter automatically sets the translation adapter's default language in the database to match the subdomain's language. This eliminates the manual step of changing Polylang's default language on the subdomain replica. The sync runs during the Environment Manager's `init/10` enforcement phase via the [`frl_environment_before_wp_options`](includes/core/environment/class-environment-applier.php:93) action. It delegates to the translation adapter ([`Frl_Translation_Adapter_Interface::set_default_language()`](includes/core/translator/adapters/interface.php:108)) for DB updates, then flushes rewrite rules via `frl_flush_rewrite_rules()`. This single call triggers:
1. `update_option_permalink_structure` → `clear_rewriter_caches()` (options→rewriter→permalinks)
2. Polylang's `clean_languages_cache()` via hook at [polylang/src/model.php:119](/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/polylang/src/model.php:119)
3. `flush_rewrite_rules(true)` + Litespeed notification

No separate cache clear call needed. A generic `cache_cleared` flag suppresses redundant cache operations from the EM's change-type classifier. The sync logs via `frl_log()` and displays an admin notice on next admin page load.

### 1.2 State Change Trigger via Filter

The adapter also hooks the [`frl_environment_state_changed`](includes/core/environment/class-environment-state.php:90) filter to trigger EM enforcement when `polylang['default_lang']` doesn't match the subdomain's language. This ensures the sync runs on every subdomain visit where a mismatch exists, not just on host changes. The EM remains agnostic — the filter is generic and any module can use it.

### 2. Data-Driven Configuration

All domain/language mappings live in a single constant — [`FRL_SUBDOMAIN_ADAPTER_MAP`](modules/subdomain_adapter/config-constants-subdomain-adapter.php:29). Adding a new language subdomain or staging domain requires zero class code changes.

### 3. Main-Domain-Keyed Structure

The config is organized by main domain, not by subdomain. Each main domain declares which languages have subdomains and what the default language is. This makes staging a first-class configuration concern — add `staging.pbservices.ge` as a top-level key with the same mappings.

### 4. Defensive Early Exits

Every filter method gates behind [`should_transform()`](modules/subdomain_adapter/class-subdomain-adapter.php:421) which checks `is_admin()`, `frl_is_rest_api_request()`, `is_preview()`, and `frl_translator_is_enabled()`. Hooks are only registered when on a recognized domain.

---

## Configuration

### Constant: `FRL_SUBDOMAIN_ADAPTER_MAP`

Defined in [`config-constants-subdomain-adapter.php`](modules/subdomain_adapter/config-constants-subdomain-adapter.php:29).

```php
define('FRL_SUBDOMAIN_ADAPTER_MAP', [
    'pbservices.ge' => [
        'ru'      => 'ru.pbservices.ge',   // lang => subdomain host
        'default_lang' => 'en',             // default language (no URL prefix)
    ],
    'staging.pbservices.ge' => [
        'ru'      => 'ru.pbservices.ge',   // same production subdomain
        'default_lang' => 'en',
    ],
]);
```

**Rules:**
- Top-level keys are recognized main domains
- Inner keys (except `'default_lang'`) are Polylang language slugs mapped to their subdomain hosts
- `'default_lang'` specifies the language with no URL prefix on that main domain
- Multiple main domains can map to the same subdomain (e.g., staging and production both map `ru` → `ru.pbservices.ge`)
- The subdomain's own language is implicit — it's the inner key (`'ru'` in `'ru' => 'ru.pbservices.ge'`)

### Environment Integration

Module activation is controlled via the environment system:
- [`config/environment/config-defaults.php`](config/environment/config-defaults.php:46): `'subdomain_adapter' => false`
- [`config/environment/config-environment.php`](config/environment/config-environment.php:27): `'subdomain_adapter' => true` in PBS template

---

## How It Works

### Detection Phase

[`detect()`](modules/subdomain_adapter/class-subdomain-adapter.php:146) runs once per request during singleton construction:

1. Loads `FRL_SUBDOMAIN_ADAPTER_MAP` into `$domain_map`
2. Builds two reverse indices:
   - `$subdomain_hosts` — flat set of all subdomain hosts for O(1) detection
   - `$subdomain_info` — per-subdomain metadata: `{ lang, default_lang, main_domains[] }`
3. Reads `$_SERVER['HTTP_HOST']`, strips port, lowercases
4. Checks if host is a subdomain (O(1) via `$subdomain_hosts`) or a main domain (O(1) via `$domain_map`)

### URL Transformation — Four Cases

[`transform_url()`](modules/subdomain_adapter/class-subdomain-adapter.php:575) handles all URL transformations using `wp_parse_url()` for robust component manipulation:

| Case | Context | Behavior | Example |
|------|---------|----------|---------|
| **1** | Main domain + default language | No-op | `pbservices.ge/post/` → unchanged |
| **2** | Main domain + mapped language | Swap domain, strip prefix | `pbservices.ge/ru/post/` → `ru.pbservices.ge/post/` |
| **3** | Subdomain + target language | No-op (Polylang generates clean URLs) | `ru.pbservices.ge/post/` → unchanged |
| **4** | Subdomain + cross language | Strip prefix, swap to primary main domain, add prefix if needed | `ru.pbservices.ge/en/post/` → `pbservices.ge/post/` |

**Key design decisions:**
- Case 2 uses `$this->current_host` as the source domain — works for any recognized main domain (production, staging, etc.)
- Case 4 uses the **primary** main domain (`main_domains[0]`, the first one that registered the subdomain) — ensures redirects go to production, not staging
- `wp_parse_url()` handles query strings, fragments, mixed case, and any scheme (HTTP/HTTPS)

### Hook Architecture

All hooks registered in [`register_hooks()`](modules/subdomain_adapter/class-subdomain-adapter.php:343):

| Hook | Priority | Purpose |
|------|----------|---------|
| `pll_get_current_language` | 10 | Override Polylang's current language on subdomain (returns `PLL_Language` object) |
| `pll_language_home_url` | 20 | Correct home URLs for hreflang tags and language switcher (non-cached path) |
| `pll_additional_language_data` | 20 | Override home_url in language data (cached path — primary fix) |
| `pll_check_canonical_url` | 10 | Prevent Polylang canonical redirects on subdomains |
| `home_url` | 20 | WordPress core home_url override on subdomains |
| `post_link` | `PHP_INT_MAX` | Transform post permalinks |
| `post_type_link` | `PHP_INT_MAX` | Transform CPT permalinks |
| `page_link` | `PHP_INT_MAX` | Transform page permalinks |
| `term_link` | `PHP_INT_MAX` | Transform term archive links |
| `wpseo_canonical` | `PHP_INT_MAX` | Transform Yoast SEO canonical URLs |
| `the_seo_framework_meta_render_data` | `PHP_INT_MAX` | Transform The SEO Framework canonical URLs (v5.0+) |
| `frl_rewriter_skip_canonical_redirect` | 10 | Cancel rewriter's canonical redirect on subdomains |
| `option_page_on_front` | 20 | Translate front page ID on subdomain |
| `option_page_for_posts` | 20 | Translate posts page ID on subdomain |
| `template_redirect` | 5 | 301-redirect non-target content on subdomain |
| `frl_environment_before_wp_options` | 10 | Sync translation adapter's default language in DB on first subdomain visit |
| `frl_environment_state_changed` | 10 | Trigger EM enforcement when `polylang['default_lang']` mismatches subdomain language |

Priority 20 for URL filters ensures they run after the Rewriter (priority 10).

### Template Redirect

[`redirect_non_target_content()`](modules/subdomain_adapter/class-subdomain-adapter.php:654) runs on `template_redirect` at priority 5. On subdomains, it 301-redirects:

- **`WP_Post` objects** whose language doesn't match the subdomain
- **`WP_Term` objects** (term archives) whose language doesn't match

**Queries with no object** (author archives, date archives, post type archives) render locally with language-filtered content via Polylang's query filtering. They are not redirected.

**404 errors are NOT redirected** — guarded by an explicit `is_404()` check. They render locally on the subdomain in the subdomain's language. This is intentional: a 404 means "no content exists," not "content belongs elsewhere."

---

## Public API

| Method | Returns | Purpose |
|--------|---------|---------|
| `Frl_Subdomain_Adapter::init()` | `self` | Get/create the singleton instance |
| `is_configured()` | `bool` | Whether the domain map is non-empty |
| `is_on_subdomain()` | `bool` | Whether the current request is on a mapped subdomain |
| `is_on_main_domain()` | `bool` | Whether the current request is on a recognized main domain |

---

## Adding a New Language Subdomain

1. Add the language mapping to each main domain in [`FRL_SUBDOMAIN_ADAPTER_MAP`](modules/subdomain_adapter/config-constants-subdomain-adapter.php:29):
   ```php
   'pbservices.ge' => [
       'ru'      => 'ru.pbservices.ge',
       'ar'      => 'ar.pbservices.ge',  // NEW
       'default_lang' => 'en',
   ],
   'staging.pbservices.ge' => [
       'ru'      => 'ru.pbservices.ge',
       'ar'      => 'ar.pbservices.ge',  // NEW
       'default_lang' => 'en',
   ],
   ```

2. Configure DNS and web server for the new subdomain.

3. Ensure Polylang has the language configured with "hide URL language information" enabled for the default language.

No code changes needed.

## Adding a Staging Domain

Add the staging domain as a top-level key with the same language mappings:

```php
'staging.pbservices.ge' => [
    'ru'      => 'ru.pbservices.ge',
    'default_lang' => 'en',
],
```

The staging domain will be detected as a main domain. Russian content URLs on staging will point to the **same production subdomain** `ru.pbservices.ge`. Cross-language redirects from the subdomain will go to the **primary** main domain (`pbservices.ge`, not staging).

---

## Performance Characteristics

| Aspect | Detail |
|--------|--------|
| Target-language URLs on subdomain | Zero cost — Polylang generates clean URLs natively |
| Cross-language URLs (first call) | One `wp_parse_url()` + string operations per URL |
| Cross-language URLs (subsequent calls) | Zero cost — full result cached per-request via static `$transform_cache` |
| Main domain detection | Single `isset($domain_map[$host])` — O(1) |
| Subdomain detection | Single `isset($subdomain_hosts[$host])` — O(1) |
| Reverse index build | One-time O(n×m) where n=main domains (≤5), m=languages (≤5) |
| Hook registration | Conditional — skipped entirely on unrecognized domains |
| DB queries | None — module never touches the database |

---

## Legacy Link Handling

The [`Frl_Subdomain_Adapter_Legacy`](modules/subdomain_adapter/class-subdomain-adapter-legacy.php:93) class handles links that aren't covered by the core adapter's permalink/canonical transforms. It addresses three categories:

| Category | Hook | Purpose |
|---|---|---|
| Hardcoded links in `post_content` | [`the_content`](modules/subdomain_adapter/class-subdomain-adapter-legacy.php:136) at `PHP_INT_MAX` | Scans rendered HTML via regex for absolute URLs to recognized hosts; transforms `href`, `src`, and `action` attributes |
| Navigation menu items | [`wp_nav_menu_objects`](modules/subdomain_adapter/class-subdomain-adapter-legacy.php:142) at `PHP_INT_MAX` | Transforms menu item URLs using the linked post/term language (via `transform_url()`) with path-based fallback |
| Legacy incoming URLs | [`template_redirect`](modules/subdomain_adapter/class-subdomain-adapter-legacy.php:133) at priority 6 | 301-redirects URLs with a language prefix on the wrong domain (e.g., `pbservices.ge/ru/post/` → `ru.pbservices.ge/post/`) |

**Design notes:**

- All three hooks register only when the adapter is configured and on a recognized domain.
- Content URL transformation is purely runtime (regex on rendered HTML) — no database writes.
- `render_block` at `PHP_INT_MAX` provides per-block transformation with a `str_contains` fast-fail guard and per-request static block cache.
- Legacy incoming redirects respect `is_404()` and `is_admin()` guards; redirect loop prevention compares target vs current URL before redirecting.
- See [`plans/subdomain-adapter-legacy-url-handling.md`](plans/subdomain-adapter-legacy-url-handling.md) for full implementation plan and testing checklist.

---

## Maintenance Notes

- **Config changes**: Adding/removing domains or languages only requires editing `FRL_SUBDOMAIN_ADAPTER_MAP`. The reverse index is rebuilt on every request from the constant.
- **Protocol**: The `get_scheme()` helper uses `is_ssl()` for dynamic protocol detection. No hardcoded `https://`.
- **TypeError safety**: Polylang and Yoast filters can pass `false`. The callback methods handle this gracefully without `: string` return types.
- **Re-entrancy**: `frl_is_already_running()` prevents double hook registration.
- **404 behavior**: 404s are intentionally not redirected. If you need custom 404 handling on subdomains, use WordPress template hierarchy.
- **Cross-environment**: For multi-site setups where different main domains map to different subdomains (e.g., `pbproperty.ge` → `ru.pbproperty.ge`), add separate top-level entries. Each main domain has its own independent language map.

---

## Code Review Notes (2026-05-08)

Key technical observations for future maintainers.

### Design Strengths

- **`pll_get_current_language` override** ([`filter_pll_get_current_language`](modules/subdomain_adapter/class-subdomain-adapter.php:449), p10): This is the ONLY real filter in Polylang 3.7+ that controls `PLL()->curlang` during language resolution (inside `PLL_Choose_Lang::set_language()` at `choose-lang.php:103`). Unlike `pll_default_language` and `pll_current_language` which are function names (not `apply_filters` hooks), `pll_get_current_language` IS a real filter. It receives a `PLL_Language` object and fires BEFORE the default-language fallback. Returning the subdomain's language object makes Polylang treat it as the current language, generating clean URLs natively — zero `str_replace` cost. This is the module's core insight.
- **Four-case URL model** ([`transform_url()`](modules/subdomain_adapter/class-subdomain-adapter.php:596)): Complete coverage of main↔subdomain transformations. No edge-case gaps.
- **Main-domain-keyed config** ([`FRL_SUBDOMAIN_ADAPTER_MAP`](modules/subdomain_adapter/config-constants-subdomain-adapter.php:29)): Staging is a first-class top-level entry. Cross-language redirects use `main_domains[0]` (primary/production), not staging.
- **Zero DB queries**: Module never touches the database. All state derived from constants and `$_SERVER`.

### Performance

- O(1) domain detection via flat `isset()` on pre-built hash maps.
- Hook registration skipped entirely on unrecognized domains.
- `wp_parse_url()` results cached per-request via static variable — avoids re-parsing when multiple filters fire for the same URL.
- **Full transformation results cached per-request** via static `$transform_cache` keyed by `"$url|$content_lang"` — when `post_link` and `wpseo_canonical` fire for the same post, the second call is a single `isset()` lookup.

### Defensive Patterns

- [`should_transform()`](modules/subdomain_adapter/class-subdomain-adapter.php:485) gates all URL filters behind `is_admin()`, REST API, preview, cron, and translator-availability checks. Intentionally does NOT exclude `is_robots()` or `is_feed()` — each subdomain should have its own robots.txt and sitemap (industry best practice), and feed URLs should point to canonical domains.
- [`filter_pll_get_home_url()`](modules/subdomain_adapter/class-subdomain-adapter.php:398) validates `$lang` against `frl_get_active_languages()` — unrecognized language slugs return the original URL unchanged instead of generating 404-prone URLs.
- Case-insensitive language prefix stripping in [`transform_url()`](modules/subdomain_adapter/class-subdomain-adapter.php:735) — uses `strtolower()` before `str_starts_with()` to handle mixed-case path segments from external links.
- `filter_home_url` intentionally ignores WordPress' `$orig_scheme` parameter — uses `is_ssl()` for dynamic protocol detection ([line 424](modules/subdomain_adapter/class-subdomain-adapter.php:424)).
- Polylang/Yoast callbacks omit `: string` return types because those plugins may pass `false`.

### PHP Version

Requires PHP 8.0+ (`str_starts_with()`, typed properties with union types). Ensure `composer.json` declares `"php": ">=8.0"`.

### SEO Plugin Integration

Two SEO plugins are explicitly supported:
- **Yoast SEO**: [`filter_canonical_url()`](modules/subdomain_adapter/class-subdomain-adapter.php:554) hooks into `wpseo_canonical` (p20).
- **The SEO Framework**: [`filter_tsf_canonical_url()`](modules/subdomain_adapter/class-subdomain-adapter.php:577) hooks into `the_seo_framework_meta_render_data` (p20). TSF v5.0+ stopped using WordPress core's `get_canonical_url` filter; this is the official replacement. The callback transforms `$render_data['canonical']['attributes']['href']` in-place.

For singular posts and terms, `post_link`/`term_link` filters (also p20) already cover most SEO plugins since they derive canonical URLs from `get_permalink()`/`get_term_link()`. The dedicated SEO filters handle edge cases: homepage, post type archives, author archives.

