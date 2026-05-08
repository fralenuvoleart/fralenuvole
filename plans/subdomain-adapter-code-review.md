# Subdomain Adapter — Code Review & Analysis

**Date:** 2026-05-08
**Scope:** `modules/subdomain_adapter/` (3 files, 891 total lines)
**Reviewer:** Automated analysis (Architect mode)
**Status:** 3 patches applied, documentation updated

---

## Files Reviewed

| File | Lines | Purpose |
|------|-------|---------|
| [`class-subdomain-adapter.php`](../modules/subdomain_adapter/class-subdomain-adapter.php) | 818 | Singleton class: detection, hook registration, URL transformation, template redirect |
| [`config-constants-subdomain-adapter.php`](../modules/subdomain_adapter/config-constants-subdomain-adapter.php) | 44 | `FRL_SUBDOMAIN_ADAPTER_MAP` constant definition |
| [`subdomain_adapter.php`](../modules/subdomain_adapter/subdomain_adapter.php) | 28 | Module entry point: loads config, class, calls `init()` |

---

## 1. Features

The module is feature-complete for its stated purpose. It delivers:

| Feature | Implementation | Location |
|---------|---------------|----------|
| Bidirectional URL transformation | Four-case model covering all main↔subdomain transitions | [`transform_url()`](../modules/subdomain_adapter/class-subdomain-adapter.php:651) |
| Polylang deep integration | `pll_default_language` filter (p1) + `pll_current_language` safety net (p2) | [`filter_pll_default_language`](../modules/subdomain_adapter/class-subdomain-adapter.php:352), [`filter_pll_current_language`](../modules/subdomain_adapter/class-subdomain-adapter.php:374) |
| Home URL correction | `pll_get_home_url` (p20) + WordPress `home_url` (p20) | [`filter_pll_get_home_url`](../modules/subdomain_adapter/class-subdomain-adapter.php:398), [`filter_home_url`](../modules/subdomain_adapter/class-subdomain-adapter.php:457) |
| Post/Page/CPT permalink transformation | `post_link`, `post_type_link`, `page_link` (all p20) | Lines [512](../modules/subdomain_adapter/class-subdomain-adapter.php:512), [526](../modules/subdomain_adapter/class-subdomain-adapter.php:526), [540](../modules/subdomain_adapter/class-subdomain-adapter.php:540) |
| Term archive link transformation | `term_link` (p20) | [`filter_term_link`](../modules/subdomain_adapter/class-subdomain-adapter.php:555) |
| Yoast SEO canonical URL | `wpseo_canonical` (p20) | [`filter_canonical_url`](../modules/subdomain_adapter/class-subdomain-adapter.php:578) |
| The SEO Framework canonical URL | `the_seo_framework_meta_render_data` (p20, TSF v5.0+) | [`filter_tsf_canonical_url`](../modules/subdomain_adapter/class-subdomain-adapter.php:600) |
| Non-target content 301 redirects | `template_redirect` (p5) | [`redirect_non_target_content`](../modules/subdomain_adapter/class-subdomain-adapter.php:751) |
| Staging as first-class citizen | Top-level key in config map | [`FRL_SUBDOMAIN_ADAPTER_MAP`](../modules/subdomain_adapter/config-constants-subdomain-adapter.php:29) |
| Data-driven configuration | Zero class code changes for new domains/languages | Entire config constant |
| Public API | `is_configured()`, `is_on_subdomain()`, `is_on_main_domain()` | Lines [255](../modules/subdomain_adapter/class-subdomain-adapter.php:255), [264](../modules/subdomain_adapter/class-subdomain-adapter.php:264), [273](../modules/subdomain_adapter/class-subdomain-adapter.php:273) |
| Debug logging | WP_DEBUG-gated `frl_log()` calls for config errors | Lines [169](../modules/subdomain_adapter/class-subdomain-adapter.php:169), [174](../modules/subdomain_adapter/class-subdomain-adapter.php:174), [209](../modules/subdomain_adapter/class-subdomain-adapter.php:209), etc. |

