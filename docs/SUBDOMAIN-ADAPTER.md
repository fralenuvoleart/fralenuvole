# Subdomain Adapter — Architecture Reference

## Design Goal

The Subdomain Adapter solves the **cross-domain multilingual URL problem**. When a site serves different languages on different subdomains (e.g., `ru.pbservices.ge` for Russian, `pbservices.ge` for English), every URL on the site must point to the correct domain for its content's language. Links, canonicals, hreflang tags, and the language switcher must all agree.

The module provides **bidirectional URL transformation** between a main domain and its language-specific subdomain mirrors, with zero `str_replace` cost for the common case.

**Core Constraint**: The module must not interfere with requests on unrecognized domains. Hooks are only registered when the current `HTTP_HOST` matches a configured main domain or subdomain.

---

## Architectural Principles

### 1. Work With Polylang, Not Against It

The key insight: instead of post-processing URLs with string replacements, the module tells Polylang what the "default" language is on each subdomain via the [`pll_default_language`](modules/subdomain_adapter/class-subdomain-adapter.php:307) filter at priority 1. Polylang then naturally generates clean URLs (no language prefix) for that language. This makes target-language URLs **zero-cost** on subdomains.

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

All hooks registered in [`register_hooks()`](modules/subdomain_adapter/class-subdomain-adapter.php:246):

| Hook | Priority | Purpose |
|------|----------|---------|
| `pll_default_language` | 1 | Switch default language on subdomain (before Polylang setup) |
| `pll_current_language` | 2 | Safety net — force current language to match subdomain |
| `pll_get_home_url` | 20 | Correct home URLs for hreflang tags and language switcher |
| `home_url` | 20 | WordPress core home_url override on subdomains |
| `post_link` | 20 | Transform post permalinks |
| `post_type_link` | 20 | Transform CPT permalinks |
| `page_link` | 20 | Transform page permalinks |
| `term_link` | 20 | Transform term archive links |
| `wpseo_canonical` | 20 | Transform Yoast SEO canonical URLs |
| `template_redirect` | 5 | 301-redirect non-target content on subdomain |

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
| Cross-language URLs | One `wp_parse_url()` + string operations per URL |
| Main domain detection | Single `isset($domain_map[$host])` — O(1) |
| Subdomain detection | Single `isset($subdomain_hosts[$host])` — O(1) |
| Reverse index build | One-time O(n×m) where n=main domains (≤5), m=languages (≤5) |
| Hook registration | Conditional — skipped entirely on unrecognized domains |
| DB queries | None — module never touches the database |

---

## Maintenance Notes

- **Config changes**: Adding/removing domains or languages only requires editing `FRL_SUBDOMAIN_ADAPTER_MAP`. The reverse index is rebuilt on every request from the constant.
- **Protocol**: The `get_scheme()` helper uses `is_ssl()` for dynamic protocol detection. No hardcoded `https://`.
- **TypeError safety**: Polylang and Yoast filters can pass `false`. The callback methods handle this gracefully without `: string` return types.
- **Re-entrancy**: `frl_is_already_running()` prevents double hook registration.
- **404 behavior**: 404s are intentionally not redirected. If you need custom 404 handling on subdomains, use WordPress template hierarchy.
- **Cross-environment**: For multi-site setups where different main domains map to different subdomains (e.g., `pbproperty.ge` → `ru.pbproperty.ge`), add separate top-level entries. Each main domain has its own independent language map.