---

## 2. Best Coding Practices

### Well-Executed

- **Singleton with private constructor** ([line 137](../modules/subdomain_adapter/class-subdomain-adapter.php:137)) — prevents external instantiation; `init()` is the only entry point
- **Typed properties throughout** ([lines 62-111](../modules/subdomain_adapter/class-subdomain-adapter.php:62)) — PHP 8.0+ union types (`?string`, `?self`), typed arrays with shape annotations
- **`ABSPATH` guard** on all three files — standard WordPress security practice
- **Comprehensive PHPDoc** — class-level header explains architecture ([lines 1-33](../modules/subdomain_adapter/class-subdomain-adapter.php:1)), every method has `@param`/`@return`, inline comments explain *why* not just *what*
- **Re-entrancy guard** via [`frl_is_already_running()`](../modules/subdomain_adapter/class-subdomain-adapter.php:302) — prevents double hook registration if `init()` is somehow called twice
- **Early returns / guard clauses** — every public method gates before doing work; no deep nesting
- **Static cache for `wp_parse_url()`** ([line 688](../modules/subdomain_adapter/class-subdomain-adapter.php:688)) — avoids re-parsing identical URLs when multiple filters fire for the same content
- **`$built` guard in `detect()`** ([line 160](../modules/subdomain_adapter/class-subdomain-adapter.php:160)) — prevents redundant reverse-index building
- **Defensive `instanceof` checks** on filter callbacks ([lines 513,527,541,559](../modules/subdomain_adapter/class-subdomain-adapter.php:513)) — WordPress filters can pass unexpected types; the adapter handles this gracefully
- **No `: string` return type on Polylang/Yoast callbacks** ([lines 352,374,578](../modules/subdomain_adapter/class-subdomain-adapter.php:352)) — documented as intentional because those plugins may pass `false`; adding `: string` would cause `TypeError`
- **Shared internal method** `filter_post_link_internal()` ([line 494](../modules/subdomain_adapter/class-subdomain-adapter.php:494)) — DRY: `post_link`, `post_type_link`, and `page_link` all delegate to the same logic
- **`get_redirect_by()` as static method** ([line 815](../modules/subdomain_adapter/class-subdomain-adapter.php:815)) — clean separation; the `x_redirect_by` filter callback doesn't need instance state
- **Input sanitization** in `get_request_uri()` ([line 805](../modules/subdomain_adapter/class-subdomain-adapter.php:805)) — strips null bytes and control characters from `$_SERVER['REQUEST_URI']`

### Minor Deviations

- **`filter_pll_get_home_url`**: declares `: string` return type but `$url` parameter is untyped ([line 398](../modules/subdomain_adapter/class-subdomain-adapter.php:398)) — inconsistent with the rest of the class which types parameters
- **`filter_home_url`**: declares `: string` return but `$path`, `$orig_scheme`, `$blog_id` are untyped ([line 457](../modules/subdomain_adapter/class-subdomain-adapter.php:457)) — WordPress core passes these, so typing them would be brittle, but a comment explaining this choice would help
- **`$built` static guard is technically unnecessary** — `detect()` is private and only called from `init()`, which is guarded by the singleton pattern. The guard is harmless but adds a small maintenance burden (future readers may wonder why it exists)

---

## 3. Elegance

The module has several genuinely elegant design decisions:

### 3.1 The `pll_default_language` Trick (★★★★★)

Instead of post-processing URLs with `str_replace` or regex, the module tells Polylang "RU is the default language on this subdomain" via the [`pll_default_language` filter at priority 1](../modules/subdomain_adapter/class-subdomain-adapter.php:352). Polylang then *natively* generates clean URLs (no language prefix) for that language. This makes target-language URLs **zero-cost** on the subdomain — no string manipulation needed at all.

This is the kind of insight that separates good integration from hacky workarounds. It works *with* the system rather than *around* it.

### 3.2 Four-Case URL Model (★★★★★)

[`transform_url()`](../modules/subdomain_adapter/class-subdomain-adapter.php:651) partitions the problem space completely:

| Case | Context | Behavior |
|------|---------|----------|
| 1 | Main domain + default language | No-op |
| 2 | Main domain + mapped language | Swap domain, strip prefix |
| 3 | Subdomain + target language | No-op (Polylang handles it) |
| 4 | Subdomain + cross language | Strip prefix, swap to primary main domain |

No edge-case gaps. The early return for Case 3 (line 656) before URL parsing is a nice performance touch.

### 3.3 Main-Domain-Keyed Config (★★★★☆)

[`FRL_SUBDOMAIN_ADAPTER_MAP`](../modules/subdomain_adapter/config-constants-subdomain-adapter.php:29) is organized by main domain, not by subdomain. This means:
- Staging is structurally identical to production — just add a top-level key
- Each main domain independently declares which languages have subdomains
- The `main_domains[0]` convention (first registrant = primary) elegantly solves "which domain do cross-language redirects go to?"

### 3.4 Reverse Index from Forward Config (★★★★☆)

[`detect()`](../modules/subdomain_adapter/class-subdomain-adapter.php:155) builds two internal structures from the human-friendly config:
- `$subdomain_hosts` — flat set for O(1) "are we on a subdomain?" checks
- `$subdomain_info` — per-subdomain metadata with language, default_lang, and main_domains

The config is written in the most human-friendly form (main domain → languages), but the runtime uses the most machine-efficient form (subdomain → metadata). The translation between them happens once per request.

### 3.5 Collision Detection (★★★★☆)

When the same subdomain is registered from multiple main domains with different `default_lang` values, the adapter detects the collision and logs a warning ([lines 206-215](../modules/subdomain_adapter/class-subdomain-adapter.php:206)). The first registration wins. This is a thoughtful touch for a scenario that's unlikely but possible in complex multi-domain setups.

---

## 4. Performance

| Aspect | Assessment | Detail |
|--------|-----------|--------|
| Domain detection | **O(1)** | `isset($subdomain_hosts[$host])` and `isset($domain_map[$host])` — single hash lookups |
| Hook registration | **Conditional** | Skipped entirely on unrecognized domains — zero overhead for unrelated sites |
| URL parsing | **Cached per-request** | `wp_parse_url()` results stored in static `$parsed_cache` ([line 688](../modules/subdomain_adapter/class-subdomain-adapter.php:688)) |
| DB queries | **Zero** | Module never touches the database; all state derived from constants and `$_SERVER` |
| Reverse index build | **O(n×m), one-time** | n = main domains (≤5), m = languages per domain (≤5) — negligible |
| Target-language URLs on subdomain | **Zero-cost** | Polylang generates clean URLs natively via the `pll_default_language` trick |
| Cross-language URLs | **One `wp_parse_url()` + string ops** | Acceptable for the uncommon case |
| Memory footprint | **~10 properties + 3 small arrays** | Negligible |

### Performance Note: Unbounded Static Cache

The static `$parsed_cache` in `transform_url()` ([line 688](../modules/subdomain_adapter/class-subdomain-adapter.php:688)) grows unbounded within a request. In practice, a single request rarely transforms more than a handful of unique URLs, so this is not a real concern. However, if a page had hundreds of unique post links (e.g., a sitemap or archive page), the cache array would grow proportionally. A simple LRU bound (e.g., keep last 50 entries) could be added, though it's arguably premature optimization.

---

## 5. Bugs and Issues

### 5.1 ✅ FIXED: Case-Sensitive Language Prefix Stripping

**Location:** [`transform_url()`](../modules/subdomain_adapter/class-subdomain-adapter.php:735) and [line 749](../modules/subdomain_adapter/class-subdomain-adapter.php:749)

**Applied:** Both prefix-stripping blocks now use `strtolower()` on `$path` and `$prefix` before `str_starts_with()` comparison. Mixed-case path segments (e.g., `/RU/post-slug/`) are now handled correctly.

### 5.2 🟡 Minor: `frl_get_language()` Hardcoded `'en'` Fallback

**Location:** [`frl_get_language()`](../includes/helpers/functions-translation-helpers.php:46)

**Issue:** When the translator is enabled but `get_language()` returns empty, `frl_get_language()` returns `'en'` (line 53). For a site whose default language is not English, this would cause incorrect URL transformations. The subdomain adapter inherits this behavior.

**Severity:** Low. In the adapter, `should_transform()` already gates on `frl_translator_is_enabled()`, so the disabled-translator path is unreachable. The empty-return path is an edge case.

**Note:** This is a bug in `frl_get_language()`, not the adapter itself, but the adapter is affected by it.

### 5.3 ✅ FIXED: Language Validation in `filter_pll_get_home_url`

**Location:** [`filter_pll_get_home_url()`](../modules/subdomain_adapter/class-subdomain-adapter.php:398)

**Applied:** Added `in_array($lang, frl_get_active_languages(), true)` guard at the top of the function. Unrecognized language slugs now return the original `$url` unchanged instead of generating 404-prone URLs like `https://pbservices.ge/xyz/`.

### 5.4 🟢 Not a Bug: `$orig_scheme` Ignored in `filter_home_url`

**Location:** [Lines 448-449](../modules/subdomain_adapter/class-subdomain-adapter.php:448)

**Assessment:** This is documented as intentional. Using `is_ssl()` for dynamic protocol detection is more reliable than trusting WordPress's passed `$orig_scheme` parameter, which can be `null` or incorrect behind reverse proxies. This is a defensible design choice, not a bug.

---

## 6. Logical Flaws

### 6.1 ✅ DOCUMENTED: `is_robots()` and `is_feed()` Behavior

**Location:** [`should_transform()`](../modules/subdomain_adapter/class-subdomain-adapter.php:474)

**Resolved:** Both are intentionally NOT excluded. Docblock now explains:
- `is_robots()`: each subdomain should have its own robots.txt pointing to its own sitemap (industry best practice per SE Ranking)
- `is_feed()`: post URLs in feeds should point to the correct canonical domain, consistent with the plugin's goal

### 6.2 🟡 Minor: `main_domains[0]` Convention Is Implicit

**Location:** [Line 670](../modules/subdomain_adapter/class-subdomain-adapter.php:670)

**Issue:** The "first registrant = primary" convention relies on PHP array key insertion order. If someone puts staging first in the config, cross-language redirects go to staging instead of production.

**Severity:** Low. The documentation clearly states production should be listed first, but there's no runtime enforcement.

**Suggested improvement:** Add a `'primary' => true` key to the config, or validate at detection time that the first entry looks like a production domain.

### 6.3 🟡 Minor: Subdomain Uses Primary Main Domain's Config for Resolution

**Location:** [`filter_pll_get_home_url`](../modules/subdomain_adapter/class-subdomain-adapter.php:398), line 409

**Issue:** When on `ru.pbservices.ge`, the function resolves `$resolve_domain` to `pbservices.ge` (primary). It then checks `$this->domain_map['pbservices.ge'][$lang]` for a subdomain mapping. This works correctly when staging and production share the same subdomain mappings. But if staging had *different* subdomain mappings (e.g., `ru.staging.pbservices.ge`), the subdomain would still resolve using production's config.

**Severity:** Low. This is an unlikely configuration scenario.

### 6.4 🟡 Minor: `$built` Static Guard Is Redundant

**Location:** [Line 160](../modules/subdomain_adapter/class-subdomain-adapter.php:160)

**Issue:** The comment says "the guard avoids even that trivial work on repeated singleton access within the same request." But `detect()` is private and only called from `init()`, which is guarded by the singleton pattern (`self::$instance === null`). The `$built` guard is therefore only relevant if `init()` is called, the singleton is destroyed, and `init()` is called again — which can't happen in normal PHP execution.

**Severity:** Trivial. The guard is harmless but adds a small maintenance burden.

---

## 7. Areas of Improvement

### 7.1 ✅ APPLIED: Case-Insensitive Prefix Stripping
Both prefix-stripping blocks in `transform_url()` now use `strtolower()` before `str_starts_with()`.

### 7.2 ✅ APPLIED: Cache Full Transformation Results
Static `$transform_cache` keyed by `"$url|$content_lang"` added to `transform_url()`. All return points after URL parsing store results in the cache.

### 7.3 ✅ APPLIED: Validate `$lang` in `filter_pll_get_home_url`
`in_array($lang, frl_get_active_languages(), true)` guard added at the top of the function.

### 7.4 ✅ APPLIED: Document `is_robots()`/`is_feed()` Behavior
Docblock in `should_transform()` explains why both are intentionally NOT excluded.

### 7.5 Remaining: Consider `wp_safe_redirect()` in `redirect_non_target_content()`
Low priority — `wp_redirect()` is safe since targets are always configured domains.

### 7.6 Remaining: Make `FALLBACK_LANG` Configurable
Low priority — only used when `default_lang` is missing from config (a configuration error).

### 7.8 Add Integration Test Hooks
The module has no way to programmatically verify its state. Adding a function like:
```php
function frl_subdomain_adapter_get_state(): array {
    $adapter = Frl_Subdomain_Adapter::init();
    return [
        'current_host' => $adapter->current_host,
        'is_on_subdomain' => $adapter->is_on_subdomain(),
        'is_on_main_domain' => $adapter->is_on_main_domain(),
        'current_subdomain_lang' => $adapter->current_subdomain_lang,
    ];
}
```
would make automated testing possible without exposing internal properties.

### 7.9 Add `wp_doing_cron()` Explicitly (Redundant but Clear)
`frl_is_cron_job_request()` already covers cron, but adding an explicit `wp_doing_cron()` check in `should_transform()` would make the guard's intent more immediately obvious to readers unfamiliar with the `frl_*` helper functions.

---

## 8. Dependency Analysis

The module depends on these external functions (all from `includes/helpers/`):

| Function | Used In | Purpose |
|----------|---------|---------|
| `frl_get_language()` | `filter_post_link_internal`, `filter_term_link`, `filter_canonical_url`, `filter_tsf_canonical_url`, `redirect_non_target_content` | Get content language |
| `frl_is_admin()` | `should_transform()` | Skip transformation in admin |
| `frl_is_rest_api_request()` | `should_transform()` | Skip transformation in REST API |
| `frl_is_cron_job_request()` | `should_transform()` | Skip transformation during cron |
| `frl_translator_is_enabled()` | `should_transform()`, `redirect_non_target_content()` | Skip when translator unavailable |
| `frl_is_already_running()` | `register_hooks()` | Re-entrancy guard |
| `frl_log()` | `detect()`, `filter_pll_get_home_url`, `transform_url`, `redirect_non_target_content` | Debug logging |

All dependencies are well-established helpers in the codebase. No circular dependencies detected.

---

## 9. Overall Verdict

**Rating: 8.5/10 — Well-architected production code with minor, non-blocking issues.**

The Subdomain Adapter exemplifies the architectural principles stated in [`systemPatterns.md`](../memory-bank/systemPatterns.md): modular, elegant, and performance-conscious.

**Strengths:**
- The `pll_default_language` trick is genuinely clever — works *with* Polylang rather than *around* it
- Four-case URL model is a complete partition of the problem space
- Performance is excellent: zero DB queries, O(1) detection, conditional hook registration
- Code is clean, well-documented, and defensively written
- Staging is a first-class configuration concern, not an afterthought

**Weaknesses:**
- One case-sensitivity edge case in prefix stripping
- Missing `is_feed()` guard (behavior is accidental, not intentional)
- Some implicit conventions (`main_domains[0]`) that could be made explicit
- Inherits `frl_get_language()`'s hardcoded `'en'` fallback

**None of the issues found are blocking or likely to cause problems in practice given the current configuration.**
